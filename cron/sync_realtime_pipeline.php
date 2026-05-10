<?php

declare(strict_types=1);

/**
 * Pipeline operacional da Fase 4.
 *
 * Uso:
 *   php cron/sync_realtime_pipeline.php
 *   php cron/sync_realtime_pipeline.php --domains=commercial
 *   php cron/sync_realtime_pipeline.php --reconcile 30
 *   php cron/sync_realtime_pipeline.php --backfill 2026-03-01 2026-03-31
 */

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
use BIomie\OmieFiscalSyncService;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieOrderEventSyncService;
use BIomie\OmieRealtimeSyncService;
use BIomie\OmieRepository;
use BIomie\RefreshQueue;
use BIomie\SyncPipelineRepository;
use BIomie\Telemetry;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan('job.sync_realtime_pipeline', [
    'domain_name' => 'sync',
    'source_name' => 'cron',
]);
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);

if (!$config->hasOmieCredentials()) {
    fwrite(STDERR, "Credenciais Omie nao configuradas.\n");
    exit(2);
}

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "Banco de dados nao configurado.\n");
    exit(2);
}

$args = $argv ?? [];
$domains = parseDomains($args);
$mode = 'delta';
$reconcileDays = (int) $config->get('sync_pipeline.reconcile_days', 30);
$backfillStart = null;
$backfillEnd = null;

if (in_array('--reconcile', $args, true)) {
    $mode = 'reconcile';
    $index = array_search('--reconcile', $args, true);
    $reconcileDays = max(1, (int) ($args[$index + 1] ?? $reconcileDays));
} elseif (in_array('--backfill', $args, true)) {
    $mode = 'backfill';
    $index = array_search('--backfill', $args, true);
    $backfillStart = (string) ($args[$index + 1] ?? '');
    $backfillEnd = (string) ($args[$index + 2] ?? '');
    if ($backfillStart === '' || $backfillEnd === '') {
        fwrite(STDERR, "Use --backfill YYYY-MM-DD YYYY-MM-DD.\n");
        exit(2);
    }
}

$pdo = Database::connect($config, $connection);
$reconnect = static fn () => Database::connect($config, $connection);
$pipeline = new SyncPipelineRepository($pdo, $logger);
$pipeline->ensureSchema();
$canonical = new CanonicalRepository($pdo, $logger, $reconnect);
$canonical->ensureFiscalSchema();
$bronze = new BronzeRepository($pdo, $logger);
$bronze->ensureSchema();

$lock = CronLock::acquire($pdo, 'biomie:realtime_pipeline', $logger);
if ($lock === null) {
    exit(0);
}

try {
    $client = new OmieClient(
        (string) $config->get('omie.app_key'),
        (string) $config->get('omie.app_secret'),
        (int) $config->get('omie.request_timeout', 20),
        $logger,
        new OmieMethodPolicyRegistry($config),
        $cache,
    );

    $omie = new OmieRepository($client, $config, $bronze, 'realtime-pipeline');
    $fiscalSync = new OmieFiscalSyncService($config, $omie, $canonical, $logger);
    $eventSync = new OmieOrderEventSyncService($omie, $canonical, $logger);
    $financeRepository = new FinanceRepository($client, $config, $logger, $cache, $bronze, 'realtime-pipeline');
    $financeSync = new OmieFinanceCanonicalSyncService($financeRepository, $canonical, $logger);
    $finance = new FinanceAnalyticsService($config, $cache, $logger, $financeRepository, $omie, $queue);
    $service = new OmieRealtimeSyncService(
        $config,
        $logger,
        $pipeline,
        $fiscalSync,
        $eventSync,
        $financeSync,
        $finance,
        $queue
    );

    if ($mode === 'reconcile') {
        $result = $service->runReconcile($reconcileDays, $domains);
    } elseif ($mode === 'backfill') {
        $result = $service->runBackfill((string) $backfillStart, (string) $backfillEnd, $domains);
    } else {
        $result = $service->runDelta($domains);
    }

    if (($result['ok'] ?? false) && isset($cache)) {
        $cache->put('biomie_live_stream_last_event_time', time());
    }

    fwrite(STDOUT, json_encode($result + [
        'domains' => $domains,
        'finished_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

    exit(($result['ok'] ?? false) ? 0 : 1);
} catch (Throwable $exception) {
    $logger->error('Falha no pipeline operacional da Fase 4.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'mode' => $mode,
        'domains' => $domains,
    ]);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $lock?->release();
}

/**
 * @param list<string> $args
 * @return list<string>
 */
function parseDomains(array $args): array
{
    foreach ($args as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--domains=')) {
            continue;
        }

        $raw = trim(substr($arg, 10));
        if ($raw === '') {
            return [];
        }

        $domains = array_map('trim', explode(',', $raw));
        return array_values(array_filter($domains, static fn (string $value): bool => $value !== ''));
    }

    return [];
}
