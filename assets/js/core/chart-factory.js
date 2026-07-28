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

  function cfPointerOnData(_event, elements, chart) {
    if (!chart || !chart.canvas) return;
    chart.canvas.style.cursor = elements && elements.length ? 'pointer' : 'default';
  }

  function cfOpenOverviewDetail(chartId, canvas, filters, title) {
    var panel = canvas && canvas.closest ? canvas.closest('[data-chart-id="' + chartId + '"]') : null;
    var trigger = panel && panel.querySelector ? panel.querySelector('[data-drill="' + chartId + '"]') : null;
    if (window.BIomieOverviewDetail && typeof window.BIomieOverviewDetail.open === 'function') {
      window.BIomieOverviewDetail.open({
        chartId: chartId,
        title: title || '',
        filters: filters || {},
        triggerElement: trigger || canvas
      });
      return;
    }
    if (trigger && typeof trigger.click === 'function') trigger.click();
  }

  B.createLineChart = function (canvasId, labels, revenueValues, orderValues, chartOptions) {
    chartOptions = chartOptions || {};
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;

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
    var rawDates = Array.isArray(chartOptions.dates) ? chartOptions.dates : labels;
    var totalRevenue = revenueValues.reduce(function (sum, value) { return sum + Number(value || 0); }, 0);
    canvas.setAttribute(
      'aria-label',
      'Evolucao do faturamento em ' + labels.length + ' pontos. Total ' + B.formatCurrency(totalRevenue)
        + '. Passe o mouse para os valores; clique em um ponto para abrir o detalhamento do dia.'
    );

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
          { type: 'line', label: 'Receita',  data: revenueValues, borderColor: accent, backgroundColor: areaGradient, borderWidth: 2.5, pointBackgroundColor: accent, pointBorderColor: B._surfaceColor(), pointBorderWidth: 2, pointRadius: 0, pointHoverRadius: 5, pointHitRadius: 10, tension: 0.4, fill: 'origin', yAxisID: 'y', order: 1 },
          { type: 'line', label: 'Media 7d', data: movingAvg7, borderColor: B.hexToRgba(accent, 0.48), backgroundColor: 'transparent', borderWidth: 1.5, borderDash: [5, 4], pointRadius: 0, pointHoverRadius: 3, pointHitRadius: 10, tension: 0.4, fill: false, yAxisID: 'y', order: 2 },
          { type: 'bar',  label: 'NF', data: orderValues, backgroundColor: B.hexToRgba(theme.info, 0.15), borderColor: B.hexToRgba(theme.info, 0.40), borderWidth: 1, borderRadius: 3, maxBarThickness: 5, yAxisID: 'y1', order: 3 }
        ]
      },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        onHover: cfPointerOnData,
        onClick: function (_event, elements, chart) {
          if (!elements || !elements.length) return;
          var index = elements[0].index;
          cfOpenOverviewDetail(
            'revenue_evolution',
            chart.canvas,
            { date: rawDates[index] || '' },
            'Evolucao da Receita'
          );
        },
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

  B.createMonthlyComparisonChart = function (canvasId, rows, chartOptions) {
    chartOptions = chartOptions || {};
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var limit = Math.max(1, Number(chartOptions.limit || 12)); // série de 12 meses (M-11..M0)
    var items = (rows || []).slice(-limit);
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
      { type: 'line', label: 'Meta do período', data: targets, borderColor: muted, backgroundColor: 'transparent', borderDash: [6, 4], pointHoverRadius: 6, pointHitRadius: 10, tension: 0.4, order: 1 },
      { type: 'line', label: 'Previsão',    data: forecast, borderColor: secondary, backgroundColor: B.hexToRgba(secondary, 0.12), pointBackgroundColor: secondary, pointBorderColor: B._surfaceColor(), pointRadius: 3, pointHoverRadius: 6, pointHitRadius: 10, tension: 0.4, fill: false, order: 2 }
    ];
    var yAxis = B.chartAxisOptions('money', { beginAtZero: true, ticks: { maxTicksLimit: 4 } });
    var scaleMax = Number(chartOptions.scaleMax || 0);
    if (scaleMax > 0) yAxis.max = scaleMax;

    var rangeLabel = labels.length > 1 ? labels[0] + ' a ' + labels[labels.length - 1] : labels[0];
    canvas.setAttribute('role', 'img');
    canvas.setAttribute(
      'aria-label',
      'Comparativo mensal de ' + rangeLabel + '. ' + items.map(function (row, i) {
        return labels[i] + ': receita ' + B.formatCurrency(revenue[i])
          + ', meta ' + B.formatCurrency(targets[i]) + ', previsao ' + B.formatCurrency(forecast[i]) + '.';
      }).join(' ')
    );

    var chart = B._renderChart(canvasId, {
      data: {
        labels: labels,
        datasets: monthlyDatasets
      },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + B.formatCurrency(ctx.parsed.y); } } } },
        scales: {
          x: B.chartAxisOptions('label', { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 0, minRotation: 0, font: { size: 10 } } }),
          y: yAxis
        }
      }
    });
    // UX.CHARTS.R1 — external legend
    if (chartOptions.governance !== false) {
      window.requestAnimationFrame(function () { cfApplyGovernance(canvasId, monthlyDatasets, null); });
    }
    return { chart: chart, datasets: monthlyDatasets, items: items };
  };

  B.createMonthlyComparisonSemesters = function (rows, chartOptions) {
    chartOptions = chartOptions || {};
    var items = (rows || []).filter(function (row) { return row && typeof row === 'object'; });
    items.sort(function (a, b) {
      return String(a.competencia || a.year_month || '').localeCompare(String(b.competencia || b.year_month || ''));
    });
    items = items.slice(-12);

    var firstCanvasId  = chartOptions.firstCanvasId  || 'monthComparisonChartH1';
    var secondCanvasId = chartOptions.secondCanvasId || 'monthComparisonChartH2';
    if (!items.length) {
      B._chartEmpty(firstCanvasId, 'Historico insuficiente para comparacao.');
      B._chartEmpty(secondCanvasId, 'Historico insuficiente para comparacao.');
      return null;
    }

    var values = [];
    items.forEach(function (row) {
      values.push(Number(row.revenue || 0));
      values.push(Number(row.target || 0));
      values.push(Number(row.forecast != null ? row.forecast : (row.revenue || 0)));
    });
    var dataMax = Math.max.apply(null, values.concat([0]));
    var sharedScaleMax = dataMax > 0 ? Math.ceil(dataMax * 1.10) : 0;
    var firstHalf  = items.slice(0, 6);
    var secondHalf = items.slice(6, 12);

    var setRangeLabel = function (elementId, half, fallback) {
      var element = document.getElementById(elementId);
      if (!element) return;
      if (!half.length) { element.textContent = fallback; return; }
      var first = B.monthLabel(half[0].competencia || half[0].year_month || half[0].label || '');
      var last  = B.monthLabel(half[half.length - 1].competencia || half[half.length - 1].year_month || half[half.length - 1].label || '');
      element.textContent = first === last ? first : first + ' — ' + last;
    };
    setRangeLabel(chartOptions.firstLabelId || 'monthComparisonLabelH1', firstHalf, 'Meses 1 a 6');
    setRangeLabel(chartOptions.secondLabelId || 'monthComparisonLabelH2', secondHalf, 'Meses 7 a 12');

    var sharedOptions = { limit: 6, scaleMax: sharedScaleMax, governance: false };
    var firstChart  = B.createMonthlyComparisonChart(firstCanvasId, firstHalf, sharedOptions);
    var secondChart = B.createMonthlyComparisonChart(secondCanvasId, secondHalf, sharedOptions);
    return { first: firstChart, second: secondChart, rows: items, scaleMax: sharedScaleMax };
  };

  B.createBarChart = function (canvasId, labels, values, chartOptions) {
    chartOptions = chartOptions || {};
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var ctx     = canvas.getContext('2d');
    var accent  = chartOptions.color || B._chartTheme().accent;

    // Um único tom para todas as barras: a cor segue a medida, não o rank —
    // a magnitude já está codificada no comprimento da barra.
    B._renderChart(canvasId, {
      type: 'bar',
      data: { labels: labels, datasets: [{ label: chartOptions.label || 'Receita', data: values, backgroundColor: B.buildGradient(ctx, accent, 0.28), borderColor: accent, borderWidth: 1, borderRadius: 10, maxBarThickness: chartOptions.maxBarThickness || 28 }] },
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

    var ranking = (rows || []).slice(0, 8);
    if (!ranking.length) { B._chartEmpty(canvasId, 'Nenhum vendedor com faturamento no periodo.'); return; }

    var theme     = B._chartTheme();
    var accent    = theme.accent;
    var secondary = theme.info;
    var labels     = ranking.map(function (r) { return B.shortenLabel(r.vendor_name, 12); });
    var fullLabels = ranking.map(function (r) { return r.vendor_name || 'Sem vendedor'; });
    var revenue    = ranking.map(function (r) { return Number(r.revenue != null ? r.revenue : (r.gross_revenue || 0)); });
    var ticket     = ranking.map(function (r) { return Number(r.ticket_avg != null ? r.ticket_avg : (Number(r.orders || 0) > 0 ? Number(r.revenue || 0) / Number(r.orders || 1) : 0)); });
    var orders     = ranking.map(function (r) { return Number(r.orders || r.invoices_count || r.nfs || 0); });
    var totalRevenue = revenue.reduce(function (sum, value) { return sum + Number(value || 0); }, 0);
    var ctx = canvas.getContext('2d');

    var leaderIndex = revenue.reduce(function (best, value, index) {
      return Number(value || 0) > Number(revenue[best] || 0) ? index : best;
    }, 0);
    canvas.setAttribute(
      'aria-label',
      'Ranking comercial com ' + ranking.length + ' vendedores. Lider: ' + fullLabels[leaderIndex]
        + ', receita ' + B.formatCurrency(revenue[leaderIndex] || 0)
        + '. Passe o mouse para receita, ticket e notas; clique para abrir o vendedor.'
    );

    var ticketDatasets = [
      { type: 'bar',  label: 'Receita', data: revenue, backgroundColor: B.buildGradient(ctx, accent, 0.26), hoverBackgroundColor: B.hexToRgba(accent, 0.42), borderColor: accent, borderWidth: 1, hoverBorderWidth: 2, borderRadius: 10, maxBarThickness: 30, yAxisID: 'y' },
      { type: 'line', label: 'Ticket médio', data: ticket, borderColor: secondary, backgroundColor: B.hexToRgba(secondary, 0.12), pointBackgroundColor: secondary, pointBorderColor: B._surfaceColor(), pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6, pointHitRadius: 12, tension: 0.35, yAxisID: 'y1' }
    ];
    B._renderChart(canvasId, {
      data: { labels: labels, datasets: ticketDatasets },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        onHover: cfPointerOnData,
        onClick: function (_event, elements, chart) {
          if (!elements || !elements.length) return;
          var row = ranking[elements[0].index] || {};
          var vendorId = String(row.vendor_id || row.vendor_ref || row.codigo_vendedor_omie || '').trim();
          if (vendorId && typeof window.openVendorDrawer === 'function') {
            window.openVendorDrawer(vendorId);
            return;
          }
          cfOpenOverviewDetail(
            'vendor_ranking',
            chart.canvas,
            { vendor_id: vendorId, vendor_name: row.vendor_name || '' },
            'Ranking Comercial'
          );
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: function (items) { return fullLabels[items[0].dataIndex] || items[0].label; },
              label: function (tooltipItem) {
                if (tooltipItem.dataset.label === 'Ticket médio') return 'Ticket: ' + B.formatCurrency(tooltipItem.parsed.y);
                var share = totalRevenue > 0 ? (Number(tooltipItem.parsed.y || 0) / totalRevenue * 100).toFixed(1) : '0.0';
                return 'Receita: ' + B.formatCurrency(tooltipItem.parsed.y) + ' | ' + share + '% do ranking';
              },
              footer: function (items) {
                var index = items && items.length ? items[0].dataIndex : -1;
                return index >= 0 ? 'Notas fiscais: ' + B.formatNumber(orders[index] || 0) + ' | Clique para detalhar' : '';
              }
            }
          }
        },
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

  /**
   * Metas do time — realizado × meta por vendedor (dashboard).
   * Bullet chart: trilha leve até o nível máximo; barra = realizado, colorida
   * por marco (abaixo/quase lá/Meta 1/Meta 2/Meta 3); cada meta tem um traço
   * próprio. O canvas desenha um status textual por linha e o tooltip preserva
   * todos os valores. Não há animação contínua nem listener adicional.
   */
  B.createVendorTargetsChart = function (canvasId, matrix) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var rows = (matrix || []).filter(function (r) {
      return Number(r.target_period || 0) > 0 && String(r.vendor_name || '') !== 'Sem vendedor';
    });
    // Leaderboard: ordena por atingimento (quem está mais perto da meta em cima)
    rows.sort(function (a, b) {
      var attA = Number(a.attainment_pct || 0) || (Number(a.billed_value || 0) / Number(a.target_period || 1)) * 100;
      var attB = Number(b.attainment_pct || 0) || (Number(b.billed_value || 0) / Number(b.target_period || 1)) * 100;
      return attB - attA;
    });
    rows = rows.slice(0, 10);
    if (!rows.length) {
      B._chartEmpty(canvasId, 'Defina metas em Rota Comercial → Metas para acompanhar aqui.');
      return;
    }

    var wrap = canvas.parentElement;
    if (wrap) {
      var h = Math.max(250, Math.min(560, rows.length * 44 + 104));
      wrap.style.height = h + 'px';
      wrap.style.minHeight = h + 'px';
    }

    var theme       = B._chartTheme();
    var purpleColor = B.getCssVar('--purple') || '#7c3aed';
    var goldColor   = B.getCssVar('--yellow') || '#d97706';
    var trackColor  = B.alphaColor(theme.subtle, 0.12); // evita a trilha cinza dominante
    var badColor    = theme.cancelled;  // abaixo do ritmo
    var warnColor   = theme.authorized; // perto da Meta 1
    var meta1Color  = theme.billed;     // Meta 1 atingida
    var meta2Color  = theme.info;       // Meta 2 atingida
    var meta3Color  = purpleColor;      // Meta 3 atingida
    var levelColors = [theme.title, meta2Color, meta3Color, goldColor];
    var labels     = rows.map(function (r) { return B.shortenLabel(r.vendor_name, 18); });
    var fullLabels = rows.map(function (r) { return r.vendor_name || 'Sem vendedor'; });

    var realized = rows.map(function (r) { return Number(r.billed_value || 0); });
    var metas    = rows.map(function (r) { return Number(r.target_period || 0); });
    var attPcts  = rows.map(function (r, i) {
      var att = Number(r.attainment_pct || 0);
      return att > 0 ? att : (metas[i] > 0 ? (realized[i] / metas[i]) * 100 : 0);
    });
    // Normaliza Meta 1 + níveis extras. A Meta 1 sempre abre a lista, mesmo
    // quando um payload antigo ainda não envia tier_levels.
    var levelSets = rows.map(function (r, i) {
      var levels = [metas[i]];
      var incoming = Array.isArray(r.tier_levels) ? r.tier_levels.map(Number) : [];
      incoming.filter(function (value) { return Number.isFinite(value) && value > metas[i] + 0.005; })
        .sort(function (a, b) { return a - b; })
        .forEach(function (value) {
          if (value > levels[levels.length - 1] + 0.005) levels.push(value);
        });
      return levels;
    });
    var achievedLevels = levelSets.map(function (levels, i) {
      return levels.filter(function (value) { return realized[i] >= value; }).length;
    });
    var milestoneCounts = achievedLevels.reduce(function (counts, achieved) {
      if (achieved >= 3) counts[3]++;
      else counts[achieved]++;
      return counts;
    }, [0, 0, 0, 0]);

    // Alvo visual de cada trilha: nível máximo do vendedor (ou meta, se sem níveis).
    var topLevels = levelSets.map(function (levels, i) {
      return Math.max(levels[levels.length - 1] || 0, metas[i], realized[i]);
    });
    var chartMax = Math.max.apply(null, topLevels) * 1.08;

    var milestoneState = function (i) {
      var levels   = levelSets[i] || [metas[i]];
      var achieved = achievedLevels[i] || 0;
      var next     = achieved < levels.length ? levels[achieved] : null;
      var gap      = next != null ? Math.max(next - realized[i], 0) : 0;
      var near     = achieved === 0 && attPcts[i] >= 85;
      var color    = achieved >= 3 ? meta3Color : (achieved === 2 ? meta2Color : (achieved === 1 ? meta1Color : (near ? warnColor : badColor)));
      var symbol   = achieved >= 3 ? '★' : (achieved === 2 ? '◆' : (achieved === 1 ? '✓' : '↗'));
      var badge;
      if (achieved >= 3) badge = 'Meta 3 atingida ' + symbol;
      else if (achieved === 2) badge = 'Meta 2 atingida ' + symbol;
      else if (achieved === 1) badge = 'Meta 1 atingida ' + symbol;
      else badge = (near ? 'Quase lá' : 'Precisamos bater') + ' • Meta 1';

      var detail;
      if (next != null) {
        detail = 'Próxima Meta ' + (achieved + 1) + ': faltam ' + B.formatCurrency(gap);
      } else if (achieved >= 3) {
        detail = 'Todos os níveis cadastrados foram atingidos.';
      } else {
        detail = 'Objetivo do período concluído.';
      }

      return { achieved: achieved, total: levels.length, next: next, gap: gap, color: color, symbol: symbol, badge: badge, detail: detail };
    };
    var milestoneStates = rows.map(function (_, i) { return milestoneState(i); });

    canvas.setAttribute('role', 'img');
    canvas.setAttribute(
      'aria-label',
      'Metas do time por vendedor. ' + milestoneCounts[0] + ' abaixo da Meta 1; '
        + milestoneCounts[1] + ' na Meta 1; ' + milestoneCounts[2] + ' na Meta 2; '
        + milestoneCounts[3] + ' na Meta 3 ou acima. '
        + rows.map(function (row, i) {
          return fullLabels[i] + ': realizado ' + B.formatCurrency(realized[i]) + ', Meta 1 '
            + B.formatCurrency(metas[i]) + ', ' + milestoneStates[i].badge + '. ' + milestoneStates[i].detail;
        }).join(' ')
    );

    var statusColor = function (i, alpha) {
      var c = milestoneStates[i].color;
      return alpha != null ? B.alphaColor(c, alpha) : c;
    };

    // Ordem de leitura do tooltip: Realizado (0) → Meta 1 (1) → Meta 2 (2) → …
    // Os datasets são criados em ordem de DESENHO (marcos por cima das barras),
    // que não é a ordem em que se lê.
    var tooltipRank = function (item) {
      var label = (item && item.dataset && item.dataset.label) || '';
      if (label === 'Realizado') return 0;
      var m = /^Meta (\d+)$/.exec(label);
      return m ? Number(m[1]) : 99;
    };

    var datasets = [
      { // trilha do bullet (fundo) — fora da legenda/tooltip
        type: 'bar', label: '_track', data: topLevels,
        backgroundColor: trackColor,
        borderColor: B.alphaColor(theme.subtle, 0.20),
        borderWidth: 1, borderRadius: 8, barPercentage: 0.62, grouped: false, order: 4
      },
      { // realizado — cor pelo status de atingimento
        type: 'bar', label: 'Realizado', data: realized,
        backgroundColor: realized.map(function (_, i) { return statusColor(i, 0.78); }),
        borderColor: realized.map(function (_, i) { return statusColor(i); }),
        borderWidth: 1.5, borderRadius: 8, barPercentage: 0.62, grouped: false, order: 3
      },
      { // meta proporcional — traço escuro
        type: 'line', label: 'Meta 1', data: metas,
        showLine: false, pointStyle: 'line', pointRadius: 14, pointRotation: 90,
        pointBorderColor: levelColors[0], pointBorderWidth: 3, borderColor: levelColors[0],
        pointHoverRadius: 15, pointHitRadius: 14, pointHoverBorderWidth: 4, pointHoverBorderColor: levelColors[0], order: 1
      }
    ];

    // Níveis extras (Meta 2..N) — cores distintas. Os três primeiros marcos
    // ficam explícitos; níveis adicionais continuam no gráfico sem poluir a legenda.
    var maxExtra = 0;
    levelSets.forEach(function (levels) {
      if (levels.length - 1 > maxExtra) maxExtra = levels.length - 1;
    });
    for (var ti = 0; ti < Math.min(maxExtra, 4); ti++) {
      (function (idx) {
        var levelNumber = idx + 2;
        var markerColor = levelColors[Math.min(idx + 1, levelColors.length - 1)];
        datasets.push({
          type: 'line', label: levelNumber <= 3 ? 'Meta ' + levelNumber : '_nivel' + levelNumber,
          data: levelSets.map(function (levels) {
            return levels.length > idx + 1 ? Number(levels[idx + 1]) : null;
          }),
          showLine: false, pointStyle: 'line', pointRadius: 10, pointRotation: 90,
          pointBorderColor: markerColor, pointBorderWidth: 2.5, borderColor: markerColor,
          pointHoverRadius: 12, pointHitRadius: 12, pointHoverBorderWidth: 3.5, pointHoverBorderColor: markerColor, order: 0
        });
      })(ti);
    }

    // Rótulo direto + status por linha. O badge fica numa faixa do próprio
    // canvas em telas largas; no mobile, o tooltip mantém a explicação completa.
    var availableWidth = (wrap && wrap.clientWidth) || canvas.clientWidth || 0;
    var statusRailForWidth = function (width) { return Number(width || 0) >= 720 ? 166 : 12; };
    var statusRailWidth = statusRailForWidth(availableWidth);
    var roundedRectPath = function (c, x, y, w, h, radius) {
      var r = Math.min(radius, h / 2, w / 2);
      c.beginPath();
      c.moveTo(x + r, y);
      c.lineTo(x + w - r, y);
      c.quadraticCurveTo(x + w, y, x + w, y + r);
      c.lineTo(x + w, y + h - r);
      c.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
      c.lineTo(x + r, y + h);
      c.quadraticCurveTo(x, y + h, x, y + h - r);
      c.lineTo(x, y + r);
      c.quadraticCurveTo(x, y, x + r, y);
      c.closePath();
    };
    var directLabels = {
      id: 'vendorTargetsLabels',
      resize: function (chart, args) {
        var width = args && args.size ? args.size.width : chart.width;
        chart.options.layout.padding.right = statusRailForWidth(width);
      },
      afterDatasetsDraw: function (chart) {
        var meta = chart.getDatasetMeta(1); // dataset Realizado
        if (!meta || !meta.data) return;
        var c = chart.ctx;
        c.save();
        c.font = '700 10.5px ' + ((window.Chart.defaults.font && window.Chart.defaults.font.family) || 'sans-serif');
        c.textBaseline = 'middle';
        var active = typeof chart.getActiveElements === 'function' ? chart.getActiveElements() : [];
        var activeIndex = active && active.length ? active[0].index : -1;
        meta.data.forEach(function (bar, i) {
          if (!bar) return;
          var state = milestoneStates[i];
          var txt  = B.formatCurrencyShort(realized[i]) + ' · ' + attPcts[i].toFixed(0) + '% ' + state.symbol;
          var textWidth = c.measureText(txt).width;
          var x    = bar.x + 6;
          var maxX = chart.chartArea.right - textWidth - 4;
          var nextMarkerX = state.next != null ? chart.scales.x.getPixelForValue(state.next) : null;
          var collidesNextMarker = nextMarkerX != null && x + textWidth + 8 >= nextMarkerX;
          var barWidth = Math.abs(bar.x - bar.base);
          var fitsInsideBar = barWidth >= textWidth + 14;
          if (collidesNextMarker && !fitsInsideBar) {
            // Em telas estreitas, prioriza a leitura do percentual e deixa o
            // valor exato no tooltip para não atravessar o marcador da meta.
            txt = attPcts[i].toFixed(0) + '% ' + state.symbol;
            textWidth = c.measureText(txt).width;
            maxX = chart.chartArea.right - textWidth - 4;
            collidesNextMarker = nextMarkerX != null && x + textWidth + 8 >= nextMarkerX;
            fitsInsideBar = barWidth >= textWidth + 14;
          }
          if ((x > maxX || collidesNextMarker) && fitsInsideBar) {
            // Evita que o valor realizado cubra a próxima meta: move o texto
            // para dentro da barra, mantendo cor de alto contraste.
            c.fillStyle = theme.title;
            c.textAlign = 'right';
            c.fillText(txt, bar.x - 6, bar.y);
          } else {
            c.fillStyle = theme.title;
            c.textAlign = 'left';
            c.fillText(txt, x, bar.y);
          }

          if (statusRailForWidth(chart.width) > 20) {
            var railX = chart.chartArea.right + 10;
            var railRight = chart.width - 8;
            var railW = Math.max(0, railRight - railX);
            var pillH = activeIndex === i ? 24 : 22;
            if (railW > 40) {
              c.save();
              c.fillStyle = B.alphaColor(state.color, activeIndex === i ? 0.18 : 0.10);
              c.strokeStyle = B.alphaColor(state.color, activeIndex === i ? 0.72 : 0.42);
              c.lineWidth = activeIndex === i ? 1.5 : 1;
              roundedRectPath(c, railX, bar.y - pillH / 2, railW, pillH, 7);
              c.fill();
              c.stroke();
              c.fillStyle = theme.title;
              c.textAlign = 'center';
              c.font = (activeIndex === i ? '800 ' : '700 ') + '10px ' + ((window.Chart.defaults.font && window.Chart.defaults.font.family) || 'sans-serif');
              c.fillText(state.badge, railX + railW / 2, bar.y + 0.5);
              c.restore();
            }
          }
        });
        c.restore();
      }
    };

    B._renderChart(canvasId, {
      type: 'bar',
      data: { labels: labels, datasets: datasets },
      plugins: [directLabels],
      options: {
        indexAxis: 'y', responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        layout: { padding: { right: statusRailWidth } },
        interaction: { mode: 'index', axis: 'y', intersect: false },
        hover: { mode: 'index', axis: 'y', intersect: false },
        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: {
              color: theme.text,
              padding: 18,
              font: { size: 11, weight: '600' },
              generateLabels: function (chart) {
                var generate = window.Chart.defaults.plugins.legend.labels.generateLabels;
                return generate(chart).map(function (item) {
                  if (item.text === 'Realizado') {
                    item.text = 'Realizado (cor = nível)';
                    item.fillStyle = theme.subtle;
                    item.strokeStyle = theme.subtle;
                  }
                  return item;
                });
              },
              filter: function (item) { return item.text && item.text.charAt(0) !== '_'; },
              sort: function (a, b) {
                var order = { 'Realizado (cor = nível)': 0, 'Meta 1': 1, 'Meta 2': 2, 'Meta 3': 3 };
                return (order[a.text] == null ? 99 : order[a.text]) - (order[b.text] == null ? 99 : order[b.text]);
              }
            }
          },
          tooltip: {
            mode: 'index', axis: 'y', intersect: false,
            backgroundColor: theme.tooltipBg,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
            titleColor: theme.title,
            bodyColor: theme.text,
            footerColor: theme.title,
            padding: 12,
            boxPadding: 5,
            displayColors: true,
            // Tooltip nativo do canvas (não HTML) e sem animação: é o caminho
            // mais barato — nada de reflow/DOM a cada hover.
            animation: false,
            filter: function (c) { return c.dataset.label && c.dataset.label.charAt(0) !== '_'; },
            // Ordem LÓGICA: Realizado → Meta 1 → Meta 2 → Meta 3.
            // Sem isto o Chart.js ordena pelo `order` de desenho dos datasets e
            // o tooltip saía embaralhado (Meta 2, Meta 3, Meta 1, Realizado).
            itemSort: function (a, b) { return tooltipRank(a) - tooltipRank(b); },
            callbacks: {
              title: function (items) { return items.length ? (fullLabels[items[0].dataIndex] || items[0].label) : ''; },
              label: function (c) {
                var v = c.parsed.x;
                if (v == null) return null;

                var label = c.dataset.label;
                var i = c.dataIndex;
                var text = ' ' + label + ': ' + B.formatCurrency(v);

                // O atingimento pertence à Meta 1 — vai na própria linha, em vez
                // de virar mais uma linha solta no rodapé.
                if (label === 'Meta 1' && v > 0) {
                  text += '  ·  ' + attPcts[i].toFixed(1) + '%';
                }
                // Marca o próximo alvo do vendedor, para dar direção na leitura.
                var state = milestoneStates[i];
                if (state && state.next != null && Math.abs(v - state.next) < 0.01 && label !== 'Realizado') {
                  text += '  ◄ próxima';
                }
                return text;
              },
              // Rodapé: só o que a lista acima não diz — status e o quanto falta.
              footer: function (items) {
                if (!items.length) return '';
                var state = milestoneStates[items[0].dataIndex];
                if (!state) return '';
                var out = [state.symbol + ' ' + state.badge];
                if (state.gap > 0) {
                  out.push('Faltam ' + B.formatCurrency(state.gap));
                }
                return out;
              }
            }
          }
        },
        scales: {
          x: B.chartAxisOptions('money', { beginAtZero: true, suggestedMax: chartMax }),
          y: B.chartAxisOptions('label', { grid: { display: false } })
        }
      }
    });

    // ── Faixa de KPIs do time (acima do gráfico) ────────────────────────────
    var statsEl = document.getElementById('vendorTargetsStats');
    if (statsEl) {
      var teamMeta = metas.reduce(function (s, v) { return s + v; }, 0);
      var teamReal = realized.reduce(function (s, v) { return s + v; }, 0);
      var teamAtt  = teamMeta > 0 ? (teamReal / teamMeta) * 100 : 0;
      var onTrack  = attPcts.filter(function (p) { return p >= 100; }).length;
      var chips = [
        { label: 'Meta do período',  value: B.formatCurrency(teamMeta) },
        { label: 'Realizado',        value: B.formatCurrency(teamReal) },
        { label: 'Atingimento',      value: teamAtt.toFixed(1) + '%', tone: teamAtt >= 100 ? 'ok' : (teamAtt >= 85 ? 'warn' : 'bad') },
        { label: 'Na meta',          value: onTrack + '/' + rows.length + ' vendedores', tone: onTrack > 0 ? 'ok' : '' }
      ];
      statsEl.innerHTML = chips.map(function (chip) {
        return '<span class="tier-stats__chip' + (chip.tone ? ' tier-stats__chip--' + chip.tone : '') + '">'
          + '<small>' + chip.label + '</small><strong>' + chip.value + '</strong></span>';
      }).join('');
    }
  };

  B.createMixedDailyChart = function (canvasId, daily) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    window.BIomieCharts.destroyChart(canvasId);

    var rows = daily || [];

    // Seed the full date range from page filters so all days appear even with 0 values
    var range       = B.getDateRange();
    var seededDates = (range.start && range.end) ? B.buildRangeDates(range.start, range.end) : [];

    // If no seeded range, derive from the data itself
    if (!seededDates.length && rows.length) {
      rows.forEach(function (r) { if (r.date) seededDates.push(r.date); });
      seededDates.sort();
    }

    if (!seededDates.length && !rows.length) {
      B._chartEmpty(canvasId, 'Sem movimentacao diaria no periodo.');
      return;
    }

    // Build per-date value Maps — rows with no entry default to 0 (seeded)
    var billedMap    = new Map(seededDates.map(function (d) { return [d, 0]; }));
    var authMap      = new Map(seededDates.map(function (d) { return [d, 0]; }));
    var cancelMap    = new Map(seededDates.map(function (d) { return [d, 0]; }));
    rows.forEach(function (r) {
      if (!r.date) return;
      billedMap.set( r.date, Number(r.billed_value     || 0));
      authMap.set(   r.date, Number(r.authorized_value || 0));
      cancelMap.set( r.date, Number(r.cancelled_value  || 0));
    });

    // For periods > 62 days compress to weekly to keep chart readable
    var dates = seededDates;
    if (dates.length > 62) {
      var weekMap = {};
      dates.forEach(function (d) {
        var wk = d.substring(0, 7); // YYYY-MM → group by month for very long ranges
        if (!weekMap[wk]) weekMap[wk] = { date: wk + '-01', billed: 0, auth: 0, cancel: 0 };
        weekMap[wk].billed += billedMap.get(d) || 0;
        weekMap[wk].auth   += authMap.get(d)   || 0;
        weekMap[wk].cancel += cancelMap.get(d)  || 0;
      });
      var compressed = Object.values(weekMap).sort(function (a, b) { return a.date.localeCompare(b.date); });
      dates       = compressed.map(function (r) { return r.date; });
      billedMap   = new Map(compressed.map(function (r) { return [r.date, r.billed];  }));
      authMap     = new Map(compressed.map(function (r) { return [r.date, r.auth];    }));
      cancelMap   = new Map(compressed.map(function (r) { return [r.date, r.cancel];  }));
    }

    var hasAnyData = dates.some(function (d) {
      return (billedMap.get(d) || 0) + (authMap.get(d) || 0) + (cancelMap.get(d) || 0) > 0;
    });
    if (!hasAnyData) { B._chartEmpty(canvasId, 'Sem movimentacao diaria no periodo.'); return; }

    var theme       = B._chartTheme();
    var billedColor = theme.billed;
    var authColor   = theme.authorized;
    var cancelColor = theme.cancelled;
    var ctx = canvas.getContext('2d');

    var mixedDatasets = [
      { type: 'bar',  label: 'NF',        data: dates.map(function (d) { return billedMap.get(d)  || 0; }), backgroundColor: B.buildGradient(ctx, billedColor, 0.26), borderColor: billedColor, borderWidth: 1, borderRadius: 8, stack: 'status', order: 2 },
      { type: 'bar',  label: 'Carteira',  data: dates.map(function (d) { return authMap.get(d)    || 0; }), backgroundColor: B.buildGradient(ctx, authColor,   0.22), borderColor: authColor,   borderWidth: 1, borderRadius: 8, stack: 'status', order: 2 },
      { type: 'line', label: 'Canceladas',data: dates.map(function (d) { return cancelMap.get(d)  || 0; }), borderColor: cancelColor, backgroundColor: B.hexToRgba(cancelColor, 0.1), pointBackgroundColor: cancelColor, pointRadius: 0, pointHoverRadius: 3, tension: 0.32, fill: false, order: 1 }
    ];
    B._renderChart(canvasId, {
      data: {
        labels: dates.map(function (d) { return B.chartDateLabel(d); }),
        datasets: mixedDatasets
      },
      options: {
        responsive: true, resizeDelay: 100, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + B.formatCurrency(ctx.parsed.y); } } } },
        scales: { x: B.chartAxisOptions('label', { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 15, maxRotation: 30 } }), y: B.chartAxisOptions('money', { stacked: true, beginAtZero: true }) }
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
    // 8 fatias nomeadas = tamanho da paleta categórica validada; o resto dobra
    // em "Outros" (neutro) — nunca gerar/ciclar cores para a 9ª série.
    var topN = 8;
    var top = source.slice(0, topN);
    var topTotal = top.reduce(function (sum, row) { return sum + Number(row[metric] || 0); }, 0);
    var slices = top.map(function (row) {
      return Object.assign({}, row, { _value: Number(row[metric] || 0), _isOther: false });
    });
    if (total > topTotal) {
      slices.push({
        product_code: '',
        description: 'Outros',
        product_name: 'Outros',
        quantity: source.slice(topN).reduce(function (sum, row) { return sum + Number(row.quantity || 0); }, 0),
        revenue: total - topTotal,
        invoices_count: source.slice(topN).reduce(function (sum, row) { return sum + Number(row.invoices_count || row.orders || 0); }, 0),
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
        + '<span class="product-donut-legend__swatch" style="background:' + B.escapeHtml(colors[idx] || '#94a3b8') + '"></span>'
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
    // Paleta categórica validada (mag-chart-defaults) — slot fixo por fatia,
    // "Outros" sempre neutro; sem cores geradas.
    var MD = window.MagChartDefaults || {};
    var catColor = typeof MD.categoricalColor === 'function'
      ? MD.categoricalColor
      : function (i) { return ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#e34948', '#e87ba4', '#eb6834'][i] || '#94a3b8'; };
    var colors = built.slices.map(function (row, idx) { return catColor(idx, !!row._isOther); });

    renderProductLegend(canvasId, built.slices, built.total, metric, colors);

    B._renderChart(canvasId, {
      type: 'doughnut',
      data: {
        labels: built.slices.map(function (row) { return B.shortenLabel(row.description || row.product_name || 'Produto', 24); }),
        datasets: [{
          data: built.slices.map(function (row) { return row._value; }),
          backgroundColor: colors.map(function (color) { return B.hexToRgba(color, 0.86); }),
          borderColor: theme.surface, // gap de 2px na cor da superfície entre fatias
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
    var canvas = document.getElementById(canvasId);
    if (canvas) {
      canvas.setAttribute(
        'aria-label',
        'Ranking com ' + labels.length + ' itens e total ' + B.formatCurrency(total)
          + '. Passe o mouse para valor e participacao.' + (opts.drillChartId ? ' Clique para detalhar.' : '')
      );
    }

    B._renderChart(canvasId, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          data: data,
          backgroundColor: barColor,
          borderColor: barColor,
          borderWidth: 1,
          hoverBorderColor: B._chartTheme().title,
          hoverBorderWidth: 1.5,
          borderRadius: 6,
          barThickness: 'flex',
          maxBarThickness: 22
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        resizeDelay: 120,
        interaction: { mode: 'nearest', axis: 'y', intersect: true },
        onHover: opts.drillChartId ? cfPointerOnData : undefined,
        onClick: opts.drillChartId ? function (_event, elements, chart) {
          if (!elements || !elements.length) return;
          cfOpenOverviewDetail(opts.drillChartId, chart.canvas, {}, opts.drillTitle || 'Detalhamento');
        } : undefined,
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
