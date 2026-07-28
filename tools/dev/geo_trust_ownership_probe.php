<?php

declare(strict_types=1);

/**
 * Static guard for geographic trust and mart ownership contracts.
 *
 * Usage:
 *   php tools/dev/geo_trust_ownership_probe.php [--json] [--with-db]
 *       [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]
 */

$root = dirname(__DIR__, 2);
$options = getopt('', ['json', 'with-db', 'start:', 'end:']);
$asJson = array_key_exists('json', $options);
$withDb = array_key_exists('with-db', $options);

$files = [
    'repository' => $root . '/app/DataPlatform/Repository/GeoReadRepository.php',
    'runner' => $root . '/app/Modules/Geo/Application/GeoCnaeMaterializationRunner.php',
    'service' => $root . '/app/GeoCnaeMaterializationService.php',
    'official_service' => $root . '/app/DataPlatform/Gold/GeoPeriodRebuildService.php',
    'official_builder' => $root . '/app/DataPlatform/Gold/Builders/GeoBuyerMartBuilder.php',
];

$sources = [];
$errors = [];
foreach ($files as $name => $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        $errors[] = "Nao foi possivel ler {$path}.";
        continue;
    }
    $sources[$name] = $source;
}

$repository = $sources['repository'] ?? '';
$runner = $sources['runner'] ?? '';
$service = $sources['service'] ?? '';
$officialService = $sources['official_service'] ?? '';
$officialBuilder = $sources['official_builder'] ?? '';

$normalCityConflation = preg_match(
    '/LOWER\(TRIM\([^)]*cidade_norm\)\)\s*=\s*:city\s+OR\s+[^\\n]*cidade_norm\s+IS\s+NULL/i',
    $repository
) === 1;

$checks = [
    'normal_city_filters_are_exact' => !$normalCityConflation
        && substr_count($repository, "LOWER(TRIM(m.cidade_norm)) = :city") >= 1
        && str_contains($repository, 'LOWER(TRIM({$prefix}cidade_norm)) = :city'),
    'explicit_sem_geo_bucket_exists' => str_contains($repository, "\$type = 'sem_geo';")
        && str_contains($repository, "\$joinKey = '__sem_geo__';"),
    'explicit_sem_bairro_bucket_exists' => str_contains($repository, "\$type = 'sem_bairro';")
        && str_contains($repository, "\$joinKey = '__sem_bairro__:' . \$cidadeKey;"),
    'city_specific_sem_bairro_is_exact' => str_contains(
        $repository,
        'LOWER(TRIM({$prefix}cidade_norm)) = :geo_filter_city'
    ),
    'sem_geo_and_sem_bairro_are_disjoint' => str_contains(
        $repository,
        '$prefix}cidade_norm IS NOT NULL AND TRIM({$prefix}cidade_norm) <>'
    ),
    'runner_uses_official_rebuild_service' => str_contains($runner, 'GeoPeriodRebuildService')
        && str_contains($runner, '$this->geoPeriodRebuild->rebuild('),
    'runner_does_not_use_legacy_writer' => !str_contains($runner, 'GeoAggregateBuilder')
        && !str_contains($runner, 'buildCompradoresPorGeoForPeriod'),
    'facade_injects_official_rebuild_service' => str_contains($service, 'new GeoPeriodRebuildService(')
        && !str_contains($service, 'GeoAggregateBuilder'),
    'official_service_uses_official_builder' => str_contains($officialService, 'new GeoBuyerMartBuilder('),
    'official_builder_writes_geo_mart' => str_contains($officialBuilder, 'DELETE FROM mart_compradores_por_geo')
        && str_contains($officialBuilder, 'INSERT INTO mart_compradores_por_geo'),
];

$dynamic = ['executed' => false, 'checks' => [], 'metrics' => [], 'errors' => []];
if ($withDb && $errors === []) {
    try {
        require_once $root . '/app/Bootstrap.php';
        \BIomie\Bootstrap::init();

        $start = trim((string) ($options['start'] ?? date('Y-m-01')));
        $end = trim((string) ($options['end'] ?? date('Y-m-d')));
        $query = \BIomie\DataPlatform\Query\GeoQuery::fromRequest([
            'date_from' => $start,
            'date_to' => $end,
            'city' => 'Fortaleza',
            'layer' => 'fortaleza_bairros',
        ]);
        [$pdo, $connection] = \BIomie\Database::getGeoConnection(new \BIomie\Config());
        $repositoryInstance = new \BIomie\DataPlatform\Repository\GeoReadRepository($pdo);
        $summary = $repositoryInstance->summary($query);
        $compositeQuery = \BIomie\DataPlatform\Query\GeoQuery::fromRequest([
            'date_from' => $start,
            'date_to' => $end,
            'layer' => \BIomie\DataPlatform\Query\GeoQuery::DEFAULT_LAYER,
        ]);
        $composite = $repositoryInstance->compositeMap($compositeQuery);

        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN LOWER(TRIM(cidade_norm)) = 'fortaleza' THEN faturamento ELSE 0 END), 0) AS exact_revenue,
                    COALESCE(SUM(CASE WHEN cidade_norm IS NULL OR TRIM(cidade_norm) = '' THEN faturamento ELSE 0 END), 0) AS no_city_revenue,
                    COALESCE(SUM(CASE
                        WHEN LOWER(TRIM(cidade_norm)) IN ('fortaleza','caucaia','maracanau','maranguape','eusebio','guaiuba','itaitinga','pacatuba')
                         AND (bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(TRIM(bairro_norm)) IN ('nao informado','nao_informado','sem bairro','sem_bairro'))
                        THEN faturamento ELSE 0 END), 0) AS known_city_no_neighborhood_revenue
                FROM mart_compradores_por_geo
                WHERE competencia BETWEEN :start_comp AND :end_comp";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['start_comp' => $query->startComp, 'end_comp' => $query->endComp]);
        $direct = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $exactRevenue = (float) ($direct['exact_revenue'] ?? 0);
        $noCityRevenue = (float) ($direct['no_city_revenue'] ?? 0);
        $readRevenue = (float) ($summary['revenue'] ?? 0);
        $semGeoRevenue = (float) ($composite['territories']['__sem_geo__']['revenue'] ?? 0);
        $semGeoType = (string) ($composite['territories']['__sem_geo__']['territory_type'] ?? '');
        $knownCityNoNeighborhoodRevenue = (float) ($direct['known_city_no_neighborhood_revenue'] ?? 0);
        $semBairroRows = array_filter(
            (array) ($composite['territories'] ?? []),
            static fn (array $row): bool => ($row['territory_type'] ?? '') === 'sem_bairro'
        );
        $semBairroRevenue = array_reduce(
            $semBairroRows,
            static fn (float $sum, array $row): float => $sum + (float) ($row['revenue'] ?? 0),
            0.0
        );

        $dynamic = [
            'executed' => true,
            'connection' => $connection,
            'period' => ['start' => $start, 'end' => $end],
            'checks' => [
                'fortaleza_filter_matches_exact_city_only' => abs($readRevenue - $exactRevenue) < 0.01,
                'fortaleza_filter_excludes_no_city_revenue' => $noCityRevenue <= 0
                    || abs($readRevenue - ($exactRevenue + $noCityRevenue)) >= 0.01,
                'no_city_revenue_is_explicit_sem_geo' => abs($semGeoRevenue - $noCityRevenue) < 0.01
                    && ($noCityRevenue <= 0 || $semGeoType === 'sem_geo'),
                'known_city_without_neighborhood_is_explicit_sem_bairro' => abs(
                    $semBairroRevenue - $knownCityNoNeighborhoodRevenue
                ) < 0.01,
            ],
            'metrics' => [
                'fortaleza_exact_revenue' => $exactRevenue,
                'readmodel_fortaleza_revenue' => $readRevenue,
                'no_city_revenue' => $noCityRevenue,
                'sem_geo_revenue' => $semGeoRevenue,
                'known_city_no_neighborhood_revenue' => $knownCityNoNeighborhoodRevenue,
                'sem_bairro_revenue' => $semBairroRevenue,
                'sem_bairro_bucket_count' => count($semBairroRows),
            ],
            'errors' => [],
        ];
    } catch (\Throwable $exception) {
        $dynamic['executed'] = true;
        $dynamic['errors'][] = $exception->getMessage();
    }
}

$checks = array_merge($checks, (array) ($dynamic['checks'] ?? []));
$errors = array_merge($errors, (array) ($dynamic['errors'] ?? []));
$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
$payload = [
    'ok' => $errors === [] && $failed === [],
    'status' => $errors !== [] || $failed !== [] ? 'failed' : 'ok',
    'checks' => $checks,
    'dynamic' => $dynamic,
    'failed' => $failed,
    'errors' => $errors,
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "--- GEO TRUST + OWNERSHIP PROBE ---\n";
    foreach ($checks as $name => $passed) {
        echo sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $name);
    }
    foreach ($errors as $error) {
        echo "[ERROR] {$error}\n";
    }
    echo 'Status: ' . $payload['status'] . PHP_EOL;
}

exit($payload['ok'] ? 0 : 1);
