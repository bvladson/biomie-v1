(function () {
  'use strict';

  window.BIomie = window.BIomie || {};
  var B = window.BIomie;

  var currencyBRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  var numberBR    = new Intl.NumberFormat('pt-BR');
  var compactBR   = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 1 });

  // ── String / DOM ─────────────────────────────────────────────────────────

  B.escapeHtml = function (value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  };

  B.setText = function (selector, value) {
    document.querySelectorAll(selector).forEach(function (el) { el.textContent = value; });
  };

  B.setHtml = function (selector, value) {
    document.querySelectorAll(selector).forEach(function (el) { el.innerHTML = value; });
  };

  // ── Formatters ────────────────────────────────────────────────────────────

  B.formatCurrency = function (value) { return currencyBRL.format(Number(value || 0)); };

  B.formatNumber = function (value) { return numberBR.format(Number(value || 0)); };

  B.formatCurrencyShort = function (value) {
    var amount = Number(value || 0);
    var abs    = Math.abs(amount);
    var prefix = amount < 0 ? '-R$ ' : 'R$ ';
    if (abs >= 1000000) return prefix + compactBR.format(abs / 1000000) + ' mi';
    if (abs >= 1000)    return prefix + compactBR.format(abs / 1000) + ' mil';
    return prefix + numberBR.format(Math.round(abs));
  };

  B.shortenLabel = function (value, maxLength) {
    maxLength = maxLength == null ? 18 : maxLength;
    var label = String(value || '').trim();
    if (label.length <= maxLength) return label;
    return label.slice(0, Math.max(0, maxLength - 1)).trim() + '...';
  };

  B.formatMetaDate = function (value) {
    if (!value) return '-';
    var parsed = new Date(String(value).length === 10 ? value + 'T12:00:00' : value);
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleDateString('pt-BR');
  };

  B.formatOrderDate = function (row, preferIssueDate) {
    var issueDate   = row && row.issue_date   ? row.issue_date   : '';
    var billingDate = row && row.billing_date ? row.billing_date : '';
    var dateValue   = preferIssueDate ? (issueDate || billingDate) : (billingDate || issueDate);
    return dateValue ? new Date(dateValue + 'T12:00:00').toLocaleDateString('pt-BR') : '-';
  };

  B.formatAlertValue = function (alert) {
    var value  = alert && alert.value != null ? alert.value : undefined;
    if (typeof value !== 'number') return B.escapeHtml(value != null ? value : '-');
    var metric = String(alert && alert.metric ? alert.metric : '').toLowerCase();
    if (metric.includes('ating') || metric.includes('top cliente') || metric.includes('%')) return Number(value).toFixed(1) + '%';
    if (metric.includes('receita') || metric.includes('valor') || metric.includes('ticket') || metric.includes('backlog')) return B.formatCurrency(value);
    return B.formatNumber(value);
  };

  B.syncAgeLabel = function (isoDate) {
    if (!isoDate) return null;
    var diffMs = Date.now() - new Date(isoDate).getTime();
    if (Number.isNaN(diffMs) || diffMs < 0) return null;
    var mins = Math.floor(diffMs / 60000);
    if (mins < 1)  return 'ha menos de 1 min';
    if (mins < 60) return 'ha ' + mins + ' min';
    var hrs = Math.floor(mins / 60);
    if (hrs < 24)  return 'ha ' + hrs + 'h';
    return 'ha ' + Math.floor(hrs / 24) + ' dia(s)';
  };

  // ── Date helpers ──────────────────────────────────────────────────────────

  B.toLocalIso = function (date) {
    var y = date.getFullYear();
    var m = String(date.getMonth() + 1).padStart(2, '0');
    var d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  };

  B.todayIso = function () { return B.toLocalIso(new Date()); };

  B.chartDateLabel = function (dateValue) {
    return dateValue ? new Date(dateValue + 'T12:00:00').toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) : '';
  };

  B.monthLabel = function (dateValue) {
    if (!dateValue) return '';
    var normalized = String(dateValue).length === 7 ? dateValue + '-01' : String(dateValue);
    var parsed = new Date(normalized + 'T12:00:00');
    if (Number.isNaN(parsed.getTime())) return String(dateValue);
    return parsed.toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' });
  };

  // ── Date range ────────────────────────────────────────────────────────────

  B.getDateRange = function () {
    var s = document.getElementById('dateStart');
    var e = document.getElementById('dateEnd');
    return { start: s ? s.value || '' : '', end: e ? e.value || '' : '' };
  };

  B.setDateRange = function (start, end) {
    var s = document.getElementById('dateStart');
    var e = document.getElementById('dateEnd');
    if (s) s.value = start;
    if (e) e.value = end;
  };

  B.applyPresetRange = function (preset) {
    var now = new Date();
    if (preset === 'month') {
      B.setDateRange(
        B.toLocalIso(new Date(now.getFullYear(), now.getMonth(), 1)),
        B.toLocalIso(new Date(now.getFullYear(), now.getMonth() + 1, 0))
      );
    } else if (preset === 'year') {
      B.setDateRange(now.getFullYear() + '-01-01', B.todayIso());
    } else if (preset === 'rolling12') {
      B.setDateRange(
        B.toLocalIso(new Date(now.getFullYear() - 1, now.getMonth(), now.getDate() + 1)),
        B.todayIso()
      );
    }
  };

  B.buildRangeDates = function (start, end) {
    if (!start || !end) return [];
    var dates  = [];
    var cursor = new Date(start + 'T12:00:00');
    var last   = new Date(end   + 'T12:00:00');
    while (cursor <= last) {
      dates.push(B.toLocalIso(cursor));
      cursor.setDate(cursor.getDate() + 1);
    }
    return dates;
  };

  B.resolveRowDate  = function (row) { return (row && (row.issue_date || row.billing_date || row.date)) || ''; };
  B.resolveRowTotal = function (row) { return Number(row && (row.gross_total != null ? row.gross_total : (row.total != null ? row.total : (row.revenue || 0)))); };

  B.aggregateRowsByVendor = function (rows) {
    var grouped = new Map();
    (rows || []).forEach(function (row) {
      var name    = String(row && row.vendor_name ? row.vendor_name : 'Sem vendedor').trim() || 'Sem vendedor';
      var current = grouped.get(name) || { label: name, value: 0, count: 0 };
      current.value += B.resolveRowTotal(row);
      current.count += 1;
      grouped.set(name, current);
    });
    return Array.from(grouped.values()).sort(function (a, b) { return b.value - a.value; });
  };

  B.aggregateRowsByDate = function (rows, seededDates) {
    var grouped = new Map((seededDates || []).map(function (d) { return [d, 0]; }));
    (rows || []).forEach(function (row) {
      var date = B.resolveRowDate(row);
      if (!date) return;
      grouped.set(date, Number(grouped.get(date) || 0) + B.resolveRowTotal(row));
    });
    return Array.from(grouped.entries())
      .sort(function (a, b) { return a[0].localeCompare(b[0]); })
      .map(function (entry) { return { date: entry[0], value: entry[1] }; });
  };

  // ── Source / meta helpers ─────────────────────────────────────────────────

  B.normalizeServedFrom = function (value) {
    var n = String(value || '').toLowerCase();
    if (!n) return '-';
    if (n.includes('legacy_fallback')) return 'legacy-fallback';
    if (n.includes('legacy'))          return 'legacy';
    if (n.includes('historical') || n.includes('snapshot')) return 'historical-read-model';
    if (n.includes('canonical'))       return 'canonical-db';
    return String(value);
  };

  B.humanizeServedFrom = function (value) {
    var n = B.normalizeServedFrom(value);
    if (n === 'canonical-db')          return 'Base principal';
    if (n === 'historical-read-model') return 'Base historica';
    if (n === 'legacy-fallback')       return 'Base de seguranca';
    if (n === 'legacy')                return 'Base legada';
    return n;
  };

  B.resolveCoverageLabel = function (data) {
    var meta   = (data && data.meta) || {};
    var actual = meta.coverage_actual || {};
    var start  = actual.start || meta.coverage_start || (data && data.coverage_start) || (data && data.period && data.period.start) || '-';
    var end    = actual.end   || meta.coverage_end   || (data && data.coverage_end)   || (data && data.period && data.period.end)   || '-';
    return B.formatMetaDate(start) + ' ate ' + B.formatMetaDate(end);
  };

  // ── Chart theme helpers ───────────────────────────────────────────────────

  B.getCssVar = function (name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  };

  B.surfaceColor = function () { return B.getCssVar('--bg-card') || '#ffffff'; };

  B.chartTheme = function () {
    if (window.BIOMIE_CHART_THEME && typeof window.BIOMIE_CHART_THEME.getChartTheme === 'function') {
      return window.BIOMIE_CHART_THEME.getChartTheme();
    }
    return {
      text:         B.getCssVar('--text-2')              || '#4b5263',
      title:        B.getCssVar('--text-1')              || '#111318',
      subtle:       B.getCssVar('--text-3')              || '#9099ac',
      surface:      B.surfaceColor(),
      grid:         B.getCssVar('--chart-grid')          || 'rgba(0,0,0,.08)',
      tooltipBg:    B.getCssVar('--chart-tooltip-bg')    || '#ffffff',
      tooltipBorder:B.getCssVar('--chart-tooltip-border')|| 'rgba(0,0,0,.12)',
      accent:       B.getCssVar('--page-accent')         || '#96140a',
      accentSoft:   B.getCssVar('--page-accent-soft')    || 'rgba(150,20,10,0.18)',
      billed:       B.getCssVar('--state-billed')        || '#16a34a',
      authorized:   B.getCssVar('--state-auth')          || '#d97706',
      cancelled:    B.getCssVar('--state-cancel')        || '#dc2626',
      info:         B.getCssVar('--state-info')          || '#2563eb'
    };
  };

  B.hexToRgba = function (hex, alpha) {
    var clean  = String(hex || '#000000').replace('#', '');
    var bigint = clean.length === 3
      ? parseInt(clean.split('').map(function (c) { return c + c; }).join(''), 16)
      : parseInt(clean, 16);
    var r = (bigint >> 16) & 255;
    var g = (bigint >> 8)  & 255;
    var b =  bigint        & 255;
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  };

  B.adjustColour = function (hex, percent) {
    var clean = String(hex || '#000000').replace('#', '');
    var num   = parseInt(clean, 16);
    var r     = Math.min(255, Math.max(0, (num >> 16)         + Math.round(2.55 * percent)));
    var g     = Math.min(255, Math.max(0, ((num >> 8) & 0xFF) + Math.round(2.55 * percent)));
    var bv    = Math.min(255, Math.max(0, (num & 0xFF)        + Math.round(2.55 * percent)));
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + bv).toString(16).slice(1);
  };

  B.buildGradient = function (ctx, color, alpha) {
    alpha = alpha == null ? 0.24 : alpha;
    var gradient = ctx.createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, B.hexToRgba(color, alpha));
    gradient.addColorStop(1, B.hexToRgba(color, 0.05));
    return gradient;
  };

  B.chartAxisOptions = function (axis, extra) {
    extra = extra || {};
    var theme        = B.chartTheme();
    var options      = Object.assign({}, extra);
    var currentTicks  = Object.assign({}, extra.ticks  || {});
    var currentGrid   = Object.assign({}, extra.grid   || {});
    var currentBorder = Object.assign({}, extra.border || {});

    currentTicks.color = currentTicks.color || theme.text;
    if (currentTicks.maxTicksLimit == null) currentTicks.maxTicksLimit = axis === 'label' ? 8 : 6;
    if (currentTicks.autoSkip == null)      currentTicks.autoSkip = true;
    if (currentGrid.display !== false)      currentGrid.color = currentGrid.color || theme.grid;
    currentBorder.color = currentBorder.color || theme.grid;

    options.ticks  = currentTicks;
    options.grid   = currentGrid;
    options.border = currentBorder;

    if (axis === 'money' && typeof currentTicks.callback !== 'function') {
      options.ticks.callback = function (value) { return B.formatCurrencyShort(value); };
    }
    return options;
  };

  // ── Vendor status labels ──────────────────────────────────────────────────

  B.vendorRiskClass = function (s) {
    if (s === 'OK')             return 'badge-status billed';
    if (s === 'ATENCAO')        return 'badge-status authorized';
    if (s === 'SALA_DE_GUERRA') return 'badge-status cancelled';
    return 'badge-status';
  };

  B.vendorRiskLabel = function (s) {
    if (s === 'OK')             return 'No alvo';
    if (s === 'ATENCAO')        return 'Atencao';
    if (s === 'SALA_DE_GUERRA') return 'Risco';
    return 'Sem meta';
  };

  B.renderStatusBadge = function (mode) {
    var n = String(mode || '').toLowerCase();
    if (n.includes('hybrid'))                                  return '<span class="mag-status-chip mag-status-warning">Hibrido</span>';
    if (n.includes('historical') || n.includes('snapshot'))    return '<span class="mag-status-chip mag-status-warning">Historico</span>';
    if (n.includes('canonical') || n.includes('online') || n.includes('live')) return '<span class="mag-status-chip mag-status-live">Online</span>';
    if (n.includes('legacy_fallback') || n.includes('fallback')) return '<span class="mag-status-chip mag-status-warning">Fallback</span>';
    return '<span class="mag-status-chip mag-status-demo">Demo</span>';
  };

})();
