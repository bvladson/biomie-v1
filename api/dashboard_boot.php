<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CanonicalRepository;
use BIomie\Cache;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Sales\Application\Overview\OverviewRequest;
use BIomie\RequestContext;
use BIomie\Support;
use BIomie\Support\GlobalHomeDataGuard;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';
Auth::requireCapability('sales.view', true);
GlobalHomeDataGuard::requireUnrestricted();

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $start = $range['start'];
    $end = $range['end'];
    $cache = new Cache(dirname(__DIR__) . '/storage/cache');
    $cacheKey = sprintf(
        'dashboard_boot_v2_%s_%s',
        OverviewRequest::tenantCacheScope(),
        md5($start . '_' . $end . '_' . (Auth::isAdmin() ? 'admin' : 'user')),
    );
    $force = Http::boolQuery('force', false);
    if ($force) {
        $cache->delete($cacheKey);
        $cache->get($cacheKey, 0);
        $cached = null;
    } else {
        $cached = $cache->get($cacheKey, 120);
    }
    if (is_array($cached)) {
        $cached['cache'] = ['status' => 'hit', 'key' => $cacheKey];
        Http::json($cached);
    }

    $summary = [
        'revenue' => 0.0,
        'gross_revenue' => 0.0,
        'orders' => 0,
        'avg_ticket' => 0.0,
        'active_clients' => 0,
        'active_vendors' => 0,
    ];
    $source = 'empty';
    $diagnostics = ['request_id' => RequestContext::id(), 'canonical_available' => false];

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (Database::isConfigured($config, $connection)) {
        $pdo = Database::connect($config, $connection);
        $canonical = new CanonicalRepository($pdo, $logger);
        if ($canonical->isAvailable()) {
            $diagnostics['canonical_available'] = true;
            $row = $canonical->summarizeInvoicesCommercial($start, $end);
            $revenue = Support::toFloat($row['billed_value'] ?? 0);
            $orders = (int) ($row['billed_orders'] ?? 0);
            $summary['revenue'] = Support::money($revenue);
            $summary['gross_revenue'] = Support::money($revenue);
            $summary['orders'] = $orders;
            $summary['avg_ticket'] = $orders > 0 ? Support::money($revenue / $orders) : 0.0;
            $summary['active_clients'] = (int) ($row['active_clients'] ?? 0);
            $summary['active_vendors'] = (int) ($row['active_vendors'] ?? 0);
            $source = 'canonical';
        }
    }

    $payload = [
        'ok' => true,
        'source' => $source,
        'period' => ['start' => $start, 'end' => $end, 'period_basis' => 'data_competencia'],
        'summary' => $summary,
        'freshness' => ['status' => 'unknown'],
        'diagnostics' => $diagnostics + [
            'company_id' => TenantContext::companyId(),
            'channel' => TenantContext::canal(),
        ],
        'cache' => ['status' => 'miss', 'key' => $cacheKey, 'ttl' => 120],
    ];
    $cache->put($cacheKey, $payload);
    Http::json($payload);
} catch (InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage(), 'request_id' => RequestContext::id()], 400);
} catch (Throwable $exception) {
    $logger->warning('dashboard_boot indisponivel.', ['message' => $exception->getMessage(), 'request_id' => RequestContext::id()]);
    Http::json(['ok' => false, 'message' => 'Boot rapido indisponivel.', 'request_id' => RequestContext::id()], 500);
}
