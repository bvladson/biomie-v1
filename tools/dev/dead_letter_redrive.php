<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Queue\FileJobQueue;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$args = parseArgs($argv);
$json = isset($args['json']);
$apply = isset($args['apply']);
$mode = isset($args['redrive']) ? 'redrive' : (isset($args['archive']) ? 'archive' : 'dry-run');
$reason = trim((string) ($args['reason'] ?? ''));
$jobIdFilter = trim((string) ($args['job-id'] ?? ''));
$allArchiveable = isset($args['all-archiveable']);
$root = dirname(__DIR__, 2);

if ($reason === '') {
    fail('missing_reason', 'Informe --reason para qualquer dry-run/apply de DLQ.', $json);
}
if ($mode === 'dry-run') {
    fail('missing_mode', 'Informe --archive ou --redrive. O modo continua dry-run sem --apply.', $json);
}

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

$rows = loadUnresolved($pdo);
$plan = [];
foreach ($rows as $row) {
    $classification = classifyForAction($pdo, $row);
    $jobId = (string) ($row['job_id'] ?? '');
    if ($jobIdFilter !== '' && $jobId !== $jobIdFilter) {
        continue;
    }
    if ($allArchiveable && ($classification['decision'] ?? '') !== 'archive_with_reason') {
        continue;
    }

    $plan[] = [
        'job_id' => $jobId,
        'job_type' => (string) ($row['job_type'] ?? ''),
        'queue' => (string) ($row['queue'] ?? 'default'),
        'failed_at' => (string) ($row['failed_at'] ?? ''),
        'payload_safe' => decodePayload((string) ($row['payload_sanitized'] ?? '')),
        'classification' => $classification['classification'],
        'decision' => $classification['decision'],
        'allowed' => isActionAllowed($mode, $classification),
        'reason' => $reason,
    ];
}

if ($plan === []) {
    fail('empty_plan', 'Nenhuma dead letter elegivel para o filtro informado.', $json);
}

$blocked = array_values(array_filter($plan, static fn (array $row): bool => empty($row['allowed'])));
if ($blocked !== []) {
    output([
        'ok' => false,
        'mode' => $apply ? 'apply' : 'dry-run',
        'action' => $mode,
        'reason' => $reason,
        'blocked' => $blocked,
        'updates_executed' => 0,
        'jobs_requeued' => 0,
    ], $json);
    exit(2);
}

$updates = 0;
$requeued = 0;
$logRows = [];

if ($apply) {
    if ($mode === 'archive') {
        $stmt = $pdo->prepare(
            'UPDATE biomie_dead_letter_jobs
             SET resolved_at = NOW(),
                 resolved_by = :resolved_by,
                 resolution_notes = :notes,
                 updated_at = NOW()
             WHERE job_id = :job_id AND resolved_at IS NULL'
        );
        foreach ($plan as $row) {
            $stmt->execute([
                'job_id' => $row['job_id'],
                'resolved_by' => 'tools/dev/dead_letter_redrive.php',
                'notes' => $reason . ' | classification=' . $row['classification'],
            ]);
            $updates += $stmt->rowCount();
            $logRows[] = $row + ['applied' => true];
        }
    } elseif ($mode === 'redrive') {
        $queue = new FileJobQueue($root . '/storage/cache');
        foreach ($plan as $row) {
            $queued = $queue->enqueue(
                (string) ($row['queue'] ?? 'default'),
                (string) ($row['job_type'] ?? ''),
                is_array($row['payload_safe'] ?? null) ? $row['payload_safe'] : [],
                ['created_by' => 'tools/dev/dead_letter_redrive.php']
            );
            $requeued += !empty($queued['queued']) ? 1 : 0;
            $logRows[] = $row + ['applied' => true, 'queue_result' => $queued];
        }
    }
} else {
    foreach ($plan as $row) {
        $logRows[] = $row + ['applied' => false];
    }
}

$logPath = writeAuditLog($root, [
    'ok' => true,
    'action' => $mode,
    'mode' => $apply ? 'apply' : 'dry-run',
    'reason' => $reason,
    'generated_at' => date(DATE_ATOM),
    'connection' => $connection,
    'rows' => $logRows,
    'updates_executed' => $updates,
    'jobs_requeued' => $requeued,
]);

output([
    'ok' => true,
    'action' => $mode,
    'mode' => $apply ? 'apply' : 'dry-run',
    'reason' => $reason,
    'planned_rows' => count($plan),
    'updates_executed' => $updates,
    'jobs_requeued' => $requeued,
    'log' => $logPath,
    'rows' => $logRows,
], $json);

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
 * @return list<array<string,mixed>>
 */
function loadUnresolved(PDO $pdo): array
{
    if (!tableExists($pdo, 'biomie_dead_letter_jobs')) {
        return [];
    }
    $stmt = $pdo->query('SELECT * FROM biomie_dead_letter_jobs WHERE resolved_at IS NULL ORDER BY failed_at DESC, id DESC');
    return $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * @param array<string,mixed> $row
 * @return array<string,string>
 */
function classifyForAction(PDO $pdo, array $row): array
{
    $payload = decodePayload((string) ($row['payload_sanitized'] ?? ''));
    $type = (string) ($row['job_type'] ?? '');
    $message = (string) ($row['last_error_message_sanitized'] ?? '');
    $failedAt = (string) ($row['failed_at'] ?? '');

    if ($type === 'commercial.materialize' && str_contains($message, 'getLastStage')) {
        $laterGold = countSuccessfulRunsAfter($pdo, $failedAt, 'materialize', 'gold');
        return [
            'classification' => $laterGold > 0 ? 'legacy_code_bug_superseded' : 'legacy_code_bug_resolved_needs_validation',
            'decision' => $laterGold > 0 ? 'archive_with_reason' : 'dry_run_redrive_after_validation',
        ];
    }

    if ($type === 'omie.delta.sync' && str_contains($message, 'Sync delta Omie falhou')) {
        $domains = array_values(array_filter(array_map('strval', (array) ($payload['domains'] ?? []))));
        $allRecovered = $domains !== [];
        foreach ($domains as $domain) {
            if (countSuccessfulRunsAfter($pdo, $failedAt, 'delta', $domain) <= 0) {
                $allRecovered = false;
            }
        }
        return [
            'classification' => $allRecovered ? 'legacy_delta_superseded_by_later_success' : 'delta_failure_needs_investigation',
            'decision' => $allRecovered ? 'archive_with_reason' : 'dry_run_redrive_after_validation',
        ];
    }

    return ['classification' => 'unknown', 'decision' => 'investigate_before_action'];
}

/**
 * @param array<string,string> $classification
 */
function isActionAllowed(string $mode, array $classification): bool
{
    $decision = (string) ($classification['decision'] ?? '');
    if ($mode === 'archive') {
        return $decision === 'archive_with_reason';
    }
    if ($mode === 'redrive') {
        return $decision === 'dry_run_redrive_after_validation';
    }
    return false;
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
    return is_array($decoded) ? SecretMask::redactArray($decoded) : [];
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @param array<string,mixed> $payload
 */
function writeAuditLog(string $root, array $payload): string
{
    $dir = rtrim($root, '/\\') . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $name = sprintf('dead_letter_%s_%s.json', (string) ($payload['action'] ?? 'action'), date('Ymd_His'));
    $path = $dir . '/' . $name;
    @file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    );
    return $path;
}

/**
 * @param array<string,mixed> $payload
 */
function output(array $payload, bool $json): void
{
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo strtoupper((string) ($payload['action'] ?? 'dlq')) . ' ' . strtoupper((string) ($payload['mode'] ?? 'dry-run')) . "\n";
    echo '  ok               : ' . (!empty($payload['ok']) ? 'true' : 'false') . "\n";
    echo '  planned_rows     : ' . (int) ($payload['planned_rows'] ?? 0) . "\n";
    echo '  updates_executed : ' . (int) ($payload['updates_executed'] ?? 0) . "\n";
    echo '  jobs_requeued    : ' . (int) ($payload['jobs_requeued'] ?? 0) . "\n";
    if (isset($payload['log'])) {
        echo '  log              : ' . $payload['log'] . "\n";
    }
    if (!empty($payload['blocked'])) {
        echo "  blocked          : " . count((array) $payload['blocked']) . "\n";
    }
}

function fail(string $code, string $message, bool $json): never
{
    output([
        'ok' => false,
        'error' => $code,
        'message' => $message,
        'updates_executed' => 0,
        'jobs_requeued' => 0,
    ], $json);
    exit(2);
}
