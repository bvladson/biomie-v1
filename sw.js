const VERSION = (() => {
  try {
    return new URL(self.location.href).searchParams.get('v') || '20260521-fase-o-fiscal-basis';
  } catch (_) {
    return '20260521-fase-o-fiscal-basis';
  }
})();
const STATIC_CACHE = `biomie-static-${VERSION}`;

const scopeUrl = new URL(self.registration.scope);
const scopePath = scopeUrl.pathname.endsWith('/') ? scopeUrl.pathname.slice(0, -1) : scopeUrl.pathname;
const appPath = (path) => `${scopePath}${path.startsWith('/') ? path : `/${path}`}`;

const PRECACHE_URLS = [
  appPath('/assets/css/theme-tokens.css'),
  appPath('/assets/css/mag-design-system.css'),
  appPath('/assets/css/shell-index.css'),
  appPath('/assets/css/module-pages.css'),
  appPath('/assets/js/pwa.js'),
  appPath('/assets/js/cache-refresh.js'),
  appPath('/images/favicon.svg'),
  appPath('/images/favicon.ico'),
  appPath('/images/pwa/icon-192.png'),
  appPath('/images/pwa/icon-512.png')
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(PRECACHE_URLS.map((url) => new Request(url, { cache: 'reload' }))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key.startsWith('biomie-') && key !== STATIC_CACHE).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
  if (event.data && event.data.type === 'CLEAR_BIOMIE_CACHES') {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('biomie-')).map((key) => caches.delete(key)))));
  }
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Nunca cachear HTML autenticado, rotas PHP ou APIs dinâmicas.
  if (request.mode === 'navigate' || url.pathname.endsWith('.php') || url.pathname.startsWith(appPath('/api/'))) {
    event.respondWith(fetch(request, { cache: 'no-store', credentials: 'same-origin' }));
    return;
  }

  if (isVersionedStaticAsset(url)) {
    event.respondWith(cacheFirst(request));
  }
});

function isVersionedStaticAsset(url) {
  return url.pathname.startsWith(`${scopePath}/assets/`)
    && /\.(?:css|js|png|svg|ico|jpg|jpeg|webp|woff2?)$/i.test(url.pathname)
    && url.searchParams.has('v');
}

async function cacheFirst(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cached = await cache.match(request, { ignoreSearch: false });
  if (cached) return cached;
  const response = await fetch(request);
  if (response.ok) await cache.put(request, response.clone());
  return response;
}
