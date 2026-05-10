(function () {
  const state = {
    rows: [],
    meta: {},
    filter: '',
    saving: false
  };

  const currencyBRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  const numberBR = new Intl.NumberFormat('pt-BR');
  const csrfToken = document.querySelector('meta[name="biomie-csrf"]')?.getAttribute('content') || '';

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatCurrency(value) {
    return currencyBRL.format(Number(value || 0));
  }

  function formatNumber(value) {
    return numberBR.format(Number(value || 0));
  }

  function decodeVendorLabel(value) {
    const raw = String(value || '').trim();
    if (!raw) return 'Sem vendedor';
    try {
      return decodeURIComponent(raw).trim() || raw;
    } catch (error) {
      return raw;
    }
  }

  function currentYearMonth() {
    const field = document.getElementById('dateStart');
    let val = field && field.value ? String(field.value) : '';
    if (val.length >= 7) return val.slice(0, 7);
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  }

  function setLoading(visible) {
    const indicator = document.getElementById('loadingIndicator');
    if (!indicator) return;
    indicator.style.display = visible ? 'inline-flex' : 'none';
  }

  function setStatus(message, type = 'info') {
    const target = document.getElementById('targetsSaveStatus');
    if (!target) return;
    if (!message) {
      target.style.display = 'none';
      target.innerHTML = '';
      return;
    }

    target.style.display = 'inline-flex';
    target.className = `module-alert module-alert--${type}`;
    target.innerHTML = `<strong>${escapeHtml(message)}</strong>`;
  }

  function monthLabel(yearMonth) {
    const [year, month] = String(yearMonth || '').split('-').map(Number);
    if (!year || !month) return '-';
    const date = new Date(year, month - 1, 1);
    return date.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
  }

  function normalizeRows(rows) {
    return (rows || []).map((row) => ({
      vendor_id: String(row.vendor_id || '').trim(),
      vendor_name: decodeVendorLabel(row.vendor_name || 'Sem vendedor'),
      target_month: Number(row.target_month || row.target_period || 0),
      target_period: Number(row.target_period || row.target_month || 0),
      locked: !!row.locked,
      editable: row.editable !== false,
      risk_status: String(row.risk_status || 'SEM_META'),
      updated_at: row.updated_at || null
    })).filter((row) => row.vendor_id !== '');
  }

  function filteredRows() {
    const term = state.filter.trim().toLowerCase();
    if (!term) return state.rows;
    return state.rows.filter((row) => {
      return row.vendor_name.toLowerCase().includes(term) || row.vendor_id.toLowerCase().includes(term);
    });
  }

  function updateSummaryChips() {
    const rows = state.rows;
    const meta = state.meta || {};
    const withTarget = Number(meta.with_target_count ?? rows.filter((row) => Number(row.target_month) > 0).length);
    const sellerCount = Number(meta.seller_count ?? rows.length);
    const missingCount = Number(meta.missing_targets_count ?? Math.max(0, sellerCount - withTarget));

    const sellerCountEl = document.getElementById('targetsSellerCount');
    const configuredEl = document.getElementById('targetsConfiguredCount');
    const missingEl = document.getElementById('targetsMissingCount');
    const teamTargetEl = document.getElementById('targetsTeamTarget');
    const monthEl = document.getElementById('targetsMonthChip');
    const modeEl = document.getElementById('targetsModeChip');

    if (sellerCountEl) sellerCountEl.textContent = formatNumber(sellerCount);
    if (configuredEl) configuredEl.textContent = formatNumber(withTarget);
    if (missingEl) missingEl.textContent = formatNumber(missingCount);
    if (teamTargetEl) teamTargetEl.textContent = formatCurrency(meta.team_target || 0);
    if (monthEl) monthEl.textContent = monthLabel(meta.year_month || currentYearMonth());
    if (modeEl) modeEl.textContent = meta.locked ? 'Historico travado' : 'Edicao aberta';
  }

  function statusChip(row) {
    if (row.locked) {
      return '<span class="status-chip status-chip--muted">Historico travado</span>';
    }
    if (Number(row.target_month) > 0) {
      return '<span class="status-chip status-chip--ok">Meta pronta</span>';
    }
    return '<span class="status-chip status-chip--attention">Pendente</span>';
  }

  function renderTableRows() {
    const target = document.getElementById('targetsFormTable');
    const saveAllButton = document.getElementById('saveAllTargets');
    if (!target) return;

    const rows = filteredRows();
    if (!rows.length) {
      target.innerHTML = `<tr><td colspan="5" class="empty-row">${state.rows.length ? 'Nenhum vendedor encontrado para esse filtro.' : 'Sem vendedores disponiveis para esse mes.'}</td></tr>`;
      if (saveAllButton) saveAllButton.style.display = 'none';
      return;
    }

    target.innerHTML = rows.map((row) => {
      const currentTarget = Number(row.target_month || 0);
      const placeholder = currentTarget > 0 ? formatCurrency(currentTarget) : 'ex: 80000';
      const updatedAt = row.updated_at
        ? `<small>Atualizada em ${new Date(row.updated_at).toLocaleString('pt-BR')}</small>`
        : `<small>${escapeHtml(row.vendor_id)}</small>`;

      return `<tr data-vendor-id="${escapeHtml(row.vendor_id)}" data-vendor-name="${escapeHtml(row.vendor_name)}">
        <td>
          <div class="flight-seller">
            <strong>${escapeHtml(row.vendor_name)}</strong>
            ${updatedAt}
          </div>
        </td>
        <td>${currentTarget > 0 ? `<span class="status-chip status-chip--ok">${formatCurrency(currentTarget)}</span>` : '<span class="status-chip status-chip--muted">Sem meta</span>'}</td>
        <td><input type="number" class="filter-input target-input" data-vendor-id="${escapeHtml(row.vendor_id)}" placeholder="${escapeHtml(placeholder)}" value="${currentTarget > 0 ? String(currentTarget) : ''}" min="0" step="0.01" ${row.locked ? 'disabled' : ''} /></td>
        <td>${statusChip(row)}</td>
        <td><button class="btn-secondary btn-save-single" data-vendor-id="${escapeHtml(row.vendor_id)}" ${row.locked ? 'disabled' : ''}><span class="material-symbols-rounded" style="font-size:16px">save</span></button></td>
      </tr>`;
    }).join('');

    target.querySelectorAll('.btn-save-single').forEach((button) => {
      button.addEventListener('click', async () => {
        const vendorId = button.getAttribute('data-vendor-id') || '';
        const row = target.querySelector(`tr[data-vendor-id="${vendorId}"]`);
        const input = row?.querySelector('.target-input');
        const vendorName = row?.getAttribute('data-vendor-name') || '';
        if (!input || input.disabled) return;

        const value = parseFloat(input.value);
        if (Number.isNaN(value)) {
          setStatus('Preencha um valor valido antes de salvar.', 'warning');
          return;
        }

        await saveRows([{
          vendor_id: vendorId,
          vendor_name: vendorName,
          year_month: currentYearMonth(),
          target_amount: value,
          changed_by: 'interface'
        }], button);
      });
    });

    const locked = rows.every((row) => row.locked);
    if (saveAllButton) {
      saveAllButton.style.display = locked ? 'none' : 'inline-flex';
      saveAllButton.disabled = locked || state.saving;
    }
  }

  async function loadTargets() {
    setLoading(true);
    setStatus('');
    try {
      const yearMonth = currentYearMonth();
      const data = await window.fetchJson(`api/flight_plan_targets_editor.php?year_month=${encodeURIComponent(yearMonth)}&page=1&per_page=300`, {
        cache: 'no-store',
        timeoutMs: 20000
      });
      state.rows = normalizeRows(data.data || []);
      state.meta = data.meta || {};
      updateSummaryChips();
      renderTableRows();

      if (state.meta.locked) {
        setStatus('Mes fechado em modo historico. Edicao bloqueada para preservar a leitura consolidada.', 'info');
      }
    } catch (error) {
      const target = document.getElementById('targetsFormTable');
      if (target) {
        target.innerHTML = `<tr><td colspan="5" class="empty-row" style="color:var(--danger)">Erro ao carregar metas: ${escapeHtml(error.message || 'falha desconhecida')}</td></tr>`;
      }
      setStatus(error.message || 'Nao foi possivel carregar as metas.', 'danger');
    } finally {
      setLoading(false);
    }
  }

  function collectBatchPayload() {
    const rows = [];
    document.querySelectorAll('#targetsFormTable tr[data-vendor-id]').forEach((row) => {
      const input = row.querySelector('.target-input');
      if (!input || input.disabled || input.value.trim() === '') return;

      const value = parseFloat(input.value);
      if (Number.isNaN(value)) return;

      rows.push({
        vendor_id: row.getAttribute('data-vendor-id') || '',
        vendor_name: row.getAttribute('data-vendor-name') || '',
        year_month: currentYearMonth(),
        target_amount: value,
        changed_by: 'interface'
      });
    });
    return rows;
  }

  function applySavedTargets(rows) {
    const nextByVendor = new Map((state.rows || []).map((row) => [String(row.vendor_id || ''), Object.assign({}, row)]));
    const nowIso = new Date().toISOString();

    (rows || []).forEach((row) => {
      const vendorId = String(row.vendor_id || '').trim();
      if (!vendorId) return;

      const current = nextByVendor.get(vendorId) || {
        vendor_id: vendorId,
        vendor_name: decodeVendorLabel(row.vendor_name || 'Sem vendedor'),
        locked: false,
        editable: true,
        risk_status: 'SEM_META',
        target_month: 0,
        target_period: 0
      };

      const targetAmount = Number(row.target_amount || 0);
      current.vendor_name = decodeVendorLabel(row.vendor_name || current.vendor_name || 'Sem vendedor');
      current.target_month = targetAmount;
      current.target_period = targetAmount;
      current.updated_at = row.updated_at || nowIso;
      current.risk_status = targetAmount > 0 ? 'OK' : 'SEM_META';
      nextByVendor.set(vendorId, current);
    });

    state.rows = Array.from(nextByVendor.values());
    state.meta = Object.assign({}, state.meta, {
      seller_count: state.rows.length,
      with_target_count: state.rows.filter((row) => Number(row.target_month || 0) > 0).length,
      missing_targets_count: state.rows.filter((row) => Number(row.target_month || 0) <= 0).length,
      team_target: state.rows.reduce((sum, row) => sum + Number(row.target_month || 0), 0)
    });
    updateSummaryChips();
    renderTableRows();
  }

  async function saveRows(rows, triggerButton) {
    if (!rows.length) {
      setStatus('Nenhuma meta preenchida para salvar.', 'info');
      return;
    }

    state.saving = true;
    if (triggerButton) triggerButton.disabled = true;
    const saveAllButton = document.getElementById('saveAllTargets');
    if (saveAllButton) saveAllButton.disabled = true;

    setStatus(rows.length === 1 ? 'Salvando meta...' : `Salvando ${rows.length} metas...`, 'info');
    try {
      const response = await window.fetchJson('api/flight_plan_targets_upsert.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
          csrf_token: csrfToken,
          rows
        }),
        cache: 'no-store',
        timeoutMs: 20000
      });
      applySavedTargets(Array.isArray(response?.rows) && response.rows.length ? response.rows : rows);
      setStatus(rows.length === 1 ? 'Meta salva com sucesso.' : `${rows.length} metas salvas com sucesso.`, 'success');
      await loadTargets();
    } catch (error) {
      setStatus(error.message || 'Nao foi possivel salvar as metas.', 'danger');
    } finally {
      state.saving = false;
      if (triggerButton) triggerButton.disabled = false;
      if (saveAllButton && !(state.meta && state.meta.locked)) saveAllButton.disabled = false;
    }
  }

  function bindEvents() {
    document.getElementById('syncNow')?.addEventListener('click', () => {
      loadTargets();
    });

    document.getElementById('dateStart')?.addEventListener('change', () => {
      loadTargets();
    });

    document.getElementById('targetSellerSearch')?.addEventListener('input', (event) => {
      state.filter = String(event.target?.value || '');
      renderTableRows();
    });

    document.getElementById('saveAllTargets')?.addEventListener('click', async () => {
      await saveRows(collectBatchPayload());
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    const monthField = document.getElementById('dateStart');
    if (monthField && !monthField.value) {
      monthField.value = currentYearMonth();
    }
    bindEvents();
    loadTargets().catch(() => {});
  });
})();
