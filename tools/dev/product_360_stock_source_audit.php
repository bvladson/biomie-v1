<?php

declare(strict_types=1);

/**
 * tools/dev/product_360_stock_source_audit.php
 *
 * COM.R3 — Fase 0 — Diagnóstico de fontes de estoque para Product 360.
 *
 * Verifica:
 *   - Existência de tabelas de estoque (saldos, movimentos, locais)
 *   - Payload bronze de produtos no Omie (biomie_omie_bronze)
 *   - Campos disponíveis para produto, local, saldo, mínimo, movimentos
 *   - Cobertura de produtos vs NFs do mix
 *
 * NÃO escreve nada. Diagnóstico read-only.
 *
 * Usage:
 *   php tools/dev/product_360_stock_source_audit.php --pretty
 *   php tools/dev/product_360_stock_source_audit.php --json
 *
 * Exit: 0=stock disponível 1=stock ausente (greenfield) 2=erro DB
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$opts     = getopt('', ['pretty', 'json']);
$isPretty = isset($opts['pretty']);
$isJson   = isset($opts['json']);

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

// ── Helper ────────────────────────────────────────────────────────────────────

function ps360TableExists(PDO $pdo, string $t): bool
{
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
        $s->execute(['t' => $t]);
        return (int) $s->fetchColumn() > 0;
    } catch (\Throwable) { return false; }
}

function ps360Columns(PDO $pdo, string $t): array
{
    try {
        $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION');
        $s->execute(['t' => $t]);
        return $s->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable) { return []; }
}

function ps360Count(PDO $pdo, string $t): int
{
    try { return (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
    catch (\Throwable) { return -1; }
}

// ── Check stock tables ────────────────────────────────────────────────────────

$stockTables = [
    'biomie_produtos_estoque_saldos'    => 'Saldos de estoque por local',
    'biomie_produtos_estoque_movimentos' => 'Movimentos de estoque',
    'biomie_estoque_locais_dim'          => 'Dimensão de locais de estoque',
    // Alternative names that might already exist
    'biomie_estoque_saldos'             => 'Saldos (nome alternativo)',
    'biomie_estoque_movimentacoes'      => 'Movimentos (nome alternativo)',
    'biomie_estoque_locais'             => 'Locais (nome alternativo)',
    'biomie_produtos_estoque'           => 'Estoque genérico',
];

$stockResults = [];
foreach ($stockTables as $table => $desc) {
    $exists  = ps360TableExists($pdo, $table);
    $columns = $exists ? ps360Columns($pdo, $table) : [];
    $rows    = $exists ? ps360Count($pdo, $table) : 0;
    $stockResults[$table] = [
        'description' => $desc,
        'exists'      => $exists,
        'columns'     => $columns,
        'row_count'   => $rows,
    ];
}

// ── Check Omie bronze for product/stock payloads ──────────────────────────────

$hasBronze = ps360TableExists($pdo, 'biomie_omie_bronze');
$bronzeEndpoints = [];

if ($hasBronze) {
    $bronzeColumns = ps360Columns($pdo, 'biomie_omie_bronze');

    $endpointSearch = [
        'ConsultarEstoque', 'ConsultarMovimentoEstoque', 'ConsultarLocaisEstoque',
        'ResumoEstoque', 'ListarProdutos', 'ConsultarProduto',
        'ListarEstoques', 'ObterEstoque',
    ];

    $endpointCol = in_array('endpoint', $bronzeColumns, true) ? 'endpoint'
        : (in_array('action', $bronzeColumns, true) ? 'action'
        : (in_array('method', $bronzeColumns, true) ? 'method' : null));

    if ($endpointCol !== null) {
        foreach ($endpointSearch as $ep) {
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM biomie_omie_bronze WHERE {$endpointCol} LIKE :ep LIMIT 1");
                $s->execute(['ep' => "%{$ep}%"]);
                $cnt = (int) $s->fetchColumn();
                if ($cnt > 0) $bronzeEndpoints[] = ['endpoint' => $ep, 'rows' => $cnt];
            } catch (\Throwable) {}
        }
    }
}

// ── Check product coverage ────────────────────────────────────────────────────

$hasItensTable = ps360TableExists($pdo, 'biomie_notas_fiscais_itens_canonicos');
$hasProdMart   = ps360TableExists($pdo, 'mart_produto_periodo');

$productStats = [];
if ($hasItensTable) {
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(DISTINCT codigo_produto) AS produtos_distintos,
                    COUNT(*) AS total_itens
             FROM biomie_notas_fiscais_itens_canonicos
             WHERE codigo_produto IS NOT NULL AND codigo_produto <> ''"
        );
        $productStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable) {}
}

if ($hasProdMart) {
    try {
        $stmt = $pdo->query("SELECT COUNT(DISTINCT codigo_produto) AS produtos_no_mart FROM mart_produto_periodo");
        $productStats['produtos_no_mart'] = (int)$stmt->fetchColumn();
    } catch (\Throwable) {}
}

// ── Determine status ──────────────────────────────────────────────────────────

$anyStockTableExists = !empty(array_filter($stockResults, fn($r) => $r['exists']));
$anyStockHasData     = !empty(array_filter($stockResults, fn($r) => $r['exists'] && $r['row_count'] > 0));

$status = 'greenfield';
if ($anyStockHasData) {
    $status = 'stock_available';
} elseif ($anyStockTableExists) {
    $status = 'tables_empty';
}

// ── Output ────────────────────────────────────────────────────────────────────

$output = [
    'tool'                => 'product_360_stock_source_audit',
    'status'              => $status,
    'stock_tables'        => $stockResults,
    'bronze_stock_endpoints' => $bronzeEndpoints,
    'has_omie_bronze'     => $hasBronze,
    'product_coverage'    => $productStats,
    'recommendations'     => [],
];

if ($status === 'greenfield') {
    $output['recommendations'][] = 'Criar 3 migrations: biomie_produtos_estoque_saldos, biomie_produtos_estoque_movimentos, biomie_estoque_locais_dim';
    $output['recommendations'][] = 'api/product_detail.php tab=stock deve mostrar empty_state "Estoque ainda não sincronizado"';
    $output['recommendations'][] = 'Sync via Omie ConsultarEstoque/ConsultarLocaisEstoque pode ser implementado em COM.R4+';
} elseif ($status === 'tables_empty') {
    $output['recommendations'][] = 'Tabelas criadas mas sem dados — executar sync Omie';
} else {
    $output['recommendations'][] = 'Dados disponíveis — Product 360 pode exibir estoque real';
}

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($anyStockHasData ? 0 : 1);
}

$bar = str_repeat('═', 70);
$sep = str_repeat('─', 70);
echo "\n{$bar}\n Product 360 — Diagnóstico de Fontes de Estoque\n{$bar}\n";
echo " Status : " . match($status) {
    'stock_available' => "\033[32mDados disponíveis\033[0m",
    'tables_empty'    => "\033[33mTabelas criadas, sem dados\033[0m",
    default           => "\033[31mGreenfield — tabelas não existem\033[0m",
} . "\n";
echo "{$sep}\n\n";

echo " Tabelas de estoque:\n";
foreach ($stockResults as $table => $info) {
    $icon = $info['exists'] ? ($info['row_count'] > 0 ? "\033[32m✔\033[0m" : "\033[33m~\033[0m") : "\033[31m✗\033[0m";
    $detail = $info['exists'] ? " ({$info['row_count']} rows)" : ' [ausente]';
    printf("   {$icon} %-48s%s\n", $table, $detail);
    if ($info['exists'] && !empty($info['columns'])) {
        echo "     Colunas: " . implode(', ', array_slice($info['columns'], 0, 8)) . (count($info['columns']) > 8 ? '...' : '') . "\n";
    }
}

echo "\n Omie Bronze:\n";
echo "   Tabela : " . ($hasBronze ? "\033[32mOK\033[0m" : "\033[31mausente\033[0m") . "\n";
if (!empty($bronzeEndpoints)) {
    echo "   Endpoints estoque encontrados:\n";
    foreach ($bronzeEndpoints as $ep) {
        printf("     ✔ %-40s %d rows\n", $ep['endpoint'], $ep['rows']);
    }
} else {
    echo "   Sem payloads de estoque no bronze\n";
}

echo "\n Produtos:\n";
printf("   Distintos em itens NF  : %d\n", (int)($productStats['produtos_distintos'] ?? 0));
printf("   Itens canonicos total  : %d\n", (int)($productStats['total_itens'] ?? 0));
printf("   No mart_produto_periodo: %d\n", (int)($productStats['produtos_no_mart'] ?? 0));

echo "\n Recomendações:\n";
foreach ($output['recommendations'] as $rec) {
    echo "   → {$rec}\n";
}

echo "\n{$bar}\n\n";
exit($anyStockHasData ? 0 : 1);
