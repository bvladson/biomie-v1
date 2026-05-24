<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Silver\Readers;

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
                COUNT(DISTINCT item.nota_key) AS orders
            FROM biomie_notas_fiscais_itens_canonicos item
            INNER JOIN biomie_notas_fiscais_canonicas nota
                ON nota.nota_key = item.nota_key
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
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_cliente_omie,
                COALESCE(NULLIF(TRIM(nome_cliente), ''), 'Cliente nao informado') AS client_name,
                COALESCE(NULLIF(TRIM(documento_cliente), ''), '') AS client_document,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_total), 0) AS invoice_total,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_descontos), 0) AS discount_total,
                MAX(data_competencia) AS last_purchase
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
        SQL . $this->commercialScopeFilter() . <<<'SQL'

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

    public function invoiceSelectSql(): string
    {
        // Fase O: include fiscal basis columns when available; fall back to 0 for pre-migration rows.
        // Fase O.1: also include pre-computed base + boolean flags when available.
        $hasFiscal = $this->fiscalBasisColumnsAvailable();
        $hasPreComp = $this->preComputedBaseAvailable();

        $fiscalCols = $hasFiscal
            ? "                nf.valor_ipi,\n                nf.valor_frete,\n                nf.valor_outras_despesas,\n                nf.valor_seguro\n        "
            : "                0 AS valor_ipi,\n                0 AS valor_frete,\n                0 AS valor_outras_despesas,\n                0 AS valor_seguro\n        ";

        $preCompCols = $hasPreComp
            ? ",\n                nf.valor_base_comercial_nf,\n                nf.has_frete,\n                nf.has_outras_despesas,\n                nf.has_seguro,\n                nf.has_componentes_nao_comissionaveis,\n                nf.fiscal_basis_source\n        "
            : ",\n                NULL AS valor_base_comercial_nf,\n                0 AS has_frete,\n                0 AS has_outras_despesas,\n                0 AS has_seguro,\n                0 AS has_componentes_nao_comissionaveis,\n                NULL AS fiscal_basis_source\n        ";

        return <<<'SQL'
            SELECT
                nf.nota_key,
                nf.nota_id_omie,
                nf.chave_nfe,
                nf.numero_nf,
                nf.serie_nf,
                nf.pedido_id_omie,
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
        $sql = <<<'SQL'
            SELECT
                data_competencia AS dia,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS invoice_total
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
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                data_competencia AS dia,
                :commercial_status AS status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = :status
              AND data_competencia BETWEEN :start AND :end
            GROUP BY data_competencia
            ORDER BY data_competencia
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
     * Fase H — retorna cláusula SQL para filtrar escopo comercial.
     *
     * Retorna '' se a coluna operation_scope ainda não existir (pré-migration H3).
     * Retorna "AND {alias}operation_scope = 'commercial'" quando a coluna existir.
     *
     * @param string $tableAlias prefixo de tabela com ponto, ex: 'nota.' ou '' para sem alias
     */
    public function commercialScopeFilter(string $tableAlias = ''): string
    {
        $clauses = '';

        if ($this->operationScopeColumnExists()) {
            $clauses .= " AND {$tableAlias}operation_scope = 'commercial'";
        } elseif ((bool) (getenv('BIOMIE_OPERATION_SCOPE_ENFORCED') ?: false)) {
            throw new OperationScopeEnforcedException(
                'Enforce mode ativo: coluna operation_scope ausente em biomie_notas_fiscais_canonicas. ' .
                'Execute: php tools/dev/backfill_operation_scope.php --migrate'
            );
        }

        // Fase L — Licitação visibility filter
        if ($this->nfVisibilityColumnExists()) {
            $clauses .= " AND COALESCE({$tableAlias}is_biomie_commercial_visible, 1) = 1";
        } elseif ($clauses !== '') {
            // Column not yet migrated: name-based fallback (only when scope column is present)
            $clauses .= " AND NOT UPPER({$tableAlias}nome_vendedor) LIKE '%LICITAC%'";
        }

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
    private ?bool $nfVisibilityColumnExists     = null;

    private function fiscalBasisColumnsAvailable(): bool
    {
        if ($this->fiscalBasisColumnExists !== null) {
            return $this->fiscalBasisColumnExists;
        }

        try {
            $stmt = $this->pdo()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'biomie_notas_fiscais_canonicas'
                   AND COLUMN_NAME = 'valor_frete'"
            );
            $stmt->execute();
            $this->fiscalBasisColumnExists = (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            $this->fiscalBasisColumnExists = false;
        }

        return $this->fiscalBasisColumnExists;
    }

    private function preComputedBaseAvailable(): bool
    {
        if ($this->fiscalBaseComercialExists !== null) {
            return $this->fiscalBaseComercialExists;
        }

        try {
            $stmt = $this->pdo()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'biomie_notas_fiscais_canonicas'
                   AND COLUMN_NAME = 'valor_base_comercial_nf'"
            );
            $stmt->execute();
            $this->fiscalBaseComercialExists = (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            $this->fiscalBaseComercialExists = false;
        }

        return $this->fiscalBaseComercialExists;
    }

    private function operationScopeColumnExists(): bool
    {
        if ($this->scopeColumnExists !== null) {
            return $this->scopeColumnExists;
        }

        try {
            $stmt = $this->pdo()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'biomie_notas_fiscais_canonicas'
                   AND COLUMN_NAME = 'operation_scope'"
            );
            $stmt->execute();
            $this->scopeColumnExists = (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            $this->scopeColumnExists = false;
        }

        return $this->scopeColumnExists;
    }

    private function nfVisibilityColumnExists(): bool
    {
        if ($this->nfVisibilityColumnExists !== null) {
            return $this->nfVisibilityColumnExists;
        }

        try {
            $stmt = $this->pdo()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'biomie_notas_fiscais_canonicas'
                   AND COLUMN_NAME = 'is_biomie_commercial_visible'"
            );
            $stmt->execute();
            $this->nfVisibilityColumnExists = (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            $this->nfVisibilityColumnExists = false;
        }

        return $this->nfVisibilityColumnExists;
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
