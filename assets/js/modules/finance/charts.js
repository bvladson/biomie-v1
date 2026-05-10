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
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
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

  function buildConicGradient(rows, valueKey, colors) {
    var total = rows.reduce(function (sum, row) { return sum + Number((row && row[valueKey]) || 0); }, 0);
    if (total <= 0) return '';
    var current = 0;
    return 'conic-gradient(' + rows.map(function (row, i) {
      var value = Number((row && row[valueKey]) || 0);
      var start = (current / total) * 360;
      current += value;
      return colors[i % colors.length] + ' ' + start + 'deg ' + ((current / total) * 360) + 'deg';
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
                return ['Cobertura: ' + formatPercent(cov), 'Em aberto: ' + formatCurrency(row.open || 0)];
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
                '<div class="label"><i class="fin-ring-dot" style="background:' + colors[i % colors.length] + '"></i>' + escapeHtml(row[labelKey] || 'Nao informado') + '</div>' +
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
    var normalizedRows = safeArray(rows).map(function (row) {
      return { bucket: row.bucket, amount: Number(row.amount || 0), titles: Number(row.titles || 0), share: Number(row.share || 0), min_days: row.min_days != null ? row.min_days : null, max_days: row.max_days != null ? row.max_days : null };
    });
    var total = normalizedRows.reduce(function (sum, row) { return sum + row.amount; }, 0);
    if (total <= 0) { renderEmptyChart(targetId, 'Sem inadimplencia vencida suficiente para montar faixas.'); return; }

    var colors = { '1-30 dias': '#fcd277', '31-60 dias': '#f39c12', '61-90 dias': '#d04d4d', '91+ dias': '#9f2727' };
    var visibleRows = normalizedRows.filter(function (r) { return r.amount > 0; });

    target.innerHTML =
      '<div class="fin-aging-wrap">' +
        '<div class="fin-aging-track">' +
          visibleRows.map(function (row) {
            var pct = (row.amount / total) * 100;
            return '<div class="fin-aging-segment" style="width:' + pct + '%; background:' + (colors[row.bucket] || '#ccc') + '" title="' + row.bucket + ': ' + formatCurrency(row.amount) + '">' +
              (pct > 8 ? formatPercent(pct) : '') +
            '</div>';
          }).join('') +
        '</div>' +
        '<div class="fin-aging-legend">' +
          visibleRows.map(function (row) {
            return '<div class="fin-aging-card">' +
              '<div class="fin-aging-card-head"><i class="fin-aging-dot" style="background:' + (colors[row.bucket] || '#ccc') + '"></i>' + escapeHtml(row.bucket) + '</div>' +
              '<div class="fin-aging-value">' + formatCurrency(row.amount) + '</div>' +
              '<div class="fin-aging-meta"><span>' + formatNumber(row.titles) + ' doc(s)</span><strong>' + formatPercent((row.amount / total) * 100) + '</strong></div>' +
            '</div>';
          }).join('') +
        '</div>' +
      '</div>';
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
      : [chartPalette.emerald, chartPalette.gold, chartPalette.red, chartPalette.navy];
    var chart = renderCanvasChart(canvasId, {
      type: 'bar',
      data: {
        labels: dataRows.map(function (row) { return row[labelKey]; }),
        datasets: [{
          label: 'Valor',
          data:            dataRows.map(function (row) { return Number(row[valueKey] || 0); }),
          backgroundColor: dataRows.map(function (_, i)  { return palette[i % palette.length]; }),
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
    if (!dataRows.length) { renderEmptyCanvasChart('financeForecastChart', 'Sem dados de previsto x realizado no periodo.'); return; }
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
          { label: 'Realizado', data: dataRows.map(function (r) { return Number(r.realized || 0); }), backgroundColor: chartPalette.emerald, borderRadius: 8, stack: 'amounts' },
          { label: 'Em aberto', data: dataRows.map(function (r) { return Number(r.open     || 0); }), backgroundColor: chartPalette.gold,    borderRadius: 8, stack: 'amounts' },
          { label: 'Vencido',   data: dataRows.map(function (r) { return Number(r.overdue  || 0); }), backgroundColor: chartPalette.red,     borderRadius: 8, stack: 'amounts' },
          { label: 'Previsto', type: 'line', data: dataRows.map(function (r) { return Number(r.planned || 0); }), borderColor: chartPalette.navy, backgroundColor: chartPalette.navy, tension: 0.25, borderWidth: 2, pointRadius: 3, yAxisID: 'y' }
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
    ['financeStatusChart', 'financePaymentChart', 'financeVendorOverdueChart', 'financeForecastChart', 'financeVendorsChart', 'financeAccountsChart'].forEach(function (id) {
      window.BIomieCharts.destroyChart(id);
      var target = document.getElementById(id);
      if (target && target.tagName !== 'CANVAS') target.innerHTML = '';
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

})();
