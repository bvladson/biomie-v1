/**
 * BIomie - Drilldown Router.
 *
 * Centraliza as acoes de detalhamento e carrega cada familia de drawers
 * somente no primeiro uso.
 */
(function () {
  'use strict';

  if (window.BIomieDrilldownRouter) return;
  window.BIomieDrilldownRouter = true;

  var B = window.BIomie || {};
  var featurePromises = {};
  var featureBundles = {
    vendor: [
      'modules/vendor-detail/tabs.js',
      'modules/vendor-detail/composition.js',
      'modules/vendor-detail/charts.js',
      'modules/vendor-detail/index.js'
    ],
    order: ['modules/order-detail/index.js'],
    invoice: ['modules/invoice-detail/index.js'],
    customer: ['modules/customer-detail/index.js'],
    product: [
      'modules/product-detail/tabs.js',
      'modules/product-detail/charts.js',
      'modules/product-detail/index.js'
    ],
    neighborhood: [
      'modules/geo-detail/tabs.js',
      'modules/geo-detail/charts.js',
      'modules/geo-detail/index.js'
    ]
  };
  var featureGlobals = {
    vendor: 'BIomieVendorDetail',
    order: 'BIomieOrderDetail',
    invoice: 'BIomieInvoiceDetail',
    customer: 'BIomieCustomerDetail',
    product: 'BIomieProductDetail',
    neighborhood: 'BIomieGeoDetail'
  };

  function routerAssetInfo() {
    var script = document.currentScript || document.querySelector('script[src*="modules/drilldown-router.js"]');
    var src = script && script.src ? script.src : '';
    var marker = '/modules/drilldown-router.js';
    var markerPosition = src.indexOf(marker);
    var queryPosition = src.indexOf('?');
    var lazyVersion = script ? script.getAttribute('data-biomie-lazy-version') : '';

    return {
      base: markerPosition >= 0 ? src.slice(0, markerPosition + 1) : 'assets/js/',
      query: lazyVersion ? '?v=' + encodeURIComponent(lazyVersion) : (queryPosition >= 0 ? src.slice(queryPosition) : '')
    };
  }

  var assetInfo = routerAssetInfo();

  function featureReady(feature) {
    return Boolean(window[featureGlobals[feature]]);
  }

  function loadScript(path) {
    return new Promise(function (resolve, reject) {
      var selector = 'script[data-biomie-lazy-src="' + path + '"]';
      var existing = document.querySelector(selector);
      if (existing && existing.getAttribute('data-biomie-loaded') === 'true') {
        resolve();
        return;
      }

      var script = existing || document.createElement('script');
      script.async = false;
      script.setAttribute('data-biomie-lazy-src', path);
      script.addEventListener('load', function () {
        script.setAttribute('data-biomie-loaded', 'true');
        resolve();
      }, { once: true });
      script.addEventListener('error', function () {
        reject(new Error('Nao foi possivel carregar o detalhamento: ' + path));
      }, { once: true });

      if (!existing) {
        script.src = assetInfo.base + path + assetInfo.query;
        document.head.appendChild(script);
      }
    });
  }

  function ensureFeature(feature) {
    if (featureReady(feature)) return Promise.resolve(window[featureGlobals[feature]]);
    if (!featureBundles[feature]) return Promise.reject(new Error('Detalhamento desconhecido: ' + feature));
    if (featurePromises[feature]) return featurePromises[feature];

    featurePromises[feature] = featureBundles[feature].reduce(function (chain, path) {
      return chain.then(function () { return loadScript(path); });
    }, Promise.resolve()).then(function () {
      if (!featureReady(feature)) {
        throw new Error('O detalhamento foi carregado, mas nao iniciou: ' + feature);
      }
      return window[featureGlobals[feature]];
    }).catch(function (error) {
      delete featurePromises[feature];
      throw error;
    });

    return featurePromises[feature];
  }

  function reportLoadError(error, feature) {
    console.error('[BIomie] Falha ao abrir detalhamento', error);
    document.dispatchEvent(new CustomEvent('biomie:drilldown-load-error', {
      detail: { feature: feature, error: error }
    }));
  }

  function showLoading(feature) {
    var el = document.getElementById('biomie-drilldown-loading');
    if (!el) {
      el = document.createElement('div');
      el.id = 'biomie-drilldown-loading';
      el.className = 'biomie-drilldown-loading';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    var labels = { invoice: 'Abrindo nota fiscal', order: 'Abrindo pedido', customer: 'Abrindo cliente', product: 'Abrindo produto', vendor: 'Abrindo vendedor' };
    el.innerHTML = '<span class="material-symbols-rounded" aria-hidden="true">progress_activity</span>' + (labels[feature] || 'Abrindo detalhes') + '…';
    el.hidden = false;
  }

  function hideLoading() {
    var el = document.getElementById('biomie-drilldown-loading');
    if (el) el.hidden = true;
  }

  function openFeature(feature, callback, trigger) {
    showLoading(feature);
    ensureFeature(feature)
      .then(function (detail) {
        hideLoading();
        callback(detail);
      })
      .catch(function (error) {
        hideLoading();
        reportLoadError(error, feature);
        var href = trigger && trigger.getAttribute ? trigger.getAttribute('href') : '';
        if (href && href !== '#') window.location.assign(href);
      });
  }

  function getDateRange() {
    if (B.getDateRange) return B.getDateRange();
    var s = document.getElementById('dateStart');
    var e = document.getElementById('dateEnd');
    return { start: (s && s.value) || '', end: (e && e.value) || '' };
  }

  function getRangeForTrigger(trigger) {
    if (!trigger || strAttr(trigger, 'data-biomie-search-origin') !== 'global') return getDateRange();
    var end = new Date();
    var start = new Date(end.getFullYear(), end.getMonth() - 11, 1);
    function iso(date) {
      return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    }
    return { start: iso(start), end: iso(end) };
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
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;

    var action = trigger.getAttribute('data-biomie-action') || '';

    if (action === 'open-vendor') {
      var vendorId = strAttr(trigger, 'data-vendor-id');
      var vendorName = strAttr(trigger, 'data-vendor-name');
      var ref = vendorId || vendorName;
      if (!ref) return;
      e.preventDefault();
      var range = getRangeForTrigger(trigger);
      openFeature('vendor', function (detail) {
        detail.open(ref, { start: range.start, end: range.end });
      }, trigger);
      return;
    }

    if (action === 'open-order') {
      var codigo = strAttr(trigger, 'data-pedido-codigo') || strAttr(trigger, 'data-codigo');
      if (!codigo) return;
      e.preventDefault();
      openFeature('order', function (detail) { detail.open(codigo); }, trigger);
      return;
    }

    if (action === 'open-invoice') {
      var notaKey = strAttr(trigger, 'data-nota-key');
      var numeroNf = strAttr(trigger, 'data-numero-nf') || strAttr(trigger, 'data-codigo');
      var chaveNfe = strAttr(trigger, 'data-chave-nfe');
      var invoiceInitialTab = strAttr(trigger, 'data-initial-tab');
      if (!notaKey && !numeroNf && !chaveNfe) return;
      e.preventDefault();
      openFeature('invoice', function (detail) {
        detail.open({ nota_key: notaKey, numero_nf: numeroNf, chave_nfe: chaveNfe, initial_tab: invoiceInitialTab });
      }, trigger);
      return;
    }

    if (action === 'open-customer') {
      var rangeCustomer = getRangeForTrigger(trigger);
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
      payload.initial_tab = strAttr(trigger, 'data-initial-tab');
      if (!payload.codigo_cliente_omie && !payload.cliente_documento && !payload.documento_norm && !payload.cliente_nome && !payload.nota_key && !payload.pedido_codigo) return;
      e.preventDefault();
      e.__biomieCustomerHandled = true;
      openFeature('customer', function (detail) { detail.open(payload); }, trigger);
      return;
    }

    if (action === 'open-product') {
      var productCode = strAttr(trigger, 'data-product-code');
      if (!productCode) return;
      e.preventDefault();
      var rangeProduct = getRangeForTrigger(trigger);
      openFeature('product', function (detail) {
        detail.open({
          productCode: productCode,
          productName: strAttr(trigger, 'data-product-name'),
          start: rangeProduct.start,
          end: rangeProduct.end
        });
      }, trigger);
      return;
    }

    if (action === 'open-neighborhood') {
      var neighborhoodKey = strAttr(trigger, 'data-neighborhood-key');
      var cidadeKey = strAttr(trigger, 'data-cidade-key') || strAttr(trigger, 'data-city-key');
      var bairroKey = strAttr(trigger, 'data-bairro-key') || neighborhoodKey;
      if (!neighborhoodKey && !cidadeKey && !bairroKey) return;
      e.preventDefault();
      var rangeGeo = getDateRange();
      openFeature('neighborhood', function (detail) {
        detail.open({
          neighborhoodKey: neighborhoodKey,
          neighborhood: strAttr(trigger, 'data-neighborhood-name'),
          city: strAttr(trigger, 'data-city') || 'Fortaleza',
          territoryType: strAttr(trigger, 'data-territory-type'),
          cidadeKey: cidadeKey,
          bairroKey: bairroKey,
          layer: strAttr(trigger, 'data-layer'),
          territoryLabel: strAttr(trigger, 'data-territory-label') || strAttr(trigger, 'data-neighborhood-name'),
          joinKey: strAttr(trigger, 'data-join-key'),
          start: rangeGeo.start,
          end: rangeGeo.end
        });
      }, trigger);
    }
  });

  window.BIomieLazyDrilldowns = {
    ensure: ensureFeature,
    isReady: featureReady
  };

  if (window.BIomieDrawer && typeof window.BIomieDrawer.initFromUrl === 'function') {
    window.BIomieDrawer.initFromUrl();
  }
}());
