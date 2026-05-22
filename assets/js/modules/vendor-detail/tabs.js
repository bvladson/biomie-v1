(function () {
  'use strict';

  var B = window.BIomie || {};
  B.VendorDetail = B.VendorDetail || {};

  // ── Utilitários ─────────────────────────────────────────────────────────────

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
      var v   = row && row[key];
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
      'data-cliente-documento':   doc,
      'data-cliente-nome':        name || '',
      'data-pedido-codigo':       orderCode || ''
    });
  }

  function stat(label, value) {
    return '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">' +
      esc(label) + '</div><div class="biomie-drawer__stat-value">' +
      esc(String(value == null || value === '' ? '-' : value)) + '</div></div>';
  }

  function sectionTitle(label) {
    return '<div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:8px">' + esc(label) + '</div>';
  }

  function empty(message, icon) {
    return '<div class="mag-empty mag-empty--compact">' +
      '<div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">' + esc(icon || 'inbox') + '</span></div>' +
      '<p class="mag-empty-text">' + esc(message || 'Sem dados disponiveis.') + '</p>' +
      '</div>';
  }

  function emptyWithHint(message, hint, icon) {
    return '<div class="mag-empty mag-empty--compact">' +
      '<div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">' + esc(icon || 'info') + '</span></div>' +
      '<strong class="mag-empty-title">' + esc(message) + '</strong>' +
      (hint ? '<p class="mag-empty-text">' + esc(hint) + '</p>' : '') +
      '</div>';
  }

  function bridgeStatusBadge(status) {
    var label = ({
      'completo':       'Completo',
      'nf_sem_titulo':  'Sem titulo',
      'nf_cancelada':   'NF cancelada',
      'pedido_sem_nf':  'Sem NF',
      'cancelado':      'Cancelado'
    })[status] || status || '-';
    var color = status === 'completo' ? 'var(--success)' : status === 'cancelado' || status === 'nf_cancelada' ? 'var(--danger)' : 'var(--warning)';
    return '<span class="biomie-drawer__badge" style="color:' + color + '">' + esc(label) + '</span>';
  }

  // ── Renderizadores de aba ────────────────────────────────────────────────────

  function renderResumo(data) {
    var v = data.vendor  || {};
    var s = data.summary || {};
    var hasTarget = toNumber(v.target_period || 0) > 0;

    var html =
      '<div class="biomie-drawer__section">' +
      '<div style="font-size:0.8rem;color:var(--text-2);margin-bottom:12px;background:var(--bg-muted);padding:8px;border-radius:4px;border-left:3px solid var(--page-accent);">' +
      'Realizado calculado por Receita Fiscal Autorizada, sem notas canceladas.' +
      '</div>' +
      '<div class="biomie-drawer__stats">' +
      stat('Realizado oficial',    money(v.realizado_vendedor || v.realized_period || v.realized)) +
      stat('Meta',                 hasTarget ? money(v.target_period) : 'Sem meta cadastrada') +
      stat('Gap',                  money(v.gap_to_target || v.gap || 0)) +
      stat('Atingimento',          number(v.attainment_pct || v.attainment || 0) + '%') +
      stat('Projecao',             money(v.forecast_period || 0)) +
      stat('Carteira',             money(s.open_total || 0)) +
      stat('NFs autorizadas',      number(s.invoices_count || 0)) +
      stat('Pedidos vinculados',   number(s.linked_orders_count || 0)) +
      stat('Carteira (s/ NF)',     number(s.wallet_orders_count || 0)) +
      stat('Clientes atendidos',   number(s.customers_count || 0)) +
      stat('Produtos distintos',   number(s.products_count || 0)) +
      stat('Ticket medio',         money(s.ticket_avg || 0)) +
      '</div>';

    if (!hasTarget) {
      html += '<div style="margin-top:12px"><a href="plano_voo_metas.php" class="biomie-btn biomie-btn--sm">Editar metas</a></div>';
    }

    html += '</div>' +
      '<div class="biomie-drawer__section">' +
      sectionTitle('Clientes em destaque') +
      renderCustomers((data.customers || []).slice(0, 5), true) +
      '</div>';

    // Bloco diagnóstico recolhível
    var meta = data.meta || {};
    html += '<details style="margin-top:16px;font-size:.75rem;color:var(--text-3)">' +
      '<summary style="cursor:pointer;padding:6px 0">Diagnostico tecnico</summary>' +
      '<div style="padding:8px;background:var(--bg-muted);border-radius:4px;margin-top:4px;line-height:1.8">' +
      'Endpoint: api/vendor_detail.php<br>' +
      'Source: ' + esc(data.source || '-') + '<br>' +
      'Ultima sincronizacao: ' + esc(meta.last_sync || '-') + '<br>' +
      'NFs canonicas: ' + esc(String(meta.invoices_count != null ? meta.invoices_count : (s.invoices_count || 0))) + '<br>' +
      'Pedidos vinculados: ' + esc(String(meta.linked_orders_count || 0)) +
        ' <em style="color:var(--text-3)">(' + esc(meta.linked_source || 'bridge') + ')</em><br>' +
      (meta.linked_source === 'nf_fallback'
        ? '<span style="color:var(--warning)">&#9888; ' + esc(meta.linked_source_hint || 'Bridge nao materializada para este periodo.') + '</span><br>'
        : '') +
      'Carteira (bridge): ' + esc(String(meta.wallet_orders_count || 0)) + '<br>' +
      'Produtos: ' + esc(String(meta.products_count || 0)) + '<br>' +
      'Bridge disponivel: ' + esc(meta.bridge_available ? 'sim' : 'nao') + '<br>' +
      'request_id: ' + esc(meta.request_id || '-') +
      '</div></details>';

    return html;
  }

  /**
   * Aba "Pedidos vinculados" — pedidos que geraram NFs via mart_bridge_pedido_nfe_titulo.
   * @param {Array} rows
   * @param {Object} [summary] - summary do payload para diagnostico contextual
   */
  function renderLinkedOrders(rows, summary) {
    if (!Array.isArray(rows) || !rows.length) {
      var invoicesCount = summary ? Number(summary.invoices_count || 0) : 0;
      var hint = invoicesCount > 0
        ? 'Existem ' + invoicesCount + ' NF(s) autorizada(s) mas nenhum pedido vinculado via bridge. ' +
          'O mart bridge pode nao estar materializado para este periodo. ' +
          'Execute o gold_mart_refresh apos sincronizar os pedidos (biomie_pedidos_canonicos).'
        : 'Nenhuma NF autorizada no periodo — nao ha pedidos vinculados a processar. ' +
          'Se houver NFs, reprocesse mart_bridge_pedido_nfe_titulo.';
      return emptyWithHint('Sem pedidos vinculados as NFs neste periodo.', hint);
    }
    return sectionTitle('Pedidos que geraram NFs (' + rows.length + ')') +
      rows.slice(0, 50).map(function (row) {
        var orderCode    = String(pick(row, 'order_code|codigo_pedido|codigo') || '').trim();
        var clientName   = String(pick(row, 'client_name|nome_cliente|cliente') || 'Cliente nao informado');
        var clientId     = String(pick(row, 'client_id|codigo_cliente_omie') || '');
        var clientDoc    = String(pick(row, 'client_document|documento_cliente') || '');
        var invoiceKey   = String(pick(row, 'invoice_key|nota_key') || '');
        var invoiceValue = pick(row, 'invoice_value|valor_nfe|gross_total|total');
        var refDate      = String(pick(row, 'invoice_date|data_competencia_nfe|billing_date|data_faturamento|issue_date|data_emissao') || '');
        var bridgeSt     = String(pick(row, 'bridge_status') || '');
        var statusFiscal = String(pick(row, 'status_fiscal') || '');

        return '<div class="biomie-drawer__list-item">' +
          (orderCode
            ? actionButton(orderCode, 'open-order', { 'data-pedido-codigo': orderCode })
            : '<span style="color:var(--text-3)">Sem codigo</span>') +
          customerButton(clientName, clientId, clientDoc, orderCode) +
          (invoiceKey
            ? actionButton('NF', 'open-invoice', { 'data-nota-key': invoiceKey })
            : '<span style="color:var(--text-3)">Sem NF</span>') +
          bridgeStatusBadge(bridgeSt || statusFiscal) +
          '<span style="font-size:.75rem;color:var(--text-3)">' + esc(date(refDate)) + '</span>' +
          '<span style="font-weight:700">' + esc(money(invoiceValue)) + '</span>' +
        '</div>';
      }).join('');
  }

  /**
   * Aba "Carteira" — pedidos autorizados sem NF (bridge_status = pedido_sem_nf).
   */
  function renderWalletOrders(rows) {
    if (!Array.isArray(rows) || !rows.length) {
      return emptyWithHint(
        'Carteira vazia: nenhum pedido autorizado sem NF no periodo.',
        'Isso pode indicar que todos os pedidos autorizados ja foram faturados, ou que o mart bridge ainda nao foi materializado.'
      );
    }
    return sectionTitle('Carteira — pedidos autorizados sem NF (' + rows.length + ')') +
      rows.slice(0, 50).map(function (row) {
        var orderCode   = String(pick(row, 'order_code|codigo_pedido|codigo') || '').trim();
        var clientName  = String(pick(row, 'client_name|nome_cliente|cliente') || 'Cliente nao informado');
        var clientId    = String(pick(row, 'client_id|codigo_cliente_omie') || '');
        var clientDoc   = String(pick(row, 'client_document|documento_cliente') || '');
        var orderValue  = pick(row, 'order_value|valor_pedido|gross_total|total');
        var refDate     = String(pick(row, 'issue_date|data_emissao|billing_date') || '');

        return '<div class="biomie-drawer__list-item">' +
          (orderCode
            ? actionButton(orderCode, 'open-order', { 'data-pedido-codigo': orderCode })
            : '<span style="color:var(--text-3)">Sem codigo</span>') +
          customerButton(clientName, clientId, clientDoc, orderCode) +
          '<span class="biomie-drawer__badge" style="color:var(--warning)">Carteira</span>' +
          '<span style="font-size:.75rem;color:var(--text-3)">' + esc(date(refDate)) + '</span>' +
          '<span style="font-weight:700">' + esc(money(orderValue)) + '</span>' +
        '</div>';
      }).join('');
  }

  function renderInvoices(rows) {
    if (!Array.isArray(rows) || !rows.length) {
      return emptyWithHint(
        'Sem notas fiscais autorizadas no periodo.',
        'Verifique se biomie_notas_fiscais_canonicas tem registros com status_fiscal = autorizada para este vendedor/periodo.'
      );
    }
    return sectionTitle('Notas fiscais autorizadas (' + rows.length + ')') +
      rows.slice(0, 50).map(function (row) {
        var nfCode    = String(pick(row, 'codigo|nf_code|numero_nf') || '').trim();
        var orderCode = String(pick(row, 'origin_order_code|codigo_integracao|codigo_pedido_origem') || '').trim();
        var clientName= String(pick(row, 'cliente|client_name|nome_cliente') || 'Cliente nao informado');
        var clientId  = String(pick(row, 'customer_id|client_id|codigo_cliente_omie') || '');
        var doc       = String(pick(row, 'cliente_documento|client_document|customer_document') || '');
        var total     = pick(row, 'gross_total|total|merchandise_total|valor_total');
        var refDate   = String(pick(row, 'competencia_date|data_competencia|status_date|issue_date|data_emissao') || '');
        var notaKey   = String(pick(row, 'nota_key') || '');
        var chaveNfe  = String(pick(row, 'document_key|chave_nfe') || '');
        var pedidoId  = String(pick(row, 'pedido_id_omie') || '');

        return '<div class="biomie-drawer__list-item">' +
          actionButton('NF ' + (nfCode || '-'), 'open-invoice', {
            'data-numero-nf':    nfCode,
            'data-chave-nfe':    chaveNfe,
            'data-nota-key':     notaKey,
            'data-pedido-codigo':orderCode
          }) +
          (orderCode
            ? actionButton(orderCode, 'open-order', {
                'data-pedido-codigo': orderCode,
                'data-pedido-id-omie': pedidoId
              })
            : '<span style="color:var(--text-3)">Sem pedido</span>') +
          customerButton(clientName, clientId, doc, orderCode) +
          '<span style="font-size:.75rem;color:var(--text-3)">' + esc(date(refDate)) + '</span>' +
          '<span style="font-weight:700">' + esc(money(total)) + '</span>' +
        '</div>';
      }).join('');
  }

  function renderCustomers(items, compact) {
    if (!Array.isArray(items) || !items.length) {
      return emptyWithHint(
        'Sem clientes vinculados.',
        'Clientes sao extraidos das NFs autorizadas do vendedor no periodo.'
      );
    }
    var rows = items.map(function (item) {
      var id    = String(pick(item, 'id|client_id|codigo_cliente_omie') || '').trim();
      var name  = String(pick(item, 'label|client_name|cliente') || 'Cliente nao informado');
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
    if (!Array.isArray(items) || !items.length) {
      return emptyWithHint(
        'Sem produtos vinculados.',
        'Produtos sao extraidos de biomie_notas_fiscais_itens_canonicos para NFs autorizadas. Verifique se os itens foram sincronizados.'
      );
    }
    return sectionTitle('Produtos vendidos no periodo (' + items.length + ')') +
      items.map(function (item) {
        var code  = String(pick(item, 'product_code|codigo_produto|id') || '').trim();
        var desc  = String(pick(item, 'description|descricao|label') || 'Produto sem descricao');
        var qty   = pick(item, 'quantity|quantidade');
        var value = pick(item, 'revenue|value|valor_total');
        var docs  = pick(item, 'invoices_count|orders|notes_count|notas_count');
        var clients = pick(item, 'customers_count');
        return '<div class="biomie-drawer__list-item">' +
          '<span><strong>' + esc(desc) + '</strong><small style="display:block;color:var(--text-3)">' + esc(code || 'Sem SKU') + '</small></span>' +
          '<span>' + esc(number(qty || 0)) + ' un.</span>' +
          '<span>' + esc(number(docs || 0)) + ' NF</span>' +
          (clients ? '<span>' + esc(number(clients)) + ' cli.</span>' : '') +
          '<span style="font-weight:700">' + esc(money(value)) + '</span>' +
        '</div>';
      }).join('');
  }

  function renderTimeline(data) {
    var rows = data && data.daily_sales ? data.daily_sales : [];
    if (!Array.isArray(rows) || !rows.length) {
      return emptyWithHint('Sem movimentacao diaria no periodo.', null);
    }
    return sectionTitle('Linha do tempo fiscal') +
      rows.filter(function (row) {
        return toNumber(pick(row, 'gross_revenue|invoice_total|revenue|value')) > 0 || toNumber(pick(row, 'orders|notes_count')) > 0;
      }).slice(-40).reverse().map(function (row) {
        var value = pick(row, 'invoice_total|gross_revenue|revenue|value');
        var docs  = pick(row, 'orders|notes_count');
        return '<div class="biomie-drawer__list-item">' +
          '<span>' + esc(date(row.date || row.dia || '')) + '</span>' +
          '<span>' + esc(number(docs || 0)) + ' NF</span>' +
          '<span style="font-weight:700">' + esc(money(value)) + '</span>' +
        '</div>';
      }).join('') || emptyWithHint('Sem eventos com valor no periodo.', null);
  }

  // Compat: renderOrders aponta para renderWalletOrders (carteira = pedidos sem NF).
  // Nao usar orders_count — use wallet_orders_count (carteira) ou linked_orders_count (bridge).
  function renderOrders(rows) {
    return renderWalletOrders(rows);
  }

  B.VendorDetail.Tabs = {
    renderResumo:        renderResumo,
    renderLinkedOrders:  renderLinkedOrders,
    renderWalletOrders:  renderWalletOrders,
    renderOrders:        renderOrders,
    renderInvoices:      renderInvoices,
    renderCustomers:     renderCustomers,
    renderProducts:      renderProducts,
    renderTimeline:      renderTimeline,
    empty:               empty,
    emptyWithHint:       emptyWithHint,
    stat:                stat,
    actionButton:        actionButton,
    customerButton:      customerButton,
    pick:                pick,
    esc:                 esc,
    money:               money,
    toNumber:            toNumber,
    number:              number,
    date:                date
  };
}());
