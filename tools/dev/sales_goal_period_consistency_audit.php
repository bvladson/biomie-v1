<?php

declare(strict_types=1);

/**
 * tools/dev/sales_goal_period_consistency_audit.php
 *
 * Test SalesGoalPeriodCalculator against multiple periods and compare
 * proportional goal calculation against realized revenue from canonical NFs.
 *
 * Usage:
 *   php tools/dev/sales_goal_period_consistency_audit.php --start=2026-01-01 --end=2026-05-29
 *   php tools/dev/sales_goal_period_consistency_audit.php --start=2026-04-01 --end=2026-04-30 --vendor="JOAO"
 *   php tools/dev/sales_goal_period_consistency_audit.php --json
 *
 * Exit codes:
 *   0 — self-tests pass and no configuration errors
 *   1 — one or more tests / attainment checks flagged
 *   2 — configuration / DB error
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SalesGoalPeriodCalculator;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();
require_once dirname(__DIR__, 2) . '/app/Support/SalesGoalPeriodCalculator.php';

$opts      = getopt('', ['start:', 'end:', 'vendor:', 'pretty', 'json']);
$startDate = isset($opts['start'])  ? trim((string) $opts['start'])  : date('Y-01-01');
$endDate   = isset($opts['end'])    ? trim((string) $opts['end'])    : date('Y-m-d');
$vendorArg = isset($opts['vendor']) ? trim((string) $opts['vendor']) : '';
$isJson    = isset($opts['json']);

// ── DB connection ─────────────────────────────────────────────────────────────

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    $msg = 'ERRO: Banco nao configurado.';
    if ($isJson) {
        echo json_encode(['error' => $msg]) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

$pdo = null;
try {
    $pdo = Database::connect($config, $connection);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    $msg = 'ERRO: Falha ao conectar ao banco: ' . $e->getMessage();
    if ($isJson) {
        echo json_encode(['error' => $msg]) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function sgpcaFmt(float $val): string
{
    return 'R$' . number_format($val, 2, ',', '.');
}

function sgpcaTableExists(\PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Load realized revenue per vendor from canonical NF table for a period.
 *
 * @return array<string,float>  keyed by nome_vendedor
 */
function sgpcaLoadRealized(\PDO $pdo, string $start, string $end): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT
                nome_vendedor,
                COALESCE(SUM(
                    COALESCE(valor_base_comercial_nf,
                        valor_mercadorias - COALESCE(valor_descontos, 0)
                    )
                ), 0) AS realized
            FROM biomie_notas_fiscais_canonicas
            WHERE operation_scope = 'commercial'
              AND status_fiscal = 'autorizada'
              AND COALESCE(is_biomie_commercial_visible, 1) = 1
              AND COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
            GROUP BY nome_vendedor
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);
        $rows   = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['nome_vendedor'] ?? ''));
            if ($name !== '') {
                $result[$name] = round((float) ($row['realized'] ?? 0), 2);
            }
        }
        return $result;
    } catch (\Throwable $e) {
        return ['__error__' => $e->getMessage()];
    }
}

/**
 * Load targets from biomie_vendor_targets, or generate synthetic ones.
 *
 * @return array{rows: list<array{vendor_name:string,year_month:string,target_amount:float}>, source: string, warnings: list<string>}
 */
function sgpcaLoadTargets(\PDO $pdo, string $startMonth, string $endMonth, array $vendors): array
{
    $warnings = [];

    if (sgpcaTableExists($pdo, 'biomie_vendor_targets')) {
        try {
            $stmt = $pdo->prepare("
                SELECT vendor_name, year_month, target_amount
                FROM biomie_vendor_targets
                WHERE year_month BETWEEN :ym_start AND :ym_end
            ");
            $stmt->execute([':ym_start' => $startMonth, ':ym_end' => $endMonth]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($rows === []) {
                $warnings[] = 'biomie_vendor_targets existe mas nao tem dados para o periodo ' . $startMonth . ' a ' . $endMonth . '.';
            }

            return [
                'rows'     => array_map(
                    fn($r) => [
                        'vendor_id'     => '',
                        'vendor_name'   => trim((string) ($r['vendor_name']   ?? '')),
                        'year_month'    => trim((string) ($r['year_month']    ?? '')),
                        'target_amount' => (float) ($r['target_amount'] ?? 0),
                    ],
                    $rows
                ),
                'source'   => 'biomie_vendor_targets',
                'warnings' => $warnings,
            ];
        } catch (\Throwable $e) {
            $warnings[] = 'Erro ao ler biomie_vendor_targets: ' . $e->getMessage() . '. Usando metas sinteticas.';
        }
    } else {
        $warnings[] = 'biomie_vendor_targets nao existe — usando metas sinteticas (R$500.000/mes por vendedor).';
    }

    // Generate synthetic targets: R$500,000/month per vendor, per month in range
    $syntheticRows = [];
    $cursor = $startMonth;
    while ($cursor <= $endMonth) {
        foreach ($vendors as $vendor) {
            if ($vendor !== '' && !str_starts_with($vendor, '__')) {
                $syntheticRows[] = [
                    'vendor_id'     => '',
                    'vendor_name'   => $vendor,
                    'year_month'    => $cursor,
                    'target_amount' => 500000.0,
                ];
            }
        }
        // advance month
        [$y, $m] = explode('-', $cursor);
        $m = (int) $m + 1;
        if ($m > 12) { $m = 1; $y = (int) $y + 1; }
        $cursor = sprintf('%04d-%02d', $y, $m);
    }

    return [
        'rows'     => $syntheticRows,
        'source'   => 'synthetic_500k',
        'warnings' => $warnings,
    ];
}

/**
 * Build test scenarios: user period + full year + broken period.
 *
 * @return list<array{label:string,start:string,end:string}>
 */
function sgpcaBuildPeriods(string $userStart, string $userEnd): array
{
    return [
        ['label' => 'user_period',    'start' => $userStart,   'end' => $userEnd],
        ['label' => 'full_year_2026', 'start' => '2026-01-01', 'end' => '2026-05-29'],
        ['label' => 'broken_period',  'start' => '2026-04-15', 'end' => '2026-05-15'],
    ];
}

// ── Self-test SalesGoalPeriodCalculator ──────────────────────────────────────

$calculator = new SalesGoalPeriodCalculator();
$selfTest   = $calculator->selfTest();

// ── Load data ─────────────────────────────────────────────────────────────────

// Global month range covering all test periods
$allPeriods   = sgpcaBuildPeriods($startDate, $endDate);
$minStart     = min(array_column($allPeriods, 'start'));
$maxEnd       = max(array_column($allPeriods, 'end'));
$globalStartM = substr($minStart, 0, 7);
$globalEndM   = substr($maxEnd,   0, 7);

// Realized for each test period
$realizedByPeriod = [];
foreach ($allPeriods as $period) {
    $realizedByPeriod[$period['label']] = sgpcaLoadRealized($pdo, $period['start'], $period['end']);
}

// Collect all known vendors
$allVendors = [];
foreach ($realizedByPeriod as $realized) {
    foreach (array_keys($realized) as $v) {
        if ($v !== '__error__') {
            $allVendors[$v] = true;
        }
    }
}
if ($vendorArg !== '') {
    $allVendors[$vendorArg] = true;
}
$allVendors = array_keys($allVendors);
sort($allVendors);

// Load targets
$targetData = sgpcaLoadTargets($pdo, $globalStartM, $globalEndM, $allVendors);

// ── Run calculations per period × vendor ─────────────────────────────────────

$results = [];

foreach ($allPeriods as $period) {
    $realized = $realizedByPeriod[$period['label']];
    $periodResults = [];

    $targetVendors = $allVendors;
    if ($vendorArg !== '') {
        $targetVendors = [$vendorArg];
    }

    foreach ($targetVendors as $vendorName) {
        if ($vendorName === '' || str_starts_with($vendorName, '__')) {
            continue;
        }

        $calc = $calculator->calculateForVendor(
            $period['start'],
            $period['end'],
            '',
            $vendorName,
            $targetData['rows']
        );

        $goalProportional = (float) ($calc['meta_total_periodo'] ?? 0.0);
        $realizedAmt      = (float) ($realized[$vendorName] ?? 0.0);
        $attainmentPct    = $goalProportional > 0
            ? round($realizedAmt / $goalProportional * 100, 2)
            : null;
        $gap              = round($goalProportional - $realizedAmt, 2);

        // Check for missing month targets
        $monthWarnings = [];
        foreach ((array) ($calc['breakdown'] ?? []) as $bk) {
            if ((float) ($bk['meta_mensal_cheia'] ?? 0) === 0.0) {
                $monthWarnings[] = 'Sem meta para ' . $bk['year_month'];
            }
        }

        $periodResults[] = [
            'vendor_name'       => $vendorName,
            'goal_proportional' => $goalProportional,
            'realized_period'   => $realizedAmt,
            'attainment_pct'    => $attainmentPct,
            'gap'               => $gap,
            'month_breakdown'   => $calc['breakdown'] ?? [],
            'warnings'          => $monthWarnings,
        ];
    }

    $results[] = [
        'period'  => $period,
        'vendors' => $periodResults,
    ];
}

// ── Build output ──────────────────────────────────────────────────────────────

$output = [
    'generated_at'  => date(DATE_ATOM),
    'user_period'   => ['start' => $startDate, 'end' => $endDate],
    'vendor_filter' => $vendorArg ?: null,
    'targets_source'=> $targetData['source'],
    'targets_warnings' => $targetData['warnings'],
    'self_test'     => $selfTest,
    'results'       => $results,
];

if ($isJson) {
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit($selfTest['passed'] ? 0 : 1);
}

// ── Pretty text output ────────────────────────────────────────────────────────

echo "\n=== sales_goal_period_consistency_audit ===\n";
echo "Periodo do usuario: {$startDate} a {$endDate}\n";
if ($vendorArg !== '') {
    echo "Filtro de vendedor: {$vendorArg}\n";
}
echo "Fonte de metas: " . $targetData['source'] . "\n";

if ($targetData['warnings'] !== []) {
    foreach ($targetData['warnings'] as $w) {
        echo "[AVISO] {$w}\n";
    }
}

// Self-test result
$stIcon = $selfTest['passed'] ? '[PASS]' : '[FAIL]';
echo "\n{$stIcon} SalesGoalPeriodCalculator self-test";
if (!$selfTest['passed']) {
    foreach ($selfTest['failures'] as $f) {
        echo "\n        {$f}";
    }
}
echo "\n";

foreach ($results as $periodResult) {
    $p = $periodResult['period'];
    echo "\n--- Periodo: {$p['label']} ({$p['start']} a {$p['end']}) ---\n";

    foreach ($periodResult['vendors'] as $vr) {
        $attLabel = $vr['attainment_pct'] !== null ? sprintf('%.1f%%', $vr['attainment_pct']) : 'N/A';
        $gapLabel = $vr['gap'] >= 0 ? '-' . sgpcaFmt($vr['gap']) : '+' . sgpcaFmt(abs($vr['gap']));
        printf(
            "  %-35s  meta=%s  realiz=%s  ating=%s  gap=%s\n",
            $vr['vendor_name'],
            sgpcaFmt($vr['goal_proportional']),
            sgpcaFmt($vr['realized_period']),
            $attLabel,
            $gapLabel
        );
        foreach ($vr['warnings'] as $w) {
            echo "    [AVISO] {$w}\n";
        }
    }
}

echo "\n";
exit($selfTest['passed'] ? 0 : 1);
