<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\GeoCnaeMaterializationService;
use BIomie\Http;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;
use BIomie\TableHealthService;

require __DIR__ . '/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Http::json([
        'ok' => false,
        'message' => 'Use POST para materializar Geo/CNAE.',
        'request_id' => RequestContext::id(),
    ], 405);
}

Auth::requireAnyCapability(['geo.materialize', 'jobs.run'], true, 'Seu perfil nao tem permissao para materializar Geo/CNAE.');

Http::enforceCsrf($logger);

$user = Auth::currentUser();
$actorUserId = isset($user['id']) ? (int) $user['id'] : null;
$companyId = \BIomie\Support\TenantContext::companyId();
if ($companyId <= 0) {
    Http::json(['ok' => false, 'message' => 'Selecione uma empresa para materializar Geo/CNAE.'], 409);
}
$jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
$queueResult = $jobQueue->enqueue('low', 'geo_cnae.materialize', [
    'company_id' => $companyId,
    'actor_user_id' => $actorUserId,
], [
    'idempotency_key' => 'geo_cnae.materialize|company:' . $companyId,
    'max_attempts' => 2,
    'created_by' => $actorUserId,
]);
$statusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));
Http::accepted([
    'ok' => true,
    'status' => 'queued',
    'message' => $queueResult['queued']
        ? 'Materializacao Geo/CNAE agendada.'
        : 'Materializacao Geo/CNAE ja esta em andamento.',
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

    $service = new GeoCnaeMaterializationService($pdo, $logger, $tableHealth);
    $report = $service->run(['dry_run' => false, 'rebuild' => false], $actorUserId);

    $ok = (bool) ($report['ok'] ?? false);
    $status = (string) ($report['status'] ?? 'unknown');

    $statusCode = 200;
    if ($status === 'prerequisites_missing') {
        $statusCode = 409;
    } elseif ($status === 'error') {
        $statusCode = 500;
    }

    Http::json([
        'ok'      => $ok,
        'status'  => $status,
        'message' => $ok
            ? sprintf(
                'Geo/CNAE materializado: %d entradas geo, %d bairros com match, %d clientes na fila.',
                (int) ($report['skeletons_created']     ?? 0),
                (int) ($report['bairros_matched']       ?? 0),
                (int) ($report['queued_for_enrichment'] ?? 0)
            )
            : (isset($report['errors'][0]) ? (string) $report['errors'][0] : 'Erro ao materializar Geo/CNAE.'),
        'report' => $report,
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'connection' => $connectionName,
        ],
    ], $statusCode);
} catch (Throwable $exception) {
    $logger->error('api/geo_cnae_materialize.php: falha.', [
        'message' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ]);
    Http::json([
        'ok'      => false,
        'status'  => 'error',
        'message' => 'Nao foi possivel materializar Geo/CNAE agora.',
        'report'  => is_array($report) ? $report : [],
        'meta'    => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM)],
    ], 500);
}
