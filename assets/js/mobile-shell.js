/**
 * BIomie Mobile Shell
 * Melhora experiência no Chrome Mobile: bottom nav, bottom sheet, topbar sync.
 * Não duplica lógica do shell.js — delega toggle ao #menu_toggle existente.
 */
(function () {
  'use strict';

  var MOBILE_BP = 768;

  function isMobile() {
    return window.innerWidth < MOBILE_BP;
  }

  // ── Hamburger mobile → aciona o toggle existente do shell.js ─────────────
  // Binding defensivo: se o botão não existir (oculto por CSS ou removido), sem erro.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-mobile-sidebar-toggle]');
    if (!btn) return;
    // Se o botão estiver oculto (display:none), ignorar o clique.
    if (btn.offsetParent === null) return;
    e.preventDefault();

    // Delegar ao #menu_toggle do shell.js que já controla sidebar.open
    var toggle = document.getElementById('menu_toggle');
    if (toggle) {
      toggle.click();
    }
  });

  // ── Bottom nav: marcar item ativo pela data-page do body ─────────────────
  function setBottomNavActive() {
    var nav = document.querySelector('.mag-bottom-nav');
    if (!nav) return;

    var pageKey = (document.body.dataset.page || '').toLowerCase();

    // Pathname fallback quando body[data-page] não está definido
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
      // match exato ou prefixo (ex: "rota-comercial" cobre "rota-comercial-metas")
      var active = linkPage !== '' && (pageKey === linkPage || pageKey.indexOf(linkPage) === 0);
      link.classList.toggle('is-active', active);
      link.setAttribute('aria-current', active ? 'page' : 'false');
    });
  }

  // ── Sync título da topbar mobile ─────────────────────────────────────────
  function syncMobileTitle() {
    var titleEl = document.querySelector('.mag-mobile-topbar__title');
    if (!titleEl) return;

    // Prioridade: topbar-badge (já visível) > h1 da página > fallback
    var badge   = document.querySelector('.topbar-badge');
    var heading = document.querySelector('.mag-page-header__title, h1.page-title, main h1');
    var text    = (badge   && badge.textContent.trim())   ||
                  (heading && heading.textContent.trim()) ||
                  'BIomie';

    // Remove ícone de material-symbols se o badge tiver apenas texto + ícone
    text = text.replace(/^[\s\S]*?\s*([A-ZÁÉÍÓÚÀÂÊÎÔÃÕÇÜ][\s\S]*)$/, '$1').trim() || text.trim();
    titleEl.textContent = text;
  }

  // ── Bottom sheet: swipe para baixo fecha o drawer ────────────────────────
  function initDrawerSwipe(drawer) {
    if (drawer.dataset.mobileSwipe) return;
    drawer.dataset.mobileSwipe = '1';

    var startY   = 0;
    var currentY = 0;
    var active   = false;

    drawer.addEventListener('touchstart', function (e) {
      var header = drawer.querySelector(
        '.biomie-drawer__header, .mag-drawer-header, [id$="-close"]'
      );
      // Apenas iniciar swipe a partir do header/handle
      var zone = drawer.querySelector('.biomie-drawer__header, .mag-drawer-header');
      if (!zone || !zone.contains(e.target)) return;
      startY  = e.touches[0].clientY;
      active  = true;
    }, { passive: true });

    drawer.addEventListener('touchmove', function (e) {
      if (!active) return;
      currentY = e.touches[0].clientY;
      var delta = currentY - startY;
      if (delta > 0) {
        drawer.style.transform = 'translateY(' + delta + 'px)';
      }
    }, { passive: true });

    drawer.addEventListener('touchend', function () {
      if (!active) return;
      active = false;
      var delta = currentY - startY;
      drawer.style.transform = '';
      if (delta > 90) {
        // Fechar via API pública se disponível, senão apenas remover is-open
        drawer.classList.remove('is-open');
        var id = drawer.id;
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

  // ── Filter bottom sheet: data-mobile-filter-sheet ────────────────────────
  var _filterEscBound = false;

  function initFilterSheets() {
    if (!isMobile()) return;
    document.querySelectorAll('[data-mobile-filter-sheet]:not([data-mobile-active])').forEach(function (sheet) {
      sheet.setAttribute('data-mobile-active', '');

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mag-mobile-filter-button';
      btn.setAttribute('data-mobile-filter-toggle', '');
      btn.setAttribute('aria-expanded', 'false');
      btn.innerHTML = '<span class="material-symbols-rounded" aria-hidden="true">tune</span> Filtros';
      sheet.parentNode.insertBefore(btn, sheet);

      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'mag-mobile-filter-close';
      closeBtn.setAttribute('aria-label', 'Fechar filtros');
      closeBtn.innerHTML = '<span class="material-symbols-rounded">close</span>';
      sheet.insertBefore(closeBtn, sheet.firstChild);

      var backdrop = document.createElement('div');
      backdrop.className = 'mag-mobile-filter-backdrop';
      document.body.appendChild(backdrop);

      function openSheet() {
        sheet.classList.add('is-open');
        backdrop.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        var firstFocusable = sheet.querySelector('input, select, button:not(.mag-mobile-filter-close)');
        if (firstFocusable) firstFocusable.focus();
      }

      function closeSheet() {
        sheet.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      }

      btn.addEventListener('click', openSheet);
      closeBtn.addEventListener('click', closeSheet);
      backdrop.addEventListener('click', closeSheet);
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
        if (opened) {
          var toggle = document.querySelector('[data-mobile-filter-toggle]');
          if (toggle) toggle.focus();
        }
      });
    }
  }

  // ── Card tables: data-mobile-card-table ───────────────────────────────────
  function initCardTables() {
    document.querySelectorAll('table[data-mobile-card-table]').forEach(function (tbl) {
      var headers = Array.from(tbl.querySelectorAll('thead th')).map(function (el) {
        return el.textContent.trim();
      });
      if (headers.length === 0) return;

      function labelRows(tbody) {
        tbody.querySelectorAll('tr').forEach(function (row) {
          Array.from(row.querySelectorAll('td')).forEach(function (td, i) {
            if (!td.hasAttribute('data-mobile-skip') && headers[i]) {
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

  // Observa drawers adicionados dinamicamente
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

  // Re-init ao abrir drawers via clique
  document.addEventListener('click', function () {
    if (isMobile()) setTimeout(initAllDrawerSwipes, 120);
  });

  // ── Lazy chart render: BIomie.mobile.whenVisible ─────────────────────────
  function whenVisible(element, callback) {
    if (!element) { callback(); return; }
    if (!isMobile()) { callback(); return; }
    if (typeof IntersectionObserver === 'undefined') { callback(); return; }
    var io = new IntersectionObserver(function (entries, obs) {
      if (entries[0].isIntersecting) {
        obs.disconnect();
        callback();
      }
    }, { rootMargin: '120px' });
    io.observe(element);
  }

  function applyMobileChartTweaks() {
    if (!isMobile()) return;
    var Chart = window.Chart;
    if (!Chart || !Chart.defaults) return;
    var d = Chart.defaults;
    d.animation = Object.assign({}, d.animation || {}, { duration: 0 });
    var scales = d.scales || {};
    ['linear', 'logarithmic', 'category'].forEach(function (t) {
      if (scales[t] && scales[t].ticks) {
        scales[t].ticks.maxTicksLimit = 4;
      }
    });
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      setBottomNavActive();
      syncMobileTitle();
      initAllDrawerSwipes();
      initFilterSheets();
      initCardTables();
    });
  } else {
    setBottomNavActive();
    syncMobileTitle();
    initAllDrawerSwipes();
    initFilterSheets();
    initCardTables();
  }

  // Chart tweaks: run after all load handlers (including mag-chart-defaults.js)
  window.addEventListener('load', function () {
    setTimeout(applyMobileChartTweaks, 0);
  }, { once: true });

  // BIomie.mobile namespace (extends existing BIomie object if present)
  window.BIomie = window.BIomie || {};
  window.BIomie.mobile = Object.assign(window.BIomie.mobile || {}, {
    whenVisible: whenVisible,
  });

  window.BIomieMobileShell = {
    isMobile:              isMobile,
    setBottomNavActive:    setBottomNavActive,
    syncMobileTitle:       syncMobileTitle,
    initFilterSheets:      initFilterSheets,
    initCardTables:        initCardTables,
    applyMobileChartTweaks: applyMobileChartTweaks,
  };
}());
