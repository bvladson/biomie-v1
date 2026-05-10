(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  function destroyExisting(cv) {
    if (window.Chart && typeof window.Chart.getChart === 'function') {
      const existing = window.Chart.getChart(cv);
      if (existing) existing.destroy();
    }
  }

  function renderAchievementChart(vendors) {
    const cv = document.getElementById('achievementChart');
    if (!cv || !window.Chart) return;
    
    destroyExisting(cv);

    const top = [...(vendors || [])].sort((a, b) => Number(b.realized_period || 0) - Number(a.realized_period || 0)).slice(0, 10);
    if (!top.length) return;

    const labels = top.map(v => (v.vendor_name || '').substring(0, 15));
    const realized = top.map(v => Number(v.realized_period || 0));
    const target = top.map(v => Number(v.target_period || 0));

    const formatters = window.BIomieFlightPlan.Formatters;
    const accent = formatters ? formatters.theme('--page-accent', '#0d6efd') : '#0d6efd';
    const surface3 = formatters ? formatters.theme('--surface-3', '#e9ecef') : '#e9ecef';
    const border = formatters ? formatters.theme('--border', '#eee') : '#eee';
    const fmt = formatters ? formatters.fmt : (v => v);

    new window.Chart(cv, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Realizado',
            data: realized,
            backgroundColor: accent,
            borderRadius: 4,
            barPercentage: 0.6
          },
          {
            label: 'Meta',
            data: target,
            backgroundColor: surface3,
            borderRadius: 4,
            barPercentage: 0.6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: { label: function(ctx) { return fmt(ctx.raw); } }
          }
        },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, grid: { color: border }, ticks: { callback: function(v) { return fmt(v); } } }
        }
      }
    });
  }

  function renderLine(labels, realized, target) {
    const cv = document.getElementById('flightPlanChart');
    if (!cv || !window.Chart) return;
    
    destroyExisting(cv);

    const formatters = window.BIomieFlightPlan.Formatters;
    const accent = formatters ? formatters.theme('--page-accent', '#96140a') : '#96140a';
    const soft = formatters ? formatters.theme('--page-accent-soft', 'rgba(150,20,10,0.12)') : 'rgba(150,20,10,0.12)';
    const text3 = formatters ? formatters.theme('--text-3', '#828282') : '#828282';
    const grid = formatters ? formatters.theme('--chart-grid', 'rgba(0,0,0,0.08)') : 'rgba(0,0,0,0.08)';
    const text2 = formatters ? formatters.theme('--text-2', '#4b5263') : '#4b5263';
    const fmt = formatters ? formatters.fmt : (v => v);

    new window.Chart(cv, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Meta Diaria Media',
            data: target,
            borderColor: text3,
            borderDash: [5, 5],
            borderWidth: 1.5,
            pointRadius: 0,
            fill: false,
            tension: 0
          },
          {
            label: 'Realizado',
            data: realized,
            borderColor: accent,
            backgroundColor: soft,
            borderWidth: 2.5,
            pointBackgroundColor: accent,
            pointBorderColor: '#fff',
            pointRadius: 4,
            pointHoverRadius: 6,
            fill: true,
            tension: 0.3
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + fmt(ctx.raw); } }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: text2, maxTicksLimit: 15 } },
          y: { beginAtZero: true, grid: { color: grid, drawBorder: false }, ticks: { color: text2, callback: function(v) { return fmt(v); } } }
        }
      }
    });
  }

  function renderCharts(p, progress) {
    const state = window.BIomieFlightPlan.State;
    const data = p || (state ? state.getPayload() : null);
    if (!data) return;
    
    const kpis = window.BIomieFlightPlan.Kpis;
    if (kpis) {
      if (typeof window.renderBulletChart !== 'undefined') window.renderBulletChart(progress);
      kpis.renderForecastTriplets(data.team || {});
      kpis.renderRiskRanking(data.vendors || []);
    }
    
    const vendors = state ? state.enrich(state.dedup(data.vendors || []), data) : (data.vendors || []);
    renderAchievementChart(vendors);

    const t = data.team || {};
    const days = Array.isArray(data.daily_sales) ? data.daily_sales : [];
    if (!days.length) {
      const wrap = document.getElementById('flightPlanChartWrap');
      if (wrap) wrap.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:260px;text-align:center;color:var(--text-secondary)"><span class="material-symbols-rounded" style="font-size:40px;margin-bottom:12px;opacity:0.5">query_stats</span><span>Sem historico diario no periodo.</span></div>';
      return;
    }
    
    const labels = days.map(r => { const d = r.date || ''; return d ? d.slice(8, 10) + '/' + d.slice(5, 7) : ''; });
    const realized = days.map(r => Number(r.gross_revenue || 0));
    const avg = (t.target_period && t.workdays?.total_weight) ? Number(t.target_period) / Number(t.workdays.total_weight || 1) : 0;
    
    renderLine(labels, realized, days.map(() => avg));
  }

  window.BIomieFlightPlan.Charts = {
    renderCharts, renderAchievementChart, renderLine
  };
})();
