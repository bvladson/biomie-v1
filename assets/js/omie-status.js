/**
 * omie-status.js — Controller para a página Conexão Omie
 *
 * Carrega status local via GET api/omie_status.php
 * Gerencia ações POST (testar conexão, atualizar visão)
 * Coloriza cards por severidade
 * Controla diagnóstico recolhível
 *
 * Light-only. Sem dark mode.
 */
(function () {
  'use strict';

  const API_STATUS            = 'api/omie_status.php';
  const API_TEST              = 'api/omie_test_connection.php';
  const API_SNAPSHOT          = 'api/omie_materialize_commercial.php';
  const API_FINANCE_SYNC      = 'api/finance_sync.php';
  const API_MAT_CLIENTS       = 'api/materialize_clients.php';
  const API_GEO_CNAE_MAT      = 'api/geo_cnae_materialize.php';
  const API_DELTA_SYNC        = 'api/omie_delta_sync.php';
  const API_REPROCESS_QUEUE   = 'api/omie_reprocess_queue.php';

  const STATUS_COLORS = {
    ok:      { bg: '#e8f5e9', border: '#43a047', icon: 'check_circle', text: '#2e7d32' },
    warning: { bg: '#fff8e1', border: '#f9a825', icon: 'warning',      text: '#f57f17' },
    danger:  { bg: '#fce4ec', border: '#e53935', icon: 'error',        text: '#c62828' },
    pending: { bg: '#e3f2fd', border: '#1976d2', icon: 'schedule',     text: '#1565c0' },
    unknown: { bg: '#f5f5f5', border: '#9e9e9e', icon: 'help',         text: '#616161' },
  };

  function getCsrf() {
    const meta = document.querySelector('meta[name="biomie-csrf"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function formatNumber(n) {
    if (n === null || n === undefined) return '-';
    return Number(n).toLocaleString('pt-BR');
  }

  function formatDate(d) {
    if (!d) return '-';
    try {
      const dt = new Date(d.replace(' ', 'T'));
      if (isNaN(dt.getTime())) return d;
      return dt.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch { return d; }
  }

  function formatDuration(ms) {
    if (ms === null || ms === undefined || ms === '') return '-';
    const value = Number(ms);
    if (!Number.isFinite(value)) return '-';
    if (value < 1000) return Math.round(value) + 'ms';
    return (value / 1000).toFixed(1).replace('.', ',') + 's';
  }

  function statusColor(status) {
    return STATUS_COLORS[status] || STATUS_COLORS.unknown;
  }

  // ── Loading ────────────────────────────────────────────────────────────────

  function omieBootCapabilities() {
    const boot = window.__BIOMIE_BOOTSTRAP__ || {};
    const omie = boot.omie || {};
    return omie.capabilities || {};
  }

  function canUseAnyCapability(names) {
    const capabilities = omieBootCapabilities();
    return names.some(name => capabilities[name] === true);
  }

  function hideUnavailableActions() {
    const actions = [
      ['btnTestConnection', ['omie_sync']],
      ['btnDeltaSync', ['omie_sync']],
      ['btnReprocessQueue', ['jobs_run', 'omie_sync']],
      ['btnUpdateCommercial', ['sales_sync', 'omie_sync', 'jobs_run']],
      ['btnMaterializeClients', ['jobs_run', 'omie_sync', 'settings_manage']],
      ['btnMaterializeGeo', ['geo_materialize', 'jobs_run']],
    ];

    actions.forEach(([id, capabilities]) => {
      const button = document.getElementById(id);
      if (button && !canUseAnyCapability(capabilities)) {
        button.remove();
      }
    });
  }

  function showLoading() {
    const el = document.getElementById('pageLoading');
    if (el) el.style.display = '';
    const content = document.getElementById('pageContent');
    if (content) content.style.display = 'none';
  }

  function hideLoading() {
    const el = document.getElementById('pageLoading');
    if (el) el.style.display = 'none';
    const content = document.getElementById('pageContent');
    if (content) content.style.display = '';
  }

  function showError(msg) {
    const el = document.getElementById('pageError');
    if (!el) return;
    el.textContent = msg;
    el.className = 'omie-status-error';
    el.style.display = '';
  }

  // ── Cards ──────────────────────────────────────────────────────────────────

  function renderStatusCards(checks, overview) {
    const container = document.getElementById('statusCards');
    if (!container) return;

    const priority = [
      'credentials_configured', 'api_last_test',
      'commercial_sync', 'finance_sync',
      'materialization', 'failed_jobs', 'last_error'
    ];

    const primary = [
      ['source_sync_status', 'Sync Omie'],
      ['canonical_freshness_status', 'Origem canonica'],
      ['gold_materialization_status', 'Materializacao Gold'],
      ['worker_queue_status', 'Worker/Fila'],
      ['webhook_status', 'Webhook inbox']
    ].map(([key, label]) => {
      const block = overview && overview[key];
      if (!block) return null;
      return {
        key,
        label: block.label || label,
        status: block.status || 'unknown',
        message: block.message || '-',
        updated_at: block.last_success_at || block.last_materialization_at || block.last_event_received_at || block.updated_at || null,
        recommended_action: block.recommended_action || null
      };
    }).filter(Boolean);

    const ordered = priority
      .map(k => Array.isArray(checks) ? checks.find(c => c.key === k) : null)
      .filter(Boolean);

    container.innerHTML = primary.concat(ordered).map(c => {
      const color = statusColor(c.status);
      return `<div class="omie-card" style="border-left:4px solid ${color.border};background:${color.bg}">
        <div class="omie-card-header">
          <span class="material-symbols-rounded" style="color:${color.text}">${color.icon}</span>
          <span class="omie-card-label">${esc(c.label)}</span>
        </div>
        <div class="omie-card-message">${esc(c.message)}</div>
        ${c.updated_at ? `<div class="omie-card-date">${formatDate(c.updated_at)}</div>` : ''}
        ${c.recommended_action ? `<div class="omie-card-action">${esc(c.recommended_action)}</div>` : ''}
      </div>`;
    }).join('');
  }

  // ── Data Status ────────────────────────────────────────────────────────────

  function renderDataStatus(data) {
    const container = document.getElementById('dataStatus');
    if (!container || !data) return;

    const items = ['commercial', 'invoices', 'clients', 'clients_geo', 'bairros_dim', 'finance'];
    container.innerHTML = items.map(key => {
      const d = data[key];
      if (!d) return '';
      const color = statusColor(d.status);
      let detail = '';
      if (key === 'clients' && d.detail) {
        detail = `<div class="omie-data-sync">Doc: ${formatNumber(d.detail.com_documento)} | Vend: ${formatNumber(d.detail.com_vendedor)}</div>`;
      } else if (key === 'bairros_dim' && d.detail) {
        const complete = d.detail.complete ? ' ✓' : ` (${d.detail.total}/${d.detail.expected})`;
        detail = `<div class="omie-data-sync">Bairros${complete}</div>`;
      }
      return `<div class="omie-data-item" style="border-left:3px solid ${color.border}">
        <div class="omie-data-label">${esc(d.label)}</div>
        <div class="omie-data-count" style="color:${color.text}">${d.count !== null ? formatNumber(d.count) : '-'}</div>
        <div class="omie-data-sync">${d.last_sync ? formatDate(d.last_sync) : 'Sem registro'}</div>
        ${d.message ? `<div class="omie-data-sync">${esc(d.message)}</div>` : ''}
        ${detail}
        ${d.recommended_action ? `<div class="omie-card-action" style="font-size:11px;margin-top:3px">${esc(d.recommended_action)}</div>` : ''}
      </div>`;
    }).join('');

    renderGeoStatusSection(data);
  }

  function renderSyncDomainStatus(domains) {
    const container = document.getElementById('syncDomainStatus');
    if (!container) return;

    if (!Array.isArray(domains) || domains.length === 0) {
      container.innerHTML = '<div class="omie-no-actions">Nenhum cursor operacional registrado ainda.</div>';
      return;
    }

    container.innerHTML = domains.map(d => {
      const color = statusColor(d.status);
      const age = d.age_minutes === null || d.age_minutes === undefined ? '-' : `${formatNumber(d.age_minutes)} min`;
      return `<div class="omie-ops-card" style="border-left:3px solid ${color.border}">
        <div class="omie-ops-title">
          <span>${esc(d.label || d.domain)}</span>
          <span class="omie-badge" style="background:${color.bg};color:${color.text};border:1px solid ${color.border}">${esc(d.status || 'unknown')}</span>
        </div>
        <div class="omie-ops-row"><span>Ultimo sucesso</span><strong>${formatDate(d.last_success_at)}</strong></div>
        <div class="omie-ops-row"><span>Idade</span><strong>${esc(age)}</strong></div>
        <div class="omie-ops-row"><span>Proximo delta</span><strong>${formatDate(d.next_delta_at)}</strong></div>
        ${d.message ? `<div class="omie-ops-warn">${esc(d.message)}</div>` : ''}
        ${d.recommended_command ? `<div class="omie-card-action" style="font-size:11px;margin-top:3px">${esc(d.recommended_command)}</div>` : ''}
        ${d.last_error ? `<div class="omie-ops-warn">${esc(d.last_error)}</div>` : ''}
      </div>`;
    }).join('');
  }

  function renderRateLimitStatus(rateLimits) {
    const container = document.getElementById('rateLimitStatus');
    if (!container || !rateLimits) return;

    const limits = rateLimits.limits || {};
    const domains = rateLimits.domains || {};
    const domainRows = Object.entries(domains).map(([domain, row]) => `<tr>
      <td>${esc(domain)}</td>
      <td>${formatNumber(row.methods || 0)}</td>
      <td>${formatNumber(row.max_page_size || '-')}</td>
      <td>${formatNumber(row.cache_enabled_methods || 0)}</td>
      <td>${formatNumber(row.max_concurrency || 0)}</td>
    </tr>`).join('');

    container.innerHTML = `<div class="omie-rate-summary">
      <div><span>IP/min</span><strong>${formatNumber(limits.global_per_ip_per_minute || 960)}</strong></div>
      <div><span>App+metodo/min</span><strong>${formatNumber(limits.per_app_method_per_minute || 240)}</strong></div>
      <div><span>Concorrencia/metodo</span><strong>${formatNumber(limits.max_concurrent_per_app_method || 4)}</strong></div>
      <div><span>Cache ID</span><strong>${formatNumber(limits.redundant_lookup_cache_seconds || 60)}s</strong></div>
    </div>
    <table class="omie-table-compact"><thead><tr>
      <th>Dominio</th><th>Metodos</th><th>Pagina max.</th><th>Cache ID</th><th>Conc.</th>
    </tr></thead><tbody>${domainRows || '<tr><td colspan="5">Sem politicas carregadas.</td></tr>'}</tbody></table>`;
  }

  function renderOperationalStatus(operational, automationCommands, workerStatus) {
    const container = document.getElementById('operationalStatus');
    if (!container || !operational) return;

    const queueCounts = (((operational.file_queue || {}).counts) || {});
    const recent = operational.recent_24h || {};
    const pipeline = operational.sync_pipeline || {};
    const webhook = pipeline.webhook_inbox || {};
    const cursors = Array.isArray(pipeline.cursors) ? pipeline.cursors : [];
    const pendingQueue = Number(queueCounts.queued || 0) + Number(queueCounts.failed_retryable || 0);
    const deadLetters = (workerStatus && workerStatus.dead_letters) || {};
    const heartbeat = (workerStatus && workerStatus.heartbeat) || {};

    let html = `<div class="omie-rate-summary">
      <div><span>Fila pendente</span><strong>${formatNumber(pendingQueue)}</strong></div>
      <div><span>Em execucao</span><strong>${formatNumber(queueCounts.running || 0)}</strong></div>
      <div><span>Falhas 24h</span><strong>${formatNumber(recent.failures || 0)}</strong></div>
      <div><span>Tempo medio</span><strong>${formatDuration(recent.avg_duration_ms)}</strong></div>
      <div><span>Dead-letter</span><strong>${formatNumber(deadLetters.unresolved || 0)}</strong></div>
      <div><span>Webhook pendente</span><strong>${formatNumber(webhook.pending_total || 0)}</strong></div>
    </div>`;

    if (workerStatus) {
      const color = statusColor(workerStatus.status);
      html += `<div class="omie-ops-card" style="border-left:3px solid ${color.border};margin-top:12px">
        <div class="omie-ops-title">
          <span>${esc(workerStatus.label || 'Worker/Fila')}</span>
          <span class="omie-badge" style="background:${color.bg};color:${color.text};border:1px solid ${color.border}">${esc(workerStatus.status || 'unknown')}</span>
        </div>
        <div class="omie-card-message">${esc(workerStatus.message || '')}</div>
        ${workerStatus.recommended_action ? `<div class="omie-card-action">${esc(workerStatus.recommended_action)}</div>` : ''}
        ${heartbeat.message ? `<div class="omie-ops-row"><span>Heartbeat</span><strong>${esc(heartbeat.message)}</strong></div>` : ''}
        ${heartbeat.latest && heartbeat.latest.last_heartbeat_at ? `<div class="omie-ops-row"><span>Ultimo heartbeat</span><strong>${formatDate(heartbeat.latest.last_heartbeat_at)}</strong></div>` : ''}
      </div>`;
    }

    const webhookCounts = webhook.counts || {};
    const webhookTotal = Object.values(webhookCounts).reduce((sum, value) => sum + Number(value || 0), 0);
    if (webhookTotal === 0) {
      html += '<div class="omie-no-actions" style="margin-top:12px">Nenhum webhook recebido. Use cron/polling ou configure webhook Omie.</div>';
    }

    if (cursors.length > 0) {
      html += '<table class="omie-table-compact"><thead><tr><th>Dominio</th><th>Grupo</th><th>Status</th><th>Ultimo sucesso</th><th>Proximo delta</th></tr></thead><tbody>';
      html += cursors.slice(0, 8).map(c => {
        const color = statusColor(c.status === 'completed' ? 'ok' : (String(c.status || '').startsWith('failed') ? 'danger' : 'pending'));
        return `<tr>
          <td>${esc(c.domain)}</td>
          <td>${esc(c.endpoint_group)}</td>
          <td><span class="omie-badge" style="background:${color.bg};color:${color.text};border:1px solid ${color.border}">${esc(c.status)}</span></td>
          <td>${formatDate(c.last_success_at)}</td>
          <td>${formatDate(c.next_delta_at)}</td>
        </tr>`;
      }).join('');
      html += '</tbody></table>';
    }

    if (Array.isArray(automationCommands) && automationCommands.length > 0) {
      html += '<div class="omie-table-group" style="margin-top:12px"><div class="omie-table-group-title">Comandos esperados</div>';
      html += '<table class="omie-table-compact"><thead><tr><th>Rotina</th><th>Comando</th><th>Uso</th></tr></thead><tbody>';
      html += automationCommands.map((row) => `<tr>
        <td>${esc(row.label || '-')}</td>
        <td><code>${esc(row.command || '')}</code></td>
        <td>${esc(row.description || '')}</td>
      </tr>`).join('');
      html += '</tbody></table></div>';
    }

    container.innerHTML = html;
  }

  function renderGeoStatusSection(data) {
    const section = document.getElementById('geoStatusSection');
    const geoContainer = document.getElementById('geoDataStatus');
    const msgEl = document.getElementById('geoStatusMessage');
    if (!section || !geoContainer) return;

    const clientsDim = data.clients || {};
    const clientsGeo = data.clients_geo || {};
    const bairrosDim = data.bairros_dim || {};

    const dimCount = clientsDim.count ?? 0;
    const geoCount = clientsGeo.count ?? 0;
    const bairrosCount = bairrosDim.count ?? 0;

    section.style.display = '';

    const geoItems = ['clients', 'clients_geo', 'bairros_dim'];
    geoContainer.innerHTML = geoItems.map(key => {
      const d = data[key];
      if (!d) return '';
      const color = statusColor(d.status);
      return `<div class="omie-data-item" style="border-left:3px solid ${color.border}">
        <div class="omie-data-label">${esc(d.label)}</div>
        <div class="omie-data-count" style="color:${color.text}">${d.count !== null ? formatNumber(d.count) : '-'}</div>
      </div>`;
    }).join('');

    // Contextual message
    let msg = '';
    if (dimCount === 0) {
      msg = 'A dimensão de clientes ainda não foi preparada. Clique em "Materializar clientes" para começar.';
    } else if (geoCount === 0) {
      msg = 'Clientes cadastrados, mas a camada geo está vazia. Clique em "Materializar Geo/CNAE".';
    } else if (bairrosCount < 121) {
      msg = 'Dimensão de bairros incompleta. Verifique se a migration BairrosFortalezaDim foi aplicada.';
    } else {
      msg = `${formatNumber(dimCount)} clientes | ${formatNumber(geoCount)} com geo | 121 bairros de Fortaleza disponíveis.`;
    }
    if (msgEl) msgEl.textContent = msg;
  }

  // ── Tables ─────────────────────────────────────────────────────────────────

  function renderTableStatus(tables) {
    const container = document.getElementById('tableStatus');
    if (!container || !tables) return;

    const groupLabels = {
      bronze: 'Bronze', canonical: 'Canonical/Silver',
      gold: 'Gold', jobs: 'Jobs/Sync'
    };

    let html = '';
    for (const [group, items] of Object.entries(tables)) {
      if (!Array.isArray(items) || items.length === 0) continue;
      html += `<div class="omie-table-group">
        <div class="omie-table-group-title">${groupLabels[group] || group}</div>
        <table class="omie-table-compact"><thead><tr>
          <th>Tabela</th><th>Status</th><th>Registros</th><th>Atualizado</th><th>Diagnostico</th>
        </tr></thead><tbody>`;
      for (const t of items) {
        const color = statusColor(t.status);
        const badge = t.badge_label || (t.exists ? (t.status === 'ok' ? 'OK' : 'Vazio') : 'Ausente');
        html += `<tr>
          <td title="${esc(t.name)}">${esc(t.label)}</td>
          <td><span class="omie-badge" title="${esc(t.empty_class || '')}" style="background:${color.bg};color:${color.text};border:1px solid ${color.border}">${esc(badge)}</span></td>
          <td>${t.rows !== null ? formatNumber(t.rows) : '-'}</td>
          <td>${formatDate(t.last_updated)}</td>
          <td>
            <div>${esc(t.message || '-')}</div>
            ${t.recommended_action ? `<div class="omie-card-action" style="font-size:11px;margin-top:2px">${esc(t.recommended_action)}</div>` : ''}
          </td>
        </tr>`;
      }
      html += '</tbody></table></div>';
    }
    container.innerHTML = html;
  }

  // ── Recommended Actions ────────────────────────────────────────────────────

  function renderActions(actions) {
    const container = document.getElementById('recommendedActions');
    if (!container) return;

    if (!Array.isArray(actions) || actions.length === 0) {
      container.innerHTML = '<div class="omie-no-actions">Nenhuma acao pendente.</div>';
      return;
    }

    container.innerHTML = '<ul class="omie-actions-list">' + actions.map(a => {
      const color = statusColor(a.severity);
      return `<li>
        <span class="material-symbols-rounded" style="color:${color.text};font-size:18px">${color.icon}</span>
        <span>${esc(a.message)}</span>
        ${a.link ? `<a href="${esc(a.link)}" class="omie-action-link">Abrir</a>` : ''}
      </li>`;
    }).join('') + '</ul>';
  }

  // ── Recent Syncs ───────────────────────────────────────────────────────────

  function renderRecentSyncs(syncs) {
    const container = document.getElementById('recentSyncs');
    if (!container) return;

    if (!Array.isArray(syncs) || syncs.length === 0) {
      container.innerHTML = '<div class="omie-no-actions">Nenhuma sincronizacao recente.</div>';
      return;
    }

    let html = '<table class="omie-table-compact"><thead><tr><th>Dominio</th><th>Tipo</th><th>Status</th><th>Inicio</th><th>Itens</th></tr></thead><tbody>';
    for (const s of syncs) {
      const color = statusColor(s.status === 'completed' || s.status === 'completed_with_warnings' ? 'ok' : (s.status === 'running' ? 'pending' : 'danger'));
      html += `<tr>
        <td>${esc(s.domain)}</td>
        <td>${esc(s.type)}</td>
        <td><span class="omie-badge" style="background:${color.bg};color:${color.text};border:1px solid ${color.border}">${esc(s.status)}</span></td>
        <td>${formatDate(s.started_at)}</td>
        <td>${s.items_processed || '-'}</td>
      </tr>`;
    }
    html += '</tbody></table>';
    container.innerHTML = html;
  }

  // ── Diagnostics ────────────────────────────────────────────────────────────

  function renderDiagnostics(diag) {
    const container = document.getElementById('diagnosticsContent');
    if (!container || !diag) return;

    const rows = [
      ['Gerado em', formatDate(diag.generated_at)],
      ['Ambiente', diag.environment],
      ['Aplicacao', diag.app_name],
      ['Fuso', diag.timezone],
      ['APP_KEY configurada', diag.app_key_configured ? 'Sim' : 'Nao'],
      ['APP_KEY (mascarada)', diag.app_key_masked || '-'],
      ['APP_SECRET configurada', diag.app_secret_configured ? 'Sim' : 'Nao'],
    ];

    let html = '<table class="omie-table-compact"><tbody>' +
      rows.map(([k, v]) => `<tr><td><strong>${esc(k)}</strong></td><td>${esc(String(v))}</td></tr>`).join('') +
      '</tbody></table>';

    if (diag.latest_materialization) {
      html += renderMaterializationBlock(diag.latest_materialization.context || {}, 'Ultima materializacao registrada');
    }

    container.innerHTML = html;
  }

  function renderMaterializationReport(report) {
    const container = document.getElementById('diagnosticsContent');
    if (!container || !report) return;

    const block = renderMaterializationBlock(report, 'Resultado da materializacao comercial');
    container.insertAdjacentHTML('afterbegin', block);
  }

  function renderMaterializationBlock(report, title) {
    const rows = [
      ['Status', report.status || '-'],
      ['Periodo', report.date_range ? (report.date_range.label || `${report.date_range.start || '-'} ate ${report.date_range.end || '-'}`) : '-'],
      ['Inicio', report.started_at ? formatDate(report.started_at) : '-'],
      ['Fim', report.ended_at ? formatDate(report.ended_at) : '-'],
      ['Duracao', report.duration_ms !== undefined ? `${formatNumber(report.duration_ms)}ms` : '-'],
      ['Motivo zero', report.zero_reason || '-'],
    ];

    let html = `<div class="omie-table-group" style="margin-top:12px">
      <div class="omie-table-group-title">${esc(title)}</div>
      <table class="omie-table-compact"><tbody>
      ${rows.map(([k, v]) => `<tr><td><strong>${esc(k)}</strong></td><td>${esc(String(v))}</td></tr>`).join('')}
      </tbody></table>`;

    html += renderCountTable('Origem lida', report.source_counts);
    html += renderCountTable('Origem no periodo', report.source_period_counts);
    html += renderCountTable('Destino antes', report.target_before_counts);
    html += renderCountTable('Destino depois', report.target_after_counts);
    html += renderCountTable('Linhas carregadas', report.inserted_counts);

    if (Array.isArray(report.warnings) && report.warnings.length > 0) {
      html += '<div class="omie-no-actions"><strong>Alertas:</strong><ul>' +
        report.warnings.map(w => `<li>${esc(w)}</li>`).join('') +
        '</ul></div>';
    }
    if (Array.isArray(report.errors) && report.errors.length > 0) {
      html += '<div class="omie-no-actions"><strong>Erros:</strong><ul>' +
        report.errors.map(e => `<li>${esc(e)}</li>`).join('') +
        '</ul></div>';
    }

    return html + '</div>';
  }

  function renderCountTable(title, counts) {
    if (!counts || typeof counts !== 'object' || Array.isArray(counts)) return '';
    const entries = Object.entries(counts);
    if (entries.length === 0) return '';

    return `<div style="margin-top:10px"><strong>${esc(title)}</strong>
      <table class="omie-table-compact"><tbody>
      ${entries.map(([k, v]) => `<tr><td>${esc(k)}</td><td>${v === null || v === undefined ? '-' : formatNumber(v)}</td></tr>`).join('')}
      </tbody></table></div>`;
  }

  // ── Actions (buttons) ──────────────────────────────────────────────────────

  function setupActions() {
    const btnTest = document.getElementById('btnTestConnection');
    if (btnTest) {
      btnTest.addEventListener('click', async () => {
        btnTest.disabled = true;
        btnTest.innerHTML = '<span class="material-symbols-rounded omie-spin">sync</span>Testando...';
        try {
          const res = await fetch(API_TEST, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
            body: JSON.stringify({ _csrf: getCsrf() }),
          });
          const json = await res.json();
          showFeedback(json.ok ? 'success' : 'error', json.message || 'Teste concluido.');
          if (json.latency_ms) {
            showFeedback('info', 'Latencia: ' + json.latency_ms + 'ms');
          }
          // Reload status
          setTimeout(loadStatus, 800);
        } catch (e) {
          showFeedback('error', 'Erro ao testar conexao.');
        } finally {
          btnTest.disabled = false;
          btnTest.innerHTML = '<span class="material-symbols-rounded">cable</span>Testar conexao Omie';
        }
      });
    }

    const btnSnapshot = document.getElementById('btnUpdateCommercial');
    if (btnSnapshot) {
      btnSnapshot.addEventListener('click', async () => {
        btnSnapshot.disabled = true;
        btnSnapshot.innerHTML = '<span class="material-symbols-rounded omie-spin">sync</span>Atualizando...';
        try {
          const res = await fetch(API_SNAPSHOT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
            body: JSON.stringify({ _csrf: getCsrf() }),
          });
          const json = await res.json();
          showFeedback(
            json.ok ? 'success' : 'error',
            json.message || (json.ok ? 'Visao comercial recalculada com os dados locais disponiveis.' : 'Nao foi possivel recalcular a visao comercial.')
          );
          if (json.warning) {
            showFeedback('info', json.warning);
          } else if (json.source_warning && json.source_warning.message) {
            showFeedback('info', json.source_warning.message);
          }
          if (json.report) {
            renderMaterializationReport(json.report);
          }
          setTimeout(loadStatus, 1000);
        } catch (e) {
          showFeedback('error', 'Erro ao atualizar visao comercial.');
        } finally {
          btnSnapshot.disabled = false;
          btnSnapshot.innerHTML = '<span class="material-symbols-rounded">refresh</span>Atualizar visao comercial';
        }
      });
    }

    const btnDelta = document.getElementById('btnDeltaSync');
    if (btnDelta) {
      btnDelta.addEventListener('click', async () => {
        btnDelta.disabled = true;
        btnDelta.innerHTML = '<span class="material-symbols-rounded omie-spin">sync</span>Agendando...';
        try {
          const res = await fetch(API_DELTA_SYNC, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
            body: JSON.stringify({ _csrf: getCsrf(), domains: ['commercial', 'finance'] }),
          });
          const json = await res.json();
          showFeedback(json.ok ? 'success' : 'error', json.message || 'Sync delta agendado.');
          if (json.follow_up) showFeedback('info', json.follow_up);
          if (json.status_url) showFeedback('info', 'Status do job: ' + json.status_url);
          setTimeout(loadStatus, 800);
        } catch {
          showFeedback('error', 'Erro ao agendar sync delta.');
        } finally {
          btnDelta.disabled = false;
          btnDelta.innerHTML = '<span class="material-symbols-rounded">sync_alt</span>Sincronizar delta';
        }
      });
    }

    const btnReprocess = document.getElementById('btnReprocessQueue');
    if (btnReprocess) {
      btnReprocess.addEventListener('click', async () => {
        btnReprocess.disabled = true;
        btnReprocess.innerHTML = '<span class="material-symbols-rounded omie-spin">sync</span>Agendando...';
        try {
          const res = await fetch(API_REPROCESS_QUEUE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
            body: JSON.stringify({ _csrf: getCsrf(), limit: 50 }),
          });
          const json = await res.json();
          showFeedback(json.ok ? 'success' : 'error', json.message || 'Fila Omie agendada para reprocessamento.');
          setTimeout(loadStatus, 800);
        } catch {
          showFeedback('error', 'Erro ao agendar reprocessamento da fila.');
        } finally {
          btnReprocess.disabled = false;
          btnReprocess.innerHTML = '<span class="material-symbols-rounded">playlist_add_check</span>Reprocessar fila';
        }
      });
    }

    const btnMatClients = document.getElementById('btnMaterializeClients');
    if (btnMatClients) {
      btnMatClients.addEventListener('click', async () => {
        if (!confirm('Isso irá popular biomie_clientes_dim a partir de pedidos e NFs canônicas.\nNão chama a API Omie. Confirmar?')) return;
        btnMatClients.disabled = true;
        btnMatClients.innerHTML = '<span class="material-symbols-rounded omie-spin">sync</span>Materializando...';
        try {
          const res = await fetch(API_MAT_CLIENTS, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
            body: JSON.stringify({ _csrf: getCsrf() }),
          });
          const json = await res.json();
          showFeedback(json.ok ? 'success' : 'error', json.message || (json.ok ? 'Clientes materializados.' : 'Falha ao materializar clientes.'));
          if (json.ok) setTimeout(loadStatus, 800);
        } catch {
          showFeedback('error', 'Erro ao materializar clientes.');
        } finally {
          btnMatClients.disabled = false;
          btnMatClients.innerHTML = '<span class="material-symbols-rounded">people</span>Materializar clientes';
        }
      });
    }

    const btnMatGeo = document.getElementById('btnMaterializeGeo');
    if (btnMatGeo) {
      btnMatGeo.addEventListener('click', async () => {
        if (!confirm('Isso irá criar entradas geo para todos os clientes e fazer match com os 121 bairros de Fortaleza.\nNão chama a API Omie. Confirmar?')) return;
        btnMatGeo.disabled = true;
        btnMatGeo.innerHTML = '<span class="material-symbols-rounded omie-spin">sync</span>Materializando Geo...';
        try {
          const res = await fetch(API_GEO_CNAE_MAT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
            body: JSON.stringify({ _csrf: getCsrf() }),
          });
          const json = await res.json();
          showFeedback(json.ok ? 'success' : 'error', json.message || (json.ok ? 'Geo/CNAE materializado.' : 'Falha ao materializar Geo/CNAE.'));
          if (json.ok) setTimeout(loadStatus, 800);
        } catch {
          showFeedback('error', 'Erro ao materializar Geo/CNAE.');
        } finally {
          btnMatGeo.disabled = false;
          btnMatGeo.innerHTML = '<span class="material-symbols-rounded">map</span>Materializar Geo/CNAE';
        }
      });
    }

    // Diagnostics toggle
    const btnDiag = document.getElementById('btnToggleDiagnostics');
    const diagPanel = document.getElementById('diagnosticsPanel');
    if (btnDiag && diagPanel) {
      btnDiag.addEventListener('click', () => {
        const open = diagPanel.style.display !== 'none';
        diagPanel.style.display = open ? 'none' : '';
        btnDiag.setAttribute('aria-expanded', String(!open));
      });
    }
  }

  function showFeedback(type, message) {
    const container = document.getElementById('syncFeedback');
    if (!container) return;

    const colors = { success: '#43a047', error: '#e53935', info: '#1976d2' };
    const bgs = { success: '#e8f5e9', error: '#fce4ec', info: '#e3f2fd' };

    const el = document.createElement('div');
    el.className = 'omie-feedback';
    el.style.cssText = `background:${bgs[type] || bgs.info};color:${colors[type] || colors.info};border:1px solid ${colors[type] || colors.info};padding:8px 16px;border-radius:6px;margin-bottom:8px;font-size:13px`;
    el.textContent = message;
    container.appendChild(el);

    setTimeout(() => el.remove(), 6000);
  }

  // ── Escape ─────────────────────────────────────────────────────────────────

  function esc(s) {
    if (s === null || s === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(s);
    return div.innerHTML;
  }

  // ── Load ───────────────────────────────────────────────────────────────────

  async function loadStatus() {
    try {
      const res = await fetch(API_STATUS);
      if (!res.ok) throw new Error('Status ' + res.status);
      const data = await res.json();

      if (!data.ok) {
        showError(data.message || 'Erro ao carregar status.');
        hideLoading();
        return;
      }

      renderStatusCards(data.checks, data);
      renderDataStatus(data.data);
      renderSyncDomainStatus(data.sync_domains);
      renderRateLimitStatus(data.rate_limits);
      renderOperationalStatus(data.operational, data.automation_commands, data.worker_queue_status);
      renderTableStatus(data.tables);
      renderActions(data.recommended_actions);
      renderRecentSyncs(data.recent_syncs);
      renderDiagnostics(data.diagnostics);
      hideLoading();
    } catch (e) {
      showError('Nao foi possivel carregar o status da integracao.');
      hideLoading();
    }
  }

  // ── Init ───────────────────────────────────────────────────────────────────

  function init() {
    hideUnavailableActions();
    showLoading();
    setupActions();
    loadStatus();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
