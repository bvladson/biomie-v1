<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

use BIomie\Auth;
use BIomie\BronzeRepository;
use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\FinanceAnalyticsService;
use BIomie\FinanceRepository;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;
use BIomie\RefreshQueue;
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
    'script' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'bootstrap_finance.php')),
]);

Auth::boot($config, $logger);
$financeBootstrapCapabilities = isset($financeBootstrapCapabilities) && is_array($financeBootstrapCapabilities)
    ? array_values(array_filter($financeBootstrapCapabilities, 'is_string'))
    : ['finance.view'];

if (count($financeBootstrapCapabilities) === 1) {
    Auth::requireCapability($financeBootstrapCapabilities[0], true);
} else {
    Auth::requireAnyCapability($financeBootstrapCapabilities, true);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$finance = null;
$salesRepository = null;

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

    $salesRepository = new OmieRepository($client, $config, $bronze, 'finance-api');
    $financeRepository = new FinanceRepository($client, $config, $logger, $cache, $bronze, 'finance-api');
    $queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);
    $finance = new FinanceAnalyticsService($config, $cache, $logger, $financeRepository, $salesRepository, $queue);

    try {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (Database::isConfigured($config, $connection)) {
            $pdo = Database::connect($config, $connection);
            $reconnect = static fn () => Database::connect($config, $connection);
            $canonical = new CanonicalRepository($pdo, $logger, $reconnect);
            if ($canonical->isAvailable()) {
                $extraMap = [];
                foreach ($canonical->getVendorMap() as $codigo => $row) {
                    $name = trim((string) ($row['nome'] ?? ''));
                    if ($name !== '') {
                        $extraMap[(string) $codigo] = $name;
                    }
                }
                if ($extraMap !== []) {
                    $finance->withExtraVendorMap($extraMap);
                }
            }
        }
    } catch (\Throwable $e) {
        $logger->warning('bootstrap_finance: falha ao carregar mapa de vendedores canonico', ['message' => $e->getMessage()]);
    }
}
