'use strict';

// Exercise Chromium's real native scrollbar thumb, not a JS scrollLeft simulation.
// Playwright normally hides headless Chromium scrollbars; keep them visible here.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { startFixtureServer, fixturePath } = require('./support.cjs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

let browser;
let server;

before(async () => {
  server = await startFixtureServer();
  browser = await chromium.launch({ headless: true, ignoreDefaultArgs: ['--hide-scrollbars'] });
});

after(async () => {
  if (browser) await browser.close();
  if (server) await server.close();
});

async function until(check, message) {
  const deadline = Date.now() + 5000;
  while (Date.now() < deadline) {
    if (await check()) return;
    await new Promise(resolve => setTimeout(resolve, 40));
  }
  assert.fail(message);
}

async function screenshot(page, scenario, position) {
  if (!process.env.FLOATING_SCROLL_ARTIFACTS) return;
  fs.mkdirSync(process.env.FLOATING_SCROLL_ARTIFACTS, { recursive: true });
  await page.screenshot({ path: path.join(process.env.FLOATING_SCROLL_ARTIFACTS,
    `chromium-native-pointer-${scenario}-${position}.png`) });
}

for (const scenario of ['tall', 'drawer', 'contained-drawer', 'modal']) {
  test(`native mouse thumb drag reaches both table edges in ${scenario}`, async t => {
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    t.after(async () => {
      await context.close();
      assert.deepEqual(errors, [], 'No unhandled browser errors');
    });
    const fixtureScenario = scenario === 'modal' ? 'tall' : scenario === 'contained-drawer' ? 'drawer' : scenario;
    await page.goto(`${server.baseURL}${fixturePath}?scenario=${fixtureScenario}`);
    await page.waitForFunction(() => document.querySelector('#source')?.hasAttribute('data-biomie-floating-scroll-source'));
    if (scenario === 'contained-drawer') {
      await page.evaluate(() => {
        const drawer = document.querySelector('#fixture-drawer');
        const block = document.createElement('section');
        block.id = 'fixture-test-containing-block';
        block.style.cssText = 'position:fixed;inset:100px 20px;transform:translateZ(0);overflow:hidden;z-index:1000';
        drawer.before(block);
        block.appendChild(drawer);
      });
    }
    if (scenario === 'modal') {
      await page.evaluate(() => {
        const section = document.querySelector('#fixture-slot');
        section.style.cssText = 'height:100px;overflow:hidden;transform:translateZ(0)';
        section.appendChild(document.querySelector('#fixture-modal'));
      });
      await page.click('#toggle-modal');
      await page.waitForFunction(() => document.querySelector('#modal-source')?.hasAttribute('data-biomie-floating-scroll-source'));
    }
    const source = page.locator(scenario === 'modal' ? '#modal-source' : '#source');
    const id = await source.getAttribute('data-biomie-floating-scroll-source');
    const bar = page.locator(`.biomie-floating-scroll[data-biomie-floating-scroll-for="${id}"]`);
    await until(() => bar.isVisible(), 'A visible floating scrollbar exists');
    if (scenario.endsWith('drawer')) {
      // The fixed drawer escapes its overflow:hidden background page section.
      // Its scrollbar belongs at the drawer body's visible bottom, not that section's bottom.
      await until(async () => {
        const panel = await page.locator('#drawer-body').boundingBox();
        const floating = await bar.boundingBox();
        const block = scenario === 'contained-drawer'
          ? await page.locator('#fixture-test-containing-block').boundingBox() : null;
        const visibleBottom = Math.min(panel ? panel.y + panel.height : 0,
          block ? block.y + block.height : page.viewportSize().height);
        return panel && floating && Math.abs(visibleBottom - floating.y - floating.height) <= 8;
      }, 'The floating bar follows the visible bottom of the fixed drawer, beyond background clipping');
    }
    if (scenario === 'modal') {
      // A top-layer modal escapes even transformed/overflow-clipped DOM ancestors.
      await until(async () => {
        const bottom = await page.locator('#fixture-modal').evaluate(element => {
          const box = element.getBoundingClientRect();
          return box.top + element.clientTop + element.clientHeight;
        });
        const floating = await bar.boundingBox();
        return floating && Math.abs(bottom - floating.y - floating.height) <= 8;
      }, 'The modal scrollbar follows the top-layer dialog bottom beyond its clipped DOM parent');
    }
    const scroller = bar.locator('.biomie-floating-scroll__viewport');
    const geometry = await scroller.evaluate(element => {
      const r = element.getBoundingClientRect();
      return { left: r.left, right: r.right, bottom: r.bottom, width: r.width,
        gutter: element.offsetHeight - element.clientHeight,
        proportion: element.clientWidth / element.scrollWidth };
    });
    assert.ok(geometry.gutter >= 10, 'The native horizontal scrollbar is rendered and hit-testable');
    assert.ok(geometry.proportion < 0.9, 'The native thumb has a useful scroll range');
    // Classic native scrollbars may reserve one gutter-width arrow at each end.
    // The estimated center also lands inside the thumb on arrowless platforms.
    const arrow = geometry.gutter;
    const thumbWidth = (geometry.width - arrow * 2) * geometry.proportion;
    const y = geometry.bottom - geometry.gutter / 2;
    const sourcePosition = () => source.evaluate(element => ({ left: element.scrollLeft,
      max: element.scrollWidth - element.clientWidth }));
    const start = await sourcePosition();
    assert.ok(start.left <= 1, 'The table starts at its left edge');

    await page.mouse.move(geometry.left + arrow + thumbWidth / 2, y);
    await page.mouse.down();
    await page.mouse.move(geometry.right - 2, y, { steps: 24 });
    await page.mouse.up();
    await until(async () => { const p = await sourcePosition(); return p.max - p.left <= 2; },
      'Dragging the actual native thumb reveals the rightmost table columns');
    const lastColumnVisible = await source.evaluate(element => {
      const last = element.querySelector('thead th:last-child').getBoundingClientRect();
      const viewport = element.getBoundingClientRect();
      return last.right <= viewport.right + 3 && last.left >= viewport.left;
    });
    assert.ok(lastColumnVisible, 'The final column is inside the source viewport');
    await screenshot(page, scenario, 'right');

    await page.mouse.move(geometry.right - arrow - thumbWidth / 2, y);
    await page.mouse.down();
    await page.mouse.move(geometry.left + 2, y, { steps: 24 });
    await page.mouse.up();
    await until(async () => (await sourcePosition()).left <= 2,
      'Dragging the actual native thumb back reveals the first table columns');
    await screenshot(page, scenario, 'left');
  });
}
