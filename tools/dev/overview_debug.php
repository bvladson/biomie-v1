<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\CommercialPlanning;
use BIomie\Config;
use BIomie\Database;
use BIomie\GoldMartRepository;
use BIomie\Logger;
use BIomie\Modules\Finance\Application\FinanceOverviewBuilder;
use BIomie\Modules\Finance\Infrastructure\FinanceReadRepository;
use BIomie\Modules\Sales\Application\Overview\AttentionSignalsBuilder;
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

$options = getopt('', ['start:', 'end:', 'json', 'pretty', 'explain', 'section:']);
$start   = (string) ($options['start'] ?? date('Y-m-01'));
$end     = (string) ($options['end']   ?? date('Y-m-t'));
$isJson  = isset($options['json']) || isset($options['pretty']);
$explain = isset($options['explain']);
$section = isset($options['section']) ? (string) $options['section'] : null;

$requestId = RequestContext::id();

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

$canonical = new CanonicalRepository($pdo, $logger);
$gold      = new GoldMartRepository($pdo, $logger);

$result = [
    'request_id'  => $requestId,
    'source'      => 'overview_debug',
    'period'      => ['start' => $start, 'end' => $end],
];

// ── Seção 1: Canonical ────────────────────────────────────────────────────────

if ($section === null || $section === 'canonical') {
    $sec = ['source' => 'biomie_notas_fiscais_canonicas'];
    $available = $canonical->isAvailable();
    $sec['available'] = $available;

    if ($available) {
        $summary = $canonical->summarizeInvoicesCommercial($start, $end);
        $sec['authorized_nfs_count'] = (int) ($summary['billed_orders'] ?? 0);
        $sec['billed_value']         = (float) ($summary['billed_value'] ?? 0);
        $sec['active_clients']       = (int) ($summary['active_clients'] ?? 0);
        $sec['merchandise_total']    = (float) ($summary['merchandise_total'] ?? 0);

        $lastSync = $canonical->lastSyncAt($start, $end);
        $ageSeconds = $lastSync ? (time() - (int) strtotime($lastSync)) : null;
        $slaStatus = match (true) {
            $ageSeconds === null     => 'unknown',
            $ageSeconds <= 86400    => 'ok',
            $ageSeconds <= 259200   => 'warning',
            default                 => 'critical',
        };
        $sec['freshness'] = [
            'last_sync_at' => $lastSync,
            'age_hours'    => $ageSeconds !== null ? round($ageSeconds / 3600, 1) : null,
            'sla_status'   => $slaStatus,
        ];

        $coverage = $canonical->commercialCoverageDiagnostics($start, $end);
        $sec['coverage_end']   = $coverage['coverage_end'] ?? $coverage['invoice_max_date'] ?? null;
        $sec['has_any_rows']   = (bool) ($coverage['has_any_rows'] ?? false);
        $sec['coverage_pct']   = $coverage['coverage_pct'] ?? null;

        $sec['reason_code'] = $sec['authorized_nfs_count'] === 0 ? 'no_authorized_nfs' : null;
    } else {
        $sec['reason_code'] = 'canonical_unavailable';
        $sec['freshness'] = ['sla_status' => 'unknown'];
    }

    $result['canonical'] = $sec;
}

// ── Seção 2: Gold mart ────────────────────────────────────────────────────────

if ($section === null || $section === 'gold') {
    $goldClients = $gold->topClientsCommercial($start, $end, 10);
    $martCount   = count($goldClients);
    $available   = $martCount > 0;

    $topClientsSource = 'none';
    if ($available) {
        $topClientsSource = 'mart_cliente_periodo';
    } elseif (isset($canonical) && $canonical->isAvailable()) {
        $fallback = $canonical->topClientsCommercial($start, $end, 10);
        $topClientsSource = $fallback !== [] ? 'biomie_notas_fiscais_canonicas' : 'none';
    }

    $result['gold'] = [
        'source'                      => 'mart_cliente_periodo',
        'mart_cliente_periodo_count'  => $martCount,
        'mart_available'              => $available,
        'top_clients_source'          => $topClientsSource,
        'fallback_used'               => $topClientsSource === 'biomie_notas_fiscais_canonicas',
        'reason_code'                 => !$available ? 'mart_not_materialized' : null,
        'sample'                      => array_slice(array_map(static fn (array $r): array => [
            'client_name'   => (string) ($r['client_name'] ?? 'N/A'),
            'invoice_total' => round((float) ($r['invoice_total'] ?? 0), 2),
        ], $goldClients), 0, 3),
    ];
}

// ── Seção 3: Pontos de Atenção / Alerts ───────────────────────────────────────

if ($section === null || $section === 'alerts') {
    $alertsSec = [
        'source'           => 'biomie_divergencias + canonical_summary',
        'expected_format'  => 'array of {level:string, title:string, message:string}',
        'payload_key'      => 'alerts',
    ];

    $canonicalAvailable = isset($canonical) && $canonical->isAvailable();
    $syntheticAlerts = [];

    if ($canonicalAvailable) {
        $summary = $canonical->summarizeInvoicesCommercial($start, $end);
        $grossRevenue = (float) ($summary['billed_value'] ?? 0);
        $clients = $canonical->topClientsCommercial($start, $end, 5);
        if ($clients !== [] && $grossRevenue > 0) {
            $top1 = (float) (($clients[0] ?? [])['invoice_total'] ?? 0);
            $topShare = round($top1 / $grossRevenue * 100, 2);
            if ($topShare >= 35) {
                $syntheticAlerts[] = [
                    'level'   => 'warning',
                    'title'   => 'Concentracao elevada em cliente',
                    'message' => 'Cliente principal representa ' . $topShare . '% do faturamento.',
                ];
            }
        }

        $divergences = $canonical->openDivergences(5);
        foreach ($divergences as $div) {
            $syntheticAlerts[] = [
                'level'   => 'warning',
                'title'   => (string) ($div['tipo'] ?? 'Divergencia'),
                'message' => (string) ($div['descricao'] ?? 'Divergencia encontrada.'),
            ];
        }
    }

    $alertsSec['simulated_alerts_count'] = count($syntheticAlerts);
    $alertsSec['simulated_sample']       = array_slice($syntheticAlerts, 0, 3);
    $alertsSec['format_valid']           = true;
    foreach ($syntheticAlerts as $a) {
        if (!isset($a['level'], $a['title'], $a['message'])) {
            $alertsSec['format_valid'] = false;
            $alertsSec['reason_code']  = 'alert_missing_required_fields';
            break;
        }
    }
    if ($alertsSec['format_valid']) {
        $alertsSec['reason_code'] = null;
    }

    // Diagnose: does the payload alias 'alerts' or uses different key?
    $alertsSec['key_check'] = [
        'canonical_key'  => 'alerts',
        'accepted_alias' => 'none — only "alerts" is canonical',
        'note'           => 'Legacy snapshot may provide text strings instead of structured objects; canonical builder always returns objects.',
    ];

    try {
        $divCountStmt = $pdo->query(
            "SELECT COUNT(*) FROM biomie_divergencias WHERE status = 'open' OR status IS NULL"
        );
        $alertsSec['open_divergences_count'] = (int) $divCountStmt->fetchColumn();
    } catch (Throwable) {
        $alertsSec['open_divergences_count'] = null;
        $alertsSec['reason_code'] = 'biomie_divergencias_query_failed';
    }

    $result['alerts'] = $alertsSec;
}

// ── Seção 4: Finance / Recebimentos ──────────────────────────────────────────

if ($section === null || $section === 'finance') {
    $finSec = [
        'source'      => 'biomie_titulos_financeiros_canonicos',
        'payload_key' => 'finance_summary / financeSummary',
    ];

    try {
        $financeRepo = new FinanceReadRepository(static fn (): PDO => $pdo);
        $finBuilder  = new FinanceOverviewBuilder($financeRepo);

        $tableInfo            = $financeRepo->tableInfo();
        $finSec['table_exists']   = $tableInfo['exists'];
        $finSec['total_rows']     = $tableInfo['total_rows'] ?? 0;
        $finSec['fields_detected']= $financeRepo->detectFinanceFields();

        if ($tableInfo['exists'] && $tableInfo['total_rows'] > 0) {
            $fSummary = $finBuilder->build($start, $end, null, 0.0, $requestId, $logger);
            $finSec['available']      = $fSummary['available'] ?? false;
            $finSec['totals']         = $fSummary['totals'] ?? [];
            $finSec['counts']         = $fSummary['counts'] ?? [];
            $finSec['risk']           = $fSummary['risk'] ?? [];
            $finSec['diagnostics']    = $fSummary['diagnostics'] ?? [];
            $finSec['reason_code']    = $fSummary['reason_code'] ?? null;
        } else {
            $finSec['available']   = false;
            $finSec['reason_code'] = $tableInfo['exists'] ? 'table_empty' : 'table_not_found';
        }
    } catch (Throwable $e) {
        $finSec['available']   = false;
        $finSec['reason_code'] = 'builder_failed: ' . $e->getMessage();
    }

    $result['finance'] = $finSec;
}

// ── Seção 5: Comparativo Mensal ───────────────────────────────────────────────

if ($section === null || $section === 'month_comparison') {
    $mcSec = [
        'source'          => 'OverviewMonthComparisonBuilder (biomie_notas_fiscais_canonicas + biomie_sales_targets_monthly)',
        'payload_key'     => 'month_comparison',
        'accepted_aliases'=> ['monthly_comparison', 'monthlyComparison'],
    ];

    try {
        $targetsRepoMc = null;
        try {
            $targetsRepoMc = new TargetsRepository($pdo, $logger);
            $mcSec['targets_table_available'] = $targetsRepoMc->isAvailable();
        } catch (Throwable) {
            $mcSec['targets_table_available'] = false;
        }

        $satWeightMc = (float) $config->get('flight_plan.saturday_weight', 0.33);
        $mcBuilder   = new OverviewMonthComparisonBuilder();
        $mcResult    = $mcBuilder->build($pdo, $logger, $canonical, $targetsRepoMc, $start, $satWeightMc);

        $mcSec['available']     = $mcResult['available'] ?? false;
        $mcSec['mode']          = $mcResult['mode'] ?? '-';
        $mcSec['period']        = $mcResult['period'] ?? [];
        $mcSec['rows_count']    = count($mcResult['rows'] ?? []);
        $mcSec['insights_count']= count($mcResult['insights'] ?? []);
        $mcSec['reason_code']   = $mcResult['reason_code'] ?? null;
        $mcSec['deltas']        = $mcResult['deltas'] ?? [];
        $mcSec['rows']          = $mcResult['rows'] ?? [];
        $mcSec['insights']      = $mcResult['insights'] ?? [];
        $mcSec['current']       = $mcResult['current'] ?? null;
        $mcSec['previous']      = $mcResult['previous'] ?? null;
        $mcSec['projection']    = $mcResult['projection'] ?? null;
        $mcSec['last_3_months_avg']  = $mcResult['last_3_months_avg'] ?? null;
        $mcSec['best_recent_month']  = $mcResult['best_recent_month'] ?? null;
        $mcSec['same_month_last_year'] = $mcResult['same_month_last_year'] ?? null;

        $mcSec['is_empty'] = $mcSec['rows_count'] === 0;
        if ($mcSec['is_empty'] && $mcSec['reason_code'] === null) {
            $mcSec['reason_code'] = 'no_data_for_any_month';
        }
    } catch (Throwable $e) {
        $mcSec['available']   = false;
        $mcSec['reason_code'] = 'builder_failed: ' . $e->getMessage();
        $mcSec['is_empty']    = true;
        $mcSec['rows']        = [];
    }

    $result['month_comparison'] = $mcSec;
}

// ── Seção 6: Chart Tooltip ───────────────────────────────────────────────────

if ($section === null || $section === 'chart_tooltip') {
    $root = Bootstrap::rootPath('') . DIRECTORY_SEPARATOR;

    $chartDefaultsFile = $root . 'assets/js/mag-chart-defaults.js';
    $chartDefaultsCode = is_file($chartDefaultsFile) ? (string) file_get_contents($chartDefaultsFile) : '';
    $chartDefaultsExists = $chartDefaultsCode !== '';

    $tooltipBg = null;
    if ($chartDefaultsExists && preg_match('/tooltip\.backgroundColor\s*=\s*([\'"])(.*?)\1/', $chartDefaultsCode, $m)) {
        $tooltipBg = $m[2];
    }

    $registryFile = $root . 'app/PageAssetRegistry.php';
    $registryCode = is_file($registryFile) ? (string) file_get_contents($registryFile) : '';
    $registeredInBase = str_contains($registryCode, "'assets/js/mag-chart-defaults.js'");
    $registeredInBody = (bool) preg_match('/js_body.*?mag-chart-defaults\.js/s', $registryCode);

    $chartHelpersFile = $root . 'assets/js/chart-helpers.js';
    $chartHelpersCode = is_file($chartHelpersFile) ? (string) file_get_contents($chartHelpersFile) : '';
    $helpersHasTooltipOverride = str_contains($chartHelpersCode, 'tooltip') &&
        (str_contains($chartHelpersCode, 'backgroundColor') || str_contains($chartHelpersCode, 'tooltipPlugin'));

    $chartUmdFile = $root . 'assets/js/chart.umd.js';
    $chartUmdExists = is_file($chartUmdFile);

    $tooltipStatus = 'unknown';
    $tooltipReason = null;
    if (!$chartDefaultsExists) {
        $tooltipStatus = 'critical';
        $tooltipReason = 'mag-chart-defaults.js nao encontrado';
    } elseif ($tooltipBg === null) {
        $tooltipStatus = 'warning';
        $tooltipReason = 'tooltip.backgroundColor nao definido em mag-chart-defaults.js';
    } elseif (in_array($tooltipBg, ['#000', '#000000', 'black'], true)) {
        $tooltipStatus = 'warning';
        $tooltipReason = 'tooltip.backgroundColor definido como preto — deve ser cor personalizada';
    } else {
        $tooltipStatus = 'ok';
    }

    $result['chart_tooltip'] = [
        'source'                       => 'assets/js/mag-chart-defaults.js + PageAssetRegistry',
        'mag_chart_defaults_exists'    => $chartDefaultsExists,
        'tooltip_background_color'     => $tooltipBg,
        'tooltip_status'               => $tooltipStatus,
        'registered_in_page_asset_registry' => $registeredInBase || $registeredInBody,
        'registered_in_js_body'        => $registeredInBody,
        'chart_umd_exists'             => $chartUmdExists,
        'chart_helpers_has_tooltip'    => $helpersHasTooltipOverride,
        'reason_code'                  => $tooltipReason,
        'note'                         => 'mag-chart-defaults.js deve carregar DEPOIS de chart.umd.js e ANTES dos scripts de pagina para que defaults sejam aplicados.',
        'load_order_check'             => [
            'chart_umd'          => $chartUmdExists ? 'present' : 'missing',
            'mag_chart_defaults' => $chartDefaultsExists ? 'present' : 'missing',
            'registered_in_registry' => ($registeredInBase || $registeredInBody) ? 'yes' : 'no — risco de nao carregar em todas as paginas',
        ],
    ];
}

// ── Seção 7: Metas ativas (Plano de Voo) ─────────────────────────────────────

if ($section === null || $section === 'targets') {
    $targetsSec = [
        'source'     => 'biomie_sales_targets_monthly',
        'payload_key'=> 'targets (via meta.targets em flight_plan)',
    ];

    try {
        $targetsRepo2 = new TargetsRepository($pdo, $logger);
        $targetsSec['table_available'] = $targetsRepo2->isAvailable();

        if ($targetsRepo2->isAvailable()) {
            $anchor2 = DateTimeImmutable::createFromFormat('!Y-m-d', $start) ?: new DateTimeImmutable('today');
            $months2 = [];
            for ($i = 0; $i < 2; $i++) {
                $months2[] = $anchor2->modify("+{$i} months")->format('Y-m');
            }
            $targetsMap2 = $targetsRepo2->getTargetsForMonths($months2);
            $targetsSec['months_checked'] = $months2;
            $targetsSec['total_target_rows'] = count($targetsMap2);
            $targetsSec['vendors_with_targets'] = count(array_unique(array_column(array_values($targetsMap2), 'vendor_id')));
            $targetsSec['last_updated_at'] = $targetsRepo2->getLastUpdatedAt();
            $targetsSec['reason_code'] = count($targetsMap2) === 0 ? 'no_targets_in_period' : null;

            $targetsSec['sample'] = array_slice(array_map(static fn (array $r): array => [
                'vendor_id'     => $r['vendor_id'],
                'vendor_name'   => $r['vendor_name'],
                'year_month'    => $r['year_month'],
                'target_amount' => $r['target_amount'],
            ], array_values($targetsMap2)), 0, 5);
        } else {
            $targetsSec['reason_code'] = 'table_not_found';
        }
    } catch (Throwable $e) {
        $targetsSec['table_available'] = false;
        $targetsSec['reason_code']     = 'query_failed: ' . $e->getMessage();
    }

    $result['targets'] = $targetsSec;
}

// ── Seção 8: Feature flags ────────────────────────────────────────────────────

if ($section === null || $section === 'features') {
    $result['features'] = [
        'source'                   => 'config',
        'canonical_fiscal_ready'   => (bool) $config->get('features.canonical_fiscal_ready', false),
        'canonical_overview'       => $config->isCanonicalEnabled('overview'),
        'flight_plan_db_configured'=> Database::isConfigured($config, 'flight_plan_db'),
        'connection_used'          => $connection,
        'reason_code'              => null,
    ];
}

// ── Seção 9: Attention Signals ────────────────────────────────────────────────

if ($section === null || $section === 'attention') {
    $attSec = ['source' => 'AttentionSignalsBuilder', 'payload_key' => 'attention_signals / attentionSignals'];

    try {
        $canonicalAvailable = isset($canonical) && $canonical->isAvailable();

        if ($canonicalAvailable) {
            $summary        = $canonical->summarizeInvoicesCommercial($start, $end);
            $planningRows   = $canonical->aggregateByVendorCommercial($start, $end);
            $pendingSummary = $canonical->summarizePendingCommercial($start, $end);
            $grossRevenue   = (float) ($summary['billed_value'] ?? 0);

            $targetsRepoAttn = new TargetsRepository($pdo, $logger);
            $targetsMap = $targetsRepoAttn->isAvailable()
                ? $targetsRepoAttn->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
                : [];
            $planning = CommercialPlanning::build($planningRows, $targetsMap, $start, $end, 0.6);

            $goldAttn     = new GoldMartRepository($pdo, $logger);
            $goldClients  = $goldAttn->topClientsCommercial($start, $end, 8);
            $vendorRanking = [];
            foreach ($canonical->aggregateInvoicesByVendorCommercial($start, $end) as $row) {
                $vendorRanking[] = [
                    'vendor_id'   => (string) ($row['codigo_vendedor_omie'] ?? ''),
                    'vendor_name' => (string) ($row['nome_vendedor'] ?? 'Sem vendedor'),
                    'revenue'     => (float) ($row['total'] ?? 0),
                    'orders'      => (int) ($row['pedidos'] ?? 0),
                ];
            }
            usort($vendorRanking, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

            $builder = new AttentionSignalsBuilder();
            $built   = $builder->build($pdo, $logger, $start, $end, $requestId, [
                'vendor_ranking'     => $vendorRanking,
                'planning_rows'      => $planningRows,
                'gross_revenue'      => $grossRevenue,
                'pending_summary'    => $pendingSummary,
                'planning'           => $planning,
                'top_clients_source' => $goldClients !== [] ? 'mart_cliente_periodo' : 'canonical',
            ]);

            $attSec['signals_total']      = $built['attention_summary']['total'] ?? 0;
            $attSec['signals_visible']    = count($built['attention_signals'] ?? []);
            $attSec['hidden_count']       = $built['attention_summary']['hidden_count'] ?? 0;
            $attSec['highest_severity']   = $built['attention_summary']['highest_severity'] ?? 'none';
            $attSec['attention_summary']  = $built['attention_summary'] ?? [];
            $attSec['attention_diagnostics'] = $built['attention_diagnostics'] ?? [];
            $attSec['sample_signals']     = array_slice((array) ($built['attention_signals'] ?? []), 0, 2);
            $attSec['reason_code']        = ($attSec['signals_total'] === 0) ? 'no_signals' : null;
        } else {
            $attSec['reason_code'] = 'canonical_unavailable';
        }
    } catch (Throwable $e) {
        $attSec['reason_code'] = 'builder_failed: ' . $e->getMessage();
        $attSec['error_class'] = get_class($e);
    }

    $result['attention'] = $attSec;
}

// ── Seção 10: Explain ─────────────────────────────────────────────────────────

if ($explain) {
    $result['explain'] = [
        'data_flow' => [
            '1_canonical_nfs'  => 'biomie_notas_fiscais_canonicas → CanonicalRepository → OverviewPayloadBuilder',
            '2_gold_mart'      => 'mart_cliente_periodo → GoldMartRepository → top_clients (primary)',
            '3_fallback'       => 'biomie_notas_fiscais_canonicas → top_clients (fallback)',
            '4_month_compare'  => 'biomie_notas_fiscais_canonicas GROUP BY mes → OverviewMonthComparisonBuilder → month_comparison (rich object + rows)',
            '5_finance'        => 'biomie_titulos_financeiros_canonicos → FinanceOverviewBuilder → finance_summary / financeSummary',
            '6_alerts'         => 'canonical_summary + biomie_divergencias → alerts array em overview payload',
            '7_chart_tooltip'  => 'Chart.defaults modificado por mag-chart-defaults.js no load',
        ],
        'key_aliases' => [
            'month_comparison' => 'accepted: month_comparison, monthly_comparison, monthlyComparison',
            'top_clients'      => 'accepted: top_clients, top_customers, topCustomers',
            'alerts'           => 'nenhum alias — apenas "alerts"',
        ],
        'feature_flags' => [
            'canonical_fiscal_ready' => 'deve ser true para usar canonical overview',
            'canonical_overview'     => 'deve ser true para ativar canonical path',
        ],
        'recommended_indexes' => [
            'idx_nf_status_comp' => 'CREATE INDEX ON biomie_notas_fiscais_canonicas (status_fiscal, data_competencia)',
            'idx_titulos_comp'   => 'CREATE INDEX ON biomie_titulos_financeiros_canonicos (data_competencia, data_vencimento)',
        ],
    ];
}

// ── Output ─────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// Text output
$line = static fn (string $label, mixed $value, string $indent = ''): string =>
    sprintf("%s%-44s %s\n", $indent, $label . ':', is_bool($value) ? ($value ? 'sim' : 'nao') : (string) ($value ?? '-'));

echo "\n=== Overview Dashboard Debug ===\n";
echo "Request ID : {$requestId}\n";
echo "Periodo    : {$start} ate {$end}\n";
if ($section !== null) {
    echo "Secao      : {$section}\n";
}
echo "\n";

if (isset($result['features'])) {
    echo "--- Feature Flags ---\n";
    echo $line('canonical_fiscal_ready', $result['features']['canonical_fiscal_ready']);
    echo $line('canonical_overview enabled', $result['features']['canonical_overview']);
    echo $line('flight_plan_db configured', $result['features']['flight_plan_db_configured']);
    echo $line('connection used', $result['features']['connection_used']);
    echo "\n";
}

if (isset($result['canonical'])) {
    echo "--- Canonical (biomie_notas_fiscais_canonicas) ---\n";
    $c = $result['canonical'];
    echo $line('Disponivel', $c['available'] ?? false);
    echo $line('NFs autorizadas', $c['authorized_nfs_count'] ?? 0);
    echo $line('Valor faturado', 'R$ ' . number_format((float) ($c['billed_value'] ?? 0), 2, ',', '.'));
    echo $line('Clientes ativos', $c['active_clients'] ?? 0);
    echo $line('Cobertura ate', $c['coverage_end'] ?? '-');
    echo $line('SLA status', strtoupper((string) ($c['freshness']['sla_status'] ?? 'unknown')));
    echo $line('Ultima sincronizacao', $c['freshness']['last_sync_at'] ?? '-');
    if (($c['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $c['reason_code']);
    }
    echo "\n";
}

if (isset($result['gold'])) {
    echo "--- Gold Mart ---\n";
    $g = $result['gold'];
    echo $line('mart_cliente_periodo linhas', $g['mart_cliente_periodo_count'] ?? 0);
    echo $line('top_clients fonte', $g['top_clients_source'] ?? '-');
    echo $line('Fallback usado', $g['fallback_used'] ?? false);
    if (($g['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $g['reason_code']);
    }
    echo "\n";
}

if (isset($result['alerts'])) {
    echo "--- Pontos de Atencao (Alerts) ---\n";
    $a = $result['alerts'];
    echo $line('Formato esperado', $a['expected_format']);
    echo $line('Chave no payload', $a['payload_key']);
    echo $line('Formato valido (simulado)', $a['format_valid'] ?? true);
    echo $line('Alertas simulados', $a['simulated_alerts_count'] ?? 0);
    echo $line('Divergencias abertas', $a['open_divergences_count'] ?? '-');
    if (($a['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $a['reason_code']);
    }
    echo $line('Nota (key_check)', $a['key_check']['note'] ?? '-');
    echo "\n";
}

if (isset($result['finance'])) {
    echo "--- Finance / Recebimentos (FinanceOverviewBuilder) ---\n";
    $f = $result['finance'];
    echo $line('Tabela existe', $f['table_exists'] ?? false);
    echo $line('Total de linhas', $f['total_rows'] ?? 0);
    echo $line('Disponivel', $f['available'] ?? false);
    if (!empty($f['totals'])) {
        $t = $f['totals'];
        echo $line('Recebido', 'R$ ' . number_format((float) ($t['received'] ?? 0), 2, ',', '.'));
        echo $line('Em aberto', 'R$ ' . number_format((float) ($t['open'] ?? 0), 2, ',', '.'));
        echo $line('Vencido', 'R$ ' . number_format((float) ($t['overdue'] ?? 0), 2, ',', '.'));
        echo $line('Conv. caixa', ($t['cash_conversion_pct'] !== null ? $t['cash_conversion_pct'] . '%' : 'N/A'));
    }
    if (!empty($f['risk'])) {
        echo $line('Risco', strtoupper($f['risk']['level'] ?? '-') . ' (score ' . ($f['risk']['score'] ?? 0) . ')');
    }
    if (!empty($f['diagnostics'])) {
        echo $line('Runtime ms', $f['diagnostics']['runtime_ms'] ?? '-');
    }
    if (($f['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $f['reason_code']);
    }
    echo "\n";
}

if (isset($result['month_comparison'])) {
    echo "--- Comparativo Mensal (OverviewMonthComparisonBuilder) ---\n";
    $mc = $result['month_comparison'];
    echo $line('Disponivel', $mc['available'] ?? false);
    echo $line('Modo', $mc['mode'] ?? '-');
    echo $line('Targets table', $mc['targets_table_available'] ?? '-');
    echo $line('Linhas (rows)', $mc['rows_count'] ?? 0);
    echo $line('Insights', $mc['insights_count'] ?? 0);
    echo $line('Esta vazio', $mc['is_empty'] ?? true);
    if (!empty($mc['period'])) {
        $p = $mc['period'];
        echo $line('Periodo', ($p['year_month'] ?? '-') . sprintf(' (%d/%d dias)', $p['elapsed_days'] ?? 0, $p['calendar_days'] ?? 0));
    }
    if (!empty($mc['deltas'])) {
        $d = $mc['deltas'];
        $fmtPctLine = static fn (?float $v): string => $v !== null ? sprintf('%+.1f%%', $v) : 'N/A';
        echo $line('vs mes anterior', $fmtPctLine($d['vs_previous_pct'] ?? null));
        echo $line('vs media 3m', $fmtPctLine($d['vs_3m_avg_pct'] ?? null));
        echo $line('vs meta', $fmtPctLine($d['vs_target_pct'] ?? null));
    }
    if (($mc['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $mc['reason_code']);
    }
    if (!empty($mc['rows'])) {
        echo "  Meses (chart rows):\n";
        foreach ($mc['rows'] as $row) {
            echo sprintf("  %-8s  rev=R$%-14s NFs=%-5s meta=R$%-12s %s\n",
                $row['year_month'] ?? '?',
                number_format((float) ($row['revenue'] ?? 0), 2, ',', '.'),
                $row['invoices_count'] ?? 0,
                number_format((float) ($row['target'] ?? 0), 2, ',', '.'),
                ($row['is_current'] ?? false) ? '<< atual' : ''
            );
        }
    }
    echo "\n";
}

if (isset($result['chart_tooltip'])) {
    echo "--- Chart Tooltip ---\n";
    $ct = $result['chart_tooltip'];
    echo $line('mag-chart-defaults.js existe', $ct['mag_chart_defaults_exists'] ?? false);
    echo $line('tooltip.backgroundColor', $ct['tooltip_background_color'] ?? '-');
    echo $line('Status', strtoupper((string) ($ct['tooltip_status'] ?? 'unknown')));
    echo $line('Registrado em PageAssetRegistry', $ct['registered_in_page_asset_registry'] ?? false);
    echo $line('Registrado em js_body', $ct['registered_in_js_body'] ?? false);
    echo $line('chart.umd.js existe', $ct['chart_umd_exists'] ?? false);
    if (($ct['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $ct['reason_code']);
    }
    echo $line('Nota', $ct['note'] ?? '-');
    echo "\n";
}

if (isset($result['targets'])) {
    echo "--- Metas (Targets) ---\n";
    $t = $result['targets'];
    echo $line('Tabela disponivel', $t['table_available'] ?? false);
    echo $line('Linhas de metas', $t['total_target_rows'] ?? 0);
    echo $line('Vendedores com meta', $t['vendors_with_targets'] ?? 0);
    echo $line('Ultima atualizacao', $t['last_updated_at'] ?? '-');
    if (($t['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $t['reason_code']);
    }
    if (!empty($t['sample'])) {
        echo "  Amostra:\n";
        foreach ($t['sample'] as $s) {
            echo sprintf("  %-30s %s R$%s\n",
                $s['vendor_name'] ?? $s['vendor_id'],
                $s['year_month'],
                number_format((float) ($s['target_amount'] ?? 0), 2, ',', '.'));
        }
    }
    echo "\n";
}

if (isset($result['attention'])) {
    echo "--- Attention Signals ---\n";
    $att = $result['attention'];
    echo $line('Total de sinais', $att['signals_total'] ?? 0);
    echo $line('Visiveis (max 6)', $att['signals_visible'] ?? 0);
    echo $line('Ocultos', $att['hidden_count'] ?? 0);
    echo $line('Severidade mais alta', $att['highest_severity'] ?? 'none');
    if (($att['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $att['reason_code']);
    }
    if (!empty($att['attention_diagnostics'])) {
        $d = $att['attention_diagnostics'];
        echo $line('Regras avaliadas', $d['rules_evaluated'] ?? 0);
        echo $line('Regras falharam', $d['rules_failed'] ?? 0);
        echo $line('Regras ignoradas', $d['rules_skipped'] ?? 0);
        echo $line('Queries extras', $d['query_count'] ?? 0);
        echo $line('Runtime ms', $d['runtime_ms'] ?? '-');
    }
    if (!empty($att['sample_signals'])) {
        echo "  Amostra:\n";
        foreach ($att['sample_signals'] as $s) {
            echo sprintf("  [%-7s] %s — %s\n",
                $s['severity'] ?? '?',
                $s['title'] ?? '?',
                $s['value'] ?? '-');
        }
    }
    echo "\n";
}

echo "Uso: php tools/dev/overview_debug.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]\n";
echo "     [--json] [--explain]\n";
echo "     [--section=canonical|gold|alerts|attention|finance|month_comparison|chart_tooltip|targets|features]\n\n";
echo "Ferramentas especializadas:\n";
echo "  php tools/dev/finance_overview_debug.php  [--start] [--end] [--json] [--pretty] [--sample=N]\n";
echo "  php tools/dev/month_comparison_debug.php  [--start] [--end] [--json] [--pretty]\n\n";
