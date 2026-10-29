'use strict';

// Test-only dependency, installed outside the application:
// PLAYWRIGHT_MODULE=/absolute/path/to/playwright node --test tests/browser/floating-scroll-native-zoom.test.cjs
// Optional screenshots: FLOATING_SCROLL_ARTIFACTS=/path/to/artifacts
// Native browser zoom uses a temporary MV3 extension in an isolated persistent Chromium profile.
// https://playwright.dev/docs/chrome-extensions
// https://developer.chrome.com/docs/extensions/reference/api/tabs#method-setZoom
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { startFixtureServer, fixturePath } = require('./support.cjs');

const modulePath = process.env.PLAYWRIGHT_MODULE;
if (!modulePath) {
  throw new Error('Set PLAYWRIGHT_MODULE to the test-only Playwright installation; no production dependency is required.');
}
const { chromium } = require(modulePath);
const artifacts = process.env.FLOATING_SCROLL_ARTIFACTS;
const temporaryPrefix = 'biomie-floating-native-zoom-';

async function until(check, description, timeout = 7000) {
  const deadline = Date.now() + timeout;
  let last;
  while (Date.now() < deadline) {
    try { if (await check()) return; } catch (error) { last = error; }
    await new Promise(resolve => setTimeout(resolve, 40));
  }
  throw new Error(`${description}${last ? ': ' + last.message : ''}`);
}

function removeOwnedTemporaryDirectory(directory) {
  // Resolve and check the final absolute target before any recursive cleanup, including Windows.
  const tempRoot = fs.realpathSync(os.tmpdir());
  const target = fs.realpathSync(directory);
  const relative = path.relative(tempRoot, target);
  assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative), 'Cleanup stays inside the OS temporary directory');
  assert.ok(path.basename(target).startsWith(temporaryPrefix), 'Cleanup targets this test profile only');
  fs.rmSync(target, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}

function createTestExtension(directory) {
  fs.mkdirSync(directory);
  fs.writeFileSync(path.join(directory, 'manifest.json'), JSON.stringify({
    manifest_version: 3,
    name: 'BIOMIE isolated native zoom test',
    version: '1.0.0',
    // Only the ephemeral localhost fixture is visible to this test extension.
    host_permissions: ['http://127.0.0.1/*'],
    background: { service_worker: 'background.js' },
  }));
  fs.writeFileSync(path.join(directory, 'background.js'),
    "chrome.runtime.onInstalled.addListener(() => {});\n");
}

async function nativeZoom(worker, page, factor) {
  const actual = await worker.evaluate(async ({ url, zoom }) => {
    const tabs = await chrome.tabs.query({});
    const tab = tabs.find(candidate => candidate.url === url);
    if (!tab || tab.id == null) throw new Error('The isolated localhost fixture tab was not found.');
    await chrome.tabs.setZoomSettings(tab.id, { mode: 'automatic', scope: 'per-tab' });
    await chrome.tabs.setZoom(tab.id, zoom);
    return chrome.tabs.getZoom(tab.id);
  }, { url: page.url(), zoom: factor });
  assert.ok(Math.abs(actual - factor) < 0.001, `Chromium reports native zoom ${factor}`);
}

async function metrics(page) {
  return page.evaluate(() => ({
    innerWidth: window.innerWidth,
    innerHeight: window.innerHeight,
    outerWidth: window.outerWidth,
    dpr: window.devicePixelRatio,
    cssZoom: getComputedStyle(document.body).zoom,
    viewportScale: window.visualViewport ? window.visualViewport.scale : 1,
  }));
}

async function floating(page) {
  await until(() => page.locator('#source').getAttribute('data-biomie-floating-scroll-source'), 'The table owner is discovered');
  const id = await page.locator('#source').getAttribute('data-biomie-floating-scroll-source');
  const bar = page.locator(`.biomie-floating-scroll[data-biomie-floating-scroll-for="${id}"]`);
  try {
    await until(() => bar.isVisible(), 'The floating scrollbar is visible at native zoom');
  } catch (error) {
    const state = await page.evaluate(() => {
      const selectors = ['#source', '#source table', '#source table thead', '#fixture-slot', '#fixture-drawer', '#drawer-body',
        '.fixture-drawer-footer', '.mag-bottom-nav', '#fixture-drawer .biomie-floating-scroll-layer'];
      const elements = selectors.map(selector => {
        const node = document.querySelector(selector);
        if (!node) return { selector, missing: true };
        const r = node.getBoundingClientRect();
        const s = getComputedStyle(node);
        return { selector, x: r.x, y: r.y, width: r.width, height: r.height, clientWidth: node.clientWidth, scrollWidth: node.scrollWidth,
          clientHeight: node.clientHeight, offsetWidth: node.offsetWidth, offsetHeight: node.offsetHeight,
          display: s.display, transform: s.transform, overflowX: s.overflowX, overflowY: s.overflowY };
      });
      const body = document.querySelector('#drawer-body');
      const source = document.querySelector('#source');
      const points = [];
      if (body && source) {
        const b = body.getBoundingClientRect();
        const s = source.getBoundingClientRect();
        for (const x of [s.left + 5, s.left + s.width / 2, s.right - 5]) {
          for (const y of [b.bottom - 26, b.bottom - 14, b.bottom - 4]) {
            points.push({ x, y, elements: document.elementsFromPoint(x, y).slice(0, 7)
              .map(node => `${node.tagName}#${node.id}.${node.className}`) });
          }
        }
      }
      const bars = Array.from(document.querySelectorAll('.biomie-floating-scroll')).map(node => ({
        source: node.getAttribute('data-biomie-floating-scroll-for'), hidden: node.hidden,
        range: node.querySelector('.biomie-floating-scroll__viewport').getAttribute('aria-valuemax'),
      }));
      return { elements, points, bars };
    });
    error.message += `; fixture geometry: ${JSON.stringify(state)}`;
    throw error;
  }
  return bar;
}

async function assertBounded(page, bar, { drawer = false } = {}) {
  // Resize/scroll observers update geometry on animation frames, rather than synchronously.
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const boxes = await bar.evaluate((element, useDrawer) => {
    const rect = node => {
      const r = node.getBoundingClientRect();
      return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
    };
    const source = document.querySelector('#source');
    const bounds = [rect(source), { left: 0, top: 0, right: innerWidth, bottom: innerHeight }];
    if (window.visualViewport) bounds.push({ left: visualViewport.offsetLeft, top: visualViewport.offsetTop,
      right: visualViewport.offsetLeft + visualViewport.width, bottom: visualViewport.offsetTop + visualViewport.height });
    if (useDrawer) bounds.push(rect(document.querySelector('#drawer-body')));
    const nav = document.querySelector('.mag-bottom-nav');
    const footer = useDrawer ? document.querySelector('.fixture-drawer-footer') : null;
    const obstacles = [nav, footer].filter(node => node && node.getClientRects().length)
      .map(rect).filter(r => r.top < innerHeight && r.bottom > 0);
    return { bar: rect(element), bounds, obstacles };
  }, drawer);
  assert.ok(boxes.bar.width > 20 && boxes.bar.height > 0, 'Scrollbar has usable dimensions');
  for (const bound of boxes.bounds) {
    assert.ok(boxes.bar.left >= Math.max(0, bound.left) - 2, 'Scrollbar starts inside the visible owner');
    assert.ok(boxes.bar.right <= bound.right + 2, `Scrollbar ends inside the visible owner: ${JSON.stringify({ bar: boxes.bar, bound })}`);
    assert.ok(boxes.bar.top >= Math.max(0, bound.top) - 2, 'Scrollbar stays below the visible upper boundary');
    assert.ok(boxes.bar.bottom <= bound.bottom + 2, 'Scrollbar stays above the visible lower boundary');
  }
  for (const obstacle of boxes.obstacles) {
    const overlapsX = boxes.bar.right > obstacle.left && boxes.bar.left < obstacle.right;
    const overlapsY = boxes.bar.bottom > obstacle.top + 1 && boxes.bar.top < obstacle.bottom - 1;
    assert.ok(!overlapsX || !overlapsY, 'Scrollbar does not cover bottom navigation or drawer actions');
  }
}

async function assertSync(page, bar) {
  const track = bar.locator('.biomie-floating-scroll__viewport');
  await page.locator('#source').evaluate(element => { element.scrollLeft = (element.scrollWidth - element.clientWidth) * 0.37; });
  await until(async () => {
    const source = await page.locator('#source').evaluate(element => ({ value: element.scrollLeft, max: element.scrollWidth - element.clientWidth }));
    return track.evaluate((element, owner) => Math.abs(element.scrollLeft - owner.value / owner.max * (element.scrollWidth - element.clientWidth)) <= 2, source);
  },
    'Native scrolling synchronizes the floating scrollbar');
  await track.evaluate(element => { element.scrollLeft = (element.scrollWidth - element.clientWidth) * 0.61; });
  await until(async () => {
    const floating = await track.evaluate(element => ({ value: element.scrollLeft, max: element.scrollWidth - element.clientWidth }));
    return page.locator('#source').evaluate((element, scrollbar) => Math.abs(element.scrollLeft - scrollbar.value / scrollbar.max * (element.scrollWidth - element.clientWidth)) <= 2, floating);
  },
    'Floating scrolling synchronizes the native table');
  await track.evaluate(element => { element.scrollLeft = element.scrollWidth; });
  await until(() => page.locator('#source').evaluate(element =>
    Math.abs(element.scrollLeft - (element.scrollWidth - element.clientWidth)) <= 2),
  'The floating control reaches the rightmost source columns');
}

test('Chromium native zoom 100%, 125%, 150% preserves floating-scroll geometry and synchronization', { timeout: 180000 }, async t => {
  const tempRoot = fs.mkdtempSync(path.join(fs.realpathSync(os.tmpdir()), temporaryPrefix));
  const extension = path.join(tempRoot, 'extension');
  let server;
  let context;
  try {
    createTestExtension(extension);
    server = await startFixtureServer();
    for (const viewport of [{ width: 1920, height: 1080 }, { width: 1366, height: 768 }, { width: 900, height: 600 }]) {
      context = await chromium.launchPersistentContext(path.join(tempRoot, `profile-${viewport.width}`), {
        ignoreDefaultArgs: ['--hide-scrollbars'],
        channel: 'chromium', headless: true, viewport: null, reducedMotion: 'reduce',
        args: [`--window-size=${viewport.width},${viewport.height}`, `--disable-extensions-except=${extension}`, `--load-extension=${extension}`],
      });
      // The suite never requests application APIs, external hosts, authentication or production.
      await context.route('**/*', route => {
        const url = new URL(route.request().url());
        return url.origin === server.baseURL ? route.continue() : route.abort();
      });
      let worker = context.serviceWorkers()[0];
      if (!worker) worker = await context.waitForEvent('serviceworker', { timeout: 15000 });

      for (const zoom of [1, 1.25, 1.5]) {
        await t.test(`${viewport.width}x${viewport.height} native zoom ${zoom * 100}%`, async child => {
          const page = await context.newPage();
          const errors = [];
          page.on('pageerror', error => errors.push(error.message));
          child.after(async () => {
            if (artifacts) {
              fs.mkdirSync(artifacts, { recursive: true });
              await page.screenshot({ path: path.join(artifacts, `native-zoom-${viewport.width}-${zoom * 100}.png`) }).catch(() => {});
            }
            await page.close();
            assert.deepEqual(errors, [], 'No unhandled browser errors');
          });
          await page.goto(`${server.baseURL}${fixturePath}?scenario=tall`);
          await page.waitForFunction(() => Boolean(window.BiomieFloatingScroll && window.fixture));
          await nativeZoom(worker, page, 1);
          const baseline = await metrics(page);
          await nativeZoom(worker, page, zoom);
          await until(async () => {
            const current = await metrics(page);
            return Math.abs(current.dpr / baseline.dpr - zoom) < 0.02
              && Math.abs(current.innerWidth - baseline.innerWidth / zoom) <= 2;
          }, 'Native zoom changes DPR and CSS viewport dimensions');
          const current = await metrics(page);
          assert.equal(Number(current.cssZoom), 1, 'No CSS zoom substitutes native browser zoom');
          assert.ok(Math.abs(current.viewportScale - 1) < 0.01, 'No pinch/page-scale emulation substitutes native browser zoom');
          assert.ok(Math.abs(current.innerHeight - baseline.innerHeight / zoom) <= 2, 'Native zoom changes CSS viewport height');
          console.log(JSON.stringify({ nativeZoom: zoom, window: viewport, baseline, actual: current }));
          await page.locator('#source').evaluate(element => {
            window.scrollTo(0, Math.max(0, scrollY + element.getBoundingClientRect().top - 96));
          });
          const bar = await floating(page);
          await assertBounded(page, bar);
          await assertSync(page, bar);
          await page.evaluate(() => window.scrollTo(0, 700));
          await until(() => bar.isVisible(), 'Scrollbar follows the visible table after vertical scrolling');
          await assertBounded(page, bar);
          await assertSync(page, bar);

          // Exercise the transformed drawer containing block at the highest native zoom.
          if (zoom === 1.5) {
            await page.evaluate(() => {
              window.scrollTo(0, 0);
              window.fixture.render('drawer');
              // Real 360 drawers use is-open; mobile CSS deliberately requires that state.
              document.querySelector('#fixture-drawer').classList.add('is-open');
              // In the reduced window at 150%, move past the header into visible data rows.
              document.querySelector('#drawer-body').scrollTop = 140;
            });
            const drawerBar = await floating(page);
            await assertBounded(page, drawerBar, { drawer: true });
            await assertSync(page, drawerBar);
            await page.locator('#drawer-body').evaluate(element => { element.scrollTop = 500; });
            await until(() => drawerBar.isVisible(), 'Drawer scrollbar follows internal vertical scrolling');
            await assertBounded(page, drawerBar, { drawer: true });
          }
        });
      }
      await context.close();
      context = null;
    }
  } finally {
    try {
      if (context) await context.close();
    } finally {
      try { if (server) await server.close(); }
      finally { removeOwnedTemporaryDirectory(tempRoot); }
    }
  }
});
