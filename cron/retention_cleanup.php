<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Observability\TelemetryRetentionService;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['apply', 'batch-size:', 'max-batches:', 'json']);
$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$service = new TelemetryRetentionService(
    $pdo,
    Bootstrap::rootPath('storage/backups/retention'),
    [
        'spans' => (int) $config->get('observability.retention_days.spans', 30),
        'metrics' => (int) $config->get('observability.retention_days.metrics', 90),
        'events' => (int) $config->get('observability.retention_days.events', 90),
    ],
);

try {
    $result = $service->run(
        isset($options['apply']),
        (int) ($options['batch-size'] ?? 1000),
        (int) ($options['max-batches'] ?? 100),
    );
    $result['connection'] = $connection;
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
        'connection' => $connection,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
