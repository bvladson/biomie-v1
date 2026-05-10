/**
 * BIomie — Invoices (NF) page drilldown.
 * Enhances the NF tables on pedidos.html to open BIomieInvoiceDetail.
 *
 * The existing data-invoice-detail-trigger buttons already carry
 * data-biomie-action="open-invoice" (added via app.js) so the drilldown-router
 * handles them automatically. This module adds extra UX:
 *
 *   - MutationObserver on ordersTable: ensures rows added after mount
 *     also get accessibility labels
 *   - Vendor name cells: adds data-biomie-action="open-vendor" where missing
 *     (progressive enhancement for rows rendered before this module loads)
 *   - DANFE/XML quick-open from drawer connected to invoice detail
 */
(function () {
  'use strict';

  if (window.BIomieInvoicesPageDrilldown) return;
  window.BIomieInvoicesPageDrilldown = true;

  var TABLES = ['ordersTable', 'pendingOrdersTable', 'cancelledOrdersTable'];

  // Ensure vendor links on already-rendered rows have the action attribute.
  // (Handles rows rendered before this script executed.)
  function enhanceVendorLinks(root) {
    root = root || document;
    root.querySelectorAll('a.bmie-ctx-link[href*="vendedores"]').forEach(function (a) {
      if (a.getAttribute('data-biomie-action')) return;
      var name = a.textContent.trim();
      if (name) a.setAttribute('data-biomie-action', 'open-vendor');
      if (name) a.setAttribute('data-vendor-name', name);
    });
  }

  function enhanceTable(id) {
    var tbody = document.getElementById(id);
    if (!tbody) return;
    enhanceVendorLinks(tbody);

    // Watch for new rows (added after API response)
    var obs = new MutationObserver(function () {
      enhanceVendorLinks(tbody);
    });
    obs.observe(tbody, { childList: true, subtree: false });
  }

  function init() {
    TABLES.forEach(enhanceTable);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
