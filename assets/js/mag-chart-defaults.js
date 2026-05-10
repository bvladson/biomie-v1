(function () {
  'use strict';

  function applyMagChartDefaults() {
    if (!window.Chart || !window.Chart.defaults) {
      return;
    }

    var Chart = window.Chart;
    var defaults = Chart.defaults;
    var plugins = defaults.plugins || (defaults.plugins = {});
    var legend = plugins.legend || (plugins.legend = {});
    var tooltip = plugins.tooltip || (plugins.tooltip = {});
    var elements = defaults.elements || (defaults.elements = {});
    var line = elements.line || (elements.line = {});
    var point = elements.point || (elements.point = {});
    var bar = elements.bar || (elements.bar = {});
    var ticksColor = '#667085';
    var gridColor = 'rgba(102, 112, 133, 0.12)';

    defaults.devicePixelRatio = Math.min(window.devicePixelRatio || 1, 1.5);
    defaults.responsive = true;
    defaults.maintainAspectRatio = false;
    defaults.color = ticksColor;
    defaults.borderColor = gridColor;
    defaults.font = Object.assign({}, defaults.font || {}, {
      family: '"Plus Jakarta Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
      size: 12,
      weight: '500'
    });

    defaults.animation = Object.assign({}, defaults.animation || {}, {
      duration: 180,
      easing: 'easeOutQuart'
    });

    legend.display = false;

    tooltip.enabled = true;
    tooltip.backgroundColor = '#ffffff';
    tooltip.borderColor = 'rgba(15, 23, 42, 0.12)';
    tooltip.borderWidth = 1;
    tooltip.titleColor = '#232a35';
    tooltip.bodyColor = '#667085';
    tooltip.padding = 10;
    tooltip.cornerRadius = 8;
    tooltip.boxPadding = 4;
    tooltip.displayColors = false;
    tooltip.titleFont = Object.assign({}, tooltip.titleFont || {}, { weight: '700', size: 12 });
    tooltip.bodyFont = Object.assign({}, tooltip.bodyFont || {}, { weight: '500', size: 12 });

    line.borderWidth = line.borderWidth || 2;
    line.tension = line.tension == null ? 0.32 : line.tension;
    point.radius = point.radius == null ? 0 : point.radius;
    point.hoverRadius = point.hoverRadius == null ? 3 : point.hoverRadius;
    bar.borderRadius = bar.borderRadius == null ? 6 : bar.borderRadius;
    bar.borderSkipped = false;

    defaults.datasets = defaults.datasets || {};
    defaults.datasets.bar = Object.assign({}, defaults.datasets.bar || {}, {
      maxBarThickness: 36,
      categoryPercentage: 0.72,
      barPercentage: 0.82
    });

    defaults.scales = defaults.scales || {};
    ['linear', 'logarithmic', 'category', 'time', 'timeseries'].forEach(function (scaleType) {
      defaults.scales[scaleType] = Object.assign({}, defaults.scales[scaleType] || {}, {
        grid: Object.assign({}, (defaults.scales[scaleType] || {}).grid || {}, {
          color: gridColor,
          drawBorder: false,
          tickLength: 0
        }),
        ticks: Object.assign({}, (defaults.scales[scaleType] || {}).ticks || {}, {
          color: ticksColor,
          maxTicksLimit: scaleType === 'category' ? 8 : 6,
          padding: 8
        })
      });
    });

    window.MagChartDefaults = Object.assign({}, window.MagChartDefaults || {}, {
      palette: ['#96140a', '#16834a', '#2563eb', '#0f5b66', '#b7791f', '#c7352f', '#64748b'],
      maxItems: 10,
      trimItems: function (items, limit) {
        if (!Array.isArray(items)) {
          return [];
        }
        return items.slice(0, limit || 10);
      },
      applied: true
    });
  }

  applyMagChartDefaults();
  window.addEventListener('load', applyMagChartDefaults, { once: true });
})();
