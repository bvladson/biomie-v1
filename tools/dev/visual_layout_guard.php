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
        // FIN.R4.3 — Recebíveis Operacional 360
        checkContains('fin_r4_3_gap_report', file_exists($root . '/tools/dev/finance_trace_linkage_gap_report.php') ? 'ok' : '', 'ok', 'Ferramenta de diagnostico de gaps de rastreabilidade existe (FIN.R4.3).'),
        checkContains('fin_r4_3_vendor_nodata', $financeHtml, 'financeTraceVendorNoData', 'Card de aviso para sem-vendedor existe no HTML (FIN.R4.3 Phase 6).'),
        checkContains('fin_r4_3_pedido_col', $financeHtml, '<th>Pedido</th>', 'Coluna Pedido existe na tabela de NFs (FIN.R4.3 Phase 5).'),
        checkContains('fin_r4_3_recebiveis_label', $financeHtml, 'recebíveis em aberto', 'Label Recebiveis em aberto substitui Boletos (FIN.R4.3 Phase 4).'),
        checkContains('fin_r4_3_drawer_width', $financeCss, '720px', 'Drawer largura minima 720px configurada (FIN.R4.3 Phase 7).'),
        checkContains('fin_r4_3_method_badge_js', $financeJs, 'methodBadge', 'Funcao methodBadge() existe no JS (FIN.R4.3 Phase 4).'),
        checkContains('fin_r4_3_vendor_filter_js', $financeJs, 'is_unknown_vendor', 'Filtro is_unknown_vendor no vendor chart JS (FIN.R4.3 Phase 6).'),
        checkContains('fin_r4_3_dedup_builder', file_exists($root . '/app/Support/FinanceReceivableTraceBuilder.php') ? (string)file_get_contents($root . '/app/Support/FinanceReceivableTraceBuilder.php') : '', 'deduped', 'Builder deduplica por titulo_key (FIN.R4.3 Phase 2).'),
        checkContains('fin_r4_3_identity_key', file_exists($root . '/app/Support/FinanceReceivableTraceBuilder.php') ? (string)file_get_contents($root . '/app/Support/FinanceReceivableTraceBuilder.php') : '', 'makeTitleIdentityKey', 'Builder expoe makeTitleIdentityKey() (FIN.R4.3 Phase 2).'),
        // FIN.R4.4 — Receivable Identity Resolver
        checkContains('fin_r4_4_resolver_exists', file_exists($root . '/app/Support/FinanceReceivableIdentityResolver.php') ? 'ok' : '', 'ok', 'FinanceReceivableIdentityResolver.php existe (FIN.R4.4 FASE 1).'),
        checkContains('fin_r4_4_resolver_resolve_title', file_exists($root . '/app/Support/FinanceReceivableIdentityResolver.php') ? (string)file_get_contents($root . '/app/Support/FinanceReceivableIdentityResolver.php') : '', 'resolveTitle', 'Resolver expoe resolveTitle() (FIN.R4.4 FASE 1).'),
        checkContains('fin_r4_4_resolver_resolve_many', file_exists($root . '/app/Support/FinanceReceivableIdentityResolver.php') ? (string)file_get_contents($root . '/app/Support/FinanceReceivableIdentityResolver.php') : '', 'resolveMany', 'Resolver expoe resolveMany() para batch loading (FIN.R4.4 FASE 1).'),
        checkContains('fin_r4_4_resolver_explain_gap', file_exists($root . '/app/Support/FinanceReceivableIdentityResolver.php') ? (string)file_get_contents($root . '/app/Support/FinanceReceivableIdentityResolver.php') : '', 'explainGap', 'Resolver expoe explainGap() (FIN.R4.4 FASE 1).'),
        checkContains('fin_r4_4_identity_audit', file_exists($root . '/tools/dev/finance_receivable_identity_audit.php') ? 'ok' : '', 'ok', 'Ferramenta de auditoria de identidade existe (FIN.R4.4 FASE 0).'),
        checkContains('fin_r4_4_governance_resolver', file_exists($root . '/api/finance_collection_governance.php') ? (string)file_get_contents($root . '/api/finance_collection_governance.php') : '', 'FinanceReceivableIdentityResolver', 'Governance API usa FinanceReceivableIdentityResolver (FIN.R4.4 FASE 2).'),
        checkContains('fin_r4_4_governance_numero_nf', file_exists($root . '/api/finance_collection_governance.php') ? (string)file_get_contents($root . '/api/finance_collection_governance.php') : '', 'numero_nf', 'Governance risk_queue retorna numero_nf (FIN.R4.4 FASE 2).'),
        checkContains('fin_r4_4_governance_gap_reason', file_exists($root . '/api/finance_collection_governance.php') ? (string)file_get_contents($root . '/api/finance_collection_governance.php') : '', 'gap_reason', 'Governance risk_queue retorna gap_reason (FIN.R4.4 FASE 2).'),
        checkContains('fin_r4_4_risk_queue_nf_col', (string)file_get_contents($root . '/views/financeiro.html'), '<th>NF / Pedido</th>', 'Coluna NF / Pedido existe no thead da fila de risco (FIN.R4.4 FASE 4).'),
        checkContains('fin_r4_4_risk_queue_nf_cell_js', file_exists($root . '/assets/js/modules/finance/collection-governance.js') ? (string)file_get_contents($root . '/assets/js/modules/finance/collection-governance.js') : '', 'riskNfCell', 'Funcao riskNfCell() existe no JS de governanca (FIN.R4.4 FASE 4).'),
        checkContains('fin_r4_4_risk_queue_colspan', file_exists($root . '/assets/js/modules/finance/collection-governance.js') ? (string)file_get_contents($root . '/assets/js/modules/finance/collection-governance.js') : '', 'colspan="11"', 'Fila de risco usa colspan=11 apos adicao de coluna NF (FIN.R4.4 FASE 4).'),
        checkContains('fin_r4_4_gap_hint_css', file_exists($root . '/assets/css/finance-realizado.css') ? (string)file_get_contents($root . '/assets/css/finance-realizado.css') : '', 'fin-gap-hint', 'CSS fin-gap-hint existe para exibir gap no vendedor (FIN.R4.4 FASE 4).'),
        // FIN.R4.4.1 — Resolver operacional + NF-Bridge + visual compacto
        checkContains('fin_r441_gap_drilldown', file_exists($root . '/tools/dev/finance_receivable_identity_gap_drilldown.php') ? 'ok' : '', 'ok', 'finance_receivable_identity_gap_drilldown.php existe (FIN.R4.4.1 FASE 0).'),
        checkContains('fin_r441_bridge_audit', file_exists($root . '/tools/dev/finance_nf_title_bridge_rebuild_audit.php') ? 'ok' : '', 'ok', 'finance_nf_title_bridge_rebuild_audit.php existe (FIN.R4.4.1 FASE 2).'),
        checkContains('fin_r441_resolver_6path', file_exists($root . '/app/Support/FinanceReceivableIdentityResolver.php') ? (string)file_get_contents($root . '/app/Support/FinanceReceivableIdentityResolver.php') : '', 'nfByNumeroNf', 'Resolver possui indice nfByNumeroNf para Path 6 (FIN.R4.4.1 FASE 1).'),
        checkContains('fin_r441_resolver_path2', file_exists($root . '/app/Support/FinanceReceivableIdentityResolver.php') ? (string)file_get_contents($root . '/app/Support/FinanceReceivableIdentityResolver.php') : '', 'title_nota_key_as_chave_nfe', 'Resolver possui Path 2 nota_key-como-chave_nfe 44-char (FIN.R4.4.1 FASE 1).'),
        checkContains('fin_r441_by_vendor_resolver', file_exists($root . '/api/finance_collection_by_vendor.php') ? (string)file_get_contents($root . '/api/finance_collection_by_vendor.php') : '', 'FinanceReceivableIdentityResolver', 'finance_collection_by_vendor.php usa resolver (FIN.R4.4.1 FASE 3).'),
        checkContains('fin_r441_by_vendor_nf_label', file_exists($root . '/api/finance_collection_by_vendor.php') ? (string)file_get_contents($root . '/api/finance_collection_by_vendor.php') : '', 'nf_label', 'finance_collection_by_vendor.php retorna nf_label (FIN.R4.4.1 FASE 3).'),
        checkContains('fin_r441_by_vendor_unresolved', file_exists($root . '/api/finance_collection_by_vendor.php') ? (string)file_get_contents($root . '/api/finance_collection_by_vendor.php') : '', 'unresolved_vendor_bucket', 'finance_collection_by_vendor.php retorna unresolved_vendor_bucket (FIN.R4.4.1 FASE 3).'),
        checkContains('fin_r441_gov_nf_label', file_exists($root . '/api/finance_collection_governance.php') ? (string)file_get_contents($root . '/api/finance_collection_governance.php') : '', 'nf_label', 'finance_collection_governance.php retorna nf_label no risk_queue (FIN.R4.4.1 FASE 4).'),
        checkContains('fin_r441_gov_pedido_label', file_exists($root . '/api/finance_collection_governance.php') ? (string)file_get_contents($root . '/api/finance_collection_governance.php') : '', 'pedido_label', 'finance_collection_governance.php retorna pedido_label no risk_queue (FIN.R4.4.1 FASE 4).'),
        checkContains('fin_r441_js_unresolved_alert', file_exists($root . '/assets/js/modules/finance/collection-by-vendor.js') ? (string)file_get_contents($root . '/assets/js/modules/finance/collection-by-vendor.js') : '', 'renderUnresolvedVendorAlert', 'JS collection-by-vendor.js possui renderUnresolvedVendorAlert (FIN.R4.4.1 FASE 5).'),
        checkContains('fin_r441_js_nf_label', file_exists($root . '/assets/js/modules/finance/collection-by-vendor.js') ? (string)file_get_contents($root . '/assets/js/modules/finance/collection-by-vendor.js') : '', 'nf_label', 'JS collection-by-vendor.js usa nf_label em renderOriginRef (FIN.R4.4.1 FASE 5).'),
        checkContains('fin_r441_js_drawer_identity', file_exists($root . '/assets/js/modules/finance/collection-by-vendor.js') ? (string)file_get_contents($root . '/assets/js/modules/finance/collection-by-vendor.js') : '', 'fcInfoNfPedido', 'JS drawer popula fcInfoNfPedido com chips NF/Pedido/Metodo (FIN.R4.4.1 FASE 6).'),
        checkContains('fin_r441_js_gov_nf_label', file_exists($root . '/assets/js/modules/finance/collection-governance.js') ? (string)file_get_contents($root . '/assets/js/modules/finance/collection-governance.js') : '', 'nf_label', 'JS collection-governance.js riskNfCell usa nf_label (FIN.R4.4.1 FASE 5).'),
        checkContains('fin_r441_html_unresolved_div', (string)file_get_contents($root . '/views/financeiro.html'), 'fcUnresolvedVendorAlert', 'financeiro.html possui div fcUnresolvedVendorAlert (FIN.R4.4.1 FASE 5).'),
        checkContains('fin_r441_html_identity_block', (string)file_get_contents($root . '/views/financeiro.html'), 'fcDrawerIdentity', 'financeiro.html possui fcDrawerIdentity no drawer (FIN.R4.4.1 FASE 6).'),
        checkContains('fin_r441_html_nf_pedido_chips', (string)file_get_contents($root . '/views/financeiro.html'), 'fcInfoNfPedido', 'financeiro.html possui fcInfoNfPedido no drawer (FIN.R4.4.1 FASE 6).'),
        checkContains('fin_r441_html_diagnostic', (string)file_get_contents($root . '/views/financeiro.html'), 'fcDiagnosticContent', 'financeiro.html possui fcDiagnosticContent no drawer (FIN.R4.4.1 FASE 6).'),
        // COM.R1.4 — Carteirização visual + metas proporcionais
        checkContains('com_r14_ownership_resolver', file_exists($root . '/app/Support/CustomerOwnershipResolver.php') ? 'ok' : '', 'ok', 'CustomerOwnershipResolver.php existe (COM.R1.4 FASE 1).'),
        checkContains('com_r14_conflict_audit', file_exists($root . '/tools/dev/customer_ownership_conflict_audit.php') ? 'ok' : '', 'ok', 'customer_ownership_conflict_audit.php existe (COM.R1.4 FASE 2).'),
        checkContains('com_r14_conflict_api', file_exists($root . '/api/customer_ownership_conflicts.php') ? 'ok' : '', 'ok', 'api/customer_ownership_conflicts.php existe (COM.R1.4 FASE 7).'),
        checkContains('com_r14_canonical_vendors_targets', file_exists($root . '/api/canonical_vendors.php') ? (string)file_get_contents($root . '/api/canonical_vendors.php') : '', 'SalesGoalPeriodCalculator', 'canonical_vendors.php usa SalesGoalPeriodCalculator (COM.R1.4 FASE 3).'),
        checkContains('com_r14_canonical_vendors_conflicts', file_exists($root . '/api/canonical_vendors.php') ? (string)file_get_contents($root . '/api/canonical_vendors.php') : '', 'conflicts_out_of_portfolio', 'canonical_vendors.php expõe conflicts_out_of_portfolio (COM.R1.4 FASE 3).'),
        checkContains('com_r14_vendedores_kpi', (string)file_get_contents($root . '/views/vendedores.html'), 'kpiMetaProporcional', 'vendedores.html possui KPI de meta proporcional (COM.R1.4 FASE 4).'),
        checkContains('com_r14_vendedores_conflict_kpi', (string)file_get_contents($root . '/views/vendedores.html'), 'kpiConflictCount', 'vendedores.html possui KPI de conflitos de carteira (COM.R1.4 FASE 4).'),
        checkContains('com_r14_vendedores_drawer_ownership', (string)file_get_contents($root . '/views/vendedores.html'), 'drawerOwnershipSection', 'vendedores.html possui seção de carteirização no drawer (COM.R1.4 FASE 4).'),
        checkContains('com_r14_render_helpers_conflict_badge', file_exists($root . '/assets/js/core/render-helpers.js') ? (string)file_get_contents($root . '/assets/js/core/render-helpers.js') : '', 'conflicts_out_of_portfolio', 'render-helpers.js exibe badge de conflito de carteira (COM.R1.4 FASE 4).'),
        checkContains('com_r14_app_js_ownership_kpi', file_exists($root . '/assets/js/app.js') ? (string)file_get_contents($root . '/assets/js/app.js') : '', 'ownership_summary', 'app.js processa ownership_summary (COM.R1.4 FASE 4).'),
        checkContains('com_r14_app_js_goal_period', file_exists($root . '/assets/js/app.js') ? (string)file_get_contents($root . '/assets/js/app.js') : '', 'goal_period', 'app.js processa goal_period para meta proporcional (COM.R1.4 FASE 3).'),
        // COM.R1.5 — Vendor alias channel + active status
        checkContains('com_r15_vendor_alias_resolver', file_exists($root . '/app/Support/VendorAliasResolver.php') ? 'ok' : '', 'ok', 'VendorAliasResolver.php existe (COM.R1.5).'),
        checkContains('com_r15_active_vendor_resolver', file_exists($root . '/app/Support/ActiveVendorResolver.php') ? 'ok' : '', 'ok', 'ActiveVendorResolver.php existe (COM.R1.5).'),
        checkContains('com_r15_alias_rev_var_separate', file_exists($root . '/app/Support/VendorAliasResolver.php') ? (string)file_get_contents($root . '/app/Support/VendorAliasResolver.php') : '', 'CHANNEL_REV', 'VendorAliasResolver define CHANNEL_REV distinto de CHANNEL_VAR (COM.R1.5).'),
        checkNotContains('com_r15_no_cross_strip', file_exists($root . '/app/Modules/Sales/Application/Vendors/VendorIdentityResolver.php') ? (string)file_get_contents($root . '/app/Modules/Sales/Application/Vendors/VendorIdentityResolver.php') : '', "str_replace([' - rev', ' - var'], '', \$normalized)", 'VendorIdentityResolver não remove REV/VAR cross-channel (COM.R1.5).'),
        checkContains('com_r15_inactive_toggle', file_exists($root . '/views/vendedores.html') ? (string)file_get_contents($root . '/views/vendedores.html') : '', 'vendorIncludeInactiveBtn', 'vendedores.html possui toggle Incluir inativos (COM.R1.5).'),
        checkContains('com_r15_app_js_inactive_param', file_exists($root . '/assets/js/app.js') ? (string)file_get_contents($root . '/assets/js/app.js') : '', 'include_inactive', 'app.js passa include_inactive ao carregar vendedores (COM.R1.5).'),
        checkContains('com_r15_canonical_active_only', file_exists($root . '/api/canonical_vendors.php') ? (string)file_get_contents($root . '/api/canonical_vendors.php') : '', 'active_only', 'canonical_vendors.php filtra active_only por padrão (COM.R1.5).'),
        checkContains('com_r15_canonical_channel', file_exists($root . '/api/canonical_vendors.php') ? (string)file_get_contents($root . '/api/canonical_vendors.php') : '', 'vendor_channel', 'canonical_vendors.php expõe vendor_channel (COM.R1.5).'),
        checkContains('com_r15_ownership_inactive', file_exists($root . '/app/Support/CustomerOwnershipResolver.php') ? (string)file_get_contents($root . '/app/Support/CustomerOwnershipResolver.php') : '', 'CONFLICT_OWNER_INACTIVE_REVIEW', 'CustomerOwnershipResolver classifica owner_inactive_review (COM.R1.5).'),
        checkContains('com_r15_ownership_cross_channel', file_exists($root . '/app/Support/CustomerOwnershipResolver.php') ? (string)file_get_contents($root . '/app/Support/CustomerOwnershipResolver.php') : '', 'CONFLICT_CROSS_CHANNEL', 'CustomerOwnershipResolver classifica cross_channel_conflict (COM.R1.5).'),
        checkContains('com_r15_channel_badge_js', file_exists($root . '/assets/js/core/render-helpers.js') ? (string)file_get_contents($root . '/assets/js/core/render-helpers.js') : '', 'vendor_channel', 'render-helpers.js exibe channel badge (COM.R1.5).'),
        checkContains('com_r15_vendor_alias_audit', file_exists($root . '/tools/dev/vendor_alias_audit.php') ? 'ok' : '', 'ok', 'vendor_alias_audit.php existe (COM.R1.5).'),
        checkContains('com_r15_vendor_active_audit', file_exists($root . '/tools/dev/vendor_active_status_audit.php') ? 'ok' : '', 'ok', 'vendor_active_status_audit.php existe (COM.R1.5).'),
        // COM.R2 — Commercial visual channel separation
        checkContains('com_r2_channel_presenter', file_exists($root . '/app/Support/CommercialChannelPresenter.php') ? 'ok' : '', 'ok', 'CommercialChannelPresenter.php existe (COM.R2 FASE 1).'),
        checkContains('com_r2_vendor_channel_filter', file_exists($root . '/views/vendedores.html') ? (string)file_get_contents($root . '/views/vendedores.html') : '', 'data-vendor-channel', 'vendedores.html possui filtro de canal (COM.R2 FASE 3).'),
        checkContains('com_r2_plano_voo_meta_prop', file_exists($root . '/views/plano_voo.html') ? (string)file_get_contents($root . '/views/plano_voo.html') : '', 'Meta proporcional', 'plano_voo.html usa label "Meta proporcional" (COM.R2 FASE 4).'),
        checkContains('com_r2_plano_voo_escopos', file_exists($root . '/views/plano_voo.html') ? (string)file_get_contents($root . '/views/plano_voo.html') : '', 'fpScopesPanel', 'plano_voo.html possui painel de escopos separados (COM.R2 FASE 4).'),
        checkNotContains('com_r2_plano_voo_no_meta_total', file_exists($root . '/views/plano_voo.html') ? (string)file_get_contents($root . '/views/plano_voo.html') : '', 'Meta Total', 'plano_voo.html não usa "Meta Total" ambíguo (COM.R2 FASE 4).'),
        checkContains('com_r2_pedidos_licitacao_tab', file_exists($root . '/views/pedidos.html') ? (string)file_get_contents($root . '/views/pedidos.html') : '', 'licitacao', 'pedidos.html possui tab Licitação (COM.R2 FASE 5).'),
        checkContains('com_r2_pedidos_remessa_tab', file_exists($root . '/views/pedidos.html') ? (string)file_get_contents($root . '/views/pedidos.html') : '', 'remittance', 'pedidos.html possui tab Remessa (COM.R2 FASE 5).'),
        checkContains('com_r2_index_realizado_nf', file_exists($root . '/views/index.html') ? (string)file_get_contents($root . '/views/index.html') : '', 'Realizado NF', 'index.html possui label "Realizado NF" (COM.R2 FASE 7).'),
        checkContains('com_r2_visual_scope_audit', file_exists($root . '/tools/dev/commercial_visual_scope_audit.php') ? 'ok' : '', 'ok', 'commercial_visual_scope_audit.php existe (COM.R2 FASE 0).'),
        checkContains('com_r2_app_js_channel', file_exists($root . '/assets/js/app.js') ? (string)file_get_contents($root . '/assets/js/app.js') : '', 'BIomieVendorsChannel', 'app.js passa canal ao carregar vendedores (COM.R2 FASE 3).'),
        // COM.R2 checks adicionais (FASE 2/3/5/6/7)
        checkContains('com_r2_vendedores_remessa_filter', file_exists($root . '/views/vendedores.html') ? (string)file_get_contents($root . '/views/vendedores.html') : '', 'data-vendor-channel="remessa"', 'vendedores.html possui filtro Remessa no canal filter (COM.R2 FASE 2).'),
        checkContains('com_r2_vendedores_drawer_tabs', file_exists($root . '/views/vendedores.html') ? (string)file_get_contents($root . '/views/vendedores.html') : '', 'drawerTabNav', 'vendedores.html possui tab nav Vendor 360 (COM.R2 FASE 3).'),
        checkContains('com_r2_plano_voo_orcamentos', file_exists($root . '/views/plano_voo.html') ? (string)file_get_contents($root . '/views/plano_voo.html') : '', 'fpOrcamentosValue', 'plano_voo.html possui card Orçamentos no painel de escopos (COM.R2 FASE 4).'),
        checkContains('com_r2_pedidos_licitacao_select',
            (file_exists($root . '/views/pedidos.html') ? (string)file_get_contents($root . '/views/pedidos.html') : '')
            . (file_exists($root . '/assets/js/modules/commercial/nf-periodo-table.js') ? (string)file_get_contents($root . '/assets/js/modules/commercial/nf-periodo-table.js') : ''),
            'value="licitacao"', 'pedidos.html (ou nf-periodo-table.js) possui Licitação no filtro de tipo de NF (COM.R2 FASE 5/6).'),
        checkContains('com_r2_pedidos_semantic_legend', file_exists($root . '/views/pedidos.html') ? (string)file_get_contents($root . '/views/pedidos.html') : '', 'nfSemanticLegend', 'pedidos.html possui bloco de semântica comercial (COM.R2 FASE 6).'),
        checkContains('com_r2_index_carteira_kpi', file_exists($root . '/views/index.html') ? (string)file_get_contents($root . '/views/index.html') : '', 'summary-wallet', 'index.html possui KPI Carteira aceita (COM.R2 FASE 7).'),
        checkContains('com_r2_index_orcamentos_kpi', file_exists($root . '/views/index.html') ? (string)file_get_contents($root . '/views/index.html') : '', 'summary-quotes', 'index.html possui KPI Orçamentos (COM.R2 FASE 7).'),
        // COM.R2.1 — Period Scope Consistency checks
        checkContains('com_r21_period_scope_context', file_exists($root . '/app/Commercial/CommercialPeriodScopeContext.php') ? 'ok' : '', 'ok', 'CommercialPeriodScopeContext.php existe (COM.R2.1).'),
        checkContains('com_r21_scope_sql_filter', file_exists($root . '/app/Commercial/CommercialScopeSqlFilter.php') ? 'ok' : '', 'ok', 'CommercialScopeSqlFilter.php existe (COM.R2.1).'),
        checkContains('com_r21_client_ownership_resolver', file_exists($root . '/app/Commercial/ClientCommercialOwnershipResolver.php') ? 'ok' : '', 'ok', 'ClientCommercialOwnershipResolver.php existe (COM.R2.1).'),
        checkContains('com_r21_period_audit', file_exists($root . '/tools/dev/commercial_period_consistency_audit.php') ? 'ok' : '', 'ok', 'commercial_period_consistency_audit.php existe (COM.R2.1).'),
        checkContains('com_r21_goal_audit', file_exists($root . '/tools/dev/sales_goal_period_consistency_audit.php') ? 'ok' : '', 'ok', 'sales_goal_period_consistency_audit.php existe (COM.R2.1).'),
        checkContains('com_r21_client_audit', file_exists($root . '/tools/dev/client_commercial_ownership_audit.php') ? 'ok' : '', 'ok', 'client_commercial_ownership_audit.php existe (COM.R2.1).'),
        checkContains('com_r21_query_perf_audit', file_exists($root . '/tools/dev/commercial_query_performance_audit.php') ? 'ok' : '', 'ok', 'commercial_query_performance_audit.php existe (COM.R2.1).'),
        checkContains('com_r21_renderPanelState_guard', file_exists($root . '/assets/js/biomie-states.js') ? (string)file_get_contents($root . '/assets/js/biomie-states.js') : '', 'window.renderPanelState', 'biomie-states.js possui safety guard window.renderPanelState (COM.R2.1).'),
        checkContains('com_r21_customers_count_fiscal', file_exists($root . '/app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php') ? (string)file_get_contents($root . '/app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php') : '', 'customers_count', 'FiscalReadRepository inclui customers_count por vendedor (COM.R2.1).'),
        checkContains('com_r21_coalesce_date_fiscal', file_exists($root . '/app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php') ? (string)file_get_contents($root . '/app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php') : '', 'COALESCE(data_competencia, data_emissao)', 'FiscalReadRepository usa COALESCE(data_competencia, data_emissao) (COM.R2.1).'),
        checkContains('com_r21_coalesce_invoiced', file_exists($root . '/app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php') ? (string)file_get_contents($root . '/app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php') : '', 'COALESCE(data_competencia, data_emissao) BETWEEN', 'FiscalReadRepository usa COALESCE(data_competencia, data_emissao) BETWEEN em aggregateInvoicedByVendorCommercial (COM.R2.1).'),
        checkContains('com_r21_customers_count_fp', file_exists($root . '/app/FlightPlanV2Service.php') ? (string)file_get_contents($root . '/app/FlightPlanV2Service.php') : '', 'customers_count', 'FlightPlanV2Service inclui customers_count no vendor row (COM.R2.1).'),
        // COM.R2.2 — Historical coverage, portfolio, Product360, dashboard KPIs
        checkContains('com_r22_time_bucket', file_exists($root . '/app/Commercial/CommercialTimeBucket.php') ? 'ok' : '', 'ok', 'CommercialTimeBucket.php existe (COM.R2.2).'),
        checkContains('com_r22_monthly_coverage', file_exists($root . '/tools/dev/commercial_monthly_coverage_audit.php') ? 'ok' : '', 'ok', 'commercial_monthly_coverage_audit.php existe (COM.R2.2).'),
        checkContains('com_r22_goal_examples', file_exists($root . '/tools/dev/sales_goal_broken_period_examples_audit.php') ? 'ok' : '', 'ok', 'sales_goal_broken_period_examples_audit.php existe (COM.R2.2).'),
        checkContains('com_r22_stock_audit', file_exists($root . '/tools/dev/omie_stock_capability_audit.php') ? 'ok' : '', 'ok', 'omie_stock_capability_audit.php existe (COM.R2.2).'),
        checkContains('com_r22_recover_range', file_exists($root . '/tools/dev/recover_commercial_range.php') ? 'ok' : '', 'ok', 'recover_commercial_range.php existe (COM.R2.2).'),
        checkContains('com_r22_product360_fix', file_exists($root . '/api/product_detail.php') ? (string)file_get_contents($root . '/api/product_detail.php') : '', "'50'", 'api/product_detail.php usa string default em Http::query limit (COM.R2.2).'),
        checkContains('com_r22_dashboard_wallet', file_exists($root . '/api/commercial_dashboard.php') ? (string)file_get_contents($root . '/api/commercial_dashboard.php') : '', 'wallet_value', 'api/commercial_dashboard.php expõe wallet_value (COM.R2.2).'),
        checkContains('com_r22_dashboard_quotes', file_exists($root . '/api/commercial_dashboard.php') ? (string)file_get_contents($root . '/api/commercial_dashboard.php') : '', 'quotes_value', 'api/commercial_dashboard.php expõe quotes_value (COM.R2.2).'),
        checkContains('com_r22_js_wallet_binding', file_exists($root . '/assets/js/core/render-helpers.js') ? (string)file_get_contents($root . '/assets/js/core/render-helpers.js') : '', 'summary-wallet', 'render-helpers.js tem binding summary-wallet (COM.R2.2).'),
        checkContains('com_r22_js_quotes_binding', file_exists($root . '/assets/js/core/render-helpers.js') ? (string)file_get_contents($root . '/assets/js/core/render-helpers.js') : '', 'summary-quotes', 'render-helpers.js tem binding summary-quotes (COM.R2.2).'),
        // COM.R3 — Commercial 360 Period, Portfolio, Product Stock
        checkContains('com_r3_metric_date', file_exists($root . '/app/Commercial/CommercialMetricDate.php') ? 'ok' : '', 'ok', 'CommercialMetricDate.php existe (COM.R3 Fase 1).'),
        checkContains('com_r3_period_bucket', file_exists($root . '/app/Commercial/CommercialPeriodBucket.php') ? 'ok' : '', 'ok', 'CommercialPeriodBucket.php existe com limites ≤45/46-180/>180 (COM.R3 Fase 1).'),
        checkContains('com_r3_metrics_aggregator', file_exists($root . '/app/Commercial/CommercialMetricsAggregator.php') ? 'ok' : '', 'ok', 'CommercialMetricsAggregator.php existe (COM.R3 Fase 1).'),
        checkContains('com_r3_portfolio_resolver', file_exists($root . '/app/Commercial/ClientPortfolio360Resolver.php') ? 'ok' : '', 'ok', 'ClientPortfolio360Resolver.php existe (COM.R3 Fase 3).'),
        checkContains('com_r3_portfolio_api', file_exists($root . '/api/client_portfolio_360.php') ? 'ok' : '', 'ok', 'api/client_portfolio_360.php existe (COM.R3 Fase 3).'),
        checkContains('com_r3_portfolio_api_modes', file_exists($root . '/api/client_portfolio_360.php') ? (string)file_get_contents($root . '/api/client_portfolio_360.php') : '', "'conflicts'", 'api/client_portfolio_360.php possui modo conflicts (COM.R3 Fase 3).'),
        checkContains('com_r3_product360_builder', file_exists($root . '/app/Support/Product360Builder.php') ? 'ok' : '', 'ok', 'Product360Builder.php existe (COM.R3 Fase 4).'),
        checkContains('com_r3_product360_stock', file_exists($root . '/app/Support/Product360Builder.php') ? (string)file_get_contents($root . '/app/Support/Product360Builder.php') : '', 'empty_state', 'Product360Builder possui graceful empty_state para estoque (COM.R3 Fase 4).'),
        checkContains('com_r3_stock_saldos_migration', file_exists($root . '/database/migrations/Version20260530000002_ProdutosEstoqueSaldos.php') ? 'ok' : '', 'ok', 'Migration ProdutosEstoqueSaldos existe (COM.R3 Fase 4).'),
        checkContains('com_r3_nf_table_api', file_exists($root . '/api/nf_periodo_table.php') ? 'ok' : '', 'ok', 'api/nf_periodo_table.php existe com paginação server-side (COM.R3 Fase 5).'),
        checkContains('com_r3_nf_table_paginated', file_exists($root . '/api/nf_periodo_table.php') ? (string)file_get_contents($root . '/api/nf_periodo_table.php') : '', 'LIMIT', 'api/nf_periodo_table.php usa LIMIT/OFFSET (COM.R3 Fase 5).'),
        checkContains('com_r3_nf_table_js', file_exists($root . '/assets/js/modules/commercial/nf-periodo-table.js') ? 'ok' : '', 'ok', 'nf-periodo-table.js existe (COM.R3 Fase 5).'),
        checkContains('com_r3_chart_helpers', file_exists($root . '/assets/js/core/chart-helpers.js') ? 'ok' : '', 'ok', 'chart-helpers.js existe com bucket temporal (COM.R3 Fase 6).'),
        checkContains('com_r3_chart_destroy', file_exists($root . '/assets/js/core/chart-helpers.js') ? (string)file_get_contents($root . '/assets/js/core/chart-helpers.js') : '', 'destroyChart', 'chart-helpers.js possui destroyChart() para evitar duplicação (COM.R3 Fase 6).'),
        checkContains('com_r3_chart_decimation', file_exists($root . '/assets/js/core/chart-helpers.js') ? (string)file_get_contents($root . '/assets/js/core/chart-helpers.js') : '', 'decimation', 'chart-helpers.js habilita decimation para séries grandes (COM.R3 Fase 6).'),
        checkContains('com_r3_360_period_audit', file_exists($root . '/tools/dev/commercial_360_period_audit.php') ? 'ok' : '', 'ok', 'commercial_360_period_audit.php existe (COM.R3 Fase 0).'),
        checkContains('com_r3_portfolio_audit', file_exists($root . '/tools/dev/client_portfolio_360_audit.php') ? 'ok' : '', 'ok', 'client_portfolio_360_audit.php existe (COM.R3 Fase 0).'),
        checkContains('com_r3_stock_source_audit', file_exists($root . '/tools/dev/product_360_stock_source_audit.php') ? 'ok' : '', 'ok', 'product_360_stock_source_audit.php existe (COM.R3 Fase 0).'),
        checkContains('com_r3_nf_perf_audit', file_exists($root . '/tools/dev/nf_periodo_performance_audit.php') ? 'ok' : '', 'ok', 'nf_periodo_performance_audit.php existe (COM.R3 Fase 0).'),
        checkNoNewMigration($root),

        // EST.R1.3 — Product 360 Polish & Temporal Semantics
        checkContains('est_r13_identity_resolver',    file_exists($root . '/app/Support/ProductCommercialIdentityResolver.php') ? 'ok' : '', 'ok', 'ProductCommercialIdentityResolver existe (EST.R1.3).'),
        checkContains('est_r13_bucket_resolver',      file_exists($root . '/app/Support/ProductTemporalBucketResolver.php') ? 'ok' : '', 'ok', 'ProductTemporalBucketResolver existe (EST.R1.3).'),
        checkContains('est_r13_repo_trim',            readFileSafe($root . '/app/Modules/Sales/Infrastructure/Product360ReadRepository.php'), 'TRIM(i.codigo_produto)', 'Product360ReadRepository usa TRIM no código (EST.R1.3).'),
        checkContains('est_r13_repo_coalesce_date',   readFileSafe($root . '/app/Modules/Sales/Infrastructure/Product360ReadRepository.php'), 'COALESCE(n.data_competencia, n.data_emissao)', 'Product360ReadRepository usa COALESCE para datas (EST.R1.3).'),
        checkContains('est_r13_timeline_bucket_mode', readFileSafe($root . '/app/Modules/Sales/Infrastructure/Product360ReadRepository.php'), 'bucket_mode', 'Product360ReadRepository::timeline retorna bucket_mode (EST.R1.3).'),
        checkContains('est_r13_service_resolver',     readFileSafe($root . '/app/Modules/Sales/Application/Products/Product360ReadService.php'), 'ProductCommercialIdentityResolver', 'Product360ReadService usa resolver (EST.R1.3).'),
        checkContains('est_r13_service_identity_block', readFileSafe($root . '/app/Modules/Sales/Application/Products/Product360ReadService.php'), "'identity'", "Product360ReadService retorna bloco 'identity' (EST.R1.3)."),
        checkContains('est_r13_vendors_qty_pct',      readFileSafe($root . '/app/Modules/Sales/Application/Products/Product360ReadService.php'), 'participation_qty_pct', 'mapVendors retorna participation_qty_pct (EST.R1.3).'),
        checkContains('est_r13_js_placeholder_guard', readFileSafe($root . '/assets/js/modules/product-detail/index.js'), 'PLACEHOLDER_NAMES', 'index.js tem guard de placeholder (EST.R1.3).'),
        checkContains('est_r13_tabs_vendor_qty',      readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'participation_qty_pct', 'tabs.js renderVendors usa qty participation (EST.R1.3).'),
        checkContains('est_r13_tabs_source_note',     readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'pd-source-note', 'tabs.js renderEstoque tem semantic source note (EST.R1.3).'),
        checkContains('est_r13_tabs_identity_note',   readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'pd-identity-note', 'tabs.js renderResumo tem identity badge (EST.R1.3).'),
        checkContains('est_r13_tabs_empty_specific',  readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'Sem NFs neste recorte', 'tabs.js tem empty state específico sem contradição (EST.R1.3).'),
        checkContains('est_r13_identity_audit',       file_exists($root . '/tools/dev/product_360_identity_consistency_audit.php') ? 'ok' : '', 'ok', 'product_360_identity_consistency_audit.php existe (EST.R1.3).'),
        checkContains('est_r13_bucket_audit',         file_exists($root . '/tools/dev/product_360_temporal_bucket_audit.php') ? 'ok' : '', 'ok', 'product_360_temporal_bucket_audit.php existe (EST.R1.3).'),

        // PROD.R2 — Product Action Intelligence
        checkContains('prod_r2_builder',              file_exists($root . '/app/Product/ProductActionIntelligenceBuilder.php') ? 'ok' : '', 'ok', 'ProductActionIntelligenceBuilder existe (PROD.R2).'),
        checkContains('prod_r2_api',                  file_exists($root . '/api/product_action_intelligence.php') ? 'ok' : '', 'ok', 'product_action_intelligence.php existe (PROD.R2).'),
        checkContains('prod_r2_api_modes',            readFileSafe($root . '/api/product_action_intelligence.php'), 'repurchase', 'API tem mode=repurchase (PROD.R2).'),
        checkContains('prod_r2_builder_prepared',     readFileSafe($root . '/app/Product/ProductActionIntelligenceBuilder.php'), '$this->pdo->prepare(', 'Builder usa prepared statements (PROD.R2).'),
        checkContains('prod_r2_product_detail_tab',   readFileSafe($root . '/api/product_detail.php'), "tab === 'acao'", "product_detail.php tem tab=acao (PROD.R2)."),
        checkContains('prod_r2_mercadorias_section',  readFileSafe($root . '/views/mercadorias.html'), 'prodR2Section', 'mercadorias.html tem seção Inteligência comercial (PROD.R2).'),
        checkContains('prod_r2_mercadorias_drawer',   readFileSafe($root . '/views/mercadorias.html'), 'prodR2DrawerBody', 'mercadorias.html tem drawer de ação (PROD.R2).'),
        checkContains('prod_r2_mercadorias_lazy',     readFileSafe($root . '/views/mercadorias.html'), 'prodR2Panel', 'mercadorias.html carrega seção lazy (PROD.R2).'),
        checkContains('prod_r2_product360_tab',       readFileSafe($root . '/assets/js/modules/product-detail/index.js'), "'acao'", "Product 360 index.js tem tab 'acao' (PROD.R2)."),
        checkContains('prod_r2_tabs_render_acao',     readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'renderAcaoComercial', 'tabs.js tem renderAcaoComercial (PROD.R2).'),
        checkContains('prod_r2_tabs_score_acao',      readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'score_acao', 'tabs.js renderAcaoComercial usa score_acao (PROD.R2).'),
        checkContains('prod_r2_opportunity_audit',    file_exists($root . '/tools/dev/product_action_opportunity_audit.php') ? 'ok' : '', 'ok', 'product_action_opportunity_audit.php existe (PROD.R2).'),
        checkContains('prod_r2_repurchase_audit',     file_exists($root . '/tools/dev/product_customer_repurchase_audit.php') ? 'ok' : '', 'ok', 'product_customer_repurchase_audit.php existe (PROD.R2).'),
        checkContains('prod_r2_affinity_audit',       file_exists($root . '/tools/dev/product_vendor_affinity_audit.php') ? 'ok' : '', 'ok', 'product_vendor_affinity_audit.php existe (PROD.R2).'),

        // PROD.R2.1 — Product Action Explainability
        checkContains('prod_r2_1_manager_explanation',  readFileSafe($root . '/app/Product/ProductActionIntelligenceBuilder.php'), 'buildManagerExplanation',  'Builder tem buildManagerExplanation (PROD.R2.1).'),
        checkContains('prod_r2_1_data_basis',           readFileSafe($root . '/app/Product/ProductActionIntelligenceBuilder.php'), 'buildDataBasis',            'Builder tem buildDataBasis (PROD.R2.1).'),
        checkContains('prod_r2_1_friendly_name',        readFileSafe($root . '/app/Product/ProductActionIntelligenceBuilder.php'), 'friendlyName',              'Builder tem friendlyName (PROD.R2.1).'),
        checkContains('prod_r2_1_action_manager_card',  readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'action-manager-card',  'tabs.js tem action-manager-card (PROD.R2.1).'),
        checkContains('prod_r2_1_action_meaning',       readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'action-meaning',        'tabs.js tem action-meaning (PROD.R2.1).'),
        checkContains('prod_r2_1_action_rec_action',    readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'action-recommended-action', 'tabs.js tem action-recommended-action (PROD.R2.1).'),
        checkContains('prod_r2_1_action_next_step',     readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'action-next-step',      'tabs.js tem action-next-step (PROD.R2.1).'),
        checkContains('prod_r2_1_action_score_reasons', readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'action-score-reasons',  'tabs.js tem action-score-reasons (PROD.R2.1).'),
        checkContains('prod_r2_1_action_data_basis',    readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'action-data-basis',     'tabs.js tem action-data-basis (PROD.R2.1).'),
        checkContains('prod_r2_1_action_risk_note',     readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'action-risk-note',      'tabs.js tem action-risk-note (PROD.R2.1).'),
        checkContains('prod_r2_1_action_kpi_grid',      readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'action-kpi-grid',       'tabs.js tem action-kpi-grid (PROD.R2.1).'),
        checkContains('prod_r2_1_empty_repurchase',     readFileSafe($root . '/assets/js/modules/product-detail/tabs.js'), 'action-empty-repurchase-explanation', 'tabs.js tem action-empty-repurchase-explanation (PROD.R2.1).'),
        checkContains('prod_r2_1_legenda',              readFileSafe($root . '/views/mercadorias.html'), 'prodR2Legenda', 'mercadorias.html tem legenda PROD.R2.1.'),
        checkContains('prod_r2_1_score_tooltip',        readFileSafe($root . '/views/mercadorias.html'), 'Como o score é calculado', 'mercadorias.html tem tooltip "Como o score é calculado" (PROD.R2.1).'),
        checkContains('prod_r2_1_explainability_audit', file_exists($root . '/tools/dev/product_action_explainability_audit.php') ? 'ok' : '', 'ok', 'product_action_explainability_audit.php existe (PROD.R2.1).'),
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
