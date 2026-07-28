<?php

declare(strict_types=1);

/**
 * Worker da inbox de webhooks Omie.
 *
 * Uso:
 *   php cron/process_webhook_inbox.php
 *   php cron/process_webhook_inbox.php 50
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieRealtimeSyncService;
use BIomie\RefreshQueue;
use BIomie\SyncPipelineRepository;
use BIomie\Support\TenantCliContext;
use BIomie\Telemetry;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan('job.process_webhook_inbox', [
    'domain_name' => 'webhook',
    'source_name' => 'cron',
]);
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "Banco de dados nao configurado.\n");
    exit(2);
}

$pdo = Database::connect($config, $connection);
$tenant = TenantCliContext::boot($pdo, $argv ?? []);
$args = $tenant['args'];
$queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);
$limit = max(1, (int) ($args[1] ?? 20));
$pipeline = new SyncPipelineRepository($pdo, $logger, $tenant['company_id']);
$pipeline->ensureSchema();

$lock = CronLock::acquire(
    $pdo,
    'biomie:webhook_inbox:company:' . $tenant['company_id'],
    $logger
);
if ($lock === null) {
    exit(0);
}

try {
    $service = new OmieRealtimeSyncService($config, $logger, $pipeline, null, null, null, null, $queue);
    $result = $service->processWebhookInbox($limit);

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'processed' => $result['processed'],
        'retryable_failures' => $result['retryable_failures'],
        'terminal_failures' => $result['terminal_failures'],
        'records' => $result['records'],
        'company_id' => $tenant['company_id'],
        'company_slug' => $tenant['slug'],
        'finished_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    $logger->error('Falha no worker de webhook Omie.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $lock?->release();
}
