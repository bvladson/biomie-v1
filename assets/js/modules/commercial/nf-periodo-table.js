/**
 * assets/js/modules/commercial/nf-periodo-table.js — COM.R3 Fase 5
 *
 * Módulo de tabela paginada server-side para NFs do período.
 * Integra com api/nf_periodo_table.php (DataTables-like response).
 *
 * Uso:
 *   import { NfPeriodoTable } from './modules/commercial/nf-periodo-table.js';
 *   NfPeriodoTable.init('#nf-table-container', { start, end, scope });
 */

export const NfPeriodoTable = (() => {
  const PAGE_SIZE = 25;
  const MAX_PAGE_SIZE = 200;

  let _state = {
    start: '',
    end: '',
    scope: 'commercial',
    vendedor: '',
    cliente: '',
    search: '',
    orderCol: 'data_ref',
    orderDir: 'desc',
    draw: 0,
    page: 0,
    length: PAGE_SIZE,
    totalRecords: 0,
    filteredRecords: 0,
    loading: false,
    containerId: '',
    hasFrete: false,
    semPedido: false,
    hasOutras: false,
    hasSeguro: false,
    comDesconto: false,
  };

  let _abortController = null;

  // ── Init ──────────────────────────────────────────────────────────────────

  function init(containerId, params = {}) {
    _state = {
      ..._state,
      containerId,
      start:    params.start    || window.biomieStartDate || '',
      end:      params.end      || window.biomieEndDate   || '',
      scope:    params.scope    || 'commercial',
      vendedor: params.vendedor || '',
      cliente:  params.cliente  || '',
      page:     0,
    };

    _render();
    _fetch();
  }

  // ── Render shell ──────────────────────────────────────────────────────────

  function _render() {
    const el = document.querySelector(_state.containerId);
    if (!el) return;

    el.innerHTML = `
      <div class="nf-table-wrapper" style="display:flex;flex-direction:column;height:100%;">
        <div class="nf-table-controls" style="flex-shrink:0;padding:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <input type="search" id="nfPeriodoSearch" placeholder="Buscar NF, cliente, vendedor..."
                 class="filter-input" style="flex:1;min-width:200px;max-width:320px;"
                 aria-label="Buscar NFs" />
          <select id="nfPeriodoScope" class="filter-select" aria-label="Escopo" style="min-width:120px;">
            <option value="commercial">Orç-PD</option>
            <option value="remittance">Remessa</option>
            <option value="licitacao">Licitação</option>
            <option value="all">Todas</option>
          </select>
          <select id="nfPeriodoLength" class="filter-select" aria-label="Linhas por página">
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
          <span id="nfPeriodoStatus" style="font-size:0.78rem;color:var(--text-muted,#888);"></span>
        </div>

        <div class="nf-table-summary-bar" id="nfPeriodoSummary"
             style="flex-shrink:0;padding:4px 0;font-size:0.82rem;color:var(--text-muted,#888);"></div>

        <div class="nf-table-scroll"
             style="flex:1;overflow-y:auto;overflow-x:auto;min-height:200px;max-height:480px;border:1px solid var(--border-color,#e2e2e2);border-radius:4px;">
          <table class="biomie-table nf-periodo-table" style="width:100%;table-layout:fixed;">
            <thead>
              <tr>
                <th class="sortable" data-col="numero_nf" style="width:90px;">NF</th>
                <th style="width:80px;">Tipo</th>
                <th class="sortable" data-col="nome_cliente">Cliente</th>
                <th class="sortable" data-col="nome_vendedor" style="width:140px;">Vendedor</th>
                <th class="sortable" data-col="data_ref" style="width:80px;">Data</th>
                <th class="sortable text-right" data-col="base_comercial" style="width:100px;">Base Com.</th>
                <th style="width:80px;">Pedido</th>
                <!-- GEO.R1.9.1 — Ver no mapa -->
                <th style="width:28px;" title="Ver no mapa"></th>
              </tr>
            </thead>
            <tbody id="nfPeriodoBody">
              <tr><td colspan="8" class="empty-state">Carregando...</td></tr>
            </tbody>
          </table>
        </div>

        <div class="nf-table-pagination" id="nfPeriodoPagination"
             style="flex-shrink:0;display:flex;align-items:center;justify-content:space-between;padding:8px 0;gap:8px;">
        </div>
      </div>
    `;

    _bindEvents();
  }

  // ── Bind events ───────────────────────────────────────────────────────────

  function _bindEvents() {
    const el = document.querySelector(_state.containerId);
    if (!el) return;

    // Search
    const searchInput = el.querySelector('#nfPeriodoSearch');
    if (searchInput) {
      let searchTimer;
      searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
          _state.search = searchInput.value.trim();
          _state.page   = 0;
          _fetch();
        }, 350);
      });
    }

    // Scope selector
    const scopeSel = el.querySelector('#nfPeriodoScope');
    if (scopeSel) {
      scopeSel.value = _state.scope;
      scopeSel.addEventListener('change', () => {
        _state.scope = scopeSel.value;
        _state.page  = 0;
        _fetch();
      });
    }

    // Page size
    const lenSel = el.querySelector('#nfPeriodoLength');
    if (lenSel) {
      lenSel.addEventListener('change', () => {
        _state.length = Math.min(MAX_PAGE_SIZE, parseInt(lenSel.value, 10) || PAGE_SIZE);
        _state.page   = 0;
        _fetch();
      });
    }

    // Sortable columns
    el.querySelectorAll('th.sortable').forEach(th => {
      th.style.cursor = 'pointer';
      th.addEventListener('click', () => {
        const col = th.dataset.col;
        if (_state.orderCol === col) {
          _state.orderDir = _state.orderDir === 'asc' ? 'desc' : 'asc';
        } else {
          _state.orderCol = col;
          _state.orderDir = 'desc';
        }
        _state.page = 0;
        _fetch();
      });
    });
  }

  // ── Fetch ─────────────────────────────────────────────────────────────────

  async function _fetch() {
    if (_state.loading) {
      _abortController?.abort();
    }

    _state.loading = true;
    _state.draw++;
    _setState('loading');

    const params = new URLSearchParams({
      start:     _state.start,
      end:       _state.end,
      draw:      String(_state.draw),
      offset:    String(_state.page * _state.length),
      length:    String(_state.length),
      scope:     _state.scope,
      order_col: _state.orderCol,
      order_dir: _state.orderDir,
    });

    if (_state.search)    params.set('search',   _state.search);
    if (_state.vendedor)  params.set('vendedor',  _state.vendedor);
    if (_state.cliente)   params.set('cliente',   _state.cliente);
    if (_state.hasFrete)   params.set('has_frete',    '1');
    if (_state.semPedido)  params.set('sem_pedido',   '1');
    if (_state.hasOutras)  params.set('has_outras',   '1');
    if (_state.hasSeguro)  params.set('has_seguro',   '1');
    if (_state.comDesconto) params.set('com_desconto', '1');

    _abortController = new AbortController();

    try {
      const resp = await fetch('/biomie/api/nf_periodo_table.php?' + params.toString(), {
        signal: _abortController.signal,
      });

      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);

      const json = await resp.json();

      if (json.draw < _state.draw) return; // stale response

      _state.totalRecords    = json.recordsTotal    || 0;
      _state.filteredRecords = json.recordsFiltered || 0;
      _state.loading = false;

      _renderRows(json.data || []);
      _renderPagination();
      _renderSummary(json.summary || {});
      _setState('done');
    } catch (err) {
      if (err.name === 'AbortError') return;
      _state.loading = false;
      _setState('error', err.message);
    }
  }

  // ── Render rows ───────────────────────────────────────────────────────────

  function _renderRows(rows) {
    const tbody = document.getElementById('nfPeriodoBody');
    if (!tbody) return;

    if (rows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="empty-state">Nenhuma NF encontrada no período.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(row => {
      const nfLink = row.nota_key
        ? `<a href="#" class="nf-link" data-nota-key="${_esc(row.nota_key)}" data-biomie-action="open-invoice" title="${_esc(row.nota_key)}">${_esc(row.numero_nf || row.nota_key)}</a>`
        : _esc(row.numero_nf || '-');

      const pedidoCell = row.pedido_ref
        ? `<span class="pedido-ref">${_esc(row.pedido_ref)}</span>`
        : '<span class="text-muted">—</span>';

      const brl = _fmtBrl(row.base_comercial || 0);
      const scopeBadge = row.scope === 'remittance'
        ? '<span class="scope-chip scope-chip--remessa">REM</span>'
        : '<span class="scope-chip scope-chip--orc-pd">ORC</span>';

      // GEO.R1.9.1 — link "Ver no mapa" com mesmo período
      const startParam  = encodeURIComponent(_state.start || '');
      const endParam    = encodeURIComponent(_state.end   || '');
      const mapLink = `<a href="mapa_vendas.php?layer=mapa_composto_ceara&date_from=${startParam}&date_to=${endParam}" target="_blank" title="Ver território no mapa" style="font-size:10px;color:var(--mag-accent-1,#96140a);white-space:nowrap;text-decoration:none;" tabindex="-1">🗺</a>`;

      return `<tr>
        <td>${nfLink}</td>
        <td>${scopeBadge}</td>
        <td class="truncate" title="${_esc(row.nome_cliente || '')}">${_esc(row.nome_cliente || '—')}</td>
        <td class="truncate" title="${_esc(row.nome_vendedor || '')}">${_esc(row.nome_vendedor || '—')}</td>
        <td>${_esc(row.data_ref || '')}</td>
        <td class="text-right font-mono">${brl}</td>
        <td>${pedidoCell}</td>
        <td style="text-align:center;width:28px;">${mapLink}</td>
      </tr>`;
    }).join('');
  }

  // ── Render pagination ─────────────────────────────────────────────────────

  function _renderPagination() {
    const el = document.getElementById('nfPeriodoPagination');
    if (!el) return;

    const totalPages = Math.max(1, Math.ceil(_state.filteredRecords / _state.length));
    const from = _state.page * _state.length + 1;
    const to   = Math.min((_state.page + 1) * _state.length, _state.filteredRecords);

    const prevDisabled = _state.page === 0 ? 'disabled' : '';
    const nextDisabled = _state.page >= totalPages - 1 ? 'disabled' : '';

    el.innerHTML = `
      <span style="font-size:0.8rem;color:var(--text-muted,#888);">
        ${from}–${to} de ${_state.filteredRecords.toLocaleString('pt-BR')}
        ${_state.filteredRecords < _state.totalRecords ? ` (total ${_state.totalRecords.toLocaleString('pt-BR')})` : ''}
      </span>
      <div style="display:flex;gap:4px;">
        <button class="btn-icon" id="nfPrevPage" ${prevDisabled} title="Página anterior" aria-label="Página anterior">◀</button>
        <span style="font-size:0.8rem;line-height:2rem;">Pág ${_state.page + 1}/${totalPages}</span>
        <button class="btn-icon" id="nfNextPage" ${nextDisabled} title="Próxima página" aria-label="Próxima página">▶</button>
      </div>
    `;

    el.querySelector('#nfPrevPage')?.addEventListener('click', () => {
      if (_state.page > 0) { _state.page--; _fetch(); }
    });
    el.querySelector('#nfNextPage')?.addEventListener('click', () => {
      if (_state.page < totalPages - 1) { _state.page++; _fetch(); }
    });
  }

  // ── Render summary bar ────────────────────────────────────────────────────

  function _renderSummary(summary) {
    const el = document.getElementById('nfPeriodoSummary');
    if (!el) return;

    const total  = _fmtBrl(summary.base_comercial_total || 0);
    const nfs    = (summary.nfs_filtered || 0).toLocaleString('pt-BR');
    const clis   = (summary.clientes_filtered || 0).toLocaleString('pt-BR');
    el.textContent = `${nfs} NFs · ${total} base comercial · ${clis} clientes`;
  }

  // ── State indicator ───────────────────────────────────────────────────────

  function _setState(state, msg = '') {
    const statusEl = document.getElementById('nfPeriodoStatus');
    if (!statusEl) return;
    statusEl.textContent = state === 'loading' ? 'Carregando...'
      : state === 'error' ? `Erro: ${msg}`
      : '';
  }

  // ── Utils ─────────────────────────────────────────────────────────────────

  function _esc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function _fmtBrl(val) {
    const n = parseFloat(val) || 0;
    if (n >= 1e6)  return 'R$' + (n / 1e6).toFixed(2).replace('.', ',') + 'M';
    if (n >= 1e3)  return 'R$' + (n / 1e3).toFixed(1).replace('.', ',') + 'k';
    return 'R$' + n.toFixed(2).replace('.', ',');
  }

  // ── External control API (called from page scope tabs, search, quick filters) ──

  function updatePeriod(start, end) {
    _state.start = start;
    _state.end   = end;
    _state.page  = 0;
    _fetch();
  }

  function updateVendor(vendedor) {
    _state.vendedor = vendedor;
    _state.page = 0;
    _fetch();
  }

  function updateScope(scope) {
    _state.scope = scope;
    _state.page  = 0;
    const el = document.querySelector(_state.containerId);
    const scopeSel = el?.querySelector('#nfPeriodoScope');
    if (scopeSel && scopeSel.value !== scope) scopeSel.value = scope;
    _fetch();
  }

  function updateSearch(search) {
    _state.search = (search || '').trim();
    _state.page   = 0;
    const el = document.querySelector(_state.containerId);
    const searchInput = el?.querySelector('#nfPeriodoSearch');
    if (searchInput && searchInput.value !== _state.search) searchInput.value = _state.search;
    _fetch();
  }

  function updateQuickFilter(key, value, active) {
    if (key === 'has_frete')   _state.hasFrete   = !!active;
    if (key === 'sem_pedido')  _state.semPedido  = !!active;
    if (key === 'has_outras')  _state.hasOutras  = !!active;
    if (key === 'has_seguro')  _state.hasSeguro  = !!active;
    if (key === 'com_desconto') _state.comDesconto = !!active;
    _state.page = 0;
    _fetch();
  }

  return { init, updatePeriod, updateVendor, updateScope, updateSearch, updateQuickFilter };
})();
