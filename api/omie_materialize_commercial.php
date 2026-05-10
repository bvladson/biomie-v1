<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CommercialMaterializationService;
use BIomie\Database;
use BIomie\GoldMartBuilder;
use BIomie\Http;
use BIomie\PipelineStatusRepository;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;
use BIomie\Support\SecretMask;
use BIomie\TableHealthService;

require __DIR__ . '/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Http::json([
        'ok' => false,
        'message' => 'Use POST para atualizar a visao comercial.',
        'request_id' => RequestContext::id(),
    ], 405);
}

Auth::requireAnyCapability(['sales.sync', 'omie.sync', 'jobs.run'], true, 'Seu perfil nao tem permissao para atualizar a visao comercial.');

Http::enforceCsrf($logger);

$report = null;

try {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : [];
    $start = isset($payload['start']) && is_string($payload['start']) && trim($payload['start']) !== ''
        ? trim($payload['start'])
        : null;
    $end = isset($payload['end']) && is_string($payload['end']) && trim($payload['end']) !== ''
        ? trim($payload['end'])
        : null;
    $user = Auth::currentUser();
    $actorUserId = isset($user['id']) ? (int) $user['id'] : null;
    $jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
    $queueResult = $jobQueue->enqueue('default', 'commercial.materialize', [
        'start' => $start,
        'end' => $end,
        'actor_user_id' => $actorUserId,
    ], [
        'idempotency_key' => sprintf('commercial.materialize|%s|%s', $start ?? 'auto', $end ?? 'auto'),
        'max_attempts' => 2,
        'created_by' => $actorUserId,
    ]);
    $statusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));

    Http::accepted([
        'ok' => true,
        'status' => 'queued',
        'message' => $queueResult['queued']
            ? 'Atualizacao da visao comercial agendada.'
            : 'Atualizacao da visao comercial ja esta em andamento para esse periodo.',
        'job_id' => $queueResult['job_id'],
        'status_url' => $statusUrl,
        'queue' => $queueResult,
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
        ],
    ], ['Location' => $statusUrl]);

    $connectionName = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connectionName);
    $tableHealth = new TableHealthService($pdo, $logger);
    $pipeline = $tableHealth->tableExists('biomie_pipeline_jobs')
        ? new PipelineStatusRepository($pdo, $logger)
        : null;

    $builder = new GoldMartBuilder($pdo, $logger, $pipeline);
    $service = new CommercialMaterializationService($pdo, $logger, $builder, $tableHealth);
    $report = $service->run(isset($user['id']) ? (int) $user['id'] : null, $start, $end);
    $message = materializationMessage($report);

    $statusCode = 200;
    if (($report['status'] ?? '') === 'missing_target_tables' || ($report['status'] ?? '') === 'missing_source_tables') {
        $statusCode = 409;
    } elseif (($report['status'] ?? '') === 'failed') {
        $statusCode = 500;
    }

    Http::json([
        'ok' => (bool) ($report['ok'] ?? false),
        'status' => (string) ($report['status'] ?? 'unknown'),
        'message' => $message,
        'report' => $report,
        'meta' => [
            'request_id' => RequestContext::id(),
            'connection' => $connectionName,
            'generated_at' => date(DATE_ATOM),
        ],
    ], $statusCode);
} catch (Throwable $exception) {
    $logger->error('Falha no endpoint de materializacao comercial.', [
        'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 500),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'status' => 'failed',
        'message' => 'Nao foi possivel atualizar a visao comercial agora. O erro tecnico foi registrado no diagnostico.',
        'report' => [
            'source_counts'   => is_array($report) ? ($report['source_counts'] ?? []) : [],
            'inserted_counts' => is_array($report) ? ($report['inserted_counts'] ?? []) : [],
            'error_meta'      => is_array($report) ? ($report['error_meta'] ?? []) : [],
        ],
        'meta' => [
            'request_id'   => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
        ],
    ], 500);
}

/**
 * @param array<string,mixed> $report
 */
function materializationMessage(array $report): string
{
    if (($report['ok'] ?? false) === true) {
        $source = is_array($report['source_period_counts'] ?? null) ? $report['source_period_counts'] : [];
        $pedidos = (int) ($source['pedidos_canonicos'] ?? 0);
        $notas = (int) ($source['notas_fiscais_canonicas'] ?? 0);
        $itens = (int) ($source['itens_nf_canonicos'] ?? 0);

        return sprintf(
            'Visao comercial atualizada. %d pedidos, %d notas e %d itens foram processados.',
            $pedidos,
            $notas,
            $itens
        );
    }

    $status = (string) ($report['status'] ?? '');
    if ($status === 'source_empty') {
        return 'Nao ha dados canonicos suficientes para materializar a visao comercial. Execute a sincronizacao comercial primeiro.';
    }
    if ($status === 'missing_target_tables') {
        return 'As tabelas analiticas ainda nao estao disponiveis. Execute as migrations.';
    }
    if ($status === 'missing_source_tables') {
        return 'As tabelas canonicas ainda nao estao disponiveis. Execute as migrations e sincronize os dados comerciais.';
    }
    if ($status === 'warning_zero_rows' || ($report['zero_reason'] ?? null) !== null) {
        return 'A visao comercial foi executada, mas nenhum registro foi materializado. Verifique o diagnostico tecnico.';
    }

    return 'Nao foi possivel atualizar a visao comercial agora. O erro tecnico foi registrado no diagnostico.';
}
