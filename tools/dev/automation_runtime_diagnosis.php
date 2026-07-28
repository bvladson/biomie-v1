<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$args = parseArgs($argv);
$json = isset($args['json']);
$pretty = isset($args['pretty']) || !$json;
$sinceHours = max(1, min(720, (int) ($args['since-hours'] ?? 24)));
$includeCommands = isset($args['include-commands']);
$maskSecrets = isset($args['mask-secrets']) || !isset($args['no-mask-secrets']);
$root = dirname(__DIR__, 2);

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

$report = buildAutomationReport($pdo, $config, $root, $connection, $sinceHours, $includeCommands, $maskSecrets);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['status'] === 'CRITICAL' ? 1 : 0);
}

printAutomationPretty($report);
exit($report['status'] === 'CRITICAL' ? 1 : 0);

/**
 * @return array<string,string|bool>
 */
function parseArgs(array $argv): array
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

/**
 * @return array<string,mixed>
 */
function buildAutomationReport(PDO $pdo, Config $config, string $root, string $connection, int $sinceHours, bool $includeCommands, bool $maskSecrets): array
{
    $jobsDir = $root . '/storage/cache/jobs';
    $fileQueue = readFileQueue($jobsDir);
    $dbJobs = readDatabaseJobs($pdo, $sinceHours);
    $heartbeat = readWorkerHeartbeat($pdo);
    $deadLetters = readDeadLetters($pdo, $sinceHours);
    $sync = readSyncState($pdo, $sinceHours);
    $pipeline = readPipelineJobs($pdo, $sinceHours);
    $locks = readRuntimeLocks($pdo, $root);
    $environment = readRuntimeEnvironment($pdo, $config, $root, $connection, $maskSecrets);
    $errors = readRecentErrors($pdo, $fileQueue, $dbJobs, $sync, $sinceHours, $maskSecrets);

    $pending = (int) ($fileQueue['counts']['queued'] ?? 0) + (int) ($fileQueue['counts']['failed_retryable'] ?? 0);
    $running = (int) ($fileQueue['counts']['running'] ?? 0);
    $failedTerminal = (int) ($fileQueue['counts']['failed_terminal'] ?? 0);
    $deadUnresolved = (int) ($deadLetters['counts']['unresolved'] ?? 0);
    $heartbeatStatus = (string) ($heartbeat['status'] ?? 'unknown');

    $causes = [];
    $status = 'OK';
    if ($pending > 0 && in_array($heartbeatStatus, ['critical', 'unknown'], true)) {
        $status = 'CRITICAL';
        $causes[] = 'Há jobs pendentes e o worker não tem heartbeat recente.';
    }
    if (($sync['overall_status'] ?? 'OK') === 'CRITICAL') {
        $status = 'CRITICAL';
        $causes[] = 'Freshness Omie tem domínio em atraso crítico.';
    } elseif (($sync['overall_status'] ?? 'OK') === 'WARNING' && $status !== 'CRITICAL') {
        $status = 'WARNING';
        $causes[] = 'Freshness Omie acima do ideal, mas abaixo do limite crítico.';
    }
    if ($failedTerminal > 0 || $deadUnresolved > 0) {
        if ($status !== 'CRITICAL') {
            $status = 'WARNING';
        }
        $causes[] = 'Existem falhas finais ou dead letters para triagem.';
    }
    if ($pending === 0 && $heartbeatStatus === 'critical' && $status !== 'CRITICAL') {
        $status = 'WARNING';
        $causes[] = 'Fila vazia, mas heartbeat do worker está antigo; cron precisa ser confirmado.';
    }

    if ($causes === []) {
        $causes[] = 'Automação sem bloqueio evidente no diagnóstico local.';
    }

    $commands = [
        'worker' => 'php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55 --stop-when-empty --json',
        'sync_commercial' => '/bin/sh cron/hostinger/sync_commercial.sh',
        'sync_finance' => '/bin/sh cron/hostinger/sync_finance.sh',
        'materialize_gold' => '/bin/sh cron/hostinger/materialize.sh',
        'geo_rebuild' => 'php cron/geo_rebuild_period.php --start=YYYY-MM-01 --end=YYYY-MM-DD --assert --allow-open-month --json',
    ];

    return [
        'ok' => $status !== 'CRITICAL',
        'status' => $status,
        'generated_at' => date(DATE_ATOM),
        'since_hours' => $sinceHours,
        'probable_cause' => implode(' ', $causes),
        'environment' => $environment,
        'queue' => [
            'file_queue' => $fileQueue,
            'database_jobs' => $dbJobs,
            'dead_letters' => $deadLetters,
        ],
        'worker' => [
            'heartbeat' => $heartbeat,
            'locks' => $locks,
        ],
        'sync' => $sync,
        'pipeline_jobs' => $pipeline,
        'recent_errors' => $errors,
        'recommended_commands' => $includeCommands ? $commands : [],
        'next_steps' => recommendedNextSteps($status, $pending, $heartbeatStatus, $sync),
        'read_only' => true,
    ];
}

/**
 * @return array<string,mixed>
 */
function readFileQueue(string $jobsDir): array
{
    $statuses = ['queued', 'running', 'failed_retryable', 'failed_terminal', 'completed', 'cancelled'];
    $counts = [];
    $recent = [];
    $errors = [];
    $oldestPending = null;
    $byType = [];
    $byQueue = [];

    foreach ($statuses as $status) {
        $files = glob($jobsDir . '/' . $status . '_job_*.json') ?: [];
        $counts[$status] = count($files);
        foreach ($files as $path) {
            $job = readJobFile($path);
            if ($job === null) {
                continue;
            }
            $job['status'] = (string) ($job['status'] ?? $status);
            $job['_file_mtime'] = date(DATE_ATOM, (int) (@filemtime($path) ?: time()));
            $public = publicJobRow($job);
            $recent[] = $public;
            $type = (string) ($public['type'] ?? 'unknown');
            $queue = (string) ($public['queue'] ?? 'default');
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byQueue[$queue] = ($byQueue[$queue] ?? 0) + 1;
            if (in_array($status, ['queued', 'failed_retryable'], true)) {
                $date = (string) ($public['available_at'] ?? $public['created_at'] ?? '');
                if ($date !== '' && ($oldestPending === null || strcmp($date, $oldestPending) < 0)) {
                    $oldestPending = $date;
                }
            }
            if (in_array($status, ['failed_retryable', 'failed_terminal'], true)) {
                $errors[] = $public;
            }
        }
    }

    usort($recent, static fn (array $a, array $b): int =>
        strcmp((string) ($b['updated_at'] ?? $b['_file_mtime'] ?? ''), (string) ($a['updated_at'] ?? $a['_file_mtime'] ?? ''))
    );
    usort($errors, static fn (array $a, array $b): int =>
        strcmp((string) ($b['failed_at'] ?? $b['updated_at'] ?? ''), (string) ($a['failed_at'] ?? $a['updated_at'] ?? ''))
    );

    return [
        'driver' => 'file',
        'jobs_dir' => $jobsDir,
        'exists' => is_dir($jobsDir),
        'counts' => $counts,
        'pending_jobs' => (int) ($counts['queued'] ?? 0) + (int) ($counts['failed_retryable'] ?? 0),
        'running_jobs' => (int) ($counts['running'] ?? 0),
        'oldest_pending_job' => $oldestPending,
        'jobs_by_type' => $byType,
        'jobs_by_queue' => $byQueue,
        'recent_jobs' => array_slice($recent, 0, 20),
        'recent_errors' => array_slice($errors, 0, 20),
    ];
}

function readJobFile(string $path): ?array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

/**
 * @param array<string,mixed> $job
 * @return array<string,mixed>
 */
function publicJobRow(array $job): array
{
    $payload = is_array($job['payload'] ?? null) ? SecretMask::redactArray($job['payload']) : [];
    return [
        'job_id' => (string) ($job['job_id'] ?? $job['job_uuid'] ?? ''),
        'queue' => (string) ($job['queue'] ?? $job['queue_name'] ?? 'default'),
        'type' => (string) ($job['type'] ?? $job['job_type'] ?? ''),
        'domain' => (string) ($payload['domain'] ?? $payload['domain_name'] ?? $job['domain_name'] ?? ''),
        'status' => (string) ($job['status'] ?? ''),
        'attempts' => (int) ($job['attempts'] ?? 0),
        'max_attempts' => (int) ($job['max_attempts'] ?? 0),
        'created_at' => (string) ($job['created_at'] ?? ''),
        'available_at' => (string) ($job['available_at'] ?? ''),
        'started_at' => (string) ($job['started_at'] ?? ''),
        'failed_at' => (string) ($job['failed_at'] ?? ''),
        'updated_at' => (string) ($job['updated_at'] ?? ''),
        'last_error' => SecretMask::sanitizeErrorMessage((string) ($job['last_error'] ?? $job['error_summary'] ?? ''), 300),
        'payload' => $payload,
    ];
}

/**
 * @return array<string,mixed>
 */
function readDatabaseJobs(PDO $pdo, int $sinceHours): array
{
    if (!tableExists($pdo, 'biomie_jobs')) {
        return ['exists' => false, 'counts_by_status' => [], 'recent_jobs' => [], 'recent_errors' => []];
    }
    return [
        'exists' => true,
        'counts_by_status' => fetchAll($pdo, 'SELECT status, COUNT(*) AS total FROM biomie_jobs GROUP BY status ORDER BY status'),
        'recent_jobs' => fetchAll($pdo, 'SELECT id, job_uuid, queue_name, job_type, status, attempts, max_attempts, available_at, started_at, finished_at, failed_at, updated_at FROM biomie_jobs ORDER BY updated_at DESC, id DESC LIMIT 20'),
        'recent_errors' => fetchAll($pdo, 'SELECT id, job_uuid, queue_name, job_type, status, attempts, max_attempts, failed_at, updated_at, error_summary, last_error FROM biomie_jobs WHERE status IN (\'failed_retryable\',\'failed_terminal\') AND updated_at >= DATE_SUB(NOW(), INTERVAL ' . $sinceHours . ' HOUR) ORDER BY updated_at DESC, id DESC LIMIT 20'),
    ];
}

/**
 * @return array<string,mixed>
 */
function readWorkerHeartbeat(PDO $pdo): array
{
    if (!tableExists($pdo, 'biomie_worker_heartbeats')) {
        return ['exists' => false, 'status' => 'unknown', 'latest' => null, 'age_seconds' => null, 'worker_success_count_24h' => 0, 'worker_fail_count_24h' => 0];
    }
    $latest = fetchOne($pdo, 'SELECT id, worker_id, hostname, pid, queues, started_at, last_heartbeat_at, status, processed_jobs, failed_jobs, memory_usage_mb, version, updated_at FROM biomie_worker_heartbeats ORDER BY last_heartbeat_at DESC, id DESC LIMIT 1');
    $last = (string) ($latest['last_heartbeat_at'] ?? '');
    $age = $last !== '' ? max(0, time() - (strtotime($last) ?: time())) : null;
    $status = match (true) {
        $age === null => 'unknown',
        $age <= 120 => 'ok',
        $age <= 600 => 'warning',
        default => 'critical',
    };
    $counts = fetchOne($pdo, 'SELECT SUM(CASE WHEN last_heartbeat_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN processed_jobs ELSE 0 END) AS processed_24h, SUM(CASE WHEN last_heartbeat_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN failed_jobs ELSE 0 END) AS failed_24h FROM biomie_worker_heartbeats');
    return [
        'exists' => true,
        'status' => $status,
        'latest' => $latest,
        'age_seconds' => $age,
        'last_worker_run_at' => $last ?: null,
        'worker_success_count_24h' => (int) ($counts['processed_24h'] ?? 0),
        'worker_fail_count_24h' => (int) ($counts['failed_24h'] ?? 0),
    ];
}

/**
 * @return array<string,mixed>
 */
function readDeadLetters(PDO $pdo, int $sinceHours): array
{
    if (!tableExists($pdo, 'biomie_dead_letter_jobs')) {
        return ['exists' => false, 'counts' => ['total' => 0, 'unresolved' => 0, 'last_window' => 0], 'recent' => []];
    }
    $counts = fetchOne($pdo, 'SELECT COUNT(*) AS total, SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS unresolved, SUM(CASE WHEN failed_at >= DATE_SUB(NOW(), INTERVAL ' . $sinceHours . ' HOUR) THEN 1 ELSE 0 END) AS last_window FROM biomie_dead_letter_jobs');
    return [
        'exists' => true,
        'counts' => [
            'total' => (int) ($counts['total'] ?? 0),
            'unresolved' => (int) ($counts['unresolved'] ?? 0),
            'last_window' => (int) ($counts['last_window'] ?? 0),
        ],
        'recent' => fetchAll($pdo, 'SELECT id, job_id, job_type, queue, attempts, failed_at, last_error_code, last_error_message_sanitized, can_retry, retry_after, resolved_at FROM biomie_dead_letter_jobs ORDER BY failed_at DESC, id DESC LIMIT 20'),
    ];
}

/**
 * @return array<string,mixed>
 */
function readSyncState(PDO $pdo, int $sinceHours): array
{
    if (!tableExists($pdo, 'biomie_sync_runs')) {
        return ['exists' => false, 'overall_status' => 'CRITICAL', 'domains' => [], 'recent_runs' => [], 'recent_errors' => []];
    }
    $canonicalDates = [
        'commercial' => freshestDate(
            lastUpdatedAt($pdo, 'biomie_pedidos_canonicos', ['sincronizado_em', 'updated_at']),
            lastUpdatedAt($pdo, 'biomie_notas_fiscais_canonicas', ['sincronizado_em', 'updated_at'])
        ),
        'finance' => lastUpdatedAt($pdo, 'biomie_titulos_financeiros_canonicos', ['sincronizado_em', 'updated_at']),
    ];
    $domains = [];
    $rows = fetchAll($pdo, <<<'SQL'
        SELECT
            domain_name,
            MAX(CASE WHEN status IN ('completed','completed_with_warnings') THEN finished_at END) AS last_success_at,
            MAX(CASE WHEN status IN ('failed_retryable','failed_terminal','failed') THEN finished_at END) AS last_failure_at,
            SUM(CASE WHEN status IN ('completed','completed_with_warnings') THEN COALESCE(items_processed,0) ELSE 0 END) AS items_processed
        FROM biomie_sync_runs
        WHERE domain_name IN ('commercial','finance')
        GROUP BY domain_name
        ORDER BY domain_name
    SQL);
    $overall = 'OK';
    foreach ($rows as $row) {
        $domain = (string) ($row['domain_name'] ?? '');
        $last = (string) ($row['last_success_at'] ?? '');
        $effectiveLast = freshestDate($last !== '' ? $last : null, $canonicalDates[$domain] ?? null);
        $hours = $effectiveLast !== null ? max(0.0, (time() - (strtotime($effectiveLast) ?: time())) / 3600) : null;
        $thresholdOk = $domain === 'finance' ? 8 : 6;
        $thresholdWarn = $domain === 'finance' ? 48 : 36;
        $status = match (true) {
            $hours === null => 'CRITICAL',
            $hours <= $thresholdOk => 'OK',
            $hours <= $thresholdWarn => 'WARNING',
            default => 'CRITICAL',
        };
        if ($status === 'CRITICAL') {
            $overall = 'CRITICAL';
        } elseif ($status === 'WARNING' && $overall !== 'CRITICAL') {
            $overall = 'WARNING';
        }
        $domains[$domain] = [
            'last_success_at' => $last ?: null,
            'last_failure_at' => ($row['last_failure_at'] ?? null) ?: null,
            'effective_success_at' => $effectiveLast,
            'freshness_source' => $effectiveLast === ($last ?: null) ? 'sync_runs' : 'canonical_fallback',
            'lag_minutes' => $hours === null ? null : (int) round($hours * 60),
            'status' => $status,
            'items_processed' => (int) ($row['items_processed'] ?? 0),
        ];
    }
    foreach (['commercial', 'finance'] as $domain) {
        if (!isset($domains[$domain])) {
            $effectiveLast = $canonicalDates[$domain] ?? null;
            $hours = $effectiveLast !== null ? max(0.0, (time() - (strtotime($effectiveLast) ?: time())) / 3600) : null;
            $thresholdOk = $domain === 'finance' ? 8 : 6;
            $thresholdWarn = $domain === 'finance' ? 48 : 36;
            $status = match (true) {
                $hours === null => 'CRITICAL',
                $hours <= $thresholdOk => 'OK',
                $hours <= $thresholdWarn => 'WARNING',
                default => 'CRITICAL',
            };
            $domains[$domain] = ['last_success_at' => null, 'last_failure_at' => null, 'effective_success_at' => $effectiveLast, 'freshness_source' => 'canonical_fallback', 'lag_minutes' => $hours === null ? null : (int) round($hours * 60), 'status' => $status, 'items_processed' => 0];
            if ($status === 'CRITICAL') {
                $overall = 'CRITICAL';
            } elseif ($status === 'WARNING' && $overall !== 'CRITICAL') {
                $overall = 'WARNING';
            }
        }
    }

    return [
        'exists' => true,
        'overall_status' => $overall,
        'domains' => $domains,
        'recent_runs' => fetchAll($pdo, 'SELECT id, run_id, domain_name, endpoint_group, trigger_source, status, items_processed, items_failed, started_at, finished_at, duration_ms, error_code, error_message_sanitized FROM biomie_sync_runs ORDER BY started_at DESC, id DESC LIMIT 20'),
        'recent_errors' => fetchAll($pdo, 'SELECT id, run_id, domain_name, endpoint_group, status, started_at, finished_at, error_code, error_message_sanitized FROM biomie_sync_runs WHERE status IN (\'failed_retryable\',\'failed_terminal\',\'failed\') AND started_at >= DATE_SUB(NOW(), INTERVAL ' . $sinceHours . ' HOUR) ORDER BY started_at DESC, id DESC LIMIT 20'),
    ];
}

/**
 * @return array<string,mixed>
 */
function readPipelineJobs(PDO $pdo, int $sinceHours): array
{
    if (!tableExists($pdo, 'biomie_pipeline_jobs')) {
        return ['exists' => false, 'recent' => [], 'recent_errors' => []];
    }
    return [
        'exists' => true,
        'recent' => fetchAll($pdo, 'SELECT id, job_name, period_start, period_end, status, rows_processed, started_at, completed_at, duration_ms, created_at FROM biomie_pipeline_jobs ORDER BY COALESCE(completed_at, started_at, created_at) DESC, id DESC LIMIT 20'),
        'recent_errors' => fetchAll($pdo, 'SELECT id, job_name, period_start, period_end, status, error_message, started_at, completed_at, created_at FROM biomie_pipeline_jobs WHERE status NOT IN (\'completed\',\'completed_with_warnings\',\'ok\',\'ready\') AND created_at >= DATE_SUB(NOW(), INTERVAL ' . $sinceHours . ' HOUR) ORDER BY created_at DESC, id DESC LIMIT 20'),
    ];
}

/**
 * @return array<string,mixed>
 */
function readRuntimeLocks(PDO $pdo, string $root): array
{
    $lockNames = [
        'biomie:job_worker:high,default,low',
        'biomie:sync_realtime_pipeline',
        'biomie:materialize_marts',
    ];
    $dbLocks = [];
    foreach ($lockNames as $name) {
        try {
            $stmt = $pdo->prepare('SELECT IS_USED_LOCK(:name) AS owner');
            $stmt->execute(['name' => $name]);
            $dbLocks[$name] = $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) {
            $dbLocks[$name] = 'unknown';
        }
    }
    $fileLocks = [];
    foreach (glob($root . '/storage/locks/*.lock') ?: [] as $path) {
        $fileLocks[] = ['path' => $path, 'mtime' => date(DATE_ATOM, (int) (@filemtime($path) ?: time()))];
    }
    return ['db_locks' => $dbLocks, 'file_locks' => $fileLocks];
}

/**
 * @return array<string,mixed>
 */
function readRuntimeEnvironment(PDO $pdo, Config $config, string $root, string $connection, bool $maskSecrets): array
{
    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    $phpPath = PHP_BINARY;
    $dbTz = fetchOne($pdo, 'SELECT @@time_zone AS time_zone, @@system_time_zone AS system_time_zone');
    $phpIni = php_ini_loaded_file() ?: '';
    return [
        'project_root' => $root,
        'connection' => $connection,
        'app_env' => (string) $config->get('app.env', getenv('APP_ENV') ?: 'producao'),
        'php_binary' => $maskSecrets ? SecretMask::sanitizeErrorMessage($phpPath, 300) : $phpPath,
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'php_ini' => $phpIni,
        'curl_cainfo' => (string) ini_get('curl.cainfo'),
        'openssl_cafile' => (string) ini_get('openssl.cafile'),
        'omie_ca_bundle_env' => $maskSecrets ? SecretMask::sanitizeErrorMessage((string) (getenv('OMIE_CA_BUNDLE') ?: ''), 300) : (string) (getenv('OMIE_CA_BUNDLE') ?: ''),
        'system_ca_candidates' => [
            '/etc/ssl/certs/ca-certificates.crt' => is_readable('/etc/ssl/certs/ca-certificates.crt'),
            '/etc/pki/tls/certs/ca-bundle.crt' => is_readable('/etc/pki/tls/certs/ca-bundle.crt'),
            $root . '/storage/certs/cacert.pem' => is_readable($root . '/storage/certs/cacert.pem'),
        ],
        'php_timezone' => date_default_timezone_get(),
        'db_timezone' => $dbTz,
        'storage_logs_writable' => is_writable($root . '/storage/logs'),
        'storage_cache_writable' => is_writable($root . '/storage/cache'),
        'storage_locks_writable' => is_dir($root . '/storage/locks') ? is_writable($root . '/storage/locks') : is_writable($root . '/storage'),
        'proc_open_available' => function_exists('proc_open') && !in_array('proc_open', $disabled, true),
        'exec_available' => function_exists('exec') && !in_array('exec', $disabled, true),
        'shell_exec_available' => function_exists('shell_exec') && !in_array('shell_exec', $disabled, true),
        'flock_available' => function_exists('flock'),
        'disabled_functions' => array_values($disabled),
        'hostinger_wrappers' => [
            'worker' => is_file($root . '/cron/hostinger/worker.sh'),
            'sync_commercial' => is_file($root . '/cron/hostinger/sync_commercial.sh'),
            'sync_finance' => is_file($root . '/cron/hostinger/sync_finance.sh'),
            'materialize' => is_file($root . '/cron/hostinger/materialize.sh'),
            'geo_rebuild' => is_file($root . '/cron/hostinger/geo_rebuild.sh'),
        ],
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function readRecentErrors(PDO $pdo, array $fileQueue, array $dbJobs, array $sync, int $sinceHours, bool $maskSecrets): array
{
    $errors = [];
    foreach (($fileQueue['recent_errors'] ?? []) as $row) {
        $errors[] = ['source' => 'file_queue'] + $row;
    }
    foreach (($dbJobs['recent_errors'] ?? []) as $row) {
        $row['last_error'] = SecretMask::sanitizeErrorMessage((string) ($row['last_error'] ?? $row['error_summary'] ?? ''), 300);
        $errors[] = ['source' => 'biomie_jobs'] + $row;
    }
    foreach (($sync['recent_errors'] ?? []) as $row) {
        $row['error_message_sanitized'] = SecretMask::sanitizeErrorMessage((string) ($row['error_message_sanitized'] ?? ''), 300);
        $errors[] = ['source' => 'biomie_sync_runs'] + $row;
    }
    if (tableExists($pdo, 'biomie_admin_logs')) {
        $rows = fetchAll($pdo, 'SELECT id, actor_user_id, event_type, route, created_at, details_json FROM biomie_admin_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . $sinceHours . ' HOUR) AND (event_type LIKE \'%failed%\' OR event_type LIKE \'%error%\' OR event_type LIKE \'%cron%\') ORDER BY created_at DESC, id DESC LIMIT 20');
        foreach ($rows as $row) {
            if ($maskSecrets) {
                $row['details_json'] = SecretMask::sanitizeErrorMessage((string) ($row['details_json'] ?? ''), 500);
            }
            $errors[] = ['source' => 'biomie_admin_logs'] + $row;
        }
    }
    return array_slice($errors, 0, 40);
}

/**
 * @return list<string>
 */
function recommendedNextSteps(string $status, int $pending, string $heartbeatStatus, array $sync): array
{
    $steps = [];
    if ($pending > 0) {
        $steps[] = 'Executar worker uma vez: php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55 --stop-when-empty --json.';
    }
    if ($heartbeatStatus === 'critical') {
        $steps[] = 'Confirmar cron do worker no hPanel/Hostinger e validar storage/logs/cron/worker.log.';
    }
    foreach (($sync['domains'] ?? []) as $domain => $row) {
        if (($row['status'] ?? '') === 'CRITICAL') {
            $steps[] = "Executar sync delta controlado do domínio {$domain}.";
        }
    }
    if ($steps === []) {
        $steps[] = 'Manter monitoramento; nenhum redrive automático recomendado.';
    }
    return $steps;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @param list<string> $columns
 */
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

function freshestDate(?string $left, ?string $right): ?string
{
    $leftTs = $left !== null && $left !== '' ? strtotime($left) : false;
    $rightTs = $right !== null && $right !== '' ? strtotime($right) : false;
    if ($leftTs === false) {
        return $rightTs === false ? null : $right;
    }
    if ($rightTs === false) {
        return $left;
    }
    return $rightTs > $leftTs ? $right : $left;
}

/**
 * @return list<array<string,mixed>>
 */
function fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string,mixed>
 */
function fetchOne(PDO $pdo, string $sql, array $params = []): array
{
    $rows = fetchAll($pdo, $sql, $params);
    return $rows[0] ?? [];
}

function printAutomationPretty(array $report): void
{
    echo PHP_EOL;
    echo "========================================================================" . PHP_EOL;
    echo "          BIOMIE AUTOMATION RUNTIME DIAGNOSIS" . PHP_EOL;
    echo "========================================================================" . PHP_EOL;
    echo "Status geral: " . $report['status'] . PHP_EOL;
    echo "Gerado em: " . $report['generated_at'] . PHP_EOL;
    echo "Causa provável: " . $report['probable_cause'] . PHP_EOL;
    echo "------------------------------------------------------------------------" . PHP_EOL;

    $file = $report['queue']['file_queue'] ?? [];
    $counts = $file['counts'] ?? [];
    echo "Fila de arquivos:" . PHP_EOL;
    printf("  Pendentes: %-5d Running: %-5d Failed terminal: %-5d Dead unresolved: %-5d\n",
        (int) ($file['pending_jobs'] ?? 0),
        (int) ($file['running_jobs'] ?? 0),
        (int) ($counts['failed_terminal'] ?? 0),
        (int) ($report['queue']['dead_letters']['counts']['unresolved'] ?? 0)
    );
    echo "  Mais antigo pendente: " . (($file['oldest_pending_job'] ?? null) ?: '-') . PHP_EOL;

    $hb = $report['worker']['heartbeat'] ?? [];
    echo PHP_EOL . "Worker:" . PHP_EOL;
    echo "  Heartbeat: " . ($hb['status'] ?? 'unknown') . " / idade " . (($hb['age_seconds'] ?? null) === null ? '-' : (string) $hb['age_seconds'] . 's') . PHP_EOL;
    echo "  Último run: " . (($hb['last_worker_run_at'] ?? null) ?: '-') . PHP_EOL;
    echo "  Processados 24h: " . (int) ($hb['worker_success_count_24h'] ?? 0) . " | Falhas 24h: " . (int) ($hb['worker_fail_count_24h'] ?? 0) . PHP_EOL;

    echo PHP_EOL . "Sync Omie:" . PHP_EOL;
    foreach (($report['sync']['domains'] ?? []) as $domain => $row) {
        printf("  %-12s %-8s lag=%s min effective=%s source=%s last_success=%s last_failure=%s\n",
            (string) $domain,
            (string) ($row['status'] ?? '-'),
            ($row['lag_minutes'] ?? null) === null ? '-' : (string) $row['lag_minutes'],
            (string) (($row['effective_success_at'] ?? null) ?: '-'),
            (string) (($row['freshness_source'] ?? null) ?: '-'),
            (string) (($row['last_success_at'] ?? null) ?: '-'),
            (string) (($row['last_failure_at'] ?? null) ?: '-')
        );
    }

    echo PHP_EOL . "Erros recentes: " . count($report['recent_errors'] ?? []) . PHP_EOL;
    foreach (array_slice($report['recent_errors'] ?? [], 0, 8) as $err) {
        echo "  - [" . ($err['source'] ?? '-') . "] "
            . (($err['job_type'] ?? $err['type'] ?? $err['domain_name'] ?? $err['event_type'] ?? '-') ?: '-')
            . " | " . (($err['status'] ?? $err['error_code'] ?? '-') ?: '-')
            . " | " . mb_substr((string) ($err['last_error'] ?? $err['error_summary'] ?? $err['error_message_sanitized'] ?? ''), 0, 160)
            . PHP_EOL;
    }

    echo PHP_EOL . "Próximos passos:" . PHP_EOL;
    foreach ($report['next_steps'] ?? [] as $step) {
        echo "  - " . $step . PHP_EOL;
    }
    if (($report['recommended_commands'] ?? []) !== []) {
        echo PHP_EOL . "Comandos recomendados:" . PHP_EOL;
        foreach ($report['recommended_commands'] as $name => $cmd) {
            echo "  {$name}: {$cmd}" . PHP_EOL;
        }
    }
    echo "========================================================================" . PHP_EOL;
}
