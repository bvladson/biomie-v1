/**
 * BIomieDrawer — Unified drawer manager.
 *
 * Single API for all 360-view drawers. Delegates to the per-type module.
 * Supports URL deep-links: vendedores.php?open=vendor&id=123
 *
 * Types: vendor | customer | invoice | order | product
 *
 * Usage:
 *   BIomieDrawer.open({ type: 'vendor', params: { id: 123, start: '...', end: '...' } });
 *   BIomieDrawer.open({ type: 'customer', params: { codigo_cliente_omie: '456' } });
 *   BIomieDrawer.open({ type: 'invoice', params: { numero_nf: '1247' } });
 *   BIomieDrawer.open({ type: 'order', params: { pedido_codigo: '789' } });
 */
(function (global) {
  'use strict';

  if (global.BIomieDrawer) return;

  var MODULES = {
    vendor:   'BIomieVendorDetail',
    customer: 'BIomieCustomerDetail',
    invoice:  'BIomieInvoiceDetail',
    order:    'BIomieOrderDetail',
    product:  'BIomieProductDetail',
  };
  var urlInitialized = false;

  function getPageDateRange() {
    var B = global.BIomie;
    if (B && typeof B.getDateRange === 'function') return B.getDateRange();
    var s = document.getElementById('dateStart') || document.getElementById('start_date');
    var e = document.getElementById('dateEnd')   || document.getElementById('end_date');
    return { start: (s && s.value) || '', end: (e && e.value) || '' };
  }

  function getModule(type) {
    var name = MODULES[type];
    if (!name) return null;
    return global[name] || null;
  }

  function open(options) {
    if (!options || !options.type) return;
    var type   = String(options.type);
    var params = Object.assign({}, options.params || {});
    var range  = getPageDateRange();

    if (range.start && !params.start) params.start = range.start;
    if (range.end   && !params.end)   params.end   = range.end;

    var mod = getModule(type);
    if (!mod || typeof mod.open !== 'function') {
      if (global.BIomieLazyDrilldowns && typeof global.BIomieLazyDrilldowns.ensure === 'function' && MODULES[type]) {
        global.BIomieLazyDrilldowns.ensure(type)
          .then(function () { open(options); })
          .catch(function (error) {
            console.error('[BIomieDrawer] Nao foi possivel carregar o detalhamento:', type, error);
          });
        return;
      }
      console.warn('[BIomieDrawer] Módulo não disponível para tipo:', type, '— verifique se o script foi carregado nesta página.');
      return;
    }

    if (type === 'vendor') {
      mod.open(params.vendor_id || params.id || '', params);
    } else if (type === 'customer') {
      mod.open(params);
    } else if (type === 'order') {
      mod.open(params.pedido_codigo || params.order_id || params.id || '');
    } else if (type === 'invoice') {
      mod.open({
        nota_key:  params.nota_key  || '',
        numero_nf: params.numero_nf || params.nf || '',
        chave_nfe: params.chave_nfe || '',
        initial_tab: params.initial_tab || '',
      });
    } else if (type === 'product') {
      mod.open({
        productCode: params.product_code || params.code || params.id || '',
        productName: params.product_name || '',
        start: params.start || '',
        end: params.end || '',
      });
    }
  }

  function close(type) {
    if (type) {
      var mod = getModule(type);
      if (mod && typeof mod.close === 'function') mod.close();
      return;
    }
    Object.keys(MODULES).forEach(function (t) {
      var mod = getModule(t);
      if (mod && typeof mod.close === 'function') mod.close();
    });
  }

  /**
   * Parse current URL and open the matching drawer if ?open=<type> is present.
   * e.g. vendedores.php?open=vendor&id=123
   *      pedidos.php?open=invoice&nf=00001247
   *      financeiro.php?open=customer&document=57228483000100
   */
  function initFromUrl() {
    if (urlInitialized) return;
    var search = global.location && global.location.search;
    if (!search) return;

    var params = {};
    try {
      new URLSearchParams(search).forEach(function (value, key) { params[key] = value; });
    } catch (e) { return; }

    var type = params.open;
    if (!type) return;
    urlInitialized = true;

    var drawerParams = Object.assign({}, params);
    delete drawerParams.open;

    // Normalize common alias keys from URL to expected param names
    if (drawerParams.document && !drawerParams.cliente_documento) {
      drawerParams.cliente_documento = drawerParams.document;
    }
    if (drawerParams.nf && !drawerParams.numero_nf) {
      drawerParams.numero_nf = drawerParams.nf;
    }

    function tryOpen() { open({ type: type, params: drawerParams }); }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { setTimeout(tryOpen, 150); });
    } else {
      setTimeout(tryOpen, 150);
    }
  }

  global.BIomieDrawer = { open: open, close: close, initFromUrl: initFromUrl };
}(window));
