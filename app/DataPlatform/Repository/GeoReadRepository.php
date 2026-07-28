<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Repository;

use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\CommercialMartScope;
use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\Support\GeoMapSupport;
use BIomie\Support\NeighborhoodNormalizer;
use BIomie\Support\TenantContext;
use PDO;
use Throwable;

final class GeoReadRepository
{
    /** @var array<string,bool> */
    private array $tableCache = [];

    /** @var array<string,bool> */
    private array $columnCache = [];

    /** @var array<string,array<string,bool>> */
    private array $tableColumnsCache = [];

    private int $queryCount = 0;

    private float $sqlTimeMs = 0.0;

    public function __construct(private readonly PDO $pdo) {}

    /** @return array{query_count:int,sql_time_ms:float} */
    public function metrics(): array
    {
        return [
            'query_count' => $this->queryCount,
            'sql_time_ms' => round($this->sqlTimeMs, 2),
        ];
    }

    public function resetMetrics(): void
    {
        $this->queryCount = 0;
        $this->sqlTimeMs = 0.0;
    }

    /** @return array<string,mixed> */
    public function summary(GeoQuery $query): array
    {
        [$whereSql, $params] = $this->geoWhere($query, 'm');

        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(m.faturamento), 0) AS revenue,
                COALESCE(SUM(CASE WHEN CHAR_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(m.documento, ''), '.', ''), '-', ''), '/', ''), ' ', '')) = 14 THEN m.faturamento ELSE 0 END), 0) AS pj_value_raw,
                COALESCE(SUM(CASE WHEN CHAR_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(m.documento, ''), '.', ''), '-', ''), '/', ''), ' ', '')) = 11 THEN m.faturamento ELSE 0 END), 0) AS pf_value_raw,
                COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS customers,
                COALESCE(SUM(m.qtd_notas), 0) AS invoices,
                IF(SUM(m.qtd_notas) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0) AS average_ticket,
                COUNT(DISTINCT CASE WHEN m.bairro_norm IS NOT NULL AND TRIM(m.bairro_norm) <> '' AND LOWER(TRIM(m.bairro_norm)) NOT IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro') THEN m.bairro_norm END) AS neighborhoods_with_sales,
                SUM(CASE WHEN m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '' THEN 1 ELSE 0 END) AS rows_without_city,
                SUM(CASE WHEN m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '' OR LOWER(TRIM(m.bairro_norm)) IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro') THEN 1 ELSE 0 END) AS rows_without_neighborhood,
                COALESCE(SUM(CASE WHEN m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '' THEN m.faturamento ELSE 0 END), 0) AS revenue_without_city,
                COALESCE(SUM(CASE WHEN m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '' OR LOWER(TRIM(m.bairro_norm)) IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro') THEN m.faturamento ELSE 0 END), 0) AS revenue_without_neighborhood,
                SUM(CASE WHEN m.cnae_codigo IS NULL OR TRIM(m.cnae_codigo) = '' THEN 1 ELSE 0 END) AS rows_without_cnae,
                MAX(m.refreshed_at) AS last_materialized_at
            FROM mart_compradores_por_geo m
            WHERE {$whereSql}
        ");
        $this->execute($stmt, $params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Consolidates summary, rankings and freshness in one database round trip.
     *
     * @return array{summary:array<string,mixed>,segments:list<array<string,mixed>>,top_buyers:list<array<string,mixed>>,top_cnaes:list<array<string,mixed>>,freshness:array<string,mixed>}
     */
    public function summaryBundle(GeoQuery $query, int $limit = 10): array
    {
        [$whereSql, $params] = $this->geoWhere($query, 'm');
        $groupExpr = $this->groupExpressionForLayer($query);
        $sql = "
            WITH filtered AS (
                SELECT m.*
                FROM mart_compradores_por_geo m
                WHERE {$whereSql}
            )
            SELECT 'summary' AS row_type, JSON_OBJECT(
                'revenue', COALESCE(SUM(m.faturamento), 0),
                'pj_value_raw', COALESCE(SUM(CASE WHEN CHAR_LENGTH(REGEXP_REPLACE(COALESCE(m.documento, ''), '[^0-9]', '')) = 14 THEN m.faturamento ELSE 0 END), 0),
                'pf_value_raw', COALESCE(SUM(CASE WHEN CHAR_LENGTH(REGEXP_REPLACE(COALESCE(m.documento, ''), '[^0-9]', '')) = 11 THEN m.faturamento ELSE 0 END), 0),
                'customers', COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))),
                'invoices', COALESCE(SUM(m.qtd_notas), 0),
                'average_ticket', IF(SUM(m.qtd_notas) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0),
                'neighborhoods_with_sales', COUNT(DISTINCT CASE WHEN m.bairro_norm IS NOT NULL AND TRIM(m.bairro_norm) <> '' AND LOWER(TRIM(m.bairro_norm)) NOT IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro') THEN m.bairro_norm END),
                'rows_without_city', SUM(CASE WHEN m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '' THEN 1 ELSE 0 END),
                'rows_without_neighborhood', SUM(CASE WHEN m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '' OR LOWER(TRIM(m.bairro_norm)) IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro') THEN 1 ELSE 0 END),
                'revenue_without_city', COALESCE(SUM(CASE WHEN m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '' THEN m.faturamento ELSE 0 END), 0),
                'revenue_without_neighborhood', COALESCE(SUM(CASE WHEN m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '' OR LOWER(TRIM(m.bairro_norm)) IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro') THEN m.faturamento ELSE 0 END), 0),
                'rows_without_cnae', SUM(CASE WHEN m.cnae_codigo IS NULL OR TRIM(m.cnae_codigo) = '' THEN 1 ELSE 0 END),
                'last_materialized_at', MAX(m.refreshed_at),
                'min_competencia', MIN(m.competencia),
                'max_competencia', MAX(m.competencia)
            ) AS payload
            FROM filtered m

            UNION ALL

            SELECT 'segment', JSON_OBJECT(
                'name', s.name,
                'cidade_norm', s.cidade_norm,
                'bairro_norm', s.bairro_norm,
                'value_raw', s.value_raw,
                'notes', s.notes,
                'clients', s.clients
            )
            FROM (
                SELECT
                    {$groupExpr} AS name,
                    MAX(m.cidade_norm) AS cidade_norm,
                    MAX(m.bairro_norm) AS bairro_norm,
                    SUM(m.faturamento) AS value_raw,
                    SUM(m.qtd_notas) AS notes,
                    COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS clients
                FROM filtered m
                GROUP BY {$groupExpr}
                ORDER BY value_raw DESC
                LIMIT {$limit}
            ) s

            UNION ALL

            SELECT 'buyer', JSON_OBJECT(
                'cliente_nome', b.cliente_nome,
                'codigo_cliente_omie', b.codigo_cliente_omie,
                'documento', b.documento,
                'bairro', b.bairro,
                'cidade', b.cidade,
                'cnae_codigo', b.cnae_codigo,
                'notas', b.notas,
                'faturamento', b.faturamento,
                'ultima_compra', b.ultima_compra
            )
            FROM (
                SELECT
                    m.cliente_nome,
                    MAX(m.codigo_cliente_omie) AS codigo_cliente_omie,
                    m.documento,
                    COALESCE(NULLIF(m.bairro_norm,''), 'Nao informado') AS bairro,
                    m.cidade_norm AS cidade,
                    m.cnae_codigo,
                    SUM(m.qtd_notas) AS notas,
                    SUM(m.faturamento) AS faturamento,
                    MAX(m.ultima_compra) AS ultima_compra
                FROM filtered m
                GROUP BY m.company_id, m.cliente_nome, m.documento, m.bairro_norm, m.cidade_norm, m.cnae_codigo
                ORDER BY faturamento DESC
                LIMIT {$limit}
            ) b

            UNION ALL

            SELECT 'cnae', JSON_OBJECT(
                'cnae_codigo', c.cnae_codigo,
                'cnae_descricao', c.cnae_descricao,
                'clientes', c.clientes,
                'faturamento', c.faturamento,
                'notas', c.notas
            )
            FROM (
                SELECT
                    COALESCE(NULLIF(m.cnae_codigo, ''), 'SEM_CNAE') AS cnae_codigo,
                    COALESCE(NULLIF(MAX(m.cnae_descricao), ''), 'CNAE nao informado') AS cnae_descricao,
                    COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS clientes,
                    SUM(m.faturamento) AS faturamento,
                    SUM(m.qtd_notas) AS notas
                FROM filtered m
                GROUP BY COALESCE(NULLIF(m.cnae_codigo, ''), 'SEM_CNAE')
                ORDER BY faturamento DESC
                LIMIT {$limit}
            ) c
        ";
        $stmt = $this->pdo->prepare($sql);
        $this->execute($stmt, $params);

        $bundle = [
            'summary' => [],
            'segments' => [],
            'top_buyers' => [],
            'top_cnaes' => [],
            'freshness' => [],
        ];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
            if (!is_array($payload)) continue;
            switch ((string) ($row['row_type'] ?? '')) {
                case 'summary':
                    $bundle['summary'] = $payload;
                    $bundle['freshness'] = [
                        'mart_compradores_por_geo' => [
                            'exists' => true,
                            'last_refreshed_at' => $payload['last_materialized_at'] ?? null,
                            'min_competencia' => $payload['min_competencia'] ?? null,
                            'max_competencia' => $payload['max_competencia'] ?? null,
                        ],
                    ];
                    break;
                case 'segment':
                    $bundle['segments'][] = $payload;
                    break;
                case 'buyer':
                    $bundle['top_buyers'][] = $payload;
                    break;
                case 'cnae':
                    $bundle['top_cnaes'][] = $payload;
                    break;
            }
        }
        unset($bundle['summary']['min_competencia'], $bundle['summary']['max_competencia']);
        return $bundle;
    }

    /** @return list<array<string,mixed>> */
    public function segments(GeoQuery $query, int $limit = 10): array
    {
        [$whereSql, $params] = $this->geoWhere($query, 'm');
        $groupExpr = $this->groupExpressionForLayer($query);

        $stmt = $this->pdo->prepare("
            SELECT
                {$groupExpr} AS name,
                MAX(m.cidade_norm) AS cidade_norm,
                MAX(m.bairro_norm) AS bairro_norm,
                SUM(m.faturamento) AS value_raw,
                SUM(m.qtd_notas) AS notes,
                COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS clients
            FROM mart_compradores_por_geo m
            WHERE {$whereSql}
            GROUP BY {$groupExpr}
            ORDER BY value_raw DESC
            LIMIT :limit
        ");
        $this->execute($stmt, $params, [':limit' => $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function topBuyers(GeoQuery $query, int $limit = 10): array
    {
        [$whereSql, $params] = $this->geoWhere($query, 'm');

        $stmt = $this->pdo->prepare("
            SELECT
                m.cliente_nome,
                MAX(m.codigo_cliente_omie) AS codigo_cliente_omie,
                m.documento,
                COALESCE(NULLIF(m.bairro_norm,''), 'Nao informado') AS bairro,
                m.cidade_norm AS cidade,
                m.cnae_codigo,
                SUM(m.qtd_notas) AS notas,
                SUM(m.faturamento) AS faturamento,
                MAX(m.ultima_compra) AS ultima_compra
            FROM mart_compradores_por_geo m
            WHERE {$whereSql}
            GROUP BY m.company_id, m.cliente_nome, m.documento, m.bairro_norm, m.cidade_norm, m.cnae_codigo
            ORDER BY faturamento DESC
            LIMIT :limit
        ");
        $this->execute($stmt, $params, [':limit' => $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function topCnaes(GeoQuery $query, int $limit = 10): array
    {
        [$whereSql, $params] = $this->geoWhere($query, 'm');
        $hasCnaeDim = $this->tableExists('biomie_cnae_dim')
            && $this->columnExists('biomie_cnae_dim', 'cnae_codigo_normalizado');
        $join = $hasCnaeDim
            ? "LEFT JOIN biomie_cnae_dim cd ON cd.cnae_codigo_normalizado = REGEXP_REPLACE(COALESCE(m.cnae_codigo, ''), '[^0-9]', '')"
            : '';
        $description = $hasCnaeDim
            ? "COALESCE(NULLIF(MAX(cd.cnae_descricao), ''), NULLIF(MAX(m.cnae_descricao), ''), 'CNAE nao informado')"
            : "COALESCE(NULLIF(MAX(m.cnae_descricao), ''), 'CNAE nao informado')";

        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(NULLIF(m.cnae_codigo, ''), 'SEM_CNAE') AS cnae_codigo,
                {$description} AS cnae_descricao,
                COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS clientes,
                SUM(m.faturamento) AS faturamento,
                SUM(m.qtd_notas) AS notas
            FROM mart_compradores_por_geo m
            {$join}
            WHERE {$whereSql}
            GROUP BY COALESCE(NULLIF(m.cnae_codigo, ''), 'SEM_CNAE')
            ORDER BY faturamento DESC
            LIMIT :limit
        ");
        $this->execute($stmt, $params, [':limit' => $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    public function compositeMap(GeoQuery $query): array
    {
        [$whereSql, $params] = $this->geoWhere($query, 'm', false);

        $stmt = $this->pdo->prepare("
            SELECT
                LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))) AS cidade_key,
                CASE
                    WHEN LOWER(TRIM(COALESCE(m.bairro_norm, ''))) IN ('', 'nao informado', 'nao_informado', 'sem bairro', 'sem_bairro') THEN ''
                    ELSE LOWER(TRIM(m.bairro_norm))
                END AS bairro_raw,
                SUM(m.faturamento) AS revenue,
                COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS customers,
                SUM(m.qtd_notas) AS invoices,
                IF(SUM(m.qtd_notas)>0, SUM(m.faturamento)/SUM(m.qtd_notas), 0) AS ticket_medio,
                MAX(m.refreshed_at) AS refreshed_at
            FROM mart_compradores_por_geo m
            WHERE {$whereSql}
            GROUP BY LOWER(TRIM(m.cidade_norm)), LOWER(TRIM(m.bairro_norm))
            ORDER BY revenue DESC
        ");
        $this->execute($stmt, $params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $classified = $this->classifyCompositeRows($rows);

        return [
            'summary' => $classified['summary'],
            'territories' => $classified['territories'],
            'ranking' => $classified['ranking'],
            'scales' => $classified['scales'],
        ];
    }

    /**
     * Compatibility payload for sales_by_neighborhood.php.
     *
     * @return array<string,mixed>
     */
    public function neighborhoodSales(GeoQuery $query, ?string $resolvedBairroKey = null): array
    {
        $hasBairrosDim = $this->tableExists('biomie_bairros_fortaleza_dim');
        $hasBairroKeyCol = $this->columnExists('mart_compradores_por_geo', 'bairro_key');
        $isMetroLayer = $query->isMetroNeighborhoodLayer();
        $isMultiCityLayer = $this->isMunicipalityLayer($query);
        $cityNorm = $query->city ?? GeoMapSupport::normalizeKey('Fortaleza');
        $cityDisplay = (string) ($query->received['city'] ?? NeighborhoodNormalizer::displayName($cityNorm));

        $where = ['m.competencia BETWEEN :start_comp AND :end_comp', TenantContext::sqlFilter('m')];
        $params = [
            'start_comp' => $query->startComp,
            'end_comp' => $query->endComp,
        ];

        if (!$isMetroLayer && !$isMultiCityLayer) {
            $where[] = 'LOWER(TRIM(m.cidade_norm)) = :city';
            $params['city'] = $cityNorm;
        }

        if ($resolvedBairroKey !== null && $resolvedBairroKey !== '') {
            if ($hasBairroKeyCol) {
                $where[] = '(m.bairro_key = :bairro_key OR (m.bairro_key IS NULL AND m.bairro_norm COLLATE utf8mb4_general_ci = :bairro_key_fb))';
                $params['bairro_key'] = $resolvedBairroKey;
                $params['bairro_key_fb'] = $resolvedBairroKey;
            } else {
                $where[] = 'LOWER(TRIM(m.bairro_norm)) = :neighborhood';
                $params['neighborhood'] = GeoKeyNormalizer::keyToNorm($resolvedBairroKey);
            }
        } elseif ($query->neighborhood !== null) {
            $where[] = 'LOWER(TRIM(m.bairro_norm)) = :neighborhood';
            $params['neighborhood'] = $query->neighborhood;
        }

        if ($query->cnae !== null) {
            if (strtoupper($query->cnae) === 'SEM_CNAE') {
                $where[] = "(m.cnae_codigo IS NULL OR TRIM(m.cnae_codigo) = '')";
            } else {
                $where[] = 'm.cnae_codigo = :cnae';
                $params['cnae'] = $query->cnae;
            }
        }

        // FILTRO COMERCIAL (seletor global): casa por CÓDIGO — exato. Tem
        // precedência sobre o filtro legado por NOME (LIKE), que confunde
        // homônimos como "CAIO - VAR" e "CAIO - REV".
        if ($query->vendorIds !== [] && $this->columnExists('mart_compradores_por_geo', 'codigo_vendedor_omie')) {
            $ph = [];
            foreach ($query->vendorIds as $i => $vid) {
                $key = 'geo_vendor_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = (string) $vid;
            }
            $where[] = 'm.codigo_vendedor_omie IN (' . implode(', ', $ph) . ')';
        } elseif ($query->sellerId !== null && $this->columnExists('mart_compradores_por_geo', 'nome_vendedor')) {
            $where[] = 'LOWER(m.nome_vendedor) LIKE :seller_pct';
            $params['seller_pct'] = '%' . mb_strtolower(trim($query->sellerId)) . '%';
        }

        $whereSql = implode(' AND ', $where);

        if ($isMultiCityLayer) {
            $sql = "
                SELECT
                    LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))) AS neighborhood_key,
                    LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))) AS neighborhood,
                    LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))) AS cidade_key_raw,
                    LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))) AS bairro_norm_raw,
                    SUM(m.faturamento) AS revenue,
                    COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS customers,
                    SUM(m.qtd_notas) AS invoices,
                    IF(SUM(m.qtd_notas)>0, SUM(m.faturamento)/SUM(m.qtd_notas),0) AS average_ticket,
                    MAX(m.refreshed_at) AS last_materialized_at,
                    1 AS tem_venda
                FROM mart_compradores_por_geo m
                WHERE {$whereSql}
                  AND LOWER(TRIM(COALESCE(m.cidade_norm,''))) != ''
                GROUP BY LOWER(TRIM(m.cidade_norm))
                ORDER BY revenue DESC
            ";
        } elseif ($isMetroLayer) {
            $sql = "
                SELECT
                    CONCAT(
                        LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))),
                        ':',
                        LOWER(TRIM(COALESCE(NULLIF(m.bairro_norm,''), 'sem_bairro')))
                    ) AS neighborhood_key,
                    CONCAT(
                        LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))),
                        ':',
                        LOWER(TRIM(COALESCE(NULLIF(m.bairro_norm,''), 'sem_bairro')))
                    ) AS neighborhood,
                    LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))) AS cidade_key_raw,
                    LOWER(TRIM(COALESCE(NULLIF(m.bairro_norm,''), 'sem_bairro'))) AS bairro_norm_raw,
                    SUM(m.faturamento) AS revenue,
                    COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS customers,
                    SUM(m.qtd_notas) AS invoices,
                    IF(SUM(m.qtd_notas)>0, SUM(m.faturamento)/SUM(m.qtd_notas), 0) AS average_ticket,
                    MAX(m.refreshed_at) AS last_materialized_at,
                    1 AS tem_venda
                FROM mart_compradores_por_geo m
                WHERE {$whereSql}
                  AND LOWER(TRIM(COALESCE(m.bairro_norm,''))) NOT IN ('','nao informado','nao_informado','sem bairro')
                  AND LOWER(TRIM(COALESCE(m.cidade_norm,''))) != ''
                GROUP BY LOWER(TRIM(m.cidade_norm)), LOWER(TRIM(m.bairro_norm))
                ORDER BY revenue DESC
            ";
        } elseif ($hasBairrosDim && $resolvedBairroKey === null && $query->neighborhood === null) {
            $sql = "
                SELECT
                    b.bairro_nome AS neighborhood,
                    b.bairro_nome_normalizado AS neighborhood_key,
                    COALESCE(SUM(m.faturamento), 0) AS revenue,
                    COALESCE(COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))), 0) AS customers,
                    COALESCE(SUM(m.qtd_notas), 0) AS invoices,
                    IF(COALESCE(SUM(m.qtd_notas), 0) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0) AS average_ticket,
                    MAX(m.refreshed_at) AS last_materialized_at,
                    (COALESCE(SUM(m.qtd_notas), 0) > 0 OR COALESCE(SUM(m.faturamento), 0) > 0) AS tem_venda
                FROM biomie_bairros_fortaleza_dim b
                LEFT JOIN mart_compradores_por_geo m
                  ON LOWER(TRIM(m.bairro_norm)) = LOWER(TRIM(b.bairro_nome_normalizado))
                 AND {$whereSql}
                WHERE b.ativo = 1
                GROUP BY b.bairro_nome, b.bairro_nome_normalizado
                ORDER BY revenue DESC, b.bairro_nome ASC
            ";
        } else {
            $sql = "
                SELECT
                    COALESCE(NULLIF(m.bairro_norm, ''), 'Nao informado') AS neighborhood,
                    COALESCE(NULLIF(m.bairro_norm, ''), 'nao informado') AS neighborhood_key,
                    SUM(m.faturamento) AS revenue,
                    COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS customers,
                    SUM(m.qtd_notas) AS invoices,
                    IF(SUM(m.qtd_notas) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0) AS average_ticket,
                    MAX(m.refreshed_at) AS last_materialized_at,
                    1 AS tem_venda
                FROM mart_compradores_por_geo m
                WHERE {$whereSql}
                GROUP BY COALESCE(NULLIF(m.bairro_norm, ''), 'Nao informado')
                ORDER BY revenue DESC
            ";
        }

        $stmt = $this->pdo->prepare($sql);
        $this->execute($stmt, $params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalRevenue = array_reduce(
            $rows,
            static fn (float $sum, array $row): float => $sum + (float) ($row['revenue'] ?? 0),
            0.0
        );

        $data = [];
        foreach ($rows as $index => $row) {
            $neighborhoodOriginal = (string) ($row['neighborhood'] ?? 'Nao informado');
            if ($isMetroLayer) {
                $bairroKeyOut = (string) ($row['neighborhood_key'] ?? '');
                $neighborhoodKey = $bairroKeyOut;
                $neighborhoodName = (string) ($row['bairro_norm_raw'] ?? $neighborhoodOriginal);
                $cityKey = (string) ($row['cidade_key_raw'] ?? '');
                $cityName = NeighborhoodNormalizer::displayName($cityKey);
            } elseif ($isMultiCityLayer) {
                $cityKey = (string) ($row['cidade_key_raw'] ?? $neighborhoodOriginal);
                $cityName = NeighborhoodNormalizer::displayName($cityKey);
                $neighborhoodName = $cityName;
                $bairroKeyOut = GeoKeyNormalizer::makeNeighborhoodKey($cityName);
                $neighborhoodKey = GeoKeyNormalizer::normalizeNeighborhood($cityName);
            } else {
                $neighborhoodName = NeighborhoodNormalizer::displayName($neighborhoodOriginal);
                $bairroKeyOut = GeoKeyNormalizer::makeNeighborhoodKey($neighborhoodName !== '' ? $neighborhoodName : $neighborhoodOriginal);
                $neighborhoodKey = GeoKeyNormalizer::normalizeNeighborhood($neighborhoodName !== '' ? $neighborhoodName : (string) ($row['neighborhood_key'] ?? ''));
                $cityName = NeighborhoodNormalizer::displayName($cityDisplay !== '' ? $cityDisplay : 'Fortaleza');
                $cityKey = GeoKeyNormalizer::makeCityKey($cityName);
            }

            $normalization = NeighborhoodNormalizer::explain($neighborhoodName);
            $aliasApplied = $normalization['alias_applied']['canonical_key'] ?? null;
            $isEmptyNeighborhood = $bairroKeyOut === '' || in_array($bairroKeyOut, ['nao_informado', 'nao-informado', 'nao informado'], true);
            $matchQuality = NeighborhoodNormalizer::matchType($neighborhoodOriginal, $neighborhoodKey, $neighborhoodName);
            $revenue = (float) ($row['revenue'] ?? 0);
            $invoices = (int) ($row['invoices'] ?? 0);

            $data[] = [
                'city' => $cityName,
                'city_key' => $cityKey,
                'neighborhood' => $neighborhoodName,
                'neighborhood_key' => $bairroKeyOut,
                'bairro_nome' => $neighborhoodName,
                'bairro_original' => $neighborhoodOriginal,
                'bairro_key' => $bairroKeyOut,
                'bairro_norm' => $neighborhoodKey,
                'cidade_nome' => $cityName,
                'cidade_key' => $cityKey,
                'receita' => $revenue,
                'notas' => $invoices,
                'clientes' => (int) ($row['customers'] ?? 0),
                'pedidos' => isset($row['orders']) ? (int) $row['orders'] : null,
                'origem' => 'mart_compradores_por_geo',
                'competencia_inicio' => $query->startComp,
                'competencia_fim' => $query->endComp,
                'normalizacao_origem' => 'NeighborhoodNormalizer',
                'alias_aplicado' => $aliasApplied,
                'match_status' => $isEmptyNeighborhood ? 'empty_neighborhood' : 'matched',
                'data_quality' => [
                    'match_type' => (string) ($matchQuality['match_type'] ?? ($isEmptyNeighborhood ? 'empty' : 'normalized')),
                    'confidence' => (float) ($matchQuality['confidence'] ?? ($isEmptyNeighborhood ? 0.0 : 0.95)),
                    'clientes_sem_bairro' => $isEmptyNeighborhood ? (int) ($row['customers'] ?? 0) : 0,
                    'nfs_sem_cliente' => 0,
                ],
                'revenue' => $revenue,
                'customers' => (int) ($row['customers'] ?? 0),
                'invoices' => $invoices,
                'average_ticket' => (float) ($row['average_ticket'] ?? 0),
                'rank' => $index + 1,
                'last_materialized_at' => $row['last_materialized_at'] ?? null,
                'name' => $neighborhoodName,
                'value_raw' => $revenue,
                'clients' => (int) ($row['customers'] ?? 0),
                'notes' => $invoices,
                'share' => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 2) : 0.0,
                'tem_venda' => (bool) ($row['tem_venda'] ?? false),
            ];
        }

        $bairrosTotais = $hasBairrosDim
            ? $this->scalarInt('SELECT COUNT(*) FROM biomie_bairros_fortaleza_dim WHERE ativo = 1')
            : null;
        $bairrosComVenda = count(array_filter($data, static fn (array $row): bool => (bool) $row['tem_venda']));
        $bairrosSemVenda = $bairrosTotais !== null ? max(0, $bairrosTotais - $bairrosComVenda) : null;
        $bestNeighborhood = null;
        foreach ($data as $row) {
            if ((float) $row['revenue'] > 0) {
                $bestNeighborhood = $row['neighborhood'];
                break;
            }
        }

        $maxRevenue = $totalRevenue > 0 ? max(array_column($data, 'revenue')) : 0.0;
        foreach ($data as &$row) {
            $rev = (float) $row['revenue'];
            if ($rev <= 0) {
                $row['bucket'] = 'sem_dados';
            } elseif ($maxRevenue > 0 && $rev >= $maxRevenue * 0.66) {
                $row['bucket'] = 'alto';
            } elseif ($maxRevenue > 0 && $rev >= $maxRevenue * 0.33) {
                $row['bucket'] = 'medio';
            } else {
                $row['bucket'] = 'baixo';
            }
        }
        unset($row);

        return [
            'data' => $data,
            'scale' => GeoMapSupport::revenueScale($data),
            'summary' => [
                'total_revenue' => (float) $totalRevenue,
                'bairros_com_venda' => $bairrosComVenda,
                'bairros_total' => $bairrosTotais,
                'best_neighborhood' => $bestNeighborhood,
            ],
            'bairros' => [
                'total' => $bairrosTotais,
                'com_venda' => $bairrosComVenda,
                'sem_venda' => $bairrosSemVenda,
                'source' => $hasBairrosDim ? 'bairros_dim' : 'mart_only',
            ],
            'source_mode' => $hasBairrosDim ? 'bairros_dim_left_join' : 'gold_mart',
        ];
    }

    /**
     * @param array<string,mixed> $territory
     * @return array<string,mixed>
     */
    public function territoryInvoices(GeoQuery $query, array $territory, int $page = 1, int $perPage = 20): array
    {
        $nfTable = 'biomie_notas_fiscais_canonicas';
        $geoTable = 'biomie_clientes_geo_dim';
        $this->preloadSchema([$nfTable, $geoTable, 'mart_compradores_por_geo']);
        if (!$this->tableExists($nfTable)) {
            return $this->emptyInvoices($page, $perPage, 'canonical_unavailable');
        }

        $valueCol = $this->columnExists($nfTable, 'valor_total') ? 'valor_total'
            : ($this->columnExists($nfTable, 'valor_nfe') ? 'valor_nfe' : null);
        if ($valueCol === null) {
            return $this->emptyInvoices($page, $perPage, 'value_column_unavailable');
        }

        [$martWhere, $martParams] = $this->territoryMartWhere($query, $territory, 'm');
        $visScope = $this->visibilityScope($nfTable, 'nf');
        $where = [
            "nf.status_fiscal = 'autorizada'",
            TenantContext::sqlFilter('nf'),
            'DATE(COALESCE(nf.data_competencia, nf.data_emissao)) BETWEEN :df AND :dt',
            "nf.codigo_cliente_omie IN (
                SELECT DISTINCT m.codigo_cliente_omie
                FROM mart_compradores_por_geo m
                WHERE {$martWhere}
                  AND m.company_id = nf.company_id
                  AND m.codigo_cliente_omie IS NOT NULL
            )",
        ];
        $params = array_merge([
            'df' => $query->dateFrom,
            'dt' => $query->dateTo,
        ], $martParams);

        // Mesma precedência do bloco do mart: código (exato) antes de nome (LIKE).
        if ($query->vendorIds !== [] && $this->columnExists($nfTable, 'codigo_vendedor_omie')) {
            $ph = [];
            foreach ($query->vendorIds as $i => $vid) {
                $key = 'nf_vendor_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = (string) $vid;
            }
            $where[] = 'nf.codigo_vendedor_omie IN (' . implode(', ', $ph) . ')';
        } elseif ($query->sellerId !== null && $this->columnExists($nfTable, 'nome_vendedor')) {
            $where[] = 'LOWER(nf.nome_vendedor) LIKE :nf_seller';
            $params['nf_seller'] = '%' . mb_strtolower(trim($query->sellerId)) . '%';
        }
        if ($query->cnae !== null && $this->columnExists($nfTable, 'cnae_codigo')) {
            if (strtoupper($query->cnae) === 'SEM_CNAE') {
                $where[] = "(nf.cnae_codigo IS NULL OR TRIM(nf.cnae_codigo) = '')";
            } else {
                $where[] = 'nf.cnae_codigo = :nf_cnae';
                $params['nf_cnae'] = $query->cnae;
            }
        }

        $whereSql = implode(' AND ', $where);
        $geoJoin = $this->tableExists($geoTable)
            ? "LEFT JOIN {$geoTable} geo
                 ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
                AND geo.company_id = nf.company_id"
            : '';
        $cityExpr = $geoJoin !== '' ? 'MAX(geo.cidade_norm)' : "''";
        $bairroExpr = $geoJoin !== '' ? 'MAX(geo.bairro_norm)' : "''";
        $sellerExpr = $this->columnExists($nfTable, 'nome_vendedor') ? 'MAX(nf.nome_vendedor)' : "''";

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare("
            WITH invoice_rows AS (
                SELECT
                    nf.id,
                    MAX(nf.numero_nf) AS numero_nf,
                    MAX(nf.serie_nf) AS serie_nf,
                    MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS data_emissao,
                    MAX(nf.nome_cliente) AS cliente_nome,
                    MAX(nf.documento_cliente) AS cliente_documento,
                    MAX(COALESCE(CAST(nf.codigo_cliente_omie AS CHAR), nf.documento_cliente, nf.nome_cliente)) AS cliente_key,
                    {$cityExpr} AS cidade,
                    {$bairroExpr} AS bairro,
                    {$sellerExpr} AS vendedor_nome,
                    COALESCE(SUM(nf.{$valueCol}), 0) AS valor
                FROM {$nfTable} nf
                {$geoJoin}
                WHERE {$whereSql} {$visScope}
                GROUP BY nf.id
            ),
            summary AS (
                SELECT
                    COUNT(*) AS total_notas,
                    COALESCE(SUM(valor), 0) AS total_valor,
                    COUNT(DISTINCT cliente_key) AS total_clientes
                FROM invoice_rows
            ),
            page_rows AS (
                SELECT *
                FROM invoice_rows
                ORDER BY data_emissao DESC, valor DESC
                LIMIT :lim OFFSET :off
            )
            SELECT
                p.id,
                p.numero_nf,
                p.serie_nf,
                p.data_emissao,
                p.cliente_nome,
                p.cliente_documento,
                p.cidade,
                p.bairro,
                p.vendedor_nome,
                p.valor,
                s.total_notas,
                s.total_valor,
                s.total_clientes
            FROM summary s
            LEFT JOIN page_rows p ON 1 = 1
            ORDER BY p.data_emissao DESC, p.valor DESC
        ");
        $this->execute($stmt, $params, [':lim' => $perPage, ':off' => $offset]);
        $resultRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summaryRow = $resultRows[0] ?? [];
        $totalNotas = (int) ($summaryRow['total_notas'] ?? 0);
        $totalValor = (float) ($summaryRow['total_valor'] ?? 0);
        $totalCli = (int) ($summaryRow['total_clientes'] ?? 0);
        $rows = [];
        foreach ($resultRows as $row) {
            if ($row['id'] === null) {
                continue;
            }
            unset($row['total_notas'], $row['total_valor'], $row['total_clientes']);
            $rows[] = $row;
        }

        return [
            'summary' => [
                'notas' => $totalNotas,
                'valor_total' => $totalValor,
                'clientes' => $totalCli,
                'ticket_medio' => $totalNotas > 0 ? $totalValor / $totalNotas : 0.0,
            ],
            'items' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalNotas,
            ],
            'source_mode' => 'readmodel_mart_to_canonical_single_query',
        ];
    }

    /**
     * @param array<string,mixed> $territory
     * @return array<string,mixed>
     */
    public function territoryBuyers(GeoQuery $query, array $territory, int $limit = 10, string $documentType = 'all'): array
    {
        if (!$this->tableExists('mart_compradores_por_geo')) {
            return ['data' => [], 'meta' => ['source_mode' => 'mart_unavailable', 'count' => 0]];
        }

        [$whereSql, $params] = $this->territoryMartWhere($query, $territory, 'm');
        [$dimJoin, $documentExpr, $rawCnaeExpr, $descExpr] = $this->martClientExpressions('m');

        if ($documentType !== 'all') {
            $documentDigitsExpr = "REGEXP_REPLACE(COALESCE({$documentExpr}, ''), '[^0-9]', '')";
            $whereSql .= $documentType === 'cnpj'
                ? " AND CHAR_LENGTH({$documentDigitsExpr}) = 14"
                : " AND CHAR_LENGTH({$documentDigitsExpr}) = 11";
        }

        if ($query->cnae !== null) {
            [$cnaeSql, $cnaeParams] = $this->cnaeFilterSql($query->cnae, $rawCnaeExpr, $descExpr);
            $whereSql .= ' AND ' . $cnaeSql;
            $params = array_merge($params, $cnaeParams);
        }

        $stmt = $this->pdo->prepare("
            SELECT
                MAX(m.cliente_nome) AS customer_name,
                MAX({$documentExpr}) AS document_raw,
                COALESCE(SUM(m.faturamento), 0) AS revenue,
                COALESCE(SUM(m.qtd_notas), 0) AS invoices,
                MAX(m.ultima_compra) AS last_purchase,
                MAX({$rawCnaeExpr}) AS cnae,
                MAX(m.codigo_cliente_omie) AS codigo_cliente_omie,
                MAX(m.cidade_norm) AS cidade_norm,
                MAX(m.bairro_norm) AS bairro_norm
            FROM mart_compradores_por_geo m
            {$dimJoin}
            WHERE {$whereSql}
            GROUP BY m.company_id, COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)
            ORDER BY revenue DESC
            LIMIT :lim
        ");
        $this->execute($stmt, $params, [':lim' => $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'data' => $rows,
            'meta' => [
                'source_mode' => 'mart_compradores_por_geo',
                'count' => count($rows),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $territory
     * @return array<string,mixed>
     */
    public function territoryCnaes(GeoQuery $query, array $territory, int $limit = 20): array
    {
        if (!$this->tableExists('mart_compradores_por_geo')) {
            return ['items' => [], 'source' => 'mart_unavailable'];
        }

        [$whereSql, $params] = $this->territoryMartWhere($query, $territory, 'm');
        [$dimJoin, , $rawCnaeExpr, $descExpr, $segExpr] = $this->martClientExpressions('m');
        $hasCnaeDim = $this->tableExists('biomie_cnae_dim');
        $cnaeDimJoin = $hasCnaeDim
            ? "LEFT JOIN biomie_cnae_dim cdim ON cdim.cnae_codigo_normalizado = REGEXP_REPLACE({$rawCnaeExpr}, '[^0-9]', '')"
            : '';
        $descriptionExpr = $hasCnaeDim
            ? "COALESCE(NULLIF(TRIM(cdim.cnae_descricao), ''), {$descExpr}, 'CNAE nao informado')"
            : "COALESCE({$descExpr}, 'CNAE nao informado')";
        $cnaeExpr = "COALESCE({$rawCnaeExpr}, 'SEM_CNAE')";

        if ($query->cnae !== null) {
            [$cnaeSql, $cnaeParams] = $this->cnaeFilterSql($query->cnae, $cnaeExpr, $descriptionExpr);
            $whereSql .= ' AND ' . $cnaeSql;
            $params = array_merge($params, $cnaeParams);
        }

        $stmt = $this->pdo->prepare("
            SELECT
                {$cnaeExpr} AS cnae_codigo,
                {$descriptionExpr} AS cnae_descricao,
                {$segExpr} AS segmento_macro,
                COUNT(DISTINCT CONCAT(m.company_id, ':', COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome))) AS clientes,
                SUM(m.qtd_notas) AS notas,
                SUM(m.faturamento) AS faturamento,
                IF(SUM(m.qtd_notas) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0) AS ticket_medio
            FROM mart_compradores_por_geo m
            {$dimJoin}
            {$cnaeDimJoin}
            WHERE {$whereSql}
            GROUP BY {$cnaeExpr}, {$descriptionExpr}, {$segExpr}
            ORDER BY faturamento DESC
            LIMIT :lim
        ");
        $this->execute($stmt, $params, [':lim' => $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            $stmtTotals = $this->pdo->prepare("
                SELECT COALESCE(SUM(m.qtd_notas),0) AS total_notas, COALESCE(SUM(m.faturamento),0) AS total_fat
                FROM mart_compradores_por_geo m
                {$dimJoin}
                {$cnaeDimJoin}
                WHERE {$whereSql}
            ");
            $this->execute($stmtTotals, $params);
            $totals = $stmtTotals->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalNotas = (int) ($totals['total_notas'] ?? 0);
            $totalFat = (float) ($totals['total_fat'] ?? 0);
            if ($totalNotas > 0) {
                $rows[] = [
                    'cnae_codigo' => 'SEM_CNAE',
                    'cnae_descricao' => 'CNAE nao informado',
                    'segmento_macro' => 'Outros',
                    'clientes' => 0,
                    'notas' => $totalNotas,
                    'faturamento' => $totalFat,
                    'ticket_medio' => $totalFat / $totalNotas,
                    'source' => 'mart_bucket',
                ];
            }
        }

        return [
            'items' => array_map(static fn (array $row): array => [
                'cnae_codigo' => $row['cnae_codigo'],
                'cnae_descricao' => $row['cnae_descricao'],
                'segmento_macro' => $row['segmento_macro'],
                'clientes' => (int) ($row['clientes'] ?? 0),
                'notas' => (int) ($row['notas'] ?? 0),
                'faturamento' => (float) ($row['faturamento'] ?? 0),
                'ticket_medio' => (float) ($row['ticket_medio'] ?? 0),
                'source' => (string) ($row['source'] ?? 'mart'),
            ], $rows),
            'source' => 'mart_compradores_por_geo',
        ];
    }

    /** @return array<string,mixed> */
    public function freshness(): array
    {
        return [
            'mart_compradores_por_geo' => [
                'exists' => $this->tableExists('mart_compradores_por_geo'),
                'last_refreshed_at' => $this->maxValue('mart_compradores_por_geo', 'refreshed_at'),
                'min_competencia' => $this->maxValue('mart_compradores_por_geo', 'competencia', true),
                'max_competencia' => $this->maxValue('mart_compradores_por_geo', 'competencia'),
            ],
        ];
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function geoWhere(GeoQuery $query, string $alias = 'm', bool $applyCityLayerRule = true): array
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        $where = [
            "{$prefix}competencia BETWEEN :start_comp AND :end_comp",
            CommercialMartScope::condition($this->pdo, 'mart_compradores_por_geo', $alias),
        ];
        $params = [
            'start_comp' => $query->startComp,
            'end_comp' => $query->endComp,
        ];

        if (
            $applyCityLayerRule
            && !$query->isMetroNeighborhoodLayer()
            && !$query->isMultiCityLayer()
            && $query->city !== null
        ) {
            $where[] = "LOWER(TRIM({$prefix}cidade_norm)) = :city";
            $params['city'] = $query->city;
        }

        if ($query->neighborhood !== null) {
            $where[] = "LOWER(TRIM({$prefix}bairro_norm)) = :neighborhood";
            $params['neighborhood'] = $query->neighborhood;
        }

        if ($query->cnae !== null) {
            if (strtoupper($query->cnae) === 'SEM_CNAE') {
                $where[] = "({$prefix}cnae_codigo IS NULL OR TRIM({$prefix}cnae_codigo) = '')";
            } else {
                $where[] = "({$prefix}cnae_codigo = :cnae OR LOWER({$prefix}cnae_descricao) LIKE :cnae_like)";
                $params['cnae'] = $query->cnae;
                $params['cnae_like'] = '%' . mb_strtolower($query->cnae) . '%';
            }
        }

        // FILTRO COMERCIAL (seletor global de vendedores) — este é o WHERE que
        // TODAS as leituras do mapa usam (summaryBundle, segments, topBuyers,
        // compositeMap...), então o recorte entra aqui uma vez e vale para o
        // mapa inteiro. Casa por CÓDIGO (exato); tem precedência sobre o filtro
        // legado por NOME (LIKE), que confunde "CAIO - VAR" com "CAIO - REV".
        if ($query->vendorIds !== [] && $this->columnExists('mart_compradores_por_geo', 'codigo_vendedor_omie')) {
            $ph = [];
            foreach ($query->vendorIds as $i => $vid) {
                $key = 'geo_vendor_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = (string) $vid;
            }
            $where[] = "{$prefix}codigo_vendedor_omie IN (" . implode(', ', $ph) . ')';
        } elseif ($query->sellerId !== null && $this->columnExists('mart_compradores_por_geo', 'nome_vendedor')) {
            $where[] = "LOWER({$prefix}nome_vendedor) LIKE :seller_pct";
            $params['seller_pct'] = '%' . mb_strtolower(trim($query->sellerId)) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    private function groupExpressionForLayer(GeoQuery $query): string
    {
        if ($query->isMultiCityLayer() && !$query->isMetroNeighborhoodLayer()) {
            return "COALESCE(NULLIF(m.cidade_norm, ''), 'Nao informado')";
        }

        if ($query->isMetroNeighborhoodLayer()) {
            return "CONCAT(COALESCE(NULLIF(m.cidade_norm, ''), 'sem_cidade'), ':', COALESCE(NULLIF(m.bairro_norm, ''), 'sem_bairro'))";
        }

        return "COALESCE(NULLIF(m.bairro_norm, ''), 'Nao informado')";
    }

    private function isMunicipalityLayer(GeoQuery $query): bool
    {
        return in_array($query->layer, [
            'ceara_municipios',
            'rmf_municipios',
            'metro_sobral_municipios',
            'metro_cariri_municipios',
            'metro_todas_municipios',
        ], true);
    }

    /**
     * @param array<string,mixed> $territory
     * @return array{0:string,1:array<string,string>}
     */
    private function territoryMartWhere(GeoQuery $query, array $territory, string $alias = 'm'): array
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        $where = [
            "{$prefix}competencia BETWEEN :geo_s AND :geo_e",
            CommercialMartScope::condition($this->pdo, 'mart_compradores_por_geo', $alias),
        ];
        $params = [
            'geo_s' => $query->startComp,
            'geo_e' => $query->endComp,
        ];

        $type = trim((string) ($territory['territory_type'] ?? ''));
        $cidadeKey = strtolower(trim((string) ($territory['cidade_key'] ?? '')));
        $bairroKey = strtolower(trim((string) ($territory['bairro_key'] ?? '')));
        $filterCity = strtolower(trim((string) ($territory['filter_city'] ?? '')));
        $isNoGeo = $type === 'sem_geo';
        $isNoNeighborhood = $type === 'sem_bairro' || $bairroKey === '__sem_bairro__';

        if ($isNoGeo) {
            $where[] = "({$prefix}cidade_norm IS NULL OR TRIM({$prefix}cidade_norm) = '')";
        } elseif ($isNoNeighborhood) {
            $where[] = "{$prefix}cidade_norm IS NOT NULL AND TRIM({$prefix}cidade_norm) <> ''";
            $where[] = "({$prefix}bairro_norm IS NULL OR TRIM({$prefix}bairro_norm) = '' OR LOWER(TRIM({$prefix}bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
            if ($filterCity !== '') {
                $where[] = "LOWER(TRIM({$prefix}cidade_norm)) = :geo_filter_city";
                $params['geo_filter_city'] = $filterCity;
            }
        } elseif ($type === 'fortaleza_bairro' && $bairroKey !== '') {
            $where[] = "LOWER(TRIM({$prefix}cidade_norm)) = 'fortaleza'";
            $where[] = "{$prefix}bairro_norm COLLATE utf8mb4_general_ci = :geo_bairro";
            $params['geo_bairro'] = $bairroKey;
        } elseif ($type === 'metro_bairro' && $bairroKey !== '') {
            $where[] = "LOWER(TRIM({$prefix}cidade_norm)) = :geo_cidade";
            $where[] = "{$prefix}bairro_norm COLLATE utf8mb4_general_ci = :geo_bairro";
            $params['geo_cidade'] = $cidadeKey;
            $params['geo_bairro'] = $bairroKey;
        } elseif ($cidadeKey !== '') {
            $where[] = "LOWER(TRIM({$prefix}cidade_norm)) = :geo_cidade";
            $params['geo_cidade'] = $cidadeKey;
        }

        if ($query->sellerId !== null && $this->columnExists('mart_compradores_por_geo', 'nome_vendedor')) {
            $where[] = "LOWER({$prefix}nome_vendedor) LIKE :geo_seller";
            $params['geo_seller'] = '%' . mb_strtolower(trim($query->sellerId)) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @return array{0:string,1:string,2:string,3:string,4:string}
     */
    private function martClientExpressions(string $alias = 'm'): array
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        $hasClientDim = $this->tableExists('biomie_clientes_dim');
        $dimJoin = $hasClientDim
            ? "LEFT JOIN biomie_clientes_dim dim
                 ON dim.codigo_cliente_omie = {$prefix}codigo_cliente_omie
                AND dim.company_id = {$prefix}company_id"
            : '';
        $martDocExpr = $this->columnExists('mart_compradores_por_geo', 'documento')
            ? "NULLIF(TRIM({$prefix}documento), '')"
            : 'NULL';
        $dimDocPieces = [];
        if ($hasClientDim && $this->columnExists('biomie_clientes_dim', 'documento_normalizado')) {
            $dimDocPieces[] = "NULLIF(TRIM(dim.documento_normalizado), '')";
        }
        if ($hasClientDim && $this->columnExists('biomie_clientes_dim', 'documento')) {
            $dimDocPieces[] = "NULLIF(TRIM(dim.documento), '')";
        }
        $dimDocExpr = $dimDocPieces !== [] ? 'COALESCE(' . implode(', ', $dimDocPieces) . ')' : 'NULL';
        $documentExpr = $hasClientDim ? "COALESCE({$martDocExpr}, {$dimDocExpr})" : $martDocExpr;

        $martCnaeExpr = $this->columnExists('mart_compradores_por_geo', 'cnae_codigo')
            ? "NULLIF(TRIM({$prefix}cnae_codigo), '')"
            : 'NULL';
        $dimCnaeExpr = $hasClientDim && $this->columnExists('biomie_clientes_dim', 'cnae_codigo')
            ? "NULLIF(TRIM(dim.cnae_codigo), '')"
            : 'NULL';
        $rawCnaeExpr = $hasClientDim ? "COALESCE({$martCnaeExpr}, {$dimCnaeExpr})" : $martCnaeExpr;

        $martDescExpr = $this->columnExists('mart_compradores_por_geo', 'cnae_descricao')
            ? "NULLIF(NULLIF(TRIM({$prefix}cnae_descricao), ''), 'CNAE nao informado')"
            : 'NULL';
        $dimDescExpr = $hasClientDim && $this->columnExists('biomie_clientes_dim', 'cnae_descricao')
            ? "NULLIF(NULLIF(TRIM(dim.cnae_descricao), ''), 'CNAE nao informado')"
            : 'NULL';
        $descExpr = $hasClientDim ? "COALESCE({$martDescExpr}, {$dimDescExpr})" : $martDescExpr;

        $martSegExpr = $this->columnExists('mart_compradores_por_geo', 'segmento_macro')
            ? "NULLIF(TRIM({$prefix}segmento_macro), '')"
            : 'NULL';
        $dimSegExpr = $hasClientDim && $this->columnExists('biomie_clientes_dim', 'segmento_macro')
            ? "NULLIF(TRIM(dim.segmento_macro), '')"
            : 'NULL';
        $segExpr = $hasClientDim ? "COALESCE({$martSegExpr}, {$dimSegExpr}, 'Outros')" : "COALESCE({$martSegExpr}, 'Outros')";

        return [$dimJoin, $documentExpr, $rawCnaeExpr, $descExpr, $segExpr];
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function cnaeFilterSql(string $cnae, string $codeExpr, string $descExpr): array
    {
        if (strtoupper(trim($cnae)) === 'SEM_CNAE') {
            return ["({$codeExpr} IS NULL OR TRIM({$codeExpr}) = '' OR UPPER(TRIM({$codeExpr})) = 'SEM_CNAE')", []];
        }

        $params = [
            'filter_cnae_code' => $cnae,
            'filter_cnae_like' => '%' . mb_strtolower($cnae) . '%',
        ];
        $parts = ["{$codeExpr} = :filter_cnae_code", "LOWER({$descExpr}) LIKE :filter_cnae_like"];
        $cnaeNorm = preg_replace('/\D+/', '', $cnae) ?? '';
        if ($cnaeNorm !== '') {
            $parts[] = "REGEXP_REPLACE({$codeExpr}, '[^0-9]', '') = :filter_cnae_norm";
            $params['filter_cnae_norm'] = $cnaeNorm;
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    private function visibilityScope(string $table, string $alias): string
    {
        try {
            $condition = BiVisibilityScope::getCondition($this->pdo, $table, $alias);
            return $condition !== '' ? 'AND ' . $condition : 'AND 1 = 0';
        } catch (Throwable) {
            return 'AND 1 = 0';
        }
    }

    /** @return array<string,mixed> */
    private function emptyInvoices(int $page, int $perPage, string $reason): array
    {
        return [
            'summary' => ['notas' => 0, 'valor_total' => 0.0, 'clientes' => 0, 'ticket_medio' => 0.0],
            'items' => [],
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0],
            'empty_reason' => $reason,
            'source_mode' => 'unavailable',
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{summary:array<string,mixed>,territories:array<string,array<string,mixed>>,ranking:list<array<string,mixed>>,scales:array<string,array{max:float}>}
     */
    private function classifyCompositeRows(array $rows): array
    {
        $rmfWithBairros = [
            'fortaleza', 'caucaia', 'maracanau', 'maranguape', 'eusebio',
            'guaiuba', 'itaitinga', 'pacatuba',
        ];
        $rmfFallback = [
            'aquiraz', 'horizonte', 'pacajus', 'chorozinho', 'cascavel',
            'pindoretama', 'paracuru', 'paraipaba', 'sao_goncalo_do_amarante',
            'sao_luis_do_curu', 'trairi',
        ];
        $rmfAll = array_merge($rmfWithBairros, $rmfFallback);

        $territories = [];
        $summary = [
            'total_vendido' => 0.0,
            'territorios_com_venda' => 0,
            'clientes' => 0,
            'notas' => 0,
            'sem_bairro' => 0,
        ];
        $scales = [
            'fortaleza_bairro' => ['max' => 0.0],
            'metro_bairro' => ['max' => 0.0],
            'rmf_municipio_fallback' => ['max' => 0.0],
            'ceara_municipio' => ['max' => 0.0],
            'sem_geo' => ['max' => 0.0],
            'sem_bairro' => ['max' => 0.0],
        ];

        foreach ($rows as $row) {
            $cidadeKey = (string) ($row['cidade_key'] ?? '');
            $bairroRaw = (string) ($row['bairro_raw'] ?? '');
            $revenue = (float) ($row['revenue'] ?? 0);
            $customers = (int) ($row['customers'] ?? 0);
            $invoices = (int) ($row['invoices'] ?? 0);

            $summary['total_vendido'] += $revenue;
            $summary['clientes'] += $customers;
            $summary['notas'] += $invoices;
            if ($bairroRaw === '') {
                $summary['sem_bairro'] += $invoices;
            }

            if ($cidadeKey === '' || $cidadeKey === 'sem_cidade') {
                $type = 'sem_geo';
                $joinKey = '__sem_geo__';
            } elseif (in_array($cidadeKey, $rmfWithBairros, true) && $bairroRaw === '') {
                $type = 'sem_bairro';
                $joinKey = '__sem_bairro__:' . $cidadeKey;
            } elseif ($cidadeKey === 'fortaleza' && $bairroRaw !== '') {
                $type = 'fortaleza_bairro';
                $joinKey = $bairroRaw;
            } elseif (in_array($cidadeKey, $rmfWithBairros, true) && $bairroRaw !== '') {
                $type = 'metro_bairro';
                $joinKey = $cidadeKey . ':' . $bairroRaw;
            } else {
                $type = in_array($cidadeKey, $rmfAll, true) ? 'rmf_municipio_fallback' : 'ceara_municipio';
                $joinKey = $cidadeKey;
            }

            if (!isset($territories[$joinKey])) {
                $territories[$joinKey] = [
                    'join_key' => $joinKey,
                    'territory_type' => $type,
                    'cidade_key' => $cidadeKey,
                    'bairro_norm' => $bairroRaw !== '' ? $bairroRaw : null,
                    'revenue' => 0.0,
                    'customers' => 0,
                    'invoices' => 0,
                    'ticket_medio' => 0.0,
                    'refreshed_at' => $row['refreshed_at'] ?? null,
                ];
            }

            $territories[$joinKey]['revenue'] += $revenue;
            $territories[$joinKey]['customers'] += $customers;
            $territories[$joinKey]['invoices'] += $invoices;
            $territories[$joinKey]['ticket_medio'] = $territories[$joinKey]['invoices'] > 0
                ? $territories[$joinKey]['revenue'] / $territories[$joinKey]['invoices']
                : 0.0;

            if ($territories[$joinKey]['revenue'] > $scales[$type]['max']) {
                $scales[$type]['max'] = (float) $territories[$joinKey]['revenue'];
            }
        }

        $ranking = array_values(array_filter(
            $territories,
            static fn (array $row): bool => (float) $row['revenue'] > 0
        ));
        usort($ranking, static fn (array $a, array $b): int => ((float) $b['revenue']) <=> ((float) $a['revenue']));
        $ranking = array_slice($ranking, 0, 50);
        $summary['territorios_com_venda'] = count($ranking);

        return [
            'summary' => $summary,
            'territories' => $territories,
            'ranking' => $ranking,
            'scales' => $scales,
        ];
    }

    /**
     * @param array<string,string> $params
     * @param array<string,int> $intParams
     */
    private function execute(\PDOStatement $stmt, array $params, array $intParams = []): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim($key, ':'), $value);
        }
        foreach ($intParams as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $start = microtime(true);
        $stmt->execute();
        $this->queryCount++;
        $this->sqlTimeMs += (microtime(true) - $start) * 1000;
    }

    private function scalarInt(string $sql): int
    {
        try {
            $start = microtime(true);
            $stmt = $this->pdo->query($sql);
            $this->queryCount++;
            $this->sqlTimeMs += (microtime(true) - $start) * 1000;
            if ($stmt === false) {
                return 0;
            }
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $start = microtime(true);
            $stmt->execute(['table' => $table]);
            $this->queryCount++;
            $this->sqlTimeMs += (microtime(true) - $start) * 1000;
            return $this->tableCache[$table] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return $this->tableCache[$table] = false;
        }
    }

    /** @param list<string> $tables */
    private function preloadSchema(array $tables): void
    {
        $missing = array_values(array_filter(
            array_unique($tables),
            fn (string $table): bool => !array_key_exists($table, $this->tableColumnsCache),
        ));
        if ($missing === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        try {
            $stmt = $this->pdo->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME IN ({$placeholders})"
            );
            $start = microtime(true);
            foreach ($missing as $index => $table) {
                $stmt->bindValue($index + 1, $table);
                $this->tableColumnsCache[$table] = [];
                $this->tableCache[$table] = false;
            }
            $stmt->execute();
            $this->queryCount++;
            $this->sqlTimeMs += (microtime(true) - $start) * 1000;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $table = (string) ($row['TABLE_NAME'] ?? '');
                $column = (string) ($row['COLUMN_NAME'] ?? '');
                if ($table === '' || $column === '') {
                    continue;
                }
                $this->tableColumnsCache[$table][$column] = true;
                $this->tableCache[$table] = true;
                $this->columnCache[$table . '.' . $column] = true;
            }
        } catch (Throwable) {
            foreach ($missing as $table) {
                unset($this->tableColumnsCache[$table], $this->tableCache[$table]);
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }

        if (!array_key_exists($table, $this->tableColumnsCache)) {
            $this->tableColumnsCache[$table] = $this->loadTableColumns($table);
        }

        if ($this->tableColumnsCache[$table] !== []) {
            return $this->columnCache[$key] = isset($this->tableColumnsCache[$table][$column]);
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $start = microtime(true);
            $stmt->execute(['table' => $table, 'column' => $column]);
            $this->queryCount++;
            $this->sqlTimeMs += (microtime(true) - $start) * 1000;
            return $this->columnCache[$key] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return $this->columnCache[$key] = false;
        }
    }

    /** @return array<string,bool> */
    private function loadTableColumns(string $table): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $start = microtime(true);
            $stmt->execute(['table' => $table]);
            $this->queryCount++;
            $this->sqlTimeMs += (microtime(true) - $start) * 1000;
            $columns = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $column) {
                $columns[(string) $column] = true;
            }

            return $columns;
        } catch (Throwable) {
            return [];
        }
    }

    private function maxValue(string $table, string $column, bool $min = false): ?string
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return null;
        }

        try {
            $fn = $min ? 'MIN' : 'MAX';
            $tenantFilter = $table === 'mart_compradores_por_geo'
                ? CommercialMartScope::condition($this->pdo, $table)
                : TenantContext::sqlFilter();
            $stmt = $this->pdo->query(
                "SELECT {$fn}(`{$column}`)
                 FROM `{$table}`
                 WHERE {$tenantFilter}"
            );
            if ($stmt === false) {
                return null;
            }
            $value = $stmt->fetchColumn();
            return $value !== false && $value !== null ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
