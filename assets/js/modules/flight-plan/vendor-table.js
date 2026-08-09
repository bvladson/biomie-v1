(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  var RISK_BADGE = {
    // Phase F new values
    on_track:  'fp-status-ok',
    attention: 'fp-status-attention',
    critical:  'fp-status-risk',
    no_sales:  'fp-status-none',
    no_target: 'fp-status-risk',
    // Legacy risk_status values
    OK:             'fp-status-ok',
    ATENCAO:        'fp-status-attention',
    SALA_DE_GUERRA: 'fp-status-risk',
  };

  var RISK_LABEL = {
    on_track:  'No ritmo',
    attention: 'Atenção',
    critical:  'Em risco',
    no_sales:  'Sem vendas',
    no_target: 'Meta pendente',
    OK:             'No ritmo',
    ATENCAO:        'Atenção',
    SALA_DE_GUERRA: 'Em risco',
    SEM_META:       'Meta pendente',
  };

  function resolveRisk(v) {
    return v.risk || v.risk_status || '';
  }

  function renderVendorTable(vendors) {
    var tb = document.getElementById('flightVendorsTable');
    if (!tb) return;

    var dom = window.BIomieFlightPlan.Dom || {};
    var formatters = window.BIomieFlightPlan.Formatters || {};
    var escH = dom.escH || function (s) { return String(s || '').replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c] || c; }); };
    var fmt  = formatters.fmt  || function (v) { return v; };
    var fmtN = formatters.fmtN || function (v) { return v; };

    if (!vendors || !vendors.length) {
      if (window.BIOMIE_STATES) {
        window.BIOMIE_STATES.renderTableEmptyState(tb);
      } else {
        tb.innerHTML = '<tr><td colspan="13" class="mag-empty-cell"><div class="mag-state mag-state-empty mag-state-compact mag-state-severity--info" role="status"><span class="material-symbols-rounded mag-state-icon">person_off</span><strong class="mag-state-title">Nenhum vendedor encontrado</strong><p class="mag-state-message">Não há vendedores com meta ou atividade comercial no período selecionado.</p></div></td></tr>';
      }
      return;
    }

    // Sort: critical > no_sales > attention > on_track, then gap desc
    var SORT_WEIGHT = { no_target: 4, critical: 3, no_sales: 2, attention: 1, on_track: 0,
                        SEM_META: 4, SALA_DE_GUERRA: 3, ATENCAO: 1, OK: 0 };
    var sorted = vendors.slice().sort(function (a, b) {
      var wa = SORT_WEIGHT[resolveRisk(a)] || 0;
      var wb = SORT_WEIGHT[resolveRisk(b)] || 0;
      if (wa !== wb) return wb - wa;
      return Number(b.gap_to_target || 0) - Number(a.gap_to_target || 0);
    });

    tb.innerHTML = sorted.map(function (v) {
      var risk       = resolveRisk(v);
      var badgeClass = RISK_BADGE[risk] || 'fp-status-none';
      var riskLbl    = v.risk_label || RISK_LABEL[risk] || risk;
      var target     = Number(v.target_period || v.target_month) || 0;
      var realized   = Number(v.realized_period) || 0;
      var forecast   = Number(v.forecast_period) || 0;
      var gap        = Number(v.gap_to_target !== undefined ? v.gap_to_target : Math.max(target - realized, 0));
      var att        = Number(v.attainment_pct !== undefined ? v.attainment_pct : (target > 0 ? (realized / target) * 100 : 0));
      var carteiraQtd = Number(v.pipeline_orders_count || 0);
      var notasQtd    = Number(v.notes_count || 0);
      var cliQtd      = Number(v.customers_count || 0);
      var vid         = escH(v.vendor_id || v.vendor_name || '');
      var gapColor    = gap > 0 ? 'var(--danger)' : 'var(--success)';
      var channel     = String(v.vendor_channel || 'outro');
      var channelLabel = String(v.channel_label || 'Não definido');
      var channelClass = {
        varejo: 'mag-badge--channel-var',
        revenda: 'mag-badge--channel-rev',
        representante: 'mag-badge--channel-rep',
        licitacao: 'mag-badge--channel-lic'
      }[channel] || 'mag-badge--source-empty';
      var targetCell = target > 0 ? fmt(target) : '<span class="fp-status-badge fp-status-risk">Definir meta</span>';

      // Fase 2: a mesma celula da tabela de vendedores, vinda do helper
      // compartilhado. Manter duas copias foi o que deixou as duas telas
      // discordarem sobre o que e "meta batida".
      //
      // `showNextStep` fica DESLIGADO aqui: `active_gap` e `new_daily_target`
      // nao vem no payload do plano de voo, e inventar a linha prescritiva com
      // campo ausente mostraria "faltam R$ 0".
      var B = window.BIomie;
      if (B && typeof B.renderTargetLadderCell === 'function') {
        targetCell = B.renderTargetLadderCell(v, { showNextStep: false });
      }

      return '<tr class="clickable-row" data-biomie-action="open-vendor" data-vendor-id="' + vid + '">' +
        '<td><strong>' + escH(v.vendor_name || 'Sem vendedor') + '</strong></td>' +
        '<td><span class="mag-badge ' + channelClass + '">' + escH(channelLabel) + '</span></td>' +
        '<td><span class="fp-status-badge ' + badgeClass + '">' + escH(riskLbl) + '</span></td>' +
        '<td>' + fmt(realized) + '</td>' +
        '<td>' + targetCell + '</td>' +
        '<td>' + fmtN(att, 1) + '%</td>' +
        '<td style="color:' + gapColor + '">' + fmt(gap) + '</td>' +
        '<td>' + fmt(forecast) + '</td>' +
        '<td>' + fmt(Number(v.pipeline_value || 0)) + '</td>' +
        '<td>' + fmtN(notasQtd) + '</td>' +
        '<td>' + fmtN(carteiraQtd) + '</td>' +
        '<td>' + fmtN(cliQtd) + '</td>' +
        '<td><button class="biomie-btn biomie-btn--secondary biomie-btn--sm" data-biomie-action="open-vendor" data-vendor-id="' + vid + '">Detalhar</button></td>' +
        '</tr>';
    }).join('');
  }

  function renderTargetsForm(vendors) {
    // Legacy support to prevent undefined errors
  }

  window.BIomieFlightPlan.VendorTable = {
    renderVendorTable, renderTargetsForm
  };
})();
