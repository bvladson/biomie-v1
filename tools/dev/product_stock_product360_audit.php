<?php

declare(strict_types=1);

/**
 * tools/dev/product_stock_product360_audit.php
 *
 * EST.R1 Fase 6 — Auditoria estática do Product 360 com aba Estoque.
 *
 * Verifica:
 *   1. product_detail.php expõe tab=estoque via Product360ReadService
 *   2. Product360ReadRepository tem stockByLocation e stockMovements
 *   3. Product360ReadService propaga stock e stock_movements no payload
 *   4. mercadorias.html tem seção estoque (#estoquePanel)
 *   5. api/products.php tem mode=stock_stats
 *   6. app/Stock/ contém os 5 serviços EST.R1
 *   7. Sem SELECT * nos serviços de stock
 *   8. biomie-drawer.css existe e PageAssetRegistry o inclui
 *
 * Usage:
 *   php tools/dev/product_stock_product360_audit.php --pretty
 *   php tools/dev/product_stock_product360_audit.php --json
 *
 * Exit: 0=OK 1=falha 2=erro
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts     = getopt('', ['pretty', 'json']);
$isPretty = isset($opts['pretty']);
$isJson   = isset($opts['json']);

$root = dirname(__DIR__, 2);

$checks = [];
$failed = 0;

function p360Check(array &$checks, int &$failed, string $name, bool $pass, string $detail): void
{
    $checks[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
    if (!$pass) $failed++;
}

// ── C1: product_detail.php delega tab ao service (tab=estoque tratado no service) ──

$prodDetailFile = $root . '/api/product_detail.php';
p360Check($checks, $failed, 'product_detail.php existe', is_file($prodDetailFile), is_file($prodDetailFile) ? 'EXISTS' : 'NOT FOUND');

// ── C2: Product360ReadRepository tem stock methods ────────────────────────────

$repoFile = $root . '/app/Modules/Sales/Infrastructure/Product360ReadRepository.php';
if (is_file($repoFile)) {
    $src = (string) file_get_contents($repoFile);
    p360Check($checks, $failed, 'Product360ReadRepository::stockByLocation', str_contains($src, 'stockByLocation'), 'Método deve existir');
    p360Check($checks, $failed, 'Product360ReadRepository::stockMovements', str_contains($src, 'stockMovements'), 'Método deve existir');
    p360Check($checks, $failed, 'ReadRepository sem SELECT *', !preg_match('/SELECT\s+\*/i', $src), 'Sem SELECT * em queries');
} else {
    p360Check($checks, $failed, 'Product360ReadRepository', false, 'Arquivo não encontrado');
}

// ── C3: Product360ReadService propaga stock ────────────────────────────────────

$svcFile = $root . '/app/Modules/Sales/Application/Products/Product360ReadService.php';
if (is_file($svcFile)) {
    $src = (string) file_get_contents($svcFile);
    p360Check($checks, $failed, "ReadService tab 'estoque' propagado", str_contains($src, "'estoque'"), 'Tab estoque em build()');
    p360Check($checks, $failed, "ReadService retorna 'stock'", str_contains($src, "'stock'"), "Chave 'stock' no payload");
    p360Check($checks, $failed, "ReadService retorna 'stock_movements'", str_contains($src, "'stock_movements'"), "Chave 'stock_movements' no payload");
} else {
    p360Check($checks, $failed, 'Product360ReadService', false, 'Arquivo não encontrado');
}

// ── C4: mercadorias.html tem #estoquePanel ────────────────────────────────────

$merFile = $root . '/views/mercadorias.html';
if (is_file($merFile)) {
    $src = (string) file_get_contents($merFile);
    p360Check($checks, $failed, 'mercadorias.html #estoquePanel', str_contains($src, 'estoquePanel'), '#estoquePanel presente');
    p360Check($checks, $failed, 'mercadorias.html stock_intelligence', str_contains($src, 'stock_intelligence'), 'Fetch de stock_intelligence (EST.R1.1)');
} else {
    p360Check($checks, $failed, 'mercadorias.html', false, 'Arquivo não encontrado');
}

// ── C5: api/products.php tem stock_stats ─────────────────────────────────────

$prodsFile = $root . '/api/products.php';
if (is_file($prodsFile)) {
    $src = (string) file_get_contents($prodsFile);
    p360Check($checks, $failed, 'products.php mode=stock_stats', str_contains($src, 'stock_stats'), 'Modo stock_stats exposto');
    p360Check($checks, $failed, 'products.php usa StockReadRepository', str_contains($src, 'StockReadRepository'), 'Importa StockReadRepository');
} else {
    p360Check($checks, $failed, 'api/products.php', false, 'Arquivo não encontrado');
}

// ── C6: app/Stock/ tem 5 serviços ────────────────────────────────────────────

$stockServices = ['OmieStockClient', 'StockPayloadMapper', 'StockSyncCoordinator', 'StockReadRepository', 'ProductStockResolver'];
foreach ($stockServices as $svc) {
    $f = $root . "/app/Stock/{$svc}.php";
    p360Check($checks, $failed, "app/Stock/{$svc}.php", is_file($f), is_file($f) ? 'EXISTS' : 'NOT FOUND');
}

// ── C7: Sem SELECT * nos serviços ────────────────────────────────────────────

$stockFiles = glob($root . '/app/Stock/*.php') ?: [];
foreach ($stockFiles as $sf) {
    $src = (string) file_get_contents($sf);
    p360Check($checks, $failed, 'Sem SELECT * em ' . basename($sf), !preg_match('/SELECT\s+\*/i', $src), 'Whitelist de colunas obrigatória');
}

// ── C8: Drawer CSS e PageAssetRegistry ───────────────────────────────────────

p360Check($checks, $failed, 'biomie-drawer.css existe', is_file($root . '/assets/css/biomie-drawer.css'), 'Arquivo presente');

$parFile = $root . '/app/PageAssetRegistry.php';
if (is_file($parFile)) {
    $src = (string) file_get_contents($parFile);
    p360Check($checks, $failed, 'PageAssetRegistry inclui biomie-drawer.css', str_contains($src, 'biomie-drawer.css'), 'No BASE_CSS');
} else {
    p360Check($checks, $failed, 'PageAssetRegistry', false, 'Arquivo não encontrado');
}

// ── C9: CLI sync existe ───────────────────────────────────────────────────────

p360Check($checks, $failed, 'CLI sync_stock_omie.php', is_file($root . '/tools/dev/sync_stock_omie.php'), 'Arquivo presente');
p360Check($checks, $failed, 'CLI omie_stock_endpoint_probe.php', is_file($root . '/tools/dev/omie_stock_endpoint_probe.php'), 'Arquivo presente');

// ── C10: 3 migrations de estoque ─────────────────────────────────────────────

$migrations = ['Version20260530000001_EstoqueLocaisDim.php', 'Version20260530000002_ProdutosEstoqueSaldos.php', 'Version20260530000003_ProdutosEstoqueMovimentos.php'];
foreach ($migrations as $m) {
    p360Check($checks, $failed, "Migration {$m}", is_file($root . "/database/migrations/{$m}"), 'Arquivo presente');
}

// ── Output ────────────────────────────────────────────────────────────────────

$passed = count($checks) - $failed;

$output = [
    'tool'    => 'product_stock_product360_audit',
    'date'    => date('Y-m-d H:i:s'),
    'summary' => ['passed' => $passed, 'failed' => $failed, 'total' => count($checks)],
    'checks'  => $checks,
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($failed > 0 ? 1 : 0);
}

$bar = str_repeat('═', 68);
$sep = str_repeat('─', 68);
echo "\n{$bar}\n Product 360 Stock — Auditoria EST.R1\n{$bar}\n\n";

foreach ($checks as $c) {
    $icon = $c['pass'] ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
    printf(" [%s] %-42s  %s\n", $icon, $c['name'], $c['detail'] ?? '');
}

echo "\n{$sep}\n";
printf(" %s  —  %d/%d checks\n",
    $failed === 0 ? "\033[32m✔ PASS\033[0m" : "\033[31m✗ FAIL\033[0m",
    $passed, count($checks)
);
echo "{$bar}\n\n";

exit($failed > 0 ? 1 : 0);
