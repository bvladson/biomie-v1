'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const desktop = read('views/partials/topbar.php');
const mobile = read('views/partials/mobile_topbar.php');
const script = read('assets/js/vendor-filter.js');
const shell = read('assets/js/shell.js');
const styles = read('assets/css/filters.css');

assert.match(
  desktop,
  /data-vendor-filter-toggle[\s\S]*data-vendor-filter-search[\s\S]*data-vendor-filter-list[\s\S]*data-vendor-filter-apply/,
  'desktop deve usar painel pesquisavel com aplicacao explicita'
);
assert.match(
  mobile,
  /biomie-vendor-filter--mobile-topbar[\s\S]*data-vendor-filter-toggle[\s\S]*id="biomieVendorPanelMobile"/,
  'mobile deve oferecer o seletor diretamente no header'
);
assert.ok(
  !desktop.includes('list="biomieVendorOptions"')
    && !mobile.includes('list="biomieVendorOptions"')
    && !mobile.includes('biomie-mobile-search__vendor'),
  'datalist e barra duplicada no overlay mobile devem ser removidos'
);

assert.ok(
  script.includes("vendor_ids: ids.length ? ids.join(',') : null")
    && script.includes("vendor_id: ids.length === 1 ? ids[0] : null")
    && script.includes("vendor_nome: ids.length === 1 ? names[0] : null"),
  'NavContext deve persistir CSV canonico com fallback singular'
);
assert.ok(
  script.includes("url.searchParams.set('vendor_ids', csv)")
    && script.includes("url.searchParams.delete('vendor_id')")
    && script.includes("url.searchParams.delete('vendor_nome')"),
  'URL deve representar multiplos vendedores sem deixar singular obsoleto'
);
assert.ok(
  script.includes('if (fromUrl !== null) return fromUrl'),
  'vendor_ids vazio na URL deve limpar a sessao em vez de restaurar estado antigo'
);
assert.ok(
  script.includes('window.sessionStorage.getItem(STORE_KEY)')
    && !script.includes('window.localStorage.getItem(STORE_KEY)'),
  'marcador de empresa deve ser isolado por aba'
);
assert.ok(
  script.includes("instance.panel.classList.add('biomie-vendor-filter__panel--mobile')")
    && script.includes('document.body.appendChild(instance.panel)')
    && styles.includes('.biomie-vendor-filter__panel--mobile'),
  'drawer mobile deve ser portalizado para a viewport'
);
assert.ok(
  script.includes("vendor.label != null")
    && script.includes("vendor.name != null"),
  'rotulo consolidado do roster deve ter prioridade e manter fallback'
);
assert.ok(
  script.includes("new CustomEvent('biomie:vendor-filter-apply'")
    && script.includes('cancelable: true')
    && script.includes('vendorIds: ids.slice()')
    && script.includes('values: {')
    && script.includes('vendor_ids_csv: ids.join'),
  'evento deve ser interceptavel e expor contrato multiplo e compativel'
);
assert.ok(
  script.includes("document.addEventListener('biomie:vendor-context-change'")
    && script.includes("detail.source === 'header'")
    && script.includes('syncExternalSelection'),
  'seletor deve sincronizar alteracoes externas sem criar loop'
);
assert.ok(
  script.includes("fetch('api/vendors_list.php'")
    && script.includes('idToVendor.has(id)')
    && script.includes('currentIds = allowed'),
  'opcoes e restauracao devem permanecer limitadas ao roster permitido'
);
assert.ok(
  script.includes('var MAX_SELECTED = 50')
    && script.includes('instance.draft.size >= MAX_SELECTED')
    && script.includes('.slice(0, MAX_SELECTED)'),
  'frontend deve respeitar o mesmo limite de vendedores aceito pelo servidor'
);
assert.ok(
  script.includes("window.BIomieContextFilters.close(false)")
    && script.includes("event.key === 'Tab'")
    && mobile.includes('data-vendor-filter-backdrop'),
  'paineis nao devem sobrepor e o drawer mobile deve conter foco e backdrop'
);

assert.ok(
  shell.includes("'vendor_ids', 'vendor_id'")
    && shell.includes("var vendorIds = window.NavContext.get('vendor_ids')")
    && shell.includes("/[?&]vendor_ids?=/.test(url)")
    && shell.includes("var key = vendorIds ? 'vendor_ids' : 'vendor_id'"),
  'choke-point global deve preferir vendor_ids e aceitar o formato singular'
);
assert.ok(
  shell.includes('function _writeVendorParams(params, state)')
    && (shell.match(/_writeVendorParams\(params, s\)/g) || []).length >= 2,
  'NavContext deve transportar a selecao canonica em pushState e buildUrl'
);

const navStart = shell.indexOf('window.NavContext = (function () {');
const navEndMarker = '\n})();';
const navEnd = shell.indexOf(navEndMarker, navStart);
assert.ok(navStart >= 0 && navEnd > navStart, 'bloco NavContext deve ser localizavel');

const store = new Map();
const sandbox = {
  URLSearchParams,
  window: {
    location: { search: '', pathname: '/index.php', href: '' },
    history: { replaceState() {} }
  },
  sessionStorage: {
    getItem(key) { return store.has(key) ? store.get(key) : null; },
    setItem(key, value) { store.set(key, String(value)); }
  },
  document: { getElementById() { return null; } }
};
vm.runInNewContext(shell.slice(navStart, navEnd + navEndMarker.length), sandbox);

store.set('biomie:nav', JSON.stringify({ vendor_ids: 'A,B', vendor_id: 'A' }));
sandbox.window.location.search = '?vendor_id=C&vendor_nome=Celia';
sandbox.window.NavContext.syncFromUrl();
assert.deepEqual(
  JSON.parse(store.get('biomie:nav')),
  { vendor_id: 'C', vendor_nome: 'Celia' },
  'deep-link singular deve remover selecao multipla obsoleta'
);

store.set('biomie:nav', JSON.stringify({ vendor_id: 'C', vendor_nome: 'Celia' }));
sandbox.window.location.search = '?vendor_ids=A,B';
sandbox.window.NavContext.syncFromUrl();
assert.deepEqual(
  JSON.parse(store.get('biomie:nav')),
  { vendor_ids: 'A,B' },
  'deep-link multiplo deve remover fallback singular obsoleto'
);
assert.equal(
  sandbox.window.NavContext.buildUrl('pedidos.php'),
  'pedidos.php?vendor_ids=A%2CB',
  'buildUrl multiplo nao deve carregar vendor_id contraditorio'
);

store.set('biomie:nav', JSON.stringify({ vendor_ids: 'A', vendor_id: 'C', vendor_nome: 'Ana' }));
assert.equal(
  sandbox.window.NavContext.buildUrl('pedidos.php'),
  'pedidos.php?vendor_ids=A&vendor_id=A&vendor_nome=Ana',
  'selecao unica deve normalizar o fallback legado para o mesmo ID'
);

const tooMany = Array.from({ length: 51 }, (_, index) => `V${index + 1}`).join(',');
store.set('biomie:nav', '{}');
sandbox.window.location.search = `?vendor_ids=${encodeURIComponent(tooMany)}`;
sandbox.window.NavContext.syncFromUrl();
assert.equal(
  JSON.parse(store.get('biomie:nav')).vendor_ids.split(',').length,
  50,
  'deep-link deve ser limitado antes de qualquer consulta global'
);

assert.match(styles, /\.biomie-vendor-filter__options\s*\{[\s\S]*overflow-y:\s*auto/);
assert.match(styles, /\.biomie-vendor-filter__option input\s*\{[\s\S]*accent-color/);
assert.match(
  styles,
  /@media \(max-width: 767px\)[\s\S]*\.biomie-vendor-filter--mobile-topbar[\s\S]*position:\s*fixed/,
  'seletor mobile deve abrir como drawer de toque'
);
assert.match(
  styles,
  /\.mag-topbar\s*\{[\s\S]*overflow:\s*visible\s*!important/,
  'popover desktop nao deve ser cortado pelo overflow do header'
);

process.stdout.write('PASS vendor filter multiselect contract (23/23)\n');
