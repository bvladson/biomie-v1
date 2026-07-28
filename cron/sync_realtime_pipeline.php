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
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieFinanceCanonicalSyncService;
use BIomie\OmieFiscalSyncService;
use BIomie\OmieOrderEventSyncService;
use BIomie\OmieRealtimeSyncService;
use BIomie\OmieRepository;
use BIomie\RefreshQueue;
use BIomie\SharedDatabaseConnection;
use BIomie\Support\SecretMask;
use BIomie\Support\TenantCliContext;
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

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "Banco de dados nao configurado.\n");
    exit(2);
}

$args = $argv ?? [];
$sharedConnection = new SharedDatabaseConnection($config, $connection);
$pdo = $sharedConnection->current();
$tenant = TenantCliContext::boot($pdo, $args);
$args = $tenant['args'];
$options = parseOptions($args);
$domains = parseDomains($args);
$mode = (string) ($options['mode'] ?? 'delta');
$reconcileDays = (int) $config->get('sync_pipeline.reconcile_days', 30);
$backfillStart = isset($options['start']) ? (string) $options['start'] : null;
$backfillEnd = isset($options['end']) ? (string) $options['end'] : null;
$runId = isset($options['run-id']) ? (string) $options['run-id'] : null;
$dryRun = isset($options['dry-run']);

if (in_array('--reconcile', $args, true)) {
    $mode = 'reconcile';
    $index = array_search('--reconcile', $args, true);
    $reconcileDays = max(1, (int) ($args[$index + 1] ?? $reconcileDays));
}
if (isset($options['reconcile'])) {
    $mode = 'reconcile';
    $reconcileDays = max(1, (int) $options['reconcile']);
}
if (in_array('--backfill', $args, true)) {
    $mode = 'backfill';
    $index = array_search('--backfill', $args, true);
    $backfillStart = (string) ($args[$index + 1] ?? '');
    $backfillEnd = (string) ($args[$index + 2] ?? '');
}
if ($mode === 'backfill' || $mode === 'full') {
    if (($backfillStart ?? '') === '' || ($backfillEnd ?? '') === '') {
        fwrite(STDERR, "Use --backfill YYYY-MM-DD YYYY-MM-DD ou --mode=backfill --start=YYYY-MM-DD --end=YYYY-MM-DD.\n");
        exit(2);
    }
}

if (!in_array($mode, ['delta', 'full', 'reconcile', 'backfill'], true)) {
    fwrite(STDERR, "Modo invalido. Use --mode=delta|full|reconcile|backfill.\n");
    exit(2);
}

if ($dryRun) {
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'dry_run' => true,
        'mode' => $mode,
        'domains' => $domains,
        'start' => $backfillStart,
        'end' => $backfillEnd,
        'reconcile_days' => $reconcileDays,
        'run_id' => $runId,
        'company_id' => $tenant['company_id'],
        'company_slug' => $tenant['slug'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
}

$reconnect = static fn () => $sharedConnection->recover();
$pipeline = new SyncPipelineRepository($pdo, $logger, $tenant['company_id']);
$pipeline->ensureSchema();
$canonical = new CanonicalRepository($pdo, $logger, $reconnect, $tenant['company_id'], $tenant['canal']);
$canonical->ensureFiscalSchema();
$bronze = new BronzeRepository($pdo, $logger, $tenant['company_id'], $reconnect);
$bronze->ensureSchema();

$lockName = 'biomie:fiscal_write:company:' . $tenant['company_id'];
$lock = CronLock::acquire(
    $pdo,
    $lockName,
    $logger
);
if ($lock === null) {
    exit(0);
}
$sharedConnection->afterReconnect(static function (PDO $newPdo) use (&$lock, $lockName, $logger): void {
    $renewed = CronLock::acquire($newPdo, $lockName, $logger);
    if ($renewed === null) {
        throw new RuntimeException('Conexao recuperada sem conseguir renovar o lock fiscal.');
    }
    $lock = $renewed;
    // O pipeline, a fila e os servicos financeiros conservam referencias ao
    // PDO original. Abortar permite que o worker/cron refaca o job inteiro de
    // forma idempotente, sem continuar com telemetria em uma sessao morta.
    throw new RuntimeException(
        'Conexao MariaDB foi recuperada; execucao abortada com seguranca para retry idempotente.'
    );
});

try {
    $client = (new OmieClientFactory($pdo, $logger, $config, $cache, $reconnect))
        ->forCompany($tenant['company_id']);

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
    } elseif ($mode === 'backfill' || $mode === 'full') {
        $result = $service->runBackfill((string) $backfillStart, (string) $backfillEnd, $domains);
    } else {
        $result = $service->runDelta($domains, null, $runId);
    }

    if ($result['ok']) {
        $cache->put('biomie_live_stream_last_event_time', ['ts' => time()]);
    }

    fwrite(STDOUT, json_encode($result + [
        'domains' => $domains,
        'mode' => $mode,
        'run_id' => $runId,
        'finished_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

    exit($result['ok'] ? 0 : 1);
} catch (Throwable $exception) {
    $message = SecretMask::sanitizeErrorMessage($exception->getMessage(), 500);
    $logger->error('Falha no pipeline operacional da Fase 4.', [
        'message' => $message,
        'trace' => $exception->getTraceAsString(),
        'mode' => $mode,
        'domains' => $domains,
    ]);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
} finally {
    $sharedConnection->afterReconnect(null);
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

/**
 * @param list<string> $args
 * @return array<string,string|bool>
 */
function parseOptions(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $options[$key] = $value;
    }

    return $options;
}
