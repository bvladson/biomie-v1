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
    text('panelTitle', 'Selecione um bairro');
    text('panelSubtitle', 'Clique em um bairro no mapa para ver a leitura comercial.');
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
  }

  function setGlobal(buyers, cnaes, formatters) {
    text('selectedFeatureBadge', 'Todos os bairros');
    text('panelTitle', 'Visao geral');
    text('panelSubtitle', 'Top compradores e CNAEs do periodo. Clique em um bairro para detalhar.');
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

  function renderNeighborhood(row, formatters) {
    const data = row || {};
    text('selectedFeatureBadge', data.neighborhood || 'Bairro');
    text('panelTitle', data.neighborhood || 'Bairro');
    text('panelSubtitle', data.city ? `Fortaleza / ${data.city}` : 'Fortaleza');
    text('kpiFeatureRevenue', formatters.money(data.revenue));
    text('kpiFeatureClients', formatters.number(data.customers));
    text('kpiFeatureNotes', formatters.number(data.invoices));
    text('kpiFeatureTicket', formatters.money(data.average_ticket));
  }

  function renderBuyers(rows, formatters) {
    const dataRows = Array.isArray(rows) ? rows.slice(0, 10) : [];
    if (!dataRows.length) {
      renderEmptyTable('topBuyersTable', 'Sem compradores para o filtro.', 3);
      document.getElementById('buyerChartBlock')?.classList.add('is-hidden');
      window.BIomieChartHelpers?.destroy('buyerChartCanvas');
      return;
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

  window.BIomieMapPanel = {
    setInitial,
    setGlobal,
    renderNeighborhood,
    renderBuyers,
    renderCnaes,
  };
})();
