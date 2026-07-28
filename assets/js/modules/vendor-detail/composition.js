(function () {
  'use strict';

  var B = window.BIomie || {};
  B.VendorDetail = B.VendorDetail || {};
  
  function renderComposition(data) {
    var T = B.VendorDetail.Tabs;
    if (!T) return '<div class="biomie-drawer__empty">Erro: Modulo tabs.js nao carregado.</div>';
    
    var rows = data.realized_composition || [];
    var officialRealized = T.toNumber(data.vendor && (data.vendor.realizado_vendedor || data.vendor.realized_period));
    var officialMerchandise = T.toNumber(data.vendor && (data.vendor.mercadoria_faturada_vendedor || data.vendor.merchandise_total));
    
    if (!Array.isArray(rows) || !rows.length) {
      if (officialRealized > 0.01) {
        return T.empty('Erro: Nao ha composicao fiscal enviada, mas o vendedor possui saldo realizado de ' + T.money(officialRealized) + '. Verifique auditoria de backend.');
      }
      return T.empty('Sem notas fiscais autorizadas para compor o realizado no periodo.');
    }
    
    var totalRealized = 0;
    var totalMerchandise = 0;
    var tableRows = rows.map(function(row) {
      var nfCode = String(T.pick(row, 'nf|numero_nfe|numero_documento') || '').trim();
      var orderCode = String(T.pick(row, 'pedido|numero_pedido') || '').trim();
      var customerName = String(T.pick(row, 'cliente|customer_name') || 'Cliente nao informado');
      var val = T.toNumber(T.pick(row, 'valor_usado|total'));
      var merc = T.toNumber(T.pick(row, 'valor_mercadoria|mercadorias'));
      var desc = T.toNumber(T.pick(row, 'desconto'));
      var refDate = String(T.pick(row, 'competencia|emissao|data_emissao') || '');
      var status = String(T.pick(row, 'status') || '');
      
      totalRealized += val;
      totalMerchandise += merc;
      
      return '<div class="biomie-drawer__list-item" style="display:grid;grid-template-columns:1fr 1fr 2fr 1fr 1fr 1fr;gap:8px;">' +
        '<span>' + T.actionButton('NF ' + (nfCode || '-'), 'open-invoice', { 'data-numero-nf': nfCode }) + '</span>' +
        '<span>' + (orderCode ? T.actionButton(orderCode, 'open-order', { 'data-pedido-codigo': orderCode }) : '-') + '</span>' +
        '<span>' + T.customerButton(customerName, '', '', orderCode) + '</span>' +
        '<span style="font-size:.75rem;color:var(--text-3)">' + T.esc(T.date(refDate)) + '</span>' +
        '<span style="font-size:.75rem;color:var(--text-3)">' + T.esc(status) + '</span>' +
        '<span style="font-weight:700;text-align:right;">' + T.esc(T.money(val)) + '</span>' +
      '</div>';
    }).join('');
    
    var diff = Math.abs(totalRealized - officialRealized);
    var alertHtml = '';
    
    if (diff > 0.01) {
      alertHtml = '<div style="margin-bottom:16px;background:var(--danger-muted);color:var(--danger);padding:12px;border-radius:4px;border-left:4px solid var(--danger);">' +
        '<strong>Atencao:</strong> A soma da composicao nao fecha com o resumo do realizado.<br>' +
        'Diferenca: ' + T.money(diff) + '. Verifique a auditoria do VendorRealizedService.' +
      '</div>';
    }
    
    var headerRow = '<div class="biomie-drawer__list-item" style="display:grid;grid-template-columns:1fr 1fr 2fr 1fr 1fr 1fr;gap:8px;font-weight:bold;background:var(--bg-muted);border-bottom:none;">' +
      '<span>NF</span><span>Pedido</span><span>Cliente</span><span>Data</span><span>Status</span><span style="text-align:right;">Valor Usado</span>' +
    '</div>';
    
    var footerRow = '<div class="biomie-drawer__list-item" style="display:flex;justify-content:space-between;font-weight:bold;background:var(--bg-muted);border-top:2px solid var(--border);">' +
      '<span>Total das notas:</span>' +
      '<span>' + T.esc(T.money(totalRealized)) + '</span>' +
    '</div>' +
    '<div class="biomie-drawer__list-item" style="display:flex;justify-content:space-between;font-weight:bold;">' +
      '<span>Vendas faturadas no resumo:</span>' +
      '<span>' + T.esc(T.money(officialRealized)) + '</span>' +
    '</div>';

    return alertHtml + 
      '<div style="font-size:0.8rem;color:var(--text-2);margin-bottom:12px;">' +
      'Notas fiscais autorizadas que formam as vendas faturadas do vendedor.' +
      '</div>' +
      headerRow + 
      tableRows + 
      footerRow;
  }

  B.VendorDetail.Composition = {
    renderComposition: renderComposition
  };
}());
