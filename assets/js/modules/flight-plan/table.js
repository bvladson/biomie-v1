(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  var RISK_CLASS = {
    on_track:  'mag-flight-status--on-track',
    attention: 'mag-flight-status--attention',
    critical:  'mag-flight-status--critical',
    no_sales:  'mag-flight-status--no-sales',
    // legacy risk_status values
    OK:             'mag-flight-status--on-track',
    ATENCAO:        'mag-flight-status--attention',
    SALA_DE_GUERRA: 'mag-flight-status--critical',
    SEM_META:       'mag-flight-status--no-sales',
  };

  var RISK_LABEL = {
    on_track:  'No ritmo',
    attention: 'Atenção',
    critical:  'Em risco',
    no_sales:  'Sem vendas',
    OK:             'No ritmo',
    ATENCAO:        'Atenção',
    SALA_DE_GUERRA: 'Em risco',
    SEM_META:       'Sem meta',
  };

  function resolveRisk(v) {
    return v.risk || v.risk_status || 'on_track';
  }

  function renderVendorTable(vendors) {
    var wrap = document.getElementById('vendorTableWrap');
    if (!wrap) return;

    var fmt  = (window.BIomieFlightPlan.Formatters || {}).fmt  || function (v) { return v; };
    var fmtN = (window.BIomieFlightPlan.Formatters || {}).fmtN || function (v) { return v; };
    var dom  = window.BIomieFlightPlan.Dom || {};
    var esc  = dom.escH || function (s) { return String(s || '').replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c] || c; }); };

    if (!vendors || !vendors.length) {
      wrap.innerHTML =
        '<div class="mag-flight-table-empty">' +
        '<span class="material-symbols-rounded">assignment_turned_in</span>' +
        '<p>Nenhum vendedor com meta ativa no período.</p>' +
        '</div>';
      return;
    }

    // Sort: critical > no_sales > attention > on_track, then by gap desc
    var SORT_WEIGHT = { critical: 3, no_sales: 2, attention: 1, on_track: 0,
                        SALA_DE_GUERRA: 3, SEM_META: 2, ATENCAO: 1, OK: 0 };
    var sorted = vendors.slice().sort(function (a, b) {
      var wa = SORT_WEIGHT[resolveRisk(a)] || 0;
      var wb = SORT_WEIGHT[resolveRisk(b)] || 0;
      if (wa !== wb) return wb - wa;
      return Number(b.gap_to_target || 0) - Number(a.gap_to_target || 0);
    });

    // Desktop table
    var tableHtml =
      '<table class="mag-flight-table">' +
      '<thead><tr>' +
      '<th>Vendedor</th>' +
      '<th>Meta do período</th>' +
      '<th>Vendas faturadas</th>' +
      '<th>% da meta</th>' +
      '<th>Quanto falta</th>' +
      '<th>Previsão</th>' +
      '<th>Ritmo necessário</th>' +
      '<th>Status</th>' +
      '<th></th>' +
      '</tr></thead><tbody>';

    sorted.forEach(function (v) {
      var risk      = resolveRisk(v);
      var riskCls   = RISK_CLASS[risk]  || 'mag-flight-status--on-track';
      var riskLbl   = RISK_LABEL[risk]  || risk;
      var ating     = fmtN(v.attainment_pct || 0, 1) + '%';
      var detailRef = (dom.detailRef && dom.detailRef(v)) || '';
      var openCall  = detailRef
        ? 'onclick="if(window.openDrawer)openDrawer(' + "'" + esc(detailRef) + "'" + ')"'
        : '';
      var actionUrl = v.vendor360_url || detailRef || '';

      tableHtml +=
        '<tr class="mag-flight-table__row">' +
        '<td class="mag-flight-table__vendor">' +
          '<strong>' + esc(v.vendor_name || '') + '</strong>' +
        '</td>' +
        '<td>' + fmt(v.target_period || 0) + '</td>' +
        '<td>' + fmt(v.realized_period || 0) + '</td>' +
        '<td>' + ating + '</td>' +
        '<td>' + fmt(v.gap_to_target || 0) + '</td>' +
        '<td>' + fmt(v.forecast_period || 0) + '</td>' +
        '<td class="mag-flight-table__pace">' +
          '<span class="mag-flight-pace__need">' + fmt(v.new_daily_target || 0) + '/dia</span>' +
        '</td>' +
        '<td><span class="mag-flight-status ' + riskCls + '">' + esc(riskLbl) + '</span></td>' +
        '<td>' +
          (actionUrl
            ? '<button class="mag-btn mag-btn--xs" ' + openCall + ' title="Ver 360">360</button>'
            : '') +
        '</td>' +
        '</tr>';
    });

    tableHtml += '</tbody></table>';

    // Mobile cards (hidden on desktop via CSS)
    var cardsHtml = '<div class="mag-flight-cards">';
    sorted.forEach(function (v) {
      var risk    = resolveRisk(v);
      var riskCls = RISK_CLASS[risk] || 'mag-flight-status--on-track';
      var riskLbl = RISK_LABEL[risk] || risk;
      var detailRef = (dom.detailRef && dom.detailRef(v)) || '';
      var openCall  = detailRef
        ? 'onclick="if(window.openDrawer)openDrawer(' + "'" + esc(detailRef) + "'" + ')"'
        : '';

      cardsHtml +=
        '<div class="mag-flight-card">' +
        '<div class="mag-flight-card__header">' +
          '<strong>' + esc(v.vendor_name || '') + '</strong>' +
          '<span class="mag-flight-status ' + riskCls + '">' + esc(riskLbl) + '</span>' +
        '</div>' +
        '<div class="mag-flight-card__kpis">' +
          '<div><span>Meta do período</span><strong>' + fmt(v.target_period || 0) + '</strong></div>' +
          '<div><span>Vendas faturadas</span><strong>' + fmt(v.realized_period || 0) + '</strong></div>' +
          '<div><span>% da meta</span><strong>' + fmtN(v.attainment_pct || 0, 1) + '%</strong></div>' +
          '<div><span>Quanto falta</span><strong>' + fmt(v.gap_to_target || 0) + '</strong></div>' +
        '</div>' +
        (detailRef
          ? '<button class="mag-btn mag-btn--sm mag-btn--block" ' + openCall + '>Ver Vendor 360</button>'
          : '') +
        '</div>';
    });
    cardsHtml += '</div>';

    wrap.innerHTML = tableHtml + cardsHtml;
  }

  window.BIomieFlightPlan.VendorTable = { renderVendorTable: renderVendorTable };
})();
