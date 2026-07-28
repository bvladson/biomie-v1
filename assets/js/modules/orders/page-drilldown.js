/**
 * BIomie — Orders page search + filter (Fase Q.2).
 *
 * Uses:
 *   B.RC        (request-controller.js) — AbortController + debounce
 *   B.FS        (filter-state.js)       — immutable filter state helpers
 *   B.FUrl      (filter-url.js)         — URL ↔ filter state sync
 *   B.FChips    (filter-chips.js)       — active chip rendering
 *   B.ServerTable (server-table.js)     — fetch + abort + state orchestration
 *
 * Attaches to elements in views/pedidos.html.
 * Searches api/orders_search.php.
 * Never calls Omie. Never zeros the page on error.
 *
 * Scope is derived from the active tab (data-nf-scope.mag-tab--active),
 * NOT stored as a chip (it's navigation, not a filter).
 */
(function () {
  'use strict';

  if (window.BIomieOrdersDrilldown) return;
  window.BIomieOrdersDrilldown = true;

  var B   = window.BIomie || {};
  var fmt = function (v) { return B.formatCurrency ? B.formatCurrency(v) : String(v); };
  var esc = function (v) {
    return B.escapeHtml
      ? B.escapeHtml(v)
      : String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
  };
  var fmtDate = function (d) {
    if (!d) return '—';
    var p = String(d).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : d;
  };

  // ── Scope pill labels ────────────────────────────────────────────────────
  var SCOPE_PILL = {
    commercial: '<span class="nf-scope-pill nf-scope-pill--commercial">Orç-PD</span>',
    remittance: '<span class="nf-scope-pill nf-scope-pill--remittance">Remessa</span>',
  };

  // ── DOM helpers ───────────────────────────────────────────────────────────
  function el(id)     { return document.getElementById(id); }
  function qval(id)   { var e = el(id); return e ? e.value.trim() : ''; }

  function getActiveScope() {
    var tab = document.querySelector('[data-nf-scope].mag-tab--active');
    return tab ? (tab.dataset.nfScope || 'commercial') : 'commercial';
  }

  // ── Build filter state from all DOM inputs ────────────────────────────────
  function buildStateFromDOM(page) {
    var FS     = B.FS;
    var scope  = getActiveScope();
    var state  = FS.make({ scope: scope, page: page || 1 });

    // Global q
    var q = qval('osmQ');
    if (q) state = FS.setQ(state, q);

    // Column filters — text inputs
    var colText = { nf: 'f_col_nf', cliente: 'f_col_cliente', vendedor: 'f_col_vendedor', pedido: 'f_col_pedido' };
    Object.keys(colText).forEach(function (field) {
      var v = qval(colText[field]);
      if (v) state = FS.setFilter(state, field, 'contains', v);
    });

    // tipo select
    var tipoEl = el('f_col_tipo');
    if (tipoEl && tipoEl.value && tipoEl.value !== '') {
      state = FS.setFilter(state, 'tipo', 'equals', tipoEl.value);
    }

    // Base Comercial range
    var bcMin = qval('f_col_bc_min');
    var bcMax = qval('f_col_bc_max');
    if (bcMin) state = FS.setFilter(state, 'base_comercial_gte', 'gte', bcMin);
    if (bcMax) state = FS.setFilter(state, 'base_comercial_lte', 'lte', bcMax);

    // Backward-compat value range (osmMin / osmMax)
    var legacyMin = qval('osmMin');
    var legacyMax = qval('osmMax');
    if (legacyMin && !bcMin) state = FS.setFilter(state, 'base_comercial_gte', 'gte', legacyMin);
    if (legacyMax && !bcMax) state = FS.setFilter(state, 'base_comercial_lte', 'lte', legacyMax);

    // Quick boolean filters (toggled via data-quick-active attribute)
    document.querySelectorAll('.nf-quick-filter-btn[data-quick-filter]').forEach(function (btn) {
      if (btn.dataset.quickActive === '1') {
        var field = btn.dataset.quickFilter;
        var val   = btn.dataset.quickValue || '1';
        // scope quick-filters are already handled via tab scope
        if (field !== 'scope') {
          state = FS.setFilter(state, field, 'equals', val);
        }
      }
    });

    return state;
  }

  // ── Chips labels ──────────────────────────────────────────────────────────
  var CHIP_LABELS = {
    q:                 function (v) { return 'Busca: ' + v; },
    nf:                function (v) { return 'NF: ' + v; },
    cliente:           function (v) { return 'Cliente: ' + v; },
    vendedor:          function (v) { return 'Vendedor: ' + v; },
    pedido:            function (v) { return 'Pedido: ' + v; },
    tipo:              function (v) { return 'Tipo: ' + ({ commercial: 'Orç-PD', remittance: 'Remessa' }[v] || v); },
    base_comercial_gte: function (v) { return 'Base ≥ ' + fmt(v); },
    base_comercial_lte: function (v) { return 'Base ≤ ' + fmt(v); },
    has_frete:         function ()   { return 'Com frete'; },
    has_outras:        function ()   { return 'Com outras despesas'; },
    has_seguro:        function ()   { return 'Com seguro'; },
    com_desconto:      function ()   { return 'Com desconto'; },
    sem_pedido:        function ()   { return 'Sem pedido'; },
  };

  function chipsForState(state) {
    var chips = [];
    if (state.q) chips.push({ key: 'q', label: CHIP_LABELS.q(state.q) });
    if (state.filters) {
      Object.keys(state.filters).forEach(function (field) {
        var f = state.filters[field];
        if (!f || f.val === null || f.val === '' || f.val === undefined) return;
        var labelFn = CHIP_LABELS[field];
        chips.push({ key: field, label: labelFn ? labelFn(f.val) : field + ': ' + f.val });
      });
    }
    return chips;
  }

  // ── Sort indicator update ─────────────────────────────────────────────────
  function updateSortIcons(state, active) {
    document.querySelectorAll('[data-sort-field]').forEach(function (th) {
      var icon  = th.querySelector('.nf-sort-icon');
      var field = th.dataset.sortField;
      if (!icon) return;
      var isSorted = active && field === state.sort;
      icon.textContent = isSorted ? (state.direction === 'asc' ? ' ↑' : ' ↓') : '';
      th.classList.toggle('nf-th-sorted', isSorted);
    });
  }

  // ── Update active-filter badge on column headers ──────────────────────────
  function updateColumnHeaderBadges(state) {
    var HEADER_MAP = {
      nf:                 'th-nf',
      cliente:            'th-cliente',
      vendedor:           'th-vendedor',
      pedido:             'th-pedido',
      tipo:               'th-tipo',
      base_comercial_gte: 'th-base',
      base_comercial_lte: 'th-base',
    };
    // Reset all
    document.querySelectorAll('[data-filter-header]').forEach(function (th) {
      th.classList.remove('nf-th-filter-active');
    });
    // Set active
    if (state.filters) {
      Object.keys(state.filters).forEach(function (field) {
        var f = state.filters[field];
        if (!f || f.val === null || f.val === '' || f.val === undefined) return;
        var headerId = HEADER_MAP[field];
        if (headerId) {
          var th = document.getElementById(headerId);
          if (th) th.classList.add('nf-th-filter-active');
        }
      });
    }
  }

  // ── Search result rendering ────────────────────────────────────────────────
  function renderSearchSummary(summary, pagination) {
    var summaryEl = el('nfSearchSummary');
    if (!summaryEl || !summary) return;
    var scopeLabel = { commercial: 'Orçamento-PD', remittance: 'Remessa', all: 'Todas' }[summary.scope] || 'NFs';
    var count      = pagination ? pagination.total : summary.nf_count;
    var baseVal    = summary.base_value != null ? summary.base_value : summary.total_value;
    summaryEl.style.display = 'flex';
    summaryEl.innerHTML =
      '<span>' + esc(scopeLabel) + ': <strong>' + count + ' NF' + (count !== 1 ? 's' : '') + '</strong></span>' +
      '<span class="mag-orders-results-summary__sep">·</span>' +
      '<span class="mag-orders-results-summary__value">' + fmt(baseVal) + '</span>' +
      '<span class="mag-orders-results-summary__sep">·</span>' +
      '<span>Merc: ' + fmt(summary.merchandise_value) + '</span>';
  }

  function renderSearchResults(rows) {
    var tbody = el('ordersTable');
    if (!tbody) return;

    if (!rows || !rows.length) {
      tbody.innerHTML =
        '<tr><td colspan="9" style="text-align:center;padding:32px 16px;color:var(--text-3);font-size:13px;">' +
        'Nenhuma NF encontrada para essa busca.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(function (row) {
      var scope = row.operation_scope || 'commercial';
      var pill  = SCOPE_PILL[scope]   || SCOPE_PILL.commercial;
      var nf    = esc(row.numero_nf  || '');
      var key   = esc(row.nota_key   || '');
      var nfBtn = nf
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-invoice" ' +
          'data-numero-nf="' + nf + '" data-nota-key="' + key + '" style="font-weight:600">' + nf + '</button>'
        : '<span style="color:var(--text-3)">—</span>';

      var pedido = esc(String(row.codigo_pedido_origem || '').trim());
      var pedBtn = pedido
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-order" ' +
          'data-pedido-codigo="' + pedido + '">' + pedido + '</button>'
        : '<span style="color:var(--text-3)">—</span>';

      var disc = row.valor_descontos
        ? fmt(row.valor_descontos)
        : '<span style="color:var(--text-3)">—</span>';

      var baseComercial = row.valor_base_comercial != null ? row.valor_base_comercial : row.valor_total;
      var freteInd  = row.has_frete || row.valor_frete > 0
        ? '<span class="nf-freight-indicator" title="Frete: ' + fmt(row.valor_frete) + '">+F</span>' : '';
      var outrasInd = row.has_outras_despesas || row.valor_outras_despesas > 0
        ? '<span class="nf-outros-indicator" title="Outras: ' + fmt(row.valor_outras_despesas) + '">+O</span>' : '';
      var seguroInd = row.has_seguro || row.valor_seguro > 0
        ? '<span class="nf-seguro-indicator" title="Seguro: ' + fmt(row.valor_seguro) + '">+S</span>' : '';

      return '<tr>' +
        '<td class="mag-table-cell">' + nfBtn + '</td>' +
        '<td class="mag-table-cell">' + pill + '</td>' +
        '<td class="mag-table-cell">' + esc(row.nome_cliente  || '—') + '</td>' +
        '<td class="mag-table-cell">' + esc(row.nome_vendedor || '—') + '</td>' +
        '<td class="mag-table-cell">' + fmtDate(row.data_emissao) + '</td>' +
        '<td class="mag-table-cell">' + fmt(row.valor_mercadorias || 0) + '</td>' +
        '<td class="mag-table-cell">' + disc + '</td>' +
        '<td class="mag-table-cell"><strong>' + fmt(baseComercial || 0) + '</strong>' +
          freteInd + outrasInd + seguroInd + '</td>' +
        '<td class="mag-table-cell">' + pedBtn + '</td>' +
        '</tr>';
    }).join('');
  }

  function renderPagination(pag, table) {
    var pagEl = el('nfSearchPagination');
    if (!pagEl) return;
    if (!pag || pag.total_pages <= 1) {
      pagEl.style.display = 'none'; pagEl.innerHTML = ''; return;
    }
    pagEl.style.display = 'flex';
    pagEl.innerHTML =
      (pag.has_prev
        ? '<button type="button" class="mag-orders-page-btn" data-page-nav="' + (pag.page - 1) + '">← Anterior</button>'
        : '') +
      '<span class="mag-orders-page-info">Página ' + pag.page + ' de ' + pag.total_pages + ' (' + pag.total + ' NFs)</span>' +
      (pag.has_next
        ? '<button type="button" class="mag-orders-page-btn" data-page-nav="' + (pag.page + 1) + '">Próxima →</button>'
        : '');
    pagEl.querySelectorAll('[data-page-nav]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        table.trigger(table.getState(), parseInt(btn.dataset.pageNav, 10) || 1);
      });
    });
  }

  // ── Mount ─────────────────────────────────────────────────────────────────
  function mount() {
    if (!el('osmQ')) return; // not on the orders page

    var FS   = B.FS;
    var FUrl = B.FUrl;

    // Create the server-table controller
    var table = B.ServerTable.create({
      endpoint:        'api/orders_search.php',
      feedbackId:      'nfSearchFeedback',
      chipsContainerId: 'nfActiveFilters',
      chipsForState:   chipsForState,
      debounceMs:      350,

      onSuccess: function (json, state) {
        var rows       = json.data    || json.orders || [];
        var pagination = json.pagination;
        var summary    = json.search_summary;

        renderSearchSummary(summary, pagination);
        renderSearchResults(rows);
        renderPagination(pagination, table);
        updateSortIcons(state, true);
        updateColumnHeaderBadges(state);

        // Show clear button
        var clearBtn = el('osmClear');
        if (clearBtn) clearBtn.style.display = '';
      },

      onClear: function () {
        // Hide UI elements
        var summaryEl = el('nfSearchSummary');
        if (summaryEl) { summaryEl.style.display = 'none'; summaryEl.innerHTML = ''; }

        var pagEl = el('nfSearchPagination');
        if (pagEl) { pagEl.style.display = 'none'; pagEl.innerHTML = ''; }

        var feedbackEl = el('nfSearchFeedback');
        if (feedbackEl) { feedbackEl.style.display = 'none'; feedbackEl.innerHTML = ''; }

        var clearBtn = el('osmClear');
        if (clearBtn) clearBtn.style.display = 'none';

        // Reset column filter inputs
        document.querySelectorAll('.nf-col-filter').forEach(function (inp) { inp.value = ''; inp.classList.remove('nf-col-filter--active'); });
        document.querySelectorAll('.nf-quick-filter-btn').forEach(function (btn) {
          btn.dataset.quickActive = '0';
          btn.classList.remove('nf-quick-filter-btn--active');
        });

        updateSortIcons(FS.make(), false);
        updateColumnHeaderBadges(FS.make());

        // Restore period view
        if (typeof window._nfPageRestore === 'function') window._nfPageRestore();
      },
    });

    // Expose so app.js can reset search on data reload
    window._clearBiomieSearch = function () { table.clear(); };

    // ── Wire global search input ────────────────────────────────────────────
    var qInput   = el('osmQ');
    var clearBtn = el('osmClear');

    if (qInput) {
      qInput.addEventListener('input', function () {
        var state = buildStateFromDOM(1);
        if (FS.hasActive(state)) {
          table.triggerDebounced(state);
        } else {
          table.clear();
        }
      });
      qInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter')  { table.trigger(buildStateFromDOM(1)); }
        if (e.key === 'Escape') { clearInput('osmQ'); table.clear(); }
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', function () { clearAllInputs(); table.clear(); });
    }

    // ── Wire "Mais filtros" toggle (backward-compat osmToggleFilters) ────────
    var extrasBtn = el('osmToggleFilters');
    var extrasDiv = el('nfSearchExtras');
    if (extrasBtn && extrasDiv) {
      extrasBtn.addEventListener('click', function () {
        var isOpen = extrasDiv.style.display !== 'none';
        extrasDiv.style.display = isOpen ? 'none' : 'flex';
        extrasBtn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
      });
    }

    // Backward-compat osmMin / osmMax
    ['osmVendor', 'osmMin', 'osmMax'].forEach(function (id) {
      var inp = el(id);
      if (!inp) return;
      inp.addEventListener('change', function () { table.trigger(buildStateFromDOM(1)); });
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') table.trigger(buildStateFromDOM(1)); });
    });

    // ── Wire column header filter inputs ─────────────────────────────────────
    document.querySelectorAll('.nf-col-filter').forEach(function (inp) {
      inp.addEventListener('input', function () {
        inp.classList.toggle('nf-col-filter--active', inp.value.trim() !== '');
        var state = buildStateFromDOM(1);
        if (FS.hasActive(state)) {
          table.triggerDebounced(state);
        } else {
          table.clear();
        }
      });
      inp.addEventListener('change', function () {
        var state = buildStateFromDOM(1);
        if (FS.hasActive(state)) table.trigger(state, 1);
        else table.clear();
      });
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { var st = buildStateFromDOM(1); if (FS.hasActive(st)) table.trigger(st, 1); else table.clear(); }
      });
    });

    // ── Wire quick filter buttons ─────────────────────────────────────────────
    document.querySelectorAll('.nf-quick-filter-btn[data-quick-filter]').forEach(function (btn) {
      btn.dataset.quickActive = '0';
      btn.addEventListener('click', function () {
        var isActive = btn.dataset.quickActive === '1';
        btn.dataset.quickActive = isActive ? '0' : '1';
        btn.classList.toggle('nf-quick-filter-btn--active', !isActive);
        var state = buildStateFromDOM(1);
        if (FS.hasActive(state)) table.trigger(state, 1);
        else table.clear();
      });
    });

    // ── Wire sort column headers ──────────────────────────────────────────────
    document.querySelectorAll('[data-sort-field]').forEach(function (th) {
      th.addEventListener('click', function () {
        if (!table.isActive()) return;
        var field    = th.dataset.sortField;
        var curState = table.getState();
        var newDir   = (curState.sort === field && curState.direction === 'desc') ? 'asc' : 'desc';
        table.setSort(field, newDir);
      });
    });

    // ── Wire scope tabs (re-search with new scope when active) ───────────────
    document.querySelectorAll('[data-nf-scope]').forEach(function (tab) {
      tab.addEventListener('click', function () {
        if (table.isActive()) {
          setTimeout(function () { table.trigger(buildStateFromDOM(1)); }, 0);
        }
      });
    });

    // ── Restore from URL on page load ────────────────────────────────────────
    var urlState = FUrl.read();
    var hasUrlFilters = urlState.q || (urlState.filters && Object.keys(urlState.filters).length > 0);

    if (hasUrlFilters) {
      // Populate inputs from URL
      if (urlState.q && qInput) qInput.value = urlState.q;

      // Column filters
      var URL_TO_INPUT = { nf: 'f_col_nf', cliente: 'f_col_cliente', vendedor: 'f_col_vendedor', pedido: 'f_col_pedido' };
      Object.keys(URL_TO_INPUT).forEach(function (field) {
        var fVal = FS.filterVal(urlState, field);
        if (fVal) {
          var inp = el(URL_TO_INPUT[field]);
          if (inp) { inp.value = fVal; inp.classList.add('nf-col-filter--active'); }
        }
      });

      // tipo
      var tipoVal = FS.filterVal(urlState, 'tipo');
      if (tipoVal) { var tipoEl = el('f_col_tipo'); if (tipoEl) tipoEl.value = tipoVal; }

      // base range
      var bcMinVal = FS.filterVal(urlState, 'base_comercial_gte');
      var bcMaxVal = FS.filterVal(urlState, 'base_comercial_lte');
      if (bcMinVal) { var bcMin = el('f_col_bc_min'); if (bcMin) bcMin.value = bcMinVal; }
      if (bcMaxVal) { var bcMax = el('f_col_bc_max'); if (bcMax) bcMax.value = bcMaxVal; }

      // Show extras panel if legacy filters are present
      var hasLegacy = urlState.filters && (urlState.filters.base_comercial_gte || urlState.filters.base_comercial_lte);
      if (hasLegacy && extrasDiv && extrasBtn) {
        extrasDiv.style.display = 'flex';
        extrasBtn.setAttribute('aria-expanded', 'true');
      }

      // Delay to let app.js finish its initial render first
      setTimeout(function () { table.trigger(urlState, urlState.page || 1); }, 600);
    }
  }

  // ── Helpers ──────────────────────────────────────────────────────────────
  function clearInput(id) { var e = el(id); if (e) e.value = ''; }
  function clearAllInputs() {
    ['osmQ', 'osmVendor', 'osmMin', 'osmMax'].forEach(clearInput);
    document.querySelectorAll('.nf-col-filter').forEach(function (inp) { inp.value = ''; inp.classList.remove('nf-col-filter--active'); });
    document.querySelectorAll('.nf-quick-filter-btn').forEach(function (btn) {
      btn.dataset.quickActive = '0';
      btn.classList.remove('nf-quick-filter-btn--active');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
}());
