(function () {
  'use strict';

  // ── State ─────────────────────────────────────────────────────────────────
  var _lastData        = null;
  var _drillFilter     = null;   // { field, value, label } | null
  var _groupBy         = {};     // tableId → groupKey | null
  var _selectedRows    = {};     // tableId → Set<number>
  var _colVisible      = {};     // tableId → { colKey: boolean }
  var _tableExpanded   = {};     // tableId → boolean
  var _loadController  = null;
  var LOADING_MIN_MS   = 420;
  var _loadingStartedAt = 0;

  function waitFor(ms) {
    return new Promise(function (resolve) { window.setTimeout(resolve, Math.max(0, ms)); });
  }

  // ── Generic utility aliases (from core/utils.js) ──────────────────────────
  var B             = window.BIomie || {};
  var escapeHtml    = function (v)    { return B.escapeHtml    ? B.escapeHtml(v)      : String(v ?? ''); };
  var setText       = function (s, v) { return B.setText       ? B.setText(s, v)      : void 0; };
  var setHtml       = function (s, v) { return B.setHtml       ? B.setHtml(s, v)      : void 0; };
  var formatCurrency = function (v)   { return B.formatCurrency ? B.formatCurrency(v) : String(v); };
  var formatNumber   = function (v)   { return B.formatNumber   ? B.formatNumber(v)   : String(v); };
  var toIsoLocal     = function (d)   { return B.toLocalIso     ? B.toLocalIso(d)     : d.toISOString().slice(0, 10); };
  var todayIso       = function ()    { return B.todayIso       ? B.todayIso()        : toIsoLocal(new Date()); };
  var formatDateBR   = function (v)   { return B.formatMetaDate ? B.formatMetaDate(v) : String(v || '-'); };
  var setDateRange   = function (s, e){ return B.setDateRange   ? B.setDateRange(s, e): void 0; };
  var getDateRange   = function ()    { return B.getDateRange   ? B.getDateRange()    : { start: '', end: '' }; };
  var applyPresetRange = function (p) { return B.applyPresetRange ? B.applyPresetRange(p) : void 0; };
  var formatMetaDate     = function (v) { return B.formatMetaDate     ? B.formatMetaDate(v)     : String(v || '-'); };
  var normalizeServedFrom = function (v){ return B.normalizeServedFrom ? B.normalizeServedFrom(v): String(v || ''); };
  var humanizeServedFrom  = function (v){ return B.humanizeServedFrom  ? B.humanizeServedFrom(v) : String(v || ''); };
  var resolveCoverageLabel = function (d){ return B.resolveCoverageLabel ? B.resolveCoverageLabel(d): '-'; };
  var renderSyncStateBadges = function (d){ return B.renderSyncStateBadges ? B.renderSyncStateBadges(d): ''; };

  // ── Finance-specific formatters ───────────────────────────────────────────
  function getCssVar(name) {
    return getComputedStyle(document.body || document.documentElement).getPropertyValue(name).trim();
  }
  function chartTheme() {
    if (window.BIOMIE_CHART_THEME && typeof window.BIOMIE_CHART_THEME.getChartTheme === 'function') {
      return window.BIOMIE_CHART_THEME.getChartTheme();
    }
    return {
      text:         getCssVar('--text-2')               || '#4b5263',
      title:        getCssVar('--text-1')               || '#111318',
      subtle:       getCssVar('--text-3')               || '#9099ac',
      surface:      getCssVar('--bg-card')              || '#ffffff',
      grid:         getCssVar('--chart-grid')           || 'rgba(0,0,0,.08)',
      tooltipBg:    getCssVar('--chart-tooltip-bg')     || '#ffffff',
      tooltipBorder:getCssVar('--chart-tooltip-border') || 'rgba(0,0,0,.12)',
      tooltipTitle: getCssVar('--chart-tooltip-title')  || '#111318',
      tooltipText:  getCssVar('--chart-tooltip-text')   || '#4b5263'
    };
  }
  var chartPalette = {
    emerald: getCssVar('--state-billed')   || '#0f9f6e',
    gold:    getCssVar('--state-auth')     || '#c99a2e',
    red:     getCssVar('--state-cancel')   || '#d04d4d',
    navy:    getCssVar('--state-info')     || '#28597a'
  };
  function formatPercent(value)  { return Number(value || 0).toFixed(1) + '%'; }
  function formatCurrencyShort(value) {
    var number = Number(value || 0);
    if (Math.abs(number) >= 1000000) return 'R$ ' + (number / 1000000).toFixed(1).replace('.', ',') + ' mi';
    if (Math.abs(number) >= 1000)    return 'R$ ' + (number / 1000).toFixed(0).replace('.', ',') + ' mil';
    return formatCurrency(number);
  }
  function buildApiUrl(path, force) {
    var params = new URLSearchParams();
    var range  = getDateRange();
    if (range.start) params.set('start', range.start);
    if (range.end)   params.set('end',   range.end);
    if (force)       params.set('force', '1');
    return path + (params.toString() ? '?' + params.toString() : '');
  }
  async function fetchJson(url, options) {
    if (typeof window.fetchJson === 'function') return window.fetchJson(url, options);
    const response    = await fetch(url, Object.assign({ headers: { Accept: 'application/json' } }, options || {}));
    const contentType = response.headers.get('content-type') || '';
    const raw         = await response.text();
    let payload = null;
    if (contentType.includes('application/json')) {
      try { payload = JSON.parse(raw); } catch { throw new Error('O servidor respondeu com JSON invalido para o financeiro.'); }
    }
    if (response.redirected) throw new Error('Sessao expirada. Recarregue a pagina e faca login novamente.');
    if (!response.ok)        throw new Error((payload && payload.message) || raw.slice(0, 180) || 'Nao foi possivel carregar os dados financeiros.');
    if (!payload || payload.ok === false) throw new Error((payload && payload.message) || 'Nao foi possivel carregar os dados financeiros.');
    return payload;
  }
  function safeArray(value) { return Array.isArray(value) ? value : []; }
  function topRows(rows, limit) { return safeArray(rows).slice(0, limit || (rows ? rows.length : 0)); }

  // ── Finance-specific status badges ────────────────────────────────────────
  function renderStatusBadge(mode) {
    var n = String(mode || '').toLowerCase();
    if (n.includes('live'))     return '<span class="fin-chip fin-chip-success">Online</span>';
    if (n.includes('fallback')) return '<span class="fin-chip fin-chip-warning">Cache anterior</span>';
    return '<span class="fin-chip fin-chip-muted">Sem dados</span>';
  }
  function renderModePill(mode) {
    var n = String(mode || '').toLowerCase();
    if (n.includes('live'))     return 'Sincronizacao ativa';
    if (n.includes('fallback')) return 'Operando com cache';
    return 'Aguardando dados';
  }

  // ── Table cell helpers ────────────────────────────────────────────────────
  function trendArrow(current, previous) {
    if (previous == null || Number(previous) === 0) return '';
    var pct = ((Number(current) - Number(previous)) / Math.abs(Number(previous))) * 100;
    if (Math.abs(pct) < 0.5) return '';
    var up = pct > 0;
    return '<span class="fin-trend-arrow fin-trend-' + (up ? 'up' : 'down') + '">' + (up ? '+' : '-') + ' ' + Math.abs(pct).toFixed(1) + '%</span>';
  }
  function statusBadge(daysLate) {
    var days = Number(daysLate || 0);
    if (days <= 0)  return '<span class="fin-badge fin-badge-green">No prazo</span>';
    if (days <= 15) return '<span class="fin-badge fin-badge-gold">' + days + 'd atraso</span>';
    return '<span class="fin-badge fin-badge-red">' + days + 'd atraso</span>';
  }

  // ── Feature 1: Drill-down ─────────────────────────────────────────────────
  function setDrillFilter(field, value, label) {
    _drillFilter  = { field: field, value: value, label: label };
    _selectedRows = {};
    renderDrillChip();
    if (_lastData) renderTables(_lastData);
  }
  function clearDrillFilter() {
    _drillFilter  = null;
    _selectedRows = {};
    renderDrillChip();
    if (_lastData) renderTables(_lastData);
  }
  function renderDrillChip() {
    var container = document.getElementById('finDrillBar');
    if (!container) return;
    if (!_drillFilter) {
      container.innerHTML = '';
      container.classList.add('d-none');
      return;
    }
    container.classList.remove('d-none');
    container.innerHTML =
      '<div class="fin-drill-bar">' +
        '<span class="fin-drill-label">' +
          '<span class="material-symbols-rounded" style="font-size:16px">filter_alt</span>' +
          'Filtro ativo: <strong>' + escapeHtml(_drillFilter.label) + '</strong>' +
        '</span>' +
        '<button class="fin-drill-clear" id="finDrillClear">Limpar <span class="material-symbols-rounded" style="font-size:14px;vertical-align:-2px">close</span></button>' +
      '</div>';
    var clearBtn = document.getElementById('finDrillClear');
    if (clearBtn) clearBtn.addEventListener('click', clearDrillFilter);
  }
  function applyDrillRows(rows) {
    if (!_drillFilter) return rows;
    var field = _drillFilter.field, value = _drillFilter.value;
    return rows.filter(function (row) { return String(row[field] || '').toLowerCase() === String(value).toLowerCase(); });
  }

  // ── Feature 5: Column visibility ─────────────────────────────────────────
  function loadColVisible(tableId, cols) {
    if (_colVisible[tableId]) return;
    try {
      var stored = localStorage.getItem('fin-cols-' + tableId);
      _colVisible[tableId] = stored ? JSON.parse(stored) : {};
    } catch (e) { _colVisible[tableId] = {}; }
    cols.forEach(function (col) {
      if (col.key && _colVisible[tableId][col.key] === undefined) _colVisible[tableId][col.key] = true;
    });
  }
  function saveColVisible(tableId) {
    try { localStorage.setItem('fin-cols-' + tableId, JSON.stringify(_colVisible[tableId])); } catch (e) {}
  }
  function isColVisible(tableId, colKey) {
    return _colVisible[tableId] && _colVisible[tableId][colKey] !== false;
  }
  function renderColPicker(tableId, cols) {
    var wrap = document.querySelector('[data-col-picker="' + tableId + '"]');
    if (!wrap) return;
    wrap.innerHTML =
      '<button class="fin-col-picker-btn" data-col-picker-toggle="' + tableId + '">' +
        '<span class="material-symbols-rounded" style="font-size:15px">tune</span> Colunas' +
      '</button>' +
      '<div class="fin-col-picker" id="finColPicker-' + tableId + '">' +
        cols.filter(function (c) { return c.key && c.label; }).map(function (col) {
          return '<label><input type="checkbox" data-col-toggle="' + tableId + ':' + col.key + '" ' + (isColVisible(tableId, col.key) ? 'checked' : '') + '> ' + escapeHtml(col.label) + '</label>';
        }).join('') +
      '</div>';
    var toggleBtn = wrap.querySelector('[data-col-picker-toggle]');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var picker = document.getElementById('finColPicker-' + tableId);
        if (picker) picker.classList.toggle('open');
      });
    }
    wrap.querySelectorAll('[data-col-toggle]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var parts = cb.getAttribute('data-col-toggle').split(':');
        _colVisible[parts[0]][parts[1]] = cb.checked;
        saveColVisible(parts[0]);
        if (_lastData) renderTables(_lastData);
      });
    });
  }

  // ── Feature 4: Bulk actions ───────────────────────────────────────────────
  function getSelection(tableId)  { return _selectedRows[tableId] || new Set(); }
  function toggleRow(tableId, index, checked) {
    if (!_selectedRows[tableId]) _selectedRows[tableId] = new Set();
    if (checked) _selectedRows[tableId].add(index);
    else         _selectedRows[tableId].delete(index);
    updateBulkBar();
  }
  function clearAllSelections() {
    _selectedRows = {};
    document.querySelectorAll('.fin-row-check').forEach(function (cb) { cb.checked = false; });
    updateBulkBar();
  }
  function updateBulkBar() {
    var bar = document.getElementById('finBulkBar');
    if (!bar) return;
    var total = 0;
    Object.values(_selectedRows).forEach(function (sel) { total += sel.size; });
    if (total === 0) { bar.classList.add('d-none'); return; }
    bar.classList.remove('d-none');
    var countEl = document.getElementById('finBulkCount');
    if (countEl) countEl.textContent = total + ' linha' + (total > 1 ? 's' : '') + ' selecionada' + (total > 1 ? 's' : '');
  }
  function exportSelectedCsv() {
    if (!_lastData) return;
    var sourceMap = { financeReceivedTable: 'received_rows', financeOpenTable: 'open_titles', financeClientsTable: 'by_client', financeBudgetTable: 'budget' };
    var allRows = [], headers = null;
    Object.entries(_selectedRows).forEach(function (entry) {
      var tableId = entry[0], sel = entry[1];
      if (!sel.size) return;
      var key    = sourceMap[tableId];
      if (!key) return;
      var source = applyDrillRows(_lastData[key] || []);
      sel.forEach(function (idx) { if (source[idx]) allRows.push(source[idx]); });
      if (!headers && source[0]) headers = Object.keys(source[0]);
    });
    if (!allRows.length) return;
    if (!headers) headers = Object.keys(allRows[0]);
    var esc = function (v) { return '"' + String(v ?? '').replace(/"/g, '""') + '"'; };
    var csv = [headers.join(',')].concat(allRows.map(function (r) { return headers.map(function (h) { return esc(r[h]); }).join(','); })).join('\n');
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url; a.download = 'financeiro-export.csv'; a.click();
    URL.revokeObjectURL(url);
  }

  // ── Feature 3: Grouped rows ───────────────────────────────────────────────
  function renderGroupedRows(target, visibleCols, rows, groupKey, tableId) {
    var groups = new Map();
    rows.forEach(function (row, index) {
      var key     = String(row[groupKey] || 'Nao identificado');
      if (!groups.has(key)) groups.set(key, { rows: [], total: 0, indices: [] });
      var g       = groups.get(key);
      var amount  = Number(row.amount_net || row.amount_open || row.amount || row.overdue || row.realized || 0);
      g.rows.push(row);
      g.total += amount;
      g.indices.push(index);
    });
    if (!target._collapsed) target._collapsed = new Set();
    var _collapsed = target._collapsed;
    var sel        = getSelection(tableId);
    var html       = [];
    groups.forEach(function (group, key) {
      var collapsed = _collapsed.has(key);
      html.push('<tr class="fin-group-row" data-group-key="' + escapeHtml(key) + '"><td colspan="' + (visibleCols.length + 1) + '"><span class="fin-group-toggle"><span class="material-symbols-rounded" style="font-size:16px">' + (collapsed ? 'expand_more' : 'expand_less') + '</span><strong>' + escapeHtml(key) + '</strong><span class="fin-badge fin-badge-green">' + group.rows.length + '</span>' + (group.total > 0 ? '<span style="margin-left:auto;font-weight:800;color:var(--fin-strong)">' + formatCurrency(group.total) + '</span>' : '') + '</span></td></tr>');
      if (!collapsed) {
        group.rows.forEach(function (row, i) {
          html.push('<tr class="fin-group-child' + (sel.has(group.indices[i]) ? ' table-active' : '') + '"><td class="fin-select-cell"><input type="checkbox" class="fin-row-check" data-table="' + escapeHtml(tableId) + '" data-index="' + group.indices[i] + '" ' + (sel.has(group.indices[i]) ? 'checked' : '') + '></td>' + visibleCols.map(function (col) { return '<td>' + col.render(row) + '</td>'; }).join('') + '</tr>');
        });
      }
    });
    target.innerHTML = html.join('');
    target.querySelectorAll('.fin-group-row').forEach(function (row) {
      row.addEventListener('click', function () {
        var k = row.dataset.groupKey;
        if (_collapsed.has(k)) _collapsed.delete(k); else _collapsed.add(k);
        renderGroupedRows(target, visibleCols, rows, groupKey, tableId);
      });
    });
    target.querySelectorAll('.fin-row-check').forEach(function (cb) {
      cb.addEventListener('change', function () { toggleRow(cb.dataset.table, Number(cb.dataset.index), cb.checked); });
    });
  }

  // ── Table rendering ───────────────────────────────────────────────────────
  function tablePreviewLimit(tableId) {
    if (tableId === 'financeClientsTable') return 8;
    if (tableId === 'financeBudgetTable')  return 6;
    return 10;
  }
  function renderTableMeta(tableId, totalRows, shownRows) {
    var target = document.querySelector('[data-table-meta-for="' + tableId + '"]');
    if (!target) return;
    var limit     = tablePreviewLimit(tableId);
    var expandable = totalRows > limit && !_groupBy[tableId];
    target.innerHTML = '<span>' + formatNumber(shownRows) + ' de ' + formatNumber(totalRows) + ' linha(s)</span>' + (expandable ? '<button type="button" class="fin-table-toggle" data-table-toggle="' + tableId + '">' + (_tableExpanded[tableId] ? 'Mostrar menos' : 'Ver todas') + '</button>' : '');
    var toggleBtn = target.querySelector('[data-table-toggle]');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        _tableExpanded[tableId] = !_tableExpanded[tableId];
        if (_lastData) renderTables(_lastData);
      });
    }
  }
  function renderTable(targetId, columns, rows, emptyMessage) {
    var target = document.getElementById(targetId);
    if (!target) return;
    loadColVisible(targetId, columns);
    var visibleCols = columns.filter(function (col) { return !col.key || isColVisible(targetId, col.key); });
    var sel         = getSelection(targetId);
    var allRows     = safeArray(rows);
    if (!allRows.length) {
      renderTableMeta(targetId, 0, 0);
      target.innerHTML = '<tr><td colspan="' + (visibleCols.length + 1) + '" class="text-center py-4 text-muted">' + escapeHtml(emptyMessage || 'Nenhum dado disponivel.') + '</td></tr>';
      return;
    }
    var groupKey = _groupBy[targetId];
    if (groupKey) {
      renderTableMeta(targetId, allRows.length, allRows.length);
      renderGroupedRows(target, visibleCols, allRows, groupKey, targetId);
      return;
    }
    var limit       = tablePreviewLimit(targetId);
    var displayRows = _tableExpanded[targetId] ? allRows : allRows.slice(0, limit);
    renderTableMeta(targetId, allRows.length, displayRows.length);
    target.innerHTML = displayRows.map(function (row, index) {
      return '<tr class="' + (sel.has(index) ? 'table-active' : '') + '"><td class="fin-select-cell"><input type="checkbox" class="fin-row-check" data-table="' + escapeHtml(targetId) + '" data-index="' + index + '" ' + (sel.has(index) ? 'checked' : '') + '></td>' + visibleCols.map(function (col) { return '<td>' + col.render(row) + '</td>'; }).join('') + '</tr>';
    }).join('');
    target.querySelectorAll('.fin-row-check').forEach(function (cb) {
      cb.addEventListener('change', function () { toggleRow(cb.dataset.table, Number(cb.dataset.index), cb.checked); });
    });
  }

  // ── Bridge exposure (for charts.js) ──────────────────────────────────────
  window.BIomieFinance             = window.BIomieFinance || {};
  window.BIomieFinance.setDrillFilter = setDrillFilter;

  // ── Column definitions ────────────────────────────────────────────────────
  function getReceivedColumns() {
    return [
      { key: 'document',   label: 'Documento',    render: function (r) { return '<div class="fin-doc-cell"><span class="fin-badge fin-badge-green">' + escapeHtml(r.document_type || 'Titulo') + '</span><strong>' + escapeHtml(r.document_label || 'Sem documento') + '</strong><small>' + escapeHtml(r.status || 'Baixado') + '</small></div>'; } },
      { key: 'client',     label: 'Cliente',       render: function (r) { return '<div class="fin-doc-cell"><strong>' + escapeHtml(r.client_name || 'Cliente nao informado') + '</strong><small>Vendedor: ' + escapeHtml(r.vendor_name || 'Sem vendedor') + '</small></div>'; } },
      { key: 'account',    label: 'Conta / meio',  render: function (r) { return '<div class="fin-doc-cell"><strong>' + escapeHtml(r.account_name || 'Conta nao identificada') + '</strong><small>' + escapeHtml(r.payment_method || 'Nao identificado') + '</small></div>'; } },
      { key: 'paid_date',  label: 'Pagamento',     render: function (r) { return formatDateBR(r.paid_date); } },
      { key: 'amount_net', label: 'Valor',         render: function (r) { return '<strong>' + formatCurrency(r.amount_net) + '</strong>'; } }
    ];
  }
  function getOpenColumns() {
    return [
      { key: 'document',    label: 'Documento',         render: function (r) { return '<div class="fin-doc-cell"><span class="fin-badge fin-badge-gold">' + escapeHtml(r.document_type || 'Titulo') + '</span><strong>' + escapeHtml(r.document_label || 'Sem documento') + '</strong><small>' + escapeHtml(r.payment_method || 'Nao identificado') + '</small></div>'; } },
      { key: 'client',      label: 'Cliente',           render: function (r) { return '<div class="fin-doc-cell"><strong>' + escapeHtml(r.client_name || 'Cliente nao informado') + '</strong>' + (r.client_document ? '<small>' + escapeHtml(r.client_document) + '</small>' : '') + '</div>'; } },
      { key: 'due_date',    label: 'Vencimento',        render: function (r) { return formatDateBR(r.due_date); } },
      { key: 'vendor',      label: 'Vendedor / conta',  render: function (r) { return '<div class="fin-doc-cell"><strong>' + escapeHtml(r.vendor_name || 'Sem vendedor') + '</strong><small>' + escapeHtml(r.account_name || 'Conta nao identificada') + '</small></div>'; } },
      { key: 'amount_open', label: 'Aberto',            render: function (r) { return '<strong>' + formatCurrency(r.amount_open) + '</strong> ' + statusBadge(r.days_late); } }
    ];
  }
  function getClientsColumns() {
    return [
      { key: 'client',     label: 'Cliente / CNPJ',  render: function (r) { return '<div class="fin-doc-cell"><strong>' + escapeHtml(r.client_name || 'Cliente nao informado') + '</strong>' + (r.client_document ? '<small>' + escapeHtml(r.client_document) + '</small>' : '<small>Documento nao informado</small>') + '</div>'; } },
      { key: 'titles',     label: 'Titulos',          render: function (r) { return formatNumber(r.titles); } },
      { key: 'overdue',    label: 'Vencido',          render: function (r) { return '<strong>' + formatCurrency(r.overdue) + '</strong>'; } },
      { key: 'max_days',   label: 'Maior atraso',     render: function (r) { return statusBadge(r.max_days_late); } },
      { key: 'oldest_due', label: 'Venc. mais ant.',  render: function (r) { return formatDateBR(r.oldest_due_date); } },
      { key: 'profile',    label: 'Perfil / causa',   render: function (r) { return '<div class="fin-client-meta">' + [r.cause_label, r.dominant_document_type, r.dominant_payment_method, r.dominant_status].filter(Boolean).map(function (v) { return '<span class="fin-tag">' + escapeHtml(v) + '</span>'; }).join('') + '</div>'; } },
      { key: 'documents',  label: 'Documentos',       render: function (r) { return (r.documents || []).length ? '<div class="fin-tag-list">' + r.documents.map(function (d) { return '<span class="fin-tag">' + escapeHtml(d) + '</span>'; }).join('') + '</div>' : '<span class="text-muted">-</span>'; } }
    ];
  }
  function getBudgetColumns() {
    return [
      { key: 'month',    label: 'Mes',       render: function (r) { return r.year_month ? (r.year_month.slice(5, 7) + '/' + r.year_month.slice(0, 4)) : '-'; } },
      { key: 'planned',  label: 'Esperado',  render: function (r) { return '<strong>' + formatCurrency(r.planned) + '</strong><div class="small text-muted">Valor esperado no periodo</div>'; } },
      { key: 'realized', label: 'Recebido',  render: function (r) { return '<strong>' + formatCurrency(r.realized) + '</strong><div class="small text-muted">Pagamento confirmado</div>'; } },
      { key: 'open',     label: 'A receber', render: function (r) { return '<strong>' + formatCurrency(r.open) + '</strong><div class="small text-muted">Ainda pendente</div>'; } },
      { key: 'overdue',  label: 'Vencido',   render: function (r) { return '<strong>' + formatCurrency(r.overdue) + '</strong><div class="small text-muted">Ja vencido</div>'; } },
      { key: 'coverage', label: '% recebido', render: function (r) { return '<span class="fin-badge fin-badge-' + (Number(r.coverage_rate || 0) >= 80 ? 'green' : Number(r.coverage_rate || 0) >= 50 ? 'gold' : 'red') + '">' + formatPercent(r.coverage_rate) + '</span>'; } }
    ];
  }

  // ── renderTables ──────────────────────────────────────────────────────────
  function renderTables(data) {
    var rCols = getReceivedColumns();
    var oCols = getOpenColumns();
    var cCols = getClientsColumns();
    var bCols = getBudgetColumns();
    loadColVisible('financeReceivedTable', rCols);
    loadColVisible('financeOpenTable',     oCols);
    loadColVisible('financeClientsTable',  cCols);
    loadColVisible('financeBudgetTable',   bCols);

    var received = applyDrillRows(data.received_rows || []);
    var open     = applyDrillRows(data.open_titles   || []);
    var clients  = applyDrillRows(data.by_client     || []);
    var budget   = data.budget                        || [];

    renderTable('financeReceivedTable', rCols, received, 'Nenhum recebimento encontrado para o periodo.');
    renderTable('financeOpenTable',     oCols, open,     'Nenhum titulo em aberto no periodo.');
    renderTable('financeClientsTable',  cCols, clients,  'Nenhum cliente inadimplente encontrado no periodo.');
    renderTable('financeBudgetTable',   bCols, budget,   'Sem dados do periodo para comparar o esperado com o recebido.');

    renderColPicker('financeReceivedTable', rCols);
    renderColPicker('financeOpenTable',     oCols);
    renderColPicker('financeClientsTable',  cCols);
    renderColPicker('financeBudgetTable',   bCols);
  }

  // ── Meta / KPI rendering ──────────────────────────────────────────────────
  function updateMeta(data) {
    var meta     = data.meta || {};
    var syncTime = meta.freshness_at || meta.last_sync || meta.generated_at;
    setText('[data-bind="period-label"]',        data.period && data.period.label ? data.period.label : '-');
    setText('[data-bind="last-sync"]',            syncTime ? new Date(syncTime).toLocaleString('pt-BR') : '-');
    setHtml('[data-bind="summary-mode"]',         renderStatusBadge(data.mode || ''));
    setText('[data-bind="mode-pill"]',            renderModePill(data.mode || ''));
    var periodBasis = meta.period_basis === 'issue_date'
      ? 'Base fiscal: emissao'
      : (meta.period_basis === 'billing_date' ? 'Base comercial: faturamento' : 'Base financeira: liquidacao');
    setText('[data-bind="summary-period-basis"]', periodBasis);
    setText('[data-bind="sync-source"]',          humanizeServedFrom(meta.served_from || data.source || ''));
    setText('[data-bind="sync-coverage"]',        resolveCoverageLabel(data));
    setText('[data-bind="sync-reconciled-until"]',formatMetaDate(meta.reconciled_until || data.reconciled_until || ''));
    setHtml('[data-bind="sync-warning"]',         renderSyncStateBadges(data) || '<span class="mag-status-chip mag-status-live">Leitura principal</span>');
  }
  function renderWarnings(warnings, notes, localDataNotice) {
    var target = document.getElementById('financeWarnings');
    if (!target) return;
    var html = '';
    if (localDataNotice) {
      html += '<div class="alert alert-info mb-2 fin-alert" style="display:flex;align-items:center;gap:8px;">'
            + '<span class="material-symbols-rounded" style="font-size:16px;flex-shrink:0">cloud_off</span>'
            + escapeHtml(String(localDataNotice))
            + '</div>';
    }
    var items = [];
    (warnings || []).forEach(function (w) { items.push('<li>' + escapeHtml(w) + '</li>'); });
    (notes    || []).forEach(function (n) { items.push('<li>' + escapeHtml(n) + '</li>'); });
    if (items.length) {
      html += '<div class="alert alert-warning mb-0 fin-alert"><strong>Notas do financeiro:</strong><ul class="mb-0 mt-2">' + items.join('') + '</ul></div>';
    }
    target.innerHTML = html;
  }
  function renderKpiCard(meta) {
    var progressEl = document.querySelector(meta.progressSelector);
    var detailEl   = document.querySelector(meta.detailSelector);
    var helperEl   = document.querySelector(meta.helperSelector);
    if (progressEl) progressEl.style.width = Math.max(4, Math.min(100, Number(meta.progress || 0))) + '%';
    if (detailEl)   detailEl.textContent   = meta.detail  || '-';
    if (helperEl)   helperEl.textContent   = meta.helper  || '';
  }
  function renderSummary(summary, mode) {
    var prev      = summary.prev || {};
    var dueAmount = Math.max(0, Number(summary.open || 0) - Number(summary.overdue || 0));
    var dueTitles = Math.max(0, Number(summary.open_titles || 0) - Number(summary.overdue_titles || 0));
    setText('#finReceived',          formatCurrency(summary.received));
    setText('#finOpen',              formatCurrency(summary.open));
    setText('#finOverdue',           formatCurrency(summary.overdue));
    setText('#finDue',               formatCurrency(dueAmount));
    setText('#finDelinquency',       formatPercent(summary.delinquency_rate));
    setText('#finPlanned',           formatCurrency(summary.planned));
    setText('#finRealizedVsPlanned', formatPercent(summary.realized_vs_planned));
    setHtml('[data-bind="summary-mode"]', renderStatusBadge(mode));
    setHtml('#finReceivedArrow', trendArrow(summary.received, prev.received));
    setHtml('#finOpenArrow',     trendArrow(summary.open,     prev.open));
    setHtml('#finOverdueArrow',  trendArrow(summary.overdue,  prev.overdue));
    renderKpiCard({ progressSelector: '#finReceivedProgress',    detailSelector: '#finReceivedDetail',    helperSelector: '#finReceivedHelper',    progress: summary.realized_vs_planned, detail: formatNumber(summary.received_titles) + ' titulo(s) baixados', helper: 'Ticket medio ' + formatCurrency(summary.avg_received_ticket) });
    renderKpiCard({ progressSelector: '#finOpenProgress',        detailSelector: '#finOpenDetail',        helperSelector: '#finOpenHelper',        progress: summary.planned ? (summary.open / summary.planned) * 100 : 0, detail: formatNumber(summary.open_titles) + ' titulo(s) pendentes', helper: 'Carteira ainda nao liquidada' });
    renderKpiCard({ progressSelector: '#finOverdueProgress',     detailSelector: '#finOverdueDetail',     helperSelector: '#finOverdueHelper',     progress: summary.open ? (summary.overdue / summary.open) * 100 : 0, detail: formatNumber(summary.overdue_titles) + ' titulo(s) em atraso', helper: 'Atrasos ja vencidos' });
    renderKpiCard({ progressSelector: '#finDueProgress',         detailSelector: '#finDueDetail',         helperSelector: '#finDueHelper',         progress: summary.open ? (dueAmount / summary.open) * 100 : 0, detail: formatNumber(dueTitles) + ' titulo(s) a vencer', helper: 'Aberto ainda dentro do prazo' });
    renderKpiCard({ progressSelector: '#finDelinquencyProgress', detailSelector: '#finDelinquencyDetail', helperSelector: '#finDelinquencyHelper', progress: summary.delinquency_rate, detail: formatNumber(summary.accounts) + ' conta(s) com movimento', helper: 'Indice sobre os titulos em aberto' });
    renderKpiCard({ progressSelector: '#finPlannedProgress',     detailSelector: '#finPlannedDetail',     helperSelector: '#finPlannedHelper',     progress: 100, detail: summary.planned_label || 'Carteira do periodo', helper: formatNumber(summary.vendors) + ' vendedor(es) no financeiro' });
    renderKpiCard({ progressSelector: '#finCoverageProgress',    detailSelector: '#finCoverageDetail',    helperSelector: '#finCoverageHelper',    progress: summary.realized_vs_planned, detail: 'Recebido ' + formatCurrency(summary.budget_realized || summary.received), helper: 'Percentual recebido no periodo' });
  }
  function renderHero(summary, statusBreakdown) {
    var outstanding = Number(summary.open || 0) + Number(summary.overdue || 0);
    setText('#heroPrimaryValue',     formatCurrency(summary.received));
    setText('#heroSecondaryValue',   formatCurrency(summary.planned));
    setText('#heroOutstandingValue', formatCurrency(outstanding));
    var list = document.getElementById('heroPortfolioList');
    if (list) list.innerHTML = (statusBreakdown || []).map(function (row) { return '<li><span>' + escapeHtml(row.label) + '</span><strong>' + formatCurrency(row.amount) + '</strong></li>'; }).join('');
  }
  function renderInsights(data) {
    var receivedTop = (data.received_rows || [])[0];
    var clients     = safeArray(data.by_client);
    var lateClients = clients.filter(function (row) { return Number(row.overdue || 0) > 0 || Number(row.max_days_late || 0) > 0; });
    var clientTop   = lateClients[0] || clients[0];
    setText('#insightReceivedDoc',   receivedTop ? (receivedTop.document_type + ' | ' + receivedTop.document_label) : 'Sem destaque');
    setText('#insightReceivedValue', receivedTop ? formatCurrency(receivedTop.amount_net) : '-');
    setText('#insightClientName',    clientTop   ? clientTop.client_name : 'Sem inadimplencia relevante');
    setText('#insightClientValue',   clientTop   ? formatCurrency(clientTop.overdue) : '-');
    setText('#insightClientLate',    clientTop   ? (formatNumber(clientTop.max_days_late) + ' dia(s) de maior atraso') : '-');
    setText('#finLateClients',       formatNumber(lateClients.length));
    setText('#finLateClientsDetail', lateClients.length ? 'Clientes com titulos vencidos no recorte.' : 'Nenhum cliente em atraso no recorte.');
    setText('#finMaxDelay',          clientTop && Number(clientTop.max_days_late || 0) > 0 ? (formatNumber(clientTop.max_days_late) + ' dias') : '-');
    setText('#finMaxDelayDetail',    clientTop && Number(clientTop.max_days_late || 0) > 0 ? (clientTop.client_name || 'Cliente nao identificado') : 'Sem atraso relevante no periodo.');
  }

  // ── Loading / error UI ────────────────────────────────────────────────────
  function toggleLoading(isLoading, message) {
    var loader = document.getElementById('pageLoading');
    if (!loader) return;
    if (isLoading) _loadingStartedAt = performance.now();
    loader.classList.toggle('d-none', !isLoading);
    var label = loader.querySelector('[data-loading-label]');
    if (label) {
      label.textContent = message || 'Carregando financeiro realizado...';
    } else if (!loader.classList.contains('module-loading-shell')) {
      loader.textContent = message || 'Carregando financeiro realizado...';
    }
  }
  function renderError(message) {
    var target = document.getElementById('pageError');
    if (!target) return;
    if (!message) { target.classList.add('d-none'); target.innerHTML = ''; return; }
    target.classList.remove('d-none');
    target.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(message) + '</div>';
  }

  // ── Data loading ──────────────────────────────────────────────────────────
  async function loadFinance(force) {
    renderError('');
    if (_loadController) _loadController.abort();
    _loadController = new AbortController();
    toggleLoading(true, force ? 'Atualizando dados financeiros...' : 'Carregando financeiro realizado...');
    try {
      const data = await fetchJson(buildApiUrl('api/finance_overview.php', force), { cache: 'no-store', signal: _loadController.signal });
      _lastData = data;
      updateMeta(data);
      renderSummary(data.summary || {}, data.mode);
      renderHero(data.summary || {}, data.status_breakdown || []);
      renderInsights(data);
      renderWarnings((data.meta && data.meta.warnings) || [], (data.meta && data.meta.notes) || [], data.local_data_notice || '');
      if (window.BIomieFinance && window.BIomieFinance.renderCharts) window.BIomieFinance.renderCharts(data);
      renderTables(data);
      var dr = getDateRange();
      if (window.BIomieFinance && window.BIomieFinance.loadTaxPanel) window.BIomieFinance.loadTaxPanel(dr.start, dr.end);
      document.dispatchEvent(new CustomEvent('biomie:finance-overview-loaded', { detail: { data: data } }));
    } catch (error) {
      if (error && error.name === 'AbortError') return;
      renderError((error && error.message) || 'Falha ao carregar o modulo financeiro.');
    } finally {
      const elapsed = performance.now() - _loadingStartedAt;
      if (elapsed < LOADING_MIN_MS) await waitFor(LOADING_MIN_MS - elapsed);
      toggleLoading(false);
    }
  }
  async function syncNow(event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    var feedback  = document.getElementById('syncFeedback');
    var csrfToken = (document.querySelector('meta[name="biomie-csrf"]') || {}).getAttribute && document.querySelector('meta[name="biomie-csrf"]').getAttribute('content') || '';
    var body = new URLSearchParams();
    if (csrfToken) body.set('csrf_token', csrfToken);
    if (feedback) feedback.innerHTML = '<div class="alert alert-secondary fin-alert">Sincronizando modulo financeiro...</div>';
    try {
      const resp = await fetch(buildApiUrl('api/finance_sync.php', true), {
        method: 'POST', cache: 'no-store',
        headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-CSRF-Token': csrfToken, 'X-Requested-With': 'fetch' },
        body: body.toString()
      });
      if (resp.redirected) throw new Error('Sessao expirada. Recarregue a pagina e faca login novamente.');
      const ct   = resp.headers.get('content-type') || '';
      const data = ct.includes('application/json') ? await resp.json() : { ok: false, message: 'Resposta inesperada do servidor.' };
      if (!resp.ok || data.ok === false) throw new Error(data.message || 'Falha na sincronizacao.');
      if (feedback) feedback.innerHTML = '<div class="alert alert-success fin-alert">' + escapeHtml(data.message) + '</div>';
      await loadFinance(true);
    } catch (error) {
      if (feedback) feedback.innerHTML = '<div class="alert alert-danger fin-alert">' + escapeHtml(error.message) + '</div>';
    }
  }

  // ── Initialisation ────────────────────────────────────────────────────────
  function initMenuToggle() {
    if (document.querySelector('.mag-sidebar')) return;
    var btn = document.getElementById('menu_toggle');
    if (btn) btn.addEventListener('click', function (e) {
      e.preventDefault();
      document.body.classList.toggle('nav-md');
      document.body.classList.toggle('nav-sm');
    });
  }
  function highlightCurrentMenu() {
    if (document.querySelector('.mag-sidebar')) return;
    var current = window.location.pathname.split('/').pop() || 'financeiro.html';
    document.querySelectorAll('#sidebar-menu a').forEach(function (link) {
      if (link.getAttribute('href') === current && link.parentElement) link.parentElement.classList.add('current-page');
    });
  }
  function notifyPeriodChanged() {
    document.dispatchEvent(new CustomEvent('biomie:finance-period-changed', {
      detail: getDateRange()
    }));
  }
  function bindFilters() {
    document.querySelectorAll('[data-range-preset]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyPresetRange(btn.getAttribute('data-range-preset'));
        loadFinance(false);
        notifyPeriodChanged();
      });
    });
    var applyBtn = document.getElementById('applyFilters');
    if (applyBtn) applyBtn.addEventListener('click', function () {
      loadFinance(false);
      notifyPeriodChanged();
    });
    var syncBtn  = document.getElementById('syncNow');
    if (syncBtn)  syncBtn.addEventListener('click', syncNow);
  }
  function bindBulkBar() {
    var exportBtn = document.getElementById('finBulkExport');
    if (exportBtn) exportBtn.addEventListener('click', exportSelectedCsv);
    var clearBtn  = document.getElementById('finBulkClear');
    if (clearBtn)  clearBtn.addEventListener('click', clearAllSelections);
  }
  function bindGroupButtons() {
    document.querySelectorAll('[data-group-toggle]').forEach(function (btn) {
      var tableId  = btn.dataset.groupToggle;
      var groupKey = btn.dataset.groupKey;
      btn.addEventListener('click', function () {
        var isActive = _groupBy[tableId] === groupKey;
        _groupBy[tableId] = isActive ? null : groupKey;
        btn.classList.toggle('active', !isActive);
        if (_lastData) renderTables(_lastData);
      });
    });
  }
  function bindGlobalClose() {
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.fin-col-picker-wrap')) {
        document.querySelectorAll('.fin-col-picker.open').forEach(function (el) { el.classList.remove('open'); });
      }
    });
  }
  // ── Tax Detail Drawer ─────────────────────────────────────────────────────
  var _taxDrawerChart1 = null;
  var _taxDrawerChart2 = null;

  function openTaxDrawer() {
    var backdrop = document.getElementById('financeTaxDrawerBackdrop');
    var drawer   = document.getElementById('financeTaxDrawer');
    if (!drawer) return;
    backdrop.style.display = 'block';
    drawer.style.display   = 'flex';
    drawer.offsetHeight;
    drawer.classList.add('open');
    var dr = getDateRange();
    loadTaxDrawerData(dr.start, dr.end);
  }

  function closeTaxDrawer() {
    var backdrop = document.getElementById('financeTaxDrawerBackdrop');
    var drawer   = document.getElementById('financeTaxDrawer');
    if (!drawer) return;
    drawer.classList.remove('open');
    window.setTimeout(function () {
      drawer.style.display   = 'none';
      backdrop.style.display = 'none';
    }, 300);
    if (_taxDrawerChart1) { try { _taxDrawerChart1.destroy(); } catch (e) {} _taxDrawerChart1 = null; }
    if (_taxDrawerChart2) { try { _taxDrawerChart2.destroy(); } catch (e) {} _taxDrawerChart2 = null; }
  }

  function loadTaxDrawerData(start, end) {
    var loading = '<div style="padding:56px;text-align:center;color:var(--text-3);"><span class="material-symbols-rounded" style="font-size:28px;display:block;margin-bottom:8px;opacity:.5;">hourglass_top</span>Carregando...</div>';
    ['resumo', 'mensal', 'nfs'].forEach(function (t) {
      var el = document.getElementById('taxDrawerSection-' + t);
      if (el) el.innerHTML = loading;
    });
    document.getElementById('taxDrawerPeriod').textContent = start + ' a ' + end;
    fetchJson('api/finance_tax_detail.php?start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end))
      .then(function (data) {
        if (!data || !data.ok) return;
        document.getElementById('taxDrawerTitle').textContent = 'Carga tributária — ' + (data.summary.trib_total || '-');
        renderTaxDrawerResumo(data);
        renderTaxDrawerMensal(data);
        renderTaxDrawerNFs(data);
      })
      .catch(function (err) {
        var el = document.getElementById('taxDrawerSection-resumo');
        if (el) el.innerHTML = '<div style="padding:40px;color:var(--text-3);">Erro ao carregar: ' + escapeHtml(String(err && err.message || '')) + '</div>';
      });
  }

  function renderTaxDrawerResumo(data) {
    var s   = data.summary || {};
    var fat = Number(s._faturamento_total || 0);
    var trib = Number(s._trib_total || 0);
    var nfsCom = Number(s.nfs_com_imposto || 0);
    var mediaNF = Number(s._media_nf || 0);

    // Find peak month from monthly data
    var monthly = data.monthly || [];
    var peakMonth = monthly.reduce(function (p, c) { return (Number(c._trib) > Number(p._trib)) ? c : p; }, { label: '-', _trib: 0, carga_pct: 0 });
    var peakFat   = data.trend ? (data.trend.reduce(function (p, c) { return (Number(c.faturamento) > Number(p.faturamento)) ? c : p; }, { label: '-', faturamento: 0 })) : { label: '-' };

    var taxCols = [
      { label: 'ICMS',   raw: Number(s._icms   || 0), color: '#6366f1' },
      { label: 'PIS',    raw: Number(s._pis    || 0), color: '#0ea5e9' },
      { label: 'COFINS', raw: Number(s._cofins || 0), color: '#10b981' },
      { label: 'IPI',    raw: Number(s._ipi    || 0), color: '#f59e0b' },
      { label: 'ISS',    raw: Number(s._iss    || 0), color: '#ef4444' },
    ].filter(function (t) { return t.raw > 0; });

    var el = document.getElementById('taxDrawerSection-resumo');
    if (!el) return;

    // KPI row
    var html = '<div class="taxd-kpi-row">' +
      taxCols.map(function (t) {
        var pctFat  = fat  > 0 ? (t.raw / fat  * 100).toFixed(1) : '-';
        var pctTrib = trib > 0 ? (t.raw / trib * 100).toFixed(1) : '-';
        return '<div class="taxd-kpi" style="--txd-c:' + t.color + ';">' +
          '<span class="taxd-kpi__label">' + escapeHtml(t.label) + '</span>' +
          '<strong class="taxd-kpi__value">' + formatCurrency(t.raw) + '</strong>' +
          '<span class="taxd-kpi__row">' +
            '<span class="taxd-kpi__pct">' + pctFat + '% fat.</span>' +
            '<span class="taxd-kpi__share">' + pctTrib + '% trib.</span>' +
          '</span>' +
        '</div>';
      }).join('') +
      '<div class="taxd-kpi taxd-kpi--total">' +
        '<span class="taxd-kpi__label">Total tributos</span>' +
        '<strong class="taxd-kpi__value">' + escapeHtml(s.trib_total || '-') + '</strong>' +
        '<span class="taxd-kpi__row">' +
          '<span class="taxd-kpi__pct taxd-kpi__pct--total">' + (s.carga_tributaria || 0).toFixed(1) + '% do faturamento</span>' +
        '</span>' +
      '</div>' +
    '</div>';

    // Charts row
    html += '<div class="taxd-charts-row">' +
      '<div class="taxd-chart-wrap">' +
        '<h4 class="taxd-chart-label">Evolução mensal dos tributos</h4>' +
        '<div style="position:relative;height:300px;"><canvas id="taxDrawerTrendCanvas"></canvas></div>' +
      '</div>' +
      '<div class="taxd-ring-wrap">' +
        '<h4 class="taxd-chart-label">Composição da carga</h4>' +
        '<div id="taxDrawerRingChart" style="min-height:220px;"></div>' +
      '</div>' +
    '</div>';

    // Insights
    var domTax = taxCols.length > 0 ? taxCols.reduce(function (a, b) { return a.raw > b.raw ? a : b; }) : null;
    html += '<div class="taxd-insights">' +
      (peakMonth._trib > 0 ? '<div class="taxd-insight"><span class="material-symbols-rounded">trending_up</span><div><strong>Mês mais oneroso</strong><span>' + escapeHtml(peakMonth.label) + ' — ' + formatCurrency(Number(peakMonth._trib)) + ' (' + peakMonth.carga_pct + '% carga)</span></div></div>' : '') +
      (domTax ? '<div class="taxd-insight"><span class="material-symbols-rounded">pie_chart</span><div><strong>Tributo dominante</strong><span>' + escapeHtml(domTax.label) + ' — ' + (trib > 0 ? (domTax.raw / trib * 100).toFixed(1) : '-') + '% dos tributos</span></div></div>' : '') +
      (nfsCom > 0 ? '<div class="taxd-insight"><span class="material-symbols-rounded">description</span><div><strong>Média tributária por NF</strong><span>' + formatCurrency(mediaNF) + ' em ' + formatNumber(nfsCom) + ' NFs</span></div></div>' : '') +
      (fat > 0 ? '<div class="taxd-insight"><span class="material-symbols-rounded">account_balance</span><div><strong>Faturamento total</strong><span>' + escapeHtml(s.faturamento_total || '-') + ' no período</span></div></div>' : '') +
    '</div>';

    el.innerHTML = html;

    // Render trend chart
    var trendCanvas = document.getElementById('taxDrawerTrendCanvas');
    var trendRows   = data.trend || [];
    if (trendCanvas && trendRows.length > 0 && window.Chart) {
      if (_taxDrawerChart1) { try { _taxDrawerChart1.destroy(); } catch (e) {} }
      _taxDrawerChart1 = new window.Chart(trendCanvas, {
        type: 'bar',
        data: {
          labels: trendRows.map(function (r) { return r.label || r.month; }),
          datasets: [
            { label: 'ICMS',        data: trendRows.map(function (r) { return r.icms;        }), backgroundColor: '#6366f1', stack: 'tax' },
            { label: 'PIS',         data: trendRows.map(function (r) { return r.pis;         }), backgroundColor: '#0ea5e9', stack: 'tax' },
            { label: 'COFINS',      data: trendRows.map(function (r) { return r.cofins;      }), backgroundColor: '#10b981', stack: 'tax' },
            { label: 'IPI',         data: trendRows.map(function (r) { return r.ipi;         }), backgroundColor: '#f59e0b', stack: 'tax' },
            { label: 'Faturamento', data: trendRows.map(function (r) { return r.faturamento; }), backgroundColor: 'rgba(160,160,160,.12)', stack: 'fat', borderRadius: 4 }
          ]
        },
        options: {
          responsive: true, maintainAspectRatio: false, animation: false,
          plugins: {
            legend: { display: true, position: 'top', labels: { boxWidth: 10, font: { size: 11 }, filter: function (i) { return i.text !== 'Faturamento'; } } },
            tooltip: {
              callbacks: {
                label: function (c) {
                  if (c.dataset.label === 'Faturamento') return 'Faturamento: ' + formatCurrency(c.parsed.y);
                  var fat = trendRows[c.dataIndex] && trendRows[c.dataIndex].faturamento || 0;
                  var pct = fat > 0 ? ' (' + (c.parsed.y / fat * 100).toFixed(1) + '%)' : '';
                  return c.dataset.label + ': ' + formatCurrency(c.parsed.y) + pct;
                }
              }
            }
          },
          scales: {
            x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0 } },
            y: { stacked: false, beginAtZero: true, ticks: { callback: function (v) { return v >= 1000000 ? 'R$' + (v/1000000).toFixed(1) + 'mi' : v >= 1000 ? 'R$' + (v/1000).toFixed(0) + 'k' : 'R$' + v; } } }
          }
        }
      });
    }

    // Render composition ring — paleta categórica validada (mag-chart-defaults)
    var BF = window.BIomieFinance;
    if (BF && BF.renderRingChart) {
      var finCatPalette = (window.MagChartDefaults && window.MagChartDefaults.categoricalPalette)
        || ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7'];
      BF.renderRingChart('taxDrawerRingChart', taxCols, 'label', 'raw',
        finCatPalette.slice(0, 5),
        'Tributos',
        function (row) { return trib > 0 ? (row.raw / trib * 100).toFixed(1) + '%' : ''; }
      );
    }
  }

  function renderTaxDrawerMensal(data) {
    var el = document.getElementById('taxDrawerSection-mensal');
    if (!el) return;
    var rows = data.monthly || [];
    if (!rows.length) {
      el.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-3);">Sem dados mensais no período.</div>';
      return;
    }
    var maxTrib = Math.max.apply(null, rows.map(function (r) { return Number(r._trib || 0); }).concat([1]));

    var html = '<div class="taxd-table-wrap">' +
      '<table class="taxd-table">' +
      '<thead><tr>' +
        '<th>Mês</th><th class="taxd-r">NFs</th><th class="taxd-r">Faturamento</th>' +
        '<th class="taxd-r">ICMS</th><th class="taxd-r">PIS</th><th class="taxd-r">COFINS</th>' +
        '<th class="taxd-r">IPI</th><th class="taxd-r">ISS</th><th class="taxd-r taxd-bold">Total</th>' +
        '<th class="taxd-r">Carga%</th>' +
      '</tr></thead><tbody>';

    rows.forEach(function (r) {
      var barW = maxTrib > 0 ? Math.round(Number(r._trib || 0) / maxTrib * 100) : 0;
      var carga = Number(r.carga_pct || 0);
      var cargaColor = carga >= 30 ? '#dc2626' : (carga >= 25 ? '#d97706' : '#16a34a');
      html += '<tr>' +
        '<td><strong>' + escapeHtml(r.label || r.month) + '</strong></td>' +
        '<td class="taxd-r">' + escapeHtml(String(r.total_nfs || 0)) + '</td>' +
        '<td class="taxd-r">' + escapeHtml(r.faturamento || '-') + '</td>' +
        '<td class="taxd-r" style="color:#6366f1;">' + escapeHtml(r.icms   || '-') + '</td>' +
        '<td class="taxd-r" style="color:#0ea5e9;">' + escapeHtml(r.pis    || '-') + '</td>' +
        '<td class="taxd-r" style="color:#10b981;">' + escapeHtml(r.cofins || '-') + '</td>' +
        '<td class="taxd-r" style="color:#f59e0b;">' + escapeHtml(r.ipi    || '-') + '</td>' +
        '<td class="taxd-r" style="color:#ef4444;">' + escapeHtml(r.iss    || '-') + '</td>' +
        '<td class="taxd-r taxd-bold">' +
          '<div>' + escapeHtml(r.trib_total || '-') + '</div>' +
          '<div class="taxd-mini-bar"><div style="width:' + barW + '%;background:#6366f1;height:100%;border-radius:2px;"></div></div>' +
        '</td>' +
        '<td class="taxd-r"><span class="taxd-carga-badge" style="background:' + cargaColor + '18;color:' + cargaColor + ';border:1px solid ' + cargaColor + '30;">' + carga.toFixed(1) + '%</span></td>' +
      '</tr>';
    });

    // Totals row
    var s = data.summary || {};
    html += '<tr class="taxd-total-row">' +
      '<td><strong>Total</strong></td>' +
      '<td class="taxd-r">' + escapeHtml(String(s.nfs_com_imposto || '-')) + '</td>' +
      '<td class="taxd-r">' + escapeHtml(s.faturamento_total || '-') + '</td>' +
      '<td class="taxd-r" style="color:#6366f1;">' + escapeHtml(s.icms   || '-') + '</td>' +
      '<td class="taxd-r" style="color:#0ea5e9;">' + escapeHtml(s.pis    || '-') + '</td>' +
      '<td class="taxd-r" style="color:#10b981;">' + escapeHtml(s.cofins || '-') + '</td>' +
      '<td class="taxd-r" style="color:#f59e0b;">' + escapeHtml(s.ipi    || '-') + '</td>' +
      '<td class="taxd-r" style="color:#ef4444;">' + escapeHtml(s.iss    || '-') + '</td>' +
      '<td class="taxd-r taxd-bold">' + escapeHtml(s.trib_total || '-') + '</td>' +
      '<td class="taxd-r"><span class="taxd-carga-badge taxd-carga-badge--total">' + (s.carga_tributaria || 0).toFixed(1) + '%</span></td>' +
    '</tr>';

    html += '</tbody></table></div>';
    el.innerHTML = html;
  }

  function renderTaxDrawerNFs(data) {
    var el = document.getElementById('taxDrawerSection-nfs');
    if (!el) return;
    var rows = data.top_nfs || [];
    if (!rows.length) {
      el.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-3);">Sem NFs com dados tributários no período.</div>';
      return;
    }
    var maxTrib = Math.max.apply(null, rows.map(function (r) { return Number(r._trib || 0); }).concat([1]));

    var html = '<p style="font-size:11px;color:var(--text-3);margin:0 0 14px;">Top ' + rows.length + ' NFs por carga tributária · ordenado por total de tributos</p>' +
      '<div class="taxd-table-wrap">' +
      '<table class="taxd-table">' +
      '<thead><tr>' +
        '<th>NF</th><th>Data</th><th>Cliente</th><th>Vendedor</th>' +
        '<th class="taxd-r">Faturamento</th>' +
        '<th class="taxd-r" style="color:#6366f1;">ICMS</th>' +
        '<th class="taxd-r" style="color:#10b981;">COFINS</th>' +
        '<th class="taxd-r" style="color:#f59e0b;">IPI</th>' +
        '<th class="taxd-r taxd-bold">Tributos</th>' +
        '<th class="taxd-r">Carga%</th>' +
      '</tr></thead><tbody>';

    rows.forEach(function (r, i) {
      var barW  = maxTrib > 0 ? Math.round(Number(r._trib || 0) / maxTrib * 100) : 0;
      var carga = Number(r.carga_pct || 0);
      var cargaColor = carga >= 35 ? '#dc2626' : (carga >= 27 ? '#d97706' : '#16a34a');
      html += '<tr>' +
        '<td><span style="font-size:11px;font-weight:700;font-family:monospace;">NF ' + escapeHtml(r.numero_nf || '-') + '</span></td>' +
        '<td style="white-space:nowrap;font-size:11px;">' + escapeHtml(r.data_emissao || '-') + '</td>' +
        '<td style="max-width:160px;"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;" title="' + escapeHtml(r.nome_cliente || '') + '">' + escapeHtml(r.nome_cliente || '-') + '</div></td>' +
        '<td style="max-width:120px;"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:var(--text-2);" title="' + escapeHtml(r.nome_vendedor || '') + '">' + escapeHtml(r.nome_vendedor || '-') + '</div></td>' +
        '<td class="taxd-r" style="font-size:11px;">' + escapeHtml(r.valor_total  || '-') + '</td>' +
        '<td class="taxd-r" style="font-size:11px;color:#6366f1;">' + escapeHtml(r.valor_icms   || '-') + '</td>' +
        '<td class="taxd-r" style="font-size:11px;color:#10b981;">' + escapeHtml(r.valor_cofins || '-') + '</td>' +
        '<td class="taxd-r" style="font-size:11px;color:#f59e0b;">' + escapeHtml(r.valor_ipi    || '-') + '</td>' +
        '<td class="taxd-r taxd-bold">' +
          '<div style="font-size:11px;">' + escapeHtml(r.valor_trib_total || '-') + '</div>' +
          '<div class="taxd-mini-bar"><div style="width:' + barW + '%;background:#6366f1;height:100%;border-radius:2px;"></div></div>' +
        '</td>' +
        '<td class="taxd-r"><span class="taxd-carga-badge" style="background:' + cargaColor + '18;color:' + cargaColor + ';border:1px solid ' + cargaColor + '30;">' + carga.toFixed(1) + '%</span></td>' +
      '</tr>';
    });

    html += '</tbody></table></div>';
    el.innerHTML = html;
  }

  function bindTaxDrawer() {
    var openBtn  = document.getElementById('financeTaxDetailBtn');
    var closeBtn = document.getElementById('financeTaxDrawerClose');
    var backdrop = document.getElementById('financeTaxDrawerBackdrop');
    var tabNav   = document.getElementById('taxDrawerTabNav');

    if (openBtn)  openBtn.addEventListener('click', openTaxDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeTaxDrawer);
    if (backdrop) backdrop.addEventListener('click', closeTaxDrawer);

    if (tabNav) {
      tabNav.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-tax-tab]');
        if (!btn) return;
        var target = btn.getAttribute('data-tax-tab');
        tabNav.querySelectorAll('[data-tax-tab]').forEach(function (b) {
          var active = b === btn;
          b.style.borderBottomColor = active ? 'var(--page-accent, #0f9f6e)' : 'transparent';
          b.style.color = active ? 'var(--page-accent, #0f9f6e)' : 'var(--text-2)';
          b.style.fontWeight = active ? '700' : '500';
        });
        document.querySelectorAll('.v360-section').forEach(function (s) {
          s.style.display = s.id === 'taxDrawerSection-' + target ? '' : 'none';
        });
      });
    }
  }

  function init() {
    var range = getDateRange();
    if (!range.start || !range.end) applyPresetRange('month');
    initMenuToggle();
    highlightCurrentMenu();
    bindFilters();
    bindBulkBar();
    bindGroupButtons();
    bindGlobalClose();
    bindTaxDrawer();
    loadFinance(false);
  }

  document.addEventListener('biomie:finance-feature-ready', function (event) {
    var feature = event && event.detail ? event.detail.feature : '';
    if (feature === 'charts' && _lastData && window.BIomieFinance) {
      if (typeof window.BIomieFinance.renderCharts === 'function') {
        window.BIomieFinance.renderCharts(_lastData);
      }
      var range = getDateRange();
      if (typeof window.BIomieFinance.loadTaxPanel === 'function' && range.start && range.end) {
        window.BIomieFinance.loadTaxPanel(range.start, range.end);
      }
    }
  });

  document.addEventListener('DOMContentLoaded', init);
})();
