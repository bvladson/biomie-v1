<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;
use BIomie\SalesAnalyticsService;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');

$repository = null;
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
    $repository = new OmieRepository($client, $config, $bronze, 'legacy-sync');
}

$service = new SalesAnalyticsService($config, $cache, $logger, $repository);
$start = date('Y-m-01');
$end = date('Y-m-t');
$snapshot = $service->getSnapshot($start, $end, true);

echo sprintf(
    "[%s] sincronizado | modo=%s | receita=%0.2f | pedidos=%d\n",
    date('Y-m-d H:i:s'),
    $snapshot['mode'],
    $snapshot['summary']['revenue'],
    $snapshot['summary']['orders']
);
