window.BIomieCharts = window.BIomieCharts || {};
window.BIOMIE_CHARTS = window.BIOMIE_CHARTS || window.BIomieCharts;

window.BIomieCharts.destroyChart = function destroyChart(target) {
  try {
    let chart = null;

    if (!target) return;

    if (typeof target === 'string') {
      const canvas = document.getElementById(target);
      if (canvas && window.Chart && typeof Chart.getChart === 'function') {
        chart = Chart.getChart(canvas);
      }
    } else if (target && typeof target.destroy === 'function') {
      chart = target;
    } else if (target && target.canvas && window.Chart && typeof Chart.getChart === 'function') {
      chart = Chart.getChart(target.canvas);
    } else if (target instanceof HTMLCanvasElement && window.Chart && typeof Chart.getChart === 'function') {
      chart = Chart.getChart(target);
    }

    if (chart && typeof chart.destroy === 'function') {
      chart.destroy();
    }
  } catch (error) {
    console.warn('[BIomieCharts] destroyChart failed', error);
  }
};

window.BIomieCharts.safeDestroyChart = window.BIomieCharts.destroyChart;
window.BIOMIE_CHARTS.destroyChart = window.BIomieCharts.destroyChart;
window.BIOMIE_CHARTS.safeDestroyChart = window.BIomieCharts.destroyChart;
window.BIomie = window.BIomie || {};
window.BIomie.destroyChart = window.BIomieCharts.destroyChart;

(function (B) {
  'use strict';

  // All functions call B._renderChart / B._destroyChart / B._chartEmpty which are
  // set by app.js's IIFE before DOMContentLoaded fires.

  B.createLineChart = function (canvasId, labels, revenueValues, orderValues) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var theme  = B._chartTheme();
    var accent = theme.accent;
    var ctx    = canvas.getContext('2d');
    var chartH = canvas.offsetHeight || 320;
    var areaGradient = ctx.createLinearGradient(0, 0, 0, chartH);
    areaGradient.addColorStop(0,    B.hexToRgba(accent, 0.36));
    areaGradient.addColorStop(0.55, B.hexToRgba(accent, 0.10));
    areaGradient.addColorStop(1,    B.hexToRgba(accent, 0));

    var movingAvg7 = revenueValues.map(function (_, i) {
      var slice = revenueValues.slice(Math.max(0, i - 6), i + 1);
      return slice.reduce(function (s, v) { return s + v; }, 0) / slice.length;
    });

    var crosshairPlugin = {
      id: 'biomie-crosshair',
      afterDraw: function (chart) {
        // Use public getActiveElements() (Chart.js 4.x) with fallback to private _active
        var active = typeof chart.getActiveElements === 'function'
          ? chart.getActiveElements()
          : (chart.tooltip && chart.tooltip._active) || [];
        if (!active || !active.length) return;
        var firstEl = active[0] && active[0].element;
        if (!firstEl) return;
        var c  = chart.ctx;
        var ca = chart.chartArea;
        var x  = firstEl.x;
        c.save();
        c.beginPath();
        c.moveTo(x, ca.top);
        c.lineTo(x, ca.bottom);
        c.lineWidth   = 1;
        c.strokeStyle = B.hexToRgba(theme.text, 0.18);
        c.setLineDash([3, 4]);
        c.stroke();
        c.restore();
      }
    };

    B._renderChart(canvasId, {
      type: 'bar',
      plugins: [crosshairPlugin],
      data: {
        labels: labels,
        datasets: [
          { type: 'line', label: 'Receita',  data: revenueValues, borderColor: accent, backgroundColor: areaGradient, borderWidth: 2.5, pointBackgroundColor: accent, pointBorderColor: B._surfaceColor(), pointBorderWidth: 2, pointRadius: 0, pointHoverRadius: 5, tension: 0.4, fill: 'origin', yAxisID: 'y', order: 1 },
          { type: 'line', label: 'Media 7d', data: movingAvg7, borderColor: B.hexToRgba(accent, 0.48), backgroundColor: 'transparent', borderWidth: 1.5, borderDash: [5, 4], pointRadius: 0, pointHoverRadius: 3, tension: 0.4, fill: false, yAxisID: 'y', order: 2 },
          { type: 'bar',  label: 'NF', data: orderValues, backgroundColor: B.hexToRgba(theme.info, 0.15), borderColor: B.hexToRgba(theme.info, 0.40), borderWidth: 1, borderRadius: 3, maxBarThickness: 5, yAxisID: 'y1', order: 3 }
        ]
      },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: true, position: 'top', align: 'end', labels: { color: theme.subtle, font: { size: 11 }, boxWidth: 22, boxHeight: 2, padding: 14, usePointStyle: true, pointStyle: 'line' } },
          tooltip: {
            enabled: true,
            backgroundColor: theme.tooltipBg,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
            titleColor: theme.title,
            bodyColor: theme.text,
            padding: 12,
            boxPadding: 5,
            caretPadding: 8,
            displayColors: true,
            callbacks: {
              title: function (items) { return items[0] && items[0].label ? items[0].label : ''; },
              label: function (context) {
                var idx = context.dataIndex;
                var lbl = context.dataset.label;
                if (lbl === 'Receita') {
                  var v    = context.parsed.y;
                  var prev = idx > 0 ? revenueValues[idx - 1] : null;
                  var change = '';
                  if (prev != null && prev > 0) {
                    var pct = ((v - prev) / prev * 100).toFixed(1);
                    change  = Number(pct) >= 0 ? '  ▲ ' + pct + '%' : '  ▼ ' + Math.abs(Number(pct)) + '%';
                  }
                  return ' Receita: ' + B.formatCurrency(v) + change;
                }
                if (lbl === 'Media 7d') return ' Media 7d: ' + B.formatCurrency(context.parsed.y);
                return ' NF: ' + B.formatNumber(context.parsed.y) + ' notas';
              },
              labelColor: function (context) {
                var c = context.dataset.label === 'NF' ? theme.info : accent;
                return { borderColor: c, backgroundColor: c };
              }
            }
          }
        },
        scales: {
          x:  B.chartAxisOptions('label', { grid: { display: false } }),
          y:  B.chartAxisOptions('money', { beginAtZero: true }),
          y1: B.chartAxisOptions('count', { beginAtZero: true, position: 'right', ticks: { precision: 0, maxTicksLimit: 5 }, grid: { drawOnChartArea: false } })
        }
      }
    });
  };

  B.createMonthlyComparisonChart = function (canvasId, rows) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var items = (rows || []).slice(-6);
    if (!items.length) { B._chartEmpty(canvasId, 'Historico insuficiente para comparacao.'); return; }

    var theme     = B._chartTheme();
    var accent    = theme.accent;
    var secondary = theme.info;
    var muted     = theme.subtle;
    var ctx       = canvas.getContext('2d');
    var labels      = items.map(function (r) { return B.monthLabel(r.competencia || r.label || ''); });
    var revenue     = items.map(function (r) { return Number(r.revenue  || 0); });
    var targets     = items.map(function (r) { return Number(r.target   || 0); });
    var forecast    = items.map(function (r) { return Number(r.forecast || r.revenue || 0); });
    var barColors   = items.map(function (r) { return r.is_current ? B.buildGradient(ctx, accent, 0.3) : B.buildGradient(ctx, B.adjustColour(accent, 18), 0.18); });
    var borderColors = items.map(function (r) { return r.is_current ? accent : B.adjustColour(accent, 18); });

    var monthlyDatasets = [
      { type: 'bar',  label: 'Receita',   data: revenue, backgroundColor: barColors, borderColor: borderColors, borderWidth: 1, borderRadius: 10, maxBarThickness: 30, order: 3 },
      { type: 'line', label: 'Meta',       data: targets, borderColor: muted, backgroundColor: 'transparent', borderDash: [6, 4], pointHoverRadius: 6, pointHitRadius: 10, tension: 0.4, order: 1 },
      { type: 'line', label: 'Tendencia',  data: forecast, borderColor: secondary, backgroundColor: B.hexToRgba(secondary, 0.12), pointBackgroundColor: secondary, pointBorderColor: B._surfaceColor(), pointRadius: 3, pointHoverRadius: 6, pointHitRadius: 10, tension: 0.4, fill: false, order: 2 }
    ];
    B._renderChart(canvasId, {
      data: {
        labels: labels,
        datasets: monthlyDatasets
      },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + B.formatCurrency(ctx.parsed.y); } } } },
        scales: { x: B.chartAxisOptions('label', { grid: { display: false } }), y: B.chartAxisOptions('money', { beginAtZero: true }) }
      }
    });
    // UX.CHARTS.R1 — external legend
    window.requestAnimationFrame(function () { cfApplyGovernance(canvasId, monthlyDatasets, null); });
  };

  B.createBarChart = function (canvasId, labels, values, chartOptions) {
    chartOptions = chartOptions || {};
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var ctx     = canvas.getContext('2d');
    var accent  = chartOptions.color || B._chartTheme().accent;
    var palette = labels.map(function (_, i) { return B.adjustColour(accent, (i / Math.max(1, labels.length - 1)) * 28); });

    B._renderChart(canvasId, {
      type: 'bar',
      data: { labels: labels, datasets: [{ label: chartOptions.label || 'Receita', data: values, backgroundColor: palette.map(function (c) { return B.buildGradient(ctx, c, 0.28); }), borderColor: palette, borderWidth: 1, borderRadius: 10, maxBarThickness: chartOptions.maxBarThickness || 28 }] },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        indexAxis: chartOptions.indexAxis || 'x', plugins: { legend: { display: false } },
        scales: { x: B.chartAxisOptions('label', { grid: { display: chartOptions.indexAxis === 'y' } }), y: B.chartAxisOptions('money', { beginAtZero: true, grid: { display: chartOptions.indexAxis === 'y' ? false : true } }) }
      }
    });
  };

  B.createRevenueTicketChart = function (canvasId, rows) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var ranking = (rows || []).slice(0, 8);
    if (!ranking.length) { B._chartEmpty(canvasId, 'Nenhum vendedor com faturamento no periodo.'); return; }

    var theme     = B._chartTheme();
    var accent    = theme.accent;
    var secondary = theme.info;
    var labels     = ranking.map(function (r) { return B.shortenLabel(r.vendor_name, 12); });
    var fullLabels = ranking.map(function (r) { return r.vendor_name || 'Sem vendedor'; });
    var revenue    = ranking.map(function (r) { return Number(r.revenue != null ? r.revenue : (r.gross_revenue || 0)); });
    var ticket     = ranking.map(function (r) { return Number(r.ticket_avg != null ? r.ticket_avg : (Number(r.orders || 0) > 0 ? Number(r.revenue || 0) / Number(r.orders || 1) : 0)); });
    var ctx = canvas.getContext('2d');

    var ticketDatasets = [
      { type: 'bar',  label: 'Receita', data: revenue, backgroundColor: B.buildGradient(ctx, accent, 0.26), borderColor: accent, borderWidth: 1, borderRadius: 10, maxBarThickness: 30, yAxisID: 'y' },
      { type: 'line', label: 'Ticket médio', data: ticket, borderColor: secondary, backgroundColor: B.hexToRgba(secondary, 0.12), pointBackgroundColor: secondary, pointBorderColor: B._surfaceColor(), pointBorderWidth: 2, pointRadius: 4, tension: 0.35, yAxisID: 'y1' }
    ];
    B._renderChart(canvasId, {
      data: { labels: labels, datasets: ticketDatasets },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { callbacks: { title: function (items) { return fullLabels[items[0].dataIndex] || items[0].label; }, label: function (ctx) { return ctx.dataset.label === 'Ticket médio' ? 'Ticket: ' + B.formatCurrency(ctx.parsed.y) : 'Receita: ' + B.formatCurrency(ctx.parsed.y); } } } },
        scales: {
          x: B.chartAxisOptions('label', { grid: { display: false } }),
          y: B.chartAxisOptions('money', { beginAtZero: true }),
          y1: B.chartAxisOptions('money', { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Ticket', color: secondary, font: { size: 10 } } })
        }
      }
    });
    // UX.CHARTS.R1 — external legend + rank insight
    window.requestAnimationFrame(function () {
      var insight = null;
      if (window.BIOMIE_CHART_GOVERNANCE) {
        insight = window.BIOMIE_CHART_GOVERNANCE.buildRankInsight(
          ranking.map(function (r) { return r.vendor_name || ''; }), revenue
        );
      }
      cfApplyGovernance(canvasId, ticketDatasets, insight);
    });
  };

  B.createGroupedBarChart = function (canvasId, matrix) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var rows = (matrix || []).slice(0, 8);
    if (!rows.length) { B._chartEmpty(canvasId, 'Sem movimentacao de pipeline no periodo.'); return; }

    var wrap = canvas.parentElement;
    if (wrap) {
      var rowHeight = 38;
      var computedHeight = Math.max(260, Math.min(520, rows.length * rowHeight + 96));
      wrap.style.height = computedHeight + 'px';
      wrap.style.minHeight = computedHeight + 'px';
    }

    var theme      = B._chartTheme();
    var billedColor = theme.billed;
    var authColor   = theme.authorized;
    var cancelColor = theme.cancelled;
    var labels      = rows.map(function (r) { return B.shortenLabel(r.vendor_name, 18); });
    var fullLabels  = rows.map(function (r) { return r.vendor_name || 'Sem vendedor'; });
    var ctx = canvas.getContext('2d');

    var groupedDatasets = [
      { label: 'NF',         data: rows.map(function (r) { return Number(r.billed_value    || 0); }), backgroundColor: B.buildGradient(ctx, billedColor, 0.28), borderColor: billedColor, borderWidth: 1, borderRadius: 8, stack: 'status' },
      { label: 'Carteira',   data: rows.map(function (r) { return Number(r.authorized_value|| 0); }), backgroundColor: B.buildGradient(ctx, authColor,   0.24), borderColor: authColor,   borderWidth: 1, borderRadius: 8, stack: 'status' },
      { label: 'Canceladas', data: rows.map(function (r) { return Number(r.cancelled_value || 0); }), backgroundColor: B.buildGradient(ctx, cancelColor,  0.22), borderColor: cancelColor,  borderWidth: 1, borderRadius: 8, stack: 'status' }
    ];
    var chart = B._renderChart(canvasId, {
      type: 'bar',
      data: { labels: labels, datasets: groupedDatasets },
      options: {
        indexAxis: 'y', responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'nearest', axis: 'y', intersect: false },
        hover: { mode: 'nearest', axis: 'y', intersect: false },
        plugins: { legend: { display: false }, tooltip: { mode: 'nearest', axis: 'y', intersect: false, callbacks: { title: function (items) { return fullLabels[items[0].dataIndex] || items[0].label; }, label: function (ctx) { return ctx.dataset.label + ': ' + B.formatCurrency(ctx.parsed.x); }, footer: function (items) { var row = rows[items[0].dataIndex] || {}; return 'Conversao: ' + (row.conversion_rate || 0) + '% | Cancelamento: ' + (row.cancel_rate || 0) + '%'; } } } },
        scales: { x: B.chartAxisOptions('money', { beginAtZero: true, stacked: true }), y: B.chartAxisOptions('label', { stacked: true, grid: { display: false } }) }
      }
    });
    window.requestAnimationFrame(function () {
      if (chart && typeof chart.resize === 'function') chart.resize();
      // UX.CHARTS.R1 — external legend + insight
      var billedVals = groupedDatasets[0].data;
      var leaderIdx  = billedVals.reduce(function (max, v, i) { return v > billedVals[max] ? i : max; }, 0);
      var cartSum    = groupedDatasets[1].data.reduce(function (s, v) { return s + v; }, 0);
      var totalSum   = billedVals.reduce(function (s, v) { return s + v; }, 0);
      var insightObj = null;
      if (window.BIOMIE_CHART_GOVERNANCE && fullLabels.length) {
        var G = window.BIOMIE_CHART_GOVERNANCE;
        insightObj = G.buildRankInsight(fullLabels, billedVals);
        cfApplyGovernance(canvasId, groupedDatasets, insightObj);
      }
    });
  };

  B.createMixedDailyChart = function (canvasId, daily) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var rows = daily || [];
    if (!rows.length) { B._chartEmpty(canvasId, 'Sem movimentacao diaria no periodo.'); return; }
    var compactRows = rows.slice(-14);

    var theme       = B._chartTheme();
    var billedColor = theme.billed;
    var authColor   = theme.authorized;
    var cancelColor = theme.cancelled;
    var ctx = canvas.getContext('2d');

    var mixedDatasets = [
      { type: 'bar',  label: 'NF',         data: compactRows.map(function (r) { return Number(r.billed_value    || 0); }), backgroundColor: B.buildGradient(ctx, billedColor, 0.26), borderColor: billedColor, borderWidth: 1, borderRadius: 8, stack: 'status', order: 2 },
      { type: 'bar',  label: 'Carteira',   data: compactRows.map(function (r) { return Number(r.authorized_value|| 0); }), backgroundColor: B.buildGradient(ctx, authColor,   0.22), borderColor: authColor,   borderWidth: 1, borderRadius: 8, stack: 'status', order: 2 },
      { type: 'line', label: 'Canceladas', data: compactRows.map(function (r) { return Number(r.cancelled_value || 0); }), borderColor: cancelColor, backgroundColor: B.hexToRgba(cancelColor, 0.1), pointBackgroundColor: cancelColor, pointRadius: 0, pointHoverRadius: 3, tension: 0.32, fill: false, order: 1 }
    ];
    B._renderChart(canvasId, {
      data: {
        labels: compactRows.map(function (r) { return B.chartDateLabel(r.date || ''); }),
        datasets: mixedDatasets
      },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + B.formatCurrency(ctx.parsed.y); } } } },
        scales: { x: B.chartAxisOptions('label', { stacked: true, grid: { display: false } }), y: B.chartAxisOptions('money', { stacked: true, beginAtZero: true }) }
      }
    });
    // UX.CHARTS.R1 — external legend
    window.requestAnimationFrame(function () { cfApplyGovernance(canvasId, mixedDatasets, null); });
  };

  B.createOrdersStatusChart = function (canvasId, orders, pendingOrders, cancelledOrders) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var range      = B.getDateRange();
    var seededDates = B.buildRangeDates(range.start, range.end);
    var billed     = B.aggregateRowsByDate(orders       || [], seededDates);
    var pending    = B.aggregateRowsByDate(pendingOrders || [], seededDates);
    var cancelled  = B.aggregateRowsByDate(cancelledOrders || [], seededDates);
    var dateSet    = new Set(seededDates);
    billed.forEach(function (r) { dateSet.add(r.date); });
    pending.forEach(function (r) { dateSet.add(r.date); });
    cancelled.forEach(function (r) { dateSet.add(r.date); });
    var dates = Array.from(dateSet).sort(function (a, b) { return a.localeCompare(b); });
    if (!dates.length) { B._chartEmpty(canvasId, 'Nenhuma nota fiscal emitida no periodo.'); return; }

    var billedMap    = new Map(billed.map(function (r)    { return [r.date, r.value]; }));
    var pendingMap   = new Map(pending.map(function (r)   { return [r.date, r.value]; }));
    var cancelledMap = new Map(cancelled.map(function (r) { return [r.date, r.value]; }));
    var theme       = B._chartTheme();
    var billedColor = theme.billed;
    var authColor   = theme.authorized;
    var cancelColor = theme.cancelled;
    var ctx = canvas.getContext('2d');

    var statusDatasets = [
      { label: 'NF',         data: dates.map(function (d) { return Number(billedMap.get(d)    || 0); }), backgroundColor: B.buildGradient(ctx, billedColor, 0.28), borderColor: billedColor, borderWidth: 1, borderRadius: 8, stack: 'status' },
      { label: 'Carteira',   data: dates.map(function (d) { return Number(pendingMap.get(d)   || 0); }), backgroundColor: B.buildGradient(ctx, authColor,   0.22), borderColor: authColor,   borderWidth: 1, borderRadius: 8, stack: 'status' },
      { label: 'Canceladas', data: dates.map(function (d) { return Number(cancelledMap.get(d) || 0); }), backgroundColor: B.buildGradient(ctx, cancelColor,  0.20), borderColor: cancelColor,  borderWidth: 1, borderRadius: 8, stack: 'status' }
    ];
    B._renderChart(canvasId, {
      type: 'bar',
      data: {
        labels: dates.map(function (d) { return B.chartDateLabel(d); }),
        datasets: statusDatasets
      },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + B.formatCurrency(ctx.parsed.y); } } } },
        scales: { x: B.chartAxisOptions('label', { stacked: true, grid: { display: false } }), y: B.chartAxisOptions('money', { stacked: true, beginAtZero: true }) }
      }
    });
    // UX.CHARTS.R1 — external legend + status insight
    window.requestAnimationFrame(function () {
      var insight = null;
      if (window.BIOMIE_CHART_GOVERNANCE) {
        insight = window.BIOMIE_CHART_GOVERNANCE.buildStatusInsight(statusDatasets, dates);
      }
      cfApplyGovernance(canvasId, statusDatasets, insight);
    });
  };

  B.createOrdersVendorChart = function (canvasId, rows) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var ranking = B.aggregateRowsByVendor(rows || []).slice(0, 8);
    if (!ranking.length) { B._chartEmpty(canvasId, 'Nenhum vendedor com faturamento no periodo.'); return; }

    var ctx    = canvas.getContext('2d');
    var accent = B._chartTheme().accent;

    B._renderChart(canvasId, {
      type: 'bar',
      data: { labels: ranking.map(function (r) { return B.shortenLabel(r.label, 18); }), datasets: [{ label: 'Receita NF', data: ranking.map(function (r) { return r.value; }), backgroundColor: B.buildGradient(ctx, accent, 0.28), borderColor: accent, borderWidth: 1, borderRadius: 10, maxBarThickness: 26 }] },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { title: function (items) { return ranking[items[0].dataIndex] && ranking[items[0].dataIndex].label ? ranking[items[0].dataIndex].label : items[0].label; }, label: function (ctx) { var row = ranking[ctx.dataIndex] || { value: 0, count: 0 }; return 'Receita: ' + B.formatCurrency(row.value) + ' | NF: ' + B.formatNumber(row.count); } } } },
        scales: { x: B.chartAxisOptions('label', { grid: { display: false } }), y: B.chartAxisOptions('money', { beginAtZero: true }) }
      }
    });
  };

  function productLegendId(canvasId) {
    if (canvasId === 'productsRevenueChart') return 'productsRevenueLegend';
    if (canvasId === 'productsShareChart') return 'productsShareLegend';
    return canvasId + 'Legend';
  }

  function buildTopProductSlices(rows, metric) {
    var source = (rows || []).filter(function (row) {
      return Number(row && row[metric] || 0) > 0;
    });
    var total = source.reduce(function (sum, row) { return sum + Number(row[metric] || 0); }, 0);
    var top = source.slice(0, 10);
    var topTotal = top.reduce(function (sum, row) { return sum + Number(row[metric] || 0); }, 0);
    var slices = top.map(function (row) {
      return Object.assign({}, row, { _value: Number(row[metric] || 0), _isOther: false });
    });
    if (total > topTotal) {
      slices.push({
        product_code: '',
        description: 'Outros',
        product_name: 'Outros',
        quantity: source.slice(10).reduce(function (sum, row) { return sum + Number(row.quantity || 0); }, 0),
        revenue: total - topTotal,
        invoices_count: source.slice(10).reduce(function (sum, row) { return sum + Number(row.invoices_count || row.orders || 0); }, 0),
        _value: total - topTotal,
        _isOther: true
      });
    }
    return { total: total, slices: slices };
  }

  function renderProductLegend(canvasId, slices, total, metric, colors) {
    var target = document.getElementById(productLegendId(canvasId));
    if (!target) return;
    if (!slices.length) { target.innerHTML = ''; return; }
    target.innerHTML = slices.map(function (row, idx) {
      var name = row.description || row.product_name || 'Produto sem descricao';
      var value = Number(row._value || 0);
      var pct = total > 0 ? (value / total * 100).toFixed(1) : '0.0';
      var meta = metric === 'revenue'
        ? B.formatCurrency(value) + ' · ' + pct + '%'
        : B.formatNumber(value) + ' un · ' + pct + '%';
      return '<button type="button" class="product-donut-legend__item"'
        + (row._isOther ? ' disabled aria-disabled="true"' : '')
        + ' data-product-code="' + B.escapeHtml(row.product_code || '') + '"'
        + ' data-product-label="' + B.escapeHtml(name) + '">'
        + '<span class="product-donut-legend__swatch" style="background:' + B.escapeHtml(colors[idx] || '#7c3aed') + '"></span>'
        + '<span class="product-donut-legend__name" title="' + B.escapeHtml(name) + '">' + B.escapeHtml(name) + '</span>'
        + '<span class="product-donut-legend__meta">' + B.escapeHtml(meta) + '</span>'
        + '</button>';
    }).join('');
    target.querySelectorAll('.product-donut-legend__item:not([disabled])').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (typeof window.openProductBreakdown === 'function') {
          window.openProductBreakdown(btn.getAttribute('data-product-code') || '', btn.getAttribute('data-product-label') || 'Produto sem descricao');
        }
      });
    });
  }

  function renderProductDoughnut(canvasId, rows, metric, emptyMessage) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var built = buildTopProductSlices(rows, metric);
    if (!built.slices.length || built.total <= 0) {
      var legend = document.getElementById(productLegendId(canvasId));
      if (legend) legend.innerHTML = '';
      B._chartEmpty(canvasId, emptyMessage || 'Sem produtos faturados no periodo.');
      return;
    }

    var theme = B._chartTheme();
    var baseColors = ['#7c3aed', '#2563eb', '#059669', '#f59e0b', '#ef4444', '#0891b2', '#db2777', '#65a30d', '#9333ea', '#ea580c', '#94a3b8'];
    var colors = built.slices.map(function (_, idx) { return baseColors[idx] || B.adjustColour(theme.accent, idx * 9); });

    renderProductLegend(canvasId, built.slices, built.total, metric, colors);

    B._renderChart(canvasId, {
      type: 'doughnut',
      data: {
        labels: built.slices.map(function (row) { return B.shortenLabel(row.description || row.product_name || 'Produto', 24); }),
        datasets: [{
          data: built.slices.map(function (row) { return row._value; }),
          backgroundColor: colors.map(function (color) { return B.hexToRgba(color, 0.86); }),
          borderColor: '#fff',
          borderWidth: 2,
          hoverOffset: 5
        }]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        animation: false,
        cutout: '66%',
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: function (items) {
                var row = built.slices[items[0].dataIndex] || {};
                return row.description || row.product_name || items[0].label;
              },
              label: function (ctx) {
                var row = built.slices[ctx.dataIndex] || {};
                var value = Number(row._value || 0);
                var pct = built.total > 0 ? (value / built.total * 100).toFixed(1) : '0.0';
                if (metric === 'revenue') {
                  return B.formatCurrency(value) + ' | ' + pct + '% | Qtd ' + B.formatNumber(row.quantity || 0);
                }
                return B.formatNumber(value) + ' un | ' + pct + '% | Receita ' + B.formatCurrency(row.revenue || 0);
              }
            }
          }
        },
        onClick: function (_event, elements) {
          if (!elements || !elements.length) return;
          var row = built.slices[elements[0].index] || {};
          if (row._isOther || !row.product_code || typeof window.openProductBreakdown !== 'function') return;
          window.openProductBreakdown(row.product_code, row.description || row.product_name || 'Produto sem descricao');
        }
      }
    }, { message: emptyMessage || 'Sem produtos faturados no periodo.' });
  }

  B.createProductMixChart = function (canvasId, rows) {
    renderProductDoughnut(canvasId, rows, 'revenue', 'Sem produtos faturados no periodo.');
  };

  B.createProductShareChart = function (canvasId, rows) {
    renderProductDoughnut(canvasId, rows, 'quantity', 'Sem composicao de produtos no periodo.');
  };

  // ── UX.CHARTS.R1 — Governance helper ────────────────────────────────────
  function cfApplyGovernance(canvasId, datasets, insightObj) {
    if (!window.BIOMIE_CHART_GOVERNANCE) return;
    var G = window.BIOMIE_CHART_GOVERNANCE;
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var container = canvas.closest('.chart-card, .mag-chart-card') || canvas.parentElement;
    if (!container) return;
    if (datasets && datasets.length > 1) G.ensureLegend(container, datasets);
    if (insightObj) G.renderInsight(container, insightObj);
  }

  // ── Phase 10G: Generic horizontal bar chart ──────────────────────────────
  B.createHorizontalBarChart = function (canvasId, labels, data, opts) {
    opts = opts || {};
    var total = data.reduce(function (s, v) { return s + Number(v || 0); }, 0);
    var barColor = opts.barColor || 'rgba(150, 20, 10, 0.7)';

    B._renderChart(canvasId, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          data: data,
          backgroundColor: barColor,
          borderRadius: 6,
          barThickness: 'flex',
          maxBarThickness: 22
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        resizeDelay: 120,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                var value = ctx.parsed && ctx.parsed.x != null ? ctx.parsed.x : 0;
                var share = total > 0 ? (Number(value || 0) / total * 100).toFixed(1) : '0.0';
                return B.formatCurrency(value) + ' | ' + share + '%';
              }
            }
          }
        },
        scales: {
          x: B.chartAxisOptions('money', { beginAtZero: true }),
          y: B.chartAxisOptions('label', { grid: { display: false } })
        }
      }
    }, { message: opts.emptyMessage || 'Nenhum dado para exibir.' });
  };

})(window.BIomie || (window.BIomie = {}));
