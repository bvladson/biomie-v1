<script>
function filterMigrations(status, btn) {
  var rows = document.querySelectorAll('#adminMigrationsTable tr[data-mig-status]');
  rows.forEach(function (row) {
    if (status === 'all') { row.style.display = ''; return; }
    row.style.display = row.getAttribute('data-mig-status') === status ? '' : 'none';
  });
  var btns = document.querySelectorAll('[data-mig-filter]');
  btns.forEach(function (b) { b.classList.toggle('active', b === btn); });
}
(function () {
  function showAdminTab(tab) {
    document.querySelectorAll('[data-admin-tab-trigger]').forEach(function (button) {
      button.classList.toggle('active', button.getAttribute('data-admin-tab-trigger') === tab);
    });
    document.querySelectorAll('[data-admin-tab-panel]').forEach(function (panel) {
      panel.classList.toggle('active', panel.getAttribute('data-admin-tab-panel') === tab);
    });
  }

  document.querySelectorAll('[data-admin-tab-trigger]').forEach(function (button) {
    button.addEventListener('click', function () {
      var tab = button.getAttribute('data-admin-tab-trigger') || 'users';
      showAdminTab(tab);
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', 'admin.php?tab=' + encodeURIComponent(tab));
      }
    });
  });

  function adminModalOpen(modalId) {
    var modal = document.getElementById(modalId);
    if (modal) {
      modal.hidden = false;
      var firstInput = modal.querySelector('input:not([type=hidden]),select,textarea');
      if (firstInput) { firstInput.focus(); }
    }
  }
  function adminModalClose(modalId) {
    var modal = document.getElementById(modalId);
    if (modal) { modal.hidden = true; }
  }

  document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-admin-modal-open]');
    if (openBtn) {
      var modalId = openBtn.getAttribute('data-admin-modal-open');

      if (modalId === 'modal-edit-user') {
        var uid    = document.getElementById('edit-user-id');
        var fname  = document.getElementById('edit-full-name');
        var email  = document.getElementById('edit-email');
        var role   = document.getElementById('edit-role');
        var active = document.getElementById('edit-active');
        if (uid)    uid.value    = openBtn.dataset.editUserId   || '';
        if (fname)  fname.value  = openBtn.dataset.editFullName || '';
        if (email)  email.value  = openBtn.dataset.editEmail    || '';
        if (role)   role.value   = openBtn.dataset.editRole     || 'viewer';
        if (active) active.checked = openBtn.dataset.editActive === '1';
      }

      if (modalId === 'modal-reset-password') {
        var rpId   = document.getElementById('reset-password-user-id');
        var rpName = document.getElementById('reset-password-user-name');
        var rpPwd  = document.querySelector('#form-reset-password input[name=password]');
        if (rpId)   rpId.value          = openBtn.dataset.resetUserId   || '';
        if (rpName) rpName.textContent  = openBtn.dataset.resetUserName || '';
        if (rpPwd)  rpPwd.value         = '';
      }

      adminModalOpen(modalId);
      return;
    }

    var closeBtn = e.target.closest('[data-admin-modal-close]');
    if (closeBtn) {
      adminModalClose(closeBtn.getAttribute('data-admin-modal-close'));
      return;
    }

    if (e.target.classList.contains('admin-modal')) {
      e.target.hidden = true;
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.admin-modal:not([hidden])').forEach(function (m) {
        m.hidden = true;
      });
    }
  });

  function adminEsc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function adminStatusClass(status) {
    var s = String(status || '').toLowerCase();
    if (s === 'ok' || s === 'pass' || s === 'success') return 'ok';
    if (s === 'warning' || s === 'warn') return 'warning';
    if (s === 'critical' || s === 'danger' || s === 'error' || s === 'fail' || s === 'failed') return 'critical';
    return 'neutral';
  }

  function adminTag(status) {
    return '<span class="admin-tag ' + adminStatusClass(status) + '">' + adminEsc(status || '-') + '</span>';
  }

  function adminOpsCard(label, value, note, status) {
    return '<article class="admin-ops-card ' + adminStatusClass(status) + '"><span>' + adminEsc(label) + '</span><strong>' + adminEsc(value) + '</strong><p class="admin-small">' + adminEsc(note || '') + '</p></article>';
  }

  function adminJson(value) {
    try { return JSON.stringify(value, null, 2); } catch (e) { return String(value); }
  }

  function adminFetchJson(url) {
    return fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) {
            throw new Error((payload && (payload.message || payload.error)) || ('HTTP ' + response.status));
          }
          return payload;
        });
      });
  }

  function normalizeChecks(payload) {
    var checks = payload && payload.checks;
    if (Array.isArray(checks)) return checks;
    if (checks && typeof checks === 'object') {
      return Object.keys(checks).map(function (key) {
        var row = checks[key] || {};
        row.key = row.key || key;
        return row;
      });
    }
    return [];
  }

  function loadAdminSystemHealth() {
    var summary = document.getElementById('adminSystemHealthSummary');
    var rows = document.getElementById('adminSystemHealthRows');
    if (!summary || !rows) return;

    summary.innerHTML = adminOpsCard('Status', 'Carregando', 'Consultando saude do sistema.', 'neutral');
    rows.innerHTML = '<tr><td colspan="4" class="admin-small">Carregando checks...</td></tr>';

    adminFetchJson('api/system_health.php').then(function (payload) {
      var checks = normalizeChecks(payload);
      var counts = { ok: 0, warning: 0, critical: 0, neutral: 0 };
      checks.forEach(function (check) {
        var cls = adminStatusClass(check.status || check.severity || check.level);
        counts[cls] = (counts[cls] || 0) + 1;
      });

      summary.innerHTML = [
        adminOpsCard('Status geral', String(payload.status || 'unknown').toUpperCase(), 'Atualizado via API de saude.', payload.status),
        adminOpsCard('Criticos', counts.critical, 'Checks que exigem acao.', counts.critical > 0 ? 'critical' : 'ok'),
        adminOpsCard('Warnings', counts.warning, 'Pendencias operacionais.', counts.warning > 0 ? 'warning' : 'ok'),
        adminOpsCard('OK', counts.ok, 'Checks saudaveis.', 'ok')
      ].join('');

      checks.sort(function (a, b) {
        var order = { critical: 0, error: 0, warning: 1, warn: 1, ok: 2 };
        return (order[String(a.status || '').toLowerCase()] ?? 3) - (order[String(b.status || '').toLowerCase()] ?? 3);
      });

      rows.innerHTML = checks.length ? checks.map(function (check) {
        var name = check.name || check.key || check.id || check.label || '-';
        var message = check.message || check.summary || check.description || '-';
        var details = check.details || check.metrics || check.data || check;
        return '<tr><td><strong>' + adminEsc(name) + '</strong></td><td>' + adminTag(check.status || check.severity || '-') + '</td><td class="admin-small">' + adminEsc(message) + '</td><td><details class="admin-ops-details"><summary>Ver</summary><pre class="admin-ops-pre">' + adminEsc(adminJson(details)) + '</pre></details></td></tr>';
      }).join('') : '<tr><td colspan="4" class="admin-small">Nenhum check retornado.</td></tr>';
    }).catch(function (error) {
      summary.innerHTML = adminOpsCard('Status', 'Erro', error.message, 'critical');
      rows.innerHTML = '<tr><td colspan="4" class="admin-small">Nao foi possivel carregar a saude do sistema.</td></tr>';
    });
  }

  function loadAdminIntegrations() {
    var summary = document.getElementById('adminIntegrationsSummary');
    var raw = document.getElementById('adminIntegrationsRaw');
    if (!summary || !raw) return;

    summary.innerHTML = adminOpsCard('Status', 'Carregando', 'Consultando integracoes.', 'neutral');
    Promise.all([
      adminFetchJson('api/omie_status.php'),
      adminFetchJson('api/cron_wrapper_status.php').catch(function (error) {
        return { ok: false, error: error.message, wrappers: [] };
      })
    ]).then(function (results) {
      var payload = results[0] || {};
      var cronPayload = results[1] || {};
      raw.textContent = adminJson({ omie_status: payload, cron_wrappers: cronPayload });

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
        adminOpsCard('Sync Omie', String(sync.status || 'unknown').toUpperCase(), sync.message || 'Pipeline delta/backfill.', sync.status),
        adminOpsCard('Origem canonica', String(canonical.status || 'unknown').toUpperCase(), canonical.message || 'Pedidos, NFs e titulos.', canonical.status),
        adminOpsCard('Gold', String(gold.status || 'unknown').toUpperCase(), gold.message || 'Materializacao dos marts.', gold.status),
        adminOpsCard('Worker/Fila', String(worker.status || 'unknown').toUpperCase(), worker.message || 'Fila operacional.', worker.status)
      ].join('');

      renderAdminIntegrationCards('adminIntegrationStatusCards', [
        ['API Omie', String(api.status || 'pending').toUpperCase(), api.last_success_at ? ('Ultimo sucesso ' + api.last_success_at) : (api.last_failure_at ? ('Ultima falha ' + api.last_failure_at) : 'Sem teste recente.'), api.status],
        ['Credenciais', credentials.credentials_valid || (credentials.app_key_configured && credentials.app_secret_configured ? 'configuradas' : 'pendente'), credentials.app_key_masked || 'APP_KEY mascarada indisponivel.', credentials.credentials_valid === 'ok' ? 'ok' : (credentials.app_key_configured && credentials.app_secret_configured ? 'warning' : 'critical')],
        ['Sync comercial', sync.last_commercial_sync_at || '-', 'Fiscal/NF roda dentro do dominio commercial.', sync.status],
        ['Sync financeiro', sync.last_finance_sync_at || '-', 'Titulos financeiros canonicos.', sync.status],
        ['Webhook inbox', String(webhook.status || 'unknown').toUpperCase(), webhook.message || 'Eventos recebidos da Omie.', webhook.status],
        ['Ultimo erro', lastError && lastError.message ? lastError.domain || 'Erro recente' : 'Sem erro recente', lastError && lastError.message ? lastError.message : 'Nenhum erro operacional retornado.', lastError && lastError.message ? 'warning' : 'ok']
      ]);

      var data = payload.data || {};
      renderAdminIntegrationCards('adminIntegrationDataCards', Object.keys(data).map(function (key) {
        var row = data[key] || {};
        return [row.label || key, row.count ?? '-', row.message || ('Atualizado em ' + (row.last_sync || '-')), row.status || 'neutral'];
      }));

      renderAdminIntegrationDomains(payload.sync_domains || []);
      renderAdminIntegrationRateQueue(payload.rate_limits || {}, worker, queueCounts, dead);
      renderAdminIntegrationCron(cronPayload.wrappers || []);
      renderAdminIntegrationActions(payload.recommended_actions || []);
      renderAdminIntegrationRecent(payload.recent_syncs || []);
      renderAdminIntegrationTables(payload.tables || {});
    }).catch(function (error) {
      raw.textContent = error.message;
      summary.innerHTML = adminOpsCard('Status', 'Erro', error.message, 'critical');
    });
  }

  function renderAdminIntegrationCards(targetId, rows) {
    var target = document.getElementById(targetId);
    if (!target) return;
    target.innerHTML = rows.length ? rows.map(function (row) {
      return adminOpsCard(row[0], row[1], row[2], row[3]);
    }).join('') : adminOpsCard('Sem dados', '-', 'API nao retornou linhas para este bloco.', 'neutral');
  }

  function adminAge(minutes) {
    if (minutes === null || minutes === undefined || minutes === '') return '-';
    var n = Number(minutes);
    if (!Number.isFinite(n)) return '-';
    if (n < 60) return Math.round(n) + ' min';
    if (n < 1440) return Math.round(n / 60) + ' h';
    return Math.round(n / 1440) + ' d';
  }

  function renderAdminIntegrationDomains(rows) {
    var target = document.getElementById('adminIntegrationDomainRows');
    if (!target) return;
    target.innerHTML = rows.length ? rows.map(function (row) {
      return '<tr><td><strong>' + adminEsc(row.label || row.domain || '-') + '</strong><br><span class="admin-small">' + adminEsc(row.domain || '') + '</span></td><td>' + adminTag(row.status || '-') + '</td><td class="admin-small">' + adminEsc(row.last_success_at || '-') + '</td><td class="admin-small">' + adminEsc(adminAge(row.age_minutes)) + '</td><td class="admin-small"><code>' + adminEsc(row.recommended_command || '-') + '</code></td><td class="admin-small">' + adminEsc(row.last_error || row.message || '-') + '</td></tr>';
    }).join('') : '<tr><td colspan="6" class="admin-small">Nenhum dominio retornado.</td></tr>';
  }

  function renderAdminIntegrationRateQueue(rate, worker, counts, dead) {
    var domains = rate.domains || {};
    var methods = rate.methods || [];
    var limits = rate.limits || {};
    var queuePending = Number(worker.queued ?? worker.pending ?? counts.queued ?? 0) + Number(counts.failed_retryable ?? 0);
    renderAdminIntegrationCards('adminIntegrationRateQueue', [
      ['IP/min', limits.global_per_ip_per_minute ?? '-', 'Limite global documentado no projeto.', 'ok'],
      ['App+metodo/min', limits.per_app_method_per_minute ?? '-', 'Rate limit por APP_KEY/metodo.', 'ok'],
      ['Metodos mapeados', methods.length, Object.keys(domains).length + ' dominio(s) com politica.', 'ok'],
      ['Fila pendente', queuePending, 'queued + failed_retryable.', queuePending > 0 ? 'warning' : 'ok'],
      ['Em execucao', worker.running ?? counts.running ?? 0, 'Jobs running.', Number(worker.running ?? counts.running ?? 0) > 0 ? 'warning' : 'ok'],
      ['Dead-letter', dead.unresolved ?? 0, 'Dead letters nao resolvidas.', Number(dead.unresolved ?? 0) > 0 ? 'critical' : 'ok']
    ]);
  }

  function renderAdminIntegrationCron(rows) {
    var target = document.getElementById('adminIntegrationCronRows');
    if (!target) return;
    target.innerHTML = rows.length ? rows.map(function (row) {
      return '<tr><td><strong>' + adminEsc(row.label || row.key || '-') + '</strong><br><span class="admin-small">' + adminEsc(row.log || '') + '</span></td><td>' + adminTag(row.status || '-') + '</td><td class="admin-small">' + adminEsc(row.frequency || '-') + '</td><td class="admin-small">' + adminEsc(row.last_modified_at || '-') + '<br>' + adminEsc(adminAge(row.age_minutes)) + '</td><td class="admin-small"><code>' + adminEsc(row.command || '-') + '</code></td><td><details class="admin-ops-details"><summary>Ver log</summary><pre class="admin-ops-pre">' + adminEsc((row.last_lines || []).join('\\n') || 'Sem log.') + '</pre></details></td></tr>';
    }).join('') : '<tr><td colspan="6" class="admin-small">Status dos wrappers indisponivel.</td></tr>';
  }

  function renderAdminIntegrationActions(actions) {
    var target = document.getElementById('adminIntegrationActions');
    if (!target) return;
    target.innerHTML = actions.length ? actions.map(function (row) {
      return '<div class="admin-ops-list-item"><strong>' + adminEsc(row.message || row.key || '-') + '</strong><span class="admin-small">' + adminEsc(row.severity || 'info') + (row.link ? ' · ' + row.link : '') + '</span></div>';
    }).join('') : '<div class="admin-ops-list-item"><strong>Nenhuma acao recomendada</strong><span class="admin-small">Checks operacionais sem recomendacao imediata.</span></div>';
  }

  function renderAdminIntegrationRecent(rows) {
    var target = document.getElementById('adminIntegrationRecentRows');
    if (!target) return;
    target.innerHTML = rows.length ? rows.map(function (row) {
      return '<tr><td>' + adminEsc(row.domain || '-') + '</td><td>' + adminEsc(row.type || '-') + '</td><td>' + adminTag(row.status || '-') + '</td><td class="admin-small">' + adminEsc(row.started_at || '-') + '</td><td>' + adminEsc(row.items_processed ?? '-') + '</td></tr>';
    }).join('') : '<tr><td colspan="5" class="admin-small">Nenhuma sincronizacao recente.</td></tr>';
  }

  function renderAdminIntegrationTables(tables) {
    var target = document.getElementById('adminIntegrationTables');
    if (!target) return;
    var rows = [];
    Object.keys(tables || {}).forEach(function (group) {
      var groupRows = Array.isArray(tables[group]) ? tables[group] : Object.keys(tables[group] || {}).map(function (key) {
        var row = tables[group][key] || {};
        if (row && typeof row === 'object') row.table = row.table || key;
        return row;
      });
      groupRows.forEach(function (row) {
        rows.push({ group: group, row: row });
      });
    });
    target.innerHTML = '<table class="admin-table admin-table-compact"><thead><tr><th>Camada</th><th>Tabela</th><th>Status</th><th>Registros</th><th>Atualizado</th><th>Diagnostico</th></tr></thead><tbody>' + (rows.length ? rows.map(function (item) {
      var row = item.row || {};
      return '<tr><td>' + adminEsc(item.group) + '</td><td><strong>' + adminEsc(row.table || row.name || row.label || '-') + '</strong></td><td>' + adminTag(row.status || '-') + '</td><td>' + adminEsc(row.rows ?? row.count ?? '-') + '</td><td class="admin-small">' + adminEsc(row.last_updated_at || row.updated_at || row.last_sync || '-') + '</td><td class="admin-small">' + adminEsc(row.message || row.note || row.diagnostic || '-') + '</td></tr>';
    }).join('') : '<tr><td colspan="6" class="admin-small">Nenhuma tabela retornada.</td></tr>') + '</tbody></table>';
  }

  function flattenMetricRows(payload) {
    var out = [];
    var byDomain = payload.kpis_by_domain || {};
    Object.keys(byDomain).forEach(function (domain) {
      (byDomain[domain] || []).forEach(function (item) {
        out.push(item);
      });
    });
    return out;
  }

  var adminMetricRowsCache = [];
  function renderAdminMetricsRows() {
    var rows = document.getElementById('adminMetricsRows');
    var search = document.getElementById('adminMetricsSearch');
    if (!rows) return;
    var q = String(search && search.value || '').toLowerCase();
    var filtered = adminMetricRowsCache.filter(function (item) {
      return !q || adminJson(item).toLowerCase().indexOf(q) !== -1;
    });
    rows.innerHTML = filtered.length ? filtered.slice(0, 120).map(function (item) {
      return '<tr><td><strong>' + adminEsc(item.label || item.name || item.id || '-') + '</strong><br><span class="admin-small">' + adminEsc(item.id || '') + '</span></td><td>' + adminEsc(item.domain || '-') + '</td><td>' + adminTag(item.status || '-') + '</td><td class="admin-small">' + adminEsc(item.source_of_truth || item.source || item.table || '-') + '</td><td class="admin-small">' + adminEsc(item.owner || '-') + '</td></tr>';
    }).join('') : '<tr><td colspan="5" class="admin-small">Nenhum KPI encontrado.</td></tr>';
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
        adminOpsCard('Ativos', totals.active ?? '-', 'Em uso operacional.', 'ok'),
        adminOpsCard('Draft', totals.draft ?? '-', 'Ainda em evolucao.', Number(totals.draft || 0) > 0 ? 'warning' : 'ok'),
        adminOpsCard('Dominios', Object.keys(payload.kpis_by_domain || {}).length, 'Agrupamentos semanticos.', 'ok')
      ].join('');
      renderAdminMetricsRows();
    }).catch(function (error) {
      summary.innerHTML = adminOpsCard('Status', 'Erro', error.message, 'critical');
    });
  }

  function loadAdminCockpit() {
    var summary = document.getElementById('adminCockpitSummary');
    var raw = document.getElementById('adminCockpitRaw');
    if (!summary || !raw) return;
    summary.innerHTML = adminOpsCard('Status', 'Carregando', 'Consultando cockpit.', 'neutral');
    adminFetchJson('api/cockpit_summary.php').then(function (payload) {
      raw.textContent = adminJson(payload);
      var cards = payload.cards || [];
      summary.innerHTML = cards.length ? cards.map(function (card) {
        return adminOpsCard(card.label || card.id || '-', card.value ?? String(card.status || '-').toUpperCase(), card.id || '', card.status);
      }).join('') : adminOpsCard('Status', String(payload.status || 'unknown').toUpperCase(), 'Resumo tecnico.', payload.status);
    }).catch(function (error) {
      raw.textContent = error.message;
      summary.innerHTML = adminOpsCard('Status', 'Erro', error.message, 'critical');
    });
  }

  document.querySelectorAll('[data-admin-refresh]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = button.getAttribute('data-admin-refresh');
      if (target === 'system-health') loadAdminSystemHealth();
      if (target === 'integrations') loadAdminIntegrations();
      if (target === 'metrics') loadAdminMetrics();
      if (target === 'cockpit') loadAdminCockpit();
    });
  });

  var metricSearch = document.getElementById('adminMetricsSearch');
  if (metricSearch) {
    metricSearch.addEventListener('input', renderAdminMetricsRows);
  }

  loadAdminSystemHealth();
  loadAdminIntegrations();
  loadAdminMetrics();
  loadAdminCockpit();
})();
</script>
