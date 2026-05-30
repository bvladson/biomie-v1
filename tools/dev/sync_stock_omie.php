<?php

declare(strict_types=1);

/**
 * tools/dev/sync_stock_omie.php
 *
 * EST.R1 Fase 3 — CLI de sincronização de estoque Omie → DB.
 *
 * SEMPRE inicia em --dry-run. Requer --apply explícito para persistir.
 *
 * Usage:
 *   php tools/dev/sync_stock_omie.php --dry-run --locations --pretty
 *   php tools/dev/sync_stock_omie.php --dry-run --balances --pretty
 *   php tools/dev/sync_stock_omie.php --dry-run --movements --start=2026-01-01 --end=2026-05-29 --pretty
 *   php tools/dev/sync_stock_omie.php --apply --locations --balances --pretty
 *   php tools/dev/sync_stock_omie.php --apply --movements --start=2026-01-01 --end=2026-05-29 --limit=500
 *   php tools/dev/sync_stock_omie.php --apply --product-code=123 --balances --movements --start=2026-01-01 --end=2026-05-29 --pretty
 *
 * Exit: 0=OK 1=erros parciais 2=erro fatal
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\Stock\OmieStockClient;
use BIomie\Stock\StockSyncCoordinator;

$opts        = getopt('', ['dry-run', 'apply', 'pretty', 'json', 'locations', 'balances', 'movements', 'product-code:', 'start:', 'end:', 'limit:']);
$isDryRun    = !isset($opts['apply']);
$isPretty    = isset($opts['pretty']);
$isJson      = isset($opts['json']);
$doLocations = isset($opts['locations']);
$doBalances  = isset($opts['balances']);
$doMovements = isset($opts['movements']);
$productCode = isset($opts['product-code']) ? trim((string) $opts['product-code']) : '';
$dateFrom    = isset($opts['start']) ? trim((string) $opts['start']) : date('Y-m-01');
$dateTo      = isset($opts['end'])   ? trim((string) $opts['end'])   : date('Y-m-d');
$limit       = isset($opts['limit']) ? max(1, (int) $opts['limit'])  : 0;

if (!$doLocations && !$doBalances && !$doMovements) {
    fwrite(STDERR, "Especifique ao menos uma flag: --locations, --balances, --movements\n");
    exit(2);
}

// ── Setup ─────────────────────────────────────────────────────────────────────

try {
    $config    = new Config();
    $conn      = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo       = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $appKey    = (string) $config->get('omie.app_key',    '');
    $appSecret = (string) $config->get('omie.app_secret', '');
    if ($appKey === '' || $appSecret === '') {
        fwrite(STDERR, "ERRO: omie.app_key / omie.app_secret não configurados.\n");
        exit(2);
    }
    $logger     = new Logger($config);
    $omieClient = new OmieClient($appKey, $appSecret, 30, $logger, null, null, 'stock_sync');
    $stockClient = new OmieStockClient($omieClient);
    $coordinator = new StockSyncCoordinator($pdo, $stockClient, $logger, $isDryRun);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRO fatal: ' . $e->getMessage() . "\n");
    exit(2);
}

$results = [];
$anyError = false;
$t0 = microtime(true);

// ── Execute ───────────────────────────────────────────────────────────────────

if ($doLocations) {
    $r = $coordinator->syncLocais();
    $results['locations'] = $r;
    if ($r['errors'] > 0) $anyError = true;
}

if ($doBalances) {
    $r = $coordinator->syncSaldos($productCode, $limit);
    $results['balances'] = $r;
    if ($r['errors'] > 0) $anyError = true;
}

if ($doMovements) {
    $r = $coordinator->syncMovimentos($dateFrom, $dateTo, $productCode, $limit);
    $results['movements'] = $r;
    if ($r['errors'] > 0) $anyError = true;
}

$elapsed = round(microtime(true) - $t0, 2);

$output = [
    'tool'         => 'sync_stock_omie',
    'mode'         => $isDryRun ? 'dry_run' : 'apply',
    'date'         => date('Y-m-d H:i:s'),
    'period'       => ['start' => $dateFrom, 'end' => $dateTo],
    'product_code' => $productCode ?: null,
    'elapsed'      => $elapsed,
    'results'      => $results,
    'summary'      => [
        'pass'    => !$anyError,
        'dry_run' => $isDryRun,
        'note'    => $isDryRun
            ? 'Dry-run: nenhum dado persistido. Use --apply para sincronizar.'
            : ($anyError ? 'Apply executado com erros. Verificar log.' : 'Apply executado com sucesso.'),
    ],
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($anyError ? 1 : 0);
}

$bar = str_repeat('═', 70);
$sep = str_repeat('─', 70);
$modeLabel = $isDryRun ? "\033[33mDRY-RUN\033[0m" : "\033[32mAPPLY\033[0m";
echo "\n{$bar}\n Sync Estoque Omie — [{$modeLabel}]\n{$bar}\n";
printf(" Período: %s → %s  |  Produto: %s  |  Elapsed: %.2fs\n{$sep}\n\n",
    $dateFrom, $dateTo, $productCode ?: '*', $elapsed);

foreach ($results as $section => $r) {
    $icon  = $r['errors'] > 0 ? "\033[31mFAIL\033[0m" : "\033[32mOK\033[0m  ";
    printf(" [%s] %-12s  synced=%d  errors=%d\n", $icon, strtoupper($section), $r['synced'] ?? 0, $r['errors'] ?? 0);
    if (!empty($r['sample'] ?? $r['items'] ?? [])) {
        $sample = $r['sample'] ?? $r['items'] ?? [];
        printf("       Amostra (%d itens):\n", count($sample));
        foreach (array_slice($sample, 0, 3) as $item) {
            printf("         %s\n", json_encode($item, JSON_UNESCAPED_UNICODE));
        }
    }
}

echo "\n{$sep}\n";
printf(" %s  —  %s\n",
    $anyError ? "\033[31m✗ FAIL\033[0m" : "\033[32m✔ PASS\033[0m",
    $output['summary']['note']
);
echo "{$bar}\n\n";

exit($anyError ? 1 : 0);
