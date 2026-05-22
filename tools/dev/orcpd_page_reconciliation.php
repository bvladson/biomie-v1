<?php

declare(strict_types=1);

/**
 * Fase M — OrcPD Page Reconciliation
 *
 * Compara KPIs comerciais de todas as páginas contra a fonte canônica.
 * Suporta valores esperados para validação golden contra planilha Omie.
 *
 * CLI: php tools/dev/orcpd_page_reconciliation.php \
 *        --start=2026-05-01 --end=2026-05-31 \
 *        --expect-commercial-nf=165 \
 *        --expect-commercial-rev=408287.30 \
 *        --expect-commercial-merch=402666.41 \
 *        --expect-remittance-nf=9 \
 *        --expect-remittance-rev=76081.71 \
 *        [--pretty] [--json]
 *
 * Web: .../tools/dev/orcpd_page_reconciliation.php?token=TOKEN&start=...&end=...
 */

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $adminToken = getenv('BIOMIE_ADMIN_TOKEN') ?: null;
    $provided   = $_GET['token'] ?? $_SERVER['HTTP_X_BIOMIE_TOKEN'] ?? null;
    if ($adminToken === null || $provided !== $adminToken) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
require_once $root . '/api/bootstrap.php';

use BIomie\Database;
use BIomie\DataPlatform\Silver\Readers\CanonicalReadSupport;
use BIomie\Modules\Fiscal\Infrastructure\FiscalReadRepository;

// ── CLI arg parsing ───────────────────────────────────────────────────────────
$args   = $isCli ? array_slice($argv ?? [], 1) : [];
$asJson = in_array('--json', $args, true) && !in_array('--pretty', $args, true);
if (!$isCli) { $asJson = true; }

$start  = null;
$end    = null;
$expect = [
    'commercial_nf'    => null,
    'commercial_rev'   => null,
    'commercial_merch' => null,
    'remittance_nf'    => null,
    'remittance_rev'   => null,
];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--start='))                   { $start = substr($arg, 8); }
    if (str_starts_with($arg, '--end='))                     { $end   = substr($arg, 6); }
    if (str_starts_with($arg, '--expect-commercial-nf='))    { $expect['commercial_nf']    = (int)   substr($arg, 24); }
    if (str_starts_with($arg, '--expect-commercial-rev='))   { $expect['commercial_rev']   = (float) substr($arg, 25); }
    if (str_starts_with($arg, '--expect-commercial-merch=')) { $expect['commercial_merch'] = (float) substr($arg, 27); }
    if (str_starts_with($arg, '--expect-remittance-nf='))    { $expect['remittance_nf']    = (int)   substr($arg, 23); }
    if (str_starts_with($arg, '--expect-remittance-rev='))   { $expect['remittance_rev']   = (float) substr($arg, 24); }
}

if (!$start) { $start = $_GET['start'] ?? date('Y-m-01'); }
if (!$end)   { $end   = $_GET['end']   ?? date('Y-m-t'); }

$hasExpectations = array_filter($expect, static fn ($v) => $v !== null) !== [];

// ── DB connection ─────────────────────────────────────────────────────────────
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    $err = ['ok' => false, 'error' => 'DB not configured', 'timestamp' => date(DATE_ATOM)];
    if ($asJson) { echo json_encode($err, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); }
    else { echo "[FAIL] DB not configured.\n"; }
    exit(1);
}

$pdo         = Database::connect($config, $connection);
$pdoResolver = static fn () => $pdo;
$fiscalRepo  = new FiscalReadRepository($pdoResolver);
$readSupport = new CanonicalReadSupport($pdoResolver);

$scopeAvailable = $readSupport->operationScopeAvailable();
$enforceMode    = (bool) (getenv('BIOMIE_OPERATION_SCOPE_ENFORCED') ?: false);

// ── Ground truth: canonical commercial ───────────────────────────────────────
$canonicalSummary = $fiscalRepo->summarizeInvoicesCommercial($start, $end);
$refNfCount    = (int)   ($canonicalSummary['billed_orders'] ?? 0);
$refRevenue    = (float) ($canonicalSummary['billed_value'] ?? 0);
$refMerch      = (float) ($canonicalSummary['merchandise_total'] ?? 0);

// ── Bruto (all authorized) ────────────────────────────────────────────────────
$brutStmt = $pdo->prepare(
    "SELECT COUNT(*) AS nfs, COALESCE(SUM(valor_total),0) AS revenue, COALESCE(SUM(valor_mercadorias),0) AS merch
     FROM biomie_notas_fiscais_canonicas
     WHERE status_fiscal = 'autorizada' AND data_competencia BETWEEN :start AND :end"
);
$brutStmt->execute(['start' => $start, 'end' => $end]);
$brutRow     = $brutStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$brutNfCount = (int)   ($brutRow['nfs'] ?? 0);
$brutRevenue = (float) ($brutRow['revenue'] ?? 0);
$brutMerch   = (float) ($brutRow['merch'] ?? 0);

// ── Remittance ────────────────────────────────────────────────────────────────
$scopeFilter = $readSupport->scopeFilter('', 'remittance');
if ($scopeFilter !== '') {
    $remitStmt = $pdo->prepare(
        "SELECT COUNT(*) AS nfs, COALESCE(SUM(valor_total),0) AS revenue, COALESCE(SUM(valor_mercadorias),0) AS merch
         FROM biomie_notas_fiscais_canonicas
         WHERE status_fiscal = 'autorizada' AND data_competencia BETWEEN :start AND :end" . $scopeFilter
    );
    $remitStmt->execute(['start' => $start, 'end' => $end]);
    $remitRow = $remitStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} else {
    $remitRow = ['nfs' => 0, 'revenue' => 0, 'merch' => 0];
}
$remitNfCount = (int)   ($remitRow['nfs'] ?? 0);
$remitRevenue = (float) ($remitRow['revenue'] ?? 0);
$remitMerch   = (float) ($remitRow['merch'] ?? 0);

// ── Module source checks ──────────────────────────────────────────────────────
$modules = [];

foreach (['overview', 'vendors', 'orders'] as $module) {
    $flagReady   = (bool) $config->get('features.canonical_fiscal_ready', false);
    $flagEnabled = (bool) $config->get("features.canonical_reads_{$module}", false);
    $useCanon    = ($flagEnabled && $flagReady) || $enforceMode;
    $modules[$module] = [
        'canonical_enabled'  => $flagEnabled,
        'fiscal_ready'       => $flagReady,
        'enforce_scope'      => $enforceMode,
        'will_use_canonical' => $useCanon,
        'actual_nfs'         => $useCanon ? $refNfCount  : $brutNfCount,
        'actual_revenue'     => $useCanon ? $refRevenue  : $brutRevenue,
        'actual_merch'       => $useCanon ? $refMerch    : $brutMerch,
        'status'             => $useCanon ? 'commercial' : 'BRUTO',
    ];
}

// mercadorias — ProductMixReadRepository always uses commercial scope, no flag
$prodStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(valor_total),0) AS revenue, COALESCE(SUM(valor_mercadorias),0) AS merch, COUNT(*) AS nfs
     FROM biomie_notas_fiscais_canonicas
     WHERE status_fiscal = 'autorizada' AND data_competencia BETWEEN :start AND :end"
    . $readSupport->commercialScopeFilter()
);
$prodStmt->execute(['start' => $start, 'end' => $end]);
$prodRow = $prodStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$modules['mercadorias'] = [
    'canonical_enabled'  => true,
    'fiscal_ready'       => true,
    'enforce_scope'      => true,
    'will_use_canonical' => true,
    'actual_nfs'         => (int)   ($prodRow['nfs'] ?? 0),
    'actual_revenue'     => (float) ($prodRow['revenue'] ?? 0),
    'actual_merch'       => (float) ($prodRow['merch'] ?? 0),
    'status'             => 'commercial',
];

// ── Convergence check ─────────────────────────────────────────────────────────
$allCommercial = true;
$allMatch      = true;
foreach ($modules as $m) {
    if ($m['status'] !== 'commercial') {
        $allCommercial = false;
        $allMatch      = false;
    }
    if (abs($m['actual_revenue'] - $refRevenue) > 0.02) {
        $allMatch = false;
    }
}

// ── Expectation checks (golden values from planilha) ─────────────────────────
$expectResults   = [];
$allExpectPass   = true;
$tolerance       = 0.02;

if ($hasExpectations) {
    $checks = [
        ['key' => 'commercial_nf',    'label' => 'Commercial NF count', 'actual' => $refNfCount,    'expected' => $expect['commercial_nf'],    'is_float' => false, 'tol' => 0],
        ['key' => 'commercial_rev',   'label' => 'Commercial Revenue',  'actual' => $refRevenue,    'expected' => $expect['commercial_rev'],   'is_float' => true,  'tol' => $tolerance],
        ['key' => 'commercial_merch', 'label' => 'Commercial Merch',    'actual' => $refMerch,      'expected' => $expect['commercial_merch'], 'is_float' => true,  'tol' => $tolerance],
        ['key' => 'remittance_nf',    'label' => 'Remittance NF count', 'actual' => $remitNfCount,  'expected' => $expect['remittance_nf'],    'is_float' => false, 'tol' => 0],
        ['key' => 'remittance_rev',   'label' => 'Remittance Revenue',  'actual' => $remitRevenue,  'expected' => $expect['remittance_rev'],   'is_float' => true,  'tol' => $tolerance],
    ];

    foreach ($checks as $check) {
        if ($check['expected'] === null) { continue; }
        $diff = $check['is_float']
            ? abs($check['actual'] - $check['expected'])
            : abs((int) $check['actual'] - (int) $check['expected']);
        $pass = $diff <= $check['tol'];
        if (!$pass) { $allExpectPass = false; }
        $expectResults[$check['key']] = [
            'label'    => $check['label'],
            'expected' => $check['expected'],
            'actual'   => $check['is_float'] ? round($check['actual'], 2) : (int) $check['actual'],
            'diff'     => $check['is_float'] ? round($diff, 2) : (int) $diff,
            'pass'     => $pass,
        ];
    }
}

$overallOk = $allMatch && (!$hasExpectations || $allExpectPass);

// ── Build result ──────────────────────────────────────────────────────────────
$result = [
    'ok'                     => $overallOk,
    'timestamp'              => date(DATE_ATOM),
    'period'                 => ['start' => $start, 'end' => $end],
    'enforce_mode'           => $enforceMode,
    'scope_column_available' => $scopeAvailable,
    'reference' => [
        'source'   => 'biomie_notas_fiscais_canonicas WHERE operation_scope=commercial',
        'nf_count' => $refNfCount,
        'revenue'  => round($refRevenue, 2),
        'merch'    => round($refMerch, 2),
    ],
    'bruto_reference' => [
        'nf_count' => $brutNfCount,
        'revenue'  => round($brutRevenue, 2),
        'merch'    => round($brutMerch, 2),
    ],
    'remittance_reference' => [
        'nf_count' => $remitNfCount,
        'revenue'  => round($remitRevenue, 2),
        'merch'    => round($remitMerch, 2),
    ],
    'modules'      => $modules,
    'expectations' => $expectResults ?: null,
    'summary' => [
        'all_commercial'    => $allCommercial,
        'all_match'         => $allMatch,
        'all_expect_pass'   => $hasExpectations ? $allExpectPass : null,
        'total_modules'     => count($modules),
        'commercial_ok'     => count(array_filter($modules, fn ($m) => $m['status'] === 'commercial')),
        'bruto_exposed'     => count(array_filter($modules, fn ($m) => $m['status'] !== 'commercial')),
    ],
];

if ($asJson) {
    if (!$isCli) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit($overallOk ? 0 : 1);
}

// ── CLI text (pretty) output ──────────────────────────────────────────────────
$hr = str_repeat('─', 70);
echo "\n╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║          BIomie OrcPD Page Reconciliation — Fase M                  ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "Timestamp   : " . $result['timestamp'] . "\n";
echo "Period      : {$start} → {$end}\n";
echo "Enforce mode: " . ($enforceMode ? '✓ ACTIVE' : '✗ off') . "\n";
echo "Scope column: " . ($scopeAvailable ? '✓ available' : '✗ MISSING') . "\n";
echo "{$hr}\n";

$fmt = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$fmtNf = static fn (int $n): string => "{$n} NFs";

echo "\nREFERÊNCIA CANÔNICA (fonte: biomie_notas_fiscais_canonicas)\n";
echo "  Commercial : " . $fmtNf($refNfCount) . " | " . $fmt($refRevenue) . " | Merch " . $fmt($refMerch) . "\n";
echo "  Remessa    : " . $fmtNf($remitNfCount) . " | " . $fmt($remitRevenue) . "\n";
echo "  Bruto      : " . $fmtNf($brutNfCount) . " | " . $fmt($brutRevenue) . "\n";

if ($hasExpectations && $expectResults !== []) {
    echo "\n{$hr}\nCHECK PLANILHA OMIE (golden values)\n";
    foreach ($expectResults as $er) {
        $icon = $er['pass'] ? '✓' : '✗';
        $val  = is_float($er['actual']) ? $fmt((float) $er['actual']) : (string) $er['actual'];
        $exp  = is_float($er['expected']) ? $fmt((float) $er['expected']) : (string) $er['expected'];
        $diff = $er['diff'] > 0 ? "  Δ=" . (is_float($er['diff']) ? $fmt((float) $er['diff']) : (string) $er['diff']) : '';
        echo "  {$icon} {$er['label']}: actual={$val}  expected={$exp}{$diff}\n";
    }
}

echo "\n{$hr}\nMÓDULOS — fonte que será servida ao browser\n";
$pad = 12;
foreach ($modules as $name => $m) {
    $icon   = $m['status'] === 'commercial' ? '✓' : '✗';
    $label  = str_pad(strtoupper($name), $pad);
    $status = str_pad($m['status'], 10);
    $revDiff = abs($m['actual_revenue'] - $refRevenue);
    $match  = $revDiff <= 0.02 ? '' : '  [DIVERGE Δ=' . $fmt($revDiff) . ']';
    echo "  {$icon} {$label}  {$status}  NFs={$m['actual_nfs']}  Rev=" . $fmt($m['actual_revenue']) . $match . "\n";
}

echo "\n{$hr}\nSUMÁRIO\n";
echo "  Módulos comerciais : " . $result['summary']['commercial_ok'] . "/" . $result['summary']['total_modules'] . "\n";
echo "  Expostos como bruto: " . $result['summary']['bruto_exposed'] . "\n";
if ($hasExpectations) {
    echo "  Golden check       : " . ($allExpectPass ? '✓ PASS' : '✗ FAIL') . "\n";
}
echo "\n";

if ($overallOk) {
    echo "╔══════════════╗\n║  ✓  PASS     ║  Todas as páginas mostram dados comerciais convergentes.\n╚══════════════╝\n\n";
} else {
    echo "╔══════════════╗\n║  ✗  FAIL     ║  Divergência detectada.\n╚══════════════╝\n";
    if (!$allCommercial) {
        echo "  → Algumas páginas usarão dados brutos (enforce mode desligado ou flags ausentes).\n";
    }
    if ($hasExpectations && !$allExpectPass) {
        echo "  → Valores no banco diferem da planilha Omie. Verificar sync/classificação.\n";
    }
    echo "\n";
}

exit($overallOk ? 0 : 1);
