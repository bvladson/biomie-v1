(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  function setDates(s, e) {
    const start = document.getElementById('dateStart');
    const end = document.getElementById('dateEnd');
    if (start && s) start.value = s;
    if (end && e) end.value = e;
  }

  function getDates() {
    const start = document.getElementById('dateStart');
    const end = document.getElementById('dateEnd');
    const formatters = window.BIomieFlightPlan.Formatters;
    const toIso = formatters ? formatters.toLocalIso : (d => new Date(d).toISOString().slice(0,10));
    
    let s = start && start.value ? start.value : '';
    let e = end && end.value ? end.value : '';
    if (!s || !e) {
      const today = new Date();
      const first = new Date(today.getFullYear(), today.getMonth(), 1);
      s = toIso(first);
      e = toIso(today);
      setDates(s, e);
    }
    return { start: s, end: e };
  }

  function preset(type) {
    const formatters = window.BIomieFlightPlan.Formatters;
    const toIso = formatters ? formatters.toLocalIso : (d => new Date(d).toISOString().slice(0,10));
    const today = new Date();
    if (type === 'month') {
      setDates(toIso(new Date(today.getFullYear(), today.getMonth(), 1)), toIso(today));
    } else if (type === 'year') {
      setDates(toIso(new Date(today.getFullYear(), 0, 1)), toIso(today));
    } else if (type === 'rolling12') {
      setDates(toIso(new Date(today.getFullYear() - 1, today.getMonth(), today.getDate())), toIso(today));
    }
  }

  function apiUrl(base, force) {
    const d = getDates();
    let url = `${base}?start=${d.start}&end=${d.end}`;
    if (force) url += '&force_refresh=1';
    return url;
  }

  window.BIomieFlightPlan.Filters = {
    setDates, getDates, preset, apiUrl
  };
})();
