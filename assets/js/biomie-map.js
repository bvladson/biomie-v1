(function () {
  'use strict';

  const GEOJSON_URL = 'assets/geo/fortaleza_bairros.json';
  const FORTALEZA_BOUNDS = [[-3.92, -38.68], [-3.67, -38.36]];
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
  ];
  const GEO_NORMALIZER = window.BIOMIE_GEO_NORMALIZER || null;
  const EMPTY_GEO_MESSAGE = 'Nao encontramos vendas geograficas para o periodo selecionado. Verifique o periodo, a sincronizacao do Omie ou a materializacao geografica.';
  const MAP_DEBUG = new URLSearchParams(window.location.search).get('debug') === '1' || window.BIOMIE_GEO_DEBUG === true;
  const API_CACHE_TTL_MS = 2 * 60 * 1000;
  const API_CACHE_PREFIX = 'biomie:geo-map-api:v20260513b:';
  const CACHEABLE_ENDPOINTS = new Set([
    'api/geo_summary.php',
    'api/sales_by_neighborhood.php',
    'api/top_buyers_by_geo.php',
    'api/sales_by_cnae.php',
  ]);

  window.BIOMIE_GEO_DEBUG = MAP_DEBUG;

  const state = {
    map: null,
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

  function $(id) {
    return document.getElementById(id);
  }

  function setText(id, value) {
    const node = $(id);
    if (node) node.textContent = value;
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
      const fallbackKey = safeText(row.bairro_key || row.neighborhood_key || row.bairro_norm || row.nome_bairro_norm);
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

    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    if (city) params.set('city', city);
    if (neighborhood) params.set('neighborhood', neighborhood);
    if (cnae) params.set('cnae', cnae);

    Object.entries(extra || {}).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        params.set(key, String(value));
      }
    });

    return params;
  }

  function updateUrl() {
    const params = currentFilters();
    window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
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
    updateDiagnostics({ geoLoaded: 'Nao', geoUrl: GEOJSON_URL, mapState: 'carregando GeoJSON' });

    let response;
    try {
      response = await fetch(GEOJSON_URL, { cache: 'force-cache' });
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
    state.map = L.map('map-container', {
      minZoom: 10,
      maxZoom: 15,
      maxBounds: FORTALEZA_BOUNDS,
      maxBoundsViscosity: 0.8,
      zoomControl: true,
    }).setView([-3.755, -38.53], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap',
      opacity: 0.52,
    }).addTo(state.map);
  }

  function getColorByRevenue(value, hasData) {
    if (!hasData) return '#e5e7eb';
    const amount = Number(value || 0);
    const max = Number(state.scale?.max || 0);
    if (amount <= 0 || max <= 0) return '#e5e7eb';
    const pct = amount / max;
    if (pct >= 0.66) return '#96140a';
    if (pct >= 0.33) return '#d97706';
    return '#f59e0b';
  }

  function featureStyle(feature) {
    const matched = getFeatureCommercialRow(feature, true);
    const key = matched.key;
    const row = matched.row;
    const selected = key && key === state.selectedKey;
    const revenue = row ? getRowRevenue(row) : 0;
    
    if (revenue > 0) {
      return {
        fillColor: getColorByRevenue(revenue, true),
        weight: selected ? 3 : 1.4,
        opacity: 1,
        color: selected ? '#24364f' : '#ffffff',
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

  function tooltipHtml(feature) {
    const rawName = getFeatureNeighborhoodName(feature);
    const matched = getFeatureCommercialRow(feature, true);
    const row = matched.row;
    const label = rawName || 'Sem nome';
    if (!row) {
      return `<div class="biomie-map-tooltip"><strong>${escapeHtml(label)}</strong><span>Sem dados comerciais</span></div>`;
    }
    return `
      <div class="biomie-map-tooltip">
        <strong>${escapeHtml(label)}</strong>
        <span><em>Faturamento</em><b>${formatters.money(getRowRevenue(row))}</b></span>
        <span><em>Clientes</em><b>${formatters.number(row.customers || row.clientes || 0)}</b></span>
        <span><em>Notas</em><b>${formatters.number(row.invoices || row.notas || 0)}</b></span>
        <span><em>Ticket</em><b>${formatters.money(row.average_ticket || row.ticket_medio || 0)}</b></span>
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
        event.target.setStyle(featureStyle(feature));
      },
      click(event) {
        const rawName = getFeatureNeighborhoodName(feature);
        const matched = getFeatureCommercialRow(feature, false);
        // Prefer bairro_key from GeoJSON properties (stable underscore key) over fuzzy matching.
        const bairroKey = feature.properties?.bairro_key || matched.key || normalizeNeighborhoodKey(rawName);
        const cityKey   = feature.properties?.city_key || 'fortaleza';
        selectNeighborhood(bairroKey, rawName, cityKey);
        event.target.setStyle(featureStyle(feature));
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
          state.map.fitBounds(state.geoLayer.getBounds(), { padding: [18, 18], maxZoom: 13 });
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
    const scale = state.scale || { buckets: [] };
    const buckets = scale.buckets || [];
    node.innerHTML = buckets.map((bucket) => {
      bucket = bucket || {};
      const color = bucket.min === null ? '#e5e7eb' : getColorByRevenue(bucket.max || bucket.min || 1, true);
      return `
        <span class="biomie-map-legend__item">
          <span class="biomie-map-legend__swatch" style="background:${color}"></span>
          ${escapeHtml(bucket.label || '-')}
        </span>
      `;
    }).join('');
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
    updateDiagnostics({
      materialized: summary.last_materialized_at || '-',
      noNeighborhood: summary.rows_without_neighborhood || 0,
    });
  }

  function renderRanking() {
    const tbody = $('scopeRankingTable');
    if (!tbody) return;
    if (!state.rows.length) {
      tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--mag-muted);padding:18px;">Sem bairros no filtro.</td></tr>';
      return;
    }
    const rowsWithSales = state.rows.filter((row) => getRowRevenue(row) > 0);
    const visibleRows = (rowsWithSales.length ? rowsWithSales : state.rows).slice(0, 10);
    const maxRevenue  = Math.max(...visibleRows.map((r) => getRowRevenue(r)), 1);
    tbody.innerHTML = visibleRows.map((row) => {
      const label   = getApiNeighborhoodName(row) || safeText(row.neighborhood_key) || 'Sem bairro';
      const rev     = getRowRevenue(row);
      const barPct  = Math.round((rev / maxRevenue) * 100);
      const hasData = rev > 0;
      const rowStyle = hasData ? '' : 'opacity:0.42;';
      const key     = normalizeNeighborhoodKey(row.neighborhood_key || label);
      return `
        <tr class="geo-row-clickable" data-key="${escapeHtml(key)}" data-label="${escapeHtml(label)}" style="${rowStyle}cursor:pointer;" title="Clique para selecionar ${escapeHtml(label)} no mapa">
          <td style="width:28px;padding-right:4px;color:var(--mag-muted);font-size:12px;">${row.rank}</td>
          <td>
            <span style="font-weight:500;">${escapeHtml(label)}</span>
            ${hasData ? `<div style="margin-top:3px;height:4px;border-radius:2px;background:var(--mag-border,#e5e7eb);overflow:hidden;"><div style="width:${barPct}%;height:4px;border-radius:2px;background:var(--mag-accent-1,#96140a);"></div></div>` : ''}
          </td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;">
            ${hasData ? `<strong>${formatters.money(rev)}</strong>` : '<span style="color:var(--mag-muted)">-</span>'}
          </td>
        </tr>
      `;
    }).join('');

    tbody.onclick = function (e) {
      const tr = e.target.closest('tr[data-key]');
      if (!tr) return;
      selectNeighborhood(tr.dataset.key, tr.dataset.label, tr.dataset.cityKey || 'fortaleza');
    };
  }

  function renderDrilldown() {
    const tbody = $('drillDownTable');
    if (!tbody) return;
    if (!state.rows.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--mag-muted);padding:18px;">Nenhuma venda encontrada para os filtros selecionados.</td></tr>';
      return;
    }
    const rowsWithSales = state.rows.filter((row) => getRowRevenue(row) > 0);
    const displayRows = rowsWithSales.length ? rowsWithSales : state.rows;
    const total = displayRows.reduce((sum, row) => sum + getRowRevenue(row), 0);
    tbody.innerHTML = displayRows.map((row) => {
      const label     = getApiNeighborhoodName(row) || safeText(row.neighborhood_key) || 'Sem bairro';
      const rev       = getRowRevenue(row);
      const sharePct  = total > 0 ? (rev / total) * 100 : 0;
      const shareStr  = `${sharePct.toFixed(1)}%`;
      const barWidth  = Math.round(sharePct);
      const hasData   = rev > 0;
      const rowStyle  = hasData ? '' : 'opacity:0.42;';
      const key       = normalizeNeighborhoodKey(row.neighborhood_key || label);
      return `
        <tr class="geo-row-clickable" data-key="${escapeHtml(key)}" data-label="${escapeHtml(label)}" style="${rowStyle}cursor:pointer;" title="Clique para selecionar ${escapeHtml(label)} no mapa">
          <td>
            <strong>${escapeHtml(label)}</strong>
            <div style="font-size:11px;color:var(--mag-muted);">${escapeHtml(row.city || 'Fortaleza')}</div>
          </td>
          <td style="text-align:right;">${formatters.number(row.customers)}</td>
          <td style="text-align:right;">${formatters.number(row.invoices)}</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;">${hasData ? `<strong>${formatters.money(rev)}</strong>` : '-'}</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;font-size:12px;">${hasData ? formatters.money(row.average_ticket) : '-'}</td>
          <td style="min-width:80px;">
            <div style="display:flex;align-items:center;gap:5px;">
              <div style="flex:1;background:var(--mag-border,#e5e7eb);border-radius:2px;height:5px;overflow:hidden;">
                <div style="width:${barWidth}%;height:5px;border-radius:2px;background:var(--mag-accent-1,#96140a);"></div>
              </div>
              <span style="width:36px;text-align:right;font-size:11px;color:var(--mag-muted);">${shareStr}</span>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    tbody.onclick = function (e) {
      const tr = e.target.closest('tr[data-key]');
      if (!tr) return;
      selectNeighborhood(tr.dataset.key, tr.dataset.label, tr.dataset.cityKey || 'fortaleza');
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

    if (rev <= 0) {
      node.className = 'module-alert d-none';
      return;
    }
    if (pct > 0) {
      node.className = 'module-alert module-alert--info';
      node.innerHTML = `<span class="material-symbols-rounded" style="vertical-align:middle;font-size:16px;margin-right:6px;">bar_chart</span>`
        + `<strong>${formatters.money(rev)}</strong> vendidos no periodo — `
        + `<strong>${pct}%</strong> sem bairro enriquecido (${formatters.money(semBairroRev)} nao aparecem no mapa).`
        + ` <a href="#" onclick="document.getElementById('geoDiagnosticsContent')?.closest('details')?.setAttribute('open','');return false;" style="font-size:12px;margin-left:6px;">Ver diagnostico</a>`;
    } else {
      node.className = 'module-alert module-alert--info';
      node.innerHTML = `<span class="material-symbols-rounded" style="vertical-align:middle;font-size:16px;margin-right:6px;">check_circle</span>`
        + `<strong>${formatters.money(rev)}</strong> vendidos no periodo — 100% com bairro enriquecido.`;
    }
  }

  async function loadPanelData(row) {
    if (!row) return;
    const token = ++state.panelToken;
    window.BIomieMapPanel?.renderNeighborhood(row, formatters);
    window.BIomieMapPanel?.renderBuyersLoading();

    // Always use underscore-based bairro_key; fall back to normalizing the display name if absent.
    const bairroKey = normalizeNeighborhoodKey(row.bairro_key || row.neighborhood_key || row.neighborhood || '');
    const cityKey   = normalizeNeighborhoodKey(row.city_key || row.cidade_key || row.city || 'Fortaleza');
    const params = currentFilters({
      bairro_key:  bairroKey,
      city_key:    cityKey,
      neighborhood: row.neighborhood || row.bairro_nome || '',
      limit: 10,
    });
    const [buyers, cnaes] = await Promise.allSettled([
      apiFetch('api/top_buyers_by_geo.php', params, 'buyers'),
      apiFetch('api/sales_by_cnae.php', params, 'cnae'),
    ]);

    if (state.panelToken !== token) return;

    if (buyers.status === 'fulfilled') {
      const sourceMode = buyers.value.meta?.source_mode || 'gold_mart';
      window.BIomieMapPanel?.renderBuyers(buyers.value.data || [], formatters, sourceMode);
    } else {
      window.BIomieMapPanel?.renderBuyersError('Erro ao carregar compradores. Tente novamente.');
    }

    if (cnaes.status === 'fulfilled') {
      window.BIomieMapPanel?.renderCnaes(cnaes.value.data || [], formatters);
    } else {
      window.BIomieMapPanel?.renderCnaes([], formatters);
    }
  }

  function selectNeighborhood(key, label, cityKey) {
    const matched = findCommercialRowByKeyOrLabel(key, label, false);
    state.selectedKey = matched.key || key || '';
    if ($('neighborhoodFilter')) $('neighborhoodFilter').value = label || '';
    state.geoLayer?.eachLayer((layer) => layer.setStyle(featureStyle(layer.feature)));
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
    loadPanelData(row);
  }

  function resetSelection(clearInput) {
    state.selectedKey = '';
    if (clearInput && $('neighborhoodFilter')) $('neighborhoodFilter').value = '';
    state.geoLayer?.eachLayer((layer) => layer.setStyle(featureStyle(layer.feature)));
    // Restore global panel if summary data is available; otherwise show empty state
    const buyers = state.summary ? (state._summaryPayload?.top_buyers || []) : [];
    const cnaes  = state.summary ? (state._summaryPayload?.top_cnaes  || []) : [];
    if (buyers.length || cnaes.length) {
      window.BIomieMapPanel?.setGlobal(buyers, cnaes, formatters);
    } else {
      window.BIomieMapPanel?.setInitial(formatters);
    }
  }

  function clearSelection() {
    resetSelection(true);
  }

  function showEnrichCard(count) {
    const node = $('geoEnrichCard');
    if (!node) return;
    if (!count || count <= 0) {
      node.className = 'module-alert d-none';
      return;
    }
    node.className = 'module-alert module-alert--warning';
    const msg = $('geoEnrichMessage');
    if (msg) msg.textContent = `${count} registro(s) com bairro ausente nao aparecem no mapa. Enriqueca os dados de endereco dos clientes para aumentar a cobertura geografica.`;
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

  async function loadCommercialData() {
    initMap();
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
        ? neighborhoodsPayload.data.filter((row) => row && typeof row === 'object')
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
      safeRender('ranking', renderRanking);
      safeRender('drilldown', renderDrilldown);
      safeRender('geo_layer', () => renderGeoLayer(geoJson));
      safeRender('legend', renderLegend);
      safeRender('selection', () => resetSelection(false));
      safeRender('enrichment', () => showEnrichCard(noNeighborhood));
      safeRender('coverage', () => renderCoverageBar(summaryPayload));

      // Populate side panel with global top buyers and CNAEs (no neighborhood selected)
      const globalBuyers = summaryPayload.top_buyers || [];
      const globalCnaes  = summaryPayload.top_cnaes  || [];
      safeRender('side_panel_global', () => window.BIomieMapPanel?.setGlobal(globalBuyers, globalCnaes, formatters));

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
        showState('danger', 'Há dados de faturamento, mas nenhum polígono foi pintado. Erro de casamento API x GeoJSON.', 'Verificar normalização dos nomes dos bairros.', 'Limpar filtros');
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
      renderDrilldown();
      renderGeoLayer();
      renderLegend();
      resetSelection(false);
      showEnrichCard(0);

      if (isGeoJsonError) {
        showState('danger', 'Mapa de bairros indisponivel.', 'Nao foi possivel carregar o GeoJSON dos bairros de Fortaleza. Verifique o arquivo assets/geo/fortaleza_bairros.json e abra o diagnostico tecnico.', 'Tentar novamente');
      } else if (isMigrationPending) {
        showState('danger', 'Os dados analiticos ainda nao foram preparados.', 'Aplique as migrations pendentes no painel de Administracao.', 'Tentar novamente');
      } else if (isMatMissing) {
        showState('danger', 'O mapa ainda nao foi materializado.', 'Execute a materializacao dos marts para gerar os dados geograficos.', 'Tentar novamente');
      } else if (isMissingTable) {
        showState('danger', 'Os dados geograficos ainda nao foram preparados.', 'Execute as migrations e a materializacao dos marts.', 'Tentar novamente');
      } else if (isOmie) {
        showState('danger', 'Os dados do Omie ainda nao foram sincronizados para este periodo.', 'Verifique a ultima sincronizacao antes de consultar o mapa.', 'Tentar novamente');
      } else {
        showState('danger', 'Nao foi possivel carregar o mapa comercial agora.', 'Tente novamente em alguns instantes. O diagnostico tecnico mostra se a falha veio da API, do GeoJSON ou do cruzamento entre ambos.', 'Tentar novamente');
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

  function applyPresetMonth() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    if ($('dateStart')) $('dateStart').value = `${year}-01-01`;
    if ($('dateEnd')) $('dateEnd').value = `${year}-${month}-${day}`;
  }

  function clearFilters() {
    applyPresetMonth();
    if ($('cityFilter')) $('cityFilter').value = 'Fortaleza';
    if ($('neighborhoodFilter')) $('neighborhoodFilter').value = '';
    if ($('cnaeFilter')) $('cnaeFilter').value = '';
    state.selectedKey = '';
    loadCommercialData();
  }

  function initFiltersFromUrl() {
    const params = new URLSearchParams(window.location.search);
    applyPresetMonth();
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
  }

  function bindEvents() {
    $('applyFilters')?.addEventListener('click', () => {
      state.selectedKey = '';
      loadCommercialData();
    });
    $('clearFilters')?.addEventListener('click', clearFilters);
    $('clearSelection')?.addEventListener('click', clearSelection);
    document.addEventListener('click', handleCustomerOpenClick);
    document.addEventListener('keydown', handleCustomerOpenKeydown);
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

  function init() {
    initFiltersFromUrl();
    bindEvents();
    window.BIomieMapPanel?.setInitial(formatters);
    loadCommercialData();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
