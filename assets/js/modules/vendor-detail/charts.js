(function () {
  'use strict';

  var B = window.BIomie || {};
  B.VendorDetail = B.VendorDetail || {};

  function renderCharts(data) {
    var T = B.VendorDetail.Tabs;
    if (!T) return '<div class="biomie-drawer__empty">Erro: Modulo tabs.js nao carregado.</div>';
    
    // We will render placeholders and then mount the charts using Chart.js
    var html = '<div class="biomie-drawer__section" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">' +
      '<div>' +
      '<div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">Evolucao diaria do realizado</div>' +
      '<div style="position:relative;height:220px;width:100%;"><canvas id="vd-chart-daily"></canvas></div>' +
      '</div>' +
      '<div>' +
      '<div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">Comparativo com meta</div>' +
      '<div style="position:relative;height:220px;width:100%;"><canvas id="vd-chart-target"></canvas></div>' +
      '</div>' +
    '</div>' +
    '<div class="biomie-drawer__section" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">' +
      '<div>' +
        '<div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">Top Clientes</div>' +
        '<div style="position:relative;height:220px;width:100%;"><canvas id="vd-chart-customers"></canvas></div>' +
      '</div>' +
      '<div>' +
        '<div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">Top Produtos</div>' +
        '<div style="position:relative;height:220px;width:100%;"><canvas id="vd-chart-products"></canvas></div>' +
      '</div>' +
    '</div>';

    // Defer chart mounting until the DOM is updated
    requestAnimationFrame(function() {
      mountCharts(data);
    });

    return html;
  }

  function mountCharts(data) {
    var T = B.VendorDetail.Tabs;
    if (!window.Chart) return;
    
    var theme = window.getChartTheme ? window.getChartTheme() : {
      colors: ['#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0'],
      text: '#6c757d',
      grid: '#dee2e6'
    };

    mountDailyChart(data, theme, T);
    mountTargetChart(data, theme, T);
    mountTopChart(data.customers || [], 'vd-chart-customers', 'value|total', 'label|cliente', theme, T);
    mountTopChart(data.products || [], 'vd-chart-products', 'value|revenue', 'description|label', theme, T);
  }

  function mountDailyChart(data, theme, T) {
    var canvas = document.getElementById('vd-chart-daily');
    if (!canvas) return;

    var existing = window.Chart.getChart(canvas);
    if (existing) existing.destroy();

    var rows = data.daily_sales || [];
    if (!rows.length) return;

    // Filter non-zero days optionally
    var labels = [];
    var values = [];
    rows.forEach(function(row) {
      labels.push(T.date(row.date || row.dia || ''));
      values.push(T.toNumber(T.pick(row, 'gross_revenue|revenue|value|invoice_total')));
    });

    new window.Chart(canvas, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Realizado',
          data: values,
          backgroundColor: theme.colors[0],
          borderRadius: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(ctx) { return T.money(ctx.raw); }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              color: theme.text,
              callback: function(value) { return T.money(value); }
            },
            grid: { color: theme.grid }
          },
          x: {
            ticks: { color: theme.text, maxTicksLimit: 10 },
            grid: { display: false }
          }
        }
      }
    });
  }

  function mountTargetChart(data, theme, T) {
    var canvas = document.getElementById('vd-chart-target');
    if (!canvas) return;

    var existing = window.Chart.getChart(canvas);
    if (existing) existing.destroy();

    var vendor = data.vendor || {};
    var target = T.toNumber(vendor.target_period || vendor.goal || 0);
    var realized = T.toNumber(vendor.realizado_vendedor || vendor.realized_period || vendor.realized || 0);
    var forecast = T.toNumber(vendor.forecast_period || realized);
    var gap = Math.max(target - realized, 0);
    var colors = theme.colors || [];

    if (!target && !realized && !forecast) return;

    new window.Chart(canvas, {
      type: 'bar',
      data: {
        labels: ['Meta', 'Realizado', 'Projecao', 'Gap'],
        datasets: [{
          data: [target, realized, forecast, gap],
          backgroundColor: [colors[2] || '#6f42c1', colors[0] || '#0d6efd', colors[7] || '#198754', colors[5] || '#fd7e14'],
          borderRadius: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(ctx) { return T.money(ctx.raw); }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              color: theme.text,
              callback: function(value) { return T.money(value); }
            },
            grid: { color: theme.grid }
          },
          x: {
            ticks: { color: theme.text },
            grid: { display: false }
          }
        }
      }
    });
  }

  function mountTopChart(items, canvasId, valueKey, labelKey, theme, T) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;

    var existing = window.Chart.getChart(canvas);
    if (existing) existing.destroy();

    var rows = items.slice(0, 5);
    if (!rows.length) return;

    var labels = [];
    var values = [];
    var colors = [];
    
    rows.forEach(function(row, i) {
      var name = String(T.pick(row, labelKey) || '-');
      if (name.length > 20) name = name.substring(0, 20) + '...';
      labels.push(name);
      values.push(T.toNumber(T.pick(row, valueKey)));
      colors.push(theme.colors[i % theme.colors.length]);
    });

    new window.Chart(canvas, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: colors,
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(ctx) { return T.money(ctx.raw); }
            }
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            ticks: {
              color: theme.text,
              callback: function(value) { return B.formatNumber ? B.formatNumber(value) : value; }
            },
            grid: { color: theme.grid }
          },
          y: {
            ticks: { color: theme.text },
            grid: { display: false }
          }
        }
      }
    });
  }

  B.VendorDetail.Charts = {
    renderCharts: renderCharts
  };
}());
