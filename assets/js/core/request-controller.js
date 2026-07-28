/**
 * BIomie — RequestController (Fase Q.1)
 *
 * Thin factory for AbortController + debounce.
 * One instance per logical fetch consumer (e.g. one per page search).
 * Exposed as window.BIomie.RC.
 */
(function () {
  'use strict';

  var B = window.BIomie = window.BIomie || {};

  B.RC = {
    /**
     * Creates an independent request controller.
     * @returns {{ signal: () => AbortSignal, abort: () => void,
     *             debounce: (fn: Function, ms: number) => Function,
     *             cancelDebounce: () => void }}
     */
    create: function () {
      var _ac    = null;
      var _timer = null;

      return {
        /** Aborts any in-flight request and returns a fresh AbortSignal. */
        signal: function () {
          if (_ac) { try { _ac.abort(); } catch (e) { /* noop */ } }
          _ac = new AbortController();
          return _ac.signal;
        },

        /** Cancels any in-flight request without issuing a new one. */
        abort: function () {
          if (_ac) { try { _ac.abort(); } catch (e) { /* noop */ } _ac = null; }
        },

        /**
         * Returns a debounced version of fn.
         * Multiple calls within `ms` collapse into one.
         */
        debounce: function (fn, ms) {
          return function () {
            var self = this;
            var args = arguments;
            if (_timer) clearTimeout(_timer);
            _timer = setTimeout(function () { _timer = null; fn.apply(self, args); }, ms || 300);
          };
        },

        /** Cancels a pending debounce without executing fn. */
        cancelDebounce: function () {
          if (_timer) { clearTimeout(_timer); _timer = null; }
        },
      };
    },
  };
}());
