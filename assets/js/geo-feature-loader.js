(function () {
  'use strict';

  var current = document.currentScript;
  var manifest = {};
  try {
    manifest = JSON.parse((current && current.dataset.geoAssets) || '{}');
  } catch (_) {
    manifest = {};
  }

  var promises = {};

  function loadScript(src) {
    if (!src) return Promise.resolve();
    var existing = document.querySelector('script[data-geo-lazy-src="' + src + '"]');
    if (existing) {
      return existing.dataset.loaded === '1'
        ? Promise.resolve()
        : new Promise(function (resolve, reject) {
            existing.addEventListener('load', resolve, { once: true });
            existing.addEventListener('error', reject, { once: true });
          });
    }

    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = src;
      script.defer = true;
      script.dataset.geoLazySrc = src;
      script.addEventListener('load', function () {
        script.dataset.loaded = '1';
        resolve();
      }, { once: true });
      script.addEventListener('error', function () {
        reject(new Error('Falha ao carregar recurso geografico: ' + src));
      }, { once: true });
      document.head.appendChild(script);
    });
  }

  function loadGroup(name) {
    if (promises[name]) return promises[name];
    var assets = Array.isArray(manifest[name]) ? manifest[name] : [];
    promises[name] = assets.reduce(function (chain, src) {
      return chain.then(function () { return loadScript(src); });
    }, Promise.resolve()).catch(function (error) {
      console.error('[BIomie Geo]', error);
      var state = document.getElementById('geoMapState');
      if (state) {
        state.innerHTML = '<div class="module-alert module-alert--warning">Nao foi possivel carregar o mapa. Recarregue a pagina.</div>';
      }
      throw error;
    });
    return promises[name];
  }

  function scheduleBackground() {
    var run = function () { loadGroup('background').catch(function () {}); };
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(run, { timeout: 5000 });
    } else {
      window.setTimeout(run, 3500);
    }
  }

  function startCore() {
    loadGroup('core').catch(function () {});
  }

  var map = document.getElementById('map-container');
  if ('IntersectionObserver' in window && map) {
    var observer = new IntersectionObserver(function (entries) {
      if (entries.some(function (entry) { return entry.isIntersecting; })) {
        observer.disconnect();
        startCore();
      }
    }, { rootMargin: '600px 0px' });
    observer.observe(map);
  }

  document.addEventListener('pointerdown', function (event) {
    if (event.target.closest('.geo-commercial-controls, .geo-main-grid, [data-geo-action]')) {
      startCore();
    }
  }, { once: true, passive: true });

  document.addEventListener('focusin', function (event) {
    if (event.target.closest('.geo-commercial-controls, .geo-main-grid')) {
      startCore();
    }
  }, { once: true });

  window.setTimeout(startCore, 1800);
  window.addEventListener('load', scheduleBackground, { once: true });
  window.BIomieGeoFeatures = { load: startCore };
})();
