(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  function renderVendorTable(vendors) {
    const tb = document.getElementById('flightVendorsTable');
    if (!tb) return;
    
    const dom = window.BIomieFlightPlan.Dom;
    const formatters = window.BIomieFlightPlan.Formatters;
    if (!dom || !formatters) return;

    if (!vendors || !vendors.length) {
      tb.innerHTML = '<tr><td colspan="12" class="empty-row" style="text-align:center;padding:24px;">Sem vendedor no periodo.</td></tr>';
      return;
    }

    tb.innerHTML = vendors.map(v => {
      const target = Number(v.target_month || v.target_period) || 0;
      const realized = Number(v.realized_period) || 0;
      const forecast = Number(v.forecast_period) || 0;
      const gap = target - realized;
      const att = target > 0 ? (realized / target) * 100 : 0;
      const carteiraQtd = Number(v.pipeline_orders_count || 0);
      const notasQtd = Number(v.notes_count || 0);
      const cliQtd = Number(v.customers_count || 0);
      const vid = dom.escH(v.vendor_id || v.vendor_name || '');
      
      let badgeClass = 'fp-status-none';
      if (v.risk_status === 'OK') badgeClass = 'fp-status-ok';
      else if (v.risk_status === 'ATENCAO') badgeClass = 'fp-status-attention';
      else if (v.risk_status === 'SALA_DE_GUERRA') badgeClass = 'fp-status-risk';

      const gapColor = gap > 0 ? 'var(--danger)' : 'var(--success)';

      return `<tr class="clickable-row" data-biomie-action="open-vendor" data-vendor-id="${vid}">
        <td><strong>${dom.escH(v.vendor_name || 'Sem vendedor')}</strong></td>
        <td><span class="fp-status-badge ${badgeClass}">${formatters.statusLabel(v.risk_status)}</span></td>
        <td>${formatters.fmt(realized)}</td>
        <td>${formatters.fmt(target)}</td>
        <td>${formatters.fmtN(att, 1)}%</td>
        <td style="color:${gapColor}">${formatters.fmt(gap)}</td>
        <td>${formatters.fmt(forecast)}</td>
        <td>${formatters.fmt(Number(v.pipeline_value || 0))}</td>
        <td>${formatters.fmtN(notasQtd)}</td>
        <td>${formatters.fmtN(carteiraQtd)}</td>
        <td>${formatters.fmtN(cliQtd)}</td>
        <td><button class="biomie-btn biomie-btn--secondary biomie-btn--sm" data-biomie-action="open-vendor" data-vendor-id="${vid}">Detalhar</button></td>
      </tr>`;
    }).join('');
  }

  function renderTargetsForm(vendors) {
    // Legacy support to prevent undefined errors
  }

  window.BIomieFlightPlan.VendorTable = {
    renderVendorTable, renderTargetsForm
  };
})();
