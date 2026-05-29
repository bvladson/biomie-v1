<?php

declare(strict_types=1);

/**
 * COM.R1.3 FASE 1 — Auditoria de Impacto da Semântica Comercial nos Painéis
 *
 * Compara o estado atual dos painéis BIomie contra CommercialPipelineClassifier.
 * Não altera nenhum dado. Apenas lê e classifica.
 *
 * Uso:
 *   php tools/dev/commercial_dashboard_semantics_impact_audit.php \
 *       --start=YYYY-MM-DD --end=YYYY-MM-DD [--pretty] [--json] [--sample=30] [--vendor=...]
 *
 * Read-only.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\CommercialPipelineClassifier;

$opts     = getopt('', ['start:', 'end:', 'vendor:', 'pretty', 'json', 'sample:', 'stage:']);
$start    = (string) ($opts['start']  ?? date('Y-01-01'));
$end      = (string) ($opts['end']    ?? date('Y-m-d'));
$vendor   = isset($opts['vendor']) ? trim((string) $opts['vendor']) : null;
$sampleN  = max(1, min(100, (int) ($opts['sample'] ?? 30)));
$pretty   = isset($opts['pretty']);
$jsonMode = isset($opts['json']);

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

function tblD(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $t]);
    return (int) $s->fetchColumn() > 0;
}

$hasCanon = tblD($pdo, 'biomie_pedidos_canonicos');
$hasNfs   = tblD($pdo, 'biomie_notas_fiscais_canonicas');

$periodClause = "COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end";
$vFilter = $vendor !== null ? " AND p.nome_vendedor = :vendor" : '';
$params  = [':start' => $start, ':end' => $end];
if ($vendor !== null) $params[':vendor'] = $vendor;

// ── SECTION 1 — By-scope summary (via CommercialPipelineClassifier) ───────────
$byScopeCurrentCanon = [];  // status_comercial → totals
$byScopeClassified   = [];  // classifier scope → totals
$etapa60AsFaturado   = 0.0;
$quoteWithNfTotal    = 0.0;
$quoteWithNfCount    = 0;

if ($hasCanon) {
    $sAll = $pdo->prepare(
        "SELECT p.etapa_omie, p.status_comercial, p.nome_vendedor,
                SUM(p.valor_total) AS valor_total, COUNT(DISTINCT p.id) AS pedidos,
                SUM(CASE WHEN nf.nota_key IS NOT NULL THEN 1 ELSE 0 END) AS tem_nf,
                SUM(CASE WHEN nf.status_fiscal='autorizada' THEN 1 ELSE 0 END) AS nf_aut
         FROM biomie_pedidos_canonicos p
         LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_pedido_origem = p.codigo_pedido
         WHERE {$periodClause}{$vFilter}
         GROUP BY p.etapa_omie, p.status_comercial, p.nome_vendedor
         ORDER BY valor_total DESC
         LIMIT 100"
    );
    $sAll->execute($params);
    $stageRows = $sAll->fetchAll(PDO::FETCH_ASSOC);

    foreach ($stageRows as $r) {
        $etapa   = (string) ($r['etapa_omie']      ?? '');
        $status  = (string) ($r['status_comercial'] ?? '');
        $valor   = (float)  $r['valor_total'];
        $pedidos = (int)    $r['pedidos'];
        $hasNfR  = (int)    $r['nf_aut'] > 0;

        // Current canonical classification
        $byScopeCurrentCanon[$status]['valor']   = ($byScopeCurrentCanon[$status]['valor']   ?? 0.0) + $valor;
        $byScopeCurrentCanon[$status]['pedidos']  = ($byScopeCurrentCanon[$status]['pedidos']  ?? 0) + $pedidos;

        // Classifier-based classification
        $cls = $clf->classify([
            'etapa_omie'       => $etapa,
            'status_comercial' => $status,
            'nome_vendedor'    => (string) ($r['nome_vendedor'] ?? ''),
            'has_authorized_nf'=> $hasNfR,
        ]);
        $scope = $cls['commercial_scope'];
        $byScopeClassified[$scope]['valor']   = ($byScopeClassified[$scope]['valor']   ?? 0.0) + $valor;
        $byScopeClassified[$scope]['pedidos']  = ($byScopeClassified[$scope]['pedidos']  ?? 0) + $pedidos;

        // Track etapa 60 that was being counted as faturado
        if ($etapa === '60' && $status === 'faturado') {
            $etapa60AsFaturado += $valor;
        }

        // Track orçamentos with NF
        if ($etapa === '10' && (int)$r['tem_nf'] > 0) {
            $quoteWithNfTotal += $valor;
            $quoteWithNfCount += $pedidos;
        }
    }
}

// ── SECTION 2 — Official realized by NF autorizada ───────────────────────────
$nfRealized  = 0.0;
$nfCount     = 0;
$nfByVendor  = [];

if ($hasNfs) {
    $nfDateExpr = "COALESCE(data_competencia, data_emissao)";
    $sNf = $pdo->prepare(
        "SELECT nome_vendedor,
                SUM(valor_mercadorias) AS valor_merc,
                COUNT(DISTINCT nota_key) AS qtd_nfs
         FROM biomie_notas_fiscais_canonicas
         WHERE {$nfDateExpr} BETWEEN :start AND :end
           AND status_fiscal = 'autorizada'
         GROUP BY nome_vendedor
         ORDER BY valor_merc DESC
         LIMIT 50"
    );
    $sNf->execute([':start' => $start, ':end' => $end]);
    foreach ($sNf->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $v = (float) $r['valor_merc'];
        $nfRealized += $v;
        $nfCount    += (int) $r['qtd_nfs'];
        $nfByVendor[trim((string)$r['nome_vendedor'])] = $v;
    }
}

// ── SECTION 3 — Impact analysis per dashboard ─────────────────────────────────
// Current: valor classificado como "faturado" via status_comercial=faturado (pedidos)
$currentFaturadoPedidos = $byScopeCurrentCanon['faturado']['valor'] ?? 0.0;
// After fix: valor classificado como faturado via CommercialPipelineClassifier
$classifiedFaturado = $byScopeClassified[CommercialPipelineClassifier::SCOPE_FATURADO]['valor'] ?? 0.0;
$classifiedEntrega  = $byScopeClassified[CommercialPipelineClassifier::SCOPE_ENTREGA_FINALIZADA]['valor'] ?? 0.0;
$classifiedOrcamento = $byScopeClassified[CommercialPipelineClassifier::SCOPE_ORCAMENTO]['valor'] ?? 0.0;
$classifiedCarteira  = ($byScopeClassified[CommercialPipelineClassifier::SCOPE_CARTEIRA_ACEITA]['valor'] ?? 0.0)
    + ($byScopeClassified[CommercialPipelineClassifier::SCOPE_CARTEIRA_VAREJO]['valor'] ?? 0.0)
    + ($byScopeClassified[CommercialPipelineClassifier::SCOPE_CARTEIRA_REVENDA]['valor'] ?? 0.0);
$classifiedCancelado = $byScopeClassified[CommercialPipelineClassifier::SCOPE_CANCELADO]['valor'] ?? 0.0;
$classifiedDevolvido = $byScopeClassified[CommercialPipelineClassifier::SCOPE_DEVOLVIDO]['valor'] ?? 0.0;

// Dashboard impact recommendations
$recommendations = [];

// etapa 60 impact
if ($etapa60AsFaturado > 0) {
    $recommendations[] = [
        'page'   => 'vendedores.php / plano_voo.php',
        'issue'  => 'R$ ' . number_format($etapa60AsFaturado, 2, ',', '.') . ' em pedidos etapa 60 (Entrega Finalizada) estavam inflando o realizado por pedido',
        'fix'    => 'etapa 60 → entrega_finalizada (já corrigido no CommercialPipelineClassifier)',
        'impact' => 'Realizado oficial vem de NF autorizada — impacto é nas métricas "por pedido" dos painéis',
    ];
}

// Orçamento stale with NF
if ($quoteWithNfCount > 0) {
    $recommendations[] = [
        'page'   => 'pedidos.php / vendedores.php',
        'issue'  => $quoteWithNfCount . ' orçamento(s) etapa 10 com NF vinculada = snapshot stale',
        'fix'    => 'Mostrar warning "orçamento convertido" — NF já contabilizada no realizado NF',
        'impact' => 'Sem dupla contagem se realizado vem de NF; carteira de orçamentos superestimada',
    ];
}

// Carteira mixing with faturado
if ($currentFaturadoPedidos > $nfRealized * 1.5) {
    $recommendations[] = [
        'page'   => 'plano_voo.php / index.php',
        'issue'  => 'Realizado por pedido (R$ ' . number_format($currentFaturadoPedidos, 2, ',', '.') . ') > 1.5x realizado por NF (R$ ' . number_format($nfRealized, 2, ',', '.') . ')',
        'fix'    => 'Validar se dashboards usam Base Comercial NF ou pedidos como base',
        'impact' => 'Possível superestimativa de realizado em painéis que usam status_comercial=faturado de pedidos',
    ];
}

// Confirm Base Comercial NF is preserved
$baseNfPreserved = $nfRealized > 0;
$recommendations[] = [
    'page'   => 'Todos os painéis',
    'issue'  => 'Base Comercial NF (biomie_notas_fiscais_canonicas status_fiscal=autorizada)',
    'fix'    => $baseNfPreserved ? 'PRESERVADA — realizado por NF = R$ ' . number_format($nfRealized, 2, ',', '.') : 'AVISO: sem NFs encontradas no período',
    'impact' => 'Realizado oficial NÃO muda nesta fase',
];

$elapsed = round(microtime(true) - $t0, 2);

// ── JSON ──────────────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'commercial_dashboard_semantics_impact_audit' => [
            'version'        => 'COM.R1.3',
            'period'         => ['start' => $start, 'end' => $end],
            'generated_at'   => date('Y-m-d H:i:s'),
            'elapsed_s'      => $elapsed,
            'realized_by_nf' => round($nfRealized, 2),
            'nf_count'       => $nfCount,
            'current_status_comercial_breakdown'    => $byScopeCurrentCanon,
            'classifier_scope_breakdown'            => $byScopeClassified,
            'etapa_60_previously_as_faturado'       => round($etapa60AsFaturado, 2),
            'quote_with_nf_valor'                   => round($quoteWithNfTotal, 2),
            'quote_with_nf_count'                   => $quoteWithNfCount,
            'base_comercial_nf_preserved'           => $baseNfPreserved,
            'recommendations'                       => $recommendations,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty ────────────────────────────────────────────────────────────────────
function fmtCd(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }

$hr = str_repeat('═', 74);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.3 — Impacto da Semântica Comercial nos Painéis' . PHP_EOL;
echo '  Período : ' . $start . ' → ' . $end . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

// Status atual vs classificador
echo str_repeat('─', 74) . PHP_EOL . '  Status atual (status_comercial ENUM) vs CommercialPipelineClassifier' . PHP_EOL . str_repeat('─', 74) . PHP_EOL . PHP_EOL;
echo '  ' . str_pad('scope_ou_status', 28) . str_pad('valor', 20) . 'pedidos' . PHP_EOL;
echo '  ' . str_repeat('-', 60) . PHP_EOL;
echo '  ATUAL (status_comercial ENUM):' . PHP_EOL;
foreach ($byScopeCurrentCanon as $st => $info) {
    $flag = ($st === 'faturado' && $etapa60AsFaturado > 0) ? ' (inclui etapa60!)' : '';
    echo '    ' . str_pad($st, 26) . str_pad(fmtCd($info['valor']), 20) . $info['pedidos'] . $flag . PHP_EOL;
}
echo PHP_EOL . '  PROPOSTO (CommercialPipelineClassifier):' . PHP_EOL;
$SCOPE_ORDER = ['faturado','entrega_finalizada','carteira_aceita','carteira_varejo','carteira_revenda','orcamento','cancelado','devolvido','licitacao','desconhecido'];
foreach ($SCOPE_ORDER as $sc) {
    if (!isset($byScopeClassified[$sc])) continue;
    $info = $byScopeClassified[$sc];
    echo '    ' . str_pad($sc, 26) . str_pad(fmtCd($info['valor']), 20) . $info['pedidos'] . PHP_EOL;
}

// Official realizado by NF
echo PHP_EOL . str_repeat('─', 74) . PHP_EOL . '  Realizado Oficial por NF Autorizada (Base Comercial NF)' . PHP_EOL . str_repeat('─', 74) . PHP_EOL . PHP_EOL;
echo '  Realizado NF total  : ' . fmtCd($nfRealized) . '  (' . $nfCount . ' NFs)' . PHP_EOL;
echo '  Base Comercial NF   : ' . ($baseNfPreserved ? '✓ PRESERVADA' : '✗ Sem dados') . PHP_EOL;
foreach (array_slice($nfByVendor, 0, 8, true) as $vName => $vVal) {
    echo '    ' . str_pad(mb_substr($vName, 0, 30), 32) . fmtCd($vVal) . PHP_EOL;
}

// Etapa 60 impact
echo PHP_EOL . str_repeat('─', 74) . PHP_EOL . '  Impacto da Etapa 60 (Entrega Finalizada)' . PHP_EOL . str_repeat('─', 74) . PHP_EOL . PHP_EOL;
echo '  Valor etapa 60 tratado como faturado (antes): ' . fmtCd($etapa60AsFaturado) . PHP_EOL;
echo '  Valor etapa 60 como entrega_finalizada (após): ' . fmtCd($classifiedEntrega) . PHP_EOL;
echo '  CONCLUSÃO: etapa 60 NÃO gera novo faturamento — NF já contabilizada em etapa 50.' . PHP_EOL;

// Recommendations
echo PHP_EOL . str_repeat('─', 74) . PHP_EOL . '  Recomendações por Painel' . PHP_EOL . str_repeat('─', 74) . PHP_EOL . PHP_EOL;
foreach ($recommendations as $rec) {
    echo '  [' . $rec['page'] . ']' . PHP_EOL;
    echo '    Problema : ' . $rec['issue'] . PHP_EOL;
    echo '    Fix      : ' . $rec['fix'] . PHP_EOL;
    echo '    Impacto  : ' . $rec['impact'] . PHP_EOL . PHP_EOL;
}

echo $hr . PHP_EOL;
echo '  Concluído em ' . $elapsed . 's' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
