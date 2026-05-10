<?php

declare(strict_types=1);

/**
 * Sincronizacao canonica - CLI.
 *
 * Uso:
 *   php cron/sync_canonical.php
 *   php cron/sync_canonical.php 2026-03-01 2026-03-31
 *   php cron/sync_canonical.php --reconcile 60
 *   php cron/sync_canonical.php --from=2024-01 --to=2026-03 --pause=8
 *
 * Exit codes:
 *   0 = sucesso
 *   1 = erro
 *   2 = configuracao ausente
 */

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\CanonicalRepository;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\OmieOrderEventSyncService;
use BIomie\OmieFiscalSyncService;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;
use BIomie\Telemetry;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan('job.sync_canonical', [
    'domain_name' => 'sync',
    'source_name' => 'cron',
]);
$cache = new Cache(dirname(__DIR__) . '/storage/cache');

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
$run = parseSyncRun($args);
$lock = null;

try {
    $pdo = Database::connect($config, $connection);
    $reconnect = static fn () => Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger, $reconnect);
    $bronze = new BronzeRepository($pdo, $logger);
    $bronze->ensureSchema();
    $canonical->ensureFiscalSchema();

    if (!$canonical->isAvailable()) {
        fwrite(STDERR, "Tabela biomie_pedidos_canonicos nao existe. Execute database/canonical_sales.sql primeiro.\n");
        exit(2);
    }

    $lock = \BIomie\CronLock::acquire($pdo, 'biomie:sync_canonical', $logger);
    if ($lock === null) {
        exit(0);
    }

    $client = new OmieClient(
        (string) $config->get('omie.app_key'),
        (string) $config->get('omie.app_secret'),
        (int) $config->get('omie.request_timeout', 20),
        $logger,
        new OmieMethodPolicyRegistry($config),
        $cache,
    );

    $omie = new OmieRepository($client, $config, $bronze, 'canonical-sync');
    $sync = new OmieFiscalSyncService($config, $omie, $canonical, $logger);
    $eventSync = new OmieOrderEventSyncService($omie, $canonical, $logger);

    $results = [];
    $hasErrors = false;
    foreach ($run['periods'] as $index => $period) {
        $start = $period['start'];
        $end = $period['end'];

        try {
            $result = $sync->syncPeriod($start, $end, $run['source']);
            $eventResult = $eventSync->syncPeriod($start, $end, $run['source'], (int) ($result['vendors_synced'] ?? 0));
            $results[] = [
                'period' => ['start' => $start, 'end' => $end],
                'vendors_synced' => $result['vendors_synced'],
                'invoices' => $result['invoices'] ?? null,
                'orders' => $result['orders'] ?? null,
                'pending_orders' => $result['pending_orders'] ?? null,
                'events' => $eventResult['events'] ?? null,
                'events_checked' => $eventResult['events_checked'] ?? null,
                'divergences' => $result['divergences'] ?? null,
                'duration_seconds' => $result['duration_seconds'] ?? null,
                'event_duration_seconds' => $eventResult['duration_seconds'] ?? null,
                'status' => 'ok',
            ];
        } catch (Throwable $e) {
            $hasErrors = true;
            $results[] = [
                'period' => ['start' => $start, 'end' => $end],
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
            throw $e;
        }

        if ($run['pause_seconds'] > 0 && $index < count($run['periods']) - 1) {
            sleep($run['pause_seconds']);
        }
    }

    fwrite(STDOUT, json_encode([
        'ok' => !$hasErrors,
        'mode' => $run['mode'],
        'processed' => count($results),
        'pause_seconds' => $run['pause_seconds'],
        'results' => $results,
        'finished_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

    exit(0);
} catch (Throwable $e) {
    $logger->error('Falha no sync canonico', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $lock?->release();
}

/**
 * @param list<string> $args
 * @return array{
 *   mode:string,
 *   source:string,
 *   pause_seconds:int,
 *   periods:list<array{start:string,end:string}>
 * }
 */
function parseSyncRun(array $args): array
{
    if (in_array('--reconcile', $args, true)) {
        $idx = array_search('--reconcile', $args, true);
        $days = max(1, (int) ($args[$idx + 1] ?? 60));
        $start = date('Y-m-d', strtotime("-{$days} days"));
        $end = date('Y-m-d');
        fwrite(STDOUT, "Modo reconciliacao: ultimos {$days} dias ({$start} a {$end})\n");

        return [
            'mode' => 'reconcile',
            'source' => 'cron-reconcile',
            'pause_seconds' => 0,
            'periods' => [['start' => $start, 'end' => $end]],
        ];
    }

    $from = null;
    $to = null;
    $pauseSeconds = 8;
    foreach ($args as $arg) {
        if (!is_string($arg)) {
            continue;
        }

        if (str_starts_with($arg, '--from=')) {
            $from = normalizeCompetencia(substr($arg, 7));
            continue;
        }

        if (str_starts_with($arg, '--to=')) {
            $to = normalizeCompetencia(substr($arg, 5));
            continue;
        }

        if (str_starts_with($arg, '--pause=')) {
            $pauseSeconds = max(0, (int) substr($arg, 8));
        }
    }

    if ($from !== null || $to !== null) {
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('Use o intervalo completo com --from=YYYY-MM --to=YYYY-MM.');
        }

        if ($from > $to) {
            throw new InvalidArgumentException('O intervalo de sincronizacao precisa respeitar from <= to.');
        }

        return [
            'mode' => 'monthly_range',
            'source' => 'cron-backfill',
            'pause_seconds' => $pauseSeconds,
            'periods' => buildMonthlyPeriods($from, $to),
        ];
    }

    if (($args[1] ?? null) === '--') {
        array_splice($args, 1, 1);
    }

    $start = (string) ($args[1] ?? date('Y-m-01'));
    $end = (string) ($args[2] ?? date('Y-m-t'));

    return [
        'mode' => 'single_period',
        'source' => 'cron',
        'pause_seconds' => 0,
        'periods' => [['start' => $start, 'end' => $end]],
    ];
}

function normalizeCompetencia(string $competencia): string
{
    $competencia = trim($competencia);
    if (preg_match('/^\d{4}-\d{2}$/', $competencia) !== 1) {
        throw new InvalidArgumentException('Competencia deve seguir o formato YYYY-MM.');
    }

    $month = \DateTimeImmutable::createFromFormat('!Y-m', $competencia);
    if (!$month instanceof \DateTimeImmutable || $month->format('Y-m') !== $competencia) {
        throw new InvalidArgumentException('Competencia invalida. Use YYYY-MM.');
    }

    return $competencia;
}

/**
 * @return list<array{start:string,end:string}>
 */
function buildMonthlyPeriods(string $from, string $to): array
{
    $cursor = \DateTimeImmutable::createFromFormat('!Y-m', $from);
    $limit = \DateTimeImmutable::createFromFormat('!Y-m', $to);
    if (!$cursor instanceof \DateTimeImmutable || !$limit instanceof \DateTimeImmutable) {
        throw new InvalidArgumentException('Intervalo mensal invalido.');
    }

    $periods = [];
    while ($cursor <= $limit) {
        $periods[] = [
            'start' => $cursor->format('Y-m-01'),
            'end' => $cursor->format('Y-m-t'),
        ];
        $cursor = $cursor->modify('+1 month');
    }

    return $periods;
}
