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
      const k = String(v.vendor_name || '').trim().toLowerCase();
      if (!k || k === 'sem vendedor') { res.push({ ...v }); continue; }
      if (seen.has(k)) {
        const e = res[seen.get(k)];
        e.realized_period = (Number(e.realized_period) || 0) + (Number(v.realized_period) || 0);
        e.orders = (Number(e.orders) || 0) + (Number(v.orders) || 0);
        const ei = String(e.vendor_id || ''), ni = String(v.vendor_id || '');
        if (ni && /^\d+$/.test(ni) && (!ei || ni.length < ei.length)) e.vendor_id = v.vendor_id;
        const t = Number(e.target_period) || 0;
        e.attainment_pct = t > 0 ? Math.round((e.realized_period / t) * 10000) / 100 : 0;
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
