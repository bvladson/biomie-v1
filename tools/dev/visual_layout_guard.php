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

        // GEO.R1 — Same-Map Ceará/RMF Expansion
        checkContains('geo_r1_layer_registry',     file_exists($root . '/app/Geo/GeoMapLayerRegistry.php') ? 'ok' : '', 'ok', 'GeoMapLayerRegistry.php existe (GEO.R1).'),
        checkContains('geo_r1_territory_resolver',  file_exists($root . '/app/Geo/GeoTerritoryResolver.php') ? 'ok' : '', 'ok', 'GeoTerritoryResolver.php existe (GEO.R1).'),
        checkContains('geo_r1_sales_builder',       file_exists($root . '/app/Geo/GeoSalesLayerBuilder.php') ? 'ok' : '', 'ok', 'GeoSalesLayerBuilder.php existe (GEO.R1).'),
        checkContains('geo_r1_api_sales_layer',     file_exists($root . '/api/geo_sales_layer.php') ? 'ok' : '', 'ok', 'api/geo_sales_layer.php existe (GEO.R1).'),
        checkContains('geo_r1_api_territory_detail',file_exists($root . '/api/geo_territory_detail.php') ? 'ok' : '', 'ok', 'api/geo_territory_detail.php existe (GEO.R1).'),
        checkContains('geo_r1_migration',           file_exists($root . '/database/migrations/Version20260530200000_GeoTerritoryCe.php') ? 'ok' : '', 'ok', 'Migration GeoTerritoryCe.php existe (GEO.R1).'),
        checkContains('geo_r1_geojson_fortaleza',   file_exists($root . '/assets/geo/fortaleza_bairros.json') ? 'ok' : '', 'ok', 'fortaleza_bairros.json existe — camada original preservada (GEO.R1).'),
        checkContains('geo_r1_geojson_rmf',         file_exists($root . '/assets/geo/rmf_municipios.json') ? 'ok' : '', 'ok', 'rmf_municipios.json existe (GEO.R1).'),
        checkContains('geo_r1_geojson_ceara_mun',   file_exists($root . '/assets/geo/ceara_mun.json') ? 'ok' : '', 'ok', 'ceara_mun.json existe (GEO.R1).'),
        checkContains('geo_r1_geojson_ceara_reg',   file_exists($root . '/assets/geo/ceara_regioes.json') ? 'ok' : '', 'ok', 'ceara_regioes.json existe (GEO.R1).'),
        checkContains('geo_r1_layer_selector',      readFileSafe($root . '/views/mapa_vendas.html'), 'geoLayerSelector', 'mapa_vendas.html tem geoLayerSelector (GEO.R1).'),
        checkContains('geo_r1_scope_selector',      readFileSafe($root . '/views/mapa_vendas.html'), 'geoScopeSelector', 'mapa_vendas.html tem geoScopeSelector (GEO.R1).'),
        checkContains('geo_r1_metric_selector',     readFileSafe($root . '/views/mapa_vendas.html'), 'geoMetricSelector', 'mapa_vendas.html tem geoMetricSelector (GEO.R1).'),
        checkContains('geo_r1_geometry_missing_el', readFileSafe($root . '/views/mapa_vendas.html'), 'geoGeometryMissing', 'mapa_vendas.html tem empty state geoGeometryMissing (GEO.R1).'),
        checkContains('geo_r1_js_geo_layers',       readFileSafe($root . '/assets/js/biomie-map.js'), 'GEO_LAYERS', 'biomie-map.js tem GEO_LAYERS registry (GEO.R1).'),
        checkContains('geo_r1_js_apply_layer',      readFileSafe($root . '/assets/js/biomie-map.js'), 'applyGeoLayer', 'biomie-map.js tem applyGeoLayer (GEO.R1).'),
        checkContains('geo_r1_js_active_layer',     readFileSafe($root . '/assets/js/biomie-map.js'), '_activeLayer', 'biomie-map.js tem _activeLayer state (GEO.R1).'),
        checkContains('geo_r1_js_fortaleza_intact', readFileSafe($root . '/assets/js/biomie-map.js'), 'fortaleza_bairros', 'biomie-map.js referencia fortaleza_bairros — camada preservada (GEO.R1).'),
        checkNotContains('geo_r1_no_viacep_api',    readFileSafe($root . '/api/geo_sales_layer.php'), 'viacep', 'geo_sales_layer.php sem ViaCEP em runtime (GEO.R1).'),
        checkContains('geo_r1_geometry_audit',      file_exists($root . '/tools/dev/geo_geometry_audit.php') ? 'ok' : '', 'ok', 'geo_geometry_audit.php existe (GEO.R1).'),
        checkContains('geo_r1_consistency_audit',   file_exists($root . '/tools/dev/geo_layer_consistency_audit.php') ? 'ok' : '', 'ok', 'geo_layer_consistency_audit.php existe (GEO.R1).'),
        checkContains('geo_r1_current_audit',       file_exists($root . '/tools/dev/geo_current_map_audit.php') ? 'ok' : '', 'ok', 'geo_current_map_audit.php existe (GEO.R1).'),

        // GEO.R1.1 — Geometrias Oficiais IBGE/IPECE
        checkContains('geo_r1_1_ibge_importer',     file_exists($root . '/tools/dev/geo_import_ibge_ce_municipios.php') ? 'ok' : '', 'ok', 'geo_import_ibge_ce_municipios.php existe (GEO.R1.1).'),
        checkContains('geo_r1_1_ipece_importer',    file_exists($root . '/tools/dev/geo_import_ipece_regioes.php') ? 'ok' : '', 'ok', 'geo_import_ipece_regioes.php existe (GEO.R1.1).'),
        checkContains('geo_r1_1_coverage_audit',    file_exists($root . '/tools/dev/geo_official_geometry_coverage_audit.php') ? 'ok' : '', 'ok', 'geo_official_geometry_coverage_audit.php existe (GEO.R1.1).'),
        checkContains('geo_r1_1_ibge_fix_migration',file_exists($root . '/database/migrations/Version20260530200001_GeoTerritoryCeIbgeFix.php') ? 'ok' : '', 'ok', 'Migration GeoTerritoryCeIbgeFix existe (GEO.R1.1).'),
        checkContains('geo_r1_1_ceara_mun_184',     (function() use ($root) {
            $f = $root.'/assets/geo/ceara_mun.json';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f),true);
            return count($d['features']??[]) >= 184 ? 'ok' : '';
        })(), 'ok', 'ceara_mun.json tem ≥184 features IBGE (GEO.R1.1).'),
        checkContains('geo_r1_1_ceara_mun_source',  (function() use ($root) {
            $f = $root.'/assets/geo/ceara_mun.json';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f),true);
            return str_contains($d['metadata']['source']??'','IBGE') ? 'ok' : '';
        })(), 'ok', 'ceara_mun.json source=IBGE oficial (GEO.R1.1).'),
        checkContains('geo_r1_1_rmf_19',            (function() use ($root) {
            $f = $root.'/assets/geo/rmf_municipios.json';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f),true);
            return count($d['features']??[]) === 19 ? 'ok' : '';
        })(), 'ok', 'rmf_municipios.json tem 19 features IBGE-derivadas (GEO.R1.1).'),
        checkContains('geo_r1_1_rmf_derived',       (function() use ($root) {
            $f = $root.'/assets/geo/rmf_municipios.json';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f),true);
            return str_contains(strtolower($d['metadata']['derived_from']??''),'ceara_mun') ? 'ok' : '';
        })(), 'ok', 'rmf_municipios.json derivado de ceara_mun.json (GEO.R1.1).'),
        checkContains('geo_r1_1_regioes_14',        (function() use ($root) {
            $f = $root.'/assets/geo/ceara_regioes.json';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f),true);
            return count($d['features']??[]) === 14 ? 'ok' : '';
        })(), 'ok', 'ceara_regioes.json tem 14 regiões (GEO.R1.1).'),
        checkContains('geo_r1_1_fortaleza_intact',   (function() use ($root) {
            $f = $root.'/assets/geo/fortaleza_bairros.json';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f),true);
            return count($d['features']??[]) === 121 ? 'ok' : '';
        })(), 'ok', 'fortaleza_bairros.json intacto com 121 features (GEO.R1.1).'),
        checkNotContains('geo_r1_1_no_approx_ceara', (function() use ($root) {
            $f = $root.'/assets/geo/ceara_mun.json';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f),true);
            return $d['metadata']['source']??'';
        })(), 'centroid_approximation', 'ceara_mun.json sem polígonos de aproximação (GEO.R1.1).'),
        // GEO.R1.2 — Metropolitan map UX expansion
        checkContains('geo_r1_2_apply_layer_labels',  readFileSafe($root . '/assets/js/biomie-map.js'), 'applyLayerLabels', 'biomie-map.js tem applyLayerLabels() para labels dinâmicos por camada (GEO.R1.2).'),
        checkContains('geo_r1_2_fit_layer_bounds',    readFileSafe($root . '/assets/js/biomie-map.js'), 'fitLayerBounds', 'biomie-map.js tem fitLayerBounds() para recentrar a camada (GEO.R1.2).'),
        checkContains('geo_r1_2_geometry_available',  readFileSafe($root . '/assets/js/biomie-map.js'), 'geometryAvailable', 'biomie-map.js usa geometryAvailable para camadas sem GeoJSON (GEO.R1.2).'),
        checkContains('geo_r1_2_fit_max_zoom',        readFileSafe($root . '/assets/js/biomie-map.js'), 'fitMaxZoom', 'biomie-map.js tem fitMaxZoom por camada para fitBounds correto (GEO.R1.2).'),
        checkContains('geo_r1_2_map_fitted_reset',    readFileSafe($root . '/assets/js/biomie-map.js'), '_mapFitted = false', 'applyGeoLayer reseta _mapFitted para novo fitBounds ao trocar camada (GEO.R1.2).'),
        checkContains('geo_r1_2_page_title_id',       readFileSafe($root . '/views/mapa_vendas.html'), 'geoPageTitle', 'mapa_vendas.html tem id=geoPageTitle para título dinâmico (GEO.R1.2).'),
        checkContains('geo_r1_2_areas_label_id',      readFileSafe($root . '/views/mapa_vendas.html'), 'geoKpiAreasLabel', 'mapa_vendas.html tem id=geoKpiAreasLabel para label de KPI (GEO.R1.2).'),
        checkContains('geo_r1_2_top_area_label_id',   readFileSafe($root . '/views/mapa_vendas.html'), 'geoKpiTopAreaLabel', 'mapa_vendas.html tem id=geoKpiTopAreaLabel para label de KPI (GEO.R1.2).'),
        checkContains('geo_r1_2_fit_bounds_btn',      readFileSafe($root . '/views/mapa_vendas.html'), 'fitLayerBoundsBtn', 'mapa_vendas.html tem botão fitLayerBoundsBtn (GEO.R1.2).'),
        checkContains('geo_r1_2_rmf_option',          readFileSafe($root . '/views/mapa_vendas.html'), 'rmf_municipios', 'mapa_vendas.html tem opção rmf_municipios no seletor (GEO.R1.2).'),
        checkContains('geo_r1_2_ceara_option',        readFileSafe($root . '/views/mapa_vendas.html'), 'ceara_municipios', 'mapa_vendas.html tem opção ceara_municipios no seletor (GEO.R1.2).'),
        checkContains('geo_r1_2_geometry_missing_msg',readFileSafe($root . '/views/mapa_vendas.html'), 'geoGeometryMissingMsg', 'mapa_vendas.html tem geoGeometryMissingMsg para empty state por camada (GEO.R1.2).'),
        checkContains('geo_r1_2_fortaleza_intact',    readFileSafe($root . '/assets/js/biomie-map.js'), "'fortaleza_bairros'", 'biomie-map.js preserva camada fortaleza_bairros (GEO.R1.2).'),
        checkNotContains('geo_r1_2_no_rendermap_bug', readFileSafe($root . '/assets/js/biomie-map.js'), 'renderMap()', 'biomie-map.js sem chamada a renderMap() inexistente — bug corrigido (GEO.R1.2).'),
        // GEO.R1.3 — RMF bairros IBGE 2022 ativado
        checkContains('geo_r1_3_probe_exists',        file_exists($root . '/tools/dev/geo_rmf_bairros_source_probe.php') ? 'ok' : '', 'ok', 'geo_rmf_bairros_source_probe.php existe (GEO.R1.3).'),
        checkContains('geo_r1_3_generator_exists',    file_exists($root . '/tools/dev/geo_generate_rmf_bairros.py') ? 'ok' : '', 'ok', 'geo_generate_rmf_bairros.py existe (GEO.R1.3).'),
        checkContains('geo_r1_3_geojson_exists',      file_exists($root . '/assets/geo/rmf_bairros_ibge_2022.geojson') ? 'ok' : '', 'ok', 'rmf_bairros_ibge_2022.geojson gerado (GEO.R1.3).'),
        checkContains('geo_r1_3_geojson_source',      (function() use ($root) {
            $f = $root . '/assets/geo/rmf_bairros_ibge_2022.geojson';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f), true);
            return ($d['metadata']['source'] ?? '') === 'IBGE_BAIRROS_CD2022' ? 'ok' : '';
        })(), 'ok', 'rmf_bairros_ibge_2022.geojson tem source=IBGE_BAIRROS_CD2022 (GEO.R1.3).'),
        checkContains('geo_r1_3_geojson_194',         (function() use ($root) {
            $f = $root . '/assets/geo/rmf_bairros_ibge_2022.geojson';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f), true);
            return count($d['features'] ?? []) >= 180 ? 'ok' : '';
        })(), 'ok', 'rmf_bairros_ibge_2022.geojson tem ≥180 features RMF (GEO.R1.3).'),
        checkContains('geo_r1_3_layer_active',        readFileSafe($root . '/assets/js/biomie-map.js'), "'grande_fortaleza_bairros'", 'biomie-map.js tem camada grande_fortaleza_bairros (GEO.R1.3).'),
        checkContains('geo_r1_3_geometry_true',       readFileSafe($root . '/assets/js/biomie-map.js'), 'geometryAvailable: true', 'grande_fortaleza_bairros tem geometryAvailable: true (GEO.R1.3).'),
        checkContains('geo_r1_3_metro_geojson_url',   readFileSafe($root . '/assets/js/biomie-map.js'), 'rmf_bairros_ibge_2022.geojson', 'biomie-map.js aponta para rmf_bairros_ibge_2022.geojson (GEO.R1.3).'),
        checkContains('geo_r1_3_join_key_lookup',     readFileSafe($root . '/assets/js/biomie-map.js'), 'props.join_key', 'biomie-map.js inclui join_key no lookup de features (GEO.R1.3).'),
        checkContains('geo_r1_3_metro_layer_param',   readFileSafe($root . '/assets/js/biomie-map.js'), "'grande_fortaleza_bairros'", 'biomie-map.js passa layer=grande_fortaleza_bairros na API (GEO.R1.3).'),
        checkContains('geo_r1_3_api_metro_detect',    readFileSafe($root . '/api/sales_by_neighborhood.php'), 'isMetroLayer', 'sales_by_neighborhood.php detecta camada metro (GEO.R1.3).'),
        checkContains('geo_r1_3_api_compound_key',    readFileSafe($root . '/api/sales_by_neighborhood.php'), 'cidade_key_raw', 'sales_by_neighborhood.php retorna compound key para metro (GEO.R1.3).'),
        checkContains('geo_r1_3_summary_metro',       readFileSafe($root . '/api/geo_summary.php'), 'isMetroLayer', 'geo_summary.php detecta camada metro (GEO.R1.3).'),
        checkContains('geo_r1_3_fortaleza_intact',    readFileSafe($root . '/assets/js/biomie-map.js'), "'fortaleza_bairros'", 'fortaleza_bairros preservada intacta (GEO.R1.3).'),
        checkContains('geo_r1_3_source_badge',        readFileSafe($root . '/assets/js/biomie-map.js'), 'sourceBadge', 'biomie-map.js tem sourceBadge IBGE na camada (GEO.R1.3).'),
        checkContains('geo_r1_3_fallback_layer',      readFileSafe($root . '/assets/js/biomie-map.js'), 'fallbackLayer', 'biomie-map.js tem fallbackLayer para municípios sem bairros (GEO.R1.3).'),
        checkContains('geo_r1_3_ibge_tooltip',        readFileSafe($root . '/assets/js/biomie-map.js'), 'IBGE_BAIRROS_CD2022', 'biomie-map.js exibe badge IBGE no tooltip (GEO.R1.3).'),
        checkContains('geo_r1_3_selector_no_em_breve',readFileSafe($root . '/views/mapa_vendas.html'), 'Grande Fortaleza · Bairros', 'seletor mostra Grande Fortaleza sem "(em breve)" (GEO.R1.3).'),
        checkNotContains('geo_r1_3_no_em_breve',      readFileSafe($root . '/views/mapa_vendas.html'), 'em breve', 'seletor não tem "(em breve)" (GEO.R1.3).'),
        checkContains('geo_r1_3_generator_ibge_url',  readFileSafe($root . '/tools/dev/geo_generate_rmf_bairros.py'), 'CE_bairros_CD2022.zip', 'Gerador tem URL canônica IBGE CE_bairros_CD2022.zip (GEO.R1.3).'),
        checkContains('geo_r1_3_generator_bairro_key',readFileSafe($root . '/tools/dev/geo_generate_rmf_bairros.py'), 'bairro_key', 'Gerador produz campo bairro_key compatível com BIomie (GEO.R1.3).'),
        checkContains('geo_r1_3_generator_join_key',  readFileSafe($root . '/tools/dev/geo_generate_rmf_bairros.py'), 'join_key', 'Gerador produz campo join_key para matching de camada metro (GEO.R1.3).'),
        checkContains('geo_r1_3_docs_exist',          file_exists($root . '/docs/GEO_R1_3_GRANDE_FORTALEZA_BAIRROS_IBGE_2022.md') ? 'ok' : '', 'ok', 'Documentação GEO_R1_3 existe (GEO.R1.3).'),

        // GEO.R1.4 — Ceará Municípios Oficial
        checkContains('geo_r1_4_generator',       file_exists($root . '/tools/dev/geo_generate_ceara_municipios.py') ? 'ok' : '', 'ok', 'geo_generate_ceara_municipios.py existe (GEO.R1.4).'),
        checkContains('geo_r1_4_probe',           file_exists($root . '/tools/dev/geo_ceara_municipios_source_probe.php') ? 'ok' : '', 'ok', 'geo_ceara_municipios_source_probe.php existe (GEO.R1.4).'),
        checkContains('geo_r1_4_geojson',         file_exists($root . '/assets/geo/ceara_municipios_oficial.geojson') ? 'ok' : '', 'ok', 'ceara_municipios_oficial.geojson existe (GEO.R1.4).'),
        checkContains('geo_r1_4_geojson_184',     (function() use ($root) {
            $f = $root . '/assets/geo/ceara_municipios_oficial.geojson';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f), true);
            return count($d['features'] ?? []) === 184 ? 'ok' : '';
        })(), 'ok', 'ceara_municipios_oficial.geojson tem 184 features (GEO.R1.4).'),
        checkContains('geo_r1_4_js_oficial',      readFileSafe($root . '/assets/js/biomie-map.js'), 'ceara_municipios_oficial.geojson', 'biomie-map.js usa ceara_municipios_oficial.geojson (GEO.R1.4).'),
        checkContains('geo_r1_4_multicity_api',   readFileSafe($root . '/api/sales_by_neighborhood.php'), 'isMultiCityLayer', 'API detecta camadas multi-cidade (GEO.R1.4/R1.5).'),
        checkContains('geo_r1_4_docs',            file_exists($root . '/docs/GEO_R1_4_CEARA_MUNICIPIOS_OFICIAIS.md') ? 'ok' : '', 'ok', 'Documentação GEO_R1_4 existe (GEO.R1.4).'),

        // GEO.R1.5 — Regiões Metropolitanas
        checkContains('geo_r1_5_registry',        file_exists($root . '/app/Geo/GeoMetropolitanRegionRegistry.php') ? 'ok' : '', 'ok', 'GeoMetropolitanRegionRegistry.php existe (GEO.R1.5).'),
        checkContains('geo_r1_5_generator',       file_exists($root . '/tools/dev/geo_generate_metropolitan_regions.py') ? 'ok' : '', 'ok', 'geo_generate_metropolitan_regions.py existe (GEO.R1.5).'),
        checkContains('geo_r1_5_probe',           file_exists($root . '/tools/dev/geo_metropolitan_regions_probe.php') ? 'ok' : '', 'ok', 'geo_metropolitan_regions_probe.php existe (GEO.R1.5).'),
        checkContains('geo_r1_5_sobral_geojson',  file_exists($root . '/assets/geo/metro_sobral_municipios.geojson') ? 'ok' : '', 'ok', 'metro_sobral_municipios.geojson existe (GEO.R1.5).'),
        checkContains('geo_r1_5_cariri_geojson',  file_exists($root . '/assets/geo/metro_cariri_municipios.geojson') ? 'ok' : '', 'ok', 'metro_cariri_municipios.geojson existe (GEO.R1.5).'),
        checkContains('geo_r1_5_todas_geojson',   file_exists($root . '/assets/geo/metro_todas_municipios.geojson') ? 'ok' : '', 'ok', 'metro_todas_municipios.geojson existe (GEO.R1.5).'),
        checkContains('geo_r1_5_js_sobral',       readFileSafe($root . '/assets/js/biomie-map.js'), 'metro_sobral_municipios', 'biomie-map.js tem camada metro_sobral_municipios (GEO.R1.5).'),
        checkContains('geo_r1_5_js_cariri',       readFileSafe($root . '/assets/js/biomie-map.js'), 'metro_cariri_municipios', 'biomie-map.js tem camada metro_cariri_municipios (GEO.R1.5).'),
        checkContains('geo_r1_5_js_todas',        readFileSafe($root . '/assets/js/biomie-map.js'), 'metro_todas_municipios', 'biomie-map.js tem camada metro_todas_municipios (GEO.R1.5).'),
        checkContains('geo_r1_5_html_optgroup',   readFileSafe($root . '/views/mapa_vendas.html'), '<optgroup', 'mapa_vendas.html usa optgroup no seletor (GEO.R1.5).'),
        checkContains('geo_r1_5_docs',            file_exists($root . '/docs/GEO_R1_5_REGIOES_METROPOLITANAS_CE.md') ? 'ok' : '', 'ok', 'Documentação GEO_R1_5 existe (GEO.R1.5).'),

        // GEO.R1.6 — Regiões IPECE oficiais
        checkContains('geo_r1_6_generator',       file_exists($root . '/tools/dev/geo_generate_ceara_regioes_ipece.py') ? 'ok' : '', 'ok', 'geo_generate_ceara_regioes_ipece.py existe (GEO.R1.6).'),
        checkContains('geo_r1_6_probe',           file_exists($root . '/tools/dev/geo_ceara_regioes_ipece_probe.php') ? 'ok' : '', 'ok', 'geo_ceara_regioes_ipece_probe.php existe (GEO.R1.6).'),
        checkContains('geo_r1_6_geojson',         file_exists($root . '/assets/geo/ceara_regioes_ipece_oficial.geojson') ? 'ok' : '', 'ok', 'ceara_regioes_ipece_oficial.geojson existe (GEO.R1.6).'),
        checkContains('geo_r1_6_geojson_14',      (function() use ($root) {
            $f = $root . '/assets/geo/ceara_regioes_ipece_oficial.geojson';
            if (!file_exists($f)) return '';
            $d = json_decode(file_get_contents($f), true);
            return count($d['features'] ?? []) === 14 ? 'ok' : '';
        })(), 'ok', 'ceara_regioes_ipece_oficial.geojson tem 14 regiões (GEO.R1.6).'),
        checkNotContains('geo_r1_6_no_bbox',      (function() use ($root) {
            $f = $root . '/assets/geo/ceara_regioes_ipece_oficial.geojson';
            if (!file_exists($f)) return '';
            return file_get_contents($f);
        })(), 'ibge_bbox_derivado', 'ceara_regioes_ipece_oficial.geojson sem bbox derivado (GEO.R1.6).'),
        checkContains('geo_r1_6_js_url',          readFileSafe($root . '/assets/js/biomie-map.js'), 'ceara_regioes_ipece_oficial.geojson', 'biomie-map.js usa ceara_regioes_ipece_oficial.geojson (GEO.R1.6).'),
        checkContains('geo_r1_6_docs',            file_exists($root . '/docs/GEO_R1_6_REGIOES_PLANEJAMENTO_IPECE_OFICIAIS.md') ? 'ok' : '', 'ok', 'Documentação GEO_R1_6 existe (GEO.R1.6).'),

        // GEO.R1.7 — Mapa Composto Inteligente do Ceará
        checkContains('geo_r1_7_composite_option',   readFileSafe($root . '/views/mapa_vendas.html'), 'mapa_composto_ceara', 'mapa_vendas.html tem opção mapa_composto_ceara (GEO.R1.7).'),
        checkContains('geo_r1_7_visao_completa',     readFileSafe($root . '/views/mapa_vendas.html'), 'Visão completa do Ceará', 'mapa_vendas.html tem "Visão completa do Ceará" como opção padrão (GEO.R1.7).'),
        checkNotContains('geo_r1_7_no_regioes_plan', readFileSafe($root . '/views/mapa_vendas.html'), 'ceara_regioes', 'Regiões de Planejamento removidas do seletor principal (GEO.R1.7).'),
        checkContains('geo_r1_7_foco_mapa',          readFileSafe($root . '/views/mapa_vendas.html'), 'Foco do mapa', 'Label "Foco do mapa" existe no seletor (GEO.R1.7).'),
        checkContains('geo_r1_7_composite_dispatch', readFileSafe($root . '/assets/js/biomie-map.js'), 'return initCompositeMap()', 'loadCommercialData() despacha para initCompositeMap() (GEO.R1.7).'),
        checkContains('geo_r1_7_init_composite',     readFileSafe($root . '/assets/js/biomie-map.js'), 'initCompositeMap', 'biomie-map.js tem initCompositeMap() (GEO.R1.7).'),
        checkContains('geo_r1_7_territory_type',     readFileSafe($root . '/assets/js/biomie-map.js'), 'territory_type', 'biomie-map.js usa territory_type para classificação territorial (GEO.R1.7).'),
        checkContains('geo_r1_7_territory_colors',   readFileSafe($root . '/assets/js/biomie-map.js'), 'TERRITORY_COLORS', 'biomie-map.js tem paleta TERRITORY_COLORS por tipo (GEO.R1.7).'),
        checkContains('geo_r1_7_composite_sales_api',file_exists($root . '/api/geo_composite_sales.php') ? 'ok' : '', 'ok', 'api/geo_composite_sales.php existe (GEO.R1.7).'),
        checkContains('geo_r1_7_composite_legend',   readFileSafe($root . '/assets/js/biomie-map.js'), 'renderCompositeLegend', 'biomie-map.js tem renderCompositeLegend() (GEO.R1.7).'),
        checkContains('geo_r1_7_composite_ranking',  readFileSafe($root . '/assets/js/biomie-map.js'), 'renderCompositeRanking', 'biomie-map.js tem renderCompositeRanking() (GEO.R1.7).'),
        checkContains('geo_r1_7_municipio_nome',     readFileSafe($root . '/assets/js/biomie-map.js'), 'municipio_nome', 'biomie-map.js usa municipio_nome real da feature — correção bug Olho d\'Água/Eusébio (GEO.R1.7).'),
        checkContains('geo_r1_7_territory_label',    readFileSafe($root . '/assets/js/biomie-map.js'), 'getFeatureTerritoryLabel', 'biomie-map.js tem getFeatureTerritoryLabel() para label bairro/município correto (GEO.R1.7).'),
        checkContains('geo_r1_7_geojson_cache',      readFileSafe($root . '/assets/js/biomie-map.js'), '_geoJsonCache', 'biomie-map.js tem cache de GeoJSON (_geoJsonCache) para não recarregar entre filtros (GEO.R1.7).'),
        checkContains('geo_r1_7_rmf_with_bairros',   readFileSafe($root . '/assets/js/biomie-map.js'), 'RMF_WITH_BAIRROS_CODES', 'biomie-map.js tem RMF_WITH_BAIRROS_CODES para excluir municípios detalhados do polígono municipal (GEO.R1.7).'),
        checkContains('geo_r1_7_probe',              file_exists($root . '/tools/dev/geo_composite_map_probe.php') ? 'ok' : '', 'ok', 'geo_composite_map_probe.php existe (GEO.R1.7).'),
        checkContains('geo_r1_7_docs',               file_exists($root . '/docs/GEO_R1_7_MAPA_COMPOSTO_INTELIGENTE_CEARA.md') ? 'ok' : '', 'ok', 'Documentação GEO_R1_7 existe (GEO.R1.7).'),

        // GEO.R1.8 — Mapa Comercial Inteligente
        checkContains('geo_r1_8_focus_chips',        readFileSafe($root . '/views/mapa_vendas.html'), 'geoLayerSelector', 'mapa_vendas.html tem geoLayerSelector — seletor de camada territorial (GEO.R1.8.1 superseded by GEO.R1.9.2).'),
        checkContains('geo_r1_8_mode_chips',         readFileSafe($root . '/views/mapa_vendas.html'), 'geoModeChips', 'mapa_vendas.html tem geoModeChips — chips de modo visual (GEO.R1.8.1).'),
        checkContains('geo_r1_8_drawer_tabs',        readFileSafe($root . '/views/mapa_vendas.html'), 'geoDrawerTabs', 'mapa_vendas.html tem geoDrawerTabs — Territory 360 stub (GEO.R1.8.2).'),
        checkContains('geo_r1_8_insights_panel',     readFileSafe($root . '/views/mapa_vendas.html'), 'geoInsightsCard', 'mapa_vendas.html tem geoInsightsCard — painel de insights (GEO.R1.8.1).'),
        checkContains('geo_r1_8_export_csv',         readFileSafe($root . '/views/mapa_vendas.html'), 'geoExportCsvBtn', 'mapa_vendas.html tem geoExportCsvBtn — exportação CSV (GEO.R1.8).'),
        checkContains('geo_r1_8_geo_features',       readFileSafe($root . '/assets/js/biomie-map.js'), 'GEO_FEATURES', 'biomie-map.js tem GEO_FEATURES com feature flags (GEO.R1.8).'),
        checkContains('geo_r1_8_insights_fn',        readFileSafe($root . '/assets/js/biomie-map.js'), 'generateInsights', 'biomie-map.js tem generateInsights() para insights automáticos (GEO.R1.8.1).'),
        checkContains('geo_r1_8_export_fn',          readFileSafe($root . '/assets/js/biomie-map.js'), 'exportRankingCsv', 'biomie-map.js tem exportRankingCsv() para exportação (GEO.R1.8).'),
        checkContains('geo_r1_8_focus_chips_bind',   readFileSafe($root . '/assets/js/biomie-map.js'), 'bindFocusChips', 'biomie-map.js tem bindFocusChips() para chips de foco (GEO.R1.8.1).'),
        checkContains('geo_r1_8_panel_subtitle_fix', readFileSafe($root . '/assets/js/biomie-map-panel.js'), 'Ceará', 'biomie-map-panel.js corrigido — subtitle usa município real + Ceará (GEO.R1.8.1).'),
        checkNotContains('geo_r1_8_no_fortaleza_slash', readFileSafe($root . '/assets/js/biomie-map-panel.js'), 'Fortaleza / ${city}', 'biomie-map-panel.js sem subtitle hardcoded "Fortaleza / " (GEO.R1.8.1).'),
        checkContains('geo_r1_8_css_focus_chips',    readFileSafe($root . '/assets/css/biomie-map.css'), 'geo-focus-chip', 'biomie-map.css tem estilos geo-focus-chip (GEO.R1.8.1).'),
        checkContains('geo_r1_8_css_mode_chips',     readFileSafe($root . '/assets/css/biomie-map.css'), 'geo-mode-chip', 'biomie-map.css tem estilos geo-mode-chip (GEO.R1.8.1).'),
        checkContains('geo_r1_8_css_insights',       readFileSafe($root . '/assets/css/biomie-map.css'), 'geo-insights-card', 'biomie-map.css tem estilo geo-insights-card (GEO.R1.8.1).'),
        checkContains('geo_r1_8_opportunities_api',  file_exists($root . '/api/geo_opportunities.php') ? 'ok' : '', 'ok', 'api/geo_opportunities.php existe (GEO.R1.8.4).'),
        checkContains('geo_r1_8_ci_probe',           file_exists($root . '/tools/dev/geo_commercial_intelligence_probe.php') ? 'ok' : '', 'ok', 'geo_commercial_intelligence_probe.php existe (GEO.R1.8).'),
        checkContains('geo_r1_8_docs',               file_exists($root . '/docs/GEO_R1_8_MAPA_COMERCIAL_INTELIGENTE.md') ? 'ok' : '', 'ok', 'Documentação GEO_R1_8 existe (GEO.R1.8).'),

        // GEO.R1.8.2 — Territory 360 tabs reais
        checkContains('geo_r1_82_tab_evolucao',      readFileSafe($root . '/views/mapa_vendas.html'), 'data-tab="evolucao"', 'mapa_vendas.html tem tab Evolução (GEO.R1.8.2).'),
        checkContains('geo_r1_82_tab_acoes',         readFileSafe($root . '/views/mapa_vendas.html'), 'data-tab="acoes"', 'mapa_vendas.html tem tab Ações (GEO.R1.8.2).'),
        checkContains('geo_r1_82_panel_evolucao',    readFileSafe($root . '/views/mapa_vendas.html'), 'data-panel="evolucao"', 'mapa_vendas.html tem painel data-panel="evolucao" (GEO.R1.8.2).'),
        checkContains('geo_r1_82_activate_tab',      readFileSafe($root . '/assets/js/biomie-map.js'), 'activateDrawerTab', 'biomie-map.js tem activateDrawerTab() real (GEO.R1.8.2).'),
        checkContains('geo_r1_82_load_evolution',    readFileSafe($root . '/assets/js/biomie-map.js'), 'loadEvolutionData', 'biomie-map.js tem loadEvolutionData() (GEO.R1.8.2).'),
        checkContains('geo_r1_82_territory_actions', readFileSafe($root . '/assets/js/biomie-map.js'), 'renderTerritoryActions', 'biomie-map.js tem renderTerritoryActions() (GEO.R1.8.2).'),
        checkContains('geo_r1_82_evolution_api',     file_exists($root . '/api/geo_territory_evolution.php') ? 'ok' : '', 'ok', 'api/geo_territory_evolution.php existe (GEO.R1.8.2).'),

        // GEO.R1.8.3 — Data Quality
        checkContains('geo_r1_83_data_quality_api',  file_exists($root . '/api/geo_data_quality.php') ? 'ok' : '', 'ok', 'api/geo_data_quality.php existe (GEO.R1.8.3).'),

        // GEO.R1.8.5 — Heatmap
        checkContains('geo_r1_85_heatmap_flag',      readFileSafe($root . '/assets/js/biomie-map.js'), 'heatmap:       true', 'GEO_FEATURES.heatmap = true (GEO.R1.8.5 ativo).'),
        checkContains('geo_r1_85_heatmap_fn',        readFileSafe($root . '/assets/js/biomie-map.js'), 'enableHeatmapMode', 'biomie-map.js tem enableHeatmapMode() (GEO.R1.8.5).'),
        checkContains('geo_r1_85_centroid_fn',       readFileSafe($root . '/assets/js/biomie-map.js'), 'polygonCentroid', 'biomie-map.js tem polygonCentroid() para centroides (GEO.R1.8.5).'),
        checkContains('geo_r1_85_leaflet_heat',      readFileSafe($root . '/views/mapa_vendas.html'), 'leaflet-heat', 'mapa_vendas.html carrega Leaflet.heat (GEO.R1.8.5).'),
        checkContains('geo_r1_85_360_probe',         file_exists($root . '/tools/dev/geo_territory_360_probe.php') ? 'ok' : '', 'ok', 'geo_territory_360_probe.php existe (GEO.R1.8.2/R1.8.5).'),

        // GEO.R1.8.6 — Seleção de área + comparação
        checkContains('geo_r1_86_area_flag',         readFileSafe($root . '/assets/js/biomie-map.js'), 'areaSelection: true', 'GEO_FEATURES.areaSelection = true (GEO.R1.8.6).'),
        checkContains('geo_r1_86_comparison_flag',   readFileSafe($root . '/assets/js/biomie-map.js'), 'comparison:    true', 'GEO_FEATURES.comparison = true (GEO.R1.8.6).'),
        checkContains('geo_r1_86_area_api',          file_exists($root . '/api/geo_area_selection.php') ? 'ok' : '', 'ok', 'api/geo_area_selection.php existe (GEO.R1.8.6).'),
        checkContains('geo_r1_86_compare_api',       file_exists($root . '/api/geo_compare_territories.php') ? 'ok' : '', 'ok', 'api/geo_compare_territories.php existe (GEO.R1.8.6).'),
        checkContains('geo_r1_86_comparison_bar',    readFileSafe($root . '/views/mapa_vendas.html'), 'geoComparisonBar', 'mapa_vendas.html tem geoComparisonBar (GEO.R1.8.6).'),
        checkContains('geo_r1_86_comparison_overlay',readFileSafe($root . '/views/mapa_vendas.html'), 'geoComparisonOverlay', 'mapa_vendas.html tem geoComparisonOverlay (GEO.R1.8.6).'),
        checkContains('geo_r1_86_add_comparison_btn',readFileSafe($root . '/views/mapa_vendas.html'), 'geoAddToComparisonBtn', 'mapa_vendas.html tem geoAddToComparisonBtn no drawer (GEO.R1.8.6).'),
        checkContains('geo_r1_86_area_result',       readFileSafe($root . '/views/mapa_vendas.html'), 'geoAreaResultPanel', 'mapa_vendas.html tem geoAreaResultPanel (GEO.R1.8.6).'),
        checkContains('geo_r1_86_leaflet_draw',      readFileSafe($root . '/views/mapa_vendas.html'), 'leaflet.draw', 'mapa_vendas.html carrega Leaflet.draw (GEO.R1.8.6).'),
        checkContains('geo_r1_86_add_to_comparison', readFileSafe($root . '/assets/js/biomie-map.js'), 'addToComparison', 'biomie-map.js tem addToComparison() (GEO.R1.8.6).'),
        checkContains('geo_r1_86_render_grid',       readFileSafe($root . '/assets/js/biomie-map.js'), 'renderComparisonGrid', 'biomie-map.js tem renderComparisonGrid() (GEO.R1.8.6).'),
        checkContains('geo_r1_86_process_area',      readFileSafe($root . '/assets/js/biomie-map.js'), 'processAreaSelection', 'biomie-map.js tem processAreaSelection() (GEO.R1.8.6).'),
        checkContains('geo_r1_86_area_probe',        file_exists($root . '/tools/dev/geo_area_comparison_probe.php') ? 'ok' : '', 'ok', 'geo_area_comparison_probe.php existe (GEO.R1.8.6).'),

        // GEO.R1.9 — Mapa Comercial Auditável
        checkContains('geo_r1_9_invoices_api',       file_exists($root . '/api/geo_territory_invoices.php') ? 'ok' : '', 'ok', 'api/geo_territory_invoices.php existe (GEO.R1.9).'),
        checkContains('geo_r1_9_cnaes_api',          file_exists($root . '/api/geo_territory_cnaes.php') ? 'ok' : '', 'ok', 'api/geo_territory_cnaes.php existe — fix CNAEs vazios (GEO.R1.9).'),
        checkContains('geo_r1_9_coverage_api',       file_exists($root . '/api/geo_sales_coverage_audit.php') ? 'ok' : '', 'ok', 'api/geo_sales_coverage_audit.php existe (GEO.R1.9).'),
        checkContains('geo_r1_9_notas_tab',          readFileSafe($root . '/views/mapa_vendas.html'), 'data-tab="notas"', 'Drawer tem aba Notas (GEO.R1.9).'),
        checkContains('geo_r1_9_invoices_panel',     readFileSafe($root . '/views/mapa_vendas.html'), 'geoInvoicesPanel', 'Drawer tem geoInvoicesPanel (GEO.R1.9).'),
        checkContains('geo_r1_9_load_invoices_fn',   readFileSafe($root . '/assets/js/biomie-map.js'), 'loadTerritoryInvoices', 'biomie-map.js tem loadTerritoryInvoices() (GEO.R1.9).'),
        checkContains('geo_r1_9_load_cnaes_fn',      readFileSafe($root . '/assets/js/biomie-map.js'), 'loadTerritoryCnaes', 'biomie-map.js tem loadTerritoryCnaes() — fix CNAEs (GEO.R1.9).'),
        checkContains('geo_r1_9_build_params',       readFileSafe($root . '/assets/js/biomie-map.js'), 'buildTerritoryParams', 'biomie-map.js tem buildTerritoryParams() (GEO.R1.9).'),
        checkContains('geo_r1_9_smart_labels',       readFileSafe($root . '/assets/js/biomie-map.js'), 'GeoSmartLabels', 'biomie-map.js tem GeoSmartLabels (GEO.R1.9).'),
        checkContains('geo_r1_9_should_show_label',  readFileSafe($root . '/assets/js/biomie-map.js'), 'shouldShowLabel', 'GeoSmartLabels.shouldShowLabel() presente (GEO.R1.9).'),
        checkContains('geo_r1_9_css_label_fort',     readFileSafe($root . '/assets/css/biomie-map.css'), 'geo-label-fortaleza', 'CSS geo-label-fortaleza existe (GEO.R1.9).'),
        checkContains('geo_r1_9_coverage_fn',        readFileSafe($root . '/assets/js/biomie-map.js'), 'loadCoverageAuditPanel', 'loadCoverageAuditPanel() presente (GEO.R1.9).'),
        checkContains('geo_r1_9_identity_audit',     file_exists($root . '/tools/dev/geo_territory_identity_audit.php') ? 'ok' : '', 'ok', 'geo_territory_identity_audit.php existe (GEO.R1.9).'),
        checkContains('geo_r1_9_detail_audit',       file_exists($root . '/tools/dev/geo_territory_detail_audit.php') ? 'ok' : '', 'ok', 'geo_territory_detail_audit.php existe (GEO.R1.9).'),

        // GEO.R1.9.1 — Cross-links NF ↔ Mapa ↔ Pedidos
        checkContains('geo_r191_invoice_detail_api',  file_exists($root . '/api/geo_invoice_detail.php') ? 'ok' : '', 'ok', 'api/geo_invoice_detail.php existe (GEO.R1.9.1).'),
        checkContains('geo_r191_cnae_ranking_api',    file_exists($root . '/api/company_cnae_ranking.php') ? 'ok' : '', 'ok', 'api/company_cnae_ranking.php existe (GEO.R1.9.1).'),
        checkContains('geo_r191_expand_nf_btn',       readFileSafe($root . '/assets/js/biomie-map.js'), 'geo-nf-expand-btn', 'biomie-map.js tem geo-nf-expand-btn (GEO.R1.9.1).'),
        checkContains('geo_r191_ver_pedidos_link',   readFileSafe($root . '/assets/js/biomie-map.js'), 'Ver em pedidos', 'biomie-map.js tem link "Ver em pedidos" (GEO.R1.9.1).'),
        checkContains('geo_r191_ver_mapa_nf_table',  readFileSafe($root . '/assets/js/modules/commercial/nf-periodo-table.js'), 'Ver no mapa', 'nf-periodo-table.js tem "Ver no mapa" (GEO.R1.9.1).'),
        checkContains('geo_r191_ver_mapa_pedidos',   readFileSafe($root . '/views/pedidos.html'), 'pedidosMapLink', 'pedidos.html tem link pedidosMapLink (GEO.R1.9.1).'),
        checkContains('geo_r191_cnae_strip_index',   readFileSafe($root . '/views/index.html'), 'indexGeoCnaeStrip', 'index.html tem indexGeoCnaeStrip (GEO.R1.9.1).'),
        checkContains('geo_r191_smoke_probe',         file_exists($root . '/tools/dev/geo_r191_smoke_probe.php') ? 'ok' : '', 'ok', 'geo_r191_smoke_probe.php existe (GEO.R1.9.1).'),

        // GEO.R1.9.2 — Hardening + Bubble Mode
        checkContains('geo_r192_build_territory_params_fix', readFileSafe($root . '/assets/js/biomie-map.js'), 'GEO_R1_9_2_INVOICE_CONTRACT_MISMATCH', 'biomie-map.js tem contract mismatch detection (GEO.R1.9.2).'),
        checkContains('geo_r192_territory_params_clean',     readFileSafe($root . '/assets/js/biomie-map.js'), "['date_from','date_to','seller_id','cnae','scope']", 'buildTerritoryParams não contamina com city/neighborhood (GEO.R1.9.2).'),
        checkContains('geo_r192_sanitize_geo_filters',       readFileSafe($root . '/assets/js/biomie-map.js'), 'sanitizeGeoFiltersForLayer', 'sanitizeGeoFiltersForLayer presente (GEO.R1.9.2).'),
        checkContains('geo_r192_sem_cidade_excluded',        readFileSafe($root . '/assets/js/biomie-map.js'), "cidade_key !== 'sem_cidade'", 'sem_cidade excluído do melhor território (GEO.R1.9.2).'),
        checkContains('geo_r192_bubble_mode',                readFileSafe($root . '/assets/js/biomie-map.js'), 'GeoBubbleMode', 'GeoBubbleMode presente (GEO.R1.9.2).'),
        checkContains('geo_r192_map_mode_selector',          readFileSafe($root . '/views/mapa_vendas.html'), 'geoMapModeSelector', 'views/mapa_vendas.html tem geoMapModeSelector (GEO.R1.9.2).'),
        checkContains('geo_r192_chips_removed',              strpos(readFileSafe($root . '/views/mapa_vendas.html'), 'geo-focus-chips') === false ? 'ok' : '', 'ok', 'Chips redundantes removidos de mapa_vendas.html (GEO.R1.9.2).'),
        checkContains('geo_r192_resolve_territory_display',  readFileSafe($root . '/assets/js/biomie-map-panel.js'), 'fortaleza_bairro', 'biomie-map-panel.js tem resolveTerritoryDisplay (GEO.R1.9.2).'),
        checkContains('geo_r192_bubble_css',                 readFileSafe($root . '/assets/css/biomie-map.css'), 'geo-bubble-marker', 'CSS geo-bubble-marker existe (GEO.R1.9.2).'),
        checkContains('geo_r192_mart_lookup_invoices',       readFileSafe($root . '/api/geo_territory_invoices.php'), 'useMartLookup', 'geo_territory_invoices.php usa mart lookup (GEO.R1.9.2).'),

        // GEO.R1.9.3 — Visual Polish + Presets Executivos
        checkContains('geo_r193_presets_bar',        readFileSafe($root . '/views/mapa_vendas.html'), 'geoPresetsBar', 'mapa_vendas.html tem geoPresetsBar (GEO.R1.9.3).'),
        checkContains('geo_r193_preset_top_revenue', readFileSafe($root . '/views/mapa_vendas.html'), 'top_revenue', 'mapa_vendas.html tem preset top_revenue (GEO.R1.9.3).'),
        checkContains('geo_r193_preset_sem_bairro',  readFileSafe($root . '/views/mapa_vendas.html'), 'sem_bairro', 'mapa_vendas.html tem preset sem_bairro (GEO.R1.9.3).'),
        checkContains('geo_r193_preset_opportunity', readFileSafe($root . '/views/mapa_vendas.html'), 'opportunity', 'mapa_vendas.html tem preset opportunity (GEO.R1.9.3).'),
        checkContains('geo_r193_apply_preset_fn',    readFileSafe($root . '/assets/js/biomie-map.js'), 'applyPreset', 'biomie-map.js tem applyPreset() (GEO.R1.9.3).'),
        checkContains('geo_r193_bind_presets_fn',    readFileSafe($root . '/assets/js/biomie-map.js'), 'bindPresets', 'biomie-map.js tem bindPresets() (GEO.R1.9.3).'),
        checkContains('geo_r193_dynamic_legend',     readFileSafe($root . '/assets/js/biomie-map.js'), 'renderMapModeLegend', 'biomie-map.js tem renderMapModeLegend() (GEO.R1.9.3).'),
        checkContains('geo_r193_quality_mode',       readFileSafe($root . '/assets/js/biomie-map.js'), "'quality'", "applyMapMode suporta 'quality' (GEO.R1.9.3)."),
        checkContains('geo_r193_label_cap',          readFileSafe($root . '/assets/js/biomie-map.js'), '_maxLabels', 'GeoSmartLabels tem _maxLabels cap (GEO.R1.9.3).'),
        checkContains('geo_r193_composite_cache',    readFileSafe($root . '/assets/js/biomie-map.js'), 'compositeRankingCache', 'biomie-map.js tem compositeRankingCache (GEO.R1.9.3).'),
        checkContains('geo_r193_preset_css',         readFileSafe($root . '/assets/css/biomie-map.css'), 'geo-preset-btn', 'CSS geo-preset-btn existe (GEO.R1.9.3).'),
        checkContains('geo_r193_bubble_legend_css',  readFileSafe($root . '/assets/css/biomie-map.css'), 'biomie-map-legend__bubble', 'CSS biomie-map-legend__bubble existe (GEO.R1.9.3).'),

        // GEO.R1.9.4 — Fix compradores + CNAEs em modo composto
        checkContains('geo_r194_load_panel_city',     readFileSafe($root . '/assets/js/biomie-map.js'), 'legacyCity', 'loadPanelData passa city= para APIs legacy (GEO.R1.9.4).'),
        checkContains('geo_r194_panel_uses_geo_cnae', readFileSafe($root . '/assets/js/biomie-map.js'), 'geo_territory_cnaes.php', 'loadPanelData usa geo_territory_cnaes.php (GEO.R1.9.4).'),
        checkContains('geo_r194_buyers_cidade_key',   readFileSafe($root . '/api/top_buyers_by_geo.php'), 'GEO.R1.9.4', 'top_buyers_by_geo.php tem fallback cidade_key (GEO.R1.9.4).'),
        checkContains('geo_r194_cnae_cidade_key',     readFileSafe($root . '/api/sales_by_cnae.php'), 'GEO.R1.9.4', 'sales_by_cnae.php tem fallback cidade_key (GEO.R1.9.4).'),

        // GEO.R1.9.5 — Territory Buyers API
        checkContains('geo_r195_buyers_api',          file_exists($root . '/api/geo_territory_buyers.php') ? 'ok' : '', 'ok', 'api/geo_territory_buyers.php existe (R1.9.5).'),
        checkContains('geo_r195_load_panel_new',      readFileSafe($root . '/assets/js/biomie-map.js'), 'geo_territory_buyers.php', 'loadPanelData usa geo_territory_buyers.php (R1.9.5).'),
        checkContains('geo_r195_cnae_doc_filters',    readFileSafe($root . '/views/mapa_vendas.html'), 'data-geo-cnae-document-type="cnpj"', 'Drawer de compradores CNAE tem filtro Empresas CNPJ.'),
        checkContains('geo_r195_cnae_export_btn',     readFileSafe($root . '/views/mapa_vendas.html'), 'geoExportCnaeBuyersCsvBtn', 'Drawer de compradores CNAE tem botao Exportar CSV.'),
        checkContains('geo_r195_cnae_export_api',     file_exists($root . '/api/geo_territory_cnae_buyers_export.php') ? 'ok' : '', 'ok', 'api/geo_territory_cnae_buyers_export.php existe.'),

        // GEO.R1.9.6 — Rich Tooltip
        checkContains('geo_r196_rich_tooltip',        readFileSafe($root . '/assets/js/biomie-map.js'), 'biomie-map-tooltip--rich', 'Tooltip rico implementado (R1.9.6).'),
        checkContains('geo_r196_rank_map',            readFileSafe($root . '/assets/js/biomie-map.js'), '_rankByJoinKey', 'rank map presente (R1.9.6).'),
        checkContains('geo_r196_tooltip_css',         readFileSafe($root . '/assets/css/biomie-map.css'), 'bmt-header', 'CSS tooltip rico (R1.9.6).'),

        // GEO.R1.9.7 — Enhanced Labels
        checkContains('geo_r197_label_top5',          readFileSafe($root . '/assets/js/biomie-map.js'), 'geo-label-top5', 'labels top5 class (R1.9.7).'),
        checkContains('geo_r197_label_css',           readFileSafe($root . '/assets/css/biomie-map.css'), 'geo-label-top5', 'CSS geo-label-top5 (R1.9.7).'),

        // GEO.R2.0 — Opportunity Map
        checkContains('geo_r20_opp_mode',             readFileSafe($root . '/assets/js/biomie-map.js'), 'loadOpportunityLayer', 'loadOpportunityLayer presente (R2.0).'),
        checkContains('geo_r20_opp_selector',         readFileSafe($root . '/views/mapa_vendas.html'), 'opportunities', 'geoMapModeSelector tem opportunities (R2.0).'),
        checkContains('geo_r20_opp_legend',           readFileSafe($root . '/assets/js/biomie-map.js'), 'Oportunidades Comerciais', 'legenda modo oportunidades (R2.0).'),
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
