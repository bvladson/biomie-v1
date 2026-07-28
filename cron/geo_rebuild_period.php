<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Gold\GeoPeriodRebuildService;
use BIomie\Logger;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$root = dirname(__DIR__);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

$opts = getopt('', [
    'start:',
    'end:',
    'assert',
    'json',
    'dry-run',
    'force-full-month',
    'allow-open-month',
    'help',
]);

if (isset($opts['help']) || !isset($opts['start'], $opts['end'])) {
    echo "Usage: php cron/geo_rebuild_period.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--assert] [--json] [--dry-run] [--force-full-month] [--allow-open-month]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$jsonMode = isset($opts['json']);

try {
    [$pdo, $connectionName] = Database::getGeoConnection($config);
    $service = new GeoPeriodRebuildService($pdo, $logger);
    $result = $service->rebuild((string) $opts['start'], (string) $opts['end'], [
        'dry_run' => isset($opts['dry-run']),
        'assert' => isset($opts['assert']),
        'force_full_month' => isset($opts['force-full-month']),
        'allow_open_month' => isset($opts['allow-open-month']),
        'source' => 'cron/geo_rebuild_period.php',
    ]);
    $result['connection'] = $connectionName;
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'status' => 'failed',
        'locked' => false,
        'errors' => [$exception->getMessage()],
        'duration_ms' => 0,
    ];
}

if ($jsonMode) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "\n=== GEO REBUILD PERIOD ===\n";
    echo 'Status: ' . ($result['status'] ?? 'unknown') . "\n";
    echo 'OK: ' . (!empty($result['ok']) ? 'sim' : 'nao') . "\n";
    if (!empty($result['dry_run'])) {
        echo "[DRY RUN] Nenhuma alteracao foi feita.\n";
    }
    $window = is_array($result['window'] ?? null) ? $result['window'] : [];
    if ($window !== []) {
        echo 'Solicitado: ' . ($window['requested_start'] ?? '-') . ' ate ' . ($window['requested_end'] ?? '-') . "\n";
        echo 'Efetivo  : ' . ($window['effective_start'] ?? '-') . ' ate ' . ($window['effective_end'] ?? '-') . "\n";
        echo 'Competencias: ' . implode(', ', (array) ($window['competencias'] ?? [])) . "\n";
        echo 'Modo: ' . ($window['mode'] ?? '-') . "\n";
    }
    $source = is_array($result['source'] ?? null) ? $result['source'] : [];
    echo 'NFs canonicas autorizadas: ' . (int) ($source['canonical_nfs_autorizadas'] ?? 0) . "\n";
    echo 'Deleted cliente/geo: '
        . (int) ($result['deleted']['mart_cliente_periodo'] ?? 0)
        . '/'
        . (int) ($result['deleted']['mart_compradores_por_geo'] ?? 0)
        . "\n";
    echo 'Inserted cliente/geo: '
        . (int) ($result['inserted']['mart_cliente_periodo'] ?? 0)
        . '/'
        . (int) ($result['inserted']['mart_compradores_por_geo'] ?? 0)
        . "\n";
    $reconciliation = is_array($result['reconciliation'] ?? null) ? $result['reconciliation'] : [];
    if ($reconciliation !== []) {
        echo 'Reconciliacao: ' . ($reconciliation['status'] ?? '-') . "\n";
    }
    $diagnosis = is_array($result['diagnosis'] ?? null) ? $result['diagnosis'] : [];
    if ($diagnosis !== []) {
        echo 'Proxima acao: ' . ($diagnosis['next_action'] ?? '-') . "\n";
    }
    foreach ((array) ($result['errors'] ?? []) as $error) {
        echo "ERRO: {$error}\n";
    }
}

if (($result['status'] ?? '') === 'locked') {
    exit(2);
}
exit(!empty($result['ok']) ? 0 : 1);
