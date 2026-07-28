<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\FinanceVisualAnalyticsBuilder;
use BIomie\Support\TenantContext;

Bootstrap::init();

$opts = parseArgs($argv);
$json = isset($opts['json']);
$pretty = isset($opts['pretty']) || !$json;
$start = (string) ($opts['start'] ?? date('Y-m-01'));
$end = (string) ($opts['end'] ?? date('Y-m-t'));

try {
    validateDate($start, 'start');
    validateDate($end, 'end');
    if ($start > $end) {
        throw new InvalidArgumentException('start deve ser menor ou igual a end.');
    }

    $config = new Config();
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $conn);
    bootAuditTenant($pdo, $opts['company'] ?? null);

    $builder = new FinanceVisualAnalyticsBuilder($pdo);
    $payload = $builder->build($start, $end, false);
    $summary = $payload['summary'];
    $governance = $payload['governance'];

    $report = [
        'ok' => true,
        'status' => 'OK',
        'period' => $payload['period'],
        'kpis' => [
            'a_receber' => $summary['receivable_total'] ?? 0,
            'vencido' => $summary['overdue_total'] ?? 0,
            'a_vencer' => $summary['due_total'] ?? 0,
            'recebido_periodo' => $summary['received_total'] ?? 0,
            'clientes_em_atraso' => $summary['overdue_clients'] ?? 0,
            'maior_atraso' => $summary['max_delay_days'] ?? 0,
            'titulos_abertos' => $summary['open_titles'] ?? 0,
            'titulos_vencidos' => $summary['overdue_titles'] ?? 0,
            'sem_vendedor' => $summary['no_vendor_titles'] ?? 0,
            'valor_sem_vendedor' => $summary['no_vendor_value'] ?? 0,
            'promessas_vencidas' => $governance['promises_overdue'] ?? 0,
            'retornos_hoje' => $governance['returns_today'] ?? 0,
            'retornos_atrasados' => $governance['returns_overdue'] ?? 0,
            'sem_acao_vencidos' => $governance['overdue_without_action'] ?? 0,
            'percentual_acionada' => $governance['action_rate'] ?? 0,
        ],
        'sources' => [
            'overview' => 'api/finance_overview.php',
            'titles' => 'biomie_titulos_financeiros_canonicos',
            'actions' => 'biomie_admin_logs event_type=finance.collection_action',
            'collection' => 'api/finance_collection_by_vendor.php',
            'governance' => 'api/finance_collection_governance.php',
        ],
        'coverage' => [
            'composition_slices' => count($payload['composition'] ?? []),
            'evolution_points' => count($payload['evolution'] ?? []),
            'aging_buckets' => count($payload['aging'] ?? []),
            'vendors' => count($payload['vendors'] ?? []),
            'clients_risk' => count($payload['clients_risk'] ?? []),
            'action_mix' => count($payload['action_mix'] ?? []),
        ],
        'recommended_visuals' => [
            'composition' => 'doughnut Recebido/Vencido/A vencer',
            'evolution' => 'grafico misto barras + linha',
            'aging' => 'barras horizontais por faixa',
            'vendor_collection' => 'ranking horizontal por valor vencido',
            'clients_risk' => 'pareto de clientes vencidos',
            'governance' => 'doughnut/barras de status operacional',
        ],
        'diagnostics' => $payload['diagnostics'],
    ];
} catch (Throwable $exception) {
    $report = [
        'ok' => false,
        'status' => 'CRITICAL',
        'period' => ['start' => $start, 'end' => $end],
        'error' => $exception->getMessage(),
    ];
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($report['ok'] ?? false) ? 0 : 1);
}

printPretty($report);
exit(($report['ok'] ?? false) ? 0 : 1);

/**
 * @return array<string,bool|string>
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

function validateDate(string $value, string $label): void
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($label . ' deve estar em YYYY-MM-DD.');
    }
}

/**
 * @return array{id:int,slug:string,canal:string}
 */
function bootAuditTenant(PDO $pdo, mixed $selector): array
{
    $selector = strtoupper(trim((string) $selector));
    if ($selector !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, slug, canal_padrao
               FROM biomie_companies
              WHERE ativo = 1
                AND (id = :id OR slug = :slug)
              LIMIT 1'
        );
        $stmt->execute([
            'id' => ctype_digit($selector) ? (int) $selector : 0,
            'slug' => $selector,
        ]);
    } else {
        $stmt = $pdo->query(
            'SELECT id, slug, canal_padrao
               FROM biomie_companies
              WHERE ativo = 1
              ORDER BY id
              LIMIT 1'
        );
    }

    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($company)) {
        throw new RuntimeException('Nenhuma empresa ativa encontrada para inicializar o TenantContext.');
    }

    $canal = (string) ($company['canal_padrao'] ?: 'varejo');
    TenantContext::bootForCompany($pdo, (int) $company['id'], $canal);

    return [
        'id' => (int) $company['id'],
        'slug' => (string) $company['slug'],
        'canal' => $canal,
    ];
}

/**
 * @param array<string,mixed> $report
 */
function printPretty(array $report): void
{
    echo PHP_EOL . 'Finance Visual Analytics Audit' . PHP_EOL;
    echo str_repeat('-', 78) . PHP_EOL;
    echo 'Status: ' . ($report['status'] ?? 'UNKNOWN') . PHP_EOL;
    echo 'Periodo: ' . ($report['period']['start'] ?? '-') . ' .. ' . ($report['period']['end'] ?? '-') . PHP_EOL;

    if (!($report['ok'] ?? false)) {
        echo 'Erro: ' . ($report['error'] ?? 'desconhecido') . PHP_EOL;
        return;
    }

    echo PHP_EOL . 'KPIs' . PHP_EOL;
    foreach (($report['kpis'] ?? []) as $key => $value) {
        echo '  - ' . $key . ': ' . (is_float($value) ? number_format($value, 2, ',', '.') : (string) $value) . PHP_EOL;
    }

    echo PHP_EOL . 'Cobertura visual' . PHP_EOL;
    foreach (($report['coverage'] ?? []) as $key => $value) {
        echo '  - ' . $key . ': ' . (string) $value . PHP_EOL;
    }

    echo PHP_EOL . 'Graficos recomendados' . PHP_EOL;
    foreach (($report['recommended_visuals'] ?? []) as $key => $value) {
        echo '  - ' . $key . ': ' . $value . PHP_EOL;
    }
}
