(function () {
  'use strict';

  var B = window.BIomie || {};
  B.VendorDetail = B.VendorDetail || {};

  function esc(value) {
    if (B.escapeHtml) return B.escapeHtml(value);
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function toNumber(value) {
    if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
    var text = String(value == null ? '' : value).trim();
    if (!text) return 0;
    if (text.indexOf(',') >= 0) text = text.replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
    else text = text.replace(/[^\d.-]/g, '');
    var n = Number(text);
    return Number.isFinite(n) ? n : 0;
  }

  function money(value) {
    if (typeof value === 'string' && /^\s*R\$\s*/.test(value)) return value;
    return B.formatCurrency
      ? B.formatCurrency(toNumber(value))
      : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(toNumber(value));
  }

  function number(value) {
    return B.formatNumber ? B.formatNumber(toNumber(value)) : new Intl.NumberFormat('pt-BR').format(toNumber(value));
  }

  function date(value) {
    var s = String(value || '').trim();
    if (!s) return '-';
    var m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return m[3] + '/' + m[2] + '/' + m[1];
    var d = new Date(s);
    if (isNaN(d.getTime())) return s;
    return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
  }

  function pick(row, keys) {
    var parts = String(keys || '').split('|');
    for (var i = 0; i < parts.length; i += 1) {
      var key = parts[i];
      var v = row && row[key];
      if (v != null && String(v).trim() !== '') return v;
    }
    return '';
  }

  function actionButton(label, action, attrs) {
    attrs = attrs || {};
    var htmlAttrs = Object.keys(attrs).map(function (key) {
      return key + '="' + esc(attrs[key]) + '"';
    }).join(' ');
    return '<button type="button" class="row-inline-link" data-biomie-action="' + esc(action) + '" ' + htmlAttrs + '>' + esc(label) + '</button>';
  }

  function customerButton(name, id, documentValue, orderCode) {
    var doc = String(documentValue || '');
    if (doc.indexOf('*') >= 0) doc = '';
    if (!id && !doc && !orderCode) return '<span>' + esc(name || 'Cliente nao informado') + '</span>';
    return actionButton(name || 'Cliente nao informado', 'open-customer', {
      'data-codigo-cliente-omie': id || '',
      'data-cliente-documento': doc,
      'data-cliente-nome': name || '',
      'data-pedido-codigo': orderCode || ''
    });
  }

  function stat(label, value) {
    return '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">' + esc(label) + '</div><div class="biomie-drawer__stat-value">' + esc(String(value == null || value === '' ? '-' : value)) + '</div></div>';
  }

  function sectionTitle(label) {
    return '<div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">' + esc(label) + '</div>';
  }

  function empty(message) {
    return '<div class="biomie-drawer__empty">' + esc(message) + '</div>';
  }

  function renderResumo(data) {
    var v = data.vendor || {};
    var s = data.summary || {};
    var hasTarget = (v.target_period || 0) > 0;
    
    var html = '<div class="biomie-drawer__section">' +
      '<div style="font-size:0.8rem;color:var(--text-2);margin-bottom:12px;background:var(--bg-muted);padding:8px;border-radius:4px;border-left:3px solid var(--page-accent);">' +
      'Realizado calculado por Receita Fiscal Autorizada, sem notas canceladas.' +
      '</div>' +
      '<div class="biomie-drawer__stats">' +
      stat('Realizado oficial', money(v.realizado_vendedor || v.realized_period)) +
      stat('Meta', hasTarget ? money(v.target_period) : 'Sem meta cadastrada') +
      stat('Gap', money(v.gap_to_target || 0)) +
      stat('Atingimento', number(v.attainment_pct || 0) + '%') +
      stat('Projecao', money(v.forecast_period || 0)) +
      stat('Carteira', money(s.open_total || 0)) +
      stat('NFs autorizadas', number(s.invoices_count || 0)) +
      stat('Pedidos vinculados', number(s.orders_count || 0)) +
      stat('Clientes atendidos', number(s.customers_count || 0)) +
      stat('Ticket medio', money(s.ticket_avg || 0)) +
      '</div>';
      
    if (!hasTarget) {
      html += '<div style="margin-top:12px"><a href="plano_voo_metas.php" class="biomie-btn biomie-btn--sm">Editar metas</a></div>';
    }
    
    html += '</div>' +
      '<div class="biomie-drawer__section">' + sectionTitle('Clientes em destaque') + renderCustomers((data.customers || []).slice(0, 5), true) + '</div>';
      
    return html;
  }

  function renderOrders(rows) {
    if (!Array.isArray(rows) || !rows.length) return empty('Sem pedidos de carteira no periodo.');
    return sectionTitle('Pedidos de carteira') + rows.slice(0, 40).map(function (row) {
      var orderCode = String(pick(row, 'codigo|codigo_pedido|codigo_integracao') || '').trim();
      var customerName = String(pick(row, 'cliente|client_name|nome_cliente') || 'Cliente nao informado');
      var customerId = String(pick(row, 'client_id|codigo_cliente_omie') || '');
      var doc = String(pick(row, 'cliente_documento|client_document|customer_document') || '');
      var status = String(pick(row, 'status_group|status_comercial|stage|etapa') || '');
      var refDate = String(pick(row, 'issue_date|data_emissao|billing_date|data_faturamento') || '');
      var total = pick(row, 'gross_total|total|merchandise_total|valor_total');
      return '<div class="biomie-drawer__list-item">' +
        actionButton(orderCode || '-', 'open-order', { 'data-pedido-codigo': orderCode }) +
        customerButton(customerName, customerId, doc, orderCode) +
        '<span class="biomie-drawer__badge">' + esc(status || 'Carteira') + '</span>' +
        '<span style="font-size:.75rem;color:var(--text-3)">' + esc(date(refDate)) + '</span>' +
        '<span style="font-weight:700">' + esc(money(total)) + '</span>' +
      '</div>';
    }).join('');
  }

  function renderInvoices(rows) {
    if (!Array.isArray(rows) || !rows.length) return empty('Sem notas fiscais autorizadas no periodo.');
    return sectionTitle('Notas fiscais autorizadas') + rows.slice(0, 40).map(function (row) {
      var nfCode = String(pick(row, 'codigo|nf_code|numero_nf') || '').trim();
      var orderCode = String(pick(row, 'origin_order_code|codigo_integracao|codigo_pedido_origem') || '').trim();
      var customerName = String(pick(row, 'cliente|client_name|nome_cliente') || 'Cliente nao informado');
      var customerId = String(pick(row, 'client_id|codigo_cliente_omie') || '');
      var doc = String(pick(row, 'cliente_documento|client_document|customer_document') || '');
      var total = pick(row, 'gross_total|total|merchandise_total|valor_total');
      var refDate = String(pick(row, 'status_date|issue_date|data_emissao|billing_date') || '');
      return '<div class="biomie-drawer__list-item">' +
        actionButton('NF ' + (nfCode || '-'), 'open-invoice', {
          'data-numero-nf': nfCode,
          'data-chave-nfe': String(pick(row, 'document_key|chave_nfe') || ''),
          'data-nota-key': String(pick(row, 'nota_key') || '')
        }) +
        (orderCode ? actionButton(orderCode, 'open-order', { 'data-pedido-codigo': orderCode }) : '<span style="color:var(--text-3)">Sem pedido</span>') +
        customerButton(customerName, customerId, doc, orderCode) +
        '<span style="font-size:.75rem;color:var(--text-3)">' + esc(date(refDate)) + '</span>' +
        '<span style="font-weight:700">' + esc(money(total)) + '</span>' +
      '</div>';
    }).join('');
  }

  function renderCustomers(items, compact) {
    if (!Array.isArray(items) || !items.length) return empty('Sem clientes vinculados.');
    var rows = items.map(function (item) {
      var id = String(pick(item, 'id|client_id|codigo_cliente_omie') || '').trim();
      var name = String(pick(item, 'label|client_name|cliente') || 'Cliente nao informado');
      var value = pick(item, 'value|invoice_total|total|revenue');
      var notes = pick(item, 'orders|notes_count|notas');
      return '<div class="biomie-drawer__list-item">' +
        customerButton(name, id, String(pick(item, 'client_document|cliente_documento') || ''), '') +
        '<span style="font-size:.75rem;color:var(--text-3)">' + esc(number(notes || 0)) + ' doc.</span>' +
        '<span style="font-weight:700">' + esc(money(value)) + '</span>' +
      '</div>';
    }).join('');
    return compact ? rows : sectionTitle('Clientes do vendedor no periodo') + rows;
  }

  function renderProducts(items) {
    if (!Array.isArray(items) || !items.length) return empty('Sem produtos vinculados.');
    return sectionTitle('Produtos vendidos no periodo') + items.map(function (item) {
      var code = String(pick(item, 'product_code|codigo_produto|id') || '').trim();
      var description = String(pick(item, 'description|descricao|label') || 'Produto sem descricao');
      var qty = pick(item, 'quantity|quantidade');
      var value = pick(item, 'revenue|value|valor_total');
      var docs = pick(item, 'orders|notes_count|notas_count');
      return '<div class="biomie-drawer__list-item">' +
        '<span><strong>' + esc(description) + '</strong><small style="display:block;color:var(--text-3)">' + esc(code || 'Sem SKU') + '</small></span>' +
        '<span>' + esc(number(qty || 0)) + ' un.</span>' +
        '<span>' + esc(number(docs || 0)) + ' NF</span>' +
        '<span style="font-weight:700">' + esc(money(value)) + '</span>' +
      '</div>';
    }).join('');
  }

  function renderTimeline(data) {
    var rows = data && data.daily_sales ? data.daily_sales : [];
    if (!Array.isArray(rows) || !rows.length) return empty('Sem movimentacao diaria no periodo.');
    return sectionTitle('Linha do tempo fiscal') + rows.filter(function (row) {
      return toNumber(pick(row, 'gross_revenue|revenue|value|invoice_total')) > 0 || toNumber(pick(row, 'orders|notes_count')) > 0;
    }).slice(-40).reverse().map(function (row) {
      var value = pick(row, 'invoice_total|gross_revenue|revenue|value');
      var docs = pick(row, 'orders|notes_count');
      return '<div class="biomie-drawer__list-item">' +
        '<span>' + esc(date(row.date || row.dia || '')) + '</span>' +
        '<span>' + esc(number(docs || 0)) + ' NF</span>' +
        '<span style="font-weight:700">' + esc(money(value)) + '</span>' +
      '</div>';
    }).join('') || empty('Sem eventos com valor no periodo.');
  }

  B.VendorDetail.Tabs = {
    renderResumo: renderResumo,
    renderOrders: renderOrders,
    renderInvoices: renderInvoices,
    renderCustomers: renderCustomers,
    renderProducts: renderProducts,
    renderTimeline: renderTimeline,
    empty: empty,
    actionButton: actionButton,
    customerButton: customerButton,
    pick: pick,
    esc: esc,
    money: money,
    number: number,
    date: date
  };
}());
