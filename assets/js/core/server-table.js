/**
 * BIomie — ServerTable (Fase Q.1)
 *
 * Creates a server-side table controller that coordinates:
 *   fetch + abort, debounce, FilterState, URL sync, chip rendering.
 *
 * Usage:
 *   var table = B.ServerTable.create({
 *     endpoint:        'api/orders_search.php',
 *     feedbackId:      'nfSearchFeedback',
 *     chipsContainerId: 'nfActiveFilters',
 *     chipsForState:   function(state) { return [{key:'q', label:'Busca: ' + state.q}]; },
 *     onSuccess:       function(json, state) { renderRows(json.data); },
 *     onClear:         function() { restoreOriginalView(); },
 *     debounceMs:      350,
 *   });
 *
 *   table.trigger(newState);          // fetch with new state, update URL + chips
 *   table.triggerDebounced(newState); // same but debounced
 *   table.clear();                    // abort, clear URL + chips, call onClear
 *   table.setSort(field, dir);        // update sort and re-fetch if active
 *   table.getState();                 // returns current state clone
 *   table.isActive();                 // true when any filter is active
 *
 * Exposed as window.BIomie.ServerTable.
 */
(function () {
  'use strict';

  var B = window.BIomie = window.BIomie || {};

  function esc(v) {
    return B.escapeHtml
      ? B.escapeHtml(v)
      : String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
  }

  B.ServerTable = {
    create: function (config) {
      var FS     = B.FS;
      var FUrl   = B.FUrl;
      var FChips = B.FChips;
      var RC     = B.RC;

      if (!FS || !FUrl || !FChips || !RC) {
        console.error('BIomie.ServerTable: required modules not loaded (FS, FUrl, FChips, RC).');
        return null;
      }

      var rc       = RC.create();
      var state    = FS.make({ scope: config.defaultScope || 'commercial' });
      var isActive = false;

      // Cached debounced trigger — recreated only when debounceMs changes
      var _debouncedTrigger = rc.debounce(function (st) {
        ctrl.trigger(st, 1);
      }, config.debounceMs || 350);

      function showFeedback(html) {
        var el = config.feedbackId ? document.getElementById(config.feedbackId) : null;
        if (!el) return;
        el.style.display = html ? '' : 'none';
        el.innerHTML = html || '';
      }

      function updateChips() {
        if (!config.chipsForState || !config.chipsContainerId) return;
        var chips = config.chipsForState(state);
        FChips.render(config.chipsContainerId, chips, function (key) {
          if (key === '__all__') { ctrl.clear(); return; }
          var next = key === 'q'
            ? FS.setQ(state, '')
            : FS.removeFilter(state, key);
          ctrl.trigger(next, 1);
        });
      }

      function doFetch(st) {
        var signal = rc.signal();
        showFeedback('');

        fetch(config.endpoint + '?' + FUrl.toQueryString(st), {
          credentials: 'same-origin',
          signal:      signal,
        })
          .then(function (r) { return r.json(); })
          .then(function (json) {
            if (!json.ok) {
              var msg = (json.error && json.error.message)
                ? json.error.message
                : 'Não foi possível aplicar a busca agora. Os resultados anteriores foram mantidos.';
              showFeedback(
                '<div class="biomie-table-feedback biomie-table-feedback--error">' +
                '<span class="material-symbols-rounded" style="font-size:18px;flex-shrink:0">error_outline</span>' +
                esc(msg) + '</div>'
              );
              return;
            }
            if (config.onSuccess) config.onSuccess(json, st);
          })
          .catch(function (err) {
            if (err && err.name === 'AbortError') return;
            showFeedback(
              '<div class="biomie-table-feedback biomie-table-feedback--error">' +
              '<span class="material-symbols-rounded" style="font-size:18px;flex-shrink:0">wifi_off</span>' +
              'Sem conexão — os resultados anteriores foram mantidos.</div>'
            );
          });
      }

      var ctrl = {
        /**
         * Triggers an immediate fetch with the given state.
         * @param {object} newState
         * @param {number} [page]  If provided, overrides state.page.
         */
        trigger: function (newState, page) {
          state    = FS.make(newState);
          if (page !== undefined) state = FS.setPage(state, page);
          isActive = FS.hasActive(state);

          if (!isActive) { ctrl.clear(); return; }

          FUrl.push(state);
          updateChips();
          doFetch(state);
        },

        /** Triggers a debounced fetch — useful for keyup events. */
        triggerDebounced: function (newState) {
          // Store the new state immediately; debounce fires trigger
          state = FS.make(newState);
          _debouncedTrigger(state);
        },

        /** Aborts in-flight request, clears URL + chips, calls onClear. */
        clear: function () {
          rc.abort();
          rc.cancelDebounce();
          isActive = false;
          state    = FS.make({ scope: config.defaultScope || 'commercial' });
          FUrl.clear();
          FChips.clear(config.chipsContainerId || '');
          showFeedback('');
          if (config.onClear) config.onClear();
        },

        /** Updates sort and re-fetches if currently active. */
        setSort: function (field, dir) {
          state = FS.setSort(state, field, dir);
          if (isActive) ctrl.trigger(state, state.page);
        },

        /** Returns a shallow clone of the current filter state. */
        getState: function () { return Object.assign({}, state); },

        /** Whether any filter is currently active. */
        isActive: function () { return isActive; },
      };

      return ctrl;
    },
  };
}());
