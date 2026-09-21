<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Queue\FileJobQueue;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root = dirname(__DIR__, 2);
$queue = new FileJobQueue($root . '/storage/cache');
$jobs = $queue->listJobs(['queued', 'running', 'failed_retryable', 'failed_terminal'], 500);
$groups = [];
foreach ($jobs as $job) {
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $key = implode('|', [
        (string) ($job['status'] ?? ''), (string) ($job['type'] ?? ''),
        (string) ($payload['company_id'] ?? ''), implode(',', (array) ($payload['domains'] ?? [])),
    ]);
    $groups[$key] ??= ['count' => 0, 'oldest_created_at' => (string) ($job['created_at'] ?? '')];
    $groups[$key]['count']++;
    $groups[$key]['oldest_created_at'] = min($groups[$key]['oldest_created_at'], (string) ($job['created_at'] ?? ''));
}

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$cursors = $pdo->query('SELECT company_id, domain_name, endpoint_group, status, last_run_started_at, last_success_at FROM biomie_sync_cursors ORDER BY company_id,domain_name')->fetchAll(PDO::FETCH_ASSOC);
$runs = $pdo->query("SELECT id,company_id,domain_name,run_type,status,started_at,finished_at,records_failed,items_failed FROM biomie_sync_runs WHERE run_type IN ('delta','reconcile','backfill') ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$inbox = $pdo->query('SELECT status,COUNT(*) AS total,MIN(received_at) AS oldest,MAX(received_at) AS newest FROM biomie_webhook_inbox GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'checked_at' => date(DATE_ATOM),
    'queue' => $queue->summary(),
    'active_groups' => $groups,
    'cursors' => $cursors,
    'recent_sync_runs' => $runs,
    'inbox' => $inbox,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
