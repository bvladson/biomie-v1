<?php

declare(strict_types=1);

/**
 * COM.R1.2 FASE 4 — Diagnóstico de Canônicos Stale por Etapa
 *
 * Compara a etapa_omie atual no canônico com o histórico order_stage bronze,
 * usa CommercialPipelineClassifier para classificar o estado proposto,
 * e identifica pedidos com snapshot desatualizado (stale).
 *
 * Uso:
 *   php tools/dev/commercial_stage_stale_audit.php --start=YYYY-MM-DD --end=YYYY-MM-DD
 *       [--pretty] [--json] [--sample=30] [--stage=10|20|50|60|70|80]
 *
 * Read-only. Sem UPDATE, INSERT ou DELETE.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\CommercialPipelineClassifier;

$opts        = getopt('', ['start:', 'end:', 'pretty', 'json', 'sample:', 'stage:']);
$start       = (string) ($opts['start']  ?? date('Y-01-01'));
$end         = (string) ($opts['end']    ?? date('Y-m-d'));
$pretty      = isset($opts['pretty']);
$jsonMode    = isset($opts['json']);
$sampleN     = max(1, min(100, (int) ($opts['sample'] ?? 30)));
$filterStage = isset($opts['stage']) ? (string) $opts['stage'] : null;

$t0  = microtime(true);
$clf = new CommercialPipelineClassifier();

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

function tblOk(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $t]);
    return (int) $s->fetchColumn() > 0;
}

$hasBronze   = tblOk($pdo, 'biomie_omie_bronze');
$hasCanon    = tblOk($pdo, 'biomie_pedidos_canonicos');
$hasNfs      = tblOk($pdo, 'biomie_notas_fiscais_canonicas');

// ── SECTION 1 — Overall stage stats ──────────────────────────────────────────
$periodClause = "COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end";
$stageFilter  = $filterStage !== null ? " AND p.etapa_omie = :stage" : '';
$params       = [':start' => $start, ':end' => $end];
if ($filterStage) $params[':stage'] = $filterStage;

$stageStats = [];
if ($hasCanon) {
    $sStats = $pdo->prepare(
        "SELECT p.etapa_omie, p.status_comercial,
                COUNT(DISTINCT p.id) AS total,
                SUM(p.valor_total) AS valor_total,
                COUNT(DISTINCT nf.nota_key) AS nfs_total,
                COUNT(DISTINCT CASE WHEN nf.status_fiscal='autorizada' THEN nf.nota_key END) AS nfs_aut
         FROM biomie_pedidos_canonicos p
         LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_pedido_origem = p.codigo_pedido
         WHERE {$periodClause}{$stageFilter}
         GROUP BY p.etapa_omie, p.status_comercial
         ORDER BY total DESC"
    );
    $sStats->execute($params);
    $stageStats = $sStats->fetchAll(PDO::FETCH_ASSOC);
}

// ── SECTION 2 — Sample and classify + stale detection ────────────────────────
$diagnostics = [];   // all sampled rows with classification
$staleCounts = [];   // classification => count

if ($hasCanon) {
    $sCanon = $pdo->prepare(
        "SELECT p.id, p.pedido_id_omie, p.codigo_pedido, p.etapa_omie,
                p.status_comercial, p.nome_vendedor, p.nome_cliente,
                p.documento_cliente, p.codigo_integracao, p.status_pedido_omie,
                p.data_emissao, p.data_faturamento, p.valor_total,
                p.sincronizado_em,
                nf.numero_nf, nf.status_fiscal AS nf_status,
                nf.data_emissao AS nf_emissao, nf.nota_key AS nf_nota_key
         FROM biomie_pedidos_canonicos p
         LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_pedido_origem = p.codigo_pedido
         WHERE {$periodClause}{$stageFilter}
         ORDER BY p.valor_total DESC
         LIMIT :lim"
    );
    $sCanon->bindValue(':start', $start, PDO::PARAM_STR);
    $sCanon->bindValue(':end',   $end,   PDO::PARAM_STR);
    if ($filterStage) $sCanon->bindValue(':stage', $filterStage, PDO::PARAM_STR);
    $sCanon->bindValue(':lim',   $sampleN, PDO::PARAM_INT);
    $sCanon->execute();
    $canonRows = $sCanon->fetchAll(PDO::FETCH_ASSOC);

    // For each row, get order_stage history from bronze
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
        $etapaAtual = (string) ($r['etapa_omie'] ?? '');
        $hasNf      = !empty($r['nf_nota_key']) && $r['nf_status'] === 'autorizada';
        $hasNfAny   = !empty($r['nf_nota_key']);

        // Get bronze history for this order
        $stageHistory = [];
        if ($sHistory && $r['pedido_id_omie']) {
            $sHistory->execute([':pid' => (string) $r['pedido_id_omie']]);
            $histRows = $sHistory->fetchAll(PDO::FETCH_ASSOC);
            $stageHistory = array_filter(array_unique(array_column($histRows, 'etapa')), fn($v) => $v !== null && $v !== '');
            $stageHistory = array_values($stageHistory);
        }

        // Latest known stage from history
        $latestHistStage = count($stageHistory) > 0 ? $stageHistory[array_key_last($stageHistory)] : null;

        // Classify current canonical stage
        $clsCurrent = $clf->classify([
            'etapa_omie'      => $etapaAtual,
            'status_comercial'=> (string) ($r['status_comercial'] ?? ''),
            'nome_vendedor'   => (string) ($r['nome_vendedor']    ?? ''),
            'has_authorized_nf' => $hasNf,
            'nf_numero'       => (string) ($r['numero_nf']        ?? ''),
            'nf_status'       => (string) ($r['nf_status']        ?? ''),
        ]);

        // Classify proposed (from latest bronze stage if different)
        $proposedStage = $latestHistStage ?? $etapaAtual;
        $clsProposed   = null;
        if ($proposedStage !== $etapaAtual) {
            $clsProposed = $clf->classify([
                'etapa_omie'      => $proposedStage,
                'status_comercial'=> (string) ($r['status_comercial'] ?? ''),
                'nome_vendedor'   => (string) ($r['nome_vendedor']    ?? ''),
                'has_authorized_nf' => $hasNf,
                'nf_numero'       => (string) ($r['numero_nf']        ?? ''),
            ]);
        }

        // Determine stale classification
        $staleClass = 'ok';
        $staleReason = 'etapa_canonica_matches_bronze_history';

        if ($latestHistStage && $latestHistStage !== $etapaAtual) {
            $history = implode('→', $stageHistory);
            if ($etapaAtual === '10' && in_array($latestHistStage, ['50','60'], true)) {
                $staleClass  = 'stale_quote_with_invoice';
                $staleReason = "canônico=10 mas bronze_history=[{$history}] — orçamento que foi faturado";
            } elseif ($etapaAtual === '20' && in_array($latestHistStage, ['50','60'], true)) {
                $staleClass  = 'stale_authorized_to_invoiced';
                $staleReason = "canônico=20 mas bronze_history=[{$history}] — pedido autorizado já faturado";
            } elseif ($etapaAtual === '50' && $latestHistStage === '60') {
                $staleClass  = 'stale_invoiced_to_delivery';
                $staleReason = "canônico=50 mas bronze_history=[{$history}] — faturado avançou para entrega";
            } elseif (in_array($latestHistStage, ['70','80'], true)) {
                if (!empty($r['nf_nota_key'])) {
                    $staleClass  = 'cancelled_after_invoice';
                    $staleReason = "cancelado/devolvido após NF — bronze_history=[{$history}]";
                } else {
                    $staleClass  = 'stale_stage_changed';
                    $staleReason = "etapa canônica diferente da última bronze — [{$history}]";
                }
            } else {
                $staleClass  = 'stale_stage_changed';
                $staleReason = "etapa canônica ({$etapaAtual}) ≠ última bronze ({$latestHistStage}) — [{$history}]";
            }
        } elseif ($etapaAtual === '10' && $hasNfAny) {
            // No bronze history but has NF — converted_after_quote
            $nfDate  = $r['nf_emissao'] ?? '';
            $pedDate = $r['data_emissao'] ?? $r['data_faturamento'] ?? '';
            $staleClass  = 'stale_quote_with_invoice';
            $staleReason = 'orçamento com NF vinculada' . ($nfDate > $pedDate ? ' — NF emitida depois do pedido' : '');
        } elseif ($etapaAtual === '60' && ($r['status_comercial'] ?? '') === 'faturado') {
            $staleClass  = 'etapa_60_mapped_as_faturado';
            $staleReason = 'etapa 60 (Entrega Finalizada) com status_comercial=faturado — divergência COM.R1.2';
        }

        $staleCounts[$staleClass] = ($staleCounts[$staleClass] ?? 0) + 1;

        $diagnostics[] = [
            'pedido_id'        => (int) ($r['pedido_id_omie']  ?? 0),
            'codigo_pedido'    => (string) ($r['codigo_pedido'] ?? ''),
            'cliente'          => mb_substr((string) ($r['nome_cliente'] ?? ''), 0, 35),
            'vendedor'         => mb_substr((string) ($r['nome_vendedor'] ?? ''), 0, 25),
            'valor_total'      => (float) ($r['valor_total'] ?? 0),
            'data_emissao'     => (string) ($r['data_emissao'] ?? ''),
            'data_faturamento' => (string) ($r['data_faturamento'] ?? ''),
            'nf_numero'        => (string) ($r['numero_nf']  ?? ''),
            'nf_status'        => (string) ($r['nf_status']  ?? ''),
            'nf_emissao'       => (string) ($r['nf_emissao'] ?? ''),
            'etapa_canonica'   => $etapaAtual,
            'status_comercial' => (string) ($r['status_comercial'] ?? ''),
            'stage_history'    => $stageHistory,
            'latest_hist_stage'=> $latestHistStage,
            'current_scope'    => $clsCurrent['commercial_scope'],
            'proposed_scope'   => $clsProposed ? $clsProposed['commercial_scope'] : $clsCurrent['commercial_scope'],
            'proposed_etapa'   => $proposedStage,
            'stale_class'      => $staleClass,
            'stale_reason'     => $staleReason,
            'synced_at'        => (string) ($r['sincronizado_em'] ?? ''),
        ];
    }
}

$elapsed = round(microtime(true) - $t0, 2);

// ── JSON ──────────────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'commercial_stage_stale_audit' => [
            'version' => 'COM.R1.2', 'period' => ['start' => $start, 'end' => $end],
            'generated_at' => date('Y-m-d H:i:s'), 'elapsed_s' => $elapsed,
            'stage_stats' => $stageStats, 'stale_counts' => $staleCounts,
            'diagnostics' => $diagnostics,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty ────────────────────────────────────────────────────────────────────
$hr = str_repeat('═', 74);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.2 — Diagnóstico de Canônicos Stale por Etapa' . PHP_EOL;
echo '  Período : ' . $start . ' → ' . $end;
if ($filterStage) echo '  |  Stage: ' . $filterStage;
echo PHP_EOL . $hr . PHP_EOL . PHP_EOL;

// Stage stats
echo str_repeat('─', 74) . PHP_EOL . '  Stats por etapa_omie no período' . PHP_EOL . str_repeat('─', 74) . PHP_EOL . PHP_EOL;
echo '  ' . str_pad('etapa', 8) . str_pad('status_comercial', 18) . str_pad('pedidos', 10) . str_pad('valor_total', 18) . str_pad('NFs', 8) . 'NFs_aut' . PHP_EOL;
echo '  ' . str_repeat('-', 68) . PHP_EOL;
$clf2 = new CommercialPipelineClassifier();
foreach ($stageStats as $r) {
    $entry = $clf2->classifyEtapa((string)($r['etapa_omie'] ?? ''));
    $flag  = '';
    if (($r['etapa_omie'] ?? '') === '60' && ($r['status_comercial'] ?? '') === 'faturado') $flag = ' ← DIVERGENTE';
    echo '  ' . str_pad((string)($r['etapa_omie']??''), 8)
        . str_pad((string)$r['status_comercial'], 18)
        . str_pad(number_format((int)$r['total'], 0, ',', '.'), 10)
        . str_pad('R$ ' . number_format((float)$r['valor_total'], 2, ',', '.'), 18)
        . str_pad(number_format((int)$r['nfs_total'], 0, ',', '.'), 8)
        . number_format((int)$r['nfs_aut'], 0, ',', '.') . $flag . PHP_EOL;
}

// Stale counts
echo PHP_EOL . str_repeat('─', 74) . PHP_EOL . '  Resumo de Classificação de Stale (amostra ' . $sampleN . ' pedidos)' . PHP_EOL . str_repeat('─', 74) . PHP_EOL . PHP_EOL;
foreach ($staleCounts as $cls => $cnt) {
    $mark = $cls === 'ok' ? '✓' : '⚠';
    echo '  ' . $mark . ' ' . str_pad($cls, 38) . $cnt . ' caso(s)' . PHP_EOL;
}

// Detail for non-ok cases
$notOk = array_filter($diagnostics, fn($d) => $d['stale_class'] !== 'ok');
if ($notOk) {
    echo PHP_EOL . str_repeat('─', 74) . PHP_EOL . '  Detalhe dos Casos Problemáticos' . PHP_EOL . str_repeat('─', 74) . PHP_EOL;
    foreach ($notOk as $d) {
        echo PHP_EOL . '  Ped.' . str_pad($d['codigo_pedido'], 10)
            . str_pad(mb_substr($d['vendedor'], 0, 22), 24)
            . 'R$ ' . number_format($d['valor_total'], 2, ',', '.') . PHP_EOL;
        echo '    etapa_canônica=' . $d['etapa_canonica']
            . '  status_comercial=' . $d['status_comercial']
            . '  scope_atual=' . $d['current_scope'] . PHP_EOL;
        if ($d['stage_history']) {
            echo '    bronze_history=[' . implode('→', $d['stage_history']) . ']'
                . '  scope_proposto=' . $d['proposed_scope'] . PHP_EOL;
        }
        if ($d['nf_numero']) {
            echo '    NF=' . $d['nf_numero'] . '[' . $d['nf_status'] . ']@' . $d['nf_emissao'] . PHP_EOL;
        }
        echo '    [' . $d['stale_class'] . '] ' . $d['stale_reason'] . PHP_EOL;
    }
}

// Findings
echo PHP_EOL . str_repeat('─', 74) . PHP_EOL . '  Achados Principais' . PHP_EOL . str_repeat('─', 74) . PHP_EOL . PHP_EOL;
$etapa60Divergent = count(array_filter($diagnostics, fn($d) => $d['etapa_canonica'] === '60' && $d['status_comercial'] === 'faturado'));
$quoteWithNf      = count(array_filter($diagnostics, fn($d) => $d['stale_class'] === 'stale_quote_with_invoice'));
$staleCount       = count(array_filter($diagnostics, fn($d) => $d['stale_class'] !== 'ok'));
echo '  Pedidos com etapa 60 mapeados como faturado (divergência): ' . $etapa60Divergent . PHP_EOL;
echo '  Orçamentos (etapa 10) com NF vinculada:                    ' . $quoteWithNf . PHP_EOL;
echo '  Total com alguma divergência de etapa na amostra:          ' . $staleCount . PHP_EOL;

echo PHP_EOL . $hr . PHP_EOL;
echo '  Concluído em ' . $elapsed . 's' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
