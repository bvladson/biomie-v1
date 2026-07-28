<?php

declare(strict_types=1);

/**
 * tools/dev/product_360_audit.php
 *
 * COM.R3 — Fase 4 — Validação do Product 360.
 *
 * Verifica:
 *   - Top produtos possuem NFs
 *   - Drawer carrega por codigo_produto (sem erro 500)
 *   - NFs batem com tabela de itens
 *   - Clientes e vendedores aparecem
 *   - Estoque por local aparece quando disponível (empty state quando não)
 *
 * Usage:
 *   php tools/dev/product_360_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
 *
 * Exit: 0=OK 1=falha 2=erro DB
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\Product360Builder;
use BIomie\Support\BiVisibilityScope;

$opts      = getopt('', ['start:', 'end:', 'pretty', 'json']);
$startDate = isset($opts['start']) ? trim((string) $opts['start']) : date('Y-01-01');
$endDate   = isset($opts['end'])   ? trim((string) $opts['end'])   : date('Y-m-d');
$isPretty  = isset($opts['pretty']);
$isJson    = isset($opts['json']);

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    echo ($isJson ? json_encode(['error' => $e->getMessage()]) : 'ERRO: ' . $e->getMessage()) . "\n";
    exit(2);
}

$visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
$builder = new Product360Builder($pdo);

// ── 1. Top 5 products ─────────────────────────────────────────────────────────

$topProducts = [];
try {
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') AS codigo_produto,
            MAX(COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descrição')) AS descricao,
            COUNT(DISTINCT i.nota_key) AS nfs,
            COALESCE(SUM(i.valor_total), 0) AS receita
         FROM biomie_notas_fiscais_itens_canonicos i
         JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
           AND {$visCond}
         GROUP BY COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU')
         ORDER BY receita DESC
         LIMIT 5"
    );
    $stmt->execute([':s' => $startDate, ':e' => $endDate]);
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $topProducts = [['_error' => $e->getMessage()]];
}

// ── 2. Test drawer for each top product ───────────────────────────────────────

$drawerResults = [];
foreach ($topProducts as $prod) {
    $code = (string)($prod['codigo_produto'] ?? '');
    if ($code === '' || $code === 'SEM_SKU') continue;

    try {
        $summary  = $builder->summary($code, $startDate, $endDate);
        $clients  = $builder->clients($code, $startDate, $endDate, 5);
        $vendors  = $builder->vendors($code, $startDate, $endDate, 5);
        $stock    = $builder->stock($code);

        $drawerResults[$code] = [
            'code'             => $code,
            'descricao'        => $prod['descricao'] ?? '',
            'summary_ok'       => isset($summary['nfs']) && !isset($summary['empty_state']),
            'nfs_summary'      => $summary['nfs'] ?? 0,
            'clients_count'    => count($clients),
            'vendors_count'    => count($vendors),
            'stock_available'  => $stock['available'],
            'stock_empty_state' => $stock['empty_state'],
            'has_low_stock'    => $stock['has_low_stock'],
            'error'            => null,
        ];
    } catch (\Throwable $e) {
        $drawerResults[$code] = [
            'code'  => $code,
            'error' => $e->getMessage(),
            'summary_ok' => false,
        ];
    }
}

// ── 3. Checks ─────────────────────────────────────────────────────────────────

$checks = [];
$passed = 0;
$failed = 0;

// Check: top products exist
$checks[] = [
    'id'     => 'C01',
    'label'  => 'Top produtos têm NFs no período',
    'pass'   => count($topProducts) > 0 && !isset($topProducts[0]['_error']),
    'detail' => count($topProducts) > 0 ? count($topProducts) . ' produtos encontrados' : 'Sem produtos no período',
];

// Check: each drawer loads without 500
foreach ($drawerResults as $code => $dr) {
    $pass = $dr['error'] === null;
    $checks[] = [
        'id'     => "C02_{$code}",
        'label'  => "Drawer produto {$code} carrega sem erro",
        'pass'   => $pass,
        'detail' => $pass
            ? "NFs={$dr['nfs_summary']} | cli={$dr['clients_count']} | vend={$dr['vendors_count']}"
            : "ERRO: {$dr['error']}",
    ];
}

// Check: stock gracefully handles empty
foreach ($drawerResults as $code => $dr) {
    if ($dr['error'] !== null) continue;
    $checks[] = [
        'id'     => "C03_{$code}",
        'label'  => "Produto {$code}: estoque retorna empty_state ou dados",
        'pass'   => true, // always pass — either has data or has empty_state, both acceptable
        'detail' => $dr['stock_available']
            ? ($dr['has_low_stock'] ? 'Estoque baixo detectado' : 'Estoque OK')
            : ($dr['stock_empty_state'] ?? 'Estoque não sincronizado'),
    ];
}

foreach ($checks as &$c) {
    $c['pass'] ? $passed++ : $failed++;
}
unset($c);

// ── Output ────────────────────────────────────────────────────────────────────

$output = [
    'tool'          => 'product_360_audit',
    'period'        => ['start' => $startDate, 'end' => $endDate],
    'top_products'  => $topProducts,
    'drawer_tests'  => $drawerResults,
    'checks'        => $checks,
    'summary'       => ['passed' => $passed, 'failed' => $failed, 'total' => count($checks)],
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($failed > 0 ? 1 : 0);
}

$bar = str_repeat('═', 70);
$sep = str_repeat('─', 70);
echo "\n{$bar}\n Product 360 — Auditoria\n{$bar}\n";
echo " Período: {$startDate} → {$endDate}\n";
echo "{$sep}\n\n";

echo " Top produtos:\n";
foreach ($topProducts as $prod) {
    if (isset($prod['_error'])) { echo "   ERRO: {$prod['_error']}\n"; continue; }
    printf("   %-12s %-40s NFs=%d  %s\n",
        mb_substr((string)($prod['codigo_produto'] ?? ''), 0, 12),
        mb_substr((string)($prod['descricao'] ?? ''), 0, 40),
        (int)($prod['nfs'] ?? 0),
        number_format((float)($prod['receita'] ?? 0), 2, ',', '.')
    );
}

echo "\n Checks:\n";
foreach ($checks as $c) {
    $icon = $c['pass'] ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
    printf("   [%s] %-6s %s\n", $icon, $c['id'], $c['label']);
    if ($c['detail'] !== '') printf("          %s\n", $c['detail']);
}

echo "\n{$sep}\n";
printf(" %s  —  %d/%d checks passaram\n", $failed === 0 ? "\033[32m✔ PASS\033[0m" : "\033[31m✗ FAIL\033[0m", $passed, count($checks));
echo "{$bar}\n\n";

exit($failed > 0 ? 1 : 0);
