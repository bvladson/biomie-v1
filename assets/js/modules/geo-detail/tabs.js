/**
 * BIomie — Geo Detail Tabs.
 * Renderizadores de aba para o drawer Geo 360 (bairro).
 */
(function () {
  'use strict';

  var B = window.BIomie || {};

  function escHtml(v) {
    return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function money(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v || 0));
  }

  function num(v) {
    return new Intl.NumberFormat('pt-BR').format(Number(v || 0));
  }

  function pct(v) {
    return Number(v || 0).toFixed(1) + '%';
  }

  function emptyRow(msg, cols) {
    return '<tr><td colspan="' + cols + '" style="text-align:center;padding:0;">'
      + '<div class="mag-empty mag-empty--compact">'
      + '<p class="mag-empty-text">' + escHtml(msg) + '</p>'
      + '</div>'
      + '</td></tr>';
  }

  function sourceBadge(source) {
    if (window.BIomie && window.BIomie.sourceBadge) return window.BIomie.sourceBadge(source);
    var label = source === 'gold_mart' ? 'Gold Mart' : (source === 'empty' ? 'Sem dados' : escHtml(source));
    var color = source === 'gold_mart' ? 'var(--color-success,#16a34a)' : 'var(--text-3,#888)';
    return '<span style="display:inline-block;font-size:10px;padding:1px 7px;border-radius:10px;border:1px solid ' + color + ';color:' + color + ';">' + label + '</span>';
  }

  function freshnessBadge(status) {
    if (window.BIomie && window.BIomie.freshnessBadge) return window.BIomie.freshnessBadge(status);
    var map = { ok: ['ok', 'var(--color-success,#16a34a)'], warning: ['atenção', '#d97706'], critical: ['crítico', 'var(--color-danger,#dc2626)'], unknown: ['desconhecido', 'var(--text-3,#888)'] };
    var info = map[status] || map.unknown;
    return '<span style="display:inline-block;font-size:10px;padding:1px 7px;border-radius:10px;border:1px solid ' + info[1] + ';color:' + info[1] + ';">freshness: ' + info[0] + '</span>';
  }

  function renderResumo(data) {
    var s   = data.summary || {};
    var dq  = data.data_quality || {};
    var nb  = data.neighborhood || {};
    var es  = data.empty_states || {};

    if (es.sales && es.sales.is_empty) {
      var reason = es.sales.reason_code === 'sync_stale'
        ? 'Dados desatualizados (>72h). Execute a materialização geográfica.'
        : 'Nenhuma venda encontrada para este bairro no período selecionado.';
      return '<div class="mag-empty mag-empty--compact">'
        + '<div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">location_off</span></div>'
        + '<p class="mag-empty-text">' + escHtml(reason) + '</p>'
        + '</div>';
    }

    var html = '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:12px 0;">';
    var kpis = [
      ['Receita', money(s.revenue)],
      ['Clientes', num(s.customers_count)],
      ['Notas Fiscais', num(s.invoices_count)],
      ['Ticket Médio', money(s.avg_ticket)],
      ['CNAEs', num(s.cnaes_count)],
    ];
    kpis.forEach(function (kpi) {
      html += '<div style="background:var(--bg-2,#f8f9fa);border-radius:6px;padding:10px 12px;">'
        + '<div style="font-size:11px;color:var(--text-3,#888);">' + escHtml(kpi[0]) + '</div>'
        + '<div style="font-size:16px;font-weight:700;color:var(--text-1,#111);">' + kpi[1] + '</div>'
        + '</div>';
    });
    html += '</div>';

    html += '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">'
      + sourceBadge(data.source) + ' ' + freshnessBadge(dq.freshness_status) + '</div>';

    if (s.first_sale_at || s.last_sale_at) {
      html += '<div style="font-size:12px;color:var(--text-3,#888);margin-bottom:8px;">Primeira venda: <strong>' + escHtml(s.first_sale_at || '-') + '</strong> &nbsp;|&nbsp; Última: <strong>' + escHtml(s.last_sale_at || '-') + '</strong></div>';
    }

    if (Array.isArray(dq.warnings) && dq.warnings.length) {
      html += '<div style="background:#fef9c3;border:1px solid #d97706;border-radius:6px;padding:10px;font-size:12px;margin-top:8px;">';
      dq.warnings.forEach(function (w) { html += '<div>⚠ ' + escHtml(w) + '</div>'; });
      html += '</div>';
    }

    return html;
  }

  function renderBuyers(rows) {
    if (!rows || !rows.length) {
      return '<table class="drawer-table"><tbody>' + emptyRow('Nenhum comprador encontrado.', 3) + '</tbody></table>';
    }
    var html = '<table class="drawer-table"><thead><tr><th>Cliente</th><th style="text-align:right;">NFs</th><th style="text-align:right;">Receita</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      var name = r.customer_name || 'Sem nome';
      var attrs = 'data-biomie-action="open-customer"'
        + ' data-codigo-cliente-omie="' + escHtml(r.codigo_cliente_omie || '') + '"'
        + ' data-cliente-nome="' + escHtml(name) + '"'
        + ' data-bairro="' + escHtml(r.bairro_norm || '') + '"'
        + ' data-city="' + escHtml(r.cidade_norm || '') + '"';
      html += '<tr>'
        + '<td><button type="button" class="row-inline-link" ' + attrs + '>' + escHtml(name) + '</button>'
        + (r.participation_pct !== undefined ? '<div style="font-size:11px;color:var(--text-3,#888);">' + pct(r.participation_pct) + ' da receita do bairro</div>' : '')
        + '</td>'
        + '<td style="text-align:right;">' + num(r.invoices_count) + '</td>'
        + '<td style="text-align:right;"><strong>' + money(r.revenue) + '</strong></td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function renderCnaes(rows) {
    if (!rows || !rows.length) {
      return '<table class="drawer-table"><tbody>' + emptyRow('Nenhum CNAE encontrado. Execute cnae:diagnose-fiscal-sources no Admin Console.', 3) + '</tbody></table>';
    }
    var html = '<table class="drawer-table"><thead><tr><th>CNAE / Segmento</th><th style="text-align:right;">Clientes</th><th style="text-align:right;">Receita</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      html += '<tr>'
        + '<td><strong>' + escHtml(r.cnae_codigo || 'SEM_CNAE') + '</strong>'
        + '<div style="font-size:11px;color:var(--text-3,#888);">' + escHtml(r.cnae_descricao || 'Sem CNAE') + '</div>'
        + (r.participation_pct !== undefined ? '<div style="font-size:11px;color:var(--text-3,#888);">' + pct(r.participation_pct) + '</div>' : '')
        + '</td>'
        + '<td style="text-align:right;">' + num(r.customers_count) + '</td>'
        + '<td style="text-align:right;"><strong>' + money(r.revenue) + '</strong></td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function renderInvoices(rows) {
    if (!rows || !rows.length) {
      return '<table class="drawer-table"><tbody>' + emptyRow('Nenhuma NF encontrada para este bairro no período.', 4) + '</tbody></table>';
    }
    var html = '<table class="drawer-table"><thead><tr><th>NF</th><th>Competência</th><th style="text-align:right;">Vendedor</th><th style="text-align:right;">Total</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      var nfBtn = r.nota_key
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-invoice" data-nota-key="' + escHtml(r.nota_key) + '" data-numero-nf="' + escHtml(r.numero_nf || '') + '">' + escHtml(r.numero_nf || r.nota_key) + '</button>'
        : '<span>' + escHtml(r.numero_nf || '-') + '</span>';
      html += '<tr>'
        + '<td>' + nfBtn + '</td>'
        + '<td>' + escHtml(r.data_competencia || '-') + '</td>'
        + '<td style="text-align:right;">' + escHtml(r.codigo_vendedor_omie || '-') + '</td>'
        + '<td style="text-align:right;"><strong>' + money(r.invoice_total) + '</strong></td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function renderVendors(rows) {
    if (!rows || !rows.length) {
      return '<table class="drawer-table"><tbody>' + emptyRow('Nenhum vendedor encontrado.', 3) + '</tbody></table>';
    }
    var total = rows.reduce(function (s, r) { return s + Number(r.revenue || 0); }, 0);
    var html = '<table class="drawer-table"><thead><tr><th>Vendedor</th><th style="text-align:right;">Clientes</th><th style="text-align:right;">Receita</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      var vendorAttrs = 'data-biomie-action="open-vendor" data-vendor-id="' + escHtml(r.codigo_vendedor_omie || '') + '" data-vendor-name="' + escHtml(r.vendor_name || '') + '"';
      html += '<tr>'
        + '<td><button type="button" class="row-inline-link" ' + vendorAttrs + '>' + escHtml(r.vendor_name || '-') + '</button>'
        + (r.participation_pct !== undefined ? '<div style="font-size:11px;color:var(--text-3,#888);">' + pct(r.participation_pct) + '</div>' : '')
        + '</td>'
        + '<td style="text-align:right;">' + num(r.customers_count || 0) + '</td>'
        + '<td style="text-align:right;"><strong>' + money(r.revenue) + '</strong></td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function renderDiagnostico(data) {
    var dq  = data.data_quality || {};
    var pb  = data.period_basis || {};
    var meta = data.meta || {};

    function row(label, value) {
      return '<tr><td style="color:var(--text-3,#888);font-size:12px;padding:4px 8px;">' + escHtml(label) + '</td>'
        + '<td style="font-size:12px;padding:4px 8px;font-family:monospace;">' + escHtml(String(value ?? '-')) + '</td></tr>';
    }

    return '<table class="drawer-table" style="font-size:12px;">'
      + row('Fonte de dados', dq.source)
      + row('Freshness (gold)', dq.gold_freshness_status)
      + row('Freshness (canônica)', dq.canonical_freshness_status)
      + row('Linhas no mart (período)', dq.mart_rows_count)
      + row('Último refresh gold', dq.last_gold_materialized_at)
      + row('Última NF canônica', dq.last_canonical_nf_at)
      + row('Basis realizado', pb.realized)
      + row('Tabela fonte', pb.source)
      + row('request_id', meta.request_id)
      + row('generated_at', meta.generated_at)
      + '</table>';
  }

  window.B = window.B || {};
  window.B.GeoDetail = window.B.GeoDetail || {};
  window.B.GeoDetail.Tabs = {
    renderResumo:       renderResumo,
    renderBuyers:       renderBuyers,
    renderCnaes:        renderCnaes,
    renderInvoices:     renderInvoices,
    renderVendors:      renderVendors,
    renderDiagnostico:  renderDiagnostico,
  };
})();
