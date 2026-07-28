(function () {
  'use strict';

  window.BIomieFinance = window.BIomieFinance || {};
  var BF = window.BIomieFinance;
  var B  = window.BIomie || {};

  // ── Aliases ──────────────────────────────────────────────────────────────
  var escapeHtml     = function (v) { return B.escapeHtml     ? B.escapeHtml(v)     : String(v ?? ''); };
  var formatCurrency = function (v) { return B.formatCurrency ? B.formatCurrency(v) : String(v); };
  var formatNumber   = function (v) { return B.formatNumber   ? B.formatNumber(v)   : String(v); };

  // ── Finance-specific formatters ───────────────────────────────────────────
  function getCssVar(name) {
    return getComputedStyle(document.body || document.documentElement).getPropertyValue(name).trim();
  }
  function chartTheme() {
    if (window.BIOMIE_CHART_THEME && typeof window.BIOMIE_CHART_THEME.getChartTheme === 'function') {
      return window.BIOMIE_CHART_THEME.getChartTheme();
    }
    return {
      text:         getCssVar('--text-2')               || '#4b5263',
      title:        getCssVar('--text-1')               || '#111318',
      subtle:       getCssVar('--text-3')               || '#9099ac',
      surface:      getCssVar('--bg-card')              || '#ffffff',
      grid:         getCssVar('--chart-grid')           || 'rgba(0,0,0,.08)',
      tooltipBg:    getCssVar('--chart-tooltip-bg')     || '#ffffff',
      tooltipBorder:getCssVar('--chart-tooltip-border') || 'rgba(0,0,0,.12)',
      tooltipTitle: getCssVar('--chart-tooltip-title')  || '#111318',
      tooltipText:  getCssVar('--chart-tooltip-text')   || '#4b5263'
    };
  }
  var chartPalette = {
    emerald:     getCssVar('--state-billed')   || '#0f9f6e',
    emeraldSoft: getCssVar('--success-strong') || '#35b98b',
    emeraldDeep: getCssVar('--green')          || '#0a6f4d',
    emeraldMute: getCssVar('--success-soft')   || '#dff7ed',
    gold:        getCssVar('--state-auth')     || '#c99a2e',
    red:         getCssVar('--state-cancel')   || '#d04d4d',
    redSoft:     getCssVar('--danger-soft')    || '#f2c7c7',
    teal:        getCssVar('--info')           || '#149a9a',
    navy:        getCssVar('--state-info')     || '#28597a',
    gray:        getCssVar('--text-2')         || '#5f6f66',
    graySoft:    getCssVar('--surface-3')      || '#e7efeb',
    lime:        getCssVar('--green')          || '#83b735'
  };
  function formatPercent(value)  { return Number(value || 0).toFixed(1) + '%'; }
  function formatCurrencyShort(value) {
    var number = Number(value || 0);
    if (Math.abs(number) >= 1000000) return 'R$ ' + (number / 1000000).toFixed(1).replace('.', ',') + ' mi';
    if (Math.abs(number) >= 1000)    return 'R$ ' + (number / 1000).toFixed(0).replace('.', ',') + ' mil';
    return formatCurrency(number);
  }
  function safeArray(value) { return Array.isArray(value) ? value : []; }
  function topRows(rows, limit) { return safeArray(rows).slice(0, limit || (rows ? rows.length : 0)); }

  // ── Own chart registry ───────────────────────────────────────────────────
  var charts = new Map();

  function destroyChart(id) {
    var c = charts.get(id);
    if (c) {
      try { c.destroy(); } catch (err) { console.warn('Falha ao destruir grafico financeiro.', err); }
    }
    charts.delete(id);
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.destroyChart === 'function') {
      window.BIOMIE_CHARTS.destroyChart(id);
    }
  }
  function registerChart(id, chart) {
    if (!chart) return;
    charts.set(id, chart);
    if (window.BIOMIE_CHARTS && window.BIOMIE_CHARTS.instances) window.BIOMIE_CHARTS.instances.set(id, chart);
  }
  function renderCanvasChart(canvasId, config) {
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.renderChartSafe === 'function') {
      var chart = window.BIOMIE_CHARTS.renderChartSafe(canvasId, config, {});
      if (chart) registerChart(canvasId, chart);
      return chart;
    }
    var ctx = getCanvas(canvasId);
    if (!ctx) return null;
    window.BIomieCharts.destroyChart(canvasId);
    var chart = new window.Chart(ctx, config);
    registerChart(canvasId, chart);
    return chart;
  }
  function getCanvas(id) {
    var canvas = document.getElementById(id);
    if (!canvas || !window.Chart) return null;
    return canvas.getContext('2d');
  }
  function clearCanvasEmptyState(canvasId) {
    var canvas = document.getElementById(canvasId);
    var parent = canvas && canvas.parentElement;
    if (!canvas || !parent) return;
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.clearChartState === 'function') {
      window.BIOMIE_CHARTS.clearChartState(parent);
    }
    canvas.classList.remove('d-none');
    var empty = parent.querySelector('.fin-chart-empty');
    if (empty) empty.remove();
  }
  function renderEmptyCanvasChart(canvasId, message) {
    window.BIomieCharts.destroyChart(canvasId);
    var canvas = document.getElementById(canvasId);
    var parent = canvas && canvas.parentElement;
    if (!canvas || !parent) return;
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.renderChartEmptyState === 'function') {
      window.BIOMIE_CHARTS.renderChartEmptyState(parent, message || 'Sem dados suficientes para o grafico.');
      return;
    }
    canvas.classList.add('d-none');
    var empty = parent.querySelector('.fin-chart-empty');
    if (!empty) {
      empty = document.createElement('div');
      empty.className = 'fin-chart-empty';
      parent.appendChild(empty);
    }
    empty.textContent = message || 'Sem dados suficientes para o grafico.';
  }
  function injectCanvasHost(targetId, canvasId, extraHtml) {
    window.BIomieCharts.destroyChart(targetId);
    var target = document.getElementById(targetId);
    if (!target) return null;
    target.innerHTML = (extraHtml || '') + '<div class="fin-chart-stage"><canvas id="' + canvasId + '"></canvas></div>';
    return getCanvas(canvasId);
  }
  function axisMoneyTick(value) { return formatCurrencyShort(value); }

  // ── HTML chart helpers ───────────────────────────────────────────────────
  function renderEmptyChart(targetId, message) {
    window.BIomieCharts.destroyChart(targetId);
    var target = document.getElementById(targetId);
    if (!target) return;
    target.innerHTML = '<div class="fin-chart-empty">' + escapeHtml(message || 'Sem dados suficientes para o grafico.') + '</div>';
  }

  // Cor por slot fixo — sem ciclar: além da paleta, cai no neutro ("Outros"),
  // nunca repete a cor de outra fatia (colisão de identidade).
  function slotColor(colors, i) {
    return (Array.isArray(colors) && colors[i]) || '#94a3b8';
  }

  function buildConicGradient(rows, valueKey, colors) {
    var total = rows.reduce(function (sum, row) { return sum + Number((row && row[valueKey]) || 0); }, 0);
    if (total <= 0) return '';
    var current = 0;
    return 'conic-gradient(' + rows.map(function (row, i) {
      var value = Number((row && row[valueKey]) || 0);
      var start = (current / total) * 360;
      current += value;
      return slotColor(colors, i) + ' ' + start + 'deg ' + ((current / total) * 360) + 'deg';
    }).join(', ') + ')';
  }

  function commonTooltip() {
    var theme = chartTheme();
    return {
      backgroundColor:  theme.tooltipBg,
      borderColor:      theme.tooltipBorder,
      borderWidth:      1,
      titleColor:       theme.tooltipTitle,
      bodyColor:        theme.tooltipText,
      callbacks: {
        label: function (ctx) {
          var v = (ctx.parsed && ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed) || 0;
          return ctx.dataset.label + ': ' + formatCurrency(v);
        }
      }
    };
  }

  // ── Drill-down helper ────────────────────────────────────────────────────
  function callSetDrillFilter(field, value, label) {
    var bf = window.BIomieFinance || {};
    if (typeof bf.setDrillFilter === 'function') bf.setDrillFilter(field, value, label);
  }

  // ── Chart renderers ──────────────────────────────────────────────────────
  function renderTrendHtml(targetId, rows, context) {
    var allRows = safeArray(rows).filter(function (row) {
      return Number(row.received || 0) > 0 || Number(row.planned || 0) > 0 || Number(row.overdue || 0) > 0 || Number(row.open || 0) > 0;
    });
    if (!allRows.length) { renderEmptyChart(targetId, 'Sem ritmo de carteira para o periodo selecionado.'); return; }

    if (allRows.length === 1) {
      destroyChart(targetId + 'Canvas');
      var tgt = document.getElementById(targetId);
      if (!tgt) return;
      var r = allRows[0];
      var kpis = [
        { label: 'Recebido no período', value: r.received, color: chartPalette.emerald || '#0f9f6e' },
        { label: 'A vencer',            value: r.open,     color: chartPalette.gold    || '#c99a2e' },
        { label: 'Vencido em aberto',   value: r.overdue,  color: chartPalette.red     || '#d04d4d' }
      ].filter(function (k) { return Number(k.value || 0) > 0; });
      tgt.innerHTML =
        '<div style="display:flex;gap:14px;align-items:stretch;height:100%;padding:16px 4px;box-sizing:border-box;flex-wrap:wrap;">' +
          kpis.map(function (k) {
            return '<div style="flex:1;min-width:140px;display:flex;flex-direction:column;gap:6px;padding:18px 20px;' +
              'border-radius:12px;background:' + k.color + '15;border:1px solid ' + k.color + '38;">' +
              '<span style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:' + k.color + ';">' + escapeHtml(k.label) + '</span>' +
              '<strong style="font-size:1.5rem;font-weight:800;color:' + k.color + ';line-height:1.15;">' + formatCurrency(k.value) + '</strong>' +
              '</div>';
          }).join('') +
        '</div>';
      return;
    }

    var ctx = injectCanvasHost(targetId, targetId + 'Canvas');
    if (!ctx) return;

    var labels   = allRows.map(function (row) { return row.label || row.key || '-'; });
    var received = allRows.map(function (row) { return Number(row.received || 0); });
    var planned  = allRows.map(function (row) { return Number(row.planned  || 0); });
    var overdue  = allRows.map(function (row) { return Number(row.overdue  || 0); });

    var chart = renderCanvasChart(targetId + 'Canvas', {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            type: 'line', label: 'Carteira', data: planned,
            borderColor: chartPalette.gold, backgroundColor: chartPalette.gold,
            pointBackgroundColor: chartPalette.gold,
            pointRadius: allRows.length > 18 ? 2 : 3, pointHoverRadius: 4,
            borderWidth: 3, tension: 0.28, fill: false, order: 0,
          },
          {
            type: 'bar', label: 'Recebido', data: received,
            backgroundColor: chartPalette.emerald, borderRadius: 10, borderSkipped: false,
            barThickness: 'flex', maxBarThickness: 22, categoryPercentage: 0.72, order: 1,
          },
          {
            type: 'bar', label: 'Vencido', data: overdue,
            backgroundColor: chartPalette.red, borderRadius: 10, borderSkipped: false,
            barThickness: 'flex', maxBarThickness: 22, categoryPercentage: 0.72, order: 2,
          }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false, normalized: true, resizeDelay: 80,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title:     function (items) { return (items && items[0] && items[0].label) || ''; },
              label:     function (ctx) { return ctx.dataset.label + ': ' + formatCurrency((ctx.parsed && ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed) || 0); },
              afterBody: function (items) {
                var idx = (items && items[0] && items[0].dataIndex != null) ? items[0].dataIndex : -1;
                var row = allRows[idx];
                if (!row) return [];
                var cov = Number(row.planned || 0) > 0 ? (Number(row.received || 0) / Number(row.planned || 0)) * 100 : 0;
                return ['Percentual recebido: ' + formatPercent(cov), 'A receber: ' + formatCurrency(row.open || 0)];
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { autoSkip: labels.length > 16, maxTicksLimit: 8, maxRotation: 0, minRotation: 0 }
          },
          y: {
            beginAtZero: true,
            ticks: { callback: function (value) { return axisMoneyTick(value); } },
            grid: { color: chartTheme().grid }
          }
        }
      }
    });
    registerChart(targetId, chart);
  }

  function renderRingChart(targetId, rows, labelKey, valueKey, colors, centerLabel, metaRenderer) {
    var target   = document.getElementById(targetId);
    if (!target) return;
    var dataRows = safeArray(rows).filter(function (row) { return Number((row && row[valueKey]) || 0) > 0; });
    if (!dataRows.length) { renderEmptyChart(targetId, 'Sem distribuicao disponivel no periodo.'); return; }
    var limited  = topRows(dataRows, 6);
    var total    = limited.reduce(function (sum, row) { return sum + Number((row && row[valueKey]) || 0); }, 0);
    var gradient = buildConicGradient(limited, valueKey, colors);
    target.innerHTML =
      '<div class="fin-ring-wrap">' +
        '<div class="fin-ring" style="--ring-gradient:' + gradient + ';">' +
          '<div class="fin-ring-center"><span>' + escapeHtml(centerLabel || 'Total') + '</span><strong>' + formatCurrency(total) + '</strong></div>' +
        '</div>' +
        '<div class="fin-ring-list">' +
          limited.map(function (row, i) {
            return '<div class="fin-ring-row" data-drill-field="' + escapeHtml(labelKey) + '" data-drill-value="' + escapeHtml(String(row[labelKey] || '')) + '" style="cursor:pointer" title="Filtrar por ' + escapeHtml(String(row[labelKey] || '')) + '">' +
              '<div>' +
                '<div class="label"><i class="fin-ring-dot" style="background:' + slotColor(colors, i) + '"></i>' + escapeHtml(row[labelKey] || 'Nao informado') + '</div>' +
                '<div class="meta">' + (metaRenderer ? metaRenderer(row) : '') + '</div>' +
              '</div>' +
              '<div class="text-end"><strong>' + formatCurrency(row[valueKey]) + '</strong></div>' +
            '</div>';
          }).join('') +
        '</div>' +
      '</div>';
    target.querySelectorAll('[data-drill-field]').forEach(function (el) {
      el.addEventListener('click', function () {
        var fieldLabels = { vendor_name: 'Vendedor', client_name: 'Cliente', payment_method: 'Meio de pag.', document_type: 'Tipo de doc.', account_name: 'Conta' };
        var lbl = fieldLabels[el.dataset.drillField] || el.dataset.drillField;
        callSetDrillFilter(el.dataset.drillField, el.dataset.drillValue, lbl + ': ' + el.dataset.drillValue);
      });
    });
  }

  function renderHorizontalBarsHtml(targetId, rows, labelKey, valueKey, color, formatter, metaRenderer) {
    var target   = document.getElementById(targetId);
    if (!target) return;
    var dataRows = safeArray(rows).filter(function (row) { return Number((row && row[valueKey]) || 0) > 0; });
    if (!dataRows.length) { renderEmptyChart(targetId, 'Sem inadimplencia vencida suficiente para montar faixas.'); return; }
    var limited  = topRows(dataRows, 8);
    var maxValue = Math.max.apply(null, limited.map(function (row) { return Number((row && row[valueKey]) || 0); }).concat([1]));
    target.innerHTML = '<div class="fin-bar-list">' + limited.map(function (row) {
      var value = Number((row && row[valueKey]) || 0);
      var width = Math.max(6, Math.round((value / maxValue) * 100));
      return '<div class="fin-bar-row">' +
        '<div class="fin-bar-head"><span>' + escapeHtml(row[labelKey] || 'Nao informado') + '</span><span>' + (formatter || formatCurrency)(value) + '</span></div>' +
        '<div class="fin-bar-track"><div class="fin-bar-fill" style="width:' + width + '%; background:' + color + ';"></div></div>' +
        (metaRenderer ? '<div class="small text-muted">' + metaRenderer(row) + '</div>' : '') +
      '</div>';
    }).join('') + '</div>';
  }

  // ── Data builders ────────────────────────────────────────────────────────
  function buildFallbackStatus(summary, rows) {
    var base = safeArray(rows).filter(function (row) { return Number(row.amount || 0) > 0; });
    if (base.length) return base;
    return [
      { label: 'Recebido', amount: Number(summary.received    || 0), titles: Number(summary.received_titles || 0) },
      { label: 'Em aberto',amount: Number(summary.open        || 0), titles: Number(summary.open_titles     || 0) },
      { label: 'Vencido',  amount: Number(summary.overdue     || 0), titles: Number(summary.overdue_titles  || 0) }
    ].filter(function (row) { return row.amount > 0; });
  }

  function aggregateBy(rows, key, valueKey, limit) {
    var map = new Map();
    safeArray(rows).forEach(function (row) {
      var label   = String((row && row[key]) || '').trim() || 'Nao identificado';
      var current = map.get(label) || { label: label, amount: 0, titles: 0 };
      current.amount += Number((row && row[valueKey]) || 0);
      current.titles += 1;
      map.set(label, current);
    });
    return Array.from(map.values()).sort(function (a, b) { return b.amount - a.amount; }).slice(0, limit || 8);
  }

  function buildFallbackAging(rows) {
    var buckets = {
      '1-30 dias':  { amount: 0, titles: 0, share: 0, min_days: 1,  max_days: 30   },
      '31-60 dias': { amount: 0, titles: 0, share: 0, min_days: 31, max_days: 60   },
      '61-90 dias': { amount: 0, titles: 0, share: 0, min_days: 61, max_days: 90   },
      '91+ dias':   { amount: 0, titles: 0, share: 0, min_days: 91, max_days: null }
    };
    var total = 0;
    safeArray(rows).forEach(function (row) {
      var days   = Number((row && row.days_late)    || 0);
      var amount = Number((row && row.amount_open)  || 0);
      if (amount <= 0 || days <= 0) return;
      total += amount;
      var bucket = '91+ dias';
      if (days <= 30) bucket = '1-30 dias';
      else if (days <= 60) bucket = '31-60 dias';
      else if (days <= 90) bucket = '61-90 dias';
      buckets[bucket].amount += amount;
      buckets[bucket].titles += 1;
    });
    return Object.entries(buckets).map(function (entry) {
      var bucket = entry[0], meta = entry[1];
      return { bucket: bucket, amount: meta.amount, titles: meta.titles, share: total > 0 ? (meta.amount / total) * 100 : 0, min_days: meta.min_days, max_days: meta.max_days };
    });
  }

  function buildVendorRows(data) {
    var source = safeArray(data.by_vendor).length
      ? safeArray(data.by_vendor)
      : aggregateBy(data.received_rows || [], 'vendor_name', 'amount_net', 8).map(function (row) { return { vendor_name: row.label, received: row.amount, titles: row.titles }; });
    var namedRows = source.filter(function (row) { return String(row.vendor_name || '').trim() !== ''; });
    return namedRows.length ? namedRows : source;
  }

  // ── Aging chart ──────────────────────────────────────────────────────────
  function renderAgingChart(targetId, rows) {
    var target = document.getElementById(targetId);
    if (!target) return;

    var bucketDefs = [
      { key: '1-30 dias',  color: '#d97706', bg: 'rgba(217,119,6,.08)',  border: 'rgba(217,119,6,.22)' },
      { key: '31-60 dias', color: '#ea580c', bg: 'rgba(234,88,12,.08)',  border: 'rgba(234,88,12,.22)' },
      { key: '61-90 dias', color: '#dc2626', bg: 'rgba(220,38,38,.08)',  border: 'rgba(220,38,38,.22)' },
      { key: '91+ dias',   color: '#7f1d1d', bg: 'rgba(127,29,29,.08)', border: 'rgba(127,29,29,.22)' },
    ];

    var normalized = {};
    safeArray(rows).forEach(function (r) {
      normalized[r.bucket] = { amount: Number(r.amount || 0), titles: Number(r.titles || 0), max_days: Number(r.max_days || 0) };
    });

    var fullRows = bucketDefs.map(function (def) {
      var d = normalized[def.key] || { amount: 0, titles: 0, max_days: 0 };
      return { key: def.key, color: def.color, bg: def.bg, border: def.border, amount: d.amount, titles: d.titles, max_days: d.max_days };
    });

    var total = fullRows.reduce(function (s, r) { return s + r.amount; }, 0);

    if (total <= 0) {
      target.innerHTML =
        '<div style="display:flex;flex-direction:column;align-items:center;gap:10px;padding:36px 16px;color:var(--text-3);">' +
          '<span class="material-symbols-rounded" style="font-size:30px;color:#16a34a;">check_circle</span>' +
          '<span style="font-size:13px;font-weight:600;">Sem títulos em atraso no período</span>' +
        '</div>';
      return;
    }

    var risk91  = fullRows[3].amount > 0;
    var risk61  = fullRows[2].amount > 0;
    var health  = risk91 ? 2 : (risk61 ? 1 : 0);
    var healthCfg = [
      { label: 'Carteira saudável', color: '#16a34a', bg: 'rgba(22,163,74,.08)', border: 'rgba(22,163,74,.25)' },
      { label: 'Atenção necessária', color: '#d97706', bg: 'rgba(217,119,6,.08)', border: 'rgba(217,119,6,.25)' },
      { label: 'Risco crítico',      color: '#dc2626', bg: 'rgba(220,38,38,.08)', border: 'rgba(220,38,38,.25)' },
    ][health];

    var maxDays = Math.max.apply(null, fullRows.filter(function (r) { return r.max_days > 0; }).map(function (r) { return r.max_days; }).concat([0]));

    var html = '';

    // Header: health badge + oldest days + total
    html += '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;">' +
      '<div style="display:flex;align-items:center;gap:10px;">' +
        '<div style="display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;background:' + healthCfg.bg + ';border:1px solid ' + healthCfg.border + ';">' +
          '<span style="width:7px;height:7px;border-radius:50%;background:' + healthCfg.color + ';display:inline-block;"></span>' +
          '<span style="font-size:11px;font-weight:700;color:' + healthCfg.color + ';">' + healthCfg.label + '</span>' +
        '</div>' +
        (maxDays > 0 ? '<span style="font-size:11px;color:var(--text-3);">Mais antigo: <strong style="color:' + healthCfg.color + ';">' + maxDays + ' dias</strong></span>' : '') +
      '</div>' +
      '<span style="font-size:14px;font-weight:800;color:var(--text-1);">' + formatCurrency(total) + '</span>' +
    '</div>';

    // Stacked proportion bar — all 4 buckets, empties as 3px gray slivers
    html += '<div style="display:flex;height:10px;border-radius:999px;overflow:hidden;gap:2px;margin-bottom:20px;">';
    fullRows.forEach(function (row) {
      var pct = total > 0 ? (row.amount / total) * 100 : 0;
      if (row.amount <= 0) {
        html += '<div style="width:3px;flex-shrink:0;background:#e5e7eb;" title="' + escapeHtml(row.key) + ': sem atrasos"></div>';
      } else {
        html += '<div style="flex:' + pct + ';min-width:4px;background:' + row.color + ';" title="' + escapeHtml(row.key) + ': ' + formatCurrency(row.amount) + '"></div>';
      }
    });
    html += '</div>';

    // 4-column bucket cards
    html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;">';
    fullRows.forEach(function (row) {
      var pct   = total > 0 ? (row.amount / total) * 100 : 0;
      var empty = row.amount <= 0;
      html += '<div style="padding:12px 14px;border-radius:12px;' +
        'background:' + (empty ? 'var(--surface-2,#f6f8fa)' : row.bg) + ';' +
        'border:1px solid ' + (empty ? 'var(--fin-border)' : row.border) + ';' +
        'border-top:3px solid ' + (empty ? '#e5e7eb' : row.color) + ';' +
        'opacity:' + (empty ? '0.45' : '1') + ';">';
      html += '<div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;color:' + (empty ? 'var(--text-3)' : row.color) + ';">' + escapeHtml(row.key) + '</div>';
      html += '<div style="font-size:' + (empty ? '.9rem' : '1.1rem') + ';font-weight:800;line-height:1.2;margin-bottom:5px;color:' + (empty ? 'var(--text-3)' : row.color) + ';">' + (empty ? '—' : formatCurrency(row.amount)) + '</div>';
      if (!empty) {
        html += '<div style="font-size:10px;color:var(--text-2);margin-bottom:5px;">' + formatNumber(row.titles) + ' título(s)</div>';
        html += '<div style="display:flex;align-items:center;gap:5px;">' +
          '<div style="flex:1;height:3px;border-radius:999px;background:' + row.color + '20;">' +
            '<div style="width:' + Math.min(Math.round(pct), 100) + '%;height:100%;border-radius:999px;background:' + row.color + ';"></div>' +
          '</div>' +
          '<span style="font-size:9px;font-weight:700;color:' + row.color + ';">' + Math.round(pct) + '%</span>' +
        '</div>';
        if (row.max_days > 0) {
          html += '<div style="font-size:9px;color:var(--text-3);margin-top:4px;">até ' + row.max_days + ' dias</div>';
        }
      } else {
        html += '<div style="font-size:10px;color:var(--text-3);">Sem atrasos</div>';
      }
      html += '</div>';
    });
    html += '</div>';

    target.innerHTML = html;
  }

  // ── Chart.js charts ──────────────────────────────────────────────────────
  function createDoughnutChart(canvasId, rows, labelKey, valueKey, colors, formatter) {
    var dataRows = safeArray(rows).filter(function (row) { return Number((row && row[valueKey]) || 0) > 0; }).slice(0, 8);
    if (!dataRows.length) { renderEmptyCanvasChart(canvasId, 'Sem distribuicao disponivel no periodo.'); return; }
    clearCanvasEmptyState(canvasId);
    window.BIomieCharts.destroyChart(canvasId);
    var ctx = getCanvas(canvasId);
    if (!ctx) return;
    var palette = Array.isArray(colors) && colors.length
      ? colors
      : ((window.MagChartDefaults && window.MagChartDefaults.categoricalPalette)
        || [chartPalette.emerald, chartPalette.gold, chartPalette.red, chartPalette.navy]);
    var chart = renderCanvasChart(canvasId, {
      type: 'bar',
      data: {
        labels: dataRows.map(function (row) { return row[labelKey]; }),
        datasets: [{
          label: 'Valor',
          data:            dataRows.map(function (row) { return Number(row[valueKey] || 0); }),
          backgroundColor: dataRows.map(function (_, i)  { return slotColor(palette, i); }),
          borderWidth: 0, borderRadius: 8, maxBarThickness: 28,
        }]
      },
      options: {
        indexAxis: 'y', responsive: true, resizeDelay: 80, maintainAspectRatio: false, animation: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + (formatter || formatCurrency)((ctx.parsed && ctx.parsed.x != null ? ctx.parsed.x : ctx.parsed) || 0); } } }
        },
        scales: {
          x: { beginAtZero: true, ticks: { callback: function (v) { return axisMoneyTick(v); } }, grid: { color: chartTheme().grid } },
          y: { grid: { display: false }, ticks: { color: chartTheme().subtle, maxTicksLimit: 8 } }
        },
        onClick: function (event, elements) {
          if (!elements.length) return;
          var row = dataRows[elements[0].index];
          if (row) {
            var lbl = canvasId === 'financeAccountsChart' ? 'Conta' : 'Vendedor';
            callSetDrillFilter(labelKey, row[labelKey], lbl + ': ' + row[labelKey]);
          }
        }
      }
    });
    registerChart(canvasId, chart);
  }

  function createHorizontalBarChart(canvasId, rows, labelKey, valueKey, datasetLabel, color, options) {
    var dataRows = safeArray(rows).filter(function (row) { return Number((row && row[valueKey]) || 0) > 0; });
    if (!dataRows.length) { renderEmptyCanvasChart(canvasId, 'Sem dados suficientes para comparacao no periodo.'); return; }
    clearCanvasEmptyState(canvasId);
    window.BIomieCharts.destroyChart(canvasId);
    var ctx = getCanvas(canvasId);
    if (!ctx) return;
    var chart = renderCanvasChart(canvasId, {
      type: 'bar',
      data: {
        labels: dataRows.map(function (row) { return row[labelKey]; }),
        datasets: [{
          label: datasetLabel,
          data: dataRows.map(function (row) { return Number(row[valueKey] || 0); }),
          backgroundColor: color, borderRadius: 10, borderSkipped: false,
          barThickness: 'flex', maxBarThickness: 24, categoryPercentage: 0.8,
        }]
      },
      options: {
        indexAxis: 'y', responsive: true, resizeDelay: 80, maintainAspectRatio: false, animation: false, normalized: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label:      function (ctx) { return datasetLabel + ': ' + formatCurrency((ctx.parsed && ctx.parsed.x != null ? ctx.parsed.x : ctx.parsed) || 0); },
              afterLabel: function (ctx) {
                var row = dataRows[ctx.dataIndex];
                var lines = [];
                if (row && row.titles    != null) lines.push('Titulos: '      + formatNumber(row.titles));
                if (row && row.avg_ticket != null) lines.push('Ticket medio: ' + formatCurrency(row.avg_ticket));
                return lines;
              }
            }
          }
        },
        scales: {
          x: { beginAtZero: true, ticks: { callback: function (v) { return axisMoneyTick(v); }, color: chartTheme().text }, grid: { color: chartTheme().grid } },
          y: { grid: { display: false } }
        },
        onClick: function (event, elements) {
          if (!elements.length || !options || !options.drillLabel) return;
          var row = dataRows[elements[0].index];
          if (row) callSetDrillFilter(labelKey, row[labelKey], options.drillLabel + ': ' + row[labelKey]);
        }
      }
    });
    registerChart(canvasId, chart);
  }

  function createStackedForecastChart(rows) {
    var dataRows = safeArray(rows).filter(function (row) {
      return Number(row.realized || 0) > 0 || Number(row.open || 0) > 0 || Number(row.overdue || 0) > 0 || Number(row.planned || 0) > 0;
    });
    if (!dataRows.length) { renderEmptyCanvasChart('financeForecastChart', 'Sem dados para comparar o esperado com o recebido no período.'); return; }
    clearCanvasEmptyState('financeForecastChart');
    window.BIomieCharts.destroyChart('financeForecastChart');
    var ctx = getCanvas('financeForecastChart');
    if (!ctx) return;
    var labels = dataRows.map(function (row) {
      return ((row.year_month && row.year_month.slice(5, 7)) || '') + '/' + ((row.year_month && row.year_month.slice(0, 4)) || '');
    });
    var chart = renderCanvasChart('financeForecastChart', {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          { label: 'Dinheiro recebido', data: dataRows.map(function (r) { return Number(r.realized || 0); }), backgroundColor: chartPalette.emerald, borderRadius: 8, stack: 'amounts' },
          { label: 'A receber', data: dataRows.map(function (r) { return Number(r.open     || 0); }), backgroundColor: chartPalette.gold,    borderRadius: 8, stack: 'amounts' },
          { label: 'Vencido',   data: dataRows.map(function (r) { return Number(r.overdue  || 0); }), backgroundColor: chartPalette.red,     borderRadius: 8, stack: 'amounts' },
          { label: 'Esperado', type: 'line', data: dataRows.map(function (r) { return Number(r.planned || 0); }), borderColor: chartPalette.navy, backgroundColor: chartPalette.navy, tension: 0.25, borderWidth: 2, pointRadius: 3, yAxisID: 'y' }
        ]
      },
      options: {
        responsive: true, resizeDelay: 80, maintainAspectRatio: false, animation: false, normalized: true,
        plugins: { tooltip: commonTooltip(), legend: { position: 'bottom' } },
        scales: {
          x: { stacked: true, grid: { display: false } },
          y: { stacked: true, beginAtZero: true, ticks: { callback: function (v) { return axisMoneyTick(v); }, color: chartTheme().text }, grid: { color: chartTheme().grid } }
        }
      }
    });
    registerChart('financeForecastChart', chart);
  }

  // ── Main chart render entry point ────────────────────────────────────────
  function renderCharts(data) {
    var trendRows = safeArray(data.trend).length
      ? safeArray(data.trend)
      : safeArray(data.budget).map(function (row) {
          return {
            key:      row.year_month,
            label:    row.year_month ? (String(row.year_month).slice(5, 7) + '/' + String(row.year_month).slice(0, 4)) : '-',
            received: Number(row.realized || 0),
            planned:  Number(row.planned  || 0),
            overdue:  Number(row.overdue  || 0),
            open:     Number(row.open     || 0)
          };
        });
    var agingRows = safeArray(data.aging).length
      ? safeArray(data.aging)
      : buildFallbackAging((data.overdue_titles || data.open_titles || []));
    var rangeContext = (data.meta && data.meta.range) || {
      points:      (data.summary && data.summary.range_points)      || trendRows.length,
      granularity: (data.summary && data.summary.range_granularity) || 'month'
    };
    renderTrendHtml('financeTrendChart', trendRows, rangeContext);
    renderAgingChart('financeAgingChart', agingRows);

    var paymentColors = [chartPalette.emerald, chartPalette.teal, chartPalette.gold, chartPalette.navy, chartPalette.red, chartPalette.gray];
    renderRingChart('financePaymentChart', safeArray(data.payment_methods), 'payment_method', 'received', paymentColors, 'Recebido', function (row) {
      return formatNumber(row.titles) + ' título(s)';
    });

    ['financeStatusChart', 'financeVendorOverdueChart', 'financeForecastChart', 'financeVendorsChart', 'financeAccountsChart'].forEach(function (id) {
      window.BIomieCharts.destroyChart(id);
      var target = document.getElementById(id);
      if (target && target.tagName !== 'CANVAS') target.innerHTML = '';
    });
  }

  // ── Tax panel rendering ──────────────────────────────────────────────────
  function renderTaxPanel(data) {
    var s = data.summary || {};
    function set(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; }

    set('taxValIcms',   s.icms        || '-');
    set('taxValPis',    s.pis         || '-');
    set('taxValCofins', s.cofins      || '-');
    set('taxValIpi',    s.ipi         || '-');
    set('taxValIss',    s.iss         || '-');
    set('taxValTotal',  s.trib_total  || '-');
    set('taxCargaTrib', (s.carga_tributaria || 0).toFixed(1));

    // % do faturamento badges per KPI
    var fat = Number(s._faturamento_total || 0);
    function setPct(id, raw) {
      var el = document.getElementById(id);
      if (!el) return;
      var r = Number(raw || 0);
      el.textContent = (fat > 0 && r > 0) ? (r / fat * 100).toFixed(2) + '% do fat.' : '';
    }
    setPct('taxPctIcms',   s._icms);
    setPct('taxPctPis',    s._pis);
    setPct('taxPctCofins', s._cofins);
    setPct('taxPctIpi',    s._ipi);
    setPct('taxPctIss',    s._iss);

    var nfsCom = Number(s.nfs_com_imposto || 0);
    var covEl = document.getElementById('financeTaxCoverage');
    if (covEl) {
      var nfsTotal = Number(s.total_nfs || 0);
      var covPct   = nfsTotal > 0 ? Math.round((nfsCom / nfsTotal) * 100) : 0;
      covEl.textContent = nfsCom + ' NFs com imposto (' + covPct + '% cobertura)';
    }

    var emptyEl  = document.getElementById('financeTaxEmpty');
    var kpisEl   = document.getElementById('financeTaxKpis');
    var nfsCom   = Number(s.nfs_com_imposto || 0);
    var hasTax   = (s._trib_total || 0) > 0 || (s._icms || 0) > 0 || nfsCom > 0;
    if (emptyEl) emptyEl.hidden = hasTax;
    if (kpisEl)  kpisEl.style.display = hasTax ? '' : 'none';

    // Trend chart: stacked bar per month
    var trendRows = safeArray(data.trend);
    if (trendRows.length > 0) {
      var trendTarget = document.getElementById('financeTaxTrendChart');
      if (trendTarget) {
        var labels   = trendRows.map(function (r) { return r.label || r.month || '-'; });
        var icmsData = trendRows.map(function (r) { return Number(r.icms   || 0); });
        var pisData  = trendRows.map(function (r) { return Number(r.pis    || 0); });
        var cofData  = trendRows.map(function (r) { return Number(r.cofins || 0); });
        var ipiData  = trendRows.map(function (r) { return Number(r.ipi    || 0); });
        var fatData  = trendRows.map(function (r) { return Number(r.faturamento || 0); });
        destroyChart('financeTaxTrendChart');
        var ctx = injectCanvasHost('financeTaxTrendChart', 'financeTaxTrendChartCanvas');
        if (ctx) {
          registerChart('financeTaxTrendChart', renderCanvasChart('financeTaxTrendChartCanvas', {
            type: 'bar',
            data: {
              labels: labels,
              datasets: [
                { label: 'ICMS',         data: icmsData, backgroundColor: '#6366f1', stack: 'tax', borderRadius: { topLeft: 0, topRight: 0, bottomLeft: 4, bottomRight: 4 } },
                { label: 'PIS',          data: pisData,  backgroundColor: '#0ea5e9', stack: 'tax' },
                { label: 'COFINS',       data: cofData,  backgroundColor: '#10b981', stack: 'tax' },
                { label: 'IPI',          data: ipiData,  backgroundColor: '#f59e0b', stack: 'tax', borderRadius: { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 } },
                { label: 'Faturamento',  data: fatData,  backgroundColor: 'rgba(180,180,180,.15)', stack: 'fat',
                  type: 'bar', borderRadius: 4, borderSkipped: false }
              ]
            },
            options: {
              responsive: true, maintainAspectRatio: false, animation: false, normalized: true, resizeDelay: 80,
              plugins: {
                legend: { display: true, position: 'top', labels: { boxWidth: 10, font: { size: 10 }, filter: function(i) { return i.text !== 'Faturamento'; } } },
                tooltip: {
                  callbacks: {
                    label: function (c) {
                      if (c.dataset.label === 'Faturamento') return 'Faturamento: ' + formatCurrency(c.parsed.y || 0);
                      var carga = (c.parsed.y || 0) > 0 && (fatData[c.dataIndex] || 0) > 0
                        ? ' (' + ((c.parsed.y / fatData[c.dataIndex]) * 100).toFixed(1) + '% fat.)'
                        : '';
                      return c.dataset.label + ': ' + formatCurrency(c.parsed.y || 0) + carga;
                    }
                  }
                }
              },
              scales: {
                x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0 } },
                y: { stacked: false, beginAtZero: true, ticks: { callback: function (v) { return axisMoneyTick(v); } }, grid: { color: chartTheme().grid } }
              }
            }
          }));
        }
      }
    } else {
      var tEl = document.getElementById('financeTaxTrendChart');
      if (tEl) renderEmptyChart('financeTaxTrendChart', 'Sem dados mensais de impostos no período.');
    }

    // Tax breakdown table (replaces ring chart)
    var taxDefs = [
      { label: 'ICMS',   raw: s._icms   || 0, color: '#6366f1', desc: 'Circulação' },
      { label: 'PIS',    raw: s._pis    || 0, color: '#0ea5e9', desc: 'Int. Social' },
      { label: 'COFINS', raw: s._cofins || 0, color: '#10b981', desc: 'Seguridade' },
      { label: 'IPI',    raw: s._ipi    || 0, color: '#f59e0b', desc: 'Ind. Produtos' },
      { label: 'ISS',    raw: s._iss    || 0, color: '#ef4444', desc: 'Serviços' },
    ].filter(function (t) { return t.raw > 0; });

    var tribTotal = Number(s._trib_total || 0);
    var distEl    = document.getElementById('financeTaxDistChart');
    if (distEl && taxDefs.length > 0) {
      var maxRaw = Math.max.apply(null, taxDefs.map(function (t) { return t.raw; }));

      var html = '<div class="fin-tax-table">';
      // header
      html += '<div class="fin-tax-table__head">' +
        '<span>Tributo</span><span>Valor</span><span class="fin-tax-table__r">% Fat.</span><span class="fin-tax-table__r">% Trib.</span>' +
        '</div>';
      // rows
      taxDefs.forEach(function (t) {
        var pctFat  = fat > 0       ? (t.raw / fat       * 100).toFixed(1) : '-';
        var pctTrib = tribTotal > 0 ? (t.raw / tribTotal * 100).toFixed(1) : '-';
        var barW    = maxRaw > 0    ? Math.round((t.raw / maxRaw) * 100) : 0;
        html += '<div class="fin-tax-table__row" style="--tx-c:' + t.color + ';">' +
          '<div class="fin-tax-table__name">' +
            '<span class="fin-tax-table__dot"></span>' +
            '<div><strong>' + escapeHtml(t.label) + '</strong><small>' + escapeHtml(t.desc) + '</small></div>' +
          '</div>' +
          '<div class="fin-tax-table__bar-cell">' +
            '<span>' + formatCurrency(t.raw) + '</span>' +
            '<div class="fin-tax-table__bar-track"><div class="fin-tax-table__bar-fill" style="width:' + barW + '%"></div></div>' +
          '</div>' +
          '<div class="fin-tax-table__r fin-tax-table__pct">' + pctFat + '%</div>' +
          '<div class="fin-tax-table__r fin-tax-table__trib">' + pctTrib + '%</div>' +
        '</div>';
      });
      // total footer
      html += '<div class="fin-tax-table__total">' +
        '<span>Total</span>' +
        '<span>' + (s.trib_total || '-') + '</span>' +
        '<span class="fin-tax-table__r">' + (s.carga_tributaria || 0).toFixed(1) + '%</span>' +
        '<span class="fin-tax-table__r" style="color:var(--text-3);">100%</span>' +
        '</div>';
      html += '</div>';
      distEl.innerHTML = html;
    }
  }

  function loadTaxPanel(start, end) {
    var url = 'api/finance_tax_overview.php?start=' + encodeURIComponent(start || '') + '&end=' + encodeURIComponent(end || '');
    fetch(url, { cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (data) { if (data && data.ok) renderTaxPanel(data); })
      .catch(function () {
        var el = document.getElementById('financeTaxEmpty');
        if (el) { el.hidden = false; el.querySelector('p').textContent = 'Erro ao carregar dados tributários.'; }
      });
  }

  // ── Public API ────────────────────────────────────────────────────────────
  BF.renderCharts               = renderCharts;
  BF.renderTrendHtml            = renderTrendHtml;
  BF.renderAgingChart           = renderAgingChart;
  BF.renderRingChart            = renderRingChart;
  BF.renderHorizontalBarsHtml   = renderHorizontalBarsHtml;
  BF.createDoughnutChart        = createDoughnutChart;
  BF.createHorizontalBarChart   = createHorizontalBarChart;
  BF.createStackedForecastChart = createStackedForecastChart;
  BF.buildVendorRows            = buildVendorRows;
  BF.buildFallbackStatus        = buildFallbackStatus;
  BF.buildFallbackAging         = buildFallbackAging;
  BF.aggregateBy                = aggregateBy;
  BF.loadTaxPanel               = loadTaxPanel;

})();
