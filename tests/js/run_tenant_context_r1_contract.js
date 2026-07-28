'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const runtimeSource = read('assets/js/tenant-tab-context.js');
const runtimeMinSource = read('assets/js/tenant-tab-context.min.js');
const selectorSource = read('assets/js/select-empresa.js');
const selectorMinSource = read('assets/js/select-empresa.min.js');
const sidebarSource = read('views/partials/sidebar.php');
const uploadAvatarSource = read('api/upload_avatar.php');
const shellSource = read('assets/js/shell.js');
const assetRegistrySource = read('app/PageAssetRegistry.php');
const pageRendererSource = read('app/PageRenderer.php');

assert.ok(
  runtimeSource.includes("var contextStorageKey = 'biomie_tab_context_v1'")
    && runtimeSource.includes('sessionStorage.setItem')
    && !runtimeSource.includes('localStorage'),
  'ctx ativo deve existir somente na sessão da aba'
);
assert.ok(
  runtimeSource.includes("nativeFetch('api/context_switch.php'")
    && selectorSource.includes("apiUrl('api/context_switch.php')")
    && sidebarSource.includes('runtime.switchContext(target')
    && !runtimeSource.includes('api/set_context.php')
    && !selectorSource.includes('api/set_context.php')
    && !sidebarSource.includes("Auth::url('api/set_context.php')"),
  'todos os seletores devem usar o endpoint canônico'
);
assert.ok(
  runtimeSource.includes('contextController.abort')
    && runtimeSource.includes('contextGeneration += 1')
    && runtimeSource.includes('activeXhrs.forEach')
    && runtimeSource.includes('biomie:context-switch-start'),
  'troca deve abortar requisições antigas e avançar a geração'
);
assert.ok(
  runtimeSource.includes('Resposta sem meta.context_id foi descartada.')
    && runtimeSource.includes('Resposta HTTP sem X-BIOMIE-Context foi descartada.')
    && runtimeSource.includes('responseContextId !== expectedId')
    && shellSource.includes('contextRuntime.acceptPayload('),
  'fetch cru e fetchJson devem exigir header/meta e rejeitar outro contexto'
);
assert.ok(
  runtimeSource.includes("xhr.addEventListener('load'")
    && runtimeSource.includes('xhr.__biomieContextGeneration !== contextGeneration')
    && runtimeSource.includes('event.stopImmediatePropagation()')
    && runtimeSource.includes("detail: { transport: 'xhr' }"),
  'XHR antigo também deve ser neutralizado antes dos handlers consumidores'
);
assert.ok(
  runtimeSource.includes("if (typeof response.text === 'function')")
    && runtimeSource.includes("['blob', 'arrayBuffer', 'formData']")
    && runtimeSource.includes('requestMeta.generation !== contextGeneration'),
  'consumo tardio por text/blob também deve reverificar a geração'
);
assert.ok(
  runtimeSource.includes("'/api/login_wallpaper.php'")
    && !runtimeSource.includes("'/api/session_ping.php'")
    && runtimeSource.includes('contextFreeRequest ? null : contextController.signal')
    && runtimeSource.includes('!contextFreeRequest'),
  'somente o wallpaper público deve permanecer fora do contexto empresarial'
);
assert.ok(
  sidebarSource.includes("'api/avatar.php?uid=' . $magUserId")
    && sidebarSource.includes("'&ctx=' . rawurlencode(\\BIomie\\Support\\TenantContext::contextId())")
    && uploadAvatarSource.includes("'&ctx=' . rawurlencode(TenantContext::contextId())"),
  'avatar binário deve receber ctx explícito no HTML e após upload'
);
assert.ok(
  shellSource.includes('function _writeTenantContext(params)')
    && (shellSource.match(/_writeTenantContext\(params\)/g) || []).length >= 3,
  'NavContext deve transportar ctx em URLs construídas e no history'
);
assert.ok(
  runtimeMinSource.includes('api/context_switch.php')
    && runtimeMinSource.includes('X-BIOMIE-Context')
    && runtimeMinSource.includes('context-response-discarded')
    && selectorMinSource.includes('api/context_switch.php')
    && fs.statSync(path.join(root, 'assets/js/tenant-tab-context.min.js')).mtimeMs
      >= fs.statSync(path.join(root, 'assets/js/tenant-tab-context.js')).mtimeMs
    && fs.statSync(path.join(root, 'assets/js/select-empresa.min.js')).mtimeMs
      >= fs.statSync(path.join(root, 'assets/js/select-empresa.js')).mtimeMs,
  'assets minificados servidos devem refletir a barreira R1'
);
assert.ok(
  assetRegistrySource.includes('private const BASE_JS_HEAD')
    && assetRegistrySource.includes('array_merge(\n            self::BASE_JS_HEAD,')
    && assetRegistrySource.indexOf("'assets/js/tenant-tab-context.js'")
      < assetRegistrySource.indexOf('private const BASE_JS = [')
    && assetRegistrySource.includes("'<script src=\"%s\"></script>'"),
  'runtime de contexto deve ser síncrono no head antes de scripts inline'
);
assert.ok(
  pageRendererSource.includes('$securityPrelude = [];')
    && pageRendererSource.includes("if ($path === 'assets/js/tenant-tab-context.js')")
    && pageRendererSource.includes("implode(\"\\n\", $securityPrelude) . \"\\n\"")
    && pageRendererSource.indexOf('implode("\\n", $securityPrelude)')
      < pageRendererSource.indexOf('. $headHtml', pageRendererSource.indexOf('$securityPrelude')),
  'PageRenderer deve posicionar a barreira antes do conteúdo original do head'
);

const firstContext = 'a'.repeat(32);
const secondContext = 'b'.repeat(32);
const thirdContext = 'c'.repeat(32);
const store = new Map([['biomie_tab_context_v1', firstContext]]);
const pending = new Map();
const requests = [];
let switchResponse = {
  status: 200,
  payload: {
    ok: true,
    context: {
      id: secondContext,
      type: 'company',
      company_id: 2,
      company_slug: 'EXPERT',
      company_name: 'Expert',
      business_scope: 'licitacao',
      role: 'manager'
    },
    redirect_url: '/biomie/index.php?ctx=' + secondContext
  }
};

function classList() {
  const values = new Set();
  return {
    add(value) { values.add(value); },
    remove(value) { values.delete(value); },
    contains(value) { return values.has(value); },
    toggle(value, force) {
      if (force === false) values.delete(value);
      else values.add(value);
    }
  };
}

const location = {
  href: 'https://example.test/biomie/index.php?ctx=' + firstContext,
  origin: 'https://example.test',
  pathname: '/biomie/index.php',
  search: '?ctx=' + firstContext,
  assign() {},
  replace() {}
};
const history = {
  state: {},
  replaceState(_state, _title, url) {
    if (!url) return;
    const parsed = new URL(url, location.href);
    location.href = parsed.toString();
    location.pathname = parsed.pathname;
    location.search = parsed.search;
  },
  pushState(_state, _title, url) {
    this.replaceState(_state, _title, url);
  }
};
const document = {
  body: { classList: classList() },
  documentElement: {},
  getElementById() { return null; },
  querySelector(selector) {
    if (selector === 'meta[name="biomie-csrf"]') return { content: 'csrf-test' };
    return null;
  },
  querySelectorAll() { return []; },
  addEventListener() {},
  dispatchEvent() { return true; },
  createElement() {
    return {
      classList: classList(),
      setAttribute() {},
      remove() {},
      appendChild() {},
      innerHTML: ''
    };
  }
};

async function nativeFetch(input, options) {
  const rawUrl = input instanceof Request ? input.url : String(input);
  const url = new URL(rawUrl, location.href);
  requests.push({ url: url.pathname + url.search, options });
  if (url.pathname.endsWith('/api/context_switch.php')) {
    return new Response(JSON.stringify(switchResponse.payload), {
      status: switchResponse.status,
      headers: { 'content-type': 'application/json' }
    });
  }
  if (url.pathname.endsWith('/api/session_ping.php')) {
    return new Response(JSON.stringify({
      ok: true,
      meta: { context_id: firstContext }
    }), {
      status: 200,
      headers: {
        'content-type': 'application/json',
        'X-BIOMIE-Context': firstContext
      }
    });
  }
  if (url.pathname.endsWith('/api/login_wallpaper.php')) {
    return new Response('{"ok":true,"url":"fallback.svg"}', {
      status: 200,
      headers: { 'content-type': 'application/json' }
    });
  }
  if (url.pathname.endsWith('/api/missing.php')) {
    return new Response('{"ok":true}', {
      status: 200,
      headers: {
        'content-type': 'application/json',
        'X-BIOMIE-Context': secondContext
      }
    });
  }
  if (url.pathname.endsWith('/api/no-header.php')) {
    return new Response(JSON.stringify({
      ok: true,
      meta: { context_id: secondContext }
    }), {
      status: 200,
      headers: { 'content-type': 'application/json' }
    });
  }
  if (url.pathname.endsWith('/api/mismatch.php')) {
    return new Response(JSON.stringify({ ok: true, meta: { context_id: firstContext } }), {
      status: 200,
      headers: {
        'content-type': 'application/json',
        'X-BIOMIE-Context': secondContext
      }
    });
  }
  if (url.pathname.endsWith('/api/current.php')) {
    return new Response(JSON.stringify({ ok: true, meta: { context_id: secondContext } }), {
      status: 200,
      headers: {
        'content-type': 'application/json',
        'X-BIOMIE-Context': secondContext
      }
    });
  }
  return new Promise((resolve) => {
    pending.set(url.pathname, resolve);
  });
}

const sandbox = {
  AbortController,
  DOMException,
  Headers,
  Request,
  Response,
  URL,
  Uint8Array,
  CustomEvent: class CustomEvent {
    constructor(type, init) {
      this.type = type;
      this.detail = init && init.detail;
    }
  },
  document,
  history,
  location,
  navigator: {},
  sessionStorage: {
    getItem(key) { return store.has(key) ? store.get(key) : null; },
    setItem(key, value) { store.set(key, String(value)); },
    removeItem(key) { store.delete(key); }
  },
  window: {
    crypto: globalThis.crypto,
    fetch: nativeFetch,
    location,
    history,
    name: '',
    setTimeout,
    clearTimeout,
    addEventListener() {},
    requestAnimationFrame(callback) { callback(); }
  },
  setTimeout,
  clearTimeout,
  Promise,
  console
};
sandbox.window.window = sandbox.window;
sandbox.window.document = document;
sandbox.window.navigator = sandbox.navigator;
sandbox.window.sessionStorage = sandbox.sessionStorage;

vm.runInNewContext(runtimeSource, sandbox, { filename: 'tenant-tab-context.js' });

async function run() {
  await sandbox.window.BIOMIE_TAB_CONTEXT_READY;
  const runtime = sandbox.window.BIOMIE_CONTEXT;
  assert.equal(runtime.currentId(), firstContext, 'ctx inicial deve vir da URL/aba');

  const pingResponse = await sandbox.window.fetch('api/session_ping.php');
  assert.equal((await pingResponse.json()).ok, true);
  const pingRequest = requests.find((request) => request.url.includes('/api/session_ping.php'));
  assert.ok(pingRequest && pingRequest.url.includes('ctx=' + firstContext));
  assert.equal(
    new Headers(pingRequest.options.headers).get('X-BIOMIE-Context'),
    firstContext,
    'session ping também deve validar o contexto da aba'
  );
  const wallpaperResponse = await sandbox.window.fetch('api/login_wallpaper.php');
  assert.equal((await wallpaperResponse.json()).ok, true);
  const wallpaperRequest = requests.find((request) => request.url.includes('/api/login_wallpaper.php'));
  assert.ok(wallpaperRequest && !wallpaperRequest.url.includes('ctx='));
  assert.equal(
    new Headers(wallpaperRequest.options.headers).has('X-BIOMIE-Context'),
    false,
    'wallpaper público é a única API sem contexto'
  );

  const staleRequest = sandbox.window.fetch('api/stale.php');
  await Promise.resolve();
  const switchPromise = runtime.switchContext({
    target_type: 'company',
    company_id: 2,
    business_scope: 'licitacao'
  }, { redirect: false });
  assert.equal(
    runtime.currentId(),
    firstContext,
    'nome/ctx não podem mudar antes da confirmação assíncrona do servidor'
  );
  const switched = await switchPromise;
  assert.equal(switched.context.id, secondContext);
  assert.equal(runtime.currentId(), secondContext, 'ctx só deve mudar após confirmação');
  assert.equal(store.get('biomie_tab_context_v1'), secondContext);

  const staleResolver = pending.get('/biomie/api/stale.php');
  assert.ok(staleResolver, 'requisição antiga deve ter sido iniciada');
  staleResolver(new Response(JSON.stringify({
    ok: true,
    meta: { context_id: firstContext }
  }), {
    status: 200,
    headers: {
      'content-type': 'application/json',
      'X-BIOMIE-Context': firstContext
    }
  }));
  await assert.rejects(
    staleRequest,
    (error) => error && error.name === 'AbortError' && error.code === 'BIOMIE_STALE_CONTEXT',
    'resposta da geração anterior deve ser descartada'
  );

  const currentResponse = await sandbox.window.fetch('api/current.php');
  assert.equal((await currentResponse.json()).ok, true, 'meta do ctx atual deve ser aceita');

  const missingResponse = await sandbox.window.fetch('api/missing.php');
  await assert.rejects(
    missingResponse.json(),
    (error) => error && error.code === 'BIOMIE_STALE_CONTEXT',
    'API sem meta.context_id deve falhar fechada'
  );

  await assert.rejects(
    sandbox.window.fetch('api/no-header.php'),
    (error) => error && error.code === 'BIOMIE_STALE_CONTEXT',
    'API sem X-BIOMIE-Context deve falhar antes do consumo do body'
  );

  const mismatchResponse = await sandbox.window.fetch('api/mismatch.php');
  await assert.rejects(
    mismatchResponse.json(),
    (error) => error && error.code === 'BIOMIE_STALE_CONTEXT',
    'API com meta de outra empresa deve ser descartada'
  );

  switchResponse = {
    status: 403,
    payload: { ok: false, message: 'Empresa sem acesso.' }
  };
  await assert.rejects(
    runtime.switchContext({ target_type: 'company', company_id: 3 }, { redirect: false }),
    /Empresa sem acesso/
  );
  assert.equal(runtime.currentId(), secondContext, 'erro deve preservar o contexto anterior');
  assert.equal(store.get('biomie_tab_context_v1'), secondContext);

  const switchRequest = requests.find((request) => (
    request.url.includes('/api/context_switch.php')
  ));
  assert.ok(switchRequest, 'endpoint canônico deve ser chamado');
  const switchBody = JSON.parse(switchRequest.options.body);
  assert.equal(switchBody.target_type, 'company');
  assert.equal(switchBody.company_id, 2);
  assert.equal(switchBody.business_scope, 'licitacao');
  assert.equal(switchBody.csrf_token, 'csrf-test');
  assert.equal(
    new Headers(switchRequest.options.headers).get('X-BIOMIE-Context'),
    firstContext,
    'troca deve informar o contexto de origem para auditoria'
  );

  assert.notEqual(runtime.currentId(), thirdContext);
  process.stdout.write('PASS tenant context R1 frontend contract\n');
}

run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
