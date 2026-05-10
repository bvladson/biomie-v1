(function () {
  'use strict';

  const records = [];
  const marks = new Map();
  const slowThresholdMs = 1000;

  function now() {
    return (window.performance && typeof window.performance.now === 'function')
      ? window.performance.now()
      : Date.now();
  }

  function safeName(url) {
    try {
      const parsed = new URL(String(url), window.location.href);
      return parsed.pathname.replace(/^.*\/api\//, 'api/');
    } catch (error) {
      return String(url || 'request');
    }
  }

  function startFetch(url, options) {
    const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    records.push({
      id,
      name: safeName(url),
      url: safeName(url),
      method: String(options?.method || 'GET').toUpperCase(),
      started_at: Date.now(),
      started_ms: now(),
      status: null,
      duration_ms: null,
      ok: null,
      warning: null
    });
    return id;
  }

  function endFetch(id, status, ok) {
    const record = records.find((item) => item.id === id);
    if (!record) return;
    record.status = Number(status || 0);
    record.ok = Boolean(ok);
    record.duration_ms = Math.round((now() - record.started_ms) * 10) / 10;
    if (record.duration_ms > slowThresholdMs) {
      record.warning = 'slow_api';
    }
    window.dispatchEvent(new CustomEvent('biomie:performance-updated', { detail: getSummary() }));
    renderDiagnostics();
  }

  function mark(name) {
    marks.set(String(name), now());
  }

  function measure(name, start, end) {
    const startValue = marks.get(String(start));
    const endValue = end ? marks.get(String(end)) : now();
    if (startValue == null || endValue == null) return null;
    return Math.round((endValue - startValue) * 10) / 10;
  }

  async function measureFetch(name, url, options) {
    const id = startFetch(name || url, options || {});
    try {
      const response = await fetch(url, options || {});
      endFetch(id, response.status, response.ok);
      return response;
    } catch (error) {
      endFetch(id, 0, false);
      throw error;
    }
  }

  function getSummary() {
    const completed = records.filter((record) => record.duration_ms != null);
    const slow = completed.filter((record) => record.warning === 'slow_api');
    return {
      total_requests: completed.length,
      slow_requests: slow.length,
      total_ms: Math.round(completed.reduce((sum, record) => sum + Number(record.duration_ms || 0), 0) * 10) / 10,
      last_request: completed[completed.length - 1] || null,
      requests: completed.slice(-12)
    };
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderDiagnostics(container) {
    const target = container || document.querySelector('[data-performance-diagnostics]');
    if (!target) return;
    const summary = getSummary();
    if (!summary.total_requests) {
      target.hidden = true;
      return;
    }

    const grid = target.querySelector('[data-performance-diagnostics-grid]');
    const list = target.querySelector('[data-performance-diagnostics-list]');
    if (grid) {
      grid.innerHTML = [
        ['Requisicoes API', summary.total_requests],
        ['Requisicoes lentas', summary.slow_requests],
        ['Tempo acumulado API', `${summary.total_ms}ms`],
        ['Ultima API', summary.last_request ? `${summary.last_request.name} (${summary.last_request.duration_ms}ms)` : '-']
      ].map(([label, value]) => `<span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong>`).join('');
    }
    if (list) {
      list.innerHTML = summary.requests.map((record) => (
        `<div class="mag-perf-row${record.warning ? ' is-slow' : ''}">`
        + `<span>${escapeHtml(record.name)}</span>`
        + `<strong>${escapeHtml(record.duration_ms + 'ms')}</strong>`
        + `<em>${escapeHtml(record.status || '-')}</em>`
        + '</div>'
      )).join('');
    }
    target.hidden = false;
  }

  window.BIOMIE_PERF = {
    startFetch,
    endFetch,
    mark,
    measure,
    measureFetch,
    getSummary,
    renderDiagnostics
  };

  document.addEventListener('DOMContentLoaded', function () {
    renderDiagnostics();
  });
})();
