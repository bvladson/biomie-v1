<?php

declare(strict_types=1);

/**
 * COM.R1.3 FASE 2 — Auditoria de Metas Proporcionais por Período
 *
 * Compara meta proporcional (SalesGoalPeriodCalculator) com realizado por NF autorizada.
 * Mostra breakdown por mês e por vendedor.
 *
 * Uso:
 *   php tools/dev/sales_goal_period_audit.php --start=YYYY-MM-DD --end=YYYY-MM-DD
 *       [--vendor=nome_ou_id] [--pretty] [--json]
 *
 * Read-only. Sem alterações.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SalesGoalPeriodCalculator;

$opts     = getopt('', ['start:', 'end:', 'vendor:', 'pretty', 'json']);
$start    = (string) ($opts['start']  ?? date('Y-01-01'));
$end      = (string) ($opts['end']    ?? date('Y-m-d'));
$vendor   = isset($opts['vendor']) ? trim((string) $opts['vendor']) : null;
$pretty   = isset($opts['pretty']);
$jsonMode = isset($opts['json']);

$t0  = microtime(true);
$calc = new SalesGoalPeriodCalculator();

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function tblG(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $t]);
    return (int)$s->fetchColumn() > 0;
}

$hasTargets = tblG($pdo, 'biomie_sales_targets_monthly');
$hasNfs     = tblG($pdo, 'biomie_notas_fiscais_canonicas');
$hasVendors = tblG($pdo, 'biomie_pedidos_canonicos');

// ── 1. Load monthly targets from DB ──────────────────────────────────────────
$targetsRows = [];
if ($hasTargets) {
    $sT = $pdo->prepare(
        "SELECT COALESCE(vendor_id,'') AS vendor_id,
                COALESCE(vendor_name,'') AS vendor_name,
                `year_month`,
                COALESCE(target_amount,0) AS target_amount
         FROM biomie_sales_targets_monthly
         WHERE `year_month` BETWEEN :ym_start AND :ym_end
         ORDER BY `year_month`, vendor_name"
    );
    $ymStart = substr($start, 0, 7);
    $ymEnd   = substr($end,   0, 7);
    $sT->execute([':ym_start' => $ymStart, ':ym_end' => $ymEnd]);
    $targetsRows = $sT->fetchAll(PDO::FETCH_ASSOC);
}

// Build team monthly total
$teamMonthly = [];
foreach ($targetsRows as $r) {
    $ym     = (string) $r['year_month'];
    $amount = (float)  $r['target_amount'];
    $teamMonthly[$ym] = ($teamMonthly[$ym] ?? 0.0) + $amount;
}

// ── 2. Realizado por NF autorizada no período ─────────────────────────────────
$nfDateExpr = "COALESCE(data_competencia, data_emissao)";
$realizedTotal = 0.0;
$realizedByVendor = [];
$realizedByMonth  = [];

if ($hasNfs) {
    $vendorFilter = '';
    $nfParams = [':start' => $start, ':end' => $end];
    if ($vendor !== null) {
        $vendorFilter = " AND (nome_vendedor = :vendor OR codigo_vendedor_omie = :vendor)";
        $nfParams[':vendor'] = $vendor;
    }

    $sNf = $pdo->prepare(
        "SELECT codigo_vendedor_omie, nome_vendedor,
                DATE_FORMAT({$nfDateExpr},'%Y-%m') AS ym,
                SUM(valor_mercadorias) AS valor_merc,
                COUNT(DISTINCT nota_key) AS qtd_nfs
         FROM biomie_notas_fiscais_canonicas
         WHERE {$nfDateExpr} BETWEEN :start AND :end
           AND status_fiscal = 'autorizada'
           {$vendorFilter}
         GROUP BY codigo_vendedor_omie, nome_vendedor, ym
         ORDER BY valor_merc DESC
         LIMIT 200"
    );
    $sNf->execute($nfParams);
    $nfRows = $sNf->fetchAll(PDO::FETCH_ASSOC);

    foreach ($nfRows as $r) {
        $vKey = trim((string) $r['nome_vendedor']) ?: 'Sem vendedor';
        $ym   = (string) $r['ym'];
        $val  = (float)  $r['valor_merc'];

        $realizedTotal += $val;
        $realizedByVendor[$vKey] = ($realizedByVendor[$vKey] ?? 0.0) + $val;
        $realizedByMonth[$ym]    = ($realizedByMonth[$ym]    ?? 0.0) + $val;
    }
}

// ── 3. Team-level proportional goal ──────────────────────────────────────────
$teamGoal = $calc->calculate($start, $end, $teamMonthly);

// ── 4. Per-vendor goal + comparison ──────────────────────────────────────────
$vendorGoals = [];
$allVendorNames = array_unique(array_merge(
    array_keys($realizedByVendor),
    array_map(fn($r) => trim((string)$r['vendor_name']) ?: 'Sem vendedor', $targetsRows)
));

foreach ($allVendorNames as $vName) {
    if ($vendor !== null && mb_strtolower($vName) !== mb_strtolower($vendor) && $vName !== $vendor) continue;
    $vGoal     = $calc->calculateForVendor($start, $end, '', $vName, $targetsRows);
    $vRealized = $realizedByVendor[$vName] ?? 0.0;
    $vTarget   = $vGoal['meta_total_periodo'];
    $vAting    = $vTarget > 0 ? round($vRealized / $vTarget * 100, 1) : 0.0;

    $vendorGoals[] = [
        'vendor_name'       => $vName,
        'meta_proporcional' => $vTarget,
        'realizado_nf'      => round($vRealized, 2),
        'atingimento_pct'   => $vAting,
        'gap'               => round(max($vTarget - $vRealized, 0), 2),
        'breakdown'         => $vGoal['breakdown'],
    ];
}
usort($vendorGoals, fn($a,$b) => $b['realizado_nf'] <=> $a['realizado_nf']);

// ── 5. Self-test ──────────────────────────────────────────────────────────────
$selfTest = $calc->selfTest();

$elapsed = round(microtime(true) - $t0, 2);

// ── JSON ──────────────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'sales_goal_period_audit' => [
            'version'         => 'COM.R1.3',
            'period'          => ['start' => $start, 'end' => $end],
            'generated_at'    => date('Y-m-d H:i:s'),
            'elapsed_s'       => $elapsed,
            'team_goal'       => $teamGoal,
            'realized_total'  => round($realizedTotal, 2),
            'atingimento_pct' => $teamGoal['meta_total_periodo'] > 0
                ? round($realizedTotal / $teamGoal['meta_total_periodo'] * 100, 1)
                : 0.0,
            'gap'             => round(max($teamGoal['meta_total_periodo'] - $realizedTotal, 0), 2),
            'realized_by_month'  => $realizedByMonth,
            'vendor_goals'       => $vendorGoals,
            'self_test_passed'   => $selfTest['passed'],
            'self_test_failures' => $selfTest['failures'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty ────────────────────────────────────────────────────────────────────
function fmtR2(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function fmtN2(int $n): string   { return number_format($n, 0, ',', '.'); }
function fmtP2(float $v): string { return number_format($v, 1, ',', '.') . '%'; }
function bar(float $pct): string {
    $filled = min((int) ($pct / 5), 20);
    return '[' . str_repeat('█', $filled) . str_repeat('░', 20 - $filled) . '] ' . fmtP2($pct);
}

$hr = str_repeat('═', 72);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.3 — Auditoria de Metas Proporcionais por Período' . PHP_EOL;
echo '  Período : ' . $start . ' → ' . $end . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

// Self-test
$stIcon = $selfTest['passed'] ? '✓' : '✗';
echo "  Self-test SalesGoalPeriodCalculator: {$stIcon} " . ($selfTest['passed'] ? 'PASS' : 'FAIL') . PHP_EOL;
if (!$selfTest['passed']) {
    foreach ($selfTest['failures'] as $f) echo '    ! ' . $f . PHP_EOL;
}
echo PHP_EOL;

// Team summary
echo str_repeat('─', 72) . PHP_EOL;
echo '  Resumo do Time' . PHP_EOL;
echo str_repeat('─', 72) . PHP_EOL;
$ating = $teamGoal['meta_total_periodo'] > 0
    ? round($realizedTotal / $teamGoal['meta_total_periodo'] * 100, 1)
    : 0.0;
echo PHP_EOL;
echo '  Meta proporcional total : ' . fmtR2($teamGoal['meta_total_periodo']) . PHP_EOL;
echo '  Realizado (NF aut.)     : ' . fmtR2($realizedTotal) . PHP_EOL;
echo '  Atingimento             : ' . bar($ating) . PHP_EOL;
echo '  Gap                     : ' . fmtR2(max($teamGoal['meta_total_periodo'] - $realizedTotal, 0)) . PHP_EOL;
echo '  Dias no período         : ' . $teamGoal['dias_periodo'] . PHP_EOL;
echo '  Tabela de metas         : ' . ($hasTargets ? 'disponível' : 'NÃO encontrada') . PHP_EOL;

// Breakdown by month
echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
echo '  Breakdown por Mês' . PHP_EOL;
echo str_repeat('─', 72) . PHP_EOL;
echo PHP_EOL;
echo '  ' . str_pad('mês', 10) . str_pad('meta_cheia', 18) . str_pad('dias_consid/total', 20) . str_pad('% mês', 8) . str_pad('meta_prop', 18) . 'realizado_NF' . PHP_EOL;
echo '  ' . str_repeat('-', 68) . PHP_EOL;
foreach ($teamGoal['breakdown'] as $bk) {
    $realMes = $realizedByMonth[$bk['year_month']] ?? 0.0;
    $complete = $bk['is_month_complete'] ? '' : ' (parcial)';
    echo '  ' . str_pad($bk['year_month'] . $complete, 18)
        . str_pad(fmtR2($bk['meta_mensal_cheia']), 18)
        . str_pad($bk['dias_considerados'] . '/' . $bk['dias_do_mes'], 20)
        . str_pad(fmtP2($bk['percentual_mes']), 8)
        . str_pad(fmtR2($bk['meta_proporcional_mes']), 18)
        . fmtR2($realMes) . PHP_EOL;
}

// Per-vendor breakdown
if ($vendorGoals) {
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    echo '  Breakdown por Vendedor' . PHP_EOL;
    echo str_repeat('─', 72) . PHP_EOL;
    echo PHP_EOL;
    echo '  ' . str_pad('vendedor', 26) . str_pad('meta_prop', 18) . str_pad('realizado_NF', 18) . str_pad('ating.', 10) . 'gap' . PHP_EOL;
    echo '  ' . str_repeat('-', 72) . PHP_EOL;
    foreach ($vendorGoals as $vg) {
        echo '  ' . str_pad(mb_substr($vg['vendor_name'], 0, 24), 26)
            . str_pad(fmtR2($vg['meta_proporcional']), 18)
            . str_pad(fmtR2($vg['realizado_nf']), 18)
            . str_pad(fmtP2($vg['atingimento_pct']), 10)
            . fmtR2($vg['gap']) . PHP_EOL;
    }
}

echo PHP_EOL . $hr . PHP_EOL;
echo '  Concluído em ' . $elapsed . 's' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
