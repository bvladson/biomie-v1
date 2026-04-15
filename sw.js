const VERSION = '20260415-sk5';
const STATIC_CACHE = `biomie-static-${VERSION}`;
const PAGE_CACHE = `biomie-pages-${VERSION}`;
const DATA_CACHE = `biomie-data-${VERSION}`;

const scopeUrl = new URL(self.registration.scope);
const scopePath = scopeUrl.pathname.endsWith('/') ? scopeUrl.pathname.slice(0, -1) : scopeUrl.pathname;
const appPath = (path) => `${scopePath}${path.startsWith('/') ? path : `/${path}`}`;

const OFFLINE_URL = appPath('/offline.html');
const PAGE_ALLOWLIST = new Set([
  appPath('/index.php'),
  appPath('/vendedores.php'),
  appPath('/pedidos.php'),
  appPath('/mercadorias.php'),
  appPath('/financeiro.php'),
  appPath('/plano_voo.php'),
  appPath('/plano_voo_metas.php'),
  appPath('/integracoes.php')
]);
const PAGE_DENYLIST = new Set([
  appPath('/admin.php'),
  appPath('/reconciliacao.php'),
  appPath('/login.php'),
  appPath('/logout.php')
]);
const CACHEABLE_API_PATHS = new Set([
  appPath('/api/overview.php'),
  appPath('/api/orders.php'),
  appPath('/api/vendors.php'),
  appPath('/api/products.php'),
  appPath('/api/finance_overview.php'),
  appPath('/api/finance_titles.php'),
  appPath('/api/flight_plan.php'),
  appPath('/api/weather.php')
]);
const PRECACHE_URLS = [
  OFFLINE_URL,
  appPath('/assets/css/theme-tokens.css'),
  appPath('/assets/css/shell-index.css'),
  appPath('/assets/css/module-pages.css'),
  appPath('/assets/css/finance-realizado.css'),
  appPath('/assets/js/shell.js'),
  appPath('/assets/js/app.js'),
  appPath('/assets/js/finance.js'),
  appPath('/assets/js/flight-plan.js'),
  appPath('/assets/js/flight-plan-v2.js'),
  appPath('/assets/js/flight-plan-targets.js'),
  appPath('/assets/js/chart.umd.js'),
  appPath('/assets/js/pwa.js'),
  appPath('/images/logo-mag-wide.png'),
  appPath('/images/favicon.svg'),
  appPath('/images/favicon.ico'),
  appPath('/images/pwa/icon-192.png'),
  appPath('/images/pwa/icon-512.png'),
  appPath('/images/pwa/icon-192-maskable.png'),
  appPath('/images/pwa/icon-512-maskable.png'),
  appPath('/images/pwa/apple-touch-icon.png')
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_URLS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((key) => ![STATIC_CACHE, PAGE_CACHE, DATA_CACHE].includes(key))
        .map((key) => caches.delete(key))
    );
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  if (request.mode === 'navigate') {
    if (PAGE_DENYLIST.has(url.pathname)) {
      return;
    }

    if (PAGE_ALLOWLIST.has(url.pathname)) {
      event.respondWith(handleNavigation(request));
    }
    return;
  }

  if (isStaticAsset(url)) {
    event.respondWith(cacheFirst(request, STATIC_CACHE, false));
    return;
  }

  if (isCacheableApi(url, request)) {
    event.respondWith(networkFirst(request, DATA_CACHE, true));
  }
});

function isStaticAsset(url) {
  return /^\/.+\.(?:css|js|png|svg|ico|jpg|jpeg|webp|woff2?)$/i.test(url.pathname) && url.pathname.startsWith(`${scopePath}/`);
}

function isCacheableApi(url, request) {
  if (!url.pathname.startsWith(appPath('/api/'))) {
    return false;
  }

  if (request.headers.get('X-Requested-With') === 'fetch' && url.searchParams.get('force') === '1') {
    return false;
  }

  return CACHEABLE_API_PATHS.has(url.pathname);
}

async function handleNavigation(request) {
  const cache = await caches.open(PAGE_CACHE);
  const cacheKey = requestKey(request, false);

  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(cacheKey, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(cacheKey);
    if (cached) {
      return cached;
    }

    const offline = await caches.match(OFFLINE_URL, { ignoreSearch: true });
    if (offline) {
      return offline;
    }

    throw error;
  }
}

async function cacheFirst(request, cacheName, preserveSearch) {
  const cache = await caches.open(cacheName);
  const cacheKey = requestKey(request, preserveSearch);
  const cached = await cache.match(cacheKey, { ignoreSearch: !preserveSearch });
  if (cached) {
    return cached;
  }

  const response = await fetch(request);
  if (response.ok) {
    await cache.put(cacheKey, response.clone());
  }
  return response;
}

async function networkFirst(request, cacheName, preserveSearch) {
  const cache = await caches.open(cacheName);
  const cacheKey = requestKey(request, preserveSearch);

  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(cacheKey, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(cacheKey, { ignoreSearch: !preserveSearch });
    if (cached) {
      return cached;
    }
    throw error;
  }
}

function requestKey(request, preserveSearch) {
  const url = new URL(request.url);
  const normalized = `${url.origin}${url.pathname}${preserveSearch ? url.search : ''}`;
  return new Request(normalized, { method: 'GET' });
}
