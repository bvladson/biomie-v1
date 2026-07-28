/**
 * BIomie — FilterChips (Fase Q.1)
 *
 * Renders "active filter" chips inside a container element.
 * Each chip has a remove button. A "Limpar tudo" button clears all at once.
 * Exposed as window.BIomie.FChips.
 */
(function () {
  'use strict';

  var B = window.BIomie = window.BIomie || {};

  var esc = function (v) {
    return B.escapeHtml
      ? B.escapeHtml(v)
      : String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
  };

  B.FChips = {
    /**
     * Renders chips and wires remove handlers.
     *
     * @param {string}   containerId  ID of the container element
     * @param {Array<{key:string, label:string}>} chips
     * @param {Function} onRemove  Called with the chip key, or '__all__' for clear-all
     */
    render: function (containerId, chips, onRemove) {
      var container = document.getElementById(containerId);
      if (!container) return;

      if (!chips || !chips.length) {
        container.style.display = 'none';
        container.innerHTML = '';
        return;
      }

      container.style.display = 'flex';
      container.innerHTML =
        chips.map(function (c) {
          return '<span class="biomie-filter-chip" data-chip-key="' + esc(c.key) + '">' +
            esc(c.label) +
            '<button type="button" class="biomie-filter-chip__remove" ' +
              'data-chip-remove="' + esc(c.key) + '" ' +
              'aria-label="Remover filtro ' + esc(c.label) + '">×</button>' +
            '</span>';
        }).join('') +
        '<button type="button" class="biomie-filter-chip-clear" id="' + esc(containerId) + '-clear-all">' +
          'Limpar tudo</button>';

      // Wire chip removal
      container.querySelectorAll('[data-chip-remove]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          if (onRemove) onRemove(btn.dataset.chipRemove);
        });
      });

      // Wire clear-all
      var clearAll = document.getElementById(containerId + '-clear-all');
      if (clearAll && onRemove) {
        clearAll.addEventListener('click', function () { onRemove('__all__'); });
      }
    },

    /** Clears and hides the chip container. */
    clear: function (containerId) {
      var container = document.getElementById(containerId);
      if (!container) return;
      container.style.display = 'none';
      container.innerHTML = '';
    },
  };
}());
