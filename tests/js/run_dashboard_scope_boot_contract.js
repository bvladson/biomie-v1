'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const moduleSource = fs.readFileSync(
  path.join(root, 'assets/js/modules/dashboard/page-drilldown.js'),
  'utf8'
);
const appSource = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
const viewSource = fs.readFileSync(path.join(root, 'views/index.html'), 'utf8');

assert.match(moduleSource, /scopeReady:\s*scopeReady/);
assert.match(moduleSource, /if \(!context\.ready\) return true;/);
assert.match(moduleSource, /return scopeReady\(\)\.then\(function \(\)/);
assert.match(moduleSource, /context\.restricted \|\|/);
assert.match(moduleSource, /vendor_id/);
assert.doesNotMatch(
  moduleSource,
  /\['f_dash_cliente', 'f_dash_produto'\][\s\S]{0,500}addEventListener\('input',[\s\S]{0,180}loadFiltered/
);

const waitPosition = appSource.indexOf("typeof window.BDashboard.scopeReady === 'function'");
const decisionPosition = appSource.indexOf('window.BDashboard.hasExtraFilters()');
assert.ok(waitPosition >= 0 && decisionPosition > waitPosition, 'app deve aguardar o escopo antes da decisao');
assert.match(appSource, /!dashboardUsesScopedEndpoint && hydrateDashboardFromBootstrap\(\)/);
assert.match(viewSource, /biomie:dashboard-filtered/);
assert.match(viewSource, /cal\.setSellers\(selected\)/);
assert.match(viewSource, /sellerIds:\s*initialCalendarVendorIds/);
assert.match(viewSource, /url\.searchParams\.set\('seller_ids', selected\.join\(','\)\)/);
assert.match(viewSource, /url\.searchParams\.set\('vendor_ids', selected\.join\(','\)\)/);
assert.match(viewSource, /link\.setAttribute\('href', file \+ url\.search \+ url\.hash\)/);
assert.match(appSource, /biomie:dashboard-load-failed/);
assert.match(moduleSource, /biomie:dashboard-load-failed[\s\S]{0,120}setLoading\(false\)/);

process.stdout.write('PASS dashboard scope boot contract\n');
