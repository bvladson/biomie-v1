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

  function previousYearMonth(yearMonth) {
    const [year, month] = String(yearMonth || '').split('-').map(Number);
    if (!year || !month) return '';
    const date = new Date(year, month - 2, 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  }

  function normalizeRows(rows) {
    return (rows || []).map((row) => ({
      vendor_id: String(row.vendor_id || '').trim(),
      vendor_name: decodeVendorLabel(row.vendor_name || 'Sem vendedor'),
      target_month: Number(row.target_month || row.target_period || 0),
      target_period: Number(row.target_period || row.target_month || 0),
      // Metas múltiplas: níveis opcionais além da meta base (Meta 2..N)
      tiers: Array.isArray(row.tiers) ? row.tiers.map(Number).filter((v) => Number.isFinite(v) && v > 0) : [],
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
    const withTarget = rows.filter((row) => Number(row.target_month) > 0).length;
    const sellerCount = Number(meta.seller_count ?? rows.length);
    const missingCount = Math.max(0, sellerCount - withTarget);
    const teamTarget = rows.reduce((sum, row) => sum + Number(row.target_month || 0), 0);

    const sellerCountEl = document.getElementById('targetsSellerCount');
    const configuredEl = document.getElementById('targetsConfiguredCount');
    const missingEl = document.getElementById('targetsMissingCount');
    const teamTargetEl = document.getElementById('targetsTeamTarget');
    const monthEl = document.getElementById('targetsMonthChip');
    const modeEl = document.getElementById('targetsModeChip');

    if (sellerCountEl) sellerCountEl.textContent = formatNumber(sellerCount);
    if (configuredEl) configuredEl.textContent = formatNumber(withTarget);
    if (missingEl) missingEl.textContent = formatNumber(missingCount);
    if (teamTargetEl) teamTargetEl.textContent = formatCurrency(teamTarget);
    if (monthEl) monthEl.textContent = monthLabel(meta.year_month || currentYearMonth());
    if (modeEl) modeEl.textContent = meta.locked ? 'Histórico protegido' : 'Edição aberta';
    const copyPreviousButton = document.getElementById('copyPreviousTargets');
    if (copyPreviousButton) {
      copyPreviousButton.style.display = !meta.locked && missingCount > 0 ? 'inline-flex' : 'none';
      copyPreviousButton.disabled = state.saving;
    }
  }

  function statusChip(row) {
    if (row.locked) {
      return '<span class="status-chip status-chip--muted">Histórico protegido</span>';
    }
    if (Number(row.target_month) > 0) {
      return '<span class="status-chip status-chip--ok">Meta pronta</span>';
    }
    return '<span class="status-chip status-chip--attention">Meta pendente</span>';
  }

  function renderTableRows() {
    const target = document.getElementById('targetsFormTable');
    const saveAllButton = document.getElementById('saveAllTargets');
    if (!target) return;

    const rows = filteredRows();
    if (!rows.length) {
      target.innerHTML = `<tr><td colspan="5" class="empty-row">${state.rows.length ? 'Nenhum vendedor encontrado para esse filtro.' : 'Sem vendedores disponíveis para esse mês.'}</td></tr>`;
      if (saveAllButton) saveAllButton.style.display = 'none';
      return;
    }

    target.innerHTML = rows.map((row) => {
      const currentTarget = Number(row.target_month || 0);
      const placeholder = currentTarget > 0 ? formatCurrency(currentTarget) : 'ex: 80000';
      const updatedAt = row.updated_at
        ? `<small>Atualizada em ${new Date(row.updated_at).toLocaleString('pt-BR')}</small>`
        : `<small>${escapeHtml(row.vendor_id)}</small>`;

      // Metas múltiplas: inputs compactos dos níveis (Meta 2..N), opcionais
      const tiers = Array.isArray(row.tiers) ? row.tiers : [];
      const tierInputs = tiers.map((tierValue, i) => `
        <span class="tier-item">
          <label>Meta ${i + 2}</label>
          <input type="number" class="filter-input tier-input" value="${Number(tierValue)}" min="0" step="0.01" ${row.locked ? 'disabled' : ''} />
          ${row.locked ? '' : '<button type="button" class="btn-tier-remove" title="Remover nível">&times;</button>'}
        </span>`).join('');
      const tierBlock = `
        <div class="tier-list" data-vendor-id="${escapeHtml(row.vendor_id)}">
          ${tierInputs}
          ${row.locked ? '' : '<button type="button" class="btn-secondary btn-add-tier" title="Adicionar nível de meta (ex: Meta 2, Meta 3...)"><span class="material-symbols-rounded" style="font-size:14px">add</span> nível</button>'}
        </div>`;

      return `<tr data-vendor-id="${escapeHtml(row.vendor_id)}" data-vendor-name="${escapeHtml(row.vendor_name)}">
        <td>
          <div class="flight-seller">
            <strong>${escapeHtml(row.vendor_name)}</strong>
            ${updatedAt}
          </div>
        </td>
        <td>${currentTarget > 0 ? `<span class="status-chip status-chip--ok">${formatCurrency(currentTarget)}</span>` : '<span class="status-chip status-chip--muted">Meta pendente</span>'}${tiers.length ? `<br /><small class="tier-summary">+${tiers.length} nível(is): ${tiers.map((t) => formatCurrency(t)).join(' · ')}</small>` : ''}</td>
        <td>
          <input type="number" class="filter-input target-input" data-vendor-id="${escapeHtml(row.vendor_id)}" placeholder="${escapeHtml(placeholder)}" value="${currentTarget > 0 ? String(currentTarget) : ''}" min="0" step="0.01" ${row.locked ? 'disabled' : ''} />
          ${tierBlock}
        </td>
        <td>${statusChip(row)}</td>
        <td><button class="btn-secondary btn-save-single" data-vendor-id="${escapeHtml(row.vendor_id)}" ${row.locked ? 'disabled' : ''}><span class="material-symbols-rounded" style="font-size:16px">save</span></button></td>
      </tr>`;
    }).join('');

    // Adicionar/remover níveis de meta (delegação simples pós-render)
    target.querySelectorAll('.btn-add-tier').forEach((button) => {
      button.addEventListener('click', () => {
        const list = button.closest('.tier-list');
        if (!list) return;
        const count = list.querySelectorAll('.tier-input').length;
        const item = document.createElement('span');
        item.className = 'tier-item';
        item.innerHTML = `<label>Meta ${count + 2}</label>
          <input type="number" class="filter-input tier-input" min="0" step="0.01" placeholder="ex: 120000" />
          <button type="button" class="btn-tier-remove" title="Remover nível">&times;</button>`;
        list.insertBefore(item, button);
        item.querySelector('.btn-tier-remove')?.addEventListener('click', () => item.remove());
        item.querySelector('.tier-input')?.focus();
      });
    });
    target.querySelectorAll('.btn-tier-remove').forEach((button) => {
      button.addEventListener('click', () => button.closest('.tier-item')?.remove());
    });

    target.querySelectorAll('.btn-save-single').forEach((button) => {
      button.addEventListener('click', async () => {
        const vendorId = button.getAttribute('data-vendor-id') || '';
        const row = target.querySelector(`tr[data-vendor-id="${vendorId}"]`);
        const input = row?.querySelector('.target-input');
        const vendorName = row?.getAttribute('data-vendor-name') || '';
        if (!input || input.disabled) return;

        const value = parseFloat(input.value);
        if (Number.isNaN(value)) {
          setStatus('Preencha um valor válido antes de salvar.', 'warning');
          return;
        }

        await saveRows([{
          vendor_id: vendorId,
          vendor_name: vendorName,
          year_month: currentYearMonth(),
          target_amount: value,
          tiers: collectTiersFromRow(row)
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
        setStatus('Mês encerrado. A edição está bloqueada para preservar o histórico.', 'info');
      }
    } catch (error) {
      const target = document.getElementById('targetsFormTable');
      if (target) {
        target.innerHTML = `<tr><td colspan="5" class="empty-row" style="color:var(--danger)">Erro ao carregar metas: ${escapeHtml(error.message || 'falha desconhecida')}</td></tr>`;
      }
      setStatus(error.message || 'Não foi possível carregar as metas.', 'danger');
    } finally {
      setLoading(false);
    }
  }

  async function copyPreviousTargets() {
    const currentMonth = currentYearMonth();
    const previousMonth = previousYearMonth(currentMonth);
    if (!previousMonth || state.meta.locked) return;

    setLoading(true);
    setStatus(`Buscando metas de ${monthLabel(previousMonth)}...`, 'info');
    try {
      const data = await window.fetchJson(`api/flight_plan_targets_editor.php?year_month=${encodeURIComponent(previousMonth)}&page=1&per_page=300`, {
        cache: 'no-store',
        timeoutMs: 20000
      });
      const previousByVendor = new Map(normalizeRows(data.data || [])
        .filter((row) => Number(row.target_month) > 0)
        .map((row) => [row.vendor_id, Number(row.target_month)]));
      let copied = 0;

      state.rows = state.rows.map((row) => {
        const previousTarget = previousByVendor.get(row.vendor_id) || 0;
        if (Number(row.target_month) > 0 || previousTarget <= 0 || row.locked) return row;
        copied++;
        return Object.assign({}, row, {
          target_month: previousTarget,
          target_period: previousTarget,
          risk_status: 'OK'
        });
      });

      updateSummaryChips();
      renderTableRows();
      setStatus(
        copied > 0
          ? `${copied} meta(s) anterior(es) preenchida(s) para revisão. Confira os valores e clique em Salvar metas.`
          : 'Nenhuma meta anterior compatível foi encontrada para as pendências atuais.',
        copied > 0 ? 'success' : 'warning'
      );
    } catch (error) {
      setStatus(error.message || 'Não foi possível buscar as metas do mês anterior.', 'danger');
    } finally {
      setLoading(false);
    }
  }

  // Lê os níveis (Meta 2..N) preenchidos numa linha do editor
  function collectTiersFromRow(rowEl) {
    const tiers = [];
    (rowEl?.querySelectorAll('.tier-input') || []).forEach((input) => {
      if (input.disabled || input.value.trim() === '') return;
      const value = parseFloat(input.value);
      if (Number.isFinite(value) && value > 0) tiers.push(value);
    });
    return tiers;
  }

  // Compara o formulário com o estado carregado e retorna SÓ as linhas
  // alteradas (dirty tracking) — "Salvar metas" não regrava o mês inteiro,
  // não mexe em updated_at de quem não mudou e não gera auditoria vazia.
  function collectBatchPayload() {
    const stateByVendor = new Map((state.rows || []).map((row) => [String(row.vendor_id || ''), row]));
    const rows = [];
    document.querySelectorAll('#targetsFormTable tr[data-vendor-id]').forEach((row) => {
      const input = row.querySelector('.target-input');
      if (!input || input.disabled || input.value.trim() === '') return;

      const value = parseFloat(input.value);
      if (Number.isNaN(value)) return;

      const vendorId = row.getAttribute('data-vendor-id') || '';
      const current  = stateByVendor.get(vendorId);
      const tiers    = collectTiersFromRow(row);
      if (current) {
        const savedTarget = Number(current.target_month || 0);
        const savedTiers  = Array.isArray(current.tiers)
          ? current.tiers.map(Number).filter((v) => Number.isFinite(v) && v > 0)
          : [];
        const sameTarget = Math.abs(savedTarget - value) < 0.005;
        const sameTiers  = savedTiers.length === tiers.length
          && savedTiers.every((v, i) => Math.abs(v - tiers[i]) < 0.005);
        if (sameTarget && sameTiers) return; // sem mudança real — não regrava
      }

      rows.push({
        vendor_id: vendorId,
        vendor_name: row.getAttribute('data-vendor-name') || '',
        year_month: currentYearMonth(),
        target_amount: value,
        tiers: tiers
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
      current.tiers = Array.isArray(row.tiers) ? row.tiers.map(Number).filter((v) => Number.isFinite(v) && v > 0) : (current.tiers || []);
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
    if (state.saving) return; // single-flight: 1 clique = 1 POST, mesmo com listener duplicado
    if (!rows.length) {
      setStatus('Nenhuma alteração de meta para salvar.', 'info');
      return;
    }

    state.saving = true;
    if (triggerButton) triggerButton.disabled = true;
    const saveAllButton = document.getElementById('saveAllTargets');
    if (saveAllButton) saveAllButton.disabled = true;
    const copyPreviousButton = document.getElementById('copyPreviousTargets');
    if (copyPreviousButton) copyPreviousButton.disabled = true;

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
      if (copyPreviousButton && !(state.meta && state.meta.locked)) copyPreviousButton.disabled = false;
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

    document.getElementById('copyPreviousTargets')?.addEventListener('click', () => {
      copyPreviousTargets();
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    // Boot idempotente: se o script for incluído duas vezes, o segundo boot
    // não registra listeners de novo (evita POST duplo por clique).
    if (window.__biomieTargetsBooted) return;
    window.__biomieTargetsBooted = true;

    const monthField = document.getElementById('dateStart');
    if (monthField && !monthField.value) {
      monthField.value = currentYearMonth();
    }
    bindEvents();
    loadTargets().catch(() => {});
  });
})();
