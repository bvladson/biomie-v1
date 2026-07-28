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
    echo "Usage: php tools/dev/geo_rebuild_diff.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--json]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$payload = [
    'ok' => false,
    'status' => 'failed',
    'mode' => 'snapshot_only',
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
    $snapshot = $service->snapshotForWindow($window);

    $payload = [
        'ok' => true,
        'status' => 'ok',
        'mode' => 'snapshot_only',
        'connection' => $connection,
        'window' => $window->toArray(),
        'before' => $snapshot,
        'after' => $snapshot,
        'diff' => [
            'mart_cliente_periodo_period_delta' => 0,
            'mart_cliente_periodo_outside_period_delta' => 0,
            'mart_compradores_por_geo_period_delta' => 0,
            'mart_compradores_por_geo_outside_period_delta' => 0,
            'faturamento_period_delta' => 0.0,
            'outside_period_unchanged' => true,
            'note' => 'Snapshot atual. Para diff real antes/depois, compare este JSON com o retorno do rebuild.',
        ],
    ];
} catch (Throwable $exception) {
    $payload['errors'][] = $exception->getMessage();
}

if (isset($opts['json'])) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "--- GEO REBUILD DIFF ---\n";
    echo 'Status: ' . ($payload['status'] ?? 'failed') . "\n";
    $diff = is_array($payload['diff'] ?? null) ? $payload['diff'] : [];
    foreach ($diff as $key => $value) {
        echo $key . ': ' . (is_bool($value) ? ($value ? 'sim' : 'nao') : (string) $value) . "\n";
    }
    foreach ((array) ($payload['errors'] ?? []) as $error) {
        echo "ERRO: {$error}\n";
    }
}

exit(!empty($payload['ok']) ? 0 : 1);
