/**
 * BIomie — Geo 360 Drawer.
 * Drawer de detalhe de bairro com lazy loading por aba.
 *
 * API: window.BIomieGeoDetail.open({ neighborhoodKey, neighborhood, city, start, end })
 */
(function () {
  'use strict';

  if (window.BIomieGeoDetail) return;

  var B = window.B || {};
  B.GeoDetail = B.GeoDetail || {};

  var DRAWER_ID = 'geo-detail-drawer';
  var TABS = [
    { key: 'resumo',       label: 'Resumo' },
    { key: 'compradores',  label: 'Compradores' },
    { key: 'cnaes',        label: 'CNAEs' },
    { key: 'nfs',          label: 'NFs' },
    { key: 'vendedores',   label: 'Vendedores' },
    { key: 'evolucao',     label: 'Evolucao' },
    { key: 'diagnostico',  label: 'Diagnostico' },
  ];
  var LAZY_TABS = { compradores: true, cnaes: true, nfs: true, vendedores: true, evolucao: true, diagnostico: true };

  var state = {
    loading: false,
    tab: 'resumo',
    neighborhoodKey: '',
    neighborhood: '',
    city: '',
    params: {},
    abortCtrl: null,
    openerEl: null,
    tabData: {},
  };
  var cache = {};

  // ── Cache ─────────────────────────────────────────────────────────────────

  function cacheKey(key, params, tab) {
    return 'gd_v1:' + key + ':' + (params.start || '') + ':' + (params.end || '') + ':' + (tab || 'all');
  }

  function fromCache(k) {
    var e = cache[k];
    return e && Date.now() - e.ts < 90000 ? e.data : null;
  }

  function toCache(k, data) {
    cache[k] = { ts: Date.now(), data: data };
  }

  // ── DOM ───────────────────────────────────────────────────────────────────

  function getDrawer() {
    var el = document.getElementById(DRAWER_ID);
    if (!el) {
      el = document.createElement('div');
      el.id = DRAWER_ID;
      el.className = 'biomie-drawer biomie-drawer--large mag-drawer mag-drawer--right mag-drawer--wide mag-drawer--mobile-full';
      el.setAttribute('role', 'dialog');
      el.setAttribute('aria-modal', 'true');
      document.body.appendChild(el);
    }
    return el;
  }

  function buildShell() {
    var drawer = getDrawer();
    drawer.setAttribute('aria-labelledby', 'gd-title');
    drawer.innerHTML =
      '<div class="biomie-drawer__header">' +
        '<div>' +
          '<div class="biomie-drawer__eyebrow">Geo 360</div>' +
          '<div class="biomie-drawer__title" id="gd-title">Carregando...</div>' +
          '<div class="biomie-drawer__subtitle" id="gd-subtitle"></div>' +
        '</div>' +
        '<button class="biomie-drawer__close" id="gd-close" aria-label="Fechar painel do bairro"><span class="material-symbols-rounded">close</span></button>' +
      '</div>' +
      '<div class="biomie-drawer__tabs" id="gd-tabs" role="tablist" aria-label="Secoes do bairro"></div>' +
      '<div class="biomie-drawer__body" id="gd-body">' +
        '<div class="biomie-drawer__loading" id="gd-loading" aria-live="polite">Carregando...</div>' +
        '<div id="gd-content" style="display:none;"></div>' +
        '<div class="biomie-drawer__error" id="gd-error" style="display:none;"></div>' +
      '</div>';

    document.getElementById('gd-close').addEventListener('click', close);

    var tabsEl = document.getElementById('gd-tabs');
    TABS.forEach(function (t) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.role = 'tab';
      btn.className = 'biomie-drawer__tab' + (t.key === 'resumo' ? ' is-active' : '');
      btn.dataset.tab = t.key;
      btn.textContent = t.label;
      btn.addEventListener('click', function () { switchTab(t.key); });
      tabsEl.appendChild(btn);
    });
  }

  // ── Drawer state ──────────────────────────────────────────────────────────

  function showLoading() {
    var ld = document.getElementById('gd-loading');
    var co = document.getElementById('gd-content');
    var er = document.getElementById('gd-error');
    if (ld) ld.style.display = '';
    if (co) co.style.display = 'none';
    if (er) er.style.display = 'none';
  }

  function showContent(html) {
    var ld = document.getElementById('gd-loading');
    var co = document.getElementById('gd-content');
    var er = document.getElementById('gd-error');
    if (ld) ld.style.display = 'none';
    if (er) er.style.display = 'none';
    if (co) { co.innerHTML = html; co.style.display = ''; }
  }

  function showError(msg) {
    var ld = document.getElementById('gd-loading');
    var co = document.getElementById('gd-content');
    var er = document.getElementById('gd-error');
    if (ld) ld.style.display = 'none';
    if (co) co.style.display = 'none';
    if (er) { er.textContent = msg || 'Erro ao carregar dados do bairro.'; er.style.display = ''; }
  }

  function updateHeader() {
    var titleEl = document.getElementById('gd-title');
    var subtitleEl = document.getElementById('gd-subtitle');
    var name = state.neighborhood || state.neighborhoodKey;
    if (titleEl) titleEl.textContent = name || 'Bairro';
    if (subtitleEl) subtitleEl.textContent = state.city ? (state.city + ' · ' + (state.params.start || '') + ' a ' + (state.params.end || '')) : '';
  }

  function setActiveTab(key) {
    document.querySelectorAll('#gd-tabs .biomie-drawer__tab').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.dataset.tab === key);
    });
  }

  // ── API ───────────────────────────────────────────────────────────────────

  function apiUrl(tab) {
    var p = new URLSearchParams({
      neighborhood_key: state.neighborhoodKey,
      neighborhood: state.neighborhood,
      city: state.city,
      start: state.params.start || '',
      end: state.params.end || '',
      limit: '50',
    });
    if (tab) p.set('tab', tab);
    return 'api/geo_neighborhood_detail.php?' + p.toString();
  }

  function fetchTab(tab, cb) {
    var ck = cacheKey(state.neighborhoodKey, state.params, tab);
    var cached = fromCache(ck);
    if (cached) { cb(null, cached); return; }

    if (state.abortCtrl) { try { state.abortCtrl.abort(); } catch (e) {} }
    state.abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;

    var opts = { credentials: 'same-origin' };
    if (state.abortCtrl) opts.signal = state.abortCtrl.signal;

    fetch(apiUrl(tab), opts)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.message || 'API error');
        toCache(ck, data);
        cb(null, data);
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        cb(err);
      });
  }

  // ── Tab rendering ─────────────────────────────────────────────────────────

  function renderTabContent(key, data) {
    var T = (window.B && window.B.GeoDetail && window.B.GeoDetail.Tabs) || {};
    var C = (window.B && window.B.GeoDetail && window.B.GeoDetail.Charts) || {};

    var html = '';
    switch (key) {
      case 'resumo':      html = T.renderResumo      ? T.renderResumo(data)      : JSON.stringify(data.summary); break;
      case 'compradores': html = T.renderBuyers       ? T.renderBuyers(data.buyers || [])    : ''; break;
      case 'cnaes':       html = T.renderCnaes        ? T.renderCnaes(data.cnaes || [])      : ''; break;
      case 'nfs':         html = T.renderInvoices     ? T.renderInvoices(data.invoices || []) : ''; break;
      case 'vendedores':  html = T.renderVendors      ? T.renderVendors(data.vendors || [])  : ''; break;
      case 'evolucao':    html = C.renderTimeline     ? C.renderTimeline(data)               : ''; break;
      case 'diagnostico': html = T.renderDiagnostico  ? T.renderDiagnostico(data)            : ''; break;
      default:            html = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
    }
    return html;
  }

  // ── Tab switching ─────────────────────────────────────────────────────────

  function switchTab(key) {
    state.tab = key;
    setActiveTab(key);

    if (state.tabData[key]) {
      showContent(renderTabContent(key, state.tabData[key]));
      return;
    }

    if (!LAZY_TABS[key]) return;

    showLoading();
    fetchTab(key, function (err, data) {
      if (err) { showError('Falha ao carregar aba ' + key + '.'); return; }
      state.tabData[key] = data;
      showContent(renderTabContent(key, data));
    });
  }

  // ── Open / close ──────────────────────────────────────────────────────────

  function open(opts) {
    opts = opts || {};
    state.neighborhoodKey = String(opts.neighborhoodKey || '').trim();
    if (!state.neighborhoodKey) return;

    state.neighborhood = String(opts.neighborhood || opts.neighborhoodKey || '').trim();
    state.city         = String(opts.city || 'Fortaleza').trim();
    state.params       = { start: opts.start || '', end: opts.end || '' };
    state.tab          = 'resumo';
    state.tabData      = {};
    state.openerEl     = document.activeElement;

    var C = (window.B && window.B.GeoDetail && window.B.GeoDetail.Charts) || {};
    if (C.destroyAll) C.destroyAll();

    buildShell();
    updateHeader();
    setActiveTab('resumo');

    var drawer = getDrawer();
    drawer.classList.add('is-open');
    document.body.classList.add('biomie-drawer-open');

    document.addEventListener('keydown', onKeydown);

    showLoading();
    fetchTab('resumo', function (err, data) {
      if (err) { showError('Falha ao carregar bairro.'); return; }
      state.tabData['resumo'] = data;

      if (data.neighborhood && data.neighborhood.display_name) {
        state.neighborhood = data.neighborhood.display_name;
        updateHeader();
      }

      showContent(renderTabContent('resumo', data));
    });
  }

  function close() {
    var C = (window.B && window.B.GeoDetail && window.B.GeoDetail.Charts) || {};
    if (C.destroyAll) C.destroyAll();

    var drawer = getDrawer();
    drawer.classList.remove('is-open');
    document.body.classList.remove('biomie-drawer-open');
    document.removeEventListener('keydown', onKeydown);

    if (state.abortCtrl) { try { state.abortCtrl.abort(); } catch (e) {} state.abortCtrl = null; }
    if (state.openerEl && typeof state.openerEl.focus === 'function') state.openerEl.focus();
  }

  function onKeydown(e) {
    if (e.key === 'Escape') close();
  }

  window.BIomieGeoDetail = { open: open, close: close };
})();
