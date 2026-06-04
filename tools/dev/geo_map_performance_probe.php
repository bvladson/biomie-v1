<?php

declare(strict_types=1);

/**
 * GEO.R2.1 performance probe for the map ReadModel migration.
 *
 * Usage:
 *   php tools/dev/geo_map_performance_probe.php
 *   php tools/dev/geo_map_performance_probe.php --json
 *   php tools/dev/geo_map_performance_probe.php --base-url=https://example.com/biomie --json
 */

$root = dirname(__DIR__, 2);
require $root . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $root . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$argv = $GLOBALS['argv'] ?? [];
$args = parsePerfArgs(array_slice($argv, 1));
$json = truthyPerf($args['json'] ?? '0') || strtolower((string) ($args['format'] ?? '')) === 'json';

$config = new \BIomie\Config();
$queryParams = [
    'date_from' => (string) ($args['start'] ?? '2026-01-01'),
    'date_to' => (string) ($args['end'] ?? date('Y-m-d')),
    'layer' => (string) ($args['layer'] ?? 'mapa_composto_ceara'),
];
if (!empty($args['city'])) {
    $queryParams['city'] = (string) $args['city'];
}
if (!empty($args['cnae'])) {
    $queryParams['cnae'] = (string) $args['cnae'];
}
if (!empty($args['seller_id'])) {
    $queryParams['seller_id'] = (string) $args['seller_id'];
}

$model = \BIomie\DataPlatform\ReadModel\GeoReadModelFactory::make($config);
$query = \BIomie\DataPlatform\Query\GeoQuery::fromRequest($queryParams);

$measurements = [];
$measurements[] = measureModel('geo_summary_readmodel', $model, static fn () => $model->summary($query)->toArray());
$measurements[] = measureModel('geo_composite_sales_readmodel', $model, static fn () => $model->compositeMap($query)->toArray());
$measurements[] = measureModel('geo_map_payload_readmodel', $model, static fn () => $model->mapPayload($query)->toArray());
$measurements[] = measureModel('sales_by_neighborhood_readmodel', $model, static fn () => $model->legacyNeighborhoodPayload($query, $queryParams));

$territoryRequest = pickTerritoryRequest($model, $query, $queryParams);
$territoryQuery = \BIomie\DataPlatform\Query\GeoQuery::fromRequest(array_merge($queryParams, $territoryRequest));
$measurements[] = measureModel('geo_territory_invoices_readmodel', $model, static fn () => $model->legacyTerritoryInvoicesPayload($territoryQuery, $territoryRequest, false));
$measurements[] = measureModel('geo_territory_buyers_readmodel', $model, static fn () => $model->legacyTerritoryBuyersPayload($territoryQuery, $territoryRequest, false));
$measurements[] = measureModel('geo_territory_cnaes_readmodel', $model, static fn () => $model->legacyTerritoryCnaesPayload($territoryQuery, $territoryRequest));

$httpMeasurements = [];
$baseUrl = rtrim((string) ($args['base_url'] ?? ''), '/');
if ($baseUrl !== '') {
    $qs = http_build_query($queryParams);
    $territoryQs = http_build_query(array_merge($queryParams, $territoryRequest));
    $httpMeasurements[] = measureHttp('api_geo_summary_readmodel', "{$baseUrl}/api/geo_summary.php?readmodel=1&{$qs}");
    $httpMeasurements[] = measureHttp('api_geo_composite_sales_readmodel', "{$baseUrl}/api/geo_composite_sales.php?readmodel=1&{$qs}");
    $httpMeasurements[] = measureHttp('api_geo_map_payload', "{$baseUrl}/api/geo_map_payload.php?{$qs}");
    $httpMeasurements[] = measureHttp('page_map_single_payload', "{$baseUrl}/mapa_vendas.php?single_payload=1&{$qs}");
    $httpMeasurements[] = measureHttp('api_sales_by_neighborhood_readmodel', "{$baseUrl}/api/sales_by_neighborhood.php?readmodel=1&{$qs}");
    $httpMeasurements[] = measureHttp('api_geo_territory_invoices_readmodel', "{$baseUrl}/api/geo_territory_invoices.php?readmodel=1&{$territoryQs}");
    $httpMeasurements[] = measureHttp('api_geo_territory_buyers_readmodel', "{$baseUrl}/api/geo_territory_buyers.php?readmodel=1&{$territoryQs}");
    $httpMeasurements[] = measureHttp('api_geo_territory_cnaes_readmodel', "{$baseUrl}/api/geo_territory_cnaes.php?readmodel=1&{$territoryQs}");
}

$warnings = [];
foreach ($measurements as $measurement) {
    $label = (string) ($measurement['label'] ?? '');
    $timeMs = (int) ($measurement['php_time_ms'] ?? 0);
    if ($label === 'geo_map_payload_readmodel' && $timeMs > 800) {
        $warnings[] = 'geo_map_payload_readmodel acima da meta interna de 800ms.';
    }
    if ($label === 'geo_map_payload_readmodel' && (int) ($measurement['json_size_bytes'] ?? 0) > 1572864) {
        $warnings[] = 'geo_map_payload_readmodel acima da meta interna de 1.5 MB.';
    }
    if ($label === 'geo_territory_invoices_readmodel' && $timeMs > 700) {
        $warnings[] = 'geo_territory_invoices_readmodel acima da meta interna de 700ms.';
    }
    if ($label === 'geo_territory_buyers_readmodel' && $timeMs > 500) {
        $warnings[] = 'geo_territory_buyers_readmodel acima da meta interna de 500ms.';
    }
    if ($label === 'geo_territory_cnaes_readmodel' && $timeMs > 500) {
        $warnings[] = 'geo_territory_cnaes_readmodel acima da meta interna de 500ms.';
    }
}

$payload = [
    'ok' => true,
    'status' => $warnings === [] ? 'ok' : 'warning',
    'period' => [
        'date_from' => $queryParams['date_from'],
        'date_to' => $queryParams['date_to'],
    ],
    'filters' => $query->filtersMeta(),
    'territory_sample' => $territoryRequest,
    'targets' => [
        'geo_map_payload_ms' => 800,
        'geo_map_payload_json_bytes' => 1572864,
        'drawer_buyers_ms' => 500,
        'drawer_cnaes_ms' => 500,
        'drawer_invoices_ms' => 700,
    ],
    'measurements' => $measurements,
    'http_measurements' => $httpMeasurements,
    'warnings' => $warnings,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

echo "Geo map performance probe\n";
echo "Period: {$queryParams['date_from']} to {$queryParams['date_to']}\n";
foreach ($measurements as $measurement) {
    echo sprintf(
        "- %s: %dms PHP | %.2fms SQL | %d SQL | %d bytes JSON\n",
        (string) $measurement['label'],
        (int) $measurement['php_time_ms'],
        (float) $measurement['sql_time_ms'],
        (int) $measurement['sql_queries'],
        (int) $measurement['json_size_bytes']
    );
}
foreach ($warnings as $warning) {
    echo "[WARN] {$warning}\n";
}
exit(0);

/**
 * @param list<string> $raw
 * @return array<string,string>
 */
function parsePerfArgs(array $raw): array
{
    $args = [];
    foreach ($raw as $item) {
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        if (str_starts_with($item, '--')) {
            $item = substr($item, 2);
        }
        if (str_contains($item, '=')) {
            [$key, $value] = explode('=', $item, 2);
            $args[str_replace('-', '_', trim($key))] = trim($value);
        } else {
            $args[str_replace('-', '_', $item)] = '1';
        }
    }

    return $args;
}

function truthyPerf(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true);
}

/**
 * @return array<string,mixed>
 */
function measureModel(string $label, \BIomie\DataPlatform\ReadModel\GeoReadModel $model, callable $callback): array
{
    $model->resetPerformanceMetrics();
    $memStart = memory_get_usage(true);
    $start = microtime(true);
    try {
        $result = $callback();
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $metrics = $model->performanceMetrics();

        return [
            'label' => $label,
            'ok' => true,
            'php_time_ms' => (int) round((microtime(true) - $start) * 1000),
            'sql_time_ms' => (float) $metrics['sql_time_ms'],
            'sql_queries' => (int) $metrics['query_count'],
            'memory_delta_bytes' => memory_get_usage(true) - $memStart,
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'json_size_bytes' => is_string($json) ? strlen($json) : 0,
        ];
    } catch (Throwable $exception) {
        $metrics = $model->performanceMetrics();
        return [
            'label' => $label,
            'ok' => false,
            'php_time_ms' => (int) round((microtime(true) - $start) * 1000),
            'sql_time_ms' => (float) $metrics['sql_time_ms'],
            'sql_queries' => (int) $metrics['query_count'],
            'memory_delta_bytes' => memory_get_usage(true) - $memStart,
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'json_size_bytes' => 0,
            'error' => $exception->getMessage(),
        ];
    }
}

/**
 * @param array<string,string> $queryParams
 * @return array<string,string>
 */
function pickTerritoryRequest(\BIomie\DataPlatform\ReadModel\GeoReadModel $model, \BIomie\DataPlatform\Query\GeoQuery $query, array $queryParams): array
{
    $payload = $model->compositeMap($query)->toArray();
    $ranking = (array) (($payload['data']['ranking'] ?? []));
    foreach ($ranking as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cidadeKey = (string) ($row['cidade_key'] ?? '');
        if ($cidadeKey === '' || $cidadeKey === 'sem_cidade') {
            continue;
        }
        $type = (string) ($row['territory_type'] ?? 'ceara_municipio');
        $request = [
            'territory_type' => $type,
            'cidade_key' => $cidadeKey,
            'bairro_key' => '',
        ];
        if (in_array($type, ['fortaleza_bairro', 'metro_bairro'], true)) {
            $request['bairro_key'] = (string) ($row['bairro_norm'] ?? '');
        }

        return array_merge($queryParams, $request);
    }

    return array_merge($queryParams, [
        'territory_type' => 'ceara_municipio',
        'cidade_key' => 'fortaleza',
        'bairro_key' => '',
    ]);
}

/** @return array<string,mixed> */
function measureHttp(string $label, string $url): array
{
    $start = microtime(true);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : [];
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $header, $m) === 1) {
            $status = (int) $m[1];
            break;
        }
    }

    return [
        'label' => $label,
        'url' => $url,
        'status' => $status,
        'ok' => $body !== false && $status >= 200 && $status < 400,
        'http_time_ms' => (int) round((microtime(true) - $start) * 1000),
        'body_size_bytes' => is_string($body) ? strlen($body) : 0,
    ];
}
