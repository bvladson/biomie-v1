(function () {
  var root = document.documentElement;
  var themeStorageKey = window.BIOMIE_THEME_KEY || 'biomie:theme';
  var themeMedia = null;

  try {
    themeMedia = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  } catch (error) {
    themeMedia = null;
  }

  const csrfMeta = document.querySelector('meta[name="biomie-csrf"]');
  const requestIdMeta = document.querySelector('meta[name="biomie-request-id"]');

  function biomieCsrf() {
    return csrfMeta?.getAttribute('content') || '';
  }

  function biomieRequestId() {
    return requestIdMeta?.getAttribute('content') || '';
  }

  function cssToken(name) {
    return getComputedStyle(root).getPropertyValue(name).trim();
  }

  function alphaColor(color, alpha) {
    var value = String(color || '').trim();
    if (!value) return '';
    if (value.indexOf('rgb(') === 0) {
      return value.replace(/^rgb\((.+)\)$/i, 'rgba($1,' + alpha + ')');
    }
    if (value.indexOf('rgba(') === 0) {
      return value.replace(/rgba\(([^,]+),([^,]+),([^,]+),([^)]+)\)/i, 'rgba($1,$2,$3,' + alpha + ')');
    }
    if (value.charAt(0) === '#') {
      var clean = value.slice(1);
      if (clean.length === 3) {
        clean = clean.split('').map(function (chunk) { return chunk + chunk; }).join('');
      }
      var parsed = parseInt(clean, 16);
      if (!Number.isNaN(parsed)) {
        return 'rgba(' + ((parsed >> 16) & 255) + ',' + ((parsed >> 8) & 255) + ',' + (parsed & 255) + ',' + alpha + ')';
      }
    }
    return value;
  }

  function getChartTheme() {
    return {
      text: cssToken('--text-2') || '#4b5263',
      title: cssToken('--text-1') || '#111318',
      subtle: cssToken('--text-3') || '#9099ac',
      surface: cssToken('--bg-card') || '#ffffff',
      grid: cssToken('--chart-grid') || 'rgba(0,0,0,.08)',
      tooltipBg: cssToken('--chart-tooltip-bg') || '#ffffff',
      tooltipBorder: cssToken('--chart-tooltip-border') || 'rgba(0,0,0,.12)',
      tooltipTitle: cssToken('--chart-tooltip-title') || '#111318',
      tooltipText: cssToken('--chart-tooltip-text') || '#4b5263',
      accent: cssToken('--page-accent') || '#96140a',
      accentSoft: cssToken('--page-accent-soft') || alphaColor(cssToken('--page-accent') || '#96140a', 0.18),
      billed: cssToken('--state-billed') || '#16a34a',
      authorized: cssToken('--state-auth') || '#d97706',
      cancelled: cssToken('--state-cancel') || '#dc2626',
      info: cssToken('--state-info') || '#2563eb'
    };
  }

  function normalizeThemePreference(value) {
    return value === 'light' || value === 'dark' || value === 'system' ? value : 'system';
  }

  function resolveTheme(preference) {
    if (preference === 'system') {
      return themeMedia && themeMedia.matches ? 'dark' : 'light';
    }
    return preference;
  }

  function getThemePreference() {
    var current = root.dataset.themePreference || 'system';
    try {
      current = window.localStorage.getItem(themeStorageKey) || current;
    } catch (error) {
      current = root.dataset.themePreference || 'system';
    }
    return normalizeThemePreference(current);
  }

  function syncThemeColorMeta() {
    var browserThemeColor = cssToken('--browser-theme-color') || (root.dataset.theme === 'dark' ? '#12151b' : '#f6f3f2');
    document.querySelectorAll('meta[name="theme-color"]').forEach(function (meta) {
      meta.setAttribute('content', browserThemeColor);
    });
  }

  function syncChartDefaults() {
    if (!window.Chart) return;

    var theme = getChartTheme();
    var textColor = theme.text;
    var titleColor = theme.title;
    var gridColor = theme.grid;
    var tooltipBg = theme.tooltipBg;
    var tooltipBorder = theme.tooltipBorder;

    Chart.defaults.color = textColor;
    Chart.defaults.borderColor = gridColor;
    Chart.defaults.font.family = cssToken('--font-body') || "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.plugins.tooltip.backgroundColor = tooltipBg;
    Chart.defaults.plugins.tooltip.borderColor = tooltipBorder;
    Chart.defaults.plugins.tooltip.borderWidth = 1;
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.titleColor = titleColor;
    Chart.defaults.plugins.tooltip.bodyColor = textColor;

    Chart.defaults.plugins.legend = Chart.defaults.plugins.legend || {};
    Chart.defaults.plugins.legend.labels = Chart.defaults.plugins.legend.labels || {};
    Chart.defaults.plugins.legend.labels.color = textColor;
  }

  function refreshActiveCharts() {
    if (!window.Chart || !window.Chart.instances) return;

    var theme = getChartTheme();
    var textColor = theme.text;
    var titleColor = theme.title;
    var gridColor = theme.grid;
    var tooltipBg = theme.tooltipBg;
    var tooltipBorder = theme.tooltipBorder;
    var surfaceColor = theme.surface;

    Object.values(window.Chart.instances).forEach(function (chart) {
      if (!chart || !chart.options) return;

      chart.options.plugins = chart.options.plugins || {};
      if (chart.options.plugins.tooltip) {
        chart.options.plugins.tooltip.backgroundColor = tooltipBg;
        chart.options.plugins.tooltip.borderColor = tooltipBorder;
        chart.options.plugins.tooltip.titleColor = titleColor;
        chart.options.plugins.tooltip.bodyColor = textColor;
      }

      if (chart.options.plugins.legend && chart.options.plugins.legend.labels) {
        chart.options.plugins.legend.labels.color = textColor;
      }

      if (chart.options.scales) {
        Object.values(chart.options.scales).forEach(function (scale) {
          if (!scale) return;
          scale.grid = scale.grid || {};
          scale.ticks = scale.ticks || {};
          scale.border = scale.border || {};

          if (scale.grid.display !== false) {
            scale.grid.color = gridColor;
          }
          scale.ticks.color = textColor;
          scale.border.color = gridColor;
        });
      }

      if (chart.data && Array.isArray(chart.data.datasets)) {
        chart.data.datasets.forEach(function (dataset) {
          if (!dataset || typeof dataset !== 'object') return;
          if (typeof dataset.pointBorderColor === 'string' && /^#fff(f{3})?$/i.test(dataset.pointBorderColor)) {
            dataset.pointBorderColor = surfaceColor;
          }
          if (typeof dataset.borderColor === 'string' && /^#fff(f{3})?$/i.test(dataset.borderColor)) {
            dataset.borderColor = surfaceColor;
          }
        });
      }

      chart.update('none');
    });
  }

  function syncThemeSwitchUI() {
    var currentPreference = getThemePreference();
    document.querySelectorAll('.theme-option').forEach(function (button) {
      var active = button.dataset.themeChoice === currentPreference;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.classList.toggle('is-active', active);
    });
  }

  function dispatchThemeChange(preference, resolvedTheme) {
    window.dispatchEvent(new CustomEvent('biomie:theme-changed', {
      detail: {
        preference: preference,
        resolvedTheme: resolvedTheme
      }
    }));
  }

  function applyThemePreference(preference, options) {
    options = options || {};
    var normalizedPreference = normalizeThemePreference(preference);
    var resolvedTheme = resolveTheme(normalizedPreference);

    root.dataset.themePreference = normalizedPreference;
    root.dataset.theme = resolvedTheme;
    root.style.colorScheme = resolvedTheme;

    if (options.persist !== false) {
      try {
        window.localStorage.setItem(themeStorageKey, normalizedPreference);
      } catch (error) {
        // Ignore storage failures.
      }
    }

    syncThemeColorMeta();
    syncThemeSwitchUI();
    syncChartDefaults();

    if (!options.skipChartRefresh) {
      window.requestAnimationFrame(refreshActiveCharts);
    }

    if (!options.silent) {
      dispatchThemeChange(normalizedPreference, resolvedTheme);
    }
  }

  function buildThemeSwitch() {
    var wrapper = document.createElement('div');
    wrapper.className = 'theme-switch';
    wrapper.setAttribute('role', 'group');
    wrapper.setAttribute('aria-label', 'Tema');

    wrapper.innerHTML = ''
      + '<span class="theme-switch-label">Tema</span>'
      + '<div class="theme-switch-options">'
      + '<button type="button" class="theme-option" data-theme-choice="light">Claro</button>'
      + '<button type="button" class="theme-option" data-theme-choice="dark">Escuro</button>'
      + '<button type="button" class="theme-option" data-theme-choice="system">Auto</button>'
      + '</div>';

    wrapper.querySelectorAll('.theme-option').forEach(function (button) {
      button.addEventListener('click', function () {
        applyThemePreference(button.dataset.themeChoice);
      });
    });

    return wrapper;
  }

  function injectThemeSwitch() {
    var actions = document.querySelector('header.topbar .topbar-actions');
    if (!actions || actions.querySelector('.theme-switch')) return;

    actions.insertBefore(buildThemeSwitch(), actions.firstChild);
    syncThemeSwitchUI();
  }

  function buildFetchConfig(options) {
    options = options || {};

    const headers = new Headers(options.headers || {});
    if (!headers.has('Accept')) headers.set('Accept', 'application/json');
    if (!headers.has('X-Requested-With')) headers.set('X-Requested-With', 'fetch');
    if (!headers.has('X-Request-Id') && biomieRequestId()) headers.set('X-Request-Id', biomieRequestId());

    const method = String(options.method || 'GET').toUpperCase();
    if (!['GET', 'HEAD'].includes(method) && !headers.has('X-CSRF-Token') && biomieCsrf()) {
      headers.set('X-CSRF-Token', biomieCsrf());
    }

    const timeoutMs = Number(options.timeoutMs || 30000);
    const controller = new AbortController();
    const timeoutId = timeoutMs > 0
      ? window.setTimeout(function () {
        controller.abort(new DOMException('timeout', 'AbortError'));
      }, timeoutMs)
      : 0;

    if (options.signal) {
      options.signal.addEventListener('abort', function () {
        controller.abort(options.signal.reason);
      }, { once: true });
    }

    const config = Object.assign({}, options, {
      credentials: options.credentials || 'same-origin',
      cache: options.cache || 'no-store',
      headers: headers,
      signal: controller.signal
    });

    delete config.timeoutMs;

    return { config: config, timeoutId: timeoutId };
  }

  async function parseApiResponse(response) {
    const requestId = response.headers.get('x-request-id') || '';
    if (response.redirected) {
      const finalUrl = String(response.url || '').toLowerCase();
      const looksLikeLoginRedirect = finalUrl.includes('login.php') || finalUrl.includes('logout.php');
      const looksLikeLogout = finalUrl.includes('logout.php');
      let redirectMessage;
      if (looksLikeLogout) {
        redirectMessage = 'Sua sessao foi encerrada. Faca login novamente para continuar.';
      } else if (looksLikeLoginRedirect) {
        redirectMessage = 'Sessao expirada. Faca login novamente.';
      } else {
        redirectMessage = 'O servidor redirecionou a requisicao para um endereco inesperado. Recarregue a pagina.';
      }
      const error = new Error(redirectMessage);
      error.code = looksLikeLoginRedirect ? 401 : (response.status || 302);
      error.requestId = requestId;
      throw error;
    }

    if (response.status === 401) {
      const error = new Error('Sessao invalida ou expirada. Faca login novamente.');
      error.code = 401;
      error.requestId = requestId;
      throw error;
    }

    const contentType = response.headers.get('content-type') || '';
    const isJson = contentType.includes('application/json');
    const rawText = await response.text();
    let payload = null;

    if (isJson && rawText) {
      try {
        payload = JSON.parse(rawText);
      } catch (err) {
        const error = new Error('Resposta JSON invalida do servidor.');
        error.code = response.status || 500;
        error.requestId = requestId;
        throw error;
      }
    }

    if (!response.ok || (payload && payload.ok === false)) {
      const message = payload?.message || rawText || ('Erro HTTP ' + response.status);
      const compact = String(message).trim();

      if (response.status === 419) {
        const error = new Error(compact || 'Sessao do formulario expirada. Recarregue a pagina e tente novamente.');
        error.code = 419;
        error.requestId = requestId;
        window.dispatchEvent(new CustomEvent('biomie:session-expired', { detail: { requestId: requestId } }));
        throw error;
      }

      if (response.status === 503 || compact.includes('503 Service Unavailable') || compact.includes('DOCTYPE html')) {
        const error = new Error('Aviso: O periodo selecionado e muito longo e o ERP de origem sobrecarregou. Tente diminuir o intervalo em alguns meses.');
        error.code = 503;
        error.requestId = requestId;
        throw error;
      }

      const error = new Error(compact ? compact.substring(0, 160) : ('Erro HTTP ' + response.status));
      error.code = response.status || 500;
      error.requestId = requestId;
      throw error;
    }

    if (!isJson) {
      const text = rawText || '';
      if (response.status === 503 || text.includes('503 Service Unavailable') || text.includes('DOCTYPE html')) {
        const error = new Error('Aviso: O periodo selecionado e muito longo e o ERP de origem sobrecarregou. Tente diminuir o intervalo em alguns meses.');
        error.code = 503;
        error.requestId = requestId;
        throw error;
      }
      const error = new Error('Resposta inesperada do servidor: ' + text.substring(0, 120));
      error.code = response.status || 500;
      error.requestId = requestId;
      throw error;
    }

    return payload;
  }

  window.fetchApi = async function (url, options) {
    const built = buildFetchConfig(options || {});
    try {
      const response = await fetch(url, built.config);
      const payload = await parseApiResponse(response);
      return { response: response, payload: payload };
    } catch (err) {
      if (err?.name === 'AbortError') {
        const error = new Error('A requisicao demorou demais. Tente novamente com um periodo menor.');
        error.code = 408;
        throw error;
      }
      throw err instanceof Error ? err : new Error('Falha ao comunicar com o servidor.');
    } finally {
      if (built.timeoutId) window.clearTimeout(built.timeoutId);
    }
  };

  window.fetchJson = async function (url, options) {
    const result = await window.fetchApi(url, options || {});
    return result.payload;
  };

  window.BIOMIE_THEME = {
    getPreference: getThemePreference,
    applyTheme: applyThemePreference,
    resolveTheme: resolveTheme,
    cssToken: cssToken,
    alphaColor: alphaColor,
    getChartTheme: getChartTheme
  };

  if (themeMedia) {
    var syncSystemTheme = function () {
      if (getThemePreference() === 'system') {
        applyThemePreference('system', { persist: false });
      }
    };

    if (typeof themeMedia.addEventListener === 'function') {
      themeMedia.addEventListener('change', syncSystemTheme);
    } else if (typeof themeMedia.addListener === 'function') {
      themeMedia.addListener(syncSystemTheme);
    }
  }

  applyThemePreference(getThemePreference(), { persist: false, silent: true, skipChartRefresh: true });
  syncChartDefaults();

  document.addEventListener('DOMContentLoaded', function () {
    injectThemeSwitch();
    syncThemeColorMeta();
    syncChartDefaults();
    window.requestAnimationFrame(refreshActiveCharts);

    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('overlay');
    var toggle = document.getElementById('menu_toggle');
    var main = document.querySelector('.main');
    var storageCollapsedKey = 'biomie:sidebar-collapsed';

    if (!sidebar || !toggle) return;

    sidebar.setAttribute('tabindex', '-1');

    var mq = window.matchMedia('(max-width: 1180px)');
    function isMobile() { return mq.matches; }
    function syncSidebarState() {
      var mobile = isMobile();
      var open = sidebar.classList.contains('open');

      if (mobile) {
        sidebar.classList.remove('collapsed');
        sidebar.dataset.sidebarMode = 'mobile';
        sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      } else {
        sidebar.classList.remove('open');
        sidebar.dataset.sidebarMode = 'desktop';
        var collapsed = sidebar.classList.contains('collapsed');
        sidebar.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      }
    }
    function applyStoredState() {
      if (!isMobile()) {
        if (window.localStorage.getItem(storageCollapsedKey) === '1') {
          sidebar.classList.add('collapsed');
        } else {
          sidebar.classList.remove('collapsed');
        }
      } else {
        sidebar.classList.remove('collapsed');
      }
      syncSidebarState();
    }

    function closeSidebar() {
      sidebar.classList.remove('collapsed');
      sidebar.classList.remove('open');
      if (overlay) overlay.classList.remove('show');
      sidebar.removeAttribute('aria-modal');

      document.body.style.overflow = '';
      document.body.classList.remove('menu-open');

      if (main) main.inert = false;

      if (isMobile()) {
        toggle.focus();
      }

      syncSidebarState();
    }

    function openMobileDrawer() {
      sidebar.classList.remove('collapsed');
      sidebar.classList.add('open');
      if (overlay) overlay.classList.add('show');

      sidebar.setAttribute('aria-modal', 'true');
      document.body.style.overflow = 'hidden';
      document.body.classList.add('menu-open');

      if (main) main.inert = true;

      sidebar.focus();
      syncSidebarState();
    }

    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();

      if (!isMobile()) {
        sidebar.classList.toggle('collapsed');
        window.localStorage.setItem(storageCollapsedKey, sidebar.classList.contains('collapsed') ? '1' : '0');
        syncSidebarState();
        return;
      }

      if (sidebar.classList.contains('open')) {
        closeSidebar();
      } else {
        openMobileDrawer();
      }
    });

    if (overlay) {
      overlay.addEventListener('click', function () {
        if (sidebar.classList.contains('open')) closeSidebar();
      });
    }

    var links = sidebar.querySelectorAll('a.nav-item');
    for (var i = 0; i < links.length; i++) {
      links[i].addEventListener('click', function () {
        if (isMobile()) closeSidebar();
      });
    }

    var sx = 0;
    var sy = 0;
    sidebar.addEventListener('touchstart', function (e) {
      sx = e.touches[0].clientX;
      sy = e.touches[0].clientY;
    }, { passive: true });

    sidebar.addEventListener('touchend', function (e) {
      var dx = sx - e.changedTouches[0].clientX;
      var dy = Math.abs(sy - e.changedTouches[0].clientY);
      if (dx > 60 && dy < 100) closeSidebar();
    }, { passive: true });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (isMobile() && sidebar.classList.contains('open')) {
          closeSidebar();
        }
      }
    });

    function handleResize() {
      if (!isMobile() && sidebar.classList.contains('open')) {
        closeSidebar();
      }
      if (isMobile()) {
        sidebar.classList.remove('collapsed');
      } else if (window.localStorage.getItem(storageCollapsedKey) === '1') {
        sidebar.classList.add('collapsed');
      } else {
        sidebar.classList.remove('collapsed');
      }
      syncSidebarState();
    }

    if (typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', handleResize);
    } else if (typeof mq.addListener === 'function') {
      mq.addListener(handleResize);
    }

    window.addEventListener('orientationchange', function () {
      setTimeout(handleResize, 300);
    });

    var observer = new MutationObserver(function () {
      if (overlay && !sidebar.classList.contains('open') && overlay.classList.contains('show')) {
        overlay.classList.remove('show');
        document.body.style.overflow = '';
        if (main) main.inert = false;
      }
    });
    observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });

    applyStoredState();
    syncSidebarState();
  });
})();
