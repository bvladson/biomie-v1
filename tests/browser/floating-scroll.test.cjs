'use strict';

// Test-only Playwright dependency can live outside this PHP repository.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { startFixtureServer, fixturePath } = require('./support.cjs');
const playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const artifactDir = process.env.FLOATING_SCROLL_ARTIFACTS;
const browserName = process.env.BROWSER || 'chromium';
let server;
let browser;
let baseURL;

before(async () => {
  server = await startFixtureServer();
  baseURL = server.baseURL;
  assert.ok(playwright[browserName], `Unknown browser ${browserName}`);
  browser = await playwright[browserName].launch({
    headless: true,
    // Exercise the actual native track/thumb, including screenshots and pointer input.
    ...(browserName === 'chromium' ? { ignoreDefaultArgs: ['--hide-scrollbars'] } : {}),
  });
  console.log(`Browser: ${browserName} ${browser.version()}; Playwright ${require(path.join(path.dirname(require.resolve(process.env.PLAYWRIGHT_MODULE || 'playwright')), 'package.json')).version}`);
});

after(async () => {
  if (browser) await browser.close();
  if (server) await server.close();
});

async function until(check, description, timeout = 5000) {
  const started = Date.now();
  let last;
  while (Date.now() - started < timeout) {
    try { if (await check()) return; } catch (error) { last = error; }
    await new Promise(resolve => setTimeout(resolve, 40));
  }
  throw new Error(`${description}${last ? ': ' + last.message : ''}`);
}

async function setup(t, { scenario = 'tall', viewport = { width: 1366, height: 768 }, zoom = 1, initScript, ...options } = {}) {
  const context = await browser.newContext({ viewport, reducedMotion: 'reduce', ...options });
  const page = await context.newPage();
  if (initScript) await page.addInitScript(initScript);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => {
    if (artifactDir) {
      fs.mkdirSync(artifactDir, { recursive: true });
      const name = t.name.replace(/[^a-z0-9-]+/gi, '-').slice(0, 130);
      await page.screenshot({ path: path.join(artifactDir, `${browserName}-${name}.png`) }).catch(() => {});
    }
    await context.close();
    assert.deepEqual(errors, [], 'No unhandled browser errors');
  });
  await page.goto(`${baseURL}${fixturePath}?scenario=${encodeURIComponent(scenario)}&zoom=${zoom}`);
  await page.waitForFunction(() => Boolean(window.BiomieFloatingScroll && window.fixture));
  return page;
}

async function floating(page, source = '#source') {
  await until(() => page.locator(source).getAttribute('data-biomie-floating-scroll-source'), `${source} was discovered`);
  const id = await page.locator(source).getAttribute('data-biomie-floating-scroll-source');
  return page.locator(`.biomie-floating-scroll[data-biomie-floating-scroll-for="${id}"]`);
}

async function visibleFloating(page, source = '#source') {
  const bar = await floating(page, source);
  await until(() => bar.isVisible(), `${source} floating bar is visible`);
  return bar;
}

async function assertBounded(page, bar, container = '#source') {
  const bounds = await page.locator(container).boundingBox();
  const rect = await bar.boundingBox();
  const viewport = page.viewportSize();
  assert.ok(rect && bounds, 'Both rectangles are visible');
  assert.ok(rect.x >= Math.max(0, bounds.x) - 2, 'Bar begins inside source/container');
  assert.ok(rect.x + rect.width <= Math.min(viewport.width, bounds.x + bounds.width) + 2, 'Bar ends inside source/container');
  assert.ok(rect.y >= Math.max(0, bounds.y) - 2, 'Bar remains below table/container top');
  assert.ok(rect.y + rect.height <= Math.min(viewport.height, bounds.y + bounds.height) + 2, 'Bar remains above visible lower boundary');
}

async function assertSync(page, bar, source = '#source') {
  const scroller = bar.locator('.biomie-floating-scroll__viewport');
  const settle = () => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const position = locator => locator.evaluate(element => ({ left: element.scrollLeft, max: element.scrollWidth - element.clientWidth }));
  const synchronized = async () => {
    const a = await position(scroller);
    const b = await position(page.locator(source));
    return Math.abs(a.left / a.max - b.left / b.max) * Math.min(a.max, b.max) <= 2.1;
  };
  await page.locator(source).evaluate(element => { element.scrollLeft = 320; });
  await until(synchronized, 'Native scroll synchronizes floating scroll proportion');
  await settle();
  await scroller.evaluate(element => { element.scrollLeft = 180; });
  await until(synchronized, 'Floating scroll synchronizes native scroll proportion');
  await settle();
  await page.locator(source).evaluate(element => { element.scrollLeft = element.scrollWidth; });
  await until(async () => { const value = await position(scroller); return value.max - value.left <= 1.5; }, 'Native end reaches floating end');
  await settle();
  await scroller.evaluate(element => { element.scrollLeft = 0; });
  await until(async () => (await position(page.locator(source))).left <= 1.5, 'Floating start reaches native start');
  await settle();
  await scroller.evaluate(element => { element.scrollLeft = element.scrollWidth; });
  await until(async () => { const value = await position(page.locator(source)); return value.max - value.left <= 1.5; }, 'Floating end reaches native end');
  await settle();
  await page.locator(source).evaluate(element => { element.scrollLeft = 0; });
  await until(async () => (await position(scroller)).left <= 1.5, 'Native start reaches floating start');
}

async function nativeScrollbarState(page) {
  return page.locator('#source').evaluate(element => {
    const style = getComputedStyle(element);
    return {
      userAgent: navigator.userAgent,
      scrollbarWidth: style.scrollbarWidth,
      overflowX: style.overflowX,
      gutter: element.offsetHeight - element.clientHeight - element.clientTop - (parseFloat(style.borderBottomWidth) || 0),
      clientWidth: element.clientWidth,
      scrollWidth: element.scrollWidth,
    };
  });
}

for (const viewport of [{ width: 1920, height: 1080 }, { width: 1366, height: 768 }, { width: 900, height: 600 }]) {
  for (const zoom of [1, 1.25, 1.5]) {
    test(`geometry and bidirectional sync ${viewport.width}x${viewport.height} CSS zoom ${zoom}`, async t => {
      const page = await setup(t, { viewport, zoom });
      const bar = await visibleFloating(page);
      await assertBounded(page, bar);
      await assertSync(page, bar);
      await page.evaluate(() => window.scrollTo(0, 800));
      await until(() => bar.isVisible(), 'Bar follows visible table on page scroll');
      await assertBounded(page, bar);
      assert.ok((await bar.boundingBox()).y < viewport.height, 'Bar is in viewport, not page footer');
    });
  }
}

test('short table uses accessible native scrollbar; fitting table has no extra bar', async t => {
  const page = await setup(t, { scenario: 'short' });
  await new Promise(resolve => setTimeout(resolve, 150));
  const state = await nativeScrollbarState(page);
  const nativeHidden = state.scrollbarWidth === 'none';
  if (nativeHidden) t.diagnostic(`Native scrollbar suppressed; floating access required: ${JSON.stringify(state)}`);
  assert.equal(await page.locator('.biomie-floating-scroll:visible').count(), nativeHidden ? 1 : 0);
  if (nativeHidden) {
    const bar = await visibleFloating(page);
    await assertSync(page, bar);
    await bar.locator('[role=scrollbar]').focus();
    assert.equal(await bar.locator('[role=scrollbar]').getAttribute('aria-controls'), 'source');
    await page.keyboard.press('End');
    await until(async () => await page.locator('#source').evaluate(element => Math.abs(element.scrollWidth - element.clientWidth - element.scrollLeft) <= 2), 'Hidden native short table remains keyboard accessible');
  }
  await page.evaluate(() => window.fixture.render('fit'));
  await new Promise(resolve => setTimeout(resolve, 150));
  assert.equal(await page.locator('.biomie-floating-scroll:visible').count(), 0);
});

test('internally scrolling finance owner does not duplicate its accessible native bar', async t => {
  const page = await setup(t, { scenario: 'internal' });
  assert.ok(await page.locator('#source').evaluate(element => element.scrollHeight > element.clientHeight));
  await new Promise(resolve => setTimeout(resolve, 150));
  const state = await nativeScrollbarState(page);
  const nativeHidden = state.scrollbarWidth === 'none';
  if (nativeHidden) t.diagnostic(`Native scrollbar suppressed; floating access required: ${JSON.stringify(state)}`);
  assert.equal(await page.locator('.biomie-floating-scroll:visible').count(), nativeHidden ? 1 : 0);
  if (nativeHidden) {
    const bar = await visibleFloating(page);
    await assertSync(page, bar);
    await assertBounded(page, bar);
    assert.equal(await bar.locator('[role=scrollbar]').getAttribute('tabindex'), '0');
  }
});

for (const scenario of ['clipped', 'panel', 'nested', 'nf', 'flight', 'dashboard', 'nested-table', 'optin', 'table-source']) {
  test(`discovery and clipping: ${scenario}`, async t => {
    const page = await setup(t, { scenario });
    const bar = await visibleFloating(page);
    await assertSync(page, bar);
    await assertBounded(page, bar);
    if (scenario === 'clipped') await assertBounded(page, bar, '#clip');
    if (['panel', 'nested', 'nf'].includes(scenario)) {
      const container = scenario === 'nf' ? '#nfPeriodoTableContainer' : '#panel';
      await assertBounded(page, bar, container);
      await page.locator(container).evaluate(element => { element.scrollTop = 180; });
      await until(() => bar.isVisible(), 'Bar remains accessible after internal vertical scroll');
      await assertBounded(page, bar, container);
    }
    if (scenario === 'nested-table') assert.equal(await page.locator('.biomie-floating-scroll:visible').count(), 1, 'One bar per actual scroll owner');
    if (scenario === 'optin') assert.equal(await bar.locator('[role=scrollbar]').getAttribute('aria-label'), 'Rolar indicadores');
    assert.equal(await page.locator('#carousel[data-biomie-floating-scroll-source], #tabs[data-biomie-floating-scroll-source]').count(), 0, 'No implicit carousel/tab bars');
  });
}

test('explicit opt-out prevents enhancement', async t => {
  const page = await setup(t, { scenario: 'optout' });
  await new Promise(resolve => setTimeout(resolve, 150));
  assert.equal(await page.locator('.biomie-floating-scroll').count(), 0);
});

test('hidden panel discovers visible data after opening; AJAX replacement cleans old source', async t => {
  const page = await setup(t, { scenario: 'hidden' });
  assert.equal(await page.locator('.biomie-floating-scroll:visible').count(), 0);
  await page.locator('#hidden-panel').evaluate(element => { element.hidden = false; });
  await visibleFloating(page);
  for (let cycle = 0; cycle < 3; cycle += 1) {
    const old = await page.locator('#source').elementHandle();
    await page.locator('#replace-table').click();
    await until(() => old.evaluate(element => !element.isConnected), 'Old AJAX source was replaced');
    await visibleFloating(page);
    assert.equal(await page.locator('.biomie-floating-scroll').count(), 1, 'Removed table does not leak overlays');
  }
});

test('resize adapts dimensions and follows native scrollbar availability after filtering', async t => {
  const page = await setup(t);
  const bar = await visibleFloating(page);
  await page.setViewportSize({ width: 840, height: 560 });
  await until(async () => (await bar.boundingBox())?.x + (await bar.boundingBox())?.width <= 841, 'Bar recomputed on resize');
  await assertBounded(page, bar);
  await page.locator('#source tbody').evaluate(element => { element.innerHTML = element.querySelector('tr').outerHTML; });
  const state = await nativeScrollbarState(page);
  if (state.scrollbarWidth === 'none') {
    await until(() => bar.isVisible(), 'Suppressed native bar requires floating access after filtering');
    await until(async () => { await assertBounded(page, bar); return true; }, 'Floating bar reclips after shorter filter result');
    await assertSync(page, bar);
  } else {
    await until(async () => !await bar.isVisible(), 'Native bar reachable after shorter filter result');
  }
  await page.locator('#source').evaluate(element => { element.innerHTML = window.fixture.table(3, true); });
  await until(async () => !await bar.isVisible(), 'No horizontal overflow never needs floating access');
});

test('keyboard and horizontal wheel use native synchronized scroll without trapping focus', async t => {
  const page = await setup(t);
  const bar = await visibleFloating(page);
  const scroller = bar.locator('[role=scrollbar]');
  await scroller.focus();
  assert.equal(await scroller.getAttribute('aria-orientation'), 'horizontal');
  assert.equal(await scroller.getAttribute('aria-controls'), 'source');
  await page.keyboard.press('ArrowRight');
  await until(async () => await page.locator('#source').evaluate(element => element.scrollLeft) >= 39, 'ArrowRight scrolls');
  await page.keyboard.press('End');
  await until(async () => await page.locator('#source').evaluate(element => Math.abs(element.scrollWidth - element.clientWidth - element.scrollLeft) <= 2), 'End reaches last column');
  await page.keyboard.press('Home');
  await until(async () => await page.locator('#source').evaluate(element => element.scrollLeft) <= 1, 'Home reaches first column');
  await page.keyboard.press('PageDown');
  await until(async () => await page.locator('#source').evaluate(element => element.scrollLeft) > 100, 'PageDown scrolls horizontally');
  await scroller.hover();
  const before = await page.locator('#source').evaluate(element => element.scrollLeft);
  await page.mouse.wheel(180, 0);
  await until(async () => await page.locator('#source').evaluate(element => element.scrollLeft) > before, 'Horizontal wheel/trackpad event synchronizes');
  const now = Number(await scroller.getAttribute('aria-valuenow'));
  assert.ok(now > 0);
  await page.evaluate(() => { const target = document.createElement('button'); target.id = 'fixture-next-focus'; target.textContent = 'Próximo controle'; document.body.appendChild(target); });
  await page.keyboard.press('Tab');
  assert.equal(await page.evaluate(() => document.activeElement.id), 'fixture-next-focus', 'Tab can reach the next control after scrollbar');
});

test('focused bar yields usable focus when filtering removes horizontal overflow', async t => {
  const page = await setup(t);
  const bar = await visibleFloating(page);
  await bar.locator('[role=scrollbar]').focus();
  await page.locator('#source').evaluate(element => { element.innerHTML = window.fixture.table(80, true); });
  await until(async () => !await bar.isVisible(), 'Bar hides after overflow disappears');
  assert.equal(await page.evaluate(() => Boolean(document.activeElement?.closest('.biomie-floating-scroll'))), false, 'Focus must not remain in hidden overlay');
});

for (const scenario of ['drawer', 'plain-drawer', 'hidden-drawer']) {
  test(`drawer portal, transform and internal scroll: ${scenario}`, async t => {
    const page = await setup(t, { scenario });
    if (scenario === 'hidden-drawer') {
      assert.equal(await page.locator('.biomie-floating-scroll:visible').count(), 0);
      await page.locator('#fixture-drawer').evaluate(element => element.classList.add('open', 'is-open'));
    }
    const source = scenario === 'plain-drawer' ? '#drawer-body > .biomie-floating-scroll-source-wrap' : '#source';
    const bar = await visibleFloating(page, source);
    assert.equal(await bar.evaluate(element => Boolean(element.closest('#fixture-drawer'))), true, 'Portal stays inside drawer stacking/focus context');
    await assertBounded(page, bar, '#drawer-body');
    await assertSync(page, bar, source);
    if (scenario === 'plain-drawer') {
      assert.equal(await page.evaluate(() => document.getElementById('plain-table') === window.fixtureOriginalTable), true, 'Original table node and ID are retained');
      assert.equal(await page.locator('#drawer-body').evaluate(element => element.scrollLeft), 0, 'Drawer body does not scroll filters horizontally');
      assert.equal(await page.locator('#drawer-filters').evaluate(element => element.scrollLeft), 0);
      assert.equal(await page.locator('.biomie-drawer__header').evaluate(element => element.scrollLeft), 0);
      await page.locator('#plain-table').evaluate(element => element.dispatchEvent(new MouseEvent('click', { bubbles: true })));
      assert.equal(await page.evaluate(() => window.fixtureTableClicks), 1, 'Pre-existing table listeners survive');
      await page.evaluate(() => { for (let cycle = 0; cycle < 5; cycle += 1) { window.BiomieFloatingScroll.init(); window.BiomieFloatingScroll.refresh(); } });
      await page.waitForTimeout(80);
      assert.equal(await page.locator('.biomie-floating-scroll-source-wrap').count(), 1, 'One controlled wrapper');
      assert.equal(await page.locator('.biomie-floating-scroll-source-wrap .biomie-floating-scroll-source-wrap').count(), 0, 'No nested controlled wrappers');
    }
    await page.locator('#drawer-body').evaluate(element => { element.scrollTop = 500; });
    await until(() => bar.isVisible(), 'Drawer scroll preserves floating access');
    await assertBounded(page, bar, '#drawer-body');
    await page.locator('#drawer-close').click();
    await until(async () => !await bar.isVisible(), 'Closed drawer never leaves a floating bar');
    if (scenario === 'plain-drawer') {
      await page.evaluate(() => window.BiomieFloatingScroll.destroy());
      assert.equal(await page.locator('.biomie-floating-scroll-source-wrap').count(), 0, 'Destroy removes only its controlled wrapper');
      assert.equal(await page.evaluate(() => document.getElementById('plain-table') === window.fixtureOriginalTable && window.fixtureOriginalTable.parentElement.id === 'drawer-body'), true, 'Destroy restores original table parent and node');
      await page.locator('#plain-table').evaluate(element => element.dispatchEvent(new MouseEvent('click', { bubbles: true })));
      assert.equal(await page.evaluate(() => window.fixtureTableClicks), 2);
    }
  });
}

function intersects(a, b) { return a && b && a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y; }

test('mobile bottom navigation and explicit fixed footer are not covered', async t => {
  const page = await setup(t, { viewport: { width: 390, height: 844 }, hasTouch: true });
  let bar = await visibleFloating(page);
  assert.equal(intersects(await bar.boundingBox(), await page.locator('#fixture-bottom-nav').boundingBox()), false);
  await page.evaluate(() => window.fixture.render('footer'));
  bar = await visibleFloating(page);
  assert.equal(intersects(await bar.boundingBox(), await page.locator('#fixed-footer').boundingBox()), false);
  await page.setViewportSize({ width: 390, height: 500 });
  await until(async () => !intersects(await bar.boundingBox(), await page.locator('#fixed-footer').boundingBox()), 'Footer clearance persists after height resize');
});

test('open menu keeps controls uncovered; native modal keeps its portal in top layer', async t => {
  const page = await setup(t);
  const bar = await visibleFloating(page);
  await page.locator('#toggle-menu').click();
  await until(async () => !intersects(await bar.boundingBox(), await page.locator('#fixture-menu').boundingBox()), 'Menu is never covered by floating controls');
  await page.locator('#toggle-menu').click();
  await page.locator('#toggle-modal').click();
  const modalBar = await visibleFloating(page, '#modal-source');
  assert.equal(await modalBar.evaluate(element => Boolean(element.closest('dialog'))), true);
  await assertBounded(page, modalBar, '#fixture-modal');
  await modalBar.locator('[role=scrollbar]').focus();
  assert.equal(await page.evaluate(() => document.activeElement.closest('dialog')?.id), 'fixture-modal');
  await page.locator('#close-modal').click();
  await until(async () => !await modalBar.isVisible(), 'Closed modal clears bar');
});

test('touch swipe uses native scrolling and synchronizes table', { skip: browserName !== 'chromium' }, async t => {
  const page = await setup(t, { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
  const bar = await visibleFloating(page);
  const box = await bar.locator('.biomie-floating-scroll__viewport').boundingBox();
  const session = await page.context().newCDPSession(page);
  const y = box.y + box.height / 2;
  await session.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: box.x + box.width * 0.8, y }] });
  for (let step = 1; step <= 8; step += 1) {
    await session.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: box.x + box.width * (0.8 - step * 0.07), y }] });
    await page.waitForTimeout(20);
  }
  await session.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await until(async () => await page.locator('#source').evaluate(element => element.scrollLeft) > 20, 'Native synthesized touch swipe scrolls table');
  await session.detach();
});

test('init/refresh/destroy are idempotent; repeated initialization does not duplicate bars', async t => {
  const page = await setup(t);
  await visibleFloating(page);
  await page.evaluate(() => { for (let count = 0; count < 5; count += 1) { window.BiomieFloatingScroll.init(); window.BiomieFloatingScroll.refresh(); } });
  assert.equal(await page.locator('.biomie-floating-scroll').count(), 1);
  await page.evaluate(() => { window.BiomieFloatingScroll.destroy(); window.BiomieFloatingScroll.destroy(); });
  assert.equal(await page.locator('.biomie-floating-scroll').count(), 0);
  await page.evaluate(() => window.BiomieFloatingScroll.init());
  await visibleFloating(page);
  assert.equal(await page.locator('.biomie-floating-scroll').count(), 1);
});

test('controlled wrapper preserves explicit off across scans; off/on and destroy/reinit never duplicate wrappers', async t => {
  const page = await setup(t, { scenario: 'plain-drawer' });
  const source = '#drawer-body > .biomie-floating-scroll-source-wrap';
  let bar = await visibleFloating(page, source);
  await page.locator(source).evaluate(element => element.setAttribute('data-biomie-floating-scroll', 'off'));
  await until(async () => !await bar.isVisible(), 'Explicit off disables controlled wrapper');
  await page.evaluate(() => { for (let count = 0; count < 5; count += 1) window.BiomieFloatingScroll.refresh(); });
  await page.waitForTimeout(100);
  assert.equal(await page.locator('.biomie-floating-scroll:visible').count(), 0, 'Rescan does not silently undo off');
  assert.equal(await page.locator(source).getAttribute('data-biomie-floating-scroll'), 'off', 'Explicit off remains attached');
  await page.locator(source).evaluate(element => element.setAttribute('data-biomie-floating-scroll', 'on'));
  bar = await visibleFloating(page, source);
  await assertSync(page, bar, source);
  await page.evaluate(() => { window.BiomieFloatingScroll.destroy(); window.BiomieFloatingScroll.init(); window.BiomieFloatingScroll.refresh(); });
  await visibleFloating(page, source);
  assert.equal(await page.locator('.biomie-floating-scroll-source-wrap').count(), 1);
  assert.equal(await page.locator('.biomie-floating-scroll-source-wrap .biomie-floating-scroll-source-wrap').count(), 0);
  assert.equal(await page.evaluate(() => document.getElementById('plain-table') === window.fixtureOriginalTable), true);
});

test('ResizeObserver follows container-only width changes without a window resize', async t => {
  const page = await setup(t);
  let bar = await visibleFloating(page);
  await page.evaluate(() => { window.fixtureWindowResizes = 0; window.addEventListener('resize', () => { window.fixtureWindowResizes += 1; }); });
  const originalWidth = (await bar.boundingBox()).width;
  await page.locator('#fixture-slot').evaluate(element => { element.style.width = '700px'; });
  await until(async () => (await bar.boundingBox())?.width < originalWidth - 200, 'Container shrink resizes floating bar');
  await assertBounded(page, bar);
  await page.locator('#fixture-slot').evaluate(element => { element.style.width = '1000px'; });
  await until(async () => (await bar.boundingBox())?.width > 900, 'Container growth resizes floating bar');
  await assertBounded(page, bar);
  assert.equal(await page.evaluate(() => window.fixtureWindowResizes), 0, 'No window resize was synthesized');
});

test('idle module does not keep scheduling animation frames after layout settles', async t => {
  const page = await setup(t, { scenario: 'plain-drawer', initScript: () => {
    const nativeRequest = window.requestAnimationFrame.bind(window);
    window.fixtureRafCalls = 0;
    window.requestAnimationFrame = callback => nativeRequest(time => { window.fixtureRafCalls += 1; callback(time); });
  } });
  await visibleFloating(page, '#drawer-body > .biomie-floating-scroll-source-wrap');
  await page.waitForTimeout(1700); // Includes drawer transition and its bounded tracking period.
  const baseline = await page.evaluate(() => window.fixtureRafCalls);
  await page.waitForTimeout(500);
  const extra = await page.evaluate(() => window.fixtureRafCalls) - baseline;
  assert.ok(extra <= 2, `Idle scheduled ${extra} animation callbacks; expected at most two residual callbacks`);
});
