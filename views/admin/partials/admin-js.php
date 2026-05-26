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
    if (s === 'critical' || s === 'error' || s === 'fail' || s === 'failed') return 'critical';
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
    adminFetchJson('api/omie_status.php').then(function (payload) {
      raw.textContent = adminJson(payload);
      var worker = payload.worker_queue || payload.worker || payload.queue || {};
      var freshness = payload.freshness || payload.sync_freshness || payload.omie_sync || {};
      var queueStatus = worker.status || payload.status || (payload.ok ? 'ok' : 'warning');
      var pending = worker.pending_jobs ?? worker.pending ?? payload.pending_jobs ?? '-';
      var running = worker.running_jobs ?? worker.running ?? payload.running_jobs ?? '-';
      var failed = worker.failed_terminal ?? payload.failed_terminal ?? payload.failures_24h ?? '-';
      var syncStatus = freshness.status || payload.freshness_status || payload.status || '-';
      summary.innerHTML = [
        adminOpsCard('Fila pendente', pending, 'Jobs aguardando worker.', Number(pending) > 0 ? 'warning' : 'ok'),
        adminOpsCard('Em execucao', running, 'Jobs em processamento.', Number(running) > 0 ? 'warning' : 'ok'),
        adminOpsCard('Falhas', failed, 'Falhas recentes ou terminal.', Number(failed) > 0 ? 'warning' : 'ok'),
        adminOpsCard('Freshness', String(syncStatus).toUpperCase(), 'Sync Omie e canonical.', syncStatus || queueStatus)
      ].join('');
    }).catch(function (error) {
      raw.textContent = error.message;
      summary.innerHTML = adminOpsCard('Status', 'Erro', error.message, 'critical');
    });
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
