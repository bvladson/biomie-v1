(function () {
  'use strict';

  const state = {
    start: document.getElementById('dateStart')?.value || '',
    end: document.getElementById('dateEnd')?.value || '',
    scope: 'fortaleza',
    mapInstance: null,
    baseLayer: null,
    geoLayer: null,
    geoDataCache: {},
    mapDataCache: {},
    selectedFeatureId: null,
    joinHealth: { matched: 0, orphans: 0 },
  };

  const scopeViews = {
    brasil: { center: [-14.235, -51.925], zoom: 4, maxZoom: 5 },
    ceara: { center: [-5.25, -39.45], zoom: 7, maxZoom: 8 },
    fortaleza: { center: [-3.755, -38.53], zoom: 12, maxZoom: 13 },
  };

  function formatMoney(value) {
    if (value == null) return 'R$ 0,00';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
  }

  function formatNum(value) {
    return new Intl.NumberFormat('pt-BR').format(Number(value || 0));
  }

  function buildQuery(extraParams) {
    const params = new URLSearchParams();
    if (state.start) params.set('start', state.start);
    if (state.end) params.set('end', state.end);
    Object.entries(extraParams || {}).forEach(([key, value]) => {
      if (value == null || value === '') return;
      params.set(key, String(value));
    });
    return params.toString();
  }

  function getColorByIntensity(value, maxValue) {
    const amount = Number(value || 0);
    if (amount <= 0 || maxValue <= 0) return '#e8edf3';
    const pct = Math.max(0.08, Math.min(1, amount / maxValue));
    const red = Math.round(248 - (98 * pct));
    const green = Math.round(236 - (216 * pct));
    const blue = Math.round(234 - (224 * pct));
    return `rgb(${red},${green},${blue})`;
  }

  async function fetchJson(url, options) {
    if (typeof window.fetchJson === 'function') {
      return window.fetchJson(url, options);
    }

    const response = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options || {}));
    return response.json();
  }

  function setMapBusy(isBusy) {
    const container = document.getElementById('map-container');
    if (!container) return;
    container.classList.toggle('is-loading', Boolean(isBusy));
  }

  function setFeedback(message, tone) {
    const node = document.getElementById('geoFeedback');
    if (!node) return;

    if (!message) {
      node.className = 'module-alert d-none';
      node.textContent = '';
      return;
    }

    const classes = {
      success: 'module-alert module-alert--success',
      warning: 'module-alert module-alert--warning',
      danger: 'module-alert module-alert--danger',
      info: 'module-alert module-alert--info',
    };

    node.className = classes[tone] || classes.info;
    node.textContent = message;
  }

  function setJoinHealth(text, tone) {
    const badge = document.getElementById('joinHealthBadge');
    if (!badge) return;

    badge.textContent = text;
    if (tone === 'danger') {
      badge.style.color = '#b42318';
      badge.style.background = 'rgba(247, 144, 142, 0.18)';
      return;
    }
    if (tone === 'success') {
      badge.style.color = '#166534';
      badge.style.background = 'rgba(134, 239, 172, 0.18)';
      return;
    }
    if (tone === 'warning') {
      badge.style.color = '#9a3412';
      badge.style.background = 'rgba(253, 186, 116, 0.18)';
      return;
    }

    badge.style.color = '';
    badge.style.background = '';
  }

  function currentScopeView() {
    return scopeViews[state.scope] || scopeViews.fortaleza;
  }

  function normalizeGeoKey(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim()
      .toUpperCase();
  }

  function getFeatureKey(feature) {
    if (state.scope === 'brasil') return normalizeGeoKey(feature?.properties?.uf_sigla);
    if (state.scope === 'ceara') return normalizeGeoKey(feature?.properties?.municipio_nome);
    if (state.scope === 'fortaleza') return normalizeGeoKey(feature?.properties?.bairro_nome);
    return '';
  }

  function getFeatureLabel(feature) {
    if (state.scope === 'brasil') return String(feature?.properties?.uf_nome || feature?.properties?.uf_sigla || 'UF');
    if (state.scope === 'ceara') return String(feature?.properties?.municipio_nome || 'Cidade');
    if (state.scope === 'fortaleza') return String(feature?.properties?.bairro_nome || 'Bairro');
    return 'Regiao';
  }

  async function fetchGeographyMetrics() {
    let url = '';
    if (state.scope === 'brasil') {
      url = 'api/sales_by_city.php?' + buildQuery({ uf: 'ALL', level: 'uf' });
    } else if (state.scope === 'ceara') {
      url = 'api/sales_by_city.php?' + buildQuery({ uf: 'CE', level: 'city' });
    } else {
      url = 'api/sales_by_neighborhood.php?' + buildQuery({ city_ibge: '2304400' });
    }

    if (state.mapDataCache[url]) {
      return state.mapDataCache[url];
    }

    const payload = await fetchJson(url, { timeoutMs: 45000 });
    const normalized = {
      data: Array.isArray(payload?.data) ? payload.data : [],
      meta: payload?.meta || {},
    };
    state.mapDataCache[url] = normalized;
    return normalized;
  }

  async function loadGeoJsonFile() {
    const urls = {
      brasil: 'assets/geo/brasil_ufs.json',
      ceara: 'assets/geo/ceara_mun.json',
      fortaleza: 'assets/geo/fortaleza_bairros.json',
    };

    if (state.geoDataCache[state.scope]) {
      return JSON.parse(JSON.stringify(state.geoDataCache[state.scope]));
    }

    const response = await fetch(urls[state.scope], { cache: 'force-cache' });
    const data = await response.json();
    state.geoDataCache[state.scope] = data;
    return JSON.parse(JSON.stringify(data));
  }

  function setupMap() {
    if (state.mapInstance) return;

    const view = currentScopeView();
    state.mapInstance = L.map('map-container', {
      zoomControl: true,
      attributionControl: true,
    }).setView(view.center, view.zoom);

    state.baseLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap',
      opacity: 0.42,
    });
    state.baseLayer.addTo(state.mapInstance);
    window.requestAnimationFrame(() => state.mapInstance.invalidateSize());
  }

  function mergeMetricsIntoFeatures(geoJson, metrics) {
    const stats = {};
    let maxRevenue = 0;
    state.joinHealth = { matched: 0, orphans: 0 };

    metrics.forEach((metric) => {
      const key = normalizeGeoKey(metric?.name);
      if (!key) return;

      const matched = geoJson.features.some((feature) => getFeatureKey(feature) === key);
      if (matched) state.joinHealth.matched += 1;
      else state.joinHealth.orphans += 1;

      stats[key] = {
        revenue: Number(metric?.value_raw || 0),
        notes: Number(metric?.notes || 0),
        clients: Number(metric?.clients || 0),
        share: Number(metric?.share || 0),
        label: String(metric?.name || key),
      };

      if (stats[key].revenue > maxRevenue) {
        maxRevenue = stats[key].revenue;
      }
    });

    geoJson.features.forEach((feature) => {
      const key = getFeatureKey(feature);
      feature.properties._stats = stats[key] || {
        revenue: 0,
        notes: 0,
        clients: 0,
        share: 0,
        label: getFeatureLabel(feature),
      };
    });

    return { geoJson, maxRevenue };
  }

  function updateBrowserUrl() {
    const params = new URLSearchParams();
    if (state.start) params.set('start', state.start);
    if (state.end) params.set('end', state.end);
    params.set('scope', state.scope);
    window.history.replaceState({}, '', '?' + params.toString());
  }

  function updateTitles() {
    const titles = {
      brasil: 'Brasil por UF',
      ceara: 'Ceara por cidades',
      fortaleza: 'Fortaleza por bairros',
    };
    const title = titles[state.scope] || titles.brasil;
    const titleNode = document.getElementById('mapTitle');
    const subNode = document.getElementById('subTotalScope');
    const scopeBadge = document.getElementById('mapScopeBadge');
    const rankingTitle = document.getElementById('scopeRankingTitle');
    const topLabel = document.getElementById('geoKpiTopLabel');
    const rankingLabels = {
      brasil: 'Ranking de UFs',
      ceara: 'Ranking de cidades',
      fortaleza: 'Ranking de bairros',
    };
    const topLabels = {
      brasil: 'Top UF',
      ceara: 'Top cidade',
      fortaleza: 'Top bairro',
    };

    if (titleNode) {
      titleNode.innerHTML = `<span class="material-symbols-rounded" style="color:var(--brand-dark)">map</span> ${title}`;
    }
    if (subNode) {
      subNode.textContent = `Ranking de ${title}`;
    }
    if (rankingTitle) {
      rankingTitle.textContent = rankingLabels[state.scope] || rankingLabels.fortaleza;
    }
    if (topLabel) {
      topLabel.textContent = topLabels[state.scope] || topLabels.fortaleza;
    }
    if (scopeBadge) {
      scopeBadge.textContent = state.scope === 'fortaleza' ? 'Fortaleza' : title;
    }
  }

  function renderGeoKpis(metrics) {
    const rows = Array.isArray(metrics) ? metrics : [];
    const totalRevenue = rows.reduce((sum, item) => sum + Number(item.value_raw || 0), 0);
    const totalClients = rows.reduce((sum, item) => sum + Number(item.clients || 0), 0);
    const totalNotes = rows.reduce((sum, item) => sum + Number(item.notes || 0), 0);
    const topArea = rows
      .slice()
      .sort((left, right) => Number(right.value_raw || 0) - Number(left.value_raw || 0))[0];

    const revenueNode = document.getElementById('geoKpiRevenue');
    const areasNode = document.getElementById('geoKpiAreas');
    const clientsNode = document.getElementById('geoKpiClients');
    const notesNode = document.getElementById('geoKpiNotes');
    const topAreaNode = document.getElementById('geoKpiTopArea');

    if (revenueNode) revenueNode.textContent = formatMoney(totalRevenue);
    if (areasNode) areasNode.textContent = formatNum(rows.length);
    if (clientsNode) clientsNode.textContent = formatNum(totalClients);
    if (notesNode) notesNode.textContent = formatNum(totalNotes);
    if (topAreaNode) topAreaNode.textContent = topArea?.name || '-';
  }

  function renderScopeRanking(metrics) {
    const tbody = document.getElementById('scopeRankingTable');
    if (!tbody) return;

    if (!metrics || !metrics.length) {
      tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:16px;">Sem geografia consolidada para o recorte.</td></tr>';
      return;
    }

    const rows = metrics
      .slice()
      .sort((left, right) => Number(right.value_raw || 0) - Number(left.value_raw || 0))
      .slice(0, 8);

    tbody.innerHTML = rows.map((item, index) => `
      <tr>
        <td style="width:30px"><strong>#${index + 1}</strong></td>
        <td>${item.name || 'N/D'}</td>
        <td style="text-align:right"><strong>${formatMoney(item.value_raw)}</strong></td>
      </tr>
    `).join('');
  }

  function renderDrillDownTable(metrics) {
    const tbody = document.getElementById('drillDownTable');
    if (!tbody) return;

    if (!metrics || !metrics.length) {
      tbody.innerHTML = '<tr><td colspan="5">Nenhum dado geografico encontrado no escopo.</td></tr>';
      return;
    }

    const total = metrics.reduce((sum, item) => sum + Number(item.value_raw || 0), 0);
    tbody.innerHTML = metrics.map((item) => {
      const share = total > 0 ? `${((Number(item.value_raw || 0) / total) * 100).toFixed(1)}%` : '0%';
      return `
        <tr>
          <td><strong>${item.name || 'Nao Informado'}</strong></td>
          <td>${formatNum(item.clients)}</td>
          <td>${formatNum(item.notes)}</td>
          <td>${formatMoney(item.value_raw)}</td>
          <td><strong>${share}</strong></td>
        </tr>
      `;
    }).join('');
  }

  function updateSidePanel(feature) {
    const badge = document.getElementById('selectedFeatureBadge');
    const revenueNode = document.getElementById('kpiFeatureRevenue');
    const notesNode = document.getElementById('kpiFeatureNotes');
    const table = document.getElementById('areaClientsTable');
    const topBuyersCard = document.getElementById('topBuyersCard');
    const topBuyersTable = document.getElementById('topBuyersTable');

    if (!badge || !revenueNode || !notesNode || !table) return;

    if (!feature) {
      badge.textContent = 'Nenhuma selecao';
      badge.style.background = 'var(--page-border)';
      badge.style.color = 'var(--text-main)';
      revenueNode.textContent = '-';
      notesNode.textContent = '-';
      table.innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--text-dim);">Clique no mapa para filtrar</td></tr>';
      if (topBuyersCard) topBuyersCard.style.display = 'none';
      return;
    }

    const stats = feature.properties?._stats || {};
    badge.textContent = getFeatureLabel(feature);
    badge.style.background = 'var(--brand-dark)';
    badge.style.color = '#fff';
    revenueNode.textContent = formatMoney(stats.revenue);
    notesNode.textContent = formatNum(stats.notes);
    table.innerHTML = `
      <tr><td>Clientes unicos</td><td>${formatNum(stats.clients)}</td></tr>
      <tr><td>Notas emitidas</td><td>${formatNum(stats.notes)}</td></tr>
      <tr><td>Share no escopo</td><td>${Number(stats.share || 0).toFixed(1)}%</td></tr>
      <tr><td>Volume local</td><td>${formatMoney(stats.revenue)}</td></tr>
    `;

    if (topBuyersCard && topBuyersTable) {
      topBuyersCard.style.display = 'block';
      topBuyersTable.innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--text-dim);">Buscando...</td></tr>';
      
      const queryParams = { limit: 5 };
      if (state.scope === 'brasil') queryParams.uf = getFeatureKey(feature);
      else if (state.scope === 'ceara') {
        queryParams.uf = 'CE';
        queryParams.cidade = getFeatureKey(feature);
      } else {
        queryParams.bairro = getFeatureKey(feature);
      }

      fetchJson('api/top_buyers_by_geo.php?' + buildQuery(queryParams))
        .then(res => {
          const buyers = Array.isArray(res?.data) ? res.data : [];
          if (!buyers.length) {
            topBuyersTable.innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--text-dim);">Sem compradores.</td></tr>';
            return;
          }
          topBuyersTable.innerHTML = buyers.map(b => `
            <tr>
              <td>
                <div style="font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;" title="${b.cliente || 'Sem nome'}">${b.cliente || 'Sem nome'}</div>
                <div style="font-size:10px;color:var(--text-dim)">${b.cnae_codigo ? b.cnae_codigo + ' - ' + (b.segmento_macro || '') : b.documento_mascarado}</div>
              </td>
              <td style="text-align:right">
                <div style="font-weight:600;font-size:12px">${formatMoney(b.faturamento)}</div>
                <div style="font-size:10px;color:var(--text-dim)">${b.qtd_notas} NF(s)</div>
              </td>
            </tr>
          `).join('');
        })
        .catch(err => {
          topBuyersTable.innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--text-dim);">Erro ao carregar</td></tr>';
        });
    }
  }

  function drawGeoLayer(geoJson, maxRevenue) {
    if (state.geoLayer) {
      state.mapInstance.removeLayer(state.geoLayer);
      state.geoLayer = null;
    }

    state.geoLayer = L.geoJson(geoJson, {
      style: function (feature) {
        const revenue = Number(feature?.properties?._stats?.revenue || 0);
        return {
          fillColor: getColorByIntensity(revenue, maxRevenue),
          weight: revenue > 0 ? 1.2 : 1,
          opacity: 1,
          color: revenue > 0 ? '#ffffff' : '#cbd5e1',
          fillOpacity: revenue > 0 ? 0.74 : 0.48,
        };
      },
      onEachFeature: function (feature, layer) {
        const label = getFeatureLabel(feature);
        const stats = feature.properties?._stats || {};

        layer.bindTooltip(`
          <div class="map-tooltip">
            <strong>${label}</strong>
            Faturamento: ${formatMoney(stats.revenue)}<br>
            Notas: ${formatNum(stats.notes)}<br>
            Clientes: ${formatNum(stats.clients)}
          </div>
        `, { sticky: true });

        layer.on({
          mouseover: function (event) {
            const target = event.target;
            target.setStyle({ weight: 2.2, color: '#24364f', fillOpacity: 0.9 });
            if (!L.Browser.ie && !L.Browser.opera && !L.Browser.edge) target.bringToFront();
          },
          mouseout: function (event) {
            state.geoLayer.resetStyle(event.target);
            if (state.selectedFeatureId === feature) {
              event.target.setStyle({ weight: 3, color: '#d97706', fillOpacity: 0.95 });
            }
          },
          click: function (event) {
            state.geoLayer.resetStyle();
            event.target.setStyle({ weight: 3, color: '#d97706', fillOpacity: 0.95 });
            if (!L.Browser.ie && !L.Browser.opera && !L.Browser.edge) event.target.bringToFront();
            state.selectedFeatureId = feature;
            updateSidePanel(feature);
          },
        });
      },
    }).addTo(state.mapInstance);

    if (geoJson.features.length > 0 && state.geoLayer.getBounds().isValid()) {
      state.mapInstance.fitBounds(state.geoLayer.getBounds(), {
        padding: [20, 20],
        maxZoom: currentScopeView().maxZoom,
      });
    }

    window.requestAnimationFrame(() => state.mapInstance.invalidateSize());
  }

  function updateMapFooter(rawGeo, meta) {
    const geoMeta = rawGeo?.geo_metadata || { version: '1.0', source: 'Local' };
    const sourceMode = meta?.source_mode === 'aggregate'
      ? 'mart_geo'
      : (meta?.source_mode === 'fact_fallback' ? 'geo_fact' : 'canonical_fallback');
    const versionNode = document.getElementById('geoVersionText');
    const sourceNode = document.getElementById('geoSourceText');
    if (versionNode) versionNode.textContent = `Malha: v${geoMeta.version} (${geoMeta.source})`;
    if (sourceNode) sourceNode.textContent = `Fonte: ${sourceMode}`;
  }

  function updateJoinHealth(metrics, meta) {
    const matchedNode = document.getElementById('geoMatchedText');
    const orphansNode = document.getElementById('geoOrphansText');
    const noAreaNode = document.getElementById('geoKpiNoArea');

    if (!metrics.length) {
      if (matchedNode) matchedNode.textContent = 'Matched: 0';
      if (orphansNode) orphansNode.textContent = 'Orfaos: 0';
      if (noAreaNode) noAreaNode.textContent = '0';
      setJoinHealth('Sem pontos geograficos no recorte', 'warning');
      return;
    }

    const total = state.joinHealth.matched + state.joinHealth.orphans;
    const pct = total > 0 ? Math.round((state.joinHealth.matched / total) * 100) : 0;
    const tone = pct >= 90 ? 'success' : (pct >= 60 ? 'warning' : 'danger');
    const sourceLabel = meta?.source_mode === 'aggregate'
      ? 'mart'
      : (meta?.source_mode === 'fact_fallback' ? 'fact' : 'fallback');
    if (matchedNode) matchedNode.textContent = `Matched: ${state.joinHealth.matched}`;
    if (orphansNode) orphansNode.textContent = `Orfaos: ${state.joinHealth.orphans}`;
    if (noAreaNode) noAreaNode.textContent = formatNum(state.joinHealth.orphans);
    setJoinHealth(`${pct}% matched | ${state.joinHealth.orphans} orfaos | ${sourceLabel}`, tone);
  }

  function applyPresetRange(preset) {
    const now = new Date();
    const toIso = (value) => {
      const year = value.getFullYear();
      const month = String(value.getMonth() + 1).padStart(2, '0');
      const day = String(value.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    };

    if (preset === 'month') {
      state.start = toIso(new Date(now.getFullYear(), now.getMonth(), 1));
      state.end = toIso(new Date(now.getFullYear(), now.getMonth() + 1, 0));
    } else if (preset === 'year') {
      state.start = `${now.getFullYear()}-01-01`;
      state.end = toIso(new Date(now.getFullYear(), now.getMonth(), now.getDate()));
    }

    document.getElementById('dateStart').value = state.start;
    document.getElementById('dateEnd').value = state.end;
  }

  async function materializeGeoData() {
    const query = buildQuery({});
    return fetchJson(`api/geo_materialize.php?${query}`, {
      method: 'POST',
      timeoutMs: 90000,
    });
  }

  async function renderSegmentation(queryParams) {
    const cnaeTbody = document.getElementById('cnaeRevenueTable');
    const cnpjTbody = document.getElementById('cnpjRevenueTable');
    if (!cnaeTbody || !cnpjTbody) return;

    cnaeTbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-dim);">Carregando segmentacao...</td></tr>';
    cnpjTbody.innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--text-dim);">Carregando CNPJs...</td></tr>';

    try {
      const qs = buildQuery(queryParams);
      const [cnaeRes, cnpjRes] = await Promise.all([
        fetchJson(`api/sales_by_cnae.php?${qs}`),
        fetchJson(`api/top_clients_by_cnae.php?${qs}&limit=10`)
      ]);

      const cnaes = Array.isArray(cnaeRes?.data) ? cnaeRes.data : [];
      if (!cnaes.length) {
        cnaeTbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-dim);">Nenhum dado encontrado</td></tr>';
      } else {
        cnaeTbody.innerHTML = cnaes.slice(0, 10).map(c => `
          <tr>
            <td>
              <div style="font-weight:600;font-size:12px;">${c.cnae_codigo || 'SEM_CNAE'}</div>
              <div style="font-size:10px;color:var(--text-dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;" title="${c.cnae_descricao || 'PJ sem CNAE'}">${c.cnae_descricao || 'PJ sem CNAE'}</div>
            </td>
            <td>${formatNum(c.qtd_clientes)}</td>
            <td style="text-align:right"><strong>${formatMoney(c.faturamento)}</strong></td>
          </tr>
        `).join('');
      }

      const cnpjs = Array.isArray(cnpjRes?.data) ? cnpjRes.data : [];
      if (!cnpjs.length) {
        cnpjTbody.innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--text-dim);">Nenhum cliente encontrado</td></tr>';
      } else {
        cnpjTbody.innerHTML = cnpjs.map(c => `
          <tr>
            <td>
              <div style="font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;" title="${c.cliente_nome || 'Sem nome'}">${c.cliente_nome || 'Sem nome'}</div>
              <div style="font-size:10px;color:var(--text-dim)">${c.documento_mascarado}</div>
            </td>
            <td style="text-align:right">
              <div style="font-weight:600;font-size:12px">${formatMoney(c.faturamento)}</div>
              <div style="font-size:10px;color:var(--text-dim)">${c.cnae_codigo !== 'SEM_CNAE' ? c.cnae_codigo : c.segmento_macro}</div>
            </td>
          </tr>
        `).join('');
      }

    } catch (err) {
      console.error('Falha ao carregar segmentacao', err);
      cnaeTbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-dim);">Erro ao carregar CNAEs</td></tr>';
      cnpjTbody.innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--text-dim);">Erro ao carregar CNPJs</td></tr>';
    }
  }

  async function renderMapScope() {
    if (window.BIomieFeatureFlags?.geo_map_enabled === false) {
      setFeedback('Modulo geografico desabilitado por feature flag.', 'warning');
      return;
    }

    setupMap();
    updateTitles();
    updateBrowserUrl();
    updateSidePanel(null);
    setMapBusy(true);

    try {
      const [rawGeo, payload] = await Promise.all([
        loadGeoJsonFile(),
        fetchGeographyMetrics(),
      ]);

      const metrics = Array.isArray(payload?.data) ? payload.data : [];
      const meta = payload?.meta || {};
      const merged = mergeMetricsIntoFeatures(rawGeo, metrics);

      renderGeoKpis(metrics);
      renderDrillDownTable(metrics);
      renderScopeRanking(metrics);
      drawGeoLayer(merged.geoJson, merged.maxRevenue);
      updateMapFooter(rawGeo, meta);
      updateJoinHealth(metrics, meta);

      const segParams = {};
      if (state.scope === 'ceara') segParams.uf = 'CE';
      else if (state.scope === 'fortaleza') segParams.city_ibge = '2304400';
      renderSegmentation(segParams);

      if (!metrics.length) {
        setFeedback('Nenhuma geografia consolidada foi encontrada para o periodo. Se houver vendas no recorte, use "Processar Malha" para recalcular a camada geo.', 'warning');
      } else {
        setFeedback('', '');
      }
    } catch (error) {
      console.error(error);
      renderGeoKpis([]);
      renderDrillDownTable([]);
      renderScopeRanking([]);
      setJoinHealth('Falha ao carregar geografia', 'danger');
      setFeedback(error?.message || 'Nao foi possivel carregar a geografia agora.', 'danger');
    } finally {
      setMapBusy(false);
    }
  }

  function handleTabClick(event) {
    const target = event.target.closest('.tab-btn');
    if (!target) return;

    document.querySelectorAll('.tab-btn').forEach((button) => button.classList.remove('active'));
    target.classList.add('active');
    state.scope = target.getAttribute('data-scope') || 'brasil';
    renderMapScope();
  }

  async function handleApplyClick() {
    state.start = document.getElementById('dateStart').value;
    state.end = document.getElementById('dateEnd').value;
    state.mapDataCache = {};
    setFeedback('Recalculando malha geografica do periodo...', 'info');

    try {
      await materializeGeoData();
      state.mapDataCache = {};
      setFeedback('Malha geografica recalculada. Atualizando mapa e rankings...', 'success');
    } catch (error) {
      console.error(error);
      setFeedback(`${error?.message || 'Falha ao processar a malha.'} Exibindo fallback da camada canonica.`, 'warning');
    }

    await renderMapScope();
  }

  function initDateInputs() {
    const params = new URLSearchParams(window.location.search);
    if (params.has('scope')) {
      const requestedScope = params.get('scope') || 'fortaleza';
      state.scope = scopeViews[requestedScope] ? requestedScope : 'fortaleza';
    }
    if (params.has('start')) state.start = params.get('start') || '';
    if (params.has('end')) state.end = params.get('end') || '';

    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    const firstDay = `${yyyy}-${mm}-01`;
    const todayIso = `${yyyy}-${mm}-${dd}`;

    const startInput = document.getElementById('dateStart');
    const endInput = document.getElementById('dateEnd');
    if (startInput) startInput.value = state.start || firstDay;
    if (endInput) endInput.value = state.end || todayIso;
    state.start = startInput?.value || firstDay;
    state.end = endInput?.value || todayIso;

    document.querySelectorAll('.tab-btn').forEach((button) => {
      button.classList.toggle('active', button.getAttribute('data-scope') === state.scope);
    });
  }

  function bindPresetButtons() {
    document.querySelectorAll('[data-range-preset]').forEach((button) => {
      button.addEventListener('click', () => {
        applyPresetRange(button.getAttribute('data-range-preset'));
      });
    });
  }

  function init() {
    initDateInputs();
    bindPresetButtons();
    document.getElementById('scopeTabs')?.addEventListener('click', handleTabClick);
    document.getElementById('applyFilters')?.addEventListener('click', handleApplyClick);
    renderMapScope();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
