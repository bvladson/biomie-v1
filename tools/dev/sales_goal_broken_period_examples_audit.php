<?php

declare(strict_types=1);

/**
 * tools/dev/sales_goal_broken_period_examples_audit.php
 *
 * COM.R1.3 — Auditoria de exemplos de meta proporcional para períodos quebrados.
 *
 * Exibe o cálculo completo de meta proporcional (dias por mês, fração,
 * meta proporcional, realizado e atingimento) para 5 períodos de referência.
 *
 * Usage:
 *   php tools/dev/sales_goal_broken_period_examples_audit.php --start=2026-04-15 --end=2026-05-15
 *   php tools/dev/sales_goal_broken_period_examples_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
 *   php tools/dev/sales_goal_broken_period_examples_audit.php --start=2026-01-01 --end=2026-05-29 --json
 *
 * Exit codes:
 *   0 — success
 *   2 — DB error
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

// ── CLI params ────────────────────────────────────────────────────────────────

$opts      = getopt('', ['start:', 'end:', 'pretty', 'json']);
$startDate = isset($opts['start']) ? trim((string) $opts['start']) : date('Y-01-01');
$endDate   = isset($opts['end'])   ? trim((string) $opts['end'])   : date('Y-m-d');
$isPretty  = isset($opts['pretty']);
$isJson    = isset($opts['json']);

// ── DB ────────────────────────────────────────────────────────────────────────

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    $msg = 'ERRO: Falha ao conectar ao banco: ' . $e->getMessage();
    if ($isJson) {
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function sgbpTableExists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $st->execute([':t' => $table]);
        return (int) $st->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

function sgbpFmtBrl(float $val): string
{
    return 'R$' . number_format($val, 2, ',', '.');
}

function sgbpFmtPct(float $pct): string
{
    return number_format($pct, 1, ',', '.') . '%';
}

/**
 * Returns days in month using cal_days_in_month if available, else date().
 */
function sgbpDaysInMonth(int $month, int $year): int
{
    if (function_exists('cal_days_in_month')) {
        return cal_days_in_month(CAL_GREGORIAN, $month, $year);
    }
    return (int) date('t', mktime(0, 0, 0, $month, 1, $year));
}

/**
 * Build month-by-month breakdown for a period [start .. end].
 *
 * Each entry: month (YYYY-MM), days_in_month, overlap_days, fraction,
 *             first_overlap (YYYY-MM-DD), last_overlap (YYYY-MM-DD)
 *
 * @return list<array<string,mixed>>
 */
function sgbpMonthBreakdown(string $start, string $end): array
{
    $startDt = new DateTimeImmutable($start);
    $endDt   = new DateTimeImmutable($end);

    $cursor  = new DateTimeImmutable($startDt->format('Y-m') . '-01');
    $rows    = [];

    while ($cursor <= $endDt) {
        $year  = (int) $cursor->format('Y');
        $month = (int) $cursor->format('m');
        $ym    = $cursor->format('Y-m');
        $daysInMonth = sgbpDaysInMonth($month, $year);

        $monthFirst = $cursor;
        $monthLast  = new DateTimeImmutable($cursor->format('Y-m-') . str_pad((string) $daysInMonth, 2, '0', STR_PAD_LEFT));

        $overlapFirst = $startDt > $monthFirst ? $startDt : $monthFirst;
        $overlapLast  = $endDt   < $monthLast  ? $endDt   : $monthLast;

        // Inclusive day count
        $overlapDays = (int) $overlapFirst->diff($overlapLast)->days + 1;
        if ($overlapDays < 0) {
            $overlapDays = 0;
        }

        $fraction = $daysInMonth > 0 ? round($overlapDays / $daysInMonth, 6) : 0.0;

        $rows[] = [
            'month'         => $ym,
            'days_in_month' => $daysInMonth,
            'overlap_days'  => $overlapDays,
            'fraction'      => $fraction,
            'first_overlap' => $overlapFirst->format('Y-m-d'),
            'last_overlap'  => $overlapLast->format('Y-m-d'),
        ];

        $cursor = $cursor->modify('+1 month');
    }

    return $rows;
}

/**
 * Load monthly targets for a vendor from DB (biomie_vendor_targets).
 * Falls back to R$500 000/month if table doesn't exist.
 *
 * @return array<string,float>  keyed by YYYY-MM
 */
function sgbpLoadTargets(PDO $pdo, bool $hasTargets, string $vendorName, string $startYm, string $endYm): array
{
    if (!$hasTargets) {
        // Synthetic fallback: iterate months
        $targets = [];
        $cur = new DateTimeImmutable($startYm . '-01');
        $lim = new DateTimeImmutable($endYm . '-01');
        while ($cur <= $lim) {
            $targets[$cur->format('Y-m')] = 500_000.0;
            $cur = $cur->modify('+1 month');
        }
        return $targets;
    }

    try {
        $st = $pdo->prepare(
            'SELECT year_month, COALESCE(target_amount, 0) AS target_amount
             FROM biomie_vendor_targets
             WHERE vendor_name = :vn
               AND year_month BETWEEN :ym_start AND :ym_end
             ORDER BY year_month'
        );
        $st->execute([':vn' => $vendorName, ':ym_start' => $startYm, ':ym_end' => $endYm]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            // Synthetic for this vendor
            $targets = [];
            $cur = new DateTimeImmutable($startYm . '-01');
            $lim = new DateTimeImmutable($endYm . '-01');
            while ($cur <= $lim) {
                $targets[$cur->format('Y-m')] = 500_000.0;
                $cur = $cur->modify('+1 month');
            }
            return $targets;
        }

        $targets = [];
        foreach ($rows as $r) {
            $targets[(string) $r['year_month']] = (float) $r['target_amount'];
        }
        return $targets;
    } catch (\Throwable) {
        // Synthetic fallback
        $targets = [];
        $cur = new DateTimeImmutable($startYm . '-01');
        $lim = new DateTimeImmutable($endYm . '-01');
        while ($cur <= $lim) {
            $targets[$cur->format('Y-m')] = 500_000.0;
            $cur = $cur->modify('+1 month');
        }
        return $targets;
    }
}

/**
 * Load realized amount per vendor from canonical NF for a period.
 *
 * @return array<string,float>  keyed by nome_vendedor
 */
function sgbpLoadRealized(PDO $pdo, string $start, string $end): array
{
    try {
        $st = $pdo->prepare(
            "SELECT nome_vendedor,
                    COALESCE(SUM(COALESCE(valor_base_comercial_nf, valor_mercadorias)), 0) AS realizado
             FROM biomie_notas_fiscais_canonicas
             WHERE status_fiscal = 'autorizada'
               AND operation_scope = 'commercial'
               AND COALESCE(is_biomie_commercial_visible, 1) = 1
               AND COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e
             GROUP BY nome_vendedor
             ORDER BY realizado DESC"
        );
        $st->execute([':s' => $start, ':e' => $end]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) $r['nome_vendedor']);
            if ($name !== '') {
                $out[$name] = (float) $r['realizado'];
            }
        }
        return $out;
    } catch (\Throwable) {
        return [];
    }
}

/**
 * Load distinct vendor names from canonical NF.
 *
 * @return list<string>
 */
function sgbpLoadVendors(PDO $pdo): array
{
    try {
        $st = $pdo->query(
            "SELECT DISTINCT nome_vendedor
             FROM biomie_notas_fiscais_canonicas
             WHERE nome_vendedor IS NOT NULL AND nome_vendedor <> ''
             ORDER BY nome_vendedor
             LIMIT 50"
        );
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($rows, fn($v) => trim((string) $v) !== ''));
    } catch (\Throwable) {
        return ['GABRIEL BENÍCIO', 'VENDEDOR DEMO'];
    }
}

/**
 * Build proportional goal + realized breakdown for one vendor + one period.
 *
 * @param  array<string,float> $monthlyTargets  keyed by YYYY-MM → full month target
 * @param  float               $realized
 * @param  list<array<string,mixed>> $breakdown  from sgbpMonthBreakdown()
 * @return array<string,mixed>
 */
function sgbpComputeGoalBreakdown(array $monthlyTargets, float $realized, array $breakdown): array
{
    $totalProportional = 0.0;
    $totalDays         = 0;
    $months            = [];

    foreach ($breakdown as $row) {
        $ym          = $row['month'];
        $fullTarget  = $monthlyTargets[$ym] ?? 500_000.0;
        $fraction    = $row['fraction'];
        $propTarget  = $fullTarget * $fraction;
        $totalProportional += $propTarget;
        $totalDays         += $row['overlap_days'];

        $months[] = [
            'month'              => $ym,
            'days_in_month'      => $row['days_in_month'],
            'overlap_days'       => $row['overlap_days'],
            'fraction'           => $fraction,
            'full_target'        => $fullTarget,
            'proportional_target'=> $propTarget,
        ];
    }

    $attainment = $totalProportional > 0 ? ($realized / $totalProportional) * 100 : 0.0;
    $gap        = $realized - $totalProportional;

    return [
        'months'              => $months,
        'total_days'          => $totalDays,
        'total_proportional'  => $totalProportional,
        'realized'            => $realized,
        'attainment_pct'      => $attainment,
        'gap'                 => $gap,
    ];
}

// ── Build reference periods ───────────────────────────────────────────────────

$today    = date('Y-m-d');
$thisYm   = date('Y-m');
$curYear  = (int) date('Y');

$periods = [
    [
        'label' => 'User-supplied',
        'start' => $startDate,
        'end'   => $endDate,
    ],
    [
        'label' => 'Quebrado fev-mar 2026',
        'start' => '2026-02-15',
        'end'   => '2026-03-15',
    ],
    [
        'label' => 'Quebrado abr-mai 2026',
        'start' => '2026-04-15',
        'end'   => '2026-05-15',
    ],
    [
        'label' => 'Ano completo 2026 (YTD)',
        'start' => '2026-01-01',
        'end'   => '2026-05-29',
    ],
    [
        'label' => 'Mês corrente (mai/2026)',
        'start' => '2026-05-01',
        'end'   => '2026-05-31',
    ],
];

// ── Load vendors ──────────────────────────────────────────────────────────────

$vendors = sgbpLoadVendors($pdo);
if (empty($vendors)) {
    $vendors = ['VENDEDOR DEMO'];
}

$hasTargets = sgbpTableExists($pdo, 'biomie_vendor_targets');

// ── Compute ───────────────────────────────────────────────────────────────────

$output = [];

foreach ($periods as $period) {
    $pStart    = $period['start'];
    $pEnd      = $period['end'];
    $pLabel    = $period['label'];
    $breakdown = sgbpMonthBreakdown($pStart, $pEnd);
    $startYm   = substr($pStart, 0, 7);
    $endYm     = substr($pEnd,   0, 7);

    // Realized per vendor for this period
    $realized = sgbpLoadRealized($pdo, $pStart, $pEnd);

    $vendorResults = [];
    foreach ($vendors as $vendor) {
        $monthlyTargets = sgbpLoadTargets($pdo, $hasTargets, $vendor, $startYm, $endYm);
        $vendorRealized = $realized[$vendor] ?? 0.0;
        $goalData = sgbpComputeGoalBreakdown($monthlyTargets, $vendorRealized, $breakdown);

        $vendorResults[] = [
            'vendor_name' => $vendor,
            'goal_data'   => $goalData,
        ];
    }

    $output[] = [
        'period_label' => $pLabel,
        'start'        => $pStart,
        'end'          => $pEnd,
        'vendors'      => $vendorResults,
    ];
}

// ── Output ────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode([
        'tool'                => 'sales_goal_broken_period_examples_audit',
        'targets_source'      => $hasTargets ? 'biomie_vendor_targets' : 'synthetic_500k',
        'periods'             => $output,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// Pretty / plain text
$sep    = str_repeat('─', 100);
$sepTop = str_repeat('═', 100);

echo "=== sales_goal_broken_period_examples_audit ===\n";
echo "Fonte de metas: " . ($hasTargets ? 'biomie_vendor_targets' : 'Sintética R$500.000/mês por vendedor') . "\n";
echo $sepTop . "\n";

foreach ($output as $periodResult) {
    echo "\nPERÍODO: {$periodResult['period_label']}  |  {$periodResult['start']} a {$periodResult['end']}\n";
    echo $sep . "\n";

    foreach ($periodResult['vendors'] as $vr) {
        $vendor   = $vr['vendor_name'];
        $gd       = $vr['goal_data'];
        $months   = $gd['months'];

        echo "\nVendedor: {$vendor}  |  Period: {$periodResult['start']} a {$periodResult['end']}\n";

        // Table header
        printf(
            "┌─────────┬───────────────┬──────────┬────────────┬───────────────┬──────────────────┐\n"
        );
        printf(
            "│  %-5s  │ %-13s │ %-8s │ %-10s │ %-13s │ %-16s │\n",
            'Mês', 'Dias do mês', 'Dias ✓', 'Fração', 'Meta cheia', 'Meta proporcional'
        );
        printf(
            "├─────────┼───────────────┼──────────┼────────────┼───────────────┼──────────────────┤\n"
        );

        foreach ($months as $mrow) {
            printf(
                "│ %-7s │ %13s │ %8d │ %10s │ %13s │ %16s │\n",
                $mrow['month'],
                str_pad((string) $mrow['days_in_month'], 13, ' ', STR_PAD_BOTH),
                $mrow['overlap_days'],
                number_format($mrow['fraction'], 6, '.', ''),
                sgbpFmtBrl($mrow['full_target']),
                sgbpFmtBrl($mrow['proportional_target'])
            );
        }

        printf(
            "├─────────┼───────────────┼──────────┼────────────┼───────────────┼──────────────────┤\n"
        );
        printf(
            "│ %-7s │ %13s │ %8d │ %10s │ %13s │ %16s │\n",
            'TOTAL',
            '',
            $gd['total_days'],
            '',
            '',
            sgbpFmtBrl($gd['total_proportional'])
        );
        printf(
            "└─────────┴───────────────┴──────────┴────────────┴───────────────┴──────────────────┘\n"
        );

        $gapSign = $gd['gap'] >= 0 ? '+' : '';
        printf(
            "  Realizado: %-14s | Atingimento: %-8s | Gap: %s%s\n\n",
            sgbpFmtBrl($gd['realized']),
            sgbpFmtPct($gd['attainment_pct']),
            $gapSign,
            sgbpFmtBrl($gd['gap'])
        );
    }
}

exit(0);
