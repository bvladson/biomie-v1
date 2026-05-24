/**
 * BIomie — Dashboard extra filters (Fase Q.7).
 *
 * Extends the main dashboard with vendedor / cliente / produto text filters
 * and quick filters (com_componentes, sem_pedido). When any extra filter is
 * active, loadDashboard() delegates to window.BDashboard.loadFiltered()
 * which calls api/commercial_dashboard.php instead of overview.php.
 *
 * Invariants (Q.7 mandatory rules):
 *  - Uses BiVisibilityScope server-side (Licitacao excluded by default)
 *  - Uses FiscalValueBasis Base Comercial NF (valor_total − frete − outras − seguro)
 *  - operation_scope = 'commercial' (remessas separadas)
 *  - IPI permanece na base
 *  - Nao altera overview.php, vendors.php, commercial_health.php
 */
(function () {
  'use strict';

  if (window.BDashboard) return;

  var B = window.BIomie || {};

  // ── State ──────────────────────────────────────────────────────────────────

  var currentSort  = { field: 'emissao', dir: 'desc' };
  var currentPage  = 1;
  var currentQuick = '';
  var _loading     = false;

  // ── Helpers ────────────────────────────────────────────────────────────────

  function getRange() {
    if (typeof B.getDateRange === 'function') return B.getDateRange();
    var s = document.getElementById('dateStart');
    var e = document.getElementById('dateEnd');
    return { start: (s && s.value) || '', end: (e && e.value) || '' };
  }

  function getText(id) {
    var el = document.getElementById(id);
    return el ? el.value.trim() : '';
  }

  function escHtml(str) {
    if (typeof B.escapeHtml === 'function') return B.escapeHtml(str);
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function fmtCurrency(val) {
    if (typeof B.formatCurrency === 'function') return B.formatCurrency(val);
    return 'R$ ' + Number(val).toFixed(2).replace('.', ',');
  }

  // ── Extra filter detection ─────────────────────────────────────────────────

  function hasExtraFilters() {
    return !!(
      getText('f_dash_vendedor') ||
      getText('f_dash_cliente')  ||
      getText('f_dash_produto')  ||
      currentQuick
    );
  }

  // ── Chip rendering ────────────────────────────────────────────────────────

  function buildChips() {
    var chips = [];
    var vnd = getText('f_dash_vendedor');
    var cli = getText('f_dash_cliente');
    var prd = getText('f_dash_produto');
    if (vnd) chips.push({ key: 'vendedor', label: 'Vendedor: ' + vnd });
    if (cli) chips.push({ key: 'cliente',  label: 'Cliente: '  + cli });
    if (prd) chips.push({ key: 'produto',  label: 'Produto: '  + prd });
    if (currentQuick === 'com_componentes') chips.push({ key: 'quick', label: 'Com frete/outras/seguro' });
    if (currentQuick === 'sem_pedido')      chips.push({ key: 'quick', label: 'Sem pedido vinculado' });
    return chips;
  }

  function renderChips() {
    var container = document.getElementById('dashboardFilterChips');
    if (!container) return;
    var chips = buildChips();
    if (!chips.length) {
      container.innerHTML = '';
      container.style.display = 'none';
      return;
    }
    container.style.display = 'flex';
    container.innerHTML = chips.map(function (c) {
      return '<span class="filter-chip" data-chip-key="' + c.key + '">'
        + escHtml(c.label)
        + '<button type="button" class="filter-chip-remove" aria-label="Remover filtro">'
        + '<span class="material-symbols-rounded" style="font-size:14px">close</span>'
        + '</button></span>';
    }).join('');

    container.querySelectorAll('.filter-chip-remove').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var chip = btn.closest('[data-chip-key]');
        if (chip) removeFilter(chip.getAttribute('data-chip-key'));
      });
    });
  }

  function removeFilter(key) {
    if (key === 'vendedor') { var el = document.getElementById('f_dash_vendedor'); if (el) el.value = ''; }
    if (key === 'cliente')  { var el = document.getElementById('f_dash_cliente');  if (el) el.value = ''; }
    if (key === 'produto')  { var el = document.getElementById('f_dash_produto');  if (el) el.value = ''; }
    if (key === 'quick')    { currentQuick = ''; updateQuickButtons(); }
    currentPage = 1;
    if (hasExtraFilters()) { loadFiltered(); } else { clearFilteredView(); }
  }

  function clearAllFilters() {
    ['f_dash_vendedor', 'f_dash_cliente', 'f_dash_produto'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.value = '';
    });
    currentQuick = '';
    currentPage  = 1;
    updateQuickButtons();
    clearFilteredView();
  }

  function updateQuickButtons() {
    document.querySelectorAll('[data-dash-quick]').forEach(function (btn) {
      var q = btn.getAttribute('data-dash-quick');
      btn.classList.toggle('nf-quick-filter-btn--active', q === currentQuick);
    });
  }

  // ── API URL ────────────────────────────────────────────────────────────────

  function buildUrl(force) {
    var range  = getRange();
    var params = new URLSearchParams();
    if (range.start) params.set('start', range.start);
    if (range.end)   params.set('end',   range.end);
    var vnd = getText('f_dash_vendedor');
    var cli = getText('f_dash_cliente');
    var prd = getText('f_dash_produto');
    if (vnd)          params.set('vendedor', vnd);
    if (cli)          params.set('cliente',  cli);
    if (prd)          params.set('produto',  prd);
    if (currentQuick) params.set('quick',    currentQuick);
    params.set('sort',      currentSort.field);
    params.set('direction', currentSort.dir);
    params.set('page',      String(currentPage));
    params.set('per_page',  '50');
    if (force)        params.set('_ts', String(Date.now()));
    return 'api/commercial_dashboard.php?' + params.toString();
  }

  // ── KPI update ────────────────────────────────────────────────────────────

  function setBind(name, text) {
    document.querySelectorAll('[data-bind="' + name + '"]').forEach(function (el) {
      el.textContent = text;
    });
  }

  function updateKpis(kpis) {
    if (!kpis) return;
    setBind('summary-revenue', fmtCurrency(kpis.base_comercial_nf));
    setBind('summary-orders',  String(kpis.nfs) + ' NFs');
    setBind('summary-ticket',  fmtCurrency(kpis.ticket_medio));
    setBind('summary-clients', String(kpis.clientes));
  }

  // ── Chart update ──────────────────────────────────────────────────────────

  function updateCharts(resp) {
    if (!resp) return;

    if (typeof B.createLineChart === 'function' && resp.series && resp.series.evolucao_diaria) {
      var dias = resp.series.evolucao_diaria;
      B.createLineChart(
        'salesTimelineChart',
        dias.map(function (r) { return r.dia; }),
        dias.map(function (r) { return Number(r.base_comercial); }),
        dias.map(function (r) { return Number(r.nfs); })
      );
    }

    if (typeof B.createRevenueTicketChart === 'function' && resp.rankings && resp.rankings.top_vendedores) {
      var vndRows = resp.rankings.top_vendedores.map(function (r) {
        return { vendor_name: r.nome, base_comercial: r.base_comercial, invoices_count: r.nfs };
      });
      B.createRevenueTicketChart('vendorsBarChart', vndRows);
    }

    if (typeof B.createHorizontalBarChart === 'function' && resp.rankings) {
      if (resp.rankings.top_produtos) {
        var prods = resp.rankings.top_produtos.slice(0, 8);
        B.createHorizontalBarChart(
          'topProductsChart',
          prods.map(function (r) { return escHtml(String(r.produto).substring(0, 28)); }),
          prods.map(function (r) { return Number(r.receita); }),
          { barColor: 'rgba(37,99,235,0.7)' }
        );
      }
      if (resp.rankings.top_clientes) {
        var clis = resp.rankings.top_clientes.slice(0, 8);
        B.createHorizontalBarChart(
          'topClientsChart',
          clis.map(function (r) { return escHtml(String(r.nome).substring(0, 28)); }),
          clis.map(function (r) { return Number(r.base_comercial); }),
          { barColor: 'rgba(22,163,74,0.7)' }
        );
      }
    }
  }

  // ── Top NFs table ─────────────────────────────────────────────────────────

  function renderTopNfs(rows) {
    var tbody = document.getElementById('topOrdersTable');
    if (!tbody) return;
    if (!rows || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-3);">'
        + 'Nenhuma NF encontrada para os filtros aplicados.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function (r) {
      return '<tr>'
        + '<td><strong style="color:var(--page-accent)">' + escHtml(r.numero_nf) + '</strong></td>'
        + '<td>' + escHtml(r.data_emissao) + '</td>'
        + '<td>' + escHtml(r.nome_cliente) + '</td>'
        + '<td>' + escHtml(r.nome_vendedor) + '</td>'
        + '<td style="color:var(--text-3)">—</td>'
        + '<td><strong>' + fmtCurrency(r.base_comercial_nf) + '</strong></td>'
        + '<td data-mobile-skip><span style="color:var(--text-3);font-size:11px">filtrado</span></td>'
        + '</tr>';
    }).join('');
  }

  // ── Pagination ────────────────────────────────────────────────────────────

  function renderPagination(pag) {
    var el = document.getElementById('dashboardNfsPagination');
    if (!el) return;
    if (!pag || pag.total_pages <= 1) { el.style.display = 'none'; return; }
    el.style.display = 'flex';
    el.innerHTML = '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" id="dashPagPrev"'
      + (pag.has_prev ? '' : ' disabled') + '>&#8592; Anterior</button>'
      + '<span style="font-size:0.82rem;color:var(--text-2);">'
      + 'Página ' + pag.page + ' de ' + pag.total_pages
      + ' (' + pag.total + ' NFs)'
      + '</span>'
      + '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" id="dashPagNext"'
      + (pag.has_next ? '' : ' disabled') + '>Próxima &#8594;</button>';

    var prev = document.getElementById('dashPagPrev');
    var next = document.getElementById('dashPagNext');
    if (prev) prev.addEventListener('click', function () { currentPage = pag.page - 1; loadFiltered(); });
    if (next) next.addEventListener('click', function () { currentPage = pag.page + 1; loadFiltered(); });
  }

  // ── Fetch & render ─────────────────────────────────────────────────────────

  function loadFiltered(force) {
    if (_loading) return;
    _loading = true;
    renderChips();

    fetch(buildUrl(force), { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (resp) {
        _loading = false;
        if (!resp || !resp.ok) { console.warn('[BDashboard] API error', resp); return; }
        updateKpis(resp.kpis);
        updateCharts(resp);
        renderTopNfs((resp.rankings && resp.rankings.top_nfs) || []);
        renderPagination(resp.pagination || null);
        renderChips();
      })
      .catch(function (err) {
        _loading = false;
        console.error('[BDashboard] fetch failed', err);
      });
  }

  // ── Restore standard view ──────────────────────────────────────────────────

  function clearFilteredView() {
    renderChips();
    var pagEl = document.getElementById('dashboardNfsPagination');
    if (pagEl) pagEl.style.display = 'none';
    var applyBtn = document.getElementById('applyFilters');
    if (applyBtn) applyBtn.click();
  }

  // ── Mount ──────────────────────────────────────────────────────────────────

  function mount() {
    var _debTimer = null;

    function debounced() {
      clearTimeout(_debTimer);
      _debTimer = setTimeout(function () {
        currentPage = 1;
        if (hasExtraFilters()) { loadFiltered(); } else { clearFilteredView(); }
      }, 400);
    }

    ['f_dash_vendedor', 'f_dash_cliente', 'f_dash_produto'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', debounced);
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          clearTimeout(_debTimer);
          currentPage = 1;
          if (hasExtraFilters()) { loadFiltered(); } else { clearFilteredView(); }
        }
        if (e.key === 'Escape') { el.value = ''; debounced(); }
      });
    });

    document.querySelectorAll('[data-dash-quick]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var q    = btn.getAttribute('data-dash-quick');
        currentQuick = (currentQuick === q) ? '' : q;
        currentPage  = 1;
        updateQuickButtons();
        if (hasExtraFilters()) { loadFiltered(); } else { clearFilteredView(); }
      });
    });

    var clearBtn = document.getElementById('dashboardClearFilters');
    if (clearBtn) clearBtn.addEventListener('click', clearAllFilters);
  }

  // ── Public API ─────────────────────────────────────────────────────────────

  window.BDashboard = {
    hasExtraFilters: hasExtraFilters,
    loadFiltered:    loadFiltered,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }

}());
