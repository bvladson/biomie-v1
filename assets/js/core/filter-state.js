/**
 * BIomie — FilterState (Fase Q.1)
 *
 * Pure helpers for managing filter state as plain objects.
 * State is always immutable — every mutation returns a new object.
 * Exposed as window.BIomie.FS.
 */
(function () {
  'use strict';

  var B = window.BIomie = window.BIomie || {};

  B.FS = {
    /**
     * Creates a new filter state, merging optional defaults.
     * @param {object} [defaults]
     * @returns {object}
     */
    make: function (defaults) {
      return Object.assign({
        q:         '',
        scope:     'commercial',
        start:     '',
        end:       '',
        sort:      'emissao',
        direction: 'desc',
        page:      1,
        per_page:  50,
        filters:   {},
      }, defaults || {});
    },

    /** Returns true when any user-visible filter is active. */
    hasActive: function (state) {
      if (state.q) return true;
      return state.filters && Object.keys(state.filters).length > 0;
    },

    /** Returns a new state with q changed and page reset to 1. */
    setQ: function (state, q) {
      return Object.assign({}, state, { q: q || '', page: 1 });
    },

    /** Returns a new state with a column-level filter set (page reset to 1). */
    setFilter: function (state, field, op, val) {
      var filters = Object.assign({}, state.filters || {});
      if (val === null || val === undefined || val === '') {
        delete filters[field];
      } else {
        filters[field] = { op: op || 'contains', val: val };
      }
      return Object.assign({}, state, { filters: filters, page: 1 });
    },

    /** Returns a new state with a column-level filter removed. */
    removeFilter: function (state, field) {
      var filters = Object.assign({}, state.filters || {});
      delete filters[field];
      return Object.assign({}, state, { filters: filters, page: 1 });
    },

    /** Returns a new state with sort changed; page resets to 1. */
    setSort: function (state, field, dir) {
      return Object.assign({}, state, { sort: field, direction: dir, page: 1 });
    },

    /** Returns a new state with page changed; all other state preserved. */
    setPage: function (state, page) {
      return Object.assign({}, state, { page: page });
    },

    /** Returns the op value for a given filter field ('contains' by default). */
    filterOp: function (state, field) {
      return state.filters && state.filters[field] ? state.filters[field].op || 'contains' : 'contains';
    },

    /** Returns the val for a given filter field (null if not set). */
    filterVal: function (state, field) {
      return state.filters && state.filters[field] ? state.filters[field].val : null;
    },
  };
}());
