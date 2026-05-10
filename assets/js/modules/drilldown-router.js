/**
 * BIomie — Drilldown Router.
 * Single event delegation handler for all data-biomie-action triggers.
 * Installed once; idempotent — safe to include on multiple pages.
 *
 * Actions handled:
 *   open-vendor  → BIomieVendorDetail.open(id, { start, end })
 *   open-order   → BIomieOrderDetail.open(codigoPedido)
 *   open-invoice → BIomieInvoiceDetail.open({ numero_nf, nota_key, chave_nfe })
 */
(function () {
  'use strict';

  if (window.BIomieDrilldownRouter) return;
  window.BIomieDrilldownRouter = true;

  var B = window.BIomie || {};

  function getDateRange() {
    if (B.getDateRange) return B.getDateRange();
    var s = document.getElementById('dateStart');
    var e = document.getElementById('dateEnd');
    return { start: (s && s.value) || '', end: (e && e.value) || '' };
  }

  function strAttr(el, name) {
    return String(el.getAttribute(name) || el.dataset[toCamel(name)] || '');
  }

  function toCamel(attr) {
    return attr.replace('data-', '').replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); });
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-biomie-action]');
    if (!trigger) return;

    var action = trigger.getAttribute('data-biomie-action') || '';

    if (action === 'open-vendor') {
      var vendorId   = strAttr(trigger, 'data-vendor-id');
      var vendorName = strAttr(trigger, 'data-vendor-name');
      var ref        = vendorId || vendorName;
      if (!ref) return;
      e.preventDefault();
      var range = getDateRange();
      if (window.BIomieVendorDetail) {
        BIomieVendorDetail.open(ref, { start: range.start, end: range.end });
      }
      return;
    }

    if (action === 'open-order') {
      var codigo = strAttr(trigger, 'data-pedido-codigo') || strAttr(trigger, 'data-codigo');
      if (!codigo) return;
      e.preventDefault();
      if (window.BIomieOrderDetail) BIomieOrderDetail.open(codigo);
      return;
    }

    if (action === 'open-invoice') {
      var notaKey  = strAttr(trigger, 'data-nota-key');
      var numeroNf = strAttr(trigger, 'data-numero-nf') || strAttr(trigger, 'data-codigo');
      var chaveNfe = strAttr(trigger, 'data-chave-nfe');
      if (!notaKey && !numeroNf && !chaveNfe) return;
      e.preventDefault();
      if (window.BIomieInvoiceDetail) BIomieInvoiceDetail.open({ nota_key: notaKey, numero_nf: numeroNf, chave_nfe: chaveNfe });
      return;
    }

    if (action === 'open-customer') {
      var rangeCustomer = getDateRange();
      var payload = {
        start: rangeCustomer.start,
        end: rangeCustomer.end,
        city: strAttr(trigger, 'data-city') || strAttr(trigger, 'data-cidade'),
        bairro: strAttr(trigger, 'data-bairro'),
        cliente_documento: strAttr(trigger, 'data-cliente-documento') || strAttr(trigger, 'data-customer-document'),
        documento_norm: strAttr(trigger, 'data-documento-norm'),
        codigo_cliente_omie: strAttr(trigger, 'data-codigo-cliente-omie') || strAttr(trigger, 'data-client-id') || strAttr(trigger, 'data-customer-id'),
        cliente_nome: strAttr(trigger, 'data-cliente-nome') || strAttr(trigger, 'data-customer-name'),
        nota_key: strAttr(trigger, 'data-nota-key'),
        pedido_codigo: strAttr(trigger, 'data-pedido-codigo') || strAttr(trigger, 'data-codigo-pedido') || strAttr(trigger, 'data-origin-order')
      };
      if (!payload.codigo_cliente_omie && !payload.cliente_documento && !payload.documento_norm && !payload.cliente_nome && !payload.nota_key && !payload.pedido_codigo) return;
      e.preventDefault();
      e.__biomieCustomerHandled = true;
      if (window.BIomieCustomerDetail) BIomieCustomerDetail.open(payload);
      return;
    }
  });
}());
