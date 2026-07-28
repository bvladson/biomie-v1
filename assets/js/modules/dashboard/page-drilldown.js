/**
 * BIomie - filtros inteligentes e leitura operacional do dashboard.
 *
 * O contexto de vendedores e carregado antes de o app decidir entre a visao
 * geral e commercial_dashboard.php. Isso evita renderizar dados globais para
 * usuarios com escopo own/selected e impede que vendor_id amplie permissao.
 */
(function () {
  'use strict';

  if (window.BDashboard) return;

  var B = window.BIomie || {};
  var currentPage = 1;
  var currentSort = { field: 'emissao', dir: 'desc' };
  var currentQuick = '';
  // Filtro Comercial Inteligente — canal (operação) + composição.
  var currentChannel = '';            // '' = todas · 'varejo' · 'licitacao'
  var channelUserSelected = false;    // usuário escolheu explicitamente? (senão: usa pref salva)
  var channelContext = { allowed: [], businessScope: '', isMixed: false, ready: false };
  var currentFilterHash = '';
  var currentVendorIds = [];
  var currentVendorNames = [];
  var currentVendorId = '';
  var currentVendorName = '';
  var vendorDirectory = [];
  var idToVendor = new Map();
  var nameToVendor = new Map();
  var requestController = null;
  var requestSeq = 0;
  var mounted = false;
  var context = {
    ready: false,
    mode: 'unknown',
    restricted: true,
    allowedCount: null,
    failed: false
  };

  function escHtml(value) {
    if (B.escapeHtml) return B.escapeHtml(value);
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function fmtCurrency(value) {
    if (B.formatCurrency) return B.formatCurrency(Number(value || 0));
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL'
    }).format(Number(value || 0));
  }

  function fmtNumber(value) {
    if (B.formatNumber) return B.formatNumber(Number(value || 0));
    return new Intl.NumberFormat('pt-BR').format(Number(value || 0));
  }

  function getText(id) {
    var element = document.getElementById(id);
    return element ? String(element.value || '').trim() : '';
  }

  function getRange() {
    var navRange = window.NavContext && typeof window.NavContext.getDateRange === 'function'
      ? window.NavContext.getDateRange()
      : {};
    return {
      start: getText('dateStart') || String(navRange.start || ''),
      end: getText('dateEnd') || String(navRange.end || '')
    };
  }

  function normalize(value) {
    return String(value || '').trim().toLocaleLowerCase('pt-BR');
  }

  function normalizeVendorIds(value) {
    var raw = Array.isArray(value) ? value : String(value || '').split(',');
    var seen = new Set();
    var ids = [];
    raw.forEach(function (candidate) {
      var id = String(candidate == null ? '' : candidate).trim();
      if (!id || id === '0' || id.length > 100 || seen.has(id) || ids.length >= 50) return;
      seen.add(id);
      ids.push(id);
    });
    return ids;
  }

  function namesForVendorIds(ids, suppliedNames) {
    var supplied = Array.isArray(suppliedNames) ? suppliedNames : [];
    return normalizeVendorIds(ids).map(function (id, index) {
      var vendor = idToVendor.get(id);
      return vendor ? vendor.name : String(supplied[index] || id).trim();
    });
  }

  function navValue(key) {
    return window.NavContext && typeof window.NavContext.get === 'function'
      ? String(window.NavContext.get(key) || '')
      : '';
  }

  function readUrlChannel() {
    try {
      var params = new URLSearchParams(window.location.search || '');
      var raw = String(params.get('channel') || '').trim().toLowerCase();
      return raw === 'varejo' || raw === 'licitacao' ? raw : '';
    } catch (error) {
      return '';
    }
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="biomie-csrf"]');
    return meta ? String(meta.getAttribute('content') || '') : '';
  }

  // Assinatura do recorte desejado — guarda anti-resposta-atrasada (par do
  // filter_hash do servidor): se o desejo mudar entre enviar e receber, não pinta.
  function desiredSignature() {
    var range = getRange();
    return JSON.stringify({
      channel: currentChannel || 'todas',
      vendors: currentVendorIds.slice().sort(),
      start: range.start,
      end: range.end,
      cliente: getText('f_dash_cliente'),
      produto: getText('f_dash_produto'),
      quick: currentQuick
    });
  }

  function requestedVendorFromNavigation() {
    var params;
    try {
      params = new URLSearchParams(window.location.search || '');
    } catch (error) {
      params = new URLSearchParams();
    }
    var ids = normalizeVendorIds(params.get('vendor_ids') || navValue('vendor_ids'));
    var legacyId = String(params.get('vendor_id') || navValue('vendor_id') || '').trim();
    if (!ids.length && legacyId) ids = normalizeVendorIds([legacyId]);
    return {
      ids: ids,
      name: String(params.get('vendor_nome') || navValue('vendor_nome') || '').trim()
    };
  }

  function persistVendorSelection(ids, names) {
    currentVendorIds = normalizeVendorIds(ids);
    currentVendorNames = namesForVendorIds(currentVendorIds, Array.isArray(names) ? names : [names]);
    currentVendorId = currentVendorIds.length === 1 ? currentVendorIds[0] : '';
    currentVendorName = currentVendorIds.length === 1 ? String(currentVendorNames[0] || '') : '';
    var idsCsv = currentVendorIds.join(',');

    var hidden = document.getElementById('f_dash_vendor_id');
    var legacyName = document.getElementById('f_dash_vendedor');
    if (hidden) hidden.value = idsCsv;
    if (legacyName) legacyName.value = currentVendorName;

    if (window.NavContext && typeof window.NavContext.merge === 'function') {
      window.NavContext.merge({
        vendor_ids: idsCsv || null,
        vendor_id: currentVendorId || null,
        vendor_nome: currentVendorName || null,
        vendor_nomes: null
      });
    }

    try {
      var url = new URL(window.location.href);
      if (idsCsv) {
        url.searchParams.set('vendor_ids', idsCsv);
      } else {
        url.searchParams.delete('vendor_ids');
      }
      if (currentVendorId) {
        url.searchParams.set('vendor_id', currentVendorId);
        if (currentVendorName) url.searchParams.set('vendor_nome', currentVendorName);
      } else {
        url.searchParams.delete('vendor_id');
        url.searchParams.delete('vendor_nome');
      }
      window.history.replaceState({}, '', url);
    } catch (error) {
      // Navegacao continua funcional mesmo se History API estiver indisponivel.
    }

    document.dispatchEvent(new CustomEvent('biomie:vendor-context-change', {
      detail: {
        vendorIds: currentVendorIds.slice(),
        vendorIdsCsv: idsCsv,
        vendorId: currentVendorId,
        vendorNames: currentVendorNames.slice(),
        vendorName: currentVendorName,
        source: 'dashboard'
      }
    }));
  }

  function populateVendorOptions() {
    var datalist = document.getElementById('dashboardVendorOptions');
    if (datalist) {
      datalist.innerHTML = vendorDirectory.map(function (vendor) {
        return '<option value="' + escHtml(vendor.name) + '"></option>';
      }).join('');
    }

    var input = document.getElementById('f_dash_vendedor');
    var hidden = document.getElementById('f_dash_vendor_id');
    if (input && currentVendorId) {
      var selected = idToVendor.get(currentVendorId);
      input.value = selected ? selected.name : currentVendorName;
    } else if (input) {
      input.value = '';
    }
    if (hidden) hidden.value = currentVendorIds.join(',');
  }

  function hydrateInitialContext(payload) {
    vendorDirectory = Array.isArray(payload && payload.vendors)
      ? payload.vendors.map(function (vendor) {
        return {
          id: String(vendor && vendor.id != null ? vendor.id : '').trim(),
          name: String(
            vendor && vendor.label != null
              ? vendor.label
              : (vendor && vendor.name != null
                ? vendor.name
                : (vendor && vendor.id != null ? vendor.id : ''))
          ).trim()
        };
      }).filter(function (vendor) { return vendor.id && vendor.name; })
      : [];

    idToVendor = new Map();
    nameToVendor = new Map();
    vendorDirectory.forEach(function (vendor) {
      idToVendor.set(vendor.id, vendor);
      nameToVendor.set(normalize(vendor.name), vendor);
    });

    var sellerScope = payload && payload.seller_scope ? payload.seller_scope : {};
    context.mode = String(sellerScope.mode || 'unknown');
    context.restricted = sellerScope.restricted !== false;
    context.allowedCount = sellerScope.allowed_count == null
      ? null
      : Number(sellerScope.allowed_count);
    context.failed = !(payload && payload.ok);

    // Escopo de canal (operação): decide se o toggle varejo/licitação aparece.
    var channelScope = payload && payload.channel_scope ? payload.channel_scope : null;
    if (channelScope) {
      channelContext = {
        allowed: Array.isArray(channelScope.allowed) ? channelScope.allowed.slice() : [],
        businessScope: String(channelScope.business_scope || ''),
        isMixed: channelScope.is_mixed === true,
        ready: true
      };
    } else {
      channelContext = { allowed: [], businessScope: '', isMixed: false, ready: true };
    }
    // Canal inicial: só da URL. Sem URL, a preferência salva vem no meta do 1º
    // carregamento (o servidor resolve pref→default e o meta reflete o efetivo).
    var urlChannel = readUrlChannel();
    if (urlChannel && channelContext.allowed.indexOf(urlChannel) !== -1) {
      currentChannel = urlChannel;
      channelUserSelected = true;   // veio explícito na URL
    } else {
      currentChannel = '';
      channelUserSelected = false;
    }

    var requested = requestedVendorFromNavigation();
    var allowedRequested = requested.ids.filter(function (id) { return idToVendor.has(id); });
    if (requested.ids.length && (allowedRequested.length || context.failed)) {
      var accepted = context.failed ? requested.ids : allowedRequested;
      persistVendorSelection(accepted, accepted.map(function (id) {
        var selected = idToVendor.get(id);
        return selected ? selected.name : (accepted.length === 1 ? requested.name : id);
      }));
    } else {
      currentVendorIds = [];
      currentVendorNames = [];
      currentVendorId = '';
      currentVendorName = '';
      if (requested.ids.length) persistVendorSelection([], []);
    }

    // No modo own com um unico vendedor, a selecao exata torna a leitura e os
    // atalhos mais claros. Com varios codigos, mantemos a carteira agregada.
    if (!currentVendorIds.length && context.mode === 'own' && vendorDirectory.length === 1) {
      persistVendorSelection([vendorDirectory[0].id], [vendorDirectory[0].name]);
    }

    context.ready = true;
    if (mounted) {
      populateVendorOptions();
      renderChips();
      renderCommercialFilterBar(null);
    }
    return context;
  }

  function fetchInitialContext() {
    return fetch('api/vendors_list.php', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    }).then(function (response) {
      if (!response.ok) throw new Error('Falha ao validar vendedores (' + response.status + ').');
      return response.json();
    }).then(function (payload) {
      if (!payload || !payload.ok) throw new Error('Resposta invalida da lista de vendedores.');
      return hydrateInitialContext(payload);
    }).catch(function () {
      // Falha fechada: commercial_dashboard.php ainda aplica o escopo no servidor.
      return hydrateInitialContext({
        ok: false,
        vendors: [],
        seller_scope: { mode: 'unknown', restricted: true, allowed_count: 0 }
      });
    });
  }

  var initialContextPromise = fetchInitialContext();

  function scopeReady() {
    return initialContextPromise;
  }

  function hasExtraFilters() {
    // Antes da preflight, nunca autorize o app a hidratar/renderizar overview.
    if (!context.ready) return true;
    return !!(
      context.restricted ||
      channelContext.isMixed ||   // mixed sempre usa o painel comercial (toggle+breakdown+pref)
      currentVendorIds.length ||
      currentChannel ||
      getText('f_dash_vendedor') ||
      getText('f_dash_cliente') ||
      getText('f_dash_produto') ||
      currentQuick
    );
  }

  function globalFilterValues() {
    var range = getRange();
    return {
      start: range.start,
      end: range.end,
      preset: navValue('preset'),
      vendor_ids: currentVendorIds.slice(),
      vendor_ids_csv: currentVendorIds.join(','),
      vendor_id: currentVendorId,
      vendor_names: currentVendorNames.slice(),
      vendor_nome: currentVendorName,
      cliente: getText('f_dash_cliente'),
      produto: getText('f_dash_produto'),
      quick: currentQuick
    };
  }

  function registerGlobalFilters() {
    document.dispatchEvent(new CustomEvent('biomie:filter-register', {
      detail: {
        source: 'dashboard',
        fields: [
          {
            key: 'cliente',
            label: 'Cliente',
            type: 'search',
            placeholder: 'Nome do cliente',
            targetId: 'f_dash_cliente'
          },
          {
            key: 'produto',
            label: 'Produto',
            type: 'search',
            placeholder: 'Descricao do produto',
            targetId: 'f_dash_produto'
          },
          {
            key: 'quick',
            label: 'Situacao comercial',
            type: 'chips',
            options: [
              { value: 'com_componentes', label: 'Com frete/outras' },
              { value: 'sem_pedido', label: 'Sem pedido vinculado' }
            ],
            multiple: false
          }
        ],
        values: globalFilterValues()
      }
    }));
  }

  function renderChips() {
    registerGlobalFilters();
  }

  function updateChannelUrl() {
    try {
      var url = new URL(window.location.href);
      if (currentChannel) url.searchParams.set('channel', currentChannel);
      else url.searchParams.delete('channel');
      window.history.replaceState({}, '', url);
    } catch (error) {
      // History API pode estar bloqueada; o servidor ainda resolve pela pref.
    }
  }

  function channelToggleHtml() {
    var options = [{ v: '', label: 'Todas as operações' }];
    if (channelContext.allowed.indexOf('varejo') !== -1) options.push({ v: 'varejo', label: 'Varejo' });
    if (channelContext.allowed.indexOf('licitacao') !== -1) options.push({ v: 'licitacao', label: 'Licitação' });
    var buttons = options.map(function (opt) {
      var active = (opt.v === currentChannel) ? ' is-active' : '';
      return '<button type="button" class="commercial-channel-toggle__btn' + active + '"'
        + ' data-commercial-channel="' + opt.v + '"'
        + ' aria-pressed="' + (active ? 'true' : 'false') + '">' + escHtml(opt.label) + '</button>';
    }).join('');
    return '<div class="commercial-channel-toggle" role="group" aria-label="Filtrar por operação">'
      + '<span class="commercial-channel-toggle__label">Operação</span>' + buttons + '</div>';
  }

  function breakdownHtml(response) {
    var bd = response && response.kpis && response.kpis.breakdown;
    if (!bd || !bd.available) return '';
    var v = bd.varejo || {};
    var l = bd.licitacao || {};
    function item(cls, label, data) {
      return '<span class="commercial-breakdown__item">'
        + '<span class="commercial-breakdown__dot commercial-breakdown__dot--' + cls + '"></span>'
        + escHtml(label) + ' <span class="commercial-breakdown__value">' + fmtCurrency(data.value || 0) + '</span>'
        + ' <span class="commercial-breakdown__nfs">(' + fmtNumber(data.nfs || 0) + ' NFs)</span></span>';
    }
    return '<div class="commercial-breakdown" aria-label="Composição por operação">'
      + item('varejo', 'Varejo', v) + item('licitacao', 'Licitação', l) + '</div>';
  }

  function sanitizationHtml(response) {
    var meta = response && response.meta;
    if (!meta || !meta.sanitized) return '';
    return '<div class="commercial-filter-notice" role="status">'
      + '<span class="material-symbols-rounded" aria-hidden="true">info</span>'
      + '<span>Parte do seu filtro salvo não está mais autorizada e foi removida. '
      + 'A visão mostra apenas o que você pode ver agora.</span></div>';
  }

  function renderCommercialFilterBar(response) {
    var bar = document.getElementById('dashboardCommercialFilter');
    if (!bar) return;
    // Toggle de operação só no contexto mixed; single-channel fixa o canal.
    if (!channelContext.ready || !channelContext.isMixed) {
      bar.hidden = true;
      bar.innerHTML = '';
      return;
    }
    bar.hidden = false;
    bar.innerHTML = channelToggleHtml() + breakdownHtml(response) + sanitizationHtml(response);
    Array.prototype.forEach.call(
      bar.querySelectorAll('[data-commercial-channel]'),
      function (btn) {
        btn.addEventListener('click', function () {
          applyChannel(String(btn.getAttribute('data-commercial-channel') || ''));
        });
      }
    );
  }

  function applyChannel(channel) {
    channel = (channel === 'varejo' || channel === 'licitacao') ? channel : '';
    if (channel && channelContext.allowed.indexOf(channel) === -1) return; // não autorizado
    if (channelUserSelected && channel === currentChannel) return;
    currentChannel = channel;
    channelUserSelected = true;   // escolha explícita sobrepõe a preferência salva
    currentPage = 1;
    updateChannelUrl();
    renderCommercialFilterBar(null);
    // O GET do dashboard valida (403 se proibido) e devolve o efetivo; só então
    // persistimos a preferência (regra: salvar após o backend validar).
    requestCurrentView(false).then(function () {
      persistCommercialPref();
    }).catch(function () { /* erro já exibido em showError */ });
  }

  // Persiste a preferência SÓ depois de o backend validar (o GET do dashboard já
  // é a trava 403). Envia o estado completo; "todas" ⇒ []; sem restrição ⇒ null.
  function persistCommercialPref() {
    if (!channelContext.ready || !channelContext.businessScope) return; // consolidado não persiste
    var token = csrfToken();
    if (!token) return;
    var payload = {
      action: 'save',
      channels: currentChannel ? [currentChannel] : [],
      seller_ids: currentVendorIds.length ? currentVendorIds.slice() : null,
      csrf_token: token
    };
    fetch('api/commercial_filter_pref.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': token },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (data) {
        // 403 (scope_denied) não deveria ocorrer pela UI; se ocorrer, apenas avisa.
        if (data && data.ok === false && data.error === 'scope_denied') {
          showError('Seu acesso a este filtro mudou. Recarregue a página.');
        }
      }).catch(function () { /* persistência é best-effort; o recorte já foi aplicado */ });
  }

  function resolveTypedVendor(commitUnique) {
    if (currentVendorIds.length) return true;
    var input = document.getElementById('f_dash_vendedor');
    var typed = input ? String(input.value || '').trim() : '';
    if (!typed) {
      return false;
    }

    var exact = nameToVendor.get(normalize(typed));
    if (!exact && commitUnique) {
      var matches = vendorDirectory.filter(function (vendor) {
        return normalize(vendor.name).indexOf(normalize(typed)) !== -1;
      });
      if (matches.length === 1) exact = matches[0];
    }

    if (exact) {
      if (input) input.value = exact.name;
      persistVendorSelection([exact.id], [exact.name]);
      return true;
    }

    persistVendorSelection([], []);
    return false;
  }

  function setFilteredMode(active) {
    document.body.classList.toggle('dashboard--seller-scope', !!active);
    var focus = document.getElementById('dashboardSellerFocus');
    if (focus) focus.hidden = !active;
  }

  function buildUrl(force) {
    var range = getRange();
    var params = new URLSearchParams();
    if (range.start) params.set('start', range.start);
    if (range.end) params.set('end', range.end);

    var typedVendor = getText('f_dash_vendedor');
    if (currentVendorIds.length) params.set('vendor_ids', currentVendorIds.join(','));
    if (currentVendorId) params.set('vendor_id', currentVendorId);
    else if (typedVendor) params.set('vendedor', typedVendor);

    var client = getText('f_dash_cliente');
    var product = getText('f_dash_produto');
    if (client) params.set('cliente', client);
    if (product) params.set('produto', product);
    if (currentQuick) params.set('quick', currentQuick);
    // Canal só é ENVIADO quando o usuário escolheu (senão o servidor usa a pref).
    // 'all' é o sentinela de "Todas": escolha explícita que sobrepõe a pref.
    if (channelUserSelected) params.set('channel', currentChannel || 'all');
    params.set('sort', currentSort.field);
    params.set('direction', currentSort.dir);
    params.set('page', String(currentPage));
    params.set('per_page', '50');
    if (force) params.set('_ts', String(Date.now()));
    return 'api/commercial_dashboard.php?' + params.toString();
  }

  function setBind(name, text) {
    document.querySelectorAll('[data-bind="' + name + '"]').forEach(function (element) {
      element.textContent = text;
    });
  }

  function updateKpis(kpis) {
    if (!kpis) return;
    setBind('summary-revenue', fmtCurrency(kpis.base_comercial_nf));
    setBind('summary-orders', fmtNumber(kpis.nfs) + ' NFs');
    setBind('summary-ticket', fmtCurrency(kpis.ticket_medio));
    setBind('summary-clients', fmtNumber(kpis.clientes));
    setBind('summary-cancelled-value', fmtCurrency(kpis.cancelled_value));
    setBind('summary-wallet', fmtCurrency(kpis.wallet_value));
    // Carteira = cliente recorrente. Mostra a composição (recorrente × novo) no
    // subtítulo: sozinho, o valor não diz se a base está sustentando a receita.
    if (kpis.carteira_disponivel === false) {
      setBind('summary-wallet-sub', 'Composição indisponível no momento.');
    } else if (kpis.carteira_pct != null) {
      setBind('summary-wallet-sub',
        Number(kpis.carteira_pct).toFixed(1).replace('.', ',') + '% do faturado · '
        + fmtNumber(kpis.carteira_clientes || 0) + ' clientes · novos '
        + fmtCurrency(kpis.novos_value || 0));
    }
    setBind('summary-quotes', fmtCurrency(kpis.quotes_value));
  }

  function updateCharts(response) {
    if (!response) return;
    var daily = response.series && response.series.evolucao_diaria
      ? response.series.evolucao_diaria
      : [];
    var monthly = response.series && response.series.evolucao_mensal
      ? response.series.evolucao_mensal
      : [];
    var rankings = response.rankings || {};
    var targetMonths = response.target && response.target.months
      ? response.target.months
      : {};

    if (typeof B.createLineChart === 'function') {
      B.createLineChart(
        'salesTimelineChart',
        daily.map(function (row) { return row.dia; }),
        daily.map(function (row) { return Number(row.base_comercial || 0); }),
        daily.map(function (row) { return Number(row.nfs || 0); }),
        { dates: daily.map(function (row) { return row.dia || ''; }) }
      );
    }

    if (typeof B.createRevenueTicketChart === 'function') {
      var sellers = (rankings.top_vendedores || []).map(function (row) {
        var revenue = Number(row.base_comercial || 0);
        var orders = Number(row.nfs || 0);
        return {
          vendor_id: row.vendor_id || row.codigo || '',
          vendor_name: row.nome || 'Sem vendedor',
          revenue: revenue,
          gross_revenue: revenue,
          orders: orders,
          ticket_avg: orders > 0 ? revenue / orders : 0
        };
      });
      B.createRevenueTicketChart('vendorsBarChart', sellers);
    }

    if (typeof B.createHorizontalBarChart === 'function') {
      var products = (rankings.top_produtos || []).slice(0, 8);
      B.createHorizontalBarChart(
        'topProductsChart',
        products.map(function (row) { return String(row.produto || '').substring(0, 28); }),
        products.map(function (row) { return Number(row.receita || 0); }),
        { barColor: 'rgba(37,99,235,0.7)', drillChartId: 'top_products', drillTitle: 'Produtos em destaque' }
      );

      var clients = (rankings.top_clientes || []).slice(0, 8);
      B.createHorizontalBarChart(
        'topClientsChart',
        clients.map(function (row) { return String(row.nome || '').substring(0, 28); }),
        clients.map(function (row) { return Number(row.base_comercial || 0); }),
        { barColor: 'rgba(22,163,74,0.7)', drillChartId: 'top_customers', drillTitle: 'Clientes em destaque' }
      );
    }

    if (typeof B.createMixedDailyChart === 'function') {
      B.createMixedDailyChart('dailyMixedChart', daily.map(function (row) {
        return {
          date: row.dia,
          billed_value: Number(row.base_comercial || 0),
          authorized_value: 0,
          cancelled_value: 0
        };
      }));
    }

    if (typeof B.createMonthlyComparisonSemesters === 'function') {
      B.createMonthlyComparisonSemesters(monthly.map(function (row) {
        return {
          competencia: row.mes,
          revenue: Number(row.base_comercial || 0),
          target: Number(targetMonths[row.mes] || 0),
          forecast: Number(row.base_comercial || 0)
        };
      }));
    }
  }

  function renderTopClients(rows) {
    var tbody = document.getElementById('topClientsTable');
    if (!tbody) return;
    rows = Array.isArray(rows) ? rows.slice(0, 5) : [];
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">'
        + 'Sem clientes para este recorte.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(function (row) {
      var invoices = Number(row.nfs || 0);
      var revenue = Number(row.base_comercial || 0);
      var ticket = invoices > 0 ? revenue / invoices : 0;
      return '<tr><td><strong>' + escHtml(row.nome || 'Cliente nao informado') + '</strong></td>'
        + '<td>' + fmtCurrency(revenue) + '</td>'
        + '<td>' + fmtNumber(invoices) + '</td>'
        + '<td>' + fmtCurrency(ticket) + '</td>'
        + '<td>' + escHtml(row.ultima_compra || '-') + '</td></tr>';
    }).join('');
  }

  function renderTopNfs(rows) {
    var tbody = document.getElementById('topOrdersTable');
    if (!tbody) return;
    rows = Array.isArray(rows) ? rows : [];
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">'
        + 'Nenhuma NF encontrada para os filtros aplicados.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(function (row) {
      var nfCode = String(row.numero_nf || '');
      var actionAttrs = ' data-biomie-action="open-invoice"'
        + ' data-numero-nf="' + escHtml(nfCode) + '"'
        + ' data-nota-key="' + escHtml(row.nota_key || '') + '"'
        + ' data-chave-nfe="' + escHtml(row.chave_nfe || '') + '"';
      return '<tr>'
        + '<td><button type="button" class="row-inline-link"' + actionAttrs + '><strong>'
        + escHtml(nfCode || 'NF sem numero') + '</strong></button></td>'
        + '<td>' + escHtml(row.data_emissao || '-') + '</td>'
        + '<td><strong>' + escHtml(row.nome_cliente || 'Cliente nao informado') + '</strong></td>'
        + '<td>' + escHtml(row.nome_vendedor || '-') + '</td>'
        + '<td>-</td>'
        + '<td><strong>' + fmtCurrency(row.base_comercial_nf) + '</strong></td>'
        + '<td data-mobile-skip><button type="button" class="commercial-action"' + actionAttrs + '>'
        + '<span class="material-symbols-rounded" aria-hidden="true">open_in_new</span>Detalhar</button></td>'
        + '</tr>';
    }).join('');
  }

  function renderPagination(pagination) {
    var element = document.getElementById('dashboardNfsPagination');
    if (!element) return;
    if (!pagination || Number(pagination.total_pages || 0) <= 1) {
      element.style.display = 'none';
      element.innerHTML = '';
      return;
    }

    element.style.display = 'flex';
    element.innerHTML = '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" id="dashPagPrev"'
      + (pagination.has_prev ? '' : ' disabled') + '>Anterior</button>'
      + '<span>Pagina ' + fmtNumber(pagination.page) + ' de ' + fmtNumber(pagination.total_pages)
      + ' (' + fmtNumber(pagination.total) + ' NFs)</span>'
      + '<button class="biomie-btn biomie-btn--secondary biomie-btn--sm" id="dashPagNext"'
      + (pagination.has_next ? '' : ' disabled') + '>Proxima</button>';

    var previous = document.getElementById('dashPagPrev');
    var next = document.getElementById('dashPagNext');
    if (previous) previous.addEventListener('click', function () {
      currentPage = Number(pagination.page || 1) - 1;
      loadFiltered(false, { keepPosition: true });
    });
    if (next) next.addEventListener('click', function () {
      currentPage = Number(pagination.page || 1) + 1;
      loadFiltered(false, { keepPosition: true });
    });
  }

  function setSellerMetric(key, value) {
    var element = document.querySelector('[data-seller-kpi="' + key + '"]');
    if (element) element.textContent = value;
  }

  function sellerTitle() {
    if (currentVendorIds.length > 1) {
      return currentVendorIds.length + ' vendedores selecionados';
    }
    if (currentVendorId) {
      var vendor = idToVendor.get(currentVendorId);
      return vendor ? vendor.name : (currentVendorName || getText('f_dash_vendedor') || 'Vendedor');
    }
    if (getText('f_dash_vendedor')) return getText('f_dash_vendedor');
    if (context.mode === 'own') return 'Minha carteira';
    if (context.mode === 'selected') return 'Carteira selecionada';
    return 'Recorte comercial';
  }

  function renderSellerFocus(response) {
    var target = response.target || {};
    var kpis = response.kpis || {};
    var range = getRange();
    var title = document.getElementById('dashboardSellerName');
    var label = document.getElementById('dashboardSellerScopeLabel');
    var period = document.getElementById('dashboardSellerPeriod');
    var attainment = document.getElementById('dashboardSellerAttainment');
    var risk = document.getElementById('dashboardSellerRisk');
    var progress = document.getElementById('dashboardSellerProgress');
    var expected = document.getElementById('dashboardSellerExpected');
    var insight = document.getElementById('dashboardSellerInsight');
    var openVendor = document.getElementById('dashboardOpenVendor');
    var agenda = document.getElementById('dashboardOpenAgenda');

    if (title) title.textContent = sellerTitle();
    if (label) {
      label.textContent = currentVendorIds.length > 1
        ? 'Resultado conjunto'
        : (currentVendorId
          ? 'Resultado individual'
          : (context.restricted ? 'Visao do seu acesso' : 'Recorte filtrado'));
    }
    if (period) period.textContent = (range.start || '-') + ' a ' + (range.end || '-');

    var attainmentValue = target.attainment_pct == null ? null : Number(target.attainment_pct);
    var expectedValue = target.expected_pct == null ? 0 : Number(target.expected_pct);
    if (attainment) attainment.textContent = attainmentValue == null ? '-' : attainmentValue.toFixed(1) + '%';
    if (progress) progress.style.width = Math.max(0, Math.min(100, attainmentValue || 0)) + '%';
    if (expected) expected.style.left = Math.max(0, Math.min(100, expectedValue)) + '%';

    var status = String(target.status || 'SEM_META');
    var tone = status === 'NO_RITMO' ? 'success'
      : (status === 'ATENCAO' ? 'warning' : (status === 'RISCO' ? 'danger' : 'neutral'));
    if (risk) {
      risk.className = 'dashboard-risk-badge dashboard-risk-badge--' + tone;
      risk.textContent = String(target.status_label || 'Meta nao cadastrada');
    }

    if (insight) {
      if (!target.available) {
        insight.textContent = 'Cadastre uma meta para acompanhar ritmo e valor restante no periodo.';
      } else if (!target.comparable) {
        insight.textContent = 'A meta fica fora da comparacao enquanto cliente, produto ou situacao estiverem filtrados.';
      } else if (Number(target.gap || 0) <= 0) {
        insight.textContent = 'Meta do periodo atingida. Use a carteira e os orcamentos para sustentar o proximo ciclo.';
      } else {
        insight.textContent = 'Faltam ' + fmtCurrency(target.gap)
          + ' para a meta. Ritmo atual: '
          + (target.pace_pct == null ? '-' : Number(target.pace_pct).toFixed(1) + '% do esperado') + '.';
      }
    }

    setSellerMetric('realized', fmtCurrency(kpis.base_comercial_nf));
    setSellerMetric('target', target.available ? fmtCurrency(target.period_target) : '-');
    setSellerMetric('wallet', fmtCurrency(kpis.wallet_value) + ' · ' + fmtNumber(kpis.wallet_orders));
    setSellerMetric('quotes', fmtCurrency(kpis.quotes_value) + ' · ' + fmtNumber(kpis.quotes_orders));
    setSellerMetric('overdue', fmtNumber(kpis.quotes_overdue) + ' orc.');
    setSellerMetric('clients', fmtNumber(kpis.clientes));
    setSellerMetric('ticket', fmtCurrency(kpis.ticket_medio));
    setSellerMetric('cancelled', fmtCurrency(kpis.cancelled_value) + ' · ' + fmtNumber(kpis.cancelled_orders));

    if (openVendor) {
      openVendor.hidden = currentVendorIds.length !== 1;
      openVendor.setAttribute('data-vendor-id', currentVendorId);
      openVendor.setAttribute('data-vendor-name', sellerTitle());
    }
    if (agenda) {
      var agendaParams = new URLSearchParams();
      if (currentVendorIds.length) {
        agendaParams.set('vendor_ids', currentVendorIds.join(','));
        agendaParams.set('seller_ids', currentVendorIds.join(','));
      }
      if (currentVendorId) agendaParams.set('seller_id', currentVendorId);
      if (range.start) agendaParams.set('start', range.start);
      if (range.end) agendaParams.set('end', range.end);
      agenda.href = 'quote_agenda.php' + (agendaParams.toString() ? '?' + agendaParams.toString() : '');
    }
  }

  function setLoading(loading) {
    var focus = document.getElementById('dashboardSellerFocus');
    if (focus) focus.classList.toggle('dashboard-seller-focus--loading', loading);
  }

  function showError(message) {
    var element = document.getElementById('pageError');
    if (!element) return;
    if (!message) {
      element.classList.add('d-none');
      element.innerHTML = '';
      return;
    }
    element.classList.remove('d-none');
    element.innerHTML = '<div class="module-alert module-alert--danger">'
      + '<strong>Nao foi possivel atualizar este recorte</strong><span>'
      + escHtml(message) + '</span></div>';
  }

  function loadFiltered(force, options) {
    options = options || {};
    return scopeReady().then(function () {
      resolveTypedVendor(false);
      setFilteredMode(true);
      renderChips();
      setLoading(true);
      showError('');

      if (requestController) requestController.abort();
      requestController = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var controller = requestController;
      var sequence = ++requestSeq;
      var fetchOptions = {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' }
      };
      if (controller) fetchOptions.signal = controller.signal;
      var timeoutMs = Number(options.timeoutMs || 30000);
      var timedOut = false;
      var timeoutId = timeoutMs > 0 && controller
        ? window.setTimeout(function () {
            timedOut = true;
            controller.abort();
          }, timeoutMs)
        : null;
      var sentSignature = desiredSignature();

      return fetch(buildUrl(force), fetchOptions).then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok || !payload || !payload.ok) {
            throw new Error(payload && (payload.message || (payload.error && payload.error.message))
              ? (payload.message || payload.error.message)
              : 'A API comercial nao respondeu corretamente.');
          }
          return payload;
        });
      }).then(function (response) {
        if (sequence !== requestSeq) return response;
        // Guarda filter_hash: se o recorte desejado mudou entre enviar e receber,
        // não pinta (evita resposta atrasada sobrescrever a seleção nova).
        if (desiredSignature() !== sentSignature) return response;

        // Sincroniza o toggle com o efetivo do servidor (pref/url resolvida).
        var meta = response.meta || {};
        var cs = meta.channel_scope || null;
        if (cs && !channelUserSelected) {
          var reqCh = cs.requested;   // null = todas
          currentChannel = (Array.isArray(reqCh) && reqCh.length === 1
            && channelContext.allowed.indexOf(reqCh[0]) !== -1) ? reqCh[0] : '';
        }
        currentFilterHash = String(meta.filter_hash || '');

        updateKpis(response.kpis);
        updateCharts(response);
        renderTopClients(response.rankings && response.rankings.top_clientes);
        renderTopNfs(response.rankings && response.rankings.top_nfs);
        renderPagination(response.pagination || null);
        renderSellerFocus(response);
        renderChips();
        renderCommercialFilterBar(response);
        document.dispatchEvent(new CustomEvent('biomie:dashboard-filtered', {
          detail: {
            vendorIds: currentVendorIds.slice(),
            vendorId: currentVendorId,
            filters: response.filters || {},
            sellerScope: response.seller_scope || {}
          }
        }));
        return response;
      }).catch(function (error) {
        if (error && error.name === 'AbortError') {
          if (timedOut) {
            throw new Error('A consulta deste recorte excedeu o tempo limite. Tente um periodo menor.');
          }
          return null;
        }
        if (sequence !== requestSeq) return null;
        showError(error && error.message ? error.message : 'Falha de comunicacao com a API.');
        throw error;
      }).finally(function () {
        if (timeoutId) window.clearTimeout(timeoutId);
        if (sequence !== requestSeq) return;
        requestController = null;
        setLoading(false);
      });
    });
  }

  function clearFilteredView() {
    if (requestController) requestController.abort();
    requestController = null;
    requestSeq++;
    renderChips();

    if (hasExtraFilters()) {
      return loadFiltered(false);
    }

    var pagination = document.getElementById('dashboardNfsPagination');
    if (pagination) pagination.style.display = 'none';
    var focus = document.getElementById('dashboardSellerFocus');
    if (focus) focus.hidden = true;
    setLoading(true);
    // Nao clica em Aplicar: isso geraria um segundo ciclo. O chamador dispara
    // biomie:dashboard-request-standard e o app executa a visao geral uma vez.
    document.dispatchEvent(new CustomEvent('biomie:dashboard-request-standard'));
    return Promise.resolve(null);
  }

  function syncGlobalFilterValues(detail) {
    detail = detail || {};
    var values = detail.values && typeof detail.values === 'object' ? detail.values : {};
    var range = detail.range && typeof detail.range === 'object' ? detail.range : {};
    var start = String(range.start || values.start || '').trim();
    var end = String(range.end || values.end || '').trim();
    var startInput = document.getElementById('dateStart');
    var endInput = document.getElementById('dateEnd');
    var client = document.getElementById('f_dash_cliente');
    var product = document.getElementById('f_dash_produto');

    if (startInput && start) startInput.value = start;
    if (endInput && end) endInput.value = end;
    if (client) client.value = String(values.cliente || '').trim();
    if (product) product.value = String(values.produto || '').trim();

    var quick = Array.isArray(values.quick) ? values.quick[0] : values.quick;
    currentQuick = ['com_componentes', 'sem_pedido'].indexOf(String(quick || '')) !== -1
      ? String(quick)
      : '';

    var requestedIds = normalizeVendorIds(
      values.vendor_ids || values.vendor_ids_csv || values.vendor_id || ''
    );
    var requestedNames = Array.isArray(values.vendor_names)
      ? values.vendor_names
      : [String(values.vendor_nome || '').trim()];
    var allowedIds = requestedIds.filter(function (id) { return idToVendor.has(id); });
    if (allowedIds.length) {
      persistVendorSelection(allowedIds, allowedIds.map(function (id) {
        return idToVendor.get(id).name;
      }));
    } else if (requestedIds.length && context.failed) {
      // Falha na lista nao relaxa a seguranca: o endpoint ainda valida o ID.
      // Preservamos o contexto global para nao apagar uma escolha valida.
      persistVendorSelection(requestedIds, requestedNames);
    } else {
      persistVendorSelection([], []);
    }

    if (window.NavContext && typeof window.NavContext.setDateRange === 'function' && start && end) {
      window.NavContext.setDateRange(start, end, String(values.preset || range.preset || ''));
    }
  }

  function handleGlobalFilterApply(event) {
    var detail = event && event.detail ? event.detail : {};
    if (String(detail.source || '') !== 'header') return;
    if (event.cancelable) event.preventDefault();

    scopeReady().then(function () {
      syncGlobalFilterValues(detail);
      currentPage = 1;
      renderChips();
      return requestCurrentView(false);
    }).then(function () {
      persistCommercialPref();   // persiste a seleção de vendedores no servidor
      document.dispatchEvent(new CustomEvent('biomie:filter-applied', {
        detail: { source: 'dashboard', applyId: detail.applyId || '', ok: true }
      }));
    }).catch(function (error) {
      document.dispatchEvent(new CustomEvent('biomie:filter-applied', {
        detail: {
          source: 'dashboard',
          applyId: detail.applyId || '',
          ok: false,
          message: error && error.message ? error.message : 'Falha ao aplicar filtros.'
        }
      }));
    });
  }

  function handleVendorFilterApply(event) {
    var detail = event && event.detail ? event.detail : {};
    if (String(detail.source || '') !== 'header') return;
    if (event.cancelable) event.preventDefault();

    var preserved = globalFilterValues();
    var incoming = detail.values && typeof detail.values === 'object' ? detail.values : {};
    var range = getRange();
    scopeReady().then(function () {
      syncGlobalFilterValues({
        values: Object.assign({}, preserved, incoming),
        range: { start: range.start, end: range.end }
      });
      currentPage = 1;
      renderChips();
      return requestCurrentView(false);
    }).then(function () {
      persistCommercialPref();   // persiste a seleção de vendedores no servidor
    }).catch(function (error) {
      showError(error && error.message ? error.message : 'Falha ao aplicar vendedores.');
    });
  }

  function requestCurrentView(force) {
    currentPage = 1;
    resolveTypedVendor(true);
    if (hasExtraFilters()) return loadFiltered(!!force);
    return clearFilteredView();
  }

  function placeControlsFirst() {
    var header = document.querySelector('.biomie-page-header');
    var controls = document.getElementById('dashboardControls');
    var focus = document.getElementById('dashboardSellerFocus');
    if (header && controls && header.nextElementSibling !== controls) {
      header.insertAdjacentElement('afterend', controls);
    }
    if (controls && focus && controls.nextElementSibling !== focus) {
      controls.insertAdjacentElement('afterend', focus);
    }
  }

  function mount() {
    if (mounted) return;
    mounted = true;
    document.addEventListener('biomie:filter-apply', handleGlobalFilterApply);
    document.addEventListener('biomie:vendor-filter-apply', handleVendorFilterApply);
    placeControlsFirst();
    populateVendorOptions();
    renderChips();
    renderCommercialFilterBar(null);
    document.addEventListener('biomie:dashboard-standard-rendered', function () {
      setFilteredMode(false);
      setLoading(false);
      showError('');
      renderChips();
      // Mantém o toggle visível (mixed) mesmo na visão geral; sem breakdown ainda.
      renderCommercialFilterBar(null);
    });
    document.addEventListener('biomie:dashboard-load-failed', function () {
      setLoading(false);
    });
  }

  window.BDashboard = {
    scopeReady: scopeReady,
    prepareInitialContext: scopeReady,
    hasExtraFilters: hasExtraFilters,
    loadFiltered: loadFiltered,
    requestCurrentView: requestCurrentView,
    clearFilteredView: clearFilteredView,
    applyChannel: applyChannel,
    context: context
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
}());
