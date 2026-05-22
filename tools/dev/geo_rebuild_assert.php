<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Gold\GeoPeriodRebuildService;
use BIomie\DataPlatform\Gold\MartRefreshWindow;
use BIomie\Logger;

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['start:', 'end:', 'json', 'help']);
if (isset($opts['help']) || !isset($opts['start'], $opts['end'])) {
    echo "Usage: php tools/dev/geo_rebuild_assert.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--json]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$asJson = isset($opts['json']);
$payload = [
    'ok' => false,
    'status' => 'failed',
    'database_available' => false,
    'errors' => [],
];

try {
    $config = new Config();
    $logger = new Logger(dirname(__DIR__, 2) . '/storage/logs/biomie.log');
    [$pdo, $connection] = Database::getGeoConnection($config);
    $service = new GeoPeriodRebuildService($pdo, $logger);
    $window = MartRefreshWindow::fromRange((string) $opts['start'], (string) $opts['end'], [
        'allow_open_month' => true,
        'force_full_month' => true,
    ]);
    $dry = $service->rebuild($window->requestedStart, $window->requestedEnd, [
        'dry_run' => true,
        'allow_open_month' => true,
        'force_full_month' => true,
        'source' => 'tools/dev/geo_rebuild_assert.php',
    ]);
    $snapshot = is_array($dry['before'] ?? null) ? $dry['before'] : [];
    $source = is_array($dry['source'] ?? null) ? $dry['source'] : [];
    $assertions = $service->assertWindow($window, $snapshot, $snapshot, $source);

    $payload = [
        'ok' => ($assertions['status'] ?? 'failed') === 'ok',
        'status' => $assertions['status'] ?? 'failed',
        'database_available' => true,
        'connection' => $connection,
        'window' => $window->toArray(),
        'source' => $source,
        'snapshot' => $snapshot,
        'assertions' => $assertions,
    ];
} catch (Throwable $exception) {
    $payload['errors'][] = $exception->getMessage();
}

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "--- GEO REBUILD ASSERT ---\n";
    echo 'Status: ' . ($payload['status'] ?? 'failed') . "\n";
    echo 'Database: ' . (!empty($payload['database_available']) ? 'ok' : 'indisponivel') . "\n";
    $checks = $payload['assertions']['checks'] ?? [];
    foreach (is_array($checks) ? $checks : [] as $key => $value) {
        echo $key . ': ' . (is_bool($value) ? ($value ? 'sim' : 'nao') : json_encode($value, JSON_UNESCAPED_UNICODE)) . "\n";
    }
    foreach ((array) ($payload['errors'] ?? []) as $error) {
        echo "ERRO: {$error}\n";
    }
}

$status = (string) ($payload['status'] ?? 'failed');
exit($status === 'ok' ? 0 : ($status === 'warning' ? 2 : 1));
