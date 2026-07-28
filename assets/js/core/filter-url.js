/**
 * BIomie — FilterUrl (Fase Q.1)
 *
 * Bidirectional sync between FilterState and URLSearchParams.
 * Does NOT navigate — uses history.replaceState only.
 * Exposed as window.BIomie.FUrl.
 */
(function () {
  'use strict';

  var B = window.BIomie = window.BIomie || {};

  var SCALAR_KEYS = ['q', 'scope', 'start', 'end', 'sort', 'direction', 'page', 'per_page'];
  var FILTER_PREFIX = 'filters[';

  B.FUrl = {
    /**
     * Reflects the filter state in the URL (replaceState — no navigation).
     * @param {object} state FilterState plain object
     */
    push: function (state) {
      if (!window.history || !history.replaceState) return;
      var url = new URL(location.href);
      var sp  = url.searchParams;

      // Scalars
      SCALAR_KEYS.forEach(function (k) {
        var v = state[k];
        if (v !== undefined && v !== '' && v !== null) { sp.set(k, String(v)); }
        else { sp.delete(k); }
      });

      // Remove all existing filter params, then re-add
      var toDelete = [];
      sp.forEach(function (v, k) { if (k.indexOf(FILTER_PREFIX) === 0) toDelete.push(k); });
      toDelete.forEach(function (k) { sp.delete(k); });

      if (state.filters) {
        Object.keys(state.filters).forEach(function (field) {
          var f = state.filters[field];
          if (f && f.val !== undefined && f.val !== null && String(f.val) !== '') {
            sp.set(FILTER_PREFIX + field + '][op]', f.op || 'contains');
            sp.set(FILTER_PREFIX + field + '][val]', String(f.val));
          }
        });
      }

      history.replaceState(null, '', url.toString());
    },

    /** Removes all filter-related params from the URL. */
    clear: function () {
      if (!window.history || !history.replaceState) return;
      var url = new URL(location.href);
      var sp  = url.searchParams;
      SCALAR_KEYS.forEach(function (k) { sp.delete(k); });
      var toDelete = [];
      sp.forEach(function (v, k) { if (k.indexOf(FILTER_PREFIX) === 0) toDelete.push(k); });
      toDelete.forEach(function (k) { sp.delete(k); });
      history.replaceState(null, '', url.toString());
    },

    /**
     * Reads the current URL and returns a FilterState-compatible plain object.
     * @returns {object}
     */
    read: function () {
      var sp = new URLSearchParams(location.search);
      var state = {
        q:         sp.get('q')         || '',
        scope:     sp.get('scope')     || 'commercial',
        start:     sp.get('start')     || '',
        end:       sp.get('end')       || '',
        sort:      sp.get('sort')      || 'emissao',
        direction: sp.get('direction') || 'desc',
        page:      parseInt(sp.get('page'),     10) || 1,
        per_page:  parseInt(sp.get('per_page'), 10) || 50,
        filters:   {},
      };
      // Parse filters[field][op|val]
      sp.forEach(function (val, key) {
        var m = key.match(/^filters\[([^\]]+)\]\[([^\]]+)\]$/);
        if (m) {
          var field = m[1], prop = m[2];
          if (!state.filters[field]) state.filters[field] = { op: 'contains', val: '' };
          state.filters[field][prop] = val;
        }
      });
      return state;
    },

    /**
     * Converts a FilterState to a URLSearchParams query string for fetch().
     * @param {object} state
     * @returns {string}
     */
    toQueryString: function (state) {
      var qs = new URLSearchParams();
      SCALAR_KEYS.forEach(function (k) {
        var v = state[k];
        if (v !== undefined && v !== '' && v !== null) qs.set(k, String(v));
      });
      if (state.filters) {
        Object.keys(state.filters).forEach(function (field) {
          var f = state.filters[field];
          if (f && f.val !== undefined && f.val !== null && String(f.val) !== '') {
            qs.set(FILTER_PREFIX + field + '][op]', f.op || 'contains');
            qs.set(FILTER_PREFIX + field + '][val]', String(f.val));
          }
        });
      }
      return qs.toString();
    },
  };
}());
