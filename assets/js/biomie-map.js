(function () {
  'use strict';

  // GEO.R1 — Configuração de camadas territoriais
  const GEO_LAYERS = {
    fortaleza_bairros: {
      geojson: 'assets/geo/fortaleza_bairros.json',
      bounds: [[-3.92,-38.68],[-3.67,-38.36]],
      matchProp: 'Nome',
      scopeLabel: 'bairro',
      title: 'Fortaleza por bairros',
      subtitle: 'Compare os bairros de Fortaleza por faturamento.',
      pageTitle: 'Fortaleza por bairros',
      kpiAreasLabel: 'Bairros com venda',
      kpiTopLabel: 'Melhor bairro',
      fitMaxZoom: 11,
      geometryAvailable: true,
    },
    grande_fortaleza_bairros: {
      geojson: 'assets/geo/rmf_bairros_ibge_2022.geojson',
      bounds: [[-4.30,-38.80],[-3.30,-38.20]],
      matchProp: 'bairro_key',
      scopeLabel: 'bairro',
      title: 'Grande Fortaleza por bairros',
      subtitle: 'Compare bairros metropolitanos por faturamento. Municípios sem bairro no IBGE continuam na camada RMF por municípios.',
      pageTitle: 'Grande Fortaleza por bairros',
      kpiAreasLabel: 'Bairros com venda',
      kpiTopLabel: 'Melhor bairro',
      fitMaxZoom: 11,
      geometryAvailable: true,
      sourceBadge: 'IBGE Bairros CD2022',
      fallbackLayer: 'rmf_municipios',
      fallbackMsg: 'Alguns municípios da RMF ainda não possuem bairros no IBGE 2022. Eles estão disponíveis na camada Região Metropolitana por municípios.',
    },
    rmf_municipios: {
      geojson: 'assets/geo/rmf_municipios.json',
      bounds: [[-4.50,-39.50],[-3.20,-37.80]],
      matchProp: 'municipio_ibge',
      scopeLabel: 'município',
      title: 'Metrópole Fortaleza · Municípios',
      subtitle: 'Compare os municípios da Região Metropolitana de Fortaleza.',
      pageTitle: 'Região Metropolitana de Fortaleza',
      kpiAreasLabel: 'Municípios com venda',
      kpiTopLabel: 'Melhor município',
      fitMaxZoom: 11,
      geometryAvailable: true,
      multiCityLayer: true,
    },
    metro_sobral_municipios: {
      geojson: 'assets/geo/metro_sobral_municipios.geojson',
      bounds: [[-4.10,-41.20],[-3.20,-40.00]],
      matchProp: 'cidade_key',
      scopeLabel: 'município',
      title: 'Metrópole Sobral · Municípios',
      subtitle: 'Compare os municípios da Região Metropolitana de Sobral.',
      pageTitle: 'Região Metropolitana de Sobral',
      kpiAreasLabel: 'Municípios com venda',
      kpiTopLabel: 'Melhor município',
      fitMaxZoom: 10,
      geometryAvailable: true,
      sourceBadge: 'LC CE 168/2016',
      multiCityLayer: true,
    },
    metro_cariri_municipios: {
      geojson: 'assets/geo/metro_cariri_municipios.geojson',
      bounds: [[-7.70,-39.80],[-7.00,-38.80]],
      matchProp: 'cidade_key',
      scopeLabel: 'município',
      title: 'Metrópole Cariri · Municípios',
      subtitle: 'Compare os municípios da Região Metropolitana do Cariri.',
      pageTitle: 'Região Metropolitana do Cariri',
      kpiAreasLabel: 'Municípios com venda',
      kpiTopLabel: 'Melhor município',
      fitMaxZoom: 10,
      geometryAvailable: true,
      sourceBadge: 'LC CE 78/2009',
      multiCityLayer: true,
    },
    metro_todas_municipios: {
      geojson: 'assets/geo/metro_todas_municipios.geojson',
      bounds: [[-7.80,-41.50],[-2.80,-37.20]],
      matchProp: 'cidade_key',
      scopeLabel: 'município',
      title: 'Metrópoles Sobral + Cariri',
      subtitle: 'Compare os municípios das metrópoles de Sobral e do Cariri. A Grande Fortaleza possui análise própria.',
      pageTitle: 'Metrópoles Sobral + Cariri',
      kpiAreasLabel: 'Municípios com venda',
      kpiTopLabel: 'Melhor município',
      fitMaxZoom: 8,
      geometryAvailable: true,
      sourceBadge: 'LC CE Metropolitanas',
      multiCityLayer: true,
    },
    ceara_municipios: {
      geojson: 'assets/geo/ceara_municipios_oficial.geojson',
      bounds: [[-7.80,-41.50],[-2.80,-37.20]],
      matchProp: 'cidade_key',
      scopeLabel: 'município',
      title: 'Ceará por municípios',
      subtitle: 'Compare os 184 municípios do Ceará por faturamento.',
      pageTitle: 'Ceará por municípios',
      kpiAreasLabel: 'Municípios com venda',
      kpiTopLabel: 'Melhor município',
      fitMaxZoom: 8,
      geometryAvailable: true,
      sourceBadge: 'IBGE Malha Municipal 2022',
      multiCityLayer: true,
    },
    ceara_regioes: {
      geojson: 'assets/geo/ceara_regioes_ipece_oficial.geojson',
      bounds: [[-7.80,-41.50],[-2.80,-37.20]],
      matchProp: 'regiao_norm',
      scopeLabel: 'região',
      title: 'Ceará por Regiões de Planejamento',
      subtitle: 'Compare as 14 Regiões de Planejamento oficiais do Ceará.',
      pageTitle: 'Ceará por Regiões de Planejamento',
      kpiAreasLabel: 'Regiões com venda',
      kpiTopLabel: 'Melhor região',
      fitMaxZoom: 8,
      geometryAvailable: true,
      sourceBadge: 'IPECE Regiões de Planejamento',
      multiCityLayer: true,
    },
  };
  // GEO.R1.7 — Adiciona mapa composto ao registry
  GEO_LAYERS['mapa_composto_ceara'] = {
    geojson:           null,
    bounds:            [[-7.80,-41.50],[-2.80,-37.20]],
    matchProp:         'join_key',
    scopeLabel:        'território',
    title:             'Visão multiescala do Ceará',
    subtitle:          'Análise avançada que reúne bairros e municípios. Use para exploração, não para comparação direta entre escalas.',
    pageTitle:         'Visão multiescala do Ceará',
    kpiAreasLabel:     'Territórios com venda',
    kpiTopLabel:       'Melhor território',
    fitMaxZoom:        8,
    geometryAvailable: true,
    isComposite:       true,
    multiCityLayer:    true,
  };

  const DEFAULT_LAYER = 'ceara_regioes';
  const FORTALEZA_BOUNDS = [[-3.92, -38.68], [-3.67, -38.36]];

  // ── Tematização por empresa: o choropleth é pintado na cor de marca da empresa
  // ativa (ex.: ERGO=laranja, MAG=vinho). Lê --company-color do <body> (onde o
  // tema por empresa é aplicado via data-company). Constrói uma escala sequencial
  // claro→marca por bairro/território conforme o faturamento.
  function _hexToRgb(hex) {
    const h = String(hex || '').trim().replace(/^#/, '');
    if (h.length === 3) return [parseInt(h[0] + h[0], 16), parseInt(h[1] + h[1], 16), parseInt(h[2] + h[2], 16)];
    if (h.length >= 6) return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
    return null;
  }
  function _rgbToHex(r, g, b) {
    return '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
  }
  function _mix(rgb, t, toward) { // t=0 → toward, t=1 → rgb
    const a = toward || [255, 255, 255];
    return _rgbToHex(a[0] + (rgb[0] - a[0]) * t, a[1] + (rgb[1] - a[1]) * t, a[2] + (rgb[2] - a[2]) * t);
  }
  function _readCompanyBrand() {
    try {
      const cs = getComputedStyle(document.body);
      const v = (cs.getPropertyValue('--company-color') || cs.getPropertyValue('--mag-accent') || '').trim();
      return _hexToRgb(v) ? v : '#1d4ed8';
    } catch (_) { return '#1d4ed8'; }
  }
  const COMPANY_BRAND = _readCompanyBrand();
  const _brandRgb = _hexToRgb(COMPANY_BRAND) || [29, 78, 216];
  // 5 tons: claro (pouca venda) → marca cheia (muita venda).
  const COMPANY_SCALE = [0.12, 0.30, 0.52, 0.76, 1.0].map((t) => _mix(_brandRgb, t));
  const COMPANY_STROKE = _mix(_brandRgb, 0.78, [0, 0, 0]); // marca escurecida p/ contorno

  // GEO.R1.7 — Paleta por tipo territorial, derivada da cor da empresa ativa.
  const _typePalette = { noSale: '#E5E7EB', low: COMPANY_SCALE[1], mid: COMPANY_SCALE[2], high: COMPANY_SCALE[4], stroke: COMPANY_STROKE };
  const TERRITORY_COLORS = {
    fortaleza_bairro:       Object.assign({}, _typePalette),
    metro_bairro:           Object.assign({}, _typePalette),
    rmf_municipio_fallback: Object.assign({}, _typePalette),
    ceara_municipio:        Object.assign({}, _typePalette),
  };

  // GEO.R1.7 — Códigos IBGE dos municípios RMF com bairros (excluídos do polígono municipal no mapa composto)
  const RMF_WITH_BAIRROS_CODES = new Set(['2304400','2303709','2304285','2304954','2306256','2307650','2307700','2309706']);
  const RMF_ALL_CODES = new Set(['2304400','2303709','2307650','2307700','2304285','2301000','2305233','2309607','2309706','2306256','2304954','2303956','2303501','2310852','2310209','2310258','2312403','2312601','2313500']);

  // Cache persistente de GeoJSONs entre trocas de modo
  const _geoJsonCache = {};

  // Layer ativo (pode ser alterado pelo seletor)
  let _activeLayer = (new URLSearchParams(window.location.search).get('layer')) || DEFAULT_LAYER;
  if (!GEO_LAYERS[_activeLayer]) _activeLayer = DEFAULT_LAYER;

  const GEOJSON_URL = GEO_LAYERS[_activeLayer].geojson;
  const GEO_NAME_CANDIDATES = [
    'Nome',
    'nome',
    'NOME',
    'bairro',
    'Bairro',
    'BAIRRO',
    'bairro_nome',
    'nome_bairro',
    'NM_BAIRRO',
    'NM_BAIRRO_',
    'NM_NOME',
    'NOM_BAIRRO',
    'name',
    'Name',
    // Municípios CE/RMF (ceara_municipios, rmf_municipios, metro_*)
    'municipio_nome',
    'MUNICIPIO_NOME',
    'NM_MUNICIP',
    'cidade_key',
    'territory_label',
    'regiao_nome',
    'regiao_norm',
    'regiao_id',
    'label',
  ];

  // Territory type inferred from the active standalone layer (not composite)
  const LAYER_TERRITORY_TYPE = {
    'fortaleza_bairros':        'fortaleza_bairro',
    'grande_fortaleza_bairros': 'metro_bairro',
    'rmf_municipios':           'rmf_municipio_fallback',
    'metro_sobral_municipios':  'ceara_municipio',
    'metro_cariri_municipios':  'ceara_municipio',
    'metro_todas_municipios':   'ceara_municipio',
    'ceara_municipios':         'ceara_municipio',
    'ceara_regioes':            'ceara_regiao',
  };
  const GEO_NORMALIZER = window.BIOMIE_GEO_NORMALIZER || null;
  const BIOMIE_BOOTSTRAP = window.__BIOMIE_BOOTSTRAP__ || {};
  const GEO_BOOT_FEATURES = BIOMIE_BOOTSTRAP.features || {};
  const EMPTY_GEO_MESSAGE = 'Nao encontramos vendas geograficas para o periodo selecionado. Verifique o periodo, a sincronizacao do Omie ou a materializacao geografica.';
  const MAP_DEBUG = new URLSearchParams(window.location.search).get('debug') === '1' || window.BIOMIE_GEO_DEBUG === true;
  const API_CACHE_TTL_MS = 2 * 60 * 1000;
  const API_CACHE_PREFIX = 'biomie:geo-map-api:v20260513b:';
  const CACHEABLE_ENDPOINTS = new Set([
    'api/geo_summary.php',
    'api/sales_by_neighborhood.php',
    'api/geo_map_payload.php',
    'api/top_buyers_by_geo.php',
    'api/sales_by_cnae.php',
  ]);

  window.BIOMIE_GEO_DEBUG = MAP_DEBUG;

  const state = {
    map: null,
    baseTileLayer: null,
    tileErrors: 0,
    geoLayer: null,
    geoJson: null,
    rows: [],
    rowsByKey: new Map(),
    summary: null,
    scale: null,
    selectedKey: '',
    controllers: new Map(),
    detectedGeoProperty: '',
    featuresWithoutName: 0,
    geoDebug: {},
    revenueByNeighborhoodKey: new Map(),
    geoNamesByKey: new Map(),
    apiOriginalByKey: new Map(),
    aliasesUsed: [],
    normalizationExamples: [],
    matchConfidence: {},
    panelToken: 0,
    drawerReturnFocus: null,
    cnaeBuyerContext: null,
    cnaeBuyerDocumentType: 'all',
    // GEO.R2.2
    summaryTrend: null,
    trendsMap: new Map(),       // territory_key → trend data
    vendorCoverage: null,       // coverage payload
    selectedVendor: '',
    alertsCache: new Map(),     // territory_key → alerts payload
    totalMetaEstimada: 0,       // meta total do período (flight plan)
    geoIntelligence: new Map(),
    geoIntelligenceSummary: null,
    geoIntelligenceLayer: '',
  };

  const formatters = {
    money(value) {
      return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
    },
    compactMoney(value) {
      const amount = Number(value || 0);
      if (Math.abs(amount) >= 1000000) return `R$ ${(amount / 1000000).toFixed(1)} mi`;
      if (Math.abs(amount) >= 1000) return `R$ ${(amount / 1000).toFixed(0)} mil`;
      return `R$ ${amount.toFixed(0)}`;
    },
    number(value) {
      return new Intl.NumberFormat('pt-BR').format(Number(value || 0));
    },
  };

  const METRIC_DEFS = {
    receita: {
      label: 'Faturamento',
      shortLabel: 'Faturamento',
      rowKeys: ['revenue', 'receita', 'faturamento', 'total', 'valor', 'value_raw'],
      format: (value) => formatters.money(value),
      compact: (value) => formatters.compactMoney(value),
      sort: 'revenue_desc',
    },
    nfs: {
      label: 'Notas fiscais',
      shortLabel: 'NFs',
      rowKeys: ['invoices', 'nfs', 'notas'],
      format: (value) => `${formatters.number(value)} NF`,
      compact: (value) => `${formatters.number(value)} NF`,
      sort: 'invoices_desc',
    },
    clientes: {
      label: 'Clientes atendidos',
      shortLabel: 'Clientes',
      rowKeys: ['customers', 'clientes', 'clients'],
      format: (value) => `${formatters.number(value)} clientes`,
      compact: (value) => formatters.number(value),
      sort: 'customers_desc',
    },
    ticket_medio: {
      label: 'Ticket medio',
      shortLabel: 'Ticket',
      rowKeys: ['average_ticket', 'ticket_medio', 'ticket'],
      format: (value) => formatters.money(value),
      compact: (value) => formatters.compactMoney(value),
      sort: 'ticket_desc',
    },
    density: {
      label: 'Densidade comercial',
      shortLabel: 'R$/km²',
      rowKeys: [],
      format:  (value) => `${formatters.compactMoney(value)}/km²`,
      compact: (value) => `${formatters.compactMoney(value)}/km²`,
      sort: 'density_desc',
      computeFn: (row) => {
        const area = Number(row?.area_km2 || 0);
        const rev  = Number(row?.revenue || row?.receita || row?.faturamento || 0);
        return area > 0 ? rev / area : 0;
      },
    },
  };

  function activeMetricKey() {
    const key = $('geoMetricSelector')?.value || 'receita';
    return METRIC_DEFS[key] ? key : 'receita';
  }

  function activeMetricDef() {
    return METRIC_DEFS[activeMetricKey()];
  }

  function getRowMetric(row, metricKey) {
    const def = METRIC_DEFS[metricKey || activeMetricKey()] || METRIC_DEFS.receita;
    if (typeof def.computeFn === 'function') return def.computeFn(row);
    for (const key of def.rowKeys) {
      const value = Number(row?.[key]);
      if (Number.isFinite(value)) return value;
    }
    return 0;
  }

  function formatActiveMetric(row, compact) {
    const def = activeMetricDef();
    const value = getRowMetric(row);
    return compact ? def.compact(value) : def.format(value);
  }

  function activeMetricShare(row) {
    const metric = activeMetricKey();
    if (metric === 'ticket_medio') return null;
    const value = getRowMetric(row, metric);
    const total = currentTerritoryRows().reduce((sum, territory) => sum + getRowMetric(territory, metric), 0);
    return total > 0 ? `${(value / total * 100).toFixed(1)}%` : null;
  }

  function currentTerritoryRows() {
    if (_activeLayer === 'mapa_composto_ceara') {
      return Array.from(state.compositeSalesIndex?.values?.() || []);
    }
    return Array.isArray(state.rows) ? state.rows : [];
  }

  function recomputeMetricScale() {
    const values = currentTerritoryRows()
      .map((row) => getRowMetric(row))
      .filter((value) => Number.isFinite(value) && value > 0);
    const max = values.length ? Math.max(...values) : 0;
    state.metricMax = max;
    state.compositeMetricMax = max;
    return max;
  }

  function sortRowsForActiveMetric(rows) {
    const metric = activeMetricKey();
    return (Array.isArray(rows) ? rows : []).slice().sort((a, b) => {
      const diff = getRowMetric(b, metric) - getRowMetric(a, metric);
      if (diff !== 0) return diff;
      return String(getApiNeighborhoodName(a) || a?.cidade_key || '').localeCompare(
        String(getApiNeighborhoodName(b) || b?.cidade_key || ''),
        'pt-BR'
      );
    });
  }

  function activeScopeLabel() {
    return 'Venda comercial';
  }

  function updateMapContext() {
    const def = activeLayerDef();
    const metric = activeMetricDef();
    const params = currentFilters();
    const period = params.get('date_from') && params.get('date_to')
      ? `${params.get('date_from').split('-').reverse().join('/')} a ${params.get('date_to').split('-').reverse().join('/')}`
      : 'Periodo atual';
    const territoryCount = currentTerritoryRows().filter((row) => getRowMetric(row) > 0).length;

    setText('geoContextLayer', def.title || 'Mapa comercial');
    setText('geoContextMetric', metric.label);
    setText('geoContextPeriod', period);
    setText('geoContextCount', `${formatters.number(territoryCount)} ${def.scopeLabel || 'territorios'} com resultado`);
    setText('geoContextScope', activeScopeLabel());

    const breadcrumb = $('geoTerritoryBreadcrumb');
    if (breadcrumb) {
      const parts = ['Ceara'];
      if (_activeLayer === 'ceara_regioes') parts.push('Regioes de planejamento');
      else if (_activeLayer === 'ceara_municipios') parts.push('Municipios');
      else if (_activeLayer === 'fortaleza_bairros') parts.push('Fortaleza', 'Bairros');
      else if (_activeLayer === 'mapa_composto_ceara') parts.push('Visao multiescala');
      else parts.push(def.title || 'Territorios');
      breadcrumb.innerHTML = parts.map((part, index) =>
        `<span${index === parts.length - 1 ? ' aria-current="location"' : ''}>${escapeHtml(part)}</span>`
      ).join('<span class="material-symbols-rounded" aria-hidden="true">chevron_right</span>');
    }
  }

  function refreshMetricVisuals() {
    recomputeMetricScale();
    applyLayerLabels(_activeLayer);
    updateMapContext();
    updateUrl();
    if (_activeLayer === 'mapa_composto_ceara') {
      state.geoLayer?.eachLayer((layer) => layer.setStyle(styleCompositeFeature(layer.feature)));
      renderCompositeRanking(state.compositeRankingCache || []);
      renderMapModeLegend();
    } else {
      renderGeoLayer();
      renderRanking();
      renderMapModeLegend();
    }
    if (state._mapMode && state._mapMode !== 'territories') applyMapMode(state._mapMode);
  }

  function $(id) {
    return document.getElementById(id);
  }

  function setText(id, value) {
    const node = $(id);
    if (node) node.textContent = value;
  }

  function boolFlag(value) {
    if (value === true) return true;
    if (value === false || value == null) return false;
    const normalized = String(value).trim().toLowerCase();
    return ['1', 'true', 'yes', 'y', 'sim', 'on'].includes(normalized);
  }

  function geoSinglePayloadEnabled() {
    const params = new URLSearchParams(window.location.search);
    return boolFlag(GEO_BOOT_FEATURES.geo_single_payload) || boolFlag(params.get('single_payload'));
  }

  function geoIsAdmin() {
    return boolFlag(GEO_BOOT_FEATURES.is_admin);
  }

  function coveragePercent(total, missing) {
    const totalValue = Number(total || 0);
    const missingValue = Math.max(0, Number(missing || 0));
    if (totalValue <= 0) return 0;
    return Math.max(0, Math.min(100, Math.round(((totalValue - missingValue) / totalValue) * 100)));
  }

  function updateCoverageKpi(total, missing, fallbackTotal, fallbackMissing) {
    let pct = coveragePercent(total, missing);
    if (Number(total || 0) <= 0 && Number(fallbackTotal || 0) > 0) {
      pct = coveragePercent(fallbackTotal, fallbackMissing);
    }
    setText('geoKpiCoverage', `${pct}%`);
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function debugLog(label, value) {
    if (window.BIOMIE_GEO_DEBUG) {
      console.log('[BIOMIE_GEO_DEBUG]', label, value);
    }
  }

  function hasValidText(value) {
    return value !== undefined && value !== null && String(value).trim() !== '';
  }

  function safeText(value) {
    return hasValidText(value) ? String(value).trim() : '';
  }

  // GEO.R2.2 — Render trend badge HTML
  function renderTrendBadge(pct) {
    if (pct === null || pct === undefined) return '';
    const sign  = pct >= 0 ? '▲' : '▼';
    const cls   = pct >= 0 ? 'geo-trend-badge--up' : 'geo-trend-badge--down';
    const abs   = Math.abs(pct).toFixed(1);
    return `<span class="geo-trend-badge ${cls}">${sign}${abs}%</span>`;
  }

  function renderTrendInline(pct) {
    if (pct === null || pct === undefined) return '';
    const sign = pct >= 0 ? '▲' : '▼';
    const cls  = pct >= 0 ? 'geo-ranking-trend--up' : 'geo-ranking-trend--down';
    const abs  = Math.abs(pct).toFixed(1);
    return `<span class="geo-ranking-trend ${cls}">${sign}${abs}%</span>`;
  }

  function formatTrendPercent(pct) {
    if (pct === null || pct === undefined) return null;
    const sign = pct >= 0 ? '+' : '';
    return `${sign}${Number(pct).toFixed(1)}%`;
  }

  function normalizeNeighborhoodKey(value) {
    if (GEO_NORMALIZER && typeof GEO_NORMALIZER.normalizeNeighborhoodKey === 'function') {
      return GEO_NORMALIZER.normalizeNeighborhoodKey(value);
    }
    // bairro_key format: lowercase, no accents, spaces/hyphens \u2192 underscore, collapse underscores.
    const stripped = String(value ?? '')
      .trim()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/_+/g, '_')
      .replace(/^_|_$/g, '');
    return stripped;
  }

  function explainNeighborhoodNormalization(value) {
    if (GEO_NORMALIZER && typeof GEO_NORMALIZER.explainNeighborhoodNormalization === 'function') {
      return GEO_NORMALIZER.explainNeighborhoodNormalization(value);
    }
    const final = normalizeNeighborhoodKey(value);
    return {
      original: value,
      trimmed: safeText(value),
      collapsed_spaces: final,
      alias_applied: null,
      final,
    };
  }

  function detectGeoProperty(features) {
    if (!Array.isArray(features) || !features.length) return '';

    const counts = new Map(GEO_NAME_CANDIDATES.map((candidate) => [candidate, 0]));
    features.forEach((feature) => {
      const props = feature && typeof feature === 'object' && feature.properties && typeof feature.properties === 'object'
        ? feature.properties
        : {};
      GEO_NAME_CANDIDATES.forEach((candidate) => {
        if (hasValidText(props[candidate])) {
          counts.set(candidate, (counts.get(candidate) || 0) + 1);
        }
      });
    });

    let bestKey = '';
    let bestCount = 0;
    GEO_NAME_CANDIDATES.forEach((candidate) => {
      const count = counts.get(candidate) || 0;
      if (count > bestCount) {
        bestKey = candidate;
        bestCount = count;
      }
    });

    state.geoDebug.geoPropertyCounts = Object.fromEntries(counts);
    return bestKey;
  }

  function getApiNeighborhoodName(row) {
    if (!row || typeof row !== 'object') return '';
    return safeText(row.bairro
      || row.neighborhood
      || row.bairro_nome
      || row.bairro_oficial
      || row.nome_bairro
      || row.territory_label
      || row.regiao_nome
      || row.municipio_nome
      || row.name
      || row.label
      || '');
  }

  function getFeatureNeighborhoodName(feature) {
    const p = feature && typeof feature === 'object' && feature.properties && typeof feature.properties === 'object'
      ? feature.properties
      : {};
    const detected = state.detectedGeoProperty;
    if (detected && hasValidText(p[detected])) {
      return safeText(p[detected]);
    }
    for (const candidate of GEO_NAME_CANDIDATES) {
      if (hasValidText(p[candidate])) {
        return safeText(p[candidate]);
      }
    }
    return '';
  }

  function getRowRevenue(row) {
    const value = row?.revenue ?? row?.faturamento ?? row?.total ?? row?.valor ?? row?.value_raw ?? 0;
    const amount = Number(value);
    return Number.isFinite(amount) ? amount : 0;
  }

  function pushLookupKey(keys, value) {
    const key = normalizeNeighborhoodKey(value);
    if (key && !keys.includes(key)) keys.push(key);
  }

  function getFeatureLookupKeys(feature) {
    const keys = [];
    const props = feature && typeof feature === 'object' && feature.properties && typeof feature.properties === 'object'
      ? feature.properties
      : {};

    // GEO.R1.3 — metro layer uses compound join_key "cidade:bairro" matching API compound bairro_key
    pushLookupKey(keys, props.join_key);
    pushLookupKey(keys, props.territory_key);
    pushLookupKey(keys, props.regiao_id);
    pushLookupKey(keys, props.bairro_key);
    pushLookupKey(keys, props.neighborhood_key);
    pushLookupKey(keys, props.neighborhood_name);
    pushLookupKey(keys, getFeatureNeighborhoodName(feature));

    if (state.detectedGeoProperty) {
      pushLookupKey(keys, props[state.detectedGeoProperty]);
    }

    GEO_NAME_CANDIDATES.forEach((candidate) => {
      pushLookupKey(keys, props[candidate]);
    });

    return keys;
  }

  function getFeatureCommercialRow(feature, revenueOnly) {
    const keys = getFeatureLookupKeys(feature);
    for (const key of keys) {
      const row = revenueOnly
        ? state.revenueByNeighborhoodKey.get(key)
        : (state.revenueByNeighborhoodKey.get(key) || state.rowsByKey.get(key));
      if (row) {
        return { key, row };
      }
    }

    return { key: keys[0] || '', row: null };
  }

  function findCommercialRowByKeyOrLabel(key, label, revenueOnly) {
    const keys = [];
    pushLookupKey(keys, key);
    pushLookupKey(keys, label);

    for (const candidate of keys) {
      const row = revenueOnly
        ? state.revenueByNeighborhoodKey.get(candidate)
        : (state.revenueByNeighborhoodKey.get(candidate) || state.rowsByKey.get(candidate));
      if (row) {
        return { key: candidate, row };
      }
    }

    const sourceRows = Array.isArray(state.rows) ? state.rows : [];
    for (const row of sourceRows) {
      const rowKeys = [];
      pushLookupKey(rowKeys, row?.bairro_key);
      pushLookupKey(rowKeys, row?.neighborhood_key);
      pushLookupKey(rowKeys, row?.bairro_nome);
      pushLookupKey(rowKeys, row?.neighborhood);
      pushLookupKey(rowKeys, row?.name);
      if (keys.some((candidate) => rowKeys.includes(candidate))) {
        return { key: rowKeys[0] || keys[0] || '', row };
      }
    }

    return { key: keys[0] || '', row: null };
  }

  function formatDiagnosticExamples(values) {
    if (!values || !values.length) return '-';
    return values.slice(0, 5).map((item) => {
      if (!item || typeof item !== 'object') return String(item);
      const original = item.original || item.nome || item.name || item.key || '';
      const key = item.key || item.canonical || item.normalized || '';
      const type = item.match_type ? ` (${item.match_type})` : '';
      return key ? `${original} [${key}]${type}` : String(original || '-');
    }).join(', ');
  }

  function formatMatchConfidence(values) {
    if (!values || typeof values !== 'object') return '-';
    return `exact: ${values.exact || 0}, normalized: ${values.normalized || 0}, alias: ${values.alias || 0}, missing: ${values.missing || 0}, empty: ${values.empty || 0}, ambiguous: ${values.ambiguous || 0}`;
  }

  function buildApiNeighborhoodIndexes(rows) {
    const rowsByKey = new Map();
    const revenueByKey = new Map();
    const apiOriginalByKey = new Map();
    const invalidRows = [];
    const invalidRevenueRows = [];
    const aliasesUsed = [];
    const normalizationExamples = [];

    (Array.isArray(rows) ? rows : []).forEach((row) => {
      if (!row || typeof row !== 'object') {
        invalidRows.push('linha invalida');
        return;
      }
      const rawName = getApiNeighborhoodName(row);
      const originalName = safeText(row.bairro_original || row.neighborhood_original || row.original_neighborhood || rawName);
      const fallbackKey = safeText(row.territory_key || row.join_key || row.bairro_key || row.neighborhood_key || row.bairro_norm || row.nome_bairro_norm);
      const explain = explainNeighborhoodNormalization(rawName || originalName || fallbackKey);
      const key = fallbackKey ? normalizeNeighborhoodKey(fallbackKey) : explain.final;
      if (!key) {
        invalidRows.push(rawName || fallbackKey || 'sem nome');
        return;
      }

      const displayName = rawName || originalName || fallbackKey;
      const rawRevenue = row.revenue ?? row.faturamento ?? row.total ?? row.valor ?? row.value_raw ?? 0;
      const revenue = getRowRevenue(row);
      if (!Number.isFinite(Number(rawRevenue))) {
        invalidRevenueRows.push(displayName);
      }
      if (explain.alias_applied && explain.alias_applied.alias_key !== explain.alias_applied.canonical_key) {
        aliasesUsed.push({
          original: displayName,
          normalized: explain.collapsed_spaces,
          canonical: explain.final,
          motivo: explain.alias_applied.motivo || '',
        });
      }
      if (normalizationExamples.length < 8) {
        normalizationExamples.push({
          original: displayName,
          key,
          normalized: explain.collapsed_spaces,
          alias: explain.alias_applied?.canonical_key || null,
        });
      }

      const normalizedRow = Object.assign({}, row, {
        neighborhood: displayName || row.neighborhood || '',
        neighborhood_key: key,
        bairro_key: row.bairro_key || key,
        revenue,
        customers: Number(row.customers ?? row.clientes ?? row.clients ?? 0),
        invoices: Number(row.invoices ?? row.nfs ?? row.notas ?? 0),
        average_ticket: Number(row.average_ticket ?? row.ticket_medio ?? 0),
      });
      const previous = rowsByKey.get(key);
      if (previous) {
        previous.revenue = getRowRevenue(previous) + revenue;
        previous.customers = Number(previous.customers || previous.clientes || 0) + Number(row.customers || row.clientes || 0);
        previous.invoices = Number(previous.invoices || previous.notas || 0) + Number(row.invoices || row.notas || 0);
        previous.average_ticket = previous.invoices > 0 ? previous.revenue / previous.invoices : 0;
      } else {
        rowsByKey.set(key, normalizedRow);
      }
      apiOriginalByKey.set(key, displayName || key);

      if (revenue > 0) {
        revenueByKey.set(key, rowsByKey.get(key));
      }
    });

    return { rowsByKey, revenueByKey, apiOriginalByKey, invalidRows, invalidRevenueRows, aliasesUsed, normalizationExamples };
  }

  function filterRowsToActiveGeometry(rows, geoJson) {
    const features = Array.isArray(geoJson?.features) ? geoJson.features : [];
    if (!features.length) return Array.isArray(rows) ? rows : [];
    const allowed = new Set();
    features.forEach((feature) => getFeatureLookupKeys(feature).forEach((key) => allowed.add(key)));
    return (Array.isArray(rows) ? rows : []).filter((row) => {
      const keys = [
        row?.territory_key,
        row?.join_key,
        row?.neighborhood_key,
        row?.bairro_key,
        row?.neighborhood,
        row?.bairro_nome,
        row?.cidade_key,
        row?.city_key,
        row?.city,
        row?.municipio_nome,
      ].map(normalizeNeighborhoodKey).filter(Boolean);
      return keys.some((key) => allowed.has(key));
    });
  }

  function renderActiveLayerKpis() {
    const rowsWithResult = state.rows.filter((row) => getRowRevenue(row) > 0);
    const totalRevenue = rowsWithResult.reduce((sum, row) => sum + getRowRevenue(row), 0);
    const totalInvoices = rowsWithResult.reduce((sum, row) => sum + Number(row.invoices || row.notas || 0), 0);
    const totalCustomers = rowsWithResult.reduce((sum, row) => sum + Number(row.customers || row.clientes || 0), 0);
    const top = sortRowsForActiveMetric(rowsWithResult)[0] || null;
    setText('geoKpiRevenue', formatters.money(totalRevenue));
    setText('geoKpiAreas', formatters.number(rowsWithResult.length));
    setText('geoKpiNotes', formatters.number(totalInvoices));
    setText('geoKpiClients', formatters.number(totalCustomers));
    setText('geoKpiTopArea', top ? getApiNeighborhoodName(top) : 'Sem território com resultado');
  }

  function getApiCacheKey(endpoint, params) {
    return `${API_CACHE_PREFIX}${endpoint}?${params.toString()}`;
  }

  function readApiCache(endpoint, params) {
    if (!CACHEABLE_ENDPOINTS.has(endpoint) || MAP_DEBUG) return null;
    try {
      const raw = window.sessionStorage?.getItem(getApiCacheKey(endpoint, params));
      if (!raw) return null;
      const cached = JSON.parse(raw);
      if (!cached || typeof cached !== 'object' || Date.now() > Number(cached.expiresAt || 0)) {
        window.sessionStorage?.removeItem(getApiCacheKey(endpoint, params));
        return null;
      }
      return cached.payload && typeof cached.payload === 'object' ? cached.payload : null;
    } catch (error) {
      debugLog('Cache de API indisponivel', error?.message || error);
      return null;
    }
  }

  function writeApiCache(endpoint, params, payload) {
    if (!CACHEABLE_ENDPOINTS.has(endpoint) || MAP_DEBUG || !payload || typeof payload !== 'object') return;
    try {
      window.sessionStorage?.setItem(getApiCacheKey(endpoint, params), JSON.stringify({
        expiresAt: Date.now() + API_CACHE_TTL_MS,
        payload,
      }));
    } catch (error) {
      debugLog('Falha ao gravar cache de API', error?.message || error);
    }
  }

  function currentFilters(extra) {
    const params = new URLSearchParams();
    const dateFrom = $('dateStart')?.value || '';
    const dateTo = $('dateEnd')?.value || '';
    const city = $('cityFilter')?.value || 'Fortaleza';
    const neighborhood = $('neighborhoodFilter')?.value || '';
    const cnae = $('cnaeFilter')?.value || '';
    const vendedor = ($('vendedorFilter')?.value || '').trim();
    const scope = $('geoScopeSelector')?.value || 'orc_pd';
    const metric = activeMetricKey();

    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    params.set('scope', scope === 'orc_pd' ? 'orc_pd' : 'orc_pd');
    params.set('metric', metric);
    // GEO.R1.3/R1.5/R1.7 — multi-city layers pass layer param; API aggregates by cidade_norm
    const layerDef = activeLayerDef();
    if (_activeLayer === 'mapa_composto_ceara') {
      params.set('layer', 'mapa_composto_ceara');
    } else if (layerDef.multiCityLayer || _activeLayer === 'grande_fortaleza_bairros') {
      params.set('layer', _activeLayer);
    } else {
      if (city) params.set('city', city);
    }
    if (neighborhood) params.set('neighborhood', neighborhood);
    if (cnae) params.set('cnae', cnae);
    if (vendedor) {
      params.set('seller_id', vendedor);
      params.set('vendor', vendedor);
    }
    // FILTRO COMERCIAL (seletor global de vendedores). Vai por CÓDIGO, que é
    // exato — o seller_id acima casa por NOME e confunde homônimos
    // ("CAIO - VAR" × "CAIO - REV"). O backend dá precedência a vendor_ids.
    try {
      var _mapVendorIds = (window.BIomieVendorFilter
        && typeof window.BIomieVendorFilter.getSelectedIds === 'function')
        ? window.BIomieVendorFilter.getSelectedIds()
        : [];
      if (!_mapVendorIds.length) {
        var _fromUrl = new URLSearchParams(window.location.search).get('vendor_ids') || '';
        _mapVendorIds = _fromUrl ? _fromUrl.split(',').filter(Boolean) : [];
      }
      if (_mapVendorIds.length) params.set('vendor_ids', _mapVendorIds.join(','));
    } catch (error) {
      // sem seletor disponível: o mapa segue sem o refino de vendedor
    }

    Object.entries(extra || {}).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        params.set(key, String(value));
      }
    });

    return params;
  }

  function updateUrl() {
    const params = currentFilters();
    params.set('layer', _activeLayer);
    window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
  }

  function applyLayerLabels(layerKey) {
    const def = GEO_LAYERS[layerKey] || GEO_LAYERS[DEFAULT_LAYER];

    const pageTitle = $('geoPageTitle');
    if (pageTitle) pageTitle.textContent = def.pageTitle || def.title;
    const pageSubtitle = $('geoPageSubtitle');
    if (pageSubtitle) pageSubtitle.textContent = def.subtitle || '';

    const titleEl = $('geoMapTitle');
    if (titleEl) titleEl.textContent = def.title;
    const subEl = $('geoMapSubtitle');
    if (subEl) subEl.textContent = def.subtitle || '';

    const badge = $('geoLayerBadge');
    if (badge) { badge.textContent = def.title; badge.style.display = 'block'; }

    const areasLabel = $('geoKpiAreasLabel');
    if (areasLabel) areasLabel.textContent = def.kpiAreasLabel || 'Territórios com venda';
    const topAreaLabel = $('geoKpiTopAreaLabel');
    if (topAreaLabel) topAreaLabel.textContent = def.kpiTopLabel || 'Melhor território';

    const rankTitle = $('geoRankingTitle');
    const rankScopeLabels = { bairro: 'bairros', município: 'municípios', região: 'regiões', território: 'territórios' };
    if (rankTitle) rankTitle.textContent = 'Ranking de ' + (rankScopeLabels[def.scopeLabel] || def.scopeLabel || 'territórios');
    const rankSubtitle = $('geoRankingSubtitle');
    if (rankSubtitle) rankSubtitle.textContent = `Ordenado por ${activeMetricDef().label.toLowerCase()}. Clique para localizar e detalhar.`;

    const cityGroup = $('cityFilter')?.closest('.filter-group');
    const neighborhoodGroup = $('neighborhoodFilter')?.closest('.filter-group');
    const cityIsUseful = layerKey === 'fortaleza_bairros';
    if (cityGroup) cityGroup.hidden = !cityIsUseful;
    if (neighborhoodGroup) neighborhoodGroup.hidden = layerKey !== 'fortaleza_bairros';

    const overlay = $('geoGeometryMissing');
    if (def.geometryAvailable === false) {
      const msg = $('geoGeometryMissingMsg');
      if (msg) msg.textContent = def.geometryMissingMsg || 'Geometria não disponível para esta camada.';
      if (overlay) overlay.style.display = 'flex';
    } else {
      if (overlay) overlay.style.display = 'none';
    }
  }

  function fitLayerBounds() {
    if (!state.map || !state.geoLayer) return;
    const bounds = state.geoLayer.getBounds();
    if (!bounds.isValid()) return;
    const def = activeLayerDef();
    state.map.fitBounds(bounds, { padding: [18, 18], maxZoom: def.fitMaxZoom || 13 });
  }

  function setMapBusy(isBusy) {
    $('map-container')?.classList.toggle('is-loading', Boolean(isBusy));
  }

  function showFeedback(message, tone) {
    const node = $('geoFeedback');
    if (!node) return;
    if (!message) {
      node.className = 'module-alert d-none';
      node.textContent = '';
      return;
    }
    node.className = `module-alert module-alert--${tone || 'info'}`;
    node.textContent = message;
  }

  function showState(kind, title, message, action) {
    const node = $('geoMapState');
    if (!node) return;
    node.className = `biomie-map-state is-visible ${kind === 'danger' ? 'biomie-map-state--danger' : ''}`;
    node.textContent = '';

    const content = document.createElement('div');
    const titleNode = document.createElement('strong');
    titleNode.textContent = title || '';
    const messageNode = document.createElement('p');
    messageNode.textContent = message || '';
    content.appendChild(titleNode);
    content.appendChild(messageNode);
    node.appendChild(content);

    if (action) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn-secondary';
      button.id = 'mapStateAction';
      button.textContent = action;
      node.appendChild(button);
    }
    $('mapStateAction')?.addEventListener('click', () => {
      if (kind === 'empty') {
        clearFilters();
      } else {
        loadCommercialData();
      }
    });
  }

  function hideState() {
    const node = $('geoMapState');
    if (!node) return;
    node.className = 'biomie-map-state';
    node.innerHTML = '';
  }

  function injectRebuildButton(params) {
    const node = $('geoMapState');
    if (!node) return;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-primary';
    btn.id = 'mapRebuildBtn';
    btn.style.cssText = 'margin-top:8px;margin-left:0;';
    btn.textContent = 'Reprocessar período';
    node.appendChild(btn);
    btn.addEventListener('click', () => triggerGeoRebuild(params));
  }

  async function triggerGeoRebuild(params) {
    const btn = $('mapRebuildBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Reprocessando…'; }

    const csrfToken = document.querySelector('meta[name="biomie-csrf"]')?.getAttribute('content') || '';
    const start = params.get('date_from') || params.get('start') || '';
    const end   = params.get('date_to')   || params.get('end')   || '';

    try {
      const body = new URLSearchParams({ start, end, csrf_token: csrfToken });
      const resp = await fetch('api/admin_geo_rebuild.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: body.toString(),
      });
      const data = await resp.json().catch(() => ({}));

      if (!resp.ok || data.ok === false) {
        const msg = data.message || 'Falha ao reprocessar.';
        showState('danger', 'Rebuild falhou.', msg, 'Tentar novamente');
        return;
      }

      const bairros = data.geo_result?.bairros_distintos ?? 0;
      const fat = data.geo_result?.faturamento ?? 0;
      const fmtFat = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(fat);
      showState('empty',
        `Rebuild concluído: ${bairros} bairros, ${fmtFat}`,
        'Os dados foram materializados. Recarregando o mapa…',
        null
      );
      setTimeout(() => loadCommercialData(), 1500);
    } catch (err) {
      showState('danger', 'Erro de rede ao reprocessar.', err.message || 'Tente novamente.', 'Tentar novamente');
    }
  }

  function updateDiagnostics(partial) {
    const current = Object.assign({}, updateDiagnostics.current || {}, partial || {});
    updateDiagnostics.current = current;
    setText('diagEndpoint', current.endpoint || '-');
    setText('diagStatus', current.status || '-');
    setText('diagTime', current.duration ? `${current.duration} ms` : '-');
    setText('diagFilters', current.filters || currentFilters().toString() || '-');
    setText('diagMaterialized', current.materialized ?? '-');
    setText('diagGeoLoaded', current.geoLoaded || '-');
    setText('diagGeoCount', current.geoCount ?? '-');
    setText('diagGeoUrl', current.geoUrl || GEOJSON_URL);
    setText('diagGeoProperty', current.geoProperty || '-');
    setText('diagGeoNoName', current.geoNoName ?? '-');
    setText('diagSalesCount', current.salesCount ?? '-');
    setText('diagApiState', current.apiState || '-');
    setText('diagMapState', current.mapState || '-');
    setText('diagApiUnmatched', current.apiUnmatched || '-');
    setText('diagGeoUnmatched', current.geoUnmatched || '-');
    setText('diagAliasesUsed', current.aliasesUsed || '-');
    setText('diagNormalizationExamples', current.normalizationExamples || '-');
    setText('diagMatchConfidence', current.matchConfidence || '-');
    setText('diagNoNeighborhood', current.noNeighborhood ?? '-');
    setText('diagGoldTable', current.goldTable || '-');
    setText('diagGoldRows', current.goldRows ?? '-');
    setText('diagMatchRate', current.matchRate ?? '-');
    setText('diagMotivoZero', current.motivoZero ?? '-');
    setText('diagLayeredStatus', current.layeredStatus || '-');
    setText('diagNextAction', current.nextAction || '-');
    setText('diagRowsBeforeCity', current.rowsBeforeCity ?? '-');
    setText('diagRowsAfterCity', current.rowsAfterCity ?? '-');
    setText('diagCanonicalNfs', current.canonicalNfs ?? '-');
    setText('diagGeoMartNotas', current.geoMartNotas ?? '-');
    setText('diagError', current.error || '-');
  }

  async function apiFetch(endpoint, params, key) {
    const previous = state.controllers.get(key);
    if (previous) previous.abort();

    const controller = new AbortController();
    state.controllers.set(key, controller);
    const url = `${endpoint}?${params.toString()}`;
    const started = performance.now();
    const cachedPayload = readApiCache(endpoint, params);

    if (cachedPayload) {
      updateDiagnostics({
        endpoint,
        status: '200 cache',
        duration: 0,
        filters: params.toString(),
      });
      state.controllers.delete(key);
      return cachedPayload;
    }

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'default',
        signal: controller.signal,
      });
      let payload = {};
      try {
        payload = await response.json();
        if (!payload || typeof payload !== 'object') {
          payload = {};
        }
      } catch (parseError) {
        const error = new Error('Resposta JSON invalida.');
        error.status = response.status;
        error.payload = { error: 'invalid_json', message: 'A API retornou uma resposta que nao e JSON valido.' };
        updateDiagnostics({ apiState: 'erro', error: 'invalid_json' });
        throw error;
      }
      const duration = Math.round(performance.now() - started);
      updateDiagnostics({
        endpoint,
        status: String(response.status),
        duration,
        filters: params.toString(),
      });

      if (!response.ok || payload.ok === false) {
        const error = new Error(payload.message || 'Falha ao carregar endpoint.');
        error.status = response.status;
        error.payload = payload;
        updateDiagnostics({ apiState: 'erro', error: payload.error || `HTTP ${response.status}` });
        throw error;
      }

      writeApiCache(endpoint, params, payload);
      return payload;
    } finally {
      if (state.controllers.get(key) === controller) {
        state.controllers.delete(key);
      }
    }
  }

  async function loadGeoJson() {
    if (state.geoJson) return state.geoJson;
    const geoUrl = getCurrentGeoJsonUrl(); // GEO.R1 — usa URL dinâmica
    updateDiagnostics({ geoLoaded: 'Nao', geoUrl, mapState: 'carregando GeoJSON' });

    let response;
    try {
      response = await fetch(geoUrl, { cache: 'force-cache' });
    } catch (fetchError) {
      updateDiagnostics({ geoLoaded: 'Nao', geoProperty: 'erro ao carregar', geoNoName: '-', mapState: 'erro', error: 'geojson_fetch_error' });
      fetchError.isGeoJsonError = true;
      fetchError.code = 'geojson_fetch_error';
      throw fetchError;
    }

    if (!response.ok) {
      const error = new Error(`Falha ao carregar mapa de bairros (HTTP ${response.status}).`);
      error.isGeoJsonError = true;
      error.code = 'geojson_http_error';
      updateDiagnostics({ geoLoaded: 'Nao', geoProperty: 'erro ao carregar', geoNoName: '-', mapState: 'erro', error: `GeoJSON HTTP ${response.status}` });
      throw error;
    }

    let geoJson;
    try {
      geoJson = await response.json();
    } catch (parseError) {
      updateDiagnostics({ geoLoaded: 'Nao', geoProperty: 'JSON invalido', geoNoName: '-', mapState: 'erro', error: 'geojson_parse_error' });
      parseError.isGeoJsonError = true;
      parseError.code = 'geojson_parse_error';
      throw parseError;
    }

    const safeGeoJson = geoJson && typeof geoJson === 'object'
      ? geoJson
      : { type: 'FeatureCollection', features: [] };
    const features = Array.isArray(safeGeoJson.features) ? safeGeoJson.features : [];
    safeGeoJson.features = features;
    const detectedProp = detectGeoProperty(features);
    state.detectedGeoProperty = detectedProp;
    let noName = 0;
    state.geoNamesByKey = new Map();
    features.forEach((feature) => {
      const name = getFeatureNeighborhoodName(feature);
      if (!name) noName++;
      // bairro_key: stable underscore-based key matching the DB bairro_key column.
      const key = normalizeNeighborhoodKey(name);
      if (key) {
        state.geoNamesByKey.set(key, name);
      }
      if (feature && typeof feature === 'object') {
        feature.properties = feature.properties && typeof feature.properties === 'object' ? feature.properties : {};
        // Enrich GeoJSON properties with typed keys so click handlers can use them directly.
        feature.properties.bairro_key       = feature.properties.bairro_key || key;
        feature.properties.bairro_nome      = feature.properties.bairro_nome || name;
        feature.properties.city_key         = feature.properties.city_key || 'fortaleza';
        feature.properties.neighborhood_key = key;
        feature.properties.neighborhood_name = name;
      }
    });
    state.featuresWithoutName = noName;
    state.geoJson = safeGeoJson;
    updateDiagnostics({
      geoLoaded: 'Sim',
      geoCount: features.length,
      geoProperty: detectedProp || 'nenhuma detectada',
      geoNoName: noName,
      mapState: features.length ? 'GeoJSON carregado' : 'GeoJSON sem features',
    });
    debugLog('Propriedade GeoJSON detectada', detectedProp || '(nenhuma)');
    debugLog('Total de features GeoJSON', features.length);
    debugLog('Amostra de nomes GeoJSON', Array.from(state.geoNamesByKey.values()).slice(0, 10));
    debugLog('Contagem por propriedade GeoJSON', state.geoDebug.geoPropertyCounts);
    return safeGeoJson;
  }

  function initMap() {
    if (state.map || !window.L || !$('map-container')) return;
    const bounds = getCurrentBounds(); // GEO.R1 — bounds dinâmico
    const center = [(bounds[0][0]+bounds[1][0])/2, (bounds[0][1]+bounds[1][1])/2];
    const zoom = _activeLayer === 'fortaleza_bairros' ? 12
               : _activeLayer.includes('rmf') ? 10
               : 7;
    state.map = L.map('map-container', {
      minZoom: 5,
      maxZoom: 15,
      maxBounds: [[-8.5,-42.0],[-2.5,-37.0]], // CE completo
      maxBoundsViscosity: 0.5,
      zoomControl: true,
    }).setView(center, zoom);

    state.baseTileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      referrerPolicy: 'strict-origin-when-cross-origin',
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      opacity: 0.52,
      // Perf: menos requisições/redraw de tiles durante pan/zoom.
      updateWhenIdle: true,
      updateWhenZooming: false,
      keepBuffer: 2,
    }).on('tileerror', () => {
      state.tileErrors += 1;
      if (state.tileErrors < 4 || !state.baseTileLayer) return;
      // Os polígonos e resultados pertencem ao BIomie e continuam válidos sem
      // a cartografia externa. Remover os placeholders preserva a leitura.
      state.map.removeLayer(state.baseTileLayer);
      state.baseTileLayer = null;
      showFeedback('Mapa de ruas indisponível. Os territórios e resultados comerciais continuam disponíveis.', 'info');
      updateDiagnostics({ mapState: 'territorios sem mapa de ruas', error: 'base_tiles_unavailable' });
    }).addTo(state.map);
  }

  // One sequential scale keeps every territory directly comparable.
  var MAP_BUCKET_COLORS = COMPANY_SCALE; // escala sequencial na cor da empresa ativa

  function getRevenueBucket(revenue) {
    const buckets = state.scale && state.scale.buckets;
    if (buckets && buckets.length) {
      for (var i = 1; i < buckets.length; i++) {
        var b = buckets[i];
        var bucketMax = b.max != null ? b.max : Infinity;
        if (revenue <= bucketMax) return i;
      }
      return buckets.length - 1;
    }
    // Fallback: percentile-based 4 tiers
    var max = Number(state.scale && state.scale.max || 0);
    if (max <= 0) return 0;
    const pct = revenue / max;
    if (pct >= 0.66) return 4;
    if (pct >= 0.33) return 3;
    if (pct > 0)     return 2;
    return 0;
  }

  function getBucketColor(bucketIndex) {
    return MAP_BUCKET_COLORS[Math.min(bucketIndex, MAP_BUCKET_COLORS.length - 1)] || '#e5e7eb';
  }

  function getColorByRevenue(value, hasData) {
    if (!hasData) return '#e5e7eb';
    const amount = Number(value || 0);
    if (amount <= 0) return '#e5e7eb';
    return getBucketColor(getRevenueBucket(amount));
  }

  function featureStyle(feature) {
    const matched = getFeatureCommercialRow(feature, true);
    const key = matched.key;
    const row = matched.row;
    const selected = key && key === state.selectedKey;
    const metricValue = row ? getRowMetric(row) : 0;
    
    if (metricValue > 0) {
      const maxMetric = Number(state.metricMax || state.scale?.max || metricValue || 1);
      const ratio = Math.min(1, metricValue / Math.max(maxMetric, 1));
      const bucket = ratio >= 0.66 ? 4 : ratio >= 0.33 ? 3 : ratio > 0 ? 2 : 0;
      return {
        fillColor: getBucketColor(bucket),
        weight: selected ? 3 : 1.4,
        opacity: 1,
        color: selected ? COMPANY_STROKE : '#ffffff',
        fillOpacity: selected ? 0.92 : 0.72,
      };
    }

    return {
      fillColor: '#dbe4ef',
      weight: 0.9,
      opacity: 0.85,
      color: '#94a3b8',
      fillOpacity: 0.18,
    };
  }

  // GEO.R1.7 — determina o tipo territorial de uma feature
  function getTerritoryType(feature) {
    const props = feature?.properties || {};
    if (props.territory_type) return props.territory_type;
    const layerId = props.layer_id || '';
    if (layerId === 'grande_fortaleza_bairros') return 'metro_bairro';
    if (layerId === 'ceara_municipios') {
      const code = String(props.codigo_ibge || props.municipio_ibge || '');
      if (!code || code === '2304400') return null;
      if (RMF_WITH_BAIRROS_CODES.has(code)) return null;
      return RMF_ALL_CODES.has(code) ? 'rmf_municipio_fallback' : 'ceara_municipio';
    }
    return 'fortaleza_bairro';
  }

  function getCompositeColor(type, value) {
    if (!value || value <= 0) return MAP_BUCKET_COLORS[0];
    const maxValue = Number(state.compositeMetricMax || value || 1);
    const ratio = value / Math.max(maxValue, 1);
    if (ratio >= 0.66) return MAP_BUCKET_COLORS[4];
    if (ratio >= 0.33) return MAP_BUCKET_COLORS[3];
    return MAP_BUCKET_COLORS[2];
  }

  function currentModeStyle(feature, baseStyleFn) {
    const base = (baseStyleFn || featureStyle)(feature);
    const mode = state._mapMode || 'territories';
    if (mode === 'heat') return { ...base, fillOpacity: 0.08, opacity: 0.25 };
    if (mode === 'quality') {
      const row = _activeLayer === 'mapa_composto_ceara'
        ? state.compositeSalesIndex?.get(normalizeNeighborhoodKey(getFeatureCompositeJoinKey(feature)))
        : getFeatureCommercialRow(feature, false).row;
      return !row || getRowRevenue(row) <= 0
        ? { fillColor: '#e8edf4', fillOpacity: 0.3, color: '#cbd5e1', weight: 0.6 }
        : { fillColor: '#16a34a', fillOpacity: 0.68, color: '#ffffff', weight: 0.9 };
    }
    if (mode === 'opportunities') {
      const key = _activeLayer === 'mapa_composto_ceara'
        ? normalizeNeighborhoodKey(getFeatureCompositeJoinKey(feature))
        : getFeatureLookupKeys(feature).find((candidate) => state._opportunityScores?.has(candidate));
      const score = Number(state._opportunityScores?.get(key) || 0);
      if (score <= 0) return { fillColor: '#e8edf4', fillOpacity: 0.28, color: '#cbd5e1', weight: 0.6 };
      return {
        fillColor: score >= 0.66 ? '#6d28d9' : score >= 0.33 ? '#a855f7' : '#fbbf24',
        fillOpacity: 0.72,
        color: '#ffffff',
        weight: 0.9,
      };
    }
    return base;
  }

  function activeFeatureStyle(feature) {
    return currentModeStyle(
      feature,
      _activeLayer === 'mapa_composto_ceara' ? styleCompositeFeature : featureStyle
    );
  }

  // GEO.R1.7 — normaliza join_key de uma feature para lookup nas vendas compostas
  function getFeatureCompositeJoinKey(feature) {
    const props = feature?.properties || {};
    const type  = getTerritoryType(feature);
    if (!type) return '';
    if (type === 'fortaleza_bairro') {
      return normalizeNeighborhoodKey(props.neighborhood_key || props.bairro_key || getFeatureNeighborhoodName(feature));
    }
    if (type === 'metro_bairro') {
      const jk = props.join_key || '';
      if (jk) return normalizeNeighborhoodKey(jk);
      const ck = props.cidade_key || '';
      const bn = props.bairro_norm || normalizeNeighborhoodKey(getFeatureNeighborhoodName(feature));
      return normalizeNeighborhoodKey(ck + ':' + bn);
    }
    // municipality
    return normalizeNeighborhoodKey(props.cidade_key || props.municipio_norm || '');
  }

  // GEO.R1.7 — label correto para feature (usa municipio_nome real, não city fixo)
  function getFeatureTerritoryLabel(feature) {
    const props = feature?.properties || {};
    if (props.label) return props.label;
    const bairro = props.bairro_nome || getFeatureNeighborhoodName(feature) || '';
    const muni   = props.municipio_nome || '';
    if (bairro && muni && muni.toLowerCase() !== bairro.toLowerCase()) return `${bairro} — ${muni}`;
    if (bairro) return bairro;
    if (muni) return muni;
    return getFeatureNeighborhoodName(feature) || 'Sem nome';
  }

  // GEO.R1.9.6 — territory type labels for tooltip badge
  const _TTYPE_LABELS = {
    fortaleza_bairro:       'Bairro Fortaleza',
    metro_bairro:           'Grande Fortaleza',
    rmf_municipio_fallback: 'Município RMF',
    ceara_municipio:        'Município CE',
    ceara_regiao:           'Região de planejamento',
  };

  function tooltipHtml(feature) {
    const rawName = getFeatureNeighborhoodName(feature);
    const props = feature && typeof feature === 'object' && feature.properties ? feature.properties : {};
    const matched = _activeLayer === 'mapa_composto_ceara'
      ? { row: state.compositeSalesIndex?.get(normalizeNeighborhoodKey(getFeatureCompositeJoinKey(feature))) || null }
      : getFeatureCommercialRow(feature, true);
    const row = matched.row;
    // GEO.R1.7 — label usa município real (nunca herda city fixo da URL)
    const label = getFeatureTerritoryLabel(feature);
    const sourceNote = props.source === 'IBGE_BAIRROS_CD2022' ? '<small style="color:#9ca3af">IBGE Bairros 2022</small>' : '';
    const tooltipRow = row || {
      revenue: 0,
      customers: 0,
      invoices: 0,
      ticket_medio: 0,
    };
    const revenue = getRowRevenue(tooltipRow);
    const metricDef = activeMetricDef();
    const metricValue = getRowMetric(tooltipRow);
    const sharePct = activeMetricShare(tooltipRow);

    // GEO.R1.9.6 — rank lookup from _rankByJoinKey Map
    const jk = normalizeNeighborhoodKey(getFeatureCompositeJoinKey(feature));
    const rank = state._rankByJoinKey?.get(jk);
    const ttype = props.territory_type || '';
    const ttypeLabel = _TTYPE_LABELS[ttype] || '';
    const rankHtml = rank != null
      ? (rank <= 10
          ? `<span class="bmt-rank bmt-rank--top">🏆 #${rank}</span>`
          : `<span class="bmt-rank">#${rank}</span>`)
      : '';

    // GEO.R2.3 — geo metadata badges
    const areaKm2  = Number(props.area_km2 || tooltipRow.area_km2 || 0);
    const density  = areaKm2 > 0 && revenue > 0 ? revenue / areaKm2 : 0;
    const geoRegiao = props.regiao_planejamento_nome || tooltipRow.regiao_planejamento_nome || '';
    const geoRegional = props.regional_administrativa || tooltipRow.regional_administrativa || '';
    const geoMeso = props.meso_regiao_nome || tooltipRow.meso_regiao_nome || '';
    const geoInfoLine = [
      areaKm2  > 0  ? `${formatters.number(areaKm2)} km²` : '',
      density  > 0  ? `${formatters.compactMoney(density)}/km²` : '',
      geoRegional || geoRegiao || geoMeso,
    ].filter(Boolean).join(' · ');

    return `
      <div class="biomie-map-tooltip biomie-map-tooltip--rich">
        <div class="bmt-header">
          <strong>${escapeHtml(label)}</strong>
          ${rankHtml}
        </div>
        ${ttypeLabel ? `<span class="bmt-badge">${escapeHtml(ttypeLabel)}</span>` : ''}
        ${sourceNote ? '<span>' + sourceNote + '</span>' : ''}
        <div class="bmt-kpis">
          <span class="bmt-kpis__primary"><em>${escapeHtml(metricDef.label)}</em><b>${escapeHtml(metricDef.format(metricValue))}</b></span>
          ${sharePct ? `<span><em>Participacao</em><b>${sharePct}</b></span>` : ''}
          ${activeMetricKey() !== 'receita' ? `<span><em>Faturamento</em><b>${formatters.money(revenue)}</b></span>` : ''}
          <span><em>Clientes</em><b>${formatters.number(tooltipRow.customers || tooltipRow.clientes || 0)}</b></span>
          <span><em>Notas</em><b>${formatters.number(tooltipRow.invoices || tooltipRow.notas || 0)}</b></span>
          <span><em>Ticket</em><b>${formatters.money(tooltipRow.average_ticket || tooltipRow.ticket_medio || 0)}</b></span>
        </div>
        ${geoInfoLine ? `<div class="bmt-geo-info">${escapeHtml(geoInfoLine)}</div>` : ''}
      </div>
    `;
  }

  function bindLayer(layer, feature) {
    layer.unbindTooltip();
    layer.bindTooltip(tooltipHtml(feature), { sticky: true, direction: 'top' });
    layer.off();
    layer.on({
      mouseover(event) {
        event.target.setStyle({ weight: 2.6, color: '#24364f', fillOpacity: 0.92 });
        event.target.bringToFront?.();
      },
      mouseout(event) {
        event.target.setStyle(currentModeStyle(feature, featureStyle));
      },
      click(event) {
        const props = feature.properties || {};
        // GEO.R2.1 — prefer municipio_nome / label for municipality layers
        const rawName = getFeatureNeighborhoodName(feature)
          || props.municipio_nome
          || props.label
          || '';
        const matched = getFeatureCommercialRow(feature, false);
        const bairroKey = props.bairro_key || matched.key || normalizeNeighborhoodKey(rawName);
        // cityKey for municipality layers = the municipality itself, not hardcoded 'fortaleza'
        const cityKey = props.city_key || props.cidade_key || normalizeNeighborhoodKey(rawName) || 'fortaleza';
        const territoryType = props.territory_type || LAYER_TERRITORY_TYPE[_activeLayer] || '';
        const muniNome = props.municipio_nome || rawName || '';

        state.selectedKey = bairroKey;
        updateRankingActiveRow();

        // Build row with territory context so renderNeighborhood shows correct title
        const baseRow = matched.row ? Object.assign({}, matched.row, {
          bairro_key:     bairroKey,
          city_key:       cityKey,
          neighborhood:   rawName,
          territory_type: territoryType || matched.row.territory_type || '',
          municipio_nome: muniNome,
          municipio_ibge: props.municipio_ibge || props.codigo_ibge || '',
        }) : {
          bairro_key:     bairroKey,
          city_key:       cityKey,
          city:           muniNome,
          neighborhood:   rawName,
          bairro_nome:    props.bairro_nome || '',
          municipio_nome: muniNome,
          municipio_ibge: props.municipio_ibge || props.codigo_ibge || '',
          territory_type: territoryType,
          revenue:        0,
          customers:      0,
          invoices:       0,
          average_ticket: 0,
        };

        openNeighborhoodDrawer(event.target);
        loadPanelData(baseRow);
        state.geoLayer?.eachLayer((territoryLayer) => territoryLayer.setStyle(activeFeatureStyle(territoryLayer.feature)));
        event.target.bringToFront?.();
      },
    });
  }

  function renderGeoLayer() {
    if (!state.map) {
      updateDiagnostics({ mapState: 'erro: Leaflet indisponivel' });
      return;
    }
    if (!state.geoJson) {
      updateDiagnostics({ mapState: 'sem GeoJSON' });
      return;
    }

    try {
      const features = Array.isArray(state.geoJson.features) ? state.geoJson.features : [];
      if (!features.length) {
        state._paintedFeatures = 0;
        updateDiagnostics({ mapState: 'sem dados', error: 'geojson_without_features' });
        return;
      }

      // If the layer already exists, update styles and tooltips in-place to avoid
      // destroying and recreating all Leaflet DOM nodes on every filter change.
      if (state.geoLayer) {
        let paintedFeatures = 0;
        state.geoLayer.eachLayer((layer) => {
          const feature = layer.feature;
          layer.setStyle(featureStyle(feature));
          bindLayer(layer, feature);
          const matched = getFeatureCommercialRow(feature, true);
          if (matched.row && getRowRevenue(matched.row) > 0) paintedFeatures++;
        });
        state._paintedFeatures = paintedFeatures;
        updateDiagnostics({ mapState: paintedFeatures > 0 ? `atualizado (${paintedFeatures} bairros pintados)` : 'sem dados' });
        debugLog('Bairros encontrados na API (update)', Array.from(state.rowsByKey.keys()).slice(0, 10));
        debugLog('Features pintadas com dados (update)', paintedFeatures);
        return;
      }

      // First render: create the geoLayer from scratch.
      let paintedFeatures = 0;
      const featuresWithNoData = [];

      state.geoLayer = L.geoJson(state.geoJson, {
        // Perf: Leaflet simplifica os polígonos no render (Douglas-Peucker em
        // pixels) — menos pontos desenhados em SVG, sem mudar os dados nem a
        // interação. Reduz bastante o custo de centenas de bairros/municípios.
        smoothFactor: 1.6,
        style: (feature) => {
          const style = featureStyle(feature);
          const matched = getFeatureCommercialRow(feature, true);
          if (matched.row && getRowRevenue(matched.row) > 0) {
            paintedFeatures++;
          } else {
            featuresWithNoData.push(getFeatureNeighborhoodName(feature) || 'sem nome');
          }
          return style;
        },
        onEachFeature(feature, layer) {
          bindLayer(layer, feature);
        },
      }).addTo(state.map);

      debugLog('Bairros encontrados na API', Array.from(state.rowsByKey.keys()).slice(0, 10));
      debugLog('Features pintadas com dados', paintedFeatures);
      debugLog('Features sem dados comerciais', featuresWithNoData.slice(0, 10));

      state._paintedFeatures = paintedFeatures;

      if (state.geoLayer) {
        state.geoLayer.bringToFront();
      }

      if (state.geoLayer && state.geoLayer.getBounds().isValid()) {
        if (!state._mapFitted) {
          const def = activeLayerDef();
          state.map.fitBounds(state.geoLayer.getBounds(), { padding: [18, 18], maxZoom: def.fitMaxZoom || 13 });
          state._mapFitted = true;
        }
      }
      window.requestAnimationFrame(() => state.map.invalidateSize({ pan: false }));
      updateDiagnostics({ mapState: paintedFeatures > 0 ? `renderizado (${paintedFeatures} bairros pintados)` : 'sem dados' });
    } catch (error) {
      state._paintedFeatures = 0;
      updateDiagnostics({ mapState: 'erro', error: 'leaflet_render_error' });
      showState('danger', 'Nao foi possivel desenhar o mapa.', 'O mapa base carregou, mas houve uma falha ao renderizar os bairros. Abra o diagnostico tecnico para ver o motivo provavel.', 'Tentar novamente');
      debugLog('Erro ao renderizar camada Leaflet', error?.message || error);
    }
  }

  function renderLegend() {
    const node = $('mapLegend');
    if (!node) return;
    const scale = state.scale || {};
    const buckets = scale.buckets || [];

    if (buckets.length) {
      // Use bucket index to assign consistent colors
      node.innerHTML = buckets.map((bucket, idx) => {
        bucket = bucket || {};
        const color = (bucket.min === null || idx === 0) ? MAP_BUCKET_COLORS[0] : getBucketColor(idx);
        return `
          <span class="biomie-map-legend__item">
            <span class="biomie-map-legend__swatch" style="background:${color}"></span>
            ${escapeHtml(bucket.label || '-')}
          </span>
        `;
      }).join('');
    } else {
      // Fallback static legend when API sends no buckets
      const staticBuckets = [
        { color: MAP_BUCKET_COLORS[0], label: 'Sem vendas' },
        { color: MAP_BUCKET_COLORS[2], label: 'Baixo' },
        { color: MAP_BUCKET_COLORS[3], label: 'Médio' },
        { color: MAP_BUCKET_COLORS[4], label: 'Alto' },
      ];
      node.innerHTML = staticBuckets.map((b) => `
        <span class="biomie-map-legend__item">
          <span class="biomie-map-legend__swatch" style="background:${b.color}"></span>
          ${escapeHtml(b.label)}
        </span>
      `).join('');
    }
  }

  function scheduleMapInvalidate() {
    if (!state.map || typeof state.map.invalidateSize !== 'function') return;
    window.requestAnimationFrame(() => state.map.invalidateSize({ pan: false }));
  }

  function updateRankingActiveRow() {
    document.querySelectorAll('.geo-ranking-row').forEach((row) => {
      const isActive = !!state.selectedKey && row.getAttribute('data-key') === state.selectedKey;
      row.classList.toggle('geo-ranking-row--active', isActive);
      row.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    const clearButton = $('clearSelection');
    if (clearButton) clearButton.hidden = !state.selectedKey;
  }

  function openNeighborhoodDrawer(trigger) {
    const drawer = $('geoNeighborhoodDrawer');
    const backdrop = $('geoNeighborhoodDrawerBackdrop');
    if (!drawer) return;
    state.drawerReturnFocus = trigger || document.activeElement || null;
    drawer.hidden = false;
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('geo-drawer-open');
    $('geoDrawerClose')?.focus({ preventScroll: true });
    scheduleMapInvalidate();
  }

  function closeNeighborhoodDrawer() {
    const drawer = $('geoNeighborhoodDrawer');
    const backdrop = $('geoNeighborhoodDrawerBackdrop');
    if (drawer) drawer.hidden = true;
    if (backdrop) backdrop.hidden = true;
    document.body.classList.remove('geo-drawer-open');
    scheduleMapInvalidate();
    if (state.drawerReturnFocus && typeof state.drawerReturnFocus.focus === 'function') {
      state.drawerReturnFocus.focus({ preventScroll: true });
    }
    state.drawerReturnFocus = null;
  }

  function renderSummary(payload) {
    const summary = payload?.summary || {};
    const nbPayload = state._neighborhoodsPayload;

    // "Bairros com venda": use API's bairros.com_venda (actual count with revenue > 0),
    // not state.rows.length which includes all 121 official bairros
    const bairrosComVenda = nbPayload?.bairros?.com_venda ?? summary.neighborhoods_with_sales ?? 0;
    setText('geoKpiRevenue', formatters.money(summary.revenue));
    setText('geoKpiAreas', formatters.number(bairrosComVenda));

    // "Melhor bairro": first row with revenue > 0, never a zero-revenue bairro
    const top = state.rows.find((r) => getRowRevenue(r) > 0);
    setText('geoKpiTopArea', getApiNeighborhoodName(top) || 'Sem bairro com venda no periodo');

    setText('geoKpiClients', formatters.number(summary.customers));
    setText('geoKpiNotes', formatters.number(summary.invoices));
    setText('geoKpiNoArea', formatters.number(summary.rows_without_neighborhood));
    const missingRevenue = Number(payload?.no_neighborhood?.faturamento || payload?.data_quality?.missing_neighborhood_revenue || 0);
    updateCoverageKpi(summary.revenue, missingRevenue, summary.invoices, summary.rows_without_neighborhood);
    updateDiagnostics({
      materialized: summary.last_materialized_at || '-',
      noNeighborhood: summary.rows_without_neighborhood || 0,
    });
  }

  function renderRanking() {
    const list = $('scopeRankingTable');
    const countNode = $('geoRankingCount');
    if (!list) return;
    if (!state.rows.length) {
      if (countNode) countNode.textContent = '0 de 0 territórios';
      list.innerHTML = '<div class="geo-ranking-empty">Sem territórios no filtro.</div>';
      return;
    }

    const query = String($('geoRankingSearch')?.value || '').trim().toLowerCase();
    const sort = $('geoRankingSort')?.value || 'revenue_desc';
    const visibleRows = state.rows
      .filter((row) => {
        if (!query) return true;
        const label = (getApiNeighborhoodName(row) || safeText(row.neighborhood_key) || '').toLowerCase();
        return label.includes(query);
      })
      .slice();

    visibleRows.sort((a, b) => {
      const revA = getRowRevenue(a);
      const revB = getRowRevenue(b);
      if (sort === 'customers_desc') return Number(b.customers || 0) - Number(a.customers || 0) || revB - revA;
      if (sort === 'invoices_desc') return Number(b.invoices || 0) - Number(a.invoices || 0) || revB - revA;
      if (sort === 'ticket_desc') return Number(b.average_ticket || 0) - Number(a.average_ticket || 0) || revB - revA;
      if (sort === 'name_asc') return String(getApiNeighborhoodName(a) || '').localeCompare(String(getApiNeighborhoodName(b) || ''), 'pt-BR');
      return revB - revA || String(getApiNeighborhoodName(a) || '').localeCompare(String(getApiNeighborhoodName(b) || ''), 'pt-BR');
    });

    if (countNode) {
      countNode.textContent = `${formatters.number(visibleRows.length)} de ${formatters.number(state.rows.length)} ${activeLayerDef().scopeLabel || 'territórios'}`;
    }

    if (!visibleRows.length) {
      list.innerHTML = '<div class="geo-ranking-empty">Nenhum território corresponde à busca.</div>';
      return;
    }

    const maxMetric = Math.max(...visibleRows.map((r) => getRowMetric(r)), 1);
    list.innerHTML = visibleRows.map((row, index) => {
      const label   = getApiNeighborhoodName(row) || safeText(row.neighborhood_key) || 'Sem bairro';
      const metricValue = getRowMetric(row);
      const barPct  = Math.round((metricValue / maxMetric) * 100);
      const hasData = metricValue > 0;
      const key     = normalizeNeighborhoodKey(row.neighborhood_key || label);
      const active  = state.selectedKey && state.selectedKey === key;

      // GEO.R2.2 — trend inline + alert dot
      const trendPct  = row.trend_receita_pct ?? state.trendsMap.get(key)?.trend_receita_pct ?? null;
      const trendHtml = hasData ? renderTrendInline(trendPct) : '';
      const alertDot  = state.alertsCache.has(key) && (state.alertsCache.get(key)?.alert_count ?? 0) > 0
        ? `<span class="geo-ranking-alert-dot${(state.alertsCache.get(key)?.has_critical ? '' : '--warning')}"></span>`
        : '';

      return `
        <button type="button" class="geo-row-clickable geo-ranking-row${active ? ' geo-ranking-row--active' : ''}" role="option" data-key="${escapeHtml(key)}" data-label="${escapeHtml(label)}" data-city-key="${escapeHtml(row.city_key || row.cidade_key || 'fortaleza')}" aria-selected="${active ? 'true' : 'false'}" title="Clique para abrir detalhes de ${escapeHtml(label)}">
          <span class="geo-ranking-rank">${index + 1}</span>
          <span class="geo-ranking-main">
            <span class="geo-ranking-name">${escapeHtml(label)}${alertDot}</span>
            <span class="geo-ranking-meta">${escapeHtml(row.territory_type === 'ceara_regiao' ? 'Região estratégica' : (row.city || row.municipio_nome || activeLayerDef().title))} · ${formatters.number(row.invoices || 0)} NF</span>
            <span class="geo-ranking-bar" aria-hidden="true"><span style="width:${barPct}%;"></span></span>
          </span>
          <span class="geo-ranking-value">${hasData ? escapeHtml(formatActiveMetric(row, false)) : '-'}${trendHtml}</span>
        </button>
      `;
    }).join('');

    list.onclick = function (e) {
      const item = e.target.closest('[data-key]');
      if (!item) return;
      selectNeighborhood(item.dataset.key, item.dataset.label, item.dataset.cityKey || '', item);
    };
  }

  function renderCoverageBar(summaryPayload) {
    const node = $('geoCoverageBar');
    if (!node) return;
    const summary = summaryPayload?.summary || {};
    const rev     = Number(summary.revenue || 0);
    const semBairro = summaryPayload?.no_neighborhood || {};
    const semBairroRev = Number(semBairro.faturamento || 0);
    const pct = rev > 0 ? Math.round((semBairroRev / rev) * 100) : 0;
    const totalCustomers = Number(summary.customers || 0);
    const totalInvoices = Number(summary.invoices || 0);
    const missingCustomers = Number(semBairro.clientes || 0);
    const missingInvoices = Number(semBairro.notas || 0);
    const customerCoverage = totalCustomers > 0 ? Math.round((1 - missingCustomers / totalCustomers) * 100) : 100;
    const invoiceCoverage = totalInvoices > 0 ? Math.round((1 - missingInvoices / totalInvoices) * 100) : 100;

    if (rev <= 0) {
      node.className = 'module-alert d-none';
      return;
    }
    if (pct > 0) {
      node.className = 'module-alert module-alert--warning geo-commercial-coverage';
      node.innerHTML = `<span class="material-symbols-rounded" style="vertical-align:middle;font-size:16px;margin-right:6px;">bar_chart</span>`
        + `<strong>Confiança territorial: ${100 - pct}%.</strong> `
        + `${formatters.money(semBairroRev)} aguardam confirmação de endereço. `
        + `<span class="geo-intelligence-summary__meta">Cobertura: receita ${100 - pct}% · clientes ${customerCoverage}% · notas ${invoiceCoverage}%.</span>`;
    } else {
      node.className = 'module-alert module-alert--success geo-commercial-coverage';
      node.innerHTML = `<span class="material-symbols-rounded" style="vertical-align:middle;font-size:16px;margin-right:6px;">check_circle</span>`
        + `<strong>Cobertura territorial completa.</strong> Todo o faturamento do período possui localização confirmada.`;
    }
  }

  async function loadPanelData(row) {
    if (!row) return;
    const token = ++state.panelToken;
    resetCnaeBuyerContext();
    // GEO.R1.8.2 — persist row for lazy tab loads + reset to resumo tab
    state.drawerTerritoryRow = row;
    state._lastPanelRow = row; // GEO.R2.2 — for alert lazy load
    setDrawerTabAvailability(row);
    activateDrawerTab('resumo');
    showComparisonDrawerBtn(row);
    applyDrawerGeoInfo(row); // GEO.R2.3 — área, densidade, região, população
    renderTerritoryIntelligence(row);
    window.BIomieMapPanel?.renderNeighborhood(row, formatters);
    window.BIomieMapPanel?.renderBuyersLoading();

    if (row.territory_type === 'ceara_regiao') {
      window.BIomieMapPanel?.renderBuyers([], formatters, 'strategic_region');
      window.BIomieMapPanel?.renderCnaes([], formatters);
      showFeedback('Regiao selecionada. Use Ceara por municipios para aprofundar a leitura operacional dentro desta area.', 'info');
      return;
    }

    const bairroKey = normalizeNeighborhoodKey(row.bairro_key || row.neighborhood_key || row.neighborhood || '');
    const cityKey   = normalizeNeighborhoodKey(row.city_key || row.cidade_key || row.city || 'Fortaleza');

    // GEO.R1.9.4 — always pass city= for legacy APIs (top_buyers/sales_by_cnae expect it;
    // composite mode doesn't send it via currentFilters, breaking territory filtering)
    const legacyCity = row.city || row.municipio_nome ||
      (row.territory_type === 'fortaleza_bairro' ? 'Fortaleza' :
       row.territory_type === 'metro_bairro' ? (row.municipio_nome || row.city || '') :
       row.cidade_key || cityKey);

    const params = currentFilters({
      bairro_key:   bairroKey,
      city_key:     cityKey,
      city:         legacyCity,
      neighborhood: row.neighborhood || row.bairro_nome || '',
      limit: 10,
    });

    // GEO.R1.9.4 — use territory-aware CNAE API (avoids mart_vendas_por_cnae_cnpj dependency)
    // GEO.R1.9.5 — use territory-aware buyers API with mart-lookup strategy
    const buyersParams = buildTerritoryParams(row);
    buyersParams.set('limit', '10');
    const cnaeParams = buildTerritoryParams(row);
    cnaeParams.set('limit', '10');

    const [buyers, cnaes] = await Promise.allSettled([
      apiFetch('api/geo_territory_buyers.php', buyersParams, 'data'),
      apiFetch('api/geo_territory_cnaes.php', cnaeParams, 'items'),
    ]);

    if (state.panelToken !== token) return;

    if (buyers.status === 'fulfilled') {
      const data = buyers.value.data || [];
      const sourceMode = buyers.value.meta?.source_mode || 'geo_territory_mart';
      if (data.length > 0) {
        window.BIomieMapPanel?.renderBuyers(data.map(b => ({
          customer_name:  b.customer_name,
          document_raw:   b.document_raw,
          revenue:        b.revenue,
          invoices:       b.invoices,
          average_ticket: b.average_ticket,
          last_purchase:  b.last_purchase,
          cnae:           b.cnae,
          segment:        b.segment,
          codigo_cliente_omie: b.codigo_cliente_omie,
          cliente_documento: b.document_raw,
          document_masked: b.document_masked || b.document_raw,
          document_type: b.document_type || '',
          document_label: b.document_label || '',
          cidade: b.cidade,
          cidade_norm: b.cidade_norm,
          bairro: b.bairro,
          bairro_norm: b.bairro_norm,
        })), formatters, sourceMode);
      } else {
        // GEO.R1.9.5 — fallback to legacy API when new API returns empty
        apiFetch('api/top_buyers_by_geo.php', params, 'buyers').then(legacyRes => {
          if (state.panelToken !== token) return;
          const legacyData = legacyRes.data || legacyRes.buyers || [];
          window.BIomieMapPanel?.renderBuyers(legacyData, formatters, legacyRes.meta?.source_mode || 'gold_mart');
        }).catch(() => {
          window.BIomieMapPanel?.renderBuyers([], formatters, 'empty');
        });
      }
    } else {
      window.BIomieMapPanel?.renderBuyersError('Erro ao carregar compradores. Tente novamente.');
    }

    if (cnaes.status === 'fulfilled') {
      // geo_territory_cnaes returns { items: [...] } — map to panel format
      const cnaeItems = (cnaes.value.items || []).map(c => ({
        cnae:             c.cnae_codigo,
        cnae_description: c.cnae_descricao,
        customers:        c.clientes,
        revenue:          c.faturamento,
      }));
      window.BIomieMapPanel?.renderCnaes(cnaeItems, formatters);
    } else {
      window.BIomieMapPanel?.renderCnaes([], formatters);
    }

    // GEO.R2.2 — apply trends + meta after panel loads
    applyDrawerTrends(row);
    applyDrawerMeta(row);

    // Reset alert panel (loaded lazily on tab click)
    const alertsPanel = $('geoDrawerAlertasPanel');
    if (alertsPanel) alertsPanel.innerHTML = '<div class="geo-alerts-empty">Clique na aba Alertas para carregar.</div>';
    const alertBadge = $('geoDrawerAlertaBadge');
    if (alertBadge) alertBadge.hidden = true;
  }

  function selectNeighborhood(key, label, cityKey, trigger) {
    const matched = findCommercialRowByKeyOrLabel(key, label, false);
    state.selectedKey = matched.key || key || '';
    if (_activeLayer === 'fortaleza_bairros' && $('neighborhoodFilter')) {
      $('neighborhoodFilter').value = label || '';
    }
    state.geoLayer?.eachLayer((layer) => layer.setStyle(activeFeatureStyle(layer.feature)));
    updateRankingActiveRow();
    focusFeatureByKey(state.selectedKey, false);
    const matchedRow = matched.row;
    const row = matchedRow ? Object.assign({}, matchedRow, {
      bairro_key:    key || matchedRow.bairro_key || matchedRow.neighborhood_key || '',
      city_key:      cityKey || matchedRow.city_key || matchedRow.cidade_key || 'fortaleza',
      neighborhood:  label || matchedRow.neighborhood || getApiNeighborhoodName(matchedRow),
    }) : {
      bairro_key:    key || '',
      city_key:      cityKey || 'fortaleza',
      city:          $('cityFilter')?.value || 'Fortaleza',
      neighborhood:  label,
      revenue:       0,
      customers:     0,
      invoices:      0,
      average_ticket: 0,
    };
    openNeighborhoodDrawer(trigger);
    loadPanelData(row);
  }

  function resetSelection(clearInput) {
    state.selectedKey = '';
    if (clearInput && $('neighborhoodFilter')) $('neighborhoodFilter').value = '';
    state.geoLayer?.eachLayer((layer) => layer.setStyle(activeFeatureStyle(layer.feature)));
    updateRankingActiveRow();
    closeNeighborhoodDrawer();
    // Restore global panel if summary data is available; otherwise show empty state
    const buyers = state.summary ? (state._summaryPayload?.top_buyers || []) : [];
    const cnaes  = state.summary ? (state._summaryPayload?.top_cnaes  || []) : [];
    if ((buyers.length || cnaes.length) && ['fortaleza_bairros', 'ceara_municipios'].includes(_activeLayer)) {
      window.BIomieMapPanel?.setGlobal(buyers, cnaes, formatters);
    } else {
      window.BIomieMapPanel?.setInitial(formatters);
    }
  }

  function clearSelection() {
    resetSelection(true);
  }

  // GEO.R1.9 — improved sem-bairro card with coverage %
  function showEnrichCard(count, revenue, totalRevenue) {
    const node = $('geoEnrichCard');
    if (!node) return;
    if (!count || count <= 0) {
      node.className = 'module-alert d-none';
      return;
    }
    node.className = 'module-alert module-alert--warning';
    const revStr = revenue > 0 ? formatters.money(revenue) : 'Valor não classificado';
    const pct    = totalRevenue > 0 && revenue > 0 ? ` (${(revenue / totalRevenue * 100).toFixed(1)}%)` : '';
    node.innerHTML = `
      <span class="material-symbols-rounded" style="vertical-align:middle;font-size:16px;margin-right:6px;">location_off</span>
      <span>
        <strong>${revStr}${pct}</strong> aguardam confirmação de cidade ou bairro.
        ${count} registro(s) permanecem no total, mas não são atribuídos a um território conhecido.
      </span>
      <div style="font-size:12px;color:var(--mag-muted,#6b7280);margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        Confirme o endereço do cliente para aumentar a confiança do mapa.
        <button type="button" id="semBairroCardBtn" style="font-size:12px;text-decoration:underline;background:none;border:none;cursor:pointer;color:var(--mag-link,#2563eb);padding:0;">Ver cadastros</button>
        <button type="button" id="geoCoverageAuditBtn" style="font-size:12px;text-decoration:underline;background:none;border:none;cursor:pointer;color:var(--mag-link,#2563eb);padding:0;">Entender cobertura</button>
      </div>
    `;
    // Coverage audit button
    $('geoCoverageAuditBtn')?.addEventListener('click', () => loadCoverageAuditPanel());
    const btn = $('semBairroCardBtn');
    if (btn) {
      btn.addEventListener('click', () => {
        const semBairroRow = {
          bairro_key: '__sem_bairro__',
          neighborhood: 'Sem bairro',
          city: $('cityFilter')?.value || 'Fortaleza',
          city_key: '',
          territory_type: 'sem_geo',
          revenue: revenue || 0,
          customers: count || 0,
          invoices: 0,
          average_ticket: 0,
        };
        state.selectedKey = '__sem_bairro__';
        updateRankingActiveRow();
        openNeighborhoodDrawer(btn);
        loadSemBairroPanel(semBairroRow);
      });
    }
  }

  async function loadSemBairroPanel(row) {
    const token = ++state.panelToken;
    resetCnaeBuyerContext();
    const semGeoRow = Object.assign({}, row || {}, {
      bairro_key: '__sem_bairro__',
      city_key: '',
      territory_type: 'sem_geo',
      neighborhood: 'Sem bairro',
    });
    state.drawerTerritoryRow = semGeoRow;
    activateDrawerTab('resumo');
    window.BIomieMapPanel?.renderNoNeighborhood(formatters, semGeoRow.customers || 0, semGeoRow.revenue || 0);
    window.BIomieMapPanel?.renderBuyersLoading();
    window.BIomieMapPanel?.renderCnaesLoading?.();

    const params = buildTerritoryParams(semGeoRow);
    params.set('limit', '10');
    const cnaeParams = buildTerritoryParams(semGeoRow);
    cnaeParams.set('limit', '10');

    const [buyers, cnaes] = await Promise.allSettled([
      apiFetch('api/geo_territory_buyers.php', params, 'sem_geo_buyers'),
      apiFetch('api/geo_territory_cnaes.php', cnaeParams, 'sem_geo_cnaes'),
    ]);
    if (state.panelToken !== token) return;

    if (buyers.status === 'fulfilled') {
      const bd = buyers.value;
      const ctx = {
        title: 'Localização ainda não identificada',
        message: 'Clientes com cidade ou bairro ainda não confirmados. Eles continuam visíveis neste painel e não são atribuídos a um território conhecido.',
      };
      window.BIomieMapPanel?.renderNoNeighborhood(formatters, semGeoRow.customers || 0, semGeoRow.revenue || 0, bd.data || [], ctx);
    } else {
      window.BIomieMapPanel?.renderBuyersError('Erro ao carregar clientes sem bairro.');
    }

    if (cnaes.status === 'fulfilled') {
      const cnaeItems = (cnaes.value.items || []).map(c => ({
        cnae: c.cnae_codigo,
        cnae_description: c.cnae_descricao,
        customers: c.clientes,
        revenue: c.faturamento,
      }));
      window.BIomieMapPanel?.renderCnaes(cnaeItems, formatters);
    } else {
      window.BIomieMapPanel?.renderCnaes([], formatters);
    }
  }

  function computeMatchRate() {
    if (!state.geoJson) {
      return {
        label: '-',
        matchedCount: 0,
        apiWithoutGeo: [],
        geoWithoutApi: [],
        confidence: {},
      };
    }

    const geoKeys = new Set(Array.from(state.geoNamesByKey.keys()).filter(Boolean));
    const apiKeys = new Set(Array.from(state.rowsByKey.keys()).filter(Boolean));
    const matchableApiKeys = Array.from(apiKeys).filter((key) => key !== 'nao informado');
    const matchedKeys = matchableApiKeys.filter((key) => geoKeys.has(key));
    const confidence = { exact: 0, normalized: 0, alias: 0, missing: 0, empty: 0, ambiguous: 0 };
    matchedKeys.forEach((key) => {
      const original = state.apiOriginalByKey.get(key) || state.rowsByKey.get(key)?.neighborhood || key;
      const geoName = state.geoNamesByKey.get(key) || key;
      const explain = explainNeighborhoodNormalization(original);
      if (safeText(original) === safeText(geoName)) {
        confidence.exact++;
      } else if (explain.alias_applied && explain.final === key) {
        confidence.alias++;
      } else {
        confidence.normalized++;
      }
    });
    confidence.missing = matchableApiKeys.length - matchedKeys.length;
    confidence.empty = Array.from(apiKeys).filter((key) => key === 'nao informado').length;

    const apiWithoutGeo = matchableApiKeys
      .filter((key) => key !== 'nao informado' && !geoKeys.has(key))
      .map((key) => ({
        original: state.apiOriginalByKey.get(key) || state.rowsByKey.get(key)?.neighborhood || key,
        key,
        match_type: 'missing',
      }));
    const geoWithoutApi = Array.from(geoKeys)
      .filter((key) => !apiKeys.has(key))
      .map((key) => ({
        original: state.geoNamesByKey.get(key) || key,
        key,
        match_type: 'missing',
      }));
    const percent = matchableApiKeys.length > 0 ? Math.round((matchedKeys.length / matchableApiKeys.length) * 1000) / 10 : 0;

    return {
      label: matchableApiKeys.length > 0 ? `${matchedKeys.length}/${matchableApiKeys.length} bairros (${percent}%)` : '0/0 bairros (0%)',
      matchedCount: matchedKeys.length,
      apiWithoutGeo,
      geoWithoutApi,
      confidence,
      percent,
    };
  }

  async function loadStrategicRegionMap() {
    initMap();
    setMapBusy(true);
    hideState();
    showFeedback('', '');

    try {
      const params = currentFilters({ layer: 'ceara_regioes', mode: 'geojson' });
      updateUrl();
      const [layerPayload, summaryPayload] = await Promise.all([
        apiFetch('api/geo_sales_layer.php', params, 'strategic_regions'),
        apiFetch('api/geo_summary.php', currentFilters({ layer: 'ceara_regioes' }), 'summary'),
      ]);

      state.geoJson = layerPayload.geojson || { type: 'FeatureCollection', features: [] };
      const features = Array.isArray(state.geoJson.features) ? state.geoJson.features : [];
      state.detectedGeoProperty = detectGeoProperty(features);
      state.geoNamesByKey = new Map();
      features.forEach((feature) => {
        feature.properties = feature.properties || {};
        const props = feature.properties;
        const key = normalizeNeighborhoodKey(props.territory_key || props.regiao_id || '');
        const label = props.territory_label || props.regiao_nome || getFeatureNeighborhoodName(feature) || key;
        props.territory_key = props.territory_key || props.regiao_id || key;
        props.territory_label = label;
        props.territory_type = 'ceara_regiao';
        if (key) state.geoNamesByKey.set(key, label);
      });

      state.rows = (layerPayload.ranking || []).map((row) => ({
        ...row,
        neighborhood: row.territory_label || row.territory_key,
        neighborhood_key: row.territory_key,
        bairro_key: row.territory_key,
        territory_type: 'ceara_regiao',
        revenue: Number(row.receita || 0),
        customers: Number(row.clientes || 0),
        invoices: Number(row.nfs || 0),
        average_ticket: Number(row.ticket_medio || 0),
      }));
      const apiIndexes = buildApiNeighborhoodIndexes(state.rows);
      state.rowsByKey = apiIndexes.rowsByKey;
      state.revenueByNeighborhoodKey = apiIndexes.revenueByKey;
      state.apiOriginalByKey = apiIndexes.apiOriginalByKey;
      state.summary = summaryPayload.summary || {};
      state.scale = {
        total: Number(layerPayload.summary?.total_receita || state.summary.revenue || 0),
        max: Math.max(...state.rows.map((row) => getRowMetric(row)), 0),
      };
      state._summaryPayload = summaryPayload;
      state._neighborhoodsPayload = { data: state.rows, scale: null };
      recomputeMetricScale();

      renderGeoLayer();
      renderRanking();
      renderMapModeLegend();
      renderSummary(summaryPayload);
      renderCoverageBar(summaryPayload);
      renderGeoFilterChips();
      updateMapContext();
      resetSelection(false);

      const strategicSummary = layerPayload.summary || {};
      setText('geoKpiRevenue', formatters.money(strategicSummary.total_receita || state.summary.revenue || 0));
      setText('geoKpiAreas', formatters.number(strategicSummary.with_sales || state.rows.length));
      setText('geoKpiTopArea', strategicSummary.top_territory || getApiNeighborhoodName(state.rows[0]) || 'Sem resultado');
      setText('geoKpiClients', formatters.number(strategicSummary.total_clientes || state.summary.customers || 0));
      setText('geoKpiNotes', formatters.number(strategicSummary.total_nfs || state.summary.invoices || 0));
      // GEO.R2.2 — store trends and update KPI trends
      if (layerPayload.summary_trend) {
        state.summaryTrend = layerPayload.summary_trend;
        updateKpiTrends(layerPayload.summary_trend);
      }
      if (Array.isArray(layerPayload.ranking)) {
        state.trendsMap = new Map(layerPayload.ranking.map((r) => [r.territory_key, r]));
      }
      window.BIomieMapPanel?.setGlobal(summaryPayload.top_buyers || [], summaryPayload.top_cnaes || [], formatters);
      if (state._mapMode && state._mapMode !== 'territories') await applyMapMode(state._mapMode);
    } catch (error) {
      if (error.name === 'AbortError') return;
      showState('danger', 'Nao foi possivel carregar a visao estrategica.', error.message || 'Tente novamente.', 'Tentar novamente');
      updateDiagnostics({ mapState: 'erro', error: 'strategic_regions_error' });
    } finally {
      setMapBusy(false);
    }
  }

  async function loadCommercialData() {
    initMap();

    // GEO.R1.7 — mapa composto usa loader dedicado com 3 GeoJSONs e API própria
    if (_activeLayer === 'mapa_composto_ceara') {
      return initCompositeMap();
    }

    if (_activeLayer === 'ceara_regioes') {
      return loadStrategicRegionMap();
    }

    // Camadas sem geometria disponível: mostrar overlay e sair sem tentar carregar GeoJSON
    if (activeLayerDef().geometryAvailable === false) {
      hideState();
      showFeedback('', '');
      applyLayerLabels(_activeLayer);
      return;
    }

    setMapBusy(true);
    hideState();
    showFeedback('', '');

    try {
      const geoJson = await loadGeoJson();
      const params = currentFilters();
      updateUrl();
      const [summaryPayload, neighborhoodsPayload] = await Promise.all([
        apiFetch('api/geo_summary.php', params, 'summary'),
        apiFetch('api/sales_by_neighborhood.php', params, 'neighborhoods'),
      ]);

      state.rows = Array.isArray(neighborhoodsPayload.data)
        ? filterRowsToActiveGeometry(
            neighborhoodsPayload.data.filter((row) => row && typeof row === 'object'),
            geoJson
          )
        : [];
      state.scale = neighborhoodsPayload.scale || null;
      state.summary = summaryPayload.summary || null;
      state._neighborhoodsPayload = neighborhoodsPayload;
      state._summaryPayload = summaryPayload;
      
      const apiIndexes = buildApiNeighborhoodIndexes(state.rows);
      state.rowsByKey = apiIndexes.rowsByKey;
      state.revenueByNeighborhoodKey = apiIndexes.revenueByKey;
      state.apiOriginalByKey = apiIndexes.apiOriginalByKey;
      state.aliasesUsed = apiIndexes.aliasesUsed;
      state.normalizationExamples = apiIndexes.normalizationExamples;
      state.geoDebug.apiRowsSample = state.rows.slice(0, 10);
      state.geoDebug.apiKeys = Array.from(state.revenueByNeighborhoodKey.keys());
      state.geoDebug.api_neighborhood_count = state.revenueByNeighborhoodKey.size;
      state.geoDebug.apiInvalidRows = apiIndexes.invalidRows;
      state.geoDebug.apiInvalidRevenueRows = apiIndexes.invalidRevenueRows;
      state.geoDebug.aliasesUsed = apiIndexes.aliasesUsed;
      state.geoDebug.normalizationExamples = apiIndexes.normalizationExamples;
      recomputeMetricScale();

      const matchDiag = computeMatchRate();
      state.matchConfidence = matchDiag.confidence || {};
      const apiState = state.rows.length > 0 ? 'OK' : 'vazia';
      debugLog('Amostra de bairros da API', state.rows.slice(0, 10).map(getApiNeighborhoodName));
      debugLog('Amostra de chaves normalizadas da API', Array.from(state.rowsByKey.keys()).slice(0, 10));
      debugLog('Bairros API sem match GeoJSON', matchDiag.apiWithoutGeo.slice(0, 10));
      debugLog('Aliases aplicados', state.aliasesUsed.slice(0, 10));

      const diagFromApi = summaryPayload.diagnostics || {};
      const layeredMapStatus = summaryPayload.map_status || diagFromApi.map_status || neighborhoodsPayload.map_status || neighborhoodsPayload.diagnostics?.map_status || null;
      if (layeredMapStatus && layeredMapStatus.map_status && layeredMapStatus.map_status !== 'ok') {
        const tone = layeredMapStatus.severidade === 'alta' ? 'warning' : 'info';
        showFeedback(layeredMapStatus.diagnostico_tecnico || layeredMapStatus.mensagem_comercial || 'Diagnostico do mapa disponivel.', tone);
      }
      const noNeighborhood = state.summary?.rows_without_neighborhood || 0;
      updateDiagnostics({
        salesCount: state.rows.length,
        apiState,
        noNeighborhood,
        goldTable: diagFromApi.source_table || 'mart_compradores_por_geo',
        goldRows: diagFromApi.gold_rows_in_period ?? state.summary?.invoices ?? '-',
        matchRate: matchDiag.label,
        apiUnmatched: formatDiagnosticExamples(matchDiag.apiWithoutGeo),
        geoUnmatched: formatDiagnosticExamples(matchDiag.geoWithoutApi),
        aliasesUsed: formatDiagnosticExamples(state.aliasesUsed.map((item) => ({
          original: item.original,
          key: item.canonical,
          normalized: item.normalized,
          match_type: 'alias',
        }))),
        normalizationExamples: formatDiagnosticExamples(state.normalizationExamples),
        matchConfidence: formatMatchConfidence(matchDiag.confidence),
        motivoZero: diagFromApi.motivo_zero || '-',
        layeredStatus: layeredMapStatus?.map_status || '-',
        nextAction: diagFromApi.next_action || layeredMapStatus?.proxima_acao || '-',
        rowsBeforeCity: diagFromApi.rows_before_city_filter ?? '-',
        rowsAfterCity: diagFromApi.rows_after_city_filter ?? '-',
        canonicalNfs: diagFromApi.canonical_nfs_no_periodo ?? '-',
        geoMartNotas: diagFromApi.geo_mart_notas_no_periodo ?? '-',
        error: '-',
      });

      const safeRender = (label, callback) => {
        try {
          callback();
          return true;
        } catch (renderError) {
          updateDiagnostics({ error: `render_${label}` });
          debugLog(`Erro ao renderizar ${label}`, renderError?.message || renderError);
          return false;
        }
      };

      safeRender('summary', () => renderSummary(summaryPayload));
      safeRender('layer_kpis', renderActiveLayerKpis);
      safeRender('ranking', renderRanking);
      safeRender('geo_layer', () => renderGeoLayer(geoJson));
      safeRender('legend', renderLegend);
      safeRender('selection', () => resetSelection(false));
      const semBairroRevenue = summaryPayload.no_neighborhood?.faturamento || summaryPayload.data_quality?.missing_neighborhood_revenue || 0;
      safeRender('enrichment', () => showEnrichCard(noNeighborhood, semBairroRevenue));
      safeRender('coverage', () => renderCoverageBar(summaryPayload));

      // GEO.R2.2 — fetch geo_sales_layer trends (runs in background, doesn't block render)
      fetchAndApplyTrends();
      // Inteligencia Python persistida tambem e progressiva: o mapa principal
      // nao espera por ela, mas o drawer e o modo Oportunidades a reutilizam.
      loadGeoIntelligence();

      // Populate side panel with global top buyers and CNAEs (no neighborhood selected)
      const globalBuyers = summaryPayload.top_buyers || [];
      const globalCnaes  = summaryPayload.top_cnaes  || [];
      safeRender('side_panel_global', () => {
        if (['fortaleza_bairros', 'ceara_municipios'].includes(_activeLayer)) {
          window.BIomieMapPanel?.setGlobal(globalBuyers, globalCnaes, formatters);
        } else {
          window.BIomieMapPanel?.setInitial(formatters);
        }
      });
      safeRender('filter_chips', renderGeoFilterChips);
      safeRender('map_context', updateMapContext);
      if (state._mapMode && state._mapMode !== 'territories') await applyMapMode(state._mapMode);

      if (!state.rows.length || state.rows.every((r) => getRowRevenue(r) <= 0)) {
        updateDiagnostics({ mapState: 'sem dados' });
        const diagMaterialized = diagFromApi.materialized;
        const motivoZero = summaryPayload.diagnostics?.motivo_zero || neighborhoodsPayload.diagnostics?.motivo_zero || null;
        const martRevTotal = summaryPayload.diagnostics?.soma_faturamento_sem_filtro || 0;
        const minComp = summaryPayload.diagnostics?.min_competencia || '';
        const maxComp = summaryPayload.diagnostics?.max_competencia || '';

        if (motivoZero === 'filtro_cidade_removeu_tudo') {
          const rowsBefore = diagFromApi.rows_before_city_filter || 0;
          showState('empty', 'Filtro de cidade sem resultado.',
            `O período tem ${rowsBefore} linha(s) no mart, mas o filtro de cidade removeu tudo. Verifique se os clientes estão enriquecidos com a cidade correta ou tente outra cidade.`,
            'Limpar filtros'
          );
        } else if (motivoZero === 'mart_nao_materializado' || motivoZero === 'mart_geo_nao_materializado') {
          const nfsCanonical = summaryPayload.diagnostics?.canonical_nfs_for_period || summaryPayload.diagnostics?.canonical_nfs_no_periodo || 0;
          const missingMonths = summaryPayload.diagnostics?.missing_months_in_geo_mart || [];
          const missingText = missingMonths.length > 0 ? `Os meses (${missingMonths.join(', ')}) possuem` : `${nfsCanonical} NF(s) encontrada(s)`;
          
          const detail = nfsCanonical > 0
            ? `${missingText} na base canonical, mas o mart geográfico ainda não foi materializado para este período.`
            : 'O mart geográfico ainda não foi materializado para o período selecionado.';
          showState('danger', 'Dados não materializados para este período.', detail, 'Reprocessar mapa deste período');
          injectRebuildButton(currentFilters());
        } else if (diagMaterialized === false) {
          showState('danger', 'O mapa ainda nao foi materializado.', 'Execute a materializacao dos marts para gerar os dados geograficos.', 'Tentar novamente');
        } else if (diagFromApi.pending_enrichment) {
          const semBairro = diagFromApi.clientes_sem_bairro || 0;
          const detail = semBairro > 0
            ? `${semBairro} cliente(s) sem endereco cadastrado. Execute clientes:enrich-omie no Console Admin e depois geo:materialize para atualizar o mapa.`
            : 'Clientes sem bairro ou cidade cadastrados. Execute clientes:enrich-omie no Console Admin e depois geo:materialize.';
          showState('empty', 'Mapa sem coloracao: clientes sem endereco.', detail, 'Tentar novamente');
        } else if (motivoZero === 'filtros_nao_reconhecidos' && martRevTotal > 0) {
          const periodoHint = minComp && maxComp ? ` Dados disponiveis: ${minComp} a ${maxComp}.` : '';
          showState('empty', 'Filtros sem resultado.', `O mart tem dados (R$ ${new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(martRevTotal)} sem filtro) mas nao bate com os filtros atuais.${periodoHint} Amplie o periodo ou limpe os filtros.`, 'Limpar filtros');
        } else {
          showState('empty', 'Nenhuma venda geografica encontrada.', EMPTY_GEO_MESSAGE, 'Alterar filtros');
        }
      } else if (state.geoDebug.api_neighborhood_count > 0 && state._paintedFeatures === 0) {
        updateDiagnostics({ mapState: 'erro: sem match API x GeoJSON', error: 'geo_match_empty' });
        showState('danger', 'Há faturamento no período, mas ele ainda não pôde ser posicionado no mapa.', 'Revise os endereços dos clientes ou tente ampliar o território analisado.', 'Limpar filtros');
      }
    } catch (error) {
      if (error.name === 'AbortError') return;
      const payload = error.payload || {};
      const errorCode = payload.error || '';
      const isGeoJsonError = error.isGeoJsonError || String(error.code || '').startsWith('geojson_');
      const isMissingTable = error.status === 409 || errorCode === 'missing_analytic_table';
      const isMigrationPending = errorCode === 'migration_pending';
      const isMatMissing = errorCode === 'materialization_missing';
      const isOmie = errorCode === 'omie_data_not_synced';

      state.rows = [];
      state.rowsByKey = new Map();
      state.revenueByNeighborhoodKey = new Map();
      renderRanking();
      renderGeoLayer();
      renderLegend();
      resetSelection(false);
      showEnrichCard(0);

      if (isGeoJsonError) {
        showState('danger', 'Mapa de bairros indisponível.', 'Não foi possível carregar a visualização territorial agora. O ranking comercial continua disponível.', 'Tentar novamente');
      } else if (isMigrationPending) {
        showState('danger', 'Os dados analiticos ainda nao foram preparados.', 'Aplique as migrations pendentes no painel de Administracao.', 'Tentar novamente');
      } else if (isMatMissing) {
        showState('danger', 'O mapa ainda nao foi materializado.', 'Execute a materializacao dos marts para gerar os dados geograficos.', 'Tentar novamente');
      } else if (isMissingTable) {
        showState('danger', 'Os dados geograficos ainda nao foram preparados.', 'Execute as migrations e a materializacao dos marts.', 'Tentar novamente');
      } else if (isOmie) {
        showState('danger', 'Os dados do Omie ainda nao foram sincronizados para este periodo.', 'Verifique a ultima sincronizacao antes de consultar o mapa.', 'Tentar novamente');
      } else {
        showState('danger', 'Não foi possível carregar o mapa comercial agora.', 'Tente novamente em alguns instantes. Seus dados permanecem preservados.', 'Tentar novamente');
      }
      updateDiagnostics({
        apiState: isGeoJsonError ? (updateDiagnostics.current?.apiState || '-') : 'erro',
        mapState: 'erro',
        error: errorCode || error.code || 'api_error',
      });
      debugLog('Erro ao carregar mapa', error?.message || error);
    } finally {
      setMapBusy(false);
    }
  }

  function applyPresetYear() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    if ($('dateStart')) $('dateStart').value = `${year}-01-01`;
    if ($('dateEnd')) $('dateEnd').value = `${year}-${month}-${day}`;
  }

  function applyPresetThisMonth() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    if ($('dateStart')) $('dateStart').value = `${year}-${month}-01`;
    if ($('dateEnd')) $('dateEnd').value = `${year}-${month}-${day}`;
  }

  function applyPreset3Months() {
    const today = new Date();
    const past = new Date(today.getFullYear(), today.getMonth() - 3, today.getDate());
    const toY = today.getFullYear();
    const toM = String(today.getMonth() + 1).padStart(2, '0');
    const toD = String(today.getDate()).padStart(2, '0');
    const frY = past.getFullYear();
    const frM = String(past.getMonth() + 1).padStart(2, '0');
    const frD = String(past.getDate()).padStart(2, '0');
    if ($('dateStart')) $('dateStart').value = `${frY}-${frM}-${frD}`;
    if ($('dateEnd')) $('dateEnd').value = `${toY}-${toM}-${toD}`;
  }

  function clearFilters() {
    applyPresetYear();
    if ($('cityFilter')) $('cityFilter').value = 'Fortaleza';
    if ($('neighborhoodFilter')) $('neighborhoodFilter').value = '';
    if ($('cnaeFilter')) $('cnaeFilter').value = '';
    if ($('vendedorFilter')) $('vendedorFilter').value = '';
    if ($('geoRankingSearch')) $('geoRankingSearch').value = '';
    if ($('geoRankingSort')) $('geoRankingSort').value = 'revenue_desc';
    state.selectedKey = '';
    loadCommercialData();
  }

  function renderGeoFilterChips() {
    const container = $('geoFilterChips');
    if (!container) return;

    const today = new Date();
    const yd = String(today.getDate()).padStart(2, '0');
    const ym = String(today.getMonth() + 1).padStart(2, '0');
    const todayStr = `${today.getFullYear()}-${ym}-${yd}`;
    const defaultFrom = `${today.getFullYear()}-01-01`;

    const dateFrom = $('dateStart')?.value || '';
    const dateTo = $('dateEnd')?.value || '';
    const city = ($('cityFilter')?.value || '').trim();
    const neighborhood = ($('neighborhoodFilter')?.value || '').trim();
    const cnae = ($('cnaeFilter')?.value || '').trim();
    const vendedor = ($('vendedorFilter')?.value || '').trim();

    const chips = [];

    if (dateFrom && dateTo && !(dateFrom === defaultFrom && dateTo === todayStr)) {
      chips.push({ key: 'date', label: `${dateFrom} – ${dateTo}` });
    }
    if (city && city.toLowerCase() !== 'fortaleza') {
      chips.push({ key: 'city', label: `Cidade: ${city}` });
    }
    if (neighborhood) {
      chips.push({ key: 'neighborhood', label: `Bairro: ${neighborhood}` });
    }
    if (cnae) {
      chips.push({ key: 'cnae', label: `CNAE: ${cnae}` });
    }
    if (vendedor) {
      chips.push({ key: 'vendedor', label: `Vendedor: ${vendedor}` });
    }

    if (!chips.length) {
      container.style.display = 'none';
      container.innerHTML = '';
      return;
    }

    container.style.display = '';
    container.innerHTML = chips.map((chip) =>
      `<span class="biomie-filter-chip" data-chip-key="${escapeHtml(chip.key)}">${escapeHtml(chip.label)}<button type="button" class="biomie-filter-chip__remove" title="Remover" aria-label="Remover filtro">×</button></span>`
    ).join('') + '<button type="button" class="biomie-filter-chip-clear">Limpar filtros</button>';

    container.querySelectorAll('.biomie-filter-chip').forEach((span) => {
      span.querySelector('.biomie-filter-chip__remove')?.addEventListener('click', () => {
        const key = span.dataset.chipKey;
        if (key === 'date') { applyPresetYear(); }
        else if (key === 'city') { if ($('cityFilter')) $('cityFilter').value = 'Fortaleza'; }
        else if (key === 'neighborhood') { if ($('neighborhoodFilter')) $('neighborhoodFilter').value = ''; state.selectedKey = ''; }
        else if (key === 'cnae') { if ($('cnaeFilter')) $('cnaeFilter').value = ''; }
        else if (key === 'vendedor') { if ($('vendedorFilter')) $('vendedorFilter').value = ''; }
        loadCommercialData();
      });
    });

    container.querySelector('.biomie-filter-chip-clear')?.addEventListener('click', clearFilters);
  }

  // GEO.R1 — retorna a camada ativa
  function activeLayerDef() {
    return GEO_LAYERS[_activeLayer] || GEO_LAYERS[DEFAULT_LAYER];
  }

  // GEO.R1 — aplica a camada selecionada: recarrega GeoJSON + dados
  // GEO.R1.9.2 — Sanitize UI filters when switching to multi-city layers
  function sanitizeGeoFiltersForLayer(newLayerId) {
    const nbFilter   = $('neighborhoodFilter');
    const multiCity  = ['grande_fortaleza_bairros','rmf_municipios','ceara_municipios','ceara_regioes','metro_todas_municipios','metro_sobral_municipios','metro_cariri_municipios','mapa_composto_ceara'];
    if (multiCity.includes(newLayerId)) {
      if (nbFilter && nbFilter.value) {
        nbFilter.value = '';
        const note = document.createElement('div');
        note.className = 'geo-filter-sanitize-note';
        note.textContent = 'Filtro de bairro removido para esta visão';
        note.style.cssText = 'position:fixed;bottom:80px;right:16px;background:#1e293b;color:#fff;padding:8px 14px;border-radius:8px;font-size:12px;z-index:9999;opacity:0;transition:opacity .3s;';
        document.body.appendChild(note);
        requestAnimationFrame(() => { note.style.opacity = '1'; });
        setTimeout(() => { note.style.opacity = '0'; setTimeout(() => note.remove(), 350); }, 2500);
      }
    }
  }

  function applyGeoLayer() {
    const sel = $('geoLayerSelector');
    const newLayer = sel ? sel.value : DEFAULT_LAYER;
    if (!GEO_LAYERS[newLayer]) return;
    sanitizeGeoFiltersForLayer(newLayer);
    _activeLayer = newLayer;

    // Atualiza URL sem reload
    try {
      const up = new URLSearchParams(window.location.search);
      up.set('layer', newLayer);
      const scope = $('geoScopeSelector')?.value || 'orc_pd';
      up.set('scope', scope);
      history.replaceState(null, '', '?' + up.toString());
    } catch(e) {}

    // Atualiza todos os labels/títulos/overlay conforme a camada
    applyLayerLabels(newLayer);

    // Reset map state — inclui _mapFitted para forçar fitBounds na nova camada
    state.selectedKey = '';
    state._mapFitted = false;
    if (state.geoLayer && state.map) { state.map.removeLayer(state.geoLayer); state.geoLayer = null; }
    state.geoJson = null;
    state.rows = [];
    state.rowsByKey.clear();
    state.revenueByNeighborhoodKey.clear();

    // Reload GeoJSON e dados
    loadCommercialData();
  }

  function getCurrentGeoJsonUrl() {
    return activeLayerDef().geojson;
  }

  function getCurrentBounds() {
    return activeLayerDef().bounds || FORTALEZA_BOUNDS;
  }

  function initFiltersFromUrl() {
    const params = new URLSearchParams(window.location.search);
    // Restore layer from URL
    const layerFromUrl = params.get('layer');
    if (layerFromUrl && GEO_LAYERS[layerFromUrl]) {
      _activeLayer = layerFromUrl;
    }
    const sel = $('geoLayerSelector');
    if (sel) sel.value = _activeLayer;
    const scopeFromUrl = params.get('scope');
    if (scopeFromUrl === 'orc_pd') {
      const scopeSel = $('geoScopeSelector');
      if (scopeSel) scopeSel.value = scopeFromUrl;
    }
    const metricFromUrl = params.get('metric');
    if (metricFromUrl && METRIC_DEFS[metricFromUrl]) {
      const metricSel = $('geoMetricSelector');
      if (metricSel) metricSel.value = metricFromUrl;
    }
    applyPresetYear();
    const dateStart = $('dateStart');
    const dateEnd = $('dateEnd');
    const cityFilter = $('cityFilter');
    const neighborhoodFilter = $('neighborhoodFilter');
    const cnaeFilter = $('cnaeFilter');
    if (dateStart && (params.get('date_from') || params.get('start'))) dateStart.value = params.get('date_from') || params.get('start');
    if (dateEnd && (params.get('date_to') || params.get('end'))) dateEnd.value = params.get('date_to') || params.get('end');
    if (cityFilter && (params.get('city') || params.get('cidade'))) cityFilter.value = params.get('city') || params.get('cidade');
    if (neighborhoodFilter && (params.get('neighborhood') || params.get('bairro'))) neighborhoodFilter.value = params.get('neighborhood') || params.get('bairro');
    if (cnaeFilter && params.get('cnae')) cnaeFilter.value = params.get('cnae');
    const vendedorFilter = $('vendedorFilter');
    if (vendedorFilter && params.get('seller_id')) vendedorFilter.value = params.get('seller_id');
  }

  function bindEvents() {
    // GEO.R1 — layer switcher
    $('applyGeoLayer')?.addEventListener('click', applyGeoLayer);
    $('geoLayerSelector')?.addEventListener('change', applyGeoLayer);
    $('geoScopeSelector')?.addEventListener('change', (event) => {
      if (event.target.value !== 'orc_pd') {
        event.target.value = 'orc_pd';
        showFeedback('O mapa usa a base comercial validada. Remessas e licitacoes nao fazem parte deste mart geografico.', 'info');
        return;
      }
      applyGeoLayer();
    });
    $('geoMetricSelector')?.addEventListener('change', () => {
      const sort = $('geoRankingSort');
      if (sort) sort.value = activeMetricDef().sort;
      refreshMetricVisuals();
    });
    $('applyFilters')?.addEventListener('click', () => {
      state.selectedKey = '';
      loadCommercialData();
    });
    $('clearFilters')?.addEventListener('click', clearFilters);
    $('clearSelection')?.addEventListener('click', clearSelection);
    $('fitLayerBoundsBtn')?.addEventListener('click', fitLayerBounds);
    $('geoRankingSearch')?.addEventListener('input', () => {
      if (_activeLayer === 'mapa_composto_ceara') renderCompositeRanking(state.compositeRankingCache || []);
      else renderRanking();
    });
    $('geoRankingSort')?.addEventListener('change', () => {
      if (_activeLayer === 'mapa_composto_ceara') renderCompositeRanking(state.compositeRankingCache || []);
      else renderRanking();
    });
    $('geoDrawerClose')?.addEventListener('click', closeNeighborhoodDrawer);
    $('geoNeighborhoodDrawerBackdrop')?.addEventListener('click', closeNeighborhoodDrawer);
    $('geoPresetThisMonth')?.addEventListener('click', () => { applyPresetThisMonth(); loadCommercialData(); });
    $('geoPresetLast3')?.addEventListener('click', () => { applyPreset3Months(); loadCommercialData(); });
    $('geoPresetThisYear')?.addEventListener('click', () => { applyPresetYear(); loadCommercialData(); });
    document.addEventListener('click', handleCustomerOpenClick);
    document.addEventListener('click', handleCnaeDrilldownClick);
    document.addEventListener('keydown', handleCustomerOpenKeydown);
    document.addEventListener('keydown', handleCnaeDrilldownKeydown);
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !$('geoNeighborhoodDrawer')?.hidden) closeNeighborhoodDrawer();
    });
    window.addEventListener('resize', scheduleMapInvalidate);
  }

  function dataFromTrigger(trigger, attr) {
    return trigger.getAttribute(`data-${attr}`) || '';
  }

  function openCustomerFromTrigger(trigger) {
    const params = currentFilters();
    const selectedBairro = dataFromTrigger(trigger, 'bairro') || params.get('neighborhood') || '';
    const selectedCity = dataFromTrigger(trigger, 'city') || params.get('city') || $('cityFilter')?.value || '';
    if (!window.BIomieCustomerDetail) {
      showFeedback('Cliente 360 ainda nao foi carregado.', 'warning');
      return;
    }
    window.BIomieCustomerDetail.open({
      cliente_documento: dataFromTrigger(trigger, 'cliente-documento'),
      documento_norm: dataFromTrigger(trigger, 'documento-norm'),
      codigo_cliente_omie: dataFromTrigger(trigger, 'codigo-cliente-omie'),
      cliente_nome: dataFromTrigger(trigger, 'cliente-nome'),
      bairro: selectedBairro,
      bairro_norm: dataFromTrigger(trigger, 'bairro-norm') || selectedBairro,
      city: selectedCity,
      cidade_norm: dataFromTrigger(trigger, 'cidade-norm') || selectedCity,
      start: params.get('date_from') || $('dateStart')?.value || '',
      end: params.get('date_to') || $('dateEnd')?.value || '',
      date_from: params.get('date_from') || $('dateStart')?.value || '',
      date_to: params.get('date_to') || $('dateEnd')?.value || '',
    });
  }

  function handleCustomerOpenClick(event) {
    if (event.__biomieCustomerHandled) return;
    const trigger = event.target.closest('[data-biomie-action="open-customer"]');
    if (!trigger) return;
    event.preventDefault();
    openCustomerFromTrigger(trigger);
  }

  function handleCustomerOpenKeydown(event) {
    if (event.__biomieCustomerHandled) return;
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const trigger = event.target.closest('[data-biomie-action="open-customer"]');
    if (!trigger) return;
    event.preventDefault();
    openCustomerFromTrigger(trigger);
  }

  function normalizeCnaeDocumentType(value) {
    if (value === 'cnpj' || value === 'cpf') return value;
    return 'all';
  }

  function renderCnaeBuyerTools(visible) {
    const tools = $('cnaeBuyerTools');
    if (!tools) return;
    tools.classList.toggle('is-hidden', !visible);
    tools.querySelectorAll('[data-geo-cnae-document-type]').forEach((button) => {
      const type = normalizeCnaeDocumentType(button.getAttribute('data-geo-cnae-document-type') || 'all');
      const active = type === state.cnaeBuyerDocumentType;
      button.classList.toggle('geo-cnae-doc-filter--active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function resetCnaeBuyerContext() {
    state.cnaeBuyerContext = null;
    state.cnaeBuyerDocumentType = 'all';
    renderCnaeBuyerTools(false);
  }

  async function loadBuyersForSelectedCnae(cnaeCode, description, documentType) {
    const row = state.drawerTerritoryRow;
    if (!row || !cnaeCode) return;

    state.cnaeBuyerDocumentType = normalizeCnaeDocumentType(documentType || state.cnaeBuyerDocumentType || 'all');
    state.cnaeBuyerContext = {
      code: cnaeCode,
      description: description || '',
      row,
    };
    renderCnaeBuyerTools(true);

    const params = buildTerritoryParams(row);
    params.set('cnae', cnaeCode);
    params.set('document_type', state.cnaeBuyerDocumentType);
    params.set('limit', '20');
    window.BIomieMapPanel?.renderBuyersLoading();
    renderCnaeBuyerTools(true);

    try {
      const payload = await apiFetch('api/geo_territory_buyers.php', params, 'cnae_buyers');
      const rows = (payload.data || []).map((b) => ({
        customer_name: b.customer_name,
        document_raw: b.document_raw,
        document_masked: b.document_masked || b.document_raw,
        document_type: b.document_type || '',
        document_label: b.document_label || '',
        buyer_kind: b.buyer_kind || '',
        is_b2b: !!b.is_b2b,
        cliente_documento: b.document_raw,
        revenue: b.revenue,
        invoices: b.invoices,
        average_ticket: b.average_ticket,
        last_purchase: b.last_purchase,
        cnae: b.cnae || cnaeCode,
        segment: b.segment || description || '',
        codigo_cliente_omie: b.codigo_cliente_omie,
        cidade: b.cidade,
        cidade_norm: b.cidade_norm,
        bairro: b.bairro,
        bairro_norm: b.bairro_norm,
      }));
      const cnaeMeta = payload.meta || {};
      cnaeMeta.cnae_filter = cnaeMeta.cnae_filter || {};
      cnaeMeta.cnae_filter.code = cnaeCode;
      cnaeMeta.cnae_filter.description = description || cnaeMeta.cnae_filter.description || '';
      window.BIomieMapPanel?.renderBuyers(rows, formatters, cnaeMeta.source_mode || 'geo_territory_cnae', cnaeMeta);
      const label = description ? `${cnaeCode} - ${description}` : cnaeCode;
      const summary = cnaeMeta.summary || {};
      const cnpjCount = summary.cnpj?.count || 0;
      const cpfCount = summary.cpf?.count || 0;
      const filterLabel = state.cnaeBuyerDocumentType === 'cnpj' ? 'empresas CNPJ' : (state.cnaeBuyerDocumentType === 'cpf' ? 'clientes CPF' : 'todos');
      showFeedback(`Compradores por CNAE ${label}: ${cnpjCount} CNPJ e ${cpfCount} CPF (${filterLabel}).`, 'info');
    } catch (error) {
      window.BIomieMapPanel?.renderBuyersError('Erro ao carregar empresas deste CNAE.');
    }
  }

  function handleCnaeBuyerDocumentTypeClick(event) {
    const trigger = event.target.closest('[data-geo-cnae-document-type]');
    if (!trigger) return;
    event.preventDefault();
    const context = state.cnaeBuyerContext;
    if (!context || !context.code) {
      showFeedback('Clique primeiro em um CNAE para filtrar os compradores.', 'warning');
      return;
    }
    loadBuyersForSelectedCnae(
      context.code,
      context.description || '',
      trigger.getAttribute('data-geo-cnae-document-type') || 'all'
    );
  }

  function exportFilenameFromDisposition(disposition) {
    const raw = String(disposition || '');
    const utfMatch = raw.match(/filename\*=UTF-8''([^;]+)/i);
    if (utfMatch) {
      try { return decodeURIComponent(utfMatch[1]); } catch (error) {}
    }
    const match = raw.match(/filename="?([^"]+)"?/i);
    return match ? match[1] : '';
  }

  async function exportSelectedCnaeBuyersCsv() {
    const context = state.cnaeBuyerContext;
    if (!context || !context.code || !state.drawerTerritoryRow) {
      showFeedback('Clique em um CNAE antes de exportar compradores.', 'warning');
      return;
    }

    const params = buildTerritoryParams(state.drawerTerritoryRow);
    params.set('cnae', context.code);
    params.set('cnae_description', context.description || '');
    params.set('document_type', state.cnaeBuyerDocumentType || 'all');
    params.set('territory_label', getApiNeighborhoodName(state.drawerTerritoryRow) || state.drawerTerritoryRow.neighborhood || state.drawerTerritoryRow.city || '');
    params.set('limit', '2000');

    showFeedback('Gerando CSV de compradores do CNAE...', 'info');
    try {
      const response = await fetch(`api/geo_territory_cnae_buyers_export.php?${params.toString()}`, {
        credentials: 'same-origin',
        cache: 'no-store',
      });
      if (!response.ok) {
        let message = 'Nao foi possivel exportar o CSV.';
        try {
          const payload = await response.json();
          message = payload.message || payload.error || message;
        } catch (error) {
          const text = await response.text();
          if (text) message = text.slice(0, 160);
        }
        throw new Error(message);
      }

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = exportFilenameFromDisposition(response.headers.get('Content-Disposition')) || `biomie-compradores-cnae-${context.code}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      showFeedback('CSV de compradores do CNAE exportado.', 'info');
    } catch (error) {
      showFeedback(error?.message || 'Erro ao exportar compradores do CNAE.', 'warning');
    }
  }

  function bindCnaeBuyerTools() {
    $('cnaeBuyerTools')?.addEventListener('click', handleCnaeBuyerDocumentTypeClick);
    $('geoExportCnaeBuyersCsvBtn')?.addEventListener('click', exportSelectedCnaeBuyersCsv);
  }

  function handleCnaeDrilldownClick(event) {
    const trigger = event.target.closest('[data-geo-cnae-code]');
    if (!trigger) return;
    event.preventDefault();
    loadBuyersForSelectedCnae(
      trigger.getAttribute('data-geo-cnae-code') || '',
      trigger.getAttribute('data-geo-cnae-description') || '',
      'all'
    );
  }

  function handleCnaeDrilldownKeydown(event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const trigger = event.target.closest('[data-geo-cnae-code]');
    if (!trigger) return;
    event.preventDefault();
    loadBuyersForSelectedCnae(
      trigger.getAttribute('data-geo-cnae-code') || '',
      trigger.getAttribute('data-geo-cnae-description') || '',
      'all'
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GEO.R1.7 — Mapa Composto Inteligente do Ceará
  // ─────────────────────────────────────────────────────────────────────────

  // State extra para modo composto
  state.compositeSalesIndex = new Map();
  state.compositeScales     = {};
  state.compositeGeoJson    = null;

  // GEO.R1.7 — carrega GeoJSON com cache persistente entre switches
  async function cachedFetchGeoJson(url) {
    if (_geoJsonCache[url]) return _geoJsonCache[url];
    const resp = await fetch(url, { cache: 'force-cache' });
    if (!resp.ok) throw new Error(`GeoJSON HTTP ${resp.status}: ${url}`);
    const data = await resp.json();
    _geoJsonCache[url] = data;
    return data;
  }

  // GEO.R1.7 — carrega os 3 GeoJSONs em paralelo e monta GeoJSON unificado
  async function loadCompositeGeometries() {
    if (state.compositeGeoJson) return state.compositeGeoJson;

    const [fortaleza, metro, ceara] = await Promise.all([
      cachedFetchGeoJson('assets/geo/fortaleza_bairros.json'),
      cachedFetchGeoJson('assets/geo/rmf_bairros_ibge_2022.geojson'),
      cachedFetchGeoJson('assets/geo/ceara_municipios_oficial.geojson'),
    ]);

    const features = [];

    // 1. Ceará municípios (base — mais baixo na pilha)
    for (const f of (ceara.features || [])) {
      const props = f.properties || {};
      const code  = String(props.codigo_ibge || props.municipio_ibge || '');
      if (!code) continue;
      if (code === '2304400') continue;           // Fortaleza — tem bairros próprios
      if (RMF_WITH_BAIRROS_CODES.has(code)) continue; // tem bairros metro
      const type = RMF_ALL_CODES.has(code) ? 'rmf_municipio_fallback' : 'ceara_municipio';
      features.push({ ...f, properties: { ...props, territory_type: type, layer_id: 'ceara_municipios' } });
    }

    // 2. Bairros metropolitanos (acima dos municípios)
    for (const f of (metro.features || [])) {
      const props = f.properties || {};
      const code = String(props.codigo_ibge || props.municipio_ibge || props.CD_MUN || props.codarea || '');
      if (!RMF_WITH_BAIRROS_CODES.has(code)) continue;
      props.territory_type = 'metro_bairro';
      features.push({ ...f, properties: { ...props } });
    }

    // 3. Bairros de Fortaleza (mais alto)
    for (const f of (fortaleza.features || [])) {
      const props = f.properties || {};
      props.territory_type = 'fortaleza_bairro';
      props.layer_id       = 'fortaleza_bairros';
      features.push({ ...f, properties: { ...props } });
    }

    state.compositeGeoJson = { type: 'FeatureCollection', features };
    return state.compositeGeoJson;
  }

  // GEO.R1.7 — carrega dados de vendas do endpoint composto
  async function loadCompositeSalesData() {
    const params = currentFilters();
    const endpoint = geoSinglePayloadEnabled() ? 'api/geo_map_payload.php' : 'api/geo_composite_sales.php';
    const data = await apiFetch(endpoint, params, 'composite');
    if (endpoint === 'api/geo_map_payload.php') {
      const payloadData = data && typeof data === 'object' ? (data.data || {}) : {};
      return {
        ok: data.ok !== false,
        layer: 'mapa_composto_ceara',
        date_from: params.get('date_from') || '',
        date_to: params.get('date_to') || '',
        summary: {
          total_vendido: Number(payloadData.summary?.revenue || 0),
          territorios_com_venda: Array.isArray(payloadData.ranking) ? payloadData.ranking.length : 0,
          clientes: Number(payloadData.summary?.customers || 0),
          notas: Number(payloadData.summary?.invoices || 0),
          sem_bairro: Number(payloadData.summary?.rows_without_neighborhood || 0),
          sem_bairro_faturamento: Number(payloadData.summary?.revenue_without_neighborhood || payloadData.summary?.revenue_without_city || 0),
        },
        territories: payloadData.territories || {},
        scales: payloadData.scales || {},
        ranking: payloadData.ranking || [],
        top_buyers: payloadData.top_buyers || [],
        top_cnaes: payloadData.top_cnaes || [],
        meta: data.meta || {},
        warnings: data.warnings || [],
        source: data.source || 'gold',
        source_contract: data.source_contract || data.meta?.source?.contract || 'geo.map_payload.v1',
      };
    }
    return data;
  }

  // GEO.R1.7 — estilo de feature no mapa composto
  function styleCompositeFeature(feature) {
    const props   = feature?.properties || {};
    const type    = props.territory_type || 'ceara_municipio';
    const joinKey = normalizeNeighborhoodKey(getFeatureCompositeJoinKey(feature));
    const row     = state.compositeSalesIndex.get(joinKey);
    const metricValue = row ? getRowMetric(row) : 0;
    const fillColor = getCompositeColor(type, metricValue);
    const selected  = joinKey && joinKey === state.selectedKey;
    const weight    = type === 'fortaleza_bairro' ? 1.2
                    : type === 'metro_bairro' ? 1.0
                    : 0.7;
    return {
      fillColor,
      weight: selected ? 3 : weight,
      opacity: 1,
      color: selected ? COMPANY_STROKE : '#ffffff',
      fillOpacity: metricValue > 0 ? (selected ? 0.92 : 0.76) : 0.18,
    };
  }

  // GEO.R1.7 — inicializa o mapa composto completo
  async function initCompositeMap() {
    initMap();
    setMapBusy(true);
    hideState();
    showFeedback('', '');

    try {
      const [geoData, salesPayload] = await Promise.all([
        loadCompositeGeometries(),
        loadCompositeSalesData(),
      ]);

      // Indexa vendas por join_key normalizado
      state.compositeSalesIndex = new Map();
      const territories = salesPayload.territories || {};
      for (const [key, data] of Object.entries(territories)) {
        state.compositeSalesIndex.set(normalizeNeighborhoodKey(key), data);
      }

      // GEO.R2.3 — Injeta metadados GeoJSON (area_km2, centroid, bounds, regiao, pop) nas rows
      for (const feature of (geoData.features || [])) {
        const jk = normalizeNeighborhoodKey(getFeatureCompositeJoinKey(feature));
        if (!jk || !state.compositeSalesIndex.has(jk)) continue;
        const entry = state.compositeSalesIndex.get(jk);
        const fp = feature.properties || {};
        if (fp.area_km2 != null)               entry.area_km2                 = fp.area_km2;
        if (fp.centroid)                        entry.centroid                 = fp.centroid;
        if (fp.bounds)                          entry.bounds                   = fp.bounds;
        if (fp.regiao_planejamento_nome)        entry.regiao_planejamento_nome = fp.regiao_planejamento_nome;
        if (fp.regiao_planejamento_norm)        entry.regiao_planejamento_norm = fp.regiao_planejamento_norm;
        if (fp.regional_administrativa)        entry.regional_administrativa  = fp.regional_administrativa;
        if (fp.meso_regiao_nome)               entry.meso_regiao_nome         = fp.meso_regiao_nome;
        if (fp.populacao != null)              entry.populacao                = fp.populacao;
      }

      state.compositeScales = salesPayload.scales || {};
      state.compositeRows = Array.from(state.compositeSalesIndex.values());
      recomputeMetricScale();

      // Renderiza camada unificada
      if (state.geoLayer && state.map) {
        state.map.removeLayer(state.geoLayer);
        state.geoLayer = null;
      }

      const features = geoData.features || [];
      let paintedFeatures = 0;
      state.geoLayer = window.L.geoJson(geoData, {
        smoothFactor: 1.6, // perf: simplifica polígonos no render (modo composto multiescala)
        style: (feature) => {
          const style = styleCompositeFeature(feature);
          const jk = normalizeNeighborhoodKey(getFeatureCompositeJoinKey(feature));
          if (state.compositeSalesIndex.has(jk) && (state.compositeSalesIndex.get(jk)?.revenue || 0) > 0) paintedFeatures++;
          return style;
        },
        onEachFeature(feature, layer) {
          layer.unbindTooltip();
          layer.bindTooltip(tooltipHtml(feature), { sticky: true, direction: 'top' });
          layer.off();
          layer.on({
            mouseover(e) { e.target.setStyle({ weight: 2.6, color: '#24364f', fillOpacity: 0.92 }); e.target.bringToFront?.(); },
            mouseout(e)  { e.target.setStyle(currentModeStyle(feature, styleCompositeFeature)); },
            click(e) {
              const props = feature.properties || {};
              const jk    = getFeatureCompositeJoinKey(feature);
              // GEO.R1.7 — usa município real da feature, nunca cityFilter
              const muniNome = props.municipio_nome || props.city || $('cityFilter')?.value || 'Fortaleza';
              const cityKey  = props.cidade_key || normalizeNeighborhoodKey(muniNome);
              const bairroKey = props.bairro_key || props.neighborhood_key || normalizeNeighborhoodKey(
                props.bairro_nome || getFeatureNeighborhoodName(feature) || ''
              );
              const displayKey = jk || bairroKey || cityKey;
              state.selectedKey = normalizeNeighborhoodKey(displayKey);
              state.geoLayer?.eachLayer(l => l.setStyle(activeFeatureStyle(l.feature)));
              updateRankingActiveRow();
              // Monta row de dados para o painel
              const salesData = state.compositeSalesIndex.get(normalizeNeighborhoodKey(jk)) || {};
              const row = {
                bairro_key:     bairroKey,
                city_key:       cityKey,
                city:           muniNome,
                neighborhood:   props.bairro_nome || props.municipio_nome || '',
                bairro_nome:    props.bairro_nome || '',
                municipio_nome: muniNome,
                municipio_ibge: props.municipio_ibge || props.codigo_ibge || '',
                territory_type: props.territory_type || salesData.territory_type || '',
                revenue:        salesData.revenue || 0,
                customers:      salesData.customers || 0,
                invoices:       salesData.invoices  || 0,
                average_ticket: salesData.ticket_medio || 0,
                // GEO.R2.3 — metadados geográficos injetados do GeoJSON
                area_km2:                 salesData.area_km2,
                regiao_planejamento_nome: salesData.regiao_planejamento_nome,
                regiao_planejamento_norm: salesData.regiao_planejamento_norm,
                regional_administrativa:  salesData.regional_administrativa,
                meso_regiao_nome:         salesData.meso_regiao_nome,
                populacao:                salesData.populacao,
              };
              // GEO.R2.3 — fitBounds via bounds pré-computado (evita getBounds() na geometria)
              const fBounds = salesData.bounds || props.bounds;
              if (fBounds && state.map) {
                const ttype = props.territory_type || '';
                const maxZoom = ttype === 'fortaleza_bairro' ? 16 : ttype === 'metro_bairro' ? 15 : 13;
                state.map.fitBounds(
                  [[fBounds[0], fBounds[1]], [fBounds[2], fBounds[3]]],
                  { maxZoom, padding: [30, 50], animate: true }
                );
              }
              openNeighborhoodDrawer(e.target);
              loadPanelData(row);
            },
          });
        },
      }).addTo(state.map);

      state._paintedFeatures = paintedFeatures;
      if (!state._mapFitted && state.geoLayer.getBounds().isValid()) {
        state.map.fitBounds(state.geoLayer.getBounds(), { padding: [18, 18], maxZoom: 8 });
        state._mapFitted = true;
      }
      window.requestAnimationFrame(() => state.map.invalidateSize({ pan: false }));
      // GEO.R1.8.6 — inicializa Leaflet.draw após mapa composto pronto
      // Renderiza KPIs, ranking, legenda composta
      const summary = salesPayload.summary || {};
      setText('geoKpiRevenue',  formatters.money(summary.total_vendido || 0));
      setText('geoKpiAreas',    formatters.number(summary.territorios_com_venda || 0));
      setText('geoKpiClients',  formatters.number(summary.clientes || 0));
      setText('geoKpiNotes',    formatters.number(summary.notas || 0));
      setText('geoKpiNoArea',   formatters.number(summary.sem_bairro || 0));
      updateCoverageKpi(
        summary.total_vendido || 0,
        summary.sem_bairro_faturamento || 0,
        summary.notas || 0,
        summary.sem_bairro || 0
      );
      // GEO.R1.9.2 — exclude sem_cidade from best territory
      const metricRanking = sortRowsForActiveMetric(salesPayload.ranking || []);
      const topRanking = metricRanking.find(r => getRowMetric(r) > 0 && r.cidade_key !== 'sem_cidade' && r.join_key !== 'sem_cidade');
      setText('geoKpiTopArea', topRanking
        ? (topRanking.bairro_norm ? `${topRanking.bairro_norm} — ${topRanking.cidade_key}` : topRanking.cidade_key)
        : 'Sem território com venda');

      // GEO.R1.9.3 — cache ranking for presets
      state.compositeRankingCache = salesPayload.ranking || [];
      state.summary = {
        revenue: Number(summary.total_vendido || 0),
        customers: Number(summary.clientes || 0),
        invoices: Number(summary.notas || 0),
        rows_without_neighborhood: Number(summary.sem_bairro || 0),
      };
      // GEO.R1.9.6 — build rank lookup Map for rich tooltip
      state._rankByJoinKey = new Map(
        (salesPayload.ranking || []).map((r, i) => [normalizeNeighborhoodKey(r.join_key || r.cidade_key || ''), i + 1])
      );
      renderMapModeLegend();
      renderCompositeRanking(salesPayload.ranking || []);
      updateMapContext();
      // GEO.R1.8.1 — auto-insights
      if (GEO_FEATURES.insights) {
        renderInsights(generateInsights(salesPayload.ranking || [], summary));
      }
      // GEO.R1.9 — update sem-bairro card in composite mode
      const semBairroNfs = summary.sem_bairro || 0;
      if (semBairroNfs > 0) {
        showEnrichCard(semBairroNfs, 0, summary.total_vendido || 0);
      }

      // GEO.R1.9 — smart labels + bind zoom for label refresh
      GeoSmartLabels.buildRankMap(salesPayload.ranking || []);
      GeoSmartLabels.applyLabels(state.map, state.geoLayer);
      // Pass a proxy that always reads state.geoLayer at zoom time
      GeoSmartLabels.bindZoom(state.map, { get current() { return state.geoLayer; } });
      if (state._mapMode && state._mapMode !== 'territories') await applyMapMode(state._mapMode);

    } catch (error) {
      if (error.name === 'AbortError') return;
      showState('danger', 'Não foi possível carregar o mapa composto.', error.message || 'Tente novamente.', 'Tentar novamente');
      updateDiagnostics({ mapState: 'erro', error: 'composite_map_error' });
    } finally {
      setMapBusy(false);
    }
  }

  // GEO.R1.9.3 — legenda dinâmica por modo de mapa
  function renderMapModeLegend() {
    const node = $('mapLegend');
    if (!node) return;
    const mode = state._mapMode || 'territories';
    const metric = activeMetricDef();
    const metricMax = Number(state.metricMax || state.compositeMetricMax || 0);
    const header = (title, subtitle) => `
      <span class="biomie-composite-legend__title">${escapeHtml(title)}</span>
      <span class="biomie-map-legend__subtitle">${escapeHtml(subtitle)}</span>
    `;
    if (mode === 'bubbles' || mode === 'hybrid') {
      node.innerHTML = `
        ${header(`Bolhas por ${metric.shortLabel}`, 'O tamanho representa o resultado do territorio')}
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__bubble" style="background:#3b82f6;"></span>Bairro Fortaleza</span>
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__bubble" style="background:#22c55e;"></span>Grande Fortaleza</span>
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__bubble" style="background:#8b5cf6;"></span>Município RMF</span>
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__bubble" style="background:#f59e0b;"></span>Município Ceará</span>
      `;
    } else if (mode === 'heat') {
      node.innerHTML = `
        ${header('Concentracao aproximada', `Centros dos territorios ponderados por ${metric.shortLabel.toLowerCase()}`)}
        <span class="biomie-map-legend__item">
          <span class="biomie-map-legend__gradient biomie-map-legend__gradient--blue"></span>
          Baixo → Alto
        </span>
      `;
    } else if (mode === 'quality') {
      node.innerHTML = `
        ${header('Cobertura territorial', 'Cadastros sem localizacao aparecem no indicador, pois nao possuem poligono')}
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__swatch" style="background:#16a34a;"></span>Resultado localizado</span>
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__swatch" style="background:#e8edf4;"></span>Sem resultado no periodo</span>
      `;
    } else if (mode === 'opportunities') {
      node.innerHTML = `
        ${header('Potencial comercial relativo', 'Mais clientes ativos com menor faturamento indicam espaco para crescer')}
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__gradient biomie-map-legend__gradient--opportunity"></span>Menor → Maior potencial</span>
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__swatch" style="background:#e8edf4;"></span>Sem base para calcular</span>
      `;
    } else {
      node.innerHTML = `
        ${header(metric.label, `Escala unica e comparavel · maximo ${metric.compact(metricMax)}`)}
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__gradient biomie-map-legend__gradient--blue"></span>Menor → Maior resultado</span>
        <span class="biomie-map-legend__item"><span class="biomie-map-legend__swatch" style="background:#e8edf4;"></span>Sem resultado</span>
        ${_activeLayer === 'mapa_composto_ceara' ? '<span class="biomie-map-legend__notice">Visao multiescala: bairros e municipios nao devem ser comparados diretamente.</span>' : ''}
      `;
    }
  }

  // GEO.R1.7 compat alias
  function renderCompositeLegend() { renderMapModeLegend(); }

  function focusFeatureByKey(key, openDetails) {
    const normalized = normalizeNeighborhoodKey(key);
    let found = null;
    state.geoLayer?.eachLayer((layer) => {
      if (found || !layer.feature) return;
      const featureKey = _activeLayer === 'mapa_composto_ceara'
        ? normalizeNeighborhoodKey(getFeatureCompositeJoinKey(layer.feature))
        : getFeatureLookupKeys(layer.feature).find((candidate) => candidate === normalized);
      if (featureKey === normalized) found = layer;
    });
    if (!found) return false;
    const bounds = found.getBounds?.();
    if (bounds?.isValid?.()) state.map?.fitBounds(bounds, { padding: [48, 48], maxZoom: 12 });
    if (openDetails) found.fire?.('click');
    return true;
  }

  // GEO.R1.7 — ranking territorial composto com badge de tipo
  function renderCompositeRanking(ranking) {
    const list = $('scopeRankingTable');
    const countNode = $('geoRankingCount');
    if (!list) return;
    if (!ranking.length) {
      if (countNode) countNode.textContent = '0 territórios';
      list.innerHTML = '<div class="geo-ranking-empty">Sem territórios com venda no filtro.</div>';
      return;
    }

    const TERRITORY_LABELS = {
      fortaleza_bairro:       'Bairro Fortaleza',
      metro_bairro:           'Bairro RMF',
      rmf_municipio_fallback: 'Município RMF',
      ceara_municipio:        'Município CE',
    };
    const TERRITORY_BADGE_COLORS = {
      fortaleza_bairro:       '#1D4ED8',
      metro_bairro:           '#0F766E',
      rmf_municipio_fallback: '#6D28D9',
      ceara_municipio:        '#B45309',
    };

    const query = String($('geoRankingSearch')?.value || '').trim().toLowerCase();
    const sort = $('geoRankingSort')?.value || activeMetricDef().sort;
    const visible = ranking.filter(r => {
      if (!query) return true;
      const s = `${r.bairro_norm || ''} ${r.cidade_key || ''}`.toLowerCase();
      return s.includes(query);
    }).slice();
    visible.sort((a, b) => {
      if (sort === 'name_asc') {
        return String(a.bairro_norm || a.cidade_key || '').localeCompare(String(b.bairro_norm || b.cidade_key || ''), 'pt-BR');
      }
      const metricBySort = sort === 'customers_desc' ? 'clientes'
        : sort === 'invoices_desc' ? 'nfs'
        : sort === 'ticket_desc' ? 'ticket_medio'
        : activeMetricKey();
      return getRowMetric(b, metricBySort) - getRowMetric(a, metricBySort) || getRowRevenue(b) - getRowRevenue(a);
    });
    if (countNode) countNode.textContent = `${formatters.number(visible.length)} de ${formatters.number(ranking.length)} territórios`;

    const maxMetric = Math.max(...visible.map(r => getRowMetric(r)), 1);
    list.innerHTML = visible.map((row, i) => {
      const metricValue = getRowMetric(row);
      const barPct  = Math.round((metricValue / maxMetric) * 100);
      const type    = row.territory_type || 'ceara_municipio';
      const badge   = TERRITORY_LABELS[type] || type;
      const color   = TERRITORY_BADGE_COLORS[type] || '#6B7280';
      const name    = row.bairro_norm
        ? `${row.bairro_norm} — ${row.cidade_key}`
        : row.cidade_key;
      const jk = normalizeNeighborhoodKey(row.join_key || name);
      const active = state.selectedKey && state.selectedKey === jk;
      return `
        <button type="button" class="geo-row-clickable geo-ranking-row${active ? ' geo-ranking-row--active' : ''}" role="option" data-key="${escapeHtml(jk)}" data-label="${escapeHtml(name)}" data-city-key="${escapeHtml(row.cidade_key || '')}" aria-selected="${active ? 'true' : 'false'}">
          <span class="geo-ranking-rank">${i + 1}</span>
          <span class="geo-ranking-main">
            <span class="geo-ranking-name">${escapeHtml(name)}</span>
            <span class="geo-ranking-meta" style="display:flex;gap:6px;align-items:center;">
              <span style="display:inline-block;padding:1px 5px;border-radius:4px;font-size:10px;font-weight:600;color:#fff;background:${color}">${escapeHtml(badge)}</span>
              ${formatters.number(row.invoices || 0)} NF
            </span>
            <span class="geo-ranking-bar" aria-hidden="true"><span style="width:${barPct}%;"></span></span>
          </span>
          <span class="geo-ranking-value">${metricValue > 0 ? escapeHtml(formatActiveMetric(row, false)) : '-'}</span>
        </button>
      `;
    }).join('');

    list.onclick = function(e) {
      const item = e.target.closest('[data-key]');
      if (!item) return;
      state.selectedKey = item.dataset.key;
      state.geoLayer?.eachLayer(l => l.setStyle(activeFeatureStyle(l.feature)));
      updateRankingActiveRow();
      focusFeatureByKey(item.dataset.key, true);
    };
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GEO.R1.8.1 — UX gerencial: chips, mode, insights, feature flags, export
  // ─────────────────────────────────────────────────────────────────────────

  // Feature flags — habilita modos quando implementados
  const GEO_FEATURES = {
    heatmap:       true,   // GEO.R1.8.5 — calor de vendas (centroids from loaded GeoJSON)
    clusters:      false,  // GEO.R1.8.5 — cluster de clientes (Leaflet.markercluster)
    areaSelection: true,   // GEO.R1.8.6 — seleção manual de área (Leaflet.draw)
    comparison:    true,   // GEO.R1.8.6 — comparar territórios (client-side, max 4)
    smartSearch:   true,   // GEO.R1.8 — busca inteligente no ranking
    insights:      true,   // GEO.R1.8.1 — insights automáticos
    exportCsv:     true,   // GEO.R1.8 — exportar ranking CSV
    timeline:      false,  // GEO.R1.8 — animação temporal
  };

  let _activeVisualMode = 'territories';

  // Sincroniza chips de foco com a camada ativa
  function syncFocusChips(layerKey) {
    document.querySelectorAll('.geo-focus-chip').forEach((chip) => {
      const active = chip.dataset.layer === layerKey;
      chip.classList.toggle('geo-focus-chip--active', active);
      chip.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  // Sincroniza chips de modo visual
  function syncModeChips(mode) {
    document.querySelectorAll('.geo-mode-chip').forEach((chip) => {
      const active = chip.dataset.mode === mode;
      chip.classList.toggle('geo-mode-chip--active', active);
      chip.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    _activeVisualMode = mode;
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GEO.R1.8.5 — Heatmap de vendas a partir dos centroides do GeoJSON carregado
  // ─────────────────────────────────────────────────────────────────────────

  // Compute polygon centroid (simple average of coordinates)
  function polygonCentroid(geometry) {
    try {
      const coords = geometry.type === 'Polygon'
        ? geometry.coordinates[0]
        : geometry.type === 'MultiPolygon'
          ? geometry.coordinates[0][0]
          : null;
      if (!coords || !coords.length) return null;
      const sum = coords.reduce((acc, c) => [acc[0] + c[0], acc[1] + c[1]], [0, 0]);
      return [sum[1] / coords.length, sum[0] / coords.length]; // [lat, lng]
    } catch {
      return null;
    }
  }

  let _heatLayer = null;

  async function enableHeatmapMode() {
    if (!state.map || !GEO_FEATURES.heatmap) return;
    if (typeof window.L?.heatLayer !== 'function') {
      showFeedback('Preparando o mapa de calor...', 'info');
      try {
        await window.BIomieGeoAdvancedAssets?.loadHeat?.();
      } catch (error) {
        showFeedback('Não foi possível abrir o mapa de calor agora.', 'warning');
        syncModeChips('territories');
        return;
      }
    }
    disableHeatmapMode();

    // Dim the polygon layer
    state.geoLayer?.setStyle({ fillOpacity: 0.08, opacity: 0.25 });

    const geoData   = _activeLayer === 'mapa_composto_ceara' ? state.compositeGeoJson : state.geoJson;
    const salesIdx  = _activeLayer === 'mapa_composto_ceara' ? state.compositeSalesIndex : state.revenueByNeighborhoodKey;
    const features  = geoData?.features || [];
    const heatPoints = [];

    features.forEach(f => {
      const centroid = polygonCentroid(f.geometry);
      if (!centroid) return;
      const jk  = _activeLayer === 'mapa_composto_ceara'
        ? normalizeNeighborhoodKey(getFeatureCompositeJoinKey(f))
        : getFeatureLookupKeys(f).find((candidate) => salesIdx.has(candidate));
      const row = salesIdx.get(jk);
      const metricValue = row ? getRowMetric(row) : 0;
      if (metricValue > 0) heatPoints.push([centroid[0], centroid[1], metricValue]);
    });

    if (!heatPoints.length) {
      showFeedback('Sem dados de vendas para o modo calor no período selecionado.', 'info');
      syncModeChips('territories');
      state.geoLayer?.setStyle && state.geoLayer.eachLayer(l => l.setStyle(featureStyle(l.feature)));
      return;
    }

    _heatLayer = window.L.heatLayer(heatPoints, {
      radius: 35,
      blur: 20,
      maxZoom: 13,
      gradient: { 0.3: '#DBEAFE', 0.6: '#60A5FA', 1.0: '#1D4ED8' },
    }).addTo(state.map);
  }

  function disableHeatmapMode() {
    if (_heatLayer && state.map) {
      state.map.removeLayer(_heatLayer);
      _heatLayer = null;
    }
    // Restore polygon opacity
    if (state.geoLayer && _activeLayer === 'mapa_composto_ceara') {
      state.geoLayer.eachLayer(l => l.setStyle(styleCompositeFeature(l.feature)));
    } else if (state.geoLayer) {
      state.geoLayer.eachLayer(l => l.setStyle(featureStyle(l.feature)));
    }
  }

  // Gera insights a partir dos dados do mapa composto
  function generateInsights(ranking, summary) {
    const insights = [];
    const rankArr = Array.isArray(ranking) ? ranking : [];

    if (!rankArr.length) {
      insights.push({ icon: 'info', type: 'info', title: 'Sem vendas no período', msg: 'Selecione um período com movimentação para ver insights territoriais.' });
      return insights;
    }

    // Melhor território
    const best = rankArr[0];
    if (best) {
      const name = best.bairro_norm ? `${best.bairro_norm} — ${best.cidade_key}` : best.cidade_key;
      insights.push({ icon: 'workspace_premium', type: 'positive', title: `Melhor: ${name}`, msg: `Faturamento de ${formatters.compactMoney(best.revenue || 0)} no período.` });
    }

    // Top 3 tipos de território com venda
    const typeCount = {};
    rankArr.forEach(r => { typeCount[r.territory_type || 'outro'] = (typeCount[r.territory_type || 'outro'] || 0) + 1; });
    const dominantType = Object.entries(typeCount).sort((a, b) => b[1] - a[1])[0];
    if (dominantType) {
      const TIPO_LABELS = { fortaleza_bairro: 'Fortaleza por bairros', metro_bairro: 'Grande Fortaleza por bairros', rmf_municipio_fallback: 'municípios RMF', ceara_municipio: 'municípios do Ceará' };
      insights.push({ icon: 'pin_drop', type: 'info', title: `${dominantType[1]} territórios ativos (${TIPO_LABELS[dominantType[0]] || dominantType[0]})`, msg: 'Concentração de venda no período.' });
    }

    // Sem bairro
    const semBairro = Number(summary?.sem_bairro || 0);
    if (semBairro > 0) {
      insights.push({ icon: 'location_off', type: 'warning', title: `${formatters.number(semBairro)} NFs sem bairro`, msg: 'Corrija o cadastro Omie para aumentar cobertura geográfica.' });
    }

    // Concentração: top 3 > 50% do total
    const total = Number(summary?.total_vendido || 0);
    if (total > 0 && rankArr.length >= 3) {
      const top3Rev = rankArr.slice(0, 3).reduce((s, r) => s + Number(r.revenue || 0), 0);
      if (top3Rev / total > 0.5) {
        insights.push({ icon: 'warning', type: 'warning', title: 'Alta concentração territorial', msg: `Top 3 territórios respondem por ${(top3Rev / total * 100).toFixed(0)}% do faturamento.` });
      }
    }

    return insights;
  }

  function renderInsights(insights) {
    const list = $('geoInsightsList');
    const card = $('geoInsightsCard');
    if (!list) return;
    if (!GEO_FEATURES.insights) { if (card) card.style.display = 'none'; return; }
    if (!insights || !insights.length) { if (card) card.style.display = 'none'; return; }
    if (card) card.style.display = '';
    list.innerHTML = insights.slice(0, 5).map(ins => `
      <div class="geo-insight-item geo-insight-item--${escapeHtml(ins.type || 'info')}">
        <span class="material-symbols-rounded geo-insight-icon">${escapeHtml(ins.icon || 'info')}</span>
        <div class="geo-insight-body">
          <strong class="geo-insight-title">${escapeHtml(ins.title)}</strong>
          <p class="geo-insight-msg">${escapeHtml(ins.msg)}</p>
        </div>
      </div>
    `).join('');
  }

  // Exportar ranking como CSV
  function exportRankingCsv() {
    if (!GEO_FEATURES.exportCsv) return;
    const rows = document.querySelectorAll('#scopeRankingTable [data-key]');
    if (!rows.length) { showFeedback('Sem dados no ranking para exportar.', 'info'); return; }

    const params = currentFilters();
    const period = `${params.get('date_from') || ''} a ${params.get('date_to') || ''}`;
    const header = ['#', 'Território', 'Tipo', 'Faturamento (R$)', 'Período'];
    const lines = [header.join(';')];

    rows.forEach((row, i) => {
      const label = row.querySelector('.geo-ranking-name')?.textContent?.trim() || '';
      const value = row.querySelector('.geo-ranking-value')?.textContent?.trim() || '';
      const badge = row.querySelector('[style*="background"]')?.textContent?.trim() || '';
      lines.push([i + 1, label, badge, value.replace(/R\$\s*/g, '').trim(), period].join(';'));
    });

    const csv = '﻿' + lines.join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `biomie-ranking-territorial-${params.get('date_from') || 'periodo'}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  // Bind dos chips de foco
  // ── GEO.R1.9.3 — Presets executivos ─────────────────────────────────────────

  function applyPreset(presetId) {
    document.querySelectorAll('.geo-preset-btn').forEach(b => b.classList.remove('geo-preset-btn--active'));
    document.querySelector(`.geo-preset-btn[data-preset="${presetId}"]`)?.classList.add('geo-preset-btn--active');

    const sortEl = $('geoRankingSort');

    if (presetId === 'top_revenue') {
      applyMapMode('territories');
      if (sortEl) { sortEl.value = 'revenue_desc'; renderCompositeRanking(state.compositeRankingCache || []); }
      showFeedback('Mostrando territórios com maior faturamento.', 'info');
    } else if (presetId === 'sem_bairro') {
      applyMapMode('quality');
      if (sortEl) sortEl.value = 'revenue_desc';
      showFeedback('Modo qualidade: territórios sem bairro ou sem cidade destacados.', 'warning');
    } else if (presetId === 'opportunity') {
      applyMapMode('opportunities');
      showFeedback('Modo oportunidades: verde = território ativo, âmbar = clientes sem venda.', 'info');
    } else if (presetId === 'cnae_view') {
      applyMapMode('territories');
      showFeedback('Abra um território e clique na aba CNAEs para ver o ranking por segmento.', 'info');
      $('geoInsightsCard')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  function bindPresets() {
    const bar = document.getElementById('geoPresetsBar');
    if (!bar) return;
    bar.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-preset]');
      if (btn) applyPreset(btn.dataset.preset);
    });
  }

  function bindFocusChips() {
    const chips = document.getElementById('geoFocusChips');
    if (!chips) return;
    chips.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-layer]');
      if (!chip) return;
      const layer = chip.dataset.layer;
      if (!GEO_LAYERS[layer]) return;
      // Sync chip UI
      syncFocusChips(layer);
      // Sync the select (keep in sync for fallback/power-users)
      const sel = $('geoLayerSelector');
      if (sel) sel.value = layer;
      // Trigger layer change
      _activeLayer = layer;
      try {
        const up = new URLSearchParams(window.location.search);
        up.set('layer', layer);
        history.replaceState(null, '', '?' + up.toString());
      } catch(err) {}
      applyLayerLabels(layer);
      state.selectedKey = '';
      state._mapFitted = false;
      if (state.geoLayer && state.map) { state.map.removeLayer(state.geoLayer); state.geoLayer = null; }
      state.geoJson = null; state.rows = []; state.rowsByKey.clear(); state.revenueByNeighborhoodKey.clear();
      loadCommercialData();
    });
  }

  // Bind dos chips de modo visual
  function bindModeChips() {
    const chips = document.getElementById('geoModeChips');
    if (!chips) return;

    chips.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-mode]');
      if (!chip || chip.disabled) return;
      const mode = chip.dataset.mode;
      if (mode === 'comparison') {
        openComparisonPanel();
        return;
      }
      applyMapMode(mode);
      if (mode === 'territories') {
        showFeedback('', '');
      } else if (mode === 'quality') {
        const semBairro = Number(state.summary?.rows_without_neighborhood || 0);
        if (semBairro > 0) {
          showFeedback(`${formatters.number(semBairro)} registros aguardam confirmação de localização.`, 'info');
        } else {
          showFeedback('Todos os registros do período possuem localização confirmada.', 'info');
        }
      }
    });
  }

  // Bind do botão de exportar CSV
  function bindExportCsv() {
    $('geoExportCsvBtn')?.addEventListener('click', exportRankingCsv);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GEO.R1.8.2 — Territory 360: tab switching + evolution + actions
  // ─────────────────────────────────────────────────────────────────────────

  // Current territory row held for tab reloads
  state.drawerTerritoryRow = null;

  function activateDrawerTab(tabName) {
    const tabs = $('geoDrawerTabs');
    if (!tabs) return;
    const requestedTab = tabs.querySelector(`[data-tab="${tabName}"]`);
    if (requestedTab?.disabled) tabName = 'resumo';

    // Update tab button active state
    tabs.querySelectorAll('.geo-drawer-tab').forEach(t => {
      const active = t.dataset.tab === tabName;
      t.classList.toggle('geo-drawer-tab--active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    // Show/hide panels by data-panel attribute
    const body = $('geoNeighborhoodDrawer')?.querySelector('.geo-drawer__body');
    if (!body) return;
    body.querySelectorAll('[data-panel]').forEach(panel => {
      const panelTabs = panel.getAttribute('data-panel').split(' ');
      panel.hidden = !panelTabs.includes(tabName);
    });

    // Lazy-load tabs on first activation
    if (tabName === 'evolucao' && state.drawerTerritoryRow) {
      loadEvolutionData(state.drawerTerritoryRow);
    }
    if (tabName === 'acoes' && state.drawerTerritoryRow) {
      renderTerritoryActions(state.drawerTerritoryRow);
    }
    // GEO.R1.9 — lazy-load notas fiscais
    if (tabName === 'notas' && state.drawerTerritoryRow) {
      loadTerritoryInvoices(state.drawerTerritoryRow);
    }
    // GEO.R1.9 — lazy-load CNAEs via corrected endpoint
    if (tabName === 'cnaes' && state.drawerTerritoryRow) {
      loadTerritoryCnaes(state.drawerTerritoryRow);
    }
  }

  function bindDrawerTabs() {
    const tabs = $('geoDrawerTabs');
    if (!tabs) return;
    tabs.addEventListener('click', (e) => {
      const tab = e.target.closest('[data-tab]');
      if (!tab) return;
      activateDrawerTab(tab.dataset.tab);
    });

    // "Ver ações" quick-jump button
    $('geoDrawerActionsBtn')?.addEventListener('click', () => {
      activateDrawerTab('acoes');
    });
  }

  function setDrawerTabAvailability(row) {
    const strategicRegion = row?.territory_type === 'ceara_regiao';
    const unavailableTabs = new Set(['compradores', 'notas', 'cnaes', 'evolucao']);
    $('geoDrawerTabs')?.querySelectorAll('.geo-drawer-tab').forEach((tab) => {
      const unavailable = strategicRegion && unavailableTabs.has(tab.dataset.tab);
      tab.disabled = unavailable;
      tab.title = unavailable
        ? 'Abra Ceara por municipios para consultar este detalhe com precisao.'
        : '';
    });
  }

  // Load monthly evolution data for a territory
  async function loadEvolutionData(row) {
    if (!row) return;
    const loadingEl = $('evolutionLoading');
    const chartBlock = $('evolutionChartBlock');
    const emptyEl    = $('evolutionEmpty');
    if (!chartBlock) return;

    if (loadingEl) loadingEl.style.display = '';
    if (chartBlock) chartBlock.style.display = 'none';
    if (emptyEl)    emptyEl.style.display    = 'none';

    try {
      const params = currentFilters();
      // Derive cidade_key and bairro_key from the row
      const cidadeKey = row.city_key || row.cidade_key || normalizeNeighborhoodKey(row.city || row.municipio_nome || '');
      const bairroKey = row.bairro_key || normalizeNeighborhoodKey(row.bairro_nome || row.neighborhood || '');
      const ttype     = row.territory_type || '';
      params.set('cidade_key',    cidadeKey);
      params.set('bairro_key',    bairroKey);
      params.set('territory_type', ttype);

      const resp = await fetch(`api/geo_territory_evolution.php?${params.toString()}`, { credentials: 'same-origin' });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();
      const months = data.months || [];

      if (loadingEl) loadingEl.style.display = 'none';

      if (!months.length) {
        if (emptyEl) emptyEl.style.display = '';
        return;
      }

      chartBlock.style.display = '';
      window.BIomieChartHelpers?.destroy('evolutionChartCanvas');

      const labels = months.map(m => String(m.competencia || '').slice(0, 7));
      const values = months.map(m => Number(m.revenue || 0));

      // Render bar chart using ChartHelpers if available, else simple Chart.js
      const _revenueColor = getComputedStyle(document.body || document.documentElement).getPropertyValue('--chart-revenue').trim() || '#96140a';
      if (window.BIomieChartHelpers && window.BIomieChartHelpers.renderLineOrBar) {
        window.BIomieChartHelpers.renderLineOrBar('evolutionChartCanvas', labels, values, {
          label: 'Faturamento',
          color: _revenueColor + 'b3',
          formatValue: formatters.compactMoney,
        });
      } else if (window.Chart) {
        new window.Chart(document.getElementById('evolutionChartCanvas'), {
          type: 'bar',
          data: {
            labels,
            datasets: [{ label: 'Faturamento', data: values, backgroundColor: _revenueColor + 'ae', borderRadius: 6 }],
          },
          options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
              y: { ticks: { callback: v => formatters.compactMoney(v), font: { size: 11 } } },
              x: { ticks: { font: { size: 11 } } },
            },
          },
        });
      }
    } catch (err) {
      if (loadingEl) loadingEl.textContent = 'Não foi possível carregar a evolução.';
    }
  }

  // Generate commercial action recommendations based on territory profile
  function renderTerritoryActions(row) {
    const list = $('geoDrawerActionsList');
    if (!list) return;

    const revenue   = Number(row?.revenue || 0);
    const customers = Number(row?.customers || 0);
    const ttype     = row?.territory_type || '';
    const nbName    = row?.neighborhood || row?.bairro_nome || row?.municipio_nome || 'Território';
    const actions   = [];

    if (revenue === 0) {
      actions.push({ icon: 'add_location_alt', color: '#2563eb', title: 'Criar lista de prospecção', desc: 'Nenhuma venda no período. Identifique clientes cadastrados sem compra recente.' });
      actions.push({ icon: 'route', color: '#059669', title: 'Verificar cobertura de vendedor', desc: 'Verifique se este território está atribuído a algum vendedor.' });
    } else {
      actions.push({ icon: 'repeat', color: '#059669', title: 'Reativar compradores antigos', desc: 'Ver clientes com última compra há mais de 90 dias neste território.' });
      if (customers <= 2 && revenue > 0) {
        actions.push({ icon: 'warning', color: '#d97706', title: 'Alta concentração em poucos clientes', desc: `Apenas ${customers} cliente(s) gerando toda a receita. Risco de perda de receita.` });
      }
      actions.push({ icon: 'campaign', color: '#7c3aed', title: 'Criar campanha territorial', desc: `Lance campanha focada em ${nbName} para aumentar frequência de compra.` });
    }

    if (ttype === 'fortaleza_bairro' || ttype === 'metro_bairro') {
      actions.push({ icon: 'location_city', color: '#0f766e', title: 'Enriquecer cadastro de bairro', desc: 'Corrija clientes com CEP/bairro inválido no Omie para aumentar cobertura do mapa.' });
    }

    actions.push({ icon: 'open_in_new', color: '#6b7280', title: 'Abrir Geo 360', desc: 'Ver análise completa deste território com histórico e comparações.' });

    list.innerHTML = actions.map(a => `
      <div style="display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--mag-border);">
        <span class="material-symbols-rounded" style="color:${a.color};font-size:20px;flex:0 0 auto;margin-top:2px;">${escapeHtml(a.icon)}</span>
        <div>
          <strong style="display:block;font-size:13px;color:var(--mag-text);">${escapeHtml(a.title)}</strong>
          <p style="margin:2px 0 0;font-size:12px;color:var(--mag-muted);line-height:1.4;">${escapeHtml(a.desc)}</p>
        </div>
      </div>
    `).join('') + `<div style="padding:8px 0 0;">
      <button type="button" id="actionGeo360Link" class="row-inline-link" style="font-size:12px;display:${$('geo360Btn') && $('geo360Btn').style.display !== 'none' ? 'inline-flex' : 'none'}">
        <span class="material-symbols-rounded" style="font-size:13px;vertical-align:middle;">open_in_new</span>Abrir Geo 360
      </button>
    </div>`;

    // Wire up Geo 360 link in ações panel
    $('actionGeo360Link')?.addEventListener('click', () => $('geo360Btn')?.click());
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GEO.R1.9 — Territory invoices, CNAEs fix, GeoSmartLabels
  // ─────────────────────────────────────────────────────────────────────────

  // ── Load NF list for territory ───────────────────────────────────────────

  async function loadTerritoryInvoices(row) {
    const panel = $('geoInvoicesPanel');
    if (!panel || !row) return;
    panel.innerHTML = '<p style="color:var(--mag-muted);font-size:13px;padding:8px 0;">Carregando notas fiscais…</p>';

    try {
      const params = buildTerritoryParams(row);
      params.set('per_page', '20');
      params.set('page', '1');
      const resp = await fetch(`api/geo_territory_invoices.php?${params}`, { credentials: 'same-origin' });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();
      renderInvoicesPanel(panel, data, row);
    } catch (err) {
      panel.innerHTML = `<p style="color:var(--danger,#dc2626);font-size:13px;padding:8px 0;">Erro ao carregar notas: ${escapeHtml(String(err.message || err))}</p>`;
    }
  }

  function renderInvoicesPanel(panel, data, row) {
    const items = data.items || [];
    const sum   = data.summary || {};
    const params = currentFilters();
    const df = params.get('date_from') || '';
    const dt = params.get('date_to')   || '';

    if (!items.length) {
      // GEO.R1.9.2 — Contract mismatch detection
      const rowNotas = Number(row?.invoices || row?.notas || 0);
      if (rowNotas > 0) {
        panel.innerHTML = `
            <div style="padding:16px;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;margin:8px 0;">
                <strong style="color:#92400e;">Contrato territorial não resolvido</strong>
                <p style="font-size:12px;color:#78350f;margin:4px 0 0;">Este território tem <strong>${rowNotas}</strong> nota(s) no resumo, mas a lista de NFs retornou vazia. Possível divergência entre mart e canonical. <br>Código: <code>GEO_R1_9_2_INVOICE_CONTRACT_MISMATCH</code></p>
            </div>`;
        console.warn('GEO_R1_9_2_INVOICE_CONTRACT_MISMATCH', { row, data });
      } else {
        panel.innerHTML = `<div style="padding:20px;text-align:center;color:var(--mag-muted);">
            <span class="material-symbols-rounded" style="font-size:32px;display:block;margin-bottom:6px;">receipt_long</span>
            <strong>Sem notas fiscais</strong>
            <p style="font-size:12px;margin:4px 0 0;">Nenhuma NF encontrada para este território no período.</p>
        </div>`;
      }
      return;
    }
    const summaryHtml = `
      <div style="display:flex;gap:16px;flex-wrap:wrap;padding:0 0 10px;border-bottom:1px solid var(--mag-border);margin-bottom:8px;align-items:center;">
        <div><span style="font-size:10px;font-weight:700;color:var(--mag-muted);text-transform:uppercase;">Total</span><strong style="display:block;font-size:14px;">${formatters.money(sum.valor_total || 0)}</strong></div>
        <div><span style="font-size:10px;font-weight:700;color:var(--mag-muted);text-transform:uppercase;">Notas</span><strong style="display:block;font-size:14px;">${formatters.number(sum.notas || 0)}</strong></div>
        <div><span style="font-size:10px;font-weight:700;color:var(--mag-muted);text-transform:uppercase;">Clientes</span><strong style="display:block;font-size:14px;">${formatters.number(sum.clientes || 0)}</strong></div>
        <a href="pedidos.php?date_from=${encodeURIComponent(df)}&date_to=${encodeURIComponent(dt)}" target="_blank" style="margin-left:auto;font-size:11px;font-weight:700;color:var(--mag-accent-1,#96140a);text-decoration:underline;">Ver em pedidos ↗</a>
      </div>`;

    const rowsHtml = items.map(nf => {
      const nfId     = escapeHtml(String(nf.id || ''));
      const nfNum    = escapeHtml(nf.numero_nf || '-');
      const pedLink  = `pedidos.php?date_from=${encodeURIComponent(df)}&date_to=${encodeURIComponent(dt)}&search=${encodeURIComponent(nf.numero_nf || '')}`;
      return `
        <div class="geo-nf-row" data-nf-id="${nfId}" style="padding:8px 0;border-bottom:1px solid var(--mag-border);font-size:12px;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
            <div style="min-width:0;">
              <strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(nf.cliente_nome || 'Sem nome')}</strong>
              <span style="color:var(--mag-muted);">NF <strong>${nfNum}</strong>
                ${nf.data_emissao ? ' · ' + escapeHtml(String(nf.data_emissao).slice(0,10)) : ''}
                ${nf.vendedor_nome ? ' · ' + escapeHtml(nf.vendedor_nome) : ''}
              </span>
            </div>
            <div style="text-align:right;flex-shrink:0;">
              <strong style="display:block;white-space:nowrap;">${formatters.money(nf.valor || 0)}</strong>
              <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:3px;">
                <button type="button" class="geo-nf-expand-btn row-inline-link" data-nf-id="${nfId}" data-numero-nf="${nfNum}" style="font-size:11px;">
                  <span class="material-symbols-rounded" style="font-size:12px;vertical-align:middle;">expand_more</span>Itens
                </button>
                <a href="${pedLink}" target="_blank" class="row-inline-link" style="font-size:11px;">↗ Pedidos</a>
              </div>
            </div>
          </div>
          <div class="geo-nf-items" data-nf-id="${nfId}" style="display:none;margin-top:6px;padding:6px 8px;background:#f8fafc;border-radius:6px;font-size:11px;color:var(--mag-text);">
            <em style="color:var(--mag-muted);">Carregando itens…</em>
          </div>
        </div>
      `;
    }).join('');

    panel.innerHTML = summaryHtml + rowsHtml;

    // Bind expand buttons
    panel.addEventListener('click', async (e) => {
      const btn = e.target.closest('.geo-nf-expand-btn');
      if (!btn) return;
      const nfIdVal = btn.dataset.nfId;
      const nfNumVal = btn.dataset.numeroNf;
      const itemsDiv = panel.querySelector(`.geo-nf-items[data-nf-id="${nfIdVal}"]`);
      if (!itemsDiv) return;

      if (itemsDiv.style.display !== 'none') {
        itemsDiv.style.display = 'none';
        return;
      }
      itemsDiv.style.display = '';
      itemsDiv.innerHTML = '<em style="color:var(--mag-muted);">Carregando itens…</em>';

      try {
        const p = currentFilters();
        p.set('numero_nf', nfNumVal);
        const resp = await fetch(`api/geo_invoice_detail.php?${p}`, { credentials: 'same-origin' });
        const detail = resp.ok ? await resp.json() : null;
        const itens  = detail?.itens || [];
        if (!itens.length) {
          itemsDiv.innerHTML = '<em style="color:var(--mag-muted);">Sem itens disponíveis.</em>';
          return;
        }
        itemsDiv.innerHTML = itens.map(it => `
          <div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid rgba(0,0,0,0.06);">
            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:65%;">${escapeHtml(it.descricao_produto || it.codigo_produto || '')}</span>
            <span style="white-space:nowrap;">${(+it.quantidade || 0).toFixed(0)}x <strong>${formatters.money(+it.valor_total || 0)}</strong></span>
          </div>`).join('');
      } catch {
        itemsDiv.innerHTML = '<em style="color:var(--danger,#dc2626);">Erro ao carregar itens.</em>';
      }
    });
  }

  // ── Load CNAEs for territory (fixes empty CNAEs bug in composite mode) ──

  async function loadTerritoryCnaes(row) {
    if (!row) return;
    const tbody = $('cnaeRevenueTable');
    const chart = $('cnaeChartBlock');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--mag-muted);padding:14px;">Carregando CNAEs…</td></tr>';
    if (chart) chart.classList.add('is-hidden');

    try {
      const params = buildTerritoryParams(row);
      const resp = await fetch(`api/geo_territory_cnaes.php?${params}`, { credentials: 'same-origin' });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();
      const items = data.items || [];

      if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--mag-muted);padding:14px;">Sem CNAEs para este território no período.</td></tr>';
        return;
      }

      // Render using existing renderCnaes-compatible format
      const cnaeRows = items.map(c => ({
        cnae: c.cnae_codigo,
        cnae_description: c.cnae_descricao,
        customers: c.clientes,
        revenue: c.faturamento,
      }));
      window.BIomieMapPanel?.renderCnaes(cnaeRows, formatters);
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;color:var(--danger,#dc2626);padding:14px;">Erro ao carregar CNAEs.</td></tr>`;
    }
  }

  // Build URL params for territory-specific API calls
  // GEO.R1.9.2 — only pass date + commercial scope; NOT UI city/neighborhood (they contaminate cross-city lookups)
  function buildTerritoryParams(row) {
    const cf = currentFilters();
    const params = new URLSearchParams();
    ['date_from','date_to','seller_id','cnae','scope'].forEach(k => { if (cf.get(k)) params.set(k, cf.get(k)); });

    const cidadeKey = row.city_key || row.cidade_key || normalizeNeighborhoodKey(row.city || row.municipio_nome || '');
    const bairroKey = row.bairro_key || normalizeNeighborhoodKey(row.bairro_nome || row.neighborhood || '');
    const ttype     = row.territory_type || '';
    const joinKey   = row.join_key || row.bairro_norm || '';

    if (ttype === 'sem_geo' || ttype === 'sem_bairro' || bairroKey === '__sem_bairro__') {
      params.set('territory_type', 'sem_geo');
      params.set('bairro_key', '__sem_bairro__');
      const filterCity = $('cityFilter')?.value || '';
      if (filterCity) params.set('filter_city', filterCity);
      return params;
    }

    params.set('cidade_key',     cidadeKey);
    params.set('bairro_key',     bairroKey);
    params.set('territory_type', ttype);
    if (joinKey) params.set('join_key', joinKey);
    return params;
  }

  // ── GEO.R1.9 — GeoSmartLabels ────────────────────────────────────────────

  const GeoSmartLabels = {
    _enabled: true,
    _labelLayers: [],
    _salesRankMap: new Map(),

    // Build sales rank lookup from current composite ranking
    buildRankMap(ranking) {
      this._salesRankMap.clear();
      (ranking || []).forEach((r, i) => {
        const jk = normalizeNeighborhoodKey(r.join_key || r.cidade_key || '');
        if (jk) this._salesRankMap.set(jk, i + 1);
      });
    },

    // Minimum pixel area to show label at each zoom level
    _minAreaByZoom: { 6: 3000, 7: 1800, 8: 900, 9: 500, 10: 250, 11: 130, 12: 60, 13: 30 },

    // GEO.R1.9.3 — lower ceara threshold to zoom 6; top 25 labels cap (R1.9.7: increased from 20)
    _maxLabels: 25,
    _labelCount: 0,

    shouldShowLabel(feature, zoom, pixelArea, salesRank) {
      if (!this._enabled) return false;
      const type = feature?.properties?.territory_type || '';
      const minArea = this._minAreaByZoom[Math.min(zoom, 13)] || 900;
      // GEO.R1.9.7 — top 5 always labeled from zoom 5+ with very low pixel threshold
      if (salesRank && salesRank <= 5 && pixelArea > 10) return true;
      // Top revenue territories always labeled (cap at _maxLabels)
      if (salesRank && salesRank <= 10 && pixelArea > 25) return true;
      // GEO.R1.9.7 — metro region names at zoom 7+ if top 20 by revenue
      if (salesRank && salesRank <= 20 && (type === 'rmf_municipio_fallback' || type === 'metro_bairro') && zoom >= 7) return true;
      // Type-based zoom thresholds (ceara_municipio now starts at zoom 6)
      if (type === 'ceara_municipio' && zoom >= 6) return pixelArea > minArea * 0.7;
      if (type === 'rmf_municipio_fallback' && zoom >= 8) return pixelArea > minArea * 0.5;
      if (type === 'metro_bairro' && zoom >= 11) return pixelArea > minArea * 0.4;
      if (type === 'fortaleza_bairro' && zoom >= 12) return pixelArea > minArea * 0.3;
      return false;
    },

    getLabel(feature) {
      const p = feature?.properties || {};
      if (p.territory_type === 'fortaleza_bairro') {
        return p.bairro_nome || p.Nome || p.nome || '';
      }
      if (p.territory_type === 'metro_bairro') {
        return p.bairro_nome || '';
      }
      return p.municipio_nome || p.label || p.cidade_key || '';
    },

    // GEO.R1.9.7 — label class includes rank-based size class
    getLabelClass(feature, rank) {
      const type = feature?.properties?.territory_type || '';
      const rankClass = rank && rank <= 5 ? 'geo-label-top5' : (rank && rank <= 10 ? 'geo-label-top10' : '');
      const typeClass = {
        fortaleza_bairro:       'geo-label-fortaleza',
        metro_bairro:           'geo-label-rmf-bairro',
        rmf_municipio_fallback: 'geo-label-rmf-municipio',
        ceara_municipio:        'geo-label-ceara-municipio',
      }[type] || 'geo-label-ceara-municipio';
      return [typeClass, rankClass].filter(Boolean).join(' ');
    },

    // Apply labels to all features in geoLayer — cap at _maxLabels to prevent pollution
    applyLabels(map, geoLayer) {
      if (!this._enabled || !geoLayer || !map) return;
      const zoom = map.getZoom();
      let labeled = 0;
      this._labelCount = 0;
      geoLayer.eachLayer((layer) => {
        const feature = layer.feature;
        if (!feature) return;
        // Compute pixel area from bounds
        let pixelArea = 0;
        try {
          const bounds = layer.getBounds?.();
          if (bounds && bounds.isValid()) {
            const sw = map.latLngToContainerPoint(bounds.getSouthWest());
            const ne = map.latLngToContainerPoint(bounds.getNorthEast());
            pixelArea = Math.abs((ne.x - sw.x) * (ne.y - sw.y));
          }
        } catch {}
        const jk = normalizeNeighborhoodKey(getFeatureCompositeJoinKey(feature));
        const rank = this._salesRankMap.get(jk) || 999;
        const label = this.getLabel(feature);
        if (!label) return;

        if (this._labelCount < this._maxLabels && this.shouldShowLabel(feature, zoom, pixelArea, rank)) {
          layer.bindTooltip(label, {
            permanent: true,
            direction: 'center',
            // GEO.R1.9.7 — pass rank to getLabelClass for font scaling
            className: this.getLabelClass(feature, rank),
            sticky: false,
          });
          labeled++;
          this._labelCount++;
        } else {
          // Remove permanent label if below threshold
          if (layer.getTooltip?.()?.options?.permanent) {
            layer.unbindTooltip();
            // Restore hover tooltip
            layer.bindTooltip(tooltipHtml(feature), { sticky: true, direction: 'top' });
          }
        }
      });
      debugLog('GeoSmartLabels', `${labeled} labels aplicados em zoom ${zoom}`);
    },

    // Wire up zoom events for dynamic label refresh
    bindZoom(map, geoLayerRef) {
      if (!map) return;
      map.on('zoomend', () => {
        if (geoLayerRef && geoLayerRef.current) {
          this.applyLabels(map, geoLayerRef.current);
        }
      });
    },
  };

  // ── GEO.R1.9.2 — GeoBubbleMode ───────────────────────────────────────────

  const GeoBubbleMode = {
    _layer: null,
    _active: false,

    clear(map) {
      if (this._layer) { map?.removeLayer(this._layer); this._layer = null; }
      this._active = false;
    },

    getBubbleRadius(value, maxValue) {
      if (!maxValue || maxValue <= 0) return 5;
      return Math.max(5, Math.min(38, Math.round(Math.sqrt(value / maxValue) * 38)));
    },

    getBubbleColor(territoryType) {
      const colors = {
        fortaleza_bairro:       '#3b82f6',
        metro_bairro:           '#22c55e',
        rmf_municipio_fallback: '#8b5cf6',
        ceara_municipio:        '#f59e0b',
      };
      return colors[territoryType] || '#64748b';
    },

    render(map, rows, onClickRow, fmts) {
      this.clear(map);
      if (!map || !rows?.length) return;
      const withRevenue = rows.filter(r => (r.revenue || 0) > 0 && r.cidade_key !== 'sem_cidade');
      if (!withRevenue.length) return;
      const maxRev = Math.max(...withRevenue.map(r => r.revenue || 0));
      const markers = [];
      withRevenue.forEach(row => {
        const lat = row.centroid_lat || row.lat;
        const lng = row.centroid_lng || row.lng;
        if (!lat || !lng) return;
        const radius = this.getBubbleRadius(row.revenue || 0, maxRev);
        const color  = this.getBubbleColor(row.territory_type || '');
        const marker = L.circleMarker([lat, lng], {
          radius, color, weight: 1.5, opacity: 0.8,
          fillColor: color, fillOpacity: 0.35,
          className: 'geo-bubble-marker',
        });
        const name = row.bairro_norm ? `${row.bairro_norm} — ${row.cidade_key}` : (row.municipio_nome || row.cidade_key || '');
        marker.bindTooltip(`
          <strong>${escapeHtml(name)}</strong><br>
          Faturamento: ${fmts.money(row.revenue || 0)}<br>
          Clientes: ${fmts.number(row.customers || 0)}<br>
          Notas: ${fmts.number(row.invoices || 0)}
        `, { sticky: true, className: 'geo-bubble-tooltip' });
        marker.on('click', () => onClickRow(row));
        markers.push(marker);
      });
      this._layer = L.layerGroup(markers);
      this._layer.addTo(map);
      this._active = true;
    },
  };

  // Extract centroid from GeoJSON feature
  function extractCentroid(feature) {
    try {
      const bounds = L.geoJSON(feature).getBounds();
      const center = bounds.getCenter();
      return { lat: center.lat, lng: center.lng };
    } catch { return null; }
  }

  async function applyMapMode(mode) {
    const map = state.map;
    if (!map) return;
    state._mapMode = mode;
    syncModeChips(mode);
    if (mode !== 'heat') disableHeatmapMode();
    const baseStyle = _activeLayer === 'mapa_composto_ceara' ? styleCompositeFeature : featureStyle;
    const bubbleRows = state.compositeRows || state.rows || [];
    const onClickBubble = (row) => {
      state.selectedKey = row.join_key || row.cidade_key || '';
      loadPanelData(row);
      openNeighborhoodDrawer(null);
    };
    if (mode === 'heat') {
      GeoBubbleMode.clear(map);
      await enableHeatmapMode();
      document.body.classList.remove('geo-map-mode-bubbles');
    } else if (mode === 'bubbles') {
      if (state.geoLayer) state.geoLayer.setStyle(f => ({ ...baseStyle(f), fillOpacity: 0, opacity: 0.25 }));
      GeoBubbleMode.render(map, bubbleRows, onClickBubble, formatters);
      document.body.classList.add('geo-map-mode-bubbles');
    } else if (mode === 'hybrid') {
      GeoBubbleMode.render(map, bubbleRows, onClickBubble, formatters);
      if (state.geoLayer) state.geoLayer.setStyle(f => {
        const s = baseStyle(f);
        return { ...s, fillOpacity: s.fillOpacity > 0 ? s.fillOpacity * 0.35 : 0 };
      });
      document.body.classList.remove('geo-map-mode-bubbles');
    } else if (mode === 'quality') {
      GeoBubbleMode.clear(map);
      if (state.geoLayer) state.geoLayer.setStyle((feature) => currentModeStyle(
        feature,
        _activeLayer === 'mapa_composto_ceara' ? styleCompositeFeature : featureStyle
      ));
      document.body.classList.remove('geo-map-mode-bubbles');
    } else if (mode === 'opportunities') {
      // GEO.R2.0 — Mapa de Oportunidades Comerciais
      GeoBubbleMode.clear(map);
      state._opportunityMode = true;
      document.body.classList.remove('geo-map-mode-bubbles');
      loadOpportunityLayer();
    } else {
      GeoBubbleMode.clear(map);
      state._opportunityMode = false;
      if (state.geoLayer) state.geoLayer.setStyle(baseStyle);
      document.body.classList.remove('geo-map-mode-bubbles');
    }
    renderMapModeLegend();
  }

  function intelligenceKey(row) {
    return normalizeNeighborhoodKey(row?.territory_key || row?.join_key || row?.bairro_key
      || row?.neighborhood_key || row?.cidade_key || row?.city_key || '');
  }

  async function loadGeoIntelligence() {
    if (state.geoIntelligenceLayer === _activeLayer && state.geoIntelligence.size) return true;
    const params = currentFilters({ layer: _activeLayer });
    try {
      const payload = await apiFetch('api/geo_intelligence.php', params, 'data');
      const rows = Array.isArray(payload.data) ? payload.data : [];
      state.geoIntelligence = new Map();
      rows.forEach((row) => {
        const key = normalizeNeighborhoodKey(row.territory_key || '');
        if (key) state.geoIntelligence.set(key, row);
      });
      state.geoIntelligenceSummary = payload.summary || null;
      state.geoIntelligenceLayer = _activeLayer;
      renderGeoIntelligenceSummary(payload);
      return rows.length > 0;
    } catch (error) {
      state.geoIntelligence = new Map();
      state.geoIntelligenceSummary = null;
      renderGeoIntelligenceSummary(null);
      debugLog('Inteligencia territorial indisponivel', error?.message || error);
      return false;
    }
  }

  function renderGeoIntelligenceSummary(payload) {
    const node = $('geoIntelligenceSummary');
    if (!node) return;
    const top = payload?.summary?.top;
    if (!top) {
      node.className = 'module-alert module-alert--info d-none geo-intelligence-summary';
      node.textContent = '';
      return;
    }
    const counts = payload.summary.confidence || {};
    node.className = 'module-alert module-alert--info geo-intelligence-summary';
    node.innerHTML = `<strong>Prioridade territorial: ${escapeHtml(top.territory_label || top.territory_key)}.</strong>`
      + `<span>Score ${Number(top.opportunity_score || 0).toFixed(0)}/100 · potencial ${escapeHtml(formatters.money(top.potential_revenue || 0))}.</span>`
      + `<span class="geo-intelligence-summary__meta">Python persistido · confiança alta ${Number(counts.alta || 0)}, média ${Number(counts.media || 0)}, baixa ${Number(counts.baixa || 0)}.</span>`;
  }

  function renderTerritoryIntelligence(row) {
    const card = $('geoDrawerIntelligence');
    if (!card) return;
    const intel = state.geoIntelligence.get(intelligenceKey(row));
    card.hidden = !intel;
    if (!intel) return;
    setText('geoIntelConfidence', `Confiança ${intel.confidence || 'baixa'}`);
    setText('geoIntelScore', `${Number(intel.opportunity_score || 0).toFixed(0)}/100`);
    setText('geoIntelPotential', formatters.money(intel.potential_revenue || 0));
    const forecast = intel.forecast_revenue == null ? 'Sem projeção' : formatters.money(intel.forecast_revenue);
    const range = intel.forecast_low == null || intel.forecast_high == null ? ''
      : ` (${formatters.compactMoney(intel.forecast_low)}–${formatters.compactMoney(intel.forecast_high)})`;
    setText('geoIntelForecast', forecast + range);
    setText('geoIntelReason', intel.main_reason || 'Sem leitura suficiente.');
    setText('geoIntelAction', intel.recommended_action || 'Acompanhar o território.');
  }

  // GEO.R2.0 — Carrega e aplica camada de oportunidades comerciais
  async function loadOpportunityLayer() {
    const hasPythonScores = await loadGeoIntelligence();
    const rows = currentTerritoryRows();
    const maxRevenue = Math.max(...rows.map((row) => getRowRevenue(row)), 1);
    const maxCustomers = Math.max(...rows.map((row) => Number(row.customers || row.clientes || 0)), 1);
    const scores = new Map();
    rows.forEach((row) => {
      const persisted = state.geoIntelligence.get(intelligenceKey(row));
      if (persisted) {
        const actionable = persisted.confidence !== 'baixa';
        const key = intelligenceKey(row);
        if (key) scores.set(key, actionable ? Number(persisted.opportunity_score || 0) / 100 : 0);
        return;
      }
      if (hasPythonScores) return;
      const customers = Number(row.customers || row.clientes || 0);
      const revenue = getRowRevenue(row);
      if (customers <= 0) return;
      const score = (customers / maxCustomers) * (1 - Math.min(1, revenue / maxRevenue));
      const key = normalizeNeighborhoodKey(row.join_key || row.territory_key || row.neighborhood_key || row.cidade_key || '');
      if (key) scores.set(key, score);
    });
    state._opportunityScores = scores;

    if (state.geoLayer) {
      state.geoLayer.setStyle((feature) => currentModeStyle(
        feature,
        _activeLayer === 'mapa_composto_ceara' ? styleCompositeFeature : featureStyle
      ));
    }
    renderMapModeLegend();
  }

  // ─────────────────────────────────────────────────────────────────────────
  // GEO.R1.8.6 — Seleção manual de área + Comparação de territórios
  // ─────────────────────────────────────────────────────────────────────────

  // ── Comparison state ────────────────────────────────────────────────────

  state.comparisonList = [];   // Array of { key, label, type, color, row }
  const COMPARISON_MAX = 4;

  const TERRITORY_COLORS_HEX = {
    fortaleza_bairro:       '#1D4ED8',
    metro_bairro:           '#0F766E',
    rmf_municipio_fallback: '#6D28D9',
    ceara_municipio:        '#B45309',
    ceara_regiao:           '#2563EB',
  };

  function addToComparison(row) {
    if (!GEO_FEATURES.comparison || !row) return;
    const key   = normalizeNeighborhoodKey(
      row.join_key || row.territory_key || row.bairro_key || row.neighborhood_key || row.city_key || row.cidade_key || row.neighborhood || row.city || ''
    );
    const joinKey = row.territory_type === 'metro_bairro'
      ? normalizeNeighborhoodKey((row.city_key || '') + ':' + (row.bairro_key || normalizeNeighborhoodKey(row.neighborhood || '')))
      : key;
    const label   = row.territory_label || row.neighborhood || row.bairro_nome || row.municipio_nome || row.city || 'Território';

    if (state.comparisonList.find(c => c.joinKey === joinKey)) {
      showFeedback(`"${label}" já está na comparação.`, 'info');
      return;
    }
    if (state.comparisonList.length >= COMPARISON_MAX) {
      showFeedback(`Máximo de ${COMPARISON_MAX} territórios por comparação. Remova um antes de adicionar.`, 'warning');
      return;
    }
    state.comparisonList.push({
      joinKey,
      label,
      type:  row.territory_type || 'ceara_municipio',
      color: TERRITORY_COLORS_HEX[row.territory_type] || '#6B7280',
      row:   Object.assign({}, row),
    });
    updateComparisonBar();
    showFeedback(`"${label}" adicionado à comparação. Total: ${state.comparisonList.length}.`, 'info');
  }

  function removeFromComparison(joinKey) {
    state.comparisonList = state.comparisonList.filter(c => c.joinKey !== joinKey);
    updateComparisonBar();
  }

  function clearComparison() {
    state.comparisonList = [];
    updateComparisonBar();
    closeComparisonPanel();
  }

  function closeComparisonPanel() {
    const overlay = $('geoComparisonOverlay');
    if (overlay) overlay.hidden = true;
    state.comparisonReturnFocus?.focus?.();
    state.comparisonReturnFocus = null;
    syncModeChips(state._mapMode || 'territories');
  }

  function updateComparisonBar() {
    const bar    = $('geoComparisonBar');
    const chips  = $('geoComparisonChips');
    const count  = $('geoComparisonCount');
    const badge  = $('geoComparisonCountBadge');
    const modeChip = $('geoModeComparison');

    const n = state.comparisonList.length;

    if (bar) bar.hidden = n === 0;
    if (count) count.textContent = String(n);
    if (badge) {
      badge.textContent = n > 0 ? String(n) : '';
      badge.hidden = n === 0;
    }

    // Enable/disable comparison mode chip
    if (modeChip) {
      if (GEO_FEATURES.comparison) {
        modeChip.removeAttribute('disabled');
      }
      modeChip.classList.toggle('geo-mode-chip--active', _activeVisualMode === 'comparison' && n > 0);
      modeChip.setAttribute('aria-pressed', _activeVisualMode === 'comparison' && n > 0 ? 'true' : 'false');
    }

    if (!chips) return;
    chips.innerHTML = state.comparisonList.map(c => `
      <span class="geo-comparison-chip" style="background:${c.color}">
        ${escapeHtml(c.label)}
        <button type="button" aria-label="Remover ${escapeHtml(c.label)}" onclick="window._geoRemoveComparison(${JSON.stringify(c.joinKey)})">×</button>
      </span>
    `).join('');
  }

  // Expose to inline onclick (safer than inline event in HTML attr)
  window._geoRemoveComparison = (key) => removeFromComparison(key);

  function openComparisonPanel() {
    if (state.comparisonList.length < 2) {
      showFeedback('Adicione pelo menos 2 territórios para comparar.', 'info');
      return;
    }
    renderComparisonGrid();
    const overlay = $('geoComparisonOverlay');
    if (overlay) {
      state.comparisonReturnFocus = document.activeElement;
      overlay.hidden = false;
      $('geoCloseComparisonBtn')?.focus();
      syncModeChips('comparison');
    }
  }

  function renderComparisonGrid() {
    const grid = $('geoComparisonGrid');
    if (!grid) return;

    // Find max values for winner highlighting
    const maxRevenue  = Math.max(...state.comparisonList.map(c => Number(c.row.revenue || 0)), 1);
    const maxCustomers= Math.max(...state.comparisonList.map(c => Number(c.row.customers || 0)), 1);
    const maxInvoices = Math.max(...state.comparisonList.map(c => Number(c.row.invoices || 0)), 1);

    const TIPO_LABELS = {
      fortaleza_bairro: 'Bairro Fortaleza',
      metro_bairro: 'Bairro RMF',
      rmf_municipio_fallback: 'Município RMF',
      ceara_municipio: 'Município CE',
      ceara_regiao: 'Região de planejamento',
    };

    grid.innerHTML = state.comparisonList.map((c) => {
      const rev  = Number(c.row.revenue || 0);
      const cus  = Number(c.row.customers || 0);
      const inv  = Number(c.row.invoices || 0);
      const tkt  = Number(c.row.average_ticket || c.row.ticket_medio || (inv > 0 ? rev / inv : 0));
      const revPct = Math.round(rev / maxRevenue * 100);
      const cusPct = Math.round(cus / maxCustomers * 100);
      const invPct = Math.round(inv / maxInvoices * 100);
      const typeLabel = TIPO_LABELS[c.type] || c.type;

      return `
        <div class="geo-comparison-card">
          <div class="geo-comparison-card__head">
            <span class="geo-comparison-card__type" style="background:${c.color}">${escapeHtml(typeLabel)}</span>
            <span class="geo-comparison-card__name">${escapeHtml(c.label)}</span>
            <span class="geo-comparison-card__sub">${escapeHtml(c.row.city || c.row.municipio_nome || '')}</span>
          </div>
          <div class="geo-comparison-card__kpis">
            <div class="geo-comparison-kpi${rev === maxRevenue ? ' geo-comparison-kpi--winner' : ''}">
              <span>Faturamento</span>
              <strong>${formatters.money(rev)}</strong>
            </div>
            <div class="geo-comparison-kpi${cus === maxCustomers ? ' geo-comparison-kpi--winner' : ''}">
              <span>Clientes</span>
              <strong>${formatters.number(cus)}</strong>
            </div>
            <div class="geo-comparison-kpi${inv === maxInvoices ? ' geo-comparison-kpi--winner' : ''}">
              <span>Notas</span>
              <strong>${formatters.number(inv)}</strong>
            </div>
            <div class="geo-comparison-kpi">
              <span>Ticket médio</span>
              <strong>${formatters.money(tkt)}</strong>
            </div>
          </div>
          <div class="geo-comparison-bar-wrap">
            <div style="font-size:10px;color:var(--mag-muted);font-weight:700;">Faturamento relativo</div>
            <div class="geo-comparison-bar-track">
              <div class="geo-comparison-bar-fill" style="width:${revPct}%;background:${c.color};"></div>
            </div>
            <div style="font-size:10px;color:var(--mag-muted);font-weight:700;margin-top:4px;">Clientes relativos</div>
            <div class="geo-comparison-bar-track">
              <div class="geo-comparison-bar-fill" style="width:${cusPct}%;background:${c.color};opacity:0.7;"></div>
            </div>
          </div>
        </div>
      `;
    }).join('');

    // Update subtitle
    const params = currentFilters();
    setText('geoComparisonSubtitle', `${state.comparisonList.length} territórios · ${params.get('date_from') || ''} a ${params.get('date_to') || ''}`);
  }

  function exportComparisonCsv() {
    if (!state.comparisonList.length) return;
    const params = currentFilters();
    const period = `${params.get('date_from') || ''} a ${params.get('date_to') || ''}`;
    const header = ['Território', 'Tipo', 'Município', 'Faturamento', 'Clientes', 'Notas', 'Ticket Médio', 'Período'];
    const lines  = [header.join(';')];
    const TIPO_LABELS = { fortaleza_bairro: 'Bairro Fortaleza', metro_bairro: 'Bairro RMF', rmf_municipio_fallback: 'Município RMF', ceara_municipio: 'Município CE' };
    state.comparisonList.forEach(c => {
      const rev = Number(c.row.revenue || 0);
      const cus = Number(c.row.customers || 0);
      const inv = Number(c.row.invoices || 0);
      const tkt = inv > 0 ? rev / inv : 0;
      lines.push([c.label, TIPO_LABELS[c.type] || c.type, c.row.city || c.row.municipio_nome || '', rev.toFixed(2), cus, inv, tkt.toFixed(2), period].join(';'));
    });
    const csv  = '﻿' + lines.join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'biomie-comparacao-territorial.csv'; a.click();
    URL.revokeObjectURL(url);
  }

  // ── Area Selection (Leaflet.draw) ────────────────────────────────────────

  let _drawControl = null;
  let _drawnLayer  = null;
  state.areaResult = null;

  async function initAreaSelection() {
    if (!GEO_FEATURES.areaSelection || !state.map) return;
    if (_drawControl) return true;
    if (typeof window.L?.Control?.Draw !== 'function') {
      try {
        await window.BIomieGeoAdvancedAssets?.loadDraw?.();
      } catch (error) {
        showFeedback('Não foi possível abrir a seleção de área agora.', 'warning');
        return false;
      }
    }
    if (typeof window.L?.Control?.Draw !== 'function') return false;

    const drawnItems = new window.L.FeatureGroup().addTo(state.map);
    _drawControl = new window.L.Control.Draw({
      draw: {
        polygon:   { shapeOptions: { color: '#059669', fillOpacity: 0.12, weight: 2 } },
        rectangle: { shapeOptions: { color: '#059669', fillOpacity: 0.12, weight: 2 } },
        circle:    false,
        marker:    false,
        circlemarker: false,
        polyline:  false,
      },
      edit: { featureGroup: drawnItems, remove: true },
    });
    state.map.addControl(_drawControl);

    // Show the "Selecionar área" button
    const drawBtn = $('geoDrawAreaBtn');
    if (drawBtn) drawBtn.style.display = '';

    state.map.on(window.L.Draw.Event.CREATED, (e) => {
      if (_drawnLayer) state.map.removeLayer(_drawnLayer);
      _drawnLayer = e.layer;
      state.map.addLayer(_drawnLayer);
      processAreaSelection(_drawnLayer);
    });
    state.map.on(window.L.Draw.Event.DELETED, () => {
      clearAreaResult();
    });
    return true;
  }

  function processAreaSelection(layer) {
    const bounds = layer.getBounds();
    const geoData = _activeLayer === 'mapa_composto_ceara' ? state.compositeGeoJson : state.geoJson;
    const salesIdx = _activeLayer === 'mapa_composto_ceara' ? state.compositeSalesIndex : state.revenueByNeighborhoodKey;
    const features = geoData?.features || [];

    let totalRevenue = 0;
    let totalCustomers = 0;
    let totalInvoices = 0;
    const found = [];

    features.forEach(f => {
      let featureBounds = null;
      try { featureBounds = window.L.geoJSON(f).getBounds(); } catch (error) {}
      if (!featureBounds?.isValid?.() || !bounds.intersects(featureBounds)) return;

      const jk  = _activeLayer === 'mapa_composto_ceara'
        ? normalizeNeighborhoodKey(getFeatureCompositeJoinKey(f))
        : getFeatureLookupKeys(f).find((candidate) => salesIdx.has(candidate));
      const row = salesIdx.get(jk);
      if (!row) return;
      const rev = Number(row.revenue || 0);
      if (rev <= 0) return;

      totalRevenue   += rev;
      totalCustomers += Number(row.customers || 0);
      totalInvoices  += Number(row.invoices || 0);
      found.push({ feature: f, jk, row, rev });
    });

    found.sort((a, b) => b.rev - a.rev);
    state.areaResult = { found, totalRevenue, totalCustomers, totalInvoices };
    renderAreaResult();
  }

  function renderAreaResult() {
    const panel = $('geoAreaResultPanel');
    const body  = $('geoAreaResultBody');
    if (!panel || !body) return;

    const r = state.areaResult;
    if (!r || !r.found.length) {
      panel.hidden = true;
      return;
    }
    panel.hidden = false;

    const topList = r.found.slice(0, 5).map(t => {
      const name = getFeatureTerritoryLabel(t.feature);
      return `<li style="font-size:11px;padding:2px 0;">${escapeHtml(name)} — ${formatters.money(t.rev)}</li>`;
    }).join('');

    body.innerHTML = `
      <div class="geo-area-result-kpis">
        <div class="geo-area-result-kpi"><span>Faturamento</span><strong>${formatters.money(r.totalRevenue)}</strong></div>
        <div class="geo-area-result-kpi"><span>Territórios</span><strong>${formatters.number(r.found.length)}</strong></div>
        <div class="geo-area-result-kpi"><span>Clientes</span><strong>${formatters.number(r.totalCustomers)}</strong></div>
        <div class="geo-area-result-kpi"><span>Notas</span><strong>${formatters.number(r.totalInvoices)}</strong></div>
      </div>
      <p class="geo-area-result__note">Selecao aproximada por intersecao territorial. Territorios parcialmente tocados pela area sao incluidos.</p>
      <ul style="margin:6px 0 0;padding-left:14px;color:var(--mag-text);">${topList}</ul>
    `;
  }

  function clearAreaResult() {
    if (_drawnLayer && state.map) { state.map.removeLayer(_drawnLayer); _drawnLayer = null; }
    state.areaResult = null;
    const panel = $('geoAreaResultPanel');
    if (panel) panel.hidden = true;
  }

  function exportAreaCsv() {
    const r = state.areaResult;
    if (!r || !r.found.length) return;
    const params = currentFilters();
    const period = `${params.get('date_from') || ''} a ${params.get('date_to') || ''}`;
    const header = ['Território', 'Tipo', 'Faturamento', 'Clientes', 'Notas', 'Período'];
    const lines  = [header.join(';')];
    r.found.forEach(t => {
      const name = getFeatureTerritoryLabel(t.feature);
      const ttype = t.feature?.properties?.territory_type || '';
      lines.push([name, ttype, t.rev.toFixed(2), t.row.customers || 0, t.row.invoices || 0, period].join(';'));
    });
    const csv  = '﻿' + lines.join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'biomie-selecao-area.csv'; a.click();
    URL.revokeObjectURL(url);
  }

  // ── Bind all R1.8.6 events ────────────────────────────────────────────────

  function bindComparison() {
    if (!GEO_FEATURES.comparison) return;

    // Enable comparison chip
    const compChip = $('geoModeComparison');
    if (compChip) compChip.removeAttribute('disabled');

    // "Adicionar à comparação" in drawer
    $('geoAddToComparisonBtn')?.addEventListener('click', () => {
      if (state.drawerTerritoryRow) addToComparison(state.drawerTerritoryRow);
    });

    // Comparison bar buttons
    $('geoOpenComparisonBtn')?.addEventListener('click', openComparisonPanel);
    $('geoClearComparisonBtn')?.addEventListener('click', clearComparison);

    // Comparison overlay close
    $('geoCloseComparisonBtn')?.addEventListener('click', closeComparisonPanel);
    $('geoComparisonOverlay')?.addEventListener('click', (e) => {
      if (e.target === $('geoComparisonOverlay')) {
        closeComparisonPanel();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !$('geoComparisonOverlay')?.hidden) {
        closeComparisonPanel();
      }
    });

    // Export comparison
    $('geoExportComparisonCsvBtn')?.addEventListener('click', exportComparisonCsv);
  }

  function bindAreaSelection() {
    if (!GEO_FEATURES.areaSelection) return;
    const areaButton = $('geoDrawAreaBtn');
    if (areaButton) areaButton.style.display = '';

    areaButton?.addEventListener('click', async () => {
      if (await initAreaSelection() && typeof window.L?.Draw?.Rectangle === 'function' && state.map) {
        new window.L.Draw.Rectangle(state.map).enable();
      }
    });

    // Clear area
    $('geoClearAreaBtn')?.addEventListener('click', clearAreaResult);

    // Export area
    $('geoExportAreaCsvBtn')?.addEventListener('click', exportAreaCsv);
  }

  // Show "Adicionar" btn when territory row is available
  function showComparisonDrawerBtn(row) {
    const btn = $('geoAddToComparisonBtn');
    if (!btn || !GEO_FEATURES.comparison) return;
    btn.style.display = row ? '' : 'none';
    if (row) {
      const jk = normalizeNeighborhoodKey(
        row.join_key || row.territory_key || row.bairro_key || row.neighborhood_key || row.city_key || row.cidade_key || ''
      );
      const alreadyIn = state.comparisonList.some(c => c.joinKey === jk);
      btn.textContent = '';
      const icon = document.createElement('span');
      icon.className = 'material-symbols-rounded';
      icon.style.cssText = 'font-size:13px;vertical-align:middle;';
      icon.textContent = alreadyIn ? 'check_circle' : 'compare';
      btn.appendChild(icon);
      btn.appendChild(document.createTextNode(alreadyIn ? ' Na comparação' : ' Comparar'));
      btn.disabled = alreadyIn;
    }
  }

  // GEO.R1.9 — Load and display coverage audit in a modal/panel
  async function loadCoverageAuditPanel() {
    const params = currentFilters();
    showFeedback('Carregando diagnóstico de cobertura geográfica…', 'info');
    try {
      const resp = await fetch(`api/geo_sales_coverage_audit.php?${params}`, { credentials: 'same-origin' });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();
      if (!data.ok) { showFeedback('Erro ao carregar diagnóstico.', 'warning'); return; }
      showFeedback('', '');
      renderCoverageAudit(data);
    } catch (err) {
      showFeedback('Não foi possível carregar o diagnóstico.', 'warning');
    }
  }

  function renderCoverageAudit(data) {
    const totals = data.totals || {};
    const pct    = totals.coverage_pct ?? 0;
    const severity = pct >= 90 ? 'info' : pct >= 70 ? 'warning' : 'danger';
    const buckets  = (data.buckets || []).filter(b => (b.valor || 0) > 0);
    const topCli   = (data.top_clientes_sem_bairro || []).slice(0, 5);

    const node = $('geoFeedback');
    if (!node) return;
    let html = `
      <div style="padding:4px 0 6px;">
        <strong>Cobertura geográfica: ${pct}%</strong>
        <span style="font-size:11px;color:var(--mag-muted);margin-left:8px;">${formatters.money(totals.mapped_total || 0)} de ${formatters.money(totals.commercial_total || 0)} mapeados</span>
      </div>
      ${buckets.map(b => `
        <div style="display:flex;justify-content:space-between;padding:4px 0;border-top:1px solid rgba(0,0,0,0.08);font-size:12px;">
          <span>${escapeHtml(b.label || b.type)}</span>
          <strong style="white-space:nowrap;">${formatters.money(b.valor)} (${b.pct ?? 0}%)</strong>
        </div>`).join('')}
    `;
    if (topCli.length) {
      html += `<div style="padding:6px 0 2px;font-size:11px;font-weight:800;">Maior impacto sem bairro:</div>`;
      html += topCli.map(c => `
        <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:11px;">
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:65%;">${escapeHtml(c.cliente_nome)}</span>
          <strong>${formatters.money(c.revenue)}</strong>
        </div>`).join('');
    }
    node.className = `module-alert module-alert--${severity}`;
    node.innerHTML = html;
  }

  function init() {
    if (geoIsAdmin()) {
      document.querySelectorAll('[data-geo-admin-only]').forEach((node) => {
        node.hidden = false;
      });
    }
    initFiltersFromUrl();
    bindEvents();
    bindFocusChips();
    bindModeChips();
    bindPresets();
    bindExportCsv();
    bindCnaeBuyerTools();
    bindDrawerTabs();
    bindComparison();
    bindAreaSelection();
    bindVendorMapMode(); // GEO.R2.2
    bindAlertsTabs();   // GEO.R2.2
    syncFocusChips(_activeLayer);
    applyLayerLabels(_activeLayer);
    window.BIomieMapPanel?.setInitial(formatters);
    loadCommercialData();
  }

  // ── GEO.R2.2 — Trends (KPIs executivos) ────────────────────────────────────

  function updateKpiTrends(trend) {
    if (!trend) return;
    updateKpiTrendNode('geoKpiRevenue', 'geoKpiRevenueTrendEl', trend.total_receita_pct);
    updateKpiTrendNode('geoKpiClients', 'geoKpiClientsTrendEl', trend.total_clientes_pct);
  }

  function updateKpiTrendNode(kpiId, trendId, pct) {
    const kpiEl = $(kpiId);
    if (!kpiEl) return;
    let trendEl = $(trendId);
    if (!trendEl) {
      trendEl = document.createElement('span');
      trendEl.id = trendId;
      trendEl.className = 'biomie-kpi-card__trend';
      kpiEl.parentNode?.insertBefore(trendEl, kpiEl.nextSibling);
    }
    if (pct === null || pct === undefined) {
      trendEl.hidden = true;
      return;
    }
    const sign     = pct >= 0 ? '▲' : '▼';
    const cls      = pct >= 0 ? 'biomie-kpi-card__trend--up' : 'biomie-kpi-card__trend--down';
    const abs      = Math.abs(pct).toFixed(1);
    const prevText = pct >= 0 ? 'vs período ant.' : 'vs período ant.';
    trendEl.className = `biomie-kpi-card__trend ${cls}`;
    trendEl.innerHTML = `<span>${sign}${abs}%</span><span style="font-weight:400;font-size:10px;color:#6b7280;">${prevText}</span>`;
    trendEl.hidden = false;
  }

  async function fetchAndApplyTrends() {
    try {
      const params = { ...currentFilters({ layer: _activeLayer, mode: 'ranking' }) };
      const payload = await apiFetch('api/geo_sales_layer.php', params, 'trends_fetch');
      if (!payload?.ranking) return;
      state.trendsMap = new Map(payload.ranking.map((r) => [r.territory_key, r]));
      if (payload.summary_trend) {
        state.summaryTrend = payload.summary_trend;
        updateKpiTrends(payload.summary_trend);
      }
      // Re-render ranking to show trends
      renderRanking();
    } catch (_) { /* silently ignore trend fetch failures */ }
  }

  // ── GEO.R2.2 — Panel: KPI trends no drawer ──────────────────────────────────

  function applyDrawerTrends(row) {
    const key = normalizeNeighborhoodKey(row.neighborhood_key || row.territory_key || '');
    const trendData = row.trend_receita_pct !== undefined ? row : state.trendsMap.get(key);
    if (!trendData) return;

    const revTrendEl = $('kpiFeatureRevenueTrend');
    const cliTrendEl = $('kpiFeatureClientsTrend');
    if (revTrendEl) {
      const pct = trendData.trend_receita_pct ?? null;
      if (pct !== null) {
        revTrendEl.innerHTML = renderTrendBadge(pct);
        revTrendEl.hidden = false;
      } else {
        revTrendEl.hidden = true;
      }
    }
    if (cliTrendEl) {
      const pct = trendData.trend_clientes_pct ?? null;
      if (pct !== null) {
        cliTrendEl.innerHTML = renderTrendBadge(pct);
        cliTrendEl.hidden = false;
      } else {
        cliTrendEl.hidden = true;
      }
    }
  }

  // ── GEO.R2.2 — Meta proporcional no drawer ──────────────────────────────────

  function applyDrawerMeta(row) {
    const metaBlock = $('geoDrawerMetaBlock');
    if (!metaBlock) return;

    const shareNum   = typeof row.share_pct === 'number' ? row.share_pct : Number(row.share_pct || 0);
    const totalMeta  = state.totalMetaEstimada;
    if (!totalMeta || !shareNum) {
      metaBlock.hidden = true;
      return;
    }

    const metaTerritorio = totalMeta * (shareNum / 100);
    const realizado      = Number(row.revenue || row.receita || 0);
    const atingimento    = metaTerritorio > 0 ? Math.min(Math.round(realizado / metaTerritorio * 100), 200) : 0;
    const isOver         = atingimento >= 100;

    setText('geoMetaRealizado', formatters.money(realizado));
    setText('geoMetaAlvo', formatters.money(metaTerritorio));
    setText('geoMetaProgressLabel', `${Math.min(atingimento, 200)}%`);
    const fill = $('geoMetaProgressFill');
    if (fill) {
      fill.style.width = `${Math.min(atingimento, 100)}%`;
      fill.className = `geo-meta-progress__fill${isOver ? ' geo-meta-progress__fill--over' : ''}`;
    }
    metaBlock.hidden = false;
  }

  // ── GEO.R2.2 — Alertas territoriais ─────────────────────────────────────────

  async function loadTerritoryAlerts(row, token) {
    const alertsPanel = $('geoDrawerAlertasPanel');
    const alertBadge  = $('geoDrawerAlertaBadge');
    if (!alertsPanel) return;

    alertsPanel.innerHTML = '<div class="geo-alerts-loading"><span class="material-symbols-rounded" style="animation:spin 1s linear infinite;display:block;margin:0 auto 6px;">progress_activity</span>Analisando alertas…</div>';
    if (alertBadge) alertBadge.hidden = true;

    const key = normalizeNeighborhoodKey(row.neighborhood_key || row.territory_key || '');

    // Use cache if available
    if (state.alertsCache.has(key)) {
      if (token !== state.panelToken) return;
      renderAlertsList(state.alertsCache.get(key));
      return;
    }

    try {
      const params = {
        ...currentFilters(),
        territory_key:  key,
        territory_type: row.territory_type || '',
        cidade_key:     row.city_key  || row.cidade_key  || '',
        bairro_key:     row.bairro_key || key,
      };
      const payload = await apiFetch('api/geo_territory_alerts.php', params, `alerts_${key}`);
      if (token !== state.panelToken) return;

      state.alertsCache.set(key, payload);

      // Update ranking badge for this key
      renderRanking();

      renderAlertsList(payload);
    } catch (err) {
      if (token !== state.panelToken) return;
      alertsPanel.innerHTML = '<div class="geo-alerts-empty">Não foi possível carregar os alertas.</div>';
    }
  }

  function renderAlertsList(payload) {
    const alertsPanel = $('geoDrawerAlertasPanel');
    const alertBadge  = $('geoDrawerAlertaBadge');
    if (!alertsPanel) return;

    const alerts = payload?.alerts || [];
    const count  = alerts.length;

    // Update tab badge
    if (alertBadge) {
      if (count > 0) {
        alertBadge.textContent = String(count);
        alertBadge.className = 'geo-alert-count' + (payload?.has_critical ? '' : '--warning geo-alert-count');
        alertBadge.hidden = false;
      } else {
        alertBadge.hidden = true;
      }
    }

    if (!count) {
      alertsPanel.innerHTML = '<div class="geo-alerts-empty"><span class="material-symbols-rounded" style="font-size:28px;display:block;margin:0 auto 6px;color:#d1fae5;">check_circle</span>Nenhum alerta para este território no período.</div>';
      return;
    }

    const iconMap = {
      queda: 'trending_down',
      dormentes: 'person_off',
      oportunidade_cnae: 'lightbulb',
      novo_territorio: 'new_releases',
    };

    alertsPanel.innerHTML = alerts.map((alert) => {
      const icon = iconMap[alert.type] || 'warning';
      const sev  = alert.severity || 'info';
      const clients = alert.data?.clients || [];
      const clientsHtml = clients.length
        ? `<div class="geo-alert-item__clients">${clients.slice(0, 3).map((c) =>
            `<div class="geo-alert-item__client"><span>${escapeHtml(c.nome)}</span><span>${formatters.money(c.faturamento)}</span></div>`
          ).join('')}</div>`
        : '';
      return `
        <div class="geo-alert-item">
          <div class="geo-alert-item__icon geo-alert-item__icon--${sev}">
            <span class="material-symbols-rounded">${icon}</span>
          </div>
          <div class="geo-alert-item__body">
            <div class="geo-alert-item__title">${escapeHtml(alert.title || '')}</div>
            <div class="geo-alert-item__msg">${escapeHtml(alert.message || '')}</div>
            ${clientsHtml}
          </div>
        </div>
      `;
    }).join('');
  }

  // ── GEO.R2.2 — Modo Carteira (vendor map) ────────────────────────────────────

  const VENDOR_COLORS = [
    '#2563eb','#16a34a','#d97706','#dc2626','#7c3aed',
    '#0891b2','#be185d','#65a30d','#ea580c','#0f766e',
  ];

  async function loadVendorCoverageMode() {
    const btn = document.querySelector('[data-mode="vendor_map"]');
    if (btn) btn.disabled = true;
    try {
      const params = { ...currentFilters({ layer: _activeLayer, mode: 'vendor_coverage' }) };
      const payload = await apiFetch('api/geo_sales_layer.php', params, 'vendor_coverage');
      state.vendorCoverage = payload?.coverage || [];
      buildVendorOverlay();
      const overlay = $('geoVendorMapOverlay');
      if (overlay) overlay.hidden = false;
    } catch (err) {
      showFeedback('Não foi possível carregar a cobertura de vendedores.', 'warning');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function buildVendorOverlay() {
    const coverage = state.vendorCoverage || [];
    const sel = $('geoVendorSelector');
    if (!sel) return;

    // Collect all unique vendors
    const vendorSet = new Set();
    coverage.forEach((t) => (t.vendors || []).forEach((v) => vendorSet.add(v.vendor_name)));
    const vendors = Array.from(vendorSet).sort();

    // Rebuild selector
    sel.innerHTML = '<option value="">Todos — dominância</option>';
    vendors.forEach((v) => {
      const opt = document.createElement('option');
      opt.value = v;
      opt.textContent = v;
      sel.appendChild(opt);
    });

    // Build color map
    const colorMap = {};
    vendors.forEach((v, i) => { colorMap[v] = VENDOR_COLORS[i % VENDOR_COLORS.length]; });
    colorMap[''] = '#6b7280';

    // Legend
    const legend = $('geoVendorLegend');
    if (legend) {
      legend.innerHTML = vendors.slice(0, 8).map((v) =>
        `<span class="geo-vendor-legend-item"><span class="geo-vendor-legend-swatch" style="background:${colorMap[v]}"></span>${escapeHtml(v)}</span>`
      ).join('');
    }

    renderVendorCoverageStats('', colorMap);

    sel.onchange = () => renderVendorCoverageStats(sel.value, colorMap);
  }

  function renderVendorCoverageStats(selectedVendor, colorMap) {
    const coverage  = state.vendorCoverage || [];
    const statsEl   = $('geoVendorStats');
    const rankEl    = $('geoVendorRankingList');
    if (!statsEl || !rankEl) return;

    let totalTerritories = 0, coveredTerritories = 0, overlapTerritories = 0;
    let totalRevenue = 0;

    const items = coverage.map((t) => {
      const vendors = t.vendors || [];
      const isActive = selectedVendor === ''
        ? vendors.length > 0
        : vendors.some((v) => v.vendor_name === selectedVendor);
      const isOverlap = selectedVendor === '' ? vendors.length > 1 : false;
      const vendorRevenue = selectedVendor === ''
        ? t.total_receita || 0
        : (vendors.find((v) => v.vendor_name === selectedVendor)?.receita || 0);
      const dominantVendor = vendors[0]?.vendor_name || '';

      totalTerritories++;
      if (isActive) coveredTerritories++;
      if (isOverlap) overlapTerritories++;
      totalRevenue += vendorRevenue;

      return { key: t.territory_key, isActive, isOverlap, vendorRevenue, dominantVendor, vendors };
    });

    // Stats cards
    const coveragePct = totalTerritories > 0 ? Math.round(coveredTerritories / totalTerritories * 100) : 0;
    statsEl.innerHTML = `
      <div class="geo-vendor-stat-card">
        <div class="geo-vendor-stat-card__label">Territórios cobertos</div>
        <div class="geo-vendor-stat-card__value">${coveredTerritories} <span style="font-size:13px;color:#6b7280;">/ ${totalTerritories} (${coveragePct}%)</span></div>
      </div>
      <div class="geo-vendor-stat-card">
        <div class="geo-vendor-stat-card__label">Faturamento no recorte</div>
        <div class="geo-vendor-stat-card__value">${formatters.compactMoney(totalRevenue)}</div>
      </div>
      ${overlapTerritories > 0 ? `
      <div class="geo-vendor-stat-card">
        <div class="geo-vendor-stat-card__label">Sobreposição de carteira</div>
        <div class="geo-vendor-stat-card__value">${overlapTerritories} <span style="font-size:13px;color:#7c3aed;">territórios</span></div>
      </div>` : ''}
    `;

    // Ranking
    const sorted = items.filter((i) => i.isActive).sort((a, b) => b.vendorRevenue - a.vendorRevenue);
    const maxRev = sorted.length ? sorted[0].vendorRevenue : 1;
    rankEl.innerHTML = sorted.map((item) => {
      const barW = maxRev > 0 ? Math.round(item.vendorRevenue / maxRev * 100) : 0;
      const color = selectedVendor !== '' ? (colorMap[selectedVendor] || '#6b7280') : (colorMap[item.dominantVendor] || '#6b7280');
      const overlapBadge = item.isOverlap ? '<span class="geo-vendor-overlap-badge">sobreposição</span>' : '';
      return `
        <div class="geo-vendor-ranking-row">
          <span class="geo-vendor-ranking-name">${escapeHtml(item.key)} ${overlapBadge}</span>
          <span class="geo-vendor-ranking-bar"><span style="width:${barW}%;background:${color};"></span></span>
          <span class="geo-vendor-ranking-value">${formatters.compactMoney(item.vendorRevenue)}</span>
        </div>
      `;
    }).join('') || '<div style="padding:12px;color:#9ca3af;font-size:13px;">Nenhum território para este vendedor.</div>';
  }

  function bindVendorMapMode() {
    const closeBtn = $('geoCloseVendorMapBtn');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        const overlay = $('geoVendorMapOverlay');
        if (overlay) overlay.hidden = true;
        document.querySelectorAll('.geo-mode-chip[data-mode="vendor_map"]').forEach((c) => {
          c.classList.remove('geo-mode-chip--active');
          c.setAttribute('aria-pressed', 'false');
        });
        state._mapMode = 'territories';
        applyMapMode('territories');
      });
    }

    // Vendor map mode chip
    document.querySelectorAll('.geo-mode-chip[data-mode="vendor_map"]').forEach((chip) => {
      chip.addEventListener('click', () => {
        document.querySelectorAll('.geo-mode-chip').forEach((c) => {
          c.classList.remove('geo-mode-chip--active');
          c.setAttribute('aria-pressed', 'false');
        });
        chip.classList.add('geo-mode-chip--active');
        chip.setAttribute('aria-pressed', 'true');
        state._mapMode = 'vendor_map';
        loadVendorCoverageMode();
      });
    });
  }

  function bindAlertsTabs() {
    const tabAlertas = $('geoDrawerTabAlertas');
    if (!tabAlertas) return;
    tabAlertas.addEventListener('click', () => {
      const row = state._lastPanelRow || state.drawerTerritoryRow;
      if (row) loadTerritoryAlerts(row, state.panelToken);
    });
  }

  // GEO.R2.3 — Preenche bloco de metadados geográficos no drawer
  function applyDrawerGeoInfo(row) {
    const block = $('geoTerritoryInfoBlock');
    if (!block) return;

    const area   = Number(row?.area_km2 || 0);
    const rev    = Number(row?.revenue || row?.faturamento || 0);
    const density = area > 0 && rev > 0 ? rev / area : 0;
    const regiao  = (row?.regional_administrativa || row?.regiao_planejamento_nome || row?.meso_regiao_nome || '').trim();
    const pop     = row?.populacao != null ? Number(row.populacao) : null;

    const setNode = (id, html, show) => {
      const el = $(id);
      if (!el) return;
      el.innerHTML = html;
      el.hidden = !show;
    };

    setNode('geoTerritoryArea',
      `<span class="material-symbols-rounded geo-info-icon">straighten</span>${formatters.number(area)} km²`,
      area > 0
    );
    setNode('geoTerritoryDensity',
      `<span class="material-symbols-rounded geo-info-icon">heatmap</span>${formatters.compactMoney(density)}/km²`,
      density > 0
    );
    setNode('geoTerritoryRegiao',
      `<span class="material-symbols-rounded geo-info-icon">map</span>${escapeHtml(regiao)}`,
      !!regiao
    );
    setNode('geoTerritoryPop',
      `<span class="material-symbols-rounded geo-info-icon">groups</span>${formatters.number(pop)} hab.`,
      pop != null && pop > 0
    );

    block.hidden = !(area > 0 || regiao || (pop != null && pop > 0));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
