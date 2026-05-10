<?php if ($can['console'] ?? false): ?>
<script>
(function () {
  'use strict';
  var CSRF = <?php echo json_encode($csrf); ?>;

  var CMD_ARGS = {
    'logs:recent':                 ['limit', 'level', 'domain'],
    'gold:materialize-commercial': ['dry_run'],
    'clientes:materialize-dim':    ['dry_run'],
    'clientes:enrich-omie':        ['dry_run'],
    'clientes:enrich-omie-all':    ['dry_run', 'target', 'source'],
    'clientes:geo-coverage':       [],
    'cnae:diagnose-sources':       [],
    'cnae:diagnose-fiscal-sources':[],
    'cnae:materialize':            ['dry_run'],
    'geo:materialize':             ['dry_run'],
    'geo:rebuild-period':          ['start', 'end'],
    'geo:validate-enrichment':     [],
    'cache:clear':                 [],
    'db:counts':                   [],
  };

  function consoleOnCommandSelect(name) {
    var confirmArea = document.getElementById('consoleConfirmArea');
    var argsArea    = document.getElementById('consoleArgsArea');
    var runBtn      = document.getElementById('consoleBtnRun');

    ['consoleLimitField','consoleLevelField','consoleDomainField','consoleDryRunField','consoleDateRangeField'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
    argsArea.style.display = 'none';

    if (!name) {
      runBtn.disabled = true;
      confirmArea.style.display = 'none';
      return;
    }

    var sel          = document.getElementById('consoleCommandSelect');
    var opt          = sel.querySelector('option[value="' + name + '"]');
    var requiresConf = opt && opt.dataset.requiresConfirmation === '1';
    confirmArea.style.display = requiresConf ? 'block' : 'none';
    document.getElementById('consoleConfirmCheck').checked = false;

    var cmdArgs = CMD_ARGS[name] || [];
    if (name === 'logs:recent') {
      argsArea.style.display = 'block';
      document.getElementById('consoleLimitField').style.display  = 'block';
      document.getElementById('consoleLevelField').style.display  = 'block';
      document.getElementById('consoleDomainField').style.display = 'block';
    }
    if (cmdArgs.indexOf('dry_run') !== -1) {
      argsArea.style.display = 'block';
      document.getElementById('consoleDryRunField').style.display = 'block';
      document.getElementById('consoleArgDryRun').checked = false;
    }
    if (cmdArgs.indexOf('start') !== -1 || cmdArgs.indexOf('end') !== -1) {
      argsArea.style.display = 'block';
      document.getElementById('consoleDateRangeField').style.display = 'block';
      // default: first and last day of current month
      var now = new Date();
      var y = now.getFullYear(), m = String(now.getMonth() + 1).padStart(2, '0');
      var lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
      document.getElementById('consoleArgStart').value = y + '-' + m + '-01';
      document.getElementById('consoleArgEnd').value   = y + '-' + m + '-' + String(lastDay).padStart(2, '0');
    }

    runBtn.disabled = false;
  }
  window.consoleOnCommandSelect = consoleOnCommandSelect;

  window.consoleSelectCommand = function (name) {
    var sel = document.getElementById('consoleCommandSelect');
    sel.value = name;
    consoleOnCommandSelect(name);
  };

  window.consoleRun = function (forceConfirm) {
    var name = document.getElementById('consoleCommandSelect').value;
    if (!name) return;

    var sel          = document.getElementById('consoleCommandSelect');
    var opt          = sel.querySelector('option[value="' + name + '"]');
    var requiresConf = opt && opt.dataset.requiresConfirmation === '1';
    var confirmed    = document.getElementById('consoleConfirmCheck').checked;

    if (requiresConf && !confirmed && !forceConfirm) {
      alert('Marque a caixa de confirmacao antes de executar este comando.');
      return;
    }

    var args    = {};
    var cmdArgs = CMD_ARGS[name] || [];
    if (name === 'logs:recent') {
      args.limit  = document.getElementById('consoleArgLimit').value;
      args.level  = document.getElementById('consoleArgLevel').value;
      args.domain = document.getElementById('consoleArgDomain').value;
    }
    if (cmdArgs.indexOf('dry_run') !== -1) {
      args.dry_run = document.getElementById('consoleArgDryRun').checked ? '1' : '0';
    }
    if (cmdArgs.indexOf('start') !== -1) {
      args.start = document.getElementById('consoleArgStart').value;
    }
    if (cmdArgs.indexOf('end') !== -1) {
      args.end = document.getElementById('consoleArgEnd').value;
    }

    var body = new URLSearchParams();
    body.append('action',     'run_console_command');
    body.append('command',    name);
    body.append('csrf_token', CSRF);
    body.append('confirm',    (requiresConf && confirmed) ? '1' : '0');
    Object.keys(args).forEach(function (k) { body.append('args[' + k + ']', args[k]); });

    setLoading(true);

    fetch('admin.php?tab=console', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      setLoading(false);
      if (!data.ok && data.requires_confirmation) {
        alert('Confirme a execucao marcando a caixa de confirmacao.');
        return;
      }
      if (!data.ok) {
        var debugLines = [];
        var dbg = data.debug || null;
        if (dbg) {
          var pairs = [
            ['exception',   dbg.exception_class],
            ['sqlstate',    dbg.sqlstate],
            ['driver_code', dbg.driver_error_code ? String(dbg.driver_error_code) : ''],
            ['driver_msg',  dbg.driver_message],
            ['message',     dbg.error_message_sanitized],
            ['command',     dbg.command],
            ['request_id',  dbg.request_id],
            ['duration_ms', dbg.duration_ms !== undefined ? String(dbg.duration_ms) : ''],
          ];
          pairs.forEach(function (p) { if (p[1]) debugLines.push(p[0] + ': ' + p[1]); });
        }
        showError(data.error || 'Erro desconhecido.', debugLines);
        return;
      }
      renderResult(data.result, data.meta);
    })
    .catch(function () {
      setLoading(false);
      showError('Erro de comunicacao. Verifique sua conexao e tente novamente.');
    });
  };

  function setLoading(on) {
    document.getElementById('consoleResultEmpty').hidden   = true;
    document.getElementById('consoleResultContent').hidden = on;
    document.getElementById('consoleResultLoading').hidden = !on;
    document.getElementById('consoleResultLoading').style.display = on ? 'flex' : 'none';
    document.getElementById('consoleBtnRun').disabled = on;
  }

  function showError(msg, debugLines) {
    var lines = (debugLines && debugLines.length) ? debugLines : [];
    var r = { success: false, status: 'error', title: 'Erro de Execucao', message: msg, output_lines: lines, metrics: {}, tables: [], warnings: [], errors: [msg], duration_ms: 0 };
    renderResult(r, null);
  }

  function h(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  function renderResult(result, meta) {
    document.getElementById('consoleResultEmpty').hidden   = true;
    document.getElementById('consoleResultLoading').hidden = true;
    document.getElementById('consoleResultLoading').style.display = 'none';
    var content = document.getElementById('consoleResultContent');
    content.hidden = false;

    var icons      = { ok: 'check_circle', warning: 'warning', error: 'error' };
    var colors     = { ok: 'var(--success-strong,#147a42)', warning: 'var(--warning-strong,#925500)', error: 'var(--danger-strong,#96140a)' };
    var badgeClass = { ok: 'ok', warning: 'warn', error: 'danger' };
    var status     = result.status || 'ok';
    document.getElementById('consoleResultIcon').textContent  = icons[status]  || 'info';
    document.getElementById('consoleResultIcon').style.color  = colors[status] || '';
    document.getElementById('consoleResultTitle').textContent = result.title   || '';
    var badge       = document.getElementById('consoleResultBadge');
    badge.textContent = status;
    badge.className   = 'admin-tag ' + (badgeClass[status] || 'neutral');

    var dur = result.duration_ms || (meta && meta.duration_ms) || 0;
    document.getElementById('consoleResultDuration').textContent = dur ? dur + 'ms' : '';

    var msgEl = document.getElementById('consoleResultMessage');
    msgEl.textContent    = result.message || '';
    msgEl.style.background = status === 'error' ? 'var(--danger-soft,#fde8e6)' : status === 'warning' ? 'var(--warning-soft,#fff4de)' : 'var(--mag-bg)';
    msgEl.style.color      = status === 'error' ? 'var(--danger-strong,#96140a)' : status === 'warning' ? 'var(--warning-strong,#925500)' : '';

    var lines   = result.output_lines || [];
    var outArea = document.getElementById('consoleOutputLines');
    var outPre  = document.getElementById('consoleOutputPre');
    if (lines.length > 0) { outPre.textContent = lines.join('\n'); outArea.hidden = false; }
    else                  { outArea.hidden = true; }

    var metrics      = result.metrics || {};
    var metricsArea  = document.getElementById('consoleMetricsArea');
    var metricsGrid  = document.getElementById('consoleMetricsGrid');
    var metricKeys   = Object.keys(metrics).filter(function (k) { return typeof metrics[k] === 'number' || typeof metrics[k] === 'string'; });
    if (metricKeys.length > 0) {
      metricsGrid.innerHTML = metricKeys.map(function (k) {
        return '<div class="admin-diagnostic-card"><span>' + h(k.replace(/_/g,' ')) + '</span><strong>' + h(String(metrics[k])) + '</strong></div>';
      }).join('');
      metricsArea.hidden = false;
    } else { metricsArea.hidden = true; }

    var warnings = result.warnings || [];
    var warnArea = document.getElementById('consoleWarningsArea');
    if (warnings.length > 0) {
      document.getElementById('consoleWarningsList').innerHTML = warnings.map(function (w) { return '<div>' + h(w) + '</div>'; }).join('');
      warnArea.hidden = false;
    } else { warnArea.hidden = true; }

    var errors  = result.errors || [];
    var errArea = document.getElementById('consoleErrorsArea');
    if (errors.length > 0) {
      document.getElementById('consoleErrorsList').innerHTML = errors.map(function (e) { return '<div>' + h(e) + '</div>'; }).join('');
      errArea.hidden = false;
    } else { errArea.hidden = true; }

    var tables     = result.tables || [];
    var tablesArea = document.getElementById('consoleTablesArea');
    tablesArea.innerHTML = tables.map(function (tbl) {
      var headers = (tbl.headers || []).map(function (h_) { return '<th>' + h(h_) + '</th>'; }).join('');
      var rows    = (tbl.rows || []).map(function (row) {
        return '<tr>' + (row || []).map(function (cell) { return '<td class="admin-small">' + h(String(cell)) + '</td>'; }).join('') + '</tr>';
      }).join('');
      return '<div class="admin-panel" style="margin-top:10px"><h2 style="font-size:.88rem;margin-bottom:8px">' + h(tbl.title || '') + '</h2>'
        + '<div class="admin-table-wrap"><table class="admin-table admin-table-compact"><thead><tr>' + headers + '</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
    }).join('');

    if (meta) {
      var metaRows   = document.getElementById('consoleMetaRows');
      var metaFields = [
        ['request_id',   meta.request_id],
        ['command',      meta.command],
        ['user_id',      meta.user_id],
        ['duration_ms',  meta.duration_ms],
        ['executed_at',  meta.executed_at],
        ['span_id',      meta.span_id],
      ];
      metaRows.innerHTML = metaFields.map(function (f) {
        return '<tr><td><strong>' + h(f[0]) + '</strong></td><td class="admin-small" style="font-family:monospace">' + h(String(f[1] != null ? f[1] : '-')) + '</td></tr>';
      }).join('');
    }
  }
})();
</script>
<?php endif; ?>
