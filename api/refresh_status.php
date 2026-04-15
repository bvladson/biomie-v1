<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Http;
use BIomie\Logger;
use BIomie\RefreshQueue;
use BIomie\RequestContext;
use BIomie\SalesAnalyticsService;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);

Auth::boot($config, $logger);
Auth::requireLogin(true);

try {
    $service = strtolower((string) Http::query('service', 'sales'));
    if (!in_array($service, ['sales', 'finance'], true)) {
        Http::json(['ok' => false, 'message' => 'Serviço inválido.'], 400);
    }

    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $key = $service === 'finance'
        ? sprintf('finance_snapshot_%s_%s', $range['start'], $range['end'])
        : SalesAnalyticsService::cacheKey($range['start'], $range['end']);
    $job = $queue->findBySnapshot($service, $key);
    $cacheMeta = $cache->getMeta($key);

    $state = 'idle';
    if (is_array($job)) {
        $state = (string) ($job['status'] ?? 'idle');
    } elseif (($cacheMeta['exists'] ?? false) === true) {
        $state = 'ready';
    }

    Http::json([
        'ok' => true,
        'service' => $service,
        'state' => $state,
        'job' => $job,
        'cache' => $cacheMeta,
        'queue' => $queue->summary(),
    ]);
} catch (\InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->error('Falha ao consultar status de refresh', ['message' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()]);
    Http::json([
        'ok' => false,
        'message' => 'Não foi possível consultar o status da atualização.',
        'request_id' => RequestContext::id(),
    ], 500);
}
