<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

use BIomie\Auth;
use BIomie\BronzeRepository;
use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;
use BIomie\RefreshQueue;
use BIomie\SalesAnalyticsService;
use BIomie\Telemetry;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan(null, [
    'domain_name' => 'http',
    'source_name' => 'api',
    'script' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'bootstrap.php')),
]);

Auth::boot($config, $logger);
Auth::requireLogin(true);
if (
    session_status() === PHP_SESSION_ACTIVE
    && in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)
) {
    session_write_close();
}

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
    $repository = new OmieRepository($client, $config, $bronze, 'sales-api');
}

$queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);
$analytics = new SalesAnalyticsService($config, $cache, $logger, $repository, $queue);
