<?php

declare(strict_types=1);

use BIomie\Http;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;
use BIomie\SalesAnalyticsService;
use BIomie\Support\SecretMask;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.sync', true);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Use POST para atualizar a visao local.'], 405);
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
            'message' => 'Visao local atualizada com os dados disponiveis.',
            'source_action' => 'local_refresh',
            'mode' => $snapshot['mode'],
            'period' => $snapshot['period'],
            'summary' => $snapshot['summary'],
            'served_from' => $snapshot['meta']['served_from'] ?? 'manual-refresh',
            'meta' => $snapshot['meta'],
            'request_id' => RequestContext::id(),
        ]);
    }

    $jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
    $companyId = \BIomie\Support\TenantContext::companyId();
    if ($companyId <= 0) {
        Http::json(['ok' => false, 'message' => 'Selecione uma empresa para sincronizar vendas.'], 409);
    }
    $queueResult = $jobQueue->enqueue('default', 'sales.snapshot.refresh', [
        'company_id' => $companyId,
        'start' => $range['start'],
        'end' => $range['end'],
    ], [
        'idempotency_key' => 'sales.snapshot.refresh|company:' . $companyId . '|' . $key,
        'max_attempts' => 3,
        'created_by' => \BIomie\Auth::currentUser()['id'] ?? null,
    ]);
    $statusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));
    Http::accepted([
        'ok' => true,
        'message' => $queueResult['queued']
            ? 'Atualizacao da visao local agendada. Requer worker ativo para executar.'
            : 'Atualizacao da visao local ja esta em andamento para esse periodo.',
        'source_action' => 'local_refresh',
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
    $logger->error('Falha ao atualizar visao local comercial', [
        'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 200),
        'trace' => $exception->getTraceAsString(),
    ]);
    Http::json([
        'ok' => false,
        'message' => 'Nao foi possivel iniciar a atualizacao da visao local agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
