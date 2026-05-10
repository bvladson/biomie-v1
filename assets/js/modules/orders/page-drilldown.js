/**
 * BIomie — Orders page drilldown + search bar.
 * Connects pedidos.html to BIomieOrderDetail and BIomieInvoiceDetail via
 * the orders_search.php API. Event delegation is handled by drilldown-router.js.
 *
 * Responsibilities:
 *   - Wire the search bar (debounce, AbortController, pagination)
 *   - Render search results with data-biomie-action attributes
 *   - Not responsible for rendering the drawers (handled by the modules)
 */
(function () {
  'use strict';

  if (window.BIomieOrdersPageDrilldown) return;
  window.BIomieOrdersPageDrilldown = true;

  var B   = window.BIomie || {};
  var esc = function (v) { return B.escapeHtml ? B.escapeHtml(v) : String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };
  var fmt = function (v) { return B.formatCurrency ? B.formatCurrency(v) : String(v); };

  var SEARCH_BAR_ID  = 'biomie-orders-search';
  var RESULTS_ID     = 'biomie-orders-results';
  var currentAbort   = null;
  var debounceTimer  = null;
  var currentPage    = 1;
  var lastParams     = {};

  // ── Build search bar HTML ─────────────────────────────────────────────────

  function buildSearchBar() {
    var bar = document.createElement('section');
    bar.className = 'table-card anim';
    bar.id = SEARCH_BAR_ID;
    bar.innerHTML =
      '<div class="table-card-header"><h3>Busca de pedidos e NFs</h3><small>Busca direta na camada canonical — sem consulta ao Omie.</small></div>' +
      '<div class="orders-search-bar">' +
        '<div class="orders-search-bar__input-wrap">' +
          '<span class="material-symbols-outlined orders-search-bar__icon">search</span>' +
          '<input type="search" id="osmQ" class="orders-search-bar__input" placeholder="Pedido, NF, cliente, CNPJ..." autocomplete="off" />' +
        '</div>' +
        '<div class="orders-search-bar__filters">' +
          '<input type="text" id="osmVendor" class="orders-search-bar__filter-input" placeholder="Vendedor" />' +
          '<input type="text" id="osmStatus" class="orders-search-bar__filter-input" placeholder="Status" />' +
          '<input type="number" id="osmMin" class="orders-search-bar__filter-input" placeholder="Valor mín." step="0.01" min="0" />' +
          '<input type="number" id="osmMax" class="orders-search-bar__filter-input" placeholder="Valor máx." step="0.01" min="0" />' +
        '</div>' +
        '<div class="orders-search-bar__actions">' +
          '<button type="button" id="osmSubmit" class="orders-search-bar__btn">Buscar</button>' +
          '<button type="button" id="osmClear" class="orders-search-bar__btn orders-search-bar__btn--ghost">Limpar</button>' +
        '</div>' +
      '</div>' +
      '<div id="' + RESULTS_ID + '" class="orders-search-bar__results" aria-live="polite"></div>';
    return bar;
  }

  // ── Build search params ───────────────────────────────────────────────────

  function collectParams(page) {
    var q      = (document.getElementById('osmQ')      || {}).value || '';
    var vendor = (document.getElementById('osmVendor') || {}).value || '';
    var status = (document.getElementById('osmStatus') || {}).value || '';
    var min    = (document.getElementById('osmMin')    || {}).value || '';
    var max    = (document.getElementById('osmMax')    || {}).value || '';
    return { q: q.trim(), vendor: vendor.trim(), status: status.trim(), min_value: min.trim(), max_value: max.trim(), page: page || 1, per_page: 50 };
  }

  // ── Fetch & render ────────────────────────────────────────────────────────

  function search(page) {
    var el = document.getElementById(RESULTS_ID);
    if (!el) return;

    var params = collectParams(page || 1);
    var hasFilter = params.q || params.vendor || params.status || params.min_value || params.max_value;
    if (!hasFilter) { el.innerHTML = ''; return; }

    lastParams = params;
    currentPage = params.page;

    if (currentAbort) { try { currentAbort.abort(); } catch (ex) { /* noop */ } }
    currentAbort = new AbortController();

    el.innerHTML = '<div class="biomie-drawer__empty" style="padding:16px">Buscando...</div>';

    var qs = new URLSearchParams();
    Object.keys(params).forEach(function (k) { if (params[k] !== '' && params[k] != null) qs.set(k, String(params[k])); });

    fetch('api/orders_search.php?' + qs.toString(), { credentials: 'same-origin', signal: currentAbort.signal })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        currentAbort = null;
        if (!json.ok) { el.innerHTML = '<div class="biomie-drawer__empty">Erro na busca.</div>'; return; }
        renderResults(el, json);
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        el.innerHTML = '<div class="biomie-drawer__empty">Falha na busca. Tente novamente.</div>';
      });
  }

  function renderResults(el, json) {
    var rows  = json.results || [];
    var pag   = json.pagination || {};

    if (!rows.length) {
      el.innerHTML = '<div class="biomie-drawer__empty" style="padding:16px">Nenhum resultado encontrado.</div>';
      return;
    }

    var html = '<div style="overflow-x:auto;margin-top:12px"><table style="width:100%;border-collapse:collapse;font-size:.83rem">' +
      '<thead><tr style="background:var(--bg-muted)">' +
        th('Pedido/NF') + th('Cliente') + th('Vendedor') + th('Status') + th('Emissão') + th('Total') +
      '</tr></thead><tbody>';

    rows.forEach(function (row) {
      var nf    = esc(row.numero_nf || '');
      var ped   = esc(row.codigo_pedido || '');

      html += '<tr>' +
        '<td style="padding:6px 8px;border-bottom:1px solid var(--border)">' +
          (nf ? '<button type="button" class="row-inline-link" data-biomie-action="open-invoice" data-numero-nf="' + nf + '" data-nota-key="" style="margin-right:4px">NF ' + nf + '</button>' : '') +
          (ped ? '<button type="button" class="row-inline-link" style="font-size:.75rem;color:var(--text-3)" data-biomie-action="open-order" data-pedido-codigo="' + ped + '">' + ped + '</button>' : '') +
          (!nf && !ped ? '<span style="color:var(--text-3)">—</span>' : '') +
        '</td>' +
        tdStr(row.cliente) + tdStr(row.vendedor) + tdStr(row.status) + tdStr(row.data_emissao) +
        '<td style="padding:6px 8px;border-bottom:1px solid var(--border);font-weight:600">' + esc(fmt(row.valor_total)) + '</td>' +
      '</tr>';
    });

    html += '</tbody></table></div>';
    html += renderPagination(pag);
    el.innerHTML = html;

    el.querySelectorAll('[data-page-nav]').forEach(function (btn) {
      btn.addEventListener('click', function () { search(parseInt(btn.dataset.pageNav, 10) || 1); });
    });
  }

  function renderPagination(pag) {
    if ((pag.total_pages || 1) <= 1) return '';
    return '<div style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:.82rem">' +
      (pag.has_prev ? '<button type="button" class="orders-search-bar__btn orders-search-bar__btn--ghost" data-page-nav="' + (pag.page - 1) + '">← Anterior</button>' : '') +
      '<span style="color:var(--text-3)">Página ' + (pag.page) + ' de ' + (pag.total_pages) + ' (' + (pag.total) + ' resultados)</span>' +
      (pag.has_next ? '<button type="button" class="orders-search-bar__btn orders-search-bar__btn--ghost" data-page-nav="' + (pag.page + 1) + '">Próxima →</button>' : '') +
    '</div>';
  }

  function th(label) { return '<th style="text-align:left;padding:6px 8px;font-weight:500;color:var(--text-3)">' + esc(label) + '</th>'; }
  function tdStr(val) { return '<td style="padding:6px 8px;border-bottom:1px solid var(--border)">' + esc(String(val || '—')) + '</td>'; }

  // ── Wire events ───────────────────────────────────────────────────────────

  function wireEvents() {
    var q      = document.getElementById('osmQ');
    var submit = document.getElementById('osmSubmit');
    var clear  = document.getElementById('osmClear');

    if (q) {
      q.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { search(1); }, 300);
      });
      q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { clearTimeout(debounceTimer); search(1); } });
    }

    if (submit) { submit.addEventListener('click', function () { clearTimeout(debounceTimer); search(1); }); }

    if (clear) {
      clear.addEventListener('click', function () {
        ['osmQ', 'osmVendor', 'osmStatus', 'osmMin', 'osmMax'].forEach(function (id) {
          var el = document.getElementById(id); if (el) el.value = '';
        });
        var res = document.getElementById(RESULTS_ID);
        if (res) res.innerHTML = '';
        if (currentAbort) { try { currentAbort.abort(); } catch (ex) { /* noop */ } currentAbort = null; }
      });
    }
  }

  // ── Mount ─────────────────────────────────────────────────────────────────

  function mount() {
    if (document.getElementById(SEARCH_BAR_ID)) return;
    var anchor = document.getElementById('tabelas');
    if (!anchor) return;
    var bar = buildSearchBar();
    anchor.parentNode.insertBefore(bar, anchor.nextSibling);
    wireEvents();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
}());
