(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  function bootCompat() {
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
