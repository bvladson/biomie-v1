<?php

declare(strict_types=1);

/**
 * COM.R1 FASE 0 — Diagnóstico de Etapas Omie e Semântica Comercial
 *
 * Audita todas as etapas/status encontrados em biomie_pedidos_canonicos e
 * classifica cada combinação (etapa_omie + codigo_integracao + status_comercial)
 * nos escopos BIomie:
 *
 *   orcamento          — proposta/intenção, não entra no realizado
 *   carteira_varejo    — pedido Varejo aceito, aguardando faturamento
 *   carteira_revenda   — pedido Revenda aceito, aguardando faturamento
 *   carteira_para_faturar — pronto para NF, genérico
 *   faturado           — NF autorizada emitida
 *   entrega_finalizada — pós-venda, status operacional
 *   remessa            — remessa de produto / transferência (não é venda)
 *   licitacao          — escopo separado (excluído do Orç-PD)
 *   desconhecido       — padrão não reconhecido
 *
 * Uso:
 *   php tools/dev/commercial_stage_semantics_audit.php \
 *       [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] \
 *       [--pretty] [--json] [--sample=30]
 *
 * Read-only. Sem alterações em schema, dados ou configuração.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Database;
use BIomie\Config;

// ── CLI args ───────────────────────────────────────────────────────────────────
$opts    = getopt('', ['start:', 'end:', 'pretty', 'json', 'sample:']);
$start   = $opts['start']  ?? null;
$end     = $opts['end']    ?? null;
$pretty  = isset($opts['pretty']);
$jsonMode = isset($opts['json']);
$sampleN = max(1, min(100, (int) ($opts['sample'] ?? 10)));

$t0 = microtime(true);
$today = date('Y-m-d');
if (!$start) $start = date('Y-01-01');
if (!$end)   $end   = $today;

// ── Helpers ───────────────────────────────────────────────────────────────────
function sep(string $title = ''): void {
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    if ($title !== '') echo "  {$title}" . PHP_EOL . str_repeat('─', 72) . PHP_EOL;
}

function row(string $label, mixed $value, int $w = 36): void {
    echo '  ' . str_pad($label . ':', $w) . $value . PHP_EOL;
}

function fmtN(int $n): string { return number_format($n, 0, ',', '.'); }
function fmtR(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function fmtPct(float $v): string { return number_format($v, 1, ',', '.') . '%'; }

// ── Omie numeric stage code lookup ────────────────────────────────────────────
// Omie returns numeric codes in cabecalho.etapa.
// These are the known standard ranges (may vary per Omie subscription).
const ETAPA_NUMERIC_MAP = [
    // code => [label, biomie_scope, confidence, note]
    '10' => ['Orçamento',            'orcamento',             90, 'standard Omie: proposta/intenção'],
    '20' => ['Pedido / Autorizado',  'carteira_aceita',       85, 'standard Omie: pedido aceito, aguardando faturamento'],
    '30' => ['Em Produção',          'carteira_para_faturar', 80, 'standard Omie: em separação/produção'],
    '40' => ['Pronto p/ Entrega',    'carteira_para_faturar', 80, 'standard Omie: pronto para faturar/entregar'],
    '50' => ['Faturado',             'faturado',              92, 'standard Omie: NF emitida'],
    '60' => ['Entrega Finalizada',   'entrega_finalizada',    88, 'standard Omie: entrega concluída — BIomie atual mapeia como faturado (DIVERGÊNCIA)'],
    '70' => ['Cancelado',            'cancelado',             92, 'standard Omie: pedido cancelado'],
    '80' => ['Devolvido',            'devolvido',             88, 'standard Omie: pedido devolvido'],
];

/**
 * Resolve the human-readable label for an etapa_omie value.
 */
function etapaLabel(string $etapaOmie): string {
    if (isset(ETAPA_NUMERIC_MAP[$etapaOmie])) {
        return ETAPA_NUMERIC_MAP[$etapaOmie][0];
    }
    // Handle numeric ranges per classifyStatus logic
    if (ctype_digit($etapaOmie) && $etapaOmie !== '') {
        $code = (int) $etapaOmie;
        if ($code >= 50 && $code <= 69) return "Faturado/Entregue (código {$etapaOmie})";
        if ($code >= 20 && $code <= 49) return "Pedido Autorizado (código {$etapaOmie})";
        if ($code >= 70) return "Cancelado/Devolvido (código {$etapaOmie})";
        if ($code <= 19) return "Orçamento (código {$etapaOmie})";
    }
    return $etapaOmie ?: '(vazio)';
}

// ── Scope classification ───────────────────────────────────────────────────────
/**
 * Classify a stage combination into a BIomie semantic scope.
 * Returns: ['scope' => string, 'confidence' => int, 'reason' => string, 'label' => string]
 */
function classifyStage(
    string $etapaOmie,
    string $statusPedidoOmie,
    string $codigoIntegracao,
    string $statusComercial
): array {
    $etapa  = mb_strtolower(trim($etapaOmie));
    $status = mb_strtolower(trim($statusPedidoOmie));
    $integ  = mb_strtolower(trim($codigoIntegracao));

    // Helper
    $has = function (string $haystack, array $needles): bool {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $n)) return true;
        }
        return false;
    };

    // ── Numeric code — primary classification path
    if (ctype_digit($etapaOmie) && $etapaOmie !== '') {
        if (isset(ETAPA_NUMERIC_MAP[$etapaOmie])) {
            $entry = ETAPA_NUMERIC_MAP[$etapaOmie];
            return ['scope' => $entry[1], 'confidence' => $entry[2], 'reason' => 'numeric_etapa_exact_match:' . $etapaOmie, 'label' => $entry[0]];
        }
        $code = (int) $etapaOmie;
        if ($code >= 50 && $code <= 69) {
            return ['scope' => 'faturado', 'confidence' => 80, 'reason' => 'numeric_etapa_range_50_69', 'label' => "Faturado/Entregue (código {$etapaOmie})"];
        }
        if ($code >= 20 && $code <= 49) {
            return ['scope' => 'carteira_aceita', 'confidence' => 78, 'reason' => 'numeric_etapa_range_20_49', 'label' => "Pedido Autorizado (código {$etapaOmie})"];
        }
        if ($code >= 70) {
            return ['scope' => 'cancelado', 'confidence' => 85, 'reason' => 'numeric_etapa_gte_70', 'label' => "Cancelado/Devolvido (código {$etapaOmie})"];
        }
        if ($code <= 19) {
            return ['scope' => 'orcamento', 'confidence' => 82, 'reason' => 'numeric_etapa_lte_19', 'label' => "Orçamento (código {$etapaOmie})"];
        }
    }

    // ── Text-based classification (fallback for non-numeric etapa values)

    // Licitação
    $licitPat = ['licitac', 'licitaç'];
    if ($has($etapa, $licitPat) || $has($status, $licitPat) || $has($integ, $licitPat)) {
        return ['scope' => 'licitacao', 'confidence' => 95, 'reason' => 'licitacao_text_pattern', 'label' => 'Licitação'];
    }

    // Remessa
    $remessaPat = ['remessa', 'simples remessa', 'transferência', 'transferencia', 'devolução', 'devolucao'];
    if ($has($integ, $remessaPat) || $has($etapa, $remessaPat)) {
        return ['scope' => 'remessa', 'confidence' => 90, 'reason' => 'remessa_text_pattern', 'label' => 'Remessa/Transferência'];
    }

    // Entrega Finalizada
    $entregaPat = ['entrega finalizada', 'entrega concluida', 'entrega concluída', 'finalizada', 'finalizado', 'entregue'];
    if ($has($etapa, $entregaPat) || $has($status, $entregaPat)) {
        return ['scope' => 'entrega_finalizada', 'confidence' => 88, 'reason' => 'entrega_finalizada_text_pattern', 'label' => 'Entrega Finalizada'];
    }

    // Faturado
    if ($has($etapa, ['faturado', 'faturada']) || $statusComercial === 'faturado') {
        return ['scope' => 'faturado', 'confidence' => 88, 'reason' => 'faturado_text_pattern_or_status_comercial', 'label' => 'Faturado'];
    }

    // Para Faturar
    if ($has($etapa, ['para faturar', 'aguardando faturamento', 'aguardando nf'])) {
        return ['scope' => 'carteira_para_faturar', 'confidence' => 85, 'reason' => 'para_faturar_text_pattern', 'label' => 'Para Faturar'];
    }

    // Revenda
    if ($has($etapa, ['revenda']) || $has($integ, ['revenda'])) {
        return ['scope' => 'carteira_revenda', 'confidence' => 85, 'reason' => 'revenda_text_pattern', 'label' => 'Pedidos Revenda'];
    }

    // Varejo
    if ($has($etapa, ['varejo']) || $has($integ, ['varejo'])) {
        return ['scope' => 'carteira_varejo', 'confidence' => 85, 'reason' => 'varejo_text_pattern', 'label' => 'Pedidos Varejo'];
    }

    // Autorizado/Aprovado
    if ($has($etapa, ['aprovado', 'autorizado', 'aceito']) || $statusComercial === 'autorizado') {
        return ['scope' => 'carteira_aceita', 'confidence' => 75, 'reason' => 'aprovado_text_pattern_or_status_comercial', 'label' => 'Pedido Autorizado'];
    }

    // Cancelado
    if ($has($etapa, ['cancelado', 'devolvido', 'recusado', 'denegado', 'rejeitado']) || $statusComercial === 'cancelado') {
        return ['scope' => 'cancelado', 'confidence' => 85, 'reason' => 'cancelado_text_pattern_or_status_comercial', 'label' => 'Cancelado'];
    }

    // Orçamento
    if ($has($etapa, ['orçamento', 'orcamento', 'proposta', 'cotação'])) {
        return ['scope' => 'orcamento', 'confidence' => 85, 'reason' => 'orcamento_text_pattern', 'label' => 'Orçamento'];
    }

    return ['scope' => 'desconhecido', 'confidence' => 0, 'reason' => 'no_pattern_matched', 'label' => '(desconhecido)'];
}

// ── Database ───────────────────────────────────────────────────────────────────
try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        fwrite(STDERR, 'ERROR: Banco de dados não configurado.' . PHP_EOL);
        exit(1);
    }
    $pdo = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ── Date filter clause ─────────────────────────────────────────────────────────
// Use COALESCE(data_faturamento, data_emissao) as the period anchor — same as overview
$periodClause = "COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end";

// ── SECTION A — Distinct field values ─────────────────────────────────────────
$secA = [];

// A1: distinct etapa_omie with count
$rowsA1 = $pdo->prepare(
    "SELECT COALESCE(p.etapa_omie,'') AS v, COUNT(*) AS cnt
     FROM biomie_pedidos_canonicos p
     WHERE {$periodClause}
     GROUP BY v ORDER BY cnt DESC LIMIT 60"
);
$rowsA1->execute([':start' => $start, ':end' => $end]);
$secA['etapa_omie_distinct'] = $rowsA1->fetchAll(PDO::FETCH_ASSOC);

// A2: distinct status_pedido_omie with count
$rowsA2 = $pdo->prepare(
    "SELECT COALESCE(p.status_pedido_omie,'') AS v, COUNT(*) AS cnt
     FROM biomie_pedidos_canonicos p
     WHERE {$periodClause}
     GROUP BY v ORDER BY cnt DESC LIMIT 60"
);
$rowsA2->execute([':start' => $start, ':end' => $end]);
$secA['status_pedido_omie_distinct'] = $rowsA2->fetchAll(PDO::FETCH_ASSOC);

// A3: distinct codigo_integracao with count
$rowsA3 = $pdo->prepare(
    "SELECT COALESCE(p.codigo_integracao,'') AS v, COUNT(*) AS cnt
     FROM biomie_pedidos_canonicos p
     WHERE {$periodClause}
     GROUP BY v ORDER BY cnt DESC LIMIT 40"
);
$rowsA3->execute([':start' => $start, ':end' => $end]);
$secA['codigo_integracao_distinct'] = $rowsA3->fetchAll(PDO::FETCH_ASSOC);

// A4: distinct status_comercial
$rowsA4 = $pdo->prepare(
    "SELECT COALESCE(p.status_comercial,'') AS v, COUNT(*) AS cnt
     FROM biomie_pedidos_canonicos p
     WHERE {$periodClause}
     GROUP BY v ORDER BY cnt DESC"
);
$rowsA4->execute([':start' => $start, ':end' => $end]);
$secA['status_comercial_distinct'] = $rowsA4->fetchAll(PDO::FETCH_ASSOC);

// ── SECTION B — Stage aggregation ─────────────────────────────────────────────
$rowsB = $pdo->prepare(
    "SELECT
         COALESCE(p.etapa_omie,'')          AS etapa_omie,
         COALESCE(p.status_pedido_omie,'')  AS status_pedido_omie,
         COALESCE(p.codigo_integracao,'')   AS codigo_integracao,
         COALESCE(p.status_comercial,'')    AS status_comercial,
         COUNT(DISTINCT p.id)               AS total_pedidos,
         SUM(p.valor_total)                 AS valor_total,
         SUM(p.valor_mercadorias)           AS valor_mercadorias,
         COUNT(DISTINCT p.codigo_vendedor_omie) AS qtd_vendedores,
         COUNT(DISTINCT p.codigo_cliente_omie)  AS qtd_clientes,
         COUNT(DISTINCT nf.nota_key)             AS total_nfs,
         COUNT(DISTINCT CASE WHEN nf.status_fiscal = 'autorizada' THEN nf.nota_key END) AS nfs_autorizadas,
         COUNT(DISTINCT CASE WHEN nf.nota_key IS NULL THEN p.id END)                    AS pedidos_sem_nf
     FROM biomie_pedidos_canonicos p
     LEFT JOIN biomie_notas_fiscais_canonicas nf
           ON nf.codigo_pedido_origem = p.codigo_pedido
     WHERE {$periodClause}
     GROUP BY p.etapa_omie, p.status_pedido_omie, p.codigo_integracao, p.status_comercial
     ORDER BY total_pedidos DESC
     LIMIT 80"
);
$rowsB->execute([':start' => $start, ':end' => $end]);
$stageGroups = $rowsB->fetchAll(PDO::FETCH_ASSOC);

// ── SECTION C — Classify + build results ──────────────────────────────────────
$classified = [];
foreach ($stageGroups as $g) {
    $cls = classifyStage(
        $g['etapa_omie'],
        $g['status_pedido_omie'],
        $g['codigo_integracao'],
        $g['status_comercial']
    );

    $totalPed  = (int)   $g['total_pedidos'];
    $valorTotal = (float) $g['valor_total'];
    $totalNfs  = (int)   $g['total_nfs'];
    $nfsAut    = (int)   $g['nfs_autorizadas'];
    $semNf     = (int)   $g['pedidos_sem_nf'];

    $classified[] = [
        // Stage keys
        'stage_code'        => $g['etapa_omie'] ?: $g['status_pedido_omie'] ?: '(vazio)',
        'stage_label'       => trim(implode(' / ', array_filter([
            $g['codigo_integracao'],
            etapaLabel($g['etapa_omie']),
        ]))) ?: '(sem etapa)',
        'etapa_label'       => etapaLabel($g['etapa_omie']),
        'cls_label'         => $cls['label'] ?? $cls['scope'],
        'etapa_omie'        => $g['etapa_omie'],
        'status_pedido_omie'=> $g['status_pedido_omie'],
        'codigo_integracao' => $g['codigo_integracao'],
        'status_comercial'  => $g['status_comercial'],
        // Metrics
        'total_pedidos'     => $totalPed,
        'valor_total'       => $valorTotal,
        'valor_mercadorias' => (float) $g['valor_mercadorias'],
        'qtd_vendedores'    => (int) $g['qtd_vendedores'],
        'qtd_clientes'      => (int) $g['qtd_clientes'],
        'total_nfs'         => $totalNfs,
        'nfs_autorizadas'   => $nfsAut,
        'pedidos_sem_nf'    => $semNf,
        'pct_com_nf'        => $totalPed > 0 ? round($totalNfs / $totalPed * 100, 1) : 0.0,
        // Classification
        'detected_scope'    => $cls['scope'],
        'confidence_score'  => $cls['confidence'],
        'reason'            => $cls['reason'],
    ];
}

// ── SECTION D — Samples per stage ─────────────────────────────────────────────
$samples = [];
$stmtSample = $pdo->prepare(
    "SELECT
         p.codigo_pedido,
         p.pedido_id_omie,
         p.nome_vendedor,
         p.nome_cliente,
         COALESCE(p.data_faturamento, p.data_emissao) AS data_ref,
         p.valor_total,
         p.etapa_omie,
         p.status_pedido_omie,
         p.codigo_integracao,
         p.status_comercial,
         nf.numero_nf,
         nf.status_fiscal AS nf_status
     FROM biomie_pedidos_canonicos p
     LEFT JOIN biomie_notas_fiscais_canonicas nf
           ON nf.codigo_pedido_origem = p.codigo_pedido
     WHERE {$periodClause}
       AND COALESCE(p.etapa_omie,'') = :etapa
       AND COALESCE(p.codigo_integracao,'') = :integ
     ORDER BY p.valor_total DESC
     LIMIT :lim"
);

$seenStageKeys = [];
foreach ($classified as $grp) {
    $stageKey = $grp['etapa_omie'] . '|' . $grp['codigo_integracao'];
    if (isset($seenStageKeys[$stageKey])) continue;
    $seenStageKeys[$stageKey] = true;

    $stmtSample->bindValue(':start',  $start,              PDO::PARAM_STR);
    $stmtSample->bindValue(':end',    $end,                PDO::PARAM_STR);
    $stmtSample->bindValue(':etapa',  $grp['etapa_omie'],  PDO::PARAM_STR);
    $stmtSample->bindValue(':integ',  $grp['codigo_integracao'], PDO::PARAM_STR);
    $stmtSample->bindValue(':lim',    $sampleN,            PDO::PARAM_INT);
    $stmtSample->execute();
    $sRows = $stmtSample->fetchAll(PDO::FETCH_ASSOC);
    if ($sRows) {
        $samples[$stageKey] = $sRows;
    }
}

// ── SECTION E — Scope summary ──────────────────────────────────────────────────
$scopeSummary = [];
foreach ($classified as $c) {
    $sc = $c['detected_scope'];
    if (!isset($scopeSummary[$sc])) {
        $scopeSummary[$sc] = ['pedidos' => 0, 'valor' => 0.0, 'nfs' => 0, 'sem_nf' => 0, 'stage_count' => 0];
    }
    $scopeSummary[$sc]['pedidos']      += $c['total_pedidos'];
    $scopeSummary[$sc]['valor']        += $c['valor_total'];
    $scopeSummary[$sc]['nfs']          += $c['total_nfs'];
    $scopeSummary[$sc]['sem_nf']       += $c['pedidos_sem_nf'];
    $scopeSummary[$sc]['stage_count']  += 1;
}

// ── Recommendations ───────────────────────────────────────────────────────────
$recommendations = [];

$unknownCount = $scopeSummary['desconhecido']['stage_count'] ?? 0;
if ($unknownCount > 0) {
    $recommendations[] = [
        'id'   => 'COM_R1_UNKNOWN_STAGES',
        'msg'  => "{$unknownCount} combinação(ões) de etapa não reconhecida(s) — revisar padrões de classificação ou mapear etapas customizadas do Omie.",
    ];
}

$hasRemessa = isset($scopeSummary['remessa']) && $scopeSummary['remessa']['pedidos'] > 0;
if ($hasRemessa) {
    $recommendations[] = [
        'id'  => 'COM_R1_REMESSA_DETECTED',
        'msg' => 'Remessas detectadas — confirmar que estão excluídas do realizado comercial e do cálculo de metas.',
    ];
}

$hasLicitacao = isset($scopeSummary['licitacao']) && $scopeSummary['licitacao']['pedidos'] > 0;
if ($hasLicitacao) {
    $recommendations[] = [
        'id'  => 'COM_R1_LICITACAO_DETECTED',
        'msg' => 'Licitação detectada como etapa explícita — confirmar que BiVisibilityScope exclui corretamente no Orç-PD.',
    ];
}

$carteiraSemNf = ($scopeSummary['carteira_varejo']['sem_nf'] ?? 0)
    + ($scopeSummary['carteira_revenda']['sem_nf'] ?? 0)
    + ($scopeSummary['carteira_para_faturar']['sem_nf'] ?? 0);
if ($carteiraSemNf > 0) {
    $recommendations[] = [
        'id'  => 'COM_R1_CARTEIRA_SEM_NF',
        'msg' => fmtN($carteiraSemNf) . ' pedido(s) em carteira sem NF vinculada — dado correto (aguardando faturamento).',
    ];
}

$faturadoSemNf = $scopeSummary['faturado']['sem_nf'] ?? 0;
if ($faturadoSemNf > 0) {
    $recommendations[] = [
        'id'  => 'COM_R1_FATURADO_SEM_NF',
        'msg' => fmtN($faturadoSemNf) . ' pedido(s) com etapa "Faturado" mas sem NF vinculada — investigar gap de sincronização.',
        'severity' => 'WARN',
    ];
}

// Check for entrega_finalizada conflated with faturado
$etapa60Count = 0;
foreach ($classified as $c) {
    if ($c['etapa_omie'] === '60') $etapa60Count += $c['total_pedidos'];
}
if ($etapa60Count > 0) {
    $recommendations[] = [
        'id'  => 'COM_R1_ETAPA60_CONFLATION',
        'msg' => fmtN($etapa60Count) . ' pedido(s) na etapa 60 (Entrega Finalizada) atualmente tratados como "faturado" em BIomie. '
            . 'Proposta COM.R1: diferenciar etapa 60 para não duplicar NFs faturadas na carteira.',
        'severity' => 'WARN',
    ];
}

// Numeric codes detected — etapa_omie is numeric (not text)
$hasNumericEtapas = false;
foreach ($secA['etapa_omie_distinct'] as $r) {
    if ($r['v'] !== '' && ctype_digit($r['v'])) { $hasNumericEtapas = true; break; }
}
if ($hasNumericEtapas) {
    $recommendations[] = [
        'id'  => 'COM_R1_NUMERIC_ETAPA_CODES',
        'msg' => 'etapa_omie armazena CÓDIGOS NUMÉRICOS (10/20/50/60/70/80), não rótulos textuais. '
            . 'As colunas "Pedidos Varejo" e "Pedidos Revenda" do Kanban Omie não estão capturadas neste campo. '
            . 'Recomendação: verificar se existe campo separado de subtipo/categoria de pedido (tipo_pedido, canal_venda) '
            . 'no payload bronze para distinguir Varejo vs Revenda dentro do etapa 20.',
        'severity' => 'INFO',
    ];
}

// status_pedido_omie is empty
$statusPedEmpty = count(array_filter($secA['status_pedido_omie_distinct'], fn($r) => $r['v'] !== '')) === 0;
if ($statusPedEmpty) {
    $recommendations[] = [
        'id'  => 'COM_R1_STATUS_PEDIDO_EMPTY',
        'msg' => 'status_pedido_omie está vazio para todos os pedidos do período. '
            . 'Este campo não está sendo populado pelo sync atual — o sync usa apenas cabecalho.etapa (numérico).',
        'severity' => 'INFO',
    ];
}

// codigo_integracao mostly empty
$integrationEmpty = ($secA['codigo_integracao_distinct'][0]['v'] ?? '') === '' &&
    (int)($secA['codigo_integracao_distinct'][0]['cnt'] ?? 0) > 400;
if ($integrationEmpty) {
    $recommendations[] = [
        'id'  => 'COM_R1_INTEGRACAO_EMPTY',
        'msg' => 'codigo_integracao está vazio para a maioria dos pedidos. '
            . 'Apenas leads do Kommo têm este campo populado. '
            . 'A operação "Orçamento - PD" não é inferida deste campo — vem de OmieOperationScope por outros critérios.',
        'severity' => 'INFO',
    ];
}

$totalPedidos = array_sum(array_column($classified, 'total_pedidos'));
$totalValor   = array_sum(array_column($classified, 'valor_total'));

// ── Elapsed ────────────────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $t0, 2);

// ── JSON output ────────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'commercial_stage_semantics_audit' => [
            'version'         => 'COM.R1-FASE0',
            'period'          => ['start' => $start, 'end' => $end],
            'generated_at'    => date('Y-m-d H:i:s'),
            'elapsed_s'       => $elapsed,
            'total_pedidos'   => $totalPedidos,
            'total_valor'     => round($totalValor, 2),
            'scope_summary'   => $scopeSummary,
            'classified_stages' => $classified,
            'field_inventory' => $secA,
            'recommendations' => $recommendations,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty / text output ───────────────────────────────────────────────────────
$hr = str_repeat('═', 72);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1 FASE 0 — Diagnóstico de Etapas Omie e Semântica Comercial' . PHP_EOL;
echo '  Período : ' . $start . ' → ' . $end . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

// ── A — Campos disponíveis ────────────────────────────────────────────────────
sep('A — Campos disponíveis (valores distintos no período)');

echo PHP_EOL . '  A1 — etapa_omie (' . count($secA['etapa_omie_distinct']) . ' valores) — CÓDIGO NUMÉRICO OMIE' . PHP_EOL;
echo '       Mapeamento padrão: 10=Orçamento  20=Pedido/Autorizado  50=Faturado  60=Entregue  70=Cancelado  80=Devolvido' . PHP_EOL . PHP_EOL;
foreach ($secA['etapa_omie_distinct'] as $r) {
    $code  = $r['v'] !== '' ? $r['v'] : '(vazio)';
    $label = etapaLabel($r['v']);
    echo '    código ' . str_pad($code, 6) . str_pad($label, 30) . fmtN((int)$r['cnt']) . ' pedidos' . PHP_EOL;
}

echo PHP_EOL . '  A2 — status_pedido_omie (' . count($secA['status_pedido_omie_distinct']) . ' valores)' . PHP_EOL;
foreach ($secA['status_pedido_omie_distinct'] as $r) {
    $lbl = $r['v'] !== '' ? $r['v'] : '(vazio)';
    echo '    ' . str_pad($lbl, 38) . fmtN((int)$r['cnt']) . ' pedidos' . PHP_EOL;
}

echo PHP_EOL . '  A3 — codigo_integracao (' . count($secA['codigo_integracao_distinct']) . ' valores)' . PHP_EOL;
foreach ($secA['codigo_integracao_distinct'] as $r) {
    $lbl = $r['v'] !== '' ? $r['v'] : '(vazio)';
    echo '    ' . str_pad($lbl, 38) . fmtN((int)$r['cnt']) . ' pedidos' . PHP_EOL;
}

echo PHP_EOL . '  A4 — status_comercial (' . count($secA['status_comercial_distinct']) . ' valores)' . PHP_EOL;
foreach ($secA['status_comercial_distinct'] as $r) {
    $lbl = $r['v'] !== '' ? $r['v'] : '(vazio)';
    echo '    ' . str_pad($lbl, 38) . fmtN((int)$r['cnt']) . ' pedidos' . PHP_EOL;
}

// ── B — Scope summary ─────────────────────────────────────────────────────────
sep('B — Resumo por Escopo BIomie');

$SCOPE_LABELS = [
    'orcamento'             => 'Orçamento / proposta',
    'carteira_aceita'       => 'Carteira Aceita (etapa 20 — Varejo+Revenda indiferenciados)',
    'carteira_varejo'       => 'Carteira Varejo',
    'carteira_revenda'      => 'Carteira Revenda',
    'carteira_para_faturar' => 'Carteira — Para Faturar',
    'faturado'              => 'Faturado (NF autorizada)',
    'entrega_finalizada'    => 'Entrega Finalizada (etapa 60)',
    'cancelado'             => 'Cancelado / Devolvido',
    'remessa'               => 'Remessa / Transferência',
    'licitacao'             => 'Licitação',
    'desconhecido'          => 'Desconhecido',
];

$ORDER_SCOPES = ['faturado','entrega_finalizada','carteira_aceita','carteira_para_faturar',
    'carteira_varejo','carteira_revenda','orcamento','cancelado','remessa','licitacao','desconhecido'];

foreach ($ORDER_SCOPES as $sc) {
    if (!isset($scopeSummary[$sc])) continue;
    $s = $scopeSummary[$sc];
    $label = $SCOPE_LABELS[$sc] ?? $sc;
    echo PHP_EOL . '  ■ ' . strtoupper($sc) . ' — ' . $label . PHP_EOL;
    row('    Pedidos', fmtN($s['pedidos']));
    row('    Valor total', fmtR($s['valor']));
    row('    NFs vinculadas', fmtN($s['nfs']));
    row('    Pedidos sem NF', fmtN($s['sem_nf']));
    row('    Grupos de etapa', (string)$s['stage_count']);
}

// ── C — Detailed stage classification ────────────────────────────────────────
sep('C — Classificação Semântica por Combinação de Etapa');
echo PHP_EOL;

foreach ($classified as $c) {
    $confBar = str_repeat('█', (int)($c['confidence_score'] / 10)) . str_repeat('░', 10 - (int)($c['confidence_score'] / 10));
    echo '  ─ ' . $c['stage_label'] . PHP_EOL;
    echo '    detected_scope    : ' . strtoupper($c['detected_scope']) . PHP_EOL;
    echo '    confidence        : ' . $c['confidence_score'] . '/100 [' . $confBar . ']' . PHP_EOL;
    echo '    reason            : ' . $c['reason'] . PHP_EOL;
    echo '    etapa_omie        : ' . ($c['etapa_omie'] ?: '(vazio)') . PHP_EOL;
    echo '    status_pedido     : ' . ($c['status_pedido_omie'] ?: '(vazio)') . PHP_EOL;
    echo '    codigo_integracao : ' . ($c['codigo_integracao'] ?: '(vazio)') . PHP_EOL;
    echo '    status_comercial  : ' . ($c['status_comercial'] ?: '(vazio)') . PHP_EOL;
    echo '    pedidos           : ' . fmtN($c['total_pedidos']) . '   valor: ' . fmtR($c['valor_total']) . PHP_EOL;
    echo '    NFs               : ' . fmtN($c['total_nfs']) . ' total / ' . fmtN($c['nfs_autorizadas']) . ' autorizadas / ' . fmtN($c['pedidos_sem_nf']) . ' sem NF (' . fmtPct($c['pct_com_nf']) . ' cobertura)' . PHP_EOL;
    echo '    vendedores        : ' . $c['qtd_vendedores'] . '   clientes: ' . $c['qtd_clientes'] . PHP_EOL;
    echo PHP_EOL;
}

// ── D — Amostras ──────────────────────────────────────────────────────────────
if ($sampleN > 0) {
    sep("D — Amostras de Pedidos por Etapa (top {$sampleN} por valor)");
    foreach ($classified as $c) {
        $stageKey = $c['etapa_omie'] . '|' . $c['codigo_integracao'];
        $rows = $samples[$stageKey] ?? [];
        if (!$rows) continue;

        echo PHP_EOL . '  ■ ' . $c['stage_label'] . ' → ' . strtoupper($c['detected_scope']) . PHP_EOL;
        foreach (array_slice($rows, 0, $sampleN) as $s) {
            $nfInfo = $s['numero_nf'] ? ('NF ' . $s['numero_nf'] . ' [' . ($s['nf_status'] ?? '?') . ']') : 'sem NF';
            echo '    Ped.' . str_pad((string)$s['codigo_pedido'], 12)
                . str_pad($s['nome_vendedor'] ?? '', 22)
                . str_pad(mb_substr($s['nome_cliente'] ?? '', 0, 22), 24)
                . str_pad(fmtR((float)$s['valor_total']), 18)
                . str_pad($s['data_ref'] ?? '', 12)
                . $nfInfo . PHP_EOL;
        }
    }
}

// ── E — Recomendações ─────────────────────────────────────────────────────────
sep('E — Recomendações');
if (!$recommendations) {
    echo PHP_EOL . '  Nenhuma pendência crítica detectada.' . PHP_EOL;
} else {
    echo PHP_EOL;
    foreach ($recommendations as $rec) {
        $sev = $rec['severity'] ?? 'INFO';
        echo '  [' . $sev . ']  [' . $rec['id'] . ']' . PHP_EOL;
        echo '         ' . $rec['msg'] . PHP_EOL . PHP_EOL;
    }
}

// ── Footer ────────────────────────────────────────────────────────────────────
echo PHP_EOL . $hr . PHP_EOL;
echo '  Total geral: ' . fmtN($totalPedidos) . ' pedidos / ' . fmtR($totalValor) . ' / ' . $elapsed . 's' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
