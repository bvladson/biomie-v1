<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts = parseArgs($argv);
$json = isset($opts['json']);
$root = dirname(__DIR__, 2);

$checks = buildChecks($root);
$failures = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'FAIL'));
$warnings = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'WARN'));

$report = [
    'ok' => $failures === [],
    'status' => $failures !== [] ? 'CRITICAL' : ($warnings !== [] ? 'WARNING' : 'OK'),
    'generated_at' => date(DATE_ATOM),
    'checks' => $checks,
    'summary' => [
        'pass' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'PASS')),
        'warn' => count($warnings),
        'fail' => count($failures),
    ],
];

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['ok'] ? 0 : 1);
}

printPretty($report);
exit($report['ok'] ? 0 : 1);

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

/**
 * @return list<array{id:string,status:string,message:string}>
 */
function buildChecks(string $root): array
{
    $mapHtml = readFileSafe($root . '/views/mapa_vendas.html');
    $mapJs = readFileSafe($root . '/assets/js/biomie-map.js');
    $mapCss = readFileSafe($root . '/assets/css/biomie-map.css');
    $productsHtml = readFileSafe($root . '/views/mercadorias.html');
    $productsCss = readFileSafe($root . '/assets/css/mercadorias.css');
    $chartFactory = readFileSafe($root . '/assets/js/core/chart-factory.js');
    $appJs = readFileSafe($root . '/assets/js/app.js');
    $financeHtml = readFileSafe($root . '/views/financeiro.html');
    $financeJs = readFileSafe($root . '/assets/js/modules/finance/visual-analytics.js');
    $financeCss = readFileSafe($root . '/assets/css/finance-realizado.css');

    return [
        checkContains('vendor_chart_axis_y', $chartFactory, "axis: 'y'", 'Ranking de vendedores usa eixo y para hover/tooltip.'),
        checkContains('vendor_chart_dynamic_height', $chartFactory, 'computedHeight', 'Ranking de vendedores calcula altura por quantidade de linhas.'),
        checkContains('geo_main_grid', $mapHtml, 'geo-main-grid', 'Mapa de vendas usa grid principal mapa + ranking.'),
        checkContains('geo_drawer', $mapHtml, 'geoNeighborhoodDrawer', 'Drawer de bairro existe na view.'),
        checkNotContains('geo_duplicate_detail_card', $mapHtml, 'Detalhamento comercial por bairro', 'Card duplicado de detalhamento foi removido.'),
        checkContains('geo_main_grid_controlled_height', $mapCss, 'height: clamp(', 'Grid mapa + ranking possui altura controlada no desktop.'),
        checkContains('geo_ranking_card_overflow_hidden', $mapCss, 'overflow: hidden', 'Card do ranking nao expande a pagina.'),
        checkContains('geo_ranking_internal_scroll', $mapCss, 'overflow-y: auto', 'Lista de bairros usa rolagem interna.'),
        checkContains('geo_ranking_list_markup', $mapHtml, 'geo-ranking-list', 'Ranking usa lista compacta com scroll interno.'),
        checkContains('geo_map_explicit_height', $mapCss, '#map-container', 'CSS do mapa define container explicito.'),
        checkContains('geo_map_min_height', $mapCss, 'min-height: 520px', 'Mapa possui altura minima desktop.'),
        checkContains('geo_drawer_css', $mapCss, '.geo-drawer', 'Drawer de bairro possui CSS dedicado.'),
        checkContains('geo_invalidate_size', $mapJs, 'invalidateSize', 'Mapa chama invalidateSize apos eventos de layout.'),
        checkContains('geo_ranking_all_rows', $mapJs, 'geoRankingSearch', 'Ranking de bairros tem busca/lista navegavel.'),
        checkContains('geo_drawer_escape', $mapJs, "event.key === 'Escape'", 'Drawer fecha por ESC.'),
        checkContains('product_scope_switcher', $productsHtml, 'productScopeSwitcher', 'Mercadorias possui seletor Orc-PD/Remessas/Todas.'),
        checkContains('product_doughnut_chart', $chartFactory, "type: 'doughnut'", 'Graficos de mercadorias usam doughnut Top 10 + Outros.'),
        checkContains('product_revenue_legend', $productsHtml, 'productsRevenueLegend', 'Grafico de receita possui legenda externa clicavel.'),
        checkContains('product_share_legend', $productsHtml, 'productsShareLegend', 'Grafico de participacao possui legenda externa clicavel.'),
        checkContains('product_drawer_summary', $productsHtml, 'productBreakdownSummary', 'Drawer de produto possui KPIs e listas de detalhe.'),
        checkContains('product_css_compact_table', $productsCss, '.product-breakdown-trigger strong', 'Tabela de produtos usa celula compacta/truncada.'),
        checkContains('product_balanced_chart_grid', $productsHtml, 'product-chart-grid', 'Doughnuts de mercadorias usam grid visual equilibrado.'),
        checkContains('product_donut_fixed_canvas', $productsCss, 'width: 240px', 'Canvas dos doughnuts possui largura controlada.'),
        checkContains('product_row_click_key', $appJs, 'data-product-key', 'Linhas de produto recebem data-product-key para abrir drawer.'),
        checkContains('product_row_delegation', $appJs, 'initProductBreakdownDelegation', 'Tabela de produtos possui clique delegado para drawer.'),
        checkContains('product_scope_css', $productsCss, '.product-scope-chip--active', 'Chips de escopo possuem estado ativo visual.'),
        checkContains('finance_visual_section', $financeHtml, 'financeVisualAnalytics', 'Financeiro possui secao visual FIN.R4.'),
        checkContains('finance_visual_drawer', $financeHtml, 'financeInsightDrawer', 'Financeiro possui drawer de insight.'),
        checkContains('finance_composition_chart', $financeHtml, 'financeVisualCompositionChart', 'Financeiro possui chart de composicao.'),
        checkContains('finance_aging_chart', $financeHtml, 'financeVisualAgingChart', 'Financeiro possui chart de aging.'),
        checkContains('finance_vendor_chart', $financeHtml, 'financeVisualVendorChart', 'Financeiro possui ranking visual de vendedor.'),
        checkContains('finance_client_risk_chart', $financeHtml, 'financeVisualClientRiskChart', 'Financeiro possui pareto de clientes de risco.'),
        checkContains('finance_governance_chart', $financeHtml, 'financeVisualGovernanceChart', 'Financeiro possui bloco visual de governanca.'),
        checkContains('finance_legacy_tables_collapsible', $financeHtml, 'financeRawTitlesDetails', 'Tabelas antigas permanecem acessiveis em details recolhivel.'),
        checkContains('finance_visual_endpoint_fetch', $financeJs, 'api/finance_visual_analytics.php', 'Modulo visual financeiro consome endpoint dedicado.'),
        checkContains('finance_visual_css_grid', $financeCss, '.finance-visual-grid', 'Financeiro visual possui CSS de grid dedicado.'),
        // FIN.R4.1 — Rastreabilidade
        checkContains('finance_trace_section', $financeHtml, 'financeReceivableTrace', 'Secao de rastreabilidade de recebiveis existe na view (FIN.R4.1).'),
        checkContains('finance_trace_nf_chart', $financeHtml, 'financeTraceNfChart', 'Grafico NFs com boletos abertos existe (FIN.R4.1).'),
        checkContains('finance_trace_method_chart', $financeHtml, 'financeTraceMethodChart', 'Grafico Top 10 metodos de pagamento existe (FIN.R4.1).'),
        checkContains('finance_trace_vendor_chart', $financeHtml, 'financeTraceVendorChart', 'Grafico boletos por vendedor existe (FIN.R4.1).'),
        checkContains('finance_trace_coverage_chart', $financeHtml, 'financeTraceCoverageChart', 'Grafico cobertura NF x titulo existe (FIN.R4.1).'),
        checkContains('finance_trace_aging_chart', $financeHtml, 'financeTraceAgingChart', 'Grafico aging boletos existe (FIN.R4.1).'),
        checkContains('finance_trace_nf_table', $financeHtml, 'financeTraceNfTableBody', 'Tabela compacta NFs com boleto aberto existe (FIN.R4.1).'),
        checkContains('finance_trace_drawer', $financeHtml, 'financeTraceDrawer', 'Drawer operacional de rastreabilidade existe (FIN.R4.1).'),
        checkContains('finance_trace_drawer_cols', $financeHtml, 'financeTraceRows', 'Drawer de rastreabilidade possui tabela de titulos (FIN.R4.1).'),
        checkContains('finance_trace_js_module', $financeJs, 'BIomieFinanceTrace', 'Modulo JS de rastreabilidade registrado (FIN.R4.1).'),
        checkContains('finance_trace_js_endpoint', $financeJs, 'api/finance_receivable_trace.php', 'JS de rastreabilidade consome endpoint dedicado (FIN.R4.1).'),
        checkContains('finance_trace_method_toggle', $financeHtml, 'traceMethodToggle', 'Toggle por valor/qtd/NFs no grafico de metodos (FIN.R4.1).'),
        checkContains('finance_trace_kpi_sem_nf', $financeHtml, 'traceSemNf', 'KPI Sem NF vinculada existe (FIN.R4.1).'),
        checkContains('finance_trace_kpi_sem_vendedor', $financeHtml, 'traceSemVendedor', 'KPI Sem vendedor existe (FIN.R4.1).'),
        checkNoNewMigration($root),
    ];
}

function readFileSafe(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * @return array{id:string,status:string,message:string}
 */
function checkContains(string $id, string $content, string $needle, string $message): array
{
    return [
        'id' => $id,
        'status' => str_contains($content, $needle) ? 'PASS' : 'FAIL',
        'message' => $message,
    ];
}

/**
 * @return array{id:string,status:string,message:string}
 */
function checkNotContains(string $id, string $content, string $needle, string $message): array
{
    return [
        'id' => $id,
        'status' => str_contains($content, $needle) ? 'FAIL' : 'PASS',
        'message' => $message,
    ];
}

/**
 * @return array{id:string,status:string,message:string}
 */
function checkNoNewMigration(string $root): array
{
    $migrationFiles = glob($root . '/database/migrations/*20260527*') ?: [];
    $hasMigrationChange = $migrationFiles !== [];

    return [
        'id' => 'no_migration_changes',
        'status' => $hasMigrationChange ? 'FAIL' : 'PASS',
        'message' => 'Fases UX nao alteram migrations/schema.',
    ];
}

/**
 * @param array<string,mixed> $report
 */
function printPretty(array $report): void
{
    echo PHP_EOL . 'Visual Layout Guard' . PHP_EOL;
    echo str_repeat('-', 72) . PHP_EOL;
    echo 'Status: ' . $report['status'] . PHP_EOL;
    echo 'Resumo: ' . $report['summary']['pass'] . ' PASS / ' . $report['summary']['warn'] . ' WARN / ' . $report['summary']['fail'] . ' FAIL' . PHP_EOL . PHP_EOL;

    foreach ($report['checks'] as $check) {
        echo '[' . $check['status'] . '] ' . $check['id'] . ' - ' . $check['message'] . PHP_EOL;
    }
}
