/**
 * BIomie — UX.CHARTS.R1 — Chart Governance Visual Layer
 *
 * Provides semantic colors, external HTML legends, gerencial insight strips,
 * accessibility labels and chart-purpose metadata.
 *
 * Does NOT alter Chart.js config, data, calculations or business rules.
 * Designed to be loaded AFTER Chart.js and chart-helpers.js.
 * All functions degrade gracefully when the DOM element is not found.
 */
(function (w) {
  'use strict';

  // ── Semantic color map ─────────────────────────────────────────────────────
  var semanticColors = {
    // Commercial
    revenue:      '#96140a',   // MAG primary red
    billed:       '#16a34a',   // green — realizado/NF
    realized:     '#16a34a',
    received:     '#16a34a',
    authorized:   '#d97706',   // amber — carteira/autorizado/previsto
    open:         '#d97706',
    carteira:     '#d97706',
    forecast:     '#2563eb',   // blue — projeção/tendência
    tendency:     '#2563eb',
    projection:   '#2563eb',
    target:       '#0f172a',   // near-black — meta (line)
    meta:         '#0f172a',
    overdue:      '#dc2626',   // red — vencido/risco
    risk:         '#dc2626',
    cancelled:    '#7f1d1d',   // dark red — cancelado
    devolvido:    '#78350f',
    neutral:      '#94a3b8',   // gray
    info:         '#3b82f6',
    warning:      '#f59e0b',
    success:      '#16a34a',
    danger:       '#dc2626',
    // Financial
    composition:  '#2563eb',
    governance:   '#7c3aed',
    aging_low:    '#16a34a',
    aging_med:    '#d97706',
    aging_high:   '#dc2626',
  };

  // Palette variants with alpha for fill/background
  var semanticBg = {
    billed:     'rgba(22,163,74,0.12)',
    realized:   'rgba(22,163,74,0.12)',
    received:   'rgba(22,163,74,0.12)',
    authorized: 'rgba(217,119,6,0.12)',
    open:       'rgba(217,119,6,0.12)',
    carteira:   'rgba(217,119,6,0.12)',
    forecast:   'rgba(37,99,235,0.10)',
    revenue:    'rgba(150,20,10,0.10)',
    overdue:    'rgba(220,38,38,0.12)',
    cancelled:  'rgba(127,29,29,0.10)',
    target:     'rgba(15,23,42,0.06)',
    neutral:    'rgba(148,163,184,0.12)',
  };

  function getSemanticColor(name, type) {
    var n = String(name || '').toLowerCase().trim();
    var map = (type === 'bg') ? semanticBg : semanticColors;
    if (map[n]) return map[n];
    // Fuzzy: check if key is contained in name
    for (var k in map) {
      if (n.indexOf(k) !== -1 || k.indexOf(n) !== -1) return map[k];
    }
    return null;
  }

  // ── Chart purpose classification ───────────────────────────────────────────
  function classifyChartPurpose(config) {
    if (!config) return 'unknown';
    var type = (config.type || '').toLowerCase();
    var isHorizontal = !!(config.options && config.options.indexAxis === 'y');
    if (type === 'doughnut' || type === 'pie') return 'composition';
    if (type === 'bar' && isHorizontal) return 'ranking';
    if (type === 'line') return 'trend';
    if (type === 'bar') {
      // Check if stacked
      if (config.options && config.options.scales && config.options.scales.x && config.options.scales.x.stacked) return 'status';
      return 'ranking';
    }
    return 'comparison';
  }

  function hasMultipleSeries(config) {
    if (!config || !config.data) return false;
    return Array.isArray(config.data.datasets) && config.data.datasets.length > 1;
  }

  function shouldShowLegend(config) {
    if (!config) return false;
    if (!hasMultipleSeries(config)) return false;
    // Always for these types
    var purpose = classifyChartPurpose(config);
    if (purpose === 'status' || purpose === 'comparison') return true;
    // Check for dual axis
    var opts = config.options || {};
    if (opts.scales && opts.scales.y1) return true;
    // Check dataset labels suggest multi-meaning
    var ds = (config.data && config.data.datasets) || [];
    var meaningfulLabels = ['nf', 'carteira', 'cancelada', 'meta', 'realizado', 'aberto', 'vencido', 'receita', 'ticket', 'previsto', 'tendência'];
    for (var i = 0; i < ds.length; i++) {
      var lbl = String(ds[i].label || '').toLowerCase();
      for (var j = 0; j < meaningfulLabels.length; j++) {
        if (lbl.indexOf(meaningfulLabels[j]) !== -1) return true;
      }
    }
    return false;
  }

  // ── Build HTML legend ──────────────────────────────────────────────────────
  function buildLegendHtml(datasets) {
    if (!datasets || !datasets.length) return '';
    var items = [];
    for (var i = 0; i < datasets.length; i++) {
      var ds = datasets[i];
      var label = ds.label || ('Série ' + (i + 1));
      var color = ds.borderColor || ds.backgroundColor;
      if (Array.isArray(color)) color = color[0];
      color = color || '#94a3b8';
      var dsType = ds.type || '';
      items.push(
        '<span class="mag-chart-legend__item" data-chart-type="' + escHtml(dsType) + '">'
        + '<span class="mag-chart-legend__swatch" style="background:' + escHtml(String(color)) + '"></span>'
        + '<span>' + escHtml(label) + '</span>'
        + '</span>'
      );
    }
    return '<div class="mag-chart-legend" data-generated="chart-governance">' + items.join('') + '</div>';
  }

  function ensureLegend(container, datasets, options) {
    if (!container) return;
    options = options || {};
    // Remove existing
    var existing = container.querySelector('.mag-chart-legend[data-generated="chart-governance"]');
    if (existing) existing.remove();
    var html = buildLegendHtml(datasets);
    if (!html) return;
    // Insert before first chart-body/chart-container, or prepend
    var target = container.querySelector('.chart-body, .mag-chart-body, .mag-chart-container, .fin-chart-stage');
    if (target) {
      target.insertAdjacentHTML('beforebegin', html);
    } else {
      container.insertAdjacentHTML('afterbegin', html);
    }
  }

  // ── Insight rendering ──────────────────────────────────────────────────────
  function renderInsight(container, insight) {
    if (!container || !insight) return;
    if (!insight.text) return;
    // Remove existing
    var existing = container.querySelector('.mag-chart-insight[data-generated="chart-governance"]');
    if (existing) existing.remove();
    var variant = insight.variant || 'neutral';
    var el = document.createElement('div');
    el.className = 'mag-chart-insight mag-chart-insight--' + variant;
    el.setAttribute('data-generated', 'chart-governance');
    el.setAttribute('role', 'note');
    el.setAttribute('aria-label', 'Insight: ' + insight.text);
    el.textContent = insight.text;
    // Insert after header but before chart-body
    var header = container.querySelector('.chart-card-header, .mag-chart-header, .detail-drawer-section h4');
    if (header) {
      header.insertAdjacentElement('afterend', el);
    } else {
      var body = container.querySelector('.chart-body, .mag-chart-body');
      if (body) body.insertAdjacentElement('beforebegin', el);
      else container.insertAdjacentElement('afterbegin', el);
    }
  }

  // ── Insight builders ───────────────────────────────────────────────────────
  function buildRankInsight(labels, values, options) {
    options = options || {};
    if (!labels || !labels.length || !values || !values.length) {
      return { text: 'Sem dados suficientes para análise de ranking.', variant: 'neutral' };
    }
    var pairs = [];
    for (var i = 0; i < labels.length; i++) {
      pairs.push({ label: labels[i], value: Number(values[i]) || 0 });
    }
    pairs.sort(function (a, b) { return b.value - a.value; });
    var total = pairs.reduce(function (s, p) { return s + p.value; }, 0);
    if (total <= 0) return { text: 'Sem dados de receita no período.', variant: 'neutral' };
    var leader = pairs[0];
    var top3Val = pairs.slice(0, 3).reduce(function (s, p) { return s + p.value; }, 0);
    var top3Pct = Math.round(top3Val / total * 100);
    var leaderPct = Math.round(leader.value / total * 100);
    var fmtFn = options.format || formatCurrencyShort;
    var text = 'Líder: ' + leader.label + ' com ' + fmtFn(leader.value) + '.';
    if (pairs.length >= 3) {
      text += ' Top 3 concentram ' + top3Pct + '% da receita.';
    }
    var variant = leaderPct > 60 ? 'info' : (top3Pct > 80 ? 'warning' : 'neutral');
    return { text: text, variant: variant };
  }

  function buildTrendInsight(labels, values, options) {
    options = options || {};
    if (!values || values.length < 4) {
      return { text: 'Período curto — tendência indisponível.', variant: 'neutral' };
    }
    var nums = values.map(function (v) { return Number(v) || 0; });
    var n = nums.length;
    var half = Math.floor(n / 2);
    var firstHalfAvg = nums.slice(0, half).reduce(function (s, v) { return s + v; }, 0) / half;
    var secondHalfAvg = nums.slice(n - half).reduce(function (s, v) { return s + v; }, 0) / half;
    if (firstHalfAvg <= 0) return { text: 'Sem dados para calcular tendência.', variant: 'neutral' };
    var delta = (secondHalfAvg - firstHalfAvg) / firstHalfAvg;
    var pct = Math.abs(Math.round(delta * 100));
    var text, variant;
    if (delta > 0.05) {
      text = 'Tendência de alta: ' + pct + '% acima da primeira metade do período.';
      variant = 'success';
    } else if (delta < -0.05) {
      text = 'Tendência de queda: ' + pct + '% abaixo da primeira metade do período.';
      variant = 'warning';
    } else {
      text = 'Volume estável no período (variação abaixo de 5%).';
      variant = 'neutral';
    }
    return { text: text, variant: variant };
  }

  function buildCompositionInsight(rows) {
    // rows: [{label, value}]
    if (!rows || !rows.length) return { text: 'Sem dados de composição.', variant: 'neutral' };
    var total = rows.reduce(function (s, r) { return s + (Number(r.value) || 0); }, 0);
    if (total <= 0) return { text: 'Sem dados de composição.', variant: 'neutral' };
    var sorted = rows.slice().sort(function (a, b) { return b.value - a.value; });
    var leader = sorted[0];
    var leaderPct = Math.round(leader.value / total * 100);
    var text = 'Maior fatia: ' + leader.label + ' (' + leaderPct + '%).';
    // Check for risk signals
    var overdueRow = rows.find(function (r) { return /vencid|overdue|risco/i.test(r.label); });
    if (overdueRow) {
      var overduePct = Math.round(overdueRow.value / total * 100);
      if (overduePct >= 15) {
        text += ' Atenção: vencido representa ' + overduePct + '% do total.';
        return { text: text, variant: 'warning' };
      }
    }
    var cancelRow = rows.find(function (r) { return /cancel/i.test(r.label); });
    if (cancelRow) {
      var cancelPct = Math.round(cancelRow.value / total * 100);
      if (cancelPct >= 20) {
        text += ' Cancelamentos em ' + cancelPct + '%.';
        return { text: text, variant: 'warning' };
      }
    }
    return { text: text, variant: 'neutral' };
  }

  function buildStatusInsight(datasets, labels) {
    // datasets: [{label, data:[...]}] — stacked status chart
    if (!datasets || !datasets.length || !labels || !labels.length) {
      return { text: 'Sem dados de status.', variant: 'neutral' };
    }
    var totals = {};
    for (var i = 0; i < datasets.length; i++) {
      var ds = datasets[i];
      var sum = (ds.data || []).reduce(function (s, v) { return s + (Number(v) || 0); }, 0);
      totals[ds.label] = sum;
    }
    var grandTotal = Object.values(totals).reduce(function (s, v) { return s + v; }, 0);
    if (grandTotal <= 0) return { text: 'Sem dados no período.', variant: 'neutral' };
    var maxLabel = Object.keys(totals).reduce(function (a, b) { return totals[a] > totals[b] ? a : b; });
    var maxPct = Math.round(totals[maxLabel] / grandTotal * 100);
    return {
      text: 'Status dominante: ' + maxLabel + ' representa ' + maxPct + '% do volume.',
      variant: maxPct > 70 ? 'info' : 'neutral'
    };
  }

  function buildChartQuestion(purpose, context) {
    var questions = {
      trend:       'Como o volume evoluiu ao longo do período?',
      ranking:     'Quem lidera no período?',
      composition: 'Qual é a composição do total?',
      status:      'Como está distribuído por status?',
      geo:         'Onde estão concentradas as vendas?',
      comparison:  'Como comparamos com a meta?',
    };
    return questions[purpose] || 'O que este gráfico responde?';
  }

  // ── Container enhancement ─────────────────────────────────────────────────
  function enhanceContainer(canvas, config, meta) {
    if (!canvas) return;
    meta = meta || {};
    var container = canvas.closest('.chart-card, .mag-chart-card, .chart-body, [class*="chart"]');
    var purpose = meta.purpose || classifyChartPurpose(config);
    var question = meta.question || buildChartQuestion(purpose);
    var ariaLabel = meta.ariaLabel || question;

    // Accessibility
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', ariaLabel);
    if (canvas.parentElement) {
      canvas.parentElement.setAttribute('data-chart-purpose', purpose);
    }

    if (!container) return;
    container.setAttribute('data-chart-purpose', purpose);

    // Legend
    var showLegend = (meta.legend === true) || (meta.legend !== false && shouldShowLegend(config));
    if (showLegend && config && config.data && config.data.datasets) {
      ensureLegend(container, config.data.datasets, meta);
    }

    // Insight
    if (meta.insight && meta.insight.text) {
      renderInsight(container, meta.insight);
    } else if (meta.insightType && meta.insightData) {
      var insight = null;
      if (meta.insightType === 'rank') {
        insight = buildRankInsight(meta.insightData.labels, meta.insightData.values, meta.insightData.options);
      } else if (meta.insightType === 'trend') {
        insight = buildTrendInsight(meta.insightData.labels, meta.insightData.values, meta.insightData.options);
      } else if (meta.insightType === 'composition') {
        insight = buildCompositionInsight(meta.insightData.rows);
      } else if (meta.insightType === 'status') {
        insight = buildStatusInsight(meta.insightData.datasets, meta.insightData.labels);
      }
      if (insight) renderInsight(container, insight);
    }
  }

  function enhanceChartConfig(config, meta) {
    if (!config || !meta) return config;
    config.options = config.options || {};
    config.options.plugins = config.options.plugins || {};
    config.options.plugins.biomieGovernance = Object.assign(
      config.options.plugins.biomieGovernance || {},
      meta
    );
    return config;
  }

  // ── Number formatters ──────────────────────────────────────────────────────
  function formatCurrencyShort(v) {
    var n = Number(v) || 0;
    if (n >= 1e6) return 'R$ ' + (n / 1e6).toFixed(1).replace('.', ',') + ' mi';
    if (n >= 1e3) return 'R$ ' + (n / 1e3).toFixed(1).replace('.', ',') + ' mil';
    return 'R$ ' + n.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function formatPercent(v, decimals) {
    return (Number(v) || 0).toFixed(decimals != null ? decimals : 1).replace('.', ',') + '%';
  }

  function formatNumberShort(v) {
    var n = Number(v) || 0;
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace('.', ',') + ' mi';
    if (n >= 1e3) return (n / 1e3).toFixed(1).replace('.', ',') + ' mil';
    return n.toFixed(0);
  }

  // ── Utility ────────────────────────────────────────────────────────────────
  function escHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Public API ─────────────────────────────────────────────────────────────
  w.BIOMIE_CHART_GOVERNANCE = {
    semanticColors:        semanticColors,
    semanticBg:            semanticBg,
    getSemanticColor:      getSemanticColor,
    classifyChartPurpose:  classifyChartPurpose,
    hasMultipleSeries:     hasMultipleSeries,
    shouldShowLegend:      shouldShowLegend,
    buildLegendHtml:       buildLegendHtml,
    ensureLegend:          ensureLegend,
    renderInsight:         renderInsight,
    buildRankInsight:      buildRankInsight,
    buildTrendInsight:     buildTrendInsight,
    buildCompositionInsight: buildCompositionInsight,
    buildStatusInsight:    buildStatusInsight,
    buildChartQuestion:    buildChartQuestion,
    enhanceContainer:      enhanceContainer,
    enhanceChartConfig:    enhanceChartConfig,
    formatCurrencyShort:   formatCurrencyShort,
    formatPercent:         formatPercent,
    formatNumberShort:     formatNumberShort,
  };

}(window));
