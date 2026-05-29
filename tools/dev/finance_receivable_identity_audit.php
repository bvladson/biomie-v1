<?php

declare(strict_types=1);

/**
 * FASE 0 — FIN.R4.4: Diagnóstico de identidade operacional dos recebíveis.
 *
 * Read-only. Audita 20 métricas de cobertura de identidade (NF, pedido, cliente, vendedor).
 *
 * Uso:
 *   php tools/dev/finance_receivable_identity_audit.php \
 *       --start=2026-05-01 --end=2026-05-31 --pretty --sample=20
 *
 * Saída: JSON ou texto formatado.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\FinanceReceivableIdentityResolver;

$opts = getopt('', ['start:', 'end:', 'pretty', 'json', 'sample:', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php finance_receivable_identity_audit.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--pretty] [--json] [--sample=20]\n";
    exit(0);
}

$start  = (string) ($opts['start'] ?? date('Y-m-01'));
$end    = (string) ($opts['end']   ?? date('Y-m-d'));
$pretty = isset($opts['pretty']);
$asJson = isset($opts['json']);
$sample = max(1, min(100, (int) ($opts['sample'] ?? 20)));

// ── Connect ──────────────────────────────────────────────────────────────────
try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        fwrite(STDERR, "ERROR: Banco de dados não configurado.\n");
        exit(1);
    }
    $pdo = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR connecting: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Check required table ──────────────────────────────────────────────────────
function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
        $stmt->execute(['t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) { return false; }
}

if (!tableExists($pdo, 'biomie_titulos_financeiros_canonicos')) {
    fwrite(STDERR, "ERROR: Tabela biomie_titulos_financeiros_canonicos não encontrada.\n");
    exit(1);
}

$hasNf       = tableExists($pdo, 'biomie_notas_fiscais_canonicas');
$hasBridge   = tableExists($pdo, 'biomie_bridge_nf_titulo_items');
$hasPedido   = tableExists($pdo, 'biomie_pedidos_canonicos');
$hasCliente  = tableExists($pdo, 'biomie_clientes_dim');
$hasBridgeSum = tableExists($pdo, 'biomie_bridge_nf_titulo_summary');

// ── Fetch titles ──────────────────────────────────────────────────────────────
$sql = "SELECT
    titulo_key, numero_documento, status_titulo,
    especie_documento, forma_pagamento, codigo_conta_corrente_omie,
    documento_origem_tipo, documento_origem_ref,
    nota_key AS titulo_nota_key, chave_nfe, pedido_id_omie, codigo_pedido_origem,
    codigo_cliente_omie, nome_cliente, documento_cliente,
    codigo_vendedor_omie, nome_vendedor,
    data_emissao, data_vencimento, data_liquidacao, data_competencia,
    valor_liquido, valor_aberto
FROM biomie_titulos_financeiros_canonicos
WHERE data_competencia BETWEEN :start AND :end
ORDER BY data_competencia ASC, titulo_key ASC
LIMIT 10000";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['start' => $start, 'end' => $end]);
    $titles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR fetching titles: " . $e->getMessage() . "\n");
    exit(1);
}

$totalTitles = count($titles);

if ($totalTitles === 0) {
    $out = ['ok' => true, 'period' => compact('start', 'end'), 'total_titles' => 0,
            'message' => 'Nenhum título encontrado no período.'];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ── Load resolver (batch lookups) ─────────────────────────────────────────────
$resolver = new FinanceReceivableIdentityResolver($pdo);

$today     = date('Y-m-d');
$paidStats = ['liquidado', 'recebido', 'pago'];

// ── Counters ──────────────────────────────────────────────────────────────────
$cOpen = $cOverdue = 0;
$cNotaKeyDirect = $cChaveNfeDirect = $cBridgeResolved = $cNfFound = 0;
$cPedidoFound = $cVendorFound = $cClienteFound = 0;
$cNoNf = $cNoPedido = $cNoVendor = $cNoCliente = 0;
$cVendorGap = $cClienteGap = 0;

$scoreSum = 0;
$scoreCount = 0;

/** @var array<string,int> */
$gapReasonCounts = [];

/** @var list<array<string,mixed>> */
$examples = [];

foreach ($titles as $t) {
    $status  = strtolower(trim((string)($t['status_titulo'] ?? '')));
    $isPaid  = in_array($status, $paidStats, true);
    $amtOpen = (float)($t['valor_aberto'] ?? 0);
    $isOpen  = !$isPaid && $amtOpen > 0.0001;
    $dueDate = (string)($t['data_vencimento'] ?? '');
    $isOverdue = $isOpen && $dueDate !== '' && $dueDate < $today;

    if ($isOpen)   $cOpen++;
    if ($isOverdue)$cOverdue++;

    // Raw presence checks
    $notaKeyRaw = trim((string)($t['titulo_nota_key'] ?? ''));
    $chaveNfe   = trim((string)($t['chave_nfe'] ?? ''));
    if ($notaKeyRaw !== '') $cNotaKeyDirect++;
    if ($chaveNfe !== '')   $cChaveNfeDirect++;

    // Resolver
    $resolved = $resolver->resolveTitle($t);

    $nf      = $resolved['nf'];
    $pedido  = $resolved['pedido'];
    $vendedor= $resolved['vendedor'];
    $cliente = $resolved['cliente'];
    $quality = $resolved['trace_quality'];

    if ($nf['found']) {
        $cNfFound++;
        if ($nf['source'] !== 'titulo_nota_key_partial' && $nf['source'] !== 'bridge_partial') {
            // resolved to a real NF row
        }
        if (str_contains($nf['source'], 'bridge')) $cBridgeResolved++;
    } else {
        $cNoNf++;
    }

    if ($pedido['found']) $cPedidoFound++; else $cNoPedido++;
    if ($vendedor['found']) $cVendorFound++; else {
        $cNoVendor++;
        $gap = $vendedor['gap_reason'] ?? 'unknown';
        $gapReasonCounts[$gap] = ($gapReasonCounts[$gap] ?? 0) + 1;
    }
    if ($cliente['found']) $cClienteFound++; else $cNoCliente++;

    $scoreSum += $quality['score'];
    $scoreCount++;

    // Collect examples with gaps
    if (count($examples) < $sample && (count($quality['missing']) > 0)) {
        $examples[] = [
            'titulo_key'          => $t['titulo_key'],
            'nota_key'            => $nf['nota_key'],
            'numero_nf'           => $nf['numero_nf'],
            'chave_nfe'           => $t['chave_nfe'],
            'pedido_id_omie'      => $pedido['pedido_id_omie'],
            'codigo_cliente_omie' => $cliente['codigo_cliente_omie'],
            'nome_cliente'        => $cliente['nome'] ?: $t['nome_cliente'],
            'codigo_vendedor_omie'=> $vendedor['codigo_vendedor_omie'],
            'nome_vendedor'       => $vendedor['nome'] ?: $t['nome_vendedor'],
            'gap_reason'          => $quality['gap_reason'],
            'missing'             => $quality['missing'],
            'score'               => $quality['score'],
            'nf_source'           => $nf['source'],
        ];
    }
}

// ── Gap reason top 20 ─────────────────────────────────────────────────────────
arsort($gapReasonCounts);
$topGapReasons = array_slice($gapReasonCounts, 0, 20, true);

// ── Pct helpers ───────────────────────────────────────────────────────────────
$pct = static fn(int $n, int $total): float => $total > 0 ? round($n / $total * 100, 1) : 0.0;
$avgScore = $scoreCount > 0 ? round($scoreSum / $scoreCount, 1) : 0.0;

// ── Before/after dedup estimation ────────────────────────────────────────────
$dedupCheck = 0;
if ($hasBridge) {
    try {
        $r = $pdo->prepare("SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items bi
            INNER JOIN biomie_titulos_financeiros_canonicos t ON t.titulo_key = bi.titulo_key
            WHERE t.data_competencia BETWEEN :start AND :end");
        $r->execute(['start' => $start, 'end' => $end]);
        $dedupCheck = (int) $r->fetchColumn();
    } catch (\Throwable) {}
}

// ── Build output ──────────────────────────────────────────────────────────────
$result = [
    'ok'      => true,
    'period'  => ['start' => $start, 'end' => $end, 'reference_date' => $today],
    'tables'  => [
        'biomie_notas_fiscais_canonicas'    => $hasNf,
        'biomie_bridge_nf_titulo_items'     => $hasBridge,
        'biomie_bridge_nf_titulo_summary'   => $hasBridgeSum,
        'biomie_pedidos_canonicos'          => $hasPedido,
        'biomie_clientes_dim'               => $hasCliente,
    ],
    'metrics' => [
        // M1
        'total_titulos'                     => $totalTitles,
        // M2
        'titulos_em_aberto'                 => $cOpen,
        // M3
        'titulos_vencidos'                  => $cOverdue,
        // M4
        'nota_key_direto'                   => ['count' => $cNotaKeyDirect,   'pct' => $pct($cNotaKeyDirect, $totalTitles)],
        // M5
        'chave_nfe_direto'                  => ['count' => $cChaveNfeDirect,  'pct' => $pct($cChaveNfeDirect, $totalTitles)],
        // M6
        'nf_resolvida_via_bridge'           => ['count' => $cBridgeResolved,  'pct' => $pct($cBridgeResolved, $totalTitles)],
        // M7
        'nf_total_encontrada'               => ['count' => $cNfFound,         'pct' => $pct($cNfFound, $totalTitles)],
        // M8
        'pedido_encontrado'                 => ['count' => $cPedidoFound,      'pct' => $pct($cPedidoFound, $totalTitles)],
        // M9
        'vendedor_encontrado'               => ['count' => $cVendorFound,      'pct' => $pct($cVendorFound, $totalTitles)],
        // M10
        'cliente_encontrado'                => ['count' => $cClienteFound,     'pct' => $pct($cClienteFound, $totalTitles)],
        // M11
        'sem_nf'                            => ['count' => $cNoNf,            'pct' => $pct($cNoNf, $totalTitles)],
        // M12
        'sem_pedido'                        => ['count' => $cNoPedido,         'pct' => $pct($cNoPedido, $totalTitles)],
        // M13
        'sem_vendedor'                      => ['count' => $cNoVendor,         'pct' => $pct($cNoVendor, $totalTitles)],
        // M14
        'sem_cliente'                       => ['count' => $cNoCliente,        'pct' => $pct($cNoCliente, $totalTitles)],
        // M15-M18 dedup
        'bridge_rows_before_dedup'          => $dedupCheck,
        'titulos_apos_dedup'                => $totalTitles,
        // M19 avg score
        'avg_trace_quality_score'           => $avgScore,
        // M20 grade
        'grade'                             => $avgScore >= 75 ? 'A' : ($avgScore >= 50 ? 'B' : ($avgScore >= 25 ? 'C' : 'D')),
    ],
    'top_gap_reasons'   => $topGapReasons,
    'examples_with_gap' => $examples,
    'diagnostics'       => [
        'generated_at' => date(DATE_ATOM),
        'source'       => 'finance_receivable_identity_audit',
    ],
];

if ($asJson || !$pretty) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ── Pretty text output ────────────────────────────────────────────────────────
$m = $result['metrics'];
$grade = $m['grade'];
$gradeColor = match ($grade) { 'A' => "\033[32m", 'B' => "\033[33m", default => "\033[31m" };
$rst = "\033[0m";

echo "\n";
echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│  FIN.R4.4 — Finance Receivable Identity Audit                   │\n";
echo "│  Período: {$start} → {$end}                                     │\n";
echo "│  Gerado:  " . date('Y-m-d H:i:s') . "                               │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

echo "TABELAS DISPONÍVEIS\n";
foreach ($result['tables'] as $tbl => $ok) {
    echo "  " . ($ok ? "✔" : "✖") . " {$tbl}\n";
}
echo "\n";

echo "MÉTRICAS DE COBERTURA\n";
echo "  Títulos no período      : {$m['total_titulos']}\n";
echo "  Títulos em aberto       : {$m['titulos_em_aberto']}\n";
echo "  Títulos vencidos        : {$m['titulos_vencidos']}\n\n";

echo "  M4  nota_key direto     : {$m['nota_key_direto']['count']} ({$m['nota_key_direto']['pct']}%)\n";
echo "  M5  chave_nfe direto    : {$m['chave_nfe_direto']['count']} ({$m['chave_nfe_direto']['pct']}%)\n";
echo "  M6  via bridge          : {$m['nf_resolvida_via_bridge']['count']} ({$m['nf_resolvida_via_bridge']['pct']}%)\n";
echo "  M7  NF encontrada total : {$m['nf_total_encontrada']['count']} ({$m['nf_total_encontrada']['pct']}%)\n";
echo "  M8  Pedido encontrado   : {$m['pedido_encontrado']['count']} ({$m['pedido_encontrado']['pct']}%)\n";
echo "  M9  Vendedor resolvido  : {$m['vendedor_encontrado']['count']} ({$m['vendedor_encontrado']['pct']}%)\n";
echo "  M10 Cliente resolvido   : {$m['cliente_encontrado']['count']} ({$m['cliente_encontrado']['pct']}%)\n\n";

echo "  M11 Sem NF              : {$m['sem_nf']['count']} ({$m['sem_nf']['pct']}%)\n";
echo "  M12 Sem pedido          : {$m['sem_pedido']['count']} ({$m['sem_pedido']['pct']}%)\n";
echo "  M13 Sem vendedor        : {$m['sem_vendedor']['count']} ({$m['sem_vendedor']['pct']}%)\n";
echo "  M14 Sem cliente         : {$m['sem_cliente']['count']} ({$m['sem_cliente']['pct']}%)\n\n";

echo "  Bridge rows pré-dedup   : {$m['bridge_rows_before_dedup']}\n";
echo "  Títulos pós-dedup       : {$m['titulos_apos_dedup']}\n\n";

echo "  Avg trace quality score : {$m['avg_trace_quality_score']} / 100\n";
echo "  Grade                   : {$gradeColor}{$grade}{$rst}\n\n";

if ($result['top_gap_reasons'] !== []) {
    echo "TOP CAUSAS DE GAP\n";
    foreach ($result['top_gap_reasons'] as $reason => $count) {
        echo "  [{$count}] {$reason}\n";
    }
    echo "\n";
}

if ($result['examples_with_gap'] !== []) {
    echo "EXEMPLOS COM GAP (sample={$sample})\n";
    foreach ($result['examples_with_gap'] as $ex) {
        echo "  titulo_key  : {$ex['titulo_key']}\n";
        echo "  nota_key    : " . ($ex['nota_key'] ?: '(não resolvida)') . "\n";
        echo "  numero_nf   : " . ($ex['numero_nf'] ?: '—') . "\n";
        echo "  pedido      : " . ($ex['pedido_id_omie'] ?: '—') . "\n";
        echo "  cliente     : " . ($ex['nome_cliente'] ?: '—') . "\n";
        echo "  vendedor    : " . ($ex['nome_vendedor'] ?: '—') . "\n";
        echo "  gap_reason  : {$ex['gap_reason']}\n";
        echo "  missing     : " . implode(', ', $ex['missing']) . "\n";
        echo "  score       : {$ex['score']}\n";
        echo "  nf_source   : {$ex['nf_source']}\n";
        echo "  ---\n";
    }
}

$okLabel = "\033[32mOK\033[0m";
$warnLabel = "\033[33mWARN\033[0m";

echo "\nDIAGNÓSTICO\n";
echo "  NF cobertura total      : " . ($m['nf_total_encontrada']['pct'] >= 80 ? $okLabel : $warnLabel) . " ({$m['nf_total_encontrada']['pct']}%)\n";
echo "  Pedido cobertura        : " . ($m['pedido_encontrado']['pct'] >= 50 ? $okLabel : $warnLabel) . " ({$m['pedido_encontrado']['pct']}%)\n";
echo "  Vendedor cobertura      : " . ($m['vendedor_encontrado']['pct'] >= 50 ? $okLabel : $warnLabel) . " ({$m['vendedor_encontrado']['pct']}%)\n";
echo "  Cliente cobertura       : " . ($m['cliente_encontrado']['pct'] >= 80 ? $okLabel : $warnLabel) . " ({$m['cliente_encontrado']['pct']}%)\n";
echo "\n";
