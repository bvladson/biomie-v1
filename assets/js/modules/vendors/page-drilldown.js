/**
 * BIomie — Vendors page drilldown.
 * Connects the vendors page (vendedores.html) to BIomieVendorDetail.
 *
 * - Overrides window.openVendorDrawer so existing table onclick handlers
 *   open the Vendor 360 drawer instead of the flight-plan drawer.
 * - Handles vendor deep-link (?vendor_id=...) on page load.
 * - Idempotent: safe to include multiple times.
 */
(function () {
  'use strict';

  if (window.BIomieVendorsPageDrilldown) return;
  window.BIomieVendorsPageDrilldown = true;

  var B = window.BIomie || {};

  function isVendorsPage() {
    var page = document.body ? document.body.getAttribute('data-page') : '';
    return ['vendors', 'time-comercial'].includes(page);
  }

  function getRange() {
    if (B.getDateRange) return B.getDateRange();
    var s = document.getElementById('dateStart');
    var e = document.getElementById('dateEnd');
    return { start: (s && s.value) || '', end: (e && e.value) || '' };
  }

  function openVendor360(ref) {
    if (!ref || !window.BIomieVendorDetail) return;
    var range = getRange();
    BIomieVendorDetail.open(ref, { start: range.start, end: range.end });
  }

  // Override openVendorDrawer only on the vendors page so flight-plan pages
  // continue to use the original drawer.
  function installOverride() {
    if (!isVendorsPage()) return;

    var _original = window.openVendorDrawer;
    window.openVendorDrawer = function (vId) {
      if (window.BIomieVendorDetail) {
        openVendor360(vId);
      } else if (typeof _original === 'function') {
        _original(vId);
      }
    };
  }

  // Handle deep-link: vendedores.php?vendor_id=123
  function handleDeepLink() {
    if (!isVendorsPage()) return;
    var params = new URLSearchParams(window.location.search);
    var vid = (params.get('vendor_id') || params.get('vendor_nome') || '').trim();
    if (!vid) return;
    window.setTimeout(function () { openVendor360(vid); }, 200);
  }

  function init() {
    installOverride();
    handleDeepLink();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
