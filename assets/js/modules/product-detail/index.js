(function () {
  'use strict';

  if (window.BIomieProductDetail) return;

  var B = window.BIomie || {};
  B.ProductDetail = B.ProductDetail || {};

  var DRAWER_ID = 'product-detail-drawer';
  var TABS = [
    { key: 'resumo',    label: 'Resumo' },
    { key: 'nfs',       label: 'NFs' },
    { key: 'clientes',  label: 'Clientes' },
    { key: 'vendedores',label: 'Vendedores' },
    { key: 'pedidos',   label: 'Pedidos vinculados' },
    { key: 'evolucao',  label: 'Evolucao' },
    { key: 'ltv',       label: 'LTV' },
    { key: 'estoque',   label: 'Giro & Risco' },
    { key: 'acao',      label: 'Ação Comercial' },
    { key: 'diagnostico', label: 'Diagnostico' },
  ];

  // Tabs that load lazily after drawer open (summary is preloaded)
  var LAZY_TABS = { nfs: true, clientes: true, vendedores: true, pedidos: true, evolucao: true, ltv: true, estoque: true, acao: true };

  var state = {
    loading: false,
    error: '',
    data: null,
    tab: 'resumo',
    productCode: '',
    productName: '',
    params: {},
    abortCtrl: null,
    openerEl: null,
    tabData: {}   // { tabKey: data }
  };
  var cache = {};

  // ── Cache ─────────────────────────────────────────────────────────────────

  function cacheKey(code, params, tab) {
    return 'pd_v1:' + code + ':' + (params.start || '') + ':' + (params.end || '') + ':' + (tab || 'all');
  }

  function fromCache(key) {
    var e = cache[key];
    return e && Date.now() - e.ts < 90000 ? e.data : null;
  }

  function toCache(key, data) {
    cache[key] = { ts: Date.now(), data: data };
  }

  // ── DOM ───────────────────────────────────────────────────────────────────

  function getDrawer() {
    var el = document.getElementById(DRAWER_ID);
    if (!el) {
      el = document.createElement('div');
      el.id = DRAWER_ID;
      el.className = 'biomie-drawer biomie-drawer--large mag-drawer mag-drawer--right mag-drawer--wide mag-drawer--mobile-medium';
      el.setAttribute('role', 'dialog');
      el.setAttribute('aria-modal', 'true');
      document.body.appendChild(el);
    }
    return el;
  }

  function buildShell() {
    var drawer = getDrawer();
    drawer.setAttribute('aria-labelledby', 'pd-title');
    drawer.innerHTML =
      '<div class="biomie-drawer__header">' +
        '<div>' +
          '<div class="biomie-drawer__eyebrow">Product 360</div>' +
          '<div class="biomie-drawer__title" id="pd-title">Carregando...</div>' +
          '<div class="biomie-drawer__subtitle" id="pd-subtitle"></div>' +
        '</div>' +
        '<button class="biomie-drawer__close" id="pd-close" aria-label="Fechar painel do produto"><span class="material-symbols-rounded">close</span></button>' +
      '</div>' +
      '<div class="biomie-drawer__tabs" id="pd-tabs" role="tablist" aria-label="Secoes do produto"></div>' +
      '<div class="biomie-drawer__body" id="pd-body" role="tabpanel"></div>';
    document.getElementById('pd-close').addEventListener('click', close);
    document.addEventListener('keydown', onKeyDown);
    renderTabs();
    return drawer;
  }

  function renderTabs() {
    var target = document.getElementById('pd-tabs');
    if (!target) return;
    var T = B.ProductDetail.Tabs;
    var esc = T ? T.esc : function (v) { return String(v == null ? '' : v); };
    target.innerHTML = TABS.map(function (tab) {
      var active = tab.key === state.tab;
      return '<button class="biomie-drawer__tab' + (active ? ' is-active' : '') + '" data-tab="' + esc(tab.key) + '" role="tab" aria-selected="' + (active ? 'true' : 'false') + '">' + esc(tab.label) + '</button>';
    }).join('');
    target.querySelectorAll('.biomie-drawer__tab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.tab = btn.getAttribute('data-tab') || 'resumo';
        renderTabs();
        renderBody();
      });
    });
  }

  function renderBody() {
    var body = document.getElementById('pd-body');
    if (!body) return;

    var T = B.ProductDetail.Tabs;
    if (!T) {
      body.innerHTML = '<div class="biomie-drawer__empty">Erro: modulos product-detail nao carregados.</div>';
      return;
    }

    if (state.loading && !state.data) {
      body.innerHTML = skeleton();
      return;
    }
    if (state.error && !state.data) {
      body.innerHTML = '<div class="mag-empty mag-empty--compact">' +
        '<div class="mag-empty-icon" style="background:rgba(220,38,38,.1);color:#b91c1c"><span class="material-symbols-rounded" aria-hidden="true">error</span></div>' +
        '<strong class="mag-empty-title">Erro ao carregar produto</strong>' +
        '<p class="mag-empty-text">' + T.esc(state.error) + '</p>' +
        '</div>';
      return;
    }
    if (!state.data) {
      body.innerHTML = T.empty('Sem dados disponiveis para este produto.');
      return;
    }

    var tab  = state.tab;
    var data = state.data;

    // Lazy tabs: check if we have tab-specific data cached
    if (LAZY_TABS[tab] && !state.tabData[tab] && !state.tabLoadingFor) {
      loadTab(tab);
      body.innerHTML = skeleton();
      return;
    }

    // Merge tab-specific data if available
    var merged = state.tabData[tab]
      ? Object.assign({}, data, state.tabData[tab])
      : data;

    if (tab === 'resumo') {
      body.innerHTML = T.renderResumo(merged);
    } else if (tab === 'nfs') {
      body.innerHTML = T.renderInvoices(merged.invoices || []);
    } else if (tab === 'clientes') {
      body.innerHTML = T.renderCustomers(merged.customers || []);
    } else if (tab === 'vendedores') {
      body.innerHTML = T.renderVendors(merged.vendors || []);
    } else if (tab === 'pedidos') {
      body.innerHTML = T.renderLinkedOrders(merged.linked_orders || [], merged.summary);
    } else if (tab === 'evolucao') {
      var C = B.ProductDetail.Charts;
      body.innerHTML = C ? C.renderTimeline(merged) : T.empty('Modulo charts nao carregado.');
    } else if (tab === 'ltv') {
      body.innerHTML = T.renderLtv(merged);
    } else if (tab === 'estoque') {
      body.innerHTML = T.renderEstoque ? T.renderEstoque(merged) : T.empty('Modulo estoque nao carregado.');
    } else if (tab === 'acao') {
      body.innerHTML = T.renderAcaoComercial ? T.renderAcaoComercial(merged) : T.empty('Modulo acao comercial nao carregado.');
    } else if (tab === 'diagnostico') {
      body.innerHTML = T.renderDiagnostico(merged);
    } else {
      body.innerHTML = T.empty('Aba desconhecida.');
    }
  }

  // ── Data loading ──────────────────────────────────────────────────────────

  function loadSummary(productCode, params) {
    if (state.abortCtrl) {
      try { state.abortCtrl.abort(); } catch (e) {}
    }
    state.abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    state.loading = true;
    state.error   = '';
    state.data    = null;
    state.tabData = {};
    renderBody();

    var ck = cacheKey(productCode, params, 'resumo');
    var cached = fromCache(ck);
    if (cached) {
      state.data    = cached;
      state.loading = false;
      updateHeader();
      renderBody();
      return;
    }

    var url = 'api/product_detail.php?product_code=' + encodeURIComponent(productCode) +
      '&start=' + encodeURIComponent(params.start || '') +
      '&end=' + encodeURIComponent(params.end || '') +
      '&tab=resumo';

    var opts = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (state.abortCtrl) opts.signal = state.abortCtrl.signal;

    fetch(url, opts)
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.ok === false) {
          state.error = json.message || 'Erro ao carregar produto.';
        } else {
          state.data = json;
          toCache(ck, json);
        }
        state.loading = false;
        updateHeader();
        renderBody();
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        state.loading = false;
        state.error   = 'Erro de conexao ao carregar produto.';
        renderBody();
      });
  }

  function loadTab(tab) {
    if (state.tabLoadingFor === tab) return;
    state.tabLoadingFor = tab;

    var ck = cacheKey(state.productCode, state.params, tab);
    var cached = fromCache(ck);
    if (cached) {
      state.tabData[tab] = cached;
      state.tabLoadingFor = null;
      renderBody();
      return;
    }

    var url = 'api/product_detail.php?product_code=' + encodeURIComponent(state.productCode) +
      '&start=' + encodeURIComponent(state.params.start || '') +
      '&end='   + encodeURIComponent(state.params.end   || '') +
      '&tab='   + encodeURIComponent(tab);

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.ok !== false) {
          state.tabData[tab] = json;
          toCache(ck, json);
        }
        state.tabLoadingFor = null;
        if (state.tab === tab) renderBody();
      })
      .catch(function () {
        state.tabLoadingFor = null;
      });
  }

  // ── Drawer lifecycle ──────────────────────────────────────────────────────

  function open(opts) {
    opts = opts || {};
    var productCode = String(opts.productCode || '').trim();
    if (!productCode) return;

    state.productCode = productCode;
    state.productName = String(opts.productName || '').trim();
    state.params      = { start: opts.start || '', end: opts.end || '' };
    state.tab         = 'resumo';
    state.openerEl    = document.activeElement;

    var drawer = buildShell();
    drawer.classList.add('is-open');
    drawer.removeAttribute('hidden');
    document.body.classList.add('biomie-drawer-open');

    updateHeader();
    loadSummary(productCode, state.params);
  }

  function close() {
    var drawer = document.getElementById(DRAWER_ID);
    if (drawer) {
      drawer.classList.remove('is-open');
      drawer.setAttribute('hidden', '');
    }
    document.body.classList.remove('biomie-drawer-open');
    document.removeEventListener('keydown', onKeyDown);
    if (state.abortCtrl) {
      try { state.abortCtrl.abort(); } catch (e) {}
    }
    if (state.openerEl && typeof state.openerEl.focus === 'function') {
      try { state.openerEl.focus(); } catch (e) {}
    }
  }

  var PLACEHOLDER_NAMES = ['Produto sem descricao', 'Produto sem descrição', 'Produto sem descricão', ''];

  function isPlaceholder(s) {
    return !s || PLACEHOLDER_NAMES.indexOf(String(s).trim()) !== -1;
  }

  function updateHeader() {
    var titleEl    = document.getElementById('pd-title');
    var subtitleEl = document.getElementById('pd-subtitle');
    if (titleEl) {
      // Priority: identity.display_name > productName (from table click) > product_description > product_code
      var identity = state.data && state.data.identity;
      var apiIdentity = identity && !isPlaceholder(identity.display_name) ? identity.display_name : null;
      var apiDesc     = state.data && state.data.summary && !isPlaceholder(state.data.summary.product_description)
                        ? state.data.summary.product_description : null;
      var cleanName   = !isPlaceholder(state.productName) ? state.productName : null;
      var desc = apiIdentity || cleanName || apiDesc || state.productCode || 'Product 360';
      titleEl.textContent = desc;
    }
    if (subtitleEl) {
      subtitleEl.textContent = state.productCode || '';
    }
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') close();
  }

  function skeleton() {
    return '<div class="biomie-drawer__skeleton">' +
      '<div class="bmie-skel"></div><div class="bmie-skel"></div><div class="bmie-skel"></div>' +
      '</div>';
  }

  // ── Public API ────────────────────────────────────────────────────────────

  window.BIomieProductDetail = { open: open, close: close };
  window.BIomie = B;
}());
