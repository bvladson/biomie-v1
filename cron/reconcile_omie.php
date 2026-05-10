<?php

declare(strict_types=1);

/**
 * Gera snapshot de conciliacao Omie x BIomie direto pela API.
 *
 * Uso:
 *   php cron/reconcile_omie.php
 *   php cron/reconcile_omie.php 2026-04-01 2026-04-30
 *   php cron/reconcile_omie.php --days 60
 */

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieApiReconciliationService;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');

if (!$config->hasOmieCredentials()) {
    fwrite(STDERR, "Credenciais Omie nao configuradas.\n");
    exit(2);
}

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "Banco de dados nao configurado.\n");
    exit(2);
}

$args = $argv ?? [];
if (($args[1] ?? null) === '--') {
    array_splice($args, 1, 1);
}

if (($args[1] ?? null) === '--days') {
    $days = max(1, (int) ($args[2] ?? 60));
    $start = date('Y-m-d', strtotime("-{$days} days"));
    $end = date('Y-m-d');
} else {
    $start = (string) ($args[1] ?? date('Y-m-01'));
    $end = (string) ($args[2] ?? date('Y-m-t'));
}

$lock = null;

try {
    $pdo = Database::connect($config, $connection);
    $reconnect = static fn () => Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger, $reconnect);
    $bronze = new BronzeRepository($pdo, $logger);
    $bronze->ensureSchema();
    $canonical->ensureFiscalSchema();

    if (!$canonical->isAvailable()) {
        fwrite(STDERR, "Base canonica indisponivel para conciliacao.\n");
        exit(2);
    }

    $lock = CronLock::acquire($pdo, 'biomie:reconcile_omie', $logger);
    if ($lock === null) {
        exit(0);
    }

    $client = new OmieClient(
        (string) $config->get('omie.app_key'),
        (string) $config->get('omie.app_secret'),
        (int) $config->get('omie.request_timeout', 20),
        $logger,
        new OmieMethodPolicyRegistry($config),
        $cache,
    );
    $repository = new OmieRepository($client, $config, $bronze, 'reconcile-omie');
    $service = new OmieApiReconciliationService($repository, $logger);

    $systemRows = $canonical->listFiscalDocumentsByIssueDate($start, $end);
    $report = $service->buildPeriodReport($start, $end, $systemRows);
    $runId = $canonical->saveReconciliationSnapshot($report, null);

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'run_id' => $runId,
        'period' => ['start' => $start, 'end' => $end],
        'source_type' => 'omie_api',
        'notes_omie' => (int) ($report['metrics']['notes_omie'] ?? 0),
        'notes_system' => (int) ($report['metrics']['notes_system'] ?? 0),
        'differences' => (int) ($report['metrics']['differences'] ?? 0),
        'matches' => (int) ($report['metrics']['matches'] ?? 0),
        'finished_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    exit(0);
} catch (Throwable $e) {
    $logger->error('Falha no cron de conciliacao Omie.', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'start' => $start ?? null,
        'end' => $end ?? null,
    ]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $lock?->release();
}
