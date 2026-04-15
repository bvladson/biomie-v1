(function () {
  'use strict';

  // ─── State ──────────────────────────────────────────────────────────────────
  let _reconciliation = null;
  let _queue         = null;
  let _catalog       = {};
  let _queueStatus   = 'aberta,reincidente';
  let _queueSeverity = '';

  // ─── Formatters ─────────────────────────────────────────────────────────────
  const currencyBRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

  function fmt(v)    { return currencyBRL.format(Number(v || 0)); }
  function n(v)      { return Number(v || 0); }
  function esc(s)    { return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
  function setText(sel, val) { document.querySelectorAll(sel).forEach(el => { el.textContent = val; }); }

  function toIsoLocal(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
  function todayIso() { return toIsoLocal(new Date()); }

  function formatDateBR(s) {
    if (!s) return '—';
    try { return new Date(`${s}T12:00:00`).toLocaleDateString('pt-BR'); } catch { return s; }
  }
  function formatDateTimeBR(s) {
    if (!s) return '—';
    try { return new Date(s).toLocaleString('pt-BR'); } catch { return s; }
  }

  // ─── Severity / status helpers ──────────────────────────────────────────────
  function severityClass(s) {
    switch (String(s || '').toLowerCase()) {
      case 'critical': return 'badge-status cancelled';
      case 'high':     return 'badge-status cancelled';
      case 'medium':   return 'badge-status authorized';
      default:         return 'badge-status';
    }
  }
  function severityLabel(s) {
    const map = { critical: 'Critico', high: 'Alto', medium: 'Medio', low: 'Baixo' };
    return map[String(s || '').toLowerCase()] || s || '—';
  }
  function statusClass(s) {
    switch (String(s || '').toLowerCase()) {
      case 'aberta':         return 'badge-status cancelled';
      case 'reincidente':    return 'badge-status cancelled';
      case 'resolvida':      return 'badge-status billed';
      case 'ignorada_regra': return 'badge-status';
      default:               return 'badge-status';
    }
  }
  function statusLabel(s) {
    const map = { aberta: 'Aberta', reincidente: 'Reincidente', resolvida: 'Resolvida', ignorada_regra: 'Ignorada' };
    return map[String(s || '').toLowerCase()] || s || '—';
  }
  function ruleLabel(code) {
    const labels = {
      pedido_sem_vendedor:                  'Pedido sem vendedor',
      nf_sem_pedido_vinculado:              'NF sem pedido vinculado',
      pedido_faturado_sem_nf_valida:        'Faturado sem NF valida',
      nf_autorizada_sem_vendedor_resolvido: 'NF sem vendedor',
      pedido_cancelado_com_nf_ativa:        'Cancelado com NF ativa',
      pedido_em_carteira_ja_faturado:       'Carteira ja faturado',
      diferenca_valor_pedido_nf:            'Valor diferente pedido/NF',
      diferenca_data_comercial_fiscal:      'Data divergente',
      diferenca_cliente_pedido_nf:          'Cliente divergente',
      duplicidade_chave_natural:            'Duplicidade de chave',
      mudanca_retroativa_detectada:         'Mudanca retroativa',
    };
    return labels[code] || (code ? code.replace(/_/g, ' ') : '—');
  }

  // ─── Date range ─────────────────────────────────────────────────────────────
  function setDateRange(start, end) {
    const s = document.getElementById('dateStart');
    const e = document.getElementById('dateEnd');
    if (s) s.value = start;
    if (e) e.value = end;
  }
  function getDateRange() {
    return {
      start: document.getElementById('dateStart')?.value || '',
      end:   document.getElementById('dateEnd')?.value   || '',
    };
  }
  function applyPreset(preset) {
    const now = new Date();
    if (preset === 'month') {
      setDateRange(
        toIsoLocal(new Date(now.getFullYear(), now.getMonth(), 1)),
        toIsoLocal(new Date(now.getFullYear(), now.getMonth() + 1, 0))
      );
    } else if (preset === 'year') {
      setDateRange(`${now.getFullYear()}-01-01`, todayIso());
    } else if (preset === 'rolling12') {
      setDateRange(toIsoLocal(new Date(now.getFullYear() - 1, now.getMonth(), now.getDate() + 1)), todayIso());
    }
  }
  function buildRecUrl() {
    const { start, end } = getDateRange();
    const p = new URLSearchParams();
    if (start) p.set('start', start);
    if (end)   p.set('end', end);
    return 'api/reconciliation.php' + (p.toString() ? `?${p.toString()}` : '');
  }
  function buildQueueUrl() {
    const { start, end } = getDateRange();
    const p = new URLSearchParams();
    if (start)          p.set('start', start);
    if (end)            p.set('end', end);
    if (_queueStatus)   p.set('statuses', _queueStatus);
    if (_queueSeverity) p.set('severity', _queueSeverity);
    p.set('limit', '200');
    return 'api/reconciliation_queue.php?' + p.toString();
  }

  // ─── HTTP ────────────────────────────────────────────────────────────────────
  async function fetchJson(url, options) {
    if (typeof window.fetchJson === 'function') return window.fetchJson(url, options);
    const res = await fetch(url, Object.assign({ headers: { Accept: 'application/json' } }, options || {}));
    const ct  = res.headers.get('content-type') || '';
    const raw = await res.text();
    if (res.redirected) throw new Error('Sessao expirada. Recarregue a pagina e faca login novamente.');
    let payload = null;
    if (ct.includes('application/json')) {
      try { payload = JSON.parse(raw); } catch { throw new Error('Resposta invalida do servidor.'); }
    }
    if (!res.ok) throw new Error(payload?.message || raw.slice(0, 180) || 'Erro ao carregar dados.');
    if (!payload || payload.ok === false) throw new Error(payload?.message || 'Dados indisponiveis.');
    return payload;
  }

  function csrfToken() {
    return document.querySelector('meta[name="biomie-csrf"]')?.getAttribute('content') || '';
  }
  function buildPostOptions(fields) {
    const token = csrfToken();
    const body  = new URLSearchParams(fields);
    if (token) body.set('csrf_token', token);
    return {
      method:  'POST',
      cache:   'no-store',
      headers: {
        Accept:           'application/json',
        'Content-Type':   'application/x-www-form-urlencoded; charset=UTF-8',
        'X-CSRF-Token':   token,
        'X-Requested-With': 'fetch',
      },
      body: body.toString(),
    };
  }

  // ─── UI helpers ──────────────────────────────────────────────────────────────
  function toggleLoading(on, msg) {
    const el = document.getElementById('pageLoading');
    if (!el) return;
    el.classList.toggle('d-none', !on);
    if (msg) el.textContent = msg;
  }
  function showError(msg) {
    const el = document.getElementById('pageError');
    if (!el) return;
    if (!msg) { el.classList.add('d-none'); el.innerHTML = ''; return; }
    el.classList.remove('d-none');
    el.innerHTML = `<div style="background:#fde8e8;border-radius:8px;padding:12px 16px;margin:8px 0;font-size:13px;color:#8b1a1a">${esc(msg)}</div>`;
  }
  function showFeedback(msg, type) {
    const el = document.getElementById('syncFeedback');
    if (!el) return;
    if (!msg) { el.innerHTML = ''; return; }
    const bg = { success: '#d4edda', danger: '#fde8e8', secondary: '#e9ecef', warning: '#fff3cd' }[type] || '#e9ecef';
    const color = type === 'danger' ? '#8b1a1a' : '#333';
    el.innerHTML = `<div style="background:${bg};border-radius:8px;padding:12px 16px;margin:8px 0;font-size:13px;color:${color}">${esc(msg)}</div>`;
  }

  // ─── Render KPIs ─────────────────────────────────────────────────────────────
  function renderKpis(rec, q) {
    if (rec) {
      const t = rec.totals || {};
      setText('#kpiFaturado',   fmt(t.faturado));
      setText('#kpiAutorizado', fmt(t.autorizado));
      setText('#kpiCancelado',  fmt(t.cancelado));
      const fqEl = document.getElementById('kpiFaturadoQtd');
      const aqEl = document.getElementById('kpiAutorizadoQtd');
      const cqEl = document.getElementById('kpiCanceladoQtd');
      if (fqEl) fqEl.textContent = `${n(t.pedidos_faturados)} NF(s)`;
      if (aqEl) aqEl.textContent = `${n(t.pedidos_autorizados)} pedido(s)`;
      if (cqEl) cqEl.textContent = `${n(t.pedidos_cancelados)} cancelado(s)`;
      setText('#heroFaturado', fmt(t.faturado));

      const syncEl = document.getElementById('lastSyncDate');
      if (syncEl) syncEl.textContent = rec.last_sync ? formatDateTimeBR(rec.last_sync) : '—';
      setText('[data-bind="period-label"]', rec.period?.label || '—');
      setText('[data-bind="last-sync"]', rec.generated_at ? new Date(rec.generated_at).toLocaleString('pt-BR') : '—');
    }

    if (q) {
      const s         = q.summary || {};
      const bySev     = s.by_severity || {};
      const bySt      = s.by_status   || {};
      const criticas  = n(bySev.critical);
      const altas     = n(bySev.high);
      const totalOpen = n(bySt.aberta) + n(bySt.reincidente);

      setText('#kpiCriticas',    String(criticas));
      setText('#kpiAltas',       String(altas));
      setText('#kpiAberto',      String(totalOpen));
      setText('#heroDivergencias', String(totalOpen));
      setText('#heroCriticas',   String(criticas));

      const card = document.getElementById('kpiCriticasCard');
      if (card) {
        card.className = criticas > 0
          ? 'status-kpi cancelled'
          : 'status-kpi info';
      }

      // Last scan: most recent last_detected_at in queue
      const items = Array.isArray(q.queue) ? q.queue : [];
      if (items.length > 0) {
        const latest = items.reduce((a, b) => ((b.last_detected_at || '') > (a.last_detected_at || '') ? b : a));
        const lsEl = document.getElementById('lastScanLabel');
        if (lsEl) lsEl.textContent = latest.last_detected_at ? formatDateTimeBR(latest.last_detected_at) : '—';
      }
    }
  }

  // ─── Vendor table ────────────────────────────────────────────────────────────
  function renderVendorTable(data) {
    const tbody = document.getElementById('vendorTable');
    if (!tbody) return;
    const vendors = Array.isArray(data.vendors) ? data.vendors : [];

    if (vendors.length === 0) {
      tbody.innerHTML = `<tr><td colspan="9" style="padding:24px;text-align:center;color:var(--text-secondary)">
        Nenhum vendedor encontrado. Verifique se a sincronizacao foi executada para o periodo selecionado.
      </td></tr>`;
      return;
    }

    tbody.innerHTML = vendors.map(v => `
      <tr>
        <td>
          <strong>${esc(v.nome || 'Sem vendedor')}</strong>
          ${v.codigo_vendedor ? `<br><small style="color:var(--text-secondary);font-family:var(--font-mono,monospace);font-size:10px">${esc(v.codigo_vendedor)}</small>` : ''}
        </td>
        <td><strong>${esc(v.faturado || '—')}</strong></td>
        <td>${n(v.faturado_qtd)}</td>
        <td>${esc(v.autorizado || '—')}</td>
        <td>${n(v.autorizado_qtd)}</td>
        <td>${esc(v.cancelado || '—')}</td>
        <td>${n(v.cancelado_qtd)}</td>
        <td>${n(v.total_pedidos)}</td>
        <td style="white-space:nowrap;font-size:11px;color:var(--text-secondary)">${v.ultima_sync ? formatDateTimeBR(v.ultima_sync) : '—'}</td>
      </tr>
    `).join('');
  }

  // ─── Divergence queue ────────────────────────────────────────────────────────
  function renderQueue(queueData) {
    const tbody = document.getElementById('queueTable');
    const meta  = document.getElementById('queueMeta');
    if (!tbody) return;

    const queue = Array.isArray(queueData.queue) ? queueData.queue : [];
    if (meta) meta.textContent = `${queue.length} ocorrencia(s) com os filtros atuais.`;

    if (queue.length === 0) {
      tbody.innerHTML = `<tr><td colspan="8" style="padding:24px;text-align:center;color:var(--text-secondary)">
        Nenhuma divergencia encontrada com os filtros atuais.
        ${_queueStatus === 'aberta,reincidente' ? ' Use "Varredura" para detectar divergencias novas.' : ''}
      </td></tr>`;
      return;
    }

    tbody.innerHTML = queue.map(item => {
      const sev          = String(item.severity || '');
      const st           = String(item.operational_status || item.status || '');
      const ruleCode     = String(item.rule_code || item.tipo || '');
      const action       = String(item.suggested_action || _catalog[ruleCode]?.suggested_action || '—');
      const canAct       = st !== 'resolvida' && st !== 'ignorada_regra';

      return `<tr>
        <td style="white-space:nowrap"><span class="${severityClass(sev)}">${esc(severityLabel(sev))}</span></td>
        <td style="white-space:nowrap">
          <strong style="display:block">${esc(ruleLabel(ruleCode))}</strong>
          <small style="color:var(--text-secondary);font-family:var(--font-mono,monospace);font-size:10px">${esc(ruleCode)}</small>
        </td>
        <td style="font-size:12px;font-family:var(--font-mono,monospace);white-space:nowrap">${esc(item.referencia || '—')}</td>
        <td style="font-size:12px;max-width:220px">${esc(item.descricao || '—')}</td>
        <td style="font-size:12px;color:var(--text-secondary);max-width:200px">${esc(action)}</td>
        <td style="white-space:nowrap">
          <span class="${statusClass(st)}">${esc(statusLabel(st))}</span>
          ${item.resolvido_por ? `<br><small style="font-size:10px;color:var(--text-secondary)">${esc(item.resolvido_por)}</small>` : ''}
        </td>
        <td style="white-space:nowrap;font-size:11px;color:var(--text-secondary)">${item.last_detected_at ? formatDateTimeBR(item.last_detected_at) : '—'}</td>
        <td style="white-space:nowrap">
          ${canAct ? `
            <button class="btn-secondary" style="font-size:11px;padding:4px 8px;display:block;margin-bottom:4px;width:100%"
              data-action-id="${n(item.id)}" data-action-status="resolvida">Resolvida</button>
            <button class="btn-secondary" style="font-size:11px;padding:4px 8px;display:block;width:100%"
              data-action-id="${n(item.id)}" data-action-status="ignorada_regra">Ignorar</button>
          ` : `<span style="font-size:11px;color:var(--text-secondary)">${esc(statusLabel(st))}</span>`}
        </td>
      </tr>`;
    }).join('');

    tbody.querySelectorAll('[data-action-id]').forEach(btn => {
      btn.addEventListener('click', () => updateStatus(Number(btn.dataset.actionId), btn.dataset.actionStatus));
    });
  }

  // ─── Actions ─────────────────────────────────────────────────────────────────
  async function scanNow() {
    showFeedback('Executando varredura de divergencias...', 'secondary');
    document.getElementById('scanNow').disabled = true;
    const { start, end } = getDateRange();
    try {
      const data = await fetchJson('api/reconciliation_queue.php', buildPostOptions({ action: 'scan', start, end }));
      const found = data.scan?.issues_found ?? (Array.isArray(data.queue) ? data.queue.length : '?');
      showFeedback(`Varredura concluida. ${found} ocorrencia(s) detectada(s).`, 'success');
      _queue   = data;
      _catalog = data.catalog || _catalog;
      renderKpis(_reconciliation, _queue);
      renderQueue(_queue);
    } catch (e) {
      showFeedback(e.message || 'Falha na varredura.', 'danger');
    } finally {
      document.getElementById('scanNow').disabled = false;
    }
  }

  async function updateStatus(id, status) {
    try {
      const data = await fetchJson(
        'api/reconciliation_queue.php',
        buildPostOptions({ action: 'update_status', id: String(id), status, ...getDateRange() })
      );
      _queue   = data;
      _catalog = data.catalog || _catalog;
      renderKpis(_reconciliation, _queue);
      renderQueue(_queue);
    } catch (e) {
      showFeedback(e.message || 'Falha ao atualizar status.', 'danger');
    }
  }

  // ─── Queue filters ────────────────────────────────────────────────────────────
  async function reloadQueue() {
    try {
      const data = await fetchJson(buildQueueUrl());
      _queue   = data;
      _catalog = data.catalog || _catalog;
      renderKpis(_reconciliation, _queue);
      renderQueue(_queue);
    } catch (e) {
      showFeedback(e.message || 'Falha ao filtrar fila.', 'danger');
    }
  }

  function bindQueueFilters() {
    document.querySelectorAll('[data-queue-status]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('[data-queue-status]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        _queueStatus = btn.dataset.queueStatus;
        reloadQueue();
      });
    });
    document.querySelectorAll('[data-queue-severity]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('[data-queue-severity]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        _queueSeverity = btn.dataset.queueSeverity;
        reloadQueue();
      });
    });

    // Default active state
    document.querySelector('[data-queue-status="aberta,reincidente"]')?.classList.add('active');
    document.querySelector('[data-queue-severity=""]')?.classList.add('active');
  }

  // ─── Main load ───────────────────────────────────────────────────────────────
  async function loadAll() {
    showError('');
    showFeedback('');
    toggleLoading(true, 'Carregando auditoria fiscal...');

    try {
      const [rec, q] = await Promise.allSettled([
        fetchJson(buildRecUrl()),
        fetchJson(buildQueueUrl()),
      ]);

      if (rec.status === 'fulfilled') {
        _reconciliation = rec.value;
      } else {
        const msg = String(rec.reason?.message || '');
        // Show warning but don't hard-fail — legacy mode still returns data
        if (msg) showFeedback(msg, 'warning');
      }

      if (q.status === 'fulfilled') {
        _queue   = q.value;
        _catalog = q.value.catalog || {};
      } else {
        // Queue requires canonical DB — graceful degradation
        const msg = String(q.reason?.message || '');
        const isSetup = msg.toLowerCase().includes('nao configurado')
          || msg.toLowerCase().includes('tabelas canonicas')
          || msg.toLowerCase().includes('banco');
        if (!isSetup && msg) {
          showFeedback(msg, 'warning');
        } else if (isSetup) {
          showFeedback('A fila de divergencias requer o banco de dados canonico configurado. Verifique as tabelas antes de usar a varredura.', 'warning');
        }
      }

      renderKpis(_reconciliation, _queue);
      if (_reconciliation) renderVendorTable(_reconciliation);
      if (_queue) renderQueue(_queue);

    } catch (e) {
      showError(e.message || 'Falha ao carregar a auditoria fiscal.');
    } finally {
      toggleLoading(false);
    }
  }

  // ─── Init ─────────────────────────────────────────────────────────────────────
  function init() {
    applyPreset('month');

    document.getElementById('menu_toggle')?.addEventListener('click', e => {
      e.preventDefault();
      document.body.classList.toggle('nav-md');
      document.body.classList.toggle('nav-sm');
    });

    document.querySelectorAll('[data-range-preset]').forEach(btn => {
      btn.addEventListener('click', () => {
        applyPreset(btn.dataset.rangePreset);
        loadAll();
      });
    });

    document.getElementById('applyFilters')?.addEventListener('click', () => loadAll());
    document.getElementById('scanNow')?.addEventListener('click', scanNow);

    bindQueueFilters();
    loadAll();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
