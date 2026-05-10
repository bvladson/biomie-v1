<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CustomerDimensionMaterializationService;
use BIomie\Database;
use BIomie\Http;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;
use BIomie\TableHealthService;

require __DIR__ . '/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Http::json([
        'ok' => false,
        'message' => 'Use POST para materializar a dimensao de clientes.',
        'request_id' => RequestContext::id(),
    ], 405);
}

Auth::requireAnyCapability(['jobs.run', 'omie.sync', 'settings.manage'], true, 'Seu perfil nao tem permissao para materializar a dimensao de clientes.');

Http::enforceCsrf($logger);

$user = Auth::currentUser();
$actorUserId = isset($user['id']) ? (int) $user['id'] : null;
$jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
$queueResult = $jobQueue->enqueue('low', 'clients.materialize', [
    'actor_user_id' => $actorUserId,
], [
    'idempotency_key' => 'clients.materialize',
    'max_attempts' => 2,
    'created_by' => $actorUserId,
]);
$statusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));
Http::accepted([
    'ok' => true,
    'status' => 'queued',
    'message' => $queueResult['queued']
        ? 'Materializacao de clientes agendada.'
        : 'Materializacao de clientes ja esta em andamento.',
    'job_id' => $queueResult['job_id'],
    'status_url' => $statusUrl,
    'queue' => $queueResult,
    'meta' => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM)],
], ['Location' => $statusUrl]);

$report = null;

try {
    $connectionName = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connectionName);
    $tableHealth = new TableHealthService($pdo, $logger);

    $user = Auth::currentUser();
    $actorUserId = isset($user['id']) ? (int) $user['id'] : null;

    $service = new CustomerDimensionMaterializationService($pdo, $logger, $tableHealth);
    $report = $service->run($actorUserId);

    $ok = (bool) ($report['ok'] ?? false);
    $status = (string) ($report['status'] ?? 'unknown');

    $statusCode = 200;
    if ($status === 'migration_pending') {
        $statusCode = 409;
    } elseif ($status === 'failed') {
        $statusCode = 500;
    }

    Http::json([
        'ok'      => $ok,
        'status'  => $status,
        'message' => $ok
            ? sprintf(
                'Dimensao de clientes materializada: %d inseridos, %d atualizados.',
                (int) ($report['inserted_count'] ?? 0),
                (int) ($report['updated_count']  ?? 0)
            )
            : (isset($report['errors'][0]) ? (string) $report['errors'][0] : 'Erro ao materializar clientes.'),
        'report' => $report,
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'connection' => $connectionName,
        ],
    ], $statusCode);
} catch (Throwable $exception) {
    $logger->error('api/materialize_clients.php: falha.', [
        'message' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ]);
    Http::json([
        'ok'      => false,
        'status'  => 'failed',
        'message' => 'Nao foi possivel materializar a dimensao de clientes agora.',
        'report'  => is_array($report) ? $report : [],
        'meta'    => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM)],
    ], 500);
}
