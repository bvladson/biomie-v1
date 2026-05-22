(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  async function boot(force = false) {
    var modules = window.BIomieFlightPlan;
    if (!modules.Api || !modules.State || !modules.Dom || !modules.Kpis || !modules.Charts || !modules.Alerts) {
      console.error('[flight-plan] Modulos ausentes. Boot cancelado.');
      return;
    }

    modules.Dom.showLoading(true);
    var payload    = null;
    var fetchError = null;

    try {
      payload = await modules.Api.fetchFlightPlan(force);
    } catch (e) {
      if (e && e.name === 'AbortError') { modules.Dom.showLoading(false); return; }
      fetchError = e;
      console.error('[flight-plan] Erro ao carregar api/flight_plan.php:', e);
    }

    // API error — structured error state, no overview fallback
    if (payload === null || payload.ok === false) {
      var errMsg = (payload && payload.message)
        || (fetchError && fetchError.message)
        || 'Não foi possível carregar o Plano de Voo.';
      var reqId = (payload && (payload.request_id || (payload.diagnostics && payload.diagnostics.request_id))) || '';
      modules.Alerts.renderAlerts([{
        level: 'danger',
        title: 'Erro ao carregar Plano de Voo',
        message: errMsg + (reqId ? ' [req: ' + reqId + ']' : '') +
          ' — Verifique o diagnóstico ou tente novamente.',
      }]);
      try { if (modules.VendorTable) modules.VendorTable.renderVendorTable([]); } catch (e) {}
      modules.Dom.showLoading(false);
      return;
    }

    // No active targets — show empty state
    if (payload.reason_code === 'no_active_targets') {
      var msg = payload.message || 'Nenhuma meta ativa para o período.';
      modules.Alerts.renderAlerts([{
        level: 'info',
        title: 'Sem metas cadastradas',
        message: msg + ' Acesse Plano de Voo Metas para cadastrar.',
      }]);
      try { modules.Kpis.updateMeta(payload, 'flight_plan_v2'); } catch (e) {}
      try { if (modules.VendorTable) modules.VendorTable.renderVendorTable([]); } catch (e) {}
      modules.Dom.showLoading(false);
      return;
    }

    // Normal render path
    modules.State.setPayload(payload);
    var team     = payload.team || payload.summary || {};
    var progress = modules.Formatters.pctOf(team.realized_period, team.target_period);

    try { modules.Kpis.updateMeta(payload, 'Plano de Voo V2'); } catch (e) { console.warn(e); }
    if (typeof window.renderDataQualityBanner !== 'undefined') {
      try { window.renderDataQualityBanner(payload); } catch (e) { console.warn(e); }
    }
    try { modules.Kpis.updateTeam(payload, progress); } catch (e) { console.warn(e); }
    try { modules.Charts.renderCharts(payload, progress); } catch (e) { console.warn(e); }

    var vendors = modules.State.enrich(modules.State.dedup(payload.vendors || []), payload);
    try { modules.Alerts.renderAlerts(payload.alerts || []); } catch (e) {}
    try { if (modules.VendorTable) modules.VendorTable.renderVendorTable(vendors); } catch (e) {}

    modules.State.setLoaded(true);
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
