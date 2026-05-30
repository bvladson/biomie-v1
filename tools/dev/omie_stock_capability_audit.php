<?php

declare(strict_types=1);

/**
 * tools/dev/omie_stock_capability_audit.php
 *
 * Diagnóstico ESTÁTICO de capacidade de estoque Omie.
 *
 * NÃO chama a API Omie (requer HTTP + credenciais em runtime).
 * Apenas verifica:
 *   1. Tabelas de estoque existentes no banco de dados
 *   2. Produtos sem snapshot de estoque (via NF itens)
 *   3. Scripts de sync de estoque existentes
 *   4. Menções a estoque nos cron scripts
 *   5. APIs Omie necessárias (lista estática)
 *   6. Configuração de credenciais Omie
 *
 * Usage:
 *   php tools/dev/omie_stock_capability_audit.php
 *   php tools/dev/omie_stock_capability_audit.php --pretty
 *   php tools/dev/omie_stock_capability_audit.php --json
 *   php tools/dev/omie_stock_capability_audit.php --sample=10
 *
 * Exit codes:
 *   0 — infra OK (todas as tabelas existem)
 *   1 — infra parcial ou incompleta
 *   2 — DB error
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

// ── CLI params ────────────────────────────────────────────────────────────────

$opts      = getopt('', ['pretty', 'json', 'sample:']);
$isPretty  = isset($opts['pretty']);
$isJson    = isset($opts['json']);
$sampleN   = isset($opts['sample']) ? max(1, (int) $opts['sample']) : 5;

$root = dirname(__DIR__, 2);

// ── DB ────────────────────────────────────────────────────────────────────────

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbOk = true;
} catch (\Throwable $e) {
    $dbOk   = false;
    $dbErr  = $e->getMessage();
    if ($isJson) {
        echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
        exit(2);
    }
    fwrite(STDERR, 'ERRO DB: ' . $e->getMessage() . "\n");
    exit(2);
}

// ── Helper: check table existence ─────────────────────────────────────────────

function oscaTableStatus(PDO $pdo, string $table): string
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $st->execute([':t' => $table]);
        return ((int) $st->fetchColumn()) > 0 ? 'EXISTS' : 'NOT_FOUND';
    } catch (\Throwable) {
        return 'ERROR';
    }
}

// ── Helper: row count ─────────────────────────────────────────────────────────

function oscaRowCount(PDO $pdo, string $table): int
{
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM {$table}");
        return (int) $st->fetchColumn();
    } catch (\Throwable) {
        return -1;
    }
}

// ── 1. Stock tables ───────────────────────────────────────────────────────────

$stockTables = [
    'biomie_estoque_locais_dim',
    'biomie_produto_estoque_snapshot',
    'biomie_produto_estoque_movimentos',
    'biomie_produto_estoque_minimo',
];

$tableResults = [];
$allExist     = true;

foreach ($stockTables as $tbl) {
    $status = oscaTableStatus($pdo, $tbl);
    $count  = $status === 'EXISTS' ? oscaRowCount($pdo, $tbl) : null;
    $tableResults[$tbl] = ['status' => $status, 'rows' => $count];
    if ($status !== 'EXISTS') {
        $allExist = false;
    }
}

// ── 2. Products without stock snapshot ───────────────────────────────────────

$productsTotal        = 0;
$productsWithSnapshot = 0;
$snapshotTableExists  = $tableResults['biomie_produto_estoque_snapshot']['status'] === 'EXISTS';
$itemsTableExists     = oscaTableStatus($pdo, 'biomie_notas_fiscais_itens_canonicos') === 'EXISTS';

if ($itemsTableExists) {
    try {
        $st = $pdo->query(
            'SELECT COUNT(DISTINCT codigo_produto) FROM biomie_notas_fiscais_itens_canonicos
             WHERE codigo_produto IS NOT NULL AND codigo_produto <> \'\''
        );
        $productsTotal = (int) $st->fetchColumn();
    } catch (\Throwable) {
        $productsTotal = -1;
    }
}

if ($snapshotTableExists && $productsTotal > 0) {
    try {
        $st = $pdo->query(
            'SELECT COUNT(DISTINCT codigo_produto) FROM biomie_produto_estoque_snapshot
             WHERE codigo_produto IS NOT NULL AND codigo_produto <> \'\''
        );
        $productsWithSnapshot = (int) $st->fetchColumn();
    } catch (\Throwable) {
        $productsWithSnapshot = 0;
    }
}

$productsMissing = $productsTotal > 0 ? max(0, $productsTotal - $productsWithSnapshot) : $productsTotal;
$missingPct      = $productsTotal > 0 ? round(($productsMissing / $productsTotal) * 100, 1) : 100.0;

// Sample products with no snapshot
$sampleProducts = [];
if ($itemsTableExists && $snapshotTableExists && $productsTotal > 0) {
    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT i.codigo_produto, i.descricao_produto
             FROM biomie_notas_fiscais_itens_canonicos i
             LEFT JOIN biomie_produto_estoque_snapshot s ON s.codigo_produto = i.codigo_produto
             WHERE s.codigo_produto IS NULL
               AND i.codigo_produto IS NOT NULL
             LIMIT :n'
        );
        $st->bindValue(':n', $sampleN, PDO::PARAM_INT);
        $st->execute();
        $sampleProducts = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {
        $sampleProducts = [];
    }
} elseif ($itemsTableExists && !$snapshotTableExists && $productsTotal > 0) {
    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT codigo_produto, descricao_produto
             FROM biomie_notas_fiscais_itens_canonicos
             WHERE codigo_produto IS NOT NULL
             LIMIT :n'
        );
        $st->bindValue(':n', $sampleN, PDO::PARAM_INT);
        $st->execute();
        $sampleProducts = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {
        $sampleProducts = [];
    }
}

// ── 3. Stock sync scripts ─────────────────────────────────────────────────────

$syncScripts = [
    'api/omie_stock_sync.php'    => $root . '/api/omie_stock_sync.php',
    'cron/sync_stock.sh'         => $root . '/cron/sync_stock.sh',
    'cron/hostinger/sync_stock.sh' => $root . '/cron/hostinger/sync_stock.sh',
    'bin/sync_stock.php'         => $root . '/bin/sync_stock.php',
];

$syncResults = [];
foreach ($syncScripts as $label => $path) {
    $syncResults[$label] = file_exists($path) ? 'FOUND' : 'NOT_FOUND';
}

// ── 4. Cron scripts mentioning estoque / stock ────────────────────────────────

$cronDir    = $root . '/cron';
$cronMentions = [];

if (is_dir($cronDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cronDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'sh', 'py'], true)) continue;

        $content = @file_get_contents($file->getPathname());
        if ($content === false) continue;

        if (preg_match('/estoque|stock/i', $content)) {
            $relPath = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR . '/');
            $cronMentions[] = $relPath;
        }
    }
}

// ── 5. Omie API endpoints (static) ───────────────────────────────────────────

$omieStockApis = [
    'ListarEstoques'    => 'Lista todos os locais de estoque disponíveis',
    'ConsultarEstoque'  => 'Consulta estoque atual de um produto específico',
    'ResumoEstoque'     => 'Resumo consolidado por produto com saldos',
    'ListarMovEstoque'  => 'Lista movimentações de entrada/saída do estoque',
    'ListarLocaisEstoque' => 'Lista locais físicos de armazenamento',
];

// ── 6. Credentials / config ───────────────────────────────────────────────────

$credFiles = [
    'config/omie.php'  => $root . '/config/omie.php',
    '.env'             => $root . '/.env',
    'config/.env'      => $root . '/config/.env',
];

$credResults = [];
foreach ($credFiles as $label => $path) {
    if (!file_exists($path)) {
        $credResults[$label] = 'NOT_FOUND';
        continue;
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        $credResults[$label] = 'UNREADABLE';
        continue;
    }
    // Check if it has stock-specific keys (non-sensitive check)
    $hasStockKey = preg_match('/estoque|stock|appKey.*estoque|estoque.*appKey/i', $content) === 1;
    $credResults[$label] = $hasStockKey ? 'FOUND (stock key detected)' : 'FOUND (no stock-specific key)';
}

// ── Build exit code ───────────────────────────────────────────────────────────

$exitCode = $allExist ? 0 : 1;

// ── Output ────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode([
        'tool'    => 'omie_stock_capability_audit',
        'db_ok'   => $dbOk,
        'checks'  => [
            'stock_tables'            => $tableResults,
            'products_total'          => $productsTotal,
            'products_with_snapshot'  => $productsWithSnapshot,
            'products_without_snapshot' => $productsMissing,
            'missing_pct'             => $missingPct,
            'sample_missing_products' => $sampleProducts,
            'sync_scripts'            => $syncResults,
            'cron_mentions'           => $cronMentions,
            'omie_stock_apis'         => array_keys($omieStockApis),
            'credential_files'        => $credResults,
        ],
        'recommendation' => $allExist
            ? 'Stock tables exist. Verify data freshness and sync schedule.'
            : 'Stock infrastructure missing. Create migrations and sync scripts.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($exitCode);
}

// Plain / pretty
echo "=== omie_stock_capability_audit ===\n\n";

echo "[CHECK] Tabelas de estoque no banco:\n";
foreach ($tableResults as $tbl => $info) {
    $suffix = '';
    if ($info['status'] === 'EXISTS' && $info['rows'] !== null) {
        $suffix = ' (' . number_format($info['rows']) . ' linhas)';
    }
    $statusLabel = $info['status'] === 'EXISTS' ? 'EXISTE' : 'NÃO EXISTE';
    printf("  %-45s %s%s\n", $tbl . ':', $statusLabel, $suffix);
}

echo "\n[CHECK] Produtos sem snapshot de estoque:\n";
if (!$itemsTableExists) {
    echo "  biomie_notas_fiscais_itens_canonicos: NÃO EXISTE — sem base para comparar\n";
} else {
    printf("  Total produtos distintos com NF: %d\n", $productsTotal);
    if ($snapshotTableExists) {
        printf("  Com snapshot: %d\n", $productsWithSnapshot);
    } else {
        echo "  Com snapshot: 0 (tabela inexistente)\n";
    }
    printf("  Sem snapshot: %d (%.1f%%)\n", $productsMissing, $missingPct);

    if (!empty($sampleProducts)) {
        echo "  Amostra de produtos sem snapshot (primeiros {$sampleN}):\n";
        foreach ($sampleProducts as $p) {
            $cod  = (string) ($p['codigo_produto'] ?? '—');
            $desc = mb_substr((string) ($p['descricao_produto'] ?? '—'), 0, 50);
            printf("    %-15s %s\n", $cod, $desc);
        }
    }
}

echo "\n[CHECK] Scripts de sync de estoque:\n";
foreach ($syncResults as $label => $status) {
    $statusLabel = $status === 'FOUND' ? 'ENCONTRADO' : 'NÃO ENCONTRADO';
    printf("  %-40s %s\n", $label . ':', $statusLabel);
}

echo "\n[CHECK] Cron scripts que mencionam estoque/stock:\n";
if (empty($cronMentions)) {
    echo "  Nenhum script cron menciona estoque/stock.\n";
} else {
    foreach ($cronMentions as $f) {
        echo "  $f\n";
    }
}

echo "\n[INFO] APIs Omie necessárias para estoque:\n";
foreach ($omieStockApis as $endpoint => $desc) {
    printf("  %-25s — %s\n", $endpoint, $desc);
}

echo "\n[CHECK] Credenciais / configuração Omie:\n";
foreach ($credResults as $label => $status) {
    printf("  %-20s %s\n", $label . ':', $status);
}

echo "\n[RECOMENDAÇÃO] Próximos passos:\n";
if ($allExist) {
    echo "  1. Tabelas de estoque já existem. Verifique a frequência e saúde do sync.\n";
    echo "  2. Validar se biomie_produto_estoque_snapshot está sendo populado via sync Omie.\n";
    echo "  3. Criar relatório de cobertura: produtos com NF mas sem estoque registrado.\n";
} else {
    $missing = array_keys(array_filter($tableResults, fn($i) => $i['status'] !== 'EXISTS'));
    echo "  1. Criar migrations para tabelas faltantes:\n";
    foreach ($missing as $tbl) {
        echo "       - {$tbl}\n";
    }
    echo "  2. Criar api/omie_stock_sync.php com suporte a --dry-run.\n";
    echo "  3. Verificar credenciais Omie para acesso às APIs de estoque.\n";
    echo "  4. Implementar cron/hostinger/sync_stock.sh com lock e log.\n";
    echo "  5. Adicionar indicador de estoque no Product 360 drawer.\n";
}

echo "\n";
exit($exitCode);
