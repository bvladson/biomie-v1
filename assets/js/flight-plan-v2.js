(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  // Deep links: ?start=YYYY-MM-DD&end=YYYY-MM-DD aplicados aos filtros antes
  // do primeiro boot — links compartilhados abrem no período pedido, não no
  // mês corrente.
  function applyDateDeepLink() {
    try {
      var params = new URLSearchParams(window.location.search);
      var start  = (params.get('start') || '').trim();
      var end    = (params.get('end') || '').trim();
      var isoDay = /^\d{4}-\d{2}-\d{2}$/;
      var startEl = document.getElementById('dateStart');
      var endEl   = document.getElementById('dateEnd');
      if (startEl && isoDay.test(start)) startEl.value = start;
      if (endEl && isoDay.test(end)) endEl.value = end;
    } catch (e) { /* URL malformada não pode impedir o boot */ }
  }

  function bootCompat() {
    applyDateDeepLink();
    if (window.BIomieFlightPlan && typeof window.BIomieFlightPlan.boot === 'function') {
      window.BIomieFlightPlan.boot();
      return;
    }
    console.error('[flight-plan] BIomieFlightPlan.boot indisponivel');
  }

  window.applyFilters = function applyFiltersCompat() {
    if (window.BIomieFlightPlan && typeof window.BIomieFlightPlan.applyFilters === 'function') {
      return window.BIomieFlightPlan.applyFilters();
    }
    if (window.BIomieFlightPlan && typeof window.BIomieFlightPlan.boot === 'function') {
      return window.BIomieFlightPlan.boot();
    }
  };

  document.addEventListener('DOMContentLoaded', bootCompat);
})();
