<?php

declare(strict_types=1);

/**
 * FIN.R4.3 — Phase 1: Finance Trace Linkage Gap Report
 *
 * Diagnóstico read-only: por que financeiro mostra muitos títulos sem NF/vendedor.
 * Não altera dados, não cria nada, não materializa.
 *
 * Uso: php tools/dev/finance_trace_linkage_gap_report.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--pretty]
 */

require_once __DIR__ . '/../../app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once __DIR__ . '/../../app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

// ──────────────────────────── CLI args ────────────────────────────
$args  = getopt('', ['start:', 'end:', 'pretty']);
$start = $args['start'] ?? date('Y-m-01');
$end   = $args['end']   ?? date('Y-m-t');
$pretty = array_key_exists('pretty', $args);

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ──────────────────────────── helpers ─────────────────────────────
function tableExists(PDO $pdo, string $table): bool
{
    $row = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
    )->fetchColumn();
    return (int) $row > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $row = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) .
        " AND COLUMN_NAME = " . $pdo->quote($column)
    )->fetchColumn();
    return (int) $row > 0;
}

function pct(int $num, int $denom): string
{
    if ($denom === 0) return '0.0%';
    return round($num / $denom * 100, 1) . '%';
}

function q(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (\Throwable) {
        return [];
    }
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (\Throwable) {
        return false;
    }
}

// ──────────────────────────── table inventory ──────────────────────
$hasTitulos      = tableExists($pdo, 'biomie_titulos_financeiros_canonicos');
$hasBridgeItems  = tableExists($pdo, 'biomie_bridge_nf_titulo_items');
$hasBridgeSummary= tableExists($pdo, 'biomie_bridge_nf_titulo_summary');
$hasNfs          = tableExists($pdo, 'biomie_notas_fiscais_canonicas');
$hasPedidos      = tableExists($pdo, 'biomie_pedidos_canonicos');
$hasMartBridge   = tableExists($pdo, 'mart_bridge_pedido_nfe_titulo');
$hasMartFin      = tableExists($pdo, 'mart_financeiro_titulo');
$hasMartVendor   = tableExists($pdo, 'mart_vendor_day');
$hasMartCliente  = tableExists($pdo, 'mart_cliente_periodo');

$tables = [
    'biomie_titulos_financeiros_canonicos' => $hasTitulos,
    'biomie_bridge_nf_titulo_items'        => $hasBridgeItems,
    'biomie_bridge_nf_titulo_summary'      => $hasBridgeSummary,
    'biomie_notas_fiscais_canonicas'       => $hasNfs,
    'biomie_pedidos_canonicos'             => $hasPedidos,
    'mart_bridge_pedido_nfe_titulo'        => $hasMartBridge,
    'mart_financeiro_titulo'               => $hasMartFin,
    'mart_vendor_day'                      => $hasMartVendor,
    'mart_cliente_periodo'                 => $hasMartCliente,
];

$report = [
    'generated_at' => date(DATE_ATOM),
    'period'       => ['start' => $start, 'end' => $end],
    'tables'       => $tables,
];

if (!$hasTitulos) {
    $report['fatal'] = 'biomie_titulos_financeiros_canonicos does not exist — cannot continue';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

// ──────────────────────────── 1. Total titles in period ────────────
$totalTitulos = (int) scalar($pdo,
    "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
     WHERE data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
);

$totalTitulosOpen = (int) scalar($pdo,
    "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
     WHERE data_competencia BETWEEN :s AND :e
       AND status_titulo NOT IN ('liquidado','recebido','pago','cancelado')",
    ['s' => $start, 'e' => $end]
);

$report['totals'] = [
    'titles_in_period' => $totalTitulos,
    'titles_open'      => $totalTitulosOpen,
];

// ──────────────────────────── 2. NF linkage breakdown ─────────────
// Source A: titulo.nota_key (direct field on canonical)
$hasNotaKeyCol = columnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'nota_key');

$withNfDirect = 0;
if ($hasNotaKeyCol) {
    $withNfDirect = (int) scalar($pdo,
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e AND nota_key IS NOT NULL AND nota_key <> ''",
        ['s' => $start, 'e' => $end]
    );
}

// Source B: biomie_bridge_nf_titulo_items
$withNfBridge = 0;
if ($hasBridgeItems) {
    $withNfBridge = (int) scalar($pdo,
        "SELECT COUNT(DISTINCT t.titulo_key)
         FROM biomie_titulos_financeiros_canonicos t
         JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
         WHERE t.data_competencia BETWEEN :s AND :e
           AND bi.nota_key IS NOT NULL AND bi.nota_key <> ''",
        ['s' => $start, 'e' => $end]
    );
}

// Source C: mart_bridge_pedido_nfe_titulo
$withNfMartBridge = 0;
if ($hasMartBridge) {
    $withNfMartBridge = (int) scalar($pdo,
        "SELECT COUNT(DISTINCT t.titulo_key)
         FROM biomie_titulos_financeiros_canonicos t
         JOIN mart_bridge_pedido_nfe_titulo mb ON mb.titulo_key = t.titulo_key
         WHERE t.data_competencia BETWEEN :s AND :e
           AND mb.nota_key IS NOT NULL AND mb.nota_key <> ''",
        ['s' => $start, 'e' => $end]
    );
}

// Combined: any source
$withNfAny = (int) scalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t"
    . ($hasBridgeItems ? " LEFT JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key" : "")
    . ($hasMartBridge  ? " LEFT JOIN mart_bridge_pedido_nfe_titulo mb ON mb.titulo_key = t.titulo_key" : "")
    . " WHERE t.data_competencia BETWEEN :s AND :e
        AND (
            " . ($hasNotaKeyCol ? "(t.nota_key IS NOT NULL AND t.nota_key <> '')" : "1=0") . "
            " . ($hasBridgeItems ? " OR (bi.nota_key IS NOT NULL AND bi.nota_key <> '')" : "") . "
            " . ($hasMartBridge  ? " OR (mb.nota_key IS NOT NULL AND mb.nota_key <> '')" : "") . "
        )",
    ['s' => $start, 'e' => $end]
);

$withoutNfAny = $totalTitulos - $withNfAny;

$report['nf_linkage'] = [
    'with_nf_any_source'     => $withNfAny,
    'without_nf_any_source'  => $withoutNfAny,
    'pct_with_nf'            => pct($withNfAny, $totalTitulos),
    'source_direct_field'    => ['count' => $withNfDirect, 'col_exists' => $hasNotaKeyCol],
    'source_bridge_items'    => ['count' => $withNfBridge, 'table_exists' => $hasBridgeItems],
    'source_mart_bridge'     => ['count' => $withNfMartBridge, 'table_exists' => $hasMartBridge],
];

// ──────────────────────────── 3. NF linkage bridge confidence ─────
$bridgeConfidenceDist = [];
if ($hasBridgeItems) {
    $rows = q($pdo,
        "SELECT
             CASE
                WHEN bi.confidence_score >= 90 THEN 'high_90plus'
                WHEN bi.confidence_score >= 70 THEN 'medium_70_89'
                WHEN bi.confidence_score >= 50 THEN 'low_50_69'
                ELSE 'very_low'
             END AS bucket,
             COUNT(*) AS cnt,
             bi.match_method
         FROM biomie_titulos_financeiros_canonicos t
         JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
         WHERE t.data_competencia BETWEEN :s AND :e
         GROUP BY bucket, bi.match_method
         ORDER BY cnt DESC",
        ['s' => $start, 'e' => $end]
    );
    $bridgeConfidenceDist = $rows;
}
$report['bridge_confidence'] = $bridgeConfidenceDist;

// ──────────────────────────── 4. Pedido linkage ───────────────────
$hasPedidoCol = columnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'pedido_id_omie');

$withPedidoDirect = 0;
if ($hasPedidoCol) {
    $withPedidoDirect = (int) scalar($pdo,
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e
           AND pedido_id_omie IS NOT NULL AND pedido_id_omie <> '' AND pedido_id_omie <> '0'",
        ['s' => $start, 'e' => $end]
    );
}

$withPedidoMart = 0;
if ($hasMartBridge) {
    $withPedidoMart = (int) scalar($pdo,
        "SELECT COUNT(DISTINCT t.titulo_key)
         FROM biomie_titulos_financeiros_canonicos t
         JOIN mart_bridge_pedido_nfe_titulo mb ON mb.titulo_key = t.titulo_key
         WHERE t.data_competencia BETWEEN :s AND :e
           AND mb.pedido_id_omie IS NOT NULL AND mb.pedido_id_omie <> '' AND mb.pedido_id_omie <> '0'",
        ['s' => $start, 'e' => $end]
    );
}

$report['pedido_linkage'] = [
    'with_pedido_direct_field' => $withPedidoDirect,
    'with_pedido_mart_bridge'  => $withPedidoMart,
    'pedido_id_col_exists'     => $hasPedidoCol,
    'mart_bridge_exists'       => $hasMartBridge,
];

// ──────────────────────────── 5. Vendor gaps ──────────────────────
$unknownVendorNames = ['sem vendedor', 'sem vendedor definido', 'nao informado', 'não informado', ''];
$quotedNames = implode(',', array_map([$pdo, 'quote'], $unknownVendorNames));

$hasVendorCols = [
    'codigo_vendedor_omie' => columnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'codigo_vendedor_omie'),
    'nome_vendedor'        => columnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'nome_vendedor'),
];

$vendorDirectKnown = 0;
$vendorDirectMissing = 0;

if ($hasVendorCols['codigo_vendedor_omie'] || $hasVendorCols['nome_vendedor']) {
    $codeCol = $hasVendorCols['codigo_vendedor_omie'] ? 'codigo_vendedor_omie' : 'NULL';
    $nameCol = $hasVendorCols['nome_vendedor'] ? 'nome_vendedor' : "''";

    // Has vendor if code is non-empty OR name is not in unknown list
    $vendorDirectKnown = (int) scalar($pdo,
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e
           AND (
               ({$codeCol} IS NOT NULL AND {$codeCol} <> '' AND {$codeCol} <> '0')
               OR (LOWER(TRIM(COALESCE({$nameCol}, ''))) NOT IN ({$quotedNames}) AND TRIM(COALESCE({$nameCol}, '')) <> '')
           )",
        ['s' => $start, 'e' => $end]
    );

    $vendorDirectMissing = $totalTitulos - $vendorDirectKnown;
}

// Vendor via NF join
$vendorViaNf = 0;
if ($hasNfs && $hasBridgeItems && $vendorDirectMissing > 0) {
    $vendorViaNf = (int) scalar($pdo,
        "SELECT COUNT(DISTINCT t.titulo_key)
         FROM biomie_titulos_financeiros_canonicos t
         JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
         JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = bi.nota_key
         WHERE t.data_competencia BETWEEN :s AND :e
           AND (nf.codigo_vendedor_omie IS NOT NULL AND nf.codigo_vendedor_omie <> '' AND nf.codigo_vendedor_omie <> '0'
               OR (nf.nome_vendedor IS NOT NULL AND LOWER(TRIM(nf.nome_vendedor)) NOT IN ({$quotedNames})))",
        ['s' => $start, 'e' => $end]
    );
}

$report['vendor_gaps'] = [
    'with_vendor_on_titulo'   => $vendorDirectKnown,
    'without_vendor_on_titulo'=> $vendorDirectMissing,
    'pct_missing_vendor'      => pct($vendorDirectMissing, $totalTitulos),
    'with_vendor_via_nf'      => $vendorViaNf,
    'titulo_vendor_cols'      => $hasVendorCols,
];

// ──────────────────────────── 6. Client gaps ─────────────────────
$unknownClientNames = ['cliente nao informado', 'cliente não informado', 'nao informado', 'não informado', ''];
$quotedClientNames  = implode(',', array_map([$pdo, 'quote'], $unknownClientNames));

$hasClientCols = [
    'codigo_cliente_omie' => columnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'codigo_cliente_omie'),
    'nome_cliente'        => columnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'nome_cliente'),
];

$clientDirectKnown   = 0;
$clientDirectMissing = 0;

if ($hasClientCols['codigo_cliente_omie'] || $hasClientCols['nome_cliente']) {
    $codeCol = $hasClientCols['codigo_cliente_omie'] ? 'codigo_cliente_omie' : 'NULL';
    $nameCol = $hasClientCols['nome_cliente'] ? 'nome_cliente' : "''";

    $clientDirectKnown = (int) scalar($pdo,
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e
           AND (
               ({$codeCol} IS NOT NULL AND {$codeCol} <> '' AND {$codeCol} <> '0')
               OR (LOWER(TRIM(COALESCE({$nameCol}, ''))) NOT IN ({$quotedClientNames}) AND TRIM(COALESCE({$nameCol}, '')) <> '')
           )",
        ['s' => $start, 'e' => $end]
    );

    $clientDirectMissing = $totalTitulos - $clientDirectKnown;
}

// Client recovery via NF bridge
$clientViaNf = 0;
if ($hasNfs && $hasBridgeItems && $clientDirectMissing > 0) {
    $clientViaNf = (int) scalar($pdo,
        "SELECT COUNT(DISTINCT t.titulo_key)
         FROM biomie_titulos_financeiros_canonicos t
         JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
         JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = bi.nota_key
         WHERE t.data_competencia BETWEEN :s AND :e
           AND (nf.codigo_cliente_omie IS NOT NULL AND nf.codigo_cliente_omie <> '' AND nf.codigo_cliente_omie <> '0'
               OR (nf.nome_cliente IS NOT NULL AND LOWER(TRIM(nf.nome_cliente)) NOT IN ({$quotedClientNames})))",
        ['s' => $start, 'e' => $end]
    );
}

$report['client_gaps'] = [
    'with_client_on_titulo'   => $clientDirectKnown,
    'without_client_on_titulo'=> $clientDirectMissing,
    'pct_missing_client'      => pct($clientDirectMissing, $totalTitulos),
    'with_client_via_nf'      => $clientViaNf,
    'titulo_client_cols'      => $hasClientCols,
];

// ──────────────────────────── 7. Chave NF-e linkage ───────────────
$hasChaveNfe = columnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'chave_nfe');
$withChaveNfe = 0;
if ($hasChaveNfe) {
    $withChaveNfe = (int) scalar($pdo,
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e
           AND chave_nfe IS NOT NULL AND chave_nfe <> ''",
        ['s' => $start, 'e' => $end]
    );
}

// Titles with chave_nfe but not resolved to nota_key via bridge
$withChaveNotBridged = 0;
if ($hasChaveNfe && $hasBridgeItems) {
    $withChaveNotBridged = (int) scalar($pdo,
        "SELECT COUNT(DISTINCT t.titulo_key)
         FROM biomie_titulos_financeiros_canonicos t
         LEFT JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
         WHERE t.data_competencia BETWEEN :s AND :e
           AND t.chave_nfe IS NOT NULL AND t.chave_nfe <> ''
           AND (bi.nota_key IS NULL OR bi.nota_key = '')",
        ['s' => $start, 'e' => $end]
    );
}

$report['chave_nfe_linkage'] = [
    'col_exists'             => $hasChaveNfe,
    'with_chave_nfe'         => $withChaveNfe,
    'pct_with_chave_nfe'     => pct($withChaveNfe, $totalTitulos),
    'with_chave_not_bridged' => $withChaveNotBridged,
    'opportunity'            => $withChaveNotBridged > 0
        ? "RECOVERY OPPORTUNITY: {$withChaveNotBridged} titles have chave_nfe but no bridge — can match via NF canonical"
        : 'No unmatched chave_nfe found',
];

// ──────────────────────────── 8. NF canonical health ─────────────
$nfStats = [];
if ($hasNfs) {
    $nfTotal = (int) scalar($pdo, "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas");

    $hasNfDataEmissao = columnExists($pdo, 'biomie_notas_fiscais_canonicas', 'data_emissao');
    $nfInPeriod = 0;
    if ($hasNfDataEmissao) {
        $nfInPeriod = (int) scalar($pdo,
            "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
             WHERE data_emissao BETWEEN :s AND :e",
            ['s' => $start, 'e' => $end]
        );
    }

    $nfWithVendor = 0;
    if ($hasNfDataEmissao && columnExists($pdo, 'biomie_notas_fiscais_canonicas', 'codigo_vendedor_omie')) {
        $nfWithVendor = (int) scalar($pdo,
            "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
             WHERE data_emissao BETWEEN :s AND :e
               AND codigo_vendedor_omie IS NOT NULL AND codigo_vendedor_omie <> '' AND codigo_vendedor_omie <> '0'",
            ['s' => $start, 'e' => $end]
        );
    }

    $nfStats = [
        'total_all_time'    => $nfTotal,
        'in_period'         => $nfInPeriod,
        'with_vendor'       => $nfWithVendor,
        'pct_with_vendor'   => pct($nfWithVendor, max(1, $nfInPeriod)),
    ];
}
$report['nf_canonical_health'] = $nfStats;

// ──────────────────────────── 9. Bridge coverage summary ──────────
$bridgeSummaryStats = [];
if ($hasBridgeSummary) {
    $bsTotal = (int) scalar($pdo, "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary");
    $bsRows = q($pdo,
        "SELECT bridge_status, COUNT(*) AS cnt, SUM(total_valor_aberto) AS total_open
         FROM biomie_bridge_nf_titulo_summary
         GROUP BY bridge_status
         ORDER BY cnt DESC
         LIMIT 20"
    );
    $bridgeSummaryStats = ['total_rows' => $bsTotal, 'by_status' => $bsRows];
}
$report['bridge_summary'] = $bridgeSummaryStats;

// ──────────────────────────── 10. documento_origem breakdown ──────
$hasDocOrigemTipo = columnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'documento_origem_tipo');
$docOrigemDist = [];
if ($hasDocOrigemTipo) {
    $docOrigemDist = q($pdo,
        "SELECT
             COALESCE(NULLIF(TRIM(documento_origem_tipo), ''), 'vazio') AS tipo,
             COUNT(*) AS cnt,
             SUM(valor_aberto) AS total_aberto
         FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e
         GROUP BY tipo
         ORDER BY cnt DESC
         LIMIT 20",
        ['s' => $start, 'e' => $end]
    );
}
$report['documento_origem_distribution'] = $docOrigemDist;

// ──────────────────────────── 11. Sample títulos sem NF ───────────
$sampleNoNf = [];
if ($withoutNfAny > 0) {
    $bridgeJoin = $hasBridgeItems
        ? "LEFT JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key"
        : "";

    $bridgeWhere = $hasBridgeItems
        ? "AND (bi.nota_key IS NULL OR bi.nota_key = '')"
        : "";

    $notaKeyWhere = $hasNotaKeyCol
        ? "AND (t.nota_key IS NULL OR t.nota_key = '')"
        : "";

    $pedidoCol  = $hasPedidoCol   ? 't.pedido_id_omie'  : "'' AS pedido_id_omie";
    $chaveCol   = $hasChaveNfe    ? 't.chave_nfe'        : "'' AS chave_nfe";
    $vendorCol  = $hasVendorCols['nome_vendedor'] ? 't.nome_vendedor' : "'' AS nome_vendedor";
    $clienteCol = $hasClientCols['nome_cliente']  ? 't.nome_cliente'  : "'' AS nome_cliente";
    $docOrigemRef = columnExists($pdo, 'biomie_titulos_financeiros_canonicos', 'documento_origem_ref')
        ? 't.documento_origem_ref' : "'' AS documento_origem_ref";

    $sampleNoNf = q($pdo,
        "SELECT
             t.titulo_key,
             t.numero_documento,
             {$docOrigemRef},
             {$pedidoCol},
             {$chaveCol},
             {$vendorCol},
             {$clienteCol},
             t.status_titulo,
             t.valor_aberto,
             t.data_competencia,
             t.data_vencimento
         FROM biomie_titulos_financeiros_canonicos t
         {$bridgeJoin}
         WHERE t.data_competencia BETWEEN :s AND :e
           {$notaKeyWhere}
           {$bridgeWhere}
         ORDER BY t.valor_aberto DESC
         LIMIT 10",
        ['s' => $start, 'e' => $end]
    );
}
$report['sample_titles_without_nf'] = $sampleNoNf;

// ──────────────────────────── 12. Sample títulos sem vendedor ─────
$sampleNoVendor = [];
if ($vendorDirectMissing > 0 && ($hasVendorCols['codigo_vendedor_omie'] || $hasVendorCols['nome_vendedor'])) {
    $codeCol2   = $hasVendorCols['codigo_vendedor_omie'] ? 'codigo_vendedor_omie' : 'NULL';
    $nameCol2   = $hasVendorCols['nome_vendedor'] ? 'nome_vendedor' : "''";
    $pedidoCol2 = $hasPedidoCol ? 'pedido_id_omie' : "'' AS pedido_id_omie";
    $chaveCol2  = $hasChaveNfe  ? 'chave_nfe' : "'' AS chave_nfe";

    $sampleNoVendor = q($pdo,
        "SELECT
             titulo_key,
             numero_documento,
             {$codeCol2},
             {$nameCol2},
             {$pedidoCol2},
             {$chaveCol2},
             status_titulo,
             valor_aberto,
             data_competencia
         FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e
           AND (
               ({$codeCol2} IS NULL OR {$codeCol2} = '' OR {$codeCol2} = '0')
               AND (LOWER(TRIM(COALESCE({$nameCol2}, ''))) IN ({$quotedNames}) OR TRIM(COALESCE({$nameCol2}, '')) = '')
           )
         ORDER BY valor_aberto DESC
         LIMIT 10",
        ['s' => $start, 'e' => $end]
    );
}
$report['sample_titles_without_vendor'] = $sampleNoVendor;

// ──────────────────────────── 13. Root cause assessment ───────────
$rootCauses = [];

// A: no bridge at all
if (!$hasBridgeItems) {
    $rootCauses[] = 'CRITICAL: biomie_bridge_nf_titulo_items table missing — NF linkage not possible via bridge';
}

// B: bridge exists but coverage low
if ($hasBridgeItems && $totalTitulos > 0) {
    $bridgeCovPct = $withNfBridge / $totalTitulos;
    if ($bridgeCovPct < 0.3) {
        $rootCauses[] = sprintf(
            'WARNING: Bridge coverage low — only %s of titles linked via bridge (%.0f%% gap)',
            $withNfBridge,
            (1 - $bridgeCovPct) * 100
        );
    }
}

// C: chave_nfe present but not bridged
if ($withChaveNotBridged > 0) {
    $rootCauses[] = sprintf(
        'OPPORTUNITY: %d titles have chave_nfe but no bridge row — bridge builder may need to re-run or re-match',
        $withChaveNotBridged
    );
}

// D: titles have pedido but no NF
if ($hasPedidoCol && $totalTitulos > 0) {
    $withPedidoNoNf = (int) scalar($pdo,
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t"
        . ($hasBridgeItems ? " LEFT JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key" : "")
        . " WHERE t.data_competencia BETWEEN :s AND :e
              AND (t.pedido_id_omie IS NOT NULL AND t.pedido_id_omie <> '' AND t.pedido_id_omie <> '0')"
        . ($hasNotaKeyCol ? " AND (t.nota_key IS NULL OR t.nota_key = '')" : "")
        . ($hasBridgeItems ? " AND (bi.nota_key IS NULL OR bi.nota_key = '')" : ""),
        ['s' => $start, 'e' => $end]
    );
    if ($withPedidoNoNf > 0) {
        $rootCauses[] = sprintf(
            'OPPORTUNITY: %d titles have pedido_id_omie but no NF linked — mart_bridge_pedido_nfe_titulo may resolve these',
            $withPedidoNoNf
        );
    }
}

// E: vendor gap root cause
if ($vendorDirectMissing > $vendorViaNf) {
    $unrecoverable = $vendorDirectMissing - $vendorViaNf;
    $rootCauses[] = sprintf(
        'GAP: %d titles truly lack vendor info even via NF join — Omie dados may not have vendedor on these orders',
        $unrecoverable
    );
}

// F: mart_bridge_pedido_nfe_titulo missing
if (!$hasMartBridge) {
    $rootCauses[] = 'INFO: mart_bridge_pedido_nfe_titulo does not exist — pedido→NF→título path unavailable';
}

// G: NFs with vendor coverage
if ($hasNfs && isset($nfStats['pct_with_vendor']) && (float) rtrim($nfStats['pct_with_vendor'], '%') < 80) {
    $rootCauses[] = sprintf(
        'WARNING: Only %s of NFs in period have a vendor — source problem in NF canonical data',
        $nfStats['pct_with_vendor']
    );
}

if ($rootCauses === []) {
    $rootCauses[] = 'No critical gaps detected';
}

$report['root_causes'] = $rootCauses;

// ──────────────────────────── 14. Actionable recommendations ─────
$recommendations = [];

if ($withChaveNotBridged > 0) {
    $recommendations[] = [
        'priority' => 'HIGH',
        'action'   => 'Re-run NfTituloBridgeSummaryBuilder to match chave_nfe → nota_key',
        'impact'   => "{$withChaveNotBridged} titles could gain NF linkage",
    ];
}

if ($vendorDirectMissing > 0 && $vendorViaNf > 0) {
    $improvements = min($vendorViaNf, $vendorDirectMissing);
    $recommendations[] = [
        'priority' => 'HIGH',
        'action'   => 'Use NF vendor info as fallback in FinanceReceivableTraceBuilder.resolveVendorInfo()',
        'impact'   => "{$improvements} titles can gain vendor via NF join (already implemented in builder)",
    ];
}

if ($clientDirectMissing > 0 && $clientViaNf > 0) {
    $recommendations[] = [
        'priority' => 'MEDIUM',
        'action'   => 'Use NF client info as fallback in FinanceReceivableTraceBuilder.resolveClientInfo()',
        'impact'   => "{$clientViaNf} titles can gain client via NF join (already implemented in builder)",
    ];
}

if (!$hasMartBridge && $hasPedidos) {
    $recommendations[] = [
        'priority' => 'MEDIUM',
        'action'   => 'Materialize mart_bridge_pedido_nfe_titulo to enable pedido → NF → título path',
        'impact'   => 'Would add pedido context to titles that have pedido_id_omie',
    ];
}

if ($withNfAny < $totalTitulos * 0.5) {
    $recommendations[] = [
        'priority' => 'HIGH',
        'action'   => 'Investigate why > 50% titles lack NF linkage — check bridge builder logic and data coverage',
        'impact'   => 'Affects all vendor/client resolution downstream',
    ];
}

$recommendations[] = [
    'priority' => 'LOW',
    'action'   => 'FIN.R4.3 Phase 2: FinanceTraceIdentityResolver class to centralize NF/Vendor/Client resolution',
    'impact'   => 'Deduplication, unified priority chain, single point of truth',
];

$report['recommendations'] = $recommendations;

// ──────────────────────────── 15. Score ───────────────────────────
$scoreItems = [
    'nf_coverage'     => $totalTitulos > 0 ? ($withNfAny / $totalTitulos) : 0,
    'vendor_coverage' => $totalTitulos > 0 ? ($vendorDirectKnown / $totalTitulos) : 0,
    'client_coverage' => $totalTitulos > 0 ? ($clientDirectKnown / $totalTitulos) : 0,
];

$avgScore = $scoreItems !== [] ? round(array_sum($scoreItems) / count($scoreItems) * 100, 1) : 0;

$report['trace_quality_score'] = [
    'nf_coverage_pct'     => round($scoreItems['nf_coverage'] * 100, 1),
    'vendor_coverage_pct' => round($scoreItems['vendor_coverage'] * 100, 1),
    'client_coverage_pct' => round($scoreItems['client_coverage'] * 100, 1),
    'avg_score'           => $avgScore,
    'grade'               => match (true) {
        $avgScore >= 80 => 'GOOD',
        $avgScore >= 60 => 'FAIR',
        $avgScore >= 40 => 'POOR',
        default         => 'CRITICAL',
    },
];

// ──────────────────────────── output ──────────────────────────────
if ($pretty) {
    printPretty($report);
} else {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

exit(0);

// ──────────────────────────── pretty printer ──────────────────────
function printPretty(array $r): void
{
    $sep = str_repeat('─', 72);
    echo "\n{$sep}\n";
    echo "FIN.R4.3 — FINANCE TRACE LINKAGE GAP REPORT\n";
    echo "Period: {$r['period']['start']} → {$r['period']['end']}\n";
    echo "Generated: {$r['generated_at']}\n";
    echo "{$sep}\n\n";

    // Tables
    echo "TABLES FOUND:\n";
    foreach ($r['tables'] as $name => $exists) {
        $mark = $exists ? '✓' : '✗';
        echo "  [{$mark}] {$name}\n";
    }

    echo "\n── TOTALS ──────────────────────────────────────────────────────\n";
    $t = $r['totals'];
    echo "  Titles in period : {$t['titles_in_period']}\n";
    echo "  Titles open      : {$t['titles_open']}\n";

    echo "\n── NF LINKAGE ──────────────────────────────────────────────────\n";
    $n = $r['nf_linkage'];
    echo "  With NF (any source)  : {$n['with_nf_any_source']} ({$n['pct_with_nf']})\n";
    echo "  Without NF            : {$n['without_nf_any_source']}\n";
    echo "  — via titulo.nota_key : {$n['source_direct_field']['count']} (col: " . ($n['source_direct_field']['col_exists'] ? 'yes' : 'no') . ")\n";
    echo "  — via bridge_items    : {$n['source_bridge_items']['count']} (table: " . ($n['source_bridge_items']['table_exists'] ? 'yes' : 'no') . ")\n";
    echo "  — via mart_bridge     : {$n['source_mart_bridge']['count']} (table: " . ($n['source_mart_bridge']['table_exists'] ? 'yes' : 'no') . ")\n";

    if (!empty($r['bridge_confidence'])) {
        echo "\n── BRIDGE CONFIDENCE ───────────────────────────────────────────\n";
        foreach ($r['bridge_confidence'] as $bc) {
            echo "  {$bc['bucket']} / {$bc['match_method']} : {$bc['cnt']}\n";
        }
    }

    echo "\n── PEDIDO LINKAGE ──────────────────────────────────────────────\n";
    $p = $r['pedido_linkage'];
    echo "  With pedido (direct)  : {$p['with_pedido_direct_field']} (col: " . ($p['pedido_id_col_exists'] ? 'yes' : 'no') . ")\n";
    echo "  With pedido (mart)    : {$p['with_pedido_mart_bridge']} (mart: " . ($p['mart_bridge_exists'] ? 'yes' : 'no') . ")\n";

    echo "\n── VENDOR GAPS ─────────────────────────────────────────────────\n";
    $v = $r['vendor_gaps'];
    echo "  With vendor on título    : {$v['with_vendor_on_titulo']}\n";
    echo "  Without vendor on título : {$v['without_vendor_on_titulo']} ({$v['pct_missing_vendor']})\n";
    echo "  Recoverable via NF       : {$v['with_vendor_via_nf']}\n";

    echo "\n── CLIENT GAPS ─────────────────────────────────────────────────\n";
    $c = $r['client_gaps'];
    echo "  With client on título    : {$c['with_client_on_titulo']}\n";
    echo "  Without client on título : {$c['without_client_on_titulo']} ({$c['pct_missing_client']})\n";
    echo "  Recoverable via NF       : {$c['with_client_via_nf']}\n";

    echo "\n── CHAVE NF-e ──────────────────────────────────────────────────\n";
    $ch = $r['chave_nfe_linkage'];
    echo "  Col exists             : " . ($ch['col_exists'] ? 'yes' : 'no') . "\n";
    echo "  With chave_nfe         : {$ch['with_chave_nfe']} ({$ch['pct_with_chave_nfe']})\n";
    echo "  With chave NOT bridged : {$ch['with_chave_not_bridged']}\n";
    echo "  " . $ch['opportunity'] . "\n";

    if (!empty($r['nf_canonical_health'])) {
        $nfh = $r['nf_canonical_health'];
        echo "\n── NF CANONICAL HEALTH ─────────────────────────────────────────\n";
        echo "  NFs all time    : {$nfh['total_all_time']}\n";
        echo "  NFs in period   : {$nfh['in_period']}\n";
        echo "  NFs with vendor : {$nfh['with_vendor']} ({$nfh['pct_with_vendor']})\n";
    }

    if (!empty($r['documento_origem_distribution'])) {
        echo "\n── DOCUMENTO ORIGEM DISTRIBUTION ───────────────────────────────\n";
        foreach ($r['documento_origem_distribution'] as $d) {
            $tot = number_format((float) ($d['total_aberto'] ?? 0), 2, ',', '.');
            echo "  {$d['tipo']} : {$d['cnt']} titles / R\${$tot} open\n";
        }
    }

    if (!empty($r['sample_titles_without_nf'])) {
        echo "\n── SAMPLE TITLES WITHOUT NF (top by valor_aberto) ──────────────\n";
        foreach ($r['sample_titles_without_nf'] as $s) {
            $val = number_format((float) ($s['valor_aberto'] ?? 0), 2, ',', '.');
            $ped = $s['pedido_id_omie'] ?? '';
            $cha = $s['chave_nfe'] ?? '';
            echo "  [{$s['titulo_key']}] doc:{$s['numero_documento']} ped:{$ped} chave:" . ($cha ? 'YES' : 'no') . " R\${$val} {$s['status_titulo']}\n";
        }
    }

    if (!empty($r['sample_titles_without_vendor'])) {
        echo "\n── SAMPLE TITLES WITHOUT VENDOR (top by valor_aberto) ──────────\n";
        foreach ($r['sample_titles_without_vendor'] as $s) {
            $val = number_format((float) ($s['valor_aberto'] ?? 0), 2, ',', '.');
            echo "  [{$s['titulo_key']}] doc:{$s['numero_documento']} status:{$s['status_titulo']} R\${$val}\n";
        }
    }

    echo "\n── ROOT CAUSES ─────────────────────────────────────────────────\n";
    foreach ($r['root_causes'] as $rc) {
        echo "  • {$rc}\n";
    }

    echo "\n── RECOMMENDATIONS ─────────────────────────────────────────────\n";
    foreach ($r['recommendations'] as $rec) {
        echo "  [{$rec['priority']}] {$rec['action']}\n";
        echo "         Impact: {$rec['impact']}\n";
    }

    echo "\n── TRACE QUALITY SCORE ─────────────────────────────────────────\n";
    $s = $r['trace_quality_score'];
    echo "  NF coverage    : {$s['nf_coverage_pct']}%\n";
    echo "  Vendor coverage: {$s['vendor_coverage_pct']}%\n";
    echo "  Client coverage: {$s['client_coverage_pct']}%\n";
    echo "  Average score  : {$s['avg_score']}% — {$s['grade']}\n";

    echo "\n{$sep}\n";
}
