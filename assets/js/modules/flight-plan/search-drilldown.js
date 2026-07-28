(function () {
  'use strict';
  if (window.BFlightPlanSearch) return;

  var _currentStatusMeta = '';
  var _debounceTimer     = null;
  var _currentPage       = 1;

  function _q()          { return (document.getElementById('f_fp_q') || {value: ''}).value.trim(); }
  function _vendedorId() { return (document.getElementById('f_fp_vendedor_id') || {value: ''}).value.trim(); }
  function _channel()    { return (document.getElementById('f_fp_channel') || {value: ''}).value.trim(); }

  function hasExtraFilters() {
    return _q() !== '' || _vendedorId() !== '' || _channel() !== '' || _currentStatusMeta !== '';
  }

  function _buildUrl(page) {
    var p  = page || _currentPage || 1;
    var ds = (document.getElementById('dateStart') || {value: ''}).value;
    var de = (document.getElementById('dateEnd')   || {value: ''}).value;
    var params = new URLSearchParams();
    if (ds)                   params.set('start',        ds);
    if (de)                   params.set('end',          de);
    if (_q())                 params.set('q',            _q());
    if (_vendedorId())        params.set('vendedor_id',  _vendedorId());
    if (_channel())            params.set('channel',      _channel());
    if (_currentStatusMeta)   params.set('status_meta',  _currentStatusMeta);
    params.set('page',     String(p));
    params.set('per_page', '25');
    return 'api/flight_plan_search.php?' + params.toString();
  }

  function _pushUrl() {
    var params = new URLSearchParams(window.location.search);
    if (_q())               params.set('f_fp_q',           _q()); else params.delete('f_fp_q');
    if (_vendedorId())      params.set('f_fp_vendedor_id', _vendedorId()); else params.delete('f_fp_vendedor_id');
    if (_channel())         params.set('f_fp_channel',     _channel()); else params.delete('f_fp_channel');
    if (_currentStatusMeta) params.set('f_fp_status',      _currentStatusMeta); else params.delete('f_fp_status');
    history.replaceState(null, '', '?' + params.toString());
  }

  function _restoreUrl() {
    var params = new URLSearchParams(window.location.search);
    var q   = params.get('f_fp_q')           || '';
    var vid = params.get('f_fp_vendedor_id') || '';
    var channel = params.get('f_fp_channel') || '';
    var st  = params.get('f_fp_status')      || '';
    var qEl  = document.getElementById('f_fp_q');
    var vidEl = document.getElementById('f_fp_vendedor_id');
    var channelEl = document.getElementById('f_fp_channel');
    if (qEl  && q)   qEl.value  = q;
    if (vidEl && vid) vidEl.value = vid;
    if (channelEl && channel) channelEl.value = channel;
    if (st) {
      _currentStatusMeta = st;
      _syncStatusBtns();
    }
  }

  function _syncStatusBtns() {
    document.querySelectorAll('[data-fp-status]').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-fp-status') === _currentStatusMeta);
    });
  }

  // ── Fetch & render ──────────────────────────────────────────────────────────
  async function loadFiltered(forceOrPage) {
    var page = typeof forceOrPage === 'number' ? forceOrPage : 1;
    _currentPage = page;
    _pushUrl();

    var m = window.BIomieFlightPlan;
    if (m && m.Dom) m.Dom.showLoading(true);

    var data = null;
    try {
      var res  = await fetch(_buildUrl(page));
      data = await res.json();
    } catch (e) {
      console.error('[flight-plan-search] fetch error', e);
      if (m && m.Dom) m.Dom.showLoading(false);
      return;
    }

    if (!data || data.ok === false) {
      if (m && m.Alerts) {
        var msg = data && data.error && data.error.message ? data.error.message : 'Não foi possível aplicar os filtros.';
        m.Alerts.renderAlerts([{ level: 'danger', title: 'Filtros indisponíveis', message: msg }]);
      }
      if (m && m.Dom) m.Dom.showLoading(false);
      return;
    }

    _updateKpis(data.summary || {});
    _renderVendorRows(data.data || []);
    _renderPagination(data.pagination || {});
    _renderChips();
    _renderFilteredCharts(data.chart_data || data.data || []);

    if (m && m.Dom) m.Dom.showLoading(false);
  }

  function _updateKpis(s) {
    var m = window.BIomieFlightPlan;
    if (!m || !m.Dom || !m.Formatters) return;
    var dom = m.Dom;
    var fmt = m.Formatters.fmt || function (v) { return v; };
    var fmtN = m.Formatters.fmtN || function (v) { return v; };

    dom.setText('[data-bind="fp-meta-total"]',         fmt(s.meta_total      || 0));
    dom.setText('[data-bind="fp-realizado"]',           fmt(s.realizado_total || 0));
    dom.setText('[data-bind="fp-gap-total"]',           fmt(s.gap_total       || 0));
    dom.setText('[data-bind="fp-atingimento-total"]',   'Atingimento ' + fmtN(s.percentual_meta || 0, 1) + '%');
    dom.setText('[data-bind="fp-notas"]',               fmtN(s.nfs_total || 0) + ' NFs');
    dom.setText('[data-bind="fp-projecao"]',             fmt(s.previsao_total || 0));
    dom.setText('[data-bind="fp-pedidos-carteira"]',     fmtN(s.pedidos_carteira_total || 0));
    dom.setText('[data-bind="fp-vendedores-acima"]',    fmtN(s.vendedores_acima_meta  || 0));
    dom.setText('[data-bind="fp-vendedores-abaixo"]',   fmtN(s.vendedores_abaixo_meta || 0));
    dom.setText('[data-bind="fp-vendedores-risco"]',    fmtN(s.vendedores_sem_venda   || 0));
    dom.setText('[data-bind="fp-vendedores-sem-meta"]',  fmtN(s.vendedores_sem_meta    || 0));
    var acimaCard  = document.getElementById('fp-vendedores-acima');
    var abaixoCard = document.getElementById('fp-vendedores-abaixo');
    if (acimaCard)  acimaCard.style.display  = '';
    if (abaixoCard) abaixoCard.style.display = '';
  }

  function _renderVendorRows(rows) {
    var m = window.BIomieFlightPlan;
    if (!m || !m.VendorTable) return;

    m.VendorTable.renderVendorTable(rows);
  }

  function _renderPagination(pag) {
    var wrap = document.getElementById('fpSearchPagination');
    if (!wrap) return;
    if (!pag || !pag.total_pages || pag.total_pages <= 1) {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'flex';
    var cur  = pag.page || 1;
    var tot  = pag.total_pages || 1;
    var html = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
    html += '<span style="font-size:12px;color:var(--text-2)">Página ' + cur + ' de ' + tot + ' (' + (pag.total || 0) + ' vendedores)</span>';
    if (pag.has_prev) {
      html += '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" onclick="window.BFlightPlanSearch.goPage(' + (cur - 1) + ')">Anterior</button>';
    }
    if (pag.has_next) {
      html += '<button class="biomie-btn biomie-btn--primary biomie-btn--sm" onclick="window.BFlightPlanSearch.goPage(' + (cur + 1) + ')">Próximo</button>';
    }
    html += '</div>';
    wrap.innerHTML = html;
  }

  function _renderChips() {
    var wrap = document.getElementById('fpFilterChips');
    if (!wrap) return;
    var chips = [];
    if (_q())               chips.push({ key: 'q',          label: 'Busca: ' + _q() });
    if (_vendedorId())      chips.push({ key: 'vendedor_id', label: 'ID: '   + _vendedorId() });
    if (_channel()) {
      var channelLabels = { varejo: 'Varejo', revenda: 'Revenda', representante: 'Representante', outro: 'Não definido' };
      chips.push({ key: 'channel', label: 'Canal: ' + (channelLabels[_channel()] || _channel()) });
    }
    if (_currentStatusMeta) {
      var lblMap = { acima_meta: 'Acima da meta', abaixo_meta: 'Abaixo da meta', sem_venda: 'Sem venda', com_venda: 'Com venda', sem_meta: 'Meta pendente' };
      chips.push({ key: 'status_meta', label: lblMap[_currentStatusMeta] || _currentStatusMeta });
    }
    if (!chips.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';
    wrap.innerHTML = chips.map(function (c) {
      return '<span class="filter-chip">' + _escH(c.label) +
        ' <button type="button" class="filter-chip-remove" onclick="window.BFlightPlanSearch.removeFilter(\'' + c.key + '\')" aria-label="Remover">&times;</button></span>';
    }).join('');
  }

  function _renderFilteredCharts(rows) {
    var daily = document.getElementById('fpDailyChartCard');
    var achievement = document.getElementById('fpAchievementChartCard');
    var subtitle = document.getElementById('fpAchievementChartSubtitle');
    if (daily) daily.style.display = 'none';
    if (achievement) achievement.style.gridColumn = '1 / -1';
    if (subtitle) subtitle.textContent = 'Vendedores encontrados nos filtros ativos';
    var m = window.BIomieFlightPlan;
    if (m && m.Charts && typeof m.Charts.renderAchievementChart === 'function') {
      m.Charts.renderAchievementChart(rows || []);
    }
  }

  function _showFpCharts() {
    var daily = document.getElementById('fpDailyChartCard');
    var achievement = document.getElementById('fpAchievementChartCard');
    var subtitle = document.getElementById('fpAchievementChartSubtitle');
    if (daily) daily.style.display = '';
    if (achievement) achievement.style.gridColumn = '';
    if (subtitle) subtitle.textContent = 'Prioridades comerciais por vendedor';
  }

  function _escH(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] || c;
    });
  }

  // ── Public API ──────────────────────────────────────────────────────────────
  function clearFilteredView() {
    _currentStatusMeta = '';
    _currentPage = 1;
    _syncStatusBtns();
    var chips = document.getElementById('fpFilterChips');
    if (chips) { chips.style.display = 'none'; chips.innerHTML = ''; }
    var pag = document.getElementById('fpSearchPagination');
    if (pag) pag.style.display = 'none';
    var acimaCard  = document.getElementById('fp-vendedores-acima');
    var abaixoCard = document.getElementById('fp-vendedores-abaixo');
    if (acimaCard)  acimaCard.style.display  = 'none';
    if (abaixoCard) abaixoCard.style.display = 'none';
    _showFpCharts();
    var m = window.BIomieFlightPlan;
    if (m && typeof m.boot === 'function') m.boot(false);
  }

  function removeFilter(key) {
    if (key === 'q')           { var el = document.getElementById('f_fp_q');          if (el) el.value = ''; }
    if (key === 'vendedor_id') { var el2 = document.getElementById('f_fp_vendedor_id'); if (el2) el2.value = ''; }
    if (key === 'channel')      { var channelEl = document.getElementById('f_fp_channel'); if (channelEl) channelEl.value = ''; }
    if (key === 'status_meta') { _currentStatusMeta = ''; _syncStatusBtns(); }
    if (hasExtraFilters()) {
      loadFiltered(1);
    } else {
      clearFilteredView();
    }
  }

  function goPage(page) {
    loadFiltered(page);
  }

  // ── Event binding ───────────────────────────────────────────────────────────
  function _bindEvents() {
    var qEl = document.getElementById('f_fp_q');
    if (qEl) {
      qEl.addEventListener('input', function () {
        clearTimeout(_debounceTimer);
        _debounceTimer = setTimeout(function () {
          if (hasExtraFilters()) { loadFiltered(1); } else { clearFilteredView(); }
        }, 400);
      });
    }

    document.querySelectorAll('[data-fp-status]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-fp-status');
        _currentStatusMeta = (_currentStatusMeta === val) ? '' : val;
        _syncStatusBtns();
        if (hasExtraFilters()) { loadFiltered(1); } else { clearFilteredView(); }
      });
    });

    var channelEl = document.getElementById('f_fp_channel');
    if (channelEl) {
      channelEl.addEventListener('change', function () {
        if (hasExtraFilters()) { loadFiltered(1); } else { clearFilteredView(); }
      });
    }

    var clearBtn = document.getElementById('fpClearFilters');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        var qEl2 = document.getElementById('f_fp_q');
        var vidEl = document.getElementById('f_fp_vendedor_id');
        var channelEl2 = document.getElementById('f_fp_channel');
        if (qEl2)  qEl2.value  = '';
        if (vidEl) vidEl.value = '';
        if (channelEl2) channelEl2.value = '';
        clearFilteredView();
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    _restoreUrl();
    _bindEvents();
    if (hasExtraFilters()) loadFiltered(1);
  });

  window.BFlightPlanSearch = {
    hasExtraFilters: hasExtraFilters,
    loadFiltered:    loadFiltered,
    clearFilteredView: clearFilteredView,
    removeFilter:    removeFilter,
    goPage:          goPage,
  };
})();
