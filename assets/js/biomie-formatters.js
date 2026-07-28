/**
 * BIomie — Centralized formatters.
 * Single source of truth for money, dates, documents, text fallbacks.
 * Import: <script src="assets/js/biomie-formatters.js"></script>
 */
(function () {
  'use strict';

  var currencyBRL = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  var numberBRL = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  function formatMoney(value) {
    return currencyBRL.format(Number(value || 0));
  }

  function formatNumber(value) {
    return numberBRL.format(Number(value || 0));
  }

  function formatMoneyShort(value) {
    var n = Number(value || 0);
    if (Math.abs(n) >= 1e6) return 'R$ ' + (n / 1e6).toFixed(1).replace('.', ',') + 'M';
    if (Math.abs(n) >= 1e3) return 'R$ ' + (n / 1e3).toFixed(1).replace('.', ',') + 'k';
    return formatMoney(n);
  }

  function formatDateBr(dateString) {
    if (!dateString) return '\u2014';
    var d = new Date(dateString);
    if (isNaN(d.getTime())) return '\u2014';
    var dd = String(d.getDate()).padStart(2, '0');
    var mm = String(d.getMonth() + 1).padStart(2, '0');
    var yyyy = d.getFullYear();
    return dd + '/' + mm + '/' + yyyy;
  }

  function formatDateTimeBr(dateString) {
    if (!dateString) return '\u2014';
    var d = new Date(dateString);
    if (isNaN(d.getTime())) return '\u2014';
    var dd = String(d.getDate()).padStart(2, '0');
    var mm = String(d.getMonth() + 1).padStart(2, '0');
    var yyyy = d.getFullYear();
    var hh = String(d.getHours()).padStart(2, '0');
    var mi = String(d.getMinutes()).padStart(2, '0');
    return dd + '/' + mm + '/' + yyyy + ' ' + hh + ':' + mi;
  }

  function maskDocument(doc) {
    if (!doc) return 'Sem documento';
    var digits = String(doc).replace(/\D/g, '');
    if (digits.length === 11) return '***.***.***-**';
    if (digits.length === 14) {
      return digits.slice(0, 2) + '.' + digits.slice(2, 5) + '.' + digits.slice(5, 8) + '/****-**';
    }
    return 'Sem documento';
  }

  function fallbackText(value, fallback) {
    if (value === null || value === undefined || String(value).trim() === '') {
      return fallback || '\u2014';
    }
    return String(value);
  }

  function ellipsisText(value, maxLen) {
    var s = String(value || '');
    maxLen = maxLen || 40;
    if (s.length <= maxLen) return s;
    return s.substring(0, maxLen - 1) + '\u2026';
  }

  function escapeHtml(str) {
    var d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
  }

  function formatPercent(value) {
    var n = Number(value || 0);
    return n.toFixed(1).replace('.', ',') + '%';
  }

  // Expose globally
  window.BIOMIE_FMT = {
    money: formatMoney,
    number: formatNumber,
    moneyShort: formatMoneyShort,
    dateBr: formatDateBr,
    dateTimeBr: formatDateTimeBr,
    maskDoc: maskDocument,
    fallback: fallbackText,
    ellipsis: ellipsisText,
    escape: escapeHtml,
    percent: formatPercent
  };
})();
