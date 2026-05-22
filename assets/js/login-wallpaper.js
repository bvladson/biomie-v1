/* login-wallpaper.js — Unsplash wallpaper loader for login page */
/* sessionStorage cache, preload, fade, fallback, attribution. No external deps. */
(function () {
  'use strict';

  var CACHE_KEY  = 'biomie_login_wp_v2'; // bumped to invalidate old Bing entries
  var CACHE_TTL  = 90 * 60 * 1000;       // 90 min sessionStorage cache
  var API_URL    = 'api/login_wallpaper.php';
  var FALLBACK   = 'assets/img/login-fallback.svg';

  var IMG_WHITELIST  = ['https://images.unsplash.com/', 'https://plus.unsplash.com/'];
  var KNOWN_SOURCES  = { unsplash: 1, cache: 1, fallback: 1, error: 1, no_key: 1, disabled: 1 };

  // ── Helpers ────────────────────────────────────────────────────────────────

  function isSafeImageUrl(url) {
    if (!url || typeof url !== 'string') return false;
    for (var i = 0; i < IMG_WHITELIST.length; i++) {
      if (url.indexOf(IMG_WHITELIST[i]) === 0) return true;
    }
    return false;
  }

  function setDebug(info) {
    var el = document.getElementById('lw-debug');
    if (!el) return;
    if (typeof info === 'string') { el.textContent = info; return; }
    var parts = [];
    if (info.provider)    parts.push('provider=' + info.provider);
    if (info.source)      parts.push('source=' + info.source);
    if (info.url_domain)  parts.push('url=' + info.url_domain);
    if (info.image_load)  parts.push('img=' + info.image_load);
    el.textContent = parts.join(' | ');
  }

  function urlDomain(url) {
    if (!url) return '?';
    if (url.indexOf('images.unsplash.com') >= 0) return 'images.unsplash.com';
    if (url.indexOf('plus.unsplash.com') >= 0)   return 'plus.unsplash.com';
    if (url.indexOf('assets/') === 0)             return 'local';
    return 'other';
  }

  function setupLogoFallback() {
    var img = document.getElementById('lw-logo');
    if (!img) return;
    if (img.complete) {
      if (img.naturalWidth === 0) img.style.display = 'none';
    } else {
      img.addEventListener('error', function () { img.style.display = 'none'; }, { once: true });
    }
  }

  // ── DOM ────────────────────────────────────────────────────────────────────

  function activate() {
    document.body.classList.add('lw-active');
  }

  function markReady() {
    document.body.classList.add('unsplash-ready');
  }

  function revealBackdrop(url) {
    var el = document.getElementById('lw-backdrop');
    if (!el) return;
    el.style.backgroundImage = 'url(' + JSON.stringify(url) + ')';
    el.classList.add('lw-ready');
  }

  // Attribution — created entirely via DOM, never innerHTML with external content.
  function applyCredit(data) {
    var el = document.getElementById('lw-credit');
    if (!el) return;

    var source = data && data.source;
    if (source === 'fallback' || source === 'error' || !data) {
      el.style.display = 'none';
      return;
    }

    var name = (data.photographer_name && typeof data.photographer_name === 'string')
      ? data.photographer_name.trim() : '';
    var photographerUrl = (data.photographer_url && typeof data.photographer_url === 'string')
      ? data.photographer_url.trim() : '';
    var unsplashUrl = (data.unsplash_url && typeof data.unsplash_url === 'string')
      ? data.unsplash_url.trim() : '';

    if (!name) { el.style.display = 'none'; return; }

    // Build: "Foto por [Name] no [Unsplash]"
    el.textContent = '';
    var prefix = document.createTextNode('Foto por ');
    el.appendChild(prefix);

    if (photographerUrl) {
      var pLink = document.createElement('a');
      pLink.textContent = name;
      pLink.setAttribute('href', photographerUrl);
      pLink.setAttribute('target', '_blank');
      pLink.setAttribute('rel', 'noopener noreferrer');
      el.appendChild(pLink);
    } else {
      el.appendChild(document.createTextNode(name));
    }

    el.appendChild(document.createTextNode(' no '));

    if (unsplashUrl) {
      var uLink = document.createElement('a');
      uLink.textContent = 'Unsplash';
      uLink.setAttribute('href', unsplashUrl);
      uLink.setAttribute('target', '_blank');
      uLink.setAttribute('rel', 'noopener noreferrer');
      el.appendChild(uLink);
    } else {
      el.appendChild(document.createTextNode('Unsplash'));
    }

    el.style.display = '';
  }

  // ── Core paths ─────────────────────────────────────────────────────────────

  function applyWallpaper(url, data, displaySource) {
    activate();
    var src     = displaySource || (data && data.source) || 'unsplash';
    var prov    = (data && data.provider) || 'unsplash';
    var domain  = urlDomain(url);
    setDebug({ provider: prov, source: src, url_domain: domain, image_load: 'pending' });

    var img    = new Image();
    img.onload = function () {
      revealBackdrop(url);
      markReady();
      setDebug({ provider: prov, source: src, url_domain: domain, image_load: 'ok' });
    };
    img.onerror = function () {
      revealBackdrop(FALLBACK);
      setDebug({ provider: prov, source: src, url_domain: domain, image_load: 'err' });
    };
    img.src = url;

    applyCredit(data);
  }

  function useFallback(reason) {
    activate();
    setDebug({ provider: 'unsplash', source: 'fallback', url_domain: 'local', image_load: reason || 'fallback' });
    revealBackdrop(FALLBACK);
    var el = document.getElementById('lw-credit');
    if (el) el.style.display = 'none';
  }

  // ── Contract validation ────────────────────────────────────────────────────

  function validateContract(resp) {
    // Shape: { ok, data: { provider, source, url, ... } }
    if (!resp || typeof resp !== 'object') return false;
    if (resp.ok !== true) return false;
    var d = resp.data;
    if (!d || typeof d !== 'object') return false;
    if (typeof d.url !== 'string') return false;
    if (d.provider !== 'unsplash') return false;
    if (!KNOWN_SOURCES[d.source]) return false;
    return true;
  }

  // ── sessionStorage ─────────────────────────────────────────────────────────

  function readSession() {
    try {
      var raw = sessionStorage.getItem(CACHE_KEY);
      if (!raw) return null;
      var obj = JSON.parse(raw);
      if (!obj || !obj._ts || (Date.now() - obj._ts) > CACHE_TTL) {
        sessionStorage.removeItem(CACHE_KEY);
        return null;
      }
      return obj;
    } catch (e) {
      try { sessionStorage.removeItem(CACHE_KEY); } catch (_) {}
      return null;
    }
  }

  function writeSession(data) {
    try {
      sessionStorage.setItem(CACHE_KEY, JSON.stringify(
        Object.assign({}, data, { _ts: Date.now() })
      ));
    } catch (e) { /* quota or unavailable — ignore */ }
  }

  // ── Initialization ─────────────────────────────────────────────────────────

  function init() {
    setupLogoFallback();

    if (typeof fetch !== 'function') { useFallback('no_fetch'); return; }

    // 1. sessionStorage hit — avoid API call on revisit.
    var cached = readSession();
    if (cached) {
      var cachedUrl = typeof cached.url === 'string' ? cached.url : '';
      if (isSafeImageUrl(cachedUrl)) {
        applyWallpaper(cachedUrl, cached, 'session');
        return;
      }
      if (cachedUrl === FALLBACK || cachedUrl.indexOf('assets/') === 0) {
        applyWallpaper(cachedUrl, cached, 'session_fallback');
        return;
      }
      // Unsafe URL in session — clear and continue to API.
      try { sessionStorage.removeItem(CACHE_KEY); } catch (_) {}
    }

    // 2. Fetch from PHP proxy — browser never calls api.unsplash.com directly.
    fetch(API_URL, { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (resp) {
        if (!validateContract(resp)) {
          useFallback('api_bad_shape');
          return;
        }

        var data   = resp.data;
        var url    = data.url;
        var source = data.source;

        // Fallback path — server couldn't get Unsplash.
        if (source === 'fallback' || source === 'error' || !isSafeImageUrl(url)) {
          var localUrl = (url && url.indexOf('assets/') === 0) ? url : FALLBACK;
          writeSession(Object.assign({}, data, { url: localUrl }));
          applyWallpaper(localUrl, data, 'server_fallback');
          return;
        }

        writeSession(data);
        applyWallpaper(url, data);
      })
      .catch(function () { useFallback('fetch_error'); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
