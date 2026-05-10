<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\FinanceAnalyticsService;
use BIomie\FinanceRepository;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\OmieFinanceCanonicalSyncService;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;
use BIomie\RefreshQueue;
use BIomie\Telemetry;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan('job.sync_finance', [
    'domain_name' => 'finance',
    'source_name' => 'cron',
]);
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);

if (!$config->hasOmieCredentials()) {
    fwrite(STDERR, "Credenciais Omie não configuradas para o módulo financeiro.\n");
    exit(1);
}

$client = new OmieClient(
    (string) $config->get('omie.app_key'),
    (string) $config->get('omie.app_secret'),
    (int) $config->get('omie.request_timeout', 20),
    $logger,
    new OmieMethodPolicyRegistry($config),
    $cache,
);

$bronze = BronzeRepository::boot($config, $logger);
$salesRepository = new OmieRepository($client, $config, $bronze, 'finance-sync');
$financeRepository = new FinanceRepository($client, $config, $logger, null, $bronze, 'finance-sync');
$finance = new FinanceAnalyticsService($config, $cache, $logger, $financeRepository, $salesRepository, $queue);
$canonicalFinance = null;

$start = $argv[1] ?? date('Y-m-01');
$end = $argv[2] ?? date('Y-m-t');

// ── Trava de concorrência ────────────────────────────────────────────────────
$lockConn = Database::isConfigured($config, 'db') ? 'db' : null;
$lock = null;
if ($lockConn !== null) {
    $lockPdo = Database::connect($config, $lockConn);
    $lock = CronLock::acquire($lockPdo, 'biomie:sync_finance', $logger);
    if ($lock === null) exit(0);

    $reconnect = static fn () => Database::connect($config, $lockConn);
    $canonical = new CanonicalRepository($lockPdo, $logger, $reconnect);
    $canonical->ensureFiscalSchema();
    $canonicalFinance = new OmieFinanceCanonicalSyncService($financeRepository, $canonical, $logger);
}

try {
    $financeResult = $canonicalFinance?->syncPeriod((string) $start, (string) $end, 'finance-sync');
    $snapshot = $finance->getSnapshot((string) $start, (string) $end, true);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'mode' => $snapshot['mode'] ?? 'live',
        'period' => $snapshot['period'] ?? ['start' => $start, 'end' => $end],
        'canonical_finance' => $financeResult,
        'generated_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    $logger->error('Falha no cron financeiro', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $lock?->release();
}
