(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  async function fetchFlightPlan(force) {
    const filters = window.BIomieFlightPlan.Filters;
    const url = filters ? filters.apiUrl('api/flight_plan.php', force) : 'api/flight_plan.php';
    const signal = window.BIomieFlightPlan.State ? window.BIomieFlightPlan.State.getAbortSignal() : undefined;
    
    try {
      const res = await fetch(url, { signal });
      if (!res.ok) {
        let msg = `HTTP ${res.status}`;
        try {
          const body = await res.json();
          if (body.error) msg = body.error;
        } catch(e) {}
        throw new Error(`Erro na API (${msg})`);
      }
      const data = await res.json();
      return data;
    } catch (err) {
      if (err.name === 'AbortError') throw err;
      throw err;
    }
  }

  async function fetchFallback(force) {
    const filters = window.BIomieFlightPlan.Filters;
    const url = filters ? filters.apiUrl('api/overview.php', force) : 'api/overview.php';
    const signal = window.BIomieFlightPlan.State ? window.BIomieFlightPlan.State.getAbortSignal() : undefined;
    
    const res = await fetch(url, { signal });
    if (!res.ok) throw new Error(`Fallback HTTP ${res.status}`);
    return await res.json();
  }

  window.BIomieFlightPlan.Api = {
    fetchFlightPlan, fetchFallback
  };
})();
