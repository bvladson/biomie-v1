<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\Support\GeoMapSupport;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $filters      = MapFilterSupport::fromRequest($_GET);
    $cityNorm     = $filters['city'];
    $neighborNorm = $filters['neighborhood'];
    $cnae         = $filters['cnae'];
    $limit        = (int) (Http::query('limit', '50') ?? 50);
    if ($limit < 1 || $limit > 100) {
        $limit = 50;
    }

    // GEO.R1.9.4 — composite mode doesn't send city= so fall back to cidade_key param
    if ($cityNorm === null) {
        $ck = strtolower(trim((string) ($_GET['cidade_key'] ?? '')));
        if ($ck !== '' && $ck !== 'sem_cidade') $cityNorm = $ck;
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_vendas_por_cnae_cnpj')) {
        Http::jsonConflictMissingTable('mart_vendas_por_cnae_cnpj');
    }
    $officialCnaeDescriptionExpr = "COALESCE(NULLIF(TRIM(cnae_descricao), ''), 'PJ sem CNAE')";

    $where = ['competencia BETWEEN :start_comp AND :end_comp'];
    $params = [
        'start_comp' => $filters['start_comp'],
        'end_comp'   => $filters['end_comp'],
    ];

    $rawBairroKey = trim((string) ($_GET['bairro_key'] ?? ''));
    $rawBairroNorm = trim((string) ($_GET['bairro_norm'] ?? ''));
    $resolvedKey = $rawBairroKey !== ''
        ? GeoKeyNormalizer::makeNeighborhoodKey($rawBairroKey)
        : ($rawBairroNorm !== ''
            ? GeoKeyNormalizer::makeNeighborhoodKey($rawBairroNorm)
            : ($neighborNorm !== null ? GeoKeyNormalizer::normToKey($neighborNorm) : null));

    $hasBairroKeyColCnae  = cnaeColExists($pdo, 'mart_vendas_por_cnae_cnpj', 'bairro_key');
    $hasBairroKeyColGeo   = cnaeColExists($pdo, 'mart_compradores_por_geo', 'bairro_key');

    if ($cityNorm !== null) {
        $where[] = "(LOWER(TRIM(cidade_norm)) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')";
        $params['city'] = $cityNorm;
    }

    if ($resolvedKey !== null && $resolvedKey !== '') {
        if ($hasBairroKeyColCnae) {
            $where[] = '(bairro_key = :bairro_key OR (bairro_key IS NULL AND bairro_norm COLLATE utf8mb4_general_ci = :bairro_key_fb))';
            $params['bairro_key']    = $resolvedKey;
            $params['bairro_key_fb'] = $resolvedKey;
        } else {
            $where[] = 'bairro_norm COLLATE utf8mb4_general_ci = :bairro_key';
            $params['bairro_key'] = $resolvedKey;
        }
    }

    if ($cnae !== null) {
        if (strtoupper($cnae) === 'SEM_CNAE') {
            $where[] = "(cnae_codigo IS NULL OR TRIM(cnae_codigo) = '')";
        } else {
            $where[] = 'cnae_codigo = :cnae';
            $params['cnae'] = $cnae;
        }
    }

    $sql = '
        SELECT
            COALESCE(NULLIF(TRIM(cnae_codigo), \'\'), \'SEM_CNAE\') AS cnae,
            ' . $officialCnaeDescriptionExpr . ' AS cnae_description,
            COALESCE(NULLIF(TRIM(segmento_macro), \'\'), \'Nao classificado\') AS segment,
            SUM(faturamento) AS revenue,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento_normalizado, cliente_nome)) AS customers,
            SUM(qtd_notas) AS invoices
        FROM mart_vendas_por_cnae_cnpj
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY 1, 2, 3
        ORDER BY revenue DESC
        LIMIT :limit
    ';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $data = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $revenue     = (float) ($row['revenue'] ?? 0);
        $customers   = (int) ($row['customers'] ?? 0);
        $invoices    = (int) ($row['invoices'] ?? 0);
        $cnaeCode    = (string) ($row['cnae'] ?? 'SEM_CNAE');
        $description = (string) ($row['cnae_description'] ?? 'PJ sem CNAE');

        $data[] = [
            'cnae'             => $cnaeCode,
            'cnae_description' => $description,
            'segment'          => $row['segment'] ?? null,
            'revenue'          => $revenue,
            'customers'        => $customers,
            'invoices'         => $invoices,
            'cnae_codigo'      => $cnaeCode,
            'cnae_descricao'   => $description,
            'segmento_macro'   => $row['segment'] ?? null,
            'faturamento'      => $revenue,
            'qtd_clientes'     => $customers,
            'qtd_notas'        => $invoices,
        ];
    }

    $sourceTable = 'mart_vendas_por_cnae_cnpj';
    if ($data === [] && Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        // Build a dedicated WHERE for the geo mart fallback (may have different bairro_key column).
        $fbWhere  = ['competencia BETWEEN :start_comp AND :end_comp'];
        $fbParams = ['start_comp' => $filters['start_comp'], 'end_comp' => $filters['end_comp']];
        if ($cityNorm !== null) {
            $fbWhere[] = "(LOWER(TRIM(cidade_norm)) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')";
            $fbParams['city'] = $cityNorm;
        }
        if ($resolvedKey !== null && $resolvedKey !== '') {
            if ($hasBairroKeyColGeo) {
                $fbWhere[] = '(bairro_key = :bairro_key OR (bairro_key IS NULL AND bairro_norm COLLATE utf8mb4_general_ci = :bairro_key_fb))';
                $fbParams['bairro_key']    = $resolvedKey;
                $fbParams['bairro_key_fb'] = $resolvedKey;
            } else {
                $fbWhere[] = 'bairro_norm COLLATE utf8mb4_general_ci = :bairro_key';
                $fbParams['bairro_key'] = $resolvedKey;
            }
        }
        if ($cnae !== null) {
            if (strtoupper($cnae) === 'SEM_CNAE') {
                $fbWhere[] = "(cnae_codigo IS NULL OR TRIM(cnae_codigo) = '')";
            } else {
                $fbWhere[] = 'cnae_codigo = :cnae';
                $fbParams['cnae'] = $cnae;
            }
        }

        $officialGeoCnaeDescriptionExpr = "COALESCE(NULLIF(MAX(TRIM(cnae_descricao)), ''), 'PJ sem CNAE')";

        $fallbackSql = '
            SELECT
                COALESCE(NULLIF(TRIM(cnae_codigo), \'\'), \'SEM_CNAE\') AS cnae,
                ' . $officialGeoCnaeDescriptionExpr . ' AS cnae_description,
                COALESCE(NULLIF(MAX(TRIM(segmento_macro)), \'\'), \'Nao classificado\') AS segment,
                SUM(faturamento) AS revenue,
                COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers,
                SUM(qtd_notas) AS invoices
            FROM mart_compradores_por_geo
            WHERE ' . implode(' AND ', $fbWhere) . '
            GROUP BY 1
            ORDER BY revenue DESC
            LIMIT :limit
        ';

        $stmt = $pdo->prepare($fallbackSql);
        foreach ($fbParams as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $revenue     = (float) ($row['revenue'] ?? 0);
            $customers   = (int) ($row['customers'] ?? 0);
            $invoices    = (int) ($row['invoices'] ?? 0);
            $cnaeCode    = (string) ($row['cnae'] ?? 'SEM_CNAE');
            $description = (string) ($row['cnae_description'] ?? 'PJ sem CNAE');

            $data[] = [
                'cnae'             => $cnaeCode,
                'cnae_description' => $description,
                'segment'          => $row['segment'] ?? null,
                'revenue'          => $revenue,
                'customers'        => $customers,
                'invoices'         => $invoices,
                'cnae_codigo'      => $cnaeCode,
                'cnae_descricao'   => $description,
                'segmento_macro'   => $row['segment'] ?? null,
                'faturamento'      => $revenue,
                'qtd_clientes'     => $customers,
                'qtd_notas'        => $invoices,
            ];
        }

        if ($data !== []) {
            $sourceTable = 'mart_compradores_por_geo';
        }
    }

    $payload = [
        'ok'      => true,
        'filters' => [
            'date_from'    => $filters['date_from'],
            'date_to'      => $filters['date_to'],
            'city'         => $cityNorm,
            'neighborhood' => $neighborNorm,
            'cnae'         => $cnae,
            'seller_id'    => $filters['seller_id'],
        ],
        'data' => $data,
        'meta' => [
            'request_id'   => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'source_table' => $sourceTable,
            'source_mode'  => $sourceTable === 'mart_compradores_por_geo' ? 'geo_mart_fallback' : 'gold_mart',
            'count'        => count($data),
        ],
    ];

    if ($data === []) {
        $payload['empty_state'] = GeoMapSupport::emptyState();
    }

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('sales_by_cnae api error', ['exception' => $exception->getMessage()]);

    Http::json([
        'ok'         => false,
        'error'      => 'api_error',
        'message'    => 'Nao foi possivel carregar vendas por CNAE agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}

function cnaeColExists(PDO $pdo, string $table, string $col): bool
{
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'"
        )->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}
