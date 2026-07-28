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
  const lazyCharts = new Map();
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
    const previous = lazyCharts.get(canvasId);
    if (previous && previous.observer) previous.observer.disconnect();
    if (!window.IntersectionObserver) {
      lazyCharts.delete(canvasId);
      renderFn();
      return;
    }
    const token = {};
    const observer = new IntersectionObserver(
      (entries, obs) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          const pending = lazyCharts.get(canvasId);
          if (!pending || pending.token !== token) { obs.disconnect(); return; }
          lazyCharts.delete(canvasId);
          obs.disconnect();
          renderFn();
        });
      },
      { rootMargin: '100px' }
    );
    lazyCharts.set(canvasId, { observer, token });
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
  function isVendorsPageKey()    { return ['vendors', 'time-comercial'].includes(currentPageKey()); }

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
    const code        = String((row && (row.codigo || row.nf_code || row.numero_nf)) || 'Documento');
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
    const rawNotaKey   = String((row && row.nota_key) || '');
    const rawChaveNfe  = String((row && (row.chave_nfe || row.document_key)) || '');
    const notaKey      = escapeHtml(rawNotaKey);
    const chaveNfe     = /^\d{44}$/.test(rawChaveNfe.replace(/\D/g, '')) ? escapeHtml(rawChaveNfe.replace(/\D/g, '')) : '';
    const numeroNf     = type === 'pending-order' ? '' : escapeHtml(String((row && (row.numero_nf || row.nf_code || row.codigo)) || ''));
    const vendorId     = escapeHtml(String((row && (row.vendor_id || row.vendor_ref || row.codigo_vendedor_omie)) || ''));
    const vendorName   = escapeHtml(String((row && row.vendor_name) || ''));
    const originOrder  = escapeHtml(String((row && (row.codigo_integracao || row.origin_order_code)) || ''));
    return `<button type="button" class="row-inline-link row-inline-link--document" data-invoice-detail-trigger="1" data-invoice-entry-id="${entryId}" data-biomie-action="${biomieAction}" data-row-type="${escapeHtml(type)}" data-codigo="${escapeHtml(code)}" data-numero-nf="${numeroNf}" data-chave-nfe="${chaveNfe}" data-nota-key="${notaKey}" data-vendor-id="${vendorId}" data-vendor-name="${vendorName}" data-origin-order="${originOrder}" aria-label="Abrir detalhes de ${escapeHtml(code)}">${contentHtml}</button>`;
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
    if (vendorDeepLinkHandled || !isVendorsPageKey()) return;
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

  function normalizeProductScope(scope) {
    const value = String(scope || '').toLowerCase().trim();
    if (['remittance', 'remessa', 'remessas'].includes(value)) return 'remittance';
    if (['all', 'todas', 'todos'].includes(value)) return 'all';
    return 'commercial';
  }

  function productScopeLabel(scope) {
    const value = normalizeProductScope(scope);
    if (value === 'remittance') return 'Remessas';
    if (value === 'all') return 'Todas';
    return 'Orc-PD';
  }

  function getProductScope() {
    if (window.BIomieProductScope) return normalizeProductScope(window.BIomieProductScope);
    const urlScope = new URLSearchParams(window.location.search).get('scope');
    return normalizeProductScope(urlScope || 'commercial');
  }

  function setProductScope(scope, updateUrl) {
    const value = normalizeProductScope(scope);
    window.BIomieProductScope = value;
    document.querySelectorAll('[data-product-scope]').forEach((btn) => {
      const active = normalizeProductScope(btn.getAttribute('data-product-scope')) === value;
      btn.classList.toggle('product-scope-chip--active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    document.querySelectorAll('[data-product-scope-label]').forEach((el) => {
      el.textContent = productScopeLabel(value);
    });
    if (document.body && ['products', 'mix-faturado'].includes(document.body.dataset.page || '')) {
      const label = productScopeLabel(value);
      const kpiLabels = Array.from(document.querySelectorAll('.biomie-kpi-card__label'));
      if (kpiLabels[0]) kpiLabels[0].textContent = 'Mercadoria ' + label;
      if (kpiLabels[1]) kpiLabels[1].textContent = 'Faturamento ' + label;
      if (kpiLabels[4]) kpiLabels[4].textContent = 'Ticket medio ' + label;
      if (kpiLabels[5]) kpiLabels[5].textContent = 'NFs ' + label;
      const heroLabels = Array.from(document.querySelectorAll('.hero-chip-label'));
      if (heroLabels[0]) heroLabels[0].textContent = 'Mercadoria ' + label;
      if (heroLabels[1]) heroLabels[1].textContent = 'Faturamento ' + label;
      const forecast = document.querySelector('.forecast-label');
      if (forecast) forecast.textContent = 'Ticket medio ' + label;
    }
    const hint = document.getElementById('productScopeHint');
    if (hint) {
      hint.textContent = value === 'remittance'
        ? 'Remessas isoladas do mix comercial Orc-PD. Licitacao continua excluida.'
        : (value === 'all'
          ? 'Visao combinada: Orc-PD + Remessas. Licitacao permanece excluida.'
          : 'Orc-PD exclui Licitacao e Remessas. Use os chips para investigar remessas separadamente.');
    }
    if (updateUrl && window.history && history.replaceState) {
      const url = new URL(window.location.href);
      if (value === 'commercial') url.searchParams.delete('scope');
      else url.searchParams.set('scope', value);
      history.replaceState(null, '', url.toString());
    }
  }

  function initProductScopeSwitcher() {
    if (!document.getElementById('productScopeSwitcher')) return;
    setProductScope(getProductScope(), false);
    document.querySelectorAll('[data-product-scope]').forEach((btn) => {
      if (btn.dataset.scopeBound === '1') return;
      btn.dataset.scopeBound = '1';
      btn.addEventListener('click', () => {
        setProductScope(btn.getAttribute('data-product-scope'), true);
        document.dispatchEvent(new CustomEvent('biomie:product-scope-change', { detail: { scope: getProductScope() } }));
        loadCurrentPage(false);
      });
    });
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
      function firstArray() {
        const values = Array.prototype.slice.call(arguments);
        return values.find((value) => Array.isArray(value) && value.length > 0)
          || values.find((value) => Array.isArray(value))
          || [];
      }
      return {
        kpis: payload.kpis || payload.cards || {},
        revenue_evolution: payload.revenue_evolution || payload.revenueEvolution || payload.series || [],
        vendor_ranking: payload.vendor_ranking || payload.vendorRanking || payload.vendors || [],
        top_products: payload.top_products || payload.topProducts || [],
        top_customers: firstArray(payload.top_clients, payload.top_customers, payload.topCustomers),
        top_invoices: payload.top_invoices || payload.topInvoices || payload.top_orders || payload.maiores_notas || [],
        geo_compact: payload.geo_compact || payload.geoCompact || payload.geo || null,
        pipeline_period: payload.pipeline_period || payload.pipelinePeriod || payload.pipeline || null,
        monthly_comparison: (function () {
          // Handle rich object (Phase D) — extract rows for chart; keep full object for renderMonthlyComparison
          var mc = payload.month_comparison || payload.monthly_comparison || payload.monthlyComparison || payload.monthComparison;
          if (mc && typeof mc === 'object' && !Array.isArray(mc) && Array.isArray(mc.rows)) return mc.rows;
          return firstArray(payload.month_comparison, payload.monthly_comparison, payload.monthlyComparison, payload.monthComparison);
        })(),
        month_comparison_full: (function () {
          var mc = payload.month_comparison || payload.monthly_comparison || payload.monthlyComparison || payload.monthComparison;
          if (mc && typeof mc === 'object' && !Array.isArray(mc) && mc.current) return mc;
          return null;
        })(),
        finance_summary: payload.finance_summary || payload.financeSummary || payload.financial_summary || null,
        finance_risk: payload.finance_risk || payload.financeRisk || payload.health || null,
        attention_points: payload.attention_points || payload.attentionPoints || payload.alerts || [],
        attention_signals: firstArray(payload.attention_signals, payload.attentionSignals, payload.data && payload.data.attention_signals),
        attention_summary: payload.attention_summary || (payload.data && payload.data.attention_summary) || null
      };
    }
    const normalized = normalizeOverviewPayload(overview);
    const topOrders     = Array.isArray(normalized.top_invoices) ? normalized.top_invoices : [];
    const topProducts   = Array.isArray(normalized.top_products) ? normalized.top_products : [];
    const topClientsRaw = Array.isArray(normalized.top_customers) ? normalized.top_customers : [];
    const topClients    = normalizeTopClientRows(topClientsRaw);
    const monthComparison = Array.isArray(normalized.monthly_comparison)
      ? normalized.monthly_comparison
      : (Array.isArray(overview && overview.month_comparison) ? overview.month_comparison : []);

    function firstFilled(row, keys) {
      for (const key of keys) {
        if (row && row[key] !== undefined && row[key] !== null && String(row[key]).trim() !== '') {
          return row[key];
        }
      }
      return '';
    }

    function toFiniteNumber(value) {
      if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
      const raw = String(value || '').trim();
      if (!raw) return 0;
      const normalizedValue = raw
        .replace(/[^\d,.-]/g, '')
        .replace(/\.(?=\d{3}(\D|$))/g, '')
        .replace(',', '.');
      const parsed = Number(normalizedValue);
      return Number.isFinite(parsed) ? parsed : 0;
    }

    function normalizeTopClientRows(rows) {
      return (rows || []).map((row) => {
        const invoiceTotal = toFiniteNumber(firstFilled(row, ['invoice_total', 'gross_revenue', 'revenue', 'total', 'value']));
        const notesCount = Math.max(0, Math.round(toFiniteNumber(firstFilled(row, ['notes_count', 'orders', 'count', 'notas', 'notas_count', 'quantity']))));
        const avgTicket = toFiniteNumber(firstFilled(row, ['avg_ticket', 'ticket_avg', 'average_ticket', 'ticket_medio', 'ticket']));
        return {
          client_id: String(firstFilled(row, ['client_id', 'codigo_cliente_omie', 'customer_id', 'id'])),
          client_name: String(firstFilled(row, ['client_name', 'customer_name', 'cliente', 'name', 'label']) || 'Cliente nao informado'),
          client_document: String(firstFilled(row, ['client_document', 'documento_cliente', 'document', 'cpf_cnpj'])),
          invoice_total: invoiceTotal,
          notes_count: notesCount,
          avg_ticket: avgTicket > 0 ? avgTicket : (notesCount > 0 ? invoiceTotal / notesCount : 0),
          last_purchase: String(firstFilled(row, ['last_purchase', 'ultima_compra', 'last_order_date', 'last_invoice_date', 'issue_date', 'competencia_date', 'date'])),
          share_pct: toFiniteNumber(firstFilled(row, ['share_pct', 'share', 'concentration_pct']))
        };
      }).filter((row) => row.invoice_total > 0 || row.notes_count > 0 || row.client_name.trim() !== '');
    }

    function getChartPanel(canvasId) {
      const canvas = document.getElementById(canvasId);
      return canvas ? canvas.closest('.dashboard-panel') : null;
    }

    function getPanelBody(container) {
      return container.querySelector('.dashboard-panel__body') || container.querySelector('.panel-body') || container;
    }

    function clearPanelState(container) {
      if (!container) return;
      const target = getPanelBody(container);
      Array.from(target.children).forEach((child) => {
        if (child.classList && child.classList.contains('dashboard-empty-state')) child.remove();
        else child.hidden = false;
      });
    }

    function renderPanelState(container, state) {
      if (!container) return;
      state = state || {};
      const target = getPanelBody(container);
      Array.from(target.children).forEach((child) => {
        if (!(child.classList && child.classList.contains('dashboard-empty-state'))) child.hidden = true;
      });
      const facts = Array.isArray(state.facts) && state.facts.length
        ? `<dl class="dashboard-empty-state__facts">${state.facts.map((item) => `<div><dt>${escapeHtml(item[0])}</dt><dd>${escapeHtml(String(item[1]))}</dd></div>`).join('')}</dl>`
        : '';
      const diagnostics = state.diagnostics || {};
      const diagnosticRows = Object.keys(diagnostics).length
        ? `<details style="margin-top:12px;"><summary style="font-size:11px; cursor:pointer; color:var(--text-3);">Diagnostico tecnico</summary><div class="small text-muted" style="margin-top:8px; font-family:monospace; font-size:10px;">${Object.entries(diagnostics).map(([k, v]) => `${escapeHtml(k)}=${escapeHtml(String(v))}`).join('<br>')}</div></details>`
        : '';
      let empty = Array.from(target.children).find((child) => child.classList && child.classList.contains('dashboard-empty-state'));
      if (!empty) {
        empty = document.createElement('div');
        empty.className = 'dashboard-empty-state';
        target.appendChild(empty);
      }
      empty.hidden = false;
      empty.innerHTML = `
        <span class="material-symbols-rounded">${escapeHtml(state.icon || (state.state === 'error' ? 'error' : 'info'))}</span>
        <strong>${escapeHtml(state.title || 'Dados indisponiveis')}</strong>
        <p>${escapeHtml(state.message || 'Dados nao enviados pela API para este painel.')}</p>
        ${facts}
        ${diagnosticRows}
      `;
    }
    B.renderPanelState = renderPanelState;
    // Alias global — compatibilidade com chamadas legado fora do namespace.
    window.renderPanelState = window.renderPanelState || renderPanelState;

    function sourceBadge(source) {
      if (B.sourceBadge) return B.sourceBadge(source);
      const src = String(source || 'empty');
      return `<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;background:var(--text-3,#adb5bd);color:#fff;margin-right:4px;">${escapeHtml(src)}</span>`;
    }

    function freshnessBadge(status) {
      if (B.freshnessBadge) return B.freshnessBadge(status);
      const key = String(status || 'unknown').toLowerCase();
      return `<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;background:var(--text-3,#adb5bd);color:#fff;">${escapeHtml(key)}</span>`;
    }

    function renderDashboardEmptyState(container, opts) {
      if (!container) return;
      opts = opts || {};
      // Destroy any active Chart.js instances inside this panel before replacing with empty state
      container.querySelectorAll('canvas[id]').forEach((canvas) => {
        try { if (typeof B._destroyChart === 'function') B._destroyChart(canvas.id); } catch (e) { /* ignore */ }
        if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.destroyChart === 'function') {
          try { window.BIOMIE_CHARTS.destroyChart(canvas.id); } catch (e) { /* ignore */ }
        }
      });
      const target = getPanelBody(container);
      Array.from(target.children).forEach((child) => {
        if (!(child.classList && child.classList.contains('dashboard-empty-state'))) child.hidden = true;
      });
      const icon = opts.state === 'error' ? 'error' : (opts.reasonCode === 'sync_stale' ? 'sync_problem' : (opts.reasonCode === 'mart_not_materialized' ? 'manufacturing' : 'search_off'));
      const actionHtml = opts.action ? `<p style="font-size:11px;margin-top:8px;color:var(--text-3)"><strong>Acao:</strong> ${escapeHtml(opts.action)}</p>` : '';
      const badgesHtml = `<div style="margin-top:10px;">${sourceBadge(opts.source)}${freshnessBadge(opts.freshnessStatus)}</div>`;
      const diagLink = opts.requestId ? `<details style="margin-top:10px;"><summary style="font-size:11px;cursor:pointer;color:var(--text-3);">Ver diagnostico</summary><div style="font-size:10px;font-family:monospace;margin-top:4px;color:var(--text-3);">request_id: ${escapeHtml(String(opts.requestId))}<br>reason_code: ${escapeHtml(String(opts.reasonCode || '-'))}</div></details>` : '';
      let empty = Array.from(target.children).find((child) => child.classList && child.classList.contains('dashboard-empty-state'));
      if (!empty) { empty = document.createElement('div'); empty.className = 'dashboard-empty-state'; target.appendChild(empty); }
      empty.hidden = false;
      empty.innerHTML = `
        <span class="material-symbols-rounded">${escapeHtml(icon)}</span>
        <strong>${escapeHtml(opts.title || 'Dados indisponiveis')}</strong>
        <p>${escapeHtml(opts.message || 'Dados nao disponiveis para este painel no periodo selecionado.')}</p>
        ${actionHtml}
        ${badgesHtml}
        ${diagLink}
      `;
    }
    B.renderDashboardEmptyState = renderDashboardEmptyState;

    function resolvePanelContainer(containerOrId, expectedKey) {
      if (containerOrId && containerOrId.nodeType === 1) return containerOrId;
      if (typeof containerOrId === 'string' && containerOrId) {
        const byId = document.getElementById(containerOrId);
        if (byId) return byId;
      }
      return document.querySelector(`[data-chart-id="${expectedKey}"]`);
    }

    function renderPanelFallback(containerOrId, expectedKey, availableKeys, options) {
      const container = resolvePanelContainer(containerOrId, expectedKey);
      if (!container) return;
      options = options || {};
      const meta = (overview && overview.meta) || {};
      const diag = {
        expected_key: expectedKey,
        endpoint: 'api/overview.php',
        period: `${meta.period_start || getDateRange().start || '-'}..${meta.period_end || getDateRange().end || '-'}`,
        source: meta.source_of_truth || '-',
        cache: meta.cache_status || '-',
        duration: meta.duration_ms ? `${meta.duration_ms}ms` : '-',
        available_keys: (availableKeys || []).join(',') || '-'
      };
      if (meta.datasets_present && !meta.datasets_present.includes(expectedKey)) {
        diag.reason = 'dataset ausente no payload';
      }
      Object.assign(diag, options.diagnostics || {});
      renderPanelState(container, {
        state: 'empty',
        title: options.title || 'Dados indisponiveis',
        message: options.message || 'Nenhum dado retornado para este painel no periodo selecionado.',
        facts: options.facts || [],
        diagnostics: diag
      });
    }

    function topClientsEmptyDiagnostics() {
      const meta = (overview && overview.meta) || {};
      const coverage = meta.coverage_actual || {};
      const possibleKeys = ['top_clients', 'top_customers', 'topCustomers'];
      const receivedKey = possibleKeys.find((key) => overview && Object.prototype.hasOwnProperty.call(overview, key)) || '';
      const rawValue = receivedKey ? overview[receivedKey] : null;
      const invoiceCount = Number(
        summary.orders ||
        statusSummary.billed_orders ||
        coverage.invoice_notes_count ||
        coverage.authorized_notes_count ||
        0
      );
      const rawHasItems = Array.isArray(rawValue) && rawValue.length > 0;
      let reason = 'dataset vazio no payload';
      if (!overview) reason = 'payload overview ausente';
      else if (!receivedKey) reason = 'chaves top_clients/top_customers/topCustomers ausentes';
      else if (!Array.isArray(rawValue)) reason = `chave ${receivedKey} veio em formato invalido`;
      else if (rawHasItems) reason = 'contract_mismatch';
      else if (rawValue.length === 0 && invoiceCount <= 0) reason = 'sem NFs no periodo';
      else if (rawValue.length === 0) reason = `chave ${receivedKey} veio vazia`;

      return {
        invoiceCount,
        reason,
        receivedKey: receivedKey || '-',
        facts: [
          ['Periodo', `${meta.period_start || getDateRange().start || '-'} ate ${meta.period_end || getDateRange().end || '-'}`],
          ['Fonte', meta.served_from || overview?.source || '-'],
          ['Modo', meta.source_of_truth || overview?.mode || '-'],
          ['NFs no periodo', formatNumber(invoiceCount)],
          ['Motivo', reason]
        ]
      };
    }

    function renderTopClientsTable(rows) {
      if (!document.getElementById('topClientsTable')) return;
      B.renderTable('topClientsTable', [
        { render: (row) => `<div class="commercial-entity">${B.commercialIcon('domain')}<div class="commercial-entity__body">${B.renderCustomerIdentityCell(row)}</div></div>` },
        { render: (row) => B.commercialMetric('Valor faturado', formatCurrency(row.invoice_total || 0), 'accent') },
        { render: (row) => B.commercialMetric('Quantidade de NFs', formatNumber(row.notes_count || 0)) },
        { render: (row) => B.commercialMetric('Ticket medio', formatCurrency(row.avg_ticket || 0)) },
        { render: (row) => B.commercialSignal('event', row.last_purchase ? formatMetaDate(row.last_purchase) : 'Sem data', row.last_purchase ? 'neutral' : 'warning') }
      ], rows.slice(0, 5), 'Sem clientes para o periodo.');
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

    document.querySelectorAll('.dashboard-panel').forEach(clearPanelState);

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
    B.createLineChart(
      'salesTimelineChart',
      dailySales.map((r) => chartDateLabel(r.date)),
      dailySales.map((r) => Number(r.gross_revenue != null ? r.gross_revenue : (r.revenue != null ? r.revenue : (r.billed_value || 0)))),
      dailySales.map((r) => Number(r.orders != null ? r.orders : (r.billed_orders || 0))),
      { dates: dailySales.map((r) => r.date || '') }
    );
    B.createRevenueTicketChart('vendorsBarChart', vendorChartRows);

    // Metas do time — realizado × meta (+ níveis das metas múltiplas)
    if (typeof B.createVendorTargetsChart === 'function') {
      var targetsMatrix = (vendors && vendors.vendor_status_matrix) || vendorChartRows || [];
      lazyChart('vendorTargetsChart', function () { B.createVendorTargetsChart('vendorTargetsChart', targetsMatrix); });
    }

    // Row 2: Top Products chart + Top Clients chart + Pipeline (lazy)
    // 12 itens (a API já entrega 12): os cards esticam na grade e ficavam com
    // espaço vazio mostrando só 8.
    const TOP_N = 12;
    if (topProducts.length > 0) {
      clearPanelState(getChartPanel('topProductsChart'));
      if (typeof B.createHorizontalBarChart === 'function') {
        lazyChart('topProductsChart', () => B.createHorizontalBarChart(
          'topProductsChart',
          topProducts.slice(0, TOP_N).map((r) => escapeHtml(String(r.description || r.label || '').substring(0, 28))),
          topProducts.slice(0, TOP_N).map((r) => Number(r.revenue || r.value || 0)),
          { barColor: 'rgba(37,99,235,0.7)', drillChartId: 'top_products', drillTitle: 'Produtos em Destaque' }
        ));
      }
    } else {
      renderPanelFallback(getChartPanel('topProductsChart'), 'top_products', availableKeys);
    }

    if (topClients.length > 0) {
      clearPanelState(getChartPanel('topClientsChart'));
      if (typeof B.createHorizontalBarChart === 'function') {
        lazyChart('topClientsChart', () => B.createHorizontalBarChart(
          'topClientsChart',
          topClients.slice(0, TOP_N).map((r) => escapeHtml(String(r.client_name || '').substring(0, 28))),
          topClients.slice(0, TOP_N).map((r) => Number(r.invoice_total || 0)),
          { barColor: 'rgba(22,163,74,0.7)', drillChartId: 'top_customers', drillTitle: 'Clientes em Destaque' }
        ));
      }
      renderTopClientsTable(topClients);
    } else {
      const emptyInfo = topClientsEmptyDiagnostics();
      const topClientsEmptyState = (overview && overview.empty_states && overview.empty_states.top_clients) || null;
      const tcMeta = (overview && overview.meta) || {};
      renderDashboardEmptyState(getChartPanel('topClientsChart'), {
        title: (topClientsEmptyState && topClientsEmptyState.title) || 'Clientes em destaque indisponiveis',
        message: (topClientsEmptyState && topClientsEmptyState.message) || 'O endpoint respondeu, mas nao trouxe clientes para compor o ranking do periodo.',
        action: (topClientsEmptyState && topClientsEmptyState.action) || null,
        reasonCode: (topClientsEmptyState && topClientsEmptyState.reason_code) || emptyInfo.reason,
        source: tcMeta.served_from || (overview && overview.source) || 'canonical',
        freshnessStatus: (overview && overview.freshness && overview.freshness.canonical_status) || 'unknown',
        requestId: tcMeta.request_id || null
      });
    }
    lazyChart('dailyMixedChart', () => B.createMixedDailyChart('dailyMixedChart', Array.isArray(vendors && vendors.daily_status) && vendors.daily_status.length ? vendors.daily_status : B.overviewSalesAsStatus(dailySales)));
    const monthComparisonCanvas = document.getElementById('monthComparisonChartH1')
      ? 'monthComparisonChartH1'
      : 'monthComparisonChart';
    if (monthComparison.length > 0) {
      clearPanelState(getChartPanel(monthComparisonCanvas));
      if (
        monthComparisonCanvas === 'monthComparisonChartH1'
        && document.getElementById('monthComparisonChartH2')
        && typeof B.createMonthlyComparisonSemesters === 'function'
      ) {
        lazyChart('monthComparisonChartH1', () => B.createMonthlyComparisonSemesters(monthComparison));
      } else {
        lazyChart(monthComparisonCanvas, () => B.createMonthlyComparisonChart(monthComparisonCanvas, monthComparison));
      }
    } else {
      const monthCompEmptyState = (overview && overview.empty_states && overview.empty_states.month_comparison) || null;
      const mcMeta = (overview && overview.meta) || {};
      renderDashboardEmptyState(getChartPanel(monthComparisonCanvas), {
        title: (monthCompEmptyState && monthCompEmptyState.title) || 'Comparativo mensal indisponivel',
        message: (monthCompEmptyState && monthCompEmptyState.message) || 'O endpoint respondeu, mas nao trouxe linhas para o comparativo mensal.',
        action: (monthCompEmptyState && monthCompEmptyState.action) || null,
        reasonCode: (monthCompEmptyState && monthCompEmptyState.reason_code) || 'not_enough_months',
        source: mcMeta.served_from || (overview && overview.source) || 'canonical',
        freshnessStatus: (overview && overview.freshness && overview.freshness.canonical_status) || 'unknown',
        requestId: mcMeta.request_id || null
      });
    }

    // Row 3: Maiores NFs (table with Emissao + Acao columns)
    if (topOrders.length > 0) {
      B.renderTable('topOrdersTable', [
        { render: (row) => `<div class="commercial-entity">${B.commercialIcon('receipt_long')}<div class="commercial-entity__body"><div class="commercial-entity__title">${renderInvoiceDetailTrigger('order', row, { horizontal: true })}</div>${B.commercialSignal('verified', 'Documento fiscal', 'success')}</div></div>` },
        { render: (row) => B.commercialMetric('Emissao', formatOrderDate(row, true)) },
        { render: (row) => B.renderCustomerIdentityCell(row) },
        { render: (row) => B.renderSellerIdentityCell(row, 'index.html') },
        { render: (row) => B.commercialMetric('Itens', formatNumber(row.items_qty || row.items || 0)) },
        { render: (row) => B.commercialMetric('Base comercial', formatCurrency(row.base_commercial_nf != null ? row.base_commercial_nf : (row.base_commercial != null ? row.base_commercial : (row.gross_total != null ? row.gross_total : (row.total || row.value || 0)))), 'accent') },
        { render: (row) => `<button type="button" class="commercial-action" data-biomie-action="open-invoice" data-nota-key="${escapeHtml(row.nota_key || '')}" data-chave-nfe="${escapeHtml(row.chave_nfe || row.document_key || '')}" data-numero-nf="${escapeHtml(String(row.numero_nf || row.nf_code || row.codigo || ''))}" data-pedido-codigo="${escapeHtml(row.origin_order_code || row.codigo_integracao || '')}"><span class="material-symbols-rounded" aria-hidden="true">open_in_new</span>Detalhar</button>` }
      ], topOrders.slice(0, 5));
    } else {
      renderPanelFallback('tabelas', 'top_invoices', availableKeys);
    }
    B.renderTopOrdersIdentityDiagnostics((overview && overview.diagnostics && overview.diagnostics.top_orders_identity) || null);

    var attSignals = Array.isArray(normalized.attention_signals) && normalized.attention_signals.length
      ? normalized.attention_signals
      : alerts.map(function (a) {
          if (typeof a === 'string') return { severity: 'info', category: 'operacao', title: a, description: '', source: 'legacy', period_basis: null, reason_code: null };
          return {
            severity: a.level === 'danger' ? 'danger' : (a.level || 'info'),
            category: 'operacao',
            title: a.title || 'Sinal',
            description: a.message || '',
            value: a.value != null ? String(a.value) : null,
            source: 'legacy',
            period_basis: 'data_competencia',
            reason_code: null
          };
        });
    B.renderAttentionSignals(
      document.getElementById('alertsList'),
      attSignals,
      normalized.attention_summary,
      (overview && overview.diagnostics && overview.diagnostics.attention) || null
    );
    if (health !== undefined) B.renderCommercialHealth(health || { available: false, message: 'Modulo financeiro indisponivel para este carregamento.' });
    (function renderRemittanceSummary(h) {
      var card = document.getElementById('remittanceSummaryCard');
      var txt  = document.getElementById('remittanceSummaryText');
      var lnk  = document.getElementById('remittanceSummaryLink');
      if (!card || !txt) return;
      var rem = h && h.remittance_summary;
      if (rem && rem.nf_count > 0) {
        txt.textContent = rem.nf_count + ' NF' + (rem.nf_count > 1 ? 's' : '') + ' · ' + rem.total_value;
        if (lnk) {
          var r = getDateRange();
          lnk.href = 'pedidos.php?start=' + encodeURIComponent(r.start) + '&end=' + encodeURIComponent(r.end) + '&scope=remittance';
        }
        card.style.display = 'block';
      } else {
        card.style.display = 'none';
      }
    }(health || null));
    if (normalized.finance_summary && typeof B.renderFinanceOverview === 'function') {
      B.renderFinanceOverview(document.getElementById('financeOverviewPanel'), normalized.finance_summary);
    }
    if (normalized.month_comparison_full && typeof B.renderMonthlyComparison === 'function') {
      B.renderMonthlyComparison(document.getElementById('monthComparisonInsights'), normalized.month_comparison_full);
    }
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
    if (window.BDashboard && typeof window.BDashboard.scopeReady === 'function') {
      await window.BDashboard.scopeReady();
    }
    if (window.BDashboard && typeof window.BDashboard.hasExtraFilters === 'function' && window.BDashboard.hasExtraFilters()) {
      return window.BDashboard.loadFiltered(force, options);
    }
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
    try {
      renderDashboardPayloads(overview, vendors, health, weather, prevOverview);
      document.dispatchEvent(new CustomEvent('biomie:dashboard-standard-rendered'));
    } catch (renderErr) {
      console.error('[BIomie] dashboard render error', renderErr);
      document.querySelectorAll('.dashboard-panel').forEach(function (panel) {
        try {
          renderPanelState(panel, {
            state: 'error',
            title: 'Erro de renderização',
            message: 'Falha ao renderizar este painel. Tente recarregar a página.',
            diagnostics: { error: String(renderErr && renderErr.message || renderErr).substring(0, 120) }
          });
        } catch (_) {}
      });
    }
  }

  async function loadVendors(force, options) {
    options = options || {};
    // COM.R1.5 — pass include_inactive; COM.R2 — pass channel filter
    var vendorExtraParams = {};
    if (window.BIomieVendorsIncludeInactive) vendorExtraParams.include_inactive = '1';
    if (window.BIomieVendorsChannel)         vendorExtraParams.channel           = window.BIomieVendorsChannel;
    const data          = await fetchJson(buildApiUrl('api/vendors.php', force, vendorExtraParams), options);
    const summaryStatus = data.summary_status || {};
    const vendorRoster  = data.meta && data.meta.vendor_roster ? data.meta.vendor_roster : {};
    const statusGapAlert = document.getElementById('vendorStatusGapAlert');
    if (statusGapAlert) {
      statusGapAlert.style.display = vendorRoster.active_status_mapping_gap ? 'flex' : 'none';
    }
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

    const matrix = (data.vendor_status_matrix || []).filter((r) => r.vendor_name !== 'Sem vendedor');
    const chartMatrix = matrix.slice(0, 10);
    window.BIOMIE_VENDOR_MATRIX = matrix;
    window.BIOMIE_VENDOR_DATA = data;
    lazyChart('vendorGroupedBar', () => B.createGroupedBarChart('vendorGroupedBar', chartMatrix));
    lazyChart('dailyMixedChart',  () => B.createMixedDailyChart('dailyMixedChart', data.daily_status || []));
    B.renderPipelineTable('vendorsTable', matrix);
    B.renderOperationalAlerts('alertsOperational', data.alerts_operational || []);

    // COM.R1.4 — Carteirização KPIs
    const goalPeriod      = data.goal_period || {};
    const ownershipSum    = data.ownership_summary || {};
    const kpiSection      = document.getElementById('ownershipKpiSection');
    const kpiMeta         = document.getElementById('kpiMetaProporcional');
    const kpiNf           = document.getElementById('kpiRealizadoNF');
    const kpiCount        = document.getElementById('kpiConflictCount');
    const kpiVal          = document.getElementById('kpiConflictValue');

    if (kpiSection && (Number(goalPeriod.meta_proporcional_total || 0) > 0 || Number(ownershipSum.total_conflicts_out || 0) > 0)) {
      kpiSection.style.display = 'block';
      if (kpiMeta) kpiMeta.textContent = formatCurrency(goalPeriod.meta_proporcional_total || 0);
      if (kpiNf)   kpiNf.textContent   = formatCurrency(goalPeriod.realizado_por_nf        || 0);
      if (kpiCount) {
        var conflictsOut = Number(ownershipSum.total_conflicts_out || 0);
        kpiCount.textContent = conflictsOut > 0 ? conflictsOut + ' NF(s)' : '0';
        kpiCount.style.color = conflictsOut > 0 ? 'var(--warning-text, #b45309)' : 'var(--text-1)';
      }
      if (kpiVal) kpiVal.textContent = formatCurrency(ownershipSum.conflict_value_out || 0) + ' valor';
    }

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

    // H5 — scope tabs (shown only when migration H3 applied and remittance data available)
    const scopeTabsAvailable = !!data.scope_tabs_available;
    const ordersCommercial   = data.orders || [];
    const ordersRemittance   = data.orders_remittance || [];
    const nfScopeTabs        = document.getElementById('nfScopeTabs');
    const nfRemittanceAlert  = document.getElementById('nfRemittanceAlert');
    const nfRemittanceBadge  = document.getElementById('nfRemittanceBadge');
    const requestedNfScope   = new URLSearchParams(window.location.search).get('scope');
    let   activeNfScope      = ['commercial', 'remittance', 'licitacao', 'all'].includes(requestedNfScope)
      ? requestedNfScope
      : 'commercial';

    const NF_SCOPE_PILL = {
      commercial: '<span style="display:inline-block;margin-top:3px;padding:1px 6px;border-radius:20px;font-size:9px;font-weight:700;letter-spacing:.3px;color:#1a7f64;background:#d1fae5;text-transform:uppercase">Orç-PD</span>',
      remittance: '<span style="display:inline-block;margin-top:3px;padding:1px 6px;border-radius:20px;font-size:9px;font-weight:700;letter-spacing:.3px;color:#856000;background:#fef3c7;text-transform:uppercase">Remessa</span>',
    };

    const NF_COL_DEFS = [
      // NF — invoice trigger (without scope pill; Tipo column handles that separately)
      { render: (row) => renderInvoiceDetailTrigger('order', row, { horizontal: true }) },
      // Tipo — scope badge (always shown; distinguishes Orç-PD from Remessa in "all" tab)
      { render: (row) => {
          const scope = row._display_scope || row.operation_scope || 'commercial';
          return NF_SCOPE_PILL[scope] || NF_SCOPE_PILL.commercial;
        }
      },
      { render: (row) => B.renderCustomerIdentityCell(row) },
      { render: (row) => B.renderSellerIdentityCell(row, 'pedidos.html') },
      { render: (row) => formatOrderDate(row, true) },
      { render: (row) => formatCurrency(row.merchandise_total || 0) },
      { render: (row) => row.discount_total ? formatCurrency(row.discount_total) : '<span style="color:var(--text-3)">—</span>' },
      { render: (row) => `<strong>${formatCurrency(row.gross_total != null ? row.gross_total : row.total)}</strong>` },
      // Pedido — origin order code with drawer link when available
      { render: (row) => {
          const code = String(row.origin_order_code || row.codigo_pedido || '').trim();
          return code
            ? `<button type="button" class="row-inline-link" data-biomie-action="open-order" data-pedido-codigo="${B.escapeHtml(code)}">${B.escapeHtml(code)}</button>`
            : '<span style="color:var(--text-3)">—</span>';
        }
      },
    ];

    const NF_SCOPE_META = {
      commercial: { title: 'Faturamento Orçamento-PD', sub: 'Somente NFs Orçamento-PD faturadas. Remessas excluídas.' },
      remittance: { title: 'Remessa de Produto', sub: 'Movimentação fiscal/logística — não compõe faturamento comercial.' },
      licitacao:   { title: 'Licitação', sub: 'NFs de Licitação em escopo separado do Orçamento-PD padrão.' },
      all:        { title: 'Todas as NFs (conferência)', sub: 'Visão fiscal completa: Orçamento-PD + Remessa + Licitação. Use as abas para filtrar.' },
    };

    function renderNfTable(scope) {
      let rows;
      let emptyMsg;
      if (scope === 'remittance') {
        rows = ordersRemittance;
        emptyMsg = 'Nenhuma NF de remessa no periodo.';
      } else if (scope === 'licitacao') {
        rows = [];
        emptyMsg = 'As NFs de Licitação são carregadas pela tabela server-side.';
      } else if (scope === 'all') {
        rows = [
          ...ordersCommercial.map(r => Object.assign({}, r, { _display_scope: 'commercial' })),
          ...ordersRemittance.map(r => Object.assign({}, r, { _display_scope: 'remittance' })),
        ].sort((a, b) => {
          const da = a.data_emissao || a.emissao || '';
          const db = b.data_emissao || b.emissao || '';
          return da < db ? 1 : da > db ? -1 : 0;
        });
        emptyMsg = 'Nenhuma NF autorizada no periodo.';
      } else {
        rows = ordersCommercial;
        emptyMsg = 'Sem NF Orçamento-PD faturada no periodo.';
      }
      B.renderTable('ordersTable', NF_COL_DEFS, rows, emptyMsg);
      if (nfRemittanceAlert) nfRemittanceAlert.style.display = scope === 'remittance' ? 'flex' : 'none';
      var meta = NF_SCOPE_META[scope] || NF_SCOPE_META.commercial;
      var titleEl = document.getElementById('nfScopeTitle');
      var subEl   = document.getElementById('nfScopeSubtitle');
      if (titleEl) titleEl.textContent = meta.title;
      if (subEl)   subEl.textContent   = meta.sub;
      document.querySelectorAll('[data-nf-scope]').forEach((btn) => {
        const isActive = btn.dataset.nfScope === scope;
        btn.classList.toggle('mag-tab--active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });
    }

    if (scopeTabsAvailable && nfScopeTabs) {
      nfScopeTabs.style.display = '';
      if (nfRemittanceBadge && ordersRemittance.length > 0) {
        nfRemittanceBadge.textContent = ordersRemittance.length;
        nfRemittanceBadge.style.display = '';
      }
      nfScopeTabs.querySelectorAll('[data-nf-scope]').forEach((btn) => {
        btn.addEventListener('click', () => {
          activeNfScope = btn.dataset.nfScope;
          renderNfTable(activeNfScope);
        });
      });
    }

    if (typeof window._clearBiomieSearch === 'function') window._clearBiomieSearch();
    renderNfTable(activeNfScope);
    window._nfPageRestore    = function (s) { renderNfTable(s !== undefined ? s : activeNfScope); };
    window._getActiveNfScope = function ()  { return activeNfScope; };

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
    const scope          = getProductScope();
    const data           = await fetchJson(buildApiUrl('api/products.php', force, { scope }), options);
    const breakdownState = B.resolveProductBreakdownState(data);
    const products       = Array.isArray(data.products) ? data.products
      : (Array.isArray(data.top_products) ? data.top_products
      : (Array.isArray(data.topProducts) ? data.topProducts
      : (Array.isArray(data.product_mix) ? data.product_mix
      : (Array.isArray(data.productMix) ? data.productMix : []))));
    const productMix     = Array.isArray(data.product_mix) ? data.product_mix
      : (Array.isArray(data.productMix) ? data.productMix
      : (Array.isArray(data.mix_share) ? data.mix_share : products));
    const emptyState     = data.empty_states && (data.empty_states.products || data.empty_states.products_table || data.empty_states.product_mix);
    B.updateGlobalMeta(data);
    setProductScope((data && data.scope) || (data.meta && data.meta.scope) || scope, false);
    B.renderSummaryCards(data.summary, data.mode);
    // Bind products_count — not in the shared renderSummaryCards but needed by mercadorias.html
    const productsCount = data.summary && data.summary.products_count != null
      ? data.summary.products_count
      : (data.diagnostics && data.diagnostics.distinct_product_count != null ? data.diagnostics.distinct_product_count : null);
    B.setText('[data-bind="summary-products-count"]', productsCount != null ? formatNumber(productsCount) : '-');
    if (products.length) {
      B.createProductMixChart('productsRevenueChart', products);
      B.createProductShareChart('productsShareChart', productMix);
    } else if (typeof B.renderDashboardEmptyState === 'function') {
      B.renderDashboardEmptyState(getChartPanel('productsRevenueChart'), Object.assign({
        title: 'Mix Orç-PD sem detalhes',
        message: 'Os KPIs existem, mas os produtos não foram retornados no contrato da API.',
        action: 'Verifique api/products.php, itens canônicos e materialização do mix.',
        source: data.source || 'empty',
        freshnessStatus: data.freshness && data.freshness.freshness_status,
        requestId: data.diagnostics && data.diagnostics.request_id
      }, emptyState || {}));
      B.renderDashboardEmptyState(getChartPanel('productsShareChart'), Object.assign({
        title: 'Participação do mix indisponível',
        message: 'Sem produtos agrupados para montar a participação do mix Orç-PD.',
        action: 'Validar biomie_notas_fiscais_itens_canonicos no período.',
        source: data.source || 'empty',
        requestId: data.diagnostics && data.diagnostics.request_id
      }, emptyState || {}));
    }
    B.renderTable('productsTable', [
      { render: (row) => B.renderProductBreakdownTrigger(row, breakdownState) },
      { render: (row) => B.commercialMetric('Quantidade', formatNumber(row.quantity)) },
      { render: (row) => B.commercialMetric('Clientes', formatNumber(row.customers_count || 0)) },
      { render: (row) => B.commercialMetric('NFs', formatNumber(row.orders || row.invoices_count)) },
      { render: (row) => B.commercialMetric('Receita', formatCurrency(row.revenue), 'accent') },
      { render: (row) => B.commercialSignal('donut_large', row.share_pct != null ? Number(row.share_pct).toFixed(1) + '% do mix' : 'Sem participacao', row.share_pct != null ? 'neutral' : 'warning') }
    ], products, (emptyState && (emptyState.message || emptyState.title)) || 'Sem item fiscal Orç-PD no periodo.');
    annotateProductRows(products);
    initProductBreakdownDelegation();
    bindProductBreakdownTriggers();
    // Freshness badge
    var genAt = (data.meta && data.meta.generated_at) || (data.freshness && data.freshness.generated_at);
    var ageLabel = B.syncAgeLabel ? B.syncAgeLabel(genAt) : null;
    var freshnessEl = document.getElementById('productFreshnessBar');
    if (freshnessEl) {
      if (ageLabel) {
        var isStale = genAt && (Date.now() - new Date(genAt).getTime()) > 4 * 3600000;
        freshnessEl.innerHTML = '<span class="product-freshness-badge' + (isStale ? ' product-freshness-badge--stale' : '') + '">'
          + '<span class="material-symbols-rounded" style="font-size:14px;vertical-align:middle;">schedule</span>'
          + ' Dados gerados ' + ageLabel
          + '</span>';
        freshnessEl.style.display = '';
      } else {
        freshnessEl.style.display = 'none';
      }
    }
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
    // Guarda o código Omie do vendedor para a aba Orçamentos (Agenda) carregar
    // sob demanda; zera o cache a cada abertura de drawer.
    window._v360VendorId = String(vId || '').trim();
    window._v360QuotesLoaded = false;
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
      let data;
      try {
        data = await fetchJson('api/vendor_detail.php?' + params.toString());
      } catch (primaryError) {
        data = await fetchJson('api/flight_plan_vendor_detail.php?' + params.toString());
      }
      const v    = data.vendor || {};
      if (nameEl)   nameEl.textContent   = v.vendor_name || 'Desconhecido';
      if (statusEl) statusEl.textContent = B.vendorRiskLabel(v.risk_status || '');

      const setT = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
      setT('drawerRealized', formatCurrency(v.realized_period));

      // ── Carteira do vendedor (clientes recorrentes) ──────────────────────
      // Vem PRONTA do servidor (api/vendor_detail.php), com a MESMA regra do
      // painel: cliente com NF autorizada anterior ao início do período. Não se
      // aproxima nada aqui — aproximação em tela de vendedor gera desconfiança,
      // e o número tem que bater com o Dashboard.
      const cart = data.carteira || null;
      if (cart && cart.disponivel) {
        setT('drawerCarteiraValue', formatCurrency(cart.valor));
        setT('drawerCarteiraPct',
          Number(cart.pct || 0).toFixed(1).replace('.', ',') + '%'
          + (cart.nfs != null ? ' (' + formatNumber(cart.nfs) + ' NFs)' : ''));
        setT('drawerCarteiraClientes',
          formatNumber(cart.clientes || 0)
          + (cart.clientes_total ? ' de ' + formatNumber(cart.clientes_total) : ''));
        setT('drawerNovosValue',
          formatCurrency(cart.novos_valor)
          + (cart.novos_nfs != null ? ' · ' + formatNumber(cart.novos_nfs) + ' NFs' : ''));
      } else {
        // Indisponível ≠ zero: o servidor sinaliza a degradação explicitamente.
        setT('drawerCarteiraValue', 'indisponível');
        setT('drawerCarteiraPct', '-');
        setT('drawerCarteiraClientes', '-');
        setT('drawerNovosValue', '-');
      }
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
            const refDate   = x.competencia_date || x.data_competencia || x.status_date || x.issue_date || '';
            const dateLabel = refDate ? refDate.slice(8, 10) + '/' + refDate.slice(5, 7) : '--/--';
            return `<div class="detail-drawer-list-item"><div style="display:flex;flex-direction:column;gap:4px"><strong>${escapeHtml(x.cliente || '')}</strong><span style="font-size:11px;color:var(--text-3)">${dateLabel} | ${kind} ${x.codigo || '-'}</span></div><div style="text-align:right"><div style="font-size:13px;font-weight:700">${formatCurrency(x.gross_total || x.invoice_total || x.total || x.merchandise_total)}</div><small style="color:var(--text-3)">NF</small></div></div>`;
          }).join('');
        };
        ow.innerHTML = renderList(invoices, 'NF autorizadas', 'Nenhuma NF no periodo.', 'NF')
                     + renderList(pipeline, 'Carteira', 'Sem pedidos na carteira.', 'Pedido')
                     + renderList(cancelled, 'NF canceladas', 'Nenhuma NF cancelada.', 'NF');
      }
      // Aba "Vendas faturadas" — lista de NFs autorizadas do vendedor no período
      const nfEl = document.getElementById('drawerNFList');
      if (nfEl) {
        const nfRows = data.invoices || [];
        if (!nfRows.length) {
          nfEl.innerHTML = '<div style="font-size:12px;color:var(--text-3);padding:10px 0">Nenhuma NF autorizada encontrada no período selecionado.</div>';
        } else {
          nfEl.innerHTML = nfRows.slice(0, 50).map((x) => {
            const refDate   = x.competencia_date || x.data_competencia || x.status_date || x.issue_date || '';
            const dateLabel = refDate ? refDate.slice(8, 10) + '/' + refDate.slice(5, 7) : '--/--';
            const nfCode    = x.numero_nf || x.codigo_nf || x.codigo || '-';
            // NF CLICÁVEL: abre o drawer da nota (drilldown-router) sem sair da
            // tela. Antes a linha era um texto morto — ver o valor não levava a
            // lugar nenhum. data-biomie-action é o contrato já usado no resto do
            // app; nota_key/chave_nfe entram quando o payload os traz.
            const notaKey  = x.nota_key || '';
            const chaveNfe = x.chave_nfe || '';
            const pedido   = x.codigo_pedido || x.codigo_pedido_origem || '';
            const canOpen  = !!(notaKey || chaveNfe || (nfCode && nfCode !== '-'));
            const attrs = canOpen
              ? ` role="button" tabindex="0" data-biomie-action="open-invoice"`
                + ` data-nota-key="${escapeHtml(String(notaKey))}"`
                + ` data-numero-nf="${escapeHtml(String(nfCode))}"`
                + ` data-chave-nfe="${escapeHtml(String(chaveNfe))}"`
                + ` title="Abrir detalhes da NF ${escapeHtml(String(nfCode))}"`
                + ` style="cursor:pointer"`
              : '';
            const pedidoLabel = pedido
              ? ` &middot; Pedido ${escapeHtml(String(pedido))}`
              : '';
            return `<div class="detail-drawer-list-item"${attrs}><div style="display:flex;flex-direction:column;gap:4px"><strong>${escapeHtml(x.cliente || x.client_name || '')}</strong><span style="font-size:11px;color:var(--text-3)">${dateLabel} | NF ${escapeHtml(String(nfCode))}${pedidoLabel}</span></div><div style="text-align:right;display:flex;align-items:center;gap:6px"><div style="font-size:13px;font-weight:700">${formatCurrency(x.gross_total || x.invoice_total || x.total || x.merchandise_total)}</div>${canOpen ? '<span class="material-symbols-rounded" aria-hidden="true" style="font-size:16px;color:var(--text-3)">chevron_right</span>' : ''}</div></div>`;
          }).join('');
        }
      }
      // COM.R1.4 — Ownership conflicts section in drawer
      const ownershipSection  = document.getElementById('drawerOwnershipSection');
      const ownershipContent  = document.getElementById('drawerOwnershipContent');
      const vendorName = v.vendor_name || '';
      if (ownershipSection && ownershipContent && vendorName) {
        try {
          const oParams = new URLSearchParams({ mode: 'summary', vendor: vendorName });
          if (start) oParams.set('start', start);
          if (end)   oParams.set('end', end);
          const oData = await fetchJson('api/customer_ownership_conflicts.php?' + oParams.toString());
          const oSum  = oData.data || {};
          var conflOut = Number(oSum.conflict_nfs || 0);
          if (conflOut > 0 || Number(oSum.total_nfs || 0) > 0) {
            ownershipSection.style.display = 'block';
            ownershipContent.innerHTML = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">'
              + '<div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);">Total NFs</div><strong>' + formatNumber(oSum.total_nfs || 0) + '</strong></div>'
              + '<div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);">Fora da carteira</div>'
              + (conflOut > 0
                  ? '<strong style="color:var(--warning-text,#b45309);">' + conflOut + ' NF(s) — ' + formatCurrency(oSum.conflict_valor || 0) + '</strong>'
                  : '<strong style="color:var(--text-3);">Sem conflitos</strong>')
              + '</div></div>'
              + (conflOut > 0 ? '<small style="color:var(--text-3);">Vendas onde o cliente pertence a outro vendedor. Não bloqueia venda.</small>' : '');
          } else {
            ownershipSection.style.display = 'none';
          }
        } catch (_) { ownershipSection.style.display = 'none'; }
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
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        window.openProductBreakdown(btn.getAttribute('data-product-code') || '', btn.getAttribute('data-product-label') || 'Produto sem descricao');
      });
    });
  }

  function annotateProductRows(products) {
    const table = document.getElementById('productsTable');
    if (!table) return;
    Array.from(table.querySelectorAll('tr')).forEach((row, index) => {
      const item = (products || [])[index] || {};
      const productCode = String(item.product_code || item.codigo || '').trim();
      const label = String(item.description || item.product_name || 'Produto sem descricao').trim();
      if (!productCode) return;
      row.dataset.productKey = productCode;
      row.dataset.productCode = productCode;
      row.dataset.productLabel = label;
      row.classList.add('commercial-row--interactive');
      row.setAttribute('tabindex', '0');
      row.setAttribute('role', 'button');
      row.setAttribute('aria-label', 'Abrir detalhamento de ' + label);
    });
  }

  function initProductBreakdownDelegation() {
    const table = document.getElementById('productsTable');
    if (!table || table.dataset.productRowDelegation === '1') return;
    table.dataset.productRowDelegation = '1';
    const openFromRow = (row) => {
      if (!row || typeof window.openProductBreakdown !== 'function') return;
      const productCode = row.getAttribute('data-product-code') || row.getAttribute('data-product-key') || '';
      if (!productCode) return;
      window.openProductBreakdown(productCode, row.getAttribute('data-product-label') || 'Produto sem descricao');
    };
    table.addEventListener('click', (event) => {
      if (event.target.closest('a, button, input, select, textarea')) return;
      const row = event.target.closest('tr[data-product-key]');
      if (!row) return;
      openFromRow(row);
    });
    table.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      const row = event.target.closest('tr[data-product-key]');
      if (!row) return;
      event.preventDefault();
      openFromRow(row);
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

  function productBreakdownTotals(rows) {
    const clients = new Map();
    const vendors = new Map();
    const nfs = new Set();
    let revenue = 0;
    let quantity = 0;
    let discount = 0;
    (rows || []).forEach((row) => {
      const value = Number(row.revenue != null ? row.revenue : (row.product_total || 0));
      const qty = Number(row.quantity || 0);
      const disc = Number(row.discount_total != null ? row.discount_total : (row.discount || 0));
      revenue += Number.isFinite(value) ? value : 0;
      quantity += Number.isFinite(qty) ? qty : 0;
      discount += Number.isFinite(disc) ? disc : 0;
      if (row.nota_key || row.numero_nf) nfs.add(String(row.nota_key || row.numero_nf));
      const clientKey = String(row.customer_id || row.client_id || row.customer_document || row.customer_name || row.client_name || '');
      if (clientKey) {
        const current = clients.get(clientKey) || { name: row.customer_name || row.client_name || 'Cliente nao informado', revenue: 0, quantity: 0, nfs: new Set() };
        current.revenue += Number.isFinite(value) ? value : 0;
        current.quantity += Number.isFinite(qty) ? qty : 0;
        if (row.nota_key || row.numero_nf) current.nfs.add(String(row.nota_key || row.numero_nf));
        clients.set(clientKey, current);
      }
      const vendorKey = String(row.vendor_id || row.codigo_vendedor_omie || row.vendor_name || '');
      if (vendorKey) {
        const current = vendors.get(vendorKey) || { name: row.vendor_name || 'Sem vendedor', revenue: 0, quantity: 0, nfs: new Set() };
        current.revenue += Number.isFinite(value) ? value : 0;
        current.quantity += Number.isFinite(qty) ? qty : 0;
        if (row.nota_key || row.numero_nf) current.nfs.add(String(row.nota_key || row.numero_nf));
        vendors.set(vendorKey, current);
      }
    });
    const clientCount = clients.size;
    const vendorCount = vendors.size;
    const listFromMap = (map) => Array.from(map.values()).sort((a, b) => b.revenue - a.revenue).slice(0, 8);
    return { revenue, quantity, discount, invoices: nfs.size, clientCount, vendorCount, clients: listFromMap(clients), vendors: listFromMap(vendors) };
  }

  function renderProductMiniList(targetId, rows, emptyText) {
    const target = document.getElementById(targetId);
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = `<div class="product-mini-list__row"><strong>${escapeHtml(emptyText)}</strong><small>-</small></div>`;
      return;
    }
    target.innerHTML = rows.map((row) => (
      `<div class="product-mini-list__row">`
      + `<div><strong title="${escapeHtml(row.name || '')}">${escapeHtml(row.name || '-')}</strong><small>${formatNumber(row.quantity || 0)} un · ${formatNumber(row.nfs ? row.nfs.size : 0)} NF</small></div>`
      + `<strong>${formatCurrency(row.revenue || 0)}</strong>`
      + `</div>`
    )).join('');
  }

  function renderProductBreakdownInsights(rows) {
    const totals = productBreakdownTotals(rows);
    const summary = document.getElementById('productBreakdownSummary');
    if (summary) {
      summary.innerHTML = [
        ['Receita', formatCurrency(totals.revenue)],
        ['Quantidade', formatNumber(totals.quantity)],
        ['Clientes', formatNumber(totals.clientCount)],
        ['NFs', formatNumber(totals.invoices)],
        ['Ticket medio', totals.invoices > 0 ? formatCurrency(totals.revenue / totals.invoices) : '-'],
        ['Descontos', formatCurrency(totals.discount)],
      ].map(([label, value]) => `<div class="product-breakdown-metric"><small>${escapeHtml(label)}</small><strong>${escapeHtml(value)}</strong></div>`).join('');
    }
    setText('#productBreakdownClientsCount', `${formatNumber(totals.clientCount)} cliente(s)`);
    setText('#productBreakdownVendorsCount', `${formatNumber(totals.vendorCount)} vendedor(es)`);
    renderProductMiniList('productBreakdownClients', totals.clients, 'Sem clientes detalhados');
    renderProductMiniList('productBreakdownVendors', totals.vendors, 'Sem vendedores detalhados');
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
    const summary  = document.getElementById('productBreakdownSummary');
    const clients  = document.getElementById('productBreakdownClients');
    const vendors  = document.getElementById('productBreakdownVendors');
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
    if (summary) summary.innerHTML = '';
    if (clients) clients.innerHTML = '';
    if (vendors) vendors.innerHTML = '';
    body.innerHTML = '';

    try {
      const data           = await fetchJson(buildApiUrl('api/products.php', false, { product_code: productCode || '', limit: 200, scope: getProductScope() }));
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
      renderProductBreakdownInsights(rows);
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
      if (['index', 'dashboard', 'indicadores', 'analises', 'mesa'].includes(page)) {
        document.dispatchEvent(new CustomEvent('biomie:dashboard-load-failed', {
          detail: { message: error && error.message ? error.message : 'Falha ao carregar o painel.' }
        }));
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

  document.addEventListener('DOMContentLoaded', async () => {
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
    initProductScopeSwitcher();
    document.getElementById('applyFilters')?.addEventListener('click', () => {
      if (window.NavContext) { const r = getDateRange(); window.NavContext.setDateRange(r.start, r.end); window.NavContext.pushState(); }
      loadCurrentPage(false);
    });
    document.addEventListener('biomie:dashboard-request-standard', () => {
      loadCurrentPage(false);
    });
    document.getElementById('syncNow')?.addEventListener('click', syncNow);
    document.getElementById('syncSnapshot')?.addEventListener('click', syncNow);
    document.getElementById('syncCanonical')?.addEventListener('click', syncCanonical);
    document.getElementById('syncFinance')?.addEventListener('click', syncFinance);
    document.getElementById('closeDrawerBtn')?.addEventListener('click', window.closeVendorDrawer);
    document.getElementById('flightDrawerBackdrop')?.addEventListener('click', window.closeVendorDrawer);

    if (
      ['index', 'dashboard'].includes((document.body.dataset && document.body.dataset.page) || 'dashboard')
      && window.BDashboard
      && typeof window.BDashboard.scopeReady === 'function'
    ) {
      await window.BDashboard.scopeReady();
    }
    const dashboardUsesScopedEndpoint = window.BDashboard
      && typeof window.BDashboard.hasExtraFilters === 'function'
      && window.BDashboard.hasExtraFilters();
    const hydratedFromBootstrap = !dashboardUsesScopedEndpoint && hydrateDashboardFromBootstrap();
    if (!hydratedFromBootstrap) {
      await loadCurrentPage(false);
    } else {
      fetchJson('api/weather.php', { timeoutMs: 10000 }).then((data) => B.renderWeather(data)).catch(() => {});
    }

    if (window.EventSource && ['index', 'dashboard'].includes((document.body.dataset && document.body.dataset.page) || 'dashboard')) {
      (function () {
        var lastTs = '';
        var backoffMs = 5000;
        var reconnectTimer = null;
        var visibilityTimer = null;

        function openStream() {
          if (window.BIOMIE_LIVE_STREAM) return;
          var url = 'api/live_stream.php' + (lastTs ? '?since=' + encodeURIComponent(lastTs) : '');
          var es = new window.EventSource(url);
          window.BIOMIE_LIVE_STREAM = es;

          es.addEventListener('refresh_dashboard', function (e) {
            try {
              var d = JSON.parse(e.data);
              if (d && d.timestamp) lastTs = String(d.timestamp);
              backoffMs = 5000;
            } catch (err) {}
            loadCurrentPage(false, true);
          });

          es.addEventListener('close', function () {
            closeStream();
            scheduleReconnect(false);
          });

          es.onerror = function () {
            closeStream();
            scheduleReconnect(true);
          };
        }

        function closeStream() {
          clearTimeout(reconnectTimer);
          if (window.BIOMIE_LIVE_STREAM) {
            window.BIOMIE_LIVE_STREAM.close();
            window.BIOMIE_LIVE_STREAM = null;
          }
        }

        function scheduleReconnect(useBackoff) {
          clearTimeout(reconnectTimer);
          if (document.hidden) return;
          var delay = useBackoff ? backoffMs : 5000;
          if (useBackoff) backoffMs = Math.min(backoffMs * 2, 120000);
          reconnectTimer = setTimeout(function () {
            if (!document.hidden) openStream();
          }, delay);
        }

        document.addEventListener('visibilitychange', function () {
          clearTimeout(visibilityTimer);
          if (document.hidden) {
            closeStream();
          } else {
            visibilityTimer = setTimeout(function () {
              if (!window.BIOMIE_LIVE_STREAM) openStream();
            }, 1000);
          }
        });

        window.addEventListener('beforeunload', closeStream);

        Promise.resolve(window.BIOMIE_TAB_CONTEXT_READY).finally(openStream);
      })();
    }
  });
})();
