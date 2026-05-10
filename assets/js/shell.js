(function () {
  window.BIomieFeatureFlags = {
    geo_index_enabled: true,
    geo_map_enabled: true,
    client_dossier_enabled: true,
    geo_observability_enabled: true
  };

  var root = document.documentElement;

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

  function syncThemeColorMeta() {
    var browserThemeColor = cssToken('--browser-theme-color') || '#f6f3f2';
    document.querySelectorAll('meta[name="theme-color"]').forEach(function (meta) {
      meta.setAttribute('content', browserThemeColor);
    });
  }

  function enforceLightOnlyMode() {
    delete root.dataset.theme;
    delete root.dataset.themePreference;
    root.style.colorScheme = 'light';

    syncThemeColorMeta();
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
    Chart.defaults.plugins.tooltip.cornerRadius = 14;
    Chart.defaults.plugins.tooltip.displayColors = true;
    Chart.defaults.plugins.tooltip.boxPadding = 4;

    Chart.defaults.plugins.legend = Chart.defaults.plugins.legend || {};
    Chart.defaults.plugins.legend.labels = Chart.defaults.plugins.legend.labels || {};
    Chart.defaults.plugins.legend.labels.color = textColor;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.boxHeight = 8;
    Chart.defaults.plugins.legend.labels.padding = 16;

    Chart.defaults.elements = Chart.defaults.elements || {};
    Chart.defaults.elements.line = Object.assign({}, Chart.defaults.elements.line, {
      borderWidth: 2,
      tension: 0.28
    });
    Chart.defaults.elements.point = Object.assign({}, Chart.defaults.elements.point, {
      radius: 0,
      hoverRadius: 4,
      borderWidth: 0
    });
    Chart.defaults.elements.bar = Object.assign({}, Chart.defaults.elements.bar, {
      borderRadius: 10,
      borderSkipped: false
    });
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
    const rawOptions = options || {};
    const built = buildFetchConfig(rawOptions);
    const perf = window.BIOMIE_PERF;
    const perfId = perf && typeof perf.startFetch === 'function' ? perf.startFetch(url, rawOptions) : null;
    let response = null;
    try {
      response = await fetch(url, built.config);
      const payload = await parseApiResponse(response);
      if (perfId && perf && typeof perf.endFetch === 'function') {
        perf.endFetch(perfId, response.status, response.ok && !(payload && payload.ok === false));
      }
      return { response: response, payload: payload };
    } catch (err) {
      if (perfId && perf && typeof perf.endFetch === 'function') {
        perf.endFetch(perfId, response ? response.status : (err?.code || 0), false);
      }
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

  window.BIOMIE_CHART_THEME = {
    cssToken: cssToken,
    alphaColor: alphaColor,
    getChartTheme: getChartTheme
  };

  enforceLightOnlyMode();
  syncChartDefaults();

  document.addEventListener('DOMContentLoaded', function () {
    enforceLightOnlyMode();
    syncThemeColorMeta();
    syncChartDefaults();
    window.requestAnimationFrame(refreshActiveCharts);

    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('overlay');
    var toggle = document.getElementById('menu_toggle');
    var main = document.querySelector('.main');
    var storageCollapsedKey = 'biomie.sidebar.compact';
    var legacyStorageCollapsedKey = 'biomie:sidebar-collapsed';

    if (!sidebar || !toggle) return;

    sidebar.setAttribute('tabindex', '-1');

    var mq = window.matchMedia('(max-width: 1180px)');
    function isMobile() { return mq.matches; }
    function storageGet(key) {
      try { return window.localStorage.getItem(key); } catch (error) { return null; }
    }
    function storageSet(key, value) {
      try { window.localStorage.setItem(key, value); } catch (error) {}
    }
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
        root.classList.toggle('sidebar-compact', collapsed);
        sidebar.setAttribute('aria-hidden', 'false');
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      }
      root.classList.add('sidebar-ready');
    }
    function applyStoredState() {
      if (!isMobile()) {
        var storedCompact = storageGet(storageCollapsedKey);
        if (storedCompact === null) {
          storedCompact = storageGet(legacyStorageCollapsedKey);
        }
        if (storedCompact === '1') {
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
      root.classList.remove('sidebar-compact');
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
        storageSet(storageCollapsedKey, sidebar.classList.contains('collapsed') ? '1' : '0');
        storageSet(legacyStorageCollapsedKey, sidebar.classList.contains('collapsed') ? '1' : '0');
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
      } else if (storageGet(storageCollapsedKey) === '1' || (storageGet(storageCollapsedKey) === null && storageGet(legacyStorageCollapsedKey) === '1')) {
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

    // ── NavContext: restore cross-page filter state ─────────────────────────
    if (window.NavContext) {
      window.NavContext.syncFromUrl();
      window.NavContext.applyToInputs();

      // Persist date range whenever inputs change
      var _ds = document.getElementById('dateStart');
      var _de = document.getElementById('dateEnd');
      if (_ds) {
        _ds.addEventListener('change', function () {
          if (window.NavContext) {
            window.NavContext.setDateRange(_ds.value || '', _de ? _de.value || '' : '');
          }
        });
      }
      if (_de) {
        _de.addEventListener('change', function () {
          if (window.NavContext) {
            window.NavContext.setDateRange(_ds ? _ds.value || '' : '', _de.value || '');
          }
        });
      }

      // Render back-link breadcrumb when navigating in from another page
      var _fromPage = window.NavContext.get('from');
      if (_fromPage) {
        var _fromLabels = {
          'index.html': 'Painel Comercial',
          'vendedores.html': 'Time Comercial',
          'pedidos.html': 'NF do Período',
          'financeiro.html': 'Financeiro',
          'plano_voo.html': 'Rota Comercial',
          'mercadorias.html': 'Mix Faturado',
          'mapa_vendas.html': 'Mapa de Vendas'
        };
        var _fromLabel = _fromLabels[_fromPage] || _fromPage;
        var _dr = window.NavContext.getDateRange();
        var _backP = new URLSearchParams();
        if (_dr.start) _backP.set('start', _dr.start);
        if (_dr.end) _backP.set('end', _dr.end);
        var _backUrl = _fromPage + (_backP.toString() ? '?' + _backP.toString() : '');
        var _crumb = document.createElement('div');
        _crumb.className = 'bmie-breadcrumb';
        _crumb.innerHTML = '<a href="' + _backUrl + '">'
          + '<span class="material-symbols-rounded" style="font-size:14px;vertical-align:middle;margin-right:3px">arrow_back</span>'
          + _fromLabel + '</a>';
        var _topbarLeft = document.querySelector('.topbar-left');
        if (_topbarLeft) _topbarLeft.appendChild(_crumb);
      }
    }

    // ── Compare toggle button wiring ────────────────────────────────────────
    var _compareBtn = document.getElementById('compareToggle');
    if (_compareBtn && window.CompareMode) {
      _compareBtn.classList.toggle('is-active', window.CompareMode.isActive());
      _compareBtn.addEventListener('click', function () {
        window.CompareMode.toggle();
        _compareBtn.classList.toggle('is-active', window.CompareMode.isActive());
        window.dispatchEvent(new CustomEvent('biomie:compare-toggled', { detail: { active: window.CompareMode.isActive() } }));
      });
    }
  });
})();

// ── NavContext — estado compartilhado de navegação entre páginas ─────────────
//
// Persiste filtros (datas, vendedor, região) em sessionStorage e URL.
// Disponível como window.NavContext em todos os scripts da página.
window.NavContext = (function () {
  var SK = 'biomie:nav';
  var URL_KEYS = ['start', 'end', 'vendor_id', 'vendor_nome', 'client_id', 'uf', 'cidade', 'preset', 'from'];

  function _load() {
    try { return JSON.parse(sessionStorage.getItem(SK) || '{}'); } catch (e) { return {}; }
  }
  function _save(s) {
    try { sessionStorage.setItem(SK, JSON.stringify(s)); } catch (e) {}
  }

  return {
    get: function (k) { var v = _load()[k]; return v !== undefined ? v : null; },

    set: function (k, v) {
      var s = _load();
      if (v == null) { delete s[k]; } else { s[k] = String(v); }
      _save(s);
    },

    merge: function (obj) {
      var s = _load();
      Object.keys(obj || {}).forEach(function (k) {
        if (obj[k] == null) { delete s[k]; } else { s[k] = String(obj[k]); }
      });
      _save(s);
    },

    getDateRange: function () {
      var s = _load();
      return { start: s.start || '', end: s.end || '', preset: s.preset || '' };
    },

    setDateRange: function (start, end, preset) {
      var s = _load();
      if (start) s.start = start;
      if (end) s.end = end;
      if (preset !== undefined) s.preset = preset || '';
      _save(s);
    },

    // Write saved state into DOM date inputs. Returns true if applied.
    applyToInputs: function () {
      var dr = this.getDateRange();
      if (!dr.start || !dr.end) return false;
      var si = document.getElementById('dateStart');
      var ei = document.getElementById('dateEnd');
      if (si) si.value = dr.start;
      if (ei) ei.value = dr.end;
      return !!(si || ei);
    },

    // Read relevant URL params and merge into sessionStorage state.
    syncFromUrl: function () {
      try {
        var params = new URLSearchParams(window.location.search);
        var s = _load();
        var changed = false;
        URL_KEYS.forEach(function (k) {
          var v = params.get(k);
          if (v) { s[k] = v; changed = true; }
        });
        if (changed) _save(s);
      } catch (e) {}
    },

    // Update URL (replaceState) to reflect current filter state.
    pushState: function (extra) {
      try {
        var s = Object.assign({}, _load(), extra || {});
        var params = new URLSearchParams();
        if (s.start) params.set('start', s.start);
        if (s.end) params.set('end', s.end);
        if (s.vendor_id) params.set('vendor_id', s.vendor_id);
        if (s.vendor_nome) params.set('vendor_nome', s.vendor_nome);
        if (s.uf) params.set('uf', s.uf);
        if (s.cidade) params.set('cidade', s.cidade);
        var qs = params.toString();
        var cur = window.location.search.replace(/^\?/, '');
        if (qs !== cur) {
          window.history.replaceState({}, '', qs ? ('?' + qs) : window.location.pathname);
        }
      } catch (e) {}
    },

    // Build a contextual URL for the given page, merging saved state with extras.
    buildUrl: function (page, extra) {
      var s = Object.assign({}, _load(), extra || {});
      var params = new URLSearchParams();
      if (s.start) params.set('start', s.start);
      if (s.end) params.set('end', s.end);
      if (s.vendor_id) params.set('vendor_id', s.vendor_id);
      if (s.vendor_nome) params.set('vendor_nome', s.vendor_nome);
      if (s.uf) params.set('uf', s.uf);
      if (s.cidade) params.set('cidade', s.cidade);
      if (s.from) params.set('from', s.from);
      var qs = params.toString();
      return page + (qs ? ('?' + qs) : '');
    },

    // Navigate to a page, carrying the current filter context.
    navigateTo: function (page, extra) {
      window.location.href = this.buildUrl(page, extra);
    },

    // Returns an <a> tag string linking to a page with context preserved.
    link: function (page, extra, label) {
      var href = this.buildUrl(page, extra || {});
      var safe = String(label || page).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      return '<a href="' + href + '" class="bmie-ctx-link">' + safe + '</a>';
    }
  };
})();

// ── CompareMode — comparação rápida com período anterior ─────────────────────
//
// Ao ativar, as páginas devem buscar o período anterior e renderizar deltas.
window.CompareMode = (function () {
  var SK = 'biomie:compare';

  function isActive() {
    try { return sessionStorage.getItem(SK) === '1'; } catch (e) { return false; }
  }

  function toggle() {
    try {
      sessionStorage.setItem(SK, isActive() ? '0' : '1');
    } catch (e) {}
  }

  // Returns { start, end } of the period immediately before the given range
  // (same duration, ending one day before start).
  function getPrevPeriod(start, end) {
    if (!start || !end) return null;
    try {
      var s = new Date(start + 'T12:00:00');
      var e = new Date(end + 'T12:00:00');
      var durationMs = e.getTime() - s.getTime();
      var pe = new Date(s.getTime() - 86400000);       // day before start
      var ps = new Date(pe.getTime() - durationMs);
      function fmt(d) {
        return d.getFullYear()
          + '-' + String(d.getMonth() + 1).padStart(2, '0')
          + '-' + String(d.getDate()).padStart(2, '0');
      }
      return { start: fmt(ps), end: fmt(pe) };
    } catch (e) { return null; }
  }

  // Renders a small delta badge (↑/↓/→) comparing current vs previous value.
  // opts.invertColors = true for metrics where lower is better (e.g. overdue).
  function renderDelta(current, prev, opts) {
    opts = opts || {};
    var curr = Number(current || 0);
    var prv  = Number(prev   || 0);
    if (prv === 0) return '';
    var pct  = ((curr - prv) / Math.abs(prv)) * 100;
    var abs  = Math.abs(pct);
    var sign = pct >= 0.05 ? '+' : '';
    var cls, icon;
    if (abs < 1)      { cls = 'bmie-delta-flat'; icon = '→'; }
    else if (pct > 0) { cls = opts.invertColors ? 'bmie-delta-down' : 'bmie-delta-up'; icon = '↑'; }
    else              { cls = opts.invertColors ? 'bmie-delta-up'   : 'bmie-delta-down'; icon = '↓'; }
    return '<span class="bmie-delta ' + cls + '" title="vs. período anterior">'
      + icon + ' ' + sign + pct.toFixed(1) + '%</span>';
  }

  return { isActive: isActive, toggle: toggle, getPrevPeriod: getPrevPeriod, renderDelta: renderDelta };
})();

// ── Estado vazio — helper para renderizar placeholders informativos ───────────
window.renderEmptyState = function (container, msg, icon) {
  if (!container) return;
  var safe = String(msg || 'Sem dados no período selecionado.')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  container.innerHTML = '<div class="bmie-empty">'
    + '<span class="material-symbols-rounded bmie-empty-icon">' + (icon || 'search_off') + '</span>'
    + '<span class="bmie-empty-msg">' + safe + '</span>'
    + '</div>';
};
