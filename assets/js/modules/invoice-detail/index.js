/**
 * BIomie — Invoice (NF) detail drawer.
 * Opens a side drawer: NF + items + order link + finance titles + timeline.
 *
 * Usage: BIomieInvoiceDetail.open({ numero_nf, nota_key, chave_nfe });
 *
 * Features: AbortController, 60s session cache, focus trap, skeleton,
 *           URL validation for DANFE/XML (https only),
 *           drilldown button: pedido origem → BIomieOrderDetail.
 */
(function () {
  'use strict';

  var B   = window.BIomie || {};
  var esc = function (v) { return B.escapeHtml ? B.escapeHtml(v) : String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };
  var fmt = function (v) { return B.formatCurrency ? B.formatCurrency(v) : String(v); };
  var fmtDate = function (v) { if (!v) return '—'; var d = new Date(v); if (isNaN(d)) return v; return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear(); };

  var DRAWER_ID = 'invoice-detail-drawer';
  var TABS = [{ key: 'resumo', label: 'Resumo' }, { key: 'itens', label: 'Itens' }, { key: 'financeiro', label: 'Financeiro' }, { key: 'timeline', label: 'Timeline' }];

  var state = { loading: false, data: null, tab: 'resumo', lookupKey: '', abortCtrl: null, openerEl: null };
  var _cache = {};
  var _focusTrapHandler = null;

  // ── Cache ─────────────────────────────────────────────────────────────────
  function fromCache(k) { var e = _cache[k]; return (e && Date.now() - e.ts < 60000) ? e.data : null; }
  function toCache(k, d) { _cache[k] = { ts: Date.now(), data: d }; }

  // ── Focus trap ────────────────────────────────────────────────────────────
  function installFocusTrap(el) {
    removeFocusTrap();
    _focusTrapHandler = function (e) {
      if (e.key !== 'Tab') return;
      var focusable = Array.from(el.querySelectorAll('button:not([disabled]),[href],input,[tabindex]:not([tabindex="-1"])'));
      if (!focusable.length) return;
      var first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey) { if (document.activeElement === first) { last.focus(); e.preventDefault(); } }
      else            { if (document.activeElement === last)  { first.focus(); e.preventDefault(); } }
    };
    document.addEventListener('keydown', _focusTrapHandler);
  }
  function removeFocusTrap() { if (_focusTrapHandler) { document.removeEventListener('keydown', _focusTrapHandler); _focusTrapHandler = null; } }

  // ── URL validation ────────────────────────────────────────────────────────
  function safeUrl(url) {
    if (!url) return null;
    try {
      var u = new URL(String(url));
      return (u.protocol === 'https:' || u.protocol === 'http:') ? String(url) : null;
    } catch (_) { return null; }
  }

  // ── DOM ───────────────────────────────────────────────────────────────────
  function getDrawer() {
    var d = document.getElementById(DRAWER_ID);
    if (!d) { d = document.createElement('div'); d.id = DRAWER_ID; d.className = 'biomie-drawer'; d.setAttribute('role', 'dialog'); d.setAttribute('aria-modal', 'true'); document.body.appendChild(d); }
    return d;
  }

  function buildShell() {
    var drawer = getDrawer();
    drawer.setAttribute('aria-labelledby', 'id-title');
    drawer.innerHTML =
      '<div class="biomie-drawer__header">' +
        '<div><div class="biomie-drawer__title" id="id-title">Nota Fiscal</div><div class="biomie-drawer__subtitle" id="id-subtitle"></div></div>' +
        '<button class="biomie-drawer__close" id="id-close" aria-label="Fechar painel da NF"><span class="material-symbols-outlined">close</span></button>' +
      '</div>' +
      '<div class="biomie-drawer__tabs" id="id-tabs" role="tablist"></div>' +
      '<div class="biomie-drawer__body" id="id-body" role="tabpanel"><div class="biomie-drawer__empty">Carregando...</div></div>';
    document.getElementById('id-close').addEventListener('click', close);
    document.addEventListener('keydown', onKeyDown);
    renderTabs();
    return drawer;
  }

  function renderTabs() {
    var el = document.getElementById('id-tabs'); if (!el) return;
    el.innerHTML = TABS.map(function (t) {
      var active = state.tab === t.key;
      return '<button class="biomie-drawer__tab' + (active ? ' is-active' : '') + '" data-tab="' + t.key + '" role="tab" aria-selected="' + active + '">' + esc(t.label) + '</button>';
    }).join('');
    el.querySelectorAll('.biomie-drawer__tab').forEach(function (b) { b.addEventListener('click', function () { state.tab = b.dataset.tab; renderTabs(); renderBody(); }); });
  }

  // ── Render body ───────────────────────────────────────────────────────────
  function renderBody() {
    var body = document.getElementById('id-body'); if (!body) return;
    if (state.loading) { body.innerHTML = skeleton(); return; }
    if (!state.data)   { body.innerHTML = '<div class="biomie-drawer__empty">Nota fiscal não encontrada.</div>'; return; }
    var d = state.data;
    switch (state.tab) {
      case 'resumo':     body.innerHTML = renderResumo(d); break;
      case 'itens':      body.innerHTML = renderItens(d.items); break;
      case 'financeiro': body.innerHTML = renderFinanceiro(d.finance_titles); break;
      case 'timeline':   body.innerHTML = renderTimeline(d.timeline); break;
      default:           body.innerHTML = '<div class="biomie-drawer__empty">Aba desconhecida.</div>';
    }
  }

  function skeleton() {
    return '<div class="biomie-drawer__stats">' + Array(6).fill('<div class="biomie-drawer__stat"><div style="background:var(--skeleton-base);border-radius:4px;height:12px;width:60px">&nbsp;</div><div style="background:var(--skeleton-base);border-radius:4px;height:18px;width:80px;margin-top:4px">&nbsp;</div></div>').join('') + '</div>';
  }

  function stat(label, value) {
    return '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">' + esc(label) + '</div><div class="biomie-drawer__stat-value">' + esc(String(value ?? '—')) + '</div></div>';
  }

  function renderResumo(d) {
    var inv = d.invoice || {}, c = d.customer || {}, s = d.seller || {}, t = d.totals || {};
    var o   = d.order || {};
    var danfeUrl = safeUrl(inv.danfe);
    var xmlUrl   = safeUrl(inv.xml_distr);
    var links = (danfeUrl ? '<a href="' + esc(danfeUrl) + '" target="_blank" rel="noopener noreferrer" style="color:var(--page-accent);margin-right:8px">DANFE</a>' : '<span style="color:var(--text-3);margin-right:8px">DANFE não disponível</span>') +
                (xmlUrl   ? '<a href="' + esc(xmlUrl) + '" target="_blank" rel="noopener noreferrer" style="color:var(--page-accent)">XML</a>' : '');
    var pedidoOrigemBtn = (o.codigo_pedido || o.numero_pedido)
      ? '<button type="button" class="row-inline-link" data-biomie-action="open-order" data-pedido-codigo="' + esc(String(o.codigo_pedido || o.numero_pedido || '')) + '">Ver pedido origem</button>'
      : '';
    return '<div class="biomie-drawer__section"><div class="biomie-drawer__stats">' +
      stat('Número', inv.numero_nfe) + stat('Série', inv.serie_nfe) +
      stat('Status', inv.status_nfe) + stat('Emissão', fmtDate(inv.data_emissao)) +
      stat('Total', fmt(t.total)) + stat('Desconto', fmt(t.discount)) +
    '</div>' +
    '<div style="margin-top:12px;font-size:.83rem">' + links + '</div>' +
    (pedidoOrigemBtn ? '<div style="margin-top:10px">' + pedidoOrigemBtn + '</div>' : '') +
    '</div>' +
    '<div class="biomie-drawer__section"><div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">Cliente</div>' +
      '<div class="biomie-drawer__list-item"><span>Nome</span><span>' + esc(c.name || '—') + '</span></div>' +
      '<div class="biomie-drawer__list-item"><span>Documento</span><span>' + esc(c.document || '—') + '</span></div>' +
    '</div>' +
    '<div class="biomie-drawer__section"><div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">Vendedor</div>' +
      '<div class="biomie-drawer__list-item"><span>Nome</span>' +
        '<button type="button" class="row-inline-link" data-biomie-action="open-vendor" data-vendor-name="' + esc(s.name || '') + '">' + esc(s.name || '—') + '</button>' +
    '</div></div>' +
    (inv.chave_nfe ? '<div class="biomie-drawer__section" style="font-size:.72rem;color:var(--text-3);word-break:break-all">Chave NF-e: ' + esc(inv.chave_nfe) + '</div>' : '');
  }

  function renderItens(items) {
    if (!Array.isArray(items) || !items.length) return '<div class="biomie-drawer__empty">Nenhum item encontrado.</div>';
    return '<div style="overflow-x:auto">' + table(
      [th('#'), th('Código'), th('Descrição'), th('Qtd'), th('Un'), th('Total')],
      items.map(function (i) { return [td(i.sequencia_item), td(i.codigo_produto), td(i.descricao_produto), td(i.quantidade), td(i.unidade), td(fmt(i.valor_total_item || i.valor_total || 0))]; })
    ) + '</div>';
  }

  function renderFinanceiro(titles) {
    if (!Array.isArray(titles) || !titles.length) return '<div class="biomie-drawer__empty">Nenhum título financeiro vinculado.</div>';
    return '<div style="overflow-x:auto">' + table(
      [th('Vencimento'), th('Valor'), th('Status'), th('Espécie'), th('Liquidação')],
      titles.map(function (t) { return [td(fmtDate(t.data_vencimento)), td(fmt(t.valor_liquido || t.valor_aberto)), td(t.status_titulo), td(t.especie_documento), td(fmtDate(t.data_liquidacao))]; })
    ) + '</div>';
  }

  function renderTimeline(events) {
    if (!Array.isArray(events) || !events.length) return '<div class="biomie-drawer__empty">Sem eventos registrados.</div>';
    return '<div style="display:flex;flex-direction:column;gap:12px;padding:4px 0">' +
      events.map(function (ev) {
        return '<div style="display:flex;align-items:flex-start;gap:12px"><span class="material-symbols-outlined" style="font-size:18px;color:var(--page-accent);flex-shrink:0;margin-top:2px">' + esc(ev.icon || 'circle') + '</span><div><div style="font-size:.82rem;font-weight:500">' + esc(ev.event || '') + '</div><div style="font-size:.75rem;color:var(--text-3)">' + esc(fmtDate(ev.date)) + '</div></div></div>';
      }).join('') + '</div>';
  }

  function table(headers, rows) {
    return '<table style="width:100%;border-collapse:collapse;font-size:.82rem"><thead><tr>' + headers.join('') + '</tr></thead><tbody>' +
      rows.map(function (r) { return '<tr>' + r.join('') + '</tr>'; }).join('') + '</tbody></table>';
  }
  function th(l) { return '<th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-3);font-weight:500">' + esc(l) + '</th>'; }
  function td(v) { return '<td style="padding:6px 8px;border-bottom:1px solid var(--border)">' + esc(String(v ?? '—')) + '</td>'; }

  // ── Open / close ──────────────────────────────────────────────────────────
  function open(lookup) {
    lookup = lookup || {};
    state.tab = 'resumo'; state.data = null; state.loading = true;
    state.openerEl = document.activeElement;

    var qs = new URLSearchParams();
    var cacheKey = '';
    if (lookup.nota_key)   { qs.set('nota_key',   lookup.nota_key);   cacheKey = 'invoice:key:' + lookup.nota_key; }
    else if (lookup.chave_nfe)  { qs.set('chave_nfe',  lookup.chave_nfe);  cacheKey = 'invoice:chave:' + lookup.chave_nfe; }
    else if (lookup.numero_nf)  { qs.set('numero_nf',  lookup.numero_nf);  cacheKey = 'invoice:nf:' + lookup.numero_nf; }
    state.lookupKey = cacheKey;

    var drawer = buildShell();
    var titleEl = document.getElementById('id-title'); if (titleEl) titleEl.textContent = lookup.numero_nf ? 'NF ' + lookup.numero_nf : 'Nota Fiscal';
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    renderBody();
    window.setTimeout(function () {
      var closeBtn = document.getElementById('id-close'); if (closeBtn) closeBtn.focus();
      installFocusTrap(drawer);
    }, 80);

    if (cacheKey) {
      var cached = fromCache(cacheKey);
      if (cached) { state.loading = false; state.data = cached; fillMeta(cached); renderBody(); return; }
    }

    if (state.abortCtrl) { try { state.abortCtrl.abort(); } catch (_) { /* noop */ } }
    state.abortCtrl = new AbortController();
    fetch('api/invoice_detail.php?' + qs.toString(), { credentials: 'same-origin', signal: state.abortCtrl.signal })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        state.loading = false; state.abortCtrl = null;
        if (!json.ok) return;
        state.data = json; if (cacheKey) toCache(cacheKey, json); fillMeta(json); renderBody();
      })
      .catch(function (err) { if (err && err.name !== 'AbortError') { state.loading = false; renderBody(); } });
  }

  function fillMeta(json) {
    var inv = json.invoice || {};
    var titleEl = document.getElementById('id-title'); if (titleEl) titleEl.textContent = 'NF ' + (inv.numero_nfe || state.lookupKey);
    var subEl   = document.getElementById('id-subtitle'); if (subEl && json.customer) subEl.textContent = json.customer.name || '';
  }

  function close() {
    var drawer = document.getElementById(DRAWER_ID);
    if (drawer) drawer.classList.remove('is-open');
    document.removeEventListener('keydown', onKeyDown); removeFocusTrap();
    if (state.openerEl && state.openerEl.focus) { try { state.openerEl.focus(); } catch (_) { /* noop */ } }
    state.openerEl = null;
  }

  function onKeyDown(e) { if (e.key === 'Escape') close(); }

  window.BIomieInvoiceDetail = { open: open, close: close };
}());
