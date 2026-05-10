<?php

/**
 * api/admin_geo_rebuild.php — Aciona a rematerialização geo para um período específico.
 *
 * Método: POST
 * Permissão: admin.view
 * CSRF: obrigatório
 *
 * Parâmetros (form ou JSON body):
 *   start  — YYYY-MM-DD  início do período
 *   end    — YYYY-MM-DD  fim do período
 *
 * Resposta JSON:
 *   ok, duration_ms, canonical{nfs, merc}, mart_periodo{before, after},
 *   mart_geo{before, after, faturamento, bairros}, steps[], log{job_id}
 *
 * Limite: máximo 3 meses por chamada para evitar rebuild histórico acidental.
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\Gold\Builders\CustomerPeriodMartBuilder;
use BIomie\DataPlatform\Gold\Builders\GeoBuyerMartBuilder;
use BIomie\Http;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['admin.view', 'settings.manage'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::json(['ok' => false, 'message' => 'Use POST para reprocessar.', 'request_id' => RequestContext::id()], 405);
}

Http::enforceCsrf($logger);

try {
    // ── Parse input (form fields or JSON body) ────────────────────────────────
    $body = [];
    $raw  = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $body = json_decode($raw, true) ?: [];
    }

    $startDate = trim((string) ($_POST['start'] ?? $body['start'] ?? Http::query('start') ?? ''));
    $endDate   = trim((string) ($_POST['end']   ?? $body['end']   ?? Http::query('end')   ?? ''));

    if ($startDate === '' || $endDate === '') {
        Http::json(['ok' => false, 'message' => 'Informe start e end (YYYY-MM-DD).', 'request_id' => RequestContext::id()], 400);
    }

    foreach (['start' => $startDate, 'end' => $endDate] as $name => $val) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            Http::json(['ok' => false, 'message' => "Parâmetro {$name} inválido: {$val}", 'request_id' => RequestContext::id()], 400);
        }
    }

    if ($startDate > $endDate) {
        Http::json(['ok' => false, 'message' => 'start deve ser anterior ou igual a end.', 'request_id' => RequestContext::id()], 400);
    }

    // Safety: limit to 3 months max
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
    $endDt   = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
    $diffMonths = (int) $startDt->diff($endDt)->m + ($startDt->diff($endDt)->y * 12);
    if ($diffMonths > 3) {
        Http::json(['ok' => false, 'message' => 'Máximo de 3 meses por rebuild. Divida o período.', 'request_id' => RequestContext::id()], 400);
    }

    // ── Connect ───────────────────────────────────────────────────────────────
    [$pdo, $connName] = Database::getGeoConnection($config);

    // ── Derive competências ───────────────────────────────────────────────────
    $cursor = $startDt->modify('first day of this month');
    $competencias = [];
    while ($cursor <= $endDt) {
        $competencias[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }

    $startComp = substr($startDate, 0, 7);
    $endComp   = substr($endDate, 0, 7);

    // ── Helpers ───────────────────────────────────────────────────────────────
    $countRows = static function (string $table) use ($pdo): int {
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        } catch (\Throwable) {
            return -1;
        }
    };

    $countPeriod = static function (string $table, string $col, string $sc, string $ec) use ($pdo): int {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` BETWEEN :s AND :e");
            $s->execute(['s' => $sc, 'e' => $ec]);
            return (int) $s->fetchColumn();
        } catch (\Throwable) {
            return -1;
        }
    };

    // ── Before snapshot ───────────────────────────────────────────────────────
    $startedAt = microtime(true);

    $before = [
        'mart_cliente_periodo_total'      => $countRows('mart_cliente_periodo'),
        'mart_cliente_periodo_period'     => $countPeriod('mart_cliente_periodo', 'competencia', $startComp, $endComp),
        'mart_compradores_por_geo_total'  => $countRows('mart_compradores_por_geo'),
        'mart_compradores_por_geo_period' => $countPeriod('mart_compradores_por_geo', 'competencia', $startComp, $endComp),
    ];

    // Canonical NFs for reference
    $canonicalNfs  = 0;
    $canonicalMerc = 0.0;
    try {
        $cs = $pdo->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(valor_mercadorias), 0) AS merc
             FROM biomie_notas_fiscais_canonicas
             WHERE COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e
               AND status_fiscal = 'autorizada'"
        );
        $cs->execute(['s' => $startDate, 'e' => $endDate]);
        $cr = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
        $canonicalNfs  = (int) ($cr['n']    ?? 0);
        $canonicalMerc = (float) ($cr['merc'] ?? 0);
    } catch (\Throwable) {}

    // Pipeline job
    $pipelineRepo = null;
    $jobId = 0;
    try {
        $pdo->query("SELECT 1 FROM biomie_pipeline_jobs LIMIT 1");
        $pipelineRepo = new PipelineStatusRepository($pdo, $logger);
        $jobId = $pipelineRepo->startJob('geo_rebuild_period', $startDate, $endDate);
    } catch (\Throwable) {}

    // ── Step 1: mart_cliente_periodo ──────────────────────────────────────────
    $step1Rows  = 0;
    $step1Error = null;
    $step1Comps = [];

    $martBuilder = new CustomerPeriodMartBuilder($pdo, $logger);
    foreach ($competencias as $comp) {
        $compStart = $comp . '-01';
        $compEnd   = date('Y-m-t', strtotime($compStart));
        if ($compEnd > $endDate) {
            $compEnd = $endDate;
        }
        try {
            $rows = $martBuilder->refresh($comp, $compStart, $compEnd);
            $step1Comps[$comp] = ['ok' => true, 'rows' => $rows, 'range' => "{$compStart}..{$compEnd}"];
            $step1Rows += $rows;
        } catch (\Throwable $e) {
            $step1Error = $e->getMessage();
            $step1Comps[$comp] = ['ok' => false, 'error' => $e->getMessage()];
            $logger->error('admin_geo_rebuild: CustomerPeriodMartBuilder falhou.', [
                'competencia' => $comp, 'message' => $e->getMessage(), 'request_id' => RequestContext::id(),
            ]);
            break;
        }
    }

    // ── Step 2: mart_compradores_por_geo ──────────────────────────────────────
    $step2Rows  = 0;
    $step2Error = null;

    if ($step1Error === null) {
        try {
            $geoBuilder = new GeoBuyerMartBuilder($pdo, $logger);
            $step2Rows  = $geoBuilder->refresh($startDate, $endDate);
        } catch (\Throwable $e) {
            $step2Error = $e->getMessage();
            $logger->error('admin_geo_rebuild: GeoBuyerMartBuilder falhou.', [
                'message' => $e->getMessage(), 'request_id' => RequestContext::id(),
            ]);
        }
    }

    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $hasError   = $step1Error !== null || $step2Error !== null;

    // ── After snapshot ────────────────────────────────────────────────────────
    $after = [
        'mart_cliente_periodo_total'      => $countRows('mart_cliente_periodo'),
        'mart_cliente_periodo_period'     => $countPeriod('mart_cliente_periodo', 'competencia', $startComp, $endComp),
        'mart_compradores_por_geo_total'  => $countRows('mart_compradores_por_geo'),
        'mart_compradores_por_geo_period' => $countPeriod('mart_compradores_por_geo', 'competencia', $startComp, $endComp),
    ];

    $geoFat     = 0.0;
    $geoBairros = 0;
    try {
        $gs = $pdo->prepare(
            "SELECT COALESCE(SUM(faturamento), 0) AS fat,
                    COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND bairro_norm != '' AND UPPER(bairro_norm) != 'NAO INFORMADO'
                                        THEN bairro_norm END) AS bairros
             FROM mart_compradores_por_geo WHERE competencia BETWEEN :sc AND :ec"
        );
        $gs->execute(['sc' => $startComp, 'ec' => $endComp]);
        $gr = $gs->fetch(PDO::FETCH_ASSOC) ?: [];
        $geoFat     = (float) ($gr['fat']    ?? 0);
        $geoBairros = (int)   ($gr['bairros'] ?? 0);
    } catch (\Throwable) {}

    // Pipeline job complete
    if ($pipelineRepo && $jobId > 0) {
        if ($hasError) {
            $pipelineRepo->failJob($jobId, $step1Error ?? $step2Error ?? 'unknown');
        } else {
            $pipelineRepo->completeJob($jobId, [
                'mart_cliente_periodo' => $step1Rows,
                'mart_compradores_por_geo' => $step2Rows,
            ], $durationMs / 1000);
        }
    }

    $logger->info('admin_geo_rebuild: concluido.', [
        'start' => $startDate, 'end' => $endDate,
        'step1' => $step1Rows, 'step2' => $step2Rows,
        'faturamento' => $geoFat, 'bairros' => $geoBairros,
        'duration_ms' => $durationMs, 'ok' => !$hasError,
        'request_id' => RequestContext::id(),
        'actor' => Auth::currentUser()['email'] ?? 'unknown',
    ]);

    Http::json([
        'ok'          => !$hasError,
        'request_id'  => RequestContext::id(),
        'period'      => ['start' => $startDate, 'end' => $endDate, 'competencias' => $competencias],
        'duration_ms' => $durationMs,
        'canonical'   => ['nfs_autorizadas' => $canonicalNfs, 'valor_mercadorias' => $canonicalMerc],
        'steps'       => [
            'mart_cliente_periodo'      => ['ok' => $step1Error === null, 'rows' => $step1Rows, 'competencias' => $step1Comps, 'error' => $step1Error],
            'mart_compradores_por_geo'  => ['ok' => $step2Error === null, 'rows' => $step2Rows, 'error' => $step2Error],
        ],
        'before'      => $before,
        'after'       => $after,
        'geo_result'  => ['faturamento' => $geoFat, 'bairros_distintos' => $geoBairros],
        'log'         => ['job_id' => $jobId ?: null, 'pipeline' => $hasError ? 'failed' : 'completed'],
        'message'     => $hasError
            ? 'Rebuild falhou. Verifique os detalhes nos steps.'
            : "Rebuild concluído: {$step1Rows} rows em mart_cliente_periodo, {$step2Rows} em mart_compradores_por_geo, R$" . number_format($geoFat, 2, ',', '.') . " faturamento, {$geoBairros} bairros.",
    ], $hasError ? 500 : 200);

} catch (\Throwable $e) {
    $logger->error('admin_geo_rebuild: erro inesperado.', [
        'message' => $e->getMessage(), 'request_id' => RequestContext::id(),
    ]);
    Http::json([
        'ok'         => false,
        'message'    => 'Erro inesperado ao reprocessar período geo.',
        'request_id' => RequestContext::id(),
    ], 500);
}
