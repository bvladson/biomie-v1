/**
 * BIomie Mobile Shell — Fase R (Mobile Premium)
 * Navegação mobile, bottom sheets, card tables, chart tweaks, PWA.
 * Não duplica lógica do shell.js — delega toggle ao #menu_toggle existente.
 */
(function () {
  'use strict';

  var MOBILE_BP = 768;

  function isMobile() {
    return window.innerWidth < MOBILE_BP;
  }

  // ── Sidebar toggle via [data-mobile-sidebar-toggle] ──────────────────────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-mobile-sidebar-toggle]');
    if (!btn) return;
    if (btn.offsetParent === null) return;
    e.preventDefault();
    var toggle = document.getElementById('menu_toggle');
    if (toggle) toggle.click();
  });

  // ── Sync aria-expanded no "Mais" e hambúrguer ao abrir/fechar sidebar ────
  function initMoreButtonSync() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    function syncExpanded() {
      var isOpen = sidebar.classList.contains('open');
      var moreBtn = document.getElementById('mobileMoreButton');
      if (moreBtn) {
        moreBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        moreBtn.classList.toggle('is-active', isOpen);
      }
      // Sync qualquer outro [data-mobile-sidebar-toggle] (topbar hamburger)
      document.querySelectorAll('[data-mobile-sidebar-toggle]').forEach(function (btn) {
        if (btn !== moreBtn) {
          btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
      });
    }

    new MutationObserver(syncExpanded).observe(sidebar, {
      attributes: true,
      attributeFilter: ['class'],
    });
    syncExpanded();
  }

  // ── Bottom nav: marcar item ativo pela data-page do body ─────────────────
  function setBottomNavActive() {
    var nav = document.querySelector('.mag-bottom-nav');
    if (!nav) return;

    var pageKey = (document.body.dataset.page || '').toLowerCase();

    if (!pageKey) {
      var pathname = (window.location.pathname || '').toLowerCase();
      var filename = pathname.split('/').pop().replace(/\.php$/, '') || 'index';
      nav.querySelectorAll('.mag-bottom-nav__link[data-page]').forEach(function (link) {
        var linkFile = (link.getAttribute('href') || '').toLowerCase().replace(/\.php$/, '');
        if (linkFile && filename === linkFile) {
          pageKey = (link.dataset.page || '').toLowerCase();
        }
      });
    }

    nav.querySelectorAll('.mag-bottom-nav__link[data-page]').forEach(function (link) {
      var linkPage = (link.dataset.page || '').toLowerCase();
      var active   = linkPage !== '' && (pageKey === linkPage || pageKey.indexOf(linkPage) === 0);
      link.classList.toggle('is-active', active);
      link.setAttribute('aria-current', active ? 'page' : 'false');
    });
  }

  // ── Sync título da topbar mobile ─────────────────────────────────────────
  function syncMobileTitle() {
    var titleEl = document.querySelector('.mag-mobile-topbar__title');
    if (!titleEl) return;

    var badge   = document.querySelector('.topbar-badge');
    var heading = document.querySelector('.mag-page-header__title, h1.page-title, main h1');
    var text    = (badge   && badge.textContent.trim())   ||
                  (heading && heading.textContent.trim()) ||
                  'BIomie';

    text = text.replace(/^[\s\S]*?\s*([A-ZÁÉÍÓÚÀÂÊÎÔÃÕÇÜ][\s\S]*)$/, '$1').trim() || text.trim();
    titleEl.textContent = text;
  }

  // ── Bottom sheet swipe para fechar drawers ────────────────────────────────
  function initDrawerSwipe(drawer) {
    if (drawer.dataset.mobileSwipe) return;
    drawer.dataset.mobileSwipe = '1';

    var startY = 0, currentY = 0, active = false;

    drawer.addEventListener('touchstart', function (e) {
      var zone = drawer.querySelector('.biomie-drawer__header, .mag-drawer-header');
      if (!zone || !zone.contains(e.target)) return;
      startY = e.touches[0].clientY;
      active = true;
    }, { passive: true });

    drawer.addEventListener('touchmove', function (e) {
      if (!active) return;
      currentY = e.touches[0].clientY;
      var delta = currentY - startY;
      if (delta > 0) drawer.style.transform = 'translateY(' + delta + 'px)';
    }, { passive: true });

    drawer.addEventListener('touchend', function () {
      if (!active) return;
      active = false;
      var delta = currentY - startY;
      drawer.style.transform = '';
      if (delta > 90) {
        drawer.classList.remove('is-open');
        var apis = [
          window.BIomieVendorDetail,
          window.BIomieInvoiceDetail,
          window.BIomieOrderDetail,
          window.BIomieProductDetail,
          window.BIomieCustomerDetail,
          window.BIomieGeoDetail,
        ];
        apis.forEach(function (api) {
          if (api && typeof api.close === 'function') {
            try { api.close(); } catch (_) {}
          }
        });
      }
    });
  }

  function initAllDrawerSwipes() {
    if (!isMobile()) return;
    document.querySelectorAll('.biomie-drawer, .mag-drawer').forEach(initDrawerSwipe);
  }

  // ── Scroll lock com save/restore do scrollY (iOS safe) ───────────────────
  var _lockedScrollY = 0;

  function lockBodyScroll() {
    _lockedScrollY = window.scrollY || document.documentElement.scrollTop || 0;
    document.body.style.top = '-' + _lockedScrollY + 'px';
    document.body.classList.add('mag-scroll-lock');
  }

  function unlockBodyScroll() {
    document.body.classList.remove('mag-scroll-lock');
    document.body.style.top = '';
    window.scrollTo(0, _lockedScrollY);
  }

  // ── Filter bottom sheet: data-mobile-filter-sheet ────────────────────────
  var _filterEscBound = false;

  function initFilterSheets() {
    if (!isMobile()) return;

    document.querySelectorAll('[data-mobile-filter-sheet]:not([data-mobile-active])').forEach(function (sheet) {
      sheet.setAttribute('data-mobile-active', '');

      // Botão "Filtros" inserido acima do sheet
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mag-mobile-filter-button';
      btn.setAttribute('data-mobile-filter-toggle', '');
      btn.setAttribute('aria-expanded', 'false');
      btn.innerHTML = '<span class="material-symbols-rounded" aria-hidden="true">tune</span> Filtros';
      sheet.parentNode.insertBefore(btn, sheet);

      // Botão fechar (topo do sheet)
      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'mag-mobile-filter-close';
      closeBtn.setAttribute('aria-label', 'Fechar filtros');
      closeBtn.innerHTML = '<span class="material-symbols-rounded">close</span>';
      sheet.insertBefore(closeBtn, sheet.firstChild);

      // Rodapé sticky: Limpar + Aplicar
      var footer = document.createElement('div');
      footer.className = 'mag-mobile-filter-footer';
      footer.innerHTML =
        '<button type="button" class="mag-mobile-filter-clear-btn">Limpar</button>' +
        '<button type="button" class="mag-mobile-filter-apply-btn">Aplicar filtros</button>';
      sheet.appendChild(footer);

      // Backdrop
      var backdrop = document.createElement('div');
      backdrop.className = 'mag-mobile-filter-backdrop';
      document.body.appendChild(backdrop);

      function openSheet() {
        sheet.classList.add('is-open');
        backdrop.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        lockBodyScroll();
        var firstFocusable = sheet.querySelector('input, select, button:not(.mag-mobile-filter-close)');
        if (firstFocusable) firstFocusable.focus();
      }

      function closeSheet() {
        sheet.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        unlockBodyScroll();
        btn.focus();
      }

      btn.addEventListener('click', openSheet);
      closeBtn.addEventListener('click', closeSheet);
      backdrop.addEventListener('click', closeSheet);

      // Aplicar: fecha o sheet e dispara o botão de aplicar da página
      footer.querySelector('.mag-mobile-filter-apply-btn').addEventListener('click', function () {
        closeSheet();
        var applyBtn = document.getElementById('applyFilters');
        if (applyBtn) applyBtn.click();
      });

      // Limpar: reseta inputs + fecha + reaplica
      footer.querySelector('.mag-mobile-filter-clear-btn').addEventListener('click', function () {
        sheet.querySelectorAll('input:not([type="hidden"]), select').forEach(function (el) {
          if (el.type === 'checkbox' || el.type === 'radio') {
            el.checked = false;
          } else {
            el.value = '';
          }
        });
        closeSheet();
        var applyBtn = document.getElementById('applyFilters');
        if (applyBtn) applyBtn.click();
      });
    });

    if (!_filterEscBound) {
      _filterEscBound = true;
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var opened = false;
        document.querySelectorAll('[data-mobile-filter-sheet].is-open').forEach(function (s) {
          s.classList.remove('is-open');
          opened = true;
        });
        document.querySelectorAll('.mag-mobile-filter-backdrop.is-open').forEach(function (b) {
          b.classList.remove('is-open');
        });
        unlockBodyScroll();
        if (opened) {
          var toggle = document.querySelector('[data-mobile-filter-toggle]');
          if (toggle) toggle.focus();
        }
      });
    }
  }

  // ── Card tables: data-mobile-card-table ───────────────────────────────────
  // Suporta data-mobile-skip em <th> para pular coluna inteira no modo card.
  // Tabelas com data-mobile-scroll-only mantêm scroll horizontal.
  function initCardTables() {
    document.querySelectorAll('table[data-mobile-card-table]').forEach(function (tbl) {
      if (tbl.hasAttribute('data-mobile-scroll-only')) return;

      var ths       = Array.from(tbl.querySelectorAll('thead th'));
      var headers   = ths.map(function (el) { return el.textContent.trim(); });
      var skipCols  = ths.map(function (el) { return el.hasAttribute('data-mobile-skip'); });
      if (headers.length === 0) return;

      function labelRows(tbody) {
        tbody.querySelectorAll('tr').forEach(function (row) {
          Array.from(row.querySelectorAll('td')).forEach(function (td, i) {
            if (skipCols[i]) {
              td.setAttribute('data-mobile-skip', '');
            } else if (!td.hasAttribute('data-mobile-skip') && headers[i]) {
              td.setAttribute('data-label', headers[i]);
            }
          });
        });
      }

      var tbody = tbl.querySelector('tbody');
      if (!tbody) return;
      labelRows(tbody);
      new MutationObserver(function () { labelRows(tbody); })
        .observe(tbody, { childList: true });
    });
  }

  // Observa drawers adicionados dinamicamente ao DOM
  if (typeof MutationObserver !== 'undefined') {
    new MutationObserver(function (mutations) {
      if (!isMobile()) return;
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          if (node.classList.contains('biomie-drawer') || node.classList.contains('mag-drawer')) {
            initDrawerSwipe(node);
          }
        });
      });
    }).observe(document.body, { childList: true });
  }

  document.addEventListener('click', function () {
    if (isMobile()) setTimeout(initAllDrawerSwipes, 120);
  });

  // ── Lazy chart render via IntersectionObserver ────────────────────────────
  function whenVisible(element, callback) {
    if (!element) { callback(); return; }
    if (!isMobile()) { callback(); return; }
    if (typeof IntersectionObserver === 'undefined') { callback(); return; }
    var io = new IntersectionObserver(function (entries, obs) {
      if (entries[0].isIntersecting) { obs.disconnect(); callback(); }
    }, { rootMargin: '120px' });
    io.observe(element);
  }

  // ── Mobile chart tweaks: animação off, legendas off, ticks compactos ─────
  function applyMobileChartTweaks() {
    if (!isMobile()) return;
    var Chart = window.Chart;
    if (!Chart || !Chart.defaults) return;
    var d = Chart.defaults;

    d.animation = Object.assign({}, d.animation || {}, { duration: 0 });

    d.plugins = d.plugins || {};
    d.plugins.legend = Object.assign({}, d.plugins.legend || {}, { display: false });

    var scales = d.scales || (d.scales = {});
    ['linear', 'logarithmic', 'category', 'time', 'timeseries', 'radialLinear'].forEach(function (t) {
      scales[t] = scales[t] || {};
      scales[t].ticks = Object.assign({}, scales[t].ticks || {}, { maxTicksLimit: 4 });
    });
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      setBottomNavActive();
      syncMobileTitle();
      initMoreButtonSync();
      initAllDrawerSwipes();
      initFilterSheets();
      initCardTables();
    });
  } else {
    setBottomNavActive();
    syncMobileTitle();
    initMoreButtonSync();
    initAllDrawerSwipes();
    initFilterSheets();
    initCardTables();
  }

  window.addEventListener('load', function () {
    setTimeout(applyMobileChartTweaks, 0);
  }, { once: true });

  window.BIomie = window.BIomie || {};
  window.BIomie.mobile = Object.assign(window.BIomie.mobile || {}, {
    whenVisible: whenVisible,
  });

  window.BIomieMobileShell = {
    isMobile:               isMobile,
    setBottomNavActive:     setBottomNavActive,
    syncMobileTitle:        syncMobileTitle,
    initFilterSheets:       initFilterSheets,
    initCardTables:         initCardTables,
    applyMobileChartTweaks: applyMobileChartTweaks,
  };
}());
