<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Queue\DeadLetterRepository;
use BIomie\Queue\FileJobQueue;
use BIomie\Queue\WorkerHeartbeatRepository;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$args = parseArgs($argv);
$json = isset($args['json']);
$root = dirname(__DIR__, 2);
$logger = new Logger($root . '/storage/logs/worker.log');
$queue = new FileJobQueue($root . '/storage/cache', $logger);
$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = null;
$databaseWarning = null;
if (Database::isConfigured($config, $connection)) {
    try {
        $pdo = Database::connect($config, $connection);
    } catch (Throwable $exception) {
        $databaseWarning = [
            'ok' => false,
            'error' => 'database_connection_failed',
            'message' => 'Fila de arquivos legivel, mas banco indisponivel para dead-letter/heartbeat.',
            'detail' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 220),
            'recommended_action' => 'Verifique driver PDO/MySQL, metodo de autenticacao do usuario e credenciais do ambiente.',
        ];
    }
}

$action = 'summary';
$result = [
    'ok' => true,
    'queue' => $queue->summary(),
    'jobs' => [],
    'dead_letter' => null,
    'heartbeat' => null,
    'database' => $databaseWarning,
];

if (isset($args['retry'])) {
    $action = 'retry';
    $retried = $queue->retry((string) $args['retry']);
    if ($pdo !== null && $retried !== null) {
        (new DeadLetterRepository($pdo))->markResolved((string) ($retried['job_id'] ?? $args['retry']), 'tools/dev/worker_queue_debug.php', 'reenfileirado manualmente');
    }
    $result['retried'] = $retried;
} elseif (isset($args['retry-all'])) {
    $action = 'retry-all';
    $retried = [];
    foreach ($queue->listJobs(['failed_terminal'], 200) as $job) {
        $row = $queue->retry((string) ($job['job_id'] ?? ''));
        if ($row !== null) {
            $retried[] = $row['job_id'] ?? null;
        }
    }
    $result['retried'] = $retried;
} elseif (isset($args['pending'])) {
    $action = 'pending';
    $result['jobs'] = $queue->listJobs(['queued', 'failed_retryable'], 100);
} elseif (isset($args['running'])) {
    $action = 'running';
    $result['jobs'] = $queue->listJobs(['running'], 100);
} elseif (isset($args['failed']) || isset($args['dead'])) {
    $action = 'failed';
    $result['jobs'] = $queue->listJobs(['failed_retryable', 'failed_terminal'], 100);
}

if ($pdo !== null) {
    $dead = new DeadLetterRepository($pdo);
    $result['dead_letter'] = [
        'counts' => $dead->counts(),
        'unresolved' => $dead->listUnresolved(50),
    ];
    $result['heartbeat'] = (new WorkerHeartbeatRepository($pdo))->summary();
}

$result['action'] = $action;
output($result, $json);

/** @return array<string,string|bool> */
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

function output(array $payload, bool $json): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
