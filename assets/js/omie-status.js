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

  function statusColor(status) {
    return STATUS_COLORS[status] || STATUS_COLORS.unknown;
  }

  // ── Loading ────────────────────────────────────────────────────────────────

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

  function renderStatusCards(checks) {
    const container = document.getElementById('statusCards');
    if (!container || !Array.isArray(checks)) return;

    const priority = [
      'credentials_configured', 'api_last_test',
      'commercial_sync', 'finance_sync',
      'materialization', 'failed_jobs', 'last_error'
    ];

    const ordered = priority
      .map(k => checks.find(c => c.key === k))
      .filter(Boolean);

    container.innerHTML = ordered.map(c => {
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
        ${detail}
        ${d.recommended_action ? `<div class="omie-card-action" style="font-size:11px;margin-top:3px">${esc(d.recommended_action)}</div>` : ''}
      </div>`;
    }).join('');

    renderGeoStatusSection(data);
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
          <th>Tabela</th><th>Status</th><th>Registros</th><th>Atualizado</th>
        </tr></thead><tbody>`;
      for (const t of items) {
        const color = statusColor(t.status);
        html += `<tr>
          <td title="${esc(t.name)}">${esc(t.label)}</td>
          <td><span class="omie-badge" style="background:${color.bg};color:${color.text};border:1px solid ${color.border}">${t.exists ? (t.status === 'ok' ? 'OK' : 'Vazio') : 'Ausente'}</span></td>
          <td>${t.rows !== null ? formatNumber(t.rows) : '-'}</td>
          <td>${formatDate(t.last_updated)}</td>
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
            json.message || (json.ok ? 'Materializacao comercial concluida.' : 'Nao foi possivel materializar a visao comercial.')
          );
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

      renderStatusCards(data.checks);
      renderDataStatus(data.data);
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
