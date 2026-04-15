<?php

declare(strict_types=1);

use BIomie\Http;
use BIomie\RequestContext;
use BIomie\SalesAnalyticsService;

require __DIR__ . '/bootstrap.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Use POST para sincronizar.'], 405);
    }

    Http::enforceCsrf($logger);
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $key = SalesAnalyticsService::cacheKey($range['start'], $range['end']);

    if (!Http::wantsAsync()) {
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

    $queueResult = $queue->enqueue('sales', $key, ['start' => $range['start'], 'end' => $range['end']]);
    Http::accepted([
        'ok' => true,
        'message' => $queueResult['queued']
            ? 'Atualização agendada. A página será recarregada quando o snapshot ficar pronto.'
            : 'Atualização já está em andamento para esse período.',
        'mode' => 'queued',
        'queue' => $queueResult,
        'request_id' => RequestContext::id(),
    ], [
        'Location' => sprintf('refresh_status.php?service=sales&start=%s&end=%s', rawurlencode($range['start']), rawurlencode($range['end'])),
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
