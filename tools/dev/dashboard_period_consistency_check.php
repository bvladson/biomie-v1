<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = parseArgs($argv);
$json = isset($opts['json']);
$start = (string) ($opts['start'] ?? date('Y-m-01'));
$end = (string) ($opts['end'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    fwrite(STDERR, "Use --start=YYYY-MM-DD --end=YYYY-MM-DD.\n");
    exit(2);
}

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));
$canonical = new CanonicalRepository($pdo, $logger);

$report = buildConsistencyReport($pdo, $canonical, $start, $end);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['status'] === 'CRITICAL' ? 1 : 0);
}

printPretty($report);
exit($report['status'] === 'CRITICAL' ? 1 : 0);

/**
 * @return array<string,string|bool>
 */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $out[$key] = $value;
    }
    return $out;
}

/**
 * @return array<string,mixed>
 */
function buildConsistencyReport(PDO $pdo, CanonicalRepository $canonical, string $start, string $end): array
{
    $summary = $canonical->summarizeInvoicesCommercial($start, $end);
    $products = $canonical->topProductsCommercial($start, $end, 12);
    $clients = $canonical->topClientsCommercial($start, $end, 12);
    $vendors = $canonical->aggregateInvoicesByVendorCommercial($start, $end);
    $daily = $canonical->dailyByStatusCommercial($start, $end);
    $periodIsFullMonth = isFullCalendarMonth($start, $end);
    $competencias = competenciasInRange($start, $end);
    $monthlySignals = monthlyMartSignals($pdo, $competencias);
    $codeGuard = overviewCodeGuard();

    $issues = [];
    $status = 'OK';
    $revenue = (float) ($summary['billed_value'] ?? 0);
    $nfs = (int) ($summary['billed_orders'] ?? 0);

    if ($nfs === 0 && ($products !== [] || $clients !== [] || $vendors !== [])) {
        $status = 'CRITICAL';
        $issues[] = 'Leituras canonicas do mesmo periodo discordam: KPI sem NF, mas ranking/listas com dados.';
    }

    if (!$periodIsFullMonth && $nfs === 0 && ($monthlySignals['has_monthly_rows'] ?? false) && !$codeGuard['partial_month_guard']) {
        $status = 'CRITICAL';
        $issues[] = 'Janela parcial sem NF, mas marts mensais podem aparecer no dashboard sem guarda de periodo.';
    } elseif (!$periodIsFullMonth && ($monthlySignals['has_monthly_rows'] ?? false)) {
        $issues[] = 'Existem marts mensais no mes, mas o codigo bloqueia fallback mensal em janela parcial.';
    }

    if (!$codeGuard['partial_month_guard']) {
        $status = $status === 'CRITICAL' ? 'CRITICAL' : 'WARNING';
        $issues[] = 'CanonicalOverviewPayloadBuilder nao possui guarda isFullCalendarMonth detectada.';
    }

    return [
        'ok' => $status !== 'CRITICAL',
        'status' => $status,
        'generated_at' => date(DATE_ATOM),
        'period' => ['start' => $start, 'end' => $end, 'is_full_calendar_month' => $periodIsFullMonth],
        'summary' => [
            'revenue' => $revenue,
            'nfs' => $nfs,
            'active_clients' => (int) ($summary['active_clients'] ?? 0),
            'active_vendors' => (int) ($summary['active_vendors'] ?? 0),
        ],
        'canonical_sections' => [
            'top_products' => count($products),
            'top_clients' => count($clients),
            'vendor_ranking' => count($vendors),
            'daily_rows' => count($daily),
        ],
        'monthly_marts' => $monthlySignals,
        'code_guard' => $codeGuard,
        'issues' => $issues,
        'classification' => $status === 'CRITICAL'
            ? 'dashboard_period_mixing_risk'
            : ($issues === [] ? 'period_consistent' : 'period_consistent_with_monthly_marts_present'),
        'read_only' => true,
    ];
}

function printPretty(array $report): void
{
    echo PHP_EOL . 'Dashboard Period Consistency Check' . PHP_EOL;
    echo str_repeat('-', 72) . PHP_EOL;
    echo 'Periodo: ' . $report['period']['start'] . ' a ' . $report['period']['end'] . PHP_EOL;
    echo 'Status: ' . $report['status'] . ' | ' . $report['classification'] . PHP_EOL;
    echo 'KPI canonico: ' . (int) $report['summary']['nfs'] . ' NF(s), R$ ' . number_format((float) $report['summary']['revenue'], 2, ',', '.') . PHP_EOL;
    echo 'Secoes canonicas: produtos=' . (int) $report['canonical_sections']['top_products']
        . ', clientes=' . (int) $report['canonical_sections']['top_clients']
        . ', vendedores=' . (int) $report['canonical_sections']['vendor_ranking']
        . ', serie=' . (int) $report['canonical_sections']['daily_rows'] . PHP_EOL;
    echo 'Guarda parcial: ' . (($report['code_guard']['partial_month_guard'] ?? false) ? 'sim' : 'nao') . PHP_EOL;
    echo 'Marts mensais no mes: ' . (($report['monthly_marts']['has_monthly_rows'] ?? false) ? 'sim' : 'nao') . PHP_EOL;

    if (($report['issues'] ?? []) !== []) {
        echo PHP_EOL . 'Observacoes:' . PHP_EOL;
        foreach ($report['issues'] as $issue) {
            echo ' - ' . $issue . PHP_EOL;
        }
    }
    echo PHP_EOL;
}

/**
 * @return list<string>
 */
function competenciasInRange(string $start, string $end): array
{
    $cursor = strtotime(date('Y-m-01', strtotime($start) ?: time()));
    $limit = strtotime(date('Y-m-01', strtotime($end) ?: time()));
    $out = [];
    while ($cursor !== false && $limit !== false && $cursor <= $limit) {
        $out[] = date('Y-m', $cursor);
        $cursor = strtotime('+1 month', $cursor);
    }
    return $out;
}

/**
 * @param list<string> $competencias
 * @return array<string,mixed>
 */
function monthlyMartSignals(PDO $pdo, array $competencias): array
{
    if ($competencias === []) {
        return ['has_monthly_rows' => false, 'tables' => []];
    }
    $tables = ['mart_cliente_periodo', 'mart_produto_periodo', 'mart_compradores_por_geo', 'mart_vendas_por_cnae_cnpj'];
    $placeholders = implode(',', array_map(static fn (int $i): string => ':c' . $i, array_keys($competencias)));
    $params = [];
    foreach ($competencias as $i => $competencia) {
        $params['c' . $i] = $competencia;
    }
    $out = [];
    foreach ($tables as $table) {
        if (!tableExists($pdo, $table)) {
            $out[$table] = ['exists' => false, 'rows' => 0];
            continue;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) AS rows_count, MAX(refreshed_at) AS refreshed_at FROM {$table} WHERE competencia IN ({$placeholders})");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out[$table] = ['exists' => true, 'rows' => (int) ($row['rows_count'] ?? 0), 'refreshed_at' => $row['refreshed_at'] ?? null];
    }
    return [
        'has_monthly_rows' => array_sum(array_map(static fn (array $row): int => (int) ($row['rows'] ?? 0), $out)) > 0,
        'tables' => $out,
    ];
}

/**
 * @return array<string,bool>
 */
function overviewCodeGuard(): array
{
    $path = Bootstrap::rootPath('app/Modules/Sales/Application/Overview/CanonicalOverviewPayloadBuilder.php');
    $source = is_file($path) ? (string) file_get_contents($path) : '';
    return [
        'partial_month_guard' => str_contains($source, 'isFullCalendarMonth')
            && str_contains($source, '$canUseMonthlyMarts')
            && str_contains($source, '$canonicalAuthorizedNfsCount > 0'),
    ];
}

function isFullCalendarMonth(string $start, string $end): bool
{
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($startTs === false || $endTs === false) {
        return false;
    }
    return date('Y-m-01', $startTs) === $start
        && date('Y-m-t', $startTs) === $end
        && date('Y-m', $startTs) === date('Y-m', $endTs);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}
