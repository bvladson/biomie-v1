(function () {
  const manifestLink = document.querySelector('link[rel="manifest"]');
  const appBaseUrl = manifestLink ? new URL('.', manifestLink.href) : new URL('./', window.location.href);
  const scopePath = appBaseUrl.pathname.endsWith('/') ? appBaseUrl.pathname : `${appBaseUrl.pathname}/`;
  const serviceWorkerUrl = new URL('sw.js', appBaseUrl).toString();
  const visitsKey = 'biomie:pwa:visits';
  const persistKey = 'biomie:pwa:persist';

  let deferredInstallPrompt = null;
  let installButton = null;

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  }

  function setBodyFlags() {
    document.body.toggleAttribute('data-offline', !navigator.onLine);
    document.body.toggleAttribute('data-standalone', isStandalone());
  }

  function ensureInstallButton() {
    if (isStandalone()) return;

    // Botão mobile pré-existente no mobile_topbar.php
    const mobileBtn = document.getElementById('mobileInstallButton');
    if (mobileBtn && !mobileBtn.__pwaWired) {
      mobileBtn.__pwaWired = true;
      mobileBtn.addEventListener('click', promptInstall);
    }

    if (installButton) return;

    // Botão desktop no .topbar-actions
    const host = document.querySelector('.topbar-actions');
    if (!host) return;

    installButton = document.createElement('button');
    installButton.type = 'button';
    installButton.className = 'btn-secondary';
    installButton.id = 'pwaInstallButton';
    installButton.hidden = true;
    installButton.innerHTML = '<span class="material-symbols-rounded">download</span>Instalar app';
    installButton.addEventListener('click', promptInstall);
    host.prepend(installButton);
  }

  function updateInstallButton() {
    const shouldHide = deferredInstallPrompt === null || isStandalone();
    if (installButton) installButton.hidden = shouldHide;
    // Sincroniza botão mobile
    const mobileBtn = document.getElementById('mobileInstallButton');
    if (mobileBtn) mobileBtn.hidden = shouldHide;
  }

  async function promptInstall() {
    if (!deferredInstallPrompt) {
      return;
    }

    deferredInstallPrompt.prompt();
    const choice = await deferredInstallPrompt.userChoice.catch(() => null);
    deferredInstallPrompt = null;
    updateInstallButton();

    if (choice && choice.outcome === 'accepted') {
      void maybeRequestPersistentStorage('installed');
    }
  }

  async function maybeRequestPersistentStorage(reason) {
    if (!('storage' in navigator) || !navigator.storage || !navigator.storage.persisted || !navigator.storage.persist) {
      return;
    }

    const visits = Number(window.localStorage.getItem(visitsKey) || '0');
    if (!isStandalone() && visits < 3 && reason !== 'installed') {
      return;
    }

    const alreadyPersisted = await navigator.storage.persisted().catch(() => false);
    if (alreadyPersisted) {
      document.body.dataset.storagePersistence = 'granted';
      return;
    }

    const previousAttempt = window.localStorage.getItem(persistKey);
    if (previousAttempt && reason !== 'installed') {
      return;
    }

    const granted = await navigator.storage.persist().catch(() => false);
    document.body.dataset.storagePersistence = granted ? 'granted' : 'denied';
    window.localStorage.setItem(persistKey, JSON.stringify({
      granted,
      reason,
      at: new Date().toISOString()
    }));
  }

  async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      return;
    }

    if (!window.isSecureContext && !['localhost', '127.0.0.1'].includes(window.location.hostname)) {
      return;
    }

    try {
      const buildMeta = document.querySelector('meta[name="biomie-build"]');
      const buildVersion = buildMeta ? buildMeta.content.trim() : '';
      const swUrl = buildVersion ? serviceWorkerUrl + '?v=' + encodeURIComponent(buildVersion) : serviceWorkerUrl;
      const registration = await navigator.serviceWorker.register(swUrl, { scope: scopePath });
      if (registration.waiting) {
        registration.waiting.postMessage({ type: 'SKIP_WAITING' });
      }
    } catch (error) {
      console.warn('BIomie PWA: falha ao registrar service worker.', error);
    }
  }

  function bumpVisits() {
    const current = Number(window.localStorage.getItem(visitsKey) || '0');
    window.localStorage.setItem(visitsKey, String(current + 1));
  }

  function bindLifecycle() {
    window.addEventListener('beforeinstallprompt', (event) => {
      event.preventDefault();
      deferredInstallPrompt = event;
      ensureInstallButton();
      updateInstallButton();
    });

    window.addEventListener('appinstalled', () => {
      deferredInstallPrompt = null;
      updateInstallButton();
      setBodyFlags();
      void maybeRequestPersistentStorage('installed');
    });

    window.addEventListener('online', setBodyFlags);
    window.addEventListener('offline', setBodyFlags);
  }

  bindLifecycle();
  document.addEventListener('DOMContentLoaded', () => {
    bumpVisits();
    ensureInstallButton();
    updateInstallButton();
    setBodyFlags();
    void maybeRequestPersistentStorage('warmup');
  });
  void registerServiceWorker();
})();
