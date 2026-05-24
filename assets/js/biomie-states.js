/**
 * BIomie — Global state manager for dynamic UI regions.
 * Renders EmptyState, ErrorState, LoadingState in JS-managed containers.
 * Import: <script src="assets/js/biomie-states.js"></script>
 */
(function () {
  'use strict';

  var _cbRegistry = {};
  var _cbSeq = 0;

  // Single delegated click handler for retry/action buttons
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest('[data-biomie-cb]');
    if (!btn) return;
    var id = btn.getAttribute('data-biomie-cb');
    if (_cbRegistry[id]) {
      _cbRegistry[id]();
      delete _cbRegistry[id];
    }
  });

  var STATE_TYPES = {
    empty: { css: 'mag-state-empty mag-state-severity--info', icon: 'search_off', role: 'status' },
    error: { css: 'mag-state-error mag-state-severity--danger', icon: 'error_outline', role: 'alert' },
    loading: { css: 'mag-state-loading', icon: '', role: 'status' },
    sync_required: { css: 'mag-state-warning', icon: 'cloud_off', role: 'status' },
    migration_required: { css: 'mag-state-warning', icon: 'database', role: 'status' },
    materialization_required: { css: 'mag-state-warning', icon: 'build_circle', role: 'status' },
    permission_denied: { css: 'mag-state-permission', icon: 'lock', role: 'status' }
  };

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        if (key === 'className') node.className = attrs[key];
        else node.setAttribute(key, attrs[key]);
      });
    }
    if (typeof children === 'string') node.innerHTML = children;
    else if (Array.isArray(children)) children.forEach(function (c) { if (c) node.appendChild(c); });
    return node;
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function renderState(container, state) {
    if (!container) return;
    var type = (state && state.type) || 'empty';
    var config = STATE_TYPES[type] || STATE_TYPES.empty;

    container.setAttribute('aria-busy', 'false');

    if (type === 'loading') {
      container.setAttribute('aria-busy', 'true');
      container.innerHTML =
        '<div class="mag-state mag-state-loading" role="status" aria-live="polite" aria-busy="true">' +
          '<div class="mag-loading-content">' +
            '<div class="mag-loading-spinner"></div>' +
            '<p class="mag-state-message">' + esc(state.message || 'Carregando dados...') + '</p>' +
          '</div>' +
          '<div class="mag-skeleton mag-skeleton-table">' +
            '<div class="mag-skeleton-row"><span class="mag-skeleton-line" style="width:65%"></span><span class="mag-skeleton-line" style="width:20%"></span></div>' +
            '<div class="mag-skeleton-row"><span class="mag-skeleton-line" style="width:55%"></span><span class="mag-skeleton-line" style="width:25%"></span></div>' +
            '<div class="mag-skeleton-row"><span class="mag-skeleton-line" style="width:60%"></span><span class="mag-skeleton-line" style="width:18%"></span></div>' +
          '</div>' +
        '</div>';
      return;
    }

    var icon = state.icon || config.icon;
    var compact = state.compact ? ' mag-state-compact' : '';
    var actionHtml = '';
    if (state.action_label && state.action_href) {
      actionHtml = '<div class="mag-state-actions"><a class="btn-secondary mag-button-secondary" href="' + esc(state.action_href) + '">' + esc(state.action_label) + '</a></div>';
    } else if (state.action_label && state.action_fn) {
      var cbId = 'bs_' + (++_cbSeq);
      _cbRegistry[cbId] = state.action_fn;
      actionHtml = '<div class="mag-state-actions"><button type="button" class="btn-secondary mag-button-secondary" data-biomie-cb="' + cbId + '">' + esc(state.action_label) + '</button></div>';
    }

    container.innerHTML =
      '<div class="mag-state ' + config.css + compact + '" role="' + config.role + '">' +
        (icon ? '<span class="material-symbols-rounded mag-state-icon">' + esc(icon) + '</span>' : '') +
        '<strong class="mag-state-title">' + esc(state.title || '') + '</strong>' +
        '<p class="mag-state-message">' + esc(state.message || '') + '</p>' +
        actionHtml +
      '</div>';
  }

  function setLoading(container, message) {
    renderState(container, { type: 'loading', message: message || 'Carregando dados...' });
  }

  function setEmpty(container, title, message, action) {
    var state = { type: 'empty', title: title || 'Nenhum dado encontrado', message: message || '' };
    if (action) {
      state.action_label = action.label;
      if (action.fn)   state.action_fn   = action.fn;
      if (action.href) state.action_href = action.href;
    }
    renderState(container, state);
  }

  function setError(container, title, message, retryFn) {
    var state = { type: 'error', title: title || 'Erro ao carregar', message: message || 'Nao foi possivel carregar esta informacao agora.' };
    if (retryFn) { state.action_label = 'Tentar novamente'; state.action_fn = retryFn; }
    renderState(container, state);
  }

  function clearState(container) {
    if (!container) return;
    container.innerHTML = '';
    container.setAttribute('aria-busy', 'false');
  }

  function setBusy(container, isBusy) {
    if (!container) return;
    container.setAttribute('aria-busy', isBusy ? 'true' : 'false');
  }

  /**
   * Render empty state inside a tbody when it has no rows.
   * Reads data-empty-title, data-empty-message, data-empty-icon from the tbody.
   */
  function renderTableEmptyState(tbody) {
    if (!tbody || tbody.children.length > 0) return;
    var title = tbody.getAttribute('data-empty-title') || 'Sem dados';
    var message = tbody.getAttribute('data-empty-message') || 'Nenhum dado encontrado para os filtros selecionados.';
    var icon = tbody.getAttribute('data-empty-icon') || 'search_off';
    var colCount = 1;
    var thead = tbody.closest('table') && tbody.closest('table').querySelector('thead tr');
    if (thead) colCount = thead.children.length || 1;

    var tr = document.createElement('tr');
    var td = document.createElement('td');
    td.setAttribute('colspan', String(colCount));
    td.className = 'mag-empty-cell';
    td.innerHTML =
      '<div class="mag-state mag-state-empty mag-state-compact mag-state--neutral mag-state-severity--info" role="status">' +
        '<span class="material-symbols-rounded mag-state-icon">' + esc(icon) + '</span>' +
        '<strong class="mag-state-title">' + esc(title) + '</strong>' +
        '<p class="mag-state-message">' + esc(message) + '</p>' +
      '</div>';
    tr.appendChild(td);
    tbody.appendChild(tr);
  }

  // Expose globally
  window.BIOMIE_STATES = {
    render: renderState,
    setLoading: setLoading,
    setEmpty: setEmpty,
    setError: setError,
    clearState: clearState,
    setBusy: setBusy,
    renderTableEmptyState: renderTableEmptyState
  };
})();
