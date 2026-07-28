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
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRepository;
use BIomie\RefreshQueue;
use BIomie\Support\PerformanceTimer;
use BIomie\Telemetry;
use BIomie\Support\TenantContext;
use BIomie\Support\TenantContextUnavailableException;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

\BIomie\Observability\EndpointMetricsCollector::registerShutdownHook(
    dirname(__DIR__) . '/storage/logs/endpoint_requests.jsonl'
);

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan(null, [
    'domain_name' => 'http',
    'source_name' => 'api',
    'script' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'bootstrap_finance.php')),
]);
$GLOBALS['biomie_performance_timer'] = PerformanceTimer::start(
    'api.' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'finance-api'))
);

Auth::boot($config, $logger);
Auth::requireLogin(true);

try {
    TenantContext::boot(Database::connect($config, 'db'));
} catch (TenantContextUnavailableException $exception) {
    $contextStatus = in_array($exception->getCode(), [400, 401, 403, 409, 503], true)
        ? $exception->getCode()
        : 403;
    \BIomie\Http::json([
        'ok' => false,
        'error' => $exception->getReasonCode(),
        'message' => $exception->getMessage(),
        'redirect' => Auth::url('select_empresa.php?context_reset=1'),
    ], $contextStatus, [
        'X-BIOMIE-Context-Invalid' => '1',
        'X-BIOMIE-Context-Reason' => $exception->getReasonCode(),
        'X-BIOMIE-Context-Redirect' => Auth::url('select_empresa.php?context_reset=1'),
    ]);
}

\BIomie\Http::enforceCsrf($logger);

$financeBootstrapCapabilities = isset($financeBootstrapCapabilities) && is_array($financeBootstrapCapabilities)
    ? array_values(array_filter($financeBootstrapCapabilities, 'is_string'))
    : ['finance.view'];

if (count($financeBootstrapCapabilities) === 1) {
    Auth::requireCapability($financeBootstrapCapabilities[0], true);
} else {
    Auth::requireAnyCapability($financeBootstrapCapabilities, true);
}

$financeContextMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (TenantContext::isConsolidado()
    && in_array($financeContextMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
) {
    \BIomie\Http::json([
        'ok' => false,
        'error' => 'group_context_read_only',
        'message' => 'O contexto consolidado é somente leitura. Entre na empresa de origem para alterar dados.',
        'meta' => TenantContext::apiMeta(),
    ], 403);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$finance = null;
$salesRepository = null;

if (!TenantContext::isConsolidado()) {
    try {
        $bronze = BronzeRepository::boot($config, $logger);
        $client = OmieClientFactory::boot($config, $logger, $cache)
            ->forCompany(TenantContext::companyId());

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
    } catch (\RuntimeException $exception) {
        $logger->warning('Cliente Omie financeiro indisponivel para o tenant ativo.', [
            'company_id' => TenantContext::companyId(),
            'message' => $exception->getMessage(),
        ]);
    }
}
