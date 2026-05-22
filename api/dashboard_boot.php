<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CanonicalRepository;
use BIomie\Cache;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;

require __DIR__ . '/bootstrap.php';
Auth::requireCapability('sales.view', true);

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $start = $range['start'];
    $end = $range['end'];
    $cache = new Cache(dirname(__DIR__) . '/storage/cache');
    $cacheKey = 'dashboard_boot_' . md5($start . '_' . $end . '_' . (Auth::isAdmin() ? 'admin' : 'user'));
    $cached = Http::boolQuery('force', false) ? null : $cache->get($cacheKey, 120);
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
            $topClients = $canonical->topClientsCommercial($start, $end, 1);
            $summary['active_clients'] = count($topClients) > 0 ? null : 0;
            $source = 'canonical';
        }
    }

    $payload = [
        'ok' => true,
        'source' => $source,
        'period' => ['start' => $start, 'end' => $end, 'period_basis' => 'data_competencia'],
        'summary' => $summary,
        'freshness' => ['status' => 'unknown'],
        'diagnostics' => $diagnostics,
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
