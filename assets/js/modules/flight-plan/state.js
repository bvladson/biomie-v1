(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  const state = {
    payload: null,
    fullFlightPlanLoaded: false,
    abortCtrl: null
  };

  function setPayload(p) { state.payload = p; }
  function getPayload() { return state.payload; }
  function setLoaded(loaded) { state.fullFlightPlanLoaded = loaded; }
  function isLoaded() { return state.fullFlightPlanLoaded; }
  
  function getAbortSignal() {
    if (state.abortCtrl) state.abortCtrl.abort();
    state.abortCtrl = new AbortController();
    return state.abortCtrl.signal;
  }

  function dedup(vendors) {
    const seen = new Map(), res = [];
    for (const v of (vendors || [])) {
      const id = String(v.vendor_id || v.vendor_key || '').trim();
      const name = String(v.vendor_name || '').trim().toLowerCase();
      const channel = String(v.vendor_channel || 'outro').trim().toLowerCase();
      const k = id ? `id:${id}|channel:${channel}` : `name:${name}|channel:${channel}`;
      if (!name || name === 'sem vendedor') { res.push({ ...v }); continue; }
      if (seen.has(k)) {
        const index = seen.get(k);
        const current = res[index];
        const currentSignal = Number(current.realized_period || 0) + Number(current.pipeline_value || 0) + Number(current.target_period || 0);
        const nextSignal = Number(v.realized_period || 0) + Number(v.pipeline_value || 0) + Number(v.target_period || 0);
        if (nextSignal > currentSignal) res[index] = { ...v };
      } else {
        seen.set(k, res.length);
        res.push({ ...v });
      }
    }
    return res;
  }

  function enrich(vendors, payload) {
    const team = payload.team || {};
    const elapsed = team.workdays?.elapsed_weight ?? 0;
    const total = team.workdays?.total_weight ?? 1;
    const ratio = total > 0 ? elapsed / total : 0;
    return vendors.map(v => ({ ...v, ideal_for_period: Math.round((Number(v.target_period) || 0) * ratio * 100) / 100 }));
  }

  window.BIomieFlightPlan.State = {
    setPayload, getPayload, setLoaded, isLoaded, getAbortSignal, dedup, enrich
  };
})();
