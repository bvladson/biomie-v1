(function () {
  'use strict';

  var current = document.currentScript;
  var manifest = {};
  var loaded = new Map();
  var featurePromises = new Map();

  try {
    manifest = JSON.parse(current && current.dataset.financeAssets || '{}');
  } catch (error) {
    manifest = {};
  }

  function loadScript(src) {
    if (!src) return Promise.resolve();
    if (loaded.has(src)) return loaded.get(src);
    var existing = Array.from(document.scripts).find(function (script) {
      return script.src === new URL(src, window.location.href).href;
    });
    if (existing) {
      var ready = Promise.resolve();
      loaded.set(src, ready);
      return ready;
    }
    var promise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = src;
      script.async = false;
      script.onload = resolve;
      script.onerror = function () { reject(new Error('Falha ao carregar recurso financeiro: ' + src)); };
      document.head.appendChild(script);
    });
    loaded.set(src, promise);
    return promise;
  }

  function ensureFeature(feature) {
    if (featurePromises.has(feature)) return featurePromises.get(feature);
    var paths = Array.isArray(manifest[feature]) ? manifest[feature] : [];
    var promise = paths.reduce(function (chain, src) {
      return chain.then(function () { return loadScript(src); });
    }, Promise.resolve()).then(function () {
      document.dispatchEvent(new CustomEvent('biomie:finance-feature-ready', { detail: { feature: feature } }));
      return feature;
    });
    featurePromises.set(feature, promise);
    return promise;
  }

  function featureForView(view) {
    if (view === 'collection') return 'collection';
    if (view === 'receivables') return 'receivables';
    if (view === 'explore') return 'charts';
    return 'charts';
  }

  function bindNavigation() {
    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-finance-view], [data-finance-jump]');
      if (!trigger) return;
      var view = trigger.getAttribute('data-finance-view') || trigger.getAttribute('data-finance-jump') || 'summary';
      var primary = featureForView(view);
      ensureFeature(primary);
      if (view === 'explore') {
        ensureFeature('receivables');
        ensureFeature('collection');
      }
    }, true);
  }

  function observeSections() {
    if (!('IntersectionObserver' in window)) return;
    var targets = [
      ['charts', document.getElementById('financeVisualAnalytics')],
      ['receivables', document.getElementById('financeReceivableTrace')],
      ['collection', document.getElementById('financeCollectionSection')]
    ];
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var feature = entry.target.dataset.financeLazyFeature;
        if (feature) ensureFeature(feature);
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '280px 0px' });
    targets.forEach(function (pair) {
      if (!pair[1]) return;
      pair[1].dataset.financeLazyFeature = pair[0];
      observer.observe(pair[1]);
    });
  }

  function init() {
    bindNavigation();
    observeSections();
    window.setTimeout(function () { ensureFeature('charts'); }, 900);
    var idle = window.requestIdleCallback || function (callback) { return window.setTimeout(callback, 1800); };
    idle(function () { ensureFeature('background'); });
  }

  window.BIomieFinanceFeatures = { ensure: ensureFeature };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
