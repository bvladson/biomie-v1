<?php

declare(strict_types=1);

use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap_finance.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
            header('Location: ../financeiro.php');
            exit;
        }
        Http::json(['ok' => false, 'message' => 'Use POST para sincronizar.'], 405);
    }
    if ($finance === null) {
        Http::json(['ok' => false, 'message' => 'Credenciais Omie não configuradas.'], 500);
    }

    Http::enforceCsrf($logger);
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $key = sprintf('finance_snapshot_%s_%s', $range['start'], $range['end']);

    if (!Http::wantsAsync()) {
        $snapshot = $finance->getSnapshot($range['start'], $range['end'], true);
        Http::json([
            'ok' => true,
            'message' => 'Atualização financeira concluída.',
            'mode' => $snapshot['mode'],
            'served_from' => $snapshot['meta']['served_from'] ?? 'manual-refresh',
            'meta' => $snapshot['meta'],
            'request_id' => RequestContext::id(),
        ]);
    }

    $queueResult = $queue->enqueue('finance', $key, ['start' => $range['start'], 'end' => $range['end']]);
    Http::accepted([
        'ok' => true,
        'message' => $queueResult['queued']
            ? 'Atualização financeira agendada. A página será recarregada quando o snapshot ficar pronto.'
            : 'Atualização financeira já está em andamento para esse período.',
        'mode' => 'queued',
        'queue' => $queueResult,
        'request_id' => RequestContext::id(),
    ], [
        'Location' => sprintf('refresh_status.php?service=finance&start=%s&end=%s', rawurlencode($range['start']), rawurlencode($range['end'])),
    ]);
} catch (\InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $logger->error('Falha ao sincronizar financeiro', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    Http::json([
        'ok' => false,
        'message' => 'Não foi possível iniciar a atualização financeira agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
