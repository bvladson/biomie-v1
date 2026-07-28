'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = { dataset: {}, style: {} };

global.document = {
  documentElement: root,
  querySelector: () => null,
  querySelectorAll: () => [],
  addEventListener: () => {},
  getElementById: () => null,
};
global.getComputedStyle = () => ({ getPropertyValue: () => '' });
global.CustomEvent = class CustomEvent {
  constructor(type, init) {
    this.type = type;
    this.detail = init && init.detail;
  }
};
global.window = {
  setTimeout,
  clearTimeout,
  dispatchEvent: () => {},
  addEventListener: () => {},
  requestAnimationFrame: () => {},
};

const shellPath = path.resolve(__dirname, '../../assets/js/shell.js');
vm.runInThisContext(fs.readFileSync(shellPath, 'utf8'), { filename: shellPath });

function abortableFetch() {
  global.fetch = function (_url, options) {
    const signal = options && options.signal;
    return new Promise((resolve, reject) => {
      const rejectAbort = () => reject(
        signal.reason instanceof Error
          ? signal.reason
          : new DOMException('aborted', 'AbortError')
      );
      if (signal.aborted) {
        queueMicrotask(rejectAbort);
        return;
      }
      signal.addEventListener('abort', rejectAbort, { once: true });
    });
  };
}

async function run() {
  abortableFetch();
  const superseded = new AbortController();
  const supersededRequest = window.fetchApi('api/test.php', {
    signal: superseded.signal,
    timeoutMs: 1000,
  });
  superseded.abort(new DOMException('superseded', 'AbortError'));
  await assert.rejects(
    supersededRequest,
    (error) => error && error.name === 'AbortError' && error.code !== 408,
    'cancelamento intencional deve continuar sendo AbortError'
  );

  abortableFetch();
  const alreadyAborted = new AbortController();
  alreadyAborted.abort(new DOMException('superseded-before-start', 'AbortError'));
  await assert.rejects(
    window.fetchApi('api/test.php', {
      signal: alreadyAborted.signal,
      timeoutMs: 1000,
    }),
    (error) => error && error.name === 'AbortError' && error.code !== 408,
    'signal abortado antes do fetch deve ser propagado'
  );

  abortableFetch();
  await assert.rejects(
    window.fetchApi('api/test.php', { timeoutMs: 5 }),
    (error) => error && error.code === 408 && error.name === 'Error',
    'timeout real deve ser convertido para erro 408 amigavel'
  );

  let scheduled = false;
  const originalWindowSetTimeout = window.setTimeout;
  window.setTimeout = function () {
    scheduled = true;
    return originalWindowSetTimeout.apply(this, arguments);
  };
  global.fetch = async function () {
    return new Response('{"ok":true}', {
      status: 200,
      headers: { 'content-type': 'application/json' },
    });
  };
  const noTimeout = await window.fetchApi('api/test.php', { timeoutMs: 0 });
  assert.equal(noTimeout.payload.ok, true);
  assert.equal(scheduled, false, 'timeoutMs=0 deve desabilitar o temporizador');
  window.setTimeout = originalWindowSetTimeout;

  process.stdout.write('PASS shell fetch abort contract\n');
}

run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
