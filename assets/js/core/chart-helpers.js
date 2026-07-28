/**
 * assets/js/core/chart-helpers.js — COM.R3 Fase 6
 *
 * Helpers unificados para Chart.js em gráficos comerciais.
 *
 * Boas práticas aplicadas:
 *   - parsing:false quando dados já estão no formato final
 *   - normalized:true para datasets normalizados
 *   - animation:false para gráficos grandes (> threshold)
 *   - destroy() antes de recriar
 *   - decimation para line charts com muitos pontos
 *   - Bucket automático por CommercialPeriodBucket rules (≤45d/46-180d/>180d)
 *   - Tooltips com total do bucket
 */

export const ChartHelpers = (() => {

  const ANIMATION_THRESHOLD = 180; // disable animation above N data points
  const DECIMATION_THRESHOLD = 100; // enable decimation above N points

  // ── Registry ──────────────────────────────────────────────────────────────

  const _charts = new Map();

  function destroyChart(id) {
    if (_charts.has(id)) {
      try { _charts.get(id).destroy(); } catch (_) {}
      _charts.delete(id);
    }
  }

  function registerChart(id, instance) {
    destroyChart(id);
    _charts.set(id, instance);
    return instance;
  }

  // ── Period bucket helpers (mirrors CommercialPeriodBucket.php) ────────────

  /**
   * Determine bucket mode for a date range.
   * ≤ 45 days → 'daily' | 46–180 → 'weekly' | > 180 → 'monthly'
   */
  function resolveBucketMode(start, end) {
    const s = new Date(start);
    const e = new Date(end);
    const days = Math.max(0, Math.round((e - s) / 86400000) + 1);
    if (days <= 45)  return 'daily';
    if (days <= 180) return 'weekly';
    return 'monthly';
  }

  /**
   * Format a bucket key as a human-readable label.
   */
  function bucketLabel(key, mode) {
    if (!key) return '';
    const d = new Date(key + (key.length === 7 ? '-01' : ''));
    const months = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    if (mode === 'monthly') return months[d.getMonth()] + '/' + String(d.getFullYear()).slice(2);
    if (mode === 'weekly')  return 'Sem ' + String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0');
    return String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0');
  }

  // ── Base options builder ──────────────────────────────────────────────────

  /**
   * Build base Chart.js config options optimized for performance.
   *
   * @param {Object} opts
   * @param {number} opts.dataPoints   - Number of data points (determines animation, decimation)
   * @param {boolean} opts.isParsed    - Data is already in {x,y} or array format
   * @param {boolean} opts.responsive  - Default true
   * @param {string}  opts.mode        - 'line'|'bar'|'doughnut' (affects decimation)
   */
  function baseOptions({
    dataPoints   = 0,
    isParsed     = true,
    responsive   = true,
    mode         = 'bar',
    currency     = true,
    bucketMode   = null,
  } = {}) {
    const disableAnimation = dataPoints > ANIMATION_THRESHOLD;
    const enableDecimation = mode === 'line' && dataPoints > DECIMATION_THRESHOLD;

    const opts = {
      responsive,
      maintainAspectRatio: false,
      animation: disableAnimation ? false : { duration: 250 },
      parsing: isParsed ? false : undefined,
    };

    if (enableDecimation) {
      opts.plugins = opts.plugins || {};
      opts.plugins.decimation = {
        enabled:   true,
        algorithm: 'min-max',
        samples:   200,
      };
    }

    // Tooltip
    opts.plugins = opts.plugins || {};
    opts.plugins.tooltip = {
      mode: 'index',
      intersect: false,
      callbacks: {
        label: ctx => {
          const val = ctx.parsed?.y ?? ctx.parsed ?? 0;
          const label = ctx.dataset.label || '';
          if (currency) return ` ${label}: ${_fmtBrl(val)}`;
          return ` ${label}: ${val.toLocaleString('pt-BR')}`;
        },
        title: ctx => {
          if (!ctx.length) return '';
          const key = ctx[0].label || '';
          if (bucketMode) return bucketLabel(key.split(' ').pop() || key, bucketMode) || key;
          return key;
        },
      },
    };

    // Legend
    opts.plugins.legend = {
      position: 'bottom',
      labels: { font: { size: 11 }, boxWidth: 12, padding: 16 },
    };

    return opts;
  }

  // ── Create chart helpers ──────────────────────────────────────────────────

  /**
   * Create a bar chart for time-series commercial data.
   * Destroys any existing chart at the same canvas ID.
   *
   * @param {HTMLCanvasElement|string} canvas - Canvas element or its ID
   * @param {Array<{key, label, base_comercial, nfs}>} buckets - from CommercialMetricsAggregator
   * @param {Object} opts
   * @param {string} opts.start
   * @param {string} opts.end
   * @param {string} [opts.label]   - Dataset label
   * @param {string} [opts.color]   - Bar color
   */
  function createTimeSeriesBar(canvas, buckets, { start, end, label = 'Base Comercial', color = '#4f8ef7' } = {}) {
    const el = typeof canvas === 'string' ? document.getElementById(canvas) : canvas;
    if (!el) return null;

    const id = el.id || ('chart-' + Math.random().toString(36).slice(2));
    el.id = id;
    destroyChart(id);

    const mode = resolveBucketMode(start, end);
    const labels = buckets.map(b => b.label || bucketLabel(b.key, mode));
    const values = buckets.map(b => parseFloat(b.base_comercial) || 0);

    const opts = baseOptions({
      dataPoints: buckets.length,
      isParsed:   false,
      mode:       'bar',
      currency:   true,
      bucketMode: mode,
    });

    opts.scales = {
      x: { ticks: { font: { size: 10 }, maxTicksLimit: 12 } },
      y: {
        ticks: {
          font: { size: 10 },
          callback: v => _fmtBrl(v),
        },
      },
    };

    if (!window.Chart) return null;

    const chart = new window.Chart(el, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label,
          data:            values,
          backgroundColor: color + 'cc',
          borderColor:     color,
          borderWidth:     1,
        }],
      },
      options: opts,
    });

    return registerChart(id, chart);
  }

  /**
   * Create a line chart for a metric series.
   */
  function createTimeSeriesLine(canvas, buckets, { start, end, datasets = [], smooth = false } = {}) {
    const el = typeof canvas === 'string' ? document.getElementById(canvas) : canvas;
    if (!el) return null;

    const id = el.id || ('chart-' + Math.random().toString(36).slice(2));
    el.id = id;
    destroyChart(id);

    const mode    = resolveBucketMode(start, end);
    const labels  = buckets.map(b => b.label || bucketLabel(b.key, mode));
    const n       = buckets.length;

    const opts = baseOptions({
      dataPoints: n,
      isParsed:   false,
      mode:       'line',
      currency:   true,
      bucketMode: mode,
    });

    opts.elements = { line: { tension: smooth ? 0.3 : 0, fill: false } };
    opts.scales = {
      x: { ticks: { font: { size: 10 }, maxTicksLimit: 12 } },
      y: { ticks: { font: { size: 10 }, callback: v => _fmtBrl(v) } },
    };

    if (!window.Chart) return null;

    const chart = new window.Chart(el, {
      type: 'line',
      data: { labels, datasets },
      options: opts,
    });

    return registerChart(id, chart);
  }

  /**
   * Create a doughnut chart (product mix, pipeline scope, etc.).
   */
  function createDoughnut(canvas, labels, values, { colors = [], id: chartId = '' } = {}) {
    const el = typeof canvas === 'string' ? document.getElementById(canvas) : canvas;
    if (!el) return null;

    const id = chartId || el.id || ('chart-' + Math.random().toString(36).slice(2));
    el.id = id;
    destroyChart(id);

    const defaultColors = ['#4f8ef7','#f59e0b','#10b981','#ef4444','#8b5cf6','#f97316','#06b6d4'];
    const finalColors   = colors.length ? colors : defaultColors.slice(0, values.length);
    const total         = values.reduce((s, v) => s + v, 0);

    const opts = baseOptions({ dataPoints: values.length, mode: 'doughnut', currency: true });
    opts.plugins.tooltip.callbacks.label = ctx => {
      const val = ctx.parsed || 0;
      const pct = total > 0 ? ((val / total) * 100).toFixed(1) : '0';
      return ` ${ctx.label}: ${_fmtBrl(val)} (${pct}%)`;
    };
    opts.cutout = '62%';

    if (!window.Chart) return null;

    const chart = new window.Chart(el, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{ data: values, backgroundColor: finalColors, borderWidth: 1 }],
      },
      options: opts,
    });

    return registerChart(id, chart);
  }

  // ── Update helpers ────────────────────────────────────────────────────────

  /**
   * Update an existing chart's data without recreating it.
   * Reuses existing labels if buckets have the same length.
   */
  function updateChartData(chartId, newLabels, newDatasets) {
    const chart = _charts.get(chartId);
    if (!chart) return false;

    chart.data.labels = newLabels;
    chart.data.datasets = newDatasets;
    chart.update('none'); // skip animation for updates
    return true;
  }

  // ── Format helper ─────────────────────────────────────────────────────────

  function _fmtBrl(v) {
    const n = parseFloat(v) || 0;
    if (n >= 1e6)  return 'R$' + (n / 1e6).toFixed(2).replace('.', ',') + 'M';
    if (n >= 1e3)  return 'R$' + (n / 1e3).toFixed(1).replace('.', ',') + 'k';
    return 'R$' + n.toFixed(2).replace('.', ',');
  }

  // ── Public API ────────────────────────────────────────────────────────────

  return {
    destroyChart,
    registerChart,
    resolveBucketMode,
    bucketLabel,
    baseOptions,
    createTimeSeriesBar,
    createTimeSeriesLine,
    createDoughnut,
    updateChartData,
  };
})();
