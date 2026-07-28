/* ═══════════════════════════════════════════════════════════════════════════
   BIomie — Busca global (omnibox do topbar) · v3
   Consulta api/global_search.php (escopo por empresa) e renderiza resultados
   agrupados: notas, pedidos, títulos financeiros, clientes, produtos e
   vendedores. Cada item carrega data-biomie-action (drawer via drilldown-router,
   onde presente) e href de fallback.
   v2: múltiplas instâncias (desktop + overlay mobile), busca por valor,
   chave NFe completa e títulos/boletos.
   v3: histórico por empresa (buscas + itens abertos, reabre o drawer direto),
   destaque do termo, estados ricos (vazio com dicas, erro com retry, loading
   com termo), contagem por grupo + "ver todos", atalhos / e Ctrl/Cmd+K,
   aria-activedescendant + anúncio de resultados para leitores de tela.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var MIN = 2;
  var RECENT_MAX_QUERIES = 5;
  var RECENT_MAX_ITEMS = 6;

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function money(v) {
    var n = Number(v || 0);
    try { return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
    catch (e) { return 'R$ ' + n.toFixed(2); }
  }
  function ptDate(v) {
    if (!v) return '';
    var m = String(v).slice(0, 10).split('-');
    return m.length === 3 ? (m[2] + '/' + m[1] + '/' + m[0]) : String(v);
  }

  // ── Histórico por empresa (localStorage) ───────────────────────────────────
  function recentKey() {
    var slug = (document.body && document.body.getAttribute('data-company')) || 'default';
    return 'biomie_omni_recent::' + slug;
  }
  function loadRecent() {
    try {
      var raw = window.localStorage.getItem(recentKey());
      var data = raw ? JSON.parse(raw) : null;
      return {
        queries: (data && Array.isArray(data.queries)) ? data.queries : [],
        items: (data && Array.isArray(data.items)) ? data.items : []
      };
    } catch (e) { return { queries: [], items: [] }; }
  }
  function saveRecent(data) {
    try { window.localStorage.setItem(recentKey(), JSON.stringify(data)); } catch (e) {}
  }
  function rememberQuery(q) {
    if (!q || q.length < MIN) return;
    var data = loadRecent();
    data.queries = [q].concat(data.queries.filter(function (x) { return x !== q; })).slice(0, RECENT_MAX_QUERIES);
    saveRecent(data);
  }
  function itemIdentity(type, it) {
    return type + '|' + (it.nota_key || it.codigo || it.documento || it.id || it.code || it.nome || '');
  }
  function rememberItem(type, icon, it) {
    var data = loadRecent();
    var id = itemIdentity(type, it);
    data.items = [{ type: type, icon: icon, it: it }]
      .concat(data.items.filter(function (x) { return itemIdentity(x.type, x.it || {}) !== id; }))
      .slice(0, RECENT_MAX_ITEMS);
    saveRecent(data);
  }
  function clearRecent() { saveRecent({ queries: [], items: [] }); }

  // ── Destaque do termo (aplicado sobre texto JÁ escapado) ───────────────────
  function makeHighlighter(query) {
    var q = String(query || '').trim();
    if (q.length < MIN) return function (s) { return s; };
    var escapedQ = esc(q).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var re;
    try { re = new RegExp('(' + escapedQ + ')', 'gi'); } catch (e) { return function (s) { return s; }; }
    return function (s) { return String(s).replace(re, '<mark class="biomie-omni-mark">$1</mark>'); };
  }

  // ── Mapeia cada tipo para icone/label/sub/atributos/href ──────────────────
  function renderItem(type, groupIcon, it, hl, optId) {
    hl = hl || function (s) { return s; };
    var icon = groupIcon, label = '', sub = '', href = '#', data = '';

    if (type === 'invoice') {
      label = 'NF ' + hl(esc(it.numero_nf || '')) + (it.serie_nf ? '/' + esc(it.serie_nf) : '');
      sub   = [hl(esc(it.cliente || '')), it.pedido ? ('Pedido ' + hl(esc(it.pedido))) : '', hl(esc(money(it.valor))), ptDate(it.data)].filter(Boolean).join(' · ');
      href  = 'pedidos.php?open=invoice&nota_key=' + encodeURIComponent(it.nota_key || '') + '&nf=' + encodeURIComponent(it.numero_nf || '');
      data  = 'data-biomie-action="open-invoice"'
            + ' data-nota-key="' + esc(it.nota_key || '') + '"'
            + ' data-numero-nf="' + esc(it.numero_nf || '') + '"'
            + ' data-chave-nfe="' + esc(it.chave_nfe || '') + '"';
    } else if (type === 'order') {
      label = 'Pedido ' + hl(esc(it.codigo || ''));
      sub   = [hl(esc(it.cliente || '')), it.status ? esc(it.status) : '', hl(esc(money(it.valor))), ptDate(it.data)].filter(Boolean).join(' · ');
      href  = 'pedidos.php?open=order&pedido_codigo=' + encodeURIComponent(it.codigo || '');
      data  = 'data-biomie-action="open-order" data-pedido-codigo="' + esc(it.codigo || '') + '"';
    } else if (type === 'finance') {
      label = 'Título ' + hl(esc(it.documento || ''));
      sub   = [hl(esc(it.cliente || '')), it.vendedor ? esc(it.vendedor) : '', it.status ? esc(it.status) : '', hl(esc(money(it.valor))), it.vencimento ? ('vence ' + ptDate(it.vencimento)) : ''].filter(Boolean).join(' · ');
      href  = 'financeiro.php?fin_q=' + encodeURIComponent(it.documento || it.cliente || '');
      if (it.nota_key || it.chave_nfe) {
        data = 'data-biomie-action="open-invoice"'
              + ' data-nota-key="' + esc(it.nota_key || '') + '"'
              + ' data-chave-nfe="' + esc(it.chave_nfe || '') + '"'
              + ' data-initial-tab="financeiro"';
      } else {
        data = 'data-biomie-action="open-customer"'
              + ' data-codigo-cliente-omie="' + esc(it.cliente_id || '') + '"'
              + ' data-cliente-documento="' + esc(it.cliente_doc || '') + '"'
              + ' data-cliente-nome="' + esc(it.cliente || '') + '"'
              + ' data-initial-tab="financeiro"';
      }
    } else if (type === 'customer') {
      label = hl(esc(it.nome || it.fantasia || 'Cliente'));
      sub   = [it.fantasia && it.fantasia !== it.nome ? hl(esc(it.fantasia)) : '', hl(esc(it.doc || ''))].filter(Boolean).join(' · ');
      href  = 'pedidos.php?open=customer&codigo_cliente_omie=' + encodeURIComponent(it.id || '') + '&document=' + encodeURIComponent(it.doc || '');
      data  = 'data-biomie-action="open-customer"'
            + ' data-codigo-cliente-omie="' + esc(it.id || '') + '"'
            + ' data-cliente-nome="' + esc(it.nome || '') + '"'
            + ' data-cliente-documento="' + esc(it.doc || '') + '"';
    } else if (type === 'product') {
      label = hl(esc(it.nome || it.code || 'Produto'));
      sub   = [it.count ? (it.count + ' NFs') : '', it.code ? ('cod ' + hl(esc(it.code))) : ''].filter(Boolean).join(' · ');
      href  = 'mercadorias.php?open=product&product_code=' + encodeURIComponent(it.code || '');
      data  = 'data-biomie-action="open-product"'
            + ' data-product-code="' + esc(it.code || '') + '"'
            + ' data-product-name="' + esc(it.nome || '') + '"';
    } else if (type === 'vendor') {
      label = hl(esc(it.nome || 'Vendedor'));
      sub   = 'Vendedor';
      href  = 'vendedores.php?open=vendor&id=' + encodeURIComponent(it.id || '');
      data  = 'data-biomie-action="open-vendor"'
            + ' data-vendor-id="' + esc(it.id || '') + '"'
            + ' data-vendor-name="' + esc(it.nome || '') + '"';
    }

    return '<a class="biomie-omni-item" role="option"' + (optId ? ' id="' + optId + '"' : '') + ' href="' + href + '" ' + data
      + ' data-search-result-type="' + esc(type) + '" data-biomie-search-origin="global">'
      + '<span class="material-symbols-rounded biomie-omni-item__icon" aria-hidden="true">' + esc(icon) + '</span>'
      + '<span class="biomie-omni-item__body">'
      + '<span class="biomie-omni-item__label">' + label + '</span>'
      + (sub ? '<span class="biomie-omni-item__sub">' + sub + '</span>' : '')
      + '</span><span class="biomie-omni-item__cta">Detalhar<span class="material-symbols-rounded" aria-hidden="true">chevron_right</span></span></a>';
  }

  // "Ver todos" — deep link por grupo para a listagem com a busca aplicada.
  function groupSeeAllHref(type, q) {
    var enc = encodeURIComponent(q);
    if (type === 'invoice' || type === 'order') return 'pedidos.php?q=' + enc;
    if (type === 'finance') return 'financeiro.php?fin_q=' + enc;
    return '';
  }

  // ── Instância de busca (desktop ou mobile) ─────────────────────────────────
  var instanceSeq = 0;

  function bindSearch(input, panel, opts) {
    opts = opts || {};
    var debounceTimer = 0;
    var lastQuery = '';
    var activeIndex = -1;
    var portaled = false;
    var controller = null;
    var instanceId = ++instanceSeq;
    var clearButton = input.parentElement ? input.parentElement.querySelector('[data-omnisearch-clear]') : null;

    // Portal: move o painel para <body> e posiciona fixo sob o input. Escapa de
    // qualquer stacking context / containing block do topbar (backdrop-filter,
    // sticky, overflow) — causa de dropdowns invisíveis.
    function positionPortal() {
      if (!opts.portal) return;
      if (!portaled) {
        document.body.appendChild(panel);
        panel.classList.add('biomie-omnisearch__panel--portal');
        portaled = true;
      }
      var r = input.getBoundingClientRect();
      var width = Math.min(Math.max(r.width, 360), window.innerWidth - 16);
      var left = Math.min(r.left, window.innerWidth - width - 8);
      panel.style.left = Math.max(8, left) + 'px';
      panel.style.top = (r.bottom + 8) + 'px';
      panel.style.width = width + 'px';
    }

    function openPanel() {
      positionPortal();
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }
    function closePanel() {
      panel.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      activeIndex = -1;
    }

    if (opts.portal) {
      window.addEventListener('resize', function () { if (!panel.hidden) positionPortal(); });
      window.addEventListener('scroll', function () { if (!panel.hidden) closePanel(); }, { passive: true });
    }

    function announce(msg) {
      var live = panel.querySelector('[data-omni-live]');
      if (live) live.textContent = msg;
    }

    function stateHtml(inner) {
      return '<div class="biomie-omni-state">' + inner + '</div>'
        + '<span class="biomie-omni-live" data-omni-live role="status" aria-live="polite"></span>';
    }

    function setLoading(q) {
      panel.innerHTML = stateHtml('<span class="biomie-omni-spinner" aria-hidden="true"></span>Buscando “' + esc(q) + '”…');
      openPanel();
    }

    function setError() {
      panel.innerHTML = stateHtml(
        'Não foi possível buscar agora.'
        + '<button type="button" class="biomie-omni-retry" data-omni-retry>'
        + '<span class="material-symbols-rounded" aria-hidden="true">refresh</span>Tentar novamente</button>'
      );
      var btn = panel.querySelector('[data-omni-retry]');
      if (btn) btn.addEventListener('click', function () { if (lastQuery) { setLoading(lastQuery); runSearch(lastQuery); } });
      openPanel();
    }

    function setEmpty(q) {
      panel.innerHTML = stateHtml(
        'Nada para “' + esc(q) + '” nesta empresa.'
        + '<span class="biomie-omni-hint">Você pode buscar por NF, pedido, cliente, CNPJ, produto, vendedor, '
        + 'valor (ex.: 1.540,00) ou chave NFe completa.</span>'
      );
      announce('Nenhum resultado para ' + q);
      openPanel();
    }

    function bindItemEvents() {
      Array.prototype.forEach.call(panel.querySelectorAll('.biomie-omni-item'), function (el) {
        el.addEventListener('click', function () {
          var recent = el.getAttribute('data-omni-recent-json');
          try {
            var meta = recent ? JSON.parse(recent) : null;
            if (meta) rememberItem(meta.type, meta.icon, meta.it);
          } catch (e) {}
          if (lastQuery) rememberQuery(lastQuery);
          closePanel();
          if (typeof opts.onSelect === 'function') opts.onSelect();
        });
      });
      Array.prototype.forEach.call(panel.querySelectorAll('[data-omni-run-query]'), function (el) {
        el.addEventListener('click', function () {
          var q = el.getAttribute('data-omni-run-query') || '';
          input.value = q;
          lastQuery = q;
          if (clearButton) clearButton.hidden = q.length === 0;
          setLoading(q);
          runSearch(q);
          input.focus();
        });
      });
      var clearHist = panel.querySelector('[data-omni-clear-recent]');
      if (clearHist) clearHist.addEventListener('click', function () { clearRecent(); closePanel(); input.focus(); });
    }

    function attachItemMeta(type, icon, it, html) {
      // guarda o item cru no atributo para alimentar o histórico ao clicar
      var json = esc(JSON.stringify({ type: type, icon: icon, it: it }));
      return html.replace('class="biomie-omni-item"', 'class="biomie-omni-item" data-omni-recent-json="' + json + '"');
    }

    function items() { return panel.querySelectorAll('.biomie-omni-item'); }
    function setActive(idx) {
      var list = items();
      if (!list.length) return;
      if (idx < 0) idx = list.length - 1;
      if (idx >= list.length) idx = 0;
      Array.prototype.forEach.call(list, function (el, i) {
        el.classList.toggle('is-active', i === idx);
        el.setAttribute('aria-selected', i === idx ? 'true' : 'false');
      });
      activeIndex = idx;
      var el = list[idx];
      if (el) {
        if (el.id) input.setAttribute('aria-activedescendant', el.id);
        if (el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
      }
    }

    function render(payload) {
      var groups = (payload && payload.groups) || [];
      if (!groups.length) { setEmpty(lastQuery); return; }

      var hl = makeHighlighter(lastQuery);
      var optSeq = 0;
      var html = '';
      groups.forEach(function (g) {
        var count = (g.items || []).length;
        var seeAll = groupSeeAllHref(g.type, lastQuery);
        html += '<div class="biomie-omni-group">'
          + '<div class="biomie-omni-group__title">'
          + '<span class="material-symbols-rounded" aria-hidden="true">' + esc(g.icon || 'search') + '</span>'
          + esc(g.label || '')
          + '<span class="biomie-omni-group__count">' + count + '</span>'
          + (seeAll ? '<a class="biomie-omni-group__all" href="' + seeAll + '">ver todos</a>' : '')
          + '</div>';
        (g.items || []).forEach(function (it) {
          var optId = 'biomie-omni-' + instanceId + '-opt-' + (optSeq++);
          html += attachItemMeta(g.type, g.icon || 'search', it, renderItem(g.type, g.icon || 'search', it, hl, optId));
        });
        html += '</div>';
      });

      panel.innerHTML = '<div class="biomie-omni-summary"><span>' + esc(String(payload.total || 0)) + ' resultado(s)</span>'
        + '<small>Enter abre o primeiro · ↑↓ navegam · clique detalha sem sair da página</small></div>'
        + html
        + '<span class="biomie-omni-live" data-omni-live role="status" aria-live="polite"></span>';
      openPanel();
      activeIndex = -1;
      announce((payload.total || 0) + ' resultados para ' + lastQuery);
      bindItemEvents();
    }

    // Painel inicial (campo vazio): itens abertos + buscas recentes da empresa.
    function renderRecent() {
      var data = loadRecent();
      if (!data.items.length && !data.queries.length) return false;

      var html = '';
      if (data.items.length) {
        html += '<div class="biomie-omni-group">'
          + '<div class="biomie-omni-group__title"><span class="material-symbols-rounded" aria-hidden="true">history</span>'
          + 'Abertos recentemente</div>';
        var optSeq = 0;
        data.items.forEach(function (entry) {
          if (!entry || !entry.type || !entry.it) return;
          var optId = 'biomie-omni-' + instanceId + '-recent-' + (optSeq++);
          html += attachItemMeta(entry.type, entry.icon || 'search', entry.it,
            renderItem(entry.type, entry.icon || 'search', entry.it, null, optId));
        });
        html += '</div>';
      }
      if (data.queries.length) {
        html += '<div class="biomie-omni-group"><div class="biomie-omni-group__title">'
          + '<span class="material-symbols-rounded" aria-hidden="true">search</span>Buscas recentes</div>'
          + '<div class="biomie-omni-chips">'
          + data.queries.map(function (q) {
              return '<button type="button" class="biomie-omni-chip" data-omni-run-query="' + esc(q) + '">' + esc(q) + '</button>';
            }).join('')
          + '</div></div>';
      }
      html += '<div class="biomie-omni-footer">'
        + '<button type="button" class="biomie-omni-footer__clear" data-omni-clear-recent>'
        + '<span class="material-symbols-rounded" aria-hidden="true">delete_sweep</span>Limpar histórico</button></div>'
        + '<span class="biomie-omni-live" data-omni-live role="status" aria-live="polite"></span>';

      panel.innerHTML = html;
      openPanel();
      activeIndex = -1;
      bindItemEvents();
      return true;
    }

    async function runSearch(q) {
      try {
        if (controller && controller.abort) controller.abort();
        controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var baseMeta = document.querySelector('meta[name="biomie-base-url"]');
        var base = (baseMeta && baseMeta.content) || '/biomie/';
        var url = base.replace(/\/?$/, '/') + 'api/global_search.php?q=' + encodeURIComponent(q) + '&limit=6';
        var payload = window.fetchJson
          ? await window.fetchJson(url, { timeoutMs: 12000 })
          : await fetch(url, { credentials: 'same-origin', signal: controller ? controller.signal : undefined }).then(function (r) {
              if (!r.ok) throw new Error('HTTP ' + r.status);
              return r.json();
            });
        if (input.value.trim() !== q) return; // resposta obsoleta
        if (!payload || payload.ok === false) { setError(); return; }
        render(payload);
      } catch (e) {
        if (e && e.name === 'AbortError') return;
        setError();
      }
    }

    input.addEventListener('input', function () {
      var q = input.value.trim();
      lastQuery = q;
      if (clearButton) clearButton.hidden = q.length === 0;
      window.clearTimeout(debounceTimer);
      if (q.length < MIN) {
        if (q.length === 0 && renderRecent()) return;
        closePanel();
        return;
      }
      setLoading(q);
      debounceTimer = window.setTimeout(function () { runSearch(q); }, 220);
    });

    if (clearButton) {
      clearButton.addEventListener('click', function () {
        window.clearTimeout(debounceTimer);
        if (controller && controller.abort) controller.abort();
        input.value = '';
        lastQuery = '';
        clearButton.hidden = true;
        closePanel();
        input.focus();
      });
    }

    input.addEventListener('focus', function () {
      var q = input.value.trim();
      if (q.length >= MIN && panel.innerHTML) { openPanel(); return; }
      if (q.length === 0) renderRecent();
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (panel.hidden) openPanel(); setActive(activeIndex + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(activeIndex - 1); }
      else if (e.key === 'Home' && !panel.hidden && items().length) { e.preventDefault(); setActive(0); }
      else if (e.key === 'End' && !panel.hidden && items().length) { e.preventDefault(); setActive(items().length - 1); }
      else if (e.key === 'Enter') {
        var list = items();
        if (activeIndex >= 0 && list[activeIndex]) { list[activeIndex].click(); }
        else if (list.length) { list[0].click(); } // Enter abre o primeiro resultado
      } else if (e.key === 'Escape') {
        closePanel();
        input.blur();
        if (typeof opts.onEscape === 'function') opts.onEscape();
      }
    });

    return { closePanel: closePanel, input: input };
  }

  document.addEventListener('DOMContentLoaded', function () {
    // ── Desktop (topbar) ─────────────────────────────────────────────────────
    var deskInput = document.getElementById('biomieOmniSearch');
    var deskPanel = document.getElementById('biomieOmniResults');
    var deskRoot  = document.querySelector('[data-omnisearch]');
    var desk = null;
    if (deskInput && deskPanel) {
      desk = bindSearch(deskInput, deskPanel, { portal: true });
      document.addEventListener('click', function (e) {
        var insideRoot = deskRoot && deskRoot.contains(e.target);
        var insidePanel = deskPanel.contains(e.target);
        if (!insideRoot && !insidePanel) desk.closePanel();
      });
    }

    // ── Mobile (overlay full-screen) ────────────────────────────────────────
    var overlay   = document.getElementById('biomieMobileSearchOverlay');
    var openBtn   = document.querySelector('[data-mobile-search-open]');
    var closeBtn  = overlay ? overlay.querySelector('[data-mobile-search-close]') : null;
    var mobInput  = document.getElementById('biomieOmniSearchMobile');
    var mobPanel  = document.getElementById('biomieOmniResultsMobile');

    function lockScroll(lock) {
      var ms = window.BIomieMobileShell;
      if (ms && typeof ms.lockBodyScroll === 'function' && typeof ms.unlockBodyScroll === 'function') {
        lock ? ms.lockBodyScroll() : ms.unlockBodyScroll();
      } else {
        document.body.style.overflow = lock ? 'hidden' : '';
      }
    }

    function openMobile() {
      if (!overlay || !mobInput) return;
      overlay.hidden = false;
      if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
      lockScroll(true);
      window.setTimeout(function () { mobInput.focus(); }, 60);
    }
    function closeMobile() {
      if (!overlay) return;
      overlay.hidden = true;
      if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
      lockScroll(false);
    }

    if (overlay && openBtn && mobInput && mobPanel) {
      bindSearch(mobInput, mobPanel, { onSelect: closeMobile, onEscape: closeMobile });
      openBtn.addEventListener('click', openMobile);
      if (closeBtn) closeBtn.addEventListener('click', closeMobile);
      overlay.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobile();
      });
    }

    // ── Atalhos globais: "/" e Ctrl/Cmd+K ────────────────────────────────────
    function focusSearch() {
      var deskVisible = deskInput && deskInput.offsetParent !== null;
      if (deskVisible) { deskInput.focus(); deskInput.select(); return; }
      openMobile();
    }
    document.addEventListener('keydown', function (e) {
      var isSlash = e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey;
      var isK = (e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey;
      if (!isSlash && !isK) return;
      var t = e.target;
      var tag = t && t.tagName ? t.tagName.toLowerCase() : '';
      if (isSlash && (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable))) return;
      e.preventDefault();
      focusSearch();
    });
  });
})();
