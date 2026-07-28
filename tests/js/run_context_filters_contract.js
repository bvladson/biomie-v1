'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const registry = read('app/PageAssetRegistry.php');
const renderer = read('app/PageRenderer.php');
const layout = read('views/layouts/app.php');
const topbar = read('views/partials/topbar.php');
const mobileTopbar = read('views/partials/mobile_topbar.php');
const panel = read('views/partials/context-filters.php');
const script = read('assets/js/context-filters.js');
const styles = read('assets/css/context-filters.css');
const globalFilterStyles = read('assets/css/filters.css');

assert.ok(
  registry.includes("'assets/css/context-filters.css'")
    && registry.includes("'assets/js/context-filters.js'"),
  'assets compactos devem integrar o stack global uma unica vez'
);

assert.ok(
  renderer.includes("'contextFiltersEnabled'")
    && layout.includes("$magLayoutContext['contextFiltersEnabled']")
    && layout.includes("partials/context-filters.php"),
  'PageRenderer deve permitir desligar o componente e o layout deve honrar a flag'
);

const vendorPosition = topbar.indexOf('data-vendor-filter');
const filterPosition = topbar.indexOf('data-context-filters-open');
assert.ok(vendorPosition >= 0 && filterPosition > vendorPosition, 'icone deve ficar ao lado e depois do vendedor');
assert.match(topbar, /aria-label="Mais filtros"[\s\S]*data-context-filters-count/);
assert.match(mobileTopbar, /mag-mobile-topbar__filter-btn[\s\S]*data-context-filters-open/);

assert.ok(
  panel.includes('id="biomieContextFilterStart"')
    && panel.includes('id="biomieContextFilterEnd"')
    && !panel.includes('id="dateStart"')
    && !panel.includes('id="dateEnd"'),
  'inputs globais precisam de ids proprios, sem colisao com as paginas'
);
assert.match(panel, /data-context-filter-slot/);
assert.match(panel, /data-context-filter-preset="month"/);
assert.match(panel, /data-context-filter-preset="year"/);
assert.match(panel, /data-context-filter-preset="rolling12"/);

assert.ok(
  script.includes("'biomie:filter-register'")
    && script.includes("'biomie:filter-change'")
    && script.includes("'biomie:filter-apply'"),
  'contrato global deve registrar schema e emitir change/apply'
);
assert.ok(
  script.includes('cancelable: true')
    && script.includes('var useLegacyFallback = document.dispatchEvent(applyEvent);')
    && script.includes("document.getElementById('applyFilters')")
    && script.includes("document.getElementById('fisApply')")
    && script.includes('localApply.click();'),
  'pagina pode assumir o refresh; fallback legado ocorre somente quando nao for assumido'
);
assert.ok(
  script.includes('nav.setDateRange(range.start, range.end, range.preset)')
    && script.includes("url.searchParams.set('start', range.start)")
    && script.includes("url.searchParams.set('end', range.end)")
    && script.includes("url.searchParams.set('preset', range.preset)"),
  'periodo deve persistir no NavContext e na URL'
);
assert.ok(
  script.includes("typeof window.BIomieVendorFilter.getSelectedIds === 'function'")
    && script.includes('values.vendor_ids = vendorIds')
    && script.includes("values.vendor_ids_csv = vendorIds.join(',')")
    && script.includes('values.vendor_names = vendorNames')
    && script.includes("values.vendor_id = vendorIds.length === 1 ? vendorIds[0] : ''")
    && script.includes('values.vendor_nome = vendorIds.length === 1')
    && !/merge\(\{[^}]*vendor_id:\s*null/.test(script),
  'filtro contextual deve expor selecao multipla e preservar o vendedor global'
);
assert.ok(
  script.includes("if (type === 'month')")
    && script.includes("String(value || '').slice(0, 7)")
    && script.includes("'fisDateStart'")
    && script.includes("'fisDateEnd'")
    && script.includes('window.BReconciliation.loadFiltered(1)'),
  'sync legado precisa respeitar input type=month'
);
assert.ok(
  script.includes("event.key === 'Escape'")
    && script.includes("event.key !== 'Tab'")
    && script.includes("backdrop.addEventListener('click'")
    && script.includes('activeTrigger.focus();'),
  'drawer/popover deve fechar por Escape/click-outside e controlar foco'
);

assert.match(styles, /@media \(max-width: 767px\)[\s\S]*\.biomie-context-filters\s*\{[\s\S]*inset: auto 0 0/);
assert.match(styles, /@media \(min-width: 768px\)[\s\S]*mag-mobile-topbar__filter-btn/);
assert.match(script, /matchMedia\('\(max-width: 767px\)'\)/);
assert.match(
  globalFilterStyles,
  /@media \(max-width: 767px\)[\s\S]*\.topbar \.biomie-vendor-filter/,
  'seletor de vendedor deve continuar visivel no header de tablet'
);
assert.ok(
  /mag-topbar \.topbar-left\s*\{[\s\S]*flex-wrap:\s*nowrap/.test(styles)
    && /@media \(min-width: 768px\) and \(max-width: 1500px\)[\s\S]*#pwaInstallButton/.test(styles),
  'vendedor e icone devem permanecer na mesma linha, com acoes compactas em telas menores'
);
assert.match(styles, /\.biomie-context-filter-trigger__badge/);

process.stdout.write('PASS context filters contract (14/14)\n');
