<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Database;
use BIomie\FinanceAnalyticsService;
use BIomie\Http;
use BIomie\Logger;
use BIomie\Queue\FileJobQueue;
use BIomie\RefreshQueue;
use BIomie\RequestContext;
use BIomie\SalesAnalyticsService;
use BIomie\Support\TenantContext;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$cache = new Cache(dirname(__DIR__) . '/storage/cache');
$queue = new RefreshQueue(dirname(__DIR__) . '/storage/cache', $logger);
$jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);

Auth::boot($config, $logger);
Auth::requireLogin(true);
TenantContext::boot(Database::connect($config, 'db'));
$companyId = TenantContext::companyId();
if ($companyId <= 0) {
    Http::json(['ok' => false, 'message' => 'Selecione uma empresa para consultar a atualizacao.'], 409);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $jobId = trim((string) Http::query('job_id', ''));
    if ($jobId !== '') {
        Auth::requireAnyCapability(['jobs.view', 'sales.view', 'finance.view', 'omie.view'], true);
        $job = $jobQueue->find($jobId);
        if (is_array($job) && (int) ($job['payload']['company_id'] ?? 0) !== $companyId) {
            $job = null;
        }
        Http::json([
            'ok' => true,
            'job' => $job,
            'state' => is_array($job) ? (string) ($job['status'] ?? 'unknown') : 'missing',
            'queue' => $jobQueue->summary(),
            'legacy_queue' => $queue->summary(),
            'request_id' => RequestContext::id(),
        ]);
    }

    $service = strtolower((string) Http::query('service', 'sales'));
    Auth::requireAnyCapability($service === 'finance' ? ['finance.view', 'jobs.view'] : ['sales.view', 'jobs.view'], true);
    if (!in_array($service, ['sales', 'finance'], true)) {
        Http::json(['ok' => false, 'message' => 'Serviço inválido.'], 400);
    }

    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $key = $service === 'finance'
        ? FinanceAnalyticsService::cacheKey($range['start'], $range['end'], $companyId)
        : SalesAnalyticsService::cacheKey($range['start'], $range['end']);
    $type = $service === 'finance' ? 'finance.snapshot.refresh' : 'sales.snapshot.refresh';
    $newJob = $jobQueue->findByIdempotencyKey($type . '|company:' . $companyId . '|' . $key);
    $job = $newJob ?? $queue->findBySnapshot($service, $key);
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
        'queue' => $jobQueue->summary(),
        'legacy_queue' => $queue->summary(),
        'request_id' => RequestContext::id(),
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
