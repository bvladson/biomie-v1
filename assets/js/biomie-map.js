(function () {
  'use strict';

  const GEOJSON_URL = 'assets/geo/fortaleza_bairros.json';
  const FORTALEZA_BOUNDS = [[-3.92, -38.68], [-3.67, -38.36]];

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

  window.BIOMIE_GEO_DEBUG = false;

  function normalizeNeighborhoodKey(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/ç/g, 'c')
      .replace(/[^\w\s-]/g, ' ')
      .replace(/[-_]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function getApiNeighborhoodName(row) {
    return row.bairro
      || row.neighborhood
      || row.bairro_nome
      || row.bairro_oficial
      || row.nome_bairro
      || row.name
      || row.label
      || '';
  }

  function getFeatureNeighborhoodName(feature) {
    const p = feature && feature.properties ? feature.properties : {};
    return p.nome
      || p.name
      || p.bairro
      || p.bairro_nome
      || p.nome_bairro
      || p.NM_BAIRRO
      || p.NOME
      || p.Name
      || p.NM_NOME
      || p.NOM_BAIRRO
      || '';
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
    node.innerHTML = `
      <div>
        <strong>${escapeHtml(title)}</strong>
        <p>${escapeHtml(message)}</p>
      </div>
      ${action ? `<button type="button" class="btn-secondary" id="mapStateAction">${escapeHtml(action)}</button>` : ''}
    `;
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
    setText('diagMaterialized', current.materialized || '-');
    setText('diagGeoCount', current.geoCount ?? '-');
    setText('diagGeoUrl', current.geoUrl || GEOJSON_URL);
    setText('diagGeoProperty', current.geoProperty || '-');
    setText('diagGeoNoName', current.geoNoName ?? '-');
    setText('diagSalesCount', current.salesCount ?? '-');
    setText('diagNoNeighborhood', current.noNeighborhood ?? '-');
    setText('diagGoldTable', current.goldTable || '-');
    setText('diagGoldRows', current.goldRows ?? '-');
    setText('diagMatchRate', current.matchRate ?? '-');
    setText('diagMotivoZero', current.motivoZero ?? '-');
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

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal,
      });
      const payload = await response.json().catch(() => ({}));
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
        throw error;
      }

      return payload;
    } finally {
      if (state.controllers.get(key) === controller) {
        state.controllers.delete(key);
      }
    }
  }

  async function loadGeoJson() {
    if (state.geoJson) return state.geoJson;
    updateDiagnostics({ geoUrl: GEOJSON_URL });
    const response = await fetch(GEOJSON_URL, { cache: 'force-cache' });
    if (!response.ok) {
      updateDiagnostics({ geoProperty: 'erro ao carregar', geoNoName: '-', error: `GeoJSON HTTP ${response.status}` });
      throw new Error(`Falha ao carregar mapa de bairros (HTTP ${response.status}).`);
    }
    const geoJson = await response.json();
    const features = geoJson.features || [];
    const detectedProp = detectGeoProperty(features);
    state.detectedGeoProperty = detectedProp;
    let noName = 0;
    features.forEach((feature) => {
      feature.properties = feature.properties || {};
      const name = getFeatureNeighborhoodName(feature);
      if (!name) noName++;
      feature.properties.neighborhood_key = normalizeNeighborhoodKey(name || 'sem nome');
    });
    state.featuresWithoutName = noName;
    state.geoJson = geoJson;
    updateDiagnostics({
      geoCount: features.length,
      geoProperty: detectedProp || 'nenhuma detectada',
      geoNoName: noName,
    });
    return geoJson;
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
    return '#f7c7b7';
  }

  function featureStyle(feature) {
    const rawName = getFeatureNeighborhoodName(feature);
    const key = normalizeNeighborhoodKey(rawName);
    const row = state.revenueByNeighborhoodKey.get(key);
    const selected = key && key === state.selectedKey;
    const revenue = row ? Number(row.revenue || row.faturamento || row.total || row.valor || 0) : 0;
    
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
      fillColor: '#e5e7eb',
      weight: 0.8,
      opacity: 0.7,
      color: '#ffffff',
      fillOpacity: 0.25,
    };
  }

  function tooltipHtml(feature) {
    const rawName = getFeatureNeighborhoodName(feature);
    const key = normalizeNeighborhoodKey(rawName);
    const row = state.revenueByNeighborhoodKey.get(key);
    const label = rawName || 'Sem nome';
    if (!row) {
      return `<div class="biomie-map-tooltip"><strong>${escapeHtml(label)}</strong><span>Sem dados comerciais</span></div>`;
    }
    return `
      <div class="biomie-map-tooltip">
        <strong>${escapeHtml(row.bairro || row.neighborhood || label)}</strong>
        <span><em>Faturamento</em><b>${formatters.money(row.revenue || row.faturamento || row.valor || 0)}</b></span>
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
        const key = normalizeNeighborhoodKey(rawName);
        selectNeighborhood(key, rawName);
        event.target.setStyle(featureStyle(feature));
        event.target.bringToFront?.();
      },
    });
  }

  function renderGeoLayer() {
    if (!state.map || !state.geoJson) return;

    if (state.geoLayer) {
      state.geoLayer.clearLayers();
      state.map.removeLayer(state.geoLayer);
      state.geoLayer = null;
    }

    let paintedFeatures = 0;
    const featuresWithNoData = [];

    state.geoLayer = L.geoJson(state.geoJson, {
      style: (feature) => {
        const style = featureStyle(feature);
        if (style.fillColor !== '#e5e7eb') paintedFeatures++;
        else featuresWithNoData.push(feature);
        return style;
      },
      onEachFeature(feature, layer) {
        bindLayer(layer, feature);
      },
    }).addTo(state.map);

    if (window.BIOMIE_GEO_DEBUG) {
      console.log('[BIOMIE_GEO_DEBUG] Bairros encontrados na API:', Array.from(state.rowsByKey.keys()));
      console.log('[BIOMIE_GEO_DEBUG] Features pintadas com dados:', paintedFeatures);
      console.log('[BIOMIE_GEO_DEBUG] Features sem dados (vazios/cinza):', featuresWithNoData.length);
    }
    
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
  }

  function renderLegend() {
    const node = $('mapLegend');
    if (!node) return;
    const scale = state.scale || { buckets: [] };
    const buckets = scale.buckets || [];
    node.innerHTML = buckets.map((bucket) => {
      const color = bucket.min === null ? '#e5e7eb' : getColorByRevenue(bucket.max || bucket.min || 1, true);
      return `
        <span class="biomie-map-legend__item">
          <span class="biomie-map-legend__swatch" style="background:${color}"></span>
          ${escapeHtml(bucket.label)}
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
    const top = state.rows.find((r) => Number(r.revenue || 0) > 0);
    setText('geoKpiTopArea', top?.neighborhood || 'Sem bairro com venda no periodo');

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
    const visibleRows = state.rows.slice(0, 10);
    const maxRevenue  = Math.max(...visibleRows.map((r) => Number(r.revenue || 0)), 1);
    tbody.innerHTML = visibleRows.map((row) => {
      const rev     = Number(row.revenue || 0);
      const barPct  = Math.round((rev / maxRevenue) * 100);
      const hasData = rev > 0;
      const rowStyle = hasData ? '' : 'opacity:0.42;';
      const key     = normalizeNeighborhoodKey(row.neighborhood_key || row.neighborhood);
      return `
        <tr class="geo-row-clickable" data-key="${escapeHtml(key)}" data-label="${escapeHtml(row.neighborhood)}" style="${rowStyle}cursor:pointer;" title="Clique para selecionar ${escapeHtml(row.neighborhood)} no mapa">
          <td style="width:28px;padding-right:4px;color:var(--mag-muted);font-size:12px;">${row.rank}</td>
          <td>
            <span style="font-weight:500;">${escapeHtml(row.neighborhood)}</span>
            ${hasData ? `<div style="margin-top:3px;height:4px;border-radius:2px;background:var(--mag-border,#e5e7eb);overflow:hidden;"><div style="width:${barPct}%;height:4px;border-radius:2px;background:var(--mag-accent-1,#96140a);"></div></div>` : ''}
          </td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;">
            ${hasData ? `<strong>${formatters.money(rev)}</strong>` : '<span style="color:var(--mag-muted)">-</span>'}
          </td>
        </tr>
      `;
    }).join('');

    // Delegate click to select neighborhood on map
    tbody.onclick = function (e) {
      const tr = e.target.closest('tr[data-key]');
      if (!tr) return;
      selectNeighborhood(tr.dataset.key, tr.dataset.label);
    };
  }

  function renderDrilldown() {
    const tbody = $('drillDownTable');
    if (!tbody) return;
    if (!state.rows.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--mag-muted);padding:18px;">Nenhuma venda encontrada para os filtros selecionados.</td></tr>';
      return;
    }
    const total = state.rows.reduce((sum, row) => sum + Number(row.revenue || 0), 0);
    tbody.innerHTML = state.rows.map((row) => {
      const rev       = Number(row.revenue || 0);
      const sharePct  = total > 0 ? (rev / total) * 100 : 0;
      const shareStr  = `${sharePct.toFixed(1)}%`;
      const barWidth  = Math.round(sharePct);
      const hasData   = rev > 0;
      const rowStyle  = hasData ? '' : 'opacity:0.42;';
      const key       = normalizeNeighborhoodKey(row.neighborhood_key || row.neighborhood);
      return `
        <tr class="geo-row-clickable" data-key="${escapeHtml(key)}" data-label="${escapeHtml(row.neighborhood)}" style="${rowStyle}cursor:pointer;" title="Clique para selecionar ${escapeHtml(row.neighborhood)} no mapa">
          <td>
            <strong>${escapeHtml(row.neighborhood)}</strong>
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

    // Delegate click to select neighborhood on map
    tbody.onclick = function (e) {
      const tr = e.target.closest('tr[data-key]');
      if (!tr) return;
      selectNeighborhood(tr.dataset.key, tr.dataset.label);
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
    window.BIomieMapPanel?.renderNeighborhood(row, formatters);

    const params = currentFilters({ neighborhood: row.neighborhood, limit: 10 });
    const [buyers, cnaes] = await Promise.allSettled([
      apiFetch('api/top_buyers_by_geo.php', params, 'buyers'),
      apiFetch('api/sales_by_cnae.php', params, 'cnae'),
    ]);

    if (buyers.status === 'fulfilled') {
      window.BIomieMapPanel?.renderBuyers(buyers.value.data || [], formatters);
    } else {
      window.BIomieMapPanel?.renderBuyers([], formatters);
    }

    if (cnaes.status === 'fulfilled') {
      window.BIomieMapPanel?.renderCnaes(cnaes.value.data || [], formatters);
    } else {
      window.BIomieMapPanel?.renderCnaes([], formatters);
    }
  }

  function selectNeighborhood(key, label) {
    state.selectedKey = key;
    if ($('neighborhoodFilter')) $('neighborhoodFilter').value = label || '';
    state.geoLayer?.eachLayer((layer) => layer.setStyle(featureStyle(layer.feature)));
    const row = state.rowsByKey.get(key) || {
      city: $('cityFilter')?.value || 'Fortaleza',
      neighborhood: label,
      revenue: 0,
      customers: 0,
      invoices: 0,
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
    if (!state.rows.length || !state.geoJson) return '-';
    const geoKeys = new Set((state.geoJson.features || []).map((f) => f?.properties?.neighborhood_key).filter(Boolean));
    const matched = state.rows.filter((r) => geoKeys.has(normalizeNeighborhoodKey(r.neighborhood_key || r.neighborhood))).length;
    return `${matched}/${state.rows.length} bairros com match`;
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

      state.rows = Array.isArray(neighborhoodsPayload.data) ? neighborhoodsPayload.data : [];
      state.scale = neighborhoodsPayload.scale || null;
      state.summary = summaryPayload.summary || null;
      state._neighborhoodsPayload = neighborhoodsPayload;
      state._summaryPayload = summaryPayload;
      
      state.revenueByNeighborhoodKey = new Map();
      let api_neighborhood_count = 0;
      state.rows.forEach(row => {
        const rawName = getApiNeighborhoodName(row);
        const key = normalizeNeighborhoodKey(rawName);
        const revenue = Number(row.revenue || row.faturamento || row.total || row.valor || 0);
        if (revenue > 0) {
          state.revenueByNeighborhoodKey.set(key, row);
          api_neighborhood_count++;
        }
      });
      state.rowsByKey = state.revenueByNeighborhoodKey;
      state.geoDebug.apiRowsSample = state.rows.slice(0, 10);
      state.geoDebug.apiKeys = Array.from(state.revenueByNeighborhoodKey.keys());
      state.geoDebug.api_neighborhood_count = api_neighborhood_count;

      const diagFromApi = summaryPayload.diagnostics || {};
      const noNeighborhood = state.summary?.rows_without_neighborhood || 0;
      updateDiagnostics({
        salesCount: state.rows.length,
        noNeighborhood,
        goldTable: diagFromApi.source_table || 'mart_compradores_por_geo',
        goldRows: diagFromApi.gold_rows_in_period ?? state.summary?.invoices ?? '-',
        matchRate: computeMatchRate(),
        motivoZero: diagFromApi.motivo_zero || '-',
        rowsBeforeCity: diagFromApi.rows_before_city_filter ?? '-',
        rowsAfterCity: diagFromApi.rows_after_city_filter ?? '-',
        canonicalNfs: diagFromApi.canonical_nfs_no_periodo ?? '-',
        geoMartNotas: diagFromApi.geo_mart_notas_no_periodo ?? '-',
        error: '-',
      });

      renderSummary(summaryPayload);
      renderRanking();
      renderDrilldown();
      renderGeoLayer(geoJson);
      renderLegend();
      resetSelection(false);
      showEnrichCard(noNeighborhood);
      renderCoverageBar(summaryPayload);

      // Populate side panel with global top buyers and CNAEs (no neighborhood selected)
      const globalBuyers = summaryPayload.top_buyers || [];
      const globalCnaes  = summaryPayload.top_cnaes  || [];
      window.BIomieMapPanel?.setGlobal(globalBuyers, globalCnaes, formatters);

      if (!state.rows.length || state.rows.every((r) => Number(r.revenue || 0) <= 0)) {
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
          showState('empty', 'Nenhuma venda encontrada para os filtros selecionados.', 'Altere o periodo ou revise os filtros comerciais.', 'Alterar filtros');
        }
      } else if (state.geoDebug.api_neighborhood_count > 0 && state._paintedFeatures === 0) {
        showState('danger', 'Há dados de faturamento, mas nenhum polígono foi pintado. Erro de casamento API x GeoJSON.', 'Verificar normalização dos nomes dos bairros.', 'Limpar filtros');
      }
    } catch (error) {
      if (error.name === 'AbortError') return;
      const payload = error.payload || {};
      const errorCode = payload.error || '';
      const isMissingTable = error.status === 409 || errorCode === 'missing_analytic_table';
      const isMigrationPending = errorCode === 'migration_pending';
      const isMatMissing = errorCode === 'materialization_missing';
      const isOmie = errorCode === 'omie_data_not_synced';

      state.rows = [];
      state.rowsByKey = new Map();
      renderRanking();
      renderDrilldown();
      renderGeoLayer();
      renderLegend();
      resetSelection(false);
      showEnrichCard(0);

      if (isMigrationPending) {
        showState('danger', 'Os dados analiticos ainda nao foram preparados.', 'Aplique as migrations pendentes no painel de Administracao.', 'Tentar novamente');
      } else if (isMatMissing) {
        showState('danger', 'O mapa ainda nao foi materializado.', 'Execute a materializacao dos marts para gerar os dados geograficos.', 'Tentar novamente');
      } else if (isMissingTable) {
        showState('danger', 'Os dados geograficos ainda nao foram preparados.', 'Execute as migrations e a materializacao dos marts.', 'Tentar novamente');
      } else if (isOmie) {
        showState('danger', 'Os dados do Omie ainda nao foram sincronizados para este periodo.', 'Verifique a ultima sincronizacao antes de consultar o mapa.', 'Tentar novamente');
      } else {
        showState('danger', 'Nao foi possivel carregar o mapa comercial agora.', 'Tente novamente em alguns instantes.', 'Tentar novamente');
      }
      updateDiagnostics({ error: payload.error || 'api_error' });
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
    if (params.get('date_from') || params.get('start')) $('dateStart').value = params.get('date_from') || params.get('start');
    if (params.get('date_to') || params.get('end')) $('dateEnd').value = params.get('date_to') || params.get('end');
    if (params.get('city') || params.get('cidade')) $('cityFilter').value = params.get('city') || params.get('cidade');
    if (params.get('neighborhood') || params.get('bairro')) $('neighborhoodFilter').value = params.get('neighborhood') || params.get('bairro');
    if (params.get('cnae')) $('cnaeFilter').value = params.get('cnae');
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
