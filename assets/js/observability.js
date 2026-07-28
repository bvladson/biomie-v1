(function () {
  'use strict';

  const endpoint = 'api/operational_observability.php';
  const REQUEST_TIMEOUT_MS = 20000;
  const MEMORY_CACHE_MS = 30000;
  const AUTO_REFRESH_MS = 5 * 60 * 1000;
  const AUTO_REFRESH_KEY = 'biomie.observability.auto-refresh.v1';

  const els = {
    root: document.getElementById('obsDashboardRoot'),
    content: document.getElementById('obsContent'),
    loading: document.getElementById('obsLoading'),
    error: document.getElementById('obsError'),
    errorMessage: document.getElementById('obsErrorMessage'),
    retry: document.querySelector('[data-obs-retry]'),
    refresh: document.getElementById('obsRefreshButton'),
    windowSelect: document.getElementById('obsWindowSelect'),
    autoRefresh: document.getElementById('obsAutoRefresh'),
    windowHours: document.getElementById('obsWindowHours'),
    generatedAt: document.getElementById('obsGeneratedAt'),
    cacheStatus: document.getElementById('obsCacheStatus'),
    traceId: document.getElementById('obsTraceId'),
    copyTrace: document.getElementById('obsCopyTrace'),
    healthStatus: document.getElementById('obsHealthStatus'),
    healthIcon: document.getElementById('obsHealthIcon'),
    healthLabel: document.getElementById('obsHealthLabel'),
    healthSummary: document.getElementById('obsHealthSummary'),
    healthCard: document.getElementById('obsHealthCard'),
    healthBadge: document.getElementById('obsHealthBadge'),
    recommendation: document.getElementById('obsRecommendation'),
    priorityList: document.getElementById('obsPriorityList'),
    pressureValue: document.getElementById('obsPressureValue'),
    progressFill: document.getElementById('obsProgressFill'),
    pendingTotal: document.getElementById('obsPendingTotal'),
    shadowCritical: document.getElementById('obsShadowCritical'),
    divergenceTotal: document.getElementById('obsDivergenceTotal'),
    cardPending: document.getElementById('cardPending'),
    cardPendingAge: document.getElementById('cardPendingAge'),
    cardFailedSpans: document.getElementById('cardFailedSpans'),
    cardShadow: document.getElementById('cardShadow'),
    cardStale: document.getElementById('cardStale'),
    kpiPending: document.getElementById('obsKpiPending'),
    kpiFailures: document.getElementById('obsKpiFailures'),
    kpiShadow: document.getElementById('obsKpiShadow'),
    kpiStale: document.getElementById('obsKpiStale'),
    alertsSection: document.getElementById('obsAlertsSection'),
    alertsCount: document.getElementById('obsAlertsCount'),
    alertsNavCount: document.getElementById('obsAlertsNavCount'),
    cardTotalClients: document.getElementById('cardTotalClients'),
    cardGeoCoveragePct: document.getElementById('cardGeoCoveragePct'),
    cardGeoCoverageSub: document.getElementById('cardGeoCoverageSub'),
    cardCnaeCoveragePct: document.getElementById('cardCnaeCoveragePct'),
    cardCnaeCoverageSub: document.getElementById('cardCnaeCoverageSub'),
    cardEnrichedPct: document.getElementById('cardEnrichedPct'),
    cardEnrichedSub: document.getElementById('cardEnrichedSub'),
    coverageClients: document.getElementById('obsCoverageClients'),
    coverageGeo: document.getElementById('obsCoverageGeo'),
    coverageCnae: document.getElementById('obsCoverageCnae'),
    coverageEnriched: document.getElementById('obsCoverageEnriched'),
    tableSearch: document.getElementById('obsTableSearch'),
    levelFilter: document.getElementById('obsLevelFilter'),
    filterSummary: document.getElementById('obsFilterSummary'),
    datasetsBody: document.getElementById('obsDatasetsBody'),
    jobsBody: document.getElementById('obsJobsBody'),
    omieBody: document.getElementById('obsOmieBody'),
    pipelineHealthBody: document.getElementById('obsPipelineHealthBody'),
    rulesBody: document.getElementById('obsRulesBody'),
    shadowBody: document.getElementById('obsShadowBody'),
    latencyBody: document.getElementById('obsLatencyBody'),
    failuresBody: document.getElementById('obsFailuresBody'),
    latencyWindowLabel: document.getElementById('obsLatencyWindowLabel'),
  };

  if (!els.root) return;

  const state = {
    hours: initialHours(),
    controller: null,
    requestSequence: 0,
    hasData: false,
    lastLoadedAt: 0,
    snapshots: new Map(),
    filterText: '',
    level: 'all',
    filterTimer: 0,
    autoTimer: 0,
  };

  function initialHours() {
    const params = new URLSearchParams(window.location.search);
    const value = Number(params.get('obs_hours') || els.windowSelect?.value || 24);
    const allowed = [1, 6, 24, 72, 168, 720];
    return allowed.includes(value) ? value : 24;
  }

  function h(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function number(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? Math.max(0, parsed) : 0;
  }

  function normalize(value) {
    return String(value ?? '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
  }

  function fmtNumber(value) {
    return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 }).format(number(value));
  }

  function fmtDate(value) {
    if (!value) return 'Sem registro';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
  }

  function fmtSeconds(value) {
    if (value == null || value === '') return 'Sem registro';
    const seconds = number(value);
    if (seconds < 60) return `${Math.round(seconds)} s`;
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min`;
    const hours = minutes / 60;
    if (hours < 48) return `${hours.toFixed(hours < 10 ? 1 : 0)} h`;
    return `${Math.round(hours / 24)} dias`;
  }

  function fmtHours(value) {
    if (value == null || value === '') return 'Sem registro';
    const hours = number(value);
    if (hours < 1) return `${Math.round(hours * 60)} min`;
    if (hours < 48) return `${hours.toFixed(hours < 10 ? 1 : 0)} h`;
    return `${Math.round(hours / 24)} dias`;
  }

  function fmtMs(value) {
    if (value == null || value === '') return 'Sem registro';
    const milliseconds = number(value);
    if (milliseconds >= 1000) return `${(milliseconds / 1000).toFixed(milliseconds >= 10000 ? 0 : 1)} s`;
    return `${Math.round(milliseconds)} ms`;
  }

  function fmtPct(value) {
    if (value == null || value === '') return 'Sem dados';
    return `${number(value).toFixed(1).replace('.', ',')}%`;
  }

  function plural(total, singular, pluralForm) {
    return `${fmtNumber(total)} ${number(total) === 1 ? singular : pluralForm}`;
  }

  function setText(target, value) {
    if (target) target.textContent = String(value);
  }

  function setLevel(target, level) {
    if (target) target.dataset.level = level;
  }

  const modeLabels = {
    live: 'Atualizada', fresh: 'Atualizada', ready: 'Atualizada', canonical: 'Canônica',
    cache: 'Em cache', cached: 'Em cache', fallback: 'Contingência', degraded: 'Degradada',
    stale: 'Atrasada', unavailable: 'Indisponível', unknown: 'Desconhecida',
  };
  const statusLabels = {
    ok: 'Concluído', completed: 'Concluído', success: 'Concluído', pronto: 'Em dia',
    running: 'Executando', rodando: 'Executando', pending: 'Pendente', pendente: 'Pendente',
    warning: 'Atenção', failed: 'Falhou', error: 'Erro', atrasado: 'Atrasado',
    incompleto: 'Incompleto', cancelled: 'Cancelado', desconhecido: 'Sem leitura',
    reconciled: 'Conferida', divergent: 'Divergente', open: 'Em aberto',
  };
  const severityLabels = { critical: 'Crítico', high: 'Alto', warning: 'Atenção', medium: 'Médio', info: 'Informativo', low: 'Baixo' };
  const entityLabels = {
    overview: 'Visão comercial', orders: 'Pedidos', vendors: 'Vendedores',
    finance_overview: 'Visão financeira', finance_titles: 'Títulos financeiros',
    reconciliation: 'Conciliação', commercial: 'Comercial', finance: 'Financeiro',
    gold_mart_refresh: 'Atualização das visões', compute_opportunities: 'Cálculo de oportunidades',
    compute_forecasts: 'Cálculo de previsões', enrich_clients_governance: 'Enriquecimento de clientes',
  };
  const reasonLabels = {
    freshness_sla_exceeded: 'Atualização fora do prazo esperado',
    pending_backlog: 'Fila de processamento pendente',
    retry_in_progress: 'Nova tentativa em andamento',
    divergence_open: 'Divergência aguardando conferência',
    source_unavailable: 'Fonte temporariamente indisponível',
    missing_freshness_reference: 'Sem horário de atualização registrado',
  };

  function translated(value, dictionary) {
    const raw = String(value || 'unknown').trim();
    return dictionary[raw.toLowerCase()] || raw.replace(/[_-]+/g, ' ');
  }

  function levelForStatus(value) {
    const status = normalize(value).replace(/\s+/g, '_');
    if (['failed', 'error', 'fatal', 'atrasado', 'incompleto', 'cancelled', 'critical'].includes(status)) return 'critical';
    if (['warning', 'pending', 'pendente', 'running', 'rodando', 'unknown', 'desconhecido', 'stale'].includes(status)) return 'warning';
    if (['ok', 'completed', 'success', 'pronto', 'ready', 'fresh', 'live', 'reconciled'].includes(status)) return 'healthy';
    return 'neutral';
  }

  function badge(label, level) {
    return `<span class="obs-status-badge" data-level="${h(level)}">${h(label)}</span>`;
  }

  function tableRow(cells, level) {
    return {
      level: level || 'neutral',
      html: cells.map((cell) => `<td>${cell}</td>`).join(''),
    };
  }

  function emptyRow(colspan, message, filtered) {
    const className = filtered ? 'obs-empty-row obs-filter-empty' : 'obs-empty-row';
    const icon = filtered ? 'filter_alt_off' : 'inbox';
    return `<tr class="${className}"><td colspan="${colspan}"><span class="material-symbols-rounded" aria-hidden="true">${icon}</span> ${h(message)}</td></tr>`;
  }

  function setRows(target, rows, emptyMessage) {
    if (!target) return;
    const colspan = Number(target.dataset.colspan || 1);
    if (!rows.length) {
      target.innerHTML = emptyRow(colspan, emptyMessage || 'Sem dados recentes.', false);
      return;
    }
    target.innerHTML = rows.map((row) => `<tr class="obs-data-row" data-level="${h(row.level)}">${row.html}</tr>`).join('');
  }

  function setCount(id, total, singular, pluralForm, shown) {
    const target = document.getElementById(id);
    if (!target) return;
    const suffix = shown != null && shown < total ? `${fmtNumber(shown)} de ${fmtNumber(total)} ${pluralForm}` : plural(total, singular, pluralForm);
    target.textContent = suffix;
  }

  function renderDatasets(datasets) {
    const rows = datasets.map((row) => {
      const mode = String(row.data_mode || 'unknown').toLowerCase();
      const open = number(row.open_divergences);
      let level = mode === 'stale' || mode === 'unavailable' ? 'critical' : levelForStatus(mode);
      if (open > 0 && level !== 'critical') level = 'warning';
      const reconcile = translated(row.reconcile_status, statusLabels);
      return tableRow([
        `<strong>${h(translated(row.dataset, entityLabels))}</strong>`,
        badge(translated(mode, modeLabels), levelForStatus(mode)),
        h(fmtSeconds(row.freshness_lag_seconds)),
        badge(reconcile, levelForStatus(row.reconcile_status)),
        h(row.staleness_reason ? translated(row.staleness_reason, reasonLabels) : 'Sem impedimento'),
        open > 0 ? `<strong>${fmtNumber(open)}</strong>` : '0',
      ], level);
    });
    setRows(els.datasetsBody, rows, 'Nenhuma base foi detectada para esta empresa.');
    setCount('obsDatasetsCount', datasets.length, 'base', 'bases');
  }

  function renderJobs(jobs) {
    const rows = jobs.map((row) => {
      const level = levelForStatus(row.last_status);
      return tableRow([
        `<strong>${h(translated(row.domain, entityLabels))}</strong>`,
        badge(translated(row.last_status, statusLabels), level),
        fmtNumber(row.runs),
        h(fmtMs(row.avg_duration_ms)),
        h(fmtMs(row.p95_duration_ms)),
        h(fmtDate(row.last_finished_at)),
      ], level);
    });
    setRows(els.jobsBody, rows, 'Nenhum fluxo foi registrado na janela selecionada.');
    setCount('obsJobsCount', jobs.length, 'fluxo', 'fluxos');
  }

  function renderOmie(methods) {
    const rows = methods.slice(0, 50).map((row) => {
      const requests = number(row.requests);
      const errors = number(row.errors);
      const errorRate = requests > 0 ? (errors / requests) * 100 : 0;
      const level = errorRate > 5 ? 'critical' : errors > 0 ? 'warning' : 'healthy';
      return tableRow([
        `<strong>${h(row.call || 'Método não identificado')}</strong>`,
        fmtNumber(requests),
        errors > 0 ? badge(fmtNumber(errors), level) : '0',
        h(fmtMs(row.avg_ms)),
        h(fmtMs(row.p95_ms)),
      ], level);
    });
    setRows(els.omieBody, rows, 'Nenhuma chamada ao Omie foi registrada.');
    setCount('obsOmieCount', methods.length, 'método', 'métodos', rows.length);
  }

  function renderPipelineHealth(items) {
    const rows = items.map((row) => {
      const level = levelForStatus(row.status);
      const failed = number(row.failed_7d);
      return tableRow([
        `<strong>${h(translated(row.job_name, entityLabels))}</strong>`,
        badge(translated(row.status, statusLabels), level),
        h(fmtDate(row.last_run_at)),
        h(fmtHours(row.age_hours)),
        h(fmtMs(row.duration_ms)),
        fmtNumber(row.runs_7d),
        failed > 0 ? badge(`${fmtNumber(failed)} (${fmtPct(row.error_rate_7d)})`, failed > 2 ? 'critical' : 'warning') : `0 (${fmtPct(row.error_rate_7d)})`,
      ], level === 'healthy' && failed > 0 ? 'warning' : level);
    });
    setRows(els.pipelineHealthBody, rows, 'Nenhum processo de atualização foi registrado.');
    setCount('obsPipelineCount', items.length, 'processo', 'processos');
  }

  function renderRules(rules) {
    const shown = rules.slice(0, 30);
    const rows = shown.map((row) => {
      const severities = row.by_severity && typeof row.by_severity === 'object' ? row.by_severity : {};
      const critical = number(severities.critical) + number(severities.high);
      const warning = number(severities.warning) + number(severities.medium);
      const level = critical > 0 ? 'critical' : warning > 0 || number(row.total) > 0 ? 'warning' : 'healthy';
      const severityText = Object.keys(severities)
        .filter((key) => number(severities[key]) > 0)
        .map((key) => `${translated(key, severityLabels)}: ${fmtNumber(severities[key])}`)
        .join(' · ') || 'Sem classificação';
      return tableRow([
        `<strong>${h(translated(row.rule_code, {}))}</strong>`,
        `<strong>${fmtNumber(row.total)}</strong>`,
        h(severityText),
      ], level);
    });
    setRows(els.rulesBody, rows, 'Nenhuma divergência de regra está ativa.');
    setCount('obsRulesCount', rules.length, 'regra', 'regras', shown.length);
  }

  function renderShadow(items) {
    const shown = items.slice(0, 30);
    const rows = shown.map((row) => {
      const severities = row.by_severity && typeof row.by_severity === 'object' ? row.by_severity : {};
      const critical = number(severities.high) + number(severities.critical);
      const total = number(row.total);
      const level = critical > 0 ? 'critical' : total > 0 ? 'warning' : 'healthy';
      return tableRow([
        `<strong>${h(row.endpoint || 'Origem não identificada')}</strong>`,
        fmtNumber(total),
        critical > 0 ? badge(fmtNumber(critical), 'critical') : '0',
      ], level);
    });
    setRows(els.shadowBody, rows, 'Nenhuma diferença entre leituras foi encontrada.');
    setCount('obsShadowCount', items.length, 'origem', 'origens', shown.length);
  }

  function renderLatency(items, hours) {
    const shown = items.slice(0, 50);
    const rows = shown.map((row) => {
      const errorRate = number(row.error_rate);
      const p95 = number(row.p95_ms);
      const level = errorRate > 5 || p95 > 5000 ? 'critical' : errorRate > 0 || p95 > 2000 ? 'warning' : 'healthy';
      return tableRow([
        `<strong>${h(row.endpoint || 'Endpoint não identificado')}</strong>`,
        h(row.method || 'GET'),
        fmtNumber(row.total),
        number(row.errors) > 0 ? badge(fmtNumber(row.errors), level) : '0',
        errorRate > 0 ? `<strong>${h(fmtPct(errorRate))}</strong>` : '0,0%',
        h(fmtMs(row.avg_ms)),
        h(fmtMs(row.p50_ms)),
        h(fmtMs(row.p95_ms)),
        h(fmtMs(row.max_ms)),
      ], level);
    });
    setRows(els.latencyBody, rows, 'Nenhuma medição de tempo de resposta está disponível.');
    setCount('obsLatencyCount', items.length, 'endpoint', 'endpoints', shown.length);
    setText(els.latencyWindowLabel, `p50 e p95 nas últimas ${hours}h, com destaque para erros acima de 5% ou p95 acima de 5 s.`);
  }

  function renderFailures(recentFailures) {
    const failedSpans = Array.isArray(recentFailures?.failed_spans) ? recentFailures.failed_spans : [];
    const errorEvents = Array.isArray(recentFailures?.error_events) ? recentFailures.error_events : [];
    const combined = failedSpans.map((row) => ({
      type: 'Processamento', name: row.span_name, status: row.status_name,
      correlation: row.correlation_id, when: row.started_at,
    })).concat(errorEvents.map((row) => ({
      type: 'Evento', name: row.event_name, status: row.level_name,
      correlation: row.correlation_id, when: row.occurred_at,
    }))).sort((left, right) => {
      const leftTime = Date.parse(left.when || '') || 0;
      const rightTime = Date.parse(right.when || '') || 0;
      return rightTime - leftTime;
    });
    const shown = combined.slice(0, 40);
    const rows = shown.map((row) => {
      const level = levelForStatus(row.status);
      return tableRow([
        h(row.type),
        `<strong>${h(row.name || 'Ocorrência sem nome')}</strong>`,
        badge(translated(row.status, statusLabels), level),
        `<code title="${h(row.correlation || 'Sem referência')}">${h(row.correlation || 'Sem referência')}</code>`,
        h(fmtDate(row.when)),
      ], level === 'neutral' ? 'warning' : level);
    });
    setRows(els.failuresBody, rows, 'Nenhum erro ou alerta foi registrado na janela.');
    setCount('obsFailuresCount', combined.length, 'ocorrência', 'ocorrências', shown.length);
    return combined.length;
  }

  function renderCoverage(coverage) {
    if (!coverage || typeof coverage !== 'object') {
      setText(els.cardTotalClients, 'Sem dados');
      setText(els.cardGeoCoveragePct, 'Sem dados');
      setText(els.cardCnaeCoveragePct, 'Sem dados');
      setText(els.cardEnrichedPct, 'Sem dados');
      setText(els.cardGeoCoverageSub, 'Fonte indisponível nesta leitura');
      setText(els.cardCnaeCoverageSub, 'Fonte indisponível nesta leitura');
      setText(els.cardEnrichedSub, 'Fonte indisponível nesta leitura');
      [els.coverageClients, els.coverageGeo, els.coverageCnae, els.coverageEnriched].forEach((el) => setLevel(el, 'warning'));
      return false;
    }

    const total = number(coverage.total_clients);
    const geo = number(coverage.geo_coverage_pct);
    const cnae = number(coverage.cnae_coverage_pct);
    const enriched = number(coverage.enriched_pct);
    setText(els.cardTotalClients, fmtNumber(total));
    setText(els.cardGeoCoveragePct, fmtPct(geo));
    setText(els.cardGeoCoverageSub, `${fmtNumber(coverage.geo_covered)} de ${fmtNumber(total)} clientes`);
    setText(els.cardCnaeCoveragePct, fmtPct(cnae));
    setText(els.cardCnaeCoverageSub, `${fmtNumber(coverage.cnae_covered)} clientes classificados`);
    setText(els.cardEnrichedPct, fmtPct(enriched));
    setText(els.cardEnrichedSub, `${fmtNumber(coverage.enriched_covered)} enriquecidos · ${fmtNumber(coverage.active_3m_clients)} ativos em 3 meses`);
    setLevel(els.coverageClients, total > 0 ? 'healthy' : 'warning');
    setLevel(els.coverageGeo, geo >= 70 ? 'healthy' : geo >= 40 ? 'warning' : 'critical');
    setLevel(els.coverageCnae, cnae >= 70 ? 'healthy' : cnae >= 20 ? 'warning' : 'critical');
    setLevel(els.coverageEnriched, enriched >= 70 ? 'healthy' : enriched > 0 ? 'warning' : 'critical');
    return true;
  }

  function renderAlerts(alerts) {
    const active = Array.isArray(alerts) ? alerts : [];
    setText(els.alertsCount, plural(active.length, 'alerta', 'alertas'));
    setText(els.alertsNavCount, fmtNumber(active.length));
    if (!els.alertsSection) return;
    if (!active.length) {
      els.alertsSection.innerHTML = '<div class="obs-healthy-empty"><span class="material-symbols-rounded" aria-hidden="true">verified</span><div><strong>Nenhum alerta automático ativo</strong><small>As regras monitoradas não identificaram uma condição que exija ação agora.</small></div></div>';
      return;
    }
    const html = active.map((alert) => {
      const rawSeverity = String(alert.severity || 'info').toLowerCase();
      const level = rawSeverity === 'critical' ? 'critical' : rawSeverity === 'warning' ? 'warning' : 'neutral';
      const icon = level === 'critical' ? 'error' : level === 'warning' ? 'warning' : 'info';
      return `<article class="obs-alert-item" data-level="${level}">
        <span class="material-symbols-rounded" aria-hidden="true">${icon}</span>
        <div><strong>${h(alert.title || translated(alert.rule_code, {}))}</strong><p>${h(alert.message || 'Alerta sem descrição.')}</p><time datetime="${h(alert.triggered_at || '')}">Detectado em ${h(fmtDate(alert.triggered_at))}</time></div>
        <span class="obs-alert-item__severity">${h(translated(rawSeverity, severityLabels))}</span>
      </article>`;
    }).join('');
    els.alertsSection.innerHTML = `<div class="obs-alert-list">${html}</div>`;
  }

  function deriveHealth(input) {
    const criticalAlerts = input.alerts.filter((item) => String(item.severity).toLowerCase() === 'critical').length;
    const warningAlerts = Math.max(0, input.alerts.length - criticalAlerts);
    const criticalPipelines = input.pipelineHealth.filter((item) => levelForStatus(item.status) === 'critical').length;
    const criticalLatency = input.latency.filter((item) => number(item.error_rate) > 5 || number(item.p95_ms) > 5000).length;
    const criticalSignals = criticalAlerts + criticalPipelines + (input.shadowCritical > 0 ? 1 : 0) + (input.staleCount > 0 ? 1 : 0);
    const warningSignals = warningAlerts + (input.pendingTotal > 0 ? 1 : 0) + (input.divergences > 0 ? 1 : 0) + (input.failures > 0 ? 1 : 0) + criticalLatency + (input.coverageAvailable ? 0 : 1);
    const level = criticalSignals > 0 ? 'critical' : warningSignals > 0 ? 'warning' : 'healthy';
    const pressure = Math.min(100,
      (criticalAlerts * 24) + (criticalPipelines * 18) + Math.min(24, input.shadowCritical * 8) +
      Math.min(18, input.staleCount * 9) + Math.min(10, warningSignals * 2) + Math.min(6, input.pendingTotal / 10)
    );

    const priorities = [];
    if (criticalAlerts > 0) priorities.push({ icon: 'notifications_active', text: `${plural(criticalAlerts, 'alerta crítico', 'alertas críticos')} aguardando ação`, href: '#obs-alerts' });
    if (criticalPipelines > 0) priorities.push({ icon: 'account_tree', text: `${plural(criticalPipelines, 'processo atrasado', 'processos atrasados ou incompletos')}`, href: '#obs-processing' });
    if (input.staleCount > 0) priorities.push({ icon: 'database_off', text: `${plural(input.staleCount, 'base atrasada', 'bases atrasadas')} pode afetar os painéis`, href: '#obs-data' });
    if (input.shadowCritical > 0) priorities.push({ icon: 'compare_arrows', text: `${plural(input.shadowCritical, 'comparação crítica', 'comparações críticas')} entre leituras`, href: '#obs-quality' });
    if (input.divergences > 0) priorities.push({ icon: 'rule', text: `${plural(input.divergences, 'divergência aberta', 'divergências abertas')} para conferência`, href: '#obs-quality' });
    if (criticalLatency > 0) priorities.push({ icon: 'speed', text: `${plural(criticalLatency, 'endpoint crítico', 'endpoints críticos')} por erro ou lentidão`, href: '#obs-performance' });
    if (!priorities.length && input.failures > 0) priorities.push({ icon: 'history', text: `${plural(input.failures, 'ocorrência recente', 'ocorrências recentes')} sem impacto crítico atual`, href: '#obs-performance' });
    if (!priorities.length) priorities.push({ icon: 'verified', text: 'Nenhuma ação prioritária identificada nesta janela', href: '#obs-overview' });

    const copy = {
      healthy: {
        icon: 'verified', label: 'Operação saudável', badge: 'Saudável',
        summary: 'Nenhum sinal crítico conhecido na janela.',
        recommendation: 'Os dados monitorados estão consistentes e atualizados. Continue acompanhando a rotina normal de sincronização.',
      },
      warning: {
        icon: 'warning', label: 'Operação exige atenção', badge: 'Atenção',
        summary: `${plural(warningSignals, 'sinal requer', 'sinais requerem')} acompanhamento.`,
        recommendation: 'Os painéis seguem disponíveis, mas há sinais que merecem conferência. Comece pelas prioridades abaixo.',
      },
      critical: {
        icon: 'error', label: 'Ação imediata necessária', badge: 'Crítico',
        summary: `${plural(criticalSignals, 'sinal crítico afeta', 'sinais críticos afetam')} a confiança da leitura.`,
        recommendation: 'Valide os alertas e processamentos críticos antes de usar os números em uma decisão operacional.',
      },
    }[level];
    return { level, pressure: Math.round(pressure), priorities: priorities.slice(0, 3), ...copy };
  }

  function renderHealth(health) {
    setLevel(els.healthStatus, health.level);
    setLevel(els.healthCard, health.level);
    setText(els.healthIcon, health.icon);
    setText(els.healthLabel, health.label);
    setText(els.healthSummary, health.summary);
    setText(els.healthBadge, health.badge);
    setText(els.recommendation, health.recommendation);
    setText(els.pressureValue, `${health.pressure}%`);
    if (els.pressureValue) els.pressureValue.title = 'Índice sintético de filas, atrasos, divergências, alertas e falhas. Não representa disponibilidade contratual.';
    if (els.progressFill) els.progressFill.style.width = `${health.pressure}%`;
    if (els.priorityList) {
      els.priorityList.innerHTML = health.priorities.map((item) => `<li><span class="material-symbols-rounded" aria-hidden="true">${h(item.icon)}</span><a href="${h(item.href)}">${h(item.text)}</a></li>`).join('');
    }
  }

  function render(payload, options) {
    const dashboard = payload && payload.dashboard && typeof payload.dashboard === 'object' ? payload.dashboard : {};
    const pipeline = dashboard.jobs?.pipeline?.webhook_inbox || dashboard.jobs?.pipeline || {};
    const pendingTotal = number(pipeline.pending_total);
    const oldestPendingAge = pipeline.oldest_pending_age_seconds;
    const shadowCritical = number(dashboard.shadow?.critical_open_total);
    const divergences = number(dashboard.divergences?.summary?.active_total);
    const datasets = Array.isArray(dashboard.datasets) ? dashboard.datasets : [];
    const jobs = Array.isArray(dashboard.jobs?.by_domain) ? dashboard.jobs.by_domain : [];
    const methods = Array.isArray(dashboard.omie_methods) ? dashboard.omie_methods : [];
    const rules = Array.isArray(dashboard.divergences?.by_rule) ? dashboard.divergences.by_rule : [];
    const shadow = Array.isArray(dashboard.shadow?.by_endpoint) ? dashboard.shadow.by_endpoint : [];
    const pipelineHealth = Array.isArray(dashboard.pipeline_health) ? dashboard.pipeline_health : [];
    const latency = Array.isArray(dashboard.endpoint_latency) ? dashboard.endpoint_latency : [];
    const alerts = Array.isArray(dashboard.active_alerts) ? dashboard.active_alerts : [];
    const staleCount = datasets.filter((row) => ['stale', 'unavailable'].includes(String(row.data_mode).toLowerCase())).length;

    renderDatasets(datasets);
    renderJobs(jobs);
    renderOmie(methods);
    renderPipelineHealth(pipelineHealth);
    renderRules(rules);
    renderShadow(shadow);
    renderLatency(latency, dashboard.window_hours || state.hours);
    const failures = renderFailures(dashboard.recent_failures || {});
    const coverageAvailable = renderCoverage(dashboard.coverage);
    renderAlerts(alerts);

    const health = deriveHealth({
      alerts, pipelineHealth, latency, pendingTotal, shadowCritical, divergences,
      staleCount, failures, coverageAvailable,
    });
    renderHealth(health);

    setText(els.pendingTotal, fmtNumber(pendingTotal));
    setText(els.shadowCritical, fmtNumber(shadowCritical));
    setText(els.divergenceTotal, fmtNumber(divergences));
    setText(els.cardPending, fmtNumber(pendingTotal));
    setText(els.cardPendingAge, oldestPendingAge == null ? 'Sem item pendente datado' : `Mais antigo: ${fmtSeconds(oldestPendingAge)}`);
    setText(els.cardFailedSpans, fmtNumber(failures));
    setText(els.cardShadow, fmtNumber(shadowCritical));
    setText(els.cardStale, fmtNumber(staleCount));
    setLevel(els.kpiPending, pendingTotal > 0 ? 'warning' : 'healthy');
    setLevel(els.kpiFailures, failures > 0 ? 'warning' : 'healthy');
    setLevel(els.kpiShadow, shadowCritical > 0 ? 'critical' : 'healthy');
    setLevel(els.kpiStale, staleCount > 0 ? 'critical' : 'healthy');

    const hours = number(dashboard.window_hours || state.hours);
    state.hours = [1, 6, 24, 72, 168, 720].includes(hours) ? hours : state.hours;
    if (els.windowSelect) els.windowSelect.value = String(state.hours);
    setText(els.windowHours, `${state.hours}h`);
    setText(els.generatedAt, fmtDate(dashboard.generated_at));
    if (els.generatedAt) els.generatedAt.dateTime = dashboard.generated_at || '';
    setText(els.traceId, dashboard.trace?.trace_id || 'Sem referência');

    const cache = payload.cache || {};
    let cacheLabel = cache.status === 'hit' ? 'Snapshot recente' : cache.status === 'refresh' ? 'Leitura forçada' : 'Leitura atualizada';
    if (options?.memoryCache) cacheLabel = 'Snapshot desta sessão';
    if (cache.age_seconds != null && number(cache.age_seconds) > 0) cacheLabel += ` · ${fmtSeconds(cache.age_seconds)}`;
    setText(els.cacheStatus, cacheLabel);

    state.hasData = true;
    state.lastLoadedAt = Date.now();
    if (els.content) els.content.hidden = false;
    if (els.loading) els.loading.hidden = true;
    if (els.error) els.error.hidden = true;
    applyFilters();
  }

  function setBusy(isBusy) {
    els.root.setAttribute('aria-busy', isBusy ? 'true' : 'false');
    els.root.classList.toggle('obs-is-refreshing', isBusy && state.hasData);
    if (els.refresh) {
      els.refresh.disabled = isBusy;
      els.refresh.classList.toggle('is-loading', isBusy);
      const label = els.refresh.querySelector('span:last-child');
      if (label) label.textContent = isBusy ? 'Atualizando…' : 'Atualizar agora';
    }
    if (!state.hasData && els.loading) els.loading.hidden = !isBusy;
  }

  function showError(error) {
    if (els.loading) els.loading.hidden = true;
    if (els.error) els.error.hidden = false;
    setText(els.errorMessage, error?.message || 'Tente novamente em alguns instantes.');
    if (!state.hasData && els.content) els.content.hidden = true;
  }

  async function load(options) {
    const settings = options || {};
    const force = settings.force === true;
    const cached = state.snapshots.get(state.hours);
    if (!force && cached && (Date.now() - cached.storedAt) < MEMORY_CACHE_MS) {
      render(cached.payload, { memoryCache: true });
      setBusy(false);
      return;
    }

    if (state.controller) state.controller.abort();
    state.controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const sequence = ++state.requestSequence;
    const timeoutId = window.setTimeout(() => state.controller?.abort(), REQUEST_TIMEOUT_MS);
    setBusy(true);
    if (els.error) els.error.hidden = true;

    try {
      const query = new URLSearchParams({ hours: String(state.hours) });
      if (force) query.set('force', '1');
      const response = await fetch(`${endpoint}?${query.toString()}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal: state.controller?.signal,
      });
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        throw new Error(response.status === 401 || response.status === 403
          ? 'Sua sessão expirou. Entre novamente para continuar.'
          : 'O servidor retornou uma resposta inesperada.');
      }
      const payload = await response.json();
      if (payload?.redirect && (response.status === 409 || payload.error === 'tenant_context_unavailable')) {
        window.location.assign(payload.redirect);
        return;
      }
      if (!response.ok || !payload?.ok) {
        throw new Error(payload?.message || 'A leitura operacional não pôde ser concluída.');
      }
      if (sequence !== state.requestSequence) return;
      state.snapshots.set(state.hours, { storedAt: Date.now(), payload });
      render(payload);
    } catch (error) {
      if (sequence !== state.requestSequence) return;
      if (error?.name === 'AbortError') {
        showError(new Error('A atualização excedeu 20 segundos. Os dados anteriores foram mantidos.'));
      } else {
        showError(error);
      }
    } finally {
      window.clearTimeout(timeoutId);
      if (sequence === state.requestSequence) setBusy(false);
    }
  }

  function updateUrlHours() {
    const url = new URL(window.location.href);
    if (state.hours === 24) url.searchParams.delete('obs_hours');
    else url.searchParams.set('obs_hours', String(state.hours));
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
  }

  function applyFilters() {
    const query = normalize(state.filterText);
    const level = state.level;
    let visibleTotal = 0;
    let rowTotal = 0;

    document.querySelectorAll('[data-obs-body]').forEach((body) => {
      body.querySelectorAll('.obs-filter-empty').forEach((row) => row.remove());
      const rows = Array.from(body.querySelectorAll('.obs-data-row'));
      let visibleInTable = 0;
      rows.forEach((row) => {
        const rowLevel = row.dataset.level || 'neutral';
        const matchesText = !query || normalize(row.textContent).includes(query);
        const matchesLevel = level === 'all'
          || (level === 'attention' && ['warning', 'critical'].includes(rowLevel))
          || rowLevel === level;
        const visible = matchesText && matchesLevel;
        row.hidden = !visible;
        rowTotal += 1;
        if (visible) {
          visibleTotal += 1;
          visibleInTable += 1;
        }
      });
      if (rows.length && visibleInTable === 0) {
        body.insertAdjacentHTML('beforeend', emptyRow(Number(body.dataset.colspan || 1), 'Nenhum registro corresponde aos filtros.', true));
      }
    });

    const filtered = query || level !== 'all';
    setText(els.filterSummary, filtered ? `${fmtNumber(visibleTotal)} de ${fmtNumber(rowTotal)} registros visíveis` : `${fmtNumber(rowTotal)} registros carregados`);
  }

  function scheduleAutoRefresh() {
    if (state.autoTimer) window.clearInterval(state.autoTimer);
    state.autoTimer = 0;
    if (!els.autoRefresh?.checked) return;
    state.autoTimer = window.setInterval(() => {
      if (!document.hidden && (Date.now() - state.lastLoadedAt) >= AUTO_REFRESH_MS) load({ force: false });
    }, 30000);
  }

  async function copyTrace() {
    const value = els.traceId?.textContent?.trim();
    if (!value || value === '—' || value === 'Sem referência') return;
    try {
      await navigator.clipboard.writeText(value);
      const icon = els.copyTrace?.querySelector('.material-symbols-rounded');
      if (icon) icon.textContent = 'check';
      if (els.copyTrace) els.copyTrace.title = 'Referência copiada';
      window.setTimeout(() => {
        if (icon) icon.textContent = 'content_copy';
        if (els.copyTrace) els.copyTrace.title = 'Copiar referência técnica';
      }, 1600);
    } catch (_) {
      if (els.copyTrace) els.copyTrace.title = 'Não foi possível copiar automaticamente';
    }
  }

  if (els.windowSelect) {
    els.windowSelect.value = String(state.hours);
    els.windowSelect.addEventListener('change', () => {
      state.hours = Number(els.windowSelect.value) || 24;
      updateUrlHours();
      load({ force: false });
    });
  }
  if (els.refresh) els.refresh.addEventListener('click', () => load({ force: true }));
  if (els.retry) els.retry.addEventListener('click', () => load({ force: true }));
  if (els.copyTrace) els.copyTrace.addEventListener('click', copyTrace);
  if (els.tableSearch) {
    els.tableSearch.addEventListener('input', () => {
      state.filterText = els.tableSearch.value;
      window.clearTimeout(state.filterTimer);
      state.filterTimer = window.setTimeout(applyFilters, 100);
    });
  }
  if (els.levelFilter) {
    els.levelFilter.addEventListener('change', () => {
      state.level = els.levelFilter.value;
      applyFilters();
    });
  }
  if (els.autoRefresh) {
    try { els.autoRefresh.checked = window.sessionStorage.getItem(AUTO_REFRESH_KEY) === '1'; } catch (_) {}
    els.autoRefresh.addEventListener('change', () => {
      try { window.sessionStorage.setItem(AUTO_REFRESH_KEY, els.autoRefresh.checked ? '1' : '0'); } catch (_) {}
      scheduleAutoRefresh();
    });
    scheduleAutoRefresh();
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && els.autoRefresh?.checked && (Date.now() - state.lastLoadedAt) >= AUTO_REFRESH_MS) load({ force: false });
  });
  window.addEventListener('beforeunload', () => state.controller?.abort(), { once: true });

  load({ force: false });
})();
