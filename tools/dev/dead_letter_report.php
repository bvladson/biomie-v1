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
$limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
$root = dirname(__DIR__, 2);

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

$report = buildReport($pdo, $root, $connection, $limit);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['ok'] ? 0 : 1);
}
printPretty($report);
exit($report['ok'] ? 0 : 1);

/**
 * @return array<string,mixed>
 */
function buildReport(PDO $pdo, string $root, string $connection, int $limit): array
{
    $jobsDir = rtrim($root, '/\\') . '/storage/cache/jobs';
    $fileQueue = summarizeFileQueue($jobsDir);
    $dbJobs = summarizeDbJobs($pdo);
    $deadLetters = loadDeadLetters($pdo, $limit);
    $heartbeat = loadHeartbeat($pdo);
    $recentRuns = loadRecentSyncRuns($pdo);

    $classified = [];
    foreach ($deadLetters['unresolved'] as $row) {
        $classified[] = classifyDeadLetter($pdo, $row);
    }

    $cron = [
        'expected_worker_command' => 'php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=240 --stop-when-empty --json',
        'expected_worker_interval' => '*/5 * * * *',
        'repo_script' => is_file($root . '/cron/hostinger/worker.sh') ? 'cron/hostinger/worker.sh' : null,
        'actual_hostinger_cron_verified' => false,
        'actual_hostinger_cron_note' => 'Nao verificavel pelo diagnostico local; usar painel/SSH Hostinger para confirmar entrada real.',
    ];

    $unclassified = array_values(array_filter(
        $classified,
        static fn (array $row): bool => ($row['decision'] ?? '') === 'investigate_before_action'
    ));

    return [
        'ok' => count($unclassified) === 0,
        'generated_at' => date(DATE_ATOM),
        'environment' => [
            'root' => $root,
            'connection' => $connection,
            'php_sapi' => PHP_SAPI,
        ],
        'queue_sources' => [
            'file_queue' => $jobsDir,
            'database_queue_table' => $dbJobs['exists'] ? 'biomie_jobs' : null,
            'dead_letter_table' => $deadLetters['exists'] ? 'biomie_dead_letter_jobs' : null,
            'heartbeat_table' => $heartbeat['exists'] ? 'biomie_worker_heartbeats' : null,
        ],
        'file_queue' => $fileQueue,
        'database_jobs' => $dbJobs,
        'dead_letter' => [
            'counts' => $deadLetters['counts'],
            'unresolved_classified' => $classified,
        ],
        'worker_heartbeat' => $heartbeat,
        'cron' => $cron,
        'recent_sync_runs' => $recentRuns,
        'summary' => [
            'pending_jobs' => (int) ($fileQueue['counts']['queued'] ?? 0) + (int) ($fileQueue['counts']['failed_retryable'] ?? 0),
            'running_jobs' => (int) ($fileQueue['counts']['running'] ?? 0),
            'processed_file_jobs' => (int) ($fileQueue['counts']['completed'] ?? 0),
            'dead_letter_unresolved' => (int) ($deadLetters['counts']['unresolved'] ?? 0),
            'dead_letter_retryable' => (int) ($deadLetters['counts']['retryable'] ?? 0),
            'unclassified_dead_letters' => count($unclassified),
            'worker_heartbeat_status' => $heartbeat['status'] ?? 'unknown',
            'worker_heartbeat_classification' => $heartbeat['classification'] ?? 'unknown',
        ],
    ];
}

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
function summarizeFileQueue(string $jobsDir): array
{
    $statuses = ['queued', 'running', 'completed', 'failed_retryable', 'failed_terminal', 'cancelled'];
    $counts = [];
    $oldest = null;
    foreach ($statuses as $status) {
        $files = glob($jobsDir . '/' . $status . '_job_*.json') ?: [];
        $counts[$status] = count($files);
        foreach ($files as $path) {
            $raw = @file_get_contents($path);
            $job = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($job)) {
                continue;
            }
            $date = (string) ($job['available_at'] ?? $job['created_at'] ?? '');
            if ($date !== '' && ($oldest === null || strcmp($date, $oldest) < 0)) {
                $oldest = $date;
            }
        }
    }

    return [
        'driver' => 'file',
        'jobs_dir' => $jobsDir,
        'exists' => is_dir($jobsDir),
        'counts' => $counts,
        'oldest_queued_at' => $oldest,
    ];
}

/**
 * @return array<string,mixed>
 */
function summarizeDbJobs(PDO $pdo): array
{
    if (!tableExists($pdo, 'biomie_jobs')) {
        return ['exists' => false, 'counts_by_status' => [], 'columns' => []];
    }

    return [
        'exists' => true,
        'columns' => columns($pdo, 'biomie_jobs'),
        'counts_by_status' => fetchAll($pdo, 'SELECT status, COUNT(*) AS total FROM biomie_jobs GROUP BY status ORDER BY status'),
        'recent' => fetchAll($pdo, 'SELECT id, job_uuid, queue_name, job_type, status, attempts, max_attempts, available_at, started_at, finished_at, failed_at, updated_at FROM biomie_jobs ORDER BY updated_at DESC, id DESC LIMIT 20'),
    ];
}

/**
 * @return array<string,mixed>
 */
function loadDeadLetters(PDO $pdo, int $limit): array
{
    if (!tableExists($pdo, 'biomie_dead_letter_jobs')) {
        return [
            'exists' => false,
            'columns' => [],
            'counts' => ['total' => 0, 'unresolved' => 0, 'retryable' => 0, 'last_24h' => 0],
            'unresolved' => [],
        ];
    }

    $counts = fetchOne($pdo, <<<'SQL'
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS unresolved,
            SUM(CASE WHEN resolved_at IS NULL AND can_retry = 1 THEN 1 ELSE 0 END) AS retryable,
            SUM(CASE WHEN resolved_at IS NULL AND failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS last_24h
        FROM biomie_dead_letter_jobs
    SQL);

    $stmt = $pdo->query(sprintf(
        'SELECT * FROM biomie_dead_letter_jobs WHERE resolved_at IS NULL ORDER BY failed_at DESC, id DESC LIMIT %d',
        max(1, min(200, $limit))
    ));

    return [
        'exists' => true,
        'columns' => columns($pdo, 'biomie_dead_letter_jobs'),
        'counts' => [
            'total' => (int) ($counts['total'] ?? 0),
            'unresolved' => (int) ($counts['unresolved'] ?? 0),
            'retryable' => (int) ($counts['retryable'] ?? 0),
            'last_24h' => (int) ($counts['last_24h'] ?? 0),
        ],
        'unresolved' => $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [],
    ];
}

/**
 * @return array<string,mixed>
 */
function loadHeartbeat(PDO $pdo): array
{
    if (!tableExists($pdo, 'biomie_worker_heartbeats')) {
        return [
            'exists' => false,
            'status' => 'unknown',
            'classification' => 'missing_heartbeat_table',
            'latest' => null,
            'age_seconds' => null,
        ];
    }

    $latest = fetchOne($pdo, 'SELECT * FROM biomie_worker_heartbeats ORDER BY last_heartbeat_at DESC, id DESC LIMIT 1');
    if ($latest === []) {
        return [
            'exists' => true,
            'status' => 'unknown',
            'classification' => 'never_seen',
            'latest' => null,
            'age_seconds' => null,
        ];
    }

    $last = (string) ($latest['last_heartbeat_at'] ?? '');
    $age = $last !== '' ? max(0, time() - (strtotime($last) ?: time())) : null;
    $status = match (true) {
        $age === null => 'unknown',
        $age <= 120 => 'ok',
        $age <= 600 => 'warning',
        default => 'critical',
    };

    $rowStatus = (string) ($latest['status'] ?? '');
    $classification = match (true) {
        $status === 'ok' => 'recent_heartbeat',
        $rowStatus === 'stopped' && $age !== null && $age > 600 => 'cron_stale_after_clean_stop',
        $rowStatus === 'running' && $age !== null && $age > 600 => 'stale_running_heartbeat',
        default => 'heartbeat_stale',
    };

    return [
        'exists' => true,
        'status' => $status,
        'classification' => $classification,
        'latest' => sanitizeRow($latest),
        'age_seconds' => $age,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function loadRecentSyncRuns(PDO $pdo): array
{
    if (!tableExists($pdo, 'biomie_sync_runs')) {
        return [];
    }

    return fetchAll($pdo, <<<'SQL'
        SELECT id, run_type, domain_name, status, items_processed, items_failed, error_summary, started_at, finished_at
        FROM biomie_sync_runs
        ORDER BY started_at DESC, id DESC
        LIMIT 30
    SQL);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function classifyDeadLetter(PDO $pdo, array $row): array
{
    $payload = decodePayload((string) ($row['payload_sanitized'] ?? ''));
    $type = (string) ($row['job_type'] ?? '');
    $message = (string) ($row['last_error_message_sanitized'] ?? '');
    $failedAt = (string) ($row['failed_at'] ?? '');

    $classification = 'unknown';
    $rootCause = 'Nao classificado.';
    $decision = 'investigate_before_action';
    $recommendedAction = 'Investigar antes de redrive/archive.';
    $reprocessable = (bool) ((int) ($row['can_retry'] ?? 0) === 1);
    $dependsOnOmie = false;
    $dependsOnDbSchema = false;
    $dependsOnCredentials = false;
    $legacyIrrecoverable = false;
    $evidence = [];

    if ($type === 'commercial.materialize' && str_contains($message, 'getLastStage')) {
        $laterGold = countSuccessfulRunsAfter($pdo, $failedAt, 'materialize', 'gold');
        $classification = $laterGold > 0 ? 'legacy_code_bug_superseded' : 'legacy_code_bug_resolved_needs_validation';
        $rootCause = 'Erro legado: GoldMartBuilder ainda nao expunha getLastStage() para o handler de erro.';
        $dependsOnDbSchema = true;
        $legacyIrrecoverable = $laterGold > 0;
        $decision = $laterGold > 0 ? 'archive_with_reason' : 'dry_run_redrive_after_validation';
        $recommendedAction = $laterGold > 0
            ? 'Arquivar metadado da DLQ como superseded por materializacoes gold posteriores bem-sucedidas.'
            : 'Validar materializacao atual antes de redrive.';
        $evidence[] = 'GoldMartBuilder atual possui getLastStage() e demais accessors.';
        $evidence[] = 'Materializacoes gold bem-sucedidas apos falha: ' . $laterGold;
    } elseif ($type === 'omie.delta.sync' && str_contains($message, 'Sync delta Omie falhou')) {
        $domains = array_values(array_filter(array_map('strval', (array) ($payload['domains'] ?? []))));
        $successByDomain = [];
        foreach ($domains as $domain) {
            $successByDomain[$domain] = countSuccessfulRunsAfter($pdo, $failedAt, 'delta', $domain);
        }

        $allRecovered = $domains !== [] && count(array_filter($successByDomain, static fn (int $count): bool => $count > 0)) === count($domains);
        $classification = $allRecovered ? 'legacy_delta_superseded_by_later_success' : 'delta_failure_needs_investigation';
        $rootCause = $allRecovered
            ? 'Falha delta antiga; dominios do payload tiveram sync delta bem-sucedido posteriormente.'
            : 'Falha delta antiga sem evidencia completa de recuperacao posterior para todos os dominios.';
        $dependsOnOmie = true;
        $legacyIrrecoverable = $allRecovered;
        $decision = $allRecovered ? 'archive_with_reason' : 'dry_run_redrive_after_validation';
        $recommendedAction = $allRecovered
            ? 'Arquivar metadado da DLQ como superseded por syncs posteriores, sem redrive.'
            : 'Investigar log/run_id e credenciais Omie antes de redrive.';
        $evidence[] = 'Dominios do payload: ' . implode(',', $domains);
        $evidence[] = 'Syncs delta posteriores por dominio: ' . json_encode($successByDomain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'job_id' => (string) ($row['job_id'] ?? ''),
        'job_type' => $type,
        'queue' => (string) ($row['queue'] ?? ''),
        'attempts' => (int) ($row['attempts'] ?? 0),
        'failed_at' => $failedAt,
        'last_error_code' => (string) ($row['last_error_code'] ?? ''),
        'last_error_message_sanitized' => SecretMask::sanitizeErrorMessage($message, 300),
        'payload_safe' => sanitizeRow($payload),
        'classification' => $classification,
        'root_cause' => $rootCause,
        'is_reprocessable' => $reprocessable,
        'depends_on_omie' => $dependsOnOmie,
        'depends_on_db_schema' => $dependsOnDbSchema,
        'depends_on_credentials_or_config' => $dependsOnCredentials,
        'legacy_irrecoverable_or_superseded' => $legacyIrrecoverable,
        'decision' => $decision,
        'recommended_action' => $recommendedAction,
        'evidence' => $evidence,
    ];
}

function countSuccessfulRunsAfter(PDO $pdo, string $failedAt, string $runType, string $domain): int
{
    if (!tableExists($pdo, 'biomie_sync_runs') || $failedAt === '') {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM biomie_sync_runs
         WHERE started_at > :failed_at
           AND run_type = :run_type
           AND domain_name = :domain
           AND status = :status'
    );
    $stmt->execute([
        'failed_at' => $failedAt,
        'run_type' => $runType,
        'domain' => $domain,
        'status' => 'completed',
    ]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array<string,mixed>
 */
function decodePayload(string $json): array
{
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function sanitizeRow(array $row): array
{
    return SecretMask::redactArray($row);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return list<array<string,mixed>>
 */
function columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute(['table' => $table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string,mixed>
 */
function fetchOne(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return is_array($row) ? $row : [];
}

/**
 * @return list<array<string,mixed>>
 */
function fetchAll(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    return $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * @param array<string,mixed> $report
 */
function printPretty(array $report): void
{
    echo "Queue/DLQ report\n";
    echo "  generated_at      : " . $report['generated_at'] . "\n";
    echo "  connection        : " . $report['environment']['connection'] . "\n";
    echo "  file_queue        : " . $report['queue_sources']['file_queue'] . "\n";
    echo "  pending_jobs      : " . $report['summary']['pending_jobs'] . "\n";
    echo "  running_jobs      : " . $report['summary']['running_jobs'] . "\n";
    echo "  dead_unresolved   : " . $report['summary']['dead_letter_unresolved'] . "\n";
    echo "  heartbeat         : " . $report['summary']['worker_heartbeat_status'] . " / " . $report['summary']['worker_heartbeat_classification'] . "\n\n";

    foreach ($report['dead_letter']['unresolved_classified'] as $row) {
        echo "#{$row['id']} {$row['job_type']} {$row['job_id']}\n";
        echo "  failed_at         : {$row['failed_at']}\n";
        echo "  attempts          : {$row['attempts']}\n";
        echo "  classification    : {$row['classification']}\n";
        echo "  decision          : {$row['decision']}\n";
        echo "  root_cause        : {$row['root_cause']}\n";
        echo "  recommendation    : {$row['recommended_action']}\n";
        foreach ((array) ($row['evidence'] ?? []) as $evidence) {
            echo "  evidence          : {$evidence}\n";
        }
        echo "\n";
    }

    echo "Cron\n";
    echo "  expected          : " . $report['cron']['expected_worker_interval'] . ' ' . $report['cron']['expected_worker_command'] . "\n";
    echo "  verified_locally  : " . ($report['cron']['actual_hostinger_cron_verified'] ? 'yes' : 'no') . "\n";
    echo "  note              : " . $report['cron']['actual_hostinger_cron_note'] . "\n";
}
