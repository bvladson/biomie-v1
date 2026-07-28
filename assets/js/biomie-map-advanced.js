(function () {
  'use strict';

  const assets = {
    heat: {
      ready: () => typeof window.L?.heatLayer === 'function',
      scripts: ['https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js'],
      styles: [],
    },
    draw: {
      ready: () => typeof window.L?.Control?.Draw === 'function',
      scripts: ['https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js'],
      styles: ['https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css'],
    },
  };

  const pending = new Map();

  function loadStyle(url) {
    if (document.querySelector(`link[data-geo-advanced="${url}"]`)) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = url;
      link.dataset.geoAdvanced = url;
      link.onload = resolve;
      link.onerror = () => reject(new Error(`Falha ao carregar recurso visual: ${url}`));
      document.head.appendChild(link);
    });
  }

  function loadScript(url) {
    if (document.querySelector(`script[data-geo-advanced="${url}"]`)) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = url;
      script.crossOrigin = 'anonymous';
      script.dataset.geoAdvanced = url;
      script.onload = resolve;
      script.onerror = () => reject(new Error(`Falha ao carregar recurso visual: ${url}`));
      document.head.appendChild(script);
    });
  }

  function load(name) {
    const asset = assets[name];
    if (!asset) return Promise.reject(new Error(`Recurso geográfico desconhecido: ${name}`));
    if (asset.ready()) return Promise.resolve(true);
    if (pending.has(name)) return pending.get(name);

    const request = Promise.all([
      ...asset.styles.map(loadStyle),
      ...asset.scripts.map(loadScript),
    ]).then(() => {
      if (!asset.ready()) throw new Error(`O recurso ${name} foi carregado, mas não iniciou corretamente.`);
      return true;
    }).finally(() => pending.delete(name));

    pending.set(name, request);
    return request;
  }

  window.BIomieGeoAdvancedAssets = {
    loadHeat: () => load('heat'),
    loadDraw: () => load('draw'),
  };
})();
