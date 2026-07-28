<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Http;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;
use BIomie\Support\SecretMask;

require __DIR__ . '/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Http::methodNotAllowed(['POST'], 'Use POST para agendar sync delta Omie.');
}

Auth::requireCapability('omie.sync', true, 'Seu perfil nao tem permissao para sincronizar a Omie.');
Http::enforceCsrf($logger);

try {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : [];
    $domains = array_values(array_filter(array_map(
        'strval',
        is_array($payload['domains'] ?? null) ? $payload['domains'] : []
    )));

    $runId = bin2hex(random_bytes(16));
    $runId = sprintf(
        '%s-%s-%s-%s-%s',
        substr($runId, 0, 8),
        substr($runId, 8, 4),
        substr($runId, 12, 4),
        substr($runId, 16, 4),
        substr($runId, 20, 12)
    );

    $queue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
    $companyId = \BIomie\Support\TenantContext::companyId();
    if ($companyId <= 0) {
        Http::json(['ok' => false, 'message' => 'Selecione uma empresa para executar o delta sync.'], 409);
    }
    $queueResult = $queue->enqueue('default', 'omie.delta.sync', [
        'company_id' => $companyId,
        'domains' => $domains,
        'run_id' => $runId,
        'actor_user_id' => Auth::currentUser()['id'] ?? null,
    ], [
        'idempotency_key' => 'omie.delta.sync|company:' . $companyId . '|' . implode(',', $domains) . '|' . date('Y-m-d-H-i'),
        'max_attempts' => 3,
        'created_by' => Auth::currentUser()['id'] ?? null,
    ]);
    $jobStatusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));
    $statusUrl = sprintf('api/omie_status.php?run_id=%s', rawurlencode($runId));
    $domainsLabel = $domains !== [] ? implode(',', $domains) : 'commercial,finance';

    Http::accepted([
        'ok' => true,
        'status' => 'queued',
        'message' => $queueResult['queued']
            ? 'Sincronizacao delta agendada. Requer worker ativo para executar.'
            : 'Sincronizacao delta ja estava agendada ou em andamento. Requer worker ativo para executar.',
        'job_id' => $queueResult['job_id'],
        'run_id' => $runId,
        'status_url' => $statusUrl,
        'job_status_url' => $jobStatusUrl,
        'omie_status_url' => 'api/omie_status.php',
        'follow_up' => 'Acompanhe fila pendente, ultimo job executado e worker recente na pagina Conexao Omie.',
        'worker_required' => true,
        'recommended_worker_command' => 'php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55',
        'recommended_direct_command' => 'php cron/sync_realtime_pipeline.php --domains=' . $domainsLabel,
        'queue' => $queueResult,
        'request_id' => RequestContext::id(),
    ], ['Location' => $jobStatusUrl]);
} catch (Throwable $exception) {
    $logger->error('Falha ao agendar sync delta Omie.', [
        'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 200),
        'request_id' => RequestContext::id(),
    ]);
    Http::json([
        'ok' => false,
        'message' => 'Nao foi possivel agendar o sync delta agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
