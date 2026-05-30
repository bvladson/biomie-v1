(function () {
  'use strict';

  var B = window.BIomie || {};
  B.ProductDetail = B.ProductDetail || {};

  var T = B.ProductDetail.Tabs = {};

  T.esc = function (v) { return String(v == null ? '' : v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };

  T.fmt = function (v) { return B.formatCurrency ? B.formatCurrency(v) : Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
  T.fmtN = function (v) { return B.formatNumber ? B.formatNumber(v) : Number(v || 0).toLocaleString('pt-BR'); };
  T.fmtD = function (v) { return v ? String(v).slice(0, 10) : '-'; };

  T.empty = function (msg, icon) {
    return '<div class="mag-empty mag-empty--compact">' +
      '<div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">' + T.esc(icon || 'inventory_2') + '</span></div>' +
      '<p class="mag-empty-text">' + T.esc(msg || 'Sem dados disponiveis.') + '</p>' +
      '</div>';
  };

  T.renderSourceBadge = function (source) {
    var B = window.BIomie;
    if (B && B.sourceBadge) return B.sourceBadge(source);
    var labels = {
      canonical: 'Canonico', gold: 'Gold', empty: 'Sem dados',
      'mart_produto_periodo': 'Gold',
      'biomie_notas_fiscais_canonicas': 'Canonical',
      fallback: 'Fallback'
    };
    var norm = String(source || '').toLowerCase();
    var key = norm.includes('gold') || norm.includes('mart') ? 'gold'
            : norm.includes('canonical') || norm.includes('canonicas') ? 'canonical'
            : norm.includes('fallback') ? 'fallback'
            : (norm in labels ? norm : 'canonical');
    var label = labels[key] || T.esc(String(source || ''));
    var cls = 'mag-badge mag-badge--source-' + key;
    return label ? '<span class="' + cls + '">' + T.esc(label) + '</span>' : '';
  };

  // ── Resumo ────────────────────────────────────────────────────────────────

  T.renderResumo = function (data) {
    var s  = (data && data.summary)     || {};
    var dq = (data && data.data_quality) || {};
    var es = (data && data.empty_states && data.empty_states.sales) || {};

    if (es.is_empty) {
      return '<div class="mag-empty mag-empty--compact">' +
        '<div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">inventory_2</span></div>' +
        '<strong class="mag-empty-title">' + T.esc(es.title || 'Produto sem vendas no periodo') + '</strong>' +
        (es.message ? '<p class="mag-empty-text">' + T.esc(es.message) + '</p>' : '') +
        (es.action ? '<p class="mag-empty-text"><strong>Acao:</strong> ' + T.esc(es.action) + '</p>' : '') +
        '</div>';
    }

    var rows = [
      ['Receita realizada',    T.fmt(s.realized_total)],
      ['Mercadorias',          T.fmt(s.merchandise_total)],
      ['Descontos',            T.fmt(s.discount_total)],
      ['Quantidade vendida',   T.fmtN(s.quantity_total) + ' un'],
      ['NFs autorizadas',      T.fmtN(s.invoices_count)],
      ['Clientes compradores', T.fmtN(s.customers_count)],
      ['Vendedores',           T.fmtN(s.vendors_count)],
      ['Pedidos vinculados',   T.fmtN(s.linked_orders_count)],
      ['Ticket medio/NF',      T.fmt(s.ticket_avg_per_invoice)],
      ['Preco medio unitario', T.fmt(s.avg_unit_price)],
      ['Primeira venda',       T.fmtD(s.first_sale_at)],
      ['Ultima venda',         T.fmtD(s.last_sale_at)],
    ];

    var kpiGrid = '<div class="pd-kpi-grid">' +
      rows.map(function (r) {
        return '<div class="pd-kpi-item"><span class="pd-kpi-label">' + T.esc(r[0]) + '</span>' +
               '<strong class="pd-kpi-value">' + T.esc(String(r[1])) + '</strong></div>';
      }).join('') +
      '</div>';

    var freshBadge = T.renderFreshnessBadge(dq.freshness_status);
    var sourceBadge = T.renderSourceBadge(s.source);
    var badges = [sourceBadge, freshBadge].filter(Boolean).join(' ');

    var warnings = Array.isArray(dq.warnings) && dq.warnings.length
      ? '<div class="pd-warnings">' + dq.warnings.map(function (w) {
          return '<span class="mag-status-chip mag-status-warning">' + T.esc(w) + '</span>';
        }).join('') + '</div>'
      : '';

    return '<div class="pd-resumo">' + (badges ? '<div class="pd-badges">' + badges + '</div>' : '') + kpiGrid + warnings + '</div>';
  };

  T.renderFreshnessBadge = function (status) {
    if (!status || status === 'ok') return '';
    var map = { warning: 'mag-status-warning', critical: 'mag-status-error', unknown: 'mag-status-demo' };
    var cls = map[status] || 'mag-status-demo';
    var labels = { warning: 'Sync antigo', critical: 'Sync critico', unknown: 'Sync desconhecido' };
    return '<span class="mag-status-chip ' + cls + '">' + T.esc(labels[status] || status) + '</span>';
  };

  // ── NFs ───────────────────────────────────────────────────────────────────

  T.renderInvoices = function (rows) {
    if (!rows || !rows.length) {
      return T.empty('Produto sem NFs autorizadas no periodo selecionado.');
    }
    var thead = '<thead><tr><th>NF</th><th>Competencia</th><th>Cliente</th><th>Vendedor</th><th>Qtd</th><th>Total item</th></tr></thead>';
    var tbody = '<tbody>' + rows.map(function (row) {
      var notaKey  = T.esc(row.nota_key || '');
      var chaveNfe = T.esc(row.chave_nfe || '');
      var nfLabel  = T.esc(row.numero_nf || row.nota_key || '-');
      var nfBtn    = row.numero_nf || row.nota_key
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-invoice"' +
          (notaKey ? ' data-nota-key="' + notaKey + '"' : '') +
          ' data-numero-nf="' + T.esc(row.numero_nf || '') + '"' +
          (chaveNfe ? ' data-chave-nfe="' + chaveNfe + '"' : '') + '>' +
          nfLabel + '</button>'
        : '-';
      var custId = T.esc(row.customer_id || '');
      var custName = T.esc(row.customer_name || 'Cliente nao informado');
      var custBtn = custId
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-customer" data-codigo-cliente-omie="' + custId + '" data-cliente-nome="' + custName + '">' + custName + '</button>'
        : custName;
      var vendId = T.esc(row.vendor_id || '');
      var vendName = T.esc(row.vendor_name || 'Sem vendedor');
      var vendBtn = vendId
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-vendor" data-vendor-id="' + vendId + '" data-vendor-name="' + vendName + '">' + vendName + '</button>'
        : vendName;
      return '<tr><td>' + nfBtn + '</td><td>' + T.esc(T.fmtD(row.data_competencia)) + '</td><td>' + custBtn + '</td><td>' + vendBtn + '</td><td>' + T.esc(T.fmtN(row.quantity)) + '</td><td>' + T.esc(String(row.product_total || '-')) + '</td></tr>';
    }).join('') + '</tbody>';
    return '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">' + thead + tbody + '</table></div>';
  };

  // ── Clientes ──────────────────────────────────────────────────────────────

  T.renderCustomers = function (rows) {
    if (!rows || !rows.length) {
      return T.empty('Nenhum cliente comprou este produto no periodo.');
    }
    var thead = '<thead><tr><th>Cliente</th><th>Receita</th><th>Qtd</th><th>NFs</th><th>Ticket medio</th><th>Ultima compra</th></tr></thead>';
    var tbody = '<tbody>' + rows.map(function (row) {
      var custId = T.esc(row.customer_id || '');
      var custName = T.esc(row.customer_name || 'Cliente nao informado');
      var custDoc  = T.esc(row.customer_document || '');
      var custBtn  = custId
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-customer" data-codigo-cliente-omie="' + custId + '" data-cliente-documento="' + custDoc + '" data-cliente-nome="' + custName + '">' + custName + '</button>'
        : custName;
      return '<tr><td>' + custBtn + '</td><td>' + T.esc(String(row.revenue || 0)) + '</td><td>' + T.esc(T.fmtN(row.quantity)) + '</td><td>' + T.esc(T.fmtN(row.invoices_count)) + '</td><td>' + T.esc(String(row.ticket_avg || '-')) + '</td><td>' + T.esc(T.fmtD(row.last_purchase_at)) + '</td></tr>';
    }).join('') + '</tbody>';
    return '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">' + thead + tbody + '</table></div>';
  };

  // ── Vendedores ────────────────────────────────────────────────────────────

  T.renderVendors = function (rows) {
    if (!rows || !rows.length) {
      return T.empty('Nenhum vendedor associado a este produto no periodo.');
    }
    var thead = '<thead><tr><th>Vendedor</th><th>Receita</th><th>Particip.</th><th>Qtd</th><th>NFs</th><th>Clientes</th></tr></thead>';
    var tbody = '<tbody>' + rows.map(function (row) {
      var vendId = T.esc(row.vendor_id || '');
      var vendName = T.esc(row.vendor_name || 'Sem vendedor');
      var vendBtn  = vendId
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-vendor" data-vendor-id="' + vendId + '" data-vendor-name="' + vendName + '">' + vendName + '</button>'
        : vendName;
      var pct = Number(row.participation_pct || 0).toFixed(1) + '%';
      return '<tr><td>' + vendBtn + '</td><td>' + T.esc(String(row.revenue || 0)) + '</td><td>' + T.esc(pct) + '</td><td>' + T.esc(T.fmtN(row.quantity)) + '</td><td>' + T.esc(T.fmtN(row.invoices_count)) + '</td><td>' + T.esc(T.fmtN(row.customers_count)) + '</td></tr>';
    }).join('') + '</tbody>';
    return '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">' + thead + tbody + '</table></div>';
  };

  // ── Pedidos vinculados ────────────────────────────────────────────────────

  T.renderLinkedOrders = function (rows, summary) {
    var invoicesCount = (summary && summary.invoices_count) || 0;
    if (!rows || !rows.length) {
      var msg = invoicesCount > 0
        ? 'Ha NFs autorizadas para este produto, mas a bridge pedido → NF nao encontrou vinculos. Reprocesse mart_bridge_pedido_nfe_titulo.'
        : 'Nao foram encontrados pedidos vinculados as NFs deste produto no periodo.';
      return T.empty(msg);
    }
    var thead = '<thead><tr><th>Pedido</th><th>NF</th><th>Cliente</th><th>Vendedor</th><th>Competencia NF</th><th>Valor NF</th></tr></thead>';
    var tbody = '<tbody>' + rows.map(function (row) {
      var orderCode = T.esc(row.order_code || row.pedido_id_omie || '');
      var orderBtn  = orderCode
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-order" data-pedido-codigo="' + orderCode + '">' + orderCode + '</button>'
        : '-';
      var notaKey = T.esc(row.nota_key || '');
      var nfBtn   = notaKey
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-invoice" data-nota-key="' + notaKey + '">' + notaKey.slice(0, 12) + '…</button>'
        : '-';
      var custId = T.esc(row.customer_id || '');
      var custName = T.esc(row.customer_name || '-');
      var custBtn = custId
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-customer" data-codigo-cliente-omie="' + custId + '" data-cliente-nome="' + custName + '">' + custName + '</button>'
        : custName;
      var vendId = T.esc(row.vendor_id || '');
      var vendName = T.esc(row.vendor_name || '-');
      var vendBtn = vendId
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-vendor" data-vendor-id="' + vendId + '" data-vendor-name="' + vendName + '">' + vendName + '</button>'
        : vendName;
      return '<tr><td>' + orderBtn + '</td><td>' + nfBtn + '</td><td>' + custBtn + '</td><td>' + vendBtn + '</td><td>' + T.esc(T.fmtD(row.data_competencia_nfe)) + '</td><td>' + T.esc(T.fmt(row.valor_nfe)) + '</td></tr>';
    }).join('') + '</tbody>';
    return '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">' + thead + tbody + '</table></div>';
  };

  // ── LTV ───────────────────────────────────────────────────────────────────

  T.renderLtv = function (data) {
    var ltv = (data && data.ltv) || {};
    var es  = (data && data.empty_states && data.empty_states.ltv) || {};

    if (!ltv || !ltv.lifetime_revenue_raw) {
      return T.empty('Historico de LTV nao disponivel para este produto.');
    }

    var note = T.esc(ltv.note || 'LTV calculado com base no historico local sincronizado.');
    var partialBadge = es.reason_code === 'ltv_partial'
      ? '<span class="mag-status-chip mag-status-warning">Historico parcial</span>'
      : '';

    var rows = [
      ['Receita historica observada', String(ltv.lifetime_revenue || '-')],
      ['Quantidade historica',        T.fmtN(ltv.lifetime_quantity) + ' un'],
      ['NFs historicas',              T.fmtN(ltv.lifetime_invoices_count)],
      ['Clientes historicos',         T.fmtN(ltv.lifetime_customers_count)],
      ['Clientes recorrentes',        T.fmtN(ltv.repeat_customers_count)],
      ['Taxa de recorrencia',         Number(ltv.repeat_customer_rate || 0).toFixed(1) + '%'],
      ['Receita media/cliente',       String(ltv.avg_revenue_per_customer || '-')],
      ['Receita no periodo atual',    String(ltv.current_period_revenue || '-')],
      ['Participacao do periodo',     Number(ltv.current_period_share_pct || 0).toFixed(1) + '%'],
      ['Primeira venda historica',    T.fmtD(ltv.lifetime_first_sale_at)],
      ['Ultima venda historica',      T.fmtD(ltv.lifetime_last_sale_at)],
    ];

    return '<div class="pd-ltv">' +
      (partialBadge ? '<div class="pd-badges">' + partialBadge + '</div>' : '') +
      '<div class="pd-kpi-grid">' +
      rows.map(function (r) {
        return '<div class="pd-kpi-item"><span class="pd-kpi-label">' + T.esc(r[0]) + '</span><strong class="pd-kpi-value">' + T.esc(r[1]) + '</strong></div>';
      }).join('') +
      '</div>' +
      '<p class="pd-ltv-note">' + note + '</p>' +
      '</div>';
  };

  // ── Giro & Risco (Estoque operacional) ───────────────────────────────────

  T.renderEstoque = function (data) {
    var ledger  = (data && data.document_ledger) || {};
    var vendors = (data && data.top_vendors)     || [];
    var source  = data && (data.source_note || '');
    var conf    = data && (data.source_confidence || '');

    if (ledger.empty_state) {
      return T.empty(ledger.empty_state, 'inventory');
    }

    var com  = ledger.commercial || {};
    var vel  = ledger.velocity   || {};
    var risk = ledger.rupture_risk || {};
    var topC = ledger.top_clients  || [];
    var monthly = ledger.monthly_breakdown || [];

    // Source note
    var sourceHtml = source
      ? '<div class="pd-source-note"><span class="material-symbols-rounded" style="font-size:15px;vertical-align:middle;margin-right:4px;">info</span>'
        + T.esc(source) + '</div>'
      : '';

    // KPIs
    var riskColor = risk.level === 'high' ? '#dc2626' : risk.level === 'medium' ? '#d97706' : '#16a34a';
    var riskLabel = risk.level === 'high' ? 'Alto' : risk.level === 'medium' ? 'Médio' : 'Baixo';
    var kpis = [
      ['Qtd. vendida', T.fmtN(com.qty_total) + ' un', 'Período selecionado'],
      ['Giro 30 dias',  T.fmtN(vel.giro_30d_qty) + ' un', vel.giro_30d_nfs + ' NFs'],
      ['Giro 90 dias',  T.fmtN(vel.giro_90d_qty) + ' un', vel.giro_90d_nfs + ' NFs'],
      ['Meses ativos',  com.active_months + ' meses', 'Com venda no período'],
      ['Clientes',      T.fmtN(com.customers_count || com.customers), 'Compradores distintos'],
      ['Receita total', T.fmt(com.value_total), 'NF comercial'],
      ['Risco operacional', '<span style="color:' + riskColor + ';font-weight:700">' + riskLabel + '</span>', T.esc(risk.note || '')],
    ];
    var kpiHtml = '<div class="pd-kpi-grid">'
      + kpis.map(function (k) {
          return '<div class="pd-kpi-item"><span class="pd-kpi-label">' + T.esc(k[0]) + '</span>'
            + '<strong class="pd-kpi-value">' + k[1] + '</strong>'
            + (k[2] ? '<span class="pd-kpi-sub">' + T.esc(k[2]) + '</span>' : '')
            + '</div>';
        }).join('')
      + '</div>';

    // Monthly breakdown table
    var monthlyHtml = '';
    if (monthly.length > 0) {
      monthlyHtml = '<h4 class="pd-section-title">Giro por mês</h4>'
        + '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">'
        + '<thead><tr><th>Mês</th><th>Qtd</th><th>NFs</th><th>Receita</th></tr></thead>'
        + '<tbody>' + monthly.map(function (m) {
            return '<tr><td>' + T.esc(m.mes || '') + '</td>'
              + '<td class="font-mono">' + T.fmtN(m.qty) + '</td>'
              + '<td>' + T.fmtN(m.nfs) + '</td>'
              + '<td class="font-mono">' + T.fmt(m.value) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
    }

    // Top clients
    var clientsHtml = '';
    if (topC.length > 0) {
      clientsHtml = '<h4 class="pd-section-title">Top clientes compradores</h4>'
        + '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">'
        + '<thead><tr><th>Cliente</th><th>Qtd</th><th>NFs</th><th>Receita</th></tr></thead>'
        + '<tbody>' + topC.map(function (c) {
            return '<tr><td class="truncate">' + T.esc(c.nome_cliente || c.customer_name || '') + '</td>'
              + '<td class="font-mono">' + T.fmtN(c.qty) + '</td>'
              + '<td>' + T.fmtN(c.nfs) + '</td>'
              + '<td class="font-mono">' + T.fmt(c.value) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
    }

    // Top vendors
    var vendorsHtml = '';
    if (vendors.length > 0) {
      vendorsHtml = '<h4 class="pd-section-title">Vendedores que mais venderam</h4>'
        + '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">'
        + '<thead><tr><th>Vendedor</th><th>Qtd</th><th>Particip.</th><th>NFs</th><th>Clientes</th></tr></thead>'
        + '<tbody>' + vendors.map(function (v) {
            return '<tr><td class="truncate">' + T.esc(v.vendor_name || '') + '</td>'
              + '<td class="font-mono">' + T.fmtN(v.qty) + '</td>'
              + '<td>' + Number(v.participation_pct || 0).toFixed(1) + '%</td>'
              + '<td>' + T.fmtN(v.nfs) + '</td>'
              + '<td>' + T.fmtN(v.customers) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
    }

    return '<div class="pd-estoque">'
      + sourceHtml
      + kpiHtml
      + monthlyHtml
      + clientsHtml
      + vendorsHtml
      + '</div>';
  };

  // ── Diagnóstico ──────────────────────────────────────────────────────────

  T.renderDiagnostico = function (data) {
    var dq  = (data && data.data_quality) || {};
    var meta = (data && data.meta) || {};
    var pb  = (data && data.period_basis) || {};

    var rows = [
      ['product_code',             meta.product_code || '-'],
      ['realized_period_basis',    pb.realized || '-'],
      ['canonical_items_count',    dq.canonical_items_count != null ? dq.canonical_items_count : '-'],
      ['authorized_invoices_count', dq.authorized_invoices_count != null ? dq.authorized_invoices_count : '-'],
      ['linked_orders_count',      dq.linked_orders_count != null ? dq.linked_orders_count : '-'],
      ['mart_rows_count',          dq.mart_rows_count != null ? dq.mart_rows_count : '-'],
      ['source',                   dq.source || '-'],
      ['freshness_status',         dq.freshness_status || '-'],
      ['last_canonical_nf_at',     dq.last_canonical_nf_at || '-'],
      ['last_gold_materialized_at', dq.last_gold_materialized_at || '-'],
      ['request_id',               meta.request_id || '-'],
      ['generated_at',             meta.generated_at || '-'],
    ];

    var warnings = Array.isArray(dq.warnings) && dq.warnings.length
      ? '<div class="pd-warnings">' + dq.warnings.map(function (w) {
          return '<span class="mag-status-chip mag-status-warning">' + T.esc(w) + '</span>';
        }).join('') + '</div>'
      : '';

    return '<div class="pd-diag">' + warnings +
      '<dl class="pd-diag-grid">' +
      rows.map(function (r) {
        return '<dt>' + T.esc(r[0]) + '</dt><dd>' + T.esc(String(r[1])) + '</dd>';
      }).join('') +
      '</dl></div>';
  };

  window.BIomie = B;
}());
