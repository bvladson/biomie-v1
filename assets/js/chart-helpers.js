(function () {
  'use strict';

  const instances = new Map();
  const stateSelector = '.mag-chart-loading, .mag-chart-empty, .mag-chart-error';

  function isDebug() {
    return document.documentElement.hasAttribute('data-debug-charts')
      || window.BIOMIE_DEBUG_CHARTS === true;
  }

  function warn(message, detail) {
    if (!isDebug() || !window.console) return;
    if (detail !== undefined) console.warn('[BIOMIE_CHARTS]', message, detail);
    else console.warn('[BIOMIE_CHARTS]', message);
  }

  function getCanvas(chartId) {
    if (!chartId) return null;
    const canvas = typeof chartId === 'string' ? document.getElementById(chartId) : chartId;
    if (!canvas || String(canvas.tagName).toLowerCase() !== 'canvas') return null;
    return canvas;
  }

  function hasSizeClass(element) {
    if (!element || !element.classList) return false;
    return Array.from(element.classList).some((name) => (
      /^mag-chart-(sm|md|lg|xl)$/.test(name)
      || /^fin-chart-(sm|md|lg|xl)$/.test(name)
      || name === 'chart-container-fixed'
      || name === 'detail-drawer-canvas'
      || name === 'fin-chart-stage'
      || name === 'biomie-chart-box'
      || name === 'short'
      || name === 'medium'
      || name === 'compact'
      || name === 'tall'
    ));
  }

  function getContainer(canvas) {
    if (!canvas) return null;
    const container = canvas.closest(
      '.mag-chart-container, .chart-container-fixed, .fin-chart-stage, .detail-drawer-canvas, .biomie-chart-box'
    ) || canvas.parentElement;
    if (!container) return null;

    container.classList.add('mag-chart-container');
    if (!hasSizeClass(container)) {
      container.classList.add(String(canvas.id || '').toLowerCase().includes('drawer') ? 'mag-chart-sm' : 'mag-chart-md');
    }
    if (canvas.id) container.setAttribute('data-chart-container', canvas.id);
    return container;
  }

  function normalizeNumber(value) {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;
    if (typeof value !== 'string') {
      const numberValue = Number(value);
      return Number.isFinite(numberValue) ? numberValue : null;
    }

    let raw = value.trim();
    if (!raw) return null;
    raw = raw.replace(/\s/g, '').replace(/^R\$/i, '');
    if (raw.includes(',') && raw.includes('.')) {
      raw = raw.replace(/\./g, '').replace(',', '.');
    } else if (raw.includes(',')) {
      raw = raw.replace(',', '.');
    }
    raw = raw.replace(/[^0-9.+-]/g, '');

    const numberValue = Number(raw);
    return Number.isFinite(numberValue) ? numberValue : null;
  }

  function normalizeDataArray(values) {
    if (!Array.isArray(values)) return [];
    return values.map((value) => {
      const normalized = normalizeNumber(value);
      return normalized === null ? value : normalized;
    });
  }

  function normalizeChartData(rawData) {
    if (!rawData || typeof rawData !== 'object') return { labels: [], datasets: [] };
    const data = { ...rawData };
    data.labels = Array.isArray(rawData.labels) ? rawData.labels.map((label) => String(label ?? '')) : [];
    data.datasets = Array.isArray(rawData.datasets)
      ? rawData.datasets.map((dataset) => ({
          ...dataset,
          data: normalizeDataArray(dataset && dataset.data),
        }))
      : [];
    return data;
  }

  function collectNumbers(dataOrDataset) {
    if (!dataOrDataset) return [];
    if (Array.isArray(dataOrDataset)) {
      return dataOrDataset.map(normalizeNumber).filter((value) => value !== null);
    }
    if (Array.isArray(dataOrDataset.datasets)) {
      return dataOrDataset.datasets.flatMap((dataset) => collectNumbers(dataset && dataset.data));
    }
    if (Array.isArray(dataOrDataset.data)) {
      return collectNumbers(dataOrDataset.data);
    }
    return [];
  }

  function hasValidChartData(dataOrDataset, options = {}) {
    const allowZeroData = options.allowZeroData !== false;
    const values = collectNumbers(dataOrDataset);
    if (!values.length) return false;
    if (allowZeroData) return values.some((value) => Number.isFinite(value));
    return values.some((value) => Number.isFinite(value) && value !== 0);
  }

  function countPoints(data) {
    if (!data || !Array.isArray(data.datasets)) return 0;
    return data.datasets.reduce((max, dataset) => Math.max(max, Array.isArray(dataset.data) ? dataset.data.length : 0), 0);
  }

  function chartTypeFromConfig(config) {
    return config?.type
      || config?.data?.datasets?.find((dataset) => dataset && dataset.type)?.type
      || 'bar';
  }

  function deepMerge(target, source) {
    const output = { ...(target || {}) };
    Object.keys(source || {}).forEach((key) => {
      const sourceValue = source[key];
      const targetValue = output[key];
      if (
        sourceValue
        && typeof sourceValue === 'object'
        && !Array.isArray(sourceValue)
        && targetValue
        && typeof targetValue === 'object'
        && !Array.isArray(targetValue)
      ) {
        output[key] = deepMerge(targetValue, sourceValue);
      } else if (sourceValue !== undefined) {
        output[key] = sourceValue;
      }
    });
    return output;
  }

  function applyDefaultChartOptions(options = {}, type = 'bar', data = null) {
    const defaults = {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      normalized: true,
      plugins: {
        tooltip: { enabled: true },
        legend: { display: false },
      },
    };

    const merged = deepMerge(defaults, options);
    const hasLineDataset = type === 'line' || Boolean(data?.datasets?.some((dataset) => dataset?.type === 'line'));
    const threshold = Number(options.decimationThreshold || 500);
    if (hasLineDataset && countPoints(data) > threshold) {
      merged.plugins = merged.plugins || {};
      merged.plugins.decimation = {
        enabled: true,
        algorithm: options.decimationAlgorithm || 'min-max',
      };
    }
    return merged;
  }

  function destroyChart(chartId) {
    const id = typeof chartId === 'string' ? chartId : chartId?.id;
    const canvas = getCanvas(chartId);
    const stored = id ? instances.get(id) : null;

    if (stored && typeof stored.destroy === 'function') {
      try { stored.destroy(); } catch (error) { warn('Falha ao destruir grafico armazenado.', error); }
      if (id) instances.delete(id);
    }

    if (window.Chart && typeof window.Chart.getChart === 'function') {
      const existing = canvas ? window.Chart.getChart(canvas) : (id ? window.Chart.getChart(id) : null);
      if (existing && existing !== stored && typeof existing.destroy === 'function') {
        try { existing.destroy(); } catch (error) { warn('Falha ao destruir grafico do Chart.js.', error); }
      }
    }
  }

  function getChart(chartId) {
    const id = typeof chartId === 'string' ? chartId : chartId?.id;
    if (id && instances.has(id)) return instances.get(id);
    const canvas = getCanvas(chartId);
    if (canvas && window.Chart && typeof window.Chart.getChart === 'function') {
      return window.Chart.getChart(canvas) || null;
    }
    return null;
  }

  function clearChartState(target) {
    const container = target?.matches?.('canvas') ? getContainer(target) : target;
    if (!container) return;
    container.querySelectorAll(stateSelector).forEach((element) => element.remove());
    const canvas = container.querySelector('canvas');
    if (canvas) {
      canvas.hidden = false;
      canvas.style.display = 'block';
      canvas.removeAttribute('aria-hidden');
    }
  }

  function renderChartState(target, type, message, actionLabel = null, actionHref = null, details = null) {
    const canvas = target?.matches?.('canvas') ? target : target?.querySelector?.('canvas');
    const container = canvas ? getContainer(canvas) : target;
    if (!container) return;

    clearChartState(container);
    if (canvas) {
      canvas.hidden = true;
      canvas.style.display = 'none';
      canvas.setAttribute('aria-hidden', 'true');
    }

    const state = document.createElement('div');
    state.className = `mag-chart-state mag-chart-${type}`;
    state.setAttribute('role', type === 'error' ? 'alert' : 'status');
    state.setAttribute('aria-live', type === 'loading' ? 'polite' : 'off');

    const icon = document.createElement('span');
    icon.className = 'material-symbols-rounded mag-chart-state__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = type === 'error' ? 'warning' : (type === 'loading' ? 'progress_activity' : 'query_stats');

    const text = document.createElement('span');
    text.className = 'mag-chart-state__message';
    text.textContent = message || defaultMessage(type);

    state.appendChild(icon);
    state.appendChild(text);

    if (actionLabel && actionHref) {
      const action = document.createElement('a');
      action.className = 'mag-chart-state__action';
      action.href = actionHref;
      action.textContent = actionLabel;
      state.appendChild(action);
    }

    if (details) {
      const detail = document.createElement('details');
      detail.className = 'mag-chart-state__details';
      const summary = document.createElement('summary');
      summary.textContent = 'Ver detalhes';
      const pre = document.createElement('pre');
      pre.textContent = String(details).slice(0, 1000);
      detail.appendChild(summary);
      detail.appendChild(pre);
      state.appendChild(detail);
    }

    container.appendChild(state);
  }

  function defaultMessage(type) {
    if (type === 'loading') return 'Carregando grafico...';
    if (type === 'error') return 'Nao foi possivel montar este grafico agora.';
    return 'Nenhum dado encontrado para este grafico.';
  }

  function renderChartEmptyState(container, message, actionLabel = null, actionHref = null) {
    renderChartState(container, 'empty', message || defaultMessage('empty'), actionLabel, actionHref);
  }

  function renderChartErrorState(container, message, details = null) {
    renderChartState(container, 'error', message || defaultMessage('error'), null, null, details);
  }

  function renderChartLoadingState(container, message = 'Carregando grafico...') {
    renderChartState(container, 'loading', message);
  }

  function renderChartSafe(chartId, config, emptyStateConfig = {}) {
    const canvas = getCanvas(chartId);
    if (!canvas) {
      warn('Canvas nao encontrado.', chartId);
      return null;
    }
    if (!window.Chart) {
      warn('Chart.js nao esta carregado.');
      return null;
    }

    const container = getContainer(canvas);
    const normalizedConfig = {
      ...(config || {}),
      data: normalizeChartData(config?.data || {}),
    };

    if (!hasValidChartData(normalizedConfig.data, emptyStateConfig)) {
      window.BIomieCharts.destroyChart(canvas);
      renderChartEmptyState(
        container,
        emptyStateConfig.message || defaultMessage('empty'),
        emptyStateConfig.actionLabel || null,
        emptyStateConfig.actionHref || null
      );
      return null;
    }

    const type = chartTypeFromConfig(normalizedConfig);
    normalizedConfig.options = applyDefaultChartOptions(normalizedConfig.options || {}, type, normalizedConfig.data);

    clearChartState(container);
    window.BIomieCharts.destroyChart(canvas);

    let chart = null;
    try {
      chart = new window.Chart(canvas.getContext('2d'), normalizedConfig);
      if (canvas.id) instances.set(canvas.id, chart);
      window.requestAnimationFrame(() => resizeChart(canvas.id || canvas));
    } catch (error) {
      renderChartErrorState(container, 'Nao foi possivel montar este grafico agora.', error.message);
      warn('Falha ao criar grafico.', error);
      return null;
    }
    return chart;
  }

  function resizeChart(chartId) {
    const chart = getChart(chartId);
    if (chart && typeof chart.resize === 'function') {
      try { chart.resize(); } catch (error) { warn('Falha ao redimensionar grafico.', error); }
    }
  }

  function updateChart(chartId, data, options = {}) {
    const chart = getChart(chartId);
    const canvas = getCanvas(chartId);
    if (!chart || !canvas) {
      return renderChartSafe(chartId, { data, options }, options.emptyState || {});
    }

    const normalizedData = normalizeChartData(data || {});
    if (!hasValidChartData(normalizedData, options.emptyState || {})) {
      window.BIomieCharts.destroyChart(chartId);
      renderChartEmptyState(getContainer(canvas), options.emptyState?.message || defaultMessage('empty'));
      return null;
    }

    clearChartState(getContainer(canvas));
    chart.data = normalizedData;
    if (options.chartOptions) chart.options = applyDefaultChartOptions(options.chartOptions, chart.config?.type || 'bar', normalizedData);
    chart.update('none');
    return chart;
  }

  function renderHorizontalBar(canvasId, rows, options = {}) {
    const dataRows = Array.isArray(rows) ? rows.slice(0, options.limit || 5) : [];
    const labels = dataRows.map((row) => String(row?.[options.labelKey] || 'N/D'));
    const values = dataRows.map((row) => normalizeNumber(row?.[options.valueKey]) || 0);
    const color = options.color || getComputedStyle(document.documentElement).getPropertyValue('--page-accent').trim() || 'rgba(150, 20, 10, 0.72)';

    return renderChartSafe(canvasId, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: options.datasetLabel || 'Valor',
          data: values,
          backgroundColor: color,
          borderColor: color,
          borderRadius: 8,
          borderWidth: 1,
          barPercentage: 0.72,
          categoryPercentage: 0.72,
        }],
      },
      options: {
        indexAxis: 'y',
        parsing: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label(context) {
                const parsed = context.parsed?.x ?? context.raw ?? 0;
                return typeof options.formatValue === 'function' ? options.formatValue(parsed) : String(parsed);
              },
            },
          },
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: 'rgba(148, 163, 184, 0.18)' },
            ticks: {
              callback(value) {
                return typeof options.formatTick === 'function' ? options.formatTick(value) : value;
              },
            },
          },
          y: {
            grid: { display: false },
            ticks: {
              autoSkip: false,
              callback(value) {
                const label = labels[value] || '';
                return label.length > 22 ? `${label.slice(0, 21)}...` : label;
              },
            },
          },
        },
      },
    }, {
      message: options.emptyMessage || 'Nenhum dado encontrado para este grafico.',
      allowZeroData: options.allowZeroData === true,
    });
  }

  window.BIOMIE_CHARTS = {
    instances,
    destroyChart,
    getChart,
    renderChartSafe,
    normalizeChartData,
    hasValidChartData,
    resizeChart,
    applyDefaultChartOptions,
    renderChartEmptyState,
    renderChartErrorState,
    renderChartLoadingState,
    clearChartState,
    updateChart,
    renderHorizontalBar,
  };

  window.BIomieChartHelpers = window.BIomieChartHelpers || {};
  window.BIomieChartHelpers.destroy = destroyChart;
  window.BIomieChartHelpers.renderHorizontalBar = renderHorizontalBar;
})();
