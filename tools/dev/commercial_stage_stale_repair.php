<?php

declare(strict_types=1);

/**
 * COM.R1.2 FASE 5 — Plano de Correção Dry-Run para Canônicos Stale
 *
 * IMPORTANTE: --apply está BLOQUEADO nesta fase (COM.R1.2).
 * Esta ferramenta APENAS mostra o que seria corrigido.
 * Nenhum UPDATE é executado.
 *
 * Uso:
 *   php tools/dev/commercial_stage_stale_repair.php \
 *       --start=YYYY-MM-DD --end=YYYY-MM-DD --dry-run [--pretty] [--json]
 *
 * Quando --apply for desbloqueado em fase futura (COM.R1.3+):
 *   php tools/dev/commercial_stage_stale_repair.php \
 *       --start=... --end=... --apply --reason="motivo explícito"
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\CommercialPipelineClassifier;

$opts     = getopt('', ['start:', 'end:', 'dry-run', 'apply', 'reason:', 'pretty', 'json', 'stage:']);
$start    = (string) ($opts['start']  ?? date('Y-01-01'));
$end      = (string) ($opts['end']    ?? date('Y-m-d'));
$dryRun   = !isset($opts['apply']);  // dry-run é o padrão
$apply    = isset($opts['apply']);
$reason   = isset($opts['reason']) ? trim((string) $opts['reason']) : '';
$pretty   = isset($opts['pretty']);
$jsonMode = isset($opts['json']);
$filterStage = isset($opts['stage']) ? (string) $opts['stage'] : null;

$t0  = microtime(true);
$clf = new CommercialPipelineClassifier();

// ── BLOQUEIO TOTAL DE APPLY nesta fase ────────────────────────────────────────
if ($apply) {
    $msg = 'BLOQUEADO: --apply não está disponível na fase COM.R1.2.' . PHP_EOL
         . 'O apply de correções de etapa requer validação adicional (COM.R1.3+).' . PHP_EOL
         . 'Execute somente com --dry-run para ver o que seria alterado.' . PHP_EOL;
    fwrite(STDERR, $msg);
    exit(1);
}

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function tblR(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $t]);
    return (int) $s->fetchColumn() > 0;
}

$hasBronze = tblR($pdo, 'biomie_omie_bronze');
$hasCanon  = tblR($pdo, 'biomie_pedidos_canonicos');
$hasNfs    = tblR($pdo, 'biomie_notas_fiscais_canonicas');

// ── Fetch candidates ──────────────────────────────────────────────────────────
$periodClause = "COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end";
$stageFilter  = $filterStage !== null ? " AND p.etapa_omie = :stage" : '';
$repairPlans  = [];
$skipped      = [];

if ($hasCanon) {
    $sCanon = $pdo->prepare(
        "SELECT p.id, p.pedido_id_omie, p.codigo_pedido, p.etapa_omie,
                p.status_comercial, p.nome_vendedor, p.nome_cliente,
                p.valor_total, p.data_emissao, p.data_faturamento, p.sincronizado_em,
                nf.numero_nf, nf.status_fiscal AS nf_status, nf.nota_key AS nf_nota_key,
                nf.data_emissao AS nf_emissao
         FROM biomie_pedidos_canonicos p
         LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_pedido_origem = p.codigo_pedido
         WHERE {$periodClause}{$stageFilter}
         ORDER BY p.valor_total DESC
         LIMIT 200"
    );
    $sCanon->bindValue(':start', $start, PDO::PARAM_STR);
    $sCanon->bindValue(':end',   $end,   PDO::PARAM_STR);
    if ($filterStage) $sCanon->bindValue(':stage', $filterStage, PDO::PARAM_STR);
    $sCanon->execute();
    $canonRows = $sCanon->fetchAll(PDO::FETCH_ASSOC);

    $sHistory = null;
    if ($hasBronze) {
        $sHistory = $pdo->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.cEtapa')) AS etapa,
                    b.ingested_at
             FROM biomie_omie_bronze b
             WHERE b.entity_name = 'order_stage'
               AND b.provider_key = :pid
             ORDER BY b.ingested_at ASC
             LIMIT 20"
        );
    }

    foreach ($canonRows as $r) {
        $etapaAtual  = (string) ($r['etapa_omie']      ?? '');
        $statusEnum  = (string) ($r['status_comercial'] ?? '');
        $hasNf       = !empty($r['nf_nota_key']) && $r['nf_status'] === 'autorizada';
        $hasNfAny    = !empty($r['nf_nota_key']);

        // Get bronze history
        $stageHistory = [];
        $latestStage  = null;
        if ($sHistory && $r['pedido_id_omie']) {
            $sHistory->execute([':pid' => (string) $r['pedido_id_omie']]);
            $histRows = $sHistory->fetchAll(PDO::FETCH_ASSOC);
            $stageHistory = array_values(array_filter(array_unique(array_column($histRows, 'etapa')), fn($v) => $v !== null && $v !== ''));
            $latestStage  = count($stageHistory) > 0 ? $stageHistory[array_key_last($stageHistory)] : null;
        }

        // Decide what correction to propose
        $suggestedEtapa    = null;
        $suggestedStatus   = null;
        $fonte             = '';
        $confidence        = 0;
        $motivo            = '';
        $risco             = 'low';
        $fieldsWouldChange = [];

        // Rule 1: etapa 60 as 'faturado' in canonical → should be 'outro'
        if ($etapaAtual === '60' && $statusEnum === 'faturado') {
            $suggestedStatus = 'outro';
            $fonte     = 'etapa_code_60_not_faturado';
            $confidence = 92;
            $motivo    = 'etapa 60 = Entrega Finalizada; status_comercial deve ser "outro", não "faturado"';
            $risco     = 'low';
            $fieldsWouldChange = ['status_comercial' => ['de' => 'faturado', 'para' => 'outro']];
        }

        // Rule 2: stale quote (etapa 10) with authorized NF and bronze history showing faturado
        if ($etapaAtual === '10' && $hasNfAny && $latestStage !== null && in_array($latestStage, ['50','60'], true)) {
            $suggestedEtapa  = $latestStage;
            $suggestedStatus = $latestStage === '50' ? 'faturado' : 'outro';
            $fonte     = 'order_stage_bronze_history';
            $confidence = 85;
            $motivo    = 'orçamento (etapa 10) com NF autorizada; bronze_history=[' . implode('→', $stageHistory) . '] confirma avanço para ' . $latestStage;
            $risco     = 'medium';
            $fieldsWouldChange = [
                'etapa_omie'      => ['de' => '10',          'para' => $suggestedEtapa],
                'status_comercial'=> ['de' => $statusEnum,   'para' => $suggestedStatus],
            ];
        }

        // Rule 3: etapa=10 with NF but no bronze history
        if ($etapaAtual === '10' && $hasNfAny && $latestStage === null) {
            // Conserve — no update without bronze confirmation
            $skipped[] = [
                'codigo_pedido' => $r['codigo_pedido'],
                'etapa'         => '10',
                'motivo'        => 'NF encontrada mas sem bronze history para confirmar — não propor correção automaticamente',
            ];
        }

        // Rule 4: stale authorized (etapa 20) with NF and bronze history → faturado
        if ($etapaAtual === '20' && $hasNfAny && $latestStage !== null && in_array($latestStage, ['50','60'], true)) {
            $suggestedEtapa  = $latestStage;
            $suggestedStatus = $latestStage === '50' ? 'faturado' : 'outro';
            $fonte     = 'order_stage_bronze_history';
            $confidence = 80;
            $motivo    = 'pedido autorizado (etapa 20) com NF; bronze_history=[' . implode('→', $stageHistory) . '] confirma faturamento';
            $risco     = 'medium';
            $fieldsWouldChange = [
                'etapa_omie'      => ['de' => '20',         'para' => $suggestedEtapa],
                'status_comercial'=> ['de' => $statusEnum,  'para' => $suggestedStatus],
            ];
        }

        // Skip if nothing to change
        if ($suggestedEtapa === null && $suggestedStatus === null) {
            continue;
        }

        // Skip conflicting cases (cancelled/devolved with NF — needs manual review)
        if (in_array($statusEnum, ['cancelado'], true) && $hasNfAny && $suggestedStatus !== 'cancelado') {
            $skipped[] = [
                'codigo_pedido' => $r['codigo_pedido'],
                'etapa'         => $etapaAtual,
                'motivo'        => 'cancelado com NF — conflito, requer revisão manual',
            ];
            continue;
        }

        $clsAtual     = $clf->classifyEtapa($etapaAtual);
        $clsProposto  = $clf->classifyEtapa($suggestedEtapa ?? $etapaAtual);

        $repairPlans[] = [
            'pedido_id_omie'    => (int) ($r['pedido_id_omie'] ?? 0),
            'codigo_pedido'     => (string) $r['codigo_pedido'],
            'cliente'           => mb_substr((string) ($r['nome_cliente'] ?? ''), 0, 35),
            'vendedor'          => mb_substr((string) ($r['nome_vendedor'] ?? ''), 0, 25),
            'valor_total'       => (float) ($r['valor_total'] ?? 0),
            'etapa_atual'       => $etapaAtual,
            'status_atual'      => $statusEnum,
            'scope_atual'       => $clsAtual['scope'],
            'etapa_sugerida'    => $suggestedEtapa ?? $etapaAtual,
            'status_sugerido'   => $suggestedStatus ?? $statusEnum,
            'scope_sugerido'    => $clsProposto['scope'],
            'fonte_da_sugestao' => $fonte,
            'stage_history'     => $stageHistory,
            'confidence_score'  => $confidence,
            'motivo'            => $motivo,
            'risco'             => $risco,
            'campos_que_mudariam' => $fieldsWouldChange,
            'nf_numero'         => (string) ($r['numero_nf']  ?? ''),
            'nf_status'         => (string) ($r['nf_status']  ?? ''),
        ];
    }
}

$elapsed = round(microtime(true) - $t0, 2);

// ── JSON ──────────────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'commercial_stage_stale_repair' => [
            'version'    => 'COM.R1.2', 'mode' => 'dry_run_only',
            'apply_blocked' => true, 'apply_available_from' => 'COM.R1.3',
            'period'     => ['start' => $start, 'end' => $end],
            'generated_at' => date('Y-m-d H:i:s'), 'elapsed_s' => $elapsed,
            'repair_plans' => $repairPlans,
            'skipped'      => $skipped,
            'summary' => [
                'total_candidates'     => count($repairPlans),
                'skipped'              => count($skipped),
                'low_risk'             => count(array_filter($repairPlans, fn($p) => $p['risco'] === 'low')),
                'medium_risk'          => count(array_filter($repairPlans, fn($p) => $p['risco'] === 'medium')),
                'high_risk'            => count(array_filter($repairPlans, fn($p) => $p['risco'] === 'high')),
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty ────────────────────────────────────────────────────────────────────
$hr = str_repeat('═', 74);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.2 — Plano de Correção Dry-Run (canônicos stale)' . PHP_EOL;
echo '  Período : ' . $start . ' → ' . $end . PHP_EOL;
echo '  Modo    : DRY-RUN (--apply BLOQUEADO nesta fase)' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

$lowRisk    = array_filter($repairPlans, fn($p) => $p['risco'] === 'low');
$mediumRisk = array_filter($repairPlans, fn($p) => $p['risco'] === 'medium');

echo '  Candidatos a correção : ' . count($repairPlans) . PHP_EOL;
echo '  Baixo risco           : ' . count($lowRisk) . PHP_EOL;
echo '  Médio risco           : ' . count($mediumRisk) . PHP_EOL;
echo '  Ignorados             : ' . count($skipped) . PHP_EOL . PHP_EOL;

if (!$repairPlans) {
    echo '  Nenhum candidato encontrado na amostra.' . PHP_EOL;
} else {
    echo str_repeat('─', 74) . PHP_EOL . '  Plano Detalhado (dry-run apenas)' . PHP_EOL . str_repeat('─', 74) . PHP_EOL;
    foreach ($repairPlans as $p) {
        echo PHP_EOL . '  Ped.' . str_pad($p['codigo_pedido'], 10)
            . str_pad(mb_substr($p['vendedor'], 0, 22), 24)
            . 'R$ ' . number_format($p['valor_total'], 2, ',', '.') . PHP_EOL;
        echo '    ATUAL    : etapa=' . $p['etapa_atual'] . '  status=' . $p['status_atual'] . '  scope=' . $p['scope_atual'] . PHP_EOL;
        echo '    PROPOSTO : etapa=' . $p['etapa_sugerida'] . '  status=' . $p['status_sugerido'] . '  scope=' . $p['scope_sugerido'] . PHP_EOL;
        echo '    fonte    : ' . $p['fonte_da_sugestao'] . '  |  confiança=' . $p['confidence_score'] . '/100  |  risco=' . $p['risco'] . PHP_EOL;
        if ($p['stage_history']) {
            echo '    history  : [' . implode('→', $p['stage_history']) . ']' . PHP_EOL;
        }
        if ($p['nf_numero']) {
            echo '    NF       : ' . $p['nf_numero'] . '[' . $p['nf_status'] . ']' . PHP_EOL;
        }
        foreach ($p['campos_que_mudariam'] as $campo => $change) {
            echo '    campo    : ' . $campo . ': "' . $change['de'] . '" → "' . $change['para'] . '"' . PHP_EOL;
        }
        echo '    motivo   : ' . $p['motivo'] . PHP_EOL;
    }
}

if ($skipped) {
    echo PHP_EOL . str_repeat('─', 74) . PHP_EOL . '  Ignorados (requerem revisão manual)' . PHP_EOL . str_repeat('─', 74) . PHP_EOL;
    foreach ($skipped as $s) {
        echo '  Ped.' . $s['codigo_pedido'] . ' etapa=' . $s['etapa'] . ': ' . $s['motivo'] . PHP_EOL;
    }
}

echo PHP_EOL;
echo '  ┌────────────────────────────────────────────────────────────────────────┐' . PHP_EOL;
echo '  │ --apply está BLOQUEADO nesta fase (COM.R1.2).                         │' . PHP_EOL;
echo '  │ Nenhuma linha do banco foi alterada.                                  │' . PHP_EOL;
echo '  │ O apply estará disponível em COM.R1.3+ com --reason obrigatório.      │' . PHP_EOL;
echo '  └────────────────────────────────────────────────────────────────────────┘' . PHP_EOL;

echo PHP_EOL . $hr . PHP_EOL;
echo '  Concluído em ' . $elapsed . 's' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
