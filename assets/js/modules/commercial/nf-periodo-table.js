/**
 * Interactive, server-side invoice list for NF do Periodo.
 */
export const NfPeriodoTable = (() => {
  const PAGE_SIZE = 25;
  const MAX_PAGE_SIZE = 100;

  let state = {
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
    minValue: '',
    maxValue: '',
  };

  let abortController = null;

  function init(containerId, params = {}) {
    state = {
      ...state,
      containerId,
      start: params.start || window.biomieStartDate || '',
      end: params.end || window.biomieEndDate || '',
      scope: params.scope || 'commercial',
      vendedor: params.vendedor || '',
      cliente: params.cliente || '',
      search: params.search || '',
      page: 0,
    };

    renderShell();
    fetchRows();
  }

  function renderShell() {
    const el = document.querySelector(state.containerId);
    if (!el) return;

    el.innerHTML = `
      <div class="nf-table-wrapper">
        <div class="nf-table-toolbar">
          <div class="nf-table-toolbar__hint">
            <span class="material-symbols-rounded" aria-hidden="true">touch_app</span>
            Selecione uma linha para abrir a visao completa da nota fiscal.
          </div>
          <div class="nf-table-toolbar__controls">
            <span id="nfPeriodoStatus" class="nf-pagination-meta" aria-live="polite"></span>
            <label class="nf-table-toolbar__label" for="nfPeriodoLength">Por pagina</label>
            <select id="nfPeriodoLength" class="filter-select" aria-label="Linhas por pagina">
              <option value="25">25</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </div>
        </div>

        <div class="nf-table-summary-bar" id="nfPeriodoSummary" aria-live="polite"></div>

        <div class="nf-table-scroll">
          <table class="nf-periodo-table" data-mobile-records>
            <thead>
              <tr>
                <th class="sortable" data-col="numero_nf">Documento fiscal</th>
                <th class="sortable" data-col="nome_cliente">Cliente</th>
                <th class="sortable" data-col="nome_vendedor">Contexto comercial</th>
                <th class="sortable" data-col="base_comercial">Composicao da NF</th>
                <th aria-label="Acoes"></th>
              </tr>
            </thead>
            <tbody id="nfPeriodoBody">
              <tr><td colspan="5" class="empty-state">Carregando...</td></tr>
            </tbody>
          </table>
        </div>

        <div class="nf-table-pagination" id="nfPeriodoPagination"></div>
      </div>
    `;

    bindShellEvents();
  }

  function bindShellEvents() {
    const el = document.querySelector(state.containerId);
    if (!el) return;

    el.querySelector('#nfPeriodoLength')?.addEventListener('change', event => {
      state.length = Math.min(MAX_PAGE_SIZE, parseInt(event.target.value, 10) || PAGE_SIZE);
      state.page = 0;
      fetchRows();
    });

    el.querySelectorAll('th.sortable').forEach(th => {
      th.addEventListener('click', () => {
        const col = th.dataset.col;
        if (state.orderCol === col) {
          state.orderDir = state.orderDir === 'asc' ? 'desc' : 'asc';
        } else {
          state.orderCol = col;
          state.orderDir = 'desc';
        }
        state.page = 0;
        fetchRows();
      });
    });
  }

  async function fetchRows() {
    if (state.loading) abortController?.abort();

    state.loading = true;
    state.draw++;
    setLoadingState('loading');

    const params = new URLSearchParams({
      start: state.start,
      end: state.end,
      draw: String(state.draw),
      offset: String(state.page * state.length),
      length: String(state.length),
      scope: state.scope,
      order_col: state.orderCol,
      order_dir: state.orderDir,
    });

    if (state.search) params.set('search', state.search);
    if (state.vendedor) params.set('vendedor', state.vendedor);
    if (state.cliente) params.set('cliente', state.cliente);
    if (state.hasFrete) params.set('has_frete', '1');
    if (state.semPedido) params.set('sem_pedido', '1');
    if (state.hasOutras) params.set('has_outras', '1');
    if (state.hasSeguro) params.set('has_seguro', '1');
    if (state.comDesconto) params.set('com_desconto', '1');
    if (state.minValue !== '') params.set('min_value', state.minValue);
    if (state.maxValue !== '') params.set('max_value', state.maxValue);

    abortController = new AbortController();

    try {
      const response = await fetch('/biomie/api/nf_periodo_table.php?' + params.toString(), {
        signal: abortController.signal,
      });
      const json = await response.json().catch(() => ({}));
      if (!response.ok || json.ok === false) throw new Error(json.error || `HTTP ${response.status}`);
      if (json.draw < state.draw) return;

      state.totalRecords = json.recordsTotal || 0;
      state.filteredRecords = json.recordsFiltered || 0;
      state.loading = false;

      if (json.data_error) {
        renderError(`Erro ao carregar NFs: ${json.data_error}`);
      } else {
        renderRows(json.data || []);
      }
      renderPagination();
      renderSummary(json.summary || {});
      setLoadingState('done');
    } catch (error) {
      if (error.name === 'AbortError') return;
      state.loading = false;
      setLoadingState('error', error.message || 'Falha ao carregar notas fiscais.');
    }
  }

  function renderRows(rows) {
    const tbody = document.getElementById('nfPeriodoBody');
    if (!tbody) return;

    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Nenhuma NF encontrada no periodo.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(renderRow).join('');
    tbody.querySelectorAll('.nf-document-row').forEach(row => {
      row.addEventListener('keydown', event => {
        if (event.target !== row) return;
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        row.click();
      });
    });
  }

  function renderRow(row) {
    const invoiceAttrs = `data-biomie-action="open-invoice" data-nota-key="${esc(row.nota_key || '')}" data-numero-nf="${esc(row.numero_nf || '')}" data-chave-nfe="${esc(row.chave_nfe || '')}"`;
    const clientAttrs = `data-biomie-action="open-customer" data-codigo-cliente-omie="${esc(row.codigo_cliente_omie || '')}" data-cliente-documento="${esc(row.documento_cliente || '')}" data-cliente-nome="${esc(row.nome_cliente || '')}" data-nota-key="${esc(row.nota_key || '')}" data-pedido-codigo="${esc(row.pedido_ref || '')}"`;
    const vendorAttrs = `data-biomie-action="open-vendor" data-vendor-id="${esc(row.codigo_vendedor_omie || '')}" data-vendor-name="${esc(row.nome_vendedor || '')}"`;
    const vendorCell = row.nome_vendedor || row.codigo_vendedor_omie
      ? `<button type="button" class="nf-inline-action nf-cell-title" ${vendorAttrs}><span class="material-symbols-rounded" aria-hidden="true">person</span>${esc(row.nome_vendedor || 'Vendedor sem nome')}</button>`
      : '<span class="nf-cell-meta"><span class="material-symbols-rounded" aria-hidden="true">person_off</span>Sem vendedor vinculado</span>';
    const orderCell = row.pedido_ref
      ? `<button type="button" class="nf-inline-action" data-biomie-action="open-order" data-pedido-codigo="${esc(row.pedido_ref)}"><span class="material-symbols-rounded" aria-hidden="true">shopping_cart</span>Pedido ${esc(row.pedido_ref)}</button>`
      : '<span class="nf-cell-meta"><span class="material-symbols-rounded" aria-hidden="true">link_off</span>Sem pedido vinculado</span>';
    const scopeBadge = scopeChip(row.scope);
    const itemCount = Number(row.items_count || 0);
    const document = maskDocument(row.documento_cliente || '');
    const startParam = encodeURIComponent(state.start || '');
    const endParam = encodeURIComponent(state.end || '');
    const mapLink = `<a class="nf-map-button" data-biomie-action="noop" href="mapa_vendas.php?layer=mapa_composto_ceara&date_from=${startParam}&date_to=${endParam}" target="_blank" rel="noopener" title="Ver no mapa"><span class="material-symbols-rounded" aria-hidden="true">map</span></a>`;

    return `<tr class="nf-document-row" tabindex="0" ${invoiceAttrs} aria-label="Abrir detalhes da NF ${esc(row.numero_nf || '')}">
      <td data-mobile-area="identidade">
        <div class="nf-cell-main">
          <span class="material-symbols-rounded nf-cell-icon" aria-hidden="true">receipt_long</span>
          <div class="nf-cell-stack">
            <button type="button" class="nf-inline-action nf-cell-title" ${invoiceAttrs} title="Abrir nota fiscal diretamente">NF ${esc(row.numero_nf || row.nota_key || '-')}</button>
            <span class="nf-cell-meta"><span class="material-symbols-rounded" aria-hidden="true">calendar_today</span>${fmtDate(row.data_ref)}${itemCount ? ` · ${itemCount} ${itemCount === 1 ? 'item' : 'itens'}` : ''}</span>
            ${scopeBadge}
          </div>
        </div>
      </td>
      <td data-mobile-area="titulo">
        <div class="nf-cell-stack">
          <button type="button" class="nf-inline-action nf-cell-title" ${clientAttrs}>${esc(row.nome_cliente || 'Cliente nao informado')}</button>
          <span class="nf-cell-meta"><span class="material-symbols-rounded" aria-hidden="true">badge</span>${esc(document || 'Documento nao informado')}</span>
        </div>
      </td>
      <td data-mobile-area="contexto">
        <div class="nf-cell-stack">
          ${vendorCell}
          ${orderCell}
        </div>
      </td>
      <td data-mobile-area="valor">
        <div class="nf-cell-stack">
          <span class="nf-base-value">${fmtBrl(row.base_comercial || 0)}</span>
          <span class="nf-total-value">Total fiscal ${fmtBrl(row.valor_total || row.base_comercial || 0)}</span>
          ${renderSignals(row)}
        </div>
      </td>
      <td data-mobile-area="acoes">
        <div class="nf-cell-main">
          <button type="button" class="nf-open-button" ${invoiceAttrs}><span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span>Detalhes</button>
          ${mapLink}
        </div>
      </td>
    </tr>`;
  }

  function scopeChip(scope) {
    if (scope === 'remittance') {
      return '<span class="scope-chip scope-chip--remessa"><span class="material-symbols-rounded" aria-hidden="true">local_shipping</span>Remessa</span>';
    }
    if (scope === 'licitacao') {
      return '<span class="scope-chip scope-chip--licitacao"><span class="material-symbols-rounded" aria-hidden="true">account_balance</span>Licitacao</span>';
    }
    return '<span class="scope-chip scope-chip--orc-pd"><span class="material-symbols-rounded" aria-hidden="true">verified</span>Orc-PD</span>';
  }

  function renderSignals(row) {
    const signals = [];
    if (Number(row.valor_desconto || 0) > 0) signals.push(['sell', `Desconto ${fmtBrl(row.valor_desconto)}`]);
    if (Number(row.valor_frete || 0) > 0) signals.push(['local_shipping', `Frete ${fmtBrl(row.valor_frete)}`]);
    if (Number(row.valor_outras_despesas || 0) > 0) signals.push(['receipt', `Outras ${fmtBrl(row.valor_outras_despesas)}`]);
    if (Number(row.valor_seguro || 0) > 0) signals.push(['shield', `Seguro ${fmtBrl(row.valor_seguro)}`]);
    if (!signals.length) signals.push(['check_circle', 'Base sem adicionais']);

    return `<div class="nf-signal-list">${signals.map(signal => `<span class="nf-signal"><span class="material-symbols-rounded" aria-hidden="true">${signal[0]}</span>${signal[1]}</span>`).join('')}</div>`;
  }

  function renderPagination() {
    const el = document.getElementById('nfPeriodoPagination');
    if (!el) return;

    const totalPages = Math.max(1, Math.ceil(state.filteredRecords / state.length));
    const from = state.filteredRecords ? state.page * state.length + 1 : 0;
    const to = Math.min((state.page + 1) * state.length, state.filteredRecords);
    const prevDisabled = state.page === 0 ? 'disabled' : '';
    const nextDisabled = state.page >= totalPages - 1 ? 'disabled' : '';

    el.innerHTML = `
      <span class="nf-pagination-meta">
        ${from}-${to} de ${state.filteredRecords.toLocaleString('pt-BR')}
        ${state.filteredRecords < state.totalRecords ? ` (total ${state.totalRecords.toLocaleString('pt-BR')})` : ''}
      </span>
      <div class="nf-pagination-actions">
        <button class="nf-pagination-button" id="nfPrevPage" ${prevDisabled} title="Pagina anterior" aria-label="Pagina anterior"><span class="material-symbols-rounded">chevron_left</span></button>
        <span class="nf-pagination-current">Pagina ${state.page + 1} de ${totalPages}</span>
        <button class="nf-pagination-button" id="nfNextPage" ${nextDisabled} title="Proxima pagina" aria-label="Proxima pagina"><span class="material-symbols-rounded">chevron_right</span></button>
      </div>`;

    el.querySelector('#nfPrevPage')?.addEventListener('click', () => {
      if (state.page > 0) {
        state.page--;
        fetchRows();
      }
    });
    el.querySelector('#nfNextPage')?.addEventListener('click', () => {
      if (state.page < totalPages - 1) {
        state.page++;
        fetchRows();
      }
    });
  }

  function renderSummary(summary) {
    const el = document.getElementById('nfPeriodoSummary');
    if (!el) return;

    const total = fmtBrl(summary.base_comercial_total || 0);
    const nfs = (summary.nfs_filtered || 0).toLocaleString('pt-BR');
    const clients = (summary.clientes_filtered || 0).toLocaleString('pt-BR');

    el.innerHTML = `
      <div class="nf-summary-card">
        <span class="material-symbols-rounded nf-summary-card__icon" aria-hidden="true">receipt_long</span>
        <div class="nf-summary-card__body"><span class="nf-summary-card__label">Notas encontradas</span><strong class="nf-summary-card__value">${nfs} NFs</strong></div>
      </div>
      <div class="nf-summary-card nf-summary-card--value">
        <span class="material-symbols-rounded nf-summary-card__icon" aria-hidden="true">payments</span>
        <div class="nf-summary-card__body"><span class="nf-summary-card__label">Base comercial filtrada</span><strong class="nf-summary-card__value">${total}</strong></div>
      </div>
      <div class="nf-summary-card nf-summary-card--clients">
        <span class="material-symbols-rounded nf-summary-card__icon" aria-hidden="true">groups</span>
        <div class="nf-summary-card__body"><span class="nf-summary-card__label">Clientes no resultado</span><strong class="nf-summary-card__value">${clients} clientes</strong></div>
      </div>`;
  }

  function setLoadingState(status, message = '') {
    const statusEl = document.getElementById('nfPeriodoStatus');
    if (statusEl) {
      statusEl.textContent = status === 'loading' ? 'Carregando...' : status === 'error' ? `Erro: ${message}` : '';
    }
    if (status === 'error') renderError('Nao foi possivel carregar as notas fiscais.', true);
  }

  function renderError(message, retry = false) {
    const tbody = document.getElementById('nfPeriodoBody');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="5" class="empty-state" style="color:var(--danger,#dc2626);">
      ${esc(message)}
      ${retry ? '<button type="button" id="nfPeriodoRetry" class="btn-secondary" style="margin-left:8px;">Tentar novamente</button>' : ''}
    </td></tr>`;
    tbody.querySelector('#nfPeriodoRetry')?.addEventListener('click', fetchRows);
  }

  function esc(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function fmtBrl(value) {
    const number = parseFloat(value) || 0;
    return number.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  function fmtDate(value) {
    const text = String(value || '').slice(0, 10);
    const parts = text.split('-');
    return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : (text || '-');
  }

  function maskDocument(value) {
    const digits = String(value || '').replace(/\D/g, '');
    if (digits.length === 11) return `***.${digits.slice(3, 6)}.${digits.slice(6, 9)}-**`;
    if (digits.length === 14) return `${digits.slice(0, 2)}.***.***/${digits.slice(8, 12)}-**`;
    return String(value || '');
  }

  function updatePeriod(start, end) {
    state.start = start;
    state.end = end;
    state.page = 0;
    fetchRows();
  }

  function updateVendor(vendedor) {
    state.vendedor = vendedor;
    state.page = 0;
    fetchRows();
  }

  function updateScope(scope) {
    state.scope = scope;
    state.page = 0;
    fetchRows();
  }

  function updateSearch(search) {
    state.search = (search || '').trim();
    state.page = 0;
    fetchRows();
  }

  function updateValueRange(minValue, maxValue) {
    state.minValue = String(minValue || '').trim();
    state.maxValue = String(maxValue || '').trim();
    state.page = 0;
    fetchRows();
  }

  function resetFilters() {
    state.search = '';
    state.vendedor = '';
    state.cliente = '';
    state.minValue = '';
    state.maxValue = '';
    state.hasFrete = false;
    state.semPedido = false;
    state.hasOutras = false;
    state.hasSeguro = false;
    state.comDesconto = false;
    state.page = 0;
    fetchRows();
  }

  function updateQuickFilter(key, value, active) {
    if (key === 'has_frete') state.hasFrete = !!active;
    if (key === 'sem_pedido') state.semPedido = !!active;
    if (key === 'has_outras') state.hasOutras = !!active;
    if (key === 'has_seguro') state.hasSeguro = !!active;
    if (key === 'com_desconto') state.comDesconto = !!active;
    state.page = 0;
    fetchRows();
  }

  return {
    init,
    updatePeriod,
    updateVendor,
    updateScope,
    updateSearch,
    updateValueRange,
    updateQuickFilter,
    resetFilters,
  };
})();
