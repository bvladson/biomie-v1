(function () {
  'use strict';

  var selector = '.biomie-kpi-card, .chart-card, .table-card, .fin-panel, .mag-card, .omie-card, .dashboard-panel';
  var current = document.currentScript || document.querySelector('script[src*="commercial-card-help-loader"]');
  var featureSrc = current && current.dataset.featureSrc
    ? current.dataset.featureSrc
    : 'assets/js/commercial-card-help.js';
  var loading = false;

  function load() {
    if (loading || window.BIomieCardHelp) return;
    loading = true;
    removeInteractionListeners();

    var script = document.createElement('script');
    script.src = featureSrc;
    script.defer = true;
    script.dataset.lazyFeature = 'commercial-card-help';
    script.addEventListener('error', function () { loading = false; });
    document.head.appendChild(script);
  }

  function requestFromInteraction(event) {
    var target = event.target && event.target.closest ? event.target.closest(selector) : null;
    if (target) load();
  }

  function removeInteractionListeners() {
    document.removeEventListener('pointerover', requestFromInteraction, true);
    document.removeEventListener('focusin', requestFromInteraction, true);
  }

  function scheduleIdleLoad() {
    if (!document.querySelector(selector)) return;
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(load, { timeout: 3000 });
    } else {
      window.setTimeout(load, 1800);
    }
  }

  window.BIomieLoadCardHelp = load;
  document.addEventListener('pointerover', requestFromInteraction, { passive: true, capture: true });
  document.addEventListener('focusin', requestFromInteraction, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleIdleLoad, { once: true });
  } else {
    scheduleIdleLoad();
  }
})();
