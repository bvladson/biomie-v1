<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Http;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;
use BIomie\Support\SecretMask;

require __DIR__ . '/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Http::methodNotAllowed(['POST'], 'Use POST para reprocessar a fila Omie.');
}

Auth::requireAnyCapability(['jobs.run', 'omie.sync'], true, 'Seu perfil nao tem permissao para reprocessar a fila.');
Http::enforceCsrf($logger);

try {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : [];
    $limit = max(1, min(200, (int) ($payload['limit'] ?? 50)));
    $domains = array_values(array_filter(array_map(
        'strval',
        is_array($payload['domains'] ?? null) ? $payload['domains'] : []
    )));

    $queue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
    $queueResult = $queue->enqueue('high', 'webhook.inbox.process', [
        'limit' => $limit,
        'domains' => $domains,
        'actor_user_id' => Auth::currentUser()['id'] ?? null,
    ], [
        'idempotency_key' => 'webhook.inbox.process|' . implode(',', $domains) . '|' . date('Y-m-d-H-i'),
        'max_attempts' => 3,
        'created_by' => Auth::currentUser()['id'] ?? null,
    ]);
    $statusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));

    Http::accepted([
        'ok' => true,
        'status' => 'queued',
        'message' => $queueResult['queued']
            ? 'Reprocessamento da fila Omie agendado.'
            : 'Reprocessamento da fila Omie ja esta em andamento.',
        'job_id' => $queueResult['job_id'],
        'status_url' => $statusUrl,
        'queue' => $queueResult,
        'request_id' => RequestContext::id(),
    ], ['Location' => $statusUrl]);
} catch (Throwable $exception) {
    $logger->error('Falha ao agendar reprocessamento da fila Omie.', [
        'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 200),
        'request_id' => RequestContext::id(),
    ]);
    Http::json([
        'ok' => false,
        'message' => 'Nao foi possivel agendar o reprocessamento da fila agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
