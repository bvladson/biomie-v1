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

    var getCssVar = function (n) {
      return (getComputedStyle(document.body || document.documentElement).getPropertyValue(n) || '').trim();
    };
    tooltip.enabled = true;
    tooltip.backgroundColor = getCssVar('--chart-tooltip-bg')     || 'rgba(255,255,255,0.97)';
    tooltip.borderColor     = getCssVar('--chart-tooltip-border') || 'rgba(15,23,42,0.10)';
    tooltip.borderWidth = 1;
    tooltip.titleColor      = getCssVar('--chart-tooltip-title')  || '#0f172a';
    tooltip.bodyColor       = getCssVar('--chart-tooltip-text')   || '#475569';
    tooltip.padding = 10;
    tooltip.cornerRadius = 8;
    tooltip.boxPadding = 4;
    tooltip.displayColors = false;
    tooltip.titleFont = Object.assign({}, tooltip.titleFont || {}, { weight: '700', size: 12 });
    tooltip.bodyFont = Object.assign({}, tooltip.bodyFont || {}, { weight: '500', size: 12 });

    line.borderWidth = line.borderWidth || 2;
    line.tension = line.tension == null ? 0.32 : line.tension;
    point.radius = point.radius == null ? 0 : point.radius;
    point.hoverRadius = point.hoverRadius == null ? 4 : point.hoverRadius;
    // Alvo de hover maior que a marca (dataviz: hit target ≥ 8px)
    point.hitRadius = point.hitRadius == null ? 8 : point.hitRadius;
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

    // UX.CHARTS.R2 — Paleta categórica validada (dataviz: lightness band, chroma,
    // separação CVD ΔE≥12 em pares adjacentes, contraste vs superfície branca).
    // Ordem FIXA: a série i usa sempre o slot i — nunca ciclar nem gerar tons.
    // Da 9ª série em diante os dados dobram em "Outros" (neutro).
    var categoricalPalette = [
      '#2a78d6', // 1 azul
      '#1baf7a', // 2 verde-água
      '#eda100', // 3 amarelo
      '#008300', // 4 verde
      '#4a3aa7', // 5 violeta
      '#e34948', // 6 vermelho
      '#e87ba4', // 7 magenta
      '#eb6834'  // 8 laranja
    ];
    var categoricalNeutral = '#94a3b8'; // "Outros" / excedente

    // UX.CHARTS.R1 — Semantic palette aligned with chart-governance.js
    var semanticPalette = {
      billed:     '#16a34a',  // green — NF/realizado/recebido
      authorized: '#d97706',  // amber — carteira/autorizado/aberto
      cancelled:  '#7f1d1d',  // dark red — cancelado
      revenue:    '#96140a',  // MAG red — receita
      target:     '#0f172a',  // near-black — meta
      forecast:   '#2563eb',  // blue — projeção/tendência
      overdue:    '#dc2626',  // red — vencido/risco
      neutral:    '#94a3b8',  // gray — neutro/sem dados
      info:       '#3b82f6',  // blue — informação
    };

    window.MagChartDefaults = Object.assign({}, window.MagChartDefaults || {}, {
      // Legacy palette kept for backward compatibility
      palette: ['#96140a', '#16834a', '#2563eb', '#0f5b66', '#b7791f', '#c7352f', '#64748b'],
      // UX.CHARTS.R2 — categorical validada; ordem fixa, "Outros" = neutro
      categoricalPalette: categoricalPalette,
      categoricalNeutral: categoricalNeutral,
      categoricalColor: function (index, isOther) {
        if (isOther) return categoricalNeutral;
        var i = Number(index) || 0;
        return i >= 0 && i < categoricalPalette.length ? categoricalPalette[i] : categoricalNeutral;
      },
      // UX.CHARTS.R1 — semantic palette
      semanticPalette: semanticPalette,
      getSemanticColor: function (name) {
        var n = String(name || '').toLowerCase().trim();
        if (semanticPalette[n]) return semanticPalette[n];
        // Delegate to governance layer if available
        if (window.BIOMIE_CHART_GOVERNANCE && window.BIOMIE_CHART_GOVERNANCE.getSemanticColor) {
          return window.BIOMIE_CHART_GOVERNANCE.getSemanticColor(n) || null;
        }
        return null;
      },
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
