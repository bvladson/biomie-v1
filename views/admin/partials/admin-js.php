<script>
(function () {
  'use strict';

  /* ── Helpers ──────────────────────────────────────────────────────────────── */
  function adminEsc(v) {
    return String(v === null || v === undefined ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function adminJson(v) { try { return JSON.stringify(v, null, 2); } catch (e) { return String(v); } }
  function adminFetchJson(url) {
    return fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().then(function (d) {
          if (!r.ok) throw new Error((d && (d.message || d.error)) || 'HTTP ' + r.status);
          return d;
        });
      });
  }
  function adminPost(url, body) {
    var csrf = window.ADMIN_CSRF || '';
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      credentials: 'same-origin',
      body: JSON.stringify(Object.assign({ _csrf: csrf }, body || {}))
    }).then(function (r) {
      return r.json().then(function (d) {
        if (!r.ok) throw new Error((d && (d.message || d.error)) || 'HTTP ' + r.status);
        return d;
      });
    });
  }
  function adminAge(minutes) {
    if (minutes === null || minutes === undefined || minutes === '') return '—';
    var n = Number(minutes);
    if (!Number.isFinite(n)) return '—';
    if (n < 2) return 'agora';
    if (n < 60) return Math.round(n) + ' min';
    if (n < 1440) return Math.round(n / 60) + 'h';
    return Math.round(n / 1440) + 'd';
  }
  function adminStatusClass(s) {
    s = String(s || '').toLowerCase();
    if (s === 'ok' || s === 'pass' || s === 'success') return 'ok';
    if (s === 'warning' || s === 'warn') return 'warn';
    if (s === 'critical' || s === 'danger' || s === 'error' || s === 'fail' || s === 'failed') return 'danger';
    if (s === 'pending' || s === 'queued') return 'pending';
    return 'neutral';
  }
  function adminTag(status) {
    return '<span class="admin-tag ' + adminStatusClass(status) + '">' + adminEsc(status || '—') + '</span>';
  }
  function adminOpsCard(label, value, note, status) {
    return '<article class="admin-ops-card ' + adminStatusClass(status) + '"><span>' + adminEsc(label) + '</span><strong>' + adminEsc(value) + '</strong><p class="admin-small">' + adminEsc(note || '') + '</p></article>';
  }
  function normalizeChecks(payload) {
    var checks = payload && payload.checks;
    if (Array.isArray(checks)) return checks;
    if (checks && typeof checks === 'object') {
      return Object.keys(checks).map(function (k) { var r = checks[k] || {}; r.key = r.key || k; return r; });
    }
    return [];
  }

  /* ── Status icon map ──────────────────────────────────────────────────────── */
  function statusIcon(s) {
    s = String(s || '').toLowerCase();
    if (s === 'ok' || s === 'pass' || s === 'success') return 'check_circle';
    if (s === 'warning' || s === 'warn') return 'warning';
    if (s === 'danger' || s === 'critical' || s === 'error' || s === 'fail') return 'error';
    if (s === 'pending') return 'schedule';
    return 'help_outline';
  }

  /* ── Tab navigation ───────────────────────────────────────────────────────── */
  document.querySelectorAll('[data-admin-tab-trigger]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tab = btn.getAttribute('data-admin-tab-trigger') || 'cockpit';
      document.querySelectorAll('[data-admin-tab-trigger]').forEach(function (b) {
        b.classList.toggle('active', b === btn);
      });
      document.querySelectorAll('[data-admin-tab-panel]').forEach(function (p) {
        p.classList.toggle('active', p.getAttribute('data-admin-tab-panel') === tab);
      });
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', 'admin.php?tab=' + encodeURIComponent(tab));
      }
    });
  });

  /* ── Modais ───────────────────────────────────────────────────────────────── */
  function adminModalOpen(id) {
    var m = document.getElementById(id);
    if (!m) return;
    m.hidden = false;
    var f = m.querySelector('input:not([type=hidden]),select,textarea');
    if (f) f.focus();
  }
  function adminModalClose(id) {
    var m = document.getElementById(id);
    if (m) m.hidden = true;
  }
  document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-admin-modal-open]');
    if (openBtn) {
      var id = openBtn.getAttribute('data-admin-modal-open');
      if (id === 'modal-edit-user') {
        ['edit-user-id','edit-full-name','edit-email','edit-role','edit-active'].forEach(function (elId) {
          var el = document.getElementById(elId);
          if (!el) return;
          if (el.type === 'checkbox') el.checked = openBtn.dataset.editActive === '1';
          else el.value = openBtn.dataset['edit' + elId.split('-').slice(1).map(function(w){return w[0].toUpperCase()+w.slice(1);}).join('')] || '';
        });
        var uid = document.getElementById('edit-user-id'); if (uid) uid.value = openBtn.dataset.editUserId || '';
        var fname = document.getElementById('edit-full-name'); if (fname) fname.value = openBtn.dataset.editFullName || '';
        var email = document.getElementById('edit-email'); if (email) email.value = openBtn.dataset.editEmail || '';
        var role = document.getElementById('edit-role'); if (role) role.value = openBtn.dataset.editRole || 'viewer';
        var active = document.getElementById('edit-active'); if (active) active.checked = openBtn.dataset.editActive === '1';
      }
      if (id === 'modal-reset-password') {
        var rpId = document.getElementById('reset-password-user-id'); if (rpId) rpId.value = openBtn.dataset.resetUserId || '';
        var rpName = document.getElementById('reset-password-user-name'); if (rpName) rpName.textContent = openBtn.dataset.resetUserName || '';
        var rpPwd = document.querySelector('#form-reset-password input[name=password]'); if (rpPwd) rpPwd.value = '';
      }
      adminModalOpen(id);
      return;
    }
    var closeBtn = e.target.closest('[data-admin-modal-close]');
    if (closeBtn) { adminModalClose(closeBtn.getAttribute('data-admin-modal-close')); return; }
    if (e.target.classList.contains('admin-modal')) { e.target.hidden = true; }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.admin-modal:not([hidden])').forEach(function (m) { m.hidden = true; });
  });

  /* ── Migration filter ─────────────────────────────────────────────────────── */
  window.filterMigrations = function (status, btn) {
    document.querySelectorAll('#adminMigrationsTable tr[data-mig-status]').forEach(function (row) {
      row.style.display = (status === 'all' || row.getAttribute('data-mig-status') === status) ? '' : 'none';
    });
    document.querySelectorAll('[data-mig-filter]').forEach(function (b) {
      b.classList.toggle('active', b === btn);
    });
  };

  /* ── Log filters ──────────────────────────────────────────────────────────── */
  function applyLogFilters() {
    var typeFilter = (document.getElementById('adminLogFilterType') || {}).value || '';
    var userFilter = ((document.getElementById('adminLogFilterUser') || {}).value || '').toLowerCase().trim();
    var dateFilter = (document.getElementById('adminLogFilterDate') || {}).value || '';
    var rows = document.querySelectorAll('#adminLogsTable tr[data-log-type]');
    var visible = 0;
    rows.forEach(function (row) {
      var show = true;
      if (typeFilter && row.dataset.logType !== typeFilter) show = false;
      if (userFilter && !(row.dataset.logUser || '').includes(userFilter)) show = false;
      if (dateFilter && row.dataset.logDate !== dateFilter) show = false;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    var countEl = document.getElementById('adminLogCount');
    if (countEl) countEl.textContent = visible + ' registro' + (visible !== 1 ? 's' : '');
  }
  ['adminLogFilterType','adminLogFilterUser','adminLogFilterDate'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', applyLogFilters);
  });
  var clearLogBtn = document.getElementById('adminLogFilterClear');
  if (clearLogBtn) clearLogBtn.addEventListener('click', function () {
    ['adminLogFilterType','adminLogFilterUser','adminLogFilterDate'].forEach(function (id) {
      var el = document.getElementById(id); if (el) el.value = '';
    });
    applyLogFilters();
  });
  applyLogFilters();

  /* ── System Health ────────────────────────────────────────────────────────── */
  function loadAdminSystemHealth() {
    var summary = document.getElementById('adminSystemHealthSummary');
    var rows = document.getElementById('adminSystemHealthRows');
    if (!summary || !rows) return;
    summary.innerHTML = adminOpsCard('Status', 'Carregando', 'Consultando saude.', 'neutral');
    rows.innerHTML = '<tr><td colspan="4" class="admin-small">Carregando...</td></tr>';
    adminFetchJson('api/system_health.php').then(function (payload) {
      var checks = normalizeChecks(payload);
      var counts = { ok: 0, warn: 0, danger: 0 };
      checks.forEach(function (c) { var cls = adminStatusClass(c.status || ''); if (cls === 'ok') counts.ok++; else if (cls === 'warn') counts.warn++; else if (cls === 'danger') counts.danger++; });
      summary.innerHTML = [
        adminOpsCard('Status', String(payload.status || 'unknown').toUpperCase(), 'Atualizado via API.', payload.status),
        adminOpsCard('Criticos', counts.danger, 'Exigem acao imediata.', counts.danger > 0 ? 'danger' : 'ok'),
        adminOpsCard('Warnings', counts.warn, 'Pendencias operacionais.', counts.warn > 0 ? 'warn' : 'ok'),
        adminOpsCard('OK', counts.ok, 'Checks saudaveis.', 'ok')
      ].join('');
      checks.sort(function (a, b) {
        var order = { danger: 0, critical: 0, error: 0, warn: 1, warning: 1, ok: 2 };
        return (order[String(a.status||'').toLowerCase()]??3) - (order[String(b.status||'').toLowerCase()]??3);
      });
      rows.innerHTML = checks.length ? checks.map(function (c) {
        var name = c.name || c.key || c.label || '—';
        var msg = c.message || c.summary || '—';
        var details = c.details || c.metrics || c;
        return '<tr><td><strong>' + adminEsc(name) + '</strong></td><td>' + adminTag(c.status || '—') + '</td><td class="admin-small">' + adminEsc(msg) + '</td><td><details class="admin-ops-details"><summary>Ver</summary><pre class="admin-ops-pre">' + adminEsc(adminJson(details)) + '</pre></details></td></tr>';
      }).join('') : '<tr><td colspan="4" class="admin-small">Nenhum check.</td></tr>';
    }).catch(function (err) {
      summary.innerHTML = adminOpsCard('Status', 'Erro', err.message, 'danger');
    });
  }

  /* ── Integrations ─────────────────────────────────────────────────────────── */
  function loadAdminIntegrations() {
    var summary = document.getElementById('adminIntegrationsSummary');
    var raw = document.getElementById('adminIntegrationsRaw');
    if (!summary || !raw) return;
    var webhookTarget = document.getElementById('adminWebhookRows');
    var webhookRequest = webhookTarget
      ? adminFetchJson('api/admin_webhook_endpoints.php').catch(function (e) { return { ok: false, error: e.message, companies: [] }; })
      : Promise.resolve({ ok: true, companies: [] });
    summary.innerHTML = adminOpsCard('Status', 'Carregando', 'Consultando integracoes.', 'neutral');
    Promise.all([
      adminFetchJson('api/omie_status.php'),
      adminFetchJson('api/cron_wrapper_status.php').catch(function (e) { return { ok: false, error: e.message, wrappers: [] }; }),
      webhookRequest
    ]).then(function (results) {
        var payload = results[0] || {};
        var cronPayload = results[1] || {};
        var webhookPayload = results[2] || {};
        var webhookDiagnostics = {
          ok: webhookPayload.ok === true,
          base_url: webhookPayload.base_url || null,
          companies: (Array.isArray(webhookPayload.companies) ? webhookPayload.companies : []).map(function (row) {
            return {
              company_id: row.company_id,
              slug: row.slug,
              configured: row.configured === true,
              endpoint_available: row.endpoint_available === true,
              rotated_at: row.rotated_at || null,
              last_received_at: row.last_received_at || null
            };
          })
        };
        raw.textContent = adminJson({ omie_status: payload, cron_wrappers: cronPayload, webhook_endpoints: webhookDiagnostics });
      var worker = payload.worker_queue_status || {};
      var sync = payload.source_sync_status || {};
      var canonical = payload.canonical_freshness_status || {};
      var gold = payload.gold_materialization_status || payload.gold || {};
      var webhook = payload.webhook_status || {};
      var api = payload.api || {};
      var credentials = payload.credentials || {};
      var lastError = payload.last_error || {};
      var queueCounts = worker.counts || {};
      var dead = worker.dead_letters || {};
      summary.innerHTML = [
        adminOpsCard('Sync Omie', String(sync.status || 'unknown').toUpperCase(), sync.message || 'Pipeline delta.', sync.status),
        adminOpsCard('Canonica', String(canonical.status || 'unknown').toUpperCase(), canonical.message || 'Pedidos e NFs.', canonical.status),
        adminOpsCard('Gold', String(gold.status || 'unknown').toUpperCase(), gold.message || 'Marts comerciais.', gold.status),
        adminOpsCard('Worker', String(worker.status || 'unknown').toUpperCase(), worker.message || 'Fila operacional.', worker.status)
      ].join('');
      renderAdminIntegrationCards('adminIntegrationStatusCards', [
        ['API Omie', String(api.status || 'pending').toUpperCase(), api.last_success_at ? 'Sucesso ' + api.last_success_at : (api.last_failure_at ? 'Falha ' + api.last_failure_at : 'Sem teste recente.'), api.status],
        ['Credenciais', credentials.credentials_valid || (credentials.app_key_configured && credentials.app_secret_configured ? 'configuradas' : 'pendente'), credentials.app_key_masked || 'APP_KEY mascarada.', credentials.credentials_valid === 'ok' ? 'ok' : (credentials.app_key_configured && credentials.app_secret_configured ? 'warn' : 'danger')],
        ['Comercial', sync.last_commercial_sync_at || '—', 'Ultimo sync de pedidos.', sync.status],
        ['Financeiro', sync.last_finance_sync_at || '—', 'Titulos financeiros.', sync.status],
        ['Webhook inbox', String(webhook.status || 'unknown').toUpperCase(), webhook.message || 'Eventos Omie.', webhook.status],
        ['Ultimo erro', lastError && lastError.message ? lastError.domain || 'Erro recente' : 'Sem erros', lastError && lastError.message ? lastError.message : 'Operacao saudavel.', lastError && lastError.message ? 'warn' : 'ok']
      ]);
      var data = payload.data || {};
      renderAdminIntegrationCards('adminIntegrationDataCards', Object.keys(data).map(function (key) {
        var row = data[key] || {};
        return [row.label || key, row.count ?? '—', row.message || 'Atualizado ' + (row.last_sync || '—'), row.status || 'neutral'];
      }));
      renderAdminIntegrationDomains(payload.sync_domains || []);
      renderAdminIntegrationRateQueue(payload.rate_limits || {}, worker, queueCounts, dead);
      renderAdminIntegrationCron(cronPayload.wrappers || []);
      renderAdminIntegrationActions(payload.recommended_actions || []);
      renderAdminIntegrationRecent(payload.recent_syncs || []);
      renderAdminIntegrationTables(payload.tables || {});
      renderAdminWebhookRows(webhookPayload);
    }).catch(function (err) {
      raw.textContent = err.message;
      summary.innerHTML = adminOpsCard('Status', 'Erro', err.message, 'danger');
    });
  }

  function renderAdminWebhookRows(payload) {
    var t = document.getElementById('adminWebhookRows');
    if (!t) return;
    var rows = payload && Array.isArray(payload.companies) ? payload.companies : [];
    if (!payload || payload.ok === false) {
      t.innerHTML = '<tr><td colspan="5" class="admin-small">' + adminEsc((payload && (payload.message || payload.error)) || 'Endpoints indisponiveis.') + '</td></tr>';
      return;
    }
    t.innerHTML = rows.length ? rows.map(function (r) {
      var endpoint = r.endpoint || '';
      var status = r.configured ? (r.endpoint_available ? 'configurado' : 'rotacao necessaria') : 'pendente';
      var statusClass = r.endpoint_available ? 'ok' : (r.configured ? 'warn' : 'pending');
      var endpointHtml = endpoint
        ? '<code style="display:block;max-width:620px;overflow-wrap:anywhere">' + adminEsc(endpoint) + '</code>'
        : '<span class="admin-small">Gere um token para obter a URL.</span>';
      var copy = endpoint
        ? '<button class="admin-row-btn" type="button" data-copy-webhook="' + adminEsc(endpoint) + '"><span class="material-symbols-rounded">content_copy</span>Copiar</button>'
        : '';
      return '<tr><td><strong>' + adminEsc(r.name || r.slug) + '</strong><br><span class="admin-small">' + adminEsc(r.slug || '') + '</span></td>'
        + '<td><span class="admin-tag ' + statusClass + '">' + adminEsc(status) + '</span><br><span class="admin-small">Rotacao: ' + adminEsc(r.rotated_at || '—') + '</span></td>'
        + '<td>' + endpointHtml + '</td>'
        + '<td class="admin-small">' + adminEsc(r.last_received_at || 'Nunca recebido') + '</td>'
        + '<td><div class="admin-row-actions">' + copy
        + '<button class="admin-row-btn" type="button" data-rotate-webhook="' + Number(r.company_id || 0) + '"><span class="material-symbols-rounded">key</span>Rotacionar</button>'
        + '</div></td></tr>';
    }).join('') : '<tr><td colspan="5" class="admin-small">Nenhuma integracao Omie ativa.</td></tr>';
  }

  function adminWebhookFeedback(message, type) {
    var el = document.getElementById('adminWebhookFeedback');
    if (!el) return;
    el.hidden = false;
    el.className = 'cc-action-feedback ' + (type || 'info');
    el.textContent = message;
  }

  document.addEventListener('click', function (event) {
    var copyButton = event.target.closest('[data-copy-webhook]');
    if (copyButton) {
      var endpoint = copyButton.getAttribute('data-copy-webhook') || '';
      navigator.clipboard.writeText(endpoint).then(function () {
        adminWebhookFeedback('Endpoint copiado. Cole esta URL no cadastro de webhook da empresa no Omie.', 'ok');
      }).catch(function () {
        adminWebhookFeedback('Nao foi possivel copiar automaticamente. Selecione a URL manualmente.', 'error');
      });
      return;
    }

    var rotateButton = event.target.closest('[data-rotate-webhook]');
    if (!rotateButton) return;
    var companyId = Number(rotateButton.getAttribute('data-rotate-webhook') || 0);
    if (!companyId || !confirm('Rotacionar o token invalida imediatamente a URL anterior desta empresa. Continuar?')) return;
    rotateButton.disabled = true;
    adminWebhookFeedback('Gerando novo endpoint...', 'info');
    adminPost('api/admin_webhook_endpoints.php', { action: 'rotate', company_id: companyId })
      .then(function () {
        adminWebhookFeedback('Novo endpoint gerado. Atualize a URL no Omie.', 'ok');
        loadAdminIntegrations();
      })
      .catch(function (e) { adminWebhookFeedback(e.message, 'error'); })
      .finally(function () { rotateButton.disabled = false; });
  });

  var rotateAllWebhooks = document.getElementById('btnRotateAllWebhooks');
  if (rotateAllWebhooks) rotateAllWebhooks.addEventListener('click', function () {
    if (!confirm('Isto invalida todas as URLs de webhook atuais. Depois sera necessario atualizar as cinco empresas no Omie. Continuar?')) return;
    rotateAllWebhooks.disabled = true;
    adminWebhookFeedback('Gerando endpoints para todas as empresas...', 'info');
    adminPost('api/admin_webhook_endpoints.php', { action: 'rotate_all' })
      .then(function () {
        adminWebhookFeedback('Endpoints gerados. Copie cada URL e cadastre na empresa correspondente no Omie.', 'ok');
        loadAdminIntegrations();
      })
      .catch(function (e) { adminWebhookFeedback(e.message, 'error'); })
      .finally(function () { rotateAllWebhooks.disabled = false; });
  });

  function renderAdminIntegrationCards(targetId, rows) {
    var t = document.getElementById(targetId);
    if (!t) return;
    t.innerHTML = rows.length ? rows.map(function (r) { return adminOpsCard(r[0], r[1], r[2], r[3]); }).join('') : adminOpsCard('Sem dados', '—', 'Nenhum dado retornado.', 'neutral');
  }
  function renderAdminIntegrationDomains(rows) {
    var t = document.getElementById('adminIntegrationDomainRows');
    if (!t) return;
    t.innerHTML = rows.length ? rows.map(function (r) {
      return '<tr><td><strong>' + adminEsc(r.label || r.domain || '—') + '</strong><br><span class="admin-small">' + adminEsc(r.domain || '') + '</span></td><td>' + adminTag(r.status || '—') + '</td><td class="admin-small">' + adminEsc(r.last_success_at || '—') + '</td><td class="admin-small">' + adminAge(r.age_minutes) + '</td><td class="admin-small"><code>' + adminEsc(r.recommended_command || '—') + '</code></td><td class="admin-small">' + adminEsc(r.last_error || r.message || '—') + '</td></tr>';
    }).join('') : '<tr><td colspan="6" class="admin-small">Nenhum dominio.</td></tr>';
  }
  function renderAdminIntegrationRateQueue(rate, worker, counts, dead) {
    var methods = rate.methods || [];
    var limits = rate.limits || {};
    var domains = rate.domains || {};
    var queuePending = Number(worker.queued ?? worker.pending ?? counts.queued ?? 0) + Number(counts.failed_retryable ?? 0);
    renderAdminIntegrationCards('adminIntegrationRateQueue', [
      ['Limite global/min', limits.global_per_ip_per_minute ?? '—', 'Limite documentado Omie.', 'ok'],
      ['App+metodo/min', limits.per_app_method_per_minute ?? '—', 'Por APP_KEY/metodo.', 'ok'],
      ['Metodos mapeados', methods.length, Object.keys(domains).length + ' dominio(s).', 'ok'],
      ['Fila pendente', queuePending, 'queued + failed_retryable.', queuePending > 0 ? 'warn' : 'ok'],
      ['Em execucao', worker.running ?? counts.running ?? 0, 'Jobs rodando.', Number(worker.running ?? 0) > 0 ? 'warn' : 'ok'],
      ['Dead-letter', dead.unresolved ?? 0, 'Nao resolvidas.', Number(dead.unresolved ?? 0) > 0 ? 'danger' : 'ok']
    ]);
  }
  function renderAdminIntegrationCron(rows) {
    var t = document.getElementById('adminIntegrationCronRows');
    if (!t) return;
    t.innerHTML = rows.length ? rows.map(function (r) {
      return '<tr><td><strong>' + adminEsc(r.label || r.key || '—') + '</strong></td><td>' + adminTag(r.status || '—') + '</td><td class="admin-small">' + adminEsc(r.frequency || '—') + '</td><td class="admin-small">' + adminAge(r.age_minutes) + '</td><td class="admin-small"><code>' + adminEsc(r.command || '—') + '</code></td><td><details class="admin-ops-details"><summary>Log</summary><pre class="admin-ops-pre">' + adminEsc((r.last_lines || []).join('\n') || 'Sem log.') + '</pre></details></td></tr>';
    }).join('') : '<tr><td colspan="6" class="admin-small">Sem wrappers.</td></tr>';
  }
  function renderAdminIntegrationActions(actions) {
    var t = document.getElementById('adminIntegrationActions');
    if (!t) return;
    t.innerHTML = actions.length ? actions.map(function (r) {
      return '<div class="admin-ops-list-item"><strong>' + adminEsc(r.message || r.key || '—') + '</strong><span class="admin-small">' + adminEsc(r.severity || 'info') + (r.link ? ' · ' + r.link : '') + '</span></div>';
    }).join('') : '<div class="admin-ops-list-item"><strong>Nenhuma acao recomendada</strong><span class="admin-small">Sistema operacional sem pendencias.</span></div>';
  }
  function renderAdminIntegrationRecent(rows) {
    var t = document.getElementById('adminIntegrationRecentRows');
    if (!t) return;
    t.innerHTML = rows.length ? rows.map(function (r) {
      return '<tr><td>' + adminEsc(r.domain || '—') + '</td><td>' + adminEsc(r.type || '—') + '</td><td>' + adminTag(r.status || '—') + '</td><td class="admin-small">' + adminEsc(r.started_at || '—') + '</td><td>' + adminEsc(r.items_processed ?? '—') + '</td></tr>';
    }).join('') : '<tr><td colspan="5" class="admin-small">Nenhum sync recente.</td></tr>';
  }
  function renderAdminIntegrationTables(tables) {
    var t = document.getElementById('adminIntegrationTables');
    if (!t) return;
    var rows = [];
    Object.keys(tables || {}).forEach(function (group) {
      var gRows = Array.isArray(tables[group]) ? tables[group] : Object.keys(tables[group] || {}).map(function (k) { var r = tables[group][k] || {}; r.table = r.table || k; return r; });
      gRows.forEach(function (row) { rows.push({ group: group, row: row }); });
    });
    t.innerHTML = '<table class="admin-table admin-table-compact"><thead><tr><th>Camada</th><th>Tabela</th><th>Status</th><th>Registros</th><th>Atualizado</th><th>Diagnostico</th></tr></thead><tbody>' + (rows.length ? rows.map(function (item) {
      var r = item.row || {};
      return '<tr><td>' + adminEsc(item.group) + '</td><td><strong>' + adminEsc(r.table || r.name || '—') + '</strong></td><td>' + adminTag(r.status || '—') + '</td><td>' + adminEsc(r.rows ?? r.count ?? '—') + '</td><td class="admin-small">' + adminEsc(r.last_updated_at || '—') + '</td><td class="admin-small">' + adminEsc(r.message || '—') + '</td></tr>';
    }).join('') : '<tr><td colspan="6" class="admin-small">Nenhuma tabela.</td></tr>') + '</tbody></table>';
  }

  /* ── Metrics ──────────────────────────────────────────────────────────────── */
  var adminMetricRowsCache = [];
  function flattenMetricRows(payload) {
    var out = [];
    Object.keys(payload.kpis_by_domain || {}).forEach(function (d) { (payload.kpis_by_domain[d] || []).forEach(function (item) { out.push(item); }); });
    return out;
  }
  function renderAdminMetricsRows() {
    var rows = document.getElementById('adminMetricsRows');
    var search = document.getElementById('adminMetricsSearch');
    if (!rows) return;
    var q = String((search && search.value) || '').toLowerCase();
    var filtered = adminMetricRowsCache.filter(function (item) { return !q || adminJson(item).toLowerCase().includes(q); });
    rows.innerHTML = filtered.length ? filtered.slice(0, 120).map(function (item) {
      return '<tr><td><strong>' + adminEsc(item.label || item.name || item.id || '—') + '</strong><br><span class="admin-small">' + adminEsc(item.id || '') + '</span></td><td>' + adminEsc(item.domain || '—') + '</td><td>' + adminTag(item.status || '—') + '</td><td class="admin-small">' + adminEsc(item.source_of_truth || item.source || '—') + '</td><td class="admin-small">' + adminEsc(item.owner || '—') + '</td></tr>';
    }).join('') : '<tr><td colspan="5" class="admin-small">Nenhum KPI.</td></tr>';
  }
  function loadAdminMetrics() {
    var summary = document.getElementById('adminMetricsSummary');
    if (!summary) return;
    summary.innerHTML = adminOpsCard('KPIs', 'Carregando', 'Consultando catalogo.', 'neutral');
    adminFetchJson('api/metric_catalog.php').then(function (payload) {
      var totals = payload.totals || {};
      adminMetricRowsCache = flattenMetricRows(payload);
      summary.innerHTML = [
        adminOpsCard('KPIs', totals.kpis ?? adminMetricRowsCache.length, 'Total registrado.', 'ok'),
        adminOpsCard('Ativos', totals.active ?? '—', 'Em uso operacional.', 'ok'),
        adminOpsCard('Draft', totals.draft ?? '—', 'Em evolucao.', Number(totals.draft || 0) > 0 ? 'warn' : 'ok'),
        adminOpsCard('Dominios', Object.keys(payload.kpis_by_domain || {}).length, 'Grupos semanticos.', 'ok')
      ].join('');
      renderAdminMetricsRows();
    }).catch(function (err) {
      summary.innerHTML = adminOpsCard('Status', 'Erro', err.message, 'danger');
    });
    var metricSearch = document.getElementById('adminMetricsSearch');
    if (metricSearch) metricSearch.addEventListener('input', renderAdminMetricsRows);
  }

  /* ── COCKPIT (Control Center) ─────────────────────────────────────────────── */
  var ccAutoRefreshTimer = null;

  function ccSetDot(status) {
    var dot = document.getElementById('ccStatusDot');
    if (!dot) return;
    dot.className = 'cc-status-dot ' + adminStatusClass(status);
  }

  function ccSetHero(label, sub, status) {
    var l = document.getElementById('ccHeroLabel');
    var s = document.getElementById('ccHeroSub');
    if (l) l.textContent = label;
    if (s) s.textContent = sub;
    ccSetDot(status);
  }

  function ccRenderKpis(omie, health) {
    var strip = document.getElementById('ccKpiStrip');
    if (!strip) return;
    var api = omie.api || {};
    var sync = omie.source_sync_status || {};
    var gold = omie.gold_materialization_status || omie.gold || {};
    var worker = omie.worker_queue_status || {};
    var checks = normalizeChecks(health);
    var errors = checks.filter(function (c) { return adminStatusClass(c.status) === 'danger'; }).length;
    var pending = Number(worker.queued ?? worker.pending ?? 0);

    var kpis = [
      { icon: 'wifi_tethering', label: 'Omie API', value: api.status ? String(api.status).toUpperCase() : '—', sub: api.last_latency_ms ? api.last_latency_ms + ' ms' : (api.last_success_at || 'Sem teste'), status: api.status || 'neutral' },
      { icon: 'sync', label: 'Sync', value: sync.age_minutes !== undefined ? adminAge(sync.age_minutes) : (sync.status ? String(sync.status).toUpperCase() : '—'), sub: sync.message || 'Pipeline delta', status: sync.status || 'neutral' },
      { icon: 'layers', label: 'Gold', value: gold.status ? String(gold.status).toUpperCase() : '—', sub: gold.message || 'Marts comerciais', status: gold.status || 'neutral' },
      { icon: 'queue', label: 'Fila', value: pending > 0 ? pending + ' jobs' : 'Livre', sub: worker.message || 'Worker operacional', status: pending > 5 ? 'warn' : 'ok' },
      { icon: 'error_outline', label: 'Erros', value: errors > 0 ? errors + ' critico' + (errors > 1 ? 's' : '') : 'Nenhum', sub: checks.length + ' checks no total', status: errors > 0 ? 'danger' : 'ok' }
    ];

    strip.innerHTML = kpis.map(function (k) {
      var cls = adminStatusClass(k.status);
      return '<div class="cc-kpi ' + cls + '"><div class="cc-kpi__accent"></div><span class="cc-kpi__icon material-symbols-rounded">' + adminEsc(k.icon) + '</span><div class="cc-kpi__label">' + adminEsc(k.label) + '</div><div class="cc-kpi__value">' + adminEsc(k.value) + '</div><div class="cc-kpi__sub">' + adminEsc(k.sub) + '</div></div>';
    }).join('');
  }

  function ccRenderHealthList(health) {
    var list = document.getElementById('ccHealthList');
    var badgesEl = document.getElementById('ccHealthBadges');
    if (!list) return;
    var checks = normalizeChecks(health);
    var counts = { ok: 0, warn: 0, danger: 0 };
    checks.forEach(function (c) { var cls = adminStatusClass(c.status || ''); if (cls === 'ok') counts.ok++; else if (cls === 'warn') counts.warn++; else if (cls === 'danger') counts.danger++; });
    if (badgesEl) {
      badgesEl.innerHTML = (counts.danger > 0 ? '<span class="admin-tag danger">✗ ' + counts.danger + ' critico</span>' : '') +
        (counts.warn > 0 ? '<span class="admin-tag warn">⚠ ' + counts.warn + ' warning</span>' : '') +
        '<span class="admin-tag ok">✓ ' + counts.ok + ' ok</span>';
    }
    checks.sort(function (a, b) {
      var order = { danger: 0, critical: 0, error: 0, warn: 1, warning: 1, ok: 2 };
      return (order[String(a.status||'').toLowerCase()]??3) - (order[String(b.status||'').toLowerCase()]??3);
    });
    var shown = checks.slice(0, 12);
    list.innerHTML = shown.length ? shown.map(function (c) {
      var cls = adminStatusClass(c.status || '');
      var icon = statusIcon(c.status);
      var name = c.name || c.label || c.key || '—';
      var msg = c.message || c.summary || '';
      var action = c.recommended_action || '';
      return '<div class="cc-health-item ' + cls + '"><span class="cc-health-icon material-symbols-rounded">' + icon + '</span><div><div class="cc-health-label">' + adminEsc(name) + '</div>' + (msg ? '<div class="cc-health-msg">' + adminEsc(msg) + '</div>' : '') + (action ? '<div class="cc-health-msg" style="margin-top:4px;font-style:italic">→ ' + adminEsc(action) + '</div>' : '') + '</div></div>';
    }).join('') + (checks.length > 12 ? '<div class="cc-health-item neutral" style="justify-content:center"><span class="admin-small">+ ' + (checks.length - 12) + ' checks adicionais — ver aba Saúde</span></div>' : '')
    : '<div class="cc-health-item neutral"><span class="cc-health-icon material-symbols-rounded">help_outline</span><div class="cc-health-label">Sem checks retornados.</div></div>';
  }

  function ccRenderRecent(syncs) {
    var list = document.getElementById('ccRecentOps');
    if (!list) return;
    if (!syncs || syncs.length === 0) {
      list.innerHTML = '<div class="cc-recent-item"><span class="cc-recent-icon material-symbols-rounded">info</span><span class="cc-recent-label">Nenhuma operacao recente.</span></div>';
      return;
    }
    list.innerHTML = syncs.slice(0, 10).map(function (r) {
      var cls = adminStatusClass(r.status || '');
      var icon = statusIcon(r.status);
      var label = (r.domain || '—') + ' · ' + (r.type || 'sync') + (r.items_processed ? ' — ' + r.items_processed + ' itens' : '');
      return '<div class="cc-recent-item"><span class="cc-recent-icon material-symbols-rounded ' + cls + '">' + icon + '</span><span class="cc-recent-label">' + adminEsc(label) + '</span><span class="cc-recent-time">' + adminEsc(r.started_at || '') + '</span></div>';
    }).join('');
  }

  var DOMAIN_ICONS = { commercial: 'shopping_cart', finance: 'account_balance_wallet', fiscal: 'receipt_long', clients: 'person', geo: 'public' };
  function ccRenderDomains(domains) {
    var el = document.getElementById('ccDomains');
    if (!el) return;
    if (!domains || domains.length === 0) {
      el.innerHTML = '<div class="cc-domain-row"><span class="cc-domain-icon material-symbols-rounded">info</span><div class="cc-domain-info"><div class="cc-domain-name">Nenhum dominio retornado.</div></div></div>';
      return;
    }
    el.innerHTML = domains.map(function (d) {
      var icon = DOMAIN_ICONS[d.domain] || 'hub';
      var cls = adminStatusClass(d.status || '');
      var ageStr = d.age_minutes !== undefined ? adminAge(d.age_minutes) : (d.last_success_at || '—');
      var tag = adminTag(d.status || '—');
      var cmd = d.recommended_command || '';
      var actions = cmd ? '<div class="cc-domain-actions"><button class="admin-row-btn" onclick="ccRunConsoleCmd(\'' + adminEsc(cmd.replace(/'/g, "\\'")) + '\')" title="' + adminEsc(cmd) + '"><span class="material-symbols-rounded">play_arrow</span>Sync</button></div>' : '';
      return '<div class="cc-domain-row"><span class="cc-domain-icon material-symbols-rounded" style="color:var(--page-accent)">' + adminEsc(icon) + '</span><div class="cc-domain-info"><div class="cc-domain-name">' + adminEsc(d.label || d.domain || '—') + '</div><div class="cc-domain-age">' + tag + ' &nbsp;há ' + adminEsc(ageStr) + (d.last_error ? ' · <span style="color:#dc2626">' + adminEsc(d.last_error.slice(0, 60)) + '</span>' : '') + '</div></div>' + actions + '</div>';
    }).join('');
  }

  function ccShowFeedback(message, type) {
    var el = document.getElementById('ccActionFeedback');
    if (!el) return;
    el.hidden = false;
    el.className = 'cc-action-feedback ' + (type || 'info');
    el.textContent = message;
    setTimeout(function () { if (el) el.hidden = true; }, 8000);
  }

  function ccBtnLoading(id, loading) {
    var btn = document.getElementById(id);
    if (!btn) return;
    btn.disabled = loading;
    if (loading) btn.classList.add('running');
    else btn.classList.remove('running');
  }

  /* ── Quick action handlers ────────────────────────────────────────────────── */
  var ccActionBtnTestOmie = document.getElementById('ccActionTestOmie');
  if (ccActionBtnTestOmie) ccActionBtnTestOmie.addEventListener('click', function () {
    ccBtnLoading('ccActionTestOmie', true);
    ccShowFeedback('Testando conexão com Omie…', 'info');
    adminPost('api/omie_test_connection.php', {}).then(function (d) {
      var msg = d.ok ? ('✓ Omie OK — latência ' + (d.latency_ms || '?') + ' ms · ' + (d.message || '')) : ('✗ Falha: ' + (d.message || d.error || 'Erro desconhecido'));
      ccShowFeedback(msg, d.ok ? 'ok' : 'error');
      loadAdminCockpit();
    }).catch(function (e) {
      ccShowFeedback('✗ Erro: ' + e.message, 'error');
    }).finally(function () { ccBtnLoading('ccActionTestOmie', false); });
  });

  var ccActionBtnSyncDelta = document.getElementById('ccActionSyncDelta');
  if (ccActionBtnSyncDelta) ccActionBtnSyncDelta.addEventListener('click', function () {
    ccBtnLoading('ccActionSyncDelta', true);
    ccShowFeedback('Agendando sync delta…', 'info');
    adminPost('api/omie_delta_sync.php', { domains: ['commercial', 'finance', 'fiscal'] }).then(function (d) {
      ccShowFeedback('✓ Sync agendado — job ' + (d.job_id || d.run_id || '?'), 'ok');
      setTimeout(loadAdminCockpit, 3000);
    }).catch(function (e) {
      ccShowFeedback('✗ Erro ao agendar sync: ' + e.message, 'error');
    }).finally(function () { ccBtnLoading('ccActionSyncDelta', false); });
  });

  var ccActionBtnMaterialize = document.getElementById('ccActionMaterialize');
  if (ccActionBtnMaterialize) ccActionBtnMaterialize.addEventListener('click', function () {
    ccBtnLoading('ccActionMaterialize', true);
    ccShowFeedback('Agendando materialização Gold…', 'info');
    adminPost('api/omie_materialize_commercial.php', {}).then(function (d) {
      ccShowFeedback('✓ Materialização agendada — job ' + (d.job_id || '?'), 'ok');
      setTimeout(loadAdminCockpit, 3000);
    }).catch(function (e) {
      ccShowFeedback('✗ Erro ao agendar materialização: ' + e.message, 'error');
    }).finally(function () { ccBtnLoading('ccActionMaterialize', false); });
  });

  var ccActionBtnClearCache = document.getElementById('ccActionClearCache');
  if (ccActionBtnClearCache) ccActionBtnClearCache.addEventListener('click', function () {
    ccBtnLoading('ccActionClearCache', true);
    ccShowFeedback('Limpando cache…', 'info');
    adminPost('api/admin_clear_cache.php', { target: 'all' }).then(function (d) {
      ccShowFeedback('✓ Cache limpo — ' + (d.cleared ? d.cleared.length + ' entradas removidas' : 'OK'), 'ok');
    }).catch(function (e) {
      ccShowFeedback('✗ Erro ao limpar cache: ' + e.message, 'error');
    }).finally(function () { ccBtnLoading('ccActionClearCache', false); });
  });

  /* ── Test connection button na aba Integrações ────────────────────────────── */
  var btnTestOmieIntegrations = document.getElementById('btnTestOmieConnection');
  if (btnTestOmieIntegrations) btnTestOmieIntegrations.addEventListener('click', function () {
    btnTestOmieIntegrations.disabled = true;
    var resultEl = document.getElementById('omieTestResult');
    if (resultEl) { resultEl.hidden = false; resultEl.className = 'cc-action-feedback info'; resultEl.textContent = 'Testando conexão…'; }
    adminPost('api/omie_test_connection.php', {}).then(function (d) {
      var msg = d.ok ? '✓ Omie OK — latência ' + (d.latency_ms || '?') + ' ms' : '✗ Falha: ' + (d.message || d.error || 'Erro');
      if (resultEl) { resultEl.className = 'cc-action-feedback ' + (d.ok ? 'ok' : 'error'); resultEl.textContent = msg; }
    }).catch(function (e) {
      if (resultEl) { resultEl.className = 'cc-action-feedback error'; resultEl.textContent = '✗ ' + e.message; }
    }).finally(function () { btnTestOmieIntegrations.disabled = false; });
  });

  /* Console cmd quick-run (para domains) */
  window.ccRunConsoleCmd = function (cmd) {
    if (!window.adminRunConsoleCommand) { alert('Console não disponível nesta aba.'); return; }
    window.location.href = 'admin.php?tab=console&cmd=' + encodeURIComponent(cmd);
  };

  function loadAdminCockpit() {
    var raw = document.getElementById('ccRaw');
    ccSetHero('Control Center', 'Verificando status do sistema…', 'unknown');
    Promise.all([
      adminFetchJson('api/omie_status.php'),
      adminFetchJson('api/system_health.php').catch(function (e) { return { status: 'error', error: e.message, checks: [] }; })
    ]).then(function (results) {
      var omie = results[0] || {};
      var health = results[1] || {};
      if (raw) raw.textContent = adminJson({ omie: omie, health: health });
      // Hero
      var checks = normalizeChecks(health);
      var dangerCount = checks.filter(function (c) { return adminStatusClass(c.status) === 'danger'; }).length;
      var warnCount = checks.filter(function (c) { return adminStatusClass(c.status) === 'warn'; }).length;
      var heroStatus = dangerCount > 0 ? 'danger' : (warnCount > 0 ? 'warn' : 'ok');
      var heroLabel = dangerCount > 0 ? (dangerCount + ' problema' + (dangerCount > 1 ? 's' : '') + ' crítico' + (dangerCount > 1 ? 's' : '')) : (warnCount > 0 ? (warnCount + ' atenção' + (warnCount > 1 ? 'ões' : '')) : 'Sistema saudável');
      var now = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
      var lastEl = document.getElementById('ccLastUpdated');
      if (lastEl) lastEl.textContent = 'Atualizado ' + now;
      ccSetHero(heroLabel, (dangerCount + warnCount > 0 ? 'Verifique os health checks abaixo.' : 'Todos os sistemas operacionais.'), heroStatus);
      ccRenderKpis(omie, health);
      ccRenderHealthList(health);
      ccRenderRecent(omie.recent_syncs || []);
      ccRenderDomains(omie.sync_domains || []);
    }).catch(function (err) {
      ccSetHero('Erro ao carregar', err.message, 'danger');
      if (raw) raw.textContent = err.message;
    });
  }

  /* ── Refresh handlers ─────────────────────────────────────────────────────── */
  document.querySelectorAll('[data-admin-refresh]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.getAttribute('data-admin-refresh');
      if (target === 'system-health') loadAdminSystemHealth();
      if (target === 'integrations') loadAdminIntegrations();
      if (target === 'metrics') loadAdminMetrics();
      if (target === 'cockpit') loadAdminCockpit();
    });
  });

  /* ── Auto-refresh cockpit a cada 60s ─────────────────────────────────────── */
  function startCockpitAutoRefresh() {
    if (ccAutoRefreshTimer) clearInterval(ccAutoRefreshTimer);
    ccAutoRefreshTimer = setInterval(function () {
      var cockpitPanel = document.querySelector('[data-admin-tab-panel="cockpit"]');
      if (cockpitPanel && cockpitPanel.classList.contains('active')) loadAdminCockpit();
    }, 60000);
  }

  /* ── Initial loads ────────────────────────────────────────────────────────── */
  loadAdminSystemHealth();
  loadAdminIntegrations();
  loadAdminMetrics();
  loadAdminCockpit();
  startCockpitAutoRefresh();
})();
</script>
