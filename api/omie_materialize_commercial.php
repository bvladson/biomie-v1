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
    $companyId = \BIomie\Support\TenantContext::companyId();
    if ($companyId <= 0) {
        Http::json(['ok' => false, 'message' => 'Selecione uma empresa para materializar o comercial.'], 409);
    }
    $jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
    $queueResult = $jobQueue->enqueue('default', 'commercial.materialize', [
        'company_id' => $companyId,
        'start' => $start,
        'end' => $end,
        'actor_user_id' => $actorUserId,
    ], [
        'idempotency_key' => sprintf('commercial.materialize|company:%d|%s|%s', $companyId, $start ?? 'auto', $end ?? 'auto'),
        'max_attempts' => 2,
        'created_by' => $actorUserId,
    ]);
    $statusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));
    $sourceWarning = commercialMaterializationSourceWarning($config, $logger);

    Http::accepted([
        'ok' => true,
        'status' => 'queued',
        'message' => $queueResult['queued']
            ? 'Job agendado para recalcular a visao comercial com os dados locais disponiveis. Requer worker ativo para executar.'
            : 'Recalculo da visao comercial ja esta agendado para esse periodo. Requer worker ativo para executar.',
        'job_id' => $queueResult['job_id'],
        'status_url' => $statusUrl,
        'omie_status_url' => 'api/omie_status.php',
        'local_data_only' => true,
        'worker_required' => true,
        'feedback_message' => 'Visao comercial recalculada com os dados locais disponiveis.',
        'source_warning' => $sourceWarning,
        'warning' => is_array($sourceWarning)
            ? 'Atencao: a visao foi materializada, mas os dados Omie/canonicos estao desatualizados.'
            : null,
        'recommended_worker_command' => 'php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55',
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
            'Visao comercial recalculada com os dados locais disponiveis. %d pedidos, %d notas e %d itens foram processados.',
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

/**
 * @return array<string,mixed>|null
 */
function commercialMaterializationSourceWarning(\BIomie\Config $config, \BIomie\Logger $logger): ?array
{
    try {
        $connectionName = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (!Database::isConfigured($config, $connectionName)) {
            return [
                'status' => 'warning',
                'message' => 'Nao foi possivel verificar a freshness canonica local.',
            ];
        }

        $pdo = Database::connect($config, $connectionName);
        $tableHealth = new TableHealthService($pdo, $logger);
        $statuses = [];
        $details = [];

        foreach ([
            'biomie_pedidos_canonicos' => 'Pedidos canonicos',
            'biomie_notas_fiscais_canonicas' => 'NF canonicas',
        ] as $table => $label) {
            if (!$tableHealth->tableExists($table)) {
                $statuses[] = 'danger';
                $details[] = [
                    'table' => $table,
                    'label' => $label,
                    'status' => 'danger',
                    'message' => 'Tabela ausente.',
                ];
                continue;
            }

            $rows = $tableHealth->safeCount($table, true) ?? 0;
            $lastSync = $tableHealth->lastUpdatedAt($table, [
                'sincronizado_em',
                'synced_at',
                'updated_at',
                'fetched_at',
                'ingested_at',
                'created_at',
                'data_competencia',
                'data_emissao',
            ]);
            $status = 'warning';
            if ($rows > 0 && $lastSync !== null) {
                $ageHours = max(0.0, (time() - (strtotime($lastSync) ?: time())) / 3600);
                $status = $ageHours <= 24 ? 'ok' : ($ageHours <= 72 ? 'warning' : 'danger');
            }

            $statuses[] = $status;
            $details[] = [
                'table' => $table,
                'label' => $label,
                'rows' => $rows,
                'last_sync' => $lastSync,
                'status' => $status,
            ];
        }

        $rank = ['ok' => 1, 'pending' => 2, 'warning' => 3, 'danger' => 4, 'unknown' => 0];
        $worst = 'ok';
        foreach ($statuses as $status) {
            if (($rank[$status] ?? 0) > ($rank[$worst] ?? 0)) {
                $worst = $status;
            }
        }

        if ($worst === 'ok') {
            return null;
        }

        return [
            'status' => $worst,
            'message' => 'Atencao: a visao foi materializada, mas os dados Omie/canonicos estao desatualizados.',
            'details' => $details,
        ];
    } catch (Throwable $exception) {
        return [
            'status' => 'warning',
            'message' => 'Nao foi possivel verificar a freshness canonica local.',
            'detail' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 160),
        ];
    }
}
