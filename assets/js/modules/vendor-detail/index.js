(function () {
  'use strict';

  var B = window.BIomie || {};
  B.VendorDetail = B.VendorDetail || {};
  
  var DRAWER_ID = 'vendor-detail-drawer';
  var TABS = [
    { key: 'resumo', label: 'Resumo' },
    { key: 'composicao', label: 'Composicao' },
    { key: 'nfs', label: 'NFs' },
    { key: 'pedidos', label: 'Pedidos' },
    { key: 'clientes', label: 'Clientes' },
    { key: 'produtos', label: 'Produtos' },
    { key: 'graficos', label: 'Graficos' },
    { key: 'timeline', label: 'Timeline' }
  ];

  var state = {
    loading: false,
    error: '',
    data: null,
    tab: 'resumo',
    vendorId: '',
    params: {},
    abortCtrl: null,
    openerEl: null
  };
  var cache = {};
  var focusTrapHandler = null;

  function cacheKey(id, params) {
    return 'vendor_v2:' + id + ':' + (params.start || '') + ':' + (params.end || '');
  }

  function fromCache(key) {
    var entry = cache[key];
    return entry && Date.now() - entry.ts < 60000 ? entry.data : null;
  }

  function toCache(key, data) {
    cache[key] = { ts: Date.now(), data: data };
  }

  function installFocusTrap(drawer) {
    removeFocusTrap();
    focusTrapHandler = function (event) {
      if (event.key !== 'Tab') return;
      var focusable = Array.from(drawer.querySelectorAll('button:not([disabled]),[href],input,[tabindex]:not([tabindex="-1"])'));
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        last.focus();
        event.preventDefault();
      } else if (!event.shiftKey && document.activeElement === last) {
        first.focus();
        event.preventDefault();
      }
    };
    document.addEventListener('keydown', focusTrapHandler);
  }

  function removeFocusTrap() {
    if (focusTrapHandler) {
      document.removeEventListener('keydown', focusTrapHandler);
      focusTrapHandler = null;
    }
  }

  function getDrawer() {
    var drawer = document.getElementById(DRAWER_ID);
    if (!drawer) {
      drawer = document.createElement('div');
      drawer.id = DRAWER_ID;
      drawer.className = 'biomie-drawer biomie-drawer--large';
      drawer.setAttribute('role', 'dialog');
      drawer.setAttribute('aria-modal', 'true');
      document.body.appendChild(drawer);
    }
    return drawer;
  }

  function buildShell() {
    var drawer = getDrawer();
    drawer.setAttribute('aria-labelledby', 'vd-title');
    drawer.innerHTML =
      '<div class="biomie-drawer__header">' +
        '<div><div class="biomie-drawer__title" id="vd-title">Vendedor 360</div><div class="biomie-drawer__subtitle" id="vd-subtitle"></div></div>' +
        '<button class="biomie-drawer__close" id="vd-close" aria-label="Fechar painel do vendedor"><span class="material-symbols-rounded">close</span></button>' +
      '</div>' +
      '<div class="biomie-drawer__tabs" id="vd-tabs" role="tablist" aria-label="Secoes do vendedor"></div>' +
      '<div class="biomie-drawer__body" id="vd-body" role="tabpanel"></div>';
    document.getElementById('vd-close').addEventListener('click', close);
    document.addEventListener('keydown', onKeyDown);
    renderTabs();
    return drawer;
  }

  function renderTabs() {
    var target = document.getElementById('vd-tabs');
    if (!target) return;
    target.innerHTML = TABS.map(function (tab) {
      var active = tab.key === state.tab;
      return '<button class="biomie-drawer__tab' + (active ? ' is-active' : '') + '" data-tab="' + B.VendorDetail.Tabs.esc(tab.key) + '" role="tab" aria-selected="' + (active ? 'true' : 'false') + '">' + B.VendorDetail.Tabs.esc(tab.label) + '</button>';
    }).join('');
    target.querySelectorAll('.biomie-drawer__tab').forEach(function (button) {
      button.addEventListener('click', function () {
        state.tab = button.getAttribute('data-tab') || 'resumo';
        renderTabs();
        renderBody();
      });
    });
  }

  function renderBody() {
    var body = document.getElementById('vd-body');
    if (!body) return;
    
    var T = B.VendorDetail.Tabs;
    if (!T) {
      body.innerHTML = '<div class="biomie-drawer__empty">Erro interno: modulos nao carregados.</div>';
      return;
    }

    if (state.loading) {
      body.innerHTML = skeleton();
      return;
    }
    if (state.error) {
      body.innerHTML = '<div class="biomie-drawer__empty" style="color:var(--danger)">' + T.esc(state.error) + '</div>';
      return;
    }
    if (!state.data) {
      body.innerHTML = '<div class="biomie-drawer__empty">Sem dados disponiveis para este vendedor.</div>';
      return;
    }

    var data = state.data;
    if (state.tab === 'resumo') body.innerHTML = T.renderResumo(data);
    else if (state.tab === 'composicao') body.innerHTML = B.VendorDetail.Composition ? B.VendorDetail.Composition.renderComposition(data) : T.empty('Modulo composition nao carregado.');
    else if (state.tab === 'nfs') body.innerHTML = T.renderInvoices(data.invoices || []);
    else if (state.tab === 'pedidos') body.innerHTML = T.renderOrders(data.orders || data.wallet_orders || []);
    else if (state.tab === 'clientes') body.innerHTML = T.renderCustomers(data.customers || []);
    else if (state.tab === 'produtos') body.innerHTML = T.renderProducts(data.products || []);
    else if (state.tab === 'graficos') body.innerHTML = B.VendorDetail.Charts ? B.VendorDetail.Charts.renderCharts(data) : T.empty('Modulo charts nao carregado.');
    else if (state.tab === 'timeline') body.innerHTML = T.renderTimeline(data);
    else body.innerHTML = '<div class="biomie-drawer__empty">Aba desconhecida.</div>';
  }

  function skeleton() {
    return '<div class="biomie-drawer__stats">' +
      Array(6).fill('<div class="biomie-drawer__stat"><div style="background:var(--skeleton-base);border-radius:4px;height:12px;width:68px">&nbsp;</div><div style="background:var(--skeleton-base);border-radius:4px;height:18px;width:92px;margin-top:4px">&nbsp;</div></div>').join('') +
      '</div>';
  }

  function open(vendorId, params) {
    vendorId = String(vendorId || '').trim();
    if (!vendorId) return;

    if (vendorId === '__SEM_VENDEDOR__' || vendorId.toLowerCase().includes('sem vendedor')) {
      openOperationalDrawer(params);
      return;
    }

    state.vendorId = vendorId;
    state.params = params || {};
    state.tab = 'resumo';
    state.data = null;
    state.error = '';
    state.loading = true;
    state.openerEl = document.activeElement;

    var drawer = buildShell();
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    fillMeta(null);
    renderBody();
    window.setTimeout(function () {
      var closeButton = document.getElementById('vd-close');
      if (closeButton) closeButton.focus();
      installFocusTrap(drawer);
    }, 80);

    var key = cacheKey(vendorId, state.params);
    var cached = fromCache(key);
    if (cached) {
      state.loading = false;
      state.data = cached;
      fillMeta(cached);
      renderBody();
      return;
    }

    if (state.abortCtrl) {
      try { state.abortCtrl.abort(); } catch (_) {}
    }
    state.abortCtrl = new AbortController();
    var qs = new URLSearchParams({ vendor_id: vendorId });
    if (state.params.start) qs.set('start', state.params.start);
    if (state.params.end) qs.set('end', state.params.end);

    fetch('api/flight_plan_vendor_detail.php?' + qs.toString(), {
      credentials: 'same-origin',
      signal: state.abortCtrl.signal,
      cache: 'no-store'
    })
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (json) {
          if (!response.ok || json.ok === false) throw new Error(json.message || 'Nao foi possivel carregar o vendedor.');
          return json;
        });
      })
      .then(function (json) {
        state.loading = false;
        state.abortCtrl = null;
        state.data = json;
        toCache(key, json);
        fillMeta(json);
        renderBody();
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        state.loading = false;
        state.abortCtrl = null;
        state.error = (error && error.message) || 'Falha ao carregar Vendor 360.';
        renderBody();
      });
  }

  function openOperationalDrawer(params) {
    state.vendorId = '__SEM_VENDEDOR__';
    state.params = params || {};
    state.openerEl = document.activeElement;

    var drawer = getDrawer();
    drawer.setAttribute('aria-labelledby', 'vd-title');
    drawer.innerHTML =
      '<div class="biomie-drawer__header">' +
        '<div><div class="biomie-drawer__title" id="vd-title" style="color:var(--danger)">Pedidos e NFs sem vendedor</div><div class="biomie-drawer__subtitle" id="vd-subtitle"></div></div>' +
        '<button class="biomie-drawer__close" id="vd-close" aria-label="Fechar painel"><span class="material-symbols-rounded">close</span></button>' +
      '</div>' +
      '<div class="biomie-drawer__body" id="vd-body" role="tabpanel">' +
        '<div style="background:var(--danger-muted);color:var(--danger);padding:12px;border-radius:4px;border-left:4px solid var(--danger);margin-bottom:16px;">' +
        '<strong>Acao recomendada:</strong> Corrigir vendedor na origem canonica/pedido/NF para entrar no ranking comercial.' +
        '</div>' +
        skeleton() +
      '</div>';

    document.getElementById('vd-close').addEventListener('click', close);
    document.addEventListener('keydown', onKeyDown);
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    
    var subtitleEl = document.getElementById('vd-subtitle');
    if (subtitleEl) subtitleEl.textContent = [params.start || '', params.end || ''].filter(Boolean).join(' ate ');

    var qs = new URLSearchParams({ vendor_id: '__SEM_VENDEDOR__' });
    if (params.start) qs.set('start', params.start);
    if (params.end) qs.set('end', params.end);

    fetch('api/flight_plan_vendor_detail.php?' + qs.toString())
      .then(function(res) { return res.json(); })
      .then(function(json) {
        var body = document.getElementById('vd-body');
        if (!body) return;
        var T = B.VendorDetail.Tabs;
        var s = json.summary || {};
        body.innerHTML = '<div style="background:var(--danger-muted);color:var(--danger);padding:12px;border-radius:4px;border-left:4px solid var(--danger);margin-bottom:16px;">' +
          '<strong>Acao recomendada:</strong> Corrigir vendedor na origem canonica/pedido/NF para entrar no ranking comercial.' +
          '</div>' +
          '<div class="biomie-drawer__stats" style="margin-bottom:16px;">' +
          T.stat('Valor retido', T.money(s.gross_total)) +
          T.stat('NFs orfas', T.number(s.invoices_count)) +
          T.stat('Pedidos orfaos', T.number(s.orders_count)) +
          T.stat('Clientes impactados', T.number(s.customers_count)) +
          '</div>' +
          T.renderInvoices(json.invoices || []);
      })
      .catch(function() {
        var body = document.getElementById('vd-body');
        if (body) body.innerHTML = '<div class="biomie-drawer__empty">Erro ao carregar bucket sem vendedor.</div>';
      });
  }

  function fillMeta(json) {
    var vendor = (json && json.vendor) || {};
    var period = (json && json.period) || state.params || {};
    var titleEl = document.getElementById('vd-title');
    var subtitleEl = document.getElementById('vd-subtitle');
    var name = vendor.name || state.vendorId || 'Vendedor 360';
    var risk = vendor.risk_status ? '<span class="biomie-drawer__badge">' + B.VendorDetail.Tabs.esc(vendor.risk_status) + '</span>' : '';

    if (titleEl) titleEl.innerHTML = B.VendorDetail.Tabs.esc(name) + ' ' + risk;
    if (subtitleEl) subtitleEl.textContent = [period.start || '', period.end || ''].filter(Boolean).join(' ate ');
  }

  function close() {
    var drawer = document.getElementById(DRAWER_ID);
    if (drawer) drawer.classList.remove('is-open');
    document.removeEventListener('keydown', onKeyDown);
    removeFocusTrap();
    if (state.abortCtrl) {
      try { state.abortCtrl.abort(); } catch (_) {}
      state.abortCtrl = null;
    }
    if (state.openerEl && state.openerEl.focus) {
      try { state.openerEl.focus(); } catch (_) {}
    }
    state.openerEl = null;
  }

  function onKeyDown(event) {
    if (event.key === 'Escape') close();
  }

  window.BIomieVendorDetail = { open: open, close: close };
}());
