/**
 * BIomie — Vendors page drilldown + Fase Q.3 filter motor.
 *
 * Responsibilities:
 * 1. Override openVendorDrawer so table rows open Vendor 360 (not flight-plan drawer).
 * 2. Handle ?vendor_id deep-link on page load.
 * 3. Wire B.ServerTable to api/vendors_search.php for filtered/sorted queries.
 * 4. Render simplified filtered rows into #vendorsTable; restore on clear.
 */
(function () {
  'use strict';

  if (window.BIomieVendorsPageDrilldown) return;
  window.BIomieVendorsPageDrilldown = true;

  var B = window.BIomie || {};

  // ── Helpers ──────────────────────────────────────────────────────────────

  function isVendorsPage() {
    var page = document.body ? document.body.getAttribute('data-page') : '';
    return ['vendors', 'time-comercial'].includes(page);
  }

  function getRange() {
    if (B.getDateRange) return B.getDateRange();
    var s = document.getElementById('dateStart');
    var e = document.getElementById('dateEnd');
    return { start: (s && s.value) || '', end: (e && e.value) || '' };
  }

  function openVendor360(ref) {
    if (!ref || !window.BIomieVendorDetail) return;
    var range = getRange();
    BIomieVendorDetail.open(ref, { start: range.start, end: range.end });
  }

  // ── Vendor 360 override ──────────────────────────────────────────────────

  function installOverride() {
    if (!isVendorsPage()) return;
    var _original = window.openVendorDrawer;
    window.openVendorDrawer = function (vId) {
      if (window.BIomieVendorDetail) {
        openVendor360(vId);
      } else if (typeof _original === 'function') {
        _original(vId);
      }
    };
  }

  function handleDeepLink() {
    if (!isVendorsPage()) return;
    var params = new URLSearchParams(window.location.search);
    var vid = (params.get('vendor_id') || params.get('vendor_nome') || '').trim();
    if (!vid) return;
    window.setTimeout(function () { openVendor360(vid); }, 200);
  }

  // ── Filter state helpers ─────────────────────────────────────────────────

  function buildStateFromDOM(page) {
    var range  = getRange();
    var qEl    = document.getElementById('f_vendor_q');
    var bcMin  = document.getElementById('f_vendor_bc_min');
    var bcMax  = document.getElementById('f_vendor_bc_max');
    var tkMin  = document.getElementById('f_vendor_tk_min');
    var tkMax  = document.getElementById('f_vendor_tk_max');

    var state  = B.FS.make({
      start:     range.start,
      end:       range.end,
      sort:      currentSort.field,
      direction: currentSort.dir,
      page:      page || 1,
      per_page:  25,
    });

    if (qEl && qEl.value.trim()) {
      state = B.FS.setQ(state, qEl.value.trim());
    }
    if (bcMin && bcMin.value.trim()) {
      state = B.FS.setFilter(state, 'min_base_comercial', 'gte', bcMin.value.trim());
    }
    if (bcMax && bcMax.value.trim()) {
      state = B.FS.setFilter(state, 'max_base_comercial', 'lte', bcMax.value.trim());
    }
    if (tkMin && tkMin.value.trim()) {
      state = B.FS.setFilter(state, 'min_ticket', 'gte', tkMin.value.trim());
    }
    if (tkMax && tkMax.value.trim()) {
      state = B.FS.setFilter(state, 'max_ticket', 'lte', tkMax.value.trim());
    }

    return state;
  }

  var CHIP_LABELS = {
    q:                  function (v) { return 'Vendedor: ' + v; },
    min_base_comercial: function (v) { return 'Base ≥ ' + B.formatCurrency(Number(v)); },
    max_base_comercial: function (v) { return 'Base ≤ ' + B.formatCurrency(Number(v)); },
    min_ticket:         function (v) { return 'Ticket ≥ ' + B.formatCurrency(Number(v)); },
    max_ticket:         function (v) { return 'Ticket ≤ ' + B.formatCurrency(Number(v)); },
  };

  function chipsForState(state) {
    var chips = [];
    if (state.q) {
      chips.push({ key: 'q', label: CHIP_LABELS.q(state.q) });
    }
    var fmap = { min_base_comercial: 'val', max_base_comercial: 'val', min_ticket: 'val', max_ticket: 'val' };
    for (var field in fmap) {
      if (state.filters && state.filters[field] != null) {
        var val = state.filters[field].val != null ? state.filters[field].val : state.filters[field];
        if (CHIP_LABELS[field]) chips.push({ key: field, label: CHIP_LABELS[field](val) });
      }
    }
    return chips;
  }

  // ── Sort state ───────────────────────────────────────────────────────────

  var currentSort = { field: 'base_comercial', dir: 'desc' };

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

    document.querySelectorAll('.nf-quick-filter-btn[data-vendor-sort]').forEach(function (btn) {
      var field = btn.getAttribute('data-vendor-sort');
      var dir   = btn.getAttribute('data-vendor-dir') || 'desc';
      if (field === currentSort.field && dir === currentSort.dir) {
        btn.classList.add('nf-quick-filter-btn--active');
      } else {
        btn.classList.remove('nf-quick-filter-btn--active');
      }
    });
  }

  function updateColumnHeaderBadges(state) {
    var fields = {
      'th-vendor-vendedor': state.q,
      'th-vendor-bc':       (state.filters && (state.filters.min_base_comercial || state.filters.max_base_comercial)) ? true : false,
      'th-vendor-ticket':   (state.filters && state.filters.min_ticket) ? true : false,
    };
    for (var id in fields) {
      var el = document.getElementById(id);
      if (!el) continue;
      if (fields[id]) {
        el.classList.add('nf-th-filter-active');
      } else {
        el.classList.remove('nf-th-filter-active');
      }
    }
  }

  // ── Render helpers ───────────────────────────────────────────────────────

  function renderVendorRows(rows) {
    var tbody = document.getElementById('vendorsTable');
    if (!tbody) return;
    if (!rows || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:24px;color:var(--text-3);">'
        + 'Nenhum vendedor encontrado para os filtros aplicados.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function (row) {
      var vRef      = String(row.vendor_ref || row.vendor_id || '');
      var clickAttr = vRef
        ? 'onclick="window.openVendorDrawer(\'' + B.escapeHtml(vRef) + '\')" style="cursor:pointer"'
        : '';
      return '<tr ' + clickAttr + '>'
        + '<td><strong style="color:var(--page-accent);text-decoration:' + (vRef ? 'underline' : 'none') + '">'
        + B.escapeHtml(row.vendor_name) + '</strong>'
        + '<div class="vendor-micro">'
        + '<span class="vm-billed">' + row.invoices_count + ' nf</span>'
        + '<span class="vm-auth">' + row.customers_count + ' cliente(s)</span>'
        + '</div></td>'
        + '<td><strong>' + B.formatCurrency(row.base_comercial) + '</strong></td>'
        + '<td style="color:var(--text-3)">—</td>'
        + '<td style="color:var(--text-3)">—</td>'
        + '<td style="color:var(--text-3)">—</td>'
        + '<td style="color:var(--text-3)">—</td>'
        + '<td style="color:var(--text-3)">—</td>'
        + '<td style="color:var(--text-3)">—</td>'
        + '<td>' + B.formatCurrency(row.avg_ticket) + '</td>'
        + '<td style="color:var(--text-3)">—</td>'
        + '</tr>';
    }).join('');
  }

  function renderPagination(pag, table) {
    var el = document.getElementById('vendorPagination');
    if (!el) return;
    if (!pag || pag.total_pages <= 1) {
      el.style.display = 'none';
      return;
    }
    el.style.display = 'flex';
    var html = '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" id="vendorPagPrev"'
      + (pag.has_prev ? '' : ' disabled') + '>← Anterior</button>'
      + '<span style="font-size:0.82rem;color:var(--text-2);">'
      + 'Página ' + pag.page + ' de ' + pag.total_pages + ' (' + pag.total + ' vendedor' + (pag.total !== 1 ? 'es' : '') + ')'
      + '</span>'
      + '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" id="vendorPagNext"'
      + (pag.has_next ? '' : ' disabled') + '>Próxima →</button>';
    el.innerHTML = html;

    var prevBtn = document.getElementById('vendorPagPrev');
    var nextBtn = document.getElementById('vendorPagNext');
    if (prevBtn) prevBtn.addEventListener('click', function () { table.trigger(buildStateFromDOM(pag.page - 1), pag.page - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { table.trigger(buildStateFromDOM(pag.page + 1), pag.page + 1); });
  }

  function showFilteredNote(pag) {
    var el = document.getElementById('vendorFilterFeedback');
    if (!el) return;
    var msg = '';
    if (pag) {
      msg = '<span style="color:var(--text-2);">' + pag.total + ' vendedor' + (pag.total !== 1 ? 'es' : '')
        + ' encontrado' + (pag.total !== 1 ? 's' : '') + ' &mdash; '
        + '<span style="color:var(--warning,#c58c00)">meta, risco e carteira disponíveis apenas na visão completa</span>'
        + '</span>';
    }
    el.innerHTML = msg;
    el.style.display = msg ? '' : 'none';
  }

  // ── DOM sync helpers ─────────────────────────────────────────────────────

  var FILTER_INPUT_MAP = {
    min_base_comercial: 'f_vendor_bc_min',
    max_base_comercial: 'f_vendor_bc_max',
    min_ticket:         'f_vendor_tk_min',
    max_ticket:         'f_vendor_tk_max',
  };

  function syncDomFromState(state) {
    var qEl = document.getElementById('f_vendor_q');
    if (qEl) qEl.value = state.q || '';
    for (var field in FILTER_INPUT_MAP) {
      var el = document.getElementById(FILTER_INPUT_MAP[field]);
      if (!el) continue;
      var fv = state.filters && state.filters[field];
      el.value = (fv && fv.val != null) ? fv.val : '';
    }
  }

  function clearAllInputs() {
    var qEl = document.getElementById('f_vendor_q');
    if (qEl) qEl.value = '';
    for (var field in FILTER_INPUT_MAP) {
      var el = document.getElementById(FILTER_INPUT_MAP[field]);
      if (el) el.value = '';
    }
  }

  // ── Mount ─────────────────────────────────────────────────────────────────

  function mount() {
    if (!isVendorsPage()) return;

    installOverride();
    handleDeepLink();

    if (!B.ServerTable || !B.FS || !B.FUrl || !B.FChips) return;

    var table = B.ServerTable.create({
      endpoint:         'api/vendors_search.php',
      feedbackId:       'vendorSearchFeedback',
      chipsContainerId: 'vendorFilterChips',
      chipsForState:    chipsForState,
      debounceMs:       350,

      onSuccess: function (resp, state) {
        var data = resp.data || [];
        var pag  = resp.pagination || null;
        renderVendorRows(data);
        renderPagination(pag, table);
        showFilteredNote(pag);
        updateColumnHeaderBadges(state);
        updateSortUI();
        // Sync DOM inputs from actual state (handles chip removal de-sync)
        syncDomFromState(state);
      },

      onClear: function () {
        var pagEl = document.getElementById('vendorPagination');
        if (pagEl) pagEl.style.display = 'none';
        showFilteredNote(null);
        updateColumnHeaderBadges(B.FS.make({}));
        clearAllInputs();
        currentSort = { field: 'base_comercial', dir: 'desc' };
        updateSortUI();
        // Restore original table by re-triggering the period filter
        var applyBtn = document.getElementById('applyFilters');
        if (applyBtn) applyBtn.click();
      },
    });

    // ── Text search (debounced) ────────────────────────────────────────────
    var qEl = document.getElementById('f_vendor_q');
    if (qEl) {
      qEl.addEventListener('input', function () {
        var state = buildStateFromDOM(1);
        if (B.FS.hasActive(state)) {
          table.triggerDebounced(state);
        } else {
          table.clear();
        }
      });
      qEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { table.trigger(buildStateFromDOM(1)); }
        if (e.key === 'Escape') { qEl.value = ''; table.clear(); }
      });
    }

    // ── Range / ticket filters ────────────────────────────────────────────
    ['f_vendor_bc_min', 'f_vendor_bc_max', 'f_vendor_tk_min', 'f_vendor_tk_max'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('change', function () {
        var state = buildStateFromDOM(1);
        if (B.FS.hasActive(state)) {
          table.trigger(state);
        } else {
          table.clear();
        }
      });
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          var s = buildStateFromDOM(1);
          if (B.FS.hasActive(s)) table.trigger(s); else table.clear();
        }
      });
    });

    // ── Quick sort buttons ────────────────────────────────────────────────
    document.querySelectorAll('.nf-quick-filter-btn[data-vendor-sort]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var field = btn.getAttribute('data-vendor-sort');
        var dir   = btn.getAttribute('data-vendor-dir') || 'desc';
        if (currentSort.field === field && currentSort.dir === dir) return;
        currentSort = { field: field, dir: dir };
        updateSortUI();
        // _sort_active ensures hasActive()=true so the server-side query fires
        var state = B.FS.setFilter(buildStateFromDOM(1), '_sort_active', 'eq', '1');
        table.trigger(state);
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
        var state = B.FS.setFilter(buildStateFromDOM(1), '_sort_active', 'eq', '1');
        table.trigger(state);
      });
    });

    // ── COM.R2 — Channel filter buttons ──────────────────────────────────
    document.querySelectorAll('#vendorChannelFilters [data-vendor-channel]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var channel = btn.getAttribute('data-vendor-channel') || '';
        // Update active state
        document.querySelectorAll('#vendorChannelFilters [data-vendor-channel]').forEach(function (b) {
          b.classList.remove('nf-quick-filter-btn--active');
        });
        btn.classList.add('nf-quick-filter-btn--active');
        // Show warning for "todos" / empty channel = all
        var warn = document.getElementById('vendorChannelAllWarning');
        if (warn) warn.style.display = (channel === 'todos') ? '' : 'none';
        // Store channel globally so loadVendors picks it up
        window.BIomieVendorsChannel = channel;
        // Re-trigger load
        var applyBtn = document.getElementById('applyFilters');
        if (applyBtn) applyBtn.click();
      });
    });

    // ── URL restore on load ───────────────────────────────────────────────
    var urlState = B.FUrl ? B.FUrl.read() : null;
    if (urlState && B.FS.hasActive(urlState)) {
      if (urlState.q && document.getElementById('f_vendor_q')) {
        document.getElementById('f_vendor_q').value = urlState.q;
      }
      if (urlState.filters) {
        var rMap = {
          min_base_comercial: 'f_vendor_bc_min',
          max_base_comercial: 'f_vendor_bc_max',
          min_ticket:         'f_vendor_tk_min',
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
