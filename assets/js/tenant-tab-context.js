/*
 * BIomie R1 — contexto multiempresa imutável e isolado por aba.
 *
 * O identificador ativo vive somente em sessionStorage/URL. A troca de empresa
 * sempre passa pelo servidor e só confirma o novo contexto depois de uma
 * resposta válida de api/context_switch.php.
 */
(function () {
  'use strict';

  var nativeFetch = window.fetch ? window.fetch.bind(window) : null;
  var nativeOpen = window.XMLHttpRequest && window.XMLHttpRequest.prototype.open;
  var nativeSend = window.XMLHttpRequest && window.XMLHttpRequest.prototype.send;
  var nativeReplaceState = history.replaceState.bind(history);
  var nativePushState = history.pushState.bind(history);
  var contextStorageKey = 'biomie_tab_context_v1';
  var tabStorageKey = 'biomie_tab_instance_v1';
  var channelName = 'biomie-tab-presence-v1';
  var contextFreeApiPaths = [
    '/api/login_wallpaper.php'
  ];
  var urlContextId = contextFromUrl();
  var storedContextId = readSession(contextStorageKey);
  var contextId = urlContextId
    || (validId(storedContextId) ? storedContextId.toLowerCase() : '');
  var tabId = validId(readSession(tabStorageKey)) ? readSession(tabStorageKey) : randomId();
  var pageNonce = randomId();
  var contextGeneration = 0;
  var contextController = new AbortController();
  var activeXhrs = new Set();
  var switchPending = false;
  var switchUiState = null;
  var readyDone = false;
  var readyResolve;
  var readyPromise = new Promise(function (resolve) { readyResolve = resolve; });

  window.BIOMIE_TAB_CONTEXT_READY = readyPromise;

  function randomId() {
    if (window.crypto && window.crypto.getRandomValues) {
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return Array.from(bytes, function (byte) {
        return byte.toString(16).padStart(2, '0');
      }).join('');
    }
    return (Date.now().toString(16) + Math.random().toString(16).slice(2)
      + '00000000000000000000000000000000').slice(0, 32);
  }

  function validId(value) {
    return typeof value === 'string'
      && /^[a-f0-9]{32}$/i.test(value);
  }

  function readSession(key) {
    try { return sessionStorage.getItem(key) || ''; } catch (_) { return ''; }
  }

  function writeSession(key, value) {
    try { sessionStorage.setItem(key, value); } catch (_) {}
  }

  function contextFromUrl() {
    try {
      var value = new URL(window.location.href).searchParams.get('ctx');
      return validId(value) ? value.toLowerCase() : '';
    } catch (_) {
      return '';
    }
  }

  function sameOriginUrl(value) {
    try {
      var url = new URL(value, window.location.href);
      return url.origin === window.location.origin ? url : null;
    } catch (_) {
      return null;
    }
  }

  function isStaticPath(pathname) {
    return /\/(?:assets|images|companies)\//.test(pathname);
  }

  function isContextFreePath(pathname) {
    if (/\/(?:login|logout|select_empresa)\.php$/i.test(pathname)) return true;
    var normalized = String(pathname || '').toLowerCase();
    return contextFreeApiPaths.some(function (path) {
      return normalized.endsWith(path);
    });
  }

  function isContextFreeUrl(value) {
    var url = sameOriginUrl(value);
    return !!(url && isContextFreePath(url.pathname));
  }

  function isApiPath(value) {
    var url = sameOriginUrl(value);
    return !!(
      url
      && !isContextFreePath(url.pathname)
      && /\/api\/[^/]+\.php$/i.test(url.pathname)
    );
  }

  function urlWithContext(value, explicitContextId) {
    var selectedContext = validId(explicitContextId) ? explicitContextId : contextId;
    if (!selectedContext) return value;
    var url = sameOriginUrl(value);
    if (!url || isStaticPath(url.pathname) || isContextFreePath(url.pathname)) return value;
    url.searchParams.set('ctx', selectedContext);
    return url.toString();
  }

  function historyUrlWithContext(value) {
    if (!contextId || value === undefined || value === null || value === '') return value;
    return urlWithContext(value, contextId);
  }

  function ensureAddressContext() {
    if (!contextId) return;
    var next = historyUrlWithContext(window.location.href);
    if (next && next !== window.location.href) {
      nativeReplaceState(history.state, '', next);
    }
  }

  function saveContext(value) {
    if (!validId(value)) return false;
    contextId = value.toLowerCase();
    writeSession(contextStorageKey, contextId);
    ensureAddressContext();
    return true;
  }

  function saveTabIdentity() {
    writeSession(tabStorageKey, tabId);
    var marker = 'biomie-tab-context:' + tabId;
    try {
      window.name = window.name
        .replace(/(?:^|\|)biomie-tab-context:[a-f0-9]{32}(?=\||$)/ig, '')
        .replace(/^\||\|$/g, '');
      window.name = window.name ? window.name + '|' + marker : marker;
    } catch (_) {}
  }

  function resetInvalidContext(redirect) {
    try {
      sessionStorage.removeItem(contextStorageKey);
      sessionStorage.removeItem(tabStorageKey);
    } catch (_) {}
    var target = sameOriginUrl(redirect || 'select_empresa.php?context_reset=1');
    if (target) window.location.replace(target.toString());
  }

  function handleInvalidResponse(response) {
    if (
      response
      && response.headers.get('X-BIOMIE-Context-Invalid') === '1'
    ) {
      resetInvalidContext(response.headers.get('X-BIOMIE-Context-Redirect') || '');
      var error = new Error('O contexto desta aba expirou ou foi revogado.');
      error.code = 'BIOMIE_CONTEXT_INVALID';
      throw error;
    }
    return response;
  }

  function staleContextError(reason) {
    var error = new Error(reason || 'Resposta descartada porque o contexto da aba mudou.');
    error.name = 'AbortError';
    error.code = 'BIOMIE_STALE_CONTEXT';
    error.silent = true;
    return error;
  }

  function responseMetaContext(payload) {
    if (!payload || typeof payload !== 'object') return null;
    var meta = payload.meta;
    if (!meta || typeof meta !== 'object' || !Object.prototype.hasOwnProperty.call(meta, 'context_id')) {
      return null;
    }
    return String(meta.context_id || '');
  }

  function acceptPayload(payload, expectedContextId, expectedGeneration) {
    var expectedId = validId(expectedContextId) ? expectedContextId : contextId;
    var expectedGen = Number.isFinite(Number(expectedGeneration))
      ? Number(expectedGeneration)
      : contextGeneration;

    if (expectedGen !== contextGeneration || expectedId !== contextId) {
      throw staleContextError();
    }

    var responseContextId = responseMetaContext(payload);
    if (expectedId && responseContextId === null) {
      throw staleContextError('Resposta sem meta.context_id foi descartada.');
    }
    if (responseContextId !== null && responseContextId !== expectedId) {
      throw staleContextError('Resposta de outro contexto foi descartada.');
    }
    return payload;
  }

  function wrapJsonValidation(response, requestMeta) {
    if (!response || !requestMeta.validatePayload || typeof response.json !== 'function') {
      return response;
    }

    var responseHeaderContext = response.headers
      ? String(response.headers.get('X-BIOMIE-Context') || '')
      : '';
    if (!responseHeaderContext) {
      throw staleContextError('Resposta HTTP sem X-BIOMIE-Context foi descartada.');
    }
    if (responseHeaderContext !== requestMeta.contextId) {
      throw staleContextError('Resposta HTTP de outro contexto foi descartada.');
    }

    var originalJson = response.json.bind(response);
    response.json = function () {
      return originalJson().then(function (payload) {
        return acceptPayload(payload, requestMeta.contextId, requestMeta.generation);
      });
    };

    function validateBodyGeneration(value, parseJson) {
      if (
        requestMeta.generation !== contextGeneration
        || requestMeta.contextId !== contextId
      ) {
        throw staleContextError();
      }
      if (parseJson) {
        var payload;
        try {
          payload = JSON.parse(String(value || 'null'));
        } catch (_) {
          throw staleContextError('Resposta JSON inválida foi descartada.');
        }
        acceptPayload(payload, requestMeta.contextId, requestMeta.generation);
      }
      return value;
    }

    if (typeof response.text === 'function') {
      var originalText = response.text.bind(response);
      response.text = function () {
        var contentType = response.headers
          ? String(response.headers.get('content-type') || '').toLowerCase()
          : '';
        return originalText().then(function (value) {
          return validateBodyGeneration(value, contentType.indexOf('application/json') !== -1);
        });
      };
    }
    ['blob', 'arrayBuffer', 'formData'].forEach(function (method) {
      if (typeof response[method] !== 'function') return;
      var original = response[method].bind(response);
      response[method] = function () {
        return original().then(function (value) {
          return validateBodyGeneration(value, false);
        });
      };
    });
    return response;
  }

  function mergeSignals(signals) {
    var available = signals.filter(function (signal, index, all) {
      return signal && all.indexOf(signal) === index;
    });
    if (!available.length) return { signal: undefined, cleanup: function () {} };
    if (available.length === 1) return { signal: available[0], cleanup: function () {} };

    var controller = new AbortController();
    var listeners = [];
    var abortFrom = function (signal) {
      if (!controller.signal.aborted) {
        controller.abort(signal.reason || new DOMException('aborted', 'AbortError'));
      }
    };
    available.forEach(function (signal) {
      if (signal.aborted) {
        abortFrom(signal);
        return;
      }
      var listener = function () { abortFrom(signal); };
      signal.addEventListener('abort', listener, { once: true });
      listeners.push([signal, listener]);
    });
    return {
      signal: controller.signal,
      cleanup: function () {
        listeners.forEach(function (entry) {
          entry[0].removeEventListener('abort', entry[1]);
        });
      }
    };
  }

  function beginContextGeneration() {
    contextGeneration += 1;
    contextController.abort(new DOMException('context-superseded', 'AbortError'));
    contextController = new AbortController();
    activeXhrs.forEach(function (xhr) {
      try { xhr.abort(); } catch (_) {}
    });
    activeXhrs.clear();
    return contextGeneration;
  }

  function contextBoundNodes() {
    return Array.prototype.slice.call(document.querySelectorAll(
      '[data-context-bound], main.page > section:not(.biomie-page-header):not(.dashboard-controls),'
      + ' .page.dashboard-shell > section:not(.biomie-page-header):not(.dashboard-controls)'
    ));
  }

  function setSelectorsBusy(busy, selectedElement) {
    var selectors = Array.prototype.slice.call(document.querySelectorAll(
      '[data-context-switch], [data-sidebar-tenant], .se-card, .se-consolidated-card'
    ));
    if (busy) {
      return selectors.map(function (element) {
        var state = {
          element: element,
          disabled: 'disabled' in element ? !!element.disabled : null,
          ariaDisabled: element.getAttribute('aria-disabled')
        };
        if ('disabled' in element) element.disabled = true;
        element.setAttribute('aria-disabled', 'true');
        element.setAttribute('aria-busy', element === selectedElement ? 'true' : 'false');
        element.classList.toggle('is-loading', element === selectedElement);
        return state;
      });
    }
    return [];
  }

  function ensureContextSkeleton() {
    var existing = document.getElementById('pageLoading');
    if (existing) {
      var wasHidden = existing.classList.contains('d-none');
      existing.classList.remove('d-none');
      return { element: existing, generated: false, wasHidden: wasHidden };
    }

    var page = document.querySelector('main.page, main.dashboard-shell, .mag-app-main .page');
    if (!page) return null;
    var skeleton = document.createElement('div');
    skeleton.className = 'biomie-context-switch-skeleton';
    skeleton.setAttribute('role', 'status');
    skeleton.setAttribute('aria-live', 'polite');
    skeleton.setAttribute('aria-label', 'Validando novo contexto');
    skeleton.innerHTML = '<span class="skeleton-block"></span>'
      + '<span class="skeleton-block"></span><span class="skeleton-block"></span>';
    var header = page.querySelector('.biomie-page-header');
    if (header && header.nextSibling) page.insertBefore(skeleton, header.nextSibling);
    else page.insertBefore(skeleton, page.firstChild);
    return { element: skeleton, generated: true, wasHidden: false };
  }

  function beginSwitchUi(selectedElement, targetName) {
    var nodes = contextBoundNodes();
    var contextNodeStates = nodes.map(function (node) {
      var state = {
        node: node,
        ariaBusy: node.getAttribute('aria-busy')
      };
      node.classList.add('biomie-context-stale');
      node.setAttribute('aria-busy', 'true');
      return state;
    });
    document.body.classList.add('biomie-context-switching');
    var state = {
      selectors: setSelectorsBusy(true, selectedElement),
      contextNodes: contextNodeStates,
      skeleton: ensureContextSkeleton()
    };
    document.dispatchEvent(new CustomEvent('biomie:context-switch-start', {
      detail: {
        generation: contextGeneration,
        previousContextId: contextId,
        targetName: String(targetName || '')
      }
    }));
    return state;
  }

  function restoreSwitchUi(state) {
    if (!state) return;
    state.selectors.forEach(function (saved) {
      if (saved.disabled !== null) saved.element.disabled = saved.disabled;
      if (saved.ariaDisabled === null) saved.element.removeAttribute('aria-disabled');
      else saved.element.setAttribute('aria-disabled', saved.ariaDisabled);
      saved.element.removeAttribute('aria-busy');
      saved.element.classList.remove('is-loading');
    });
    state.contextNodes.forEach(function (saved) {
      var node = saved.node;
      node.classList.remove('biomie-context-stale');
      if (saved.ariaBusy === null) node.removeAttribute('aria-busy');
      else node.setAttribute('aria-busy', saved.ariaBusy);
    });
    if (state.skeleton) {
      if (state.skeleton.generated) state.skeleton.element.remove();
      else if (state.skeleton.wasHidden) state.skeleton.element.classList.add('d-none');
    }
    document.body.classList.remove('biomie-context-switching');
  }

  function normalizeSwitchTarget(target) {
    target = target && typeof target === 'object' ? target : {};
    var type = String(target.target_type || target.type || '').toLowerCase();
    if (type !== 'company' && type !== 'group') {
      throw new Error('Tipo de contexto inválido.');
    }
    var payload = { target_type: type };
    if (type === 'company') {
      var companyId = Number(target.company_id);
      if (!Number.isInteger(companyId) || companyId <= 0) {
        throw new Error('Empresa inválida.');
      }
      payload.company_id = companyId;
      var businessScope = String(target.business_scope || target.canal || '').toLowerCase();
      if (businessScope === 'varejo' || businessScope === 'licitacao') {
        payload.business_scope = businessScope;
      }
    } else if (Array.isArray(target.company_ids) && target.company_ids.length) {
      payload.company_ids = target.company_ids.map(Number).filter(function (id) {
        return Number.isInteger(id) && id > 0;
      });
    }
    return payload;
  }

  function parseSwitchResponse(response) {
    return response.json().catch(function () {
      return { ok: false, message: 'Resposta inválida ao trocar o contexto.' };
    }).then(function (data) {
      if (!response.ok || !data || data.ok !== true) {
        var message = data && data.message
          ? data.message
          : 'Não foi possível alterar o contexto.';
        var error = new Error(message);
        error.code = response.status || 500;
        error.requestId = data && data.request_id ? data.request_id : '';
        throw error;
      }
      if (!data.context || !validId(data.context.id)) {
        throw new Error('O servidor não devolveu um contexto válido.');
      }
      data.context.id = data.context.id.toLowerCase();
      return data;
    });
  }

  function switchContext(target, options) {
    options = options || {};
    if (!nativeFetch) return Promise.reject(new Error('Navegador sem suporte à troca de contexto.'));
    if (switchPending) return Promise.reject(new Error('Uma troca de contexto já está em andamento.'));

    var payload;
    try {
      payload = normalizeSwitchTarget(target);
    } catch (error) {
      return Promise.reject(error);
    }

    var previousContextId = contextId;
    var selectedElement = options.selectedElement || null;
    var targetName = options.targetName || '';
    var csrf = (document.querySelector('meta[name="biomie-csrf"]') || {}).content || options.csrf || '';
    var switchGeneration = beginContextGeneration();
    switchPending = true;
    switchUiState = beginSwitchUi(selectedElement, targetName);
    if (csrf) payload.csrf_token = csrf;

    var headers = new Headers({
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-Requested-With': 'fetch'
    });
    if (csrf) headers.set('X-CSRF-Token', csrf);
    if (previousContextId) headers.set('X-BIOMIE-Context', previousContextId);

    return nativeFetch('api/context_switch.php', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: headers,
      body: JSON.stringify(payload)
    })
      .then(handleInvalidResponse)
      .then(parseSwitchResponse)
      .then(function (data) {
        if (switchGeneration !== contextGeneration) throw staleContextError();
        var targetUrl = null;
        if (options.redirect !== false) {
          targetUrl = sameOriginUrl(data.redirect_url || window.location.href);
          if (!targetUrl) throw new Error('Destino de contexto inválido.');
          targetUrl.searchParams.set('ctx', data.context.id);
        }

        // A partir daqui o servidor confirmou o novo contexto imutável.
        beginContextGeneration();
        saveContext(data.context.id);
        document.dispatchEvent(new CustomEvent('biomie:context-switch-success', {
          detail: {
            previousContextId: previousContextId,
            context: data.context,
            redirectUrl: data.redirect_url || ''
          }
        }));

        if (options.redirect !== false) {
          window.location.assign(targetUrl.toString());
        } else {
          restoreSwitchUi(switchUiState);
          switchUiState = null;
          switchPending = false;
        }
        return data;
      })
      .catch(function (error) {
        // Nenhuma confirmação: ctx anterior permanece como fonte da aba.
        if (!error || error.code !== 'BIOMIE_CONTEXT_INVALID') {
          contextId = previousContextId;
          if (previousContextId) {
            writeSession(contextStorageKey, previousContextId);
            ensureAddressContext();
          }
        } else {
          contextId = '';
        }
        restoreSwitchUi(switchUiState);
        switchUiState = null;
        switchPending = false;
        document.dispatchEvent(new CustomEvent('biomie:context-switch-error', {
          detail: {
            previousContextId: previousContextId,
            targetName: String(targetName || ''),
            message: error && error.message ? error.message : 'Falha ao trocar o contexto.'
          }
        }));
        throw error;
      });
  }

  history.replaceState = function (state, title, url) {
    return nativeReplaceState(state, title, historyUrlWithContext(url));
  };
  history.pushState = function (state, title, url) {
    return nativePushState(state, title, historyUrlWithContext(url));
  };

  if (nativeFetch) {
    window.fetch = function (input, init) {
      var inputUrl = typeof Request !== 'undefined' && input instanceof Request ? input.url : input;
      if (!sameOriginUrl(inputUrl)) return nativeFetch(input, init);

      return readyPromise.then(function () {
        var expectedContextId = contextId;
        var expectedGeneration = contextGeneration;
        var contextFreeRequest = isContextFreeUrl(inputUrl);
        var options = Object.assign({}, init || {});
        var sourceHeaders = options.headers;
        var inputSignal = null;
        if (typeof Request !== 'undefined' && input instanceof Request) {
          if (!sourceHeaders) sourceHeaders = input.headers;
          inputSignal = input.signal;
        }
        var headers = new Headers(sourceHeaders || {});
        if (expectedContextId && !contextFreeRequest) {
          headers.set('X-BIOMIE-Context', expectedContextId);
        }
        if (!contextFreeRequest) {
          headers.set('X-BIOMIE-Context-Generation', String(expectedGeneration));
        }
        options.headers = headers;
        options.credentials = options.credentials || 'same-origin';

        var signals = mergeSignals([
          options.signal,
          inputSignal,
          contextFreeRequest ? null : contextController.signal
        ]);
        options.signal = signals.signal;

        var decoratedUrl = urlWithContext(inputUrl, expectedContextId);
        var requestInput = input;
        if (typeof Request !== 'undefined' && input instanceof Request) {
          if (decoratedUrl !== input.url) requestInput = new Request(decoratedUrl, input);
        } else {
          requestInput = decoratedUrl;
        }
        var request = typeof Request !== 'undefined' && requestInput instanceof Request
          ? new Request(requestInput, options)
          : requestInput;
        var requestMeta = {
          contextId: expectedContextId,
          generation: expectedGeneration,
          validatePayload: !!(expectedContextId && isApiPath(inputUrl))
        };

        var promise = request === requestInput
          ? nativeFetch(request, options)
          : nativeFetch(request);
        return promise
          .then(handleInvalidResponse)
          .then(function (response) {
            if (
              !contextFreeRequest
              && (
                expectedGeneration !== contextGeneration
                || expectedContextId !== contextId
              )
            ) {
              throw staleContextError();
            }
            return wrapJsonValidation(response, requestMeta);
          })
          .finally(signals.cleanup);
      });
    };
  }

  if (nativeOpen && nativeSend) {
    window.XMLHttpRequest.prototype.open = function (method, url, async) {
      this.__biomieSameOrigin = sameOriginUrl(url) !== null;
      this.__biomieAsync = async !== false;
      this.__biomieContextFree = isContextFreeUrl(url);
      this.__biomieContextId = this.__biomieContextFree ? '' : contextId;
      this.__biomieContextGeneration = contextGeneration;
      this.__biomieValidateContext = !!(
        this.__biomieContextId
        && isApiPath(url)
      );
      var args = Array.prototype.slice.call(arguments);
      if (this.__biomieSameOrigin) args[1] = urlWithContext(url, contextId);
      var result = nativeOpen.apply(this, args);
      if (this.__biomieValidateContext) {
        var xhr = this;
        xhr.addEventListener('load', function (event) {
          var stale = xhr.__biomieContextGeneration !== contextGeneration
            || xhr.__biomieContextId !== contextId;
          var headerContext = '';
          try { headerContext = String(xhr.getResponseHeader('X-BIOMIE-Context') || ''); } catch (_) {}
          if (!headerContext || headerContext !== xhr.__biomieContextId) stale = true;

          if (!stale) {
            try {
              var payload = xhr.responseType === 'json'
                ? xhr.response
                : JSON.parse(xhr.responseText || 'null');
              acceptPayload(
                payload,
                xhr.__biomieContextId,
                xhr.__biomieContextGeneration
              );
            } catch (_) {
              stale = true;
            }
          }

          if (stale) {
            xhr.__biomieStaleContext = true;
            if (event && typeof event.stopImmediatePropagation === 'function') {
              event.stopImmediatePropagation();
            }
            try { xhr.abort(); } catch (_) {}
            document.dispatchEvent(new CustomEvent('biomie:context-response-discarded', {
              detail: { transport: 'xhr' }
            }));
          }
        }, true);
      }
      return result;
    };
    window.XMLHttpRequest.prototype.send = function () {
      var xhr = this;
      var args = arguments;
      var sendNow = function () {
        if (xhr.__biomieContextId && xhr.__biomieSameOrigin) {
          try { xhr.setRequestHeader('X-BIOMIE-Context', xhr.__biomieContextId); } catch (_) {}
        }
        if (xhr.__biomieSameOrigin && !xhr.__biomieContextFree) {
          try {
            xhr.setRequestHeader(
              'X-BIOMIE-Context-Generation',
              String(xhr.__biomieContextGeneration)
            );
          } catch (_) {}
          activeXhrs.add(xhr);
          xhr.addEventListener('loadend', function () { activeXhrs.delete(xhr); }, { once: true });
        }
        return nativeSend.apply(xhr, args);
      };
      if (xhr.__biomieSameOrigin && xhr.__biomieAsync && !readyDone) {
        readyPromise.then(sendNow);
        return;
      }
      return sendNow();
    };
  }

  if (window.EventSource) {
    var NativeEventSource = window.EventSource;
    var WrappedEventSource = function (url, config) {
      return new NativeEventSource(urlWithContext(url), config);
    };
    WrappedEventSource.prototype = NativeEventSource.prototype;
    try { Object.setPrototypeOf(WrappedEventSource, NativeEventSource); } catch (_) {}
    ['CONNECTING', 'OPEN', 'CLOSED'].forEach(function (key) {
      if (key in NativeEventSource) {
        try { Object.defineProperty(WrappedEventSource, key, { value: NativeEventSource[key] }); } catch (_) {}
      }
    });
    window.EventSource = WrappedEventSource;
  }

  if (navigator.sendBeacon) {
    var nativeBeacon = navigator.sendBeacon.bind(navigator);
    navigator.sendBeacon = function (url, data) {
      return nativeBeacon(urlWithContext(url), data);
    };
  }

  function decorateAnchor(anchor) {
    if (!anchor || !anchor.getAttribute || !contextId) return;
    var raw = anchor.getAttribute('href');
    if (!raw || raw.charAt(0) === '#' || /^(?:mailto:|tel:|javascript:)/i.test(raw)) return;
    var decorated = urlWithContext(raw);
    if (decorated !== raw) anchor.setAttribute('href', decorated);
  }

  function decorateLinks(root) {
    if (!contextId || !root || !root.querySelectorAll) return;
    if (root.matches && root.matches('a[href]')) decorateAnchor(root);
    root.querySelectorAll('a[href]').forEach(decorateAnchor);
  }

  ['pointerdown', 'mousedown', 'auxclick', 'contextmenu', 'click'].forEach(function (eventName) {
    document.addEventListener(eventName, function (event) {
      var anchor = event.target.closest && event.target.closest('a[href]');
      decorateAnchor(anchor);
    }, true);
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!contextId || !form || form.nodeName !== 'FORM') return;

    // Nunca usar form.action: um input name="action" sobrescreve a propriedade.
    var action = form.getAttribute('action') || window.location.href;
    if (!sameOriginUrl(action)) return;
    var field = form.querySelector('input[name="ctx"]');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = 'ctx';
      form.appendChild(field);
    }
    field.value = contextId;
    form.setAttribute('action', urlWithContext(action));
  }, true);

  function detectTabCollision() {
    if (!window.BroadcastChannel) return Promise.resolve(false);
    return new Promise(function (resolve) {
      var collision = false;
      var channel = new BroadcastChannel(channelName);
      channel.addEventListener('message', function (event) {
        var data = event.data || {};
        if (data.tab_id !== tabId || data.nonce === pageNonce) return;
        if (data.type === 'probe') {
          channel.postMessage({
            type: 'present',
            tab_id: tabId,
            nonce: pageNonce,
            target: data.nonce
          });
        } else if (data.type === 'present' && data.target === pageNonce) {
          collision = true;
        }
      });
      channel.postMessage({ type: 'probe', tab_id: tabId, nonce: pageNonce });
      window.setTimeout(function () {
        window.__BIOMIE_TAB_PRESENCE_CHANNEL__ = channel;
        resolve(collision);
      }, 120);
    });
  }

  window.BIOMIE_CONTEXT = {
    version: '1.0.0',
    ready: readyPromise,
    currentId: function () { return contextId || ''; },
    generation: function () { return contextGeneration; },
    signal: function () { return contextController.signal; },
    isSwitching: function () { return switchPending; },
    url: function (value) { return urlWithContext(value); },
    acceptPayload: acceptPayload,
    switchContext: switchContext,
    abortCurrent: beginContextGeneration
  };

  writeSession(tabStorageKey, tabId);
  if (contextId) {
    writeSession(contextStorageKey, contextId);
    ensureAddressContext();
  }

  detectTabCollision()
    .then(function (collision) {
      if (collision) {
        // A aba duplicada mantém o ctx imutável; só sua identidade local muda.
        tabId = randomId();
        writeSession(tabStorageKey, tabId);
      }
      saveTabIdentity();
    })
    .catch(function () {})
    .finally(function () {
      readyDone = true;
      readyResolve(contextId || null);
      ensureAddressContext();
      decorateLinks(document);
      if (window.MutationObserver) {
        new MutationObserver(function (mutations) {
          mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
              if (node.nodeType === 1) decorateLinks(node);
            });
          });
        }).observe(document.documentElement, { childList: true, subtree: true });
      }
    });
}());
