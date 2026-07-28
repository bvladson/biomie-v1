(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  function destroyExisting(cv) {
    if (window.Chart && typeof window.Chart.getChart === 'function') {
      const existing = window.Chart.getChart(cv);
      if (existing) existing.destroy();
    }
  }

  function _cv(name, fallback) {
    var v = getComputedStyle(document.body || document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }

  // UX.CHARTS.R1 — color by attainment % — reads CSS vars for company theming
  function achievementColor(realizedVal, targetVal) {
    if (!targetVal || targetVal <= 0) return _cv('--chart-neutral',   '#94a3b8');
    const pct = realizedVal / targetVal;
    if (pct >= 1.0)  return _cv('--chart-target',    '#16a34a');
    if (pct >= 0.8)  return _cv('--chart-pending',   '#d97706');
    return _cv('--chart-cancelled', '#dc2626');
  }

  function renderAchievementChart(vendors) {
    const cv = document.getElementById('achievementChart');
    if (!cv || !window.Chart) return;

    destroyExisting(cv);

    const top = [...(vendors || [])].sort((a, b) => {
      const riskDelta = Number(b.risk_sort || 0) - Number(a.risk_sort || 0);
      if (riskDelta !== 0) return riskDelta;
      return Number(b.gap_to_target || 0) - Number(a.gap_to_target || 0);
    }).slice(0, 10);
    if (!top.length) return;

    const labels    = top.map(v => (v.vendor_name || '').substring(0, 15));
    const realized  = top.map(v => Number(v.realized_period || 0));
    const target    = top.map(v => Number(v.target_period || 0));
    const barColors = top.map(v => achievementColor(Number(v.realized_period || 0), Number(v.target_period || 0)));

    const formatters = window.BIomieFlightPlan.Formatters;
    const border = formatters ? formatters.theme('--border', '#eee') : '#eee';
    const fmt    = formatters ? formatters.fmt : (v => v);

    new window.Chart(cv, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Vendas faturadas',
            data: realized,
            backgroundColor: barColors,
            borderRadius: 4,
            barPercentage: 0.55,
            order: 1
          },
          {
            label: 'Meta do período',
            data: target,
            backgroundColor: 'rgba(15,23,42,0.07)',
            borderColor: 'rgba(15,23,42,0.15)',
            borderWidth: 1,
            borderRadius: 4,
            barPercentage: 0.55,
            order: 2
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                var v = ctx.raw;
                var row = top[ctx.dataIndex] || {};
                if (ctx.dataset.label === 'Vendas faturadas' && row.target_period > 0) {
                  var pct = (Number(v) / Number(row.target_period) * 100).toFixed(1);
                  return 'Vendas faturadas: ' + fmt(v) + ' (' + pct + '% da meta)';
                }
                return ctx.dataset.label + ': ' + fmt(v);
              }
            }
          }
        },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, grid: { color: border }, ticks: { callback: function (v) { return fmt(v); } } }
        }
      }
    });

    // UX.CHARTS.R1 — external legend with semantic colors
    window.requestAnimationFrame(function () {
      var container = cv.closest('.chart-card, .mag-chart-card') || cv.parentElement;
      if (!container || !window.BIOMIE_CHART_GOVERNANCE) return;
      var G = window.BIOMIE_CHART_GOVERNANCE;
      // Insight: how many above vs below target
      var aboveMeta  = top.filter(function (v) { return Number(v.realized_period) >= Number(v.target_period) && Number(v.target_period) > 0; }).length;
      var belowWarn  = top.filter(function (v) { return Number(v.target_period) > 0 && Number(v.realized_period) / Number(v.target_period) < 0.8; }).length;
      var noTarget = top.filter(function (v) { return Number(v.target_period) <= 0; }).length;
      var text = noTarget > 0 ? noTarget + ' vendedor(es) com meta pendente. ' : '';
      text += aboveMeta + ' vendedor(es) acima da meta.';
      if (belowWarn > 0) text += ' ' + belowWarn + ' abaixo de 80%.';
      var variant = noTarget > 0 || belowWarn > 0 ? 'warning' : (aboveMeta === top.length ? 'success' : 'neutral');
      var cSuccess  = formatters ? formatters.theme('--chart-target',    '#16a34a') : '#16a34a';
      var cWarn     = formatters ? formatters.theme('--chart-pending',   '#d97706') : '#d97706';
      var cDanger   = formatters ? formatters.theme('--chart-cancelled', '#dc2626') : '#dc2626';
      var cNeutral  = formatters ? formatters.theme('--chart-neutral',   '#94a3b8') : '#94a3b8';
      var legendDs = [
        { label: '≥ 100% (meta atingida)', borderColor: cSuccess, backgroundColor: cSuccess, type: 'bar' },
        { label: '80-99% (atenção)',        borderColor: cWarn,    backgroundColor: cWarn,    type: 'bar' },
        { label: '< 80% (abaixo)',          borderColor: cDanger,  backgroundColor: cDanger,  type: 'bar' },
        { label: 'Meta pendente',            borderColor: cNeutral, backgroundColor: cNeutral, type: 'bar' },
        { label: 'Meta do período',         borderColor: 'rgba(15,23,42,0.15)', backgroundColor: 'rgba(15,23,42,0.07)', type: 'bar' },
      ];
      G.ensureLegend(container, legendDs);
      G.renderInsight(container, { text: text, variant: variant });
    });
  }

  // UX.CHARTS.R1 — render cumulative realized vs cumulative target
  function renderLine(labels, realized, target) {
    const cv = document.getElementById('flightPlanChart');
    if (!cv || !window.Chart) return;

    destroyExisting(cv);

    const formatters = window.BIomieFlightPlan.Formatters;
    const accent = formatters ? formatters.theme('--page-accent', '#96140a') : '#96140a';
    const soft   = formatters ? formatters.theme('--page-accent-soft', 'rgba(150,20,10,0.12)') : 'rgba(150,20,10,0.12)';
    const text3  = formatters ? formatters.theme('--text-3', '#828282') : '#828282';
    const grid   = formatters ? formatters.theme('--chart-grid', 'rgba(0,0,0,0.08)') : 'rgba(0,0,0,0.08)';
    const text2  = formatters ? formatters.theme('--text-2', '#4b5263') : '#4b5263';
    const fmt    = formatters ? formatters.fmt : (v => v);

    // Build cumulative series
    var cumRealized = [];
    var cumTarget   = [];
    var rSum = 0; var tSum = 0;
    for (var i = 0; i < realized.length; i++) {
      rSum += Number(realized[i] || 0);
      tSum += Number(target[i]   || 0);
      cumRealized.push(rSum);
      cumTarget.push(tSum);
    }

    var lineDatasets = [
      {
        label: 'Meta até o dia',
        data: cumTarget,
        borderColor: text3,
        borderDash: [5, 5],
        borderWidth: 1.5,
        pointRadius: 0,
        fill: false,
        tension: 0
      },
      {
        label: 'Vendas até o dia',
        data: cumRealized,
        borderColor: accent,
        backgroundColor: soft,
        borderWidth: 2.5,
        pointBackgroundColor: accent,
        pointBorderColor: '#fff',
        pointRadius: 0,
        pointHoverRadius: 5,
        fill: true,
        tension: 0.3
      }
    ];

    new window.Chart(cv, {
      type: 'line',
      data: { labels: labels, datasets: lineDatasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                var v = ctx.raw;
                var pairIdx = ctx.dataIndex;
                if (ctx.dataset.label === 'Vendas até o dia' && cumTarget[pairIdx] > 0) {
                  var pct = (v / cumTarget[pairIdx] * 100).toFixed(1);
                  return 'Vendas até o dia: ' + fmt(v) + ' (' + pct + '% da meta)';
                }
                return ctx.dataset.label + ': ' + fmt(v);
              }
            }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: text2, maxTicksLimit: 15 } },
          y: { beginAtZero: true, grid: { color: grid, drawBorder: false }, ticks: { color: text2, callback: function (v) { return fmt(v); } } }
        }
      }
    });

    // UX.CHARTS.R1 — external legend + trend insight
    window.requestAnimationFrame(function () {
      var container = cv.closest('.chart-card, .mag-chart-card') || cv.parentElement;
      if (!container || !window.BIOMIE_CHART_GOVERNANCE) return;
      var G = window.BIOMIE_CHART_GOVERNANCE;
      G.ensureLegend(container, lineDatasets);
      var lastR = cumRealized[cumRealized.length - 1] || 0;
      var lastT = cumTarget[cumTarget.length - 1] || 0;
      if (lastT <= 0) {
        G.renderInsight(container, {
          text: 'Vendas faturadas no período: ' + G.formatCurrencyShort(lastR) + '. Defina as metas para acompanhar o ritmo.',
          variant: 'warning'
        });
        return;
      }
      var attPct = Math.round(lastR / lastT * 100);
      var text, variant;
      if (attPct >= 100) {
        text = 'Dentro da rota: realizado ' + attPct + '% da meta acumulada.';
        variant = 'success';
      } else if (attPct >= 80) {
        text = 'Atenção: realizado ' + attPct + '% da meta acumulada.';
        variant = 'warning';
      } else {
        text = 'Abaixo da rota: ' + attPct + '% da meta acumulada. Faltam ' + G.formatCurrencyShort(Math.max(0, lastT - lastR)) + '.';
        variant = 'danger';
      }
      G.renderInsight(container, { text: text, variant: variant });
    });
  }

  function renderCharts(p, progress) {
    const state = window.BIomieFlightPlan.State;
    const data  = p || (state ? state.getPayload() : null);
    if (!data) return;

    const kpis = window.BIomieFlightPlan.Kpis;
    if (kpis) {
      if (typeof window.renderBulletChart !== 'undefined') window.renderBulletChart(progress);
      kpis.renderForecastTriplets(data.team || {});
      kpis.renderRiskRanking(data.vendors || []);
    }

    const vendors = state ? state.enrich(state.dedup(data.vendors || []), data) : (data.vendors || []);
    renderAchievementChart(vendors);

    const t    = data.team || {};
    const days = Array.isArray(data.daily_sales) ? data.daily_sales : [];
    if (!days.length) {
      const wrap = document.getElementById('flightPlanChartWrap');
      if (wrap) wrap.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:260px;text-align:center;color:var(--text-secondary)"><span class="material-symbols-rounded" style="font-size:40px;margin-bottom:12px;opacity:0.5">query_stats</span><span>Sem histórico diário no período.</span></div>';
      return;
    }

    const labels = days.map(r => { const d = r.date || ''; return d ? d.slice(8, 10) + '/' + d.slice(5, 7) : ''; });
    // Daily realized values (cumulation done inside renderLine)
    const realizedDaily = days.map(r => Number(r.gross_revenue || 0));
    // Daily target follows the same weighted-workday rule used by the backend.
    const periodTarget = Number(t.target_period || 0);
    const totalWeight  = days.reduce((sum, row) => sum + Number(row.workday_weight || 0), 0);
    const targetByWeight = periodTarget > 0 && totalWeight > 0 ? periodTarget / totalWeight : 0;
    const targetDaily  = days.map(row => targetByWeight * Number(row.workday_weight || 0));

    renderLine(labels, realizedDaily, targetDaily);
  }

  window.BIomieFlightPlan.Charts = {
    renderCharts, renderAchievementChart, renderLine
  };
})();
