<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRepository;
use BIomie\SalesAnalyticsService;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$tenant = TenantCliContext::boot($pdo, $argv ?? []);

$lock = CronLock::acquire(
    $pdo,
    'biomie:legacy_sync:company:' . $tenant['company_id'],
    $logger
);
if ($lock === null) {
    exit(0);
}

try {
    $client = (new OmieClientFactory($pdo, $logger, $config, $cache))
        ->forCompany($tenant['company_id']);
    $repository = new OmieRepository(
        $client,
        $config,
        new BronzeRepository($pdo, $logger, $tenant['company_id']),
        'legacy-sync'
    );
    $service = new SalesAnalyticsService($config, $cache, $logger, $repository);
    $start = date('Y-m-01');
    $end = date('Y-m-t');
    $snapshot = $service->getSnapshot($start, $end, true);

    echo sprintf(
        "[%s] company=%s(%d) sincronizado | modo=%s | receita=%0.2f | pedidos=%d\n",
        date('Y-m-d H:i:s'),
        $tenant['slug'],
        $tenant['company_id'],
        $snapshot['mode'],
        $snapshot['summary']['revenue'],
        $snapshot['summary']['orders']
    );
} finally {
    $lock->release();
}
