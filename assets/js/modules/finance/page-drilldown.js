/**
 * BIomie Finance — server-side title search (Fase Q.5)
 * Uses B.ServerTable (core/server-table.js) — same pattern as Q.2–Q.4.
 * Does NOT touch finance.js, api/finance_overview.php, or existing tables.
 */
(function () {
  'use strict';

  var B              = window.BIomie || {};
  var escapeHtml     = function (v) { return B.escapeHtml     ? B.escapeHtml(v)     : String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  var formatCurrency = function (v) { return B.formatCurrency ? B.formatCurrency(v) : 'R$ ' + Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}); };
  var formatDateBR   = function (v) {
    if (!v) return '-';
    var parts = String(v).split('-');
    if (parts.length === 3) return parts[2] + '/' + parts[1] + '/' + parts[0];
    return v;
  };

  // ── Status badge ──────────────────────────────────────────────────────────
  var STATUS_LABELS = {
    liquidado: 'Recebido', recebido: 'Recebido', pago: 'Recebido',
    aberto: 'Em aberto', vencido: 'Vencido', a_vencer: 'A vencer',
    vence_hoje: 'Vence hoje', parcial: 'Parcial', cancelado: 'Cancelado', outro: 'Outro',
  };
  var STATUS_CLASS = {
    liquidado: 'fin-badge-green', recebido: 'fin-badge-green', pago: 'fin-badge-green',
    aberto: 'fin-badge-gold', vencido: 'fin-badge-red', a_vencer: 'fin-badge-gold',
    vence_hoje: 'fin-badge-gold', parcial: 'fin-badge-gold', cancelado: 'fin-badge-muted', outro: 'fin-badge-muted',
  };
  function statusBadge(raw) {
    var n = String(raw || '').toLowerCase();
    var label = STATUS_LABELS[n] || raw;
    var cls   = STATUS_CLASS[n] || 'fin-badge-muted';
    return '<span class="fin-badge ' + cls + '">' + escapeHtml(label) + '</span>';
  }
  function agingLabel(days, bucket) {
    if (bucket === 'recebido' || days <= 0) return '';
    if (days === 0 || bucket === 'vence_hoje') return '<span class="fin-badge fin-badge-gold">Hoje</span>';
    if (bucket === 'a_vencer') return '<span class="fin-badge fin-badge-green">No prazo</span>';
    var cls = days <= 15 ? 'fin-badge-gold' : days <= 30 ? 'fin-badge-red' : 'fin-badge-red';
    return '<span class="fin-badge ' + cls + '">' + days + 'd</span>';
  }

  // ── Pagination UI ─────────────────────────────────────────────────────────
  function renderPagination(pag, onPage) {
    var el = document.getElementById('financePagination');
    if (!el) return;
    if (!pag || pag.total <= pag.per_page) { el.style.display = 'none'; return; }
    el.style.display = 'flex';
    var pages = pag.total_pages;
    var cur   = pag.page;
    var html  = '<span style="margin-right:auto;">' + pag.total + ' título(s) — pág. ' + cur + '/' + pages + '</span>';
    if (pag.has_prev) html += '<button class="fin-page-btn" data-page="' + (cur - 1) + '">‹ Anterior</button>';
    var from = Math.max(1, cur - 2);
    var to   = Math.min(pages, cur + 2);
    for (var i = from; i <= to; i++) {
      html += '<button class="fin-page-btn' + (i === cur ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
    }
    if (pag.has_next) html += '<button class="fin-page-btn" data-page="' + (cur + 1) + '">Próxima ›</button>';
    el.innerHTML = html;
    el.querySelectorAll('[data-page]').forEach(function (btn) {
      btn.addEventListener('click', function () { onPage(Number(btn.dataset.page)); });
    });
  }

  // ── Summary bar ───────────────────────────────────────────────────────────
  function renderSummaryBar(summary) {
    var bar = document.getElementById('financeSearchSummary');
    if (!bar || !summary) return;
    bar.style.display = 'flex';
    var r = document.getElementById('fss_recebido');
    var a = document.getElementById('fss_aberto');
    var v = document.getElementById('fss_vencido');
    var q = document.getElementById('fss_qtd');
    if (r) r.textContent = formatCurrency(summary.total_recebido || 0);
    if (a) a.textContent = formatCurrency(summary.total_aberto   || 0);
    if (v) v.textContent = formatCurrency(summary.total_vencido  || 0);
    if (q) q.textContent = summary.qtd_titulos || 0;
  }

  // ── Render rows ───────────────────────────────────────────────────────────
  function renderRows(data, state) {
    var tbody = document.getElementById('financeSearchTable');
    if (!tbody) return;

    if (!data || !data.length) {
      tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">Nenhum título encontrado para os filtros atuais.</td></tr>';
      // re-init card table for the empty state
      if (window.BIomieMobileShell && window.BIomieMobileShell.initCardTables) {
        window.BIomieMobileShell.initCardTables();
      }
      return;
    }

    var html = data.map(function (row) {
      var isVencido = row.status === 'vencido' || (row.aging_bucket !== 'recebido' && row.dias_atraso > 0);
      var refLink = '';
      if (row.nota_key)   refLink += '<span class="fin-tag" title="NF">NF</span> ';
      if (row.pedido_ref) refLink += '<span class="fin-tag" title="Pedido">' + escapeHtml(row.pedido_ref) + '</span>';
      return '<tr>' +
        '<td><div class="fin-doc-cell"><strong>' + escapeHtml(row.cliente) + '</strong>' +
          (row.documento_cliente ? '<small>' + escapeHtml(row.documento_cliente) + '</small>' : '') +
        '</div></td>' +
        '<td><div class="fin-doc-cell"><span class="fin-badge fin-badge-green">' + escapeHtml(row.especie) + '</span><strong>' + escapeHtml(row.documento) + '</strong>' +
          (row.forma_pagamento ? '<small>' + escapeHtml(row.forma_pagamento) + '</small>' : '') +
        '</div></td>' +
        '<td>' + (refLink || '<span class="text-muted">—</span>') + '</td>' +
        '<td>' + escapeHtml(row.vendedor || '—') + '</td>' +
        '<td style="' + (isVencido ? 'color:var(--state-cancel,#d04d4d);font-weight:600;' : '') + '">' + formatDateBR(row.vencimento) + '</td>' +
        '<td><strong>' + formatCurrency(row.valor) + '</strong></td>' +
        '<td>' + (row.valor_aberto > 0 ? '<strong style="' + (isVencido ? 'color:var(--state-cancel,#d04d4d);' : '') + '">' + formatCurrency(row.valor_aberto) + '</strong>' : '<span class="text-muted">—</span>') + '</td>' +
        '<td>' + statusBadge(row.status) + '</td>' +
        '<td data-mobile-skip>' + agingLabel(row.dias_atraso, row.aging_bucket) + '</td>' +
      '</tr>';
    }).join('');

    tbody.innerHTML = html;

    // Re-apply mobile card labels after AJAX render
    if (window.BIomieMobileShell && window.BIomieMobileShell.initCardTables) {
      window.BIomieMobileShell.initCardTables();
    }
  }

  // ── Build state from DOM ──────────────────────────────────────────────────
  function buildStateFromDOM(page) {
    var q         = (document.getElementById('f_fin_q')          || {}).value || '';
    var vendedor  = (document.getElementById('f_fin_vendedor')    || {}).value || '';
    var status    = (document.getElementById('f_fin_status')      || {}).value || '';
    var aging     = (document.getElementById('f_fin_aging')       || {}).value || '';
    var dateField = (document.getElementById('f_fin_date_field')  || {}).value || 'vencimento';
    var valMin    = (document.getElementById('f_fin_valor_min')   || {}).value || '';
    var valMax    = (document.getElementById('f_fin_valor_max')   || {}).value || '';

    var range = (B.getDateRange && B.getDateRange()) || {};
    var start = range.start || '';
    var end   = range.end   || '';

    var filters = {};
    if (vendedor) filters['vendedor'] = { op: 'contains', val: vendedor };
    if (valMin)   filters['min_valor'] = { op: 'gte', val: valMin };
    if (valMax)   filters['max_valor'] = { op: 'lte', val: valMax };

    return {
      q:          q,
      start:      start,
      end:        end,
      date_field: dateField,
      status:     status,
      aging:      aging,
      valor_min:  valMin,
      valor_max:  valMax,
      filters:    filters,
      page:       page || 1,
    };
  }

  // ── Chips for active state ────────────────────────────────────────────────
  var STATUS_CHIP_LABELS = {
    recebido: 'Recebidos', aberto: 'Em aberto', vencido: 'Vencido',
    vence_hoje: 'Vence hoje', a_vencer: 'A vencer',
  };
  var AGING_CHIP_LABELS = {
    sem_atraso: 'Sem atraso', '1_7': '1–7 dias', '8_15': '8–15 dias',
    '16_30': '16–30 dias', '31_60': '31–60 dias', '60_plus': '60+ dias',
  };
  function chipsForState(st) {
    var chips = [];
    if (st.q)          chips.push({ key: 'q',         label: 'Busca: ' + st.q });
    if (st.status)     chips.push({ key: 'status',    label: STATUS_CHIP_LABELS[st.status] || st.status });
    if (st.aging)      chips.push({ key: 'aging',     label: 'Tempo de atraso: ' + (AGING_CHIP_LABELS[st.aging] || st.aging) });
    if (st.valor_min)  chips.push({ key: 'valor_min', label: 'Valor ≥ R$ ' + st.valor_min });
    if (st.valor_max)  chips.push({ key: 'valor_max', label: 'Valor ≤ R$ ' + st.valor_max });
    if ((st.filters || {}).vendedor) chips.push({ key: 'vendedor', label: 'Vendedor: ' + st.filters.vendedor.val });
    return chips;
  }

  // ── Sync DOM from state (after chip removal) ──────────────────────────────
  function syncDomFromState(st) {
    var setVal = function (id, v) { var el = document.getElementById(id); if (el) el.value = v; };
    setVal('f_fin_q',          st.q         || '');
    setVal('f_fin_status',     st.status    || '');
    setVal('f_fin_aging',      st.aging     || '');
    setVal('f_fin_valor_min',  st.valor_min || '');
    setVal('f_fin_valor_max',  st.valor_max || '');
    setVal('f_fin_vendedor',   (st.filters && st.filters.vendedor) ? st.filters.vendedor.val : '');
  }

  // ── URL state ─────────────────────────────────────────────────────────────
  function stateToUrl(st) {
    var p = new URLSearchParams(window.location.search);
    var set = function (k, v) { if (v) p.set(k, v); else p.delete(k); };
    set('fin_q',         st.q);
    set('fin_status',    st.status);
    set('fin_aging',     st.aging);
    set('fin_df',        st.date_field !== 'vencimento' ? st.date_field : '');
    set('fin_vmin',      st.valor_min);
    set('fin_vmax',      st.valor_max);
    set('fin_vendedor',  (st.filters && st.filters.vendedor) ? st.filters.vendedor.val : '');
    set('fin_page',      st.page > 1 ? String(st.page) : '');
    var newUrl = window.location.pathname + (p.toString() ? '?' + p.toString() : '');
    window.history.replaceState(null, '', newUrl);
  }

  function urlToState() {
    var p = new URLSearchParams(window.location.search);
    var get = function (k) { return p.get(k) || ''; };
    return {
      q:          get('fin_q'),
      status:     get('fin_status'),
      aging:      get('fin_aging'),
      date_field: get('fin_df') || 'vencimento',
      valor_min:  get('fin_vmin'),
      valor_max:  get('fin_vmax'),
      vendedor:   get('fin_vendedor'),
      page:       Number(get('fin_page')) || 1,
    };
  }

  // ── Main init ─────────────────────────────────────────────────────────────
  function initFinanceServerTable() {
    if (!B.ServerTable || !B.FS || !B.FUrl || !B.FChips || !B.RC) {
      // Core modules not yet loaded — retry after a tick
      setTimeout(initFinanceServerTable, 80);
      return;
    }

    var _currentPage = 1;
    var _abortCtrl   = null;
    var _debounceTimer = null;

    function buildQueryString(domState, page, sortField, sortDir) {
      var p = new URLSearchParams();
      if (domState.start)      p.set('start', domState.start);
      if (domState.end)        p.set('end',   domState.end);
      if (domState.q)          p.set('q',     domState.q);
      if (domState.status)     p.set('status', domState.status);
      if (domState.aging)      p.set('aging',  domState.aging);
      if (domState.date_field) p.set('date_field', domState.date_field);
      if (domState.valor_min)  p.set('valor_min', domState.valor_min);
      if (domState.valor_max)  p.set('valor_max', domState.valor_max);
      if ((domState.filters || {}).vendedor) p.set('filters[vendedor]', domState.filters.vendedor.val);
      p.set('page',      String(page || 1));
      p.set('per_page',  '25');
      p.set('sort',      sortField || 'vencimento');
      p.set('direction', sortDir   || 'desc');
      return p.toString();
    }

    var _sortField = 'vencimento';
    var _sortDir   = 'desc';

    function hasActiveFilters(st) {
      return !!(st.q || st.status || st.aging || st.valor_min || st.valor_max ||
        (st.filters && st.filters.vendedor && st.filters.vendedor.val));
    }

    function setFeedback(msg, isError) {
      var el = document.getElementById('financeSearchFeedback');
      if (!el) return;
      if (!msg) { el.style.display = 'none'; el.textContent = ''; return; }
      el.style.display = 'block';
      el.textContent   = msg;
      el.className     = 'biomie-table-feedback' + (isError ? ' biomie-table-feedback--error' : ' biomie-table-feedback--info');
    }

    function setMeta(text) {
      var el = document.getElementById('financeSearchMeta');
      if (el) el.textContent = text || '';
    }

    function fetch_(page, sortField, sortDir) {
      var domState = buildStateFromDOM(page);
      domState.start = domState.start || '';
      domState.end   = domState.end   || '';

      if (!domState.start || !domState.end) {
        setFeedback('Selecione um período e clique em Aplicar para buscar títulos.', false);
        return;
      }

      if (_abortCtrl) _abortCtrl.abort();
      _abortCtrl = new AbortController();

      var qs  = buildQueryString(domState, page, sortField, sortDir);
      var url = 'api/finance_search.php?' + qs;

      setFeedback('Buscando títulos…', false);

      window.fetch(url, { signal: _abortCtrl.signal, credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(function (resp) {
          if (resp.redirected) throw new Error('Sessão expirada.');
          return resp.json();
        })
        .then(function (json) {
          if (!json.ok) throw new Error((json.error && json.error.message) || 'Erro ao carregar títulos.');

          setFeedback('');
          _currentPage = page;
          stateToUrl(Object.assign({}, domState, { page: page }));

          renderRows(json.data, domState);
          renderPagination(json.pagination, function (p) { fetch_(p, _sortField, _sortDir); });
          renderSummaryBar(json.summary);

          var total = (json.pagination && json.pagination.total) || 0;
          setMeta(total ? total + ' título(s)' : '');

          // Chips
          var chips = chipsForState(Object.assign({}, domState, { status: domState.status, aging: domState.aging }));
          var chipsEl = document.getElementById('financeFilterChips');
          if (chipsEl) {
            if (chips.length) {
              chipsEl.style.display = 'block';
              if (B.FChips && B.FChips.render) {
                B.FChips.render(chipsEl, chips, function (removedKey) {
                  var st = buildStateFromDOM(1);
                  if (removedKey === 'q')          st.q         = '';
                  if (removedKey === 'status')     st.status    = '';
                  if (removedKey === 'aging')      st.aging     = '';
                  if (removedKey === 'valor_min')  st.valor_min = '';
                  if (removedKey === 'valor_max')  st.valor_max = '';
                  if (removedKey === 'vendedor' && st.filters) { delete st.filters.vendedor; }
                  syncDomFromState(st);
                  fetch_(1, _sortField, _sortDir);
                });
              }
            } else {
              chipsEl.style.display = 'none';
              chipsEl.innerHTML     = '';
            }
          }

          // Update column sort icons
          updateSortHeaders(sortField, sortDir);
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          setFeedback((err && err.message) || 'Falha ao carregar títulos.', true);
        });
    }

    function updateSortHeaders(field, dir) {
      document.querySelectorAll('[data-sort-field]').forEach(function (th) {
        var icon = th.querySelector('.nf-sort-icon');
        if (!icon) return;
        if (th.dataset.sortField === field) {
          icon.textContent = dir === 'asc' ? '↑' : '↓';
          th.classList.add('nf-th-active-sort');
        } else {
          icon.textContent = '↕';
          th.classList.remove('nf-th-active-sort');
        }
      });
    }

    function trigger() {
      fetch_(1, _sortField, _sortDir);
    }

    function triggerDebounced() {
      clearTimeout(_debounceTimer);
      _debounceTimer = setTimeout(trigger, 350);
    }

    // ── Quick filters ─────────────────────────────────────────────────────
    document.querySelectorAll('[data-fin-status]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var status = btn.dataset.finStatus || '';
        var sort   = btn.dataset.finSort   || 'vencimento';

        document.querySelectorAll('[data-fin-status]').forEach(function (b) { b.classList.remove('nf-quick-filter-btn--active'); });
        btn.classList.add('nf-quick-filter-btn--active');

        var statusEl = document.getElementById('f_fin_status');
        if (statusEl) statusEl.value = status;

        _sortField = sort || 'vencimento';
        _sortDir   = (sort === 'atraso') ? 'desc' : 'desc';
        trigger();
      });
    });

    // ── Column header sort ────────────────────────────────────────────────
    document.querySelectorAll('[id^="th-fin-"]').forEach(function (th) {
      th.style.cursor = 'pointer';
      th.addEventListener('click', function () {
        var field = th.dataset.sortField;
        if (!field) return;
        if (_sortField === field) {
          _sortDir = _sortDir === 'asc' ? 'desc' : 'asc';
        } else {
          _sortField = field;
          _sortDir   = 'desc';
        }
        fetch_(_currentPage, _sortField, _sortDir);
      });
    });

    // ── Filter inputs ─────────────────────────────────────────────────────
    ['f_fin_q', 'f_fin_vendedor'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', triggerDebounced);
    });

    ['f_fin_status', 'f_fin_aging', 'f_fin_date_field'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('change', trigger);
    });

    ['f_fin_valor_min', 'f_fin_valor_max'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', triggerDebounced);
    });

    // ── applyFilters button (global period change) ────────────────────────
    var applyBtn = document.getElementById('applyFilters');
    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        setTimeout(trigger, 0); // let date inputs settle first
      });
    }

    // ── URL restore ───────────────────────────────────────────────────────
    var restored = urlToState();
    if (restored.q || restored.status || restored.aging || restored.valor_min || restored.valor_max || restored.vendedor) {
      var setVal = function (id, v) { var el = document.getElementById(id); if (el && v) el.value = v; };
      setVal('f_fin_q',         restored.q);
      setVal('f_fin_status',    restored.status);
      setVal('f_fin_aging',     restored.aging);
      setVal('f_fin_date_field',restored.date_field);
      setVal('f_fin_valor_min', restored.valor_min);
      setVal('f_fin_valor_max', restored.valor_max);
      setVal('f_fin_vendedor',  restored.vendedor);
      if (restored.page) _currentPage = restored.page;
    }

    // Expose for testing / external calls
    window.BIomieFinanceSearch = {
      trigger:   trigger,
      setSort:   function (f, d) { _sortField = f; _sortDir = d; fetch_(_currentPage, f, d); },
      getState:  function () { return buildStateFromDOM(_currentPage); },
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFinanceServerTable, { once: true });
  } else {
    initFinanceServerTable();
  }
})();
