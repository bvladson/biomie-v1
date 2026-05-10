<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\GeoMapSupport;
use BIomie\Support\MapFilterSupport;

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

    // City filter always applies (defaults to Fortaleza); include NULL/empty cidade_norm (unenriched rows)
    $martWhere[] = '(LOWER(m.cidade_norm) = :city OR m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = \'\')';
    $params['city'] = $cityNorm;

    if ($neighborhoodNorm !== null) {
        $martWhere[] = 'LOWER(m.bairro_norm) = :neighborhood';
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

    $martWhereSql = implode(' AND ', $martWhere);

    // When the official bairros dimension exists, LEFT JOIN it so that all
    // 121 bairros always appear — even those with zero sales in the period.
    if ($hasBairrosDim && $neighborhood === null) {
        $sql = "
            SELECT
                b.bairro_nome                                                          AS neighborhood,
                b.bairro_nome_normalizado                                              AS neighborhood_key,
                COALESCE(SUM(m.faturamento), 0)                                        AS revenue,
                COALESCE(COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)), 0) AS customers,
                COALESCE(SUM(m.qtd_notas), 0)                                          AS invoices,
                IF(COALESCE(SUM(m.qtd_notas), 0) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0) AS average_ticket,
                MAX(m.refreshed_at)                                                    AS last_materialized_at,
                (SUM(m.qtd_notas) > 0)                                                 AS tem_venda
            FROM biomie_bairros_fortaleza_dim b
            LEFT JOIN mart_compradores_por_geo m
              ON m.bairro_norm = b.bairro_nome_normalizado
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
    }

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
        $neighborhoodName = (string) ($row['neighborhood'] ?? 'Nao informado');
        $neighborhoodKey  = (string) ($row['neighborhood_key'] ?? GeoMapSupport::normalizeKey($neighborhoodName));
        $revenue = (float) ($row['revenue'] ?? 0);
        $data[] = [
            'city' => $city ?? 'Fortaleza',
            'neighborhood' => $neighborhoodName,
            'neighborhood_key' => $neighborhoodKey,
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
                $motivoZero = 'mart_vazio';
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
            'date_from' => $range['date_from'],
            'date_to' => $range['date_to'],
            'city' => $city,
            'neighborhood' => $neighborhood,
            'cnae' => $cnae,
            'seller_id' => null,
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
        ],
    ];

    if ($totalRevenue <= 0.0) {
        $payload['empty_state'] = GeoMapSupport::emptyState();
        $payload['diagnostics'] = [
            'motivo_zero' => $motivoZero,
            'filters_applied' => implode(', ', array_keys($params)),
        ];
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
