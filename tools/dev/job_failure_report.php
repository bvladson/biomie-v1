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

$args = parseFailureArgs($argv);
$json = isset($args['json']);
$limit = max(1, min(200, (int) ($args['limit'] ?? 20)));
$domain = strtolower(trim((string) ($args['domain'] ?? '')));
$status = strtolower(trim((string) ($args['status'] ?? 'failed_terminal')));
$jobId = trim((string) ($args['job-id'] ?? ''));
$root = dirname(__DIR__, 2);

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

$report = buildFailureReport($pdo, $root, $domain, $status, $jobId, $limit);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['ok'] ? 0 : 1);
}

printFailurePretty($report);
exit($report['ok'] ? 0 : 1);

/**
 * @return array<string,string|bool>
 */
function parseFailureArgs(array $argv): array
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
function buildFailureReport(PDO $pdo, string $root, string $domain, string $status, string $jobId, int $limit): array
{
    $fileRows = failureRowsFromFiles($root . '/storage/cache/jobs', $domain, $status, $jobId, $limit);
    $dbRows = failureRowsFromDb($pdo, $domain, $status, $jobId, $limit);
    $syncRows = failureRowsFromSyncRuns($pdo, $domain, $status, $jobId, $limit);

    $rows = array_merge($fileRows, $dbRows, $syncRows);
    usort($rows, static fn (array $a, array $b): int =>
        strcmp((string) ($b['failed_at'] ?? $b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['failed_at'] ?? $a['updated_at'] ?? $a['created_at'] ?? ''))
    );
    $rows = array_slice($rows, 0, $limit);

    foreach ($rows as &$row) {
        $row['classification'] = classifyFailure($row);
        $row['recommendation'] = recommendationForFailure($row);
    }
    unset($row);

    return [
        'ok' => true,
        'generated_at' => date(DATE_ATOM),
        'filters' => ['domain' => $domain, 'status' => $status, 'job_id' => $jobId, 'limit' => $limit],
        'counts' => [
            'file_queue' => count($fileRows),
            'database_jobs' => count($dbRows),
            'sync_runs' => count($syncRows),
            'returned' => count($rows),
        ],
        'rows' => $rows,
        'read_only' => true,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function failureRowsFromFiles(string $jobsDir, string $domain, string $status, string $jobId, int $limit): array
{
    $statuses = $status !== '' ? [$status] : ['failed_terminal', 'failed_retryable'];
    $rows = [];
    foreach ($statuses as $st) {
        foreach (glob($jobsDir . '/' . $st . '_job_*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $job = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($job)) {
                continue;
            }
            $payload = is_array($job['payload'] ?? null) ? SecretMask::redactArray($job['payload']) : [];
            $rowDomain = strtolower((string) ($payload['domain'] ?? $payload['domain_name'] ?? $job['domain_name'] ?? ''));
            $rowJobId = (string) ($job['job_id'] ?? $job['job_uuid'] ?? '');
            if ($domain !== '' && $rowDomain !== $domain && !str_contains(strtolower((string) ($job['type'] ?? '')), $domain)) {
                continue;
            }
            if ($jobId !== '' && $rowJobId !== $jobId) {
                continue;
            }
            $rows[] = [
                'source' => 'file_queue',
                'job_id' => $rowJobId,
                'queue' => (string) ($job['queue'] ?? 'default'),
                'domain' => $rowDomain,
                'job_type' => (string) ($job['type'] ?? $job['job_type'] ?? ''),
                'status' => (string) ($job['status'] ?? $st),
                'attempts' => (int) ($job['attempts'] ?? 0),
                'max_attempts' => (int) ($job['max_attempts'] ?? 0),
                'created_at' => (string) ($job['created_at'] ?? ''),
                'started_at' => (string) ($job['started_at'] ?? ''),
                'failed_at' => (string) ($job['failed_at'] ?? ''),
                'updated_at' => (string) ($job['updated_at'] ?? ''),
                'error' => SecretMask::sanitizeErrorMessage((string) ($job['last_error'] ?? $job['error_summary'] ?? ''), 500),
                'payload' => $payload,
            ];
            if (count($rows) >= $limit) {
                return $rows;
            }
        }
    }
    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function failureRowsFromDb(PDO $pdo, string $domain, string $status, string $jobId, int $limit): array
{
    if (!failureTableExists($pdo, 'biomie_jobs')) {
        return [];
    }
    $where = ['status = :status'];
    $params = ['status' => $status !== '' ? $status : 'failed_terminal'];
    if ($jobId !== '') {
        $where[] = 'job_uuid = :job_id';
        $params['job_id'] = $jobId;
    }
    if ($domain !== '') {
        $where[] = '(job_type LIKE :domain_like OR payload_json LIKE :domain_payload)';
        $params['domain_like'] = '%' . $domain . '%';
        $params['domain_payload'] = '%"domain":"' . $domain . '"%';
    }
    $sql = 'SELECT id, job_uuid, queue_name, job_type, payload_json, status, attempts, max_attempts, created_at, available_at, started_at, finished_at, failed_at, updated_at, error_summary, last_error FROM biomie_jobs WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(failed_at, updated_at, created_at) DESC, id DESC LIMIT ' . $limit;
    $rows = failureFetchAll($pdo, $sql, $params);
    return array_map(static function (array $row): array {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        $payload = is_array($payload) ? SecretMask::redactArray($payload) : [];
        return [
            'source' => 'biomie_jobs',
            'job_id' => (string) ($row['job_uuid'] ?? ''),
            'queue' => (string) ($row['queue_name'] ?? ''),
            'domain' => (string) ($payload['domain'] ?? $payload['domain_name'] ?? ''),
            'job_type' => (string) ($row['job_type'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'max_attempts' => (int) ($row['max_attempts'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'failed_at' => (string) ($row['failed_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'error' => SecretMask::sanitizeErrorMessage((string) ($row['last_error'] ?? $row['error_summary'] ?? ''), 500),
            'payload' => $payload,
        ];
    }, $rows);
}

/**
 * @return list<array<string,mixed>>
 */
function failureRowsFromSyncRuns(PDO $pdo, string $domain, string $status, string $jobId, int $limit): array
{
    if (!failureTableExists($pdo, 'biomie_sync_runs')) {
        return [];
    }
    $where = ["status IN ('failed_retryable','failed_terminal','failed')"];
    $params = [];
    if ($domain !== '') {
        $where[] = 'domain_name = :domain';
        $params['domain'] = $domain;
    }
    if ($jobId !== '') {
        $where[] = 'run_id = :job_id';
        $params['job_id'] = $jobId;
    }
    if ($status !== '' && $status !== 'failed_terminal') {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }
    $sql = 'SELECT id, run_id, domain_name, endpoint_group, status, items_processed, items_failed, started_at, finished_at, duration_ms, error_code, error_message_sanitized FROM biomie_sync_runs WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(finished_at, started_at) DESC, id DESC LIMIT ' . $limit;
    $rows = failureFetchAll($pdo, $sql, $params);
    return array_map(static fn (array $row): array => [
        'source' => 'biomie_sync_runs',
        'job_id' => (string) ($row['run_id'] ?? $row['id'] ?? ''),
        'queue' => 'sync',
        'domain' => (string) ($row['domain_name'] ?? ''),
        'job_type' => (string) ($row['endpoint_group'] ?? 'sync_run'),
        'status' => (string) ($row['status'] ?? ''),
        'attempts' => 1,
        'max_attempts' => 1,
        'created_at' => (string) ($row['started_at'] ?? ''),
        'started_at' => (string) ($row['started_at'] ?? ''),
        'failed_at' => (string) ($row['finished_at'] ?? ''),
        'updated_at' => (string) ($row['finished_at'] ?? ''),
        'error' => SecretMask::sanitizeErrorMessage((string) ($row['error_message_sanitized'] ?? $row['error_code'] ?? ''), 500),
        'payload' => ['items_processed' => (int) ($row['items_processed'] ?? 0), 'items_failed' => (int) ($row['items_failed'] ?? 0)],
    ], $rows);
}

/**
 * @param array<string,mixed> $row
 */
function classifyFailure(array $row): string
{
    $error = mb_strtolower((string) ($row['error'] ?? ''));
    if (str_contains($error, 'ssl') || str_contains($error, 'certificate') || str_contains($error, 'issuer certificate')) {
        return 'omie_ssl_connection_error';
    }
    if (str_contains($error, 'timeout')) {
        return 'transient_timeout';
    }
    if (str_contains($error, 'credencial') || str_contains($error, 'app_key') || str_contains($error, 'app_secret')) {
        return 'credential_or_config_error';
    }
    if (str_contains($error, 'schema') || str_contains($error, 'tabela') || str_contains($error, 'column')) {
        return 'schema_or_code_error';
    }
    if (($row['source'] ?? '') === 'biomie_sync_runs' && ($row['domain'] ?? '') === 'commercial') {
        return 'commercial_sync_failure';
    }
    return 'unknown_failure';
}

/**
 * @param array<string,mixed> $row
 */
function recommendationForFailure(array $row): string
{
    return match ((string) ($row['classification'] ?? classifyFailure($row))) {
        'omie_ssl_connection_error' => 'corrigir cadeia CA/SSL ou fallback seguro antes de redrive',
        'transient_timeout' => 'redrive possível após confirmar cron/worker estável',
        'credential_or_config_error' => 'corrigir credencial/configuração antes de reprocessar',
        'schema_or_code_error' => 'corrigir código/schema em fase separada antes de reprocessar',
        'commercial_sync_failure' => 'verificar se houve sync comercial posterior bem-sucedido; se sim, arquivar metadado com reason',
        default => 'investigar antes de redrive/archive',
    };
}

function failureTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return list<array<string,mixed>>
 */
function failureFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function printFailurePretty(array $report): void
{
    echo PHP_EOL;
    echo "========================================================================" . PHP_EOL;
    echo "          BIOMIE JOB FAILURE REPORT" . PHP_EOL;
    echo "========================================================================" . PHP_EOL;
    echo "Gerado em: " . $report['generated_at'] . PHP_EOL;
    echo "Filtros: domain=" . (($report['filters']['domain'] ?? '') ?: 'todos')
        . " status=" . (($report['filters']['status'] ?? '') ?: 'todos')
        . " job-id=" . (($report['filters']['job_id'] ?? '') ?: '-') . PHP_EOL;
    echo "Fontes: file_queue={$report['counts']['file_queue']} biomie_jobs={$report['counts']['database_jobs']} sync_runs={$report['counts']['sync_runs']}" . PHP_EOL;
    echo "------------------------------------------------------------------------" . PHP_EOL;

    if (($report['rows'] ?? []) === []) {
        echo "Nenhuma falha encontrada para o filtro informado." . PHP_EOL;
    }
    foreach ($report['rows'] ?? [] as $row) {
        echo "- {$row['source']} | {$row['job_id']} | {$row['job_type']} | {$row['status']}" . PHP_EOL;
        echo "  queue/domain: {$row['queue']} / " . (($row['domain'] ?? '') ?: '-') . PHP_EOL;
        echo "  attempts: {$row['attempts']}/{$row['max_attempts']} | failed_at: " . (($row['failed_at'] ?? '') ?: '-') . PHP_EOL;
        echo "  erro: " . (($row['error'] ?? '') ?: '-') . PHP_EOL;
        echo "  classificação: {$row['classification']}" . PHP_EOL;
        echo "  recomendação: {$row['recommendation']}" . PHP_EOL;
    }
    echo "========================================================================" . PHP_EOL;
}
