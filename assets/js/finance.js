(function () {
  // â”€â”€â”€ State â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  const charts = new Map();
  let _lastData   = null;
  let _drillFilter = null;   // { field, value, label } | null
  let _groupBy     = {};     // tableId â†’ groupKey | null
  let _selectedRows = {};    // tableId â†’ Set<number>
  let _colVisible  = {};     // tableId â†’ { colKey: boolean }
  let _tableExpanded = {};   // tableId â†’ boolean
  let _loadController = null;
  const LOADING_MIN_MS = 420;
  let _loadingStartedAt = 0;

  function waitFor(ms) {
    return new Promise((resolve) => {
      window.setTimeout(resolve, Math.max(0, ms));
    });
  }

  // â”€â”€â”€ Formatters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  const currencyBRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  const numberBR    = new Intl.NumberFormat('pt-BR');
  function getCssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }
  function chartTheme() {
    if (window.BIOMIE_THEME && typeof window.BIOMIE_THEME.getChartTheme === 'function') {
      return window.BIOMIE_THEME.getChartTheme();
    }
    return {
      text: getCssVar('--text-2') || '#4b5263',
      title: getCssVar('--text-1') || '#111318',
      subtle: getCssVar('--text-3') || '#9099ac',
      surface: getCssVar('--bg-card') || '#ffffff',
      grid: getCssVar('--chart-grid') || 'rgba(0,0,0,.08)',
      tooltipBg: getCssVar('--chart-tooltip-bg') || '#ffffff',
      tooltipBorder: getCssVar('--chart-tooltip-border') || 'rgba(0,0,0,.12)',
      tooltipTitle: getCssVar('--chart-tooltip-title') || '#111318',
      tooltipText: getCssVar('--chart-tooltip-text') || '#4b5263'
    };
  }
  const chartPalette = {
    emerald: getCssVar('--state-billed') || '#0f9f6e',
    emeraldSoft: getCssVar('--success-strong') || '#35b98b',
    emeraldDeep: getCssVar('--green') || '#0a6f4d',
    emeraldMute: getCssVar('--success-soft') || '#dff7ed',
    gold: getCssVar('--state-auth') || '#c99a2e',
    red: getCssVar('--state-cancel') || '#d04d4d',
    redSoft: getCssVar('--danger-soft') || '#f2c7c7',
    teal: getCssVar('--info') || '#149a9a',
    navy: getCssVar('--state-info') || '#28597a',
    gray: getCssVar('--text-2') || '#5f6f66',
    graySoft: getCssVar('--surface-3') || '#e7efeb',
    lime: getCssVar('--green') || '#83b735'
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function setText(selector, value) {
    document.querySelectorAll(selector).forEach((el) => { el.textContent = value; });
  }
  function setHtml(selector, value) {
    document.querySelectorAll(selector).forEach((el) => { el.innerHTML = value; });
  }
  function formatCurrency(value) { return currencyBRL.format(Number(value || 0)); }
  function formatNumber(value)   { return numberBR.format(Number(value || 0)); }
  function formatPercent(value)  { return `${Number(value || 0).toFixed(1)}%`; }
  function formatCurrencyShort(value) {
    const number = Number(value || 0);
    if (Math.abs(number) >= 1000000) return `R$ ${(number / 1000000).toFixed(1).replace('.', ',')} mi`;
    if (Math.abs(number) >= 1000) return `R$ ${(number / 1000).toFixed(0).replace('.', ',')} mil`;
    return formatCurrency(number);
  }
  function toIsoLocal(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }
  function todayIso() { return toIsoLocal(new Date()); }
  function formatDateBR(dateString) {
    if (!dateString) return 'â€”';
    return new Date(`${dateString}T12:00:00`).toLocaleDateString('pt-BR');
  }
  function setDateRange(start, end) {
    const s = document.getElementById('dateStart');
    const e = document.getElementById('dateEnd');
    if (s) s.value = start;
    if (e) e.value = end;
  }
  function getDateRange() {
    return { start: document.getElementById('dateStart')?.value || '', end: document.getElementById('dateEnd')?.value || '' };
  }
  function applyPresetRange(preset) {
    const now = new Date();
    if (preset === 'month') {
      setDateRange(
        toIsoLocal(new Date(now.getFullYear(), now.getMonth(), 1)),
        toIsoLocal(new Date(now.getFullYear(), now.getMonth() + 1, 0))
      );
    } else if (preset === 'year') {
      setDateRange(`${now.getFullYear()}-01-01`, todayIso());
    } else if (preset === 'rolling12') {
      setDateRange(toIsoLocal(new Date(now.getFullYear() - 1, now.getMonth(), now.getDate() + 1)), todayIso());
    }
  }
  function buildApiUrl(path, force) {
    const params = new URLSearchParams();
    const range = getDateRange();
    if (range.start) params.set('start', range.start);
    if (range.end)   params.set('end', range.end);
    if (force)       params.set('force', '1');
    return path + (params.toString() ? `?${params.toString()}` : '');
  }
  async function fetchJson(url, options) {
    if (typeof window.fetchJson === 'function') {
      return window.fetchJson(url, options);
    }
    const response = await fetch(url, Object.assign({ headers: { Accept: 'application/json' } }, options || {}));
    const contentType = response.headers.get('content-type') || '';
    const raw = await response.text();
    let payload = null;
    if (contentType.includes('application/json')) {
      try {
        payload = JSON.parse(raw);
      } catch (error) {
        throw new Error('O servidor respondeu com JSON invalido para o financeiro.');
      }
    }
    if (response.redirected) {
      throw new Error('Sessao expirada. Recarregue a pagina e faca login novamente.');
    }
    if (!response.ok) {
      throw new Error(payload?.message || raw.slice(0, 180) || 'Nao foi possivel carregar os dados financeiros.');
    }
    if (!payload || payload.ok === false) {
      throw new Error(payload?.message || 'Nao foi possivel carregar os dados financeiros.');
    }
    return payload;
  }
  function safeArray(value)          { return Array.isArray(value) ? value : []; }
  function topRows(rows, limit)      { return safeArray(rows).slice(0, limit || rows.length); }

  // â”€â”€â”€ Status badges â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function renderStatusBadge(mode) {
    const n = String(mode || '').toLowerCase();
    if (n.includes('live'))     return '<span class="fin-chip fin-chip-success">Online</span>';
    if (n.includes('fallback')) return '<span class="fin-chip fin-chip-warning">Cache anterior</span>';
    return '<span class="fin-chip fin-chip-muted">Sem dados</span>';
  }
  function renderModePill(mode) {
    const n = String(mode || '').toLowerCase();
    if (n.includes('live'))     return 'Sincronizacao ativa';
    if (n.includes('fallback')) return 'Operando com cache';
    return 'Aguardando dados';
  }

  function formatMetaDate(value) {
    if (!value) return 'â€”';
    const parsed = new Date(String(value).length === 10 ? `${value}T12:00:00` : value);
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleDateString('pt-BR');
  }

  function humanizeServedFrom(value) {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    if (normalized.includes('legacy_fallback')) return 'legacy-fallback';
    if (normalized.includes('legacy')) return 'legacy';
    if (normalized.includes('historical')) return 'historical-read-model';
    if (normalized.includes('snapshot')) return 'historical-read-model';
    if (normalized.includes('canonical')) return 'canonical-db';
    return String(value);
  }

  function resolveCoverageLabel(data) {
    const meta = data?.meta || {};
    const actual = meta.coverage_actual || {};
    const start = actual.start || meta.coverage_start || data?.coverage_start || data?.period?.start || 'â€”';
    const end = actual.end || meta.coverage_end || data?.coverage_end || data?.period?.end || 'â€”';
    return `${formatMetaDate(start)} ate ${formatMetaDate(end)}`;
  }

  function renderSyncStateBadges(data) {
    const meta = data?.meta || {};
    const badges = [];
    const servedFrom = humanizeServedFrom(meta.served_from || data?.source || '');
    const signalStatus = String(meta.signal_status || '').toLowerCase();
    const openDivergences = Number(meta.open_divergences || 0);

    if (servedFrom === 'canonical-db') {
      badges.push('<span class="mag-status-chip mag-status-live">Leitura principal</span>');
    } else if (servedFrom === 'historical-read-model') {
      badges.push('<span class="mag-status-chip mag-status-warning">Snapshot historico</span>');
    } else if (servedFrom === 'legacy-fallback') {
      badges.push('<span class="mag-status-chip mag-status-warning">Fallback legado</span>');
    } else if (servedFrom === 'legacy') {
      badges.push('<span class="mag-status-chip mag-status-demo">Leitura legado</span>');
    }

    if (signalStatus === 'partial_window') {
      badges.push('<span class="mag-status-chip mag-status-warning">Cobertura parcial</span>');
    } else if (signalStatus === 'sync_missing') {
      badges.push('<span class="mag-status-chip mag-status-warning">Sync ausente</span>');
    } else if (signalStatus === 'empty_after_sync') {
      badges.push('<span class="mag-status-chip mag-status-demo">Periodo vazio</span>');
    }

    if (meta.has_retry_or_delay) {
      badges.push('<span class="mag-status-chip mag-status-warning">Pipeline com atraso</span>');
    }
    if (openDivergences > 0) {
      badges.push(`<span class="mag-status-chip mag-status-warning">${formatNumber(openDivergences)} divergencia(s)</span>`);
    }

    return badges.join(' ');
  }

  // â”€â”€â”€ Chart registry â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function destroyChart(id)          { const c = charts.get(id); if (c) { c.destroy(); charts.delete(id); } }
  function registerChart(id, chart)  { charts.set(id, chart); }
  function getCanvas(id) {
    const canvas = document.getElementById(id);
    if (!canvas || !window.Chart) return null;
    return canvas.getContext('2d');
  }
  function clearCanvasEmptyState(canvasId) {
    const canvas = document.getElementById(canvasId);
    const parent = canvas?.parentElement;
    if (!canvas || !parent) return;
    canvas.classList.remove('d-none');
    parent.querySelector('.fin-chart-empty')?.remove();
  }
  function renderEmptyCanvasChart(canvasId, message) {
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    const parent = canvas?.parentElement;
    if (!canvas || !parent) return;
    canvas.classList.add('d-none');
    let empty = parent.querySelector('.fin-chart-empty');
    if (!empty) {
      empty = document.createElement('div');
      empty.className = 'fin-chart-empty';
      parent.appendChild(empty);
    }
    empty.textContent = message || 'Sem dados suficientes para o grafico.';
  }
  function injectCanvasHost(targetId, canvasId, extraHtml) {
    destroyChart(targetId);
    const target = document.getElementById(targetId);
    if (!target) return null;
    target.innerHTML = `${extraHtml || ''}<div class="fin-chart-stage"><canvas id="${canvasId}"></canvas></div>`;
    return getCanvas(canvasId);
  }
  function axisMoneyTick(value) {
    return formatCurrencyShort(value);
  }

  // â”€â”€â”€ Feature 2: Conditional formatting â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function trendArrow(current, previous) {
    if (previous == null || Number(previous) === 0) return '';
    const pct = ((Number(current) - Number(previous)) / Math.abs(Number(previous))) * 100;
    if (Math.abs(pct) < 0.5) return '';
    const up = pct > 0;
    return `<span class="fin-trend-arrow fin-trend-${up ? 'up' : 'down'}">${up ? 'â†‘' : 'â†“'} ${Math.abs(pct).toFixed(1)}%</span>`;
  }
  function statusBadge(daysLate) {
    const days = Number(daysLate || 0);
    if (days <= 0)  return '<span class="fin-badge fin-badge-green">No prazo</span>';
    if (days <= 15) return `<span class="fin-badge fin-badge-gold">${days}d atraso</span>`;
    return `<span class="fin-badge fin-badge-red">${days}d atraso</span>`;
  }

  // â”€â”€â”€ Feature 1: Drill-down â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function setDrillFilter(field, value, label) {
    _drillFilter  = { field, value, label };
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
    const container = document.getElementById('finDrillBar');
    if (!container) return;
    if (!_drillFilter) {
      container.innerHTML = '';
      container.classList.add('d-none');
      return;
    }
    container.classList.remove('d-none');
    container.innerHTML = `<div class="fin-drill-bar">
      <span class="fin-drill-label">
        <span class="material-symbols-rounded" style="font-size:16px">filter_alt</span>
        Filtro ativo: <strong>${escapeHtml(_drillFilter.label)}</strong>
      </span>
      <button class="fin-drill-clear" id="finDrillClear">
        Limpar <span class="material-symbols-rounded" style="font-size:14px;vertical-align:-2px">close</span>
      </button>
    </div>`;
    document.getElementById('finDrillClear')?.addEventListener('click', clearDrillFilter);
  }
  function applyDrillRows(rows) {
    if (!_drillFilter) return rows;
    const { field, value } = _drillFilter;
    return rows.filter((row) => String(row[field] || '').toLowerCase() === String(value).toLowerCase());
  }

  // â”€â”€â”€ Feature 5: Column visibility â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function loadColVisible(tableId, cols) {
    if (_colVisible[tableId]) return;
    try {
      const stored = localStorage.getItem(`fin-cols-${tableId}`);
      _colVisible[tableId] = stored ? JSON.parse(stored) : {};
    } catch { _colVisible[tableId] = {}; }
    cols.forEach((col) => {
      if (col.key && _colVisible[tableId][col.key] === undefined) _colVisible[tableId][col.key] = true;
    });
  }
  function saveColVisible(tableId) {
    try { localStorage.setItem(`fin-cols-${tableId}`, JSON.stringify(_colVisible[tableId])); } catch {}
  }
  function isColVisible(tableId, colKey) {
    return _colVisible[tableId]?.[colKey] !== false;
  }
  function renderColPicker(tableId, cols) {
    const wrap = document.querySelector(`[data-col-picker="${tableId}"]`);
    if (!wrap) return;
    wrap.innerHTML = `
      <button class="fin-col-picker-btn" data-col-picker-toggle="${tableId}">
        <span class="material-symbols-rounded" style="font-size:15px">tune</span> Colunas
      </button>
      <div class="fin-col-picker" id="finColPicker-${tableId}">
        ${cols.filter((c) => c.key && c.label).map((col) => `
          <label>
            <input type="checkbox" data-col-toggle="${tableId}:${col.key}" ${isColVisible(tableId, col.key) ? 'checked' : ''}>
            ${escapeHtml(col.label)}
          </label>`).join('')}
      </div>`;
    wrap.querySelector(`[data-col-picker-toggle]`)?.addEventListener('click', (e) => {
      e.stopPropagation();
      document.getElementById(`finColPicker-${tableId}`)?.classList.toggle('open');
    });
    wrap.querySelectorAll('[data-col-toggle]').forEach((cb) => {
      cb.addEventListener('change', () => {
        const [tId, colKey] = cb.getAttribute('data-col-toggle').split(':');
        _colVisible[tId][colKey] = cb.checked;
        saveColVisible(tId);
        if (_lastData) renderTables(_lastData);
      });
    });
  }

  // â”€â”€â”€ Feature 4: Bulk actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function getSelection(tableId)  { return _selectedRows[tableId] || new Set(); }
  function toggleRow(tableId, index, checked) {
    if (!_selectedRows[tableId]) _selectedRows[tableId] = new Set();
    if (checked) _selectedRows[tableId].add(index);
    else         _selectedRows[tableId].delete(index);
    updateBulkBar();
  }
  function clearAllSelections() {
    _selectedRows = {};
    document.querySelectorAll('.fin-row-check').forEach((cb) => { cb.checked = false; });
    updateBulkBar();
  }
  function updateBulkBar() {
    const bar = document.getElementById('finBulkBar');
    if (!bar) return;
    let total = 0;
    Object.values(_selectedRows).forEach((sel) => { total += sel.size; });
    if (total === 0) { bar.classList.add('d-none'); return; }
    bar.classList.remove('d-none');
    const countEl = document.getElementById('finBulkCount');
    if (countEl) countEl.textContent = `${total} linha${total > 1 ? 's' : ''} selecionada${total > 1 ? 's' : ''}`;
  }
  function exportSelectedCsv() {
    if (!_lastData) return;
    const sourceMap = {
      financeReceivedTable: 'received_rows',
      financeOpenTable:     'open_titles',
      financeClientsTable:  'by_client',
      financeBudgetTable:   'budget'
    };
    const allRows = [];
    let headers   = null;
    Object.entries(_selectedRows).forEach(([tableId, sel]) => {
      if (!sel.size) return;
      const key      = sourceMap[tableId];
      if (!key) return;
      const source   = applyDrillRows(_lastData[key] || []);
      sel.forEach((idx) => { if (source[idx]) allRows.push(source[idx]); });
      if (!headers && source[0]) headers = Object.keys(source[0]);
    });
    if (!allRows.length) return;
    if (!headers) headers = Object.keys(allRows[0]);
    const esc   = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const csv   = [headers.join(','), ...allRows.map((r) => headers.map((h) => esc(r[h])).join(','))].join('\n');
    const blob  = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url   = URL.createObjectURL(blob);
    const a     = document.createElement('a');
    a.href = url; a.download = 'financeiro-export.csv'; a.click();
    URL.revokeObjectURL(url);
  }

  // â”€â”€â”€ Feature 3: Grouped rows â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function renderGroupedRows(target, visibleCols, rows, groupKey, tableId) {
    const groups = new Map();
    rows.forEach((row, index) => {
      const key     = String(row[groupKey] || 'Nao identificado');
      if (!groups.has(key)) groups.set(key, { rows: [], total: 0, indices: [] });
      const g       = groups.get(key);
      const amount  = Number(row.amount_net || row.amount_open || row.amount || row.overdue || row.realized || 0);
      g.rows.push(row);
      g.total += amount;
      g.indices.push(index);
    });
    if (!target._collapsed) target._collapsed = new Set();
    const _collapsed = target._collapsed;
    const sel        = getSelection(tableId);
    const html       = [];
    groups.forEach((group, key) => {
      const collapsed = _collapsed.has(key);
      html.push(`<tr class="fin-group-row" data-group-key="${escapeHtml(key)}">
        <td colspan="${visibleCols.length + 1}">
          <span class="fin-group-toggle">
            <span class="material-symbols-rounded" style="font-size:16px">${collapsed ? 'expand_more' : 'expand_less'}</span>
            <strong>${escapeHtml(key)}</strong>
            <span class="fin-badge fin-badge-green">${group.rows.length}</span>
            ${group.total > 0 ? `<span style="margin-left:auto;font-weight:800;color:var(--fin-strong)">${formatCurrency(group.total)}</span>` : ''}
          </span>
        </td>
      </tr>`);
      if (!collapsed) {
        group.rows.forEach((row, i) => {
          html.push(`<tr class="fin-group-child${sel.has(group.indices[i]) ? ' table-active' : ''}">
            <td class="fin-select-cell"><input type="checkbox" class="fin-row-check" data-table="${escapeHtml(tableId)}" data-index="${group.indices[i]}" ${sel.has(group.indices[i]) ? 'checked' : ''}></td>
            ${visibleCols.map((col) => `<td>${col.render(row)}</td>`).join('')}
          </tr>`);
        });
      }
    });
    target.innerHTML = html.join('');
    target.querySelectorAll('.fin-group-row').forEach((row) => {
      row.addEventListener('click', () => {
        const k = row.dataset.groupKey;
        if (_collapsed.has(k)) _collapsed.delete(k); else _collapsed.add(k);
        renderGroupedRows(target, visibleCols, rows, groupKey, tableId);
      });
    });
    target.querySelectorAll('.fin-row-check').forEach((cb) => {
      cb.addEventListener('change', () => toggleRow(cb.dataset.table, Number(cb.dataset.index), cb.checked));
    });
  }

  // â”€â”€â”€ renderTable (with all features) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function tablePreviewLimit(tableId) {
    if (tableId === 'financeClientsTable') return 8;
    if (tableId === 'financeBudgetTable') return 6;
    return 10;
  }
  function renderTableMeta(tableId, totalRows, shownRows) {
    const target = document.querySelector(`[data-table-meta-for="${tableId}"]`);
    if (!target) return;
    const limit = tablePreviewLimit(tableId);
    const expandable = totalRows > limit && !_groupBy[tableId];
    target.innerHTML = `<span>${formatNumber(shownRows)} de ${formatNumber(totalRows)} linha(s)</span>${expandable ? `<button type="button" class="fin-table-toggle" data-table-toggle="${tableId}">${_tableExpanded[tableId] ? 'Mostrar menos' : 'Ver todas'}</button>` : ''}`;
    target.querySelector('[data-table-toggle]')?.addEventListener('click', () => {
      _tableExpanded[tableId] = !_tableExpanded[tableId];
      if (_lastData) renderTables(_lastData);
    });
  }
  function renderTable(targetId, columns, rows, emptyMessage) {
    const target = document.getElementById(targetId);
    if (!target) return;
    loadColVisible(targetId, columns);
    const visibleCols = columns.filter((col) => !col.key || isColVisible(targetId, col.key));
    const sel = getSelection(targetId);
    const allRows = safeArray(rows);
    if (!allRows.length) {
      renderTableMeta(targetId, 0, 0);
      target.innerHTML = `<tr><td colspan="${visibleCols.length + 1}" class="text-center py-4 text-muted">${escapeHtml(emptyMessage || 'Nenhum dado disponivel.')}</td></tr>`;
      return;
    }
    const groupKey = _groupBy[targetId];
    if (groupKey) {
      renderTableMeta(targetId, allRows.length, allRows.length);
      renderGroupedRows(target, visibleCols, allRows, groupKey, targetId);
      return;
    }

    const limit = tablePreviewLimit(targetId);
    const displayRows = _tableExpanded[targetId] ? allRows : allRows.slice(0, limit);
    renderTableMeta(targetId, allRows.length, displayRows.length);
    target.innerHTML = displayRows.map((row, index) => `
      <tr class="${sel.has(index) ? 'table-active' : ''}">
        <td class="fin-select-cell"><input type="checkbox" class="fin-row-check" data-table="${escapeHtml(targetId)}" data-index="${index}" ${sel.has(index) ? 'checked' : ''}></td>
        ${visibleCols.map((col) => `<td>${col.render(row)}</td>`).join('')}
      </tr>`).join('');
    target.querySelectorAll('.fin-row-check').forEach((cb) => {
      cb.addEventListener('change', () => toggleRow(cb.dataset.table, Number(cb.dataset.index), cb.checked));
    });
  }

  // â”€â”€â”€ Chart helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function renderEmptyChart(targetId, message) {
    destroyChart(targetId);
    const target = document.getElementById(targetId);
    if (!target) return;
    target.innerHTML = `<div class="fin-chart-empty">${escapeHtml(message || 'Sem dados suficientes para o grafico.')}</div>`;
  }
  function buildConicGradient(rows, valueKey, colors) {
    const total = rows.reduce((sum, row) => sum + Number(row?.[valueKey] || 0), 0);
    if (total <= 0) return '';
    let current = 0;
    return `conic-gradient(${rows.map((row, i) => {
      const value = Number(row?.[valueKey] || 0);
      const start = (current / total) * 360;
      current += value;
      return `${colors[i % colors.length]} ${start}deg ${(current / total) * 360}deg`;
    }).join(', ')})`;
  }
  function commonTooltip() {
    const theme = chartTheme();
    return {
      backgroundColor: theme.tooltipBg,
      borderColor: theme.tooltipBorder,
      borderWidth: 1,
      titleColor: theme.tooltipTitle,
      bodyColor: theme.tooltipText,
      callbacks: {
        label(ctx) {
          const v = ctx.parsed?.y ?? ctx.parsed ?? 0;
          return `${ctx.dataset.label}: ${formatCurrency(v)}`;
        }
      }
    };
  }

  function renderTrendHtml(targetId, rows, context) {
    const allRows = safeArray(rows).filter((row) => Number(row.received || 0) > 0 || Number(row.planned || 0) > 0 || Number(row.overdue || 0) > 0 || Number(row.open || 0) > 0);
    if (!allRows.length) { renderEmptyChart(targetId, 'Sem ritmo de carteira para o periodo selecionado.'); return; }

    const bestCoverage = allRows.reduce((best, row) => {
      const planned = Number(row.planned || 0);
      const coverage = planned > 0 ? (Number(row.received || 0) / planned) * 100 : 0;
      return !best || coverage > best.coverage ? { label: row.label || row.key || 'â€”', coverage } : best;
    }, null);
    const worstOverdue = allRows.reduce((worst, row) => {
      const overdue = Number(row.overdue || 0);
      return !worst || overdue > worst.amount ? { label: row.label || row.key || 'â€”', amount: overdue } : worst;
    }, null);
    const totalReceived = allRows.reduce((sum, row) => sum + Number(row.received || 0), 0);
    const totalPlanned = allRows.reduce((sum, row) => sum + Number(row.planned || 0), 0);
    const granularity = context?.granularity === 'month' ? 'Mensal' : 'Diaria';

    const ctx = injectCanvasHost(
      targetId,
      `${targetId}Canvas`,
      `<div class="fin-insight-grid fin-insight-grid-compact" style="margin-bottom:14px">
        <div class="fin-insight-card"><span>Granularidade</span><strong>${escapeHtml(granularity)}</strong><small>${formatNumber(context?.points || allRows.length)} ponto(s)</small></div>
        <div class="fin-insight-card"><span>Cobertura do recorte</span><strong>${formatPercent(totalPlanned > 0 ? (totalReceived / totalPlanned) * 100 : 0)}</strong><small>${formatCurrency(totalReceived)} realizado</small></div>
        <div class="fin-insight-card"><span>Melhor cobertura</span><strong>${escapeHtml(bestCoverage?.label || 'â€”')}</strong><small>${formatPercent(bestCoverage?.coverage || 0)}</small></div>
        <div class="fin-insight-card"><span>Pico vencido</span><strong>${escapeHtml(worstOverdue?.label || 'â€”')}</strong><small>${formatCurrency(worstOverdue?.amount || 0)}</small></div>
      </div>`
    );
    if (!ctx) return;

    const labels = allRows.map((row) => row.label || row.key || 'â€”');
    const received = allRows.map((row) => Number(row.received || 0));
    const planned = allRows.map((row) => Number(row.planned || 0));
    const overdue = allRows.map((row) => Number(row.overdue || 0));

    const chart = new window.Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            type: 'line',
            label: 'Carteira',
            data: planned,
            borderColor: chartPalette.gold,
            backgroundColor: chartPalette.gold,
            pointBackgroundColor: chartPalette.gold,
            pointRadius: allRows.length > 18 ? 2 : 3,
            pointHoverRadius: 4,
            borderWidth: 3,
            tension: 0.28,
            fill: false,
            order: 0,
          },
          {
            type: 'bar',
            label: 'Recebido',
            data: received,
            backgroundColor: chartPalette.emerald,
            borderRadius: 10,
            borderSkipped: false,
            barThickness: 'flex',
            maxBarThickness: 22,
            categoryPercentage: 0.72,
            order: 1,
          },
          {
            type: 'bar',
            label: 'Vencido',
            data: overdue,
            backgroundColor: chartPalette.red,
            borderRadius: 10,
            borderSkipped: false,
            barThickness: 'flex',
            maxBarThickness: 22,
            categoryPercentage: 0.72,
            order: 2,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        normalized: true,
        resizeDelay: 80,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top', align: 'start' },
          tooltip: {
            callbacks: {
              title(items) {
                return items?.[0]?.label || '';
              },
              label(ctx) {
                return `${ctx.dataset.label}: ${formatCurrency(ctx.parsed?.y ?? ctx.parsed ?? 0)}`;
              },
              afterBody(items) {
                const idx = items?.[0]?.dataIndex ?? -1;
                const row = allRows[idx];
                if (!row) return [];
                const coverage = Number(row.planned || 0) > 0 ? (Number(row.received || 0) / Number(row.planned || 0)) * 100 : 0;
                return [`Cobertura: ${formatPercent(coverage)}`, `Em aberto: ${formatCurrency(row.open || 0)}`];
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              autoSkip: labels.length > 16,
              maxRotation: 0,
              minRotation: 0,
            }
          },
          y: {
            beginAtZero: true,
            ticks: { callback(value) { return axisMoneyTick(value); } },
            grid: { color: chartTheme().grid }
          }
        }
      }
    });
    registerChart(targetId, chart);
  }

  // Drill-click on ring chart rows
  function renderRingChart(targetId, rows, labelKey, valueKey, colors, centerLabel, metaRenderer) {
    const target   = document.getElementById(targetId);
    if (!target) return;
    const dataRows = safeArray(rows).filter((row) => Number(row?.[valueKey] || 0) > 0);
    if (!dataRows.length) { renderEmptyChart(targetId, 'Sem distribuicao disponivel no periodo.'); return; }
    const limited  = topRows(dataRows, 6);
    const total    = limited.reduce((sum, row) => sum + Number(row?.[valueKey] || 0), 0);
    const gradient = buildConicGradient(limited, valueKey, colors);
    target.innerHTML = `
      <div class="fin-ring-wrap">
        <div class="fin-ring" style="--ring-gradient:${gradient};">
          <div class="fin-ring-center"><span>${escapeHtml(centerLabel || 'Total')}</span><strong>${formatCurrency(total)}</strong></div>
        </div>
        <div class="fin-ring-list">
          ${limited.map((row, i) => `
            <div class="fin-ring-row" data-drill-field="${escapeHtml(labelKey)}" data-drill-value="${escapeHtml(String(row[labelKey] || ''))}" style="cursor:pointer" title="Filtrar por ${escapeHtml(String(row[labelKey] || ''))}">
              <div>
                <div class="label"><i class="fin-ring-dot" style="background:${colors[i % colors.length]}"></i>${escapeHtml(row[labelKey] || 'Nao informado')}</div>
                <div class="meta">${metaRenderer ? metaRenderer(row) : ''}</div>
              </div>
              <div class="text-end"><strong>${formatCurrency(row[valueKey])}</strong></div>
            </div>`).join('')}
        </div>
      </div>`;
    target.querySelectorAll('[data-drill-field]').forEach((el) => {
      el.addEventListener('click', () => {
        const fieldLabels = { vendor_name: 'Vendedor', client_name: 'Cliente', payment_method: 'Meio de pag.', document_type: 'Tipo de doc.', account_name: 'Conta' };
        const lbl = fieldLabels[el.dataset.drillField] || el.dataset.drillField;
        setDrillFilter(el.dataset.drillField, el.dataset.drillValue, `${lbl}: ${el.dataset.drillValue}`);
      });
    });
  }

  function renderHorizontalBarsHtml(targetId, rows, labelKey, valueKey, color, formatter, metaRenderer) {
    const target   = document.getElementById(targetId);
    if (!target) return;
    const dataRows = safeArray(rows).filter((row) => Number(row?.[valueKey] || 0) > 0);
    if (!dataRows.length) { renderEmptyChart(targetId, 'Sem inadimplencia vencida suficiente para montar faixas.'); return; }
    const limited  = topRows(dataRows, 8);
    const maxValue = Math.max(...limited.map((row) => Number(row?.[valueKey] || 0)), 1);
    target.innerHTML = `<div class="fin-bar-list">${limited.map((row) => {
      const value = Number(row?.[valueKey] || 0);
      const width = Math.max(6, Math.round((value / maxValue) * 100));
      return `<div class="fin-bar-row">
        <div class="fin-bar-head"><span>${escapeHtml(row[labelKey] || 'Nao informado')}</span><span>${(formatter || formatCurrency)(value)}</span></div>
        <div class="fin-bar-track"><div class="fin-bar-fill" style="width:${width}%; background:${color};"></div></div>
        ${metaRenderer ? `<div class="small text-muted">${metaRenderer(row)}</div>` : ''}
      </div>`;
    }).join('')}</div>`;
  }

  function buildFallbackStatus(summary, rows) {
    const base = safeArray(rows).filter((row) => Number(row.amount || 0) > 0);
    if (base.length) return base;
    return [
      { label: 'Recebido', amount: Number(summary.received || 0), titles: Number(summary.received_titles || 0) },
      { label: 'Em aberto', amount: Number(summary.open || 0), titles: Number(summary.open_titles || 0) },
      { label: 'Vencido', amount: Number(summary.overdue || 0), titles: Number(summary.overdue_titles || 0) }
    ].filter((row) => row.amount > 0);
  }
  function aggregateBy(rows, key, valueKey, limit) {
    const map = new Map();
    safeArray(rows).forEach((row) => {
      const label   = String(row?.[key] || '').trim() || 'Nao identificado';
      const current = map.get(label) || { label, amount: 0, titles: 0 };
      current.amount += Number(row?.[valueKey] || 0);
      current.titles += 1;
      map.set(label, current);
    });
    return Array.from(map.values()).sort((a, b) => b.amount - a.amount).slice(0, limit || 8);
  }
  function buildFallbackAging(rows) {
    const buckets = {
      '1-30 dias': { amount: 0, titles: 0, share: 0, min_days: 1, max_days: 30 },
      '31-60 dias': { amount: 0, titles: 0, share: 0, min_days: 31, max_days: 60 },
      '61-90 dias': { amount: 0, titles: 0, share: 0, min_days: 61, max_days: 90 },
      '91+ dias': { amount: 0, titles: 0, share: 0, min_days: 91, max_days: null }
    };
    let total = 0;
    safeArray(rows).forEach((row) => {
      const days = Number(row?.days_late || 0);
      const amount = Number(row?.amount_open || 0);
      if (amount <= 0 || days <= 0) return;
      total += amount;
      let bucket = '91+ dias';
      if (days <= 30) bucket = '1-30 dias';
      else if (days <= 60) bucket = '31-60 dias';
      else if (days <= 90) bucket = '61-90 dias';
      buckets[bucket].amount += amount;
      buckets[bucket].titles += 1;
    });
    return Object.entries(buckets).map(([bucket, meta]) => ({
      bucket,
      amount: meta.amount,
      titles: meta.titles,
      share: total > 0 ? (meta.amount / total) * 100 : 0,
      min_days: meta.min_days,
      max_days: meta.max_days,
    }));
  }

  function renderAgingChart(targetId, rows) {
    const target = document.getElementById(targetId);
    if (!target) return;
    const normalizedRows = safeArray(rows).map((row) => ({
      bucket: row.bucket,
      amount: Number(row.amount || 0),
      titles: Number(row.titles || 0),
      share: Number(row.share || 0),
      min_days: row.min_days ?? null,
      max_days: row.max_days ?? null,
    }));
    const total = normalizedRows.reduce((sum, row) => sum + row.amount, 0);
    if (total <= 0) { renderEmptyChart(targetId, 'Sem inadimplencia vencida suficiente para montar faixas.'); return; }

    const colors = {
      '1-30 dias': '#fcd277',
      '31-60 dias': '#f39c12',
      '61-90 dias': '#d04d4d',
      '91+ dias': '#9f2727'
    };

    target.innerHTML = `
      <div class="fin-aging-wrap">
        <div class="fin-aging-track">
          ${normalizedRows.filter(r => r.amount > 0).map(row => 
            `<div class="fin-aging-segment" style="width:${(row.amount/total)*100}%; background:${colors[row.bucket] || '#ccc'}" title="${row.bucket}: ${formatCurrency(row.amount)}">
              ${(row.amount/total)*100 > 8 ? formatPercent((row.amount/total)*100) : ''}
            </div>`
          ).join('')}
        </div>
        <div class="fin-aging-legend">
          ${normalizedRows.filter(r => r.amount > 0).map(row => 
            `<div class="fin-aging-card">
              <div class="fin-aging-card-head"><i class="fin-aging-dot" style="background:${colors[row.bucket] || '#ccc'}"></i>${escapeHtml(row.bucket)}</div>
              <div class="fin-aging-value">${formatCurrency(row.amount)}</div>
              <div class="fin-aging-meta"><span>${formatNumber(row.titles)} doc(s)</span><strong>${formatPercent((row.amount/total)*100)}</strong></div>
            </div>`
          ).join('')}
        </div>
      </div>
    `;
  }

  function buildVendorRows(data) {
    const source = safeArray(data.by_vendor).length
      ? safeArray(data.by_vendor)
      : aggregateBy(data.received_rows || [], 'vendor_name', 'amount_net', 8).map((row) => ({ vendor_name: row.label, received: row.amount, titles: row.titles }));
    const namedRows = source.filter((row) => String(row.vendor_name || '').trim() !== '');
    return namedRows.length ? namedRows : source;
  }

  // â”€â”€â”€ Chart.js charts (with drill-down onClick) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function createDoughnutChart(canvasId, rows, labelKey, valueKey, colors, formatter) {
    const dataRows = safeArray(rows).filter((row) => Number(row?.[valueKey] || 0) > 0);
    if (!dataRows.length) { renderEmptyCanvasChart(canvasId, 'Sem distribuicao disponivel no periodo.'); return; }
    clearCanvasEmptyState(canvasId);
    destroyChart(canvasId);
    const ctx = getCanvas(canvasId);
    if (!ctx) return;
    const chart = new window.Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: dataRows.map((row) => row[labelKey]),
        datasets: [{ data: dataRows.map((row) => Number(row[valueKey] || 0)), backgroundColor: colors, borderWidth: 0, hoverOffset: 8 }]
      },
      options: {
        responsive: true,
        resizeDelay: 80,
        maintainAspectRatio: false,
        animation: false,
        cutout: '66%',
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label(ctx) { return `${ctx.label}: ${(formatter || formatCurrency)(ctx.parsed || 0)}`; } } }
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const row = dataRows[elements[0].index];
          if (row) {
            const lbl = canvasId === 'financeAccountsChart' ? 'Conta' : 'Vendedor';
            setDrillFilter(labelKey, row[labelKey], `${lbl}: ${row[labelKey]}`);
          }
        }
      }
    });
    registerChart(canvasId, chart);
  }

  function createHorizontalBarChart(canvasId, rows, labelKey, valueKey, datasetLabel, color, options) {
    const dataRows = safeArray(rows).filter((row) => Number(row?.[valueKey] || 0) > 0);
    if (!dataRows.length) { renderEmptyCanvasChart(canvasId, 'Sem dados suficientes para comparacao no periodo.'); return; }
    clearCanvasEmptyState(canvasId);
    destroyChart(canvasId);
    const ctx = getCanvas(canvasId);
    if (!ctx) return;
    const chart = new window.Chart(ctx, {
      type: 'bar',
      data: {
        labels: dataRows.map((row) => row[labelKey]),
        datasets: [{
          label: datasetLabel,
          data: dataRows.map((row) => Number(row[valueKey] || 0)),
          backgroundColor: color,
          borderRadius: 10,
          borderSkipped: false,
          barThickness: 'flex',
          maxBarThickness: 24,
          categoryPercentage: 0.8,
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        resizeDelay: 80,
        maintainAspectRatio: false,
        animation: false,
        normalized: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label(ctx) { return `${datasetLabel}: ${formatCurrency(ctx.parsed?.x ?? ctx.parsed ?? 0)}`; },
              afterLabel(ctx) {
                const row = dataRows[ctx.dataIndex];
                const metaLines = [];
                if (row?.titles != null) metaLines.push(`Titulos: ${formatNumber(row.titles)}`);
                if (row?.avg_ticket != null) metaLines.push(`Ticket medio: ${formatCurrency(row.avg_ticket)}`);
                return metaLines;
              }
            }
          }
        },
        scales: {
          x: { beginAtZero: true, ticks: { callback(v) { return axisMoneyTick(v); }, color: chartTheme().text }, grid: { color: chartTheme().grid } },
          y: { grid: { display: false } }
        },
        onClick: (event, elements) => {
          if (!elements.length || !options?.drillLabel) return;
          const row = dataRows[elements[0].index];
          if (row) setDrillFilter(labelKey, row[labelKey], `${options.drillLabel}: ${row[labelKey]}`);
        }
      }
    });
    registerChart(canvasId, chart);
  }

  function createStackedForecastChart(rows) {
    const dataRows = safeArray(rows).filter((row) => Number(row.realized || 0) > 0 || Number(row.open || 0) > 0 || Number(row.overdue || 0) > 0 || Number(row.planned || 0) > 0);
    if (!dataRows.length) { renderEmptyCanvasChart('financeForecastChart', 'Sem dados de previsto x realizado no periodo.'); return; }
    clearCanvasEmptyState('financeForecastChart');
    destroyChart('financeForecastChart');
    const ctx = getCanvas('financeForecastChart');
    if (!ctx) return;
    const labels = dataRows.map((row) => (row.year_month?.slice(5, 7) || '') + '/' + (row.year_month?.slice(0, 4) || ''));
    const chart = new window.Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'Realizado', data: dataRows.map((r) => Number(r.realized || 0)), backgroundColor: chartPalette.emerald, borderRadius: 8, stack: 'amounts' },
          { label: 'Em aberto', data: dataRows.map((r) => Number(r.open || 0)), backgroundColor: chartPalette.gold, borderRadius: 8, stack: 'amounts' },
          { label: 'Vencido', data: dataRows.map((r) => Number(r.overdue || 0)), backgroundColor: chartPalette.red, borderRadius: 8, stack: 'amounts' },
          { label: 'Previsto', type: 'line', data: dataRows.map((r) => Number(r.planned || 0)), borderColor: chartPalette.navy, backgroundColor: chartPalette.navy, tension: 0.25, borderWidth: 2, pointRadius: 3, yAxisID: 'y' }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 80,
        maintainAspectRatio: false,
        animation: false,
        normalized: true,
        plugins: { tooltip: commonTooltip(), legend: { position: 'bottom' } },
        scales: {
          x: { stacked: true, grid: { display: false } },
          y: { stacked: true, beginAtZero: true, ticks: { callback(v) { return axisMoneyTick(v); }, color: chartTheme().text }, grid: { color: chartTheme().grid } }
        }
      }
    });
    registerChart('financeForecastChart', chart);
  }

  // â”€â”€â”€ Meta / KPI rendering â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function updateMeta(data) {
    const meta = data.meta || {};
    const syncTime = meta.freshness_at || meta.last_sync || meta.generated_at;
    setText('[data-bind="period-label"]', data.period?.label || 'â€”');
    setText('[data-bind="last-sync"]', syncTime ? new Date(syncTime).toLocaleString('pt-BR') : 'â€”');
    setHtml('[data-bind="summary-mode"]', renderStatusBadge(data.mode || ''));
    setText('[data-bind="mode-pill"]', renderModePill(data.mode || ''));
    const periodBasis = meta.period_basis === 'issue_date'
      ? 'Base fiscal: emissao'
      : (meta.period_basis === 'billing_date'
        ? 'Base comercial: faturamento'
        : 'Base financeira: liquidacao');
    setText('[data-bind="summary-period-basis"]', periodBasis);
    setText('[data-bind="sync-source"]', humanizeServedFrom(meta.served_from || data.source || ''));
    setText('[data-bind="sync-coverage"]', resolveCoverageLabel(data));
    setText('[data-bind="sync-reconciled-until"]', formatMetaDate(meta.reconciled_until || data.reconciled_until || ''));
    setHtml('[data-bind="sync-warning"]', renderSyncStateBadges(data) || '<span class="mag-status-chip mag-status-live">Leitura principal</span>');
  }
  function renderWarnings(warnings, notes) {
    const target = document.getElementById('financeWarnings');
    if (!target) return;
    const items  = [];
    (warnings || []).forEach((w) => items.push(`<li>${escapeHtml(w)}</li>`));
    (notes    || []).forEach((n) => items.push(`<li>${escapeHtml(n)}</li>`));
    target.innerHTML = items.length ? `<div class="alert alert-warning mb-0 fin-alert"><strong>Notas do financeiro:</strong><ul class="mb-0 mt-2">${items.join('')}</ul></div>` : '';
  }
  function renderKpiCard(meta) {
    const progressEl = document.querySelector(meta.progressSelector);
    const detailEl   = document.querySelector(meta.detailSelector);
    const helperEl   = document.querySelector(meta.helperSelector);
    if (progressEl) progressEl.style.width = `${Math.max(4, Math.min(100, Number(meta.progress || 0)))}%`;
    if (detailEl)   detailEl.textContent   = meta.detail  || 'â€”';
    if (helperEl)   helperEl.textContent   = meta.helper  || '';
  }
  function renderSummary(summary, mode) {
    const prev = summary.prev || {};
    setText('#finReceived',          formatCurrency(summary.received));
    setText('#finOpen',              formatCurrency(summary.open));
    setText('#finOverdue',           formatCurrency(summary.overdue));
    setText('#finDelinquency',       formatPercent(summary.delinquency_rate));
    setText('#finPlanned',           formatCurrency(summary.planned));
    setText('#finRealizedVsPlanned', formatPercent(summary.realized_vs_planned));
    setHtml('[data-bind="summary-mode"]', renderStatusBadge(mode));
    // Trend arrows (only shown when prev-period data is available)
    setHtml('#finReceivedArrow', trendArrow(summary.received, prev.received));
    setHtml('#finOpenArrow',     trendArrow(summary.open,     prev.open));
    setHtml('#finOverdueArrow',  trendArrow(summary.overdue,  prev.overdue));
    renderKpiCard({ progressSelector: '#finReceivedProgress',  detailSelector: '#finReceivedDetail',  helperSelector: '#finReceivedHelper',  progress: summary.realized_vs_planned, detail: `${formatNumber(summary.received_titles)} titulo(s) baixados`, helper: `Ticket medio ${formatCurrency(summary.avg_received_ticket)}` });
    renderKpiCard({ progressSelector: '#finOpenProgress',      detailSelector: '#finOpenDetail',      helperSelector: '#finOpenHelper',      progress: summary.planned ? (summary.open / summary.planned) * 100 : 0, detail: `${formatNumber(summary.open_titles)} titulo(s) pendentes`, helper: 'Carteira ainda nao liquidada' });
    renderKpiCard({ progressSelector: '#finOverdueProgress',   detailSelector: '#finOverdueDetail',   helperSelector: '#finOverdueHelper',   progress: summary.open ? (summary.overdue / summary.open) * 100 : 0, detail: `${formatNumber(summary.overdue_titles)} titulo(s) em atraso`, helper: 'Atrasos ja vencidos' });
    renderKpiCard({ progressSelector: '#finDelinquencyProgress', detailSelector: '#finDelinquencyDetail', helperSelector: '#finDelinquencyHelper', progress: summary.delinquency_rate, detail: `${formatNumber(summary.accounts)} conta(s) com movimento`, helper: 'Indice sobre os titulos em aberto' });
    renderKpiCard({ progressSelector: '#finPlannedProgress',   detailSelector: '#finPlannedDetail',   helperSelector: '#finPlannedHelper',   progress: 100, detail: summary.planned_label || 'Carteira do periodo', helper: `${formatNumber(summary.vendors)} vendedor(es) no financeiro` });
    renderKpiCard({ progressSelector: '#finCoverageProgress',  detailSelector: '#finCoverageDetail',  helperSelector: '#finCoverageHelper',  progress: summary.realized_vs_planned, detail: `Realizado ${formatCurrency(summary.budget_realized || summary.received)}`, helper: 'Cobertura da carteira do periodo' });
  }
  function renderHero(summary, statusBreakdown) {
    const outstanding = Number(summary.open || 0) + Number(summary.overdue || 0);
    setText('#heroPrimaryValue',     formatCurrency(summary.received));
    setText('#heroSecondaryValue',   formatCurrency(summary.planned));
    setText('#heroOutstandingValue', formatCurrency(outstanding));
    const list = document.getElementById('heroPortfolioList');
    if (list) list.innerHTML = (statusBreakdown || []).map((row) => `<li><span>${escapeHtml(row.label)}</span><strong>${formatCurrency(row.amount)}</strong></li>`).join('');
  }
  function renderInsights(data) {
    const receivedTop = (data.received_rows || [])[0];
    const clientTop   = (data.by_client    || [])[0];
    setText('#insightReceivedDoc',   receivedTop ? `${receivedTop.document_type} â€¢ ${receivedTop.document_label}` : 'Sem destaque');
    setText('#insightReceivedValue', receivedTop ? formatCurrency(receivedTop.amount_net) : 'â€”');
    setText('#insightClientName',    clientTop   ? clientTop.client_name : 'Sem inadimplencia relevante');
    setText('#insightClientValue',   clientTop   ? formatCurrency(clientTop.overdue) : 'â€”');
    setText('#insightClientLate',    clientTop   ? `${formatNumber(clientTop.max_days_late)} dia(s) de maior atraso` : 'â€”');
  }

  function renderCharts(data) {
    const trendRows = safeArray(data.trend).length ? safeArray(data.trend)
      : safeArray(data.budget).map((row) => ({
          key: row.year_month,
          label: row.year_month ? `${String(row.year_month).slice(5, 7)}/${String(row.year_month).slice(0, 4)}` : 'â€”',
          received: Number(row.realized || 0),
          planned: Number(row.planned || 0),
          overdue: Number(row.overdue || 0),
          open: Number(row.open || 0)
        }));
    const statusRows = buildFallbackStatus(data.summary || {}, data.status_breakdown || []);
    const paymentRows = safeArray(data.payment_methods).length ? safeArray(data.payment_methods)
      : aggregateBy(data.received_rows || [], 'payment_method', 'amount_net', 6).map((r) => ({ payment_method: r.label, received: r.amount, titles: r.titles }));
    const documentRows = safeArray(data.document_types).length ? safeArray(data.document_types)
      : aggregateBy(
          [...(data.received_rows || []).map((r) => ({ ...r, chart_amount: Number(r.amount_net || 0) })),
           ...(data.open_titles || []).map((r) => ({ ...r, chart_amount: Number(r.amount_open || 0) }))],
          'document_type', 'chart_amount', 6
        ).map((r) => ({ document_type: r.label, amount: r.amount, titles: r.titles }));
    const agingRows = safeArray(data.aging).length ? safeArray(data.aging) : buildFallbackAging(data.overdue_titles || data.open_titles || []);
    const vendorRows = buildVendorRows(data).slice(0, 8);
    
    // Calcula vendedores ofensores de inadimplencia para o novo ring chart
    const overdueTitles = safeArray(data.open_titles).filter(r => Number(r.days_late || 0) > 0);
    const vendorOverdue = aggregateBy(overdueTitles, 'vendor_name', 'amount_open', 5)
      .map(r => ({ vendor_name: r.label, amount: r.amount, titles: r.titles }));

    const rangeContext = data.meta?.range || { points: data.summary?.range_points || trendRows.length, granularity: data.summary?.range_granularity || 'month' };
    renderTrendHtml('financeTrendChart', trendRows, rangeContext);
    renderRingChart('financeStatusChart', statusRows, 'label', 'amount', [chartPalette.emerald, chartPalette.gold, chartPalette.red], 'Total', (r) => `${formatNumber(r.titles || 0)} titulo(s)`);
    renderRingChart('financePaymentChart', paymentRows, 'payment_method', 'received', [chartPalette.emerald, chartPalette.teal, chartPalette.gold, chartPalette.navy, chartPalette.lime, chartPalette.gray], 'Recebido', (r) => `${formatNumber(r.titles || 0)} ocorrencia(s)`);
    renderRingChart('financeVendorOverdueChart', vendorOverdue, 'vendor_name', 'amount', [chartPalette.red, '#dc6f6f', '#e69f9f', '#efc9c9', '#f9eaea'], 'Vencido', (r) => `${formatNumber(r.titles || 0)} titulo(s)`);
    renderAgingChart('financeAgingChart', agingRows);
    createStackedForecastChart((data.budget || []).slice(-12));
    createHorizontalBarChart('financeVendorsChart', vendorRows, 'vendor_name', 'received', 'Recebido', chartPalette.emerald, { drillLabel: 'Vendedor' });
    createDoughnutChart('financeAccountsChart', (data.by_account || []).slice(0, 6), 'account_name', 'received', [chartPalette.emerald, chartPalette.emeraldSoft, chartPalette.teal, chartPalette.navy, chartPalette.lime, chartPalette.gold]);
  }

  // â”€â”€â”€ Column definitions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function getReceivedColumns() {
    return [
      { key: 'document',   label: 'Documento',   render: (r) => `<div class="fin-doc-cell"><span class="fin-badge fin-badge-green">${escapeHtml(r.document_type || 'Titulo')}</span><strong>${escapeHtml(r.document_label || 'Sem documento')}</strong><small>${escapeHtml(r.status || 'Baixado')}</small></div>` },
      { key: 'client',     label: 'Cliente',     render: (r) => `<div class="fin-doc-cell"><strong>${escapeHtml(r.client_name || 'Cliente nao informado')}</strong><small>Vendedor: ${escapeHtml(r.vendor_name || 'Sem vendedor')}</small></div>` },
      { key: 'account',    label: 'Conta / meio',render: (r) => `<div class="fin-doc-cell"><strong>${escapeHtml(r.account_name || 'Conta nao identificada')}</strong><small>${escapeHtml(r.payment_method || 'Nao identificado')}</small></div>` },
      { key: 'paid_date',  label: 'Pagamento',   render: (r) => formatDateBR(r.paid_date) },
      { key: 'amount_net', label: 'Valor',        render: (r) => `<strong>${formatCurrency(r.amount_net)}</strong>` }
    ];
  }
  function getOpenColumns() {
    return [
      { key: 'document',    label: 'Documento',        render: (r) => `<div class="fin-doc-cell"><span class="fin-badge fin-badge-gold">${escapeHtml(r.document_type || 'Titulo')}</span><strong>${escapeHtml(r.document_label || 'Sem documento')}</strong><small>${escapeHtml(r.payment_method || 'Nao identificado')}</small></div>` },
      { key: 'client',      label: 'Cliente',          render: (r) => `<div class="fin-doc-cell"><strong>${escapeHtml(r.client_name || 'Cliente nao informado')}</strong>${r.client_document ? `<small>${escapeHtml(r.client_document)}</small>` : ''}</div>` },
      { key: 'due_date',    label: 'Vencimento',       render: (r) => formatDateBR(r.due_date) },
      { key: 'vendor',      label: 'Vendedor / conta', render: (r) => `<div class="fin-doc-cell"><strong>${escapeHtml(r.vendor_name || 'Sem vendedor')}</strong><small>${escapeHtml(r.account_name || 'Conta nao identificada')}</small></div>` },
      { key: 'amount_open', label: 'Aberto',           render: (r) => `<strong>${formatCurrency(r.amount_open)}</strong> ${statusBadge(r.days_late)}` }
    ];
  }
  function getClientsColumns() {
    return [
      { key: 'client',      label: 'Cliente / CNPJ',  render: (r) => `<div class="fin-doc-cell"><strong>${escapeHtml(r.client_name || 'Cliente nao informado')}</strong>${r.client_document ? `<small>${escapeHtml(r.client_document)}</small>` : '<small>Documento nao informado</small>'}</div>` },
      { key: 'titles',      label: 'Titulos',         render: (r) => formatNumber(r.titles) },
      { key: 'overdue',     label: 'Vencido',         render: (r) => `<strong>${formatCurrency(r.overdue)}</strong>` },
      { key: 'max_days',    label: 'Maior atraso',    render: (r) => statusBadge(r.max_days_late) },
      { key: 'oldest_due',  label: 'Venc. mais ant.', render: (r) => formatDateBR(r.oldest_due_date) },
      { key: 'profile',     label: 'Perfil / causa',  render: (r) => `<div class="fin-client-meta">${[r.cause_label, r.dominant_document_type, r.dominant_payment_method, r.dominant_status].filter(Boolean).map((v) => `<span class="fin-tag">${escapeHtml(v)}</span>`).join('')}</div>` },
      { key: 'documents',   label: 'Documentos',      render: (r) => (r.documents || []).length ? `<div class="fin-tag-list">${r.documents.map((d) => `<span class="fin-tag">${escapeHtml(d)}</span>`).join('')}</div>` : '<span class="text-muted">â€”</span>' }
    ];
  }
  function getBudgetColumns() {
    return [
      { key: 'month',    label: 'Mes',      render: (r) => r.year_month ? `${r.year_month.slice(5, 7)}/${r.year_month.slice(0, 4)}` : 'â€”' },
      { key: 'planned',  label: 'Previsto', render: (r) => `<strong>${formatCurrency(r.planned)}</strong><div class="small text-muted">Carteira prevista</div>` },
      { key: 'realized', label: 'Realizado',render: (r) => `<strong>${formatCurrency(r.realized)}</strong><div class="small text-muted">Pago / baixado</div>` },
      { key: 'open',     label: 'Aberto',   render: (r) => `<strong>${formatCurrency(r.open)}</strong><div class="small text-muted">Ainda em aberto</div>` },
      { key: 'overdue',  label: 'Vencido',  render: (r) => `<strong>${formatCurrency(r.overdue)}</strong><div class="small text-muted">Ja vencido</div>` },
      { key: 'coverage', label: 'Cobertura',render: (r) => `<span class="fin-badge fin-badge-${Number(r.coverage_rate || 0) >= 80 ? 'green' : Number(r.coverage_rate || 0) >= 50 ? 'gold' : 'red'}">${formatPercent(r.coverage_rate)}</span>` }
    ];
  }

  // â”€â”€â”€ renderTables (applies drill filter + all features) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function renderTables(data) {
    const rCols = getReceivedColumns();
    const oCols = getOpenColumns();
    const cCols = getClientsColumns();
    const bCols = getBudgetColumns();
    loadColVisible('financeReceivedTable', rCols);
    loadColVisible('financeOpenTable',     oCols);
    loadColVisible('financeClientsTable',  cCols);
    loadColVisible('financeBudgetTable',   bCols);

    const received = applyDrillRows(data.received_rows || []);
    const open     = applyDrillRows(data.open_titles   || []);
    const clients  = applyDrillRows(data.by_client     || []);
    const budget   = data.budget                        || [];

    renderTable('financeReceivedTable', rCols, received, 'Nenhum recebimento encontrado para o periodo.');
    renderTable('financeOpenTable',     oCols, open,     'Nenhum titulo em aberto no periodo.');
    renderTable('financeClientsTable',  cCols, clients,  'Nenhum cliente inadimplente encontrado no periodo.');
    renderTable('financeBudgetTable',   bCols, budget,   'Sem dados de carteira do periodo para previsto x realizado.');

    renderColPicker('financeReceivedTable', rCols);
    renderColPicker('financeOpenTable',     oCols);
    renderColPicker('financeClientsTable',  cCols);
    renderColPicker('financeBudgetTable',   bCols);
  }

  // â”€â”€â”€ Loading / error UI â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function toggleLoading(isLoading, message) {
    const loader = document.getElementById('pageLoading');
    if (!loader) return;
    if (isLoading) {
      _loadingStartedAt = performance.now();
    }
    loader.classList.toggle('d-none', !isLoading);
    const label = loader.querySelector('[data-loading-label]');
    if (label) {
      label.textContent = message || 'Carregando financeiro realizado...';
    } else if (!loader.classList.contains('module-loading-shell')) {
      loader.textContent = message || 'Carregando financeiro realizado...';
    }
  }
  function renderError(message) {
    const target = document.getElementById('pageError');
    if (!target) return;
    if (!message) { target.classList.add('d-none'); target.innerHTML = ''; return; }
    target.classList.remove('d-none');
    target.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(message)}</div>`;
  }

  // â”€â”€â”€ Data loading â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
      renderWarnings(data.meta?.warnings || [], data.meta?.notes || []);
      renderCharts(data);
      renderTables(data);
    } catch (error) {
      if (error?.name === 'AbortError') return;
      renderError(error.message || 'Falha ao carregar o modulo financeiro.');
    } finally {
      const elapsed = performance.now() - _loadingStartedAt;
      if (elapsed < LOADING_MIN_MS) {
        await waitFor(LOADING_MIN_MS - elapsed);
      }
      toggleLoading(false);
    }
  }
  async function syncNow(event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const feedback = document.getElementById('syncFeedback');
    const csrfToken = document.querySelector('meta[name="biomie-csrf"]')?.getAttribute('content') || '';
    const body = new URLSearchParams();
    if (csrfToken) body.set('csrf_token', csrfToken);
    if (feedback) feedback.innerHTML = '<div class="alert alert-secondary fin-alert">Sincronizando modulo financeiro...</div>';
    try {
      const resp = await fetch(buildApiUrl('api/finance_sync.php', true), {
        method: 'POST',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-CSRF-Token': csrfToken,
          'X-Requested-With': 'fetch'
        },
        body: body.toString()
      });
      if (resp.redirected) throw new Error('Sessao expirada. Recarregue a pagina e faca login novamente.');
      const ct = resp.headers.get('content-type') || '';
      const data = ct.includes('application/json')
        ? await resp.json()
        : { ok: false, message: 'Resposta inesperada do servidor.' };
      if (!resp.ok || data.ok === false) throw new Error(data.message || 'Falha na sincronizacao.');
      if (feedback) feedback.innerHTML = `<div class="alert alert-success fin-alert">${escapeHtml(data.message)}</div>`;
      await loadFinance(true);
    } catch (error) {
      if (feedback) feedback.innerHTML = `<div class="alert alert-danger fin-alert">${escapeHtml(error.message)}</div>`;
    }
  }

  // â”€â”€â”€ Initialisation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
  function initMenuToggle() {
    document.getElementById('menu_toggle')?.addEventListener('click', (e) => {
      e.preventDefault();
      document.body.classList.toggle('nav-md');
      document.body.classList.toggle('nav-sm');
    });
  }
  function highlightCurrentMenu() {
    const current = window.location.pathname.split('/').pop() || 'financeiro.html';
    document.querySelectorAll('#sidebar-menu a').forEach((link) => {
      if (link.getAttribute('href') === current) link.parentElement?.classList.add('current-page');
    });
  }
  function bindFilters() {
    document.querySelectorAll('[data-range-preset]').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyPresetRange(btn.getAttribute('data-range-preset'));
        loadFinance(false);
      });
    });
    document.getElementById('applyFilters')?.addEventListener('click', () => loadFinance(false));
    document.getElementById('syncNow')?.addEventListener('click', syncNow);
  }
  function bindBulkBar() {
    document.getElementById('finBulkExport')?.addEventListener('click', exportSelectedCsv);
    document.getElementById('finBulkClear')?.addEventListener('click', clearAllSelections);
  }
  function bindGroupButtons() {
    document.querySelectorAll('[data-group-toggle]').forEach((btn) => {
      const tableId  = btn.dataset.groupToggle;
      const groupKey = btn.dataset.groupKey;
      btn.addEventListener('click', () => {
        const isActive = _groupBy[tableId] === groupKey;
        _groupBy[tableId] = isActive ? null : groupKey;
        btn.classList.toggle('active', !isActive);
        if (_lastData) renderTables(_lastData);
      });
    });
  }
  // Close col pickers when clicking outside
  function bindGlobalClose() {
    document.addEventListener('click', (e) => {
      if (!e.target.closest('.fin-col-picker-wrap')) {
        document.querySelectorAll('.fin-col-picker.open').forEach((el) => el.classList.remove('open'));
      }
    });
  }

  function init() {
    applyPresetRange('month');
    initMenuToggle();
    highlightCurrentMenu();
    bindFilters();
    bindBulkBar();
    bindGroupButtons();
    bindGlobalClose();
    loadFinance(false);
  }

  document.addEventListener('DOMContentLoaded', init);
})();




