<?php

/**
 * tools/dev/daily_sales_integrity_check.php
 *
 * Checks consistency between Omie canonical tables and Gold marts.
 * Compares totals for a given date (and month) to detect drifts.
 *
 * Usage:
 *   php tools/dev/daily_sales_integrity_check.php [--date=YYYY-MM-DD] [--yesterday] [--today] [--pretty] [--json] [--fail-on-drift] [--tolerance=0.01]
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

function dailySalesTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

// Parse CLI arguments
$opts = getopt('', ['date:', 'yesterday', 'today', 'pretty', 'json', 'fail-on-drift', 'tolerance:']);
$jsonMode = isset($opts['json']);
$pretty = isset($opts['pretty']);
$failOnDrift = isset($opts['fail-on-drift']);
$tolerance = (float) ($opts['tolerance'] ?? 0.01);

$date = date('Y-m-d');
if (isset($opts['yesterday'])) {
    $date = date('Y-m-d', strtotime('-1 day'));
} elseif (isset($opts['today'])) {
    $date = date('Y-m-d');
} elseif (isset($opts['date'])) {
    $date = trim((string) $opts['date']);
}

$month = substr($date, 0, 7); // YYYY-MM
$monthPattern = $month . '-%';

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = null;

try {
    if (Database::isConfigured($config, $connection)) {
        $pdo = Database::connect($config, $connection);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "ERRO: Falha ao conectar ao banco: " . $e->getMessage() . "\n");
    exit(1);
}

if ($pdo === null) {
    fwrite(STDERR, "ERRO: Banco de dados não configurado.\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Canônico fiscal: NFs comerciais autorizadas no dia
// ─────────────────────────────────────────────────────────────────────────────
$canonStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as qtd_nfs,
        COALESCE(SUM(valor_total), 0) as total_nf,
        COALESCE(SUM(valor_frete), 0) as frete,
        COALESCE(SUM(valor_outras_despesas), 0) as outras,
        COALESCE(SUM(valor_seguro), 0) as seguro,
        COALESCE(SUM(COALESCE(valor_base_comercial_nf, valor_total - COALESCE(valor_frete,0) - COALESCE(valor_outras_despesas,0) - COALESCE(valor_seguro,0))), 0) as base_comercial,
        MIN(numero_nf) as min_numero_nf,
        MAX(numero_nf) as max_numero_nf,
        COUNT(DISTINCT codigo_vendedor_omie) as qtd_vendedores
FROM biomie_notas_fiscais_canonicas
WHERE status_fiscal = 'autorizada'
  AND operation_scope = 'commercial'
  AND COALESCE(is_biomie_commercial_visible, 1) = 1
  AND data_emissao = :date
");
$canonStmt->execute(['date' => $date]);
$canon = $canonStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Canonical totals for the month (to compare with monthly marts)
$canonMonthStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(COALESCE(valor_base_comercial_nf, valor_total - COALESCE(valor_frete,0) - COALESCE(valor_outras_despesas,0) - COALESCE(valor_seguro,0))), 0) as base_comercial,
        COALESCE(SUM(valor_mercadorias), 0) as merchandise
FROM biomie_notas_fiscais_canonicas
WHERE status_fiscal = 'autorizada'
  AND operation_scope = 'commercial'
  AND COALESCE(is_biomie_commercial_visible, 1) = 1
  AND data_emissao LIKE :month_pattern
");
$canonMonthStmt->execute(['month_pattern' => $monthPattern]);
$canonMonth = $canonMonthStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// ─────────────────────────────────────────────────────────────────────────────
// 2. Itens canônicos
// ─────────────────────────────────────────────────────────────────────────────
$itemsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_itens,
        COUNT(DISTINCT i.nota_key) as notas_com_itens,
        SUM(CASE WHEN i.nota_key IS NULL OR i.nota_key = '' THEN 1 ELSE 0 END) as itens_sem_nota
FROM biomie_notas_fiscais_itens_canonicos i
JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
WHERE nf.status_fiscal = 'autorizada'
  AND nf.operation_scope = 'commercial'
  AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1
  AND nf.data_emissao = :date
");
$itemsStmt->execute(['date' => $date]);
$items = $itemsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$noItemsStmt = $pdo->prepare("
    SELECT numero_nf, nota_key 
    FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
      AND operation_scope = 'commercial'
      AND COALESCE(is_biomie_commercial_visible, 1) = 1
      AND data_emissao = :date
      AND nota_key NOT IN (SELECT DISTINCT nota_key FROM biomie_notas_fiscais_itens_canonicos WHERE nota_key IS NOT NULL)
");
$noItemsStmt->execute(['date' => $date]);
$notesWithoutItems = $noItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ─────────────────────────────────────────────────────────────────────────────
// 3. Marts (Daily and Monthly)
// ─────────────────────────────────────────────────────────────────────────────
// A) mart_vendedor_dia (Daily)
$vendedorDia = ['valor_nfe' => 0.0, 'qtd_vendedores' => 0];
if (dailySalesTableExists($pdo, 'mart_vendedor_dia')) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(valor_nfe), 0) as valor_nfe, COUNT(DISTINCT codigo_vendedor_omie) as qtd_vendedores
        FROM mart_vendedor_dia
        WHERE dia = :date
    ");
    $stmt->execute(['date' => $date]);
    $vendedorDia = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['valor_nfe' => 0.0, 'qtd_vendedores' => 0];
}

// B) mart_cliente_periodo (Monthly)
$clientePeriodo = ['valor_nfe' => 0.0];
if (dailySalesTableExists($pdo, 'mart_cliente_periodo')) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(valor_nfe), 0) as valor_nfe
        FROM mart_cliente_periodo
        WHERE competencia = :month
    ");
    $stmt->execute(['month' => $month]);
    $clientePeriodo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['valor_nfe' => 0.0];
}

// C) mart_produto_periodo (Monthly - compares with merchandise value)
$produtoPeriodo = ['valor_total' => 0.0];
if (dailySalesTableExists($pdo, 'mart_produto_periodo')) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(valor_total), 0) as valor_total
        FROM mart_produto_periodo
        WHERE competencia = :month
    ");
    $stmt->execute(['month' => $month]);
    $produtoPeriodo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['valor_total' => 0.0];
}

// D) mart_compradores_por_geo (Monthly)
$geoPeriodo = ['faturamento' => 0.0];
if (dailySalesTableExists($pdo, 'mart_compradores_por_geo')) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(faturamento), 0) as faturamento
        FROM mart_compradores_por_geo
        WHERE competencia = :month
    ");
    $stmt->execute(['month' => $month]);
    $geoPeriodo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['faturamento' => 0.0];
}

// E) mart_vendas_por_cnae_cnpj (Monthly)
$cnaePeriodo = ['faturamento' => 0.0];
if (dailySalesTableExists($pdo, 'mart_vendas_por_cnae_cnpj')) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(faturamento), 0) as faturamento
        FROM mart_vendas_por_cnae_cnpj
        WHERE competencia = :month
    ");
    $stmt->execute(['month' => $month]);
    $cnaePeriodo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['faturamento' => 0.0];
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. Divergência / Drift Calculations
// ─────────────────────────────────────────────────────────────────────────────
$canonBase = (float) ($canon['base_comercial'] ?? 0);
$canonMonthBase = (float) ($canonMonth['base_comercial'] ?? 0);
$canonMonthMerch = (float) ($canonMonth['merchandise'] ?? 0);

$drifts = [
    'mart_vendedor_dia' => [
        'label' => 'Marts Vendedor × Dia',
        'canon' => $canonBase,
        'mart' => (float) $vendedorDia['valor_nfe'],
        'scope' => 'daily'
    ],
    'mart_cliente_periodo' => [
        'label' => 'Marts Cliente × Período',
        'canon' => $canonMonthBase,
        'mart' => (float) $clientePeriodo['valor_nfe'],
        'scope' => 'monthly'
    ],
    'mart_produto_periodo' => [
        'label' => 'Marts Produto × Período',
        'canon' => $canonMonthMerch,
        'mart' => (float) $produtoPeriodo['valor_total'],
        'scope' => 'monthly'
    ],
    'mart_compradores_por_geo' => [
        'label' => 'Marts Compradores × Geo',
        'canon' => $canonMonthBase,
        'mart' => (float) $geoPeriodo['faturamento'],
        'scope' => 'monthly'
    ],
    'mart_vendas_por_cnae_cnpj' => [
        'label' => 'Marts Vendas × CNAE/CNPJ',
        'canon' => $canonMonthBase,
        'mart' => (float) $cnaePeriodo['faturamento'],
        'scope' => 'monthly'
    ],
];

$hasDrift = false;
$maxDriftAbs = 0.0;
foreach ($drifts as $k => &$d) {
    $diff = round($d['mart'] - $d['canon'], 2);
    $diffAbs = abs($diff);
    $d['diff'] = $diff;
    $d['diff_abs'] = $diffAbs;
    $d['diff_pct'] = $d['canon'] > 0 ? round(($diff / $d['canon']) * 100, 4) : 0.0;
    
    if ($diffAbs > $tolerance) {
        $d['status'] = 'FAIL';
        $hasDrift = true;
        $maxDriftAbs = max($maxDriftAbs, $diffAbs);
    } else {
        $d['status'] = 'OK';
    }
}
unset($d);

// Overall status evaluation
$status = 'OK';
$message = 'Pipeline de vendas em perfeita harmonia.';
$recAction = 'sem ação';

if ($hasDrift) {
    $status = 'CRITICAL';
    $message = "Descompasso financeiro detectado acima da tolerância (R$ {$tolerance}). Desvio máximo: R$ " . number_format($maxDriftAbs, 2);
    
    // Choose appropriate recovery action
    if ($canonBase > 0 && (float) $vendedorDia['valor_nfe'] === 0.0) {
        $recAction = 'rodar materialize_marts';
    } elseif ($canonBase === 0.0 && (float) $vendedorDia['valor_nfe'] === 0.0 && (int) ($canon['qtd_nfs'] ?? 0) === 0) {
        // Warning if it's weekend/holiday (usually 0 NFs)
        $status = 'WARNING';
        $message = "Nenhum faturamento comercial canônico registrado em {$date}.";
        $recAction = 'sem ação';
    } else {
        $recAction = 'rodar sync restrito';
    }
}

// Check items health
$itemsIssue = false;
if ((int) ($canon['qtd_nfs'] ?? 0) > 0 && (int) ($items['total_itens'] ?? 0) === 0) {
    $status = 'CRITICAL';
    $message = "Notas fiscais comerciais existem em {$date}, mas nenhum item canônico foi importado.";
    $recAction = 'rodar sync restrito';
    $itemsIssue = true;
}

if (count($notesWithoutItems) > 0) {
    $status = 'CRITICAL';
    $message = "Existem " . count($notesWithoutItems) . " NF(s) comercial(is) autorizada(s) sem itens importados em {$date}.";
    $recAction = 'rodar sync restrito';
    $itemsIssue = true;
}

$output = [
    'date' => $date,
    'competencia' => $month,
    'status' => $status,
    'message' => $message,
    'recommended_action' => $recAction,
    'canonical_summary' => [
        'qtd_nfs' => (int) ($canon['qtd_nfs'] ?? 0),
        'total_nf' => round((float) ($canon['total_nf'] ?? 0), 2),
        'frete' => round((float) ($canon['frete'] ?? 0), 2),
        'outras' => round((float) ($canon['outras'] ?? 0), 2),
        'seguro' => round((float) ($canon['seguro'] ?? 0), 2),
        'base_comercial' => round($canonBase, 2),
        'min_numero_nf' => $canon['min_numero_nf'] ? (int) $canon['min_numero_nf'] : null,
        'max_numero_nf' => $canon['max_numero_nf'] ? (int) $canon['max_numero_nf'] : null,
        'qtd_vendedores' => (int) ($canon['qtd_vendedores'] ?? 0),
    ],
    'items_summary' => [
        'total_itens' => (int) ($items['total_itens'] ?? 0),
        'notas_com_itens' => (int) ($items['notas_com_itens'] ?? 0),
        'itens_sem_nota' => (int) ($items['itens_sem_nota'] ?? 0),
        'notas_sem_itens_qtd' => count($notesWithoutItems),
        'notas_sem_itens' => $notesWithoutItems,
    ],
    'drifts' => $drifts,
];

if ($jsonMode) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if ($failOnDrift && $status === 'CRITICAL') {
        exit(1);
    }
    exit(0);
}

// Pretty print CLI formatting
$cGreen = "\033[32m";
$cYellow = "\033[33m";
$cRed = "\033[31m";
$cReset = "\033[0m";

$statusColor = $status === 'OK' ? $cGreen : ($status === 'WARNING' ? $cYellow : $cRed);

echo "\n";
echo "========================================================================\n";
echo "          BIOMIE PIPELINE SALES INTEGRITY CHECK\n";
echo "========================================================================\n";
echo "Data de análise: {$date} (Competência: {$month})\n";
echo "Status: {$statusColor}{$status}{$cReset}\n";
echo "Mensagem: {$message}\n";
echo "Ação recomendada: \033[1m{$recAction}\033[0m\n";
echo "------------------------------------------------------------------------\n\n";

echo "RESUMO CANÔNICO FISCAL:\n";
printf("  Notas fiscais:      %d (NFs %s a %s)\n", $canon['qtd_nfs'], $canon['min_numero_nf'] ?? '-', $canon['max_numero_nf'] ?? '-');
printf("  Faturamento total:  R$ %s\n", number_format((float)$canon['total_nf'], 2, ',', '.'));
printf("  Frete / Outras:     R$ %s / R$ %s\n", number_format((float)$canon['frete'], 2, ',', '.'), number_format((float)$canon['outras'], 2, ',', '.'));
printf("  Seguro:             R$ %s\n", number_format((float)$canon['seguro'], 2, ',', '.'));
printf("  Base Comercial:     R$ %s\n", number_format($canonBase, 2, ',', '.'));
printf("  Vendedores:         %d\n\n", $canon['qtd_vendedores']);

echo "RESUMO ITENS FISCAIS:\n";
printf("  Total itens:        %d\n", $items['total_itens']);
printf("  Notas com itens:    %d\n", $items['notas_com_itens']);
printf("  Notas sem itens:    %d\n", count($notesWithoutItems));
if (count($notesWithoutItems) > 0) {
    foreach ($notesWithoutItems as $nwi) {
        echo "    - NF {$nwi['numero_nf']} (key: {$nwi['nota_key']})\n";
    }
}
echo "\n";

echo "VERIFICAÇÃO DE DIVERGÊNCIA (Tolerância: R$ {$tolerance}):\n";
foreach ($drifts as $k => $d) {
    $icon = $d['status'] === 'OK' ? "{$cGreen}✔ OK{$cReset}" : "{$cRed}✗ FAIL{$cReset}";
    $scopeLabel = $d['scope'] === 'daily' ? 'diário' : 'mensal';
    printf("  [%s]  %-30s (%s)\n", $icon, $d['label'], $scopeLabel);
    printf("         Canônico: R$ %s  |  Marts: R$ %s\n", number_format($d['canon'], 2, ',', '.'), number_format($d['mart'], 2, ',', '.'));
    if ($d['status'] !== 'OK') {
        printf("         Diferença: R$ %s (%s%%)\n", number_format($d['diff'], 2, ',', '.'), $d['diff_pct']);
    }
    echo "\n";
}

echo "========================================================================\n\n";

if ($failOnDrift && $status === 'CRITICAL') {
    exit(1);
}
exit(0);
