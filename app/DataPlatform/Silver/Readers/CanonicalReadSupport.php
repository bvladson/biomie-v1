<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Silver\Readers;

use BIomie\SchemaCapabilities;
use BIomie\Support\OperationScopeEnforcedException;
use Closure;
use PDO;
use RuntimeException;

final class CanonicalReadSupport
{
    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    /** @return array<string,array{codigo_omie:string,nome:string,email:?string,comissao:mixed,sincronizado_em:string}> */
    public function getVendorMap(): array
    {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $stmt = $this->pdo()->prepare(
            "SELECT codigo_omie, nome, email, comissao, sincronizado_em
               FROM biomie_vendedores_omie
              WHERE ativo = 1 AND {$tenantFilter}"
        );
        $stmt->execute([]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['codigo_omie']] = $row;
        }

        return $map;
    }

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
               AND nota.company_id = item.company_id
            WHERE nota.status_fiscal = 'autorizada'
              AND nota.data_competencia BETWEEN :start AND :end
        SQL;
        $sql .= $this->commercialScopeFilter('nota.');

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
            $serie = trim((string) ($row['serie_nf'] ?? ''));

            return [
                'product_code' => (string) ($row['codigo_produto'] ?? ''),
                'description' => (string) ($row['descricao_produto'] ?? 'Produto sem descricao'),
                'quantity' => (float) ($row['quantidade'] ?? 0),
                'unit_value' => (float) ($row['valor_unitario'] ?? 0),
                'revenue' => (float) ($row['valor_total'] ?? 0),
                'discount' => (float) ($row['valor_desconto'] ?? 0),
                'nota_key' => (string) ($row['nota_key'] ?? ''),
                'document_key' => (string) ($row['chave_nfe'] ?? ''),
                'nf_code' => $number !== '' ? trim($number . ($serie !== '' ? '/' . $serie : '')) : (string) ($row['chave_nfe'] ?? 'NF sem numero'),
                'origin_order_code' => (string) ($row['codigo_pedido_origem'] ?? ''),
                'vendor_id' => (string) ($row['codigo_vendedor_omie'] ?? ''),
                'vendor_name' => (string) ($row['nome_vendedor'] ?? 'Sem vendedor'),
                'client_id' => (string) ($row['codigo_cliente_omie'] ?? ''),
                'client_name' => (string) ($row['nome_cliente'] ?? 'Cliente nao informado'),
                'client_document' => (string) ($row['documento_cliente'] ?? ''),
                'issue_date' => (string) ($row['data_emissao'] ?? ''),
                'competencia_date' => (string) ($row['data_competencia'] ?? ''),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function topProductsCommercial(string $start, string $end, int $limit = 12): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                NULLIF(TRIM(COALESCE(item.codigo_produto, '')), '') AS product_code,
                COALESCE(NULLIF(TRIM(item.descricao_produto), ''), 'Produto sem descricao') AS description,
                COALESCE(SUM(item.quantidade), 0) AS quantity,
                COALESCE(SUM(item.valor_total), 0) AS revenue,
                COUNT(DISTINCT CONCAT(item.company_id, ':', item.nota_key)) AS orders
            FROM biomie_notas_fiscais_itens_canonicos item
            INNER JOIN biomie_notas_fiscais_canonicas nota
                ON nota.nota_key = item.nota_key
               AND nota.company_id = item.company_id
            WHERE nota.status_fiscal = 'autorizada'
              AND nota.data_competencia BETWEEN :start AND :end
        SQL . $this->commercialScopeFilter('nota.') . <<<'SQL'

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

    public function topClientsCommercial(string $start, string $end, int $limit = 12): array
    {
        $baseSql = $this->commercialBaseSql();
        $stmt = $this->pdo()->prepare(<<<SQL
            SELECT
                CASE
                    WHEN codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0
                        THEN CONCAT(company_id, ':id:', codigo_cliente_omie)
                    WHEN NULLIF(TRIM(COALESCE(documento_cliente, '')), '') IS NOT NULL
                        THEN CONCAT(company_id, ':doc:', TRIM(documento_cliente))
                    WHEN NULLIF(TRIM(COALESCE(nome_cliente, '')), '') IS NOT NULL
                        THEN CONCAT(company_id, ':name:', TRIM(nome_cliente))
                    ELSE NULL
                END AS client_identity,
                MAX(codigo_cliente_omie) AS codigo_cliente_omie,
                COALESCE(MAX(NULLIF(TRIM(nome_cliente), '')), 'Cliente nao informado') AS client_name,
                COALESCE(MAX(NULLIF(TRIM(documento_cliente), '')), '') AS client_document,
                COUNT(*) AS notes_count,
                COALESCE(SUM({$baseSql}), 0) AS invoice_total,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_descontos), 0) AS discount_total,
                MAX(data_competencia) AS last_purchase
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
        SQL . $this->commercialScopeFilter() . <<<'SQL'

            GROUP BY client_identity
            HAVING client_identity IS NOT NULL
            ORDER BY invoice_total DESC, notes_count DESC, client_name ASC
            LIMIT :limit
        SQL);
        $stmt->bindValue('start', $start);
        $stmt->bindValue('end', $end);
        $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function invoiceSelectSql(): string
    {
        // Fase O: include fiscal basis columns when available; fall back to 0 for pre-migration rows.
        // Fase O.1: also include pre-computed base + boolean flags when available.
        $hasFiscal = $this->fiscalBasisColumnsAvailable();
        $hasPreComp = $this->preComputedBaseAvailable();
        $hasProject = SchemaCapabilities::columnExists(
            $this->pdo(),
            'biomie_notas_fiscais_canonicas',
            'projeto_id_omie',
        );
        $hasOrderOperation = SchemaCapabilities::columnExists(
            $this->pdo(),
            'biomie_notas_fiscais_canonicas',
            'pedido_operacao_omie',
        );

        $fiscalCols = $hasFiscal
            ? "                nf.valor_ipi,\n                nf.valor_frete,\n                nf.valor_outras_despesas,\n                nf.valor_seguro\n        "
            : "                0 AS valor_ipi,\n                0 AS valor_frete,\n                0 AS valor_outras_despesas,\n                0 AS valor_seguro\n        ";

        $preCompCols = $hasPreComp
            ? ",\n                nf.valor_base_comercial_nf,\n                nf.has_frete,\n                nf.has_outras_despesas,\n                nf.has_seguro,\n                nf.has_componentes_nao_comissionaveis,\n                nf.fiscal_basis_source\n        "
            : ",\n                NULL AS valor_base_comercial_nf,\n                0 AS has_frete,\n                0 AS has_outras_despesas,\n                0 AS has_seguro,\n                0 AS has_componentes_nao_comissionaveis,\n                NULL AS fiscal_basis_source\n        ";

        $lineageCols = ($hasProject ? 'nf.projeto_id_omie' : 'NULL AS projeto_id_omie')
            . ",\n                "
            . ($hasOrderOperation ? 'nf.pedido_operacao_omie' : 'NULL AS pedido_operacao_omie');

        return <<<'SQL'
            SELECT
                nf.company_id,
                nf.nota_key,
                nf.nota_id_omie,
                nf.chave_nfe,
                nf.numero_nf,
                nf.serie_nf,
                nf.pedido_id_omie,
        SQL . "\n                " . $lineageCols . <<<'SQL'
                ,
                nf.codigo_pedido_origem,
                nf.codigo_vendedor_omie,
                CASE
                    WHEN TRIM(COALESCE(nf.nome_vendedor, '')) IN ('', 'Sem vendedor')
                         AND TRIM(COALESCE(nf.codigo_vendedor_omie, '')) <> ''
                    THEN COALESCE(NULLIF(TRIM(v.nome), ''), 'Sem vendedor')
                    ELSE nf.nome_vendedor
                END AS nome_vendedor,
                nf.codigo_cliente_omie,
                nf.nome_cliente,
                nf.documento_cliente,
                nf.status_fiscal,
                nf.data_emissao,
                nf.data_cancelamento,
                nf.data_competencia,
                nf.valor_total,
                nf.valor_mercadorias,
                nf.valor_descontos,
                nf.quantidade_itens,
        SQL . "\n" . $fiscalCols . $preCompCols;
    }

    public function pendingOrderSelectSql(): string
    {
        return <<<'SQL'
            SELECT
                p.company_id,
                p.pedido_id_omie,
                p.codigo_pedido,
                p.codigo_integracao,
                p.codigo_vendedor_omie,
                CASE
                    WHEN TRIM(COALESCE(p.nome_vendedor, '')) IN ('', 'Sem vendedor')
                         AND TRIM(COALESCE(p.codigo_vendedor_omie, '')) <> ''
                    THEN COALESCE(NULLIF(TRIM(v.nome), ''), 'Sem vendedor')
                    ELSE p.nome_vendedor
                END AS nome_vendedor,
                p.codigo_cliente_omie,
                p.nome_cliente,
                p.documento_cliente,
                p.status_comercial,
                p.etapa_omie,
                p.status_pedido_omie,
                p.data_emissao,
                p.data_faturamento,
                p.valor_total,
                p.valor_mercadorias,
                p.valor_descontos,
                p.quantidade_itens

        SQL;
    }

    public function dailyByFiscalStatus(string $start, string $end, string $status, ?string $vendorId): array
    {
        $commercialBase = $this->commercialBaseSql();
        $sql = <<<SQL
            SELECT
                data_competencia AS dia,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM({$commercialBase}), 0) AS invoice_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = :status
              AND data_competencia BETWEEN :start AND :end
        SQL;

        $params = ['status' => $status, 'start' => $start, 'end' => $end];
        $sql .= $this->commercialScopeFilter();
        if ($vendorId !== null && $vendorId !== '') {
            $sql .= ' AND codigo_vendedor_omie = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }
        $sql .= ' GROUP BY data_competencia ORDER BY data_competencia ASC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function dailyFiscalStatusRows(string $start, string $end, string $status, string $commercialStatus): array
    {
        $commercialBase = $this->commercialBaseSql('nota');
        $scopeFilter = $this->commercialScopeFilter('nota.');
        $stmt = $this->pdo()->prepare(<<<SQL
            SELECT
                nota.data_competencia AS dia,
                :commercial_status AS status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM({$commercialBase}), 0) AS total
            FROM biomie_notas_fiscais_canonicas nota
            WHERE nota.status_fiscal = :status
              AND nota.data_competencia >= :start
              AND nota.data_competencia < DATE_ADD(:end, INTERVAL 1 DAY)
              {$scopeFilter}
            GROUP BY nota.data_competencia
            ORDER BY nota.data_competencia
        SQL);
        $stmt->execute([
            'commercial_status' => $commercialStatus,
            'status' => $status,
            'start' => $start,
            'end' => $end,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna clausulas SQL para filtrar NFs comerciais pelo tenant + escopo + visibilidade.
     * Sempre inclui company_id isolation (Fase multi-empresa).
     *
     * @param string $tableAlias prefixo de tabela com ponto, ex: 'nota.' ou '' para sem alias
     */
    public function commercialScopeFilter(string $tableAlias = ''): string
    {
        // Company isolation — uses sqlFilter() to support consolidado mode (IN clause)
        $cleanAlias = rtrim($tableAlias, '.');
        $clauses    = " AND " . \BIomie\Support\TenantContext::sqlFilter($cleanAlias);

        if ($this->operationScopeColumnExists()) {
            $clauses .= " AND {$tableAlias}operation_scope = 'commercial'";
        } elseif ((bool) (getenv('BIOMIE_OPERATION_SCOPE_ENFORCED') ?: false)) {
            throw new OperationScopeEnforcedException(
                'Enforce mode ativo: coluna operation_scope ausente em biomie_notas_fiscais_canonicas. ' .
                'Execute: php tools/dev/backfill_operation_scope.php --migrate'
            );
        }

        // Fase L — Licitacao visibility filter
        $clauses .= ' AND (' . \BIomie\Support\BiVisibilityScope::getCondition(
            $this->pdo(),
            'biomie_notas_fiscais_canonicas',
            $cleanAlias,
        ) . ')';

        return $clauses;
    }

    /**
     * Fase H — retorna cláusula SQL para filtrar qualquer escopo de operação.
     *
     * Retorna '' se a coluna não existir. $scope deve ser um dos valores válidos
     * ('commercial', 'remittance', 'unknown'). Outros valores retornam ''.
     *
     * @param string $tableAlias prefixo de tabela com ponto, ex: 'nf.'
     * @param string $scope      'commercial', 'remittance' ou 'unknown'
     */
    public function scopeFilter(string $tableAlias, string $scope): string
    {
        if (!in_array($scope, ['commercial', 'remittance', 'unknown'], true)) {
            return '';
        }

        if (!$this->operationScopeColumnExists()) {
            return '';
        }

        return " AND {$tableAlias}operation_scope = '$scope'";
    }

    public function operationScopeAvailable(): bool
    {
        return $this->operationScopeColumnExists();
    }

    /**
     * Fase O.1 — retorna expressão SQL para base comercial em 3 tiers:
     *   1. valor_base_comercial_nf pré-computado (se disponível e não-null)
     *   2. Fórmula inline (valor_total − frete − outras − seguro) quando colunas existem
     *   3. Fallback para {alias}valor_total pré-Fase O
     *
     * @param string $alias alias de tabela sem ponto ('nf', 'n', '') — ponto adicionado internamente
     */
    public function commercialBaseSql(string $alias = ''): string
    {
        if ($this->preComputedBaseAvailable()) {
            return \BIomie\Support\FiscalValueBasis::preComputedBaseSql($alias);
        }

        if ($this->fiscalBasisColumnsAvailable()) {
            return \BIomie\Support\FiscalValueBasis::commercialBaseSql($alias);
        }

        $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return "{$p}valor_total";
    }

    public function fiscalBasisAvailable(): bool
    {
        return $this->fiscalBasisColumnsAvailable();
    }

    private ?bool $scopeColumnExists            = null;
    private ?bool $fiscalBasisColumnExists      = null;
    private ?bool $fiscalBaseComercialExists    = null;

    private function fiscalBasisColumnsAvailable(): bool
    {
        if ($this->fiscalBasisColumnExists !== null) {
            return $this->fiscalBasisColumnExists;
        }

        $this->fiscalBasisColumnExists = SchemaCapabilities::columnExists(
            $this->pdo(),
            'biomie_notas_fiscais_canonicas',
            'valor_frete',
        );

        return $this->fiscalBasisColumnExists;
    }

    private function preComputedBaseAvailable(): bool
    {
        if ($this->fiscalBaseComercialExists !== null) {
            return $this->fiscalBaseComercialExists;
        }

        $this->fiscalBaseComercialExists = SchemaCapabilities::columnExists(
            $this->pdo(),
            'biomie_notas_fiscais_canonicas',
            'valor_base_comercial_nf',
        );

        return $this->fiscalBaseComercialExists;
    }

    private function operationScopeColumnExists(): bool
    {
        if ($this->scopeColumnExists !== null) {
            return $this->scopeColumnExists;
        }

        $this->scopeColumnExists = SchemaCapabilities::columnExists(
            $this->pdo(),
            'biomie_notas_fiscais_canonicas',
            'operation_scope',
        );

        return $this->scopeColumnExists;
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
