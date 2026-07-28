(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  function setText(selector, val) {
    const el = document.querySelector(selector);
    if (el) el.textContent = val;
  }
  function setHtml(selector, val) {
    const el = document.querySelector(selector);
    if (el) el.innerHTML = val;
  }
  function escH(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
  function showLoading(on) {
    const el = document.getElementById('loadingIndicator');
    if (el) el.style.display = on ? 'inline-flex' : 'none';
  }
  function detailRef(v) {
    const d = window.BIomieFlightPlan.Filters ? window.BIomieFlightPlan.Filters.getDates() : { start: '', end: '' };
    return JSON.stringify({
      vendor_id: v.vendor_id,
      vendor_name: v.vendor_name,
      start: d.start,
      end: d.end
    });
  }

  window.BIomieFlightPlan.Dom = {
    setText, setHtml, escH, showLoading, detailRef
  };
})();
