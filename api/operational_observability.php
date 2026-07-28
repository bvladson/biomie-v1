<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Http;
use BIomie\OperationalObservabilityService;
use BIomie\RequestContext;
use BIomie\Telemetry;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config definido em api/bootstrap.php */
/** @var \BIomie\Logger $logger definido em api/bootstrap.php */
/** @var \BIomie\Cache $cache definido em api/bootstrap.php */

Auth::requireCapability('logs.view', true);

try {
    $telemetry = Telemetry::boot($config, $logger);
    $telemetry->startRequestSpan('http.operational_observability', [
        'domain_name' => 'observability',
        'source_name' => 'api',
    ]);

    $hours = max(1, min((int) ($_GET['hours'] ?? $config->get('observability.dashboard_default_hours', 24)), 720));
    $force = Http::boolQuery('force', false);
    $cacheTtl = 30;
    $cacheKey = 'operational_observability_v3_hours_' . $hours;
    $startedAt = microtime(true);
    $dashboard = $force ? null : $cache->get($cacheKey, $cacheTtl);
    $cacheStatus = is_array($dashboard) ? 'hit' : ($force ? 'refresh' : 'miss');

    if (!is_array($dashboard)) {
        $service = OperationalObservabilityService::boot($config, $logger);
        if ($force) {
            $dashboard = $service->buildDashboard($hours);
            $cache->put($cacheKey, $dashboard);
        } else {
            $dashboard = $cache->getOrCompute(
                $cacheKey,
                $cacheTtl,
                static fn (): array => $service->buildDashboard($hours),
            );
        }
    }

    // A referência identifica esta requisição; generated_at continua indicando
    // quando o snapshot foi realmente calculado.
    $dashboard['trace'] = RequestContext::traceContext();
    $cacheMeta = $cache->getMeta($cacheKey);
    header('X-BIOMIE-Cache: ' . $cacheStatus);
    $payload = [
        'ok' => true,
        'dashboard' => $dashboard,
        'cache' => [
            'status' => $cacheStatus,
            'ttl_seconds' => $cacheTtl,
            'age_seconds' => $cacheMeta['age_seconds'] ?? null,
        ],
        'request' => [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'request_id' => RequestContext::id(),
        ],
    ];

    Http::json($payload);
} catch (\InvalidArgumentException $exception) {
    Http::json([
        'ok' => false,
        'message' => $exception->getMessage(),
        'request_id' => \BIomie\RequestContext::id(),
    ], 400);
} catch (Throwable $exception) {
    $logger->error('Falha ao carregar dashboard operacional de observabilidade.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao carregar observabilidade operacional.',
        'request_id' => \BIomie\RequestContext::id(),
    ], 500);
}
