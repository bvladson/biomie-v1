<?php

declare(strict_types=1);

/**
 * tools/dev/sales_goal_broken_period_audit.php
 *
 * COM.R3 — Fase 0 — Diagnóstico de metas proporcionais para períodos quebrados.
 *
 * Testa automaticamente os períodos:
 *   - 2026-01-01 → 2026-05-29 (anual parcial)
 *   - 2026-02-15 → 2026-03-15 (quebrado cross-month)
 *   - 2026-04-15 → 2026-05-15 (quebrado cross-month)
 *   - Mês atual (mês cheio)
 *   - --start / --end passados pelo caller
 *
 * Para cada período mostra:
 *   - Dias por mês envolvido
 *   - Meta mensal base de cada vendedor
 *   - Meta proporcional calculada
 *   - Realizado por NF (biomie_notas_fiscais_canonicas)
 *   - Atingimento %
 *   - Gap
 *   - Status
 *
 * Usage:
 *   php tools/dev/sales_goal_broken_period_audit.php --company=MAG_FILIAL --pretty
 *   php tools/dev/sales_goal_broken_period_audit.php --company=MAG_FILIAL --start=2026-02-15 --end=2026-03-15 --pretty
 *
 * Exit: 0=tudo calculável 1=sem metas configuradas 2=erro DB
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SalesGoalPeriodCalculator;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\TenantCliContext;

$opts = getopt('', ['start:', 'end:', 'company:', 'pretty', 'json']);
$isPretty = isset($opts['pretty']);
$isJson   = isset($opts['json']);

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tenant = TenantCliContext::boot($pdo, $_SERVER['argv'] ?? []);
} catch (\Throwable $e) {
    $msg = 'ERRO DB: ' . $e->getMessage();
    echo ($isJson ? json_encode(['error' => $msg]) : $msg) . "\n";
    exit(2);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function sgbpTableExists(PDO $pdo, string $t): bool
{
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
        $s->execute(['t' => $t]);
        return (int) $s->fetchColumn() > 0;
    } catch (\Throwable) { return false; }
}

function sgbpFmt(float $v): string
{
    if ($v >= 1_000_000) return 'R$' . number_format($v / 1_000_000, 2, ',', '.') . 'M';
    if ($v >= 1_000)     return 'R$' . number_format($v / 1_000, 1, ',', '.') . 'k';
    return 'R$' . number_format($v, 2, ',', '.');
}

// ── Load targets ──────────────────────────────────────────────────────────────

$hasTargetsTable = sgbpTableExists($pdo, 'biomie_sales_targets_monthly');
$targetsRows = [];
if ($hasTargetsTable) {
    try {
        $stmt = $pdo->prepare(
            'SELECT vendor_id, vendor_name, year_month, target_amount
             FROM biomie_sales_targets_monthly
             WHERE company_id = :company_id
             ORDER BY year_month, vendor_name'
        );
        $stmt->execute(['company_id' => $tenant['company_id']]);
        $targetsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {}
}

// Build monthly aggregate (team total) per month
$teamMonthlyTargets = [];
foreach ($targetsRows as $row) {
    $ym = (string)($row['year_month'] ?? '');
    if ($ym === '') continue;
    $teamMonthlyTargets[$ym] = ($teamMonthlyTargets[$ym] ?? 0.0) + (float)($row['target_amount'] ?? 0);
}

// Get distinct vendors with targets
$vendorIds = [];
foreach ($targetsRows as $row) {
    $vn = trim((string)($row['vendor_name'] ?? ''));
    if ($vn !== '') $vendorIds[$vn] = $vn;
}

// ── Define periods to test ────────────────────────────────────────────────────

$periods = [];

// User-supplied period
if (isset($opts['start'], $opts['end'])) {
    $periods['custom'] = ['start' => trim($opts['start']), 'end' => trim($opts['end']), 'label' => 'Período customizado'];
}

// Standard test periods
$periods['anual_parcial'] = ['start' => '2026-01-01', 'end' => '2026-05-29', 'label' => 'Anual parcial (Jan–Mai/2026)'];
$periods['quebrado_fev_mar'] = ['start' => '2026-02-15', 'end' => '2026-03-15', 'label' => 'Quebrado Fev–Mar (15/02→15/03)'];
$periods['quebrado_abr_mai'] = ['start' => '2026-04-15', 'end' => '2026-05-15', 'label' => 'Quebrado Abr–Mai (15/04→15/05)'];
$periods['mes_atual'] = ['start' => date('Y-m-01'), 'end' => date('Y-m-t'), 'label' => 'Mês atual (' . date('M/Y') . ')'];
$periods['ano_atual'] = ['start' => date('Y-01-01'), 'end' => date('Y-12-31'), 'label' => 'Ano atual'];

// ── Calculator ────────────────────────────────────────────────────────────────

$calculator = new SalesGoalPeriodCalculator();
$visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');

$results = [];

foreach ($periods as $key => $period) {
    $start = $period['start'];
    $end   = $period['end'];

    // Team proportional goal
    $team = $calculator->calculate($start, $end, $teamMonthlyTargets);

    // Realized (canonical NFs)
    try {
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS realizado,
                COUNT(*) AS nfs,
                COUNT(DISTINCT nf.nome_vendedor) AS vendedores
             FROM biomie_notas_fiscais_canonicas nf
             WHERE nf.status_fiscal = 'autorizada'
               AND nf.operation_scope = 'commercial'
               AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
               AND {$visCond}"
        );
        $stmt->execute([':s' => $start, ':e' => $end]);
        $realized = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $realized = ['_error' => $e->getMessage()];
    }

    $meta      = $team['meta_total_periodo'];
    $realizado = (float)($realized['realizado'] ?? 0);
    $atingPct  = $meta > 0 ? round($realizado / $meta * 100, 1) : null;
    $gap       = $meta - $realizado;

    $status = match(true) {
        $meta === 0.0                          => 'sem_meta',
        $atingPct !== null && $atingPct >= 100 => 'atingido',
        $atingPct !== null && $atingPct >= 85  => 'atencao',
        $atingPct !== null && $atingPct >= 60  => 'abaixo',
        default                                => 'critico',
    };

    $results[$key] = [
        'label'         => $period['label'],
        'start'         => $start,
        'end'           => $end,
        'dias_periodo'  => $team['dias_periodo'],
        'meta_total'    => $meta,
        'realizado'     => $realizado,
        'atingimento_pct' => $atingPct,
        'gap'           => $gap,
        'status'        => $status,
        'nfs'           => (int)($realized['nfs'] ?? 0),
        'vendedores_ativos' => (int)($realized['vendedores'] ?? 0),
        'breakdown'     => $team['breakdown'],
    ];
}

// ── SelfTest ──────────────────────────────────────────────────────────────────

$selfTest = $calculator->selfTest();

$output = [
    'tool'               => 'sales_goal_broken_period_audit',
    'company_id'         => $tenant['company_id'],
    'company_slug'       => $tenant['slug'],
    'has_targets_table'  => $hasTargetsTable,
    'target_vendors'     => count($vendorIds),
    'target_months'      => count($teamMonthlyTargets),
    'calculator_selftest' => $selfTest,
    'periods'            => $results,
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($hasTargetsTable && count($targetsRows) > 0 ? 0 : 1);
}

$bar = str_repeat('═', 72);
$sep = str_repeat('─', 72);

echo "\n{$bar}\n Meta Proporcional — Diagnóstico de Períodos Quebrados\n{$bar}\n";
echo " Tabela metas   : " . ($hasTargetsTable ? "\033[32mOK\033[0m" : "\033[33mAUSENTE\033[0m") . "\n";
echo " Vendedores c/meta: {$output['target_vendors']} | Meses configurados: {$output['target_months']}\n";
echo " Selftest calc  : " . ($selfTest['passed'] ? "\033[32mPASS\033[0m" : "\033[31mFAIL — " . implode('; ', $selfTest['failures']) . "\033[0m") . "\n";
echo "{$sep}\n\n";

foreach ($results as $key => $r) {
    $statusColor = match($r['status']) {
        'atingido' => "\033[32m", 'atencao' => "\033[33m", 'sem_meta' => "\033[90m",
        default    => "\033[31m"
    };
    $statusLabel = strtoupper($r['status']);

    echo " {$r['label']}\n";
    printf("   %s → %s  (%d dias)\n", $r['start'], $r['end'], $r['dias_periodo']);
    printf("   Meta proporcional : %s\n", sgbpFmt($r['meta_total']));
    printf("   Realizado NF      : %s (%d NFs, %d vend)\n", sgbpFmt($r['realizado']), $r['nfs'], $r['vendedores_ativos']);
    if ($r['atingimento_pct'] !== null) {
        printf("   Atingimento       : %.1f%%  |  Gap: %s\n", $r['atingimento_pct'], sgbpFmt(abs($r['gap'])));
    }
    printf("   Status            : {$statusColor}%s\033[0m\n", $statusLabel);

    if (!empty($r['breakdown'])) {
        echo "   Breakdown mensal:\n";
        foreach ($r['breakdown'] as $b) {
            printf("     %s: %d/%d dias (%.1f%%)  meta=%s  proporcional=%s\n",
                $b['year_month'], $b['dias_considerados'], $b['dias_do_mes'],
                $b['percentual_mes'], sgbpFmt($b['meta_mensal_cheia']), sgbpFmt($b['meta_proporcional_mes'])
            );
        }
    }
    echo "\n";
}

echo "{$bar}\n\n";
exit($hasTargetsTable && count($targetsRows) > 0 ? 0 : 1);
