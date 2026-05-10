(function () {
  'use strict';

  function currentFile() {
    var path = window.location.pathname || '';
    var file = path.split('/').pop() || 'index.php';
    return file.replace(/\.html$/i, '.php');
  }

  function normalizeHref(href) {
    var clean = String(href || '').split('#')[0].split('?')[0];
    var file = clean.split('/').pop() || '';
    return file.replace(/\.html$/i, '.php');
  }

  function activePageKey() {
    var bodyPage = document.body ? document.body.dataset.page || '' : '';
    var hash = window.location.hash || '';
    var hashPageMap = {
      '#cards': 'indicadores',
      '#graficos': 'analises',
      '#tabelas': 'mesa'
    };

    if ((bodyPage === 'index' || bodyPage === 'dashboard' || currentFile() === 'index.php') && hashPageMap[hash]) {
      return hashPageMap[hash];
    }

    return bodyPage || currentFile().replace(/\.php$/i, '');
  }

  function linkMatchesPage(link, pageKey) {
    var matches = String(link.dataset.pageMatch || link.dataset.pageKey || '')
      .split(/\s+/)
      .filter(Boolean);

    if (matches.indexOf(pageKey) !== -1) {
      return true;
    }

    var href = normalizeHref(link.getAttribute('href'));
    return href && href === currentFile() && !link.dataset.pageKey && pageKey !== 'indicadores' && pageKey !== 'analises' && pageKey !== 'mesa';
  }

  function syncActiveNavigation() {
    var pageKey = activePageKey();
    var links = document.querySelectorAll('.sidebar-nav a.nav-item[href]');
    var activeLink = null;

    links.forEach(function (link) {
      link.classList.remove('active');
      link.classList.remove('current-page');
      link.removeAttribute('aria-current');

      if (!activeLink && linkMatchesPage(link, pageKey)) {
        activeLink = link;
      }
    });

    if (!activeLink) return;

    activeLink.classList.add('active');
    activeLink.classList.add('current-page');
    activeLink.setAttribute('aria-current', 'page');
  }

  function storageGet(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function storageSet(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (error) {
      // Sidebar remains usable when storage is blocked.
    }
  }

  function loadSidebarGroups() {
    try {
      return JSON.parse(storageGet('biomie.sidebar.groups') || '{}') || {};
    } catch (error) {
      return {};
    }
  }

  function saveSidebarGroups(groups) {
    storageSet('biomie.sidebar.groups', JSON.stringify(groups || {}));
  }

  function initSidebarGroups() {
    var groups = loadSidebarGroups();
    document.querySelectorAll('.mag-nav-group').forEach(function (group) {
      var button = group.querySelector('.mag-nav-group__button');
      var items = group.querySelector('.mag-nav-group__items');
      var key = group.dataset.sidebarGroup || '';
      if (!button || !items || !key) return;

      var hasActive = items.dataset.hasActive === '1';
      var collapsed = groups[key] === false && !hasActive;
      group.classList.toggle('is-collapsed', collapsed);
      button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

      button.addEventListener('click', function () {
        var nextCollapsed = !group.classList.contains('is-collapsed');
        group.classList.toggle('is-collapsed', nextCollapsed);
        button.setAttribute('aria-expanded', nextCollapsed ? 'false' : 'true');
        groups[key] = !nextCollapsed;
        saveSidebarGroups(groups);
      });
    });
  }

  function markTableScrollAreas() {
    var tables = document.querySelectorAll('table.mag-table, table.data-table, table.fin-table, table.admin-table, table.flight-table');

    tables.forEach(function (table) {
      var parent = table.parentElement;
      if (!parent) return;

      var style = window.getComputedStyle(parent);
      if (style.overflowX === 'auto' || style.overflowX === 'scroll') {
        parent.classList.add('mag-table-scroll');
      }
    });
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function sanitizePublicMessage(message, fallback) {
    var text = String(message || '').trim();
    var unsafe = /SQLSTATE|stack trace|trace|\/home\/|C:\\|app_key|app_secret|token|password|PDOException|Fatal error/i;

    if (!text || unsafe.test(text)) {
      return fallback || 'Nao foi possivel carregar esta leitura agora. Atualize os dados ou ajuste os filtros.';
    }

    return text.length > 180 ? text.slice(0, 177) + '...' : text;
  }

  function emptyStateHtml(options) {
    options = options || {};
    var icon = options.icon || 'search_off';
    var title = options.title || 'Sem dados';
    var message = options.message || 'Ajuste os filtros ou atualize a leitura para tentar novamente.';

    return ''
      + '<div class="mag-empty-state mag-empty-state--' + escapeHtml(options.variant || 'neutral') + '">'
      + '<span class="material-symbols-rounded mag-empty-state__icon">' + escapeHtml(icon) + '</span>'
      + '<strong>' + escapeHtml(title) + '</strong>'
      + '<p>' + escapeHtml(sanitizePublicMessage(message)) + '</p>'
      + '</div>';
  }

  function enhanceEmptyTable(tbody) {
    if (!tbody || tbody.dataset.magEmptyEnhanced === '1') return;

    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    if (rows.length !== 1) return;

    var row = rows[0];
    if (row.querySelector('.mag-empty-state')) return;

    var text = row.textContent.trim();
    var shouldEnhance = /sem|nenhum|nenhuma|clique|selecione|carregando|agregando|buscando/i.test(text);
    if (!shouldEnhance && !tbody.dataset.emptyTitle) return;

    var colCount = row.children.length ? Math.max(row.children.length, Number(row.children[0].getAttribute('colspan') || 1)) : 1;
    var title = tbody.dataset.emptyTitle || 'Sem dados';
    var message = tbody.dataset.emptyMessage || text || 'Ajuste os filtros ou atualize a leitura.';
    var icon = tbody.dataset.emptyIcon || 'search_off';

    row.innerHTML = '<td class="mag-empty-cell" colspan="' + colCount + '">' + emptyStateHtml({ title: title, message: message, icon: icon }) + '</td>';
    tbody.dataset.magEmptyEnhanced = '1';
  }

  function installEmptyStateObserver() {
    var tables = document.querySelectorAll('tbody[data-empty-title], tbody[data-empty-message], tbody[id]');
    tables.forEach(function (tbody) {
      enhanceEmptyTable(tbody);

      var observer = new MutationObserver(function () {
        tbody.dataset.magEmptyEnhanced = '0';
        enhanceEmptyTable(tbody);
      });
      observer.observe(tbody, { childList: true, subtree: true });
    });
  }

  function protectMainErrorMessages() {
    var targets = document.querySelectorAll('#pageError, #geoFeedback, #financeWarnings, #syncFeedback');
    targets.forEach(function (target) {
      var observer = new MutationObserver(function () {
        if (!target.textContent) return;
        var safe = sanitizePublicMessage(target.textContent);
        if (safe !== target.textContent.trim()) {
          target.textContent = safe;
        }
      });
      observer.observe(target, { childList: true, subtree: true, characterData: true });
    });
  }

  function syncDiagnosticsState() {
    document.querySelectorAll('.mag-technical-diagnostics > summary').forEach(function (summary) {
      var details = summary.closest('details');
      if (!details) return;
      summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');
      details.addEventListener('toggle', function () {
        summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');
      });
    });
  }

  function init() {
    document.body.classList.add('mag-ui-ready');
    syncActiveNavigation();
    markTableScrollAreas();
    installEmptyStateObserver();
    protectMainErrorMessages();
    syncDiagnosticsState();
    initSidebarGroups();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }

  window.addEventListener('hashchange', syncActiveNavigation);

  window.MAGUI = {
    init: init,
    syncActiveNavigation: syncActiveNavigation,
    emptyStateHtml: emptyStateHtml,
    sanitizePublicMessage: sanitizePublicMessage
  };
})();
