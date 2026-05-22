<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Modules\Finance\Application\FinanceOverviewBuilder;
use BIomie\Modules\Finance\Infrastructure\FinanceReadRepository;
use BIomie\RequestContext;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root   = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

$options  = getopt('', ['start:', 'end:', 'json', 'pretty', 'sample:']);
$start    = (string) ($options['start']  ?? date('Y-m-01'));
$end      = (string) ($options['end']    ?? date('Y-m-t'));
$isJson   = isset($options['json']) || isset($options['pretty']);
$isPretty = isset($options['pretty']);
$sampleN  = max(1, min(50, (int) ($options['sample'] ?? 5)));

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

$financeRepo = new FinanceReadRepository(static fn (): PDO => $pdo);
$builder     = new FinanceOverviewBuilder($financeRepo);

// ── Table info ────────────────────────────────────────────────────────────────
$tableInfo     = $financeRepo->tableInfo();
$fieldsDetected = $financeRepo->detectFinanceFields();

// ── Build finance summary ─────────────────────────────────────────────────────
$summary = null;
$buildError = null;
try {
    $summary = $builder->build($start, $end, null, 0.0, $requestId, $logger);
} catch (Throwable $e) {
    $buildError = $e->getMessage();
}

// ── Overdue customers sample ──────────────────────────────────────────────────
$overdueCustomers = [];
if ($tableInfo['exists'] && $tableInfo['total_rows'] > 0) {
    try {
        $overdueCustomers = $financeRepo->listOverdueCustomers($start, $end, null, $sampleN);
    } catch (Throwable $e) {
        $overdueCustomers = [['error' => $e->getMessage()]];
    }
}

$result = [
    'request_id' => $requestId,
    'source'     => 'finance_overview_debug',
    'period'     => ['start' => $start, 'end' => $end],
    'table_info' => $tableInfo,
    'fields_detected' => $fieldsDetected,
    'summary_available' => ($summary['available'] ?? false),
    'reason_code'       => $summary['reason_code'] ?? ($buildError !== null ? 'builder_exception' : null),
    'build_error'       => $buildError,
    'totals'            => $summary !== null ? ($summary['totals'] ?? []) : [],
    'counts'            => $summary !== null ? ($summary['counts'] ?? []) : [],
    'risk'              => $summary !== null ? ($summary['risk'] ?? []) : [],
    'diagnostics'       => $summary !== null ? ($summary['diagnostics'] ?? []) : [],
    'overdue_customers_sample' => $overdueCustomers,
];

if ($isJson) {
    echo json_encode($result, $isPretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ── Text output ───────────────────────────────────────────────────────────────
echo "=== finance_overview_debug ===\n";
echo "Periodo : {$start} → {$end}\n";
echo "Table   : biomie_titulos_financeiros_canonicos\n";
echo sprintf("Exists  : %s  |  Total rows: %d\n",
    $tableInfo['exists'] ? 'SIM' : 'NAO',
    $tableInfo['total_rows'] ?? 0);

echo "\n── Resultado ──────────────────────────────────────\n";
echo sprintf("Available   : %s\n", ($summary['available'] ?? false) ? 'SIM' : 'NAO');
echo sprintf("Reason code : %s\n", $result['reason_code'] ?? '(nenhum)');
if ($buildError !== null) {
    echo sprintf("Build error : %s\n", $buildError);
}

if (!empty($result['totals'])) {
    $t = $result['totals'];
    echo "\n── Totais ─────────────────────────────────────────\n";
    echo sprintf("Recebido    : R$ %s\n", number_format((float) ($t['received'] ?? 0), 2, ',', '.'));
    echo sprintf("Em aberto   : R$ %s\n", number_format((float) ($t['open'] ?? 0), 2, ',', '.'));
    echo sprintf("Vencido     : R$ %s\n", number_format((float) ($t['overdue'] ?? 0), 2, ',', '.'));
    echo sprintf("Conv. caixa : %s%%\n", $t['cash_conversion_pct'] !== null ? number_format((float) $t['cash_conversion_pct'], 1, ',', '.') : 'N/A');
}

if (!empty($result['counts'])) {
    $c = $result['counts'];
    echo "\n── Contagens ──────────────────────────────────────\n";
    echo sprintf("Recebidos   : %d titulos\n", (int) ($c['received_titles'] ?? 0));
    echo sprintf("Em aberto   : %d titulos\n", (int) ($c['open_titles'] ?? 0));
    echo sprintf("Vencidos    : %d titulos | %d clientes\n", (int) ($c['overdue_titles'] ?? 0), (int) ($c['overdue_customers'] ?? 0));
}

if (!empty($result['risk'])) {
    $r = $result['risk'];
    echo "\n── Risco ──────────────────────────────────────────\n";
    echo sprintf("Level : %s  |  Score : %d\n", strtoupper($r['level'] ?? 'unknown'), (int) ($r['score'] ?? 0));
    if (!empty($r['reasons'])) {
        echo sprintf("Razoes: %s\n", implode(', ', (array) $r['reasons']));
    }
}

if (!empty($overdueCustomers)) {
    echo "\n── Clientes vencidos (amostra {$sampleN}) ─────────────\n";
    foreach ($overdueCustomers as $i => $cust) {
        if (isset($cust['error'])) {
            echo sprintf("  [%d] ERRO: %s\n", $i + 1, $cust['error']);
            continue;
        }
        echo sprintf("  [%d] %-30s  Vencido: R$ %s  (%d titulos, max %d dias)\n",
            $i + 1,
            mb_strimwidth((string) ($cust['customer_name'] ?? ''), 0, 30, '...'),
            number_format((float) ($cust['overdue_amount'] ?? 0), 2, ',', '.'),
            (int) ($cust['titles_count'] ?? 0),
            (int) ($cust['max_days_overdue'] ?? 0)
        );
    }
}

if (!empty($result['diagnostics'])) {
    $d = $result['diagnostics'];
    echo "\n── Diagnósticos ───────────────────────────────────\n";
    echo sprintf("Runtime  : %dms  |  Queries: %d\n",
        (int) ($d['runtime_ms'] ?? 0),
        (int) ($d['query_count'] ?? 0));
    if (!empty($d['errors'])) {
        echo sprintf("Errors   : %d\n", count($d['errors']));
        foreach ($d['errors'] as $err) {
            echo sprintf("  - [%s] %s\n", $err['step'] ?? '?', $err['message'] ?? '');
        }
    }
    if (isset($d['performance_warning'])) {
        echo sprintf("AVISO    : %s\n", $d['performance_warning']);
    }
}

echo "\nUso: php tools/dev/finance_overview_debug.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--pretty] [--sample=N]\n";
