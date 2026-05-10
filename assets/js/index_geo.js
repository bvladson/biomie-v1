(function () {
  'use strict';

  const charts = {};
  const bootstrapState = {
    geoHydrated: false,
  };
  let fortalezaGeoJsonPromise = null;

  function formatMoney(value) {
    if (value == null) return 'R$ 0,00';
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(Number(value || 0));
  }

  function setText(id, value) {
    const node = document.getElementById(id);
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

  function normalizeGeoKey(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim()
      .toUpperCase();
  }

  function getCssVar(name, fallback) {
    const value = window.getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
  }

  function geoTheme() {
    return {
      brand: getCssVar('--brand-primary', '#96140a'),
      brandDark: getCssVar('--brand-dark', '#7c0600'),
      brandSoft: getCssVar('--brand-primary-soft', 'rgba(150, 20, 10, 0.12)'),
      warning: getCssVar('--warning', '#d97706'),
      warningSoft: getCssVar('--warning-soft', 'rgba(217, 119, 6, 0.14)'),
      infoSoft: getCssVar('--info-soft', 'rgba(37, 99, 235, 0.1)'),
      text: getCssVar('--text-secondary', '#5d6678'),
      surface: getCssVar('--surface-card', '#ffffff'),
      tooltipBg: getCssVar('--chart-tooltip-bg', '#ffffff'),
      tooltipBorder: getCssVar('--chart-tooltip-border', 'rgba(0, 0, 0, 0.12)'),
      tooltipTitle: getCssVar('--chart-tooltip-title', '#111318'),
      tooltipText: getCssVar('--chart-tooltip-text', '#4b5263'),
    };
  }

  async function fetchJson(url, options) {
    if (typeof window.fetchJson === 'function') {
      return window.fetchJson(url, options);
    }

    const response = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options || {}));
    return response.json();
  }

  function hideGeoSection(reason) {
    const region = document.getElementById('geo-executivo');
    const section = region?.matches('details') ? null : region?.nextElementSibling;
    if (region) region.style.display = 'none';
    if (section?.classList.contains('wallboard-grid')) section.style.display = 'none';
    renderHomeNeighborhoodMap([]);
    if (reason) console.warn(reason);
  }

  function ensureCanvasState(canvas) {
    if (!canvas) return;
    canvas.style.display = '';
    const fallback = canvas.parentElement?.querySelector('.geo-empty-state');
    if (fallback) fallback.remove();
  }

  function deferUntilDetailsOpen(canvas, renderFn) {
    const details = canvas?.closest('details:not([open])');
    if (!details) return false;

    details.addEventListener('toggle', function handleToggle() {
      if (!details.open) return;
      details.removeEventListener('toggle', handleToggle);
      renderFn();
    });
    return true;
  }

  function renderCanvasFallback(canvas, title, message) {
    if (!canvas || !canvas.parentElement) return;
    ensureCanvasState(canvas);
    if (canvas.id && window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.destroyChart === 'function') {
      window.BIOMIE_CHARTS.destroyChart(canvas.id);
    }
    canvas.style.display = 'none';

    const fallback = document.createElement('div');
    fallback.className = 'module-alert module-alert--info geo-empty-state';
    fallback.innerHTML = `<strong>${title}</strong><span>${message}</span>`;
    canvas.parentElement.appendChild(fallback);
  }

  function renderGeoChart(canvas, key, config, emptyMessage) {
    if (!canvas || !key) return null;
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.renderChartSafe === 'function') {
      const chart = window.BIOMIE_CHARTS.renderChartSafe(canvas.id, config, {
        message: emptyMessage || 'Sem dados geograficos para este grafico.',
        allowZeroData: false,
      });
      charts[key] = chart;
      return chart;
    }
    return new Chart(canvas, config);
  }

  function buildQuery() {
    const start = document.getElementById('dateStart')?.value || '';
    const end = document.getElementById('dateEnd')?.value || '';
    const params = new URLSearchParams();
    if (start) params.set('start', start);
    if (end) params.set('end', end);
    return params.toString();
  }

  function getPageBootstrap() {
    const payload = window.__BIOMIE_BOOTSTRAP__;
    return payload && typeof payload === 'object' ? payload : null;
  }

  function bootstrapMatchesCurrentRange(bundle) {
    if (!bundle || !bundle.range) return false;
    const start = document.getElementById('dateStart')?.value || String(bundle.range.start || '');
    const end = document.getElementById('dateEnd')?.value || String(bundle.range.end || '');
    return String(bundle.range.start || '') === String(start)
      && String(bundle.range.end || '') === String(end);
  }

  function hydrateGeoFromBootstrap() {
    const bundle = getPageBootstrap();
    if (!bundle || bootstrapState.geoHydrated || !bootstrapMatchesCurrentRange(bundle)) {
      return false;
    }

    const geo = bundle.geo_index;
    if (!geo) {
      return false;
    }

    if (geo.summary) {
      renderGeoSummary(geo.summary);
    } else {
      renderGeoSummaryError('Geografia indisponivel no bootstrap inicial.');
    }

    renderTopCities(geo.cities?.data || []);
    renderTopNeighborhoods(geo.neighborhoods?.data || []);
    renderHomeNeighborhoodMap(geo.neighborhoods?.data || []);
    bootstrapState.geoHydrated = true;
    return true;
  }

  function renderGeoSummary(payload) {
    const summary = payload?.summary || {};
    const coverage = payload?.coverage || {};
    const lifecycle = payload?.lifecycle || {};
    const sourceMode = payload?.meta?.source_mode === 'aggregate'
      ? 'malha materializada'
      : (payload?.meta?.source_mode === 'fact_fallback' ? 'fact geo' : 'fallback canonico');

    setText('kpi-recurrent-percent', `${Number(lifecycle.recurrent || 0).toFixed(1)}%`);
    setText('kpi-recurrent-rev', `${formatMoney(lifecycle.rev_recurrent || 0)} Retidos | ${sourceMode}`);

    const coveragePct = coverage.missing_percent == null
      ? 0
      : Math.max(0, 100 - Number(coverage.missing_percent || 0));
    setText('kpi-geo-coverage', `${coveragePct.toFixed(1)}%`);
    const meter = document.getElementById('geoIndexMeter');
    if (meter) meter.style.setProperty('--meter-billed', `${coveragePct}%`);

    renderProfileChart(summary);
    renderCnaeChart(Array.isArray(payload?.segments) ? payload.segments : []);
  }

  function renderGeoSummaryError(message) {
    setText('kpi-recurrent-percent', '-');
    setText('kpi-recurrent-rev', message || 'Geografia indisponivel no momento.');
    setText('kpi-geo-coverage', '-');
    const meter = document.getElementById('geoIndexMeter');
    if (meter) meter.style.setProperty('--meter-billed', '0%');

    renderCanvasFallback(document.getElementById('indexProfileChart'), 'Perfil geografico indisponivel', message || 'Nao foi possivel carregar o share PF x PJ agora.');
    renderCanvasFallback(document.getElementById('indexCnaeChart'), 'Segmentacao indisponivel', 'A leitura CNAE ficara visivel assim que a camada geo responder.');
  }

  function renderProfileChart(summary) {
    const canvas = document.getElementById('indexProfileChart');
    if (!canvas) return;
    if (deferUntilDetailsOpen(canvas, () => renderProfileChart(summary))) return;
    const theme = geoTheme();

    const values = [
      Number(summary?.pj_value_raw || 0),
      Number(summary?.pf_value_raw || 0),
    ];
    if (values[0] <= 0 && values[1] <= 0) {
      renderCanvasFallback(canvas, 'Sem distribuicao PF x PJ', 'Nao houve faturamento geografico suficiente para montar o perfil no recorte.');
      if (charts.profile) charts.profile.destroy();
      return;
    }

    ensureCanvasState(canvas);
    if (charts.profile) charts.profile.destroy();

    charts.profile = renderGeoChart(canvas, 'profile', {
      type: 'bar',
      data: {
        labels: ['PJ', 'PF'],
        datasets: [{
          label: 'Receita',
          data: values,
          backgroundColor: [theme.brand, theme.warning],
          borderColor: [theme.brandDark, theme.warning],
          borderWidth: 1,
          borderRadius: 8,
          maxBarThickness: 28,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: theme.tooltipBg,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
            titleColor: theme.tooltipTitle,
            bodyColor: theme.tooltipText,
            callbacks: {
              label: function (ctx) {
                const total = ctx.dataset.data.reduce((acc, current) => acc + current, 0);
                const pct = total > 0 ? `${((ctx.raw / total) * 100).toFixed(1)}%` : '0%';
                return ` ${pct} (${formatMoney(ctx.raw)})`;
              },
            },
          },
        },
        scales: {
          x: { display: false, grid: { display: false } },
          y: { grid: { display: false }, ticks: { color: theme.text, maxTicksLimit: 2 } },
        },
      },
    });
  }

  function renderCnaeChart(segments) {
    const canvas = document.getElementById('indexCnaeChart');
    if (!canvas) return;
    if (deferUntilDetailsOpen(canvas, () => renderCnaeChart(segments))) return;
    const theme = geoTheme();

    const rows = Array.isArray(segments) ? segments.slice(0, 8) : [];
    if (!rows.length) {
      renderCanvasFallback(canvas, 'Sem segmentos geograficos', 'A distribuicao por CNAE aparecera assim que houver dados geograficos no periodo.');
      if (charts.cnae) charts.cnae.destroy();
      return;
    }

    ensureCanvasState(canvas);
    if (charts.cnae) charts.cnae.destroy();

    charts.cnae = renderGeoChart(canvas, 'cnae', {
      type: 'bar',
      data: {
        labels: rows.map((segment) => {
          const label = String(segment?.name || 'N/D');
          return label.length > 20 ? `${label.slice(0, 18)}..` : label;
        }),
        datasets: [{
          data: rows.map((segment) => Number(segment?.value_raw || 0)),
          backgroundColor: rows.map((_, index) => [theme.brandDark, theme.brand, theme.warning, theme.warningSoft, theme.infoSoft][index % 5]),
          borderColor: theme.brand,
          borderWidth: 1,
          borderRadius: 8,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: theme.tooltipBg,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
            titleColor: theme.tooltipTitle,
            bodyColor: theme.tooltipText,
            callbacks: {
              label: function (ctx) {
                return formatMoney(ctx.raw);
              },
            },
          },
        },
        scales: {
          x: { display: false, grid: { display: false } },
          y: { grid: { display: false }, ticks: { color: theme.text, maxTicksLimit: 8 } },
        },
      },
    });
  }

  function renderTopCities(rows) {
    const canvas = document.getElementById('indexCitiesChart');
    if (!canvas) return;
    if (deferUntilDetailsOpen(canvas, () => renderTopCities(rows))) return;
    const theme = geoTheme();

    const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
    if (!data.length) {
      renderCanvasFallback(canvas, 'Sem cidades no recorte', 'Nao houve pontos geograficos suficientes para montar o ranking nacional de cidades.');
      if (charts.cities) charts.cities.destroy();
      return;
    }

    ensureCanvasState(canvas);
    if (charts.cities) charts.cities.destroy();

    charts.cities = renderGeoChart(canvas, 'cities', {
      type: 'bar',
      data: {
        labels: data.map((row) => row?.name || 'N/D'),
        datasets: [{
          data: data.map((row) => Number(row?.value_raw || 0)),
          backgroundColor: theme.brandSoft,
          borderColor: theme.brand,
          borderWidth: 1,
          borderRadius: 10,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: theme.tooltipBg,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
            titleColor: theme.tooltipTitle,
            bodyColor: theme.tooltipText,
            callbacks: {
              label: function (ctx) {
                return formatMoney(ctx.raw);
              },
            },
          },
        },
        scales: {
          x: { display: false },
          y: { grid: { display: false }, ticks: { color: theme.text, maxTicksLimit: 8 } },
        },
      },
    });
  }

  function neighborhoodLabel(row) {
    return String(row?.name || row?.bairro_nome || row?.neighborhood || row?.label || 'N/D');
  }

  function loadFortalezaGeoJson() {
    if (!fortalezaGeoJsonPromise) {
      fortalezaGeoJsonPromise = fetch('assets/geo/fortaleza_bairros.json', { cache: 'force-cache' })
        .then((response) => response.json());
    }
    return fortalezaGeoJsonPromise;
  }

  function geometryRings(geometry) {
    if (!geometry || !Array.isArray(geometry.coordinates)) return [];
    if (geometry.type === 'Polygon') return geometry.coordinates;
    if (geometry.type === 'MultiPolygon') {
      return geometry.coordinates.reduce((rings, polygon) => rings.concat(polygon || []), []);
    }
    return [];
  }

  function geoBounds(features) {
    const bounds = { minLng: Infinity, maxLng: -Infinity, minLat: Infinity, maxLat: -Infinity };
    features.forEach((feature) => {
      geometryRings(feature.geometry).forEach((ring) => {
        ring.forEach((coord) => {
          const lng = Number(coord?.[0]);
          const lat = Number(coord?.[1]);
          if (!Number.isFinite(lng) || !Number.isFinite(lat)) return;
          bounds.minLng = Math.min(bounds.minLng, lng);
          bounds.maxLng = Math.max(bounds.maxLng, lng);
          bounds.minLat = Math.min(bounds.minLat, lat);
          bounds.maxLat = Math.max(bounds.maxLat, lat);
        });
      });
    });
    return bounds;
  }

  function projectPoint(coord, bounds, width, height, padding) {
    const lngRange = Math.max(0.0001, bounds.maxLng - bounds.minLng);
    const latRange = Math.max(0.0001, bounds.maxLat - bounds.minLat);
    const lng = Number(coord?.[0]);
    const lat = Number(coord?.[1]);
    const x = padding + ((lng - bounds.minLng) / lngRange) * (width - padding * 2);
    const y = padding + ((bounds.maxLat - lat) / latRange) * (height - padding * 2);
    return `${x.toFixed(1)} ${y.toFixed(1)}`;
  }

  function ringPath(ring, bounds, width, height, padding) {
    return ring.map((coord, index) => `${index === 0 ? 'M' : 'L'}${projectPoint(coord, bounds, width, height, padding)}`).join(' ') + ' Z';
  }

  function renderHomeNeighborhoodRanking(rows) {
    const target = document.getElementById('indexNeighborhoodsRanking');
    if (!target) return;

    const data = Array.isArray(rows) ? rows.slice(0, 5) : [];
    const total = (Array.isArray(rows) ? rows : []).reduce((sum, row) => sum + Number(row?.value_raw || 0), 0);
    if (!data.length) {
      target.innerHTML = '<tr><td colspan="3" class="text-muted">Sem bairros no periodo.</td></tr>';
      return;
    }

    target.innerHTML = data.map((row) => {
      const value = Number(row?.value_raw || 0);
      const share = Number.isFinite(Number(row?.share)) && Number(row?.share) > 0
        ? Number(row.share)
        : (total > 0 ? (value / total) * 100 : 0);
      return `
        <tr>
          <td>${escapeHtml(neighborhoodLabel(row))}</td>
          <td><strong>${formatMoney(value)}</strong></td>
          <td>${share.toFixed(1)}%</td>
        </tr>
      `;
    }).join('');
  }

  async function renderHomeNeighborhoodMap(rows) {
    renderHomeNeighborhoodRanking(rows);

    const container = document.getElementById('indexFortalezaMap');
    if (!container) return;

    const data = Array.isArray(rows) ? rows : [];
    if (!data.length) {
      container.textContent = 'Sem bairros no periodo.';
      return;
    }

    try {
      const geoJson = await loadFortalezaGeoJson();
      const features = Array.isArray(geoJson?.features) ? geoJson.features : [];
      const bounds = geoBounds(features);
      if (!features.length || !Number.isFinite(bounds.minLng)) {
        container.textContent = 'Mapa indisponivel.';
        return;
      }

      const revenueByKey = new Map(data.map((row) => [normalizeGeoKey(neighborhoodLabel(row)), Number(row?.value_raw || 0)]));
      const maxRevenue = Math.max(1, ...data.map((row) => Number(row?.value_raw || 0)));
      const width = 640;
      const height = 320;
      const padding = 18;
      const paths = features.map((feature) => {
        const label = String(feature?.properties?.bairro_nome || 'Bairro');
        const value = revenueByKey.get(normalizeGeoKey(label)) || 0;
        const intensity = value > 0 ? Math.max(0.18, value / maxRevenue) : 0;
        const fill = value > 0 ? `rgba(150,20,10,${(0.18 + intensity * 0.62).toFixed(2)})` : '#e7ecf2';
        const d = geometryRings(feature.geometry)
          .map((ring) => ringPath(ring, bounds, width, height, padding))
          .join(' ');
        return `<path d="${d}" fill="${fill}" stroke="#ffffff" stroke-width="2"><title>${escapeHtml(label)} - ${formatMoney(value)}</title></path>`;
      }).join('');

      container.innerHTML = `
        <svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Mapa de Fortaleza por bairros">
          <rect width="${width}" height="${height}" fill="transparent"></rect>
          ${paths}
        </svg>
      `;
    } catch (error) {
      console.warn(error);
      container.textContent = 'Mapa indisponivel.';
    }
  }

  function renderTopNeighborhoods(rows) {
    const canvas = document.getElementById('indexNeighborhoodsChart');
    if (!canvas) return;
    if (deferUntilDetailsOpen(canvas, () => renderTopNeighborhoods(rows))) return;
    const theme = geoTheme();

    const data = Array.isArray(rows) ? rows.slice(0, 8) : [];
    if (!data.length) {
      renderCanvasFallback(canvas, 'Sem bairros no recorte', 'Nao houve vendas geocodificadas em Fortaleza para montar o ranking local.');
      if (charts.neighborhoods) charts.neighborhoods.destroy();
      return;
    }

    ensureCanvasState(canvas);
    if (charts.neighborhoods) charts.neighborhoods.destroy();

    charts.neighborhoods = renderGeoChart(canvas, 'neighborhoods', {
      type: 'bar',
      data: {
        labels: data.map((row) => row?.name || 'N/D'),
        datasets: [{
          data: data.map((row) => Number(row?.value_raw || 0)),
          backgroundColor: theme.warningSoft,
          borderColor: theme.warning,
          borderWidth: 1,
          borderRadius: 10,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: theme.tooltipBg,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
            titleColor: theme.tooltipTitle,
            bodyColor: theme.tooltipText,
            callbacks: {
              label: function (ctx) {
                return formatMoney(ctx.raw);
              },
            },
          },
        },
        scales: {
          x: { display: false },
          y: { grid: { display: false }, ticks: { color: theme.text, maxTicksLimit: 8 } },
        },
      },
    });
  }

  async function fetchGeoExecutivo() {
    if (window.BIomieFeatureFlags?.geo_index_enabled === false) {
      hideGeoSection('Geo executivo desabilitado por feature flag.');
      return;
    }

    const query = buildQuery();
    const requests = await Promise.allSettled([
      fetchJson(`api/geo_summary.php?${query}`, { timeoutMs: 30000 }),
      fetchJson(`api/sales_by_city.php?uf=ALL&level=city&${query}`, { timeoutMs: 30000 }),
      fetchJson(`api/sales_by_neighborhood.php?city_ibge=2304400&${query}`, { timeoutMs: 30000 }),
    ]);

    if (requests[0].status === 'fulfilled') {
      renderGeoSummary(requests[0].value);
    } else {
      renderGeoSummaryError(requests[0].reason?.message || 'Falha ao carregar o resumo geografico.');
    }

    if (requests[1].status === 'fulfilled') {
      renderTopCities(requests[1].value?.data || []);
    } else {
      renderCanvasFallback(document.getElementById('indexCitiesChart'), 'Ranking de cidades indisponivel', requests[1].reason?.message || 'Nao foi possivel montar o ranking nacional agora.');
    }

    if (requests[2].status === 'fulfilled') {
      const neighborhoods = requests[2].value?.data || [];
      renderTopNeighborhoods(neighborhoods);
      renderHomeNeighborhoodMap(neighborhoods);
    } else {
      renderCanvasFallback(document.getElementById('indexNeighborhoodsChart'), 'Ranking de bairros indisponivel', requests[2].reason?.message || 'Nao foi possivel montar o ranking local agora.');
      renderHomeNeighborhoodMap([]);
    }
  }

  document.getElementById('applyFilters')?.addEventListener('click', fetchGeoExecutivo);
  const hydratedFromBootstrap = hydrateGeoFromBootstrap();
  if (!hydratedFromBootstrap) {
    setTimeout(fetchGeoExecutivo, 600);
  }
})();
