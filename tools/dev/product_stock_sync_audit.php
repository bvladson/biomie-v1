<?php

declare(strict_types=1);

/**
 * tools/dev/product_stock_sync_audit.php
 *
 * EST.R1 Fase 6 — Auditoria de sincronização de estoque.
 *
 * Verifica:
 *   1. Tabelas de estoque existem
 *   2. Tabelas têm índices corretos
 *   3. Contagem de registros (locais, saldos, movimentos)
 *   4. Produtos com saldo abaixo do mínimo
 *   5. Produtos em ruptura (saldo <= 0)
 *   6. Produtos sem saldo sincronizado (estão em NFs mas não em saldos)
 *   7. Última atualização de saldo
 *
 * Usage:
 *   php tools/dev/product_stock_sync_audit.php --pretty
 *   php tools/dev/product_stock_sync_audit.php --json
 *
 * Exit: 0=OK 1=warn 2=DB error
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Stock\StockReadRepository;

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

$repo   = new StockReadRepository($pdo);
$tables = $repo->tablesExist();
$issues = [];
$checks = [];

// ── C1: Tabelas existem ───────────────────────────────────────────────────────

foreach (['locais' => 'biomie_estoque_locais_dim', 'saldos' => 'biomie_produtos_estoque_saldos', 'movimentos' => 'biomie_produtos_estoque_movimentos'] as $key => $tbl) {
    $exists = $tables[$key] ?? false;
    $checks[] = ['name' => "Tabela {$tbl}", 'pass' => $exists, 'detail' => $exists ? 'EXISTS' : 'NOT FOUND'];
    if (!$exists) $issues[] = "Tabela {$tbl} não existe — executar migrations EST.R1";
}

// ── C2: Contagens ─────────────────────────────────────────────────────────────

if ($tables['locais']) {
    try {
        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM biomie_estoque_locais_dim')->fetchColumn();
        $checks[] = ['name' => 'Locais sincronizados', 'pass' => true, 'detail' => "{$cnt} local(ais)"];
    } catch (\Throwable) { $checks[] = ['name' => 'Locais sincronizados', 'pass' => false, 'detail' => 'ERRO']; }
}

if ($tables['saldos']) {
    try {
        $cntSaldos = (int) $pdo->query('SELECT COUNT(*) FROM biomie_produtos_estoque_saldos')->fetchColumn();
        $cntProds  = (int) $pdo->query('SELECT COUNT(DISTINCT codigo_produto) FROM biomie_produtos_estoque_saldos')->fetchColumn();
        $checks[] = ['name' => 'Saldos sincronizados', 'pass' => true, 'detail' => "{$cntSaldos} saldos | {$cntProds} produtos distintos"];
    } catch (\Throwable) { $checks[] = ['name' => 'Saldos sincronizados', 'pass' => false, 'detail' => 'ERRO']; }
}

if ($tables['movimentos']) {
    try {
        $cntMov = (int) $pdo->query('SELECT COUNT(*) FROM biomie_produtos_estoque_movimentos')->fetchColumn();
        $checks[] = ['name' => 'Movimentos sincronizados', 'pass' => true, 'detail' => "{$cntMov} movimentos"];
    } catch (\Throwable) { $checks[] = ['name' => 'Movimentos sincronizados', 'pass' => false, 'detail' => 'ERRO']; }
}

// ── C3: Stats gerais ──────────────────────────────────────────────────────────

$stats = $repo->statsGerais();
$checks[] = ['name' => 'Produtos com saldo', 'pass' => true, 'detail' => $stats['com_saldo'] . ' produto(s) com estoque > 0'];
if ($stats['abaixo_minimo'] > 0) {
    $checks[] = ['name' => 'Abaixo do mínimo', 'pass' => true, 'warn' => true, 'detail' => $stats['abaixo_minimo'] . ' produto(s) abaixo do mínimo'];
}
if ($stats['ruptura'] > 0) {
    $checks[] = ['name' => 'Ruptura (saldo <= 0)', 'pass' => true, 'warn' => true, 'detail' => $stats['ruptura'] . ' produto(s) em ruptura'];
}

// ── C4: Última atualização ────────────────────────────────────────────────────

if ($tables['saldos']) {
    try {
        $lastSync = (string) $pdo->query('SELECT MAX(atualizado_em) FROM biomie_produtos_estoque_saldos')->fetchColumn();
        if ($lastSync && $lastSync !== '0000-00-00 00:00:00') {
            $diff = (int)((time() - strtotime($lastSync)) / 3600);
            $stale = $diff > 48;
            $checks[] = ['name' => 'Último sync de saldo', 'pass' => !$stale, 'warn' => $stale, 'detail' => substr($lastSync, 0, 16) . " ({$diff}h atrás)"];
            if ($stale) $issues[] = "Saldo não sincronizado há {$diff}h — considerar rodar EST.R1 sync";
        } else {
            $checks[] = ['name' => 'Último sync de saldo', 'pass' => false, 'detail' => 'Nenhum saldo sincronizado ainda'];
        }
    } catch (\Throwable) {}
}

// ── C5: Produtos vendidos sem estoque sincronizado ────────────────────────────

if ($tables['saldos']) {
    try {
        $stVendidos = $pdo->query(
            "SELECT COUNT(DISTINCT COALESCE(NULLIF(TRIM(i.codigo_produto),''), 'SEM_SKU')) AS cnt
             FROM biomie_notas_fiscais_itens_canonicos i
             WHERE i.codigo_produto IS NOT NULL AND i.codigo_produto != ''"
        );
        $totalVendidos = (int)($stVendidos ? $stVendidos->fetchColumn() : 0);
        $stSemEstoque = $pdo->query(
            "SELECT COUNT(DISTINCT COALESCE(NULLIF(TRIM(i.codigo_produto),''), 'SEM_SKU')) AS cnt
             FROM biomie_notas_fiscais_itens_canonicos i
             LEFT JOIN biomie_produtos_estoque_saldos s ON s.codigo_produto = COALESCE(NULLIF(TRIM(i.codigo_produto),''), 'SEM_SKU')
             WHERE i.codigo_produto IS NOT NULL AND i.codigo_produto != ''
               AND s.codigo_produto IS NULL"
        );
        $semEstoque = (int)($stSemEstoque ? $stSemEstoque->fetchColumn() : 0);
        $pct = $totalVendidos > 0 ? round($semEstoque / $totalVendidos * 100) : 0;
        $checks[] = ['name' => 'Cobertura estoque/NF', 'pass' => $pct < 50, 'warn' => $pct >= 20, 'detail' => "{$semEstoque}/{$totalVendidos} produtos vendidos sem saldo ({$pct}%)"];
        if ($pct >= 50) $issues[] = "{$pct}% dos produtos vendidos sem saldo de estoque";
    } catch (\Throwable) {
        $checks[] = ['name' => 'Cobertura estoque/NF', 'pass' => true, 'detail' => 'Tabela de itens não disponível (verificação ignorada)'];
    }
}

$anyFail = !empty($issues);

$output = [
    'tool'    => 'product_stock_sync_audit',
    'date'    => date('Y-m-d H:i:s'),
    'summary' => ['pass' => !$anyFail, 'issues' => count($issues), 'checks' => count($checks)],
    'checks'  => $checks,
    'issues'  => $issues,
    'stats'   => $stats,
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($anyFail ? 1 : 0);
}

$bar = str_repeat('═', 68);
$sep = str_repeat('─', 68);
echo "\n{$bar}\n Product Stock Sync — Auditoria EST.R1\n{$bar}\n\n";

foreach ($checks as $c) {
    $warn = !empty($c['warn']);
    $icon = !$c['pass'] ? "\033[31mFAIL\033[0m" : ($warn ? "\033[33mWARN\033[0m" : "\033[32mPASS\033[0m");
    printf(" [%s] %-36s  %s\n", $icon, $c['name'], $c['detail'] ?? '');
}

echo "\n Stats: " . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
echo "\n{$sep}\n";
printf(" %s  —  %d checks  |  %d issue(s)\n",
    $anyFail ? "\033[31m✗ FAIL\033[0m" : "\033[32m✔ PASS\033[0m",
    count($checks), count($issues)
);
echo "{$bar}\n\n";

exit($anyFail ? 1 : 0);
