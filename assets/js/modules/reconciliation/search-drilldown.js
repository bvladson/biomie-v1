(function () {
  'use strict';
  if (window.BReconciliation) return;

  var _statusConc    = '';
  var _soDiverg      = false;
  var _debounceTimer = null;
  var _currentPage    = 1;
  var _pendingMode    = false;
  var _pendingClass   = 'all';
  var _pendCurrentPage = 1;

  function _v(id) { return ((document.getElementById(id) || {}).value || '').trim(); }

  function hasExtraFilters() {
    return _v('f_rec_q') !== '' || _v('f_rec_vendedor') !== '' || _v('f_rec_cliente') !== '' ||
           _v('f_rec_pedido') !== '' || _v('f_rec_nf') !== '' ||
           _statusConc !== '' || _soDiverg;
  }

  function _buildUrl(page) {
    var ds = _v('dateStart') || (document.getElementById('dateStart') || {}).value || '';
    var de = _v('dateEnd')   || (document.getElementById('dateEnd')   || {}).value || '';
    var p  = new URLSearchParams();
    if (ds) p.set('start', ds);
    if (de) p.set('end',   de);
    if (_v('f_rec_q'))        p.set('q',       _v('f_rec_q'));
    if (_v('f_rec_vendedor')) p.set('vendedor', _v('f_rec_vendedor'));
    if (_v('f_rec_cliente'))  p.set('cliente',  _v('f_rec_cliente'));
    if (_v('f_rec_pedido'))   p.set('pedido',   _v('f_rec_pedido'));
    if (_v('f_rec_nf'))       p.set('nf',       _v('f_rec_nf'));
    if (_statusConc)          p.set('status_conciliacao', _statusConc);
    if (_soDiverg)            p.set('somente_divergencias', '1');
    p.set('page',     String(page || _currentPage || 1));
    p.set('per_page', '25');
    p.set('sort',      _v('f_rec_sort')      || 'emissao');
    p.set('direction', _v('f_rec_direction') || 'desc');
    return 'api/reconciliation_search.php?' + p.toString();
  }

  function _pushUrl() {
    var params = new URLSearchParams(window.location.search);
    var setOrDel = function (k, v) { v ? params.set(k, v) : params.delete(k); };
    setOrDel('f_rec_q',       _v('f_rec_q'));
    setOrDel('f_rec_vendedor', _v('f_rec_vendedor'));
    setOrDel('f_rec_cliente',  _v('f_rec_cliente'));
    setOrDel('f_rec_pedido',   _v('f_rec_pedido'));
    setOrDel('f_rec_nf',       _v('f_rec_nf'));
    setOrDel('f_rec_status',   _statusConc);
    params[_soDiverg ? 'set' : 'delete']('f_rec_diverg', '1');
    history.replaceState(null, '', '?' + params.toString());
  }

  function _restoreUrl() {
    var p = new URLSearchParams(window.location.search);
    var set = function (id, key) { var el = document.getElementById(id); if (el && p.get(key)) el.value = p.get(key); };
    set('f_rec_q',       'f_rec_q');
    set('f_rec_vendedor', 'f_rec_vendedor');
    set('f_rec_cliente',  'f_rec_cliente');
    set('f_rec_pedido',   'f_rec_pedido');
    set('f_rec_nf',       'f_rec_nf');
    if (p.get('f_rec_status')) { _statusConc = p.get('f_rec_status'); _syncStatusBtns(); }
    if (p.get('f_rec_diverg')) { _soDiverg = true; _syncDivergBtn(); }
  }

  function _syncStatusBtns() {
    document.querySelectorAll('[data-rec-status]').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-rec-status') === _statusConc);
    });
  }

  function _syncDivergBtn() {
    var btn = document.getElementById('recSoDiverg');
    if (btn) btn.classList.toggle('active', _soDiverg);
  }

  // ── Fetch & render ──────────────────────────────────────────────────────────
  async function loadFiltered(pageArg) {
    if (_pendingMode) { return _loadPending(pageArg); }
    var page = typeof pageArg === 'number' ? pageArg : (_currentPage || 1);
    _currentPage = page;
    _pushUrl();

    var loadEl = document.getElementById('recLoadingIndicator');
    if (loadEl) loadEl.style.display = 'inline-flex';

    var data = null;
    try {
      var res = await fetch(_buildUrl(page));
      data = await res.json();
    } catch (e) {
      console.error('[reconciliation] fetch error', e);
      if (loadEl) loadEl.style.display = 'none';
      return;
    }

    if (!data || data.ok === false) {
      if (loadEl) loadEl.style.display = 'none';
      _renderError((data && data.error && data.error.message) || 'Erro ao carregar conciliação.');
      return;
    }

    _updateKpis(data.summary || {});
    _renderTable(data.data || []);
    _renderPagination(data.pagination || {});
    _renderChips();

    if (loadEl) loadEl.style.display = 'none';
  }

  function _updateKpis(s) {
    var fmt  = (window.biomieFormatters && window.biomieFormatters.currency) || function (v) { return 'R$ ' + Number(v).toFixed(2); };
    var fmtN = (window.biomieFormatters && window.biomieFormatters.integer) || function (v) { return String(v); };

    var setText = function (sel, val) {
      var els = document.querySelectorAll(sel);
      els.forEach(function (el) { el.textContent = val; });
    };
    setText('[data-bind="rec-total"]',         fmtN(s.total_registros       || 0));
    setText('[data-bind="rec-ok"]',            fmtN(s.ok                    || 0));
    setText('[data-bind="rec-divergentes"]',   fmtN(s.divergentes           || 0));
    setText('[data-bind="rec-pedido-sem-nf"]', fmtN(s.pedido_sem_nf         || 0));
    setText('[data-bind="rec-nf-sem-pedido"]', fmtN(s.nf_sem_pedido         || 0));
    setText('[data-bind="rec-nf-sem-titulo"]', fmtN(s.nf_sem_titulo         || 0));
    setText('[data-bind="rec-valor-div"]',     fmtN(s.valor_divergente      || 0));
    setText('[data-bind="rec-valor-div-total"]', fmt(s.valor_total_divergente || 0));
    setText('[data-bind="rec-parcelado"]',     fmtN(s.parcelado             || 0));
  }

  // ── Badge derivation: combines status_conciliacao + qtd_titulos + valor_titulos_aberto
  function _deriveBadge(r) {
    var st    = r.status_conciliacao     || '';
    var qtd   = Number(r.qtd_titulos     || 0);
    var aberto = Number(r.valor_titulos_aberto || 0);

    if (st === 'ok') {
      if (qtd <= 1) return 'ok';
      if (aberto <= 0.01) return 'parcelado_ok';
      return 'parcelado_parcial';
    }
    if (st === 'valor_divergente' && qtd > 1) return 'parcelado_divergente';
    return st;
  }

  var STATUS_LABEL = {
    // Single-titulo statuses
    ok:                     'Conciliado',
    pedido_sem_nf:          'Pedido s/ NF',
    nf_sem_pedido:          'NF s/ Pedido',
    nf_sem_titulo:          'NF s/ Título',
    titulo_sem_nf:          'Título s/ NF',
    valor_divergente:       'Valor diverge',
    titulo_baixa_confianca: 'Conf. baixa',
    cancelado:              'Cancelado',
    pendente_manual:        'Pendente',
    // Parcelado-derived statuses (Q.10.1)
    parcelado_ok:           'Conciliado · Parc.',
    parcelado_parcial:      'Parc. em aberto',
    parcelado_divergente:   'Soma diverge',
  };

  var STATUS_CSS = {
    ok:                     'rec-status-ok',
    pedido_sem_nf:          'rec-status-warn',
    nf_sem_pedido:          'rec-status-warn',
    nf_sem_titulo:          'rec-status-warn',
    titulo_sem_nf:          'rec-status-warn',
    valor_divergente:       'rec-status-error',
    titulo_baixa_confianca: 'rec-status-info',
    cancelado:              'rec-status-muted',
    pendente_manual:        'rec-status-info',
    parcelado_ok:           'rec-status-ok',
    parcelado_parcial:      'rec-status-warn',
    parcelado_divergente:   'rec-status-error',
  };

  function _esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] || c;
    });
  }

  function _brl(v) {
    var n = Number(v) || 0;
    return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  // Renders the Títulos cell: count badge for parceladas, titulo_num for single
  function _buildTitulosCell(r) {
    var qtd = Number(r.qtd_titulos || 0);
    if (qtd === 0) return '—';
    if (qtd === 1) return _esc(r.titulo_num || '1');
    return '<span class="rec-status-badge rec-status-info" style="font-size:10px">' + qtd + ' parc.</span>';
  }

  function _renderTable(rows) {
    var tb = document.getElementById('reconciliationTableBody');
    if (!tb) return;

    if (!rows || !rows.length) {
      tb.innerHTML =
        '<tr><td colspan="13" class="mag-empty-cell">' +
        '<div class="mag-state mag-state-empty mag-state-compact mag-state-severity--info" role="status">' +
        '<span class="material-symbols-rounded mag-state-icon">search_off</span>' +
        '<strong class="mag-state-title">Nenhum registro encontrado</strong>' +
        '<p class="mag-state-message">Nenhum registro encontrado para os filtros selecionados.</p>' +
        '</div></td></tr>';
      return;
    }

    tb.innerHTML = rows.map(function (r) {
      var badge    = _deriveBadge(r);
      var css      = STATUS_CSS[badge] || 'rec-status-info';
      var lbl      = STATUS_LABEL[badge] || badge;
      var dif      = Number(r.diferenca || 0);
      var difColor = dif > 0.01 ? 'color:var(--danger)' : '';

      return '<tr class="rec-row">' +
        '<td><span class="rec-status-badge ' + css + '">' + _esc(lbl) + '</span></td>' +
        '<td>' + _esc(r.pedido   || '—') + '</td>' +
        '<td>' + _esc(r.nf       || '—') + '</td>' +
        '<td>' + _buildTitulosCell(r) + '</td>' +
        '<td>' + _esc(r.cliente  || '—') + '</td>' +
        '<td>' + _esc(r.vendedor || '—') + '</td>' +
        '<td style="text-align:right">' + _brl(r.base_comercial) + '</td>' +
        '<td style="text-align:right">' + _brl(r.valor_titulos_total) + '</td>' +
        '<td style="text-align:right">' + _brl(r.valor_titulos_recebido) + '</td>' +
        '<td style="text-align:right">' + _brl(r.valor_titulos_aberto) + '</td>' +
        '<td style="text-align:right;' + difColor + '"><strong>' + _brl(dif) + '</strong></td>' +
        '<td>' + _esc(r.proximo_vencimento || '—') + '</td>' +
        '<td class="rec-actions">' + _buildActions(r) + '</td>' +
        '</tr>';
    }).join('');
  }

  function _buildActions(r) {
    var qtd  = Number(r.qtd_titulos || 0);
    var btns = '';
    if (r.pedido)   btns += '<button type="button" class="biomie-btn biomie-btn--secondary biomie-btn--sm" onclick="window.BReconciliation.openDiag(' + JSON.stringify({type:'pedido',ref:r.pedido,id:r.pedido_id}) + ')">Pedido</button> ';
    if (r.nota_key) btns += '<button type="button" class="biomie-btn biomie-btn--secondary biomie-btn--sm" onclick="window.BReconciliation.openDiag(' + JSON.stringify({type:'nf',ref:r.nota_key,nf:r.nf}) + ')">NF</button> ';
    if (qtd > 1 && r.nota_key) {
      btns += '<button type="button" class="biomie-btn biomie-btn--secondary biomie-btn--sm" onclick="window.BReconciliation.openDiag(' + JSON.stringify({type:'parcelas',ref:r.nota_key,nf:r.nf,qtd:qtd}) + ')">' + qtd + ' Títulos</button>';
    } else if (r.titulo_key) {
      btns += '<button type="button" class="biomie-btn biomie-btn--secondary biomie-btn--sm" onclick="window.BReconciliation.openDiag(' + JSON.stringify({type:'titulo',ref:r.titulo_key}) + ')">Título</button>';
    }
    return btns || '—';
  }

  function _renderPagination(pag) {
    var wrap = document.getElementById('recPagination');
    if (!wrap) return;
    if (!pag || !pag.total_pages || pag.total_pages <= 1) {
      wrap.style.display = 'none'; return;
    }
    wrap.style.display = 'flex';
    var cur = pag.page || 1;
    var tot = pag.total_pages || 1;
    var html = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
    html += '<span style="font-size:12px;color:var(--text-2)">Página ' + cur + ' de ' + tot + ' (' + (pag.total || 0) + ' registros)</span>';
    if (pag.has_prev) html += '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" onclick="window.BReconciliation.goPage(' + (cur - 1) + ')">Anterior</button>';
    if (pag.has_next) html += '<button class="biomie-btn biomie-btn--primary biomie-btn--sm"   onclick="window.BReconciliation.goPage(' + (cur + 1) + ')">Próximo</button>';
    html += '</div>';
    wrap.innerHTML = html;
  }

  function _renderChips() {
    var wrap = document.getElementById('recFilterChips');
    if (!wrap) return;
    var chips = [];
    var STATUS_LABEL_MAP = { ok: 'Conciliado', pedido_sem_nf: 'Pedido s/ NF', nf_sem_pedido: 'NF s/ Pedido', nf_sem_titulo: 'NF s/ Título', titulo_sem_nf: 'Título s/ NF', valor_divergente: 'Valor diverge', titulo_baixa_confianca: 'Confiança baixa', cancelado: 'Cancelado', pendente_manual: 'Pendente' };
    if (_v('f_rec_q'))        chips.push({ key: 'q',        label: 'Busca: '   + _v('f_rec_q') });
    if (_v('f_rec_vendedor')) chips.push({ key: 'vendedor', label: 'Vendedor: '+ _v('f_rec_vendedor') });
    if (_v('f_rec_cliente'))  chips.push({ key: 'cliente',  label: 'Cliente: ' + _v('f_rec_cliente') });
    if (_v('f_rec_pedido'))   chips.push({ key: 'pedido',   label: 'Pedido: '  + _v('f_rec_pedido') });
    if (_v('f_rec_nf'))       chips.push({ key: 'nf',       label: 'NF: '      + _v('f_rec_nf') });
    if (_statusConc)          chips.push({ key: 'status',   label: STATUS_LABEL_MAP[_statusConc] || _statusConc });
    if (_soDiverg)            chips.push({ key: 'diverg',   label: 'Somente divergências' });
    if (!chips.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';
    wrap.innerHTML = chips.map(function (c) {
      return '<span class="filter-chip">' + _esc(c.label) +
        ' <button type="button" class="filter-chip-remove" onclick="window.BReconciliation.removeFilter(\'' + c.key + '\')" aria-label="Remover">&times;</button></span>';
    }).join('');
  }

  function _renderError(msg) {
    var tb = document.getElementById('reconciliationTableBody');
    if (!tb) return;
    tb.innerHTML =
      '<tr><td colspan="13" class="mag-empty-cell">' +
      '<div class="mag-state mag-state-error mag-state-compact mag-state-severity--danger" role="alert">' +
      '<span class="material-symbols-rounded mag-state-icon">error_outline</span>' +
      '<strong class="mag-state-title">Erro na conciliação</strong>' +
      '<p class="mag-state-message">' + _esc(msg) + '</p>' +
      '</div></td></tr>';
  }

  // ── Pending mode (Q.10.4) ────────────────────────────────────────────────────
  function _buildPendingUrl(page) {
    var ds = _v('dateStart') || '';
    var de = _v('dateEnd')   || '';
    var p  = new URLSearchParams();
    if (ds) p.set('start', ds);
    if (de) p.set('end',   de);
    if (_v('f_rec_vendedor')) p.set('vendedor', _v('f_rec_vendedor'));
    if (_v('f_rec_cliente'))  p.set('cliente',  _v('f_rec_cliente'));
    if (_pendingClass && _pendingClass !== 'all') p.set('class', _pendingClass);
    p.set('page',     String(page || _pendCurrentPage || 1));
    p.set('per_page', '25');
    return 'api/reconciliation_pending.php?' + p.toString();
  }

  function _togglePendingMode(enable) {
    var mainSec   = document.getElementById('mainRecSection');
    var pendSec   = document.getElementById('pendenciasSection');
    var mainKpis  = document.querySelector('.rec-kpi-strip:not(#pendingKpiStrip)');
    var pendKpis  = document.getElementById('pendingKpiStrip');
    var classFltr = document.getElementById('pendingClassFilter');
    var pendBtn   = document.getElementById('recPendencias');
    if (enable) {
      if (mainSec)   mainSec.style.display   = 'none';
      if (pendSec)   pendSec.style.display   = '';
      if (mainKpis)  mainKpis.style.display  = 'none';
      if (pendKpis)  pendKpis.style.display  = '';
      if (classFltr) classFltr.style.display = 'flex';
      if (pendBtn)   pendBtn.classList.add('active');
    } else {
      if (mainSec)   mainSec.style.display   = '';
      if (pendSec)   pendSec.style.display   = 'none';
      if (mainKpis)  mainKpis.style.display  = '';
      if (pendKpis)  pendKpis.style.display  = 'none';
      if (classFltr) classFltr.style.display = 'none';
      if (pendBtn)   pendBtn.classList.remove('active');
    }
  }

  function _updatePendingKpis(summary) {
    var fmt  = (window.biomieFormatters && window.biomieFormatters.currency) || function (v) { return 'R$ ' + Number(v).toFixed(2); };
    var fmtN = (window.biomieFormatters && window.biomieFormatters.integer)  || function (v) { return String(v); };
    var setText = function (sel, val) {
      document.querySelectorAll(sel).forEach(function (el) { el.textContent = val; });
    };
    setText('[data-bind="pend-total"]',  fmtN(summary.total_nfs           || 0));
    setText('[data-bind="pend-titulo"]', fmtN(summary.titulo_nao_lancado  || 0));
    setText('[data-bind="pend-avista"]', fmtN(summary.pagamento_avista    || 0));
    setText('[data-bind="pend-base"]',   fmt(summary.total_base_comercial || 0));
  }

  var _PEND_CLASS_CSS = {
    titulo_nao_lancado:        'rec-status-warn',
    possivel_pagamento_avista: 'rec-status-info',
  };
  var _PEND_CLASS_LABEL = {
    titulo_nao_lancado:        'Título não lançado',
    possivel_pagamento_avista: 'Pag. à vista',
  };
  var _PEND_ACAO_CSS = {
    lancar_titulo_no_omie:              'rec-status-error',
    preencher_chave_nfe_origem_no_omie: 'rec-status-warn',
    confirmar_pagamento_avista:         'rec-status-info',
  };
  var _PEND_ACAO_LABEL = {
    lancar_titulo_no_omie:              'Lançar título no Omie',
    preencher_chave_nfe_origem_no_omie: 'Preencher chave NF no Omie',
    confirmar_pagamento_avista:         'Confirmar pag. à vista',
  };

  function _renderPendingTable(rows) {
    var tb = document.getElementById('pendenciasTableBody');
    if (!tb) return;
    if (!rows || !rows.length) {
      tb.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-2)">Nenhuma pendência encontrada no período.</td></tr>';
      return;
    }
    tb.innerHTML = rows.map(function (r) {
      var clsCss = _PEND_CLASS_CSS[r.classificacao]   || 'rec-status-muted';
      var clsLbl = _PEND_CLASS_LABEL[r.classificacao] || r.classificacao;
      var acaCss = _PEND_ACAO_CSS[r.acao_recomendada]   || 'rec-status-muted';
      var acaLbl = _PEND_ACAO_LABEL[r.acao_recomendada] || r.acao_recomendada;
      return '<tr class="rec-row">' +
        '<td><span class="rec-status-badge ' + clsCss + '">' + _esc(clsLbl) + '</span></td>' +
        '<td>' + _esc(r.numero_nf || '—') + '</td>' +
        '<td>' + _esc(r.data_emissao || '—') + '</td>' +
        '<td>' + _esc(r.pedido_id_omie ? String(r.pedido_id_omie) : '—') + '</td>' +
        '<td>' + _esc(r.nome_cliente  || '—') + '</td>' +
        '<td>' + _esc(r.nome_vendedor || '—') + '</td>' +
        '<td style="text-align:right">' + _brl(r.base_comercial) + '</td>' +
        '<td><span class="rec-status-badge ' + acaCss + '" style="font-size:10px;white-space:normal">' + _esc(acaLbl) + '</span></td>' +
        '</tr>';
    }).join('');
  }

  function _renderPendingPagination(pag) {
    var wrap = document.getElementById('pendPagination');
    if (!wrap) return;
    if (!pag || !pag.total_pages || pag.total_pages <= 1) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';
    var cur = pag.page || 1;
    var tot = pag.total_pages || 1;
    var html = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
    html += '<span style="font-size:12px;color:var(--text-2)">Página ' + cur + ' de ' + tot + ' (' + (pag.total || 0) + ' registros)</span>';
    if (pag.has_prev) html += '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" onclick="window.BReconciliation.pendGoPage(' + (cur - 1) + ')">Anterior</button>';
    if (pag.has_next) html += '<button class="biomie-btn biomie-btn--primary biomie-btn--sm" onclick="window.BReconciliation.pendGoPage(' + (cur + 1) + ')">Próximo</button>';
    html += '</div>';
    wrap.innerHTML = html;
  }

  async function _loadPending(pageArg) {
    var page = typeof pageArg === 'number' ? pageArg : (_pendCurrentPage || 1);
    _pendCurrentPage = page;

    var loadEl = document.getElementById('recLoadingIndicator');
    if (loadEl) loadEl.style.display = 'inline-flex';

    var data = null;
    try {
      var res = await fetch(_buildPendingUrl(page));
      data = await res.json();
    } catch (e) {
      console.error('[reconciliation-pending] fetch error', e);
      if (loadEl) loadEl.style.display = 'none';
      return;
    }

    if (!data || data.ok === false) {
      if (loadEl) loadEl.style.display = 'none';
      var tb = document.getElementById('pendenciasTableBody');
      if (tb) tb.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--danger)">' +
        _esc((data && data.error && data.error.message) || 'Erro ao carregar pendências.') + '</td></tr>';
      return;
    }

    _updatePendingKpis(data.summary || {});
    _renderPendingTable(data.data || []);
    _renderPendingPagination(data.pagination || {});
    if (loadEl) loadEl.style.display = 'none';
  }

  // ── Public API ──────────────────────────────────────────────────────────────
  function clearFilteredView() {
    _statusConc = ''; _soDiverg = false; _currentPage = 1;
    if (_pendingMode) { _pendingMode = false; _pendCurrentPage = 1; _togglePendingMode(false); }
    _syncStatusBtns(); _syncDivergBtn();
    var chips = document.getElementById('recFilterChips');
    if (chips) { chips.style.display = 'none'; chips.innerHTML = ''; }
    var pag = document.getElementById('recPagination');
    if (pag) pag.style.display = 'none';
    loadFiltered(1);
  }

  function removeFilter(key) {
    var clearInput = function (id) { var el = document.getElementById(id); if (el) el.value = ''; };
    if (key === 'q')        clearInput('f_rec_q');
    if (key === 'vendedor') clearInput('f_rec_vendedor');
    if (key === 'cliente')  clearInput('f_rec_cliente');
    if (key === 'pedido')   clearInput('f_rec_pedido');
    if (key === 'nf')       clearInput('f_rec_nf');
    if (key === 'status')   { _statusConc = ''; _syncStatusBtns(); }
    if (key === 'diverg')   { _soDiverg = false; _syncDivergBtn(); }
    loadFiltered(1);
  }

  function goPage(page) { loadFiltered(page); }

  function openDiag(ref) {
    if (!ref) return;
    if (window.biomieOpenDrawer) {
      if (ref.type === 'nf'       && ref.ref) window.biomieOpenDrawer('nota-fiscal', { nota_key: ref.ref });
      if (ref.type === 'pedido'   && ref.id)  window.biomieOpenDrawer('pedido',      { pedido_id: ref.id });
      if (ref.type === 'parcelas' && ref.ref) window.biomieOpenDrawer('nota-fiscal', { nota_key: ref.ref });
    }
  }

  // ── Event binding ───────────────────────────────────────────────────────────
  function _debounceLoad() {
    clearTimeout(_debounceTimer);
    _debounceTimer = setTimeout(function () { loadFiltered(1); }, 400);
  }

  function _bindEvents() {
    ['f_rec_q', 'f_rec_vendedor', 'f_rec_cliente', 'f_rec_pedido', 'f_rec_nf'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', _debounceLoad);
    });

    document.querySelectorAll('[data-rec-status]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-rec-status');
        _statusConc = (_statusConc === val) ? '' : val;
        _syncStatusBtns();
        loadFiltered(1);
      });
    });

    var divBtn = document.getElementById('recSoDiverg');
    if (divBtn) {
      divBtn.addEventListener('click', function () {
        _soDiverg = !_soDiverg; _syncDivergBtn(); loadFiltered(1);
      });
    }

    var clearBtn = document.getElementById('recClearFilters');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        ['f_rec_q', 'f_rec_vendedor', 'f_rec_cliente', 'f_rec_pedido', 'f_rec_nf'].forEach(function (id) {
          var el = document.getElementById(id); if (el) el.value = '';
        });
        clearFilteredView();
      });
    }

    var applyBtn = document.getElementById('recApplyFilters');
    if (applyBtn) applyBtn.addEventListener('click', function () { loadFiltered(1); });

    var pendBtn = document.getElementById('recPendencias');
    if (pendBtn) {
      pendBtn.addEventListener('click', function () {
        _pendingMode = !_pendingMode;
        _pendCurrentPage = 1;
        _togglePendingMode(_pendingMode);
        loadFiltered(1);
      });
    }

    document.querySelectorAll('[data-pend-class]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        _pendingClass = btn.getAttribute('data-pend-class');
        _pendCurrentPage = 1;
        document.querySelectorAll('.pend-class-btn').forEach(function (b) {
          b.classList.toggle('active', b === btn);
        });
        if (_pendingMode) loadFiltered(1);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    _restoreUrl();
    _bindEvents();
    loadFiltered(1);
  });

  window.BReconciliation = {
    hasExtraFilters:   hasExtraFilters,
    loadFiltered:      loadFiltered,
    clearFilteredView: clearFilteredView,
    removeFilter:      removeFilter,
    goPage:            goPage,
    openDiag:          openDiag,
    pendGoPage:        function (page) { _pendCurrentPage = page; _loadPending(page); },
  };
})();
