<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Queue\DeadLetterRepository;
use BIomie\Queue\FileJobQueue;
use BIomie\Queue\JobRunner;
use BIomie\Queue\WorkerHeartbeatRepository;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$options = parseWorkerOptions($argv);
$queues = array_values(array_filter(array_map('trim', explode(',', (string) ($options['queue'] ?? 'high,default,low')))));
$limit = isset($options['once']) ? 1 : max(1, (int) ($options['limit'] ?? 50));
$sleepSeconds = max(0, (int) ($options['sleep'] ?? 2));
$maxRuntime = max(1, (int) ($options['max-runtime'] ?? 120));
$stopWhenEmpty = isset($options['stop-when-empty']) || isset($options['once']);
$jsonOutput = isset($options['json']);
$memoryLimitMb = parseMemoryLimitMb((string) ($options['memory-limit'] ?? '256M'));
if ($memoryLimitMb > 0) {
    @ini_set('memory_limit', $memoryLimitMb . 'M');
}
set_time_limit($maxRuntime + 30);

$root = dirname(__DIR__);
$config = new Config();
$logger = new Logger($root . '/storage/logs/worker.log');
$cache = new Cache($root . '/storage/cache');
$queue = new FileJobQueue($root . '/storage/cache', $logger);
$runner = new JobRunner($config, $logger, $cache, $root);
$workerId = sprintf('%s:%s:%s', gethostname() ?: 'worker', (string) (getmypid() ?: '0'), substr(hash('sha256', implode(',', $queues)), 0, 8));

$lock = null;
$fileLock = null;
$heartbeat = null;
$deadLetters = null;
$processed = 0;
$failedJobs = 0;
$releasedLocks = 0;
$startedAt = time();
$results = [];

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : (Database::isConfigured($config, 'db') ? 'db' : null);
    if ($connection !== null) {
        $pdo = Database::connect($config, $connection);
        $heartbeat = new WorkerHeartbeatRepository($pdo);
        $heartbeat->start($workerId, $queues, 'phase7');
        $deadLetters = new DeadLetterRepository($pdo);
        $deadLetters->ensureSchema();

        $lock = CronLock::acquire($pdo, 'biomie:job_worker:' . implode(',', $queues), $logger);
        if ($lock === null) {
            $heartbeat->stop($workerId, 0, 0);
            if ($jsonOutput) {
                fwrite(STDOUT, json_encode([
                    'ok' => true,
                    'status' => 'skipped',
                    'reason' => 'worker_lock_held',
                    'message' => 'Outro worker ja esta em execucao para estas filas.',
                    'worker_id' => $workerId,
                    'queues' => $queues,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
            } else {
                fwrite(STDERR, "[SKIP] Worker ja esta em execucao para estas filas.\n");
            }
            exit(0);
        }
    }
} catch (Throwable $exception) {
    $message = SecretMask::sanitizeErrorMessage($exception->getMessage(), 300);
    $logger->warning('Worker usando lock de arquivo porque o lock de banco nao esta disponivel.', [
        'worker_id' => $workerId,
        'message' => $message,
    ]);
}

if ($lock === null) {
    $lockPath = $root . '/storage/cache/worker_' . md5(implode(',', $queues)) . '.lock';
    $fileLock = fopen($lockPath, 'c');
    if ($fileLock === false || !flock($fileLock, LOCK_EX | LOCK_NB)) {
        if (!$jsonOutput) {
            fwrite(STDERR, "[SKIP] Worker ja esta em execucao para estas filas.\n");
        }
        exit(0);
    }
}

try {
    $releasedLocks = $queue->releaseExpiredLocks();
    $lastHeartbeat = time();

    while ($processed < $limit && (time() - $startedAt) < $maxRuntime) {
        if ($memoryLimitMb > 0 && currentMemoryMb() >= max(1, $memoryLimitMb - 16)) {
            $logger->warning('Worker encerrando por limite de memoria.', [
                'worker_id' => $workerId,
                'memory_mb' => currentMemoryMb(),
                'memory_limit_mb' => $memoryLimitMb,
            ]);
            break;
        }

        if (time() - $lastHeartbeat >= 15) {
            $heartbeat?->heartbeat($workerId, $processed, $failedJobs);
            $lastHeartbeat = time();
        }

        $job = $queue->claimNext($queues);
        if ($job === null) {
            if (!$stopWhenEmpty && $sleepSeconds > 0 && (time() - $startedAt + $sleepSeconds) < $maxRuntime) {
                sleep($sleepSeconds);
                continue;
            }
            break;
        }

        $jobStartedAt = microtime(true);
        $logger->info('job.started', [
            'worker_id' => $workerId,
            'job_id' => $job['job_id'] ?? null,
            'queue' => $job['queue'] ?? null,
            'type' => $job['type'] ?? null,
            'attempts' => $job['attempts'] ?? null,
            'max_attempts' => $job['max_attempts'] ?? null,
        ]);

        try {
            $result = $runner->run($job);
            $result['worker_duration_ms'] = max(0, (int) round((microtime(true) - $jobStartedAt) * 1000));
            $queue->complete($job, $result);
            $results[] = [
                'job_id' => $job['job_id'] ?? null,
                'type' => $job['type'] ?? null,
                'status' => 'completed',
                'duration_ms' => $result['worker_duration_ms'],
            ];
        } catch (Throwable $exception) {
            $message = SecretMask::sanitizeErrorMessage($exception->getMessage(), 500);
            $transient = isTransientJobFailure($exception);
            $attempts = (int) ($job['attempts'] ?? 1);
            $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
            $willRetry = $transient && $attempts < $maxAttempts;
            $queue->fail($job, $message, $transient);
            if (!$willRetry) {
                $deadLetters?->recordJobFailure($job, $message, canRetry: $transient);
            }
            $failedJobs++;
            $results[] = [
                'job_id' => $job['job_id'] ?? null,
                'type' => $job['type'] ?? null,
                'status' => $willRetry ? 'failed_retryable' : 'failed_terminal',
                'transient' => $transient,
                'message' => $message,
            ];
        }

        $processed++;
        $heartbeat?->heartbeat($workerId, $processed, $failedJobs);
    }

    $payload = [
        'ok' => true,
        'worker_id' => $workerId,
        'processed' => $processed,
        'failed' => $failedJobs,
        'queues' => $queues,
        'released_expired_locks' => $releasedLocks,
        'summary' => $queue->summary(),
        'results' => $results,
        'finished_at' => date(DATE_ATOM),
    ];

    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
    $heartbeat?->stop($workerId, $processed, $failedJobs);
    $lock?->release();
    if (is_resource($fileLock)) {
        flock($fileLock, LOCK_UN);
        fclose($fileLock);
    }
}

/**
 * @param list<string> $argv
 * @return array<string,string>
 */
function parseWorkerOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        [$key, $value] = array_pad(explode('=', $arg, 2), 2, '1');
        $options[$key] = $value;
    }

    return $options;
}

function isTransientJobFailure(Throwable $exception): bool
{
    $message = mb_strtolower($exception->getMessage());
    if (str_contains($message, 'credencial')
        || str_contains($message, 'app_key')
        || str_contains($message, 'app_secret')
        || str_contains($message, 'schema')
        || str_contains($message, 'tabela')
        || str_contains($message, 'permissao')
        || str_contains($message, 'payload invalido')) {
        return false;
    }

    return true;
}

function parseMemoryLimitMb(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '0') {
        return 0;
    }
    if (preg_match('/^(\d+)\s*G$/i', $value, $m)) {
        return (int) $m[1] * 1024;
    }
    if (preg_match('/^(\d+)\s*M?$/i', $value, $m)) {
        return (int) $m[1];
    }

    return 0;
}

function currentMemoryMb(): float
{
    return round(memory_get_usage(true) / 1048576, 2);
}
