(function () {
  'use strict';

  function fallbackDestroy(id) {
    if (!window.Chart || typeof window.Chart.getChart !== 'function') return;
    const canvas = document.getElementById(id);
    const chart = canvas ? window.Chart.getChart(canvas) : window.Chart.getChart(id);
    if (chart && typeof chart.destroy === 'function') chart.destroy();
  }

  function fallbackHorizontalBar(canvasId, rows, options) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return null;
    const dataRows = Array.isArray(rows) ? rows.slice(0, options.limit || 5) : [];
    if (!dataRows.length) return null;

    fallbackDestroy(canvasId);
    const labels = dataRows.map((row) => String(row[options.labelKey] || 'N/D'));
    const values = dataRows.map((row) => Number(row[options.valueKey] || 0));
    return new window.Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: options.datasetLabel || 'Valor',
          data: values,
          backgroundColor: options.color || 'rgba(150, 20, 10, 0.72)',
          borderRadius: 6,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: { legend: { display: false } },
      },
    });
  }

  window.BIomieChartHelpers = {
    destroy(id) {
      if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.destroyChart === 'function') {
        window.BIOMIE_CHARTS.destroyChart(id);
        return;
      }
      fallbackDestroy(id);
    },
    renderHorizontalBar(canvasId, rows, options = {}) {
      if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.renderHorizontalBar === 'function') {
        return window.BIOMIE_CHARTS.renderHorizontalBar(canvasId, rows, options);
      }
      return fallbackHorizontalBar(canvasId, rows, options);
    },
  };
})();
