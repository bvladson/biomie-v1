/**
 * Overview Detail Drawer — Phase 10G
 * Large-screen drilldown drawer (70–82 vw) for chart detail exploration.
 * Reuses existing entity drawers (Vendor 360, Client Dossier, Invoice Detail)
 * when the click target is a known entity.
 */
(function () {
  'use strict';

  var B = window.BIomie || (window.BIomie = {});

  var drawer, backdrop, titleEl, subtitleEl, closeBtn, tabsEl, bodyEl;
  var abortCtrl = null;
  var triggerEl = null;
  var cache = {};
  var CACHE_TTL = 60000;
  var focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

  function init() {
    drawer   = document.getElementById('overviewDetailDrawer');
    backdrop = document.getElementById('overviewDetailBackdrop');
    titleEl  = document.getElementById('overviewDetailTitle');
    subtitleEl = document.getElementById('overviewDetailSubtitle');
    closeBtn = document.getElementById('overviewDetailClose');
    tabsEl   = document.getElementById('overviewDetailTabs');
    bodyEl   = document.getElementById('overviewDetailBody');
    if (!drawer) return;

    closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen()) close();
    });
    drawer.addEventListener('keydown', trapFocus);

    // Wire all Detalhar buttons
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-drill]');
      if (!btn) return;
      var chartId = btn.dataset.drill;
      if (!chartId) return;
      e.preventDefault();
      open({ chartId: chartId, title: chartTitles[chartId] || 'Detalhamento', triggerElement: btn });
    });
  }

  var chartTitles = {
    revenue_evolution: 'Evolucao da Receita',
    vendor_ranking: 'Ranking Comercial',
    top_products: 'Produtos em Destaque',
    top_customers: 'Clientes em Destaque',
    top_invoices: 'Maiores Notas Fiscais',
    geo_compact: 'Geografia Resumida'
  };

  function isOpen() { return drawer && drawer.classList.contains('is-open'); }

  function open(opts) {
    if (!drawer) init();
    if (!drawer) return;

    triggerEl = opts.triggerElement || document.activeElement;
    var chartId = opts.chartId || '';
    var title = opts.title || chartTitles[chartId] || 'Detalhamento';
    var filters = opts.filters || {};

    titleEl.textContent = title;
    subtitleEl.textContent = opts.subtitle || '';
    tabsEl.innerHTML = '';
    bodyEl.innerHTML = renderSkeleton();

    drawer.setAttribute('aria-hidden', 'false');
    drawer.classList.add('is-open');
    backdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';

    // Focus first focusable inside drawer
    setTimeout(function () {
      var first = drawer.querySelector(focusableSelector);
      if (first) first.focus();
    }, 100);

    fetchDetail(chartId, filters);
  }

  function close() {
    if (!drawer) return;
    if (abortCtrl) { abortCtrl.abort(); abortCtrl = null; }

    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';

    if (triggerEl && typeof triggerEl.focus === 'function') {
      triggerEl.focus();
    }
    triggerEl = null;
  }

  function trapFocus(e) {
    if (e.key !== 'Tab') return;
    var els = drawer.querySelectorAll(focusableSelector);
    if (!els.length) return;
    var first = els[0];
    var last = els[els.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function renderSkeleton() {
    return '<div class="drawer-skeleton">' +
      '<div class="skeleton-block"></div>' +
      '<div class="skeleton-block"></div>' +
      '<div class="skeleton-block"></div>' +
      '<div class="skeleton-block"></div>' +
      '</div>';
  }

  function cacheKey(chartId, filters) {
    return chartId + ':' + JSON.stringify(filters || {});
  }

  function fetchDetail(chartId, filters) {
    var key = cacheKey(chartId, filters);
    var cached = cache[key];
    if (cached && (Date.now() - cached.ts < CACHE_TTL)) {
      renderDetail(chartId, cached.data);
      return;
    }

    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();

    var dateStart = document.getElementById('dateStart');
    var dateEnd = document.getElementById('dateEnd');
    var params = new URLSearchParams({
      chart_id: chartId,
      start: (dateStart && dateStart.value) || '',
      end: (dateEnd && dateEnd.value) || ''
    });

    Object.keys(filters).forEach(function (k) { if (filters[k]) params.set(k, filters[k]); });

    var fetchFn = typeof window.fetchJson === 'function' ? window.fetchJson : null;
    var url = 'api/overview_detail.php?' + params.toString();

    function handleResponse(response) {
      return response.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) {}
        if (!response.ok) {
          if (data) throw data;
          throw {
            message: 'Erro HTTP ' + response.status,
            request_id: response.headers.get('X-Request-Id') || 'N/A',
            diagnostics: { content_type: response.headers.get('Content-Type') || 'desconhecido' }
          };
        }
        return data || {};
      });
    }

    fetch(url, { credentials: 'same-origin', signal: abortCtrl.signal })
      .then(handleResponse)
      .then(function (data) { cache[key] = { data: data, ts: Date.now() }; renderDetail(chartId, data); })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        renderError(err.message || 'Erro inesperado.', err.request_id, chartId, err.diagnostics);
      });
  }

  function renderError(msg, reqId, chartId, diagnostics) {
    var details = '';
    if (reqId || chartId) {
      details += '<div style="margin-top:8px; font-family:monospace; font-size:11px; opacity:0.8;">';
      if (reqId) details += '<div>Req ID: ' + B.escapeHtml(reqId) + '</div>';
      if (chartId) details += '<div>Chart: ' + B.escapeHtml(chartId) + '</div>';
      if (diagnostics && diagnostics.handler) details += '<div>Handler: ' + B.escapeHtml(diagnostics.handler) + '</div>';
      details += '</div>';
    }

    bodyEl.innerHTML = '<div class="module-alert module-alert--info">' +
      '<strong>Detalhamento indisponivel</strong><br>' +
      '<span>' + B.escapeHtml(msg || 'Erro ao carregar dados.') + '</span>' +
      details +
      '<div style="margin-top:12px;"><button type="button" class="btn-detail" data-overview-retry="' + B.escapeHtml(chartId || '') + '">Tentar novamente</button></div>' +
      '</div>';
    bodyEl.querySelector('[data-overview-retry]')?.addEventListener('click', function () {
      cache = {};
      fetchDetail(chartId || '', {});
    });
  }

  function renderDetail(chartId, data) {
    if (!data || data.ok === false) {
      renderError(data ? data.message : 'Sem dados disponiveis.', data ? data.request_id : null, chartId, data ? data.diagnostics : null);
      return;
    }

    var rows = data.rows || data.items || [];
    if (!rows.length) {
      var warnings = data.meta && Array.isArray(data.meta.warnings) ? data.meta.warnings : [];
      bodyEl.innerHTML = '<div class="dashboard-empty-state">' +
        '<span class="material-symbols-rounded">search_off</span>' +
        '<strong>Sem dados para este filtro</strong>' +
        '<p>Nao foram encontrados registros para o detalhamento solicitado.</p>' +
        (warnings.length ? '<div class="small text-muted" style="margin-top:8px;">' + warnings.map(B.escapeHtml).join('<br>') + '</div>' : '') +
        (data.request_id ? '<div class="small text-muted" style="margin-top:8px;font-family:monospace;">request_id=' + B.escapeHtml(data.request_id) + '</div>' : '') +
        '</div>';
      return;
    }

    var html = '<table class="mag-table"><thead><tr>';
    var columns = detectColumns(chartId, rows[0]);
    columns.forEach(function (col) { html += '<th>' + B.escapeHtml(col.label) + '</th>'; });
    html += '</tr></thead><tbody>';
    rows.forEach(function (row) {
      html += '<tr>';
      columns.forEach(function (col) {
        var val = row[col.key];
        if (col.key === 'vendor_name' && typeof B.renderGoalBadge === 'function') {
          val = B.escapeHtml(String(val || '-')) + B.renderGoalBadge(row);
          html += '<td style="display:flex;align-items:center;gap:6px;">' + val + '</td>';
        } else {
          if (col.format === 'currency') val = B.formatCurrency(val);
          else if (col.format === 'number') val = B.formatNumber(val);
          else if (col.format === 'date') val = B.formatMetaDate(val || '');
          else val = B.escapeHtml(String(val || '-'));
          html += '<td>' + val + '</td>';
        }
      });
      html += '</tr>';
    });
    html += '</tbody></table>';

    if (data.summary) {
      var summaryHtml = '<div class="module-tiny-kpis" style="margin-bottom:16px;">';
      Object.keys(data.summary).forEach(function (k) {
        var label = k.replace(/_/g, ' ');
        summaryHtml += '<div class="mini"><span>' + B.escapeHtml(label) + '</span><strong>' + B.escapeHtml(String(data.summary[k])) + '</strong></div>';
      });
      summaryHtml += '</div>';
      html = summaryHtml + html;
    }

    bodyEl.innerHTML = html;
  }

  function detectColumns(chartId, sample) {
    var maps = {
      revenue_evolution: [
        { key: 'date', label: 'Data', format: 'date' },
        { key: 'orders', label: 'Pedidos', format: 'number' },
        { key: 'revenue', label: 'Receita', format: 'currency' },
        { key: 'vendor_name', label: 'Vendedor' }
      ],
      vendor_ranking: [
        { key: 'vendor_name', label: 'Vendedor' },
        { key: 'orders', label: 'Pedidos', format: 'number' },
        { key: 'revenue', label: 'Receita', format: 'currency' },
        { key: 'ticket', label: 'Ticket', format: 'currency' }
      ],
      top_products: [
        { key: 'description', label: 'Produto' },
        { key: 'quantity', label: 'Qtd', format: 'number' },
        { key: 'revenue', label: 'Receita', format: 'currency' }
      ],
      top_customers: [
        { key: 'client_name', label: 'Cliente' },
        { key: 'notes_count', label: 'NFs', format: 'number' },
        { key: 'invoice_total', label: 'Receita', format: 'currency' },
        { key: 'share_pct', label: 'Share' }
      ],
      top_invoices: [
        { key: 'code', label: 'NF' },
        { key: 'issue_date', label: 'Emissao', format: 'date' },
        { key: 'client', label: 'Cliente' },
        { key: 'vendor_name', label: 'Vendedor' },
        { key: 'total', label: 'Valor', format: 'currency' }
      ],
      geo_compact: [
        { key: 'neighborhood', label: 'Bairro' },
        { key: 'revenue', label: 'Receita', format: 'currency' },
        { key: 'share_pct', label: 'Share' }
      ]
    };

    return maps[chartId] || Object.keys(sample).map(function (k) { return { key: k, label: k }; });
  }

  // Public API
  window.BIomieOverviewDetail = { open: open, close: close, isOpen: isOpen };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
