/**
 * BIomie — Orders page inline search (Fase N.1).
 *
 * Attaches to elements already present in pedidos.html.
 * Searches api/orders_search.php (biomie_notas_fiscais_canonicas).
 * Never calls Omie directly. Never zeros the page on error.
 *
 * Scope is always derived from the active tab (data-nf-scope.mag-tab--active).
 * When search is active, results replace #ordersTable content.
 * When search is cleared, window._nfPageRestore() restores the period view.
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

  // ── State ─────────────────────────────────────────────────────────────────
  var currentAbort   = null;
  var debounceTimer  = null;
  var currentPage    = 1;
  var currentSort    = 'emissao';
  var currentDir     = 'desc';
  var isSearchActive = false;

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

  function hasActiveFilter() {
    return !!(qval('osmQ') || qval('osmVendor') || qval('osmMin') || qval('osmMax'));
  }

  // ── URL state ─────────────────────────────────────────────────────────────
  var SEARCH_PARAMS = ['q', 'scope', 'vendor', 'min_value', 'max_value', 'sort', 'direction', 'page'];

  function pushUrl(params) {
    if (!window.history || !history.replaceState) return;
    var url = new URL(location.href);
    SEARCH_PARAMS.forEach(function (k) {
      if (params[k] !== undefined && params[k] !== '' && params[k] != null) {
        url.searchParams.set(k, String(params[k]));
      } else {
        url.searchParams.delete(k);
      }
    });
    history.replaceState(null, '', url.toString());
  }

  function clearUrl() {
    if (!window.history || !history.replaceState) return;
    var url = new URL(location.href);
    SEARCH_PARAMS.forEach(function (k) { url.searchParams.delete(k); });
    history.replaceState(null, '', url.toString());
  }

  function readUrl() {
    var sp = new URLSearchParams(location.search);
    return {
      q:         sp.get('q')         || '',
      scope:     sp.get('scope')     || '',
      vendor:    sp.get('vendor')    || '',
      min_value: sp.get('min_value') || '',
      max_value: sp.get('max_value') || '',
      sort:      sp.get('sort')      || 'emissao',
      direction: sp.get('direction') || 'desc',
      page:      parseInt(sp.get('page'), 10) || 1,
    };
  }

  // ── Active filter chips ───────────────────────────────────────────────────
  function renderChips() {
    var container = el('nfActiveFilters');
    if (!container) return;

    var chips = [];
    var q = qval('osmQ'); var vendor = qval('osmVendor');
    var min = qval('osmMin'); var max = qval('osmMax');
    if (q)      chips.push({ key: 'q',         label: 'Busca: ' + q });
    if (vendor) chips.push({ key: 'vendor',    label: 'Vendedor: ' + vendor });
    if (min)    chips.push({ key: 'min_value', label: 'Mín: ' + min });
    if (max)    chips.push({ key: 'max_value', label: 'Máx: ' + max });

    if (!chips.length) { container.style.display = 'none'; container.innerHTML = ''; return; }

    container.style.display = 'flex';
    container.innerHTML = chips.map(function (c) {
      return '<span class="mag-orders-filter-chip" data-chip-key="' + c.key + '">' +
        esc(c.label) +
        '<button type="button" class="mag-orders-filter-chip__remove" data-chip-remove="' + c.key + '" aria-label="Remover filtro">×</button>' +
        '</span>';
    }).join('');
  }

  function removeChip(key) {
    var idMap = { q: 'osmQ', vendor: 'osmVendor', min_value: 'osmMin', max_value: 'osmMax' };
    var input = el(idMap[key]);
    if (input) input.value = '';
    if (!hasActiveFilter()) { clearSearch(); return; }
    renderChips();
    triggerSearch(1);
  }

  // ── Render results ────────────────────────────────────────────────────────
  function renderSearchSummary(summary, pagination) {
    var summaryEl = el('nfSearchSummary');
    if (!summaryEl || !summary) return;
    var scopeLabel = { commercial: 'Orçamento-PD', remittance: 'Remessa', all: 'Todas' }[summary.scope] || 'NFs';
    var count      = pagination ? pagination.total : summary.nf_count;
    // Fase O: prefer base_value (valor_total - frete - outras - seguro); fall back to total_value pre-migration.
    var baseVal    = summary.base_value != null ? summary.base_value : summary.total_value;
    summaryEl.style.display = 'flex';
    summaryEl.innerHTML =
      '<span>' + esc(scopeLabel) + ': <strong>' + count + ' NF' + (count !== 1 ? 's' : '') + '</strong></span>' +
      '<span class="mag-orders-results-summary__sep">·</span>' +
      '<span class="mag-orders-results-summary__value">' + fmt(baseVal) + '</span>' +
      '<span class="mag-orders-results-summary__sep">·</span>' +
      '<span>Merc: ' + fmt(summary.merchandise_value) + '</span>';
  }

  function updateSortIcons() {
    document.querySelectorAll('[data-sort-field]').forEach(function (th) {
      var icon  = th.querySelector('.nf-sort-icon');
      var field = th.dataset.sortField;
      if (!icon) return;
      if (isSearchActive && field === currentSort) {
        icon.textContent = currentDir === 'asc' ? ' ↑' : ' ↓';
        th.classList.add('nf-th-sorted');
      } else {
        icon.textContent = '';
        th.classList.remove('nf-th-sorted');
      }
    });
  }

  function renderSearchResults(orders) {
    var tbody = el('ordersTable');
    if (!tbody) return;

    if (!orders.length) {
      tbody.innerHTML =
        '<tr><td colspan="9" style="text-align:center;padding:32px 16px;color:var(--text-3);font-size:13px;">' +
        'Nenhuma NF encontrada para essa busca.</td></tr>';
      return;
    }

    tbody.innerHTML = orders.map(function (row) {
      var scope  = row.operation_scope || 'commercial';
      var pill   = SCOPE_PILL[scope]   || SCOPE_PILL.commercial;
      var nf     = esc(row.numero_nf   || '');
      var key    = esc(row.nota_key    || '');
      var nfBtn  = nf
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-invoice" data-numero-nf="' + nf + '" data-nota-key="' + key + '" style="font-weight:600">' + nf + '</button>'
        : '<span style="color:var(--text-3)">—</span>';
      var pedido = esc(String(row.codigo_pedido_origem || '').trim());
      var pedBtn = pedido
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-order" data-pedido-codigo="' + pedido + '">' + pedido + '</button>'
        : '<span style="color:var(--text-3)">—</span>';
      var disc = row.valor_descontos
        ? fmt(row.valor_descontos)
        : '<span style="color:var(--text-3)">—</span>';
      // Fase O: show fiscal base; fall back to valor_total for pre-migration rows.
      var baseComercial = row.valor_base_comercial != null ? row.valor_base_comercial : row.valor_total;
      var freteIndicator  = row.valor_frete > 0
        ? '<span class="nf-freight-indicator" title="Frete: ' + fmt(row.valor_frete) + '">+F</span>'
        : '';
      var outrasIndicator = row.valor_outras_despesas > 0
        ? '<span class="nf-outros-indicator" title="Outras despesas: ' + fmt(row.valor_outras_despesas) + '">+O</span>'
        : '';
      var seguroIndicator = row.valor_seguro > 0
        ? '<span class="nf-seguro-indicator" title="Seguro: ' + fmt(row.valor_seguro) + '">+S</span>'
        : '';

      return '<tr>' +
        '<td class="mag-table-cell">' + nfBtn + '</td>' +
        '<td class="mag-table-cell">' + pill + '</td>' +
        '<td class="mag-table-cell">' + esc(row.nome_cliente  || '—') + '</td>' +
        '<td class="mag-table-cell">' + esc(row.nome_vendedor || '—') + '</td>' +
        '<td class="mag-table-cell">' + fmtDate(row.data_emissao) + '</td>' +
        '<td class="mag-table-cell">' + fmt(row.valor_mercadorias || 0) + '</td>' +
        '<td class="mag-table-cell">' + disc + '</td>' +
        '<td class="mag-table-cell"><strong>' + fmt(baseComercial || 0) + '</strong>' + freteIndicator + outrasIndicator + seguroIndicator + '</td>' +
        '<td class="mag-table-cell">' + pedBtn + '</td>' +
        '</tr>';
    }).join('');
  }

  function renderPagination(pag) {
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
        triggerSearch(parseInt(btn.dataset.pageNav, 10) || 1);
      });
    });
  }

  // ── Search ────────────────────────────────────────────────────────────────
  function buildParams(page) {
    return {
      q:         qval('osmQ'),
      scope:     getActiveScope(),
      vendor:    qval('osmVendor'),
      min_value: qval('osmMin'),
      max_value: qval('osmMax'),
      sort:      currentSort,
      direction: currentDir,
      page:      page || 1,
      per_page:  50,
    };
  }

  function triggerSearch(page) {
    if (!hasActiveFilter()) { clearSearch(); return; }

    var params = buildParams(page || 1);
    currentPage = params.page;

    if (currentAbort) { try { currentAbort.abort(); } catch (ex) { /* noop */ } }
    currentAbort = new AbortController();

    isSearchActive = true;
    var clearBtn = el('osmClear');
    if (clearBtn) clearBtn.style.display = '';

    renderChips();
    pushUrl(params);
    updateSortIcons();

    var feedbackEl = el('nfSearchFeedback');
    if (feedbackEl) { feedbackEl.style.display = 'none'; feedbackEl.innerHTML = ''; }

    var qs = new URLSearchParams();
    Object.keys(params).forEach(function (k) {
      if (params[k] !== '' && params[k] != null) qs.set(k, String(params[k]));
    });

    fetch('api/orders_search.php?' + qs.toString(), {
      credentials: 'same-origin',
      signal: currentAbort.signal,
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        currentAbort = null;
        if (!json.ok) {
          // Show error inline — never clear the table (regra 9+10)
          if (feedbackEl) {
            feedbackEl.style.display = '';
            feedbackEl.innerHTML =
              '<div class="mag-orders-feedback mag-orders-feedback--error">' +
              '<span class="material-symbols-rounded" style="font-size:18px;flex-shrink:0;">error_outline</span>' +
              'Não foi possível aplicar a busca agora. Os resultados anteriores foram mantidos.</div>';
          }
          return;
        }
        renderSearchSummary(json.search_summary, json.pagination);
        renderSearchResults(json.orders || []);
        renderPagination(json.pagination);
        currentSort = (json.sort && json.sort.field)     || currentSort;
        currentDir  = (json.sort && json.sort.direction) || currentDir;
        updateSortIcons();
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        currentAbort = null;
        if (feedbackEl) {
          feedbackEl.style.display = '';
          feedbackEl.innerHTML =
            '<div class="mag-orders-feedback mag-orders-feedback--error">' +
            '<span class="material-symbols-rounded" style="font-size:18px;flex-shrink:0;">wifi_off</span>' +
            'Não foi possível aplicar a busca agora. Os resultados anteriores foram mantidos.</div>';
        }
      });
  }

  function clearSearch() {
    isSearchActive = false;

    if (currentAbort) { try { currentAbort.abort(); } catch (ex) { /* noop */ } currentAbort = null; }

    ['osmQ', 'osmVendor', 'osmMin', 'osmMax'].forEach(function (id) {
      var input = el(id); if (input) input.value = '';
    });

    var clearBtn = el('osmClear');
    if (clearBtn) clearBtn.style.display = 'none';

    var chips = el('nfActiveFilters');
    if (chips) { chips.style.display = 'none'; chips.innerHTML = ''; }

    var summary = el('nfSearchSummary');
    if (summary) { summary.style.display = 'none'; summary.innerHTML = ''; }

    var feedback = el('nfSearchFeedback');
    if (feedback) { feedback.style.display = 'none'; feedback.innerHTML = ''; }

    var pag = el('nfSearchPagination');
    if (pag) { pag.style.display = 'none'; pag.innerHTML = ''; }

    clearUrl();
    updateSortIcons();

    if (typeof window._nfPageRestore === 'function') {
      window._nfPageRestore();
    }
  }

  // Expose so app.js can reset search UI on data reload
  window._clearBiomieSearch = clearSearch;

  // ── Wire events ───────────────────────────────────────────────────────────
  function wireEvents() {
    var qInput    = el('osmQ');
    var clearBtn  = el('osmClear');
    var extrasBtn = el('osmToggleFilters');
    var extrasDiv = el('nfSearchExtras');

    if (qInput) {
      qInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { triggerSearch(1); }, 350);
      });
      qInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter')  { clearTimeout(debounceTimer); triggerSearch(1); }
        if (e.key === 'Escape') { clearSearch(); }
      });
    }

    if (clearBtn)  { clearBtn.addEventListener('click', clearSearch); }

    if (extrasBtn && extrasDiv) {
      extrasBtn.addEventListener('click', function () {
        var isOpen = extrasDiv.style.display !== 'none';
        extrasDiv.style.display = isOpen ? 'none' : 'flex';
        extrasBtn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
      });
    }

    ['osmVendor', 'osmMin', 'osmMax'].forEach(function (id) {
      var input = el(id);
      if (!input) return;
      input.addEventListener('change', function () { triggerSearch(1); });
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') triggerSearch(1); });
    });

    // Chip removal via event delegation
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-chip-remove]') : null;
      if (!btn) {
        // IE fallback
        var t = e.target;
        while (t && t !== document.body) {
          if (t.dataset && t.dataset.chipRemove) { btn = t; break; }
          t = t.parentNode;
        }
      }
      if (btn && btn.dataset.chipRemove) { removeChip(btn.dataset.chipRemove); }
    });

    // Column sort (only active during search)
    document.querySelectorAll('[data-sort-field]').forEach(function (th) {
      th.addEventListener('click', function () {
        if (!isSearchActive) return;
        var field = th.dataset.sortField;
        if (currentSort === field) {
          currentDir = currentDir === 'asc' ? 'desc' : 'asc';
        } else {
          currentSort = field;
          currentDir  = 'desc';
        }
        triggerSearch(currentPage);
      });
    });

    // Tab scope change: re-search if active, after app.js renders period data
    document.querySelectorAll('[data-nf-scope]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (isSearchActive && hasActiveFilter()) {
          setTimeout(function () { triggerSearch(1); }, 0);
        }
      });
    });
  }

  // ── Mount ─────────────────────────────────────────────────────────────────
  function mount() {
    if (!el('osmQ')) return;  // not on orders page

    wireEvents();

    // Restore from URL on page load
    var urlP = readUrl();
    if (urlP.q || urlP.vendor || urlP.min_value || urlP.max_value) {
      currentSort = urlP.sort      || 'emissao';
      currentDir  = urlP.direction || 'desc';

      if (urlP.q)         { var qi = el('osmQ');      if (qi) qi.value = urlP.q; }
      if (urlP.vendor)    { var vi = el('osmVendor'); if (vi) vi.value = urlP.vendor; }
      if (urlP.min_value) { var mi = el('osmMin');    if (mi) mi.value = urlP.min_value; }
      if (urlP.max_value) { var xi = el('osmMax');    if (xi) xi.value = urlP.max_value; }

      if (urlP.vendor || urlP.min_value || urlP.max_value) {
        var extras = el('nfSearchExtras');
        var btn    = el('osmToggleFilters');
        if (extras) extras.style.display = 'flex';
        if (btn)    btn.setAttribute('aria-expanded', 'true');
      }

      // Delay to let app.js finish its initial render first
      setTimeout(function () { triggerSearch(urlP.page || 1); }, 600);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
}());
