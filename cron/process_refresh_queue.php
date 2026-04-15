<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\FinanceAnalyticsService;
use BIomie\FinanceRepository;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;
use BIomie\RefreshQueue;
use BIomie\SalesAnalyticsService;

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);

$repository = null;
$financeRepository = null;
if ($config->hasOmieCredentials()) {
    $bronze = BronzeRepository::boot($config, $logger);
    $client = new OmieClient(
        (string) $config->get('omie.app_key'),
        (string) $config->get('omie.app_secret'),
        (int) $config->get('omie.request_timeout', 20),
        $logger,
        new OmieMethodPolicyRegistry($config),
        $cache,
    );
    $repository = new OmieRepository($client, $config, $bronze, 'refresh-queue');
    $financeRepository = new FinanceRepository($client, $config, $logger, $cache, $bronze, 'refresh-queue');
}

$sales = new SalesAnalyticsService($config, $cache, $logger, $repository, $queue);
$finance = $financeRepository ? new FinanceAnalyticsService($config, $cache, $logger, $financeRepository, $repository, $queue) : null;

$limit = max(1, (int) ($argv[1] ?? 5));
$processed = 0;

// ── Trava de concorrência ────────────────────────────────────────────────────
$lock = null;
$lockConn = Database::isConfigured($config, 'db') ? 'db' : null;
if ($lockConn !== null) {
    $lockPdo = Database::connect($config, $lockConn);
    $lock = CronLock::acquire($lockPdo, 'biomie:refresh_queue', $logger);
    if ($lock === null) exit(0);
}

try {
    while ($processed < $limit && ($job = $queue->claimNext()) !== null) {
        try {
            $service = (string) ($job['service'] ?? '');
            $payload = (array) ($job['payload'] ?? []);
            $start = (string) ($payload['start'] ?? date('Y-m-01'));
            $end = (string) ($payload['end'] ?? date('Y-m-t'));

            if ($service === 'sales') {
                $snapshot = $sales->getSnapshot($start, $end, true);
                $queue->complete($job, ['mode' => $snapshot['mode'] ?? 'live', 'generated_at' => date(DATE_ATOM)]);
                echo sprintf("[%s] sales %s..%s OK\n", date('H:i:s'), $start, $end);
            } elseif ($service === 'finance' && $finance !== null) {
                $snapshot = $finance->getSnapshot($start, $end, true);
                $queue->complete($job, ['mode' => $snapshot['mode'] ?? 'live', 'generated_at' => date(DATE_ATOM)]);
                echo sprintf("[%s] finance %s..%s OK\n", date('H:i:s'), $start, $end);
            } else {
                throw new RuntimeException('Serviço não suportado: ' . $service);
            }
        } catch (Throwable $e) {
            $queue->fail($job, $e->getMessage());
            echo sprintf("[%s] FALHA: %s\n", date('H:i:s'), $e->getMessage());
        }
        $processed++;
    }

    if ($processed === 0) echo sprintf("[%s] Fila vazia\n", date('H:i:s'));
} finally {
    $lock?->release();
}
