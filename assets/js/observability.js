(function () {
  const endpoint = 'api/operational_observability.php';

  const state = {
    hours: 24,
  };
  const LOADING_MIN_MS = 420;
  let loadingStartedAt = 0;

  const els = {
    loading: document.getElementById('obsLoading'),
    error: document.getElementById('obsError'),
    refresh: document.getElementById('obsRefreshButton'),
    windowHours: document.getElementById('obsWindowHours'),
    generatedAt: document.getElementById('obsGeneratedAt'),
    traceId: document.getElementById('obsTraceId'),
    pendingTotal: document.getElementById('obsPendingTotal'),
    shadowCritical: document.getElementById('obsShadowCritical'),
    divergenceTotal: document.getElementById('obsDivergenceTotal'),
    progressFill: document.getElementById('obsProgressFill'),
    cardPending: document.getElementById('cardPending'),
    cardPendingAge: document.getElementById('cardPendingAge'),
    cardFailedSpans: document.getElementById('cardFailedSpans'),
    cardShadow: document.getElementById('cardShadow'),
    cardStale: document.getElementById('cardStale'),
    datasetsBody: document.getElementById('obsDatasetsBody'),
    jobsBody: document.getElementById('obsJobsBody'),
    omieBody: document.getElementById('obsOmieBody'),
    rulesBody: document.getElementById('obsRulesBody'),
    shadowBody: document.getElementById('obsShadowBody'),
    failuresBody: document.getElementById('obsFailuresBody'),
  };

  function fmtDate(value) {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString('pt-BR');
  }

  function fmtSeconds(value) {
    if (value == null || value === '') return '-';
    const seconds = Number(value) || 0;
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min`;
    const hours = (minutes / 60).toFixed(1);
    return `${hours} h`;
  }

  function fmtMs(value) {
    if (value == null || value === '') return '-';
    return `${Number(value).toFixed(0)} ms`;
  }

  function setRows(target, rows) {
    if (!target) return;
    target.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="6">Sem dados recentes.</td></tr>';
  }

  function waitFor(ms) {
    return new Promise((resolve) => {
      window.setTimeout(resolve, Math.max(0, ms));
    });
  }

  function render(payload) {
    const dashboard = payload.dashboard || {};
    const pipeline = dashboard.jobs?.pipeline?.webhook_inbox || {};
    const pendingTotal = pipeline.pending_total || 0;
    const oldestPendingAge = pipeline.oldest_pending_age_seconds || 0;
    const shadowCritical = dashboard.shadow?.critical_open_total || 0;
    const activeDivergences = dashboard.divergences?.summary?.active_total || 0;
    const datasets = Array.isArray(dashboard.datasets) ? dashboard.datasets : [];
    const staleCount = datasets.filter((row) => row.data_mode === 'stale').length;
    const failedSpans = (dashboard.recent_failures?.failed_spans || []).length;

    if (els.loading) els.loading.classList.add('d-none');
    if (els.error) els.error.classList.add('d-none');

    if (els.windowHours) els.windowHours.textContent = `${dashboard.window_hours || state.hours}h`;
    if (els.generatedAt) els.generatedAt.textContent = fmtDate(dashboard.generated_at);
    if (els.traceId) els.traceId.textContent = dashboard.trace?.trace_id || '-';
    if (els.pendingTotal) els.pendingTotal.textContent = String(pendingTotal);
    if (els.shadowCritical) els.shadowCritical.textContent = String(shadowCritical);
    if (els.divergenceTotal) els.divergenceTotal.textContent = String(activeDivergences);
    if (els.cardPending) els.cardPending.textContent = String(pendingTotal);
    if (els.cardPendingAge) els.cardPendingAge.textContent = `Mais antigo: ${fmtSeconds(oldestPendingAge)}`;
    if (els.cardFailedSpans) els.cardFailedSpans.textContent = String(failedSpans);
    if (els.cardShadow) els.cardShadow.textContent = String(shadowCritical);
    if (els.cardStale) els.cardStale.textContent = String(staleCount);
    if (els.progressFill) {
      const totalPressure = Math.min(100, (shadowCritical * 10) + (activeDivergences * 2) + Math.min(30, staleCount * 10));
      els.progressFill.style.width = `${totalPressure}%`;
    }

    setRows(els.datasetsBody, datasets.map((row) => `
      <tr>
        <td>${row.dataset || '-'}</td>
        <td>${row.data_mode || '-'}</td>
        <td>${fmtSeconds(row.freshness_lag_seconds)}</td>
        <td>${row.reconcile_status || '-'}</td>
        <td>${row.staleness_reason || '-'}</td>
        <td>${row.open_divergences ?? 0}</td>
      </tr>
    `));

    const jobs = dashboard.jobs?.by_domain || [];
    setRows(els.jobsBody, jobs.map((row) => `
      <tr>
        <td>${row.domain || '-'}</td>
        <td>${row.last_status || '-'}</td>
        <td>${row.runs ?? 0}</td>
        <td>${fmtMs(row.avg_duration_ms)}</td>
        <td>${fmtMs(row.p95_duration_ms)}</td>
        <td>${fmtDate(row.last_finished_at)}</td>
      </tr>
    `));

    const omieMethods = dashboard.omie_methods || [];
    setRows(els.omieBody, omieMethods.map((row) => `
      <tr>
        <td>${row.call || '-'}</td>
        <td>${row.requests ?? 0}</td>
        <td>${row.errors ?? 0}</td>
        <td>${fmtMs(row.avg_ms)}</td>
        <td>${fmtMs(row.p95_ms)}</td>
      </tr>
    `));

    const rules = dashboard.divergences?.by_rule || [];
    setRows(els.rulesBody, rules.slice(0, 12).map((row) => {
      const severities = Object.keys(row.by_severity || {}).map((key) => `${key}:${row.by_severity[key]}`).join(' | ') || '-';
      return `
        <tr>
          <td>${row.rule_code || '-'}</td>
          <td>${row.total ?? 0}</td>
          <td>${severities}</td>
        </tr>
      `;
    }));

    const shadowEndpoints = dashboard.shadow?.by_endpoint || [];
    setRows(els.shadowBody, shadowEndpoints.slice(0, 12).map((row) => {
      const sev = row.by_severity || {};
      const critical = (sev.high || 0) + (sev.critical || 0);
      return `
        <tr>
          <td>${row.endpoint || '-'}</td>
          <td>${row.total ?? 0}</td>
          <td>${critical}</td>
        </tr>
      `;
    }));

    const failedSpansRows = dashboard.recent_failures?.failed_spans || [];
    const errorEventsRows = dashboard.recent_failures?.error_events || [];
    const combined = []
      .concat(failedSpansRows.map((row) => ({
        type: 'span',
        name: row.span_name,
        status: row.status_name,
        correlation: row.correlation_id,
        when: row.started_at,
      })))
      .concat(errorEventsRows.map((row) => ({
        type: 'event',
        name: row.event_name,
        status: row.level_name,
        correlation: row.correlation_id,
        when: row.occurred_at,
      })))
      .slice(0, 20);

    setRows(els.failuresBody, combined.map((row) => `
      <tr>
        <td>${row.type || '-'}</td>
        <td>${row.name || '-'}</td>
        <td>${row.status || '-'}</td>
        <td><code>${row.correlation || '-'}</code></td>
        <td>${fmtDate(row.when)}</td>
      </tr>
    `));
  }

  async function load() {
    if (els.loading) {
      loadingStartedAt = performance.now();
      els.loading.classList.remove('d-none');
    }
    if (els.error) els.error.classList.add('d-none');

    try {
      const response = await fetch(`${endpoint}?hours=${encodeURIComponent(state.hours)}`, {
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
        },
      });

      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Falha ao carregar observabilidade.');
      }

      const elapsed = performance.now() - loadingStartedAt;
      if (elapsed < LOADING_MIN_MS) {
        await waitFor(LOADING_MIN_MS - elapsed);
      }
      render(payload);
    } catch (error) {
      if (els.loading) {
        const elapsed = performance.now() - loadingStartedAt;
        if (elapsed < LOADING_MIN_MS) {
          await waitFor(LOADING_MIN_MS - elapsed);
        }
        els.loading.classList.add('d-none');
      }
      if (els.error) {
        els.error.classList.remove('d-none');
        els.error.textContent = error?.message || 'Falha ao carregar observabilidade.';
      }
    }
  }

  if (els.refresh) {
    els.refresh.addEventListener('click', load);
  }

  load();
})();
