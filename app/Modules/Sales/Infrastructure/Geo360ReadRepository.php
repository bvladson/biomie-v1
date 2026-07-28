<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Infrastructure;

use BIomie\Support\CommercialMartScope;
use PDO;
use Throwable;

final class Geo360ReadRepository
{
    /** @var array<string,bool> */
    private array $tableCache = [];

    /** @var array<string,bool> */
    private array $columnCache = [];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string,string> $territory
     */
    public function summary(array $territory, string $start, string $end): array
    {
        try {
            [$where, $params] = $this->territoryWhere($territory, 'm', 'mart_compradores_por_geo');
            $tenantFilter = CommercialMartScope::condition($this->pdo, 'mart_compradores_por_geo', 'm');
            $stmt = $this->pdo->prepare("
                SELECT
                    COALESCE(SUM(m.faturamento), 0) AS revenue,
                    COALESCE(SUM(m.qtd_notas), 0) AS invoices_count,
                    COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS customers_count,
                    IF(SUM(m.qtd_notas) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0) AS avg_ticket,
                    MIN(m.competencia) AS first_sale_at,
                    MAX(m.competencia) AS last_sale_at,
                    COUNT(DISTINCT NULLIF(m.cnae_codigo, '')) AS cnaes_count
                FROM mart_compradores_por_geo m
                WHERE {$tenantFilter}
                  AND m.competencia BETWEEN :start_comp AND :end_comp {$where}
            ");
            $this->execute($stmt, $params + $this->monthParams($start, $end));
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,string> $territory
     * @return array<int,array<string,mixed>>
     */
    public function buyers(array $territory, string $start, string $end, int $limit): array
    {
        try {
            [$where, $params] = $this->territoryWhere($territory, 'm', 'mart_compradores_por_geo');
            $tenantFilter = CommercialMartScope::condition($this->pdo, 'mart_compradores_por_geo', 'm');
            $stmt = $this->pdo->prepare("
                SELECT
                    m.codigo_cliente_omie,
                    MAX(m.cliente_nome) AS customer_name,
                    MAX(m.documento) AS document_masked,
                    SUM(m.faturamento) AS revenue,
                    SUM(m.qtd_notas) AS invoices_count,
                    MAX(m.bairro_norm) AS bairro_norm,
                    MAX(m.cidade_norm) AS cidade_norm
                FROM mart_compradores_por_geo m
                WHERE {$tenantFilter}
                  AND m.competencia BETWEEN :start_comp AND :end_comp {$where}
                GROUP BY m.codigo_cliente_omie
                ORDER BY SUM(m.faturamento) DESC
                LIMIT :limit
            ");
            $this->execute($stmt, $params + $this->monthParams($start, $end), [':limit' => $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,string> $territory
     * @return array<int,array<string,mixed>>
     */
    public function cnaes(array $territory, string $start, string $end, int $limit): array
    {
        try {
            [$where, $params] = $this->territoryWhere($territory, 'm', 'mart_compradores_por_geo');
            $hasCnaeDim = $this->tableExists('biomie_cnae_dim')
                && $this->columnExists('biomie_cnae_dim', 'cnae_codigo_normalizado');
            $join = $hasCnaeDim
                ? "LEFT JOIN biomie_cnae_dim cd ON cd.cnae_codigo_normalizado = REGEXP_REPLACE(COALESCE(m.cnae_codigo, ''), '[^0-9]', '')"
                : '';
            $descExpr = $hasCnaeDim
                ? "COALESCE(NULLIF(cd.cnae_descricao, ''), NULLIF(m.cnae_descricao, ''), 'Sem CNAE')"
                : "COALESCE(NULLIF(m.cnae_descricao, ''), 'Sem CNAE')";

            $tenantFilter = CommercialMartScope::condition($this->pdo, 'mart_compradores_por_geo', 'm');
            $stmt = $this->pdo->prepare("
                SELECT
                    COALESCE(NULLIF(m.cnae_codigo, ''), 'SEM_CNAE') AS cnae_codigo,
                    {$descExpr} AS cnae_descricao,
                    COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS customers_count,
                    SUM(m.faturamento) AS revenue,
                    SUM(m.qtd_notas) AS invoices_count
                FROM mart_compradores_por_geo m
                {$join}
                WHERE {$tenantFilter}
                  AND m.competencia BETWEEN :start_comp AND :end_comp {$where}
                GROUP BY COALESCE(NULLIF(m.cnae_codigo, ''), 'SEM_CNAE'), {$descExpr}
                ORDER BY SUM(m.faturamento) DESC
                LIMIT :limit
            ");
            $this->execute($stmt, $params + $this->monthParams($start, $end), [':limit' => $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,string> $territory
     * @return array<int,array<string,mixed>>
     */
    public function invoices(array $territory, string $start, string $end, int $limit): array
    {
        try {
            if (!$this->tableExists('biomie_clientes_geo_dim')) {
                return [];
            }
            [$where, $params] = $this->territoryWhere($territory, 'g', 'biomie_clientes_geo_dim');
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('n');
            $stmt = $this->pdo->prepare("
                SELECT
                    n.nota_key,
                    n.numero_nf,
                    n.serie_nf,
                    n.data_competencia,
                    n.codigo_cliente_omie,
                    n.codigo_vendedor_omie,
                    n.valor_total AS invoice_total,
                    n.status_fiscal
                FROM biomie_notas_fiscais_canonicas n
                JOIN biomie_clientes_geo_dim g
                  ON g.company_id = n.company_id
                 AND g.codigo_cliente_omie = n.codigo_cliente_omie
                WHERE {$tenantFilter}
                  AND n.status_fiscal = 'autorizada'
                  AND n.data_competencia BETWEEN :start AND :end {$where}
                ORDER BY n.data_competencia DESC, n.valor_total DESC
                LIMIT :limit
            ");
            $this->execute($stmt, $params + [':start' => $start, ':end' => $end], [':limit' => $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,string> $territory
     * @return array<int,array<string,mixed>>
     */
    public function vendors(array $territory, string $start, string $end, int $limit): array
    {
        try {
            if (!$this->tableExists('biomie_clientes_geo_dim')) {
                return [];
            }
            [$where, $params] = $this->territoryWhere($territory, 'g', 'biomie_clientes_geo_dim');
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('n');
            $stmt = $this->pdo->prepare("
                SELECT
                    n.codigo_vendedor_omie,
                    COALESCE(NULLIF(n.nome_vendedor_omie, ''), CONCAT('Vendedor ', n.codigo_vendedor_omie)) AS vendor_name,
                    COUNT(DISTINCT n.nota_key) AS invoices_count,
                    SUM(n.valor_total) AS revenue,
                    COUNT(DISTINCT n.codigo_cliente_omie) AS customers_count
                FROM biomie_notas_fiscais_canonicas n
                JOIN biomie_clientes_geo_dim g
                  ON g.company_id = n.company_id
                 AND g.codigo_cliente_omie = n.codigo_cliente_omie
                WHERE {$tenantFilter}
                  AND n.status_fiscal = 'autorizada'
                  AND n.data_competencia BETWEEN :start AND :end {$where}
                GROUP BY n.codigo_vendedor_omie, vendor_name
                ORDER BY SUM(n.valor_total) DESC
                LIMIT :limit
            ");
            $this->execute($stmt, $params + [':start' => $start, ':end' => $end], [':limit' => $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,string> $territory
     * @return array<int,array<string,mixed>>
     */
    public function timeline(array $territory, string $start, string $end): array
    {
        try {
            [$where, $params] = $this->territoryWhere($territory, 'm', 'mart_compradores_por_geo');
            $tenantFilter = CommercialMartScope::condition($this->pdo, 'mart_compradores_por_geo', 'm');
            $stmt = $this->pdo->prepare("
                SELECT
                    m.competencia AS bucket,
                    SUM(m.faturamento) AS revenue,
                    SUM(m.qtd_notas) AS invoices_count,
                    COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS customers_count
                FROM mart_compradores_por_geo m
                WHERE {$tenantFilter}
                  AND m.competencia BETWEEN :start_comp AND :end_comp {$where}
                GROUP BY m.competencia
                ORDER BY m.competencia ASC
            ");
            $this->execute($stmt, $params + $this->monthParams($start, $end));
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,string> $territory
     */
    public function martRowCount(array $territory, string $start, string $end): int
    {
        try {
            [$where, $params] = $this->territoryWhere($territory, 'm', 'mart_compradores_por_geo');
            $tenantFilter = CommercialMartScope::condition($this->pdo, 'mart_compradores_por_geo', 'm');
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM mart_compradores_por_geo m
                WHERE {$tenantFilter}
                  AND m.competencia BETWEEN :start_comp AND :end_comp {$where}
            ");
            $this->execute($stmt, $params + $this->monthParams($start, $end));
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string,string> $territory
     */
    public function lastGoldMaterializedAt(array $territory): ?string
    {
        try {
            [$where, $params] = $this->territoryWhere($territory, 'm', 'mart_compradores_por_geo');
            $tenantFilter = CommercialMartScope::condition($this->pdo, 'mart_compradores_por_geo', 'm');
            $stmt = $this->pdo->prepare("
                SELECT MAX(m.refreshed_at) FROM mart_compradores_por_geo m
                WHERE {$tenantFilter} {$where}
            ");
            $this->execute($stmt, $params);
            $val = $stmt->fetchColumn();
            return $val ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function lastCanonicalNfAt(): ?string
    {
        try {
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
            $stmt = $this->pdo->query(
                "SELECT MAX(updated_at) FROM biomie_notas_fiscais_canonicas
                 WHERE {$tenantFilter} AND status_fiscal = 'autorizada'"
            );
            $val = $stmt->fetchColumn();
            return $val ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function officialNeighborhoodName(string $neighborhoodKey): ?string
    {
        try {
            if (!$this->tableExists('biomie_bairros_fortaleza_dim')) {
                return null;
            }
            $stmt = $this->pdo->prepare("
                SELECT bairro_nome FROM biomie_bairros_fortaleza_dim
                WHERE LOWER(TRIM(bairro_nome_normalizado)) = :key AND ativo = 1
                LIMIT 1
            ");
            $stmt->execute(['key' => $this->normalizeKey($neighborhoodKey)]);
            $val = $stmt->fetchColumn();
            return $val ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,string> $territory
     * @return array{0:string,1:array<string,string>}
     */
    private function territoryWhere(array $territory, string $alias, string $table): array
    {
        $type = (string) ($territory['territory_type'] ?? '');
        $cidadeKey = $this->normalizeKey((string) ($territory['cidade_key'] ?? $territory['city_key'] ?? ''));
        $bairroKey = $this->normalizeKey((string) ($territory['bairro_key'] ?? $territory['neighborhood_key'] ?? ''));

        if ($type === 'sem_geo' || $type === 'sem_bairro' || $bairroKey === '__sem_bairro__') {
            return [' AND (' . $this->emptyGeoPredicate($alias, $table) . ')', []];
        }

        $where = [];
        $params = [];
        $isNeighborhood = in_array($type, ['fortaleza_bairro', 'metro_bairro'], true);

        if ($isNeighborhood && $cidadeKey === '' && $type === 'fortaleza_bairro') {
            $cidadeKey = 'fortaleza';
        }

        if ($cidadeKey !== '') {
            [$cityWhere, $cityParams] = $this->keyPredicate($alias, $table, 'cidade_key', 'cidade_norm', 'cidade', $cidadeKey);
            $where[] = $cityWhere;
            $params += $cityParams;
        }

        if ($isNeighborhood && $bairroKey !== '') {
            [$bairroWhere, $bairroParams] = $this->keyPredicate($alias, $table, 'bairro_key', 'bairro_norm', 'bairro', $bairroKey);
            $where[] = $bairroWhere;
            $params += $bairroParams;
        }

        if (!$isNeighborhood && $cidadeKey === '' && $bairroKey !== '') {
            [$fallbackWhere, $fallbackParams] = $this->keyPredicate($alias, $table, 'bairro_key', 'bairro_norm', 'bairro', $bairroKey);
            $where[] = $fallbackWhere;
            $params += $fallbackParams;
        }

        if (!$where) {
            return [' AND 1=0', []];
        }

        return [' AND ' . implode(' AND ', $where), $params];
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function keyPredicate(string $alias, string $table, string $keyColumn, string $normColumn, string $prefix, string $key): array
    {
        $key = $this->normalizeKey($key);
        $norm = $this->keyToNorm($key);
        $conditions = ["LOWER(TRIM(COALESCE({$alias}.{$normColumn}, ''))) IN (:{$prefix}_key_norm, :{$prefix}_norm)"];
        $params = [
            ":{$prefix}_key_norm" => $key,
            ":{$prefix}_norm" => $norm,
        ];

        if ($this->columnExists($table, $keyColumn)) {
            $conditions[] = "LOWER(TRIM(COALESCE({$alias}.{$keyColumn}, ''))) = :{$prefix}_key";
            $params[":{$prefix}_key"] = $key;
        }

        return ['(' . implode(' OR ', $conditions) . ')', $params];
    }

    private function emptyGeoPredicate(string $alias, string $table): string
    {
        $cityEmpty = ["{$alias}.cidade_norm IS NULL OR TRIM({$alias}.cidade_norm) = ''"];
        $bairroEmpty = ["{$alias}.bairro_norm IS NULL OR TRIM({$alias}.bairro_norm) = ''"];

        if ($this->columnExists($table, 'cidade_key')) {
            $cityEmpty[] = "{$alias}.cidade_key IS NULL OR TRIM({$alias}.cidade_key) = ''";
        }
        if ($this->columnExists($table, 'bairro_key')) {
            $bairroEmpty[] = "{$alias}.bairro_key IS NULL OR TRIM({$alias}.bairro_key) = ''";
        }

        return '(' . implode(' OR ', $cityEmpty) . ') OR (' . implode(' OR ', $bairroEmpty) . ')';
    }

    /**
     * @return array<string,string>
     */
    private function monthParams(string $start, string $end): array
    {
        return [
            ':start_comp' => substr($start, 0, 7),
            ':end_comp' => substr($end, 0, 7),
        ];
    }

    /**
     * @param array<string,string> $params
     * @param array<string,int> $intParams
     */
    private function execute(\PDOStatement $stmt, array $params, array $intParams = []): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        foreach ($intParams as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    private function normalizeKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                $value = $converted;
            }
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        return trim(preg_replace('/_+/', '_', $value) ?: '', '_');
    }

    private function keyToNorm(string $key): string
    {
        return str_replace('_', ' ', $this->normalizeKey($key));
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            return $this->tableCache[$table] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return $this->tableCache[$table] = false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            return $this->columnCache[$key] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return $this->columnCache[$key] = false;
        }
    }
}
