<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\Support\GeoMapSupport;
use BIomie\Support\GeoTraceDiagnostics;
use BIomie\Support\MapFilterSupport;
use BIomie\Support\NeighborhoodNormalizer;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f              = MapFilterSupport::fromRequest($_GET);
    $range          = ['date_from' => $f['date_from'], 'date_to' => $f['date_to']];
    // Display values (raw input or defaults)
    $city           = $f['filters_received']['city'] ?? 'Fortaleza';
    $neighborhood   = $f['filters_received']['neighborhood'];
    $cnae           = $f['cnae'];
    // Normalized values for SQL binding (state suffix already stripped by MapFilterSupport)
    $cityNorm       = $f['city'] ?? GeoMapSupport::normalizeKey('Fortaleza');
    $neighborhoodNorm = $f['neighborhood'];
    // GEO.R1.3/R1.4/R1.5 — multi-city layers aggregate without single-city restriction
    $geoLayer       = trim((string) ($_GET['layer'] ?? ''));
    $isMetroLayer   = $geoLayer === 'grande_fortaleza_bairros';
    $isMultiCityLayer = in_array($geoLayer, [
        'ceara_municipios', 'rmf_municipios',
        'metro_sobral_municipios', 'metro_cariri_municipios', 'metro_todas_municipios',
    ], true);

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::jsonConflictMissingTable('mart_compradores_por_geo');
    }

    $hasBairrosDim = Http::tableExists($pdo, 'biomie_bairros_fortaleza_dim');

    $martWhere = ['competencia BETWEEN :start_comp AND :end_comp'];
    $params = [
        'start_comp' => substr($range['date_from'], 0, 7),
        'end_comp' => substr($range['date_to'], 0, 7),
    ];

    // City filter — skip for multi-city layers which aggregate across multiple cities.
    if (!$isMetroLayer && !$isMultiCityLayer) {
        $martWhere[] = '(LOWER(TRIM(m.cidade_norm)) = :city OR m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = \'\')';
        $params['city'] = $cityNorm;
    }

    // Resolve bairro_key from request (supports bairro_key, bairro_norm, and neighborhood params).
    $rawBairroKey  = trim((string) ($_GET['bairro_key'] ?? ''));
    $rawBairroNorm = trim((string) ($_GET['bairro_norm'] ?? ''));
    $resolvedBairroKey = $rawBairroKey !== ''
        ? GeoKeyNormalizer::makeNeighborhoodKey($rawBairroKey)
        : ($rawBairroNorm !== ''
            ? GeoKeyNormalizer::makeNeighborhoodKey($rawBairroNorm)
            : ($neighborhoodNorm !== null ? GeoKeyNormalizer::normToKey($neighborhoodNorm) : null));

    $hasBairroKeyColNb = nbColExists($pdo, 'mart_compradores_por_geo', 'bairro_key');

    if ($resolvedBairroKey !== null && $resolvedBairroKey !== '') {
        if ($hasBairroKeyColNb) {
            $martWhere[] = '(m.bairro_key = :bairro_key OR (m.bairro_key IS NULL AND m.bairro_norm COLLATE utf8mb4_general_ci = :bairro_key_fb))';
            $params['bairro_key']    = $resolvedBairroKey;
            $params['bairro_key_fb'] = $resolvedBairroKey;
        } else {
            $martWhere[] = 'LOWER(TRIM(m.bairro_norm)) = :neighborhood';
            $params['neighborhood'] = GeoKeyNormalizer::keyToNorm($resolvedBairroKey);
        }
    } elseif ($neighborhoodNorm !== null) {
        $martWhere[] = 'LOWER(TRIM(m.bairro_norm)) = :neighborhood';
        $params['neighborhood'] = $neighborhoodNorm;
    }

    if ($cnae !== null) {
        if (strtoupper($cnae) === 'SEM_CNAE') {
            $martWhere[] = '(m.cnae_codigo IS NULL OR TRIM(m.cnae_codigo) = \'\')';
        } else {
            $martWhere[] = 'm.cnae_codigo = :cnae';
            $params['cnae'] = $cnae;
        }
    }

    // Vendor filter — only applied when the mart has the vendor grain column (Q.6.DB migration).
    $sellerId = $f['seller_id'];
    if ($sellerId !== null && nbColExists($pdo, 'mart_compradores_por_geo', 'codigo_vendedor_omie')) {
        $martWhere[] = 'LOWER(m.nome_vendedor) LIKE :seller_pct';
        $params['seller_pct'] = '%' . mb_strtolower(trim($sellerId)) . '%';
    }

    $martWhereSql = implode(' AND ', $martWhere);

    // GEO.R1.4/R1.5 — multi-city municipality layers: aggregate by cidade_norm
    if ($isMultiCityLayer) {
        $sql = "
            SELECT
                LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade')))  AS neighborhood_key,
                LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade')))  AS neighborhood,
                LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade')))  AS cidade_key_raw,
                LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade')))  AS bairro_norm_raw,
                SUM(m.faturamento)                                              AS revenue,
                COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS customers,
                SUM(m.qtd_notas)                                                AS invoices,
                IF(SUM(m.qtd_notas)>0, SUM(m.faturamento)/SUM(m.qtd_notas),0) AS average_ticket,
                MAX(m.refreshed_at)                                             AS last_materialized_at,
                1                                                               AS tem_venda
            FROM mart_compradores_por_geo m
            WHERE {$martWhereSql}
              AND LOWER(TRIM(COALESCE(m.cidade_norm,''))) != ''
            GROUP BY LOWER(TRIM(m.cidade_norm))
            ORDER BY revenue DESC
        ";
    // GEO.R1.3 — metro layer: aggregate by (cidade_norm, bairro_norm), compound join_key
    } elseif ($isMetroLayer) {
        $sql = "
            SELECT
                CONCAT(
                    LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))),
                    ':',
                    LOWER(TRIM(COALESCE(NULLIF(m.bairro_norm,''), 'sem_bairro')))
                )                                                           AS neighborhood_key,
                CONCAT(
                    LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))),
                    ':',
                    LOWER(TRIM(COALESCE(NULLIF(m.bairro_norm,''), 'sem_bairro')))
                )                                                           AS neighborhood,
                LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))) AS cidade_key_raw,
                LOWER(TRIM(COALESCE(NULLIF(m.bairro_norm,''), 'sem_bairro'))) AS bairro_norm_raw,
                SUM(m.faturamento)                                          AS revenue,
                COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS customers,
                SUM(m.qtd_notas)                                            AS invoices,
                IF(SUM(m.qtd_notas)>0, SUM(m.faturamento)/SUM(m.qtd_notas), 0) AS average_ticket,
                MAX(m.refreshed_at)                                         AS last_materialized_at,
                1                                                           AS tem_venda
            FROM mart_compradores_por_geo m
            WHERE {$martWhereSql}
              AND LOWER(TRIM(COALESCE(m.bairro_norm,''))) NOT IN ('','nao informado','nao_informado','sem bairro')
              AND LOWER(TRIM(COALESCE(m.cidade_norm,''))) != ''
            GROUP BY LOWER(TRIM(m.cidade_norm)), LOWER(TRIM(m.bairro_norm))
            ORDER BY revenue DESC
        ";
    // When the official bairros dimension exists, LEFT JOIN it so that all
    // 121 bairros always appear — even those with zero sales in the period.
    } elseif ($hasBairrosDim && $neighborhood === null) {
        $sql = "
            SELECT
                b.bairro_nome                                                          AS neighborhood,
                b.bairro_nome_normalizado                                              AS neighborhood_key,
                COALESCE(SUM(m.faturamento), 0)                                        AS revenue,
                COALESCE(COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)), 0) AS customers,
                COALESCE(SUM(m.qtd_notas), 0)                                          AS invoices,
                IF(COALESCE(SUM(m.qtd_notas), 0) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0) AS average_ticket,
                MAX(m.refreshed_at)                                                    AS last_materialized_at,
                (COALESCE(SUM(m.qtd_notas), 0) > 0 OR COALESCE(SUM(m.faturamento), 0) > 0) AS tem_venda
            FROM biomie_bairros_fortaleza_dim b
            LEFT JOIN mart_compradores_por_geo m
              ON LOWER(TRIM(m.bairro_norm)) = LOWER(TRIM(b.bairro_nome_normalizado))
             AND {$martWhereSql}
            WHERE b.ativo = 1
            GROUP BY b.bairro_nome, b.bairro_nome_normalizado
            ORDER BY revenue DESC, b.bairro_nome ASC
        ";
    } else {
        $sql = "
            SELECT
                COALESCE(NULLIF(m.bairro_norm, ''), 'Nao informado') AS neighborhood,
                COALESCE(NULLIF(m.bairro_norm, ''), 'nao informado') AS neighborhood_key,
                SUM(m.faturamento)                                    AS revenue,
                COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS customers,
                SUM(m.qtd_notas)                                      AS invoices,
                IF(SUM(m.qtd_notas) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0)  AS average_ticket,
                MAX(m.refreshed_at)                                   AS last_materialized_at,
                1                                                     AS tem_venda
            FROM mart_compradores_por_geo m
            WHERE {$martWhereSql}
            GROUP BY COALESCE(NULLIF(m.bairro_norm, ''), 'Nao informado')
            ORDER BY revenue DESC
        ";
    } // end query selection

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalRevenue = array_reduce(
        $rows,
        static fn (float $sum, array $row): float => $sum + (float) ($row['revenue'] ?? 0),
        0.0
    );

    $data = [];
    foreach ($rows as $index => $row) {
        $neighborhoodOriginal = (string) ($row['neighborhood'] ?? 'Nao informado');

        // GEO.R1.3 metro layer: neighborhood_key is compound "cidade:bairro" — use as-is for join_key matching
        if ($isMetroLayer) {
            $bairroKeyOut    = (string) ($row['neighborhood_key'] ?? '');   // "caucaia:jurema"
            $neighborhoodKey = $bairroKeyOut;
            $neighborhoodName = (string) ($row['bairro_norm_raw'] ?? $neighborhoodOriginal);
            $citaKeyRaw      = (string) ($row['cidade_key_raw'] ?? '');
            $cityKey         = $citaKeyRaw;
            $cityName        = NeighborhoodNormalizer::displayName($citaKeyRaw);
        } else {
            $neighborhoodName = NeighborhoodNormalizer::displayName($neighborhoodOriginal);
            // bairro_key: underscore-based stable technical key (new standard)
            $bairroKeyOut = GeoKeyNormalizer::makeNeighborhoodKey($neighborhoodName !== '' ? $neighborhoodName : $neighborhoodOriginal);
            // bairro_norm: space-based legacy key (backward compat)
            $neighborhoodKey = GeoKeyNormalizer::normalizeNeighborhood($neighborhoodName !== '' ? $neighborhoodName : (string) ($row['neighborhood_key'] ?? ''));
            $cityName = NeighborhoodNormalizer::displayName($city ?? 'Fortaleza');
            $cityKey  = GeoKeyNormalizer::makeCityKey($cityName);
        }
        $normalization = NeighborhoodNormalizer::explain($neighborhoodName);
        $aliasApplied = $normalization['alias_applied']['canonical_key'] ?? null;
        $isEmptyNeighborhood = $bairroKeyOut === '' || in_array($bairroKeyOut, ['nao_informado', 'nao-informado', 'nao informado'], true);
        $matchStatus = $isEmptyNeighborhood ? 'empty_neighborhood' : 'matched';
        $matchQuality = NeighborhoodNormalizer::matchType($neighborhoodOriginal, $neighborhoodKey, $neighborhoodName);
        $revenue = (float) ($row['revenue'] ?? 0);
        $data[] = [
            'city'          => $cityName,
            'city_key'      => $cityKey,
            'neighborhood'  => $neighborhoodName,
            'neighborhood_key' => $bairroKeyOut,
            'bairro_nome'   => $neighborhoodName,
            'bairro_original' => $neighborhoodOriginal,
            'bairro_key'    => $bairroKeyOut,
            'bairro_norm'   => $neighborhoodKey,
            'cidade_nome'   => $cityName,
            'cidade_key'    => $cityKey,
            'receita' => $revenue,
            'notas' => (int) ($row['invoices'] ?? 0),
            'clientes' => (int) ($row['customers'] ?? 0),
            'pedidos' => isset($row['orders']) ? (int) $row['orders'] : null,
            'origem' => 'mart_compradores_por_geo',
            'competencia_inicio' => $params['start_comp'],
            'competencia_fim' => $params['end_comp'],
            'normalizacao_origem' => 'NeighborhoodNormalizer',
            'alias_aplicado' => $aliasApplied,
            'match_status' => $matchStatus,
            'data_quality' => [
                'match_type' => (string) ($matchQuality['match_type'] ?? ($isEmptyNeighborhood ? 'empty' : 'normalized')),
                'confidence' => (float) ($matchQuality['confidence'] ?? ($isEmptyNeighborhood ? 0.0 : 0.95)),
                'clientes_sem_bairro' => $isEmptyNeighborhood ? (int) ($row['customers'] ?? 0) : 0,
                'nfs_sem_cliente' => 0,
            ],
            'revenue' => $revenue,
            'customers' => (int) ($row['customers'] ?? 0),
            'invoices' => (int) ($row['invoices'] ?? 0),
            'average_ticket' => (float) ($row['average_ticket'] ?? 0),
            'rank' => $index + 1,
            'last_materialized_at' => $row['last_materialized_at'] ?? null,
            'name' => $neighborhoodName,
            'value_raw' => $revenue,
            'clients' => (int) ($row['customers'] ?? 0),
            'notes' => (int) ($row['invoices'] ?? 0),
            'share' => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 2) : 0.0,
            'tem_venda' => (bool) ($row['tem_venda'] ?? false),
        ];
    }

    $bairrosTotais   = $hasBairrosDim
        ? (int) $pdo->query('SELECT COUNT(*) FROM biomie_bairros_fortaleza_dim WHERE ativo = 1')->fetchColumn()
        : null;
    $bairrosComVenda = count(array_filter($data, static fn (array $r): bool => (bool) ($r['tem_venda'] ?? false)));
    $bairrosSemVenda = $bairrosTotais !== null ? max(0, $bairrosTotais - $bairrosComVenda) : null;

    // Best neighborhood: first row with revenue > 0
    $bestNeighborhood = null;
    foreach ($data as $row) {
        if ((float) ($row['revenue'] ?? 0) > 0) {
            $bestNeighborhood = $row['neighborhood'];
            break;
        }
    }

    // Divergence diagnostic
    $totalRevenue = array_sum(array_column($data, 'revenue'));
    $motivoZero   = null;
    if ($totalRevenue <= 0.0) {
        try {
            $martTotal = (int) $pdo->query('SELECT COUNT(*) FROM mart_compradores_por_geo')->fetchColumn();
            $martSum   = (float) $pdo->query('SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo')->fetchColumn();
            if ($martTotal === 0) {
                $motivoZero = 'mart_compradores_geo_nao_materializado';
            } elseif ($martSum <= 0.0) {
                $motivoZero = 'periodo_sem_vendas';
            } else {
                $motivoZero = 'filtros_nao_reconhecidos';
            }
        } catch (\Throwable) {
            $motivoZero = 'erro_sql';
        }
    }

    // Add bucket field to each row
    $maxRevenue = $totalRevenue > 0 ? max(array_column($data, 'revenue')) : 0.0;
    foreach ($data as &$row) {
        $rev = (float) ($row['revenue'] ?? 0);
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

    $payload = [
        'ok' => true,
        'filters' => [
            'date_from'    => $range['date_from'],
            'date_to'      => $range['date_to'],
            'city'         => $city,
            'neighborhood' => $neighborhood,
            'cnae'         => $cnae,
            'seller_id'    => $sellerId ?? null,
        ],
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
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'source_table' => 'mart_compradores_por_geo',
            'source_mode' => $hasBairrosDim ? 'bairros_dim_left_join' : 'gold_mart',
            'count' => count($data),
            'materialization' => [
                'source_table' => 'mart_compradores_por_geo',
                'competencia_start' => $params['start_comp'],
                'competencia_end' => $params['end_comp'],
                'last_refreshed_at' => null,
                'rows' => count($data),
                'status' => count($data) > 0 ? 'ok' : 'empty',
            ],
        ],
    ];

    try {
        $layerDiagnostics = GeoTraceDiagnostics::build($pdo, $range['date_from'], $range['date_to'], $city ?? 'Fortaleza');
        $payload['diagnostics'] = array_merge($payload['diagnostics'] ?? [], [
            'omie' => $layerDiagnostics['omie'] ?? [],
            'canonical' => $layerDiagnostics['canonical'] ?? [],
            'mart_cliente_periodo' => $layerDiagnostics['mart_cliente_periodo'] ?? [],
            'mart_compradores_por_geo' => $layerDiagnostics['mart_compradores_por_geo'] ?? [],
            'api' => $layerDiagnostics['api'] ?? [],
            'map' => $layerDiagnostics['map'] ?? [],
            'materialization' => $layerDiagnostics['materialization'] ?? [],
            'freshness' => $layerDiagnostics['freshness'] ?? [],
            'motivo_zero' => $layerDiagnostics['motivo_zero'] ?? ($payload['diagnostics']['motivo_zero'] ?? null),
            'next_action' => $layerDiagnostics['next_action'] ?? null,
            'map_status' => $layerDiagnostics['map_status'] ?? null,
        ]);
        $materialization = is_array($layerDiagnostics['materialization'] ?? null) ? $layerDiagnostics['materialization'] : [];
        $payload['meta']['materialization'] = [
            'source_table' => 'mart_compradores_por_geo',
            'competencia_start' => $params['start_comp'],
            'competencia_end' => $params['end_comp'],
            'last_refreshed_at' => $materialization['last_rebuild_at'] ?? null,
            'rows' => (int) ($materialization['period_rows']['mart_compradores_por_geo'] ?? count($data)),
            'status' => (string) ($materialization['status'] ?? (count($data) > 0 ? 'ok' : 'empty')),
            'next_action' => $materialization['next_action'] ?? null,
        ];
        $payload['map_status'] = $layerDiagnostics['map_status'] ?? null;
    } catch (\Throwable) {}

    if ($totalRevenue <= 0.0) {
        $payload['empty_state'] = GeoMapSupport::emptyState();
        $payload['diagnostics'] = array_merge($payload['diagnostics'] ?? [], [
            'motivo_zero' => $motivoZero,
            'filters_applied' => implode(', ', array_keys($params)),
        ]);
    }

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('sales_by_neighborhood api error', ['exception' => $exception->getMessage()]);

    Http::json([
        'ok' => false,
        'error' => 'api_error',
        'message' => 'Nao foi possivel carregar as vendas por bairro agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}

function nbColExists(PDO $pdo, string $table, string $col): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $col]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}
