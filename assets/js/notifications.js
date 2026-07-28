/* Central de Notificações — sininho interativo (polling + painel). */
(function () {
  'use strict';

  var root = document.querySelector('[data-notif-root]');
  if (!root) return;

  var bell    = root.querySelector('[data-notif-bell]');
  var panel   = root.querySelector('[data-notif-panel]');
  var badge   = root.querySelector('[data-notif-badge]');
  var listEl  = root.querySelector('[data-notif-list]');
  var emptyEl = root.querySelector('[data-notif-empty]');
  var loadEl  = root.querySelector('[data-notif-loading]');
  var markAll = root.querySelector('[data-notif-markall]');
  var filters = root.querySelectorAll('[data-notif-filter]');

  var API = 'api/notifications.php';
  var POLL_MS = 60000;
  var currentFilter = 'all';
  var lastUnread = 0;
  var open = false;

  function csrf() {
    var m = document.querySelector('meta[name="biomie-csrf"]');
    return m ? (m.getAttribute('content') || '') : '';
  }

  var CAT_ICON = {
    sync: 'sync', webhook: 'cloud_sync', business: 'trending_up',
    security: 'shield', system: 'settings'
  };

  function timeAgo(iso) {
    if (!iso) return '';
    var d = new Date((iso || '').replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    var s = Math.max(0, (Date.now() - d.getTime()) / 1000);
    if (s < 60) return 'agora';
    if (s < 3600) return Math.floor(s / 60) + ' min';
    if (s < 86400) return Math.floor(s / 3600) + ' h';
    if (s < 604800) return Math.floor(s / 86400) + ' d';
    return d.toLocaleDateString('pt-BR');
  }

  function esc(t) {
    var div = document.createElement('div');
    div.textContent = t == null ? '' : String(t);
    return div.innerHTML;
  }

  function setBadge(n) {
    lastUnread = n;
    if (!badge) return;
    if (n > 0) {
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  function ring() {
    if (!bell) return;
    bell.classList.remove('is-ringing');
    void bell.offsetWidth;
    bell.classList.add('is-ringing');
  }

  function renderItems(items) {
    if (loadEl) loadEl.hidden = true;
    listEl.querySelectorAll('.biomie-notif__item').forEach(function (el) { el.remove(); });
    if (!items || !items.length) { if (emptyEl) emptyEl.hidden = false; return; }
    if (emptyEl) emptyEl.hidden = true;

    // Um fragment só: 1 reflow para a lista inteira, em vez de 1 por item.
    var frag = document.createDocumentFragment();

    items.forEach(function (it) {
      var a = document.createElement(it.link ? 'a' : 'div');
      var priority = it.priority || 'normal';
      a.className = 'biomie-notif__item'
        + (it.is_read ? '' : ' is-unread')
        + ' biomie-notif__item--' + priority;
      a.setAttribute('data-severity', it.severity || 'info');
      a.setAttribute('data-id', it.id);
      if (it.link) a.setAttribute('href', it.link);

      // Ícone: o classificador já escolheu no backend (pedido, NF, cliente…);
      // CAT_ICON é só o fallback das notificações antigas.
      var icon = it.icon || CAT_ICON[it.category] || 'notifications';

      // Selo da empresa: logo real, com as iniciais como fallback se a imagem
      // falhar (onerror). Cor da marca no anel.
      var brandStyle = it.company_color ? ' style="--notif-brand:' + esc(it.company_color) + '"' : '';
      var brand = it.company_label
        ? '<span class="biomie-notif__brand"' + brandStyle + ' title="' + esc(it.company_label) + '">'
            + '<span class="biomie-notif__brand-initials">' + esc(it.company_initials || '') + '</span>'
            + (it.company_logo
                ? '<img class="biomie-notif__brand-logo" src="' + esc(it.company_logo) + '" alt="" loading="lazy" onerror="this.remove()">'
                : '')
          + '</span>'
        : '';

      var flag = priority === 'high'
        ? '<span class="biomie-notif__flag" aria-label="Prioridade alta"></span>'
        : '';

      a.innerHTML =
        flag +
        '<span class="biomie-notif__icon"><span class="material-symbols-rounded" aria-hidden="true">' + icon + '</span></span>' +
        '<span class="biomie-notif__body">' +
          '<span class="biomie-notif__title">' + esc(it.title) + '</span>' +
          (it.body ? '<span class="biomie-notif__text">' + esc(it.body) + '</span>' : '') +
          '<span class="biomie-notif__meta">' +
            brand +
            (it.company_label ? '<span class="biomie-notif__company">' + esc(it.company_label) + '</span>' : '') +
            '<span class="biomie-notif__time">' + timeAgo(it.created_at) + '</span>' +
            (it.link ? '<span class="biomie-notif__cta">Abrir<span class="material-symbols-rounded" aria-hidden="true">chevron_right</span></span>' : '') +
          '</span>' +
        '</span>';

      a.addEventListener('click', function (ev) {
        if (!it.is_read) { markRead(it.id); a.classList.remove('is-unread'); it.is_read = true; }
        if (!it.link) { ev.preventDefault(); }
      });
      frag.appendChild(a);
    });

    listEl.appendChild(frag);
  }

  function fetchList() {
    if (loadEl) loadEl.hidden = false;
    var url = API + '?action=list&limit=40' + (currentFilter === 'unread' ? '&unread=1' : '');
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { if (loadEl) loadEl.hidden = true; return; }
        setBadge(d.unread || 0);
        renderItems(d.items || []);
      })
      .catch(function () { if (loadEl) loadEl.hidden = true; });
  }

  function fetchCount() {
    fetch(API + '?action=count', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        if ((d.unread || 0) > lastUnread) ring();
        setBadge(d.unread || 0);
      })
      .catch(function () {});
  }

  function markRead(id) {
    fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'read', id: id, csrf_token: csrf() })
    }).then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.ok) setBadge(d.unread || 0); })
      .catch(function () {});
  }

  function doMarkAll() {
    fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'read_all', csrf_token: csrf() })
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) {
          setBadge(0);
          listEl.querySelectorAll('.biomie-notif__item.is-unread').forEach(function (el) { el.classList.remove('is-unread'); });
        }
      }).catch(function () {});
  }

  // Portal: o painel usa position:fixed, mas dentro do .topbar o backdrop-filter
  // cria um containing block que desloca/aprisiona o fixed. Mover o painel para
  // <body> garante coordenadas de viewport reais e stacking acima do conteúdo.
  if (panel && panel.parentElement !== document.body) {
    document.body.appendChild(panel);
  }

  function openPanel() {
    open = true;
    panel.hidden = false;
    panel.classList.add('is-open');
    bell.setAttribute('aria-expanded', 'true');
    fetchList();
  }
  function closePanel() {
    open = false;
    panel.classList.remove('is-open');
    panel.hidden = true;
    bell.setAttribute('aria-expanded', 'false');
  }

  bell.addEventListener('click', function (e) { e.stopPropagation(); open ? closePanel() : openPanel(); });
  panel.addEventListener('click', function (e) { e.stopPropagation(); });
  document.addEventListener('click', function () { if (open) closePanel(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) closePanel(); });

  if (markAll) markAll.addEventListener('click', doMarkAll);
  filters.forEach(function (btn) {
    btn.addEventListener('click', function () {
      filters.forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      currentFilter = btn.getAttribute('data-notif-filter') || 'all';
      fetchList();
    });
  });

  // Polling — pausa quando a aba está oculta.
  fetchCount();
  setInterval(function () { if (!document.hidden) fetchCount(); }, POLL_MS);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) fetchCount(); });
})();
