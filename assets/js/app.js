(function () {
  'use strict';

  var B = window.BIomie || {};

  // Local aliases for frequently-called utilities (all defined in core/utils.js)
  var formatCurrency      = function (v) { return B.formatCurrency(v); };
  var formatNumber        = function (v) { return B.formatNumber(v); };
  var formatCurrencyShort = function (v) { return B.formatCurrencyShort(v); };
  var escapeHtml          = function (v) { return B.escapeHtml(v); };
  var setText             = function (s, v) { B.setText(s, v); };
  var setHtml             = function (s, v) { B.setHtml(s, v); };
  var formatMetaDate      = function (v) { return B.formatMetaDate(v); };
  var formatOrderDate     = function (r, p) { return B.formatOrderDate(r, p); };
  var getDateRange        = function () { return B.getDateRange(); };
  var buildRangeDates     = function (s, e) { return B.buildRangeDates(s, e); };
  var chartDateLabel      = function (d) { return B.chartDateLabel(d); };
  var applyPresetRange    = function (p) { B.applyPresetRange(p); };

  // ── IIFE-private chart state ──────────────────────────────────────────────

  const charts = new Map();
  const bootstrapState = { dashboardHydrated: false };

  function destroyChart(id) {
    const chart = charts.get(id);
    if (chart) { try { chart.destroy(); } catch (e) { console.warn('Falha ao destruir grafico.', e); } }
    charts.delete(id);
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.destroyChart === 'function') {
      window.BIOMIE_CHARTS.destroyChart(id);
    }
  }

  function chartEmpty(canvasId, message, actionLabel, actionHref) {
    window.BIomieCharts.destroyChart(canvasId);
    const canvas    = document.getElementById(canvasId);
    const container = canvas && canvas.parentElement;
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.renderChartEmptyState === 'function') {
      window.BIOMIE_CHARTS.renderChartEmptyState(container || canvas, message, actionLabel, actionHref);
      return;
    }
    if (!canvas || !container) return;
    canvas.style.display = 'none';
    let empty = container.querySelector('.mag-chart-empty');
    if (!empty) {
      empty = document.createElement('div');
      empty.className = 'mag-chart-empty';
      container.appendChild(empty);
    }
    empty.textContent = message || 'Nenhum dado encontrado para este grafico.';
  }

  function renderChart(canvasId, config, emptyConfig) {
    emptyConfig = emptyConfig || {};
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.renderChartSafe === 'function') {
      const chart = window.BIOMIE_CHARTS.renderChartSafe(canvasId, config, emptyConfig);
      if (chart) charts.set(canvasId, chart); else charts.delete(canvasId);
      return chart;
    }
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return null;
    if (typeof window.Chart.getChart === 'function') {
      const old = window.Chart.getChart(canvas);
      if (old) old.destroy();
    }
    window.BIomieCharts.destroyChart(canvasId);
    const chart = new window.Chart(canvas.getContext('2d'), config);
    charts.set(canvasId, chart);
    return chart;
  }

  function lazyChart(canvasId, renderFn) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    if (!window.IntersectionObserver) { renderFn(); return; }
    const observer = new IntersectionObserver(
      (entries, obs) => {
        entries.forEach((entry) => { if (!entry.isIntersecting) return; renderFn(); obs.unobserve(entry.target); });
      },
      { rootMargin: '100px' }
    );
    observer.observe(canvas);
  }

  // ── Expose IIFE-private bridges for core/*.js ─────────────────────────────

  window.BIomie         = window.BIomie || {};
  B                     = window.BIomie;
  B._renderChart        = renderChart;
  B._destroyChart       = destroyChart;
  B._chartEmpty         = chartEmpty;
  B._lazyChart          = lazyChart;
  B._chartTheme         = function () { return B.chartTheme(); };
  B._surfaceColor       = function () { return B.surfaceColor(); };

  // ── Invoice-detail registry (IIFE-private state) ──────────────────────────

  const invoiceDetailRegistry = new Map();
  let invoiceDetailSeq        = 0;
  let vendorDeepLinkHandled   = false;

  function currentSearchParams() { return new URLSearchParams(window.location.search || ''); }
  function currentPageKey()      { return String((document.body && document.body.dataset && document.body.dataset.page) || ''); }

  function getVendorDrilldownHref(vendorId) {
    const normalized = String(vendorId || '').trim();
    if (!normalized) return '';
    const params = new URLSearchParams();
    params.set('vendor_id', normalized);
    const range = getDateRange();
    if (range.start) params.set('start', range.start);
    if (range.end)   params.set('end', range.end);
    return `vendedores.php?${params.toString()}`;
  }

  function registerInvoiceDetailRow(type, row) {
    const entry = B.normalizeInvoiceRow(type, row);
    const id    = `invoice-${++invoiceDetailSeq}`;
    invoiceDetailRegistry.set(id, entry);
    return id;
  }

  function renderInvoiceDetailTrigger(type, row, options) {
    options   = options || {};
    const entryId     = registerInvoiceDetailRow(type, row);
    const code        = String((row && (row.codigo || row.nf_code)) || 'Documento');
    const integration = String((row && (row.codigo_integracao || row.origin_order_code || row.document_key)) || '');

    const strongClass = options.compact ? 'row-inline-link__strong row-inline-link__strong--compact' : 'row-inline-link__strong';
    let contentHtml   = `<strong class="${strongClass}">${escapeHtml(code)}</strong>`;

    if (options.horizontal) {
      if (integration) {
        contentHtml = `<div style="display:flex; align-items:center;">${contentHtml}<span style="font-size:10px; padding:2px 6px; background:var(--bg-hover); border:1px solid var(--border); border-radius:4px; color:var(--text-3); white-space:nowrap; margin-left:8px;">${escapeHtml(integration)}</span></div>`;
      }
    } else {
      const subtitleParts = [];
      if (integration) subtitleParts.push(escapeHtml(integration));
      if (row && (row.issue_date || row.competencia_date || row.status_date)) subtitleParts.push(`Ref.: ${escapeHtml(formatMetaDate(row.issue_date || row.competencia_date || row.status_date || ''))}`);
      const subtitleHtml = subtitleParts.length ? `<div class="small text-muted" style="margin-top:2px;">${subtitleParts.join(' &middot; ')}</div>` : '';
      contentHtml += subtitleHtml;
    }

    const biomieAction = (type === 'pending-order') ? 'open-order' : 'open-invoice';
    const notaKey      = escapeHtml(String((row && (row.nota_key || row.document_key)) || ''));
    const vendorId     = escapeHtml(String((row && (row.vendor_id || row.vendor_ref || row.codigo_vendedor_omie)) || ''));
    const vendorName   = escapeHtml(String((row && row.vendor_name) || ''));
    const originOrder  = escapeHtml(String((row && (row.codigo_integracao || row.origin_order_code)) || ''));
    return `<button type="button" class="row-inline-link row-inline-link--document" data-invoice-detail-trigger="1" data-invoice-entry-id="${entryId}" data-biomie-action="${biomieAction}" data-row-type="${escapeHtml(type)}" data-codigo="${escapeHtml(code)}" data-nota-key="${notaKey}" data-vendor-id="${vendorId}" data-vendor-name="${vendorName}" data-origin-order="${originOrder}" aria-label="Abrir detalhes de ${escapeHtml(code)}">${contentHtml}</button>`;
  }

  function ensureInvoiceDetailModal() {
    if (document.getElementById('invoiceDetailModal')) return;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
      <div class="product-breakdown-backdrop" id="invoiceDetailBackdrop" hidden></div>
      <aside class="product-breakdown-modal invoice-detail-modal" id="invoiceDetailModal" hidden aria-hidden="true" aria-labelledby="invoiceDetailTitle">
        <div class="product-breakdown-head">
          <div>
            <p class="invoice-detail-eyebrow">Detalhe rapido do documento</p>
            <h3 id="invoiceDetailTitle">Carregando...</h3>
            <p id="invoiceDetailSubtitle">-</p>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="invoiceDetailClose">Fechar</button>
        </div>
        <div class="invoice-detail-grid" id="invoiceDetailGrid"></div>
        <div class="product-breakdown-empty invoice-detail-note" id="invoiceDetailNote"></div>
      </aside>`;
    document.body.appendChild(wrapper);

    const close = () => {
      const modal    = document.getElementById('invoiceDetailModal');
      const backdrop = document.getElementById('invoiceDetailBackdrop');
      if (modal)    { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); }
      if (backdrop)   backdrop.hidden = true;
      document.body.classList.remove('modal-open');
    };

    document.getElementById('invoiceDetailClose')?.addEventListener('click', close);
    document.getElementById('invoiceDetailBackdrop')?.addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !document.getElementById('invoiceDetailModal')?.hidden) close(); });
  }

  function openInvoiceDetail(entryId) {
    ensureInvoiceDetailModal();
    const entry    = invoiceDetailRegistry.get(String(entryId || ''));
    if (!entry) return;
    const title    = document.getElementById('invoiceDetailTitle');
    const subtitle = document.getElementById('invoiceDetailSubtitle');
    const grid     = document.getElementById('invoiceDetailGrid');
    const note     = document.getElementById('invoiceDetailNote');
    const modal    = document.getElementById('invoiceDetailModal');
    const backdrop = document.getElementById('invoiceDetailBackdrop');

    if (title) title.textContent = entry.code || 'Documento';
    if (subtitle) subtitle.textContent = entry.type === 'product-breakdown'
      ? 'Visao resumida do item fiscal vinculado ao produto selecionado.'
      : 'Visao resumida para reforcar o contexto comercial da NF/pedido.';
    if (grid) grid.innerHTML = B.renderInvoiceDetailGrid(entry);
    if (note) {
      const vendorHref   = getVendorDrilldownHref(entry.vendorId);
      const vendorAction = vendorHref ? ` <a href="${escapeHtml(vendorHref)}">Abrir vendedor</a>.` : '';
      note.innerHTML = `<strong>Leitura rapida, sem nova consulta.</strong><span>Este detalhe foi montado com os dados ja carregados na tela atual.${vendorAction}</span>`;
    }
    if (modal)    { modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); }
    if (backdrop)   backdrop.hidden = false;
    document.body.classList.add('modal-open');
  }

  function bindInvoiceDetailTriggers() {
    document.querySelectorAll('[data-invoice-detail-trigger="1"]').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      if (btn.getAttribute('data-biomie-action') && window.BIomieDrilldownRouter) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => openInvoiceDetail(btn.getAttribute('data-invoice-entry-id') || ''));
    });
  }

  function bindVendorDrawerTriggers() {
    document.querySelectorAll('[data-vendor-drawer-trigger="1"]').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => { const id = btn.getAttribute('data-vendor-id') || ''; if (id) window.openVendorDrawer(id); });
    });
  }

  function maybeHandleVendorDeepLink() {
    if (vendorDeepLinkHandled || currentPageKey() !== 'vendors') return;
    const vendorId = String(currentSearchParams().get('vendor_id') || '').trim();
    if (!vendorId) return;
    vendorDeepLinkHandled = true;
    window.setTimeout(() => { if (typeof window.openVendorDrawer === 'function') window.openVendorDrawer(vendorId); }, 160);
  }

  // ── Bootstrap hydration helpers ───────────────────────────────────────────

  function getPageBootstrap() {
    const payload = window.__BIOMIE_BOOTSTRAP__;
    return payload && typeof payload === 'object' ? payload : null;
  }

  function bootstrapMatchesCurrentRange(bundle) {
    if (!bundle || !bundle.range) return false;
    const range = getDateRange();
    return String(bundle.range.start || '') === String(range.start || '') && String(bundle.range.end || '') === String(range.end || '');
  }

  function getDashboardBootstrapPayload() {
    const bundle = getPageBootstrap();
    if (!bundle || bootstrapState.dashboardHydrated || !bootstrapMatchesCurrentRange(bundle)) return null;
    const dashboard = bundle.dashboard;
    if (!dashboard || (!dashboard.overview && !dashboard.vendors)) return null;
    return dashboard;
  }

  // ── Weak signal notice ────────────────────────────────────────────────────

  function renderDashboardWeakSignalNotice(needsAttention) {
    const feedback = document.getElementById('syncFeedback');
    if (!feedback) return;
    if (!needsAttention) {
      if (feedback.dataset.dashboardNotice === '1') { feedback.innerHTML = ''; delete feedback.dataset.dashboardNotice; }
      return;
    }
    if (feedback.innerHTML.trim() !== '' && feedback.dataset.dashboardNotice !== '1') return;
    feedback.innerHTML = '<div class="module-alert module-alert--warning"><strong>Leitura parcial detectada</strong><span>O resumo principal veio com pouco sinal fiscal. O painel usou a camada operacional para manter a leitura do periodo, mas vale revisar a competencia historica.</span></div>';
    feedback.dataset.dashboardNotice = '1';
  }

  // ── API helpers ───────────────────────────────────────────────────────────

  async function fetchJson(url, options) {
    return window.fetchJson(url, options || {});
  }

  function buildApiUrl(path, force, extraParams) {
    const url   = new URL(path, window.location.href);
    const range = getDateRange();
    if (range.start) url.searchParams.set('start', range.start);
    if (range.end)   url.searchParams.set('end', range.end);
    if (force)       url.searchParams.set('force', '1');
    Object.entries(extraParams || {}).forEach(([key, value]) => {
      if (value == null || value === '') return;
      url.searchParams.set(key, String(value));
    });
    return `${url.pathname}${url.search}`;
  }

  // ── Dashboard render ──────────────────────────────────────────────────────

  function renderDashboardPayloads(overview, vendors, health, weather, prevOverview) {
    prevOverview = prevOverview || null;
    const compareActive = window.CompareMode && window.CompareMode.isActive ? window.CompareMode.isActive() : false;
    const prevPeriod = compareActive && window.CompareMode
      ? window.CompareMode.getPrevPeriod(getDateRange().start, getDateRange().end)
      : null;

    const metaCarrier   = overview || vendors || {};
    const summary       = B.mergeDashboardSummary((overview && overview.summary) || {}, vendors);
    const dailySales    = (overview && overview.daily_sales || []).length ? overview.daily_sales : B.overviewSalesAsStatus((vendors && vendors.daily_status) || []);
    const vendorChartRows = B.vendorsMatrixForHome(vendors, overview);
    function normalizeOverviewPayload(payload) {
      if (!payload) return {};
      return {
        kpis: payload.kpis || payload.cards || {},
        revenue_evolution: payload.revenue_evolution || payload.revenueEvolution || payload.series || [],
        vendor_ranking: payload.vendor_ranking || payload.vendorRanking || payload.vendors || [],
        top_products: payload.top_products || payload.topProducts || [],
        top_customers: payload.top_customers || payload.topCustomers || payload.top_clients || [],
        top_invoices: payload.top_invoices || payload.topInvoices || payload.top_orders || payload.maiores_notas || [],
        geo_compact: payload.geo_compact || payload.geoCompact || payload.geo || null,
        pipeline_period: payload.pipeline_period || payload.pipelinePeriod || payload.pipeline || null,
        monthly_comparison: payload.monthly_comparison || payload.monthlyComparison || payload.month_comparison || null,
        finance_risk: payload.finance_risk || payload.financeRisk || payload.health || null,
        attention_points: payload.attention_points || payload.attentionPoints || payload.alerts || []
      };
    }
    const normalized = normalizeOverviewPayload(overview);
    const topOrders     = Array.isArray(normalized.top_invoices) ? normalized.top_invoices : [];
    const topProducts   = Array.isArray(normalized.top_products) ? normalized.top_products : [];
    const topClients    = Array.isArray(normalized.top_customers) ? normalized.top_customers : [];

    function renderPanelState(container, state) {
      if (!container) return;
      state = state || {};
      const target = container.querySelector('.dashboard-panel__body') || container.querySelector('.panel-body') || container;
      const diagnostics = state.diagnostics || {};
      const diagnosticRows = Object.keys(diagnostics).length
        ? `<details style="margin-top:12px;"><summary style="font-size:11px; cursor:pointer; color:var(--text-3);">Diagnostico tecnico</summary><div class="small text-muted" style="margin-top:8px; font-family:monospace; font-size:10px;">${Object.entries(diagnostics).map(([k, v]) => `${escapeHtml(k)}=${escapeHtml(String(v))}`).join('<br>')}</div></details>`
        : '';
      target.innerHTML = `<div class="dashboard-empty-state">
        <span class="material-symbols-rounded">${escapeHtml(state.icon || (state.state === 'error' ? 'error' : 'info'))}</span>
        <strong>${escapeHtml(state.title || 'Dados indisponiveis')}</strong>
        <p>${escapeHtml(state.message || 'Dados nao enviados pela API para este painel.')}</p>
        ${diagnosticRows}
      </div>`;
    }
    B.renderPanelState = renderPanelState;

    function renderPanelFallback(containerId, expectedKey, availableKeys) {
      const container = document.getElementById(containerId) || document.querySelector(`[data-chart-id="${expectedKey}"]`);
      if (!container) return;
      const meta = (overview && overview.meta) || {};
      const diag = {
        expected_key: expectedKey,
        endpoint: 'api/overview.php',
        period: `${meta.period_start || getDateRange().start || '-'}..${meta.period_end || getDateRange().end || '-'}`,
        source: meta.source_of_truth || '-',
        cache: meta.cache_status || '-',
        duration: meta.duration_ms ? `${meta.duration_ms}ms` : '-'
      };
      if (meta.datasets_present && !meta.datasets_present.includes(expectedKey)) {
        diag.reason = 'dataset ausente no payload';
      }
      renderPanelState(container, {
        state: 'empty',
        title: 'Dados indisponiveis',
        message: 'Nenhum dado retornado para este painel no periodo selecionado.',
        diagnostics: diag
      });
    }

    const availableKeys = overview ? Object.keys(overview) : [];
    const statusSummary = (vendors && vendors.summary_status) || {
      billed_value:      Number(summary.gross_revenue != null ? summary.gross_revenue : (summary.revenue || 0)),
      billed_orders:     Number(summary.orders || 0),
      authorized_value:  Number(summary.pending_value || 0),
      authorized_orders: Number(summary.pending_orders || 0),
      cancelled_value:   Number(summary.cancelled_value || 0),
      cancelled_orders:  Number(summary.cancelled_orders || 0)
    };
    const alerts = [].concat(
      Array.isArray(overview && overview.alerts) ? overview.alerts : [],
      Array.isArray(vendors && vendors.alerts_operational)
        ? vendors.alerts_operational.slice(0, 3).map((a) => ({ level: a.level || 'warning', title: a.title || 'Sinal operacional', message: a.message || 'Atencao operacional no time.', metric: 'Operacao', value: '-' }))
        : []
    );

    B.updateGlobalMeta(metaCarrier);
    B.renderSummaryCards(summary, metaCarrier.mode || 'demo');
    B.renderStatusMeter(statusSummary);
    renderDashboardWeakSignalNotice(!B.summaryHasSignal((overview && overview.summary) || {}) && B.vendorsPayloadHasSignal(vendors));

    if (compareActive && prevOverview && window.CompareMode) {
      const prevSummary = (prevOverview && prevOverview.summary) || {};
      [
        ['summary-revenue',         Number(summary.gross_revenue != null ? summary.gross_revenue : (summary.revenue || 0)), Number(prevSummary.gross_revenue != null ? prevSummary.gross_revenue : (prevSummary.revenue || 0)), false],
        ['summary-pending-value',   Number(summary.pending_value || 0),   Number(prevSummary.pending_value || 0),   false],
        ['summary-cancelled-value', Number(summary.cancelled_value || 0), Number(prevSummary.cancelled_value || 0), true],
      ].forEach(([binding, curr, prev, invertColors]) => {
        const delta = window.CompareMode.renderDelta(curr, prev, { invertColors });
        if (!delta) return;
        document.querySelectorAll(`[data-bind="${binding}"]`).forEach((el) => { if (!el.querySelector('.bmie-delta')) el.insertAdjacentHTML('afterend', delta); });
      });
      if (prevPeriod) {
        const compareLabel = document.getElementById('comparePeriodLabel');
        if (compareLabel) { compareLabel.textContent = `vs. ${prevPeriod.start} – ${prevPeriod.end}`; compareLabel.classList.remove('d-none'); }
      }
    }

    // Row 1: Revenue (always visible) + Vendor Ranking (visible, not lazy)
    B.createLineChart('salesTimelineChart', dailySales.map((r) => chartDateLabel(r.date)), dailySales.map((r) => Number(r.gross_revenue != null ? r.gross_revenue : (r.revenue != null ? r.revenue : (r.billed_value || 0)))), dailySales.map((r) => Number(r.orders != null ? r.orders : (r.billed_orders || 0))));
    B.createRevenueTicketChart('vendorsBarChart', vendorChartRows);

    // Row 2: Top Products chart + Top Clients chart + Pipeline (lazy)
    if (topProducts.length > 0) {
      if (typeof B.createHorizontalBarChart === 'function') B.createHorizontalBarChart('topProductsChart', topProducts.slice(0, 8).map((r) => escapeHtml(String(r.description || r.label || '').substring(0, 28))), topProducts.slice(0, 8).map((r) => Number(r.revenue || r.value || 0)), { barColor: 'rgba(37,99,235,0.7)' });
    } else {
      renderPanelFallback(document.getElementById('topProductsChart') ? document.getElementById('topProductsChart').closest('.dashboard-panel').id : '', 'top_products', availableKeys);
    }

    if (topClients.length > 0) {
      if (typeof B.createHorizontalBarChart === 'function') B.createHorizontalBarChart('topClientsChart', topClients.slice(0, 8).map((r) => escapeHtml(String(r.client_name || r.label || '').substring(0, 28))), topClients.slice(0, 8).map((r) => Number(r.invoice_total || r.value || 0)), { barColor: 'rgba(22,163,74,0.7)' });
    } else {
      renderPanelFallback(document.getElementById('topClientsChart') ? document.getElementById('topClientsChart').closest('.dashboard-panel').id : '', 'top_customers', availableKeys);
    }
    lazyChart('dailyMixedChart', () => B.createMixedDailyChart('dailyMixedChart', Array.isArray(vendors && vendors.daily_status) && vendors.daily_status.length ? vendors.daily_status : B.overviewSalesAsStatus(dailySales)));
    lazyChart('monthComparisonChart', () => B.createMonthlyComparisonChart('monthComparisonChart', (overview && overview.month_comparison) || []));

    // Row 3: Maiores NFs (table with Emissao + Acao columns)
    if (topOrders.length > 0) {
      B.renderTable('topOrdersTable', [
        { render: (row) => renderInvoiceDetailTrigger('order', row, { horizontal: true }) },
        { render: (row) => `<span>${formatOrderDate(row, true)}</span>` },
        { render: (row) => B.renderCustomerIdentityCell(row) },
        { render: (row) => B.renderSellerIdentityCell(row, 'index.html') },
        { render: (row) => formatNumber(row.items_qty || row.items || 0) },
        { render: (row) => `<strong>${formatCurrency(row.gross_total != null ? row.gross_total : (row.total || row.value || 0))}</strong>` },
        { render: (row) => `<button type="button" class="btn-detail" data-biomie-action="open-invoice" data-nota-key="${escapeHtml(row.document_key || row.nota_key || '')}" data-numero-nf="${escapeHtml(String(row.codigo || row.nf_code || ''))}" data-pedido-codigo="${escapeHtml(row.origin_order_code || row.codigo_integracao || '')}"><span class="material-symbols-rounded">open_in_new</span></button>` }
      ], topOrders.slice(0, 5));
    } else {
      renderPanelFallback('tabelas', 'top_invoices', availableKeys);
    }
    B.renderTopOrdersIdentityDiagnostics((overview && overview.diagnostics && overview.diagnostics.top_orders_identity) || null);

    B.renderAlerts(alerts);
    if (health !== undefined) B.renderCommercialHealth(health || { available: false, message: 'Modulo financeiro indisponivel para este carregamento.' });
    if (weather !== undefined) B.renderWeather(weather || { ok: false, message: 'Clima indisponivel no momento.' });
    bindInvoiceDetailTriggers();
  }

  function hydrateDashboardFromBootstrap() {
    if (!['index', 'dashboard'].includes(document.body.dataset.page || 'dashboard')) return false;
    const dashboard = getDashboardBootstrapPayload();
    if (!dashboard) return false;
    renderDashboardPayloads(dashboard.overview || null, dashboard.vendors || null, dashboard.health, undefined, null);
    bootstrapState.dashboardHydrated = true;
    return true;
  }

  // ── Page load functions ───────────────────────────────────────────────────

  async function loadCommercialHealth(force, options) {
    const target = document.getElementById('commercialHealthInfo');
    if (!target) return;
    try {
      const data = await fetchJson(buildApiUrl('api/commercial_health.php', force), Object.assign({ timeoutMs: 20000 }, options || {}));
      B.renderCommercialHealth(data);
    } catch (error) {
      B.renderCommercialHealth({ available: false, message: (error && error.message) || 'Falha ao consultar dados financeiros.' });
    }
  }

  async function loadDashboard(force, options) {
    options = options || {};
    const compareActive = window.CompareMode && window.CompareMode.isActive ? window.CompareMode.isActive() : false;
    const prevPeriod = compareActive && window.CompareMode
      ? window.CompareMode.getPrevPeriod(getDateRange().start, getDateRange().end)
      : null;

    const fetchList = [
      fetchJson(buildApiUrl('api/overview.php', force), options),
      fetchJson(buildApiUrl('api/vendors.php', force), Object.assign({}, options, { timeoutMs: 25000 })),
      fetchJson(buildApiUrl('api/commercial_health.php', force), Object.assign({}, options, { timeoutMs: 20000 })),
      fetchJson('api/weather.php', Object.assign({}, options, { timeoutMs: 10000 })),
    ];
    if (compareActive && prevPeriod) {
      fetchList.push(fetchJson(buildApiUrl('api/overview.php', false, { start: prevPeriod.start, end: prevPeriod.end }), options));
    }

    const [overviewResult, vendorsResult, healthResult, weatherResult, prevOverviewResult] = await Promise.allSettled(fetchList);

    const overview     = overviewResult.status  === 'fulfilled' ? overviewResult.value  : null;
    const vendors      = vendorsResult.status   === 'fulfilled' ? vendorsResult.value   : null;
    const health       = healthResult.status    === 'fulfilled' ? healthResult.value    : null;
    const weather      = weatherResult.status   === 'fulfilled' ? weatherResult.value   : null;
    const prevOverview = (compareActive && prevOverviewResult && prevOverviewResult.status === 'fulfilled') ? prevOverviewResult.value : null;

    if (!overview && !vendors) {
      const diagMsg = overviewResult.status === 'rejected' ? (overviewResult.reason && overviewResult.reason.message) || 'Erro desconhecido' : 'Erro no carregamento';
      document.querySelectorAll('.dashboard-panel').forEach(panel => {
        const title = panel.querySelector('h3') ? panel.querySelector('h3').textContent : 'Painel';
        renderPanelState(panel, {
          state: 'error',
          title: 'Erro ao carregar dados',
          message: 'Falha na comunicacao com a API para este periodo.',
          diagnostics: {
            error: String(diagMsg).substring(0, 100),
            period: `${getDateRange().start}..${getDateRange().end}`
          }
        });
      });
      document.querySelectorAll('[data-bind^="summary-"]').forEach(el => {
        el.innerHTML = '<span style="color:var(--text-3); font-size:16px;" title="Erro de carregamento">-</span>';
      });
      throw overviewResult.status === 'rejected' ? overviewResult.reason : vendorsResult.reason;
    }
    renderDashboardPayloads(overview, vendors, health, weather, prevOverview);
  }

  async function loadVendors(force, options) {
    options = options || {};
    const data          = await fetchJson(buildApiUrl('api/vendors.php', force), options);
    const summaryStatus = data.summary_status || {};
    B.updateGlobalMeta(data);
    B.renderSummaryCards(data.summary, data.mode);

    setText('[data-bind="status-billed-value"]',   formatCurrency(summaryStatus.billed_value));
    setText('[data-bind="status-billed-orders"]',  `${formatNumber(summaryStatus.billed_orders || 0)} NF`);
    setText('[data-bind="status-auth-value"]',     formatCurrency(summaryStatus.authorized_value));
    setText('[data-bind="status-auth-orders"]',    `${formatNumber(summaryStatus.authorized_orders || 0)} pedidos`);
    setText('[data-bind="status-cancel-value"]',   formatCurrency(summaryStatus.cancelled_value));
    setText('[data-bind="status-cancel-orders"]',  `${formatNumber(summaryStatus.cancelled_orders || 0)} NF`);
    setText('[data-bind="status-conversion-rate"]', `${summaryStatus.conversion_rate || 0}%`);
    setText('[data-bind="status-backlog-value"]',  formatCurrency(summaryStatus.backlog_value));
    setText('[data-bind="status-cancel-rate"]',    `${summaryStatus.cancel_rate || 0}%`);

    const total = Number(summaryStatus.billed_value || 0) + Number(summaryStatus.authorized_value || 0) + Number(summaryStatus.cancelled_value || 0);
    if (total > 0) {
      const billed = document.querySelector('#funnelStrip .f-billed');
      const auth   = document.querySelector('#funnelStrip .f-auth');
      const cancel = document.querySelector('#funnelStrip .f-cancel');
      if (billed) billed.style.width = `${(Number(summaryStatus.billed_value    || 0) / total) * 100}%`;
      if (auth)   auth.style.width   = `${(Number(summaryStatus.authorized_value || 0) / total) * 100}%`;
      if (cancel) cancel.style.width = `${(Number(summaryStatus.cancelled_value  || 0) / total) * 100}%`;
    }

    const matrix = (data.vendor_status_matrix || []).filter((r) => r.vendor_name !== 'Sem vendedor').slice(0, 10);
    lazyChart('vendorGroupedBar', () => B.createGroupedBarChart('vendorGroupedBar', matrix));
    lazyChart('dailyMixedChart',  () => B.createMixedDailyChart('dailyMixedChart', data.daily_status || []));
    B.renderPipelineTable('vendorsTable', matrix);
    B.renderOperationalAlerts('alertsOperational', data.alerts_operational || []);
    bindVendorDrawerTriggers();
    maybeHandleVendorDeepLink();
  }

  async function loadOrders(force, options) {
    options = options || {};
    const data = await fetchJson(buildApiUrl('api/orders.php', force), options);
    B.updateGlobalMeta(data);
    B.renderSummaryCards(data.summary, data.mode);
    B.createOrdersStatusChart('ordersStatusChart', data.orders || [], data.pending_orders || [], data.cancelled_orders || []);
    B.createOrdersVendorChart('ordersVendorChart', data.orders || []);

    B.renderTable('ordersTable', [
      { render: (row) => renderInvoiceDetailTrigger('order', row, { horizontal: true }) },
      { render: (row) => B.renderCustomerIdentityCell(row) },
      { render: (row) => B.renderSellerIdentityCell(row, 'pedidos.html') },
      { render: (row) => formatOrderDate(row, true) },
      { render: (row) => formatNumber(row.items_qty) },
      { render: (row) => `<div style="display:flex; align-items:center; flex-wrap:wrap; gap:6px;"><strong>${formatCurrency(row.gross_total != null ? row.gross_total : row.total)}</strong>${(row.merchandise_total || row.discount_total) ? `<span style="font-size:11px; color:var(--text-3); padding-top:2px;">(Merc: ${formatCurrency(row.merchandise_total || 0)}${row.discount_total ? ` | Desc: ${formatCurrency(row.discount_total)}` : ''})</span>` : ''}</div>` }
    ], data.orders || [], 'Sem NF autorizada no periodo.');

    B.renderTable('pendingOrdersTable', [
      { render: (row) => renderInvoiceDetailTrigger('pending-order', row, { compact: true }) },
      { render: (row) => B.renderCustomerIdentityCell(row) },
      { render: (row) => B.renderSellerIdentityCell(row, 'pedidos.html') },
      { render: (row) => formatOrderDate(row, true) },
      { render: (row) => `<strong>${formatCurrency(row.gross_total != null ? row.gross_total : row.total)}</strong>` }
    ], (data.pending_orders || []).slice(0, 8), 'Sem carteira no periodo.');

    B.renderTable('cancelledOrdersTable', [
      { render: (row) => renderInvoiceDetailTrigger('cancelled-order', row, { compact: true }) },
      { render: (row) => B.renderCustomerIdentityCell(row) },
      { render: (row) => B.renderSellerIdentityCell(row, 'pedidos.html') },
      { render: (row) => formatOrderDate(row, true) },
      { render: (row) => `<strong>${formatCurrency(row.gross_total != null ? row.gross_total : row.total)}</strong>` }
    ], (data.cancelled_orders || []).slice(0, 8), 'Sem NF cancelada no periodo.');
  }

  async function loadProducts(force, options) {
    options = options || {};
    const data           = await fetchJson(buildApiUrl('api/products.php', force), options);
    const breakdownState = B.resolveProductBreakdownState(data);
    B.updateGlobalMeta(data);
    B.renderSummaryCards(data.summary, data.mode);
    B.createProductMixChart('productsRevenueChart', data.products || []);
    B.createProductShareChart('productsShareChart', data.products || []);
    B.renderTable('productsTable', [
      { render: (row) => B.renderProductBreakdownTrigger(row, breakdownState) },
      { render: (row) => formatNumber(row.quantity) },
      { render: (row) => formatNumber(row.orders) },
      { render: (row) => formatCurrency(row.revenue) }
    ], data.products || [], 'Sem item fiscal no periodo.');
    bindProductBreakdownTriggers();
  }

  async function loadIntegrations(options) {
    options = options || {};
    const data = await fetchJson(buildApiUrl('api/status.php', false), options);
    setHtml('#integrationModeBadge',      B.renderStatusBadge(data.mode));
    setText('#integrationAppName',        data.app_name || 'BIomie Comercial MAG');
    setText('#integrationTimezone',       data.timezone || 'America/Fortaleza');
    setText('#integrationCredentials',    data.credentials_configured ? 'Configuradas' : 'Pendentes');
    setText('#integrationOrdersEndpoint', (data.endpoints && data.endpoints.orders) || '-');
    setText('#integrationVendorsEndpoint',(data.endpoints && data.endpoints.vendors) || '-');
    setText('#integrationClientsEndpoint',(data.endpoints && data.endpoints.clients) || '-');
    setText('#integrationOrdersFatEndpoint', (data.endpoints && data.endpoints.orders_fat) || '-');
    setText('#integrationReceivableEndpoint',(data.endpoints && data.endpoints.accounts_receivable) || '-');
    setText('#integrationCacheAge',  data.cache && data.cache.age_seconds == null ? 'Sem cache' : `${formatNumber(data.cache && data.cache.age_seconds)} s`);
    setText('#integrationCacheDate', data.cache && data.cache.modified_at ? new Date(data.cache.modified_at).toLocaleString('pt-BR') : '-');
    const notes = document.getElementById('integrationNotes');
    if (notes) notes.innerHTML = (data.notes || []).map((n) => `<li>${escapeHtml(n)}</li>`).join('');

    const ss = data.sync_status || {};
    const commercialAt = ss.canonical_commercial && ss.canonical_commercial.last_sync_at;
    setText('#syncStatusCommercial',    commercialAt ? new Date(commercialAt).toLocaleString('pt-BR') : (ss.canonical_commercial && ss.canonical_commercial.available ? 'Sem registro' : 'Nao disponivel'));
    setText('#syncStatusCommercialAge', B.syncAgeLabel(commercialAt) || '');

    const financialAt = ss.canonical_financial && ss.canonical_financial.last_sync_at;
    setText('#syncStatusFinancial',    financialAt ? new Date(financialAt).toLocaleString('pt-BR') : (ss.canonical_financial && ss.canonical_financial.available ? 'Sem registro' : 'Nao disponivel'));
    setText('#syncStatusFinancialAge', B.syncAgeLabel(financialAt) || '');

    const snapshotAt  = ss.snapshot_commercial && ss.snapshot_commercial.generated_at;
    const snapshotAge = ss.snapshot_commercial && ss.snapshot_commercial.age_seconds;
    setText('#syncStatusSnapshot',    snapshotAt ? new Date(snapshotAt).toLocaleString('pt-BR') : 'Sem cache');
    setText('#syncStatusSnapshotAge', snapshotAge != null ? `Idade: ${formatNumber(snapshotAge)} s` : '');

    const cursors = Array.isArray(ss.pipeline_cursors) ? ss.pipeline_cursors : [];
    if (!cursors.length) {
      setText('#syncStatusPipeline', 'Nenhum cursor');
    } else {
      const activeDomains = [...new Set(cursors.map((c) => c.domain))].join(', ');
      setText('#syncStatusPipeline', activeDomains || `${cursors.length} cursor(es)`);
    }
  }

  // ── Vendor drawer ─────────────────────────────────────────────────────────

  window.openVendorDrawer = async function (vId) {
    if (!String(vId || '').trim() || vId === 'undefined') return;
    const bg    = document.getElementById('flightDrawerBackdrop');
    const panel = document.getElementById('flightDrawer');
    if (!bg || !panel) return;
    bg.style.display    = 'block';
    panel.style.display = 'flex';
    setTimeout(() => { panel.style.right = '0'; }, 10);

    const nameEl   = document.getElementById('drawerVendorName');
    const statusEl = document.getElementById('drawerVendorStatus');
    const syncEl   = document.getElementById('drawerSyncing');
    if (nameEl)   nameEl.textContent   = 'Abrindo vendedor...';
    if (statusEl) statusEl.textContent = '';
    if (syncEl)   syncEl.style.display = 'inline-block';

    const start  = document.getElementById('dateStart') && document.getElementById('dateStart').value || '';
    const end    = document.getElementById('dateEnd')   && document.getElementById('dateEnd').value   || '';
    const params = new URLSearchParams({ vendor_id: vId });
    if (start) params.set('start', start);
    if (end)   params.set('end', end);

    try {
      const data = await fetchJson('api/flight_plan_vendor_detail.php?' + params.toString());
      const v    = data.vendor || {};
      if (nameEl)   nameEl.textContent   = v.vendor_name || 'Desconhecido';
      if (statusEl) statusEl.textContent = B.vendorRiskLabel(v.risk_status || '');

      const setT = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
      setT('drawerRealized', formatCurrency(v.realized_period));
      setT('drawerTarget',   formatCurrency(v.target_period));
      setT('drawerGap',      formatCurrency(v.gap_to_target));
      setT('drawerPct',      Number(v.attainment_pct || 0).toFixed(1) + '%');

      const fw = document.getElementById('drawerForecastWrap');
      if (fw) {
        const f      = v.forecasts || { base: v.forecast_period || 0, probable: v.forecast_period || 0, commit: v.forecast_period || 0 };
        const maxVal = Math.max(Number(v.target_period || 0), Number(f.base), Number(f.probable), Number(f.commit)) * 1.05 || 1;
        const getCssVar = B.getCssVar;
        const bar = (lbl, clr, val) => `<div class="detail-drawer-forecast-row"><div style="width:70px;font-size:11px;color:var(--text-3);font-weight:600">${lbl}</div><div class="detail-drawer-forecast-track"><div class="detail-drawer-forecast-fill" style="--fill-width:${Math.round((Number(val) / maxVal) * 100)}%;--fill-color:${clr}"></div></div><div style="width:80px;text-align:right;font-size:12px;font-weight:600">${formatCurrency(val)}</div></div>`;
        fw.innerHTML = bar('Commit',  getCssVar('--text-3')    || '#adb5bd', f.commit)
                     + bar('Provavel', getCssVar('--state-info') || '#22a3ff', f.probable)
                     + bar('Base',    getCssVar('--page-accent') || '#96140a', f.base);
      }

      const cv = document.getElementById('drawerDailyChart');
      if (cv && window.Chart) {
        if (window._vendorDrawerChart) window._vendorDrawerChart.destroy();
        const ds     = data.daily_sales || [];
        const labels = ds.map((d) => String(d.date || '').slice(8, 10) + '/' + String(d.date || '').slice(5, 7));
        const values = ds.map((d) => Number(d.gross_revenue || 0));
        window._vendorDrawerChart = renderChart('drawerDailyChart', {
          type: 'bar',
          data: { labels, datasets: [{ label: 'NF', data: values, backgroundColor: B.getCssVar('--page-accent-soft') || B.hexToRgba(B.getCssVar('--page-accent') || '#96140a', 0.5), borderRadius: 2, barPercentage: 0.7 }] },
          options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 9 } } }, y: { beginAtZero: true, ticks: { callback(val) { const m = val / 1000; return m >= 1 ? m + 'k' : val; } } } } }
        });
      }

      const ow = document.getElementById('drawerOrdersList');
      if (ow) {
        const invoices  = data.invoices           || [];
        const pipeline  = data.pipeline_orders    || [];
        const cancelled = data.cancelled_invoices || [];
        const renderList = (items, title, empty, kind) => {
          if (!items.length) return `<div style="font-size:12px;color:var(--text-3);padding:10px 0">${empty}</div>`;
          return `<h4 style="font-size:13px;margin:12px 0 8px">${title}</h4>` + items.map((x) => {
            const refDate   = x.status_date || x.issue_date || '';
            const dateLabel = refDate ? refDate.slice(8, 10) + '/' + refDate.slice(5, 7) : '--/--';
            return `<div class="detail-drawer-list-item"><div style="display:flex;flex-direction:column;gap:4px"><strong>${escapeHtml(x.cliente || '')}</strong><span style="font-size:11px;color:var(--text-3)">${dateLabel} | ${kind} ${x.codigo || '-'}</span></div><div style="text-align:right"><div style="font-size:13px;font-weight:700">${formatCurrency(x.merchandise_total || x.total)}</div><small style="color:var(--text-3)">merc.</small></div></div>`;
          }).join('');
        };
        ow.innerHTML = renderList(invoices, 'NF autorizadas', 'Nenhuma NF no periodo.', 'NF')
                     + renderList(pipeline, 'Carteira', 'Sem pedidos na carteira.', 'Pedido')
                     + renderList(cancelled, 'NF canceladas', 'Nenhuma NF cancelada.', 'NF');
      }
    } catch (err) {
      if (nameEl) nameEl.textContent = 'Erro ao carregar';
      const ow = document.getElementById('drawerOrdersList');
      if (ow) ow.innerHTML = `<div class="module-alert module-alert--danger" style="margin-top:10px">${escapeHtml(err.message)}</div>`;
    } finally {
      if (syncEl) syncEl.style.display = 'none';
    }
  };

  window.closeVendorDrawer = function () {
    const panel = document.getElementById('flightDrawer');
    const bg    = document.getElementById('flightDrawerBackdrop');
    if (panel) panel.style.right = '-450px';
    if (bg)    setTimeout(() => { bg.style.display = 'none'; }, 300);
  };

  // ── Product breakdown modal ───────────────────────────────────────────────

  function bindProductBreakdownTriggers() {
    document.querySelectorAll('.product-breakdown-trigger').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => window.openProductBreakdown(btn.getAttribute('data-product-code') || '', btn.getAttribute('data-product-label') || 'Produto sem descricao'));
    });
  }

  function applyProductBreakdownFilter() {
    const input = document.getElementById('productBreakdownFilter');
    const body  = document.getElementById('productBreakdownTable');
    if (!input || !body) return;
    const needle = String(input.value || '').trim().toLowerCase();
    body.querySelectorAll('tr').forEach((row) => {
      row.style.display = needle === '' || String(row.textContent || '').toLowerCase().includes(needle) ? '' : 'none';
    });
  }

  function initProductBreakdownModal() {
    const modal    = document.getElementById('productBreakdownModal');
    const backdrop = document.getElementById('productBreakdownBackdrop');
    const closeBtn = document.getElementById('closeProductBreakdown');
    const filter   = document.getElementById('productBreakdownFilter');
    if (!modal || !backdrop) return;

    const close = () => {
      modal.hidden = true; backdrop.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
    };
    window.closeProductBreakdown = close;
    closeBtn?.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    modal.addEventListener('click', (e) => e.stopPropagation());
    filter?.addEventListener('input', applyProductBreakdownFilter);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) close(); });
  }

  window.openProductBreakdown = async function (productCode, productLabel) {
    const modal    = document.getElementById('productBreakdownModal');
    const backdrop = document.getElementById('productBreakdownBackdrop');
    const title    = document.getElementById('productBreakdownTitle');
    const subtitle = document.getElementById('productBreakdownSubtitle');
    const body     = document.getElementById('productBreakdownTable');
    const empty    = document.getElementById('productBreakdownEmpty');
    const loading  = document.getElementById('productBreakdownLoading');
    const meta     = document.getElementById('productBreakdownMeta');
    const filter   = document.getElementById('productBreakdownFilter');
    if (!modal || !backdrop || !body) return;

    if (filter) filter.value = '';
    modal.hidden = false; backdrop.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    if (title)   title.textContent   = productLabel || 'Produto sem descricao';
    if (subtitle) subtitle.textContent = productCode ? `SKU ${productCode}` : 'Sem SKU';
    if (meta)    meta.innerHTML  = '';
    if (loading) loading.hidden  = false;
    if (empty)   empty.hidden    = true;
    body.innerHTML = '';

    try {
      const data           = await fetchJson(buildApiUrl('api/products.php', false, { product_code: productCode || '', limit: 200 }));
      const rows           = Array.isArray(data.breakdown) ? data.breakdown : [];
      const breakdownState = B.resolveProductBreakdownState(data);
      if (meta) {
        meta.innerHTML = [
          `<span class="mag-status-chip mag-status-live">${formatNumber(rows.length)} documento(s)</span>`,
          data.meta && data.meta.coverage_actual && data.meta.coverage_actual.end ? `<span class="mag-status-chip mag-status-demo">Cobertura ate ${B.formatMetaDate(data.meta.coverage_actual.end)}</span>` : '',
          data.meta && data.meta.signal_status === 'partial_window' ? '<span class="mag-status-chip mag-status-warning">Cobertura parcial</span>' : '',
          breakdownState.source ? `<span class="mag-status-chip mag-status-live">${escapeHtml(String(breakdownState.source).replace(/-/g, ' '))}</span>` : '',
        ].filter(Boolean).join(' ');
      }
      body.innerHTML = B.renderProductBreakdownRows(rows);
      if (empty) {
        empty.hidden = rows.length > 0;
        if (!rows.length) empty.textContent = breakdownState.reason || 'Nenhum documento encontrado para este produto no periodo selecionado.';
      }
    } catch (error) {
      if (empty) { empty.hidden = false; empty.textContent = (error && error.message) || 'Nao foi possivel carregar o detalhamento do produto.'; }
    } finally {
      if (loading) loading.hidden = true;
    }
  };

  // ── Sync operations ───────────────────────────────────────────────────────

  let loadController = null;
  const LOADING_MIN_MS = 420;

  function waitFor(ms) { return new Promise((resolve) => window.setTimeout(resolve, Math.max(0, ms))); }

  async function pollRefreshStatus(service) {
    const feedback = document.getElementById('syncFeedback');
    const syncStateLabels = { queued: 'Sincronizacao agendada, aguardando worker...', pending: 'Sincronizacao na fila de processamento...', running: 'Sincronizacao canonica em andamento...', processing: 'Processando dados do periodo...', syncing: 'Buscando dados no ERP...', finalizing: 'Finalizando snapshot do periodo...' };
    for (let attempt = 0; attempt < 20; attempt += 1) {
      await new Promise((resolve) => window.setTimeout(resolve, 1500));
      const data = await fetchJson(buildApiUrl('api/refresh_status.php', false, { service }), { cache: 'no-store', timeoutMs: 10000 });
      if (['done', 'ready'].includes(String(data.state || '').toLowerCase())) return data;
      if (String(data.state || '').toLowerCase() === 'failed') throw new Error((data.job && data.job.last_error) || 'Falha ao sincronizar. Tente novamente em instantes.');
      const stateLabel = syncStateLabels[String(data.state || '').toLowerCase()] || `Atualizando (${escapeHtml(String(data.state || ''))})...`;
      if (feedback) feedback.innerHTML = `<div class="alert alert-secondary">${stateLabel}</div>`;
    }
    throw new Error('A atualizacao demorou mais que o esperado. Recarregue em instantes.');
  }

  async function syncNow(event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const feedback  = document.getElementById('syncFeedback');
    const csrfToken = document.querySelector('meta[name="biomie-csrf"]') && document.querySelector('meta[name="biomie-csrf"]').getAttribute('content') || '';
    const body      = new URLSearchParams();
    if (csrfToken) body.set('csrf_token', csrfToken);
    if (feedback) feedback.innerHTML = '<div class="alert alert-secondary">Agendando sincronizacao comercial...</div>';
    try {
      const { response, payload } = await window.fetchApi(buildApiUrl('api/sync.php', false), { method: 'POST', cache: 'no-store', headers: { Prefer: 'respond-async', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString(), timeoutMs: 15000 });
      if (feedback) feedback.innerHTML = `<div class="alert alert-success">${escapeHtml((payload && payload.message) || 'Sincronizacao iniciada.')}</div>`;
      if (response.status === 202 || String((payload && payload.mode) || '').toLowerCase() === 'queued') { await pollRefreshStatus('sales'); await loadCurrentPage(false); return; }
      await loadCurrentPage(true);
    } catch (error) {
      const extra = error && error.code === 419 ? ' Recarregue a pagina para renovar a sessao.' : '';
      if (feedback) feedback.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message + extra)}</div>`;
    } finally { window.setTimeout(() => { if (feedback) feedback.innerHTML = ''; }, 5000); }
  }

  async function syncCanonical(event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const feedback  = document.getElementById('syncFeedback');
    const btn       = document.getElementById('syncCanonical');
    const csrfToken = document.querySelector('meta[name="biomie-csrf"]') && document.querySelector('meta[name="biomie-csrf"]').getAttribute('content') || '';
    const body      = new URLSearchParams();
    if (csrfToken) body.set('csrf_token', csrfToken);
    if (btn) btn.disabled = true;
    if (feedback) feedback.innerHTML = '<div class="alert alert-secondary">Iniciando sync canônico — buscando dados no Omie. Isso pode levar alguns minutos...</div>';
    try {
      const { payload } = await window.fetchApi(buildApiUrl('api/canonical_sync.php', false), { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString(), timeoutMs: 320000 });
      if (feedback) feedback.innerHTML = `<div class="alert alert-success">${escapeHtml((payload && payload.message) || 'Sync canonico concluido.')}</div>`;
      await loadCurrentPage(false);
    } catch (error) {
      const extra = error && (error.code === 401 || error.code === 403) ? ' Apenas administradores podem disparar o sync canonico.' : '';
      if (feedback) feedback.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}${extra}</div>`;
    } finally {
      if (btn) btn.disabled = false;
      window.setTimeout(() => { if (feedback) feedback.innerHTML = ''; }, 8000);
    }
  }

  async function syncFinance(event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const feedback  = document.getElementById('syncFeedback');
    const btn       = document.getElementById('syncFinance');
    const csrfToken = document.querySelector('meta[name="biomie-csrf"]') && document.querySelector('meta[name="biomie-csrf"]').getAttribute('content') || '';
    const body      = new URLSearchParams();
    if (csrfToken) body.set('csrf_token', csrfToken);
    if (btn) btn.disabled = true;
    if (feedback) feedback.innerHTML = '<div class="alert alert-secondary">Agendando sync financeiro...</div>';
    try {
      const { response, payload } = await window.fetchApi(buildApiUrl('api/finance_sync.php', false), { method: 'POST', cache: 'no-store', headers: { Prefer: 'respond-async', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString(), timeoutMs: 15000 });
      if (feedback) feedback.innerHTML = `<div class="alert alert-success">${escapeHtml((payload && payload.message) || 'Sync financeiro iniciado.')}</div>`;
      if (response.status === 202 || String((payload && payload.mode) || '').toLowerCase() === 'queued') await pollRefreshStatus('finance');
      await loadCurrentPage(false);
    } catch (error) {
      if (feedback) feedback.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
    } finally {
      if (btn) btn.disabled = false;
      window.setTimeout(() => { if (feedback) feedback.innerHTML = ''; }, 8000);
    }
  }

  // ── Navigation helpers ────────────────────────────────────────────────────

  function setDefaultMonth() {
    if (window.NavContext && window.NavContext.applyToInputs()) return;
    const startInput = document.getElementById('dateStart');
    const endInput   = document.getElementById('dateEnd');
    if (!startInput || !endInput || startInput.value || endInput.value) return;
    applyPresetRange('month');
  }

  function initMenuToggle() {
    if (document.querySelector('.mag-sidebar')) return;
    const toggle = document.getElementById('menu_toggle');
    if (!toggle) return;
    toggle.addEventListener('click', (e) => {
      e.preventDefault();
      document.body.classList.toggle('nav-md');
      document.body.classList.toggle('nav-sm');
    });
  }

  function highlightCurrentMenu() {
    if (document.querySelector('.mag-sidebar')) return;
    const current = window.location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('#sidebar-menu a[href]').forEach((link) => {
      if (link.getAttribute('href') === current) {
        link.parentElement.classList.add('current-page');
        link.style.color        = B.getCssVar('--page-accent') || '#96140a';
        link.style.background   = B.getCssVar('--red-dim')    || 'rgba(150,20,10,0.22)';
        link.style.borderRadius = '10px';
      }
    });
  }

  function initRangePresets() {
    document.querySelectorAll('[data-range-preset]').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyPresetRange(btn.dataset.rangePreset);
        if (window.NavContext) {
          const r = getDateRange();
          window.NavContext.setDateRange(r.start, r.end, btn.dataset.rangePreset);
          window.NavContext.pushState();
        }
        loadCurrentPage(false);
      });
    });
  }

  async function loadCurrentPage(force, silent) {
    silent = silent || false;
    const page             = (document.body.dataset && document.body.dataset.page) || 'dashboard';
    const loading          = document.getElementById('pageLoading');
    const errorTarget      = document.getElementById('pageError');
    const loadingStartedAt = loading ? performance.now() : 0;
    if (loading && !silent) loading.classList.remove('d-none');
    if (errorTarget) errorTarget.classList.add('d-none');
    if (loadController) loadController.abort();
    loadController = new AbortController();
    try {
      const options = { cache: 'no-store', signal: loadController.signal, timeoutMs: 30000 };
      if (['vendors', 'time-comercial'].includes(page))                           await loadVendors(force, options);
      else if (['orders', 'nf-periodo'].includes(page))                           await loadOrders(force, options);
      else if (['products', 'mix-faturado'].includes(page))                       await loadProducts(force, options);
      else if (['integrations', 'omie'].includes(page))                           await loadIntegrations(options);
      else if (['index', 'dashboard', 'indicadores', 'analises', 'mesa'].includes(page)) await loadDashboard(force, options);
      else return;
    } catch (error) {
      if (error && error.name === 'AbortError') return;
      if (errorTarget) {
        errorTarget.classList.remove('d-none');
        const extra = error && error.code === 419 ? '<div class="small mt-2">Recarregue a pagina para renovar a sessao.</div>' : '';
        errorTarget.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message)}${extra}</div>`;
      }
    } finally {
      if (loading && !silent) {
        const elapsed = performance.now() - loadingStartedAt;
        if (elapsed < LOADING_MIN_MS) await waitFor(LOADING_MIN_MS - elapsed);
        loading.classList.add('d-none');
      }
    }
  }

  // ── Bootstrap ─────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', () => {
    setDefaultMonth();
    if (window.NavContext) {
      const r = getDateRange();
      window.NavContext.setDateRange(r.start, r.end);
      window.NavContext.pushState();
    }
    initProductBreakdownModal();
    initMenuToggle();
    highlightCurrentMenu();
    initRangePresets();
    document.getElementById('applyFilters')?.addEventListener('click', () => {
      if (window.NavContext) { const r = getDateRange(); window.NavContext.setDateRange(r.start, r.end); window.NavContext.pushState(); }
      loadCurrentPage(false);
    });
    document.getElementById('syncNow')?.addEventListener('click', syncNow);
    document.getElementById('syncSnapshot')?.addEventListener('click', syncNow);
    document.getElementById('syncCanonical')?.addEventListener('click', syncCanonical);
    document.getElementById('syncFinance')?.addEventListener('click', syncFinance);
    document.getElementById('closeDrawerBtn')?.addEventListener('click', window.closeVendorDrawer);
    document.getElementById('flightDrawerBackdrop')?.addEventListener('click', window.closeVendorDrawer);

    const hydratedFromBootstrap = hydrateDashboardFromBootstrap();
    if (!hydratedFromBootstrap) {
      loadCurrentPage(false);
    } else {
      fetchJson('api/weather.php', { timeoutMs: 10000 }).then((data) => B.renderWeather(data)).catch(() => {});
    }

    if (window.EventSource && ['index', 'dashboard'].includes((document.body.dataset && document.body.dataset.page) || 'dashboard')) {
      const sse = new window.EventSource('api/live_stream.php');
      sse.addEventListener('refresh_dashboard', (e) => {
        try { JSON.parse(e.data); loadCurrentPage(false, true); } catch (err) {}
      });
    }
  });
})();
