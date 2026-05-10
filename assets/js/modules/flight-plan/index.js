(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  async function boot(force = false) {
    const modules = window.BIomieFlightPlan;
    if (!modules.Api || !modules.State || !modules.Dom || !modules.Kpis || !modules.VendorTable || !modules.Charts || !modules.Alerts) {
      console.error('[flight-plan] Modulos ausentes. Boot cancelado.');
      return;
    }

    modules.Dom.showLoading(true);
    let payload = null;

    try {
      payload = await modules.Api.fetchFlightPlan(force);
    } catch (e) {
      console.warn('flight_plan.php indisponivel, tentando fallback.', e);
    }

    if (payload !== null) {
      modules.State.setPayload(payload);
      const team = payload.team || {};
      const progress = modules.Formatters.pctOf(team.realized_period, team.target_period);
      
      try { modules.Kpis.updateMeta(payload, 'Plano modular'); } catch (e) { console.warn(e); }
      if (typeof window.renderDataQualityBanner !== 'undefined') {
        try { window.renderDataQualityBanner(payload); } catch (e) { console.warn(e); }
      }
      try { modules.Kpis.updateTeam(payload, progress); } catch (e) { console.warn(e); }
      try { modules.Charts.renderCharts(payload, progress); } catch (e) { console.warn(e); }
      
      const vendors = modules.State.enrich(modules.State.dedup(payload.vendors || []), payload);
      try { modules.Alerts.renderAlerts(payload.alerts || []); } catch (e) {}
      try { modules.VendorTable.renderVendorTable(vendors); } catch (e) {}
      
      modules.State.setLoaded(true);
      modules.Dom.showLoading(false);
      return;
    }

    // Fallback overview
    let fallbackData = null;
    try { fallbackData = await modules.Api.fetchFallback(force); } catch (e) { console.error(e); }
    
    if (fallbackData !== null) {
      const s = fallbackData.summary || {}, r = Number(s.gross_revenue ?? s.revenue ?? 0), m = Number(s.target ?? 0);
      const fc = Number(s.forecast ?? r), prog = modules.Formatters.pctOf(r, m), rem = Math.max(m - r, 0);
      
      try { modules.Kpis.updateMeta(fallbackData, 'Fallback overview'); } catch (e) {}
      modules.Dom.setText('[data-bind="fp-realizado"]', modules.Formatters.fmt(r));
      modules.Dom.setText('[data-bind="fp-meta"]', modules.Formatters.fmt(m));
      modules.Dom.setText('[data-bind="fp-atingimento"]', `${modules.Formatters.fmtN(prog, 1)}%`);
      modules.Dom.setText('[data-bind="fp-projecao"]', modules.Formatters.fmt(fc));
      modules.Dom.setText('[data-bind="fp-nova-meta-dia"]', modules.Formatters.fmt(rem));
      modules.Dom.setText('[data-bind="fp-gap-meta"]', modules.Formatters.fmt(rem));
      modules.Dom.setText('[data-bind="fp-sem-meta"]', '-');
      
      const pf = document.getElementById('flightProgressFill');
      if (pf) pf.style.width = `${Math.min(100, prog)}%`;
      
      try { modules.Kpis.renderRiskBadge('SEM_META'); } catch (e) {}
      try {
        if (typeof window.renderBulletChart !== 'undefined') window.renderBulletChart(prog);
        modules.Kpis.renderForecastTriplets({});
        modules.Kpis.renderRiskRanking([]);
        const daily = Array.isArray(fallbackData.daily_sales) ? fallbackData.daily_sales : [];
        const labels = daily.map(d => (d.date || '').slice(8, 10) + '/' + (d.date || '').slice(5, 7));
        const realized = daily.map(d => Number(d.gross_revenue || d.revenue || 0));
        const avg = daily.map(() => (m > 0 && daily.length ? m / daily.length : 0));
        modules.Charts.renderLine(labels, realized, avg);
      } catch (e) {}
    }

    modules.Alerts.renderAlerts(fallbackData !== null
      ? [{ level: 'info', title: 'Fallback legado', message: 'O endpoint de Plano de Voo nao respondeu. Exibindo dados do overview.' }]
      : [{ level: 'danger', title: 'Sem conexao', message: 'Nao foi possivel carregar os dados.' }]);
    
    modules.VendorTable.renderVendorTable([]);
    modules.Dom.showLoading(false);
  }

  function applyFilters() {
    return boot(false);
  }

  function bindEvents() {
    const btnSync = document.getElementById('syncNow');
    const btnApply = document.getElementById('applyFilters');
    
    if (btnSync) btnSync.addEventListener('click', () => boot(true));
    if (btnApply) btnApply.addEventListener('click', () => applyFilters());

    document.querySelectorAll('.btn-preset').forEach(btn => {
      btn.addEventListener('click', () => {
        const type = btn.getAttribute('data-preset');
        if (window.BIomieFlightPlan.Filters && type) {
          window.BIomieFlightPlan.Filters.preset(type);
          applyFilters();
        }
      });
    });
  }

  // Bind early DOM
  document.addEventListener('DOMContentLoaded', bindEvents);

  window.BIomieFlightPlan.boot = boot;
  window.BIomieFlightPlan.applyFilters = applyFilters;
})();
