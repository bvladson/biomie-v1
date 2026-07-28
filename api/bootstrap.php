<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Referrer-Policy: same-origin');

use BIomie\Auth;
use BIomie\BronzeRepository;
use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http\ErrorBoundary;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRepository;
use BIomie\RefreshQueue;
use BIomie\SalesAnalyticsService;
use BIomie\Support\PerformanceTimer;
use BIomie\Support\TenantContext;
use BIomie\Support\TenantContextUnavailableException;
use BIomie\Telemetry;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

ErrorBoundary::registerApiHandlers([
    'route' => 'api/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'api')),
    'handler' => 'api/bootstrap.php',
]);

// Fase 11: registra coleta de latência por endpoint antes de qualquer saída
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
    'script' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'bootstrap.php')),
]);
$GLOBALS['biomie_performance_timer'] = PerformanceTimer::start(
    'api.' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'api'))
);

Auth::boot($config, $logger);
Auth::requireLogin(true);
try {
    TenantContext::boot(Database::connect($config, 'db'));
} catch (TenantContextUnavailableException $exception) {
    $contextStatus = in_array($exception->getCode(), [400, 401, 403, 409, 503], true)
        ? $exception->getCode()
        : 403;
    http_response_code($contextStatus);
    header('X-BIOMIE-Context-Invalid: 1');
    header('X-BIOMIE-Context-Reason: ' . $exception->getReasonCode());
    header('X-BIOMIE-Context-Redirect: ' . Auth::url('select_empresa.php?context_reset=1'));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'ok' => false,
        'error' => $exception->getReasonCode(),
        'message' => $exception->getMessage(),
        'redirect' => Auth::url('select_empresa.php?context_reset=1'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

\BIomie\Http::enforceCsrf($logger);

$contextMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (TenantContext::isConsolidado()
    && in_array($contextMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
) {
    \BIomie\Http::json([
        'ok' => false,
        'error' => 'group_context_read_only',
        'message' => 'O contexto consolidado é somente leitura. Entre na empresa de origem para alterar dados.',
        'meta' => TenantContext::apiMeta(),
    ], 403);
}
if (
    session_status() === PHP_SESSION_ACTIVE
    && in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)
) {
    session_write_close();
}

$repository = null;
if (!TenantContext::isConsolidado()) {
    try {
        $bronze = BronzeRepository::boot($config, $logger);
        $client = OmieClientFactory::boot($config, $logger, $cache)
            ->forCompany(TenantContext::companyId());
        $repository = new OmieRepository($client, $config, $bronze, 'sales-api');
    } catch (\RuntimeException $exception) {
        $logger->warning('Cliente Omie indisponivel para o tenant ativo.', [
            'company_id' => TenantContext::companyId(),
            'message' => $exception->getMessage(),
        ]);
    }
}

$queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);
$analytics = new SalesAnalyticsService($config, $cache, $logger, $repository, $queue);
