<?php

declare(strict_types=1);

/**
 * tools/dev/nf_periodo_performance_audit.php
 *
 * COM.R3 — Fase 0 — Diagnóstico de performance da página NF do Período.
 *
 * Detecta:
 *   - Número de NFs no período (vs limite de renderização segura)
 *   - Queries sem LIMIT que retornam todas as linhas
 *   - Colunas usadas em ORDER BY sem índice provável
 *   - Endpoints que retornam tudo sem paginação
 *   - Recomendação de paginação server-side
 *
 * Usage:
 *   php tools/dev/nf_periodo_performance_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
 *
 * Exit: 0=dentro do limite 1=acima do limite (paginação necessária) 2=erro DB
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\BiVisibilityScope;

$opts      = getopt('', ['start:', 'end:', 'pretty', 'json']);
$startDate = isset($opts['start']) ? trim((string) $opts['start']) : date('Y-01-01');
$endDate   = isset($opts['end'])   ? trim((string) $opts['end'])   : date('Y-m-d');
$isPretty  = isset($opts['pretty']);
$isJson    = isset($opts['json']);

const NF_SAFE_LIMIT     = 200;   // NFs máximas sem paginação
const NF_CRITICAL_LIMIT = 1000;  // Acima disso = crítico

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    $msg = 'ERRO DB: ' . $e->getMessage();
    echo ($isJson ? json_encode(['error' => $msg]) : $msg) . "\n";
    exit(2);
}

// ── 1. Contagem de NFs no período ─────────────────────────────────────────────

$visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');

$stmtCount = $pdo->prepare(
    "SELECT COUNT(*) AS total_nfs,
            COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes,
            COUNT(DISTINCT nf.nome_vendedor) AS vendedores
     FROM biomie_notas_fiscais_canonicas nf
     WHERE nf.status_fiscal = 'autorizada'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e"
);
$stmtCount->execute([':s' => $startDate, ':e' => $endDate]);
$countRow = $stmtCount->fetch(PDO::FETCH_ASSOC) ?: [];
$totalNfs = (int)($countRow['total_nfs'] ?? 0);

// ── 2. Breakdown por escopo ────────────────────────────────────────────────────

$stmtScopes = $pdo->prepare(
    "SELECT operation_scope, COUNT(*) AS nfs
     FROM biomie_notas_fiscais_canonicas nf
     WHERE status_fiscal = 'autorizada'
       AND COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e
     GROUP BY operation_scope
     ORDER BY nfs DESC"
);
$stmtScopes->execute([':s' => $startDate, ':e' => $endDate]);
$byScope = $stmtScopes->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Verificar índices em biomie_notas_fiscais_canonicas ────────────────────

$indexCheck = [];
try {
    $stmt = $pdo->query("SHOW INDEX FROM biomie_notas_fiscais_canonicas");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $indexedCols = array_unique(array_column($indexes, 'Column_name'));
    $criticalCols = ['data_competencia', 'data_emissao', 'status_fiscal', 'operation_scope', 'codigo_cliente_omie', 'nome_vendedor', 'nota_key'];
    foreach ($criticalCols as $col) {
        $indexCheck[$col] = in_array($col, $indexedCols, true) ? 'indexed' : 'not_indexed';
    }
} catch (\Throwable) {
    $indexCheck = ['_error' => 'Não foi possível verificar índices'];
}

// ── 4. Checar se orders_search.php usa paginação ──────────────────────────────

$projectRoot = dirname(__DIR__, 2);
$ordersSearchFile = $projectRoot . '/api/orders_search.php';
$nfPeriodoTableFile = $projectRoot . '/api/nf_periodo_table.php';
$pedidosHtml = $projectRoot . '/views/pedidos.html';

$apiAudit = [];

if (file_exists($ordersSearchFile)) {
    $src = file_get_contents($ordersSearchFile);
    $apiAudit['orders_search.php'] = [
        'exists'         => true,
        'has_limit'      => str_contains($src, 'LIMIT') || str_contains($src, 'limit'),
        'has_offset'     => str_contains($src, 'OFFSET') || str_contains($src, 'offset'),
        'has_pagination' => str_contains($src, 'page') || str_contains($src, 'per_page'),
        'select_star'    => (bool) preg_match('/SELECT\s+\*\s+FROM/i', $src),
    ];
} else {
    $apiAudit['orders_search.php'] = ['exists' => false];
}

$apiAudit['nf_periodo_table.php'] = ['exists' => file_exists($nfPeriodoTableFile)];

$htmlAudit = [];
if (file_exists($pedidosHtml)) {
    $html = file_get_contents($pedidosHtml);
    $htmlAudit['pedidos.html'] = [
        'exists'           => true,
        'has_server_side'  => str_contains($html, 'serverSide')
                           || str_contains($html, 'server_side')
                           || str_contains($html, 'server-side')
                           || str_contains($html, 'NfPeriodoTable'),
        'has_datatable'    => str_contains($html, 'DataTable') || str_contains($html, 'datatable'),
        'has_pagination'   => str_contains($html, 'pagination'),
        'has_fixed_height' => str_contains($html, 'max-height') || str_contains($html, 'overflow-y'),
        'estimated_dom_rows' => $totalNfs,
    ];
} else {
    $htmlAudit['pedidos.html'] = ['exists' => false];
}

// ── 5. Estimate query time ────────────────────────────────────────────────────

$queryTimingMs = null;
try {
    $t0 = microtime(true);
    $stmt = $pdo->prepare(
        "SELECT nf.nota_key, nf.numero_nf, COALESCE(nf.data_competencia, nf.data_emissao) AS data_ref,
                nf.nome_cliente, nf.nome_vendedor, nf.operation_scope,
                COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias) AS base_comercial
         FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
         ORDER BY COALESCE(nf.data_competencia, nf.data_emissao) DESC
         LIMIT 50"
    );
    $stmt->execute([':s' => $startDate, ':e' => $endDate]);
    $stmt->fetchAll();
    $queryTimingMs = round((microtime(true) - $t0) * 1000, 1);
} catch (\Throwable) {}

// ── 6. Recommendations ────────────────────────────────────────────────────────

$recommendations = [];
$severity = 'ok';

$serverSideActive = ($htmlAudit['pedidos.html']['has_server_side'] ?? false)
                 && ($apiAudit['nf_periodo_table.php']['exists'] ?? false);

if ($totalNfs > NF_CRITICAL_LIMIT && !$serverSideActive) {
    $severity = 'critical';
    $recommendations[] = "CRÍTICO: {$totalNfs} NFs no período. Renderizar tudo no DOM vai travar o browser.";
    $recommendations[] = "Implementar api/nf_periodo_table.php com LIMIT/OFFSET server-side.";
    $recommendations[] = "pedidos.html deve receber apenas 25–50 linhas por vez.";
} elseif ($totalNfs > NF_SAFE_LIMIT && !$serverSideActive) {
    $severity = 'warning';
    $recommendations[] = "ATENÇÃO: {$totalNfs} NFs no período. Acima do limite seguro de " . NF_SAFE_LIMIT . ".";
    $recommendations[] = "Implementar paginação server-side em api/nf_periodo_table.php.";
} elseif ($totalNfs > NF_SAFE_LIMIT && $serverSideActive) {
    $recommendations[] = "OK: {$totalNfs} NFs no período — server-side ativo, pedidos.html pagina corretamente.";
}

if (!($apiAudit['nf_periodo_table.php']['exists'] ?? false)) {
    $recommendations[] = "api/nf_periodo_table.php não existe — criar endpoint paginado dedicado.";
}

$notIndexed = array_keys(array_filter($indexCheck, fn($v) => $v === 'not_indexed'));
if (!empty($notIndexed)) {
    $recommendations[] = "Colunas sem índice: " . implode(', ', $notIndexed) . " — adicionar índices para queries de NF.";
}

// ── Output ────────────────────────────────────────────────────────────────────

$output = [
    'tool'          => 'nf_periodo_performance_audit',
    'period'        => ['start' => $startDate, 'end' => $endDate],
    'severity'      => $severity,
    'total_nfs'     => $totalNfs,
    'safe_limit'    => NF_SAFE_LIMIT,
    'critical_limit'=> NF_CRITICAL_LIMIT,
    'by_scope'      => $byScope,
    'index_audit'   => $indexCheck,
    'api_audit'     => $apiAudit,
    'html_audit'    => $htmlAudit,
    'query_timing_ms' => $queryTimingMs,
    'recommendations' => $recommendations,
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($totalNfs > NF_SAFE_LIMIT ? 1 : 0);
}

$bar = str_repeat('═', 70);
$sep = str_repeat('─', 70);
echo "\n{$bar}\n NF Período — Diagnóstico de Performance\n{$bar}\n";
echo " Período  : {$startDate} → {$endDate}\n";
echo " Status   : " . match($severity) {
    'critical' => "\033[31mCRÍTICO\033[0m",
    'warning'  => "\033[33mATENÇÃO\033[0m",
    default    => "\033[32mOK\033[0m",
} . "\n";
echo "{$sep}\n\n";

printf(" Total NFs no período : %d  (limite seguro: %d, crítico: %d)\n", $totalNfs, NF_SAFE_LIMIT, NF_CRITICAL_LIMIT);
if ($queryTimingMs !== null) {
    printf(" Query timing (LIMIT 50): %.1f ms\n", $queryTimingMs);
}

echo "\n Por escopo:\n";
foreach ($byScope as $row) {
    printf("   %-20s : %d NFs\n", (string)($row['operation_scope'] ?? '?'), (int)($row['nfs'] ?? 0));
}

echo "\n Índices em biomie_notas_fiscais_canonicas:\n";
foreach ($indexCheck as $col => $status) {
    if ($col === '_error') { echo "   ERRO: {$status}\n"; continue; }
    $icon = $status === 'indexed' ? "\033[32m✔\033[0m" : "\033[33m✗\033[0m";
    printf("   {$icon} %-35s %s\n", $col, $status);
}

echo "\n APIs e HTML:\n";
foreach ($apiAudit as $file => $info) {
    $icon = ($info['exists'] ?? false) ? "\033[32m✔\033[0m" : "\033[31m✗\033[0m";
    echo "   {$icon} {$file}";
    if ($info['exists'] ?? false) {
        $features = [];
        if ($info['has_limit'] ?? false) $features[] = 'LIMIT';
        if ($info['has_pagination'] ?? false) $features[] = 'pagination';
        if ($info['select_star'] ?? false) $features[] = "\033[33mSELECT*\033[0m";
        if (!empty($features)) echo " [" . implode(', ', $features) . "]";
    }
    echo "\n";
}
foreach ($htmlAudit as $file => $info) {
    $icon = ($info['exists'] ?? false) ? "\033[32m✔\033[0m" : "\033[31m✗\033[0m";
    echo "   {$icon} {$file}";
    if ($info['exists'] ?? false) {
        $feat = [];
        if ($info['has_server_side'] ?? false) $feat[] = 'server-side';
        if ($info['has_pagination'] ?? false) $feat[] = 'pagination';
        if ($info['has_fixed_height'] ?? false) $feat[] = 'fixed-height';
        if (!($info['has_server_side'] ?? false) && ($info['estimated_dom_rows'] ?? 0) > NF_SAFE_LIMIT) {
            $feat[] = "\033[31mDOM=" . ($info['estimated_dom_rows'] ?? 0) . " linhas\033[0m";
        }
        if (!empty($feat)) echo " [" . implode(', ', $feat) . "]";
    }
    echo "\n";
}

if (!empty($recommendations)) {
    echo "\n Recomendações:\n";
    foreach ($recommendations as $rec) {
        echo "   → {$rec}\n";
    }
}

echo "\n{$bar}\n\n";
exit($totalNfs > NF_SAFE_LIMIT ? 1 : 0);
