(function () {
  'use strict';

  function text(id, value) {
    const node = document.getElementById(id);
    if (node) node.textContent = value;
  }

  function html(id, value) {
    const node = document.getElementById(id);
    if (node) node.innerHTML = value;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderEmptyTable(targetId, message, colspan) {
    html(targetId, `<tr><td colspan="${colspan || 2}" style="text-align:center;color:var(--mag-muted);padding:18px;">${escapeHtml(message)}</td></tr>`);
  }

  function setInitial(formatters) {
    text('selectedFeatureBadge', 'Nenhuma selecao');
    text('geoDrawerHeading', 'Leitura comercial');
    text('panelTitle', 'Selecione um território');
    text('panelSubtitle', 'Clique em um território no mapa para ver a leitura comercial.');
    text('kpiFeatureRevenue', '-');
    text('kpiFeatureClients', '-');
    text('kpiFeatureNotes', '-');
    text('kpiFeatureTicket', '-');
    renderEmptyTable('topBuyersTable', 'Selecione um bairro com vendas.', 3);
    renderEmptyTable('cnaeRevenueTable', 'Selecione um bairro para ver os CNAEs.', 3);
    document.getElementById('buyerChartBlock')?.classList.add('is-hidden');
    document.getElementById('cnaeChartBlock')?.classList.add('is-hidden');
    window.BIomieChartHelpers?.destroy('buyerChartCanvas');
    window.BIomieChartHelpers?.destroy('cnaeChartCanvas');
    const geo360El = document.getElementById('geo360Btn');
    if (geo360El) geo360El.style.display = 'none';
  }

  function setGlobal(buyers, cnaes, formatters) {
    text('selectedFeatureBadge', 'Visão geral do Ceará');
    text('geoDrawerHeading', 'Visao geral');
    text('panelTitle', 'Visao geral do Ceará');
    text('panelSubtitle', 'Top compradores e CNAEs do periodo. Clique em um território para detalhar.');
    text('kpiFeatureRevenue', '-');
    text('kpiFeatureClients', '-');
    text('kpiFeatureNotes', '-');
    text('kpiFeatureTicket', '-');

    // Adapt top_buyers (geo_summary format) to renderBuyers format
    const buyerRows = (Array.isArray(buyers) ? buyers : []).map((r) => ({
      customer_name:    r.nome         || r.customer_name    || '',
      document_masked:  maskDoc(r.documento || r.document_masked || ''),
      cliente_nome:     r.cliente_nome || r.nome || r.customer_name || '',
      cliente_documento: r.cliente_documento || r.documento || '',
      documento_norm:   r.documento_norm || '',
      codigo_cliente_omie: r.codigo_cliente_omie || '',
      bairro:           r.bairro || r.bairro_norm || '',
      bairro_norm:      r.bairro_norm || r.bairro || '',
      cidade:           r.cidade || r.city || r.cidade_norm || '',
      cidade_norm:      r.cidade_norm || r.cidade || '',
      invoices:         r.notas        || r.invoices         || 0,
      revenue:          r.faturamento  || r.revenue          || 0,
    }));

    // Adapt top_cnaes (geo_summary format) to renderCnaes format
    const cnaeRows = (Array.isArray(cnaes) ? cnaes : []).map((r) => ({
      cnae:         r.cnae_codigo    || r.cnae   || 'SEM_CNAE',
      cnae_description: r.cnae_descricao || r.cnae_description || '',
      customers:    r.clientes       || r.customers || 0,
      revenue:      r.faturamento    || r.revenue   || 0,
    }));

    renderBuyers(buyerRows, formatters);
    if (cnaeRows.length) {
      renderCnaes(cnaeRows, formatters);
    } else {
      renderEmptyTable('cnaeRevenueTable', 'CNAE ainda nao materializado. Execute cnae:diagnose-fiscal-sources no Console Admin para descobrir a fonte fiscal disponivel.', 3);
      document.getElementById('cnaeChartBlock')?.classList.add('is-hidden');
    }
  }

  function maskDoc(raw) {
    const digits = String(raw || '').replace(/\D/g, '');
    if (digits.length === 11) return '***.***.XXX-' + digits.slice(-2);
    if (digits.length === 14) return digits.slice(0, 2) + '.***.***/****-' + digits.slice(-2);
    if (digits.length > 4) return '*'.repeat(digits.length - 4) + digits.slice(-4);
    return raw || 'Nao informado';
  }

  function dataAttr(name, value) {
    return `data-${name}="${escapeHtml(value || '')}"`;
  }

  function buyerAttrs(row) {
    const name = row.cliente_nome || row.customer_name || row.cliente || '';
    return [
      dataAttr('biomie-action', 'open-customer'),
      dataAttr('cliente-documento', row.cliente_documento || row.documento || row.document_raw || ''),
      dataAttr('documento-norm', row.documento_norm || row.documento_normalizado || ''),
      dataAttr('codigo-cliente-omie', row.codigo_cliente_omie || ''),
      dataAttr('cliente-nome', name),
      dataAttr('bairro', row.bairro || row.bairro_norm || ''),
      dataAttr('bairro-norm', row.bairro_norm || row.bairro || ''),
      dataAttr('city', row.city || row.cidade || row.cidade_norm || ''),
      dataAttr('cidade-norm', row.cidade_norm || row.cidade || '')
    ].join(' ');
  }

  // GEO.R1.8.1 — territory type labels and colors
  const TERRITORY_TYPE_LABELS = {
    fortaleza_bairro:       { label: 'Bairro · Fortaleza',          color: '#1D4ED8' },
    metro_bairro:           { label: 'Bairro · Grande Fortaleza',    color: '#0F766E' },
    rmf_municipio_fallback: { label: 'Município · RMF',              color: '#6D28D9' },
    ceara_municipio:        { label: 'Município · Ceará',            color: '#B45309' },
  };

  function renderNeighborhood(row, formatters) {
    const data = row || {};
    const nbName  = data.neighborhood || data.bairro_nome || data.municipio_nome || 'Território';
    const nbKey   = data.neighborhood_key || data.bairro_key || normalizeKey(nbName);
    const city    = data.city || data.municipio_nome || 'Ceará';
    // GEO.R1.8.1 — usar municipio real, não fixo "Fortaleza"
    const subtitle = city ? `${city} · Ceará` : 'Ceará';
    // Territory type badge
    const ttype   = data.territory_type || '';
    const ttDef   = TERRITORY_TYPE_LABELS[ttype] || null;

    text('selectedFeatureBadge', ttDef ? ttDef.label : nbName);
    text('geoDrawerHeading', nbName);
    text('panelTitle', nbName);
    text('panelSubtitle', subtitle);
    text('kpiFeatureRevenue', formatters.money(data.revenue));
    text('kpiFeatureClients', formatters.number(data.customers));
    text('kpiFeatureNotes', formatters.number(data.invoices));
    text('kpiFeatureTicket', formatters.money(data.average_ticket));

    // GEO.R1.8.1 — territory type badge in drawer eyebrow
    const eyebrow = document.querySelector('.geo-drawer__eyebrow');
    if (eyebrow && ttDef) {
      eyebrow.innerHTML = `<span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#fff;background:${ttDef.color}">${escapeHtml(ttDef.label)}</span>`;
    } else if (eyebrow) {
      eyebrow.textContent = 'Detalhe do território';
    }

    const geo360El = document.getElementById('geo360Btn');
    if (geo360El && nbKey) {
      geo360El.setAttribute('data-neighborhood-key', nbKey);
      geo360El.setAttribute('data-neighborhood-name', nbName);
      geo360El.setAttribute('data-city', city);
      geo360El.style.display = '';
    }

    // GEO.R1.8.2 — show "Ver ações" quick-jump button
    const actionsBtn = document.getElementById('geoDrawerActionsBtn');
    if (actionsBtn) actionsBtn.style.display = '';
  }

  function normalizeKey(value) {
    // bairro_key format: underscore-based, matching GeoKeyNormalizer::makeNeighborhoodKey().
    return String(value ?? '').trim()
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
  }

  function clearBuyersSourceBadge() {
    const el = document.getElementById('buyersSourceBadge');
    if (el) { el.innerHTML = ''; el.style.display = 'none'; }
  }

  function renderBuyersLoading() {
    clearBuyersSourceBadge();
    html('topBuyersTable', '<tr><td colspan="3" style="text-align:center;color:var(--mag-muted);padding:18px;">Carregando compradores…</td></tr>');
    document.getElementById('buyerChartBlock')?.classList.add('is-hidden');
    window.BIomieChartHelpers?.destroy('buyerChartCanvas');
  }

  function renderBuyersError(message) {
    clearBuyersSourceBadge();
    html('topBuyersTable', `<tr><td colspan="3" style="text-align:center;color:var(--danger,#dc2626);padding:18px;">${escapeHtml(message || 'Erro ao carregar compradores.')}</td></tr>`);
    document.getElementById('buyerChartBlock')?.classList.add('is-hidden');
    window.BIomieChartHelpers?.destroy('buyerChartCanvas');
  }

  function renderBuyers(rows, formatters, sourceMode) {
    const dataRows = Array.isArray(rows) ? rows.slice(0, 10) : [];
    if (!dataRows.length) {
      renderEmptyTable('topBuyersTable', 'Sem compradores neste bairro no periodo.', 3);
      document.getElementById('buyerChartBlock')?.classList.add('is-hidden');
      window.BIomieChartHelpers?.destroy('buyerChartCanvas');
      return;
    }

    const isFallback = sourceMode === 'canonical_fallback';
    if (isFallback) {
      const headerEl = document.getElementById('buyersSourceBadge');
      if (headerEl) {
        headerEl.innerHTML = '<span style="font-size:10px;font-weight:600;color:var(--warning,#d97706);background:var(--warning-soft,rgba(217,119,6,.14));border-radius:4px;padding:2px 6px;">Fonte: NF direta (mart nao materializado)</span>';
        headerEl.style.display = '';
      }
    } else {
      const headerEl = document.getElementById('buyersSourceBadge');
      if (headerEl) headerEl.style.display = 'none';
    }

    html('topBuyersTable', dataRows.map((row) => {
      const attrs = buyerAttrs(row);
      const name = row.customer_name || row.cliente || row.cliente_nome || 'Sem nome';
      return `
      <tr class="geo-buyer-row" ${attrs} tabindex="0" style="cursor:pointer;" title="Abrir Cliente 360">
        <td>
          <button type="button" class="row-inline-link" ${attrs} style="font-weight:700;text-align:left;">${escapeHtml(name)}</button>
          <div style="color:var(--mag-muted);font-size:12px;">${escapeHtml(row.document_masked || row.documento_mascarado || 'Nao informado')}</div>
        </td>
        <td style="text-align:right;">${formatters.number(row.invoices || row.qtd_notas)}</td>
        <td style="text-align:right;"><strong>${formatters.money(row.revenue || row.faturamento)}</strong></td>
      </tr>
    `;
    }).join(''));

    document.getElementById('buyerChartBlock')?.classList.remove('is-hidden');
    window.BIomieChartHelpers?.renderHorizontalBar('buyerChartCanvas', dataRows.slice(0, 5), {
      labelKey: 'customer_name',
      valueKey: 'revenue',
      datasetLabel: 'Faturamento',
      limit: 5,
      color: 'rgba(150, 20, 10, 0.68)',
      formatTick: formatters.compactMoney,
      formatValue: formatters.money,
    });
  }

  function renderCnaes(rows, formatters) {
    const dataRows = Array.isArray(rows) ? rows.slice(0, 10) : [];
    if (!dataRows.length) {
      renderEmptyTable('cnaeRevenueTable', 'Sem CNAEs para o filtro.', 3);
      document.getElementById('cnaeChartBlock')?.classList.add('is-hidden');
      window.BIomieChartHelpers?.destroy('cnaeChartCanvas');
      return;
    }

    html('cnaeRevenueTable', dataRows.map((row) => `
      <tr>
        <td>
          <strong>${escapeHtml(row.cnae || row.cnae_codigo || 'SEM_CNAE')}</strong>
          <div style="color:var(--mag-muted);font-size:12px;">${escapeHtml(row.cnae_description || row.cnae_descricao || row.segment || 'PJ sem CNAE')}</div>
        </td>
        <td style="text-align:right;">${formatters.number(row.customers || row.qtd_clientes)}</td>
        <td style="text-align:right;"><strong>${formatters.money(row.revenue || row.faturamento)}</strong></td>
      </tr>
    `).join(''));

    document.getElementById('cnaeChartBlock')?.classList.remove('is-hidden');
    window.BIomieChartHelpers?.renderHorizontalBar('cnaeChartCanvas', dataRows.slice(0, 5), {
      labelKey: 'cnae',
      valueKey: 'revenue',
      datasetLabel: 'Faturamento',
      limit: 5,
      color: 'rgba(36, 54, 79, 0.72)',
      formatTick: formatters.compactMoney,
      formatValue: formatters.money,
    });
  }

  function renderNoNeighborhood(formatters, count, revenue, buyerRows, context) {
    clearBuyersSourceBadge();
    const ctx = context || {};
    text('selectedFeatureBadge', 'Sem bairro');
    text('geoDrawerHeading', 'Sem bairro');
    text('panelTitle', ctx.title || 'Sem bairro cadastrado');
    text('panelSubtitle', 'Qualidade de cadastro Omie — nao e um bug tecnico');
    text('kpiFeatureRevenue', formatters.money(revenue || 0));
    text('kpiFeatureClients', formatters.number(count || 0));
    text('kpiFeatureNotes', '-');
    text('kpiFeatureTicket', '-');

    const message = ctx.message || 'Clientes sem bairro cadastrado no Omie. Atualize o cadastro/endereco do cliente no Omie para que aparecam no mapa.';

    const geo360El = document.getElementById('geo360Btn');
    if (geo360El) geo360El.style.display = 'none';

    if (Array.isArray(buyerRows) && buyerRows.length > 0) {
      html('topBuyersTable', buyerRows.slice(0, 10).map((row) => {
        const attrs = buyerAttrs(row);
        const name = row.customer_name || row.cliente || row.cliente_nome || 'Sem nome';
        return `
          <tr class="geo-buyer-row" ${attrs} tabindex="0" style="cursor:pointer;" title="Abrir Cliente 360">
            <td>
              <button type="button" class="row-inline-link" ${attrs} style="font-weight:700;text-align:left;">${escapeHtml(name)}</button>
              <div style="color:var(--mag-muted);font-size:12px;">${escapeHtml(row.document_masked || row.documento_mascarado || 'Nao informado')}</div>
            </td>
            <td style="text-align:right;">${formatters.number(row.invoices || row.qtd_notas || 0)}</td>
            <td style="text-align:right;"><strong>${formatters.money(row.revenue || row.faturamento || 0)}</strong></td>
          </tr>
        `;
      }).join(''));
    } else {
      html('topBuyersTable', `
        <tr>
          <td colspan="3" style="padding:14px 8px;">
            <div style="font-size:13px;color:var(--mag-muted,#6b7280);line-height:1.5;">
              ${escapeHtml(message)}
            </div>
          </td>
        </tr>
      `);
    }

    renderEmptyTable('cnaeRevenueTable', 'CNAE indisponivel para registros sem bairro.', 3);
    document.getElementById('buyerChartBlock')?.classList.add('is-hidden');
    document.getElementById('cnaeChartBlock')?.classList.add('is-hidden');
    window.BIomieChartHelpers?.destroy('buyerChartCanvas');
    window.BIomieChartHelpers?.destroy('cnaeChartCanvas');
  }

  window.BIomieMapPanel = {
    setInitial,
    setGlobal,
    renderNeighborhood,
    renderNoNeighborhood,
    renderBuyers,
    renderBuyersLoading,
    renderBuyersError,
    renderCnaes,
  };
})();
