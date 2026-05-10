<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Http;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['geo.materialize', 'jobs.run'], true, 'Seu perfil nao tem permissao para materializar geografia.');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Http::json([
        'ok' => false,
        'message' => 'Use POST para materializar geografia.',
        'request_id' => RequestContext::id(),
    ], 405);
}
Http::enforceCsrf($logger);

try {
    $range = Http::resolveDateRangeOrCompetencia();
    Http::validateDateRange($range['start'], $range['end']);
    $user = Auth::currentUser();
    $actorUserId = isset($user['id']) ? (int) $user['id'] : null;
    $jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
    $queueResult = $jobQueue->enqueue('low', 'geo.materialize', [
        'start' => $range['start'],
        'end' => $range['end'],
        'actor_user_id' => $actorUserId,
    ], [
        'idempotency_key' => sprintf('geo.materialize|%s|%s', $range['start'], $range['end']),
        'max_attempts' => 2,
        'created_by' => $actorUserId,
    ]);
    $statusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));
    Http::accepted([
        'ok' => true,
        'status' => 'queued',
        'message' => $queueResult['queued']
            ? 'Materializacao geografica agendada.'
            : 'Materializacao geografica ja esta em andamento para esse periodo.',
        'job_id' => $queueResult['job_id'],
        'status_url' => $statusUrl,
        'queue' => $queueResult,
        'period' => ['start' => $range['start'], 'end' => $range['end'], 'label' => $range['label']],
        'meta' => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM)],
    ], ['Location' => $statusUrl]);

    $connectionName = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connectionName);
    $canonical = new CanonicalRepository($pdo, $logger);

    $start = $range['start'];
    $end = $range['end'];
    $missingTables = $canonical->missingGeoAnalyticsTables();
    if ($missingTables !== []) {
        $logger->warning('Geo analytics: materializacao bloqueada por tabelas ausentes.', [
            'missing_tables' => $missingTables,
            'start' => $start,
            'end' => $end,
            'request_id' => RequestContext::id(),
        ]);

        Http::json([
            'ok' => false,
            'message' => 'Tabelas geográficas ausentes. Rode as migrations no painel administrativo.',
            'missing_tables' => $missingTables,
            'request_id' => RequestContext::id(),
        ], 409);
    }

    $canonical->reconstructGeoFactTables($start, $end);
    $adminLog->log('materialization_executed', null, [
        'module' => 'geo',
        'start' => $start,
        'end' => $end,
    ]);

    Http::json([
        'ok' => true,
        'message' => 'Malha geografica reconstruida com sucesso para o periodo.',
        'period' => [
            'start' => $start,
            'end' => $end,
            'label' => $range['label'],
        ],
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'connection' => $connectionName,
        ],
    ]);
} catch (\Throwable $exception) {
    $logger->error('Falha ao materializar geo analytics.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Nao foi possivel reconstruir a malha geografica agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
