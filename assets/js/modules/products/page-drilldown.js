/**
 * BIomie — Products page drilldown (Fase Q.4).
 *
 * Wires B.ServerTable to api/products_search.php on the Mix Faturado screen.
 * Coexists with app.js:loadProducts() — initial table stays static,
 * filtered view activates on user interaction and restores on clear.
 */
(function () {
  'use strict';

  if (window.BIomieProductsPageDrilldown) return;
  window.BIomieProductsPageDrilldown = true;

  var B = window.BIomie || {};

  // ── Helpers ──────────────────────────────────────────────────────────────

  function isProductsPage() {
    var page = document.body ? document.body.getAttribute('data-page') : '';
    return ['products', 'mix-faturado'].includes(page);
  }

  function getRange() {
    if (B.getDateRange) return B.getDateRange();
    var s = document.getElementById('dateStart');
    var e = document.getElementById('dateEnd');
    return { start: (s && s.value) || '', end: (e && e.value) || '' };
  }

  function getProductScope() {
    var scope = window.BIomieProductScope || (new URLSearchParams(window.location.search)).get('scope') || 'commercial';
    scope = String(scope || '').toLowerCase();
    if (['remittance', 'remessa', 'remessas'].includes(scope)) return 'remittance';
    if (['all', 'todas', 'todos'].includes(scope)) return 'all';
    return 'commercial';
  }

  // ── Filter state ──────────────────────────────────────────────────────────

  var currentSort = { field: 'revenue', dir: 'desc' };

  var FILTER_INPUT_MAP = {
    min_quantidade: 'f_prod_qty_min',
    max_quantidade: 'f_prod_qty_max',
    min_revenue:    'f_prod_rev_min',
    max_revenue:    'f_prod_rev_max',
    vendedor:       'f_prod_vendedor',
    cliente:        'f_prod_cliente',
  };

  function buildStateFromDOM(page) {
    var range = getRange();
    var qEl   = document.getElementById('f_prod_q');

    var state = B.FS.make({
      start:     range.start,
      end:       range.end,
      scope:     getProductScope(),
      sortField: currentSort.field,
      sortDir:   currentSort.dir,
      page:      page || 1,
      perPage:   50,
    });

    if (qEl && qEl.value.trim()) {
      state = B.FS.setQ(state, qEl.value.trim());
    }

    var numberFields = ['min_quantidade', 'max_quantidade', 'min_revenue', 'max_revenue'];
    var textFields   = ['vendedor', 'cliente'];

    numberFields.forEach(function (field) {
      var el = document.getElementById(FILTER_INPUT_MAP[field]);
      if (el && el.value.trim()) {
        state = B.FS.setFilter(state, field, field.startsWith('min') ? 'gte' : 'lte', el.value.trim());
      }
    });

    textFields.forEach(function (field) {
      var el = document.getElementById(FILTER_INPUT_MAP[field]);
      if (el && el.value.trim()) {
        state = B.FS.setFilter(state, field, 'contains', el.value.trim());
      }
    });

    return state;
  }

  // ── Chip labels ───────────────────────────────────────────────────────────

  var CHIP_LABELS = {
    q:              function (v) { return 'Busca: ' + v; },
    vendedor:       function (v) { return 'Vendedor: ' + v; },
    cliente:        function (v) { return 'Cliente: ' + v; },
    min_quantidade: function (v) { return 'Qtd ≥ ' + v; },
    max_quantidade: function (v) { return 'Qtd ≤ ' + v; },
    min_revenue:    function (v) { return 'Receita ≥ ' + B.formatCurrency(Number(v)); },
    max_revenue:    function (v) { return 'Receita ≤ ' + B.formatCurrency(Number(v)); },
  };

  function chipsForState(state) {
    var chips = [];
    if (state.q) chips.push({ key: 'q', label: CHIP_LABELS.q(state.q) });
    var allFields = ['vendedor', 'cliente', 'min_quantidade', 'max_quantidade', 'min_revenue', 'max_revenue'];
    allFields.forEach(function (field) {
      if (state.filters && state.filters[field] != null) {
        var fv  = state.filters[field];
        var val = fv.val != null ? fv.val : fv;
        if (CHIP_LABELS[field]) chips.push({ key: field, label: CHIP_LABELS[field](val) });
      }
    });
    return chips;
  }

  // ── Sort UI ───────────────────────────────────────────────────────────────

  function updateSortUI() {
    document.querySelectorAll('.nf-th-sortable[data-sort-field]').forEach(function (th) {
      var field = th.getAttribute('data-sort-field');
      var icon  = th.querySelector('.nf-sort-icon');
      if (!icon) return;
      if (field === currentSort.field) {
        icon.textContent = currentSort.dir === 'asc' ? '↑' : '↓';
        th.classList.add('nf-th-sorted');
      } else {
        icon.textContent = '↕';
        th.classList.remove('nf-th-sorted');
      }
    });

    document.querySelectorAll('.nf-quick-filter-btn[data-prod-sort]').forEach(function (btn) {
      var field = btn.getAttribute('data-prod-sort');
      var dir   = btn.getAttribute('data-prod-dir') || 'desc';
      if (field === currentSort.field && dir === currentSort.dir) {
        btn.classList.add('nf-quick-filter-btn--active');
      } else {
        btn.classList.remove('nf-quick-filter-btn--active');
      }
    });
  }

  function updateColumnHeaderBadges(state) {
    var headerMap = {
      'th-prod-produto':    !!state.q,
      'th-prod-qtd':        !!(state.filters && (state.filters.min_quantidade || state.filters.max_quantidade)),
      'th-prod-receita':    !!(state.filters && (state.filters.min_revenue || state.filters.max_revenue)),
    };
    for (var id in headerMap) {
      var el = document.getElementById(id);
      if (!el) continue;
      if (headerMap[id]) el.classList.add('nf-th-filter-active');
      else               el.classList.remove('nf-th-filter-active');
    }
  }

  // ── Render ────────────────────────────────────────────────────────────────

  function bindProductTriggers() {
    document.querySelectorAll('.product-breakdown-trigger:not([data-bound="1"])').forEach(function (btn) {
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var code  = btn.getAttribute('data-product-code')  || '';
        var label = btn.getAttribute('data-product-label') || 'Produto sem descricao';
        if (typeof window.openProductBreakdown === 'function') {
          window.openProductBreakdown(code, label);
        }
      });
    });
  }

  function renderProductRows(rows) {
    var tbody = document.getElementById('productsTable');
    if (!tbody) return;

    if (!rows || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-3);">'
        + 'Nenhum produto encontrado para os filtros aplicados.</td></tr>';
      return;
    }

    var totalRevenue = rows.reduce(function (s, r) { return s + Number(r.revenue || 0); }, 0);

    tbody.innerHTML = rows.map(function (row) {
      var sharePct = totalRevenue > 0
        ? (Number(row.revenue || 0) / totalRevenue * 100).toFixed(1) + '%'
        : '—';

      var triggerHtml = (B.renderProductBreakdownTrigger && row.product_code)
        ? B.renderProductBreakdownTrigger(row, { available: true, hint: 'Clique para detalhar' })
        : '<div class="pd-trigger-cell"><strong>' + B.escapeHtml(row.description || 'Produto sem descricao') + '</strong>'
          + '<div class="small text-muted">' + B.escapeHtml(row.product_code || 'Sem SKU') + '</div></div>';

      return '<tr>'
        + '<td>' + triggerHtml + '</td>'
        + '<td>' + B.formatNumber(row.quantity) + '</td>'
        + '<td>' + B.formatNumber(row.customers_count) + '</td>'
        + '<td>' + B.formatNumber(row.invoices_count) + '</td>'
        + '<td><strong>' + B.formatCurrency(row.revenue) + '</strong></td>'
        + '<td>' + sharePct + '</td>'
        + '</tr>';
    }).join('');

    bindProductTriggers();
  }

  function renderPagination(pag, table) {
    var el = document.getElementById('productPagination');
    if (!el) return;
    if (!pag || pag.total_pages <= 1) { el.style.display = 'none'; return; }
    el.style.display = 'flex';
    el.innerHTML = '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" id="prodPagPrev"'
      + (pag.has_prev ? '' : ' disabled') + '>← Anterior</button>'
      + '<span style="font-size:0.82rem;color:var(--text-2);">'
      + 'Página ' + pag.page + ' de ' + pag.total_pages + ' (' + pag.total + ' produto' + (pag.total !== 1 ? 's' : '') + ')'
      + '</span>'
      + '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" id="prodPagNext"'
      + (pag.has_next ? '' : ' disabled') + '>Próxima →</button>';
    var prev = document.getElementById('prodPagPrev');
    var next = document.getElementById('prodPagNext');
    if (prev) prev.addEventListener('click', function () { table.trigger(buildStateFromDOM(pag.page - 1), pag.page - 1); });
    if (next) next.addEventListener('click', function () { table.trigger(buildStateFromDOM(pag.page + 1), pag.page + 1); });
  }

  function showFilteredNote(pag) {
    var el = document.getElementById('productFilterFeedback');
    if (!el) return;
    if (!pag) { el.style.display = 'none'; el.innerHTML = ''; return; }
    el.innerHTML = '<span style="color:var(--text-2);">'
      + pag.total + ' produto' + (pag.total !== 1 ? 's' : '') + ' encontrado' + (pag.total !== 1 ? 's' : '')
      + ' &mdash; <span style="color:var(--warning,#c58c00)">% Mix calculado sobre o total filtrado</span>'
      + '</span>';
    el.style.display = '';
  }

  // ── DOM sync ──────────────────────────────────────────────────────────────

  function syncDomFromState(state) {
    var qEl = document.getElementById('f_prod_q');
    if (qEl) qEl.value = state.q || '';
    var textFields = ['vendedor', 'cliente'];
    textFields.forEach(function (field) {
      var el = document.getElementById(FILTER_INPUT_MAP[field]);
      if (!el) return;
      var fv = state.filters && state.filters[field];
      el.value = (fv && fv.val != null) ? fv.val : '';
    });
    var numFields = ['min_quantidade', 'max_quantidade', 'min_revenue', 'max_revenue'];
    numFields.forEach(function (field) {
      var el = document.getElementById(FILTER_INPUT_MAP[field]);
      if (!el) return;
      var fv = state.filters && state.filters[field];
      el.value = (fv && fv.val != null) ? fv.val : '';
    });
  }

  function clearAllInputs() {
    var qEl = document.getElementById('f_prod_q');
    if (qEl) qEl.value = '';
    for (var field in FILTER_INPUT_MAP) {
      var el = document.getElementById(FILTER_INPUT_MAP[field]);
      if (el) el.value = '';
    }
  }

  // ── Mount ─────────────────────────────────────────────────────────────────

  function mount() {
    if (!isProductsPage()) return;
    if (!B.ServerTable || !B.FS || !B.FUrl || !B.FChips) return;

    var table = B.ServerTable.create({
      endpoint:         'api/products_search.php',
      feedbackId:       'productSearchFeedback',
      chipsContainerId: 'productFilterChips',
      chipsForState:    chipsForState,
      debounceMs:       350,

      onSuccess: function (resp, state) {
        renderProductRows(resp.data || []);
        renderPagination(resp.pagination || null, table);
        showFilteredNote(resp.pagination || null);
        updateColumnHeaderBadges(state);
        updateSortUI();
        syncDomFromState(state);
      },

      onClear: function () {
        var pagEl = document.getElementById('productPagination');
        if (pagEl) pagEl.style.display = 'none';
        showFilteredNote(null);
        updateColumnHeaderBadges(B.FS.make({}));
        clearAllInputs();
        currentSort = { field: 'revenue', dir: 'desc' };
        updateSortUI();
        var applyBtn = document.getElementById('applyFilters');
        if (applyBtn) applyBtn.click();
      },
    });

    // ── Text search ───────────────────────────────────────────────────────
    var qEl = document.getElementById('f_prod_q');
    if (qEl) {
      qEl.addEventListener('input', function () {
        var s = buildStateFromDOM(1);
        if (B.FS.hasActive(s)) table.triggerDebounced(s); else table.clear();
      });
      qEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { table.trigger(buildStateFromDOM(1)); }
        if (e.key === 'Escape') { qEl.value = ''; table.clear(); }
      });
    }

    // ── Text filters (vendedor, cliente) ──────────────────────────────────
    ['f_prod_vendedor', 'f_prod_cliente'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', function () {
        var s = buildStateFromDOM(1);
        if (B.FS.hasActive(s)) table.triggerDebounced(s); else table.clear();
      });
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { var s = buildStateFromDOM(1); if (B.FS.hasActive(s)) table.trigger(s); else table.clear(); }
        if (e.key === 'Escape') { el.value = ''; var s = buildStateFromDOM(1); if (B.FS.hasActive(s)) table.trigger(s); else table.clear(); }
      });
    });

    // ── Numeric range filters ─────────────────────────────────────────────
    ['f_prod_qty_min', 'f_prod_qty_max', 'f_prod_rev_min', 'f_prod_rev_max'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('change', function () {
        var s = buildStateFromDOM(1);
        if (B.FS.hasActive(s)) table.trigger(s); else table.clear();
      });
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { var s = buildStateFromDOM(1); if (B.FS.hasActive(s)) table.trigger(s); else table.clear(); }
      });
    });

    // ── Quick sort buttons ────────────────────────────────────────────────
    document.querySelectorAll('.nf-quick-filter-btn[data-prod-sort]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var field = btn.getAttribute('data-prod-sort');
        var dir   = btn.getAttribute('data-prod-dir') || 'desc';
        if (currentSort.field === field && currentSort.dir === dir) return;
        currentSort = { field: field, dir: dir };
        updateSortUI();
        var s = B.FS.setFilter(buildStateFromDOM(1), '_sort_active', 'eq', '1');
        table.trigger(s);
      });
    });

    // ── Sortable column headers ───────────────────────────────────────────
    document.querySelectorAll('.nf-th-sortable[data-sort-field]').forEach(function (th) {
      th.style.cursor = 'pointer';
      th.addEventListener('click', function () {
        var field = th.getAttribute('data-sort-field');
        var dir   = (currentSort.field === field && currentSort.dir === 'desc') ? 'asc' : 'desc';
        currentSort = { field: field, dir: dir };
        updateSortUI();
        var s = B.FS.setFilter(buildStateFromDOM(1), '_sort_active', 'eq', '1');
        table.trigger(s);
      });
    });

    document.addEventListener('biomie:product-scope-change', function () {
      var s = buildStateFromDOM(1);
      if (table.isActive()) table.trigger(s);
    });

    // ── URL restore on load ───────────────────────────────────────────────
    var urlState = B.FUrl ? B.FUrl.read() : null;
    if (urlState && B.FS.hasActive(urlState)) {
      if (urlState.q) { var qEl2 = document.getElementById('f_prod_q'); if (qEl2) qEl2.value = urlState.q; }
      if (urlState.filters) {
        var rMap = {
          vendedor:       'f_prod_vendedor',
          cliente:        'f_prod_cliente',
          min_quantidade: 'f_prod_qty_min',
          max_quantidade: 'f_prod_qty_max',
          min_revenue:    'f_prod_rev_min',
          max_revenue:    'f_prod_rev_max',
        };
        for (var field in rMap) {
          var fv = urlState.filters[field];
          if (fv != null) {
            var val = fv.val != null ? fv.val : fv;
            var el  = document.getElementById(rMap[field]);
            if (el) el.value = val;
          }
        }
      }
      if (urlState.sortField) currentSort.field = urlState.sortField;
      if (urlState.sortDir)   currentSort.dir   = urlState.sortDir;
      updateSortUI();
      table.trigger(buildStateFromDOM(urlState.page || 1));
    }
  }

  // ── Init ─────────────────────────────────────────────────────────────────

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
}());
