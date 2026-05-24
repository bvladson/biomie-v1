<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Infrastructure;

use BIomie\DataPlatform\Silver\Readers\CanonicalDimensionEnricher;
use BIomie\DataPlatform\Silver\Readers\CanonicalDisplayMapper;
use BIomie\DataPlatform\Silver\Readers\CanonicalReadSupport;
use BIomie\Support;
use Closure;
use PDO;
use RuntimeException;

final class FiscalReadRepository
{
    private ?CanonicalDisplayMapper $displayMapper = null;
    private ?CanonicalDimensionEnricher $dimensionEnricher = null;
    private ?CanonicalReadSupport $readSupport = null;

    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

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
     * @return list<array<string,mixed>>
     */
    public function aggregateInvoicedByVendorCommercial(string $start, string $end): array
    {
        $sql = <<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS invoice_total,
                COALESCE(SUM(valor_descontos), 0) AS discount_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
        SQL . $this->readSupport()->commercialScopeFilter() . <<<'SQL'

            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor ASC
        SQL;
        $stmt = $this->pdo()->prepare($sql);
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
    public function aggregateInvoicesByVendorCommercial(string $start, string $end): array
    {
        $sql = <<<'SQL'
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
        SQL . $this->readSupport()->commercialScopeFilter() . <<<'SQL'

            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor
        SQL;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateCancelledVendorStatusCommercial(string $start, string $end): array
    {
        $sql = <<<'SQL'
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
        SQL . $this->readSupport()->commercialScopeFilter() . <<<'SQL'

            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor
        SQL;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyInvoicedCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->readSupport()->dailyByFiscalStatus($start, $end, 'autorizada', $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyCancelledCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->readSupport()->dailyByFiscalStatus($start, $end, 'cancelada', $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyInvoiceStatusRowsCommercial(string $start, string $end): array
    {
        return $this->readSupport()->dailyFiscalStatusRows($start, $end, 'autorizada', 'faturado');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyCancelledStatusRowsCommercial(string $start, string $end): array
    {
        return $this->readSupport()->dailyFiscalStatusRows($start, $end, 'cancelada', 'cancelado');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicedOrdersForDisplayCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        $rows = $this->fetchInvoiceRows($start, $end, 'autorizada', $vendorId, $this->readSupport()->commercialScopeFilter('nf.'));

        return $this->mapInvoiceDisplayRows($this->enrichCustomerSellerIdentityRows($rows));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesCommercial(string $start, string $end): array
    {
        return $this->fetchInvoiceRows($start, $end, 'autorizada', null, $this->readSupport()->commercialScopeFilter('nf.'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesCommercial(string $start, string $end): array
    {
        return $this->fetchInvoiceRows($start, $end, 'cancelada', null, $this->readSupport()->commercialScopeFilter('nf.'));
    }

    /**
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

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->mapInvoiceDisplayRows($this->enrichCustomerSellerIdentityRows($this->listInvoicesCommercial($start, $end)));
    }

    /**
     * Fase H — NFs com operation_scope = 'remittance'.
     * Retorna lista vazia se o campo ainda não existir (pré-migration H3).
     *
     * @return list<array<string,mixed>>
     */
    public function listInvoicesForDisplayRemittance(string $start, string $end): array
    {
        $scopeFilter = $this->readSupport()->scopeFilter('nf.', 'remittance');
        if ($scopeFilter === '') {
            return [];
        }

        $sql = $this->readSupport()->invoiceSelectSql() . <<<'SQL'
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = nf.codigo_vendedor_omie
               AND v.ativo = 1
            WHERE nf.status_fiscal = 'autorizada'
              AND nf.data_competencia BETWEEN :start AND :end
        SQL . $scopeFilter . <<<'SQL'

            ORDER BY nf.data_competencia DESC, nf.numero_nf DESC, nf.serie_nf DESC
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $this->mapInvoiceDisplayRows($this->enrichCustomerSellerIdentityRows($stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function operationScopeAvailable(): bool
    {
        return $this->readSupport()->operationScopeAvailable();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listTopInvoicesForDisplayCommercial(string $start, string $end, int $limit = 8): array
    {
        $sql = $this->readSupport()->invoiceSelectSql() . <<<'SQL'
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = nf.codigo_vendedor_omie
               AND v.ativo = 1
            WHERE nf.status_fiscal = 'autorizada'
              AND nf.data_competencia BETWEEN :start AND :end
        SQL . $this->readSupport()->commercialScopeFilter('nf.') . <<<'SQL'

            ORDER BY nf.valor_total DESC, nf.data_competencia DESC, nf.numero_nf DESC
            LIMIT :limit
        SQL;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->bindValue('start', $start);
        $stmt->bindValue('end', $end);
        $stmt->bindValue('limit', min(max($limit, 1), 50), PDO::PARAM_INT);
        $stmt->execute();

        return $this->mapInvoiceDisplayRows($this->enrichCustomerSellerIdentityRows($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->mapInvoiceDisplayRows($this->enrichCustomerSellerIdentityRows($this->listCancelledInvoicesCommercial($start, $end)));
    }

    /**
     * @return array<string,int|float>
     */
    public function summarizeInvoicesCommercial(string $start, string $end): array
    {
        // Fase O: usar valor_base_comercial (valor_total - frete - outras - seguro) como receita comercial.
        $baseSql = $this->readSupport()->commercialBaseSql();
        $sql = "
            SELECT
                COUNT(*) AS pedidos,
                COALESCE(SUM({$baseSql}), 0) AS total,
                COALESCE(SUM(valor_total), 0) AS total_nf_bruto,
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
        " . $this->readSupport()->commercialScopeFilter();
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'billed_value'      => (float) ($row['total'] ?? 0),          // Fase O: valor_base_comercial
            'billed_value_nf'   => (float) ($row['total_nf_bruto'] ?? 0), // valor_total bruto (diagnóstico)
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
     * @return list<array<string,mixed>>
     */
    private function fetchInvoiceRows(string $start, string $end, string $status, ?string $vendorId = null, string $extraWhere = ''): array
    {
        $sql = $this->readSupport()->invoiceSelectSql() . <<<'SQL'
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = nf.codigo_vendedor_omie
               AND v.ativo = 1
            WHERE nf.status_fiscal = :status
              AND nf.data_competencia BETWEEN :start AND :end
        SQL;

        if ($extraWhere !== '') {
            $sql .= $extraWhere;
        }

        $params = ['status' => $status, 'start' => $start, 'end' => $end];
        if ($vendorId !== null && $vendorId !== '') {
            $sql .= ' AND nf.codigo_vendedor_omie = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }
        $sql .= ' ORDER BY nf.data_competencia DESC, nf.numero_nf DESC, nf.serie_nf DESC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function enrichCustomerSellerIdentityRows(array $rows): array
    {
        return $this->dimensionEnricher()->enrichCustomerSellerIdentityRows($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapInvoiceDisplayRows(array $rows): array
    {
        return $this->displayMapper()->mapInvoiceRows($rows);
    }

    private function displayMapper(): CanonicalDisplayMapper
    {
        return $this->displayMapper ??= new CanonicalDisplayMapper();
    }

    private function dimensionEnricher(): CanonicalDimensionEnricher
    {
        return $this->dimensionEnricher ??= new CanonicalDimensionEnricher($this->pdoResolver);
    }

    private function readSupport(): CanonicalReadSupport
    {
        return $this->readSupport ??= new CanonicalReadSupport($this->pdoResolver);
    }

    private function pdo(): PDO
    {
        $pdo = ($this->pdoResolver)();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('PDO resolver returned an invalid connection.');
        }

        return $pdo;
    }
}
