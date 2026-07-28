(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  function renderAlerts(alerts) {
    const wrap = document.getElementById('flightAlerts');
    if (!wrap) return;
    const dom = window.BIomieFlightPlan.Dom;
    if (!dom) return;

    if (!alerts || !alerts.length) {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'flex';
    wrap.innerHTML = alerts.slice(0, 5).map(a => {
      const icon = a.level === 'danger' ? 'error' : a.level === 'warning' ? 'warning' : 'info';
      const action = a.action && a.action.url
        ? `<a class="biomie-btn biomie-btn--secondary biomie-btn--sm" href="${dom.escH(a.action.url)}">${dom.escH(a.action.label || 'Abrir')}</a>`
        : '';
      return `<div class="fp-alert-item ${a.level}">
        <span class="material-symbols-rounded fp-alert-icon">${icon}</span>
        <div class="fp-alert-content">
          <h4>${dom.escH(a.title || 'Alerta')}</h4>
          <p>${dom.escH(a.message || '')}</p>
        </div>
        ${action}
      </div>`;
    }).join('');
  }

  window.BIomieFlightPlan.Alerts = {
    renderAlerts
  };
})();
