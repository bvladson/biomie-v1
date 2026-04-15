<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use BIomie\Support;
use Closure;
use PDO;

/**
 * Responsável exclusivamente por operações de leitura da camada canônica.
 *
 * Cobre: vendedores, pedidos, notas fiscais, títulos financeiros, eventos
 * canônicos e agregações analíticas por período.
 *
 * Não escreve nada. Não gerencia transações. Não executa DDL.
 */
final class CanonicalReadRepository
{
    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    // ── Vendedores ───────────────────────────────────────────────────────────

    /**
     * @return array<string, array{codigo_omie:string,nome:string,email:?string,comissao:mixed}>
     */
    public function getVendorMap(): array
    {
        $rows = $this->pdo()
            ->query('SELECT codigo_omie, nome, email, comissao FROM biomie_vendedores_omie WHERE ativo = 1')
            ->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['codigo_omie']] = $row;
        }
        return $map;
    }

    /**
     * @return list<array{vendor_id:string,vendor_name:string,email:?string}>
     */
    public function listCommercialVendorRoster(): array
    {
        $roster = [];
        foreach ($this->getVendorMap() as $vendorId => $row) {
            $vendorName = trim((string) ($row['nome'] ?? ''));
            if ($vendorName === '') {
                continue;
            }
            $roster[] = [
                'vendor_id'   => (string) $vendorId,
                'vendor_name' => $vendorName,
                'email'       => isset($row['email']) ? (string) $row['email'] : null,
            ];
        }

        usort($roster, static fn (array $l, array $r): int => strcmp(
            mb_strtolower((string) ($l['vendor_name'] ?? '')),
            mb_strtolower((string) ($r['vendor_name'] ?? '')),
        ));

        return $roster;
    }

    // ── Resumos de alto nível (compatibilidade retroativa) ───────────────────

    /**
     * @return array{notes_count:int,merchandise_total:float,invoice_total:float,discount_total:float}
     */
    public function summarizeInvoicedCommercial(string $start, string $end): array
    {
        $summary = $this->summarizeInvoicesCommercial($start, $end);
        return [
            'notes_count'       => (int) ($summary['billed_orders'] ?? 0),
            'merchandise_total' => Support::money((float) ($summary['merchandise_total'] ?? 0)),
            'invoice_total'     => Support::money((float) ($summary['billed_value'] ?? 0)),
            'discount_total'    => Support::money((float) ($summary['discounts_total'] ?? 0)),
        ];
    }

    /**
     * @return array{notes_count:int,merchandise_total:float,invoice_total:float}
     */
    public function summarizeCancelledCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS invoice_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'cancelada'
              AND data_competencia BETWEEN :start AND :end
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'notes_count'       => (int) ($row['notes_count'] ?? 0),
            'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
            'invoice_total'     => Support::money((float) ($row['invoice_total'] ?? 0)),
        ];
    }

    /**
     * @return array{orders_count:int,merchandise_total:float,order_total:float}
     */
    public function summarizeOrderPipelineCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                COUNT(*) AS orders_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS order_total
            FROM biomie_pedidos_canonicos
            WHERE status_comercial = 'autorizado'
              AND data_emissao BETWEEN :start AND :end
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'orders_count'      => (int) ($row['orders_count'] ?? 0),
            'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
            'order_total'       => Support::money((float) ($row['order_total'] ?? 0)),
        ];
    }

    // ── Agregações por vendedor (vendors.php) ────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateInvoicedByVendorCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS invoice_total,
                COALESCE(SUM(valor_descontos), 0) AS discount_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor ASC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateCancelledByVendorCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS invoice_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'cancelada'
              AND data_competencia BETWEEN :start AND :end
            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor ASC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateOrderPipelineByVendorCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                COUNT(*) AS orders_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS order_total
            FROM biomie_pedidos_canonicos
            WHERE status_comercial = 'autorizado'
              AND data_emissao BETWEEN :start AND :end
            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor ASC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Séries diárias (por vendedor) ────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyInvoicedCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        $sql = <<<'SQL'
            SELECT
                data_competencia AS dia,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS invoice_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
        SQL;

        $params = ['start' => $start, 'end' => $end];
        if ($vendorId !== null && $vendorId !== '') {
            $sql .= ' AND codigo_vendedor_omie = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }
        $sql .= ' GROUP BY data_competencia ORDER BY data_competencia ASC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyCancelledCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        $sql = <<<'SQL'
            SELECT
                data_competencia AS dia,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS invoice_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'cancelada'
              AND data_competencia BETWEEN :start AND :end
        SQL;

        $params = ['start' => $start, 'end' => $end];
        if ($vendorId !== null && $vendorId !== '') {
            $sql .= ' AND codigo_vendedor_omie = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }
        $sql .= ' GROUP BY data_competencia ORDER BY data_competencia ASC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Listagens para exibição por vendedor ─────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicedOrdersForDisplayCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        $sql = <<<'SQL'
            SELECT *
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
        SQL;

        $params = ['start' => $start, 'end' => $end];
        if ($vendorId !== null && $vendorId !== '') {
            $sql .= ' AND codigo_vendedor_omie = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }
        $sql .= ' ORDER BY data_competencia DESC, numero_nf DESC, serie_nf DESC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $this->mapInvoiceDisplayRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPipelineOrdersForDisplayCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        $sql = <<<'SQL'
            SELECT *
            FROM biomie_pedidos_canonicos
            WHERE status_comercial = 'autorizado'
              AND data_emissao BETWEEN :start AND :end
        SQL;

        $params = ['start' => $start, 'end' => $end];
        if ($vendorId !== null && $vendorId !== '') {
            $sql .= ' AND codigo_vendedor_omie = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }
        $sql .= ' ORDER BY data_emissao DESC, codigo_pedido DESC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $this->mapDisplayRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── Títulos financeiros ──────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function listFinancialTitlesByPeriod(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM biomie_titulos_financeiros_canonicos
             WHERE data_competencia BETWEEN :start AND :end
             ORDER BY data_competencia DESC, data_liquidacao DESC, data_vencimento DESC, titulo_key DESC'
        );
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Mapa de entidades de domínio ─────────────────────────────────────────

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getDomainEntityMap(): array
    {
        return [
            'pedido_comercial' => [
                'domain'      => 'commercial',
                'table'       => 'biomie_pedidos_canonicos',
                'natural_key' => 'codigo_pedido',
                'provider_key'=> 'pedido_id_omie',
                'note'        => 'Entidade comercial de origem; nao deve ser promovida por evidencias financeiras.',
            ],
            'evento_pedido' => [
                'domain'      => 'commercial',
                'table'       => 'biomie_eventos_canonicos',
                'natural_key' => 'entity_code + event_type + event_at_omie',
                'provider_key'=> 'omie_entity_id',
                'note'        => 'Linha do tempo comercial-fiscal oriunda de etapas do pedido.',
            ],
            'nota_fiscal' => [
                'domain'      => 'fiscal',
                'table'       => 'biomie_notas_fiscais_canonicas',
                'natural_key' => 'chave_nfe',
                'provider_key'=> 'nota_key',
                'note'        => 'Documento fiscal oficial para receita fiscal e cancelamentos.',
            ],
            'documento_xml' => [
                'domain'      => 'fiscal',
                'table'       => 'biomie_documentos_xml_canonicos',
                'natural_key' => 'xml_key',
                'provider_key'=> 'nota_id_omie',
                'note'        => 'Artefato documental fiscal separado da nota, inclusive quando o XML ainda nao foi coletado.',
            ],
            'titulo_financeiro' => [
                'domain'      => 'finance',
                'table'       => 'biomie_titulos_financeiros_canonicos',
                'natural_key' => 'titulo_key',
                'provider_key'=> 'titulo_id_omie',
                'note'        => 'Representa contas a receber e baixas sem corrigir comercial ou fiscal sem trilha de origem.',
            ],
            'snapshot_indicadores' => [
                'domain'      => 'gold',
                'table'       => 'biomie_competencia_snapshots',
                'natural_key' => 'competencia',
                'provider_key'=> null,
                'note'        => 'Camada de apresentacao consolidada; nao substitui as evidencias dos dominios base.',
            ],
        ];
    }

    // ── Eventos canônicos ────────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function listCanonicalEventsByPeriod(string $start, string $end, ?string $entityName = null): array
    {
        $sql = <<<'SQL'
            SELECT *
            FROM biomie_eventos_canonicos
            WHERE DATE(event_at_omie) BETWEEN :start AND :end
        SQL;

        $params = ['start' => $start, 'end' => $end];
        if ($entityName !== null && $entityName !== '') {
            $sql .= ' AND entity_name = :entity_name';
            $params['entity_name'] = $entityName;
        }
        $sql .= ' ORDER BY event_at_omie ASC, event_order ASC, id ASC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Consultas canônicas principais ───────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrders(string $start, string $end, ?string $status = null): array
    {
        return $this->listOrdersCommercial($start, $end, $status);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrdersCommercial(string $start, string $end, ?string $status = null): array
    {
        if ($status === 'faturado') {
            return $this->listInvoicesCommercial($start, $end);
        }
        if ($status === 'cancelado') {
            return $this->listCancelledInvoicesCommercial($start, $end);
        }
        if ($status === 'autorizado' || $status === 'outro') {
            return array_values(array_filter(
                $this->listPendingOrdersCommercial($start, $end),
                static fn (array $row): bool => (string) ($row['status_comercial'] ?? '') === $status,
            ));
        }

        return array_merge(
            $this->listInvoicesCommercial($start, $end),
            $this->listPendingOrdersCommercial($start, $end),
            $this->listCancelledInvoicesCommercial($start, $end),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrderRowsForReconciliation(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT *
            FROM biomie_pedidos_canonicos
            WHERE data_emissao BETWEEN :start AND :end
               OR (data_faturamento IS NOT NULL AND data_faturamento BETWEEN :start AND :end)
               OR (data_cancelamento IS NOT NULL AND data_cancelamento BETWEEN :start AND :end)
            ORDER BY data_emissao DESC, pedido_id_omie DESC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoiceRowsForReconciliation(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT *
            FROM biomie_notas_fiscais_canonicas
            WHERE data_competencia BETWEEN :start AND :end
               OR (data_emissao IS NOT NULL AND data_emissao BETWEEN :start AND :end)
               OR (data_cancelamento IS NOT NULL AND data_cancelamento BETWEEN :start AND :end)
            ORDER BY data_competencia DESC, nota_key DESC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Agregações gerais ────────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateByVendor(string $start, string $end): array
    {
        return $this->aggregateByVendorCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateByVendorCommercial(string $start, string $end): array
    {
        $rows = array_merge(
            $this->aggregateInvoicesByVendorCommercial($start, $end),
            $this->aggregatePendingByVendorCommercial($start, $end),
            $this->aggregateCancelledVendorStatusCommercial($start, $end),
        );

        usort($rows, static function (array $l, array $r): int {
            $nameCompare = strcmp(
                mb_strtolower((string) ($l['nome_vendedor'] ?? '')),
                mb_strtolower((string) ($r['nome_vendedor'] ?? '')),
            );
            if ($nameCompare !== 0) {
                return $nameCompare;
            }
            return strcmp((string) ($l['status_comercial'] ?? ''), (string) ($r['status_comercial'] ?? ''));
        });

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function summarize(string $start, string $end): array
    {
        return $this->summarizeCommercial($start, $end);
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeCommercial(string $start, string $end): array
    {
        $inv = $this->summarizeInvoicesCommercial($start, $end);
        $pen = $this->summarizePendingCommercial($start, $end);

        return [
            'billed_value'       => (float) ($inv['billed_value'] ?? 0),
            'billed_orders'      => (int) ($inv['billed_orders'] ?? 0),
            'authorized_value'   => (float) ($pen['authorized_value'] ?? 0),
            'authorized_orders'  => (int) ($pen['authorized_orders'] ?? 0),
            'cancelled_value'    => (float) ($pen['cancelled_value'] ?? 0),
            'cancelled_orders'   => (int) ($pen['cancelled_orders'] ?? 0),
            'quote_value'        => (float) ($pen['quote_value'] ?? 0),
            'quote_orders'       => (int) ($pen['quote_orders'] ?? 0),
            'merchandise_total'  => (float) ($inv['merchandise_total'] ?? 0),
            'discounts_total'    => (float) ($inv['discounts_total'] ?? 0),
            'active_vendors'     => (int) ($inv['active_vendors'] ?? 0),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyByStatus(string $start, string $end): array
    {
        return $this->dailyByStatusCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyByStatusCommercial(string $start, string $end): array
    {
        $rows = array_merge(
            $this->dailyInvoiceStatusRowsCommercial($start, $end),
            $this->dailyPendingStatusRowsCommercial($start, $end),
            $this->dailyCancelledStatusRowsCommercial($start, $end),
        );

        usort($rows, static function (array $l, array $r): int {
            $dayCompare = strcmp((string) ($l['dia'] ?? ''), (string) ($r['dia'] ?? ''));
            if ($dayCompare !== 0) {
                return $dayCompare;
            }
            return strcmp((string) ($l['status_comercial'] ?? ''), (string) ($r['status_comercial'] ?? ''));
        });

        return $rows;
    }

    // ── Listagens para exibição (front-end) ──────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrdersForDisplay(string $start, string $end, ?string $status = null): array
    {
        return $this->listOrdersForDisplayCommercial($start, $end, $status);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrdersForDisplayCommercial(string $start, string $end, ?string $status = null): array
    {
        $rows = [];

        if ($status === null || $status === '' || $status === 'faturado') {
            $rows = array_merge($rows, $this->listInvoicesForDisplayCommercial($start, $end));
        }

        if ($status === null || $status === '' || $status === 'autorizado' || $status === 'outro') {
            $pendingRows = $this->listPendingOrdersForDisplayCommercial($start, $end);
            if ($status === 'autorizado' || $status === 'outro') {
                $pendingRows = array_values(array_filter(
                    $pendingRows,
                    static fn (array $row): bool => (string) ($row['status_group'] ?? '') === $status,
                ));
            }
            $rows = array_merge($rows, $pendingRows);
        }

        if ($status === null || $status === '' || $status === 'cancelado') {
            $rows = array_merge($rows, $this->listCancelledInvoicesForDisplayCommercial($start, $end));
        }

        usort($rows, static function (array $l, array $r): int {
            $leftDate  = (string) ($l['status_date'] ?? $l['issue_date'] ?? '');
            $rightDate = (string) ($r['status_date'] ?? $r['issue_date'] ?? '');
            $dateCompare = strcmp($rightDate, $leftDate);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return strcmp((string) ($r['codigo'] ?? ''), (string) ($l['codigo'] ?? ''));
        });

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                nf.*,
                CASE
                    WHEN TRIM(COALESCE(nf.nome_vendedor, '')) IN ('', 'Sem vendedor')
                         AND TRIM(COALESCE(nf.codigo_vendedor_omie, '')) <> ''
                    THEN COALESCE(NULLIF(TRIM(v.nome), ''), 'Sem vendedor')
                    ELSE nf.nome_vendedor
                END AS nome_vendedor
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = nf.codigo_vendedor_omie
               AND v.ativo = 1
            WHERE nf.status_fiscal = 'autorizada'
              AND nf.data_competencia BETWEEN :start AND :end
            ORDER BY nf.data_competencia DESC, nf.numero_nf DESC, nf.serie_nf DESC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                nf.*,
                CASE
                    WHEN TRIM(COALESCE(nf.nome_vendedor, '')) IN ('', 'Sem vendedor')
                         AND TRIM(COALESCE(nf.codigo_vendedor_omie, '')) <> ''
                    THEN COALESCE(NULLIF(TRIM(v.nome), ''), 'Sem vendedor')
                    ELSE nf.nome_vendedor
                END AS nome_vendedor
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = nf.codigo_vendedor_omie
               AND v.ativo = 1
            WHERE nf.status_fiscal = 'cancelada'
              AND nf.data_competencia BETWEEN :start AND :end
            ORDER BY nf.data_competencia DESC, nf.numero_nf DESC, nf.serie_nf DESC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Base fiscal bruta para reconciliação com o consolidado do Omie.
     * Usa data de emissão para espelhar a exportação padrão da página de downloads.
     *
     * @return list<array<string,mixed>>
     */
    public function listFiscalDocumentsByIssueDate(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT *
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal IN ('autorizada', 'cancelada')
              AND data_emissao BETWEEN :start AND :end
            ORDER BY data_emissao DESC, numero_nf DESC, serie_nf DESC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPendingOrdersCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                p.*,
                CASE
                    WHEN TRIM(COALESCE(p.nome_vendedor, '')) IN ('', 'Sem vendedor')
                         AND TRIM(COALESCE(p.codigo_vendedor_omie, '')) <> ''
                    THEN COALESCE(NULLIF(TRIM(v.nome), ''), 'Sem vendedor')
                    ELSE p.nome_vendedor
                END AS nome_vendedor
            FROM biomie_pedidos_canonicos p
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = p.codigo_vendedor_omie
               AND v.ativo = 1
            WHERE p.status_comercial IN ('autorizado', 'outro')
              AND p.data_emissao BETWEEN :start AND :end
            ORDER BY p.data_emissao DESC, p.codigo_pedido DESC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->mapInvoiceDisplayRows($this->listInvoicesCommercial($start, $end));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->mapInvoiceDisplayRows($this->listCancelledInvoicesCommercial($start, $end));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPendingOrdersForDisplayCommercial(string $start, string $end): array
    {
        return $this->mapDisplayRows($this->listPendingOrdersCommercial($start, $end));
    }

    // ── Produtos e clientes (top N) ──────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function productDocumentBreakdownCommercial(
        string $start,
        string $end,
        ?string $productCode = null,
        int $limit = 200,
    ): array {
        $sql = <<<'SQL'
            SELECT
                item.codigo_produto, item.descricao_produto,
                item.quantidade, item.valor_unitario, item.valor_total, item.valor_desconto,
                nota.nota_key, nota.chave_nfe, nota.numero_nf, nota.serie_nf,
                nota.codigo_pedido_origem,
                nota.codigo_vendedor_omie, nota.nome_vendedor,
                nota.codigo_cliente_omie, nota.nome_cliente, nota.documento_cliente,
                nota.data_emissao, nota.data_competencia
            FROM biomie_notas_fiscais_itens_canonicos item
            INNER JOIN biomie_notas_fiscais_canonicas nota
                ON nota.nota_key = item.nota_key
            WHERE nota.status_fiscal = 'autorizada'
              AND nota.data_competencia BETWEEN :start AND :end
        SQL;

        if ($productCode !== null && trim($productCode) !== '') {
            $sql .= ' AND item.codigo_produto = :product_code';
        }
        $sql .= <<<'SQL'
             ORDER BY nota.data_competencia DESC, nota.numero_nf DESC, item.descricao_produto ASC
             LIMIT :limit
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->bindValue('start', $start);
        $stmt->bindValue('end', $end);
        if ($productCode !== null && trim($productCode) !== '') {
            $stmt->bindValue('product_code', trim($productCode));
        }
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            $number = trim((string) ($row['numero_nf'] ?? ''));
            $serie  = trim((string) ($row['serie_nf'] ?? ''));
            return [
                'product_code'       => (string) ($row['codigo_produto'] ?? ''),
                'description'        => (string) ($row['descricao_produto'] ?? 'Produto sem descricao'),
                'quantity'           => (float) ($row['quantidade'] ?? 0),
                'unit_value'         => (float) ($row['valor_unitario'] ?? 0),
                'revenue'            => (float) ($row['valor_total'] ?? 0),
                'discount'           => (float) ($row['valor_desconto'] ?? 0),
                'nota_key'           => (string) ($row['nota_key'] ?? ''),
                'document_key'       => (string) ($row['chave_nfe'] ?? ''),
                'nf_code'            => $number !== ''
                    ? trim($number . ($serie !== '' ? '/' . $serie : ''))
                    : (string) ($row['chave_nfe'] ?? 'NF sem numero'),
                'origin_order_code'  => (string) ($row['codigo_pedido_origem'] ?? ''),
                'vendor_id'          => (string) ($row['codigo_vendedor_omie'] ?? ''),
                'vendor_name'        => (string) ($row['nome_vendedor'] ?? 'Sem vendedor'),
                'client_id'          => (string) ($row['codigo_cliente_omie'] ?? ''),
                'client_name'        => (string) ($row['nome_cliente'] ?? 'Cliente nao informado'),
                'client_document'    => (string) ($row['documento_cliente'] ?? ''),
                'issue_date'         => (string) ($row['data_emissao'] ?? ''),
                'competencia_date'   => (string) ($row['data_competencia'] ?? ''),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── Resumos internos ─────────────────────────────────────────────────────

    /**
     * @return array<string,int|float>
     */
    public function summarizeInvoicesCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total,
                COALESCE(SUM(valor_mercadorias), 0) AS mercadorias,
                COALESCE(SUM(valor_descontos), 0) AS descontos,
                COUNT(DISTINCT NULLIF(TRIM(COALESCE(nome_vendedor, '')), '')) AS vendedores,
                COUNT(DISTINCT CASE
                    WHEN codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0 THEN CONCAT('id:', codigo_cliente_omie)
                    WHEN NULLIF(TRIM(COALESCE(nome_cliente, '')), '') IS NOT NULL THEN CONCAT('name:', TRIM(COALESCE(nome_cliente, '')))
                    ELSE NULL
                END) AS clientes
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'billed_value'      => (float) ($row['total'] ?? 0),
            'billed_orders'     => (int) ($row['pedidos'] ?? 0),
            'authorized_value'  => 0.0,
            'authorized_orders' => 0,
            'cancelled_value'   => 0.0,
            'cancelled_orders'  => 0,
            'merchandise_total' => (float) ($row['mercadorias'] ?? 0),
            'discounts_total'   => (float) ($row['descontos'] ?? 0),
            'active_vendors'    => (int) ($row['vendedores'] ?? 0),
            'active_clients'    => (int) ($row['clientes'] ?? 0),
        ];
    }

    /**
     * @return array<string,int|float>
     */
    public function summarizePendingCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total
            FROM biomie_pedidos_canonicos
            WHERE status_comercial IN ('autorizado', 'outro')
              AND data_emissao BETWEEN :start AND :end
            GROUP BY status_comercial
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);

        $result = [
            'authorized_value'  => 0.0,
            'authorized_orders' => 0,
            'cancelled_value'   => 0.0,
            'cancelled_orders'  => 0,
            'quote_value'       => 0.0,
            'quote_orders'      => 0,
        ];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['status_comercial'] ?? 'outro');
            $orders = (int) ($row['pedidos'] ?? 0);
            $total  = (float) ($row['total'] ?? 0);
            if ($status === 'autorizado') {
                $result['authorized_value']  = $total;
                $result['authorized_orders'] = $orders;
            } else {
                $result['quote_value']  += $total;
                $result['quote_orders'] += $orders;
            }
        }

        $cancelledSummary = $this->summarizeCancelledCommercial($start, $end);
        $result['cancelled_value']  = (float) ($cancelledSummary['invoice_total'] ?? 0);
        $result['cancelled_orders'] = (int) ($cancelledSummary['notes_count'] ?? 0);

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateInvoicesByVendorCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                'faturado' AS status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total,
                COALESCE(SUM(valor_mercadorias), 0) AS mercadorias,
                COALESCE(SUM(valor_descontos), 0) AS descontos
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function topProductsCommercial(string $start, string $end, int $limit = 12): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                NULLIF(TRIM(COALESCE(item.codigo_produto, '')), '') AS product_code,
                COALESCE(NULLIF(TRIM(item.descricao_produto), ''), 'Produto sem descricao') AS description,
                COALESCE(SUM(item.quantidade), 0) AS quantity,
                COALESCE(SUM(item.valor_total), 0) AS revenue,
                COUNT(DISTINCT item.nota_key) AS orders
            FROM biomie_notas_fiscais_itens_canonicos item
            INNER JOIN biomie_notas_fiscais_canonicas nota
                ON nota.nota_key = item.nota_key
            WHERE nota.status_fiscal = 'autorizada'
              AND nota.data_competencia BETWEEN :start AND :end
            GROUP BY product_code, description
            ORDER BY revenue DESC, quantity DESC, description ASC
            LIMIT :limit
        SQL);
        $stmt->bindValue('start', $start);
        $stmt->bindValue('end', $end);
        $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function topClientsCommercial(string $start, string $end, int $limit = 12): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_cliente_omie,
                COALESCE(NULLIF(TRIM(nome_cliente), ''), 'Cliente nao informado') AS client_name,
                COALESCE(NULLIF(TRIM(documento_cliente), ''), '') AS client_document,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_total), 0) AS invoice_total,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_descontos), 0) AS discount_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
            GROUP BY codigo_cliente_omie, client_name, client_document
            ORDER BY invoice_total DESC, notes_count DESC, client_name ASC
            LIMIT :limit
        SQL);
        $stmt->bindValue('start', $start);
        $stmt->bindValue('end', $end);
        $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    private function pdo(): PDO
    {
        return ($this->pdoResolver)();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function aggregatePendingByVendorCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor, status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total,
                COALESCE(SUM(valor_mercadorias), 0) AS mercadorias,
                COALESCE(SUM(valor_descontos), 0) AS descontos
            FROM biomie_pedidos_canonicos
            WHERE status_comercial IN ('autorizado', 'outro')
              AND data_emissao BETWEEN :start AND :end
            GROUP BY codigo_vendedor_omie, nome_vendedor, status_comercial
            ORDER BY nome_vendedor, status_comercial
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function aggregateCancelledVendorStatusCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                'cancelado' AS status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total,
                COALESCE(SUM(valor_mercadorias), 0) AS mercadorias,
                COALESCE(SUM(valor_descontos), 0) AS descontos
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'cancelada'
              AND data_competencia BETWEEN :start AND :end
            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function dailyInvoiceStatusRowsCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                data_competencia AS dia,
                'faturado' AS status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
            GROUP BY data_competencia
            ORDER BY data_competencia
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function dailyPendingStatusRowsCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                data_emissao AS dia, status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total
            FROM biomie_pedidos_canonicos
            WHERE status_comercial IN ('autorizado', 'outro')
              AND data_emissao BETWEEN :start AND :end
            GROUP BY data_emissao, status_comercial
            ORDER BY data_emissao, status_comercial
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function dailyCancelledStatusRowsCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                data_competencia AS dia,
                'cancelado' AS status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'cancelada'
              AND data_competencia BETWEEN :start AND :end
            GROUP BY data_competencia
            ORDER BY data_competencia
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mapeia linhas brutas de pedidos canônicos para o formato de exibição.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapDisplayRows(array $rows, string $defaultClient = 'Cliente nao informado'): array
    {
        $orders = [];
        foreach ($rows as $row) {
            $grossTotal  = (float) ($row['valor_total'] ?? 0);
            $merchandise = (float) ($row['valor_mercadorias'] ?? 0);
            $discount    = (float) ($row['valor_descontos'] ?? 0);

            $orders[] = [
                'codigo'             => (string) ($row['codigo_pedido'] ?? ''),
                'codigo_integracao'  => (string) ($row['codigo_integracao'] ?? ''),
                'cliente'            => (string) ($row['nome_cliente'] ?? $defaultClient),
                'cliente_documento'  => (string) ($row['documento_cliente'] ?? ''),
                'vendor_id'          => (string) ($row['codigo_vendedor_omie'] ?? ''),
                'vendor_name'        => (string) ($row['nome_vendedor'] ?? 'Sem vendedor'),
                'issue_date'         => (string) ($row['data_emissao'] ?? ''),
                'billing_date'       => (string) ($row['data_faturamento'] ?? ''),
                'stage'              => (string) ($row['etapa_omie'] ?? $row['status_pedido_omie'] ?? ''),
                'status_group'       => (string) ($row['status_comercial'] ?? 'outro'),
                'items_qty'          => (int) ($row['quantidade_itens'] ?? 0),
                'gross_total'        => $grossTotal,
                'merchandise_total'  => $merchandise,
                'discount_total'     => $discount,
                'total'              => $grossTotal,
            ];
        }
        return $orders;
    }

    /**
     * Mapeia linhas brutas de notas fiscais para o formato de exibição.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapInvoiceDisplayRows(array $rows): array
    {
        $orders = [];
        foreach ($rows as $row) {
            $grossTotal  = (float) ($row['valor_total'] ?? 0);
            $merchandise = (float) ($row['valor_mercadorias'] ?? 0);
            $discount    = (float) ($row['valor_descontos'] ?? 0);
            $number      = trim((string) ($row['numero_nf'] ?? ''));
            $serie       = trim((string) ($row['serie_nf'] ?? ''));
            $codigo      = $number !== ''
                ? trim($number . ($serie !== '' ? '/' . $serie : ''))
                : (string) ($row['chave_nfe'] ?? 'NF sem numero');

            $statusFiscal = (string) ($row['status_fiscal'] ?? 'autorizada');
            $issueDate    = (string) ($row['data_emissao'] ?? '');
            $cancelDate   = (string) ($row['data_cancelamento'] ?? '');
            $statusGroup  = $statusFiscal === 'cancelada' ? 'cancelado' : 'faturado';

            $orders[] = [
                'codigo'             => $codigo,
                'codigo_integracao'  => (string) ($row['codigo_pedido_origem'] ?? ($row['chave_nfe'] ?? '')),
                'cliente'            => (string) ($row['nome_cliente'] ?? 'Cliente nao informado'),
                'cliente_documento'  => (string) ($row['documento_cliente'] ?? ''),
                'vendor_id'          => (string) ($row['codigo_vendedor_omie'] ?? ''),
                'vendor_name'        => (string) ($row['nome_vendedor'] ?? 'Sem vendedor'),
                'issue_date'         => $issueDate,
                'billing_date'       => $issueDate,
                'stage'              => $statusGroup === 'cancelado' ? 'NF CANCELADA' : 'NF AUTORIZADA',
                'status_group'       => $statusGroup,
                'items_qty'          => (int) ($row['quantidade_itens'] ?? 0),
                'gross_total'        => $grossTotal,
                'merchandise_total'  => $merchandise,
                'discount_total'     => $discount,
                'total'              => $grossTotal,
                'status_date'        => $statusGroup === 'cancelado'
                    ? ($cancelDate !== '' ? $cancelDate : $issueDate)
                    : $issueDate,
                'document_key'       => (string) ($row['chave_nfe'] ?? ''),
                'origin_order_code'  => (string) ($row['codigo_pedido_origem'] ?? ''),
            ];
        }
        return $orders;
    }
}
