<?php

declare(strict_types=1);

use BIomie\Http;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;
use BIomie\SalesAnalyticsService;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.sync', true);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Use POST para sincronizar.'], 405);
    }

    Http::enforceCsrf($logger);
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $key = SalesAnalyticsService::cacheKey($range['start'], $range['end']);

    $appEnv = strtolower((string) $config->get('app.env', 'production'));
    $allowSyncWeb = (bool) $config->get('app.allow_sync_web', false)
        && !in_array($appEnv, ['production', 'prod'], true);

    if (!Http::wantsAsync() && $allowSyncWeb) {
        $snapshot = $analytics->getSnapshot($range['start'], $range['end'], true);
        Http::json([
            'ok' => true,
            'message' => 'Sincronização concluída.',
            'mode' => $snapshot['mode'],
            'period' => $snapshot['period'],
            'summary' => $snapshot['summary'],
            'served_from' => $snapshot['meta']['served_from'] ?? 'manual-refresh',
            'meta' => $snapshot['meta'],
            'request_id' => RequestContext::id(),
        ]);
    }

    $jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
    $queueResult = $jobQueue->enqueue('default', 'sales.snapshot.refresh', [
        'start' => $range['start'],
        'end' => $range['end'],
    ], [
        'idempotency_key' => 'sales.snapshot.refresh|' . $key,
        'max_attempts' => 3,
        'created_by' => \BIomie\Auth::currentUser()['id'] ?? null,
    ]);
    $statusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));
    Http::accepted([
        'ok' => true,
        'message' => $queueResult['queued']
            ? 'Atualização agendada. A página será recarregada quando o snapshot ficar pronto.'
            : 'Atualização já está em andamento para esse período.',
        'mode' => 'queued',
        'job_id' => $queueResult['job_id'],
        'status_url' => $statusUrl,
        'queue' => $queueResult,
        'request_id' => RequestContext::id(),
    ], [
        'Location' => $statusUrl,
    ]);
} catch (\InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->error('Falha ao sincronizar comercial', ['message' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()]);
    Http::json([
        'ok' => false,
        'message' => 'Não foi possível iniciar a atualização comercial agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
