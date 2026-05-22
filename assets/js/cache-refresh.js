(function () {
  'use strict';

  // ── 1. Expor clearClientCacheAndReload no namespace BIomie ────────────────
  window.BIomie = window.BIomie || {};

  window.BIomie.clearClientCacheAndReload = async function () {
    try { localStorage.clear(); }       catch (_) {}
    try { sessionStorage.clear(); }     catch (_) {}
    try {
      var keys = await caches.keys();
      await Promise.all(keys.map(function (k) { return caches.delete(k); }));
    } catch (_) {}
    try {
      var regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(regs.map(function (r) { return r.unregister(); }));
    } catch (_) {}
    location.reload(true);
  };

  // ── 2. Detectar build diferente e recarregar uma vez ─────────────────────
  var meta  = document.querySelector('meta[name="biomie-build"]');
  var build = meta ? meta.getAttribute('content') : '';
  if (!build) return;

  try {
    var previous      = localStorage.getItem('biomie.build.version');
    var refreshDone   = sessionStorage.getItem('biomie.cache.refresh.done');
    var freshParam    = (window.location.search || '').indexOf('fresh=1') !== -1;

    if (previous && previous !== build && !refreshDone && !freshParam) {
      // Marcar antes do reload para evitar loop infinito.
      sessionStorage.setItem('biomie.cache.refresh.done', '1');

      // Limpar caches do Service Worker e forçar update.
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function (regs) {
          regs.forEach(function (reg) {
            if (reg.active) reg.active.postMessage({ type: 'CLEAR_BIOMIE_CACHES' });
            if (reg.update) reg.update();
          });
        }).catch(function () {});
      }

      // Recarregar com ?fresh=1 para sinalizar reload controlado.
      var sep = window.location.search ? '&' : '?';
      localStorage.setItem('biomie.build.version', build);
      window.location.replace(window.location.href + sep + 'fresh=1');
      return;
    }

    // Build OK ou já recarregamos — gravar versão atual.
    localStorage.setItem('biomie.build.version', build);
  } catch (_) {}

  // ── 3. Manter Service Worker atualizado a cada carregamento ──────────────
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistration().then(function (reg) {
      if (reg && reg.update) reg.update();
    }).catch(function () {});
  }
})();
