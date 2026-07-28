<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Modules\Sales\Application\Overview\OverviewMonthComparisonBuilder;
use BIomie\RequestContext;
use BIomie\TargetsRepository;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root   = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

$options  = getopt('', ['start:', 'end:', 'json', 'pretty']);
$start    = (string) ($options['start'] ?? date('Y-m-01'));
$end      = (string) ($options['end']   ?? date('Y-m-t'));
$isJson   = isset($options['json']) || isset($options['pretty']);
$isPretty = isset($options['pretty']);

$requestId  = RequestContext::id();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Nenhuma conexao MySQL configurada.\n");
    exit(1);
}

try {
    $pdo = Database::connect($config, $connection);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO ao conectar: ' . $e->getMessage() . "\n");
    exit(1);
}

$canonical         = new CanonicalRepository($pdo, $logger);
$targetsRepository = null;
try {
    $targetsRepository = new TargetsRepository($pdo, $logger);
} catch (Throwable) {
    // targets unconfigured — proceed without
}

$saturdayWeight = (float) $config->get('flight_plan.saturday_weight', 0.33);
$builder        = new OverviewMonthComparisonBuilder();

$comparison  = null;
$buildError  = null;
try {
    $comparison = $builder->build($pdo, $logger, $canonical, $targetsRepository, $start, $saturdayWeight);
} catch (Throwable $e) {
    $buildError = $e->getMessage();
}

$result = [
    'request_id'  => $requestId,
    'source'      => 'month_comparison_debug',
    'period'      => ['start' => $start, 'end' => $end],
    'build_error' => $buildError,
    'comparison'  => $comparison,
];

if ($isJson) {
    echo json_encode($result, $isPretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ── Text output ───────────────────────────────────────────────────────────────
echo "=== month_comparison_debug ===\n";
echo "Periodo : {$start} → {$end}\n";

if ($buildError !== null) {
    echo "ERRO    : {$buildError}\n";
    exit(1);
}

if ($comparison === null) {
    echo "Resultado nulo — verifique a conexao.\n";
    exit(1);
}

$period = $comparison['period'] ?? [];
echo sprintf("Competencia  : %s  |  Modo: %s\n", $period['year_month'] ?? '-', $comparison['mode'] ?? '-');
echo sprintf("Dias elapsed : %d / %d  |  Em andamento: %s\n",
    (int) ($period['elapsed_days'] ?? 0),
    (int) ($period['calendar_days'] ?? 0),
    ($period['is_month_in_progress'] ?? false) ? 'SIM' : 'NAO'
);

$fmt = static fn (?float $v): string => $v !== null ? 'R$ ' . number_format($v, 2, ',', '.') : 'N/A';
$fmtPct = static fn (?float $v): string => $v !== null ? sprintf('%+.1f%%', $v) : 'N/A';

$cur = $comparison['current'] ?? [];
echo "\n── Mês atual ({$period['year_month']}) ──────────────────────────\n";
echo sprintf("Receita     : %s  (%d NFs)\n", $fmt($cur['revenue'] ?? null), (int) ($cur['invoices_count'] ?? 0));
echo sprintf("Meta        : %s  |  Atingimento: %s\n", $fmt($cur['target'] ?? null), $fmtPct($cur['attainment_pct'] ?? null));
echo sprintf("Forecast    : %s\n", $fmt($cur['forecast'] ?? null));

$prev = $comparison['previous'] ?? [];
$prevYmLabel = (string) ($prev['year_month'] ?? '-');
echo "\n── Mês anterior ({$prevYmLabel}) ───────────────────\n";
echo sprintf("Receita     : %s  (%d NFs)\n", $fmt($prev['revenue'] ?? null), (int) ($prev['invoices_count'] ?? 0));

$pse = $comparison['previous_same_elapsed_days'] ?? null;
if ($pse !== null) {
    echo sprintf("Prev mesmos %d dias: %s  (%d NFs)\n",
        (int) ($pse['elapsed_days'] ?? 0),
        $fmt($pse['revenue'] ?? null),
        (int) ($pse['invoices_count'] ?? 0));
}

$avg = $comparison['last_3_months_avg'] ?? null;
if ($avg !== null) {
    echo sprintf("\nMedia 3 meses  : %s  (%d meses usados)\n", $fmt($avg['revenue'] ?? null), (int) ($avg['months_used'] ?? 0));
}

$best = $comparison['best_recent_month'] ?? null;
if ($best !== null) {
    echo sprintf("Melhor recente : %s — %s\n", $best['year_month'] ?? '-', $fmt($best['revenue'] ?? null));
}

$sly = $comparison['same_month_last_year'] ?? null;
if ($sly !== null) {
    echo sprintf("Mesmo mes ano anterior (%s): %s\n", $sly['year_month'] ?? '-', $fmt($sly['revenue'] ?? null));
}

$proj = $comparison['projection'] ?? null;
if ($proj !== null) {
    echo "\n── Projeção ────────────────────────────────────────────\n";
    echo sprintf("Provavel     : %s  |  Metodo: %s\n", $fmt($proj['probable'] ?? null), $proj['method'] ?? '-');
    echo sprintf("Otimista     : %s  |  Pessimista: %s\n", $fmt($proj['optimistic'] ?? null), $fmt($proj['pessimistic'] ?? null));
    echo sprintf("Dias restantes: %d\n", (int) ($proj['remaining_days'] ?? 0));
}

$deltas = $comparison['deltas'] ?? [];
echo "\n── Deltas ──────────────────────────────────────────────\n";
echo sprintf("vs mes anterior       : %s\n", $fmtPct($deltas['vs_previous_pct'] ?? null));
echo sprintf("vs media 3 meses      : %s\n", $fmtPct($deltas['vs_3m_avg_pct'] ?? null));
echo sprintf("vs mesmo elapsed      : %s\n", $fmtPct($deltas['vs_same_elapsed_pct'] ?? null));
echo sprintf("vs meta               : %s\n", $fmtPct($deltas['vs_target_pct'] ?? null));
echo sprintf("vs mesmo mes ano ant. : %s\n", $fmtPct($deltas['vs_same_month_last_year_pct'] ?? null));

$insights = $comparison['insights'] ?? [];
echo sprintf("\n── Insights (%d) ───────────────────────────────────────\n", count($insights));
foreach ($insights as $i => $ins) {
    $valPct = $ins['value_pct'] !== null ? sprintf(' (%+.1f%%)', $ins['value_pct']) : '';
    echo sprintf("  [%d] %-10s %-25s %s\n",
        $i + 1,
        strtoupper($ins['severity'] ?? 'info'),
        $ins['label'] ?? '',
        $valPct !== '' ? $valPct : ''
    );
    echo sprintf("       %s\n", $ins['detail'] ?? '');
}

$rows = $comparison['rows'] ?? [];
echo sprintf("\n── Rows para chart (%d linhas) ─────────────────────────\n", count($rows));
foreach ($rows as $row) {
    echo sprintf("  %s  receita=%s  meta=%s  is_current=%s\n",
        $row['year_month'] ?? '-',
        $fmt($row['revenue'] ?? null),
        $fmt($row['target'] ?? null),
        ($row['is_current'] ?? false) ? 'SIM' : 'NAO'
    );
}

echo sprintf("\nReason code  : %s\n", $comparison['reason_code'] ?? '(nenhum)');
echo "\nUso: php tools/dev/month_comparison_debug.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--pretty]\n";
