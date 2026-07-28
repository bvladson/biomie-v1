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
  var fmtDate = function (v) {
    if (!v) return '-';
    var raw = String(v);
    var dateOnly = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (dateOnly) return dateOnly[3] + '/' + dateOnly[2] + '/' + dateOnly[1];
    var d = new Date(raw);
    if (isNaN(d)) return raw;
    return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear();
  };

  var DRAWER_ID = 'invoice-detail-drawer';
  var TABS = [
    { key: 'resumo', label: 'Resumo', icon: 'space_dashboard' },
    { key: 'itens', label: 'Itens', icon: 'inventory_2' },
    { key: 'financeiro', label: 'Financeiro', icon: 'account_balance_wallet' },
    { key: 'timeline', label: 'Timeline', icon: 'timeline' }
  ];

  var state = { loading: false, error: '', data: null, tab: 'resumo', lookupKey: '', abortCtrl: null, openerEl: null };
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

  function cleanLookupValue(value) {
    return String(value == null ? '' : value).trim();
  }

  function cleanInvoiceNumber(value) {
    var text = cleanLookupValue(value);
    if (!text) return '';
    text = text.replace(/^\s*(nota\s*fiscal|nf-e|nfe|nf)\s*[:#-]?\s*/i, '');
    text = text.replace(/\s+/g, '');
    return text;
  }

  // ── DOM ───────────────────────────────────────────────────────────────────
  function getDrawer() {
    var d = document.getElementById(DRAWER_ID);
    if (!d) { d = document.createElement('div'); d.id = DRAWER_ID; d.className = 'biomie-drawer biomie-drawer--document mag-drawer mag-drawer--right mag-drawer--mobile-medium'; d.setAttribute('role', 'dialog'); d.setAttribute('aria-modal', 'true'); document.body.appendChild(d); }
    return d;
  }

  function buildShell() {
    var drawer = getDrawer();
    drawer.setAttribute('aria-labelledby', 'id-title');
    drawer.innerHTML =
      '<div class="biomie-drawer__header">' +
        '<div class="document-drawer-heading"><span class="document-drawer-heading__icon"><span class="material-symbols-rounded">receipt_long</span></span><div><div class="biomie-drawer__title" id="id-title">Nota Fiscal</div><div class="biomie-drawer__subtitle" id="id-subtitle"></div></div></div>' +
        '<button class="biomie-drawer__close" id="id-close" aria-label="Fechar painel da NF"><span class="material-symbols-rounded">close</span></button>' +
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
      return '<button class="biomie-drawer__tab' + (active ? ' is-active' : '') + '" data-tab="' + t.key + '" role="tab" aria-selected="' + active + '"><span class="material-symbols-rounded" aria-hidden="true">' + esc(t.icon) + '</span>' + esc(t.label) + '</button>';
    }).join('');
    el.querySelectorAll('.biomie-drawer__tab').forEach(function (b) { b.addEventListener('click', function () { state.tab = b.dataset.tab; renderTabs(); renderBody(); }); });
  }

  // ── Render body ───────────────────────────────────────────────────────────
  function renderBody() {
    var body = document.getElementById('id-body'); if (!body) return;
    if (state.loading) { body.innerHTML = skeleton(); return; }
    if (state.error) {
      body.innerHTML = '<div class="mag-empty mag-empty--compact"><div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">error</span></div><strong class="mag-empty-title">Não foi possível abrir esta nota</strong><p class="mag-empty-text">' + esc(state.error) + '</p></div>';
      return;
    }
    if (!state.data)   {
      body.innerHTML = '<div class="mag-empty mag-empty--compact"><div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">receipt_long</span></div><strong class="mag-empty-title">Nota fiscal não encontrada</strong><p class="mag-empty-text">Verifique se a NF foi sincronizada do Omie.</p></div>';
      return;
    }
    var d = state.data;
    switch (state.tab) {
      case 'resumo':     body.innerHTML = renderResumoPremium(d); break;
      case 'itens':      body.innerHTML = renderItens(d.items); break;
      case 'financeiro': body.innerHTML = renderFinanceiro(d.finance_titles); break;
      case 'timeline':   body.innerHTML = renderTimelinePremium(d.timeline); break;
      default:           body.innerHTML = '<div class="biomie-drawer__empty">Aba desconhecida.</div>';
    }
  }

  function skeleton() {
    return '<div class="biomie-drawer__stats">' + Array(6).fill('<div class="biomie-drawer__stat"><div style="background:var(--skeleton-base);border-radius:4px;height:12px;width:60px">&nbsp;</div><div style="background:var(--skeleton-base);border-radius:4px;height:18px;width:80px;margin-top:4px">&nbsp;</div></div>').join('') + '</div>';
  }

  function stat(label, value) {
    return '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">' + esc(label) + '</div><div class="biomie-drawer__stat-value">' + esc(String(value ?? '—')) + '</div></div>';
  }

  // H6/T5 — scope badge + business explanation for the NF drawer
  var SCOPE_BADGE = {
    commercial: {
      label: 'Orçamento-PD — base comercial',
      color: 'var(--green-500,#16a34a)', bg: 'var(--green-50,#f0fdf4)', icon: 'shopping_bag',
      alertBg: 'var(--green-50,#f0fdf4)', alertBorder: 'var(--green-200,#bbf7d0)',
      alertColor: 'var(--green-800,#166534)', alertIcon: 'check_circle',
      explanation: 'Esta NF compõe o faturamento comercial, ranking, mix, metas e Plano de Voo. KPI principal: <strong>Base Comercial NF</strong> (total − frete − outras despesas − seguro). IPI permanece na base.',
    },
    remittance: {
      label: 'Remessa de Produto — conferência fiscal',
      color: 'var(--yellow-700,#b45309)', bg: 'var(--yellow-50,#fffbeb)', icon: 'local_shipping',
      alertBg: 'var(--yellow-50,#fffbeb)', alertBorder: 'var(--yellow-200,#fde68a)',
      alertColor: 'var(--yellow-800,#92400e)', alertIcon: 'info',
      explanation: 'Esta NF registra movimentação de mercadoria e <strong>não compõe</strong> faturamento comercial, ticket médio, ranking, metas, rota comercial, participação de produtos ou mapa de vendas.',
    },
    unknown: {
      label: 'Operação não identificada',
      color: 'var(--text-3,#94a3b8)', bg: 'var(--surface-2,#f8fafc)', icon: 'help_outline',
      alertBg: 'var(--surface-2,#f8fafc)', alertBorder: 'var(--border,#e2e8f0)',
      alertColor: 'var(--text-2,#64748b)', alertIcon: 'help_outline',
      explanation: 'Esta NF está fora dos KPIs comerciais até classificação.',
    },
  };

  function renderScopeBadge(inv) {
    var scope = inv.operation_scope;
    if (!scope) return '';
    var cfg = SCOPE_BADGE[scope] || SCOPE_BADGE.unknown;
    // Raw codes look like "1", "4", "5.915", "6.101" — digits-only or CFOP pattern.
    var opName = inv.omie_operation_name || '';
    var isRawCode = /^\d+(\.\d+)?$/.test(opName.trim());
    var opDetail = (opName && !isRawCode) ? '<div style="font-size:.7rem;margin-top:3px;opacity:.8">' + esc(opName) + '</div>' : '';
    var badge = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-size:.72rem;font-weight:600;color:' + cfg.color + ';background:' + cfg.bg + ';margin-bottom:8px">' +
      '<span class="material-symbols-rounded" style="font-size:12px;vertical-align:middle">' + cfg.icon + '</span>' + esc(cfg.label) + '</span>';
    var alert = '<div role="alert" style="display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border-radius:8px;background:' + cfg.alertBg + ';border:1px solid ' + cfg.alertBorder + ';font-size:.78rem;color:' + cfg.alertColor + ';margin-bottom:8px">' +
      '<span class="material-symbols-rounded" style="font-size:16px;flex-shrink:0;margin-top:1px">' + cfg.alertIcon + '</span>' +
      '<div>' + cfg.explanation + opDetail + '</div></div>';
    return badge + alert;
  }

  function renderComposicaoNF(t, scope) {
    if (scope !== 'commercial') return '';
    var zero = '<span style="color:var(--text-3)">R$ 0,00</span>';
    var frete  = t.freight       && t.freight       !== 0 ? t.freight       : null;
    var outras = t.other_expenses && t.other_expenses !== 0 ? t.other_expenses : null;
    var seguro = t.insurance      && t.insurance      !== 0 ? t.insurance      : null;
    var ipi    = t.ipi            && t.ipi            !== 0 ? t.ipi            : null;

    var freteBadge  = frete  ? ' <span class="nf-freight-indicator" title="NF com frete">+F</span>' : '';
    var outrasBadge = outras ? ' <span class="nf-outros-indicator" title="NF com outras despesas">+O</span>' : '';
    var seguroBadge = seguro ? ' <span class="nf-seguro-indicator" title="NF com seguro">+S</span>' : '';

    var rowStyle      = 'display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:.8rem;';
    var rowStyleMuted = rowStyle + 'color:var(--text-2);';
    var rowStyleNC    = rowStyle + 'color:var(--yellow-700,#b45309);';
    var divider       = '<div style="border-top:1px solid var(--border);margin:6px 0"></div>';

    return '<div class="biomie-drawer__section">' +
      '<div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:10px">Composição da NF</div>' +
      '<div style="' + rowStyleMuted + '"><span>Total da NF</span><span>' + fmt(t.total) + '</span></div>' +
      '<div style="' + rowStyleMuted + '"><span style="display:flex;align-items:center;gap:4px">IPI <span style="font-size:.65rem;color:var(--green-700,#15803d);background:var(--green-50,#f0fdf4);padding:1px 4px;border-radius:3px;font-weight:600">dentro da base</span></span><span>' + (ipi ? fmt(ipi) : zero) + '</span></div>' +
      '<div style="' + (frete ? rowStyleNC : rowStyleMuted) + '"><span>Frete' + freteBadge + '</span><span>' + (frete ? fmt(frete) : zero) + '</span></div>' +
      '<div style="' + (outras ? rowStyleNC : rowStyleMuted) + '"><span>Outras despesas' + outrasBadge + '</span><span>' + (outras ? fmt(outras) : zero) + '</span></div>' +
      '<div style="' + (seguro ? rowStyleNC : rowStyleMuted) + '"><span>Seguro' + seguroBadge + '</span><span>' + (seguro ? fmt(seguro) : zero) + '</span></div>' +
      divider +
      '<div style="' + rowStyle + 'font-weight:700"><span style="color:var(--green-700,#15803d)">Base Comercial NF</span><span style="color:var(--green-700,#15803d)">' + fmt(t.base_comercial || t.total) + '</span></div>' +
      '<div style="font-size:.7rem;color:var(--text-3);margin-top:8px">Frete, outras despesas e seguro não entram na base comercial.</div>' +
    '</div>';
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
    var baseComercialStat = (inv.operation_scope === 'commercial' && t.base_comercial != null)
      ? stat('Base Comercial NF', fmt(t.base_comercial))
      : stat('Total NF', fmt(t.total));
    return renderScopeBadge(inv) +
    '<div class="biomie-drawer__section"><div class="biomie-drawer__stats">' +
      stat('Número', inv.numero_nfe) + stat('Série', inv.serie_nfe) +
      stat('Status', inv.status_nfe) + stat('Emissão', fmtDate(inv.data_emissao)) +
      baseComercialStat + stat('Desconto', fmt(t.discount)) +
    '</div>' +
    '<div style="margin-top:12px;font-size:.83rem">' + links + '</div>' +
    (pedidoOrigemBtn ? '<div style="margin-top:10px">' + pedidoOrigemBtn + '</div>' : '') +
    '</div>' +
    renderComposicaoNF(t, inv.operation_scope) +
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

  function premiumStat(icon, label, value) {
    return '<div class="biomie-drawer__stat">' +
      '<span class="material-symbols-rounded biomie-drawer__stat-icon" aria-hidden="true">' + esc(icon) + '</span>' +
      '<div class="biomie-drawer__stat-label">' + esc(label) + '</div>' +
      '<div class="biomie-drawer__stat-value">' + esc(String(value == null ? '-' : value)) + '</div>' +
    '</div>';
  }

  function premiumParty(icon, label, name, meta, action, attrs) {
    var nameHtml = action
      ? '<button type="button" class="nf-inline-action document-party-card__name" data-biomie-action="' + action + '" ' + attrs + '>' + esc(name || 'Nao informado') + '</button>'
      : '<div class="document-party-card__name">' + esc(name || 'Nao informado') + '</div>';
    return '<div class="document-party-card">' +
      '<div class="document-party-card__label"><span class="material-symbols-rounded" style="font-size:13px;vertical-align:middle;margin-right:4px">' + esc(icon) + '</span>' + esc(label) + '</div>' +
      nameHtml +
      '<div class="document-party-card__meta">' + esc(meta || 'Sem informacao adicional') + '</div>' +
    '</div>';
  }

  function renderResumoPremium(d) {
    var inv = d.invoice || {}, c = d.customer || {}, s = d.seller || {}, t = d.totals || {}, o = d.order || {};
    var isCommercial = inv.operation_scope === 'commercial';
    var scopeLabel = isCommercial ? 'Orcamento-PD autorizado' : (inv.operation_scope === 'remittance' ? 'Remessa fiscal' : 'Documento fiscal');
    var mainValue = isCommercial && t.base_comercial != null ? t.base_comercial : t.total;
    var orderCode = String(o.codigo_pedido || o.numero_pedido || '');
    var danfeUrl = safeUrl(inv.danfe);
    var xmlUrl = safeUrl(inv.xml_distr);
    var actions = '';

    if (orderCode) {
      actions += '<button type="button" class="document-hero__action" data-biomie-action="open-order" data-pedido-codigo="' + esc(orderCode) + '"><span class="material-symbols-rounded">shopping_cart</span>Abrir pedido ' + esc(orderCode) + '</button>';
    }
    if (danfeUrl) {
      actions += '<a class="document-hero__action" href="' + esc(danfeUrl) + '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-rounded">picture_as_pdf</span>Abrir DANFE</a>';
    }
    if (xmlUrl) {
      actions += '<a class="document-hero__action" href="' + esc(xmlUrl) + '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-rounded">code</span>Baixar XML</a>';
    }
    if (!actions) {
      actions = '<div class="document-hero__note">Pedido, DANFE e XML ainda nao estao disponiveis para este documento.</div>';
    }

    var customerAttrs = 'data-codigo-cliente-omie="' + esc(c.id || '') + '" data-cliente-documento="' + esc(c.document || '') + '" data-cliente-nome="' + esc(c.name || '') + '" data-pedido-codigo="' + esc(orderCode) + '"';
    var sellerAttrs = 'data-vendor-id="' + esc(s.id || '') + '" data-vendor-name="' + esc(s.name || '') + '"';

    return '<div class="document-hero">' +
      '<div class="document-hero__main">' +
        '<div class="document-hero__eyebrow"><span class="material-symbols-rounded" style="font-size:15px">verified</span>' + esc(scopeLabel) + '</div>' +
        '<div class="document-hero__value">' + fmt(mainValue || 0) + '</div>' +
        '<div class="document-hero__note">' + (isCommercial ? 'Base comercial utilizada no realizado, ranking e metas.' : 'Valor fiscal do documento no escopo selecionado.') + '</div>' +
      '</div>' +
      '<div class="document-hero__aside">' + actions + '</div>' +
    '</div>' +
    '<div class="document-section-card"><div class="document-section-title"><span class="material-symbols-rounded">analytics</span>Leitura rapida</div><div class="biomie-drawer__stats">' +
      premiumStat('tag', 'Numero', inv.numero_nfe || '-') +
      premiumStat('calendar_today', 'Emissao', fmtDate(inv.data_emissao)) +
      premiumStat('fact_check', 'Status', inv.status_nfe || '-') +
      premiumStat('inventory_2', 'Itens', Array.isArray(d.items) ? d.items.length : 0) +
      premiumStat('sell', 'Desconto', fmt(t.discount || 0)) +
      premiumStat('payments', 'Total fiscal', fmt(t.total || 0)) +
    '</div></div>' +
    renderCompositionPremium(t, isCommercial) +
    '<div class="document-section-card"><div class="document-section-title"><span class="material-symbols-rounded">hub</span>Relacionamentos</div><div class="document-party-grid">' +
      premiumParty('domain', 'Cliente', c.name, c.document, 'open-customer', customerAttrs) +
      premiumParty('person', 'Vendedor', s.name, s.id ? 'Codigo Omie ' + s.id : '', s.name ? 'open-vendor' : '', sellerAttrs) +
    '</div></div>' +
    (inv.chave_nfe ? '<div class="document-section-card"><div class="document-section-title"><span class="material-symbols-rounded">key</span>Chave NF-e</div><div class="document-party-card__meta" style="word-break:break-all">' + esc(inv.chave_nfe) + '</div></div>' : '');
  }

  function renderCompositionPremium(t, isCommercial) {
    var rows = [
      ['Mercadorias', t.subtotal],
      ['Desconto', t.discount],
      ['IPI', t.ipi],
      ['Frete', t.freight],
      ['Outras despesas', t.other_expenses],
      ['Seguro', t.insurance],
      ['Total fiscal', t.total]
    ];
    return '<div class="document-section-card"><div class="document-section-title"><span class="material-symbols-rounded">account_tree</span>Composicao da NF</div><div class="document-composition">' +
      rows.map(function (row) {
        return '<div class="document-composition__row"><span>' + esc(row[0]) + '</span><strong>' + fmt(row[1] || 0) + '</strong></div>';
      }).join('') +
      (isCommercial ? '<div class="document-composition__row document-composition__row--accent"><span>Base Comercial NF</span><strong>' + fmt(t.base_comercial || t.total || 0) + '</strong></div>' : '') +
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
    if (!Array.isArray(items) || !items.length) return '<div class="mag-empty mag-empty--compact"><div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">inventory_2</span></div><p class="mag-empty-text">Nenhum item encontrado nesta NF.</p></div>';
    return '<div style="overflow-x:auto">' + table(
      [th('#'), th('Código'), th('Descrição'), th('Qtd'), th('Un'), th('Total')],
      items.map(function (i) { return [td(i.sequencia_item), td(i.codigo_produto), td(i.descricao_produto), td(i.quantidade), td(i.unidade), td(fmt(i.valor_total_item || i.valor_total || 0))]; })
    ) + '</div>';
  }

  function renderFinanceiro(titles) {
    if (!Array.isArray(titles) || !titles.length) return '<div class="mag-empty mag-empty--compact"><div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">account_balance_wallet</span></div><p class="mag-empty-text">Nenhum título financeiro vinculado a esta NF.</p></div>';
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
    var requestedTab = cleanLookupValue(lookup.initial_tab);
    lookup = {
      nota_key: cleanLookupValue(lookup.nota_key),
      chave_nfe: cleanLookupValue(lookup.chave_nfe),
      numero_nf: cleanInvoiceNumber(lookup.numero_nf || lookup.codigo || lookup.nf_code),
      initial_tab: requestedTab
    };
    state.tab = TABS.some(function (tab) { return tab.key === requestedTab; }) ? requestedTab : 'resumo'; state.data = null; state.error = ''; state.loading = true;
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

    if (!cacheKey) {
      state.loading = false;
      state.data = null;
      renderBody();
      return;
    }

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
        if (!json.ok) { state.error = (json.error && json.error.message) || json.message || 'A nota fiscal não foi encontrada ou ainda não foi sincronizada.'; renderBody(); return; }
        state.data = json; if (cacheKey) toCache(cacheKey, json); fillMeta(json); renderBody();
      })
      .catch(function (err) { if (err && err.name !== 'AbortError') { state.loading = false; state.error = err.message || 'Falha ao carregar a nota fiscal.'; renderBody(); } });
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
