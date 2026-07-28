/**
 * BIomie — Order detail drawer.
 * Opens a side drawer: order + NFs + items + installments + timeline.
 *
 * Usage: BIomieOrderDetail.open(codigoPedido);
 *
 * Features: AbortController, 60s session cache, focus trap, skeleton,
 *           drilldown buttons (NFs → BIomieInvoiceDetail).
 */
(function () {
  'use strict';

  var B   = window.BIomie || {};
  var esc = function (v) { return B.escapeHtml ? B.escapeHtml(v) : String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };
  var fmt = function (v) { return B.formatCurrency ? B.formatCurrency(v) : String(v); };
  var fmtDate = function (v) {
    if (!v) return '-';
    var raw = String(v);
    var dateOnly = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (dateOnly) return dateOnly[3] + '/' + dateOnly[2] + '/' + dateOnly[1];
    var d = new Date(raw);
    if (isNaN(d)) return raw;
    return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear();
  };

  var DRAWER_ID = 'order-detail-drawer';
  var TABS = [
    { key: 'resumo', label: 'Resumo', icon: 'space_dashboard' },
    { key: 'itens', label: 'Itens', icon: 'inventory_2' },
    { key: 'nfs', label: 'NFs', icon: 'receipt_long' },
    { key: 'parcelas', label: 'Parcelas', icon: 'payments' },
    { key: 'timeline', label: 'Timeline', icon: 'timeline' }
  ];

  var state = { loading: false, error: '', data: null, tab: 'resumo', codigo: '', abortCtrl: null, openerEl: null };
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

  // ── DOM ───────────────────────────────────────────────────────────────────
  function getDrawer() {
    var d = document.getElementById(DRAWER_ID);
    if (!d) { d = document.createElement('div'); d.id = DRAWER_ID; d.className = 'biomie-drawer biomie-drawer--document mag-drawer mag-drawer--right mag-drawer--mobile-medium'; d.setAttribute('role', 'dialog'); d.setAttribute('aria-modal', 'true'); document.body.appendChild(d); }
    return d;
  }

  function buildShell() {
    var drawer = getDrawer();
    drawer.setAttribute('aria-labelledby', 'od-title');
    drawer.innerHTML =
      '<div class="biomie-drawer__header">' +
        '<div class="document-drawer-heading"><span class="document-drawer-heading__icon"><span class="material-symbols-rounded">shopping_cart</span></span><div><div class="biomie-drawer__title" id="od-title">Pedido</div><div class="biomie-drawer__subtitle" id="od-subtitle"></div></div></div>' +
        '<button class="biomie-drawer__close" id="od-close" aria-label="Fechar painel do pedido"><span class="material-symbols-rounded">close</span></button>' +
      '</div>' +
      '<div class="biomie-drawer__tabs" id="od-tabs" role="tablist"></div>' +
      '<div class="biomie-drawer__body" id="od-body" role="tabpanel"><div class="biomie-drawer__empty">Carregando...</div></div>';
    document.getElementById('od-close').addEventListener('click', close);
    document.addEventListener('keydown', onKeyDown);
    renderTabs();
    return drawer;
  }

  function renderTabs() {
    var el = document.getElementById('od-tabs'); if (!el) return;
    el.innerHTML = TABS.map(function (t) {
      var active = state.tab === t.key;
      return '<button class="biomie-drawer__tab' + (active ? ' is-active' : '') + '" data-tab="' + t.key + '" role="tab" aria-selected="' + active + '"><span class="material-symbols-rounded" aria-hidden="true">' + esc(t.icon) + '</span>' + esc(t.label) + '</button>';
    }).join('');
    el.querySelectorAll('.biomie-drawer__tab').forEach(function (b) { b.addEventListener('click', function () { state.tab = b.dataset.tab; renderTabs(); renderBody(); }); });
  }

  // ── Render body ───────────────────────────────────────────────────────────
  function renderBody() {
    var body = document.getElementById('od-body'); if (!body) return;
    if (state.loading) { body.innerHTML = skeleton(); return; }
    if (state.error) { body.innerHTML = '<div class="mag-empty mag-empty--compact"><div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">error</span></div><strong class="mag-empty-title">Não foi possível abrir este pedido</strong><p class="mag-empty-text">' + esc(state.error) + '</p></div>'; return; }
    if (!state.data)   { body.innerHTML = '<div class="biomie-drawer__empty">Pedido não encontrado.</div>'; return; }
    var d = state.data;
    switch (state.tab) {
      case 'resumo':   body.innerHTML = renderResumoPremium(d); break;
      case 'itens':    body.innerHTML = renderItens(d.items); break;
      case 'nfs':      body.innerHTML = renderNfs(d.invoices); break;
      case 'parcelas': body.innerHTML = renderParcelas(d.installments); break;
      case 'timeline': body.innerHTML = renderTimelinePremium(d.timeline); break;
      default:         body.innerHTML = '<div class="biomie-drawer__empty">Aba desconhecida.</div>';
    }
  }

  function skeleton() {
    return '<div class="biomie-drawer__stats">' + Array(6).fill('<div class="biomie-drawer__stat"><div style="background:var(--skeleton-base);border-radius:4px;height:12px;width:60px">&nbsp;</div><div style="background:var(--skeleton-base);border-radius:4px;height:18px;width:80px;margin-top:4px">&nbsp;</div></div>').join('') + '</div>';
  }

  function stat(label, value) {
    return '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">' + esc(label) + '</div><div class="biomie-drawer__stat-value">' + esc(String(value ?? '—')) + '</div></div>';
  }

  function renderResumo(d) {
    var o = d.order || {}, c = o.customer || {}, s = o.seller || {}, t = o.totals || {};
    return '<div class="biomie-drawer__section"><div class="biomie-drawer__stats">' +
      stat('Status', o.status) + stat('Etapa', o.etapa) +
      stat('Emissão', fmtDate(o.data_inclusao)) + stat('Faturamento', fmtDate(o.data_faturamento)) +
      stat('Total', fmt(t.total)) + stat('Desconto', fmt(t.discount)) +
    '</div></div>' +
    '<div class="biomie-drawer__section"><div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">Cliente</div>' +
      '<div class="biomie-drawer__list-item"><span>Nome</span><span>' + esc(c.name || '—') + '</span></div>' +
      '<div class="biomie-drawer__list-item"><span>Documento</span><span>' + esc(c.document || '—') + '</span></div>' +
    '</div>' +
    '<div class="biomie-drawer__section"><div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">Vendedor</div>' +
      '<div class="biomie-drawer__list-item"><span>Nome</span>' +
        '<button type="button" class="row-inline-link" data-biomie-action="open-vendor" data-vendor-name="' + esc(s.name || '') + '">' + esc(s.name || '—') + '</button>' +
    '</div></div>';
  }

  function premiumStat(icon, label, value) {
    return '<div class="biomie-drawer__stat">' +
      '<span class="material-symbols-rounded biomie-drawer__stat-icon" aria-hidden="true">' + esc(icon) + '</span>' +
      '<div class="biomie-drawer__stat-label">' + esc(label) + '</div>' +
      '<div class="biomie-drawer__stat-value">' + esc(String(value == null ? '-' : value)) + '</div>' +
    '</div>';
  }

  function renderResumoPremium(d) {
    var o = d.order || {}, c = o.customer || {}, s = o.seller || {}, t = o.totals || {};
    var customerAttrs = 'data-codigo-cliente-omie="' + esc(c.id || '') + '" data-cliente-documento="' + esc(c.document || '') + '" data-cliente-nome="' + esc(c.name || '') + '" data-pedido-codigo="' + esc(o.codigo_pedido || '') + '"';
    var sellerAttrs = 'data-vendor-id="' + esc(s.id || '') + '" data-vendor-name="' + esc(s.name || '') + '"';
    var invoices = Array.isArray(d.invoices) ? d.invoices : [];
    var installments = Array.isArray(d.installments) ? d.installments : [];
    var customerName = c.name
      ? '<button type="button" class="nf-inline-action document-party-card__name" data-biomie-action="open-customer" ' + customerAttrs + '>' + esc(c.name) + '</button>'
      : '<div class="document-party-card__name">Nao informado</div>';
    var sellerName = s.name || s.id
      ? '<button type="button" class="nf-inline-action document-party-card__name" data-biomie-action="open-vendor" ' + sellerAttrs + '>' + esc(s.name || 'Vendedor sem nome') + '</button>'
      : '<div class="document-party-card__name">Nao informado</div>';

    return '<div class="document-hero">' +
      '<div class="document-hero__main"><div class="document-hero__eyebrow"><span class="material-symbols-rounded" style="font-size:15px">shopping_cart</span>Pedido ' + esc(o.status || 'comercial') + '</div><div class="document-hero__value">' + fmt(t.total || 0) + '</div><div class="document-hero__note">Valor comercial do pedido e seus vinculos fiscais.</div></div>' +
      '<div class="document-hero__aside">' +
        (invoices.length ? '<button type="button" class="document-hero__action" data-biomie-action="open-invoice" data-numero-nf="' + esc(invoices[0].numero_nf || '') + '" data-nota-key="' + esc(invoices[0].nota_key || '') + '"><span class="material-symbols-rounded">receipt_long</span>Abrir NF vinculada</button>' : '<div class="document-hero__note">Pedido ainda sem NF vinculada.</div>') +
      '</div>' +
    '</div>' +
    '<div class="document-section-card"><div class="document-section-title"><span class="material-symbols-rounded">analytics</span>Leitura rapida</div><div class="biomie-drawer__stats">' +
      premiumStat('fact_check', 'Status', o.status || '-') +
      premiumStat('route', 'Etapa', o.etapa || '-') +
      premiumStat('calendar_today', 'Emissao', fmtDate(o.data_inclusao)) +
      premiumStat('receipt_long', 'NFs vinculadas', invoices.length) +
      premiumStat('payments', 'Parcelas', installments.length) +
      premiumStat('sell', 'Desconto', fmt(t.discount || 0)) +
    '</div></div>' +
    '<div class="document-section-card"><div class="document-section-title"><span class="material-symbols-rounded">hub</span>Relacionamentos</div><div class="document-party-grid">' +
      '<div class="document-party-card"><div class="document-party-card__label"><span class="material-symbols-rounded" style="font-size:13px;vertical-align:middle;margin-right:4px">domain</span>Cliente</div>' + customerName + '<div class="document-party-card__meta">' + esc(c.document || 'Documento nao informado') + '</div></div>' +
      '<div class="document-party-card"><div class="document-party-card__label"><span class="material-symbols-rounded" style="font-size:13px;vertical-align:middle;margin-right:4px">person</span>Vendedor</div>' + sellerName + '<div class="document-party-card__meta">' + esc(s.id ? 'Codigo Omie ' + s.id : 'Sem codigo') + '</div></div>' +
    '</div></div>';
  }

  function renderTimelinePremium(events) {
    if (!Array.isArray(events) || !events.length) return '<div class="biomie-drawer__empty">Sem eventos registrados.</div>';
    return '<div class="document-section-card"><div class="document-section-title"><span class="material-symbols-rounded">timeline</span>Linha do tempo</div><div class="document-timeline">' +
      events.map(function (event) {
        return '<div class="document-timeline__item"><span class="document-timeline__icon"><span class="material-symbols-rounded">' + esc(event.icon || 'circle') + '</span></span><div><div class="document-timeline__title">' + esc(event.event || '') + '</div><div class="document-timeline__date">' + esc(fmtDate(event.date)) + '</div></div></div>';
      }).join('') +
    '</div></div>';
  }

  function renderItens(items) {
    if (!Array.isArray(items) || !items.length) return '<div class="mag-empty mag-empty--compact"><div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">inventory_2</span></div><p class="mag-empty-text">Nenhum item encontrado neste pedido.</p></div>';
    return '<div style="overflow-x:auto">' + table(
      [th('#'), th('Código'), th('Descrição'), th('Qtd'), th('Un'), th('Total')],
      items.map(function (i) { return [td(i.sequencia_item), td(i.codigo_produto), td(i.descricao_produto), td(i.quantidade), td(i.unidade), td(fmt(i.valor_unitario ? (i.quantidade * i.valor_unitario) : (i.valor_total || 0)))]; })
    ) + '</div>';
  }

  function renderNfs(invoices) {
    if (!Array.isArray(invoices) || !invoices.length) return '<div class="mag-empty mag-empty--compact"><div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">receipt_long</span></div><p class="mag-empty-text">Nenhuma NF vinculada a este pedido.</p></div>';
    return invoices.map(function (nf) {
      var num    = esc(String(nf.numero_nf || nf.numero_nfe || ''));
      var notaKey = esc(String(nf.nota_key || ''));
      return '<div class="biomie-drawer__list-item">' +
        '<button type="button" class="row-inline-link" data-biomie-action="open-invoice" data-numero-nf="' + num + '" data-nota-key="' + notaKey + '">NF ' + num + '</button>' +
        '<span>' + esc(fmtDate(nf.data_emissao)) + '</span>' +
        '<span class="biomie-drawer__badge">' + esc(String(nf.status_fiscal || nf.status || '')) + '</span>' +
      '</div>';
    }).join('');
  }

  function renderParcelas(installments) {
    if (!Array.isArray(installments) || !installments.length) return '<div class="mag-empty mag-empty--compact"><div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">payments</span></div><p class="mag-empty-text">Nenhuma parcela financeira encontrada para este pedido.</p></div>';
    return '<div style="overflow-x:auto">' + table(
      [th('#'), th('Vencimento'), th('Valor'), th('Status'), th('Liquidação')],
      installments.map(function (p) { return [td(p.numero), td(fmtDate(p.vencimento)), td(fmt(p.valor)), td(p.status), td(fmtDate(p.liquidacao))]; })
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
    return '<table data-mobile-card-table style="width:100%;border-collapse:collapse;font-size:.82rem"><thead><tr>' + headers.join('') + '</tr></thead><tbody>' +
      rows.map(function (r) { return '<tr>' + r.join('') + '</tr>'; }).join('') + '</tbody></table>';
  }
  function th(l) { return '<th style="text-align:left;padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-3);font-weight:500">' + esc(l) + '</th>'; }
  function td(v) { return '<td style="padding:6px 8px;border-bottom:1px solid var(--border)">' + esc(String(v ?? '—')) + '</td>'; }

  // ── Open / close ──────────────────────────────────────────────────────────
  function open(codigoPedido) {
    state.codigo = codigoPedido; state.tab = 'resumo'; state.data = null; state.error = ''; state.loading = true;
    state.openerEl = document.activeElement;
    var drawer = buildShell();
    var titleEl = document.getElementById('od-title'); if (titleEl) titleEl.textContent = 'Pedido ' + codigoPedido;
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    renderBody();
    window.setTimeout(function () {
      var closeBtn = document.getElementById('od-close'); if (closeBtn) closeBtn.focus();
      installFocusTrap(drawer);
    }, 80);

    var k = 'order:' + codigoPedido;
    var cached = fromCache(k);
    if (cached) { state.loading = false; state.data = cached; fillMeta(cached); renderBody(); return; }

    if (state.abortCtrl) { try { state.abortCtrl.abort(); } catch (_) { /* noop */ } }
    state.abortCtrl = new AbortController();
    fetch('api/order_detail.php?codigo_pedido=' + encodeURIComponent(codigoPedido), { credentials: 'same-origin', signal: state.abortCtrl.signal })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        state.loading = false; state.abortCtrl = null;
        if (!json.ok) { state.error = (json.error && json.error.message) || json.message || 'O pedido não foi encontrado ou ainda não foi sincronizado.'; renderBody(); return; }
        state.data = json; toCache(k, json); fillMeta(json); renderBody();
      })
      .catch(function (err) { if (err && err.name !== 'AbortError') { state.loading = false; state.error = err.message || 'Falha ao carregar o pedido.'; renderBody(); } });
  }

  function fillMeta(json) {
    var o = json.order || {};
    var titleEl = document.getElementById('od-title'); if (titleEl) titleEl.textContent = 'Pedido ' + (o.codigo_pedido || state.codigo);
    var subEl   = document.getElementById('od-subtitle'); if (subEl && o.customer) subEl.textContent = o.customer.name || '';
  }

  function close() {
    var drawer = document.getElementById(DRAWER_ID);
    if (drawer) drawer.classList.remove('is-open');
    document.removeEventListener('keydown', onKeyDown); removeFocusTrap();
    if (state.openerEl && state.openerEl.focus) { try { state.openerEl.focus(); } catch (_) { /* noop */ } }
    state.openerEl = null;
  }

  function onKeyDown(e) { if (e.key === 'Escape') close(); }

  window.BIomieOrderDetail = { open: open, close: close };
}());
