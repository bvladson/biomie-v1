<?php

declare(strict_types=1);

/**
 * Operational status diagnostic for Omie sync pipeline.
 *
 * Usage:
 *   php tools/dev/omie_sync_status.php [options]
 *
 * Options:
 *   --json            Output JSON (default: human-readable)
 *   --domain=NAME     Filter by domain (commercial | finance)
 *   --last=N          Show last N sync runs (default: 20, max: 100)
 *   --failed          Show only failed runs
 *   --pending         Show pending/queued jobs only
 *   --stuck           Show stuck running jobs only
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Queue\DeadLetterRepository;
use BIomie\Queue\FileJobQueue;
use BIomie\Queue\WorkerHeartbeatRepository;
use BIomie\Support\SecretMask;
use BIomie\SyncPipelineRepository;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$args    = parseCliArgs($argv);
$asJson  = isset($args['json']);
$domain  = isset($args['domain']) && $args['domain'] !== true ? (string) $args['domain'] : 'all';
$last    = isset($args['last'])   && $args['last']   !== true ? min(100, max(1, (int) $args['last'])) : 20;
$failed  = isset($args['failed']);
$pending = isset($args['pending']);
$stuck   = isset($args['stuck']);
$root    = dirname(__DIR__, 2);

$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    cliOutput(['ok' => false, 'error' => 'db_not_configured', 'message' => 'Banco de dados nao configurado.'], $asJson);
    exit(2);
}

try {
    $pdo = Database::connect($config, $connection);
} catch (Throwable $exception) {
    cliOutput([
        'ok'      => false,
        'error'   => 'db_connection_failed',
        'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 220),
    ], $asJson);
    exit(2);
}

$pipeline      = new SyncPipelineRepository($pdo, $logger);
$pipeline->ensureSchema();
$queue         = new FileJobQueue($root . '/storage/cache', $logger);
$heartbeatRepo = new WorkerHeartbeatRepository($pdo);
$deadRepo      = new DeadLetterRepository($pdo);

// ─── Focused modes ────────────────────────────────────────────────────────────

if ($stuck) {
    $stuckJobs = findStuckRunningJobs($root . '/storage/cache/jobs');
    cliOutput(['ok' => true, 'mode' => 'stuck', 'stuck_count' => count($stuckJobs), 'jobs' => $stuckJobs], $asJson);
    exit(0);
}

if ($pending) {
    $qSummary = $queue->summary();
    $pendingJobs = listPendingJobs($root . '/storage/cache/jobs');
    cliOutput([
        'ok'           => true,
        'mode'         => 'pending',
        'queue_counts' => $qSummary['counts'] ?? [],
        'jobs'         => $pendingJobs,
    ], $asJson);
    exit(0);
}

// ─── Sync runs ────────────────────────────────────────────────────────────────

$allRuns = fetchRecentRuns($pdo, $last, $domain === 'all' ? null : $domain, $failed);

if ($failed) {
    cliOutput([
        'ok'         => true,
        'mode'       => 'failed',
        'domain'     => $domain,
        'runs_count' => count($allRuns),
        'runs'       => $allRuns,
    ], $asJson);
    exit(0);
}

// ─── Full status ──────────────────────────────────────────────────────────────

$domainStats = fetchDomainStats($pdo);
$heartbeat   = $heartbeatRepo->summary();
$qSummary    = $queue->summary();
$dead        = $deadRepo->counts();
$stuckCount  = count(findStuckRunningJobs($root . '/storage/cache/jobs'));

$canonicalFreshness = [
    'orders'   => lastUpdatedAt($pdo, 'biomie_pedidos_canonicos',              ['sincronizado_em', 'updated_at']),
    'invoices' => lastUpdatedAt($pdo, 'biomie_notas_fiscais_canonicas',        ['sincronizado_em', 'updated_at']),
    'finance'  => lastUpdatedAt($pdo, 'biomie_titulos_financeiros_canonicos',  ['sincronizado_em', 'updated_at']),
    'gold'     => lastUpdatedAt($pdo, 'mart_cliente_periodo',                  ['refreshed_at', 'updated_at']),
];

$cronSuggestions = buildCronSuggestions($domainStats, $heartbeat, $qSummary);

$result = [
    'ok'         => true,
    'generated_at' => date('c'),
    'domain_filter' => $domain,
    'domains'    => $domainStats,
    'canonical_freshness' => $canonicalFreshness,
    'worker_heartbeat' => [
        'status'      => $heartbeat['status'] ?? 'unknown',
        'age_seconds' => $heartbeat['age_seconds'] ?? null,
        'message'     => $heartbeat['message'] ?? null,
    ],
    'file_queue' => $qSummary['counts'] ?? [],
    'stuck_running_jobs' => $stuckCount,
    'dead_letter' => $dead,
    'recent_runs' => $allRuns,
    'cron_suggestions' => $cronSuggestions,
    'recommended_commands' => [
        'sync_commercial' => 'php cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json',
        'sync_finance'    => 'php cron/sync_realtime_pipeline.php --domains=finance --mode=delta --json',
        'reconcile_7d'    => 'php cron/sync_realtime_pipeline.php --domains=commercial --reconcile=7 --json',
        'worker_once'     => 'php cron/worker.php --once --queue=high,default,low --json',
        'worker_run'      => 'php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=120 --json',
        'materialize'     => 'php bin/materialize_marts.php --json',
    ],
];

if (!$asJson) {
    printSummary($result);
    exit(0);
}

cliOutput($result, $asJson);
exit(0);

// ─── Functions ────────────────────────────────────────────────────────────────

/** @return array<string,array<string,mixed>> */
function fetchDomainStats(PDO $pdo): array
{
    $domains = ['commercial' => [], 'finance' => []];

    if (!tableExists($pdo, 'biomie_sync_runs')) {
        foreach ($domains as $d => &$v) {
            $v = ['configured' => false, 'last_success_at' => null, 'last_failure_at' => null, 'lag_minutes' => null, 'status' => 'never_configured'];
        }
        return $domains;
    }

    $stmt = $pdo->query(<<<'SQL'
        SELECT
            domain_name,
            MAX(CASE WHEN status IN ('completed','completed_with_warnings') THEN finished_at END) AS last_success_at,
            MAX(CASE WHEN status IN ('failed_retryable','failed_terminal')  THEN finished_at END) AS last_failure_at,
            COUNT(*) AS total_runs,
            SUM(CASE WHEN status IN ('completed','completed_with_warnings') THEN 1 ELSE 0 END) AS success_count,
            SUM(CASE WHEN status IN ('failed_retryable','failed_terminal')  THEN 1 ELSE 0 END) AS failure_count,
            SUM(COALESCE(items_processed, 0)) AS total_records_processed
        FROM biomie_sync_runs
        WHERE domain_name IN ('commercial', 'finance')
        GROUP BY domain_name
    SQL);

    $thresholds = [
        'commercial' => ['ok' => 6,  'warn' => 36],
        'finance'    => ['ok' => 8,  'warn' => 48],
    ];

    foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $d = (string) ($row['domain_name'] ?? '');
        if (!isset($domains[$d])) {
            continue;
        }
        $lastOk  = ($row['last_success_at'] ?? null) ?: null;
        $lagMin  = lagMinutes($lastOk);
        $t       = $thresholds[$d] ?? ['ok' => 24, 'warn' => 72];
        $lagH    = $lagMin !== null ? $lagMin / 60 : null;
        $status  = $lagH === null ? 'never_ran'
                 : ($lagH <= $t['ok'] ? 'ok' : ($lagH <= $t['warn'] ? 'warning' : 'critical'));

        $domains[$d] = [
            'configured'             => true,
            'last_success_at'        => $lastOk,
            'last_failure_at'        => ($row['last_failure_at'] ?? null) ?: null,
            'lag_minutes'            => $lagMin,
            'status'                 => $status,
            'total_runs'             => (int) ($row['total_runs'] ?? 0),
            'success_count'          => (int) ($row['success_count'] ?? 0),
            'failure_count'          => (int) ($row['failure_count'] ?? 0),
            'total_records_processed'=> (int) ($row['total_records_processed'] ?? 0),
            'ok_threshold_hours'     => $t['ok'],
            'warn_threshold_hours'   => $t['warn'],
        ];
    }

    // Fill unconfigured domains
    foreach ($domains as $d => &$v) {
        if ($v === []) {
            $v = ['configured' => false, 'last_success_at' => null, 'last_failure_at' => null, 'lag_minutes' => null, 'status' => 'never_ran'];
        }
    }

    return $domains;
}

/**
 * @return list<array<string,mixed>>
 */
function fetchRecentRuns(PDO $pdo, int $limit, ?string $domain, bool $failedOnly): array
{
    if (!tableExists($pdo, 'biomie_sync_runs')) {
        return [];
    }

    $where  = [];
    $params = [];

    if ($domain !== null) {
        $where[] = 'domain_name = :domain';
        $params['domain'] = $domain;
    }
    if ($failedOnly) {
        $where[] = "status IN ('failed_retryable','failed_terminal')";
    }

    $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
    $limit = max(1, min(100, $limit));

    $stmt = $pdo->prepare(sprintf(
        'SELECT id, run_type, domain_name, status, items_processed, items_failed, error_summary, started_at, finished_at FROM biomie_sync_runs %s ORDER BY started_at DESC LIMIT %d',
        $whereClause,
        $limit
    ));
    $stmt->execute($params);

    return array_map(function (array $row) {
        $started  = strtotime((string) ($row['started_at'] ?? '')) ?: null;
        $finished = strtotime((string) ($row['finished_at'] ?? '')) ?: null;
        $row['duration_seconds'] = ($started && $finished) ? max(0, $finished - $started) : null;
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** @return list<array<string,mixed>> */
function findStuckRunningJobs(string $jobsDir): array
{
    $threshold = time() - 1800;
    $stuck = [];
    foreach (glob($jobsDir . '/running_job_*.json') ?: [] as $path) {
        $content = @file_get_contents($path);
        if ($content === false) {
            continue;
        }
        $job = json_decode($content, true);
        if (!is_array($job)) {
            continue;
        }
        $startedAt = strtotime((string) ($job['started_at'] ?? ''));
        if ($startedAt !== false && $startedAt > 0 && $startedAt < $threshold) {
            $ageMin = (int) round((time() - $startedAt) / 60);
            $stuck[] = [
                'job_id'      => $job['job_id'] ?? null,
                'type'        => $job['type']    ?? null,
                'queue'       => $job['queue']   ?? null,
                'started_at'  => $job['started_at'] ?? null,
                'age_minutes' => $ageMin,
            ];
        }
    }
    return $stuck;
}

/** @return list<array<string,mixed>> */
function listPendingJobs(string $jobsDir): array
{
    $jobs = [];
    foreach (['queued', 'failed_retryable'] as $status) {
        foreach (glob($jobsDir . "/{$status}_job_*.json") ?: [] as $path) {
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }
            $job = json_decode($content, true);
            if (!is_array($job)) {
                continue;
            }
            $jobs[] = [
                'job_id'    => $job['job_id']   ?? null,
                'type'      => $job['type']      ?? null,
                'queue'     => $job['queue']     ?? null,
                'status'    => $status,
                'attempts'  => $job['attempts']  ?? 0,
                'queued_at' => $job['queued_at'] ?? null,
            ];
        }
    }
    usort($jobs, fn ($a, $b) => strcmp((string) ($a['queued_at'] ?? ''), (string) ($b['queued_at'] ?? '')));
    return $jobs;
}

/** @param array<string,array<string,mixed>> $domainStats */
function buildCronSuggestions(array $domainStats, array $heartbeat, array $qSummary): array
{
    $suggestions = [];
    $heartbeatStatus = (string) ($heartbeat['status'] ?? 'unknown');

    if ($heartbeatStatus === 'unknown') {
        $suggestions[] = 'Worker nunca detectado — configure cron/worker.php no Hostinger (a cada 5 min)';
    } elseif ($heartbeatStatus === 'critical') {
        $suggestions[] = 'Worker parado — verifique o cron do worker no Hostinger';
    }

    foreach ($domainStats as $d => $stats) {
        if (in_array((string) ($stats['status'] ?? ''), ['never_ran', 'never_configured'], true)) {
            $suggestions[] = "Dominio '{$d}' nunca sincronizado — configure cron/sync_realtime_pipeline.php";
        } elseif ((string) ($stats['status'] ?? '') === 'critical') {
            $suggestions[] = "Dominio '{$d}' com atraso critico — execute sync imediato";
        }
    }

    if (empty($suggestions)) {
        $suggestions[] = 'Sistema operacional — apenas monitore o cron para continuidade';
    }

    return $suggestions;
}

function printSummary(array $result): void
{
    echo "\n=== Omie Sync Status ===\n";
    echo "Gerado em: " . ($result['generated_at'] ?? '') . "\n\n";

    echo "-- Dominios --\n";
    foreach ($result['domains'] as $domain => $stats) {
        $lag = $stats['lag_minutes'] !== null ? "{$stats['lag_minutes']}min atraso" : 'nunca executado';
        echo sprintf("  %-12s | %-10s | %s\n", $domain, $stats['status'] ?? '?', $lag);
    }

    echo "\n-- Worker --\n";
    $wh = $result['worker_heartbeat'];
    echo sprintf("  status: %s, age: %ss\n",
        $wh['status'] ?? 'unknown',
        $wh['age_seconds'] ?? 'N/A'
    );

    echo "\n-- Fila --\n";
    foreach ($result['file_queue'] as $status => $count) {
        if ($count > 0) {
            echo "  {$status}: {$count}\n";
        }
    }
    echo "  stuck_running: {$result['stuck_running_jobs']}\n";

    if ($result['cron_suggestions'] !== []) {
        echo "\n-- Sugestoes --\n";
        foreach ($result['cron_suggestions'] as $s) {
            echo "  * {$s}\n";
        }
    }

    echo "\n-- Runs recentes --\n";
    foreach (array_slice($result['recent_runs'] ?? [], 0, 10) as $run) {
        echo sprintf("  [%s] %-12s %-10s %s\n",
            substr((string) ($run['started_at'] ?? ''), 0, 16),
            $run['domain_name'] ?? '?',
            $run['status']      ?? '?',
            $run['error_summary'] ? (' ERR: ' . substr((string) $run['error_summary'], 0, 60)) : ''
        );
    }

    echo "\n-- Freshness canonica --\n";
    foreach ($result['canonical_freshness'] as $name => $date) {
        $lag = $date ? lagMinutes($date) . 'min' : 'nunca';
        echo "  {$name}: {$lag}\n";
    }

    echo "\nPara detalhes: php tools/dev/omie_sync_status.php --json\n";
}

function lagMinutes(?string $date): ?int
{
    if ($date === null || $date === '') {
        return null;
    }
    $ts = strtotime($date);
    return $ts !== false ? (int) round((time() - $ts) / 60) : null;
}

/** @param list<string> $columns */
function lastUpdatedAt(PDO $pdo, string $table, array $columns): ?string
{
    if (!tableExists($pdo, $table)) {
        return null;
    }
    foreach ($columns as $column) {
        if (!columnExists($pdo, $table, $column)) {
            continue;
        }
        $stmt = $pdo->query(sprintf('SELECT MAX(`%s`) FROM `%s`', $column, $table));
        $value = $stmt !== false ? $stmt->fetchColumn() : false;
        if ($value !== false && $value !== null && (string) $value !== '') {
            return (string) $value;
        }
    }
    return null;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function cliOutput(array $payload, bool $json): void
{
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

/** @return array<string,string|bool> */
function parseCliArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $out[$key] = $value;
    }
    return $out;
}
