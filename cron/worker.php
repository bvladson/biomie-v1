<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Queue\FileJobQueue;
use BIomie\Queue\JobRunner;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$options = parseWorkerOptions($argv);
$queues = array_values(array_filter(array_map('trim', explode(',', (string) ($options['queue'] ?? 'high,default,low')))));
$limit = max(1, (int) ($options['limit'] ?? 50));
$sleepSeconds = max(0, (int) ($options['sleep'] ?? 0));
$maxRuntime = max(1, (int) ($options['max-runtime'] ?? 120));
set_time_limit($maxRuntime + 30);

$root = dirname(__DIR__);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');
$cache = new Cache($root . '/storage/cache');
$queue = new FileJobQueue($root . '/storage/cache', $logger);
$runner = new JobRunner($config, $logger, $cache, $root);

$lock = null;
$fileLock = null;
try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : (Database::isConfigured($config, 'db') ? 'db' : null);
    if ($connection !== null) {
        $lock = CronLock::acquire(Database::connect($config, $connection), 'biomie:job_worker:' . implode(',', $queues), $logger);
        if ($lock === null) {
            exit(0);
        }
    }
} catch (Throwable $exception) {
    $logger->warning('Worker usando lock de arquivo porque o lock de banco nao esta disponivel.', [
        'message' => $exception->getMessage(),
    ]);
}

if ($lock === null) {
    $lockPath = $root . '/storage/cache/worker_' . md5(implode(',', $queues)) . '.lock';
    $fileLock = fopen($lockPath, 'c');
    if ($fileLock === false || !flock($fileLock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "[SKIP] Worker ja esta em execucao para estas filas.\n");
        exit(0);
    }
}

$processed = 0;
$startedAt = time();
$results = [];

try {
    while ($processed < $limit && (time() - $startedAt) < $maxRuntime) {
        $job = $queue->claimNext($queues);
        if ($job === null) {
            if ($sleepSeconds > 0 && (time() - $startedAt + $sleepSeconds) < $maxRuntime) {
                sleep($sleepSeconds);
                continue;
            }

            break;
        }

        $jobStartedAt = microtime(true);
        $logger->info('job.started', [
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
            $queue->fail($job, $exception->getMessage(), true);
            $results[] = [
                'job_id' => $job['job_id'] ?? null,
                'type' => $job['type'] ?? null,
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }

        $processed++;
    }

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'processed' => $processed,
        'queues' => $queues,
        'summary' => $queue->summary(),
        'results' => $results,
        'finished_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
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
