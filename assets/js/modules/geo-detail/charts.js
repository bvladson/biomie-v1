/**
 * BIomie — Geo Detail Charts.
 * Chart.js para a aba Evolução do drawer Geo 360.
 */
(function () {
  'use strict';

  var _instances = {};

  function destroyChart(id) {
    if (_instances[id]) {
      try { _instances[id].destroy(); } catch (e) {}
      delete _instances[id];
    }
  }

  function renderTimeline(data) {
    var rows = Array.isArray(data.timeline) ? data.timeline : [];
    var canvasId = 'geo-detail-timeline-canvas';

    if (!rows.length) {
      return '<div style="padding:20px;text-align:center;color:var(--text-3,#888);">Nenhum dado de evolução para o período selecionado.</div>';
    }

    var html = '<div style="position:relative;height:220px;margin-top:8px;">'
      + '<canvas id="' + canvasId + '"></canvas></div>';

    requestAnimationFrame(function () {
      _drawTimeline(canvasId, rows);
    });

    return html;
  }

  function _drawTimeline(canvasId, rows) {
    destroyChart(canvasId);
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;

    var labels   = rows.map(function (r) { return String(r.bucket || ''); });
    var revenues = rows.map(function (r) { return Number(r.revenue_raw || r.revenue || 0); });
    var customers = rows.map(function (r) { return Number(r.customers_count || 0); });

    _instances[canvasId] = new Chart(canvas.getContext('2d'), {
      data: {
        labels: labels,
        datasets: [
          {
            type: 'bar',
            label: 'Receita (R$)',
            data: revenues,
            backgroundColor: 'rgba(150, 20, 10, 0.62)',
            borderColor: 'rgba(150, 20, 10, 0.85)',
            borderWidth: 1,
            yAxisID: 'yRevenue',
          },
          {
            type: 'line',
            label: 'Clientes',
            data: customers,
            borderColor: '#24364f',
            backgroundColor: 'rgba(36,54,79,0.12)',
            pointRadius: 3,
            tension: 0.3,
            yAxisID: 'yCustomers',
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: true, position: 'bottom' } },
        scales: {
          yRevenue: {
            type: 'linear',
            position: 'left',
            ticks: {
              callback: function (v) {
                if (Math.abs(v) >= 1000) return 'R$ ' + (v / 1000).toFixed(0) + 'k';
                return 'R$ ' + v;
              },
              font: { size: 10 },
            },
          },
          yCustomers: {
            type: 'linear',
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: { font: { size: 10 } },
          },
        },
      },
    });
  }

  window.B = window.B || {};
  window.B.GeoDetail = window.B.GeoDetail || {};
  window.B.GeoDetail.Charts = {
    renderTimeline: renderTimeline,
    destroyAll: function () {
      Object.keys(_instances).forEach(destroyChart);
    },
  };
})();
