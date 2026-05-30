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
    var s        = (data && data.summary)      || {};
    var dq       = (data && data.data_quality) || {};
    var es       = (data && data.empty_states && data.empty_states.sales) || {};
    var identity = (data && data.identity)     || {};

    // Identity badge (shows source/confidence + any warnings)
    var identityBadge = '';
    if (identity.display_name) {
      var confColor = identity.confidence === 'high' ? 'var(--success,#16a34a)' : identity.confidence === 'medium' ? 'var(--warning,#d97706)' : 'var(--text-3,#6b7280)';
      identityBadge = '<div class="pd-identity-note" style="font-size:0.78rem;color:var(--text-3);margin-bottom:10px;display:flex;align-items:center;gap:6px;">'
        + '<span class="material-symbols-rounded" style="font-size:14px;color:' + confColor + '">verified</span>'
        + '<span>' + T.esc(identity.display_name) + '</span>'
        + '<span style="color:var(--text-4,#9ca3af)">· ' + T.esc(identity.matched_by || '') + ' · ' + T.esc(identity.confidence || '') + '</span>'
        + '</div>';
    }

    // If truly empty (no sales in period AND identity says no data), show empty state
    if (es.is_empty && !identity.display_name) {
      return identityBadge + '<div class="mag-empty mag-empty--compact">' +
        '<div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">inventory_2</span></div>' +
        '<strong class="mag-empty-title">' + T.esc(es.title || 'Produto sem vendas no periodo') + '</strong>' +
        (es.message ? '<p class="mag-empty-text">' + T.esc(es.message) + '</p>' : '') +
        (es.action ? '<p class="mag-empty-text"><strong>Acao:</strong> ' + T.esc(es.action) + '</p>' : '') +
        '</div>';
    }

    // If empty but we have identity, show a softer message with the period note
    if (es.is_empty) {
      var periodNote = '<p class="mag-empty-text">' + T.esc(es.message || 'Sem NFs no recorte selecionado.') + '</p>';
      return identityBadge + '<div class="mag-empty mag-empty--compact">' +
        '<div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">event_busy</span></div>' +
        '<strong class="mag-empty-title">Sem NFs neste recorte</strong>' +
        periodNote + '</div>';
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

    var freshBadge  = T.renderFreshnessBadge(dq.freshness_status);
    var sourceBadge = T.renderSourceBadge(s.source);
    var badges      = [sourceBadge, freshBadge].filter(Boolean).join(' ');

    var warnings = Array.isArray(dq.warnings) && dq.warnings.length
      ? '<div class="pd-warnings">' + dq.warnings.filter(function (w) {
            // Filter out identity-level warnings from the visual warnings block
            return w.indexOf('product_') !== 0 && w !== 'product_code_has_whitespace';
          }).map(function (w) {
            return '<span class="mag-status-chip mag-status-warning">' + T.esc(w) + '</span>';
          }).join('') + '</div>'
      : '';

    return '<div class="pd-resumo">' +
      identityBadge +
      (badges ? '<div class="pd-badges">' + badges + '</div>' : '') +
      kpiGrid + warnings +
      '</div>';
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
      return T.empty('Sem NFs neste recorte para vendedores.', 'person_off');
    }
    var thead = '<thead><tr><th>Vendedor</th><th>Receita</th><th>% Receita</th><th>Qtd</th><th>% Qtd</th><th>NFs</th><th>Clientes</th></tr></thead>';
    var tbody = '<tbody>' + rows.map(function (row) {
      var vendId   = T.esc(row.vendor_id   || '');
      var vendName = T.esc(row.vendor_name || 'Sem vendedor');
      var vendBtn  = vendId
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-vendor" data-vendor-id="' + vendId + '" data-vendor-name="' + vendName + '">' + vendName + '</button>'
        : vendName;
      var revPct = Number(row.participation_pct     || 0).toFixed(1) + '%';
      var qtyPct = Number(row.participation_qty_pct || 0).toFixed(1) + '%';
      return '<tr><td>' + vendBtn + '</td>' +
        '<td class="font-mono">' + T.esc(String(row.revenue || '0')) + '</td>' +
        '<td>' + T.esc(revPct) + '</td>' +
        '<td class="font-mono">' + T.esc(T.fmtN(row.quantity)) + '</td>' +
        '<td>' + T.esc(qtyPct) + '</td>' +
        '<td>' + T.esc(T.fmtN(row.invoices_count)) + '</td>' +
        '<td>' + T.esc(T.fmtN(row.customers_count)) + '</td></tr>';
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
      return T.empty('Sem NFs neste recorte para LTV.', 'history');
    }

    var note = T.esc(ltv.note || 'LTV calculado com base no historico local sincronizado.');
    var partialBadge = es.reason_code === 'ltv_partial'
      ? '<span class="mag-status-chip mag-status-warning">Historico parcial</span>'
      : '';

    // Recurrence indicator
    var recRate = Number(ltv.repeat_customer_rate || 0).toFixed(1);
    var recColor = ltv.repeat_customer_rate >= 50 ? '#16a34a' : ltv.repeat_customer_rate >= 20 ? '#d97706' : '#6b7280';

    var kpis = [
      ['Receita histórica',      T.esc(String(ltv.lifetime_revenue || '-')), 'Todo o histórico local'],
      ['Qtd. histórica',         T.fmtN(ltv.lifetime_quantity) + ' un', ''],
      ['NFs históricas',         T.fmtN(ltv.lifetime_invoices_count), ''],
      ['Clientes históricos',    T.fmtN(ltv.lifetime_customers_count), ''],
      ['Clientes recorrentes',   T.fmtN(ltv.repeat_customers_count), '2+ compras'],
      ['Taxa de recorrência',    '<span style="color:' + recColor + ';font-weight:700">' + T.esc(recRate) + '%</span>', ''],
      ['Receita média/cliente',  T.esc(String(ltv.avg_revenue_per_customer || '-')), ''],
      ['Receita no período',     T.esc(String(ltv.current_period_revenue || '-')), ''],
      ['Participação período',   Number(ltv.current_period_share_pct || 0).toFixed(1) + '%', 'Do LTV total'],
      ['Primeira venda',         T.fmtD(ltv.lifetime_first_sale_at), ''],
      ['Última venda',           T.fmtD(ltv.lifetime_last_sale_at), ''],
    ];

    return '<div class="pd-ltv">' +
      (partialBadge ? '<div class="pd-badges" style="margin-bottom:8px;">' + partialBadge + '</div>' : '') +
      '<div class="pd-kpi-grid">' +
      kpis.map(function (k) {
        return '<div class="pd-kpi-item">'
          + '<span class="pd-kpi-label">' + T.esc(k[0]) + '</span>'
          + '<strong class="pd-kpi-value">' + k[1] + '</strong>'
          + (k[2] ? '<span class="pd-kpi-sub">' + T.esc(k[2]) + '</span>' : '')
          + '</div>';
      }).join('') +
      '</div>' +
      '<p class="pd-ltv-note" style="font-size:0.75rem;color:var(--text-3);margin-top:10px;">' + note + '</p>' +
      '</div>';
  };

  // ── Ação Comercial (PROD.R2) ─────────────────────────────────────────────

  T.renderAcaoComercial = function (data) {
    var prod       = (data && data.product)    || {};
    var repurchase = (data && data.repurchase) || [];

    var CAT_COLORS = {
      'Produto campeão':              '#16a34a',
      'Reativar produto':             '#dc2626',
      'Acompanhar concentração':      '#d97706',
      'Campanha para clientes antigos': '#2563eb',
      'Cross-sell com vendedores fortes': '#7c3aed',
      'Produto sazonal':              '#db2777',
      'Baixo giro':                   '#6b7280',
      'Monitorar':                    '#9ca3af',
    };

    var cat      = prod.categoria      || '';
    var score    = prod.score_acao     || 0;
    var catColor = CAT_COLORS[cat] || '#9ca3af';
    var scColor  = score >= 75 ? '#16a34a' : score >= 40 ? '#2563eb' : '#9ca3af';
    var mgr      = prod.manager_explanation || {};
    var breakdown= prod.score_breakdown || {};
    var dataBasis= Array.isArray(prod.data_basis) ? prod.data_basis : [];
    var motivos  = Array.isArray(breakdown.motivos) ? breakdown.motivos : [];
    var friendly = prod.friendly_name || cat;

    if (!prod.product_code && !prod.receita_periodo) {
      return T.empty('Sem dados de ação comercial para este produto no período selecionado. Tente ampliar o recorte de datas.', 'lightbulb');
    }

    // ── A. Decisão rápida ──────────────────────────────────────────────────
    var decisionHtml = '<div class="action-manager-card" style="padding:16px;border-radius:8px;background:var(--surface-muted,#f3f4f6);margin-bottom:14px;">'
      // Score + badge
      + '<div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:12px;">'
      + '<div style="flex-shrink:0;text-align:center;">'
      + '<div style="font-size:2.2rem;font-weight:800;color:' + scColor + ';line-height:1">' + score + '</div>'
      + '<div style="font-size:0.65rem;color:var(--text-3);text-transform:uppercase;">/100</div>'
      + '</div>'
      + '<div style="flex:1;">'
      + '<div style="margin-bottom:6px;">'
      + '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:0.76rem;font-weight:700;color:#fff;background:' + catColor + '">' + T.esc(friendly) + '</span>'
      + (mgr.confidence_label ? '<span style="margin-left:6px;font-size:0.72rem;color:var(--text-3)">Confiança: ' + T.esc(mgr.confidence_label) + '</span>' : '')
      + '</div>'
      + '<div class="action-meaning" style="font-size:0.88rem;font-weight:600;color:var(--text-1,#111);margin-bottom:4px;">' + T.esc(mgr.headline || cat) + '</div>'
      + '<div style="font-size:0.7rem;color:var(--text-3);text-transform:uppercase;letter-spacing:.04em;margin-top:6px;margin-bottom:2px;">O que significa</div>'
      + '<div style="font-size:0.82rem;color:var(--text-2,#374151);">' + T.esc(mgr.meaning || '') + '</div>'
      + '</div></div>'
      // Decisão + próximo passo
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">'
      + _acaoBlock('rocket_launch', 'Decisão', mgr.manager_decision || '', '#1e40af', '#eff6ff', '#bfdbfe')
      + _acaoBlock('bolt', 'Ação recomendada', mgr.recommended_action || '', '#065f46', '#f0fdf4', '#bbf7d0')
      + '</div>'
      + '<div class="action-recommended-action" style="padding:10px 12px;border-radius:6px;background:#f8fafc;border:1px solid #e2e8f0;font-size:0.82rem;color:var(--text-2);">'
      + '<span style="font-size:0.72rem;color:var(--text-3);display:block;margin-bottom:3px;">Próximo passo</span>'
      + '<span class="action-next-step">' + T.esc(mgr.next_step || '') + '</span>'
      + '</div>'
      // Risk note
      + (mgr.risk_note
          ? '<div class="action-risk-note" style="margin-top:10px;padding:8px 12px;border-radius:6px;background:#fff7ed;border:1px solid #fed7aa;font-size:0.8rem;color:#9a3412;">'
            + '<span class="material-symbols-rounded" style="font-size:13px;vertical-align:middle;margin-right:4px;">warning</span>'
            + '<strong>Atenção do gestor: </strong>' + T.esc(mgr.risk_note)
            + '</div>'
          : '')
      + '</div>';

    // ── B. KPI grid legível ────────────────────────────────────────────────
    var lastSaleLabel = prod.dias_sem_venda === 0 ? 'Hoje'
      : prod.dias_sem_venda === 1 ? 'Ontem'
      : 'há ' + (prod.dias_sem_venda || 0) + ' dias';
    var kpis = [
      { l: 'Receita no período',    v: T.fmt(prod.receita_periodo),              s: 'Total vendido deste produto' },
      { l: 'Quantidade vendida',    v: T.fmtN(prod.qtd_periodo) + ' un',         s: 'Volume comercial' },
      { l: 'Clientes compradores',  v: T.fmtN(prod.clientes_periodo),             s: 'Compradores distintos' },
      { l: 'Meses ativos',          v: (prod.meses_ativos || 0) + ' meses',       s: 'Com NF no período' },
      { l: 'Última venda',          v: lastSaleLabel,                             s: prod.ultima_venda || '' },
      { l: 'Concentração',          v: (prod.top_cliente_share_pct || 0) + '%',   s: prod.top_cliente_nome ? 'Top: ' + prod.top_cliente_nome.substring(0,20) : 'Maior cliente' },
    ];
    var kpiHtml = '<div class="action-kpi-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:14px;">'
      + kpis.map(function(k) {
          return '<div style="background:var(--surface-muted,#f3f4f6);border-radius:6px;padding:8px 10px;">'
            + '<div style="font-size:0.7rem;color:var(--text-3);margin-bottom:2px;">' + T.esc(k.l) + '</div>'
            + '<div style="font-size:1rem;font-weight:700;">' + k.v + '</div>'
            + '<div style="font-size:0.68rem;color:var(--text-3);">' + T.esc(k.s) + '</div>'
            + '</div>';
        }).join('')
      + '</div>';

    // ── C. Por que apareceu (score motivos) ───────────────────────────────
    var motivosHtml = '';
    if (motivos.length > 0) {
      motivosHtml = '<details style="margin-bottom:12px;" open>'
        + '<summary style="cursor:pointer;font-size:0.82rem;font-weight:600;color:var(--text-2);padding:6px 0;list-style:none;display:flex;align-items:center;gap:6px;">'
        + '<span class="material-symbols-rounded" style="font-size:15px;">info</span>'
        + '<span class="action-score-reasons">Por que apareceu nesta categoria</span></summary>'
        + '<div style="padding:8px 0 0 20px;">'
        + motivos.map(function(m) {
            var mColor = m.startsWith('+') ? '#16a34a' : m.startsWith('−') ? '#dc2626' : '#6b7280';
            return '<div style="font-size:0.8rem;color:' + mColor + ';margin-bottom:4px;">' + T.esc(m) + '</div>';
          }).join('')
        + '<div style="margin-top:8px;font-size:0.72rem;color:var(--text-3);">'
        + 'Score = recorrência (' + (breakdown.recorrencia||0) + ') + recência (' + (breakdown.recencia||0) + ') + diversidade (' + (breakdown.diversidade||0) + ') + volume (' + (breakdown.volume||0) + ')'
        + ((breakdown.penalidade||0) < 0 ? ' − concentração (' + Math.abs(breakdown.penalidade||0) + ')' : '')
        + '</div>'
        + '</div></details>';
    }

    // ── D. Base de cálculo ────────────────────────────────────────────────
    var basisHtml = '';
    if (dataBasis.length > 0) {
      basisHtml = '<div class="action-data-basis" style="margin-bottom:12px;">'
        + '<div style="font-size:0.72rem;color:var(--text-3);margin-bottom:5px;">Base de cálculo</div>'
        + '<div style="display:flex;flex-wrap:wrap;gap:4px;">'
        + dataBasis.map(function(b) {
            return '<span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:0.7rem;background:#f1f5f9;color:var(--text-2);border:1px solid #e2e8f0;">' + T.esc(b) + '</span>';
          }).join('')
        + '</div></div>';
    }

    // ── E. Clientes para recompra ─────────────────────────────────────────
    var repurchaseHtml = '<h4 class="pd-section-title" style="margin:14px 0 6px;">Clientes para recompra</h4>';
    if (repurchase.length > 0) {
      repurchaseHtml += '<p style="font-size:0.78rem;color:var(--text-3);margin-bottom:8px;">'
        + repurchase.length + ' cliente(s) sem compra há 30+ dias — priorizar pelos de maior score</p>'
        + '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">'
        + '<thead><tr><th>Cliente</th><th>Score</th><th>Compras</th><th>Dias</th><th>Vendedor</th></tr></thead>'
        + '<tbody>' + repurchase.slice(0, 8).map(function (c) {
            var sc = c.score_recompra || 0;
            var scC = sc >= 70 ? '#16a34a' : sc >= 40 ? '#2563eb' : '#9ca3af';
            return '<tr><td class="truncate">' + T.esc((c.cliente_nome || '').substring(0, 25)) + '</td>'
              + '<td><strong style="color:' + scC + '">' + sc + '</strong></td>'
              + '<td>' + (c.n_compras || 0) + '</td>'
              + '<td>' + (c.dias_desde_ultima || 0) + 'd</td>'
              + '<td class="truncate">' + T.esc((c.vendedor_ultima_venda || '').substring(0, 18)) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
    } else {
      // Smart empty state based on category
      var emptyMsg = cat === 'Produto campeão'
        ? 'Não há recompra atrasada neste recorte. Como o produto está ativo, a ação recomendada é prospectar clientes semelhantes aos compradores atuais.'
        : cat === 'Campanha para clientes antigos'
        ? 'Nenhum cliente com compra há 30+ dias neste recorte. Amplie o período ou verifique a sincronização das NFs.'
        : cat === 'Reativar produto'
        ? 'Sem clientes mapeados no recorte. Amplie o período para encontrar histórico de compradores anteriores.'
        : 'Sem clientes para recompra identificados neste recorte de datas.';
      repurchaseHtml += '<div class="action-empty-repurchase-explanation" style="padding:12px;border-radius:6px;background:var(--surface-muted,#f3f4f6);font-size:0.82rem;color:var(--text-3);">'
        + '<span class="material-symbols-rounded" style="font-size:14px;vertical-align:middle;margin-right:4px;">info</span>'
        + T.esc(emptyMsg) + '</div>';
    }

    return '<div class="pd-acao-comercial" style="padding-bottom:8px;">' + decisionHtml + kpiHtml + motivosHtml + basisHtml + repurchaseHtml + '</div>';
  };

  function _acaoBlock(icon, label, value, textColor, bgColor, borderColor) {
    return '<div style="padding:10px 12px;border-radius:6px;background:' + bgColor + ';border:1px solid ' + borderColor + ';">'
      + '<div style="font-size:0.68rem;color:var(--text-3);margin-bottom:2px;display:flex;align-items:center;gap:3px;">'
      + '<span class="material-symbols-rounded" style="font-size:12px;">' + icon + '</span>' + label + '</div>'
      + '<div style="font-size:0.85rem;font-weight:600;color:' + textColor + ';">' + T.esc(value) + '</div>'
      + '</div>';
  }

  // ── Giro & Risco (Estoque operacional) ───────────────────────────────────

  T.renderEstoque = function (data) {
    var ledger  = (data && data.document_ledger) || {};
    var vendors = (data && data.top_vendors)     || [];
    var srcNote = (data && data.source_note)     || 'Visão operacional por documentos Omie. Não é saldo físico real.';

    if (ledger.empty_state) {
      return '<div class="pd-source-note pd-source-note--warn" style="margin-bottom:12px;padding:10px 14px;border-radius:6px;background:var(--surface-muted,#f3f4f6);font-size:0.82rem;">'
        + '<span class="material-symbols-rounded" style="font-size:14px;vertical-align:middle;margin-right:4px;">info</span>'
        + T.esc(srcNote) + '</div>'
        + T.empty(ledger.empty_state, 'inventory');
    }

    var com     = ledger.commercial      || {};
    var vel     = ledger.velocity        || {};
    var risk    = ledger.rupture_risk    || {};
    var topC    = ledger.top_clients     || [];
    var monthly = ledger.monthly_breakdown || [];

    // Semantic source banner
    var sourceHtml = '<div class="pd-source-note" style="margin-bottom:12px;padding:10px 14px;border-radius:6px;'
      + 'background:#e0e7ff;border:1px solid #c7d2fe;font-size:0.81rem;color:#3730a3;display:flex;align-items:flex-start;gap:6px;">'
      + '<span class="material-symbols-rounded" style="font-size:15px;flex-shrink:0;margin-top:1px;">info</span>'
      + '<span>' + T.esc(srcNote) + '</span></div>';

    // Risk badge
    var riskColor = { high: '#dc2626', medium: '#d97706', low: '#16a34a', none: '#9ca3af' };
    var riskLabel = { high: 'Alto', medium: 'Médio', low: 'Baixo', none: 'Sem dados' };
    var rLevel = risk.level || 'none';
    var riskBadge = '<span style="color:' + (riskColor[rLevel] || '#9ca3af') + ';font-weight:700;font-size:0.9rem;">'
      + T.esc(riskLabel[rLevel] || 'Sem dados') + '</span>';

    // KPI grid
    var kpiDefs = [
      ['Qtd. vendida',       T.fmtN(com.qty_total) + ' un',            'Período selecionado'],
      ['Giro 30 dias',       T.fmtN(vel.giro_30d_qty) + ' un',         (vel.giro_30d_nfs || 0) + ' NFs'],
      ['Giro 90 dias',       T.fmtN(vel.giro_90d_qty) + ' un',         (vel.giro_90d_nfs || 0) + ' NFs'],
      ['Meses ativos',       (com.active_months || 0) + ' meses',       'Com venda no período'],
      ['Clientes distintos', T.fmtN(com.customers_count || com.customers || 0), 'Compradores'],
      ['Receita total',      T.fmt(com.value_total),                    'NF comercial autorizada'],
      ['Risco operacional',  riskBadge,                                 T.esc(risk.note || '')],
    ];
    var kpiHtml = '<div class="pd-kpi-grid">'
      + kpiDefs.map(function (k) {
          return '<div class="pd-kpi-item">'
            + '<span class="pd-kpi-label">' + T.esc(k[0]) + '</span>'
            + '<strong class="pd-kpi-value">' + k[1] + '</strong>'
            + (k[2] ? '<span class="pd-kpi-sub">' + k[2] + '</span>' : '')
            + '</div>';
        }).join('')
      + '</div>';

    // Monthly table
    var monthlyHtml = '';
    if (monthly.length > 0) {
      monthlyHtml = '<h4 class="pd-section-title" style="margin:16px 0 6px;">Giro por mês</h4>'
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
      clientsHtml = '<h4 class="pd-section-title" style="margin:16px 0 6px;">Top clientes compradores</h4>'
        + '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">'
        + '<thead><tr><th>Cliente</th><th>Qtd</th><th>NFs</th><th>Receita</th></tr></thead>'
        + '<tbody>' + topC.map(function (c) {
            return '<tr><td class="truncate">' + T.esc(c.nome_cliente || c.customer_name || '') + '</td>'
              + '<td class="font-mono">' + T.fmtN(c.qty) + '</td>'
              + '<td>' + T.fmtN(c.nfs) + '</td>'
              + '<td class="font-mono">' + T.fmt(c.value) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
    }

    // Top vendors (from EST.R1.2 builder)
    var vendorsHtml = '';
    if (vendors.length > 0) {
      vendorsHtml = '<h4 class="pd-section-title" style="margin:16px 0 6px;">Vendedores que mais venderam</h4>'
        + '<div style="overflow-x:auto"><table class="mag-table mag-table-clean">'
        + '<thead><tr><th>Vendedor</th><th>Qtd</th><th>% Qtd</th><th>NFs</th><th>Clientes</th></tr></thead>'
        + '<tbody>' + vendors.map(function (v) {
            return '<tr><td class="truncate">' + T.esc(v.vendor_name || '') + '</td>'
              + '<td class="font-mono">' + T.fmtN(v.qty) + '</td>'
              + '<td>' + Number(v.participation_pct || 0).toFixed(1) + '%</td>'
              + '<td>' + T.fmtN(v.nfs) + '</td>'
              + '<td>' + T.fmtN(v.customers) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
    }

    return '<div class="pd-estoque">'
      + sourceHtml + kpiHtml + monthlyHtml + clientsHtml + vendorsHtml
      + '</div>';
  };

  // ── Diagnóstico ──────────────────────────────────────────────────────────

  T.renderDiagnostico = function (data) {
    var dq       = (data && data.data_quality) || {};
    var meta     = (data && data.meta)         || {};
    var pb       = (data && data.period_basis) || {};
    var identity = (data && data.identity)     || {};

    // Identity block — most useful info first
    var identHtml = '';
    if (identity.display_name) {
      identHtml = '<div style="padding:10px 14px;border-radius:6px;background:var(--surface-muted,#f3f4f6);'
        + 'font-size:0.81rem;margin-bottom:12px;">'
        + '<strong>Identidade:</strong> ' + T.esc(identity.display_name)
        + ' · fonte: ' + T.esc(identity.description_source || '-')
        + ' · match: ' + T.esc(identity.matched_by || '-')
        + ' · confiança: ' + T.esc(identity.confidence || '-')
        + (Array.isArray(identity.warnings) && identity.warnings.length
            ? '<br><span style="color:var(--warning,#d97706)">⚠ ' + identity.warnings.map(T.esc).join(', ') + '</span>'
            : '')
        + '</div>';
    }

    var rows = [
      ['product_code',              meta.product_code  || '-'],
      ['resolved_db_code',          identity.db_code   || '-'],
      ['period_basis',              pb.realized        || '-'],
      ['canonical_items_count',     dq.canonical_items_count      != null ? dq.canonical_items_count      : '-'],
      ['authorized_invoices_count', dq.authorized_invoices_count  != null ? dq.authorized_invoices_count  : '-'],
      ['linked_orders_count',       dq.linked_orders_count        != null ? dq.linked_orders_count        : '-'],
      ['mart_rows_count',           dq.mart_rows_count            != null ? dq.mart_rows_count            : '-'],
      ['identity_matched_by',       dq.identity_matched_by        || '-'],
      ['identity_confidence',       dq.identity_confidence        || '-'],
      ['source',                    dq.source          || '-'],
      ['freshness_status',          dq.freshness_status || '-'],
      ['last_canonical_nf_at',      dq.last_canonical_nf_at       || '-'],
      ['last_gold_materialized_at', dq.last_gold_materialized_at  || '-'],
      ['request_id',                meta.request_id    || '-'],
      ['generated_at',              meta.generated_at  || '-'],
    ];

    var techWarnings = Array.isArray(dq.warnings) && dq.warnings.length
      ? dq.warnings.filter(function (w) { return w.indexOf('product_') !== 0; })
      : [];

    var warningsHtml = techWarnings.length
      ? '<div class="pd-warnings" style="margin-bottom:8px;">'
          + techWarnings.map(function (w) {
              return '<span class="mag-status-chip mag-status-warning">' + T.esc(w) + '</span>';
            }).join('') + '</div>'
      : '';

    var diagContent = identHtml + warningsHtml +
      '<dl class="pd-diag-grid">' +
      rows.map(function (r) {
        return '<dt>' + T.esc(r[0]) + '</dt><dd>' + T.esc(String(r[1])) + '</dd>';
      }).join('') +
      '</dl>';

    // Wrap in collapsible details element
    return '<div class="pd-diag">'
      + '<details style="font-size:0.82rem;">'
      + '<summary style="cursor:pointer;padding:8px 0;color:var(--text-2);list-style:none;display:flex;align-items:center;gap:6px;">'
      + '<span class="material-symbols-rounded" style="font-size:16px;">analytics</span>'
      + 'Diagnóstico técnico</summary>'
      + '<div style="margin-top:8px;">' + diagContent + '</div>'
      + '</details></div>';
  };

  window.BIomie = B;
}());
