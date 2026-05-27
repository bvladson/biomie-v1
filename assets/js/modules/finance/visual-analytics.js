(function () {
  'use strict';

  var B = window.BIomie || {};
  var charts = new Map();
  var lastPayload = null;
  var activeElement = null;
  var abortController = null;
  var activeLoadKey = '';
  var activeLoadPromise = null;

  function fmtCurrency(value) {
    return B.formatCurrency ? B.formatCurrency(value || 0) : 'R$ ' + Number(value || 0).toFixed(2);
  }

  function fmtNumber(value) {
    return B.formatNumber ? B.formatNumber(value || 0) : String(value || 0);
  }

  function esc(value) {
    return B.escapeHtml ? B.escapeHtml(value) : String(value || '');
  }

  function range() {
    var start = document.getElementById('dateStart');
    var end = document.getElementById('dateEnd');
    return {
      start: start && start.value ? start.value : '',
      end: end && end.value ? end.value : ''
    };
  }

  function destroyChart(id) {
    var chart = charts.get(id);
    if (chart && typeof chart.destroy === 'function') chart.destroy();
    charts.delete(id);
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.destroyChart === 'function') {
      window.BIOMIE_CHARTS.destroyChart(id);
    }
  }

  function renderChart(id, config) {
    var canvas = document.getElementById(id);
    if (!canvas || !window.Chart) return null;
    destroyChart(id);
    var chart = new window.Chart(canvas.getContext('2d'), config);
    charts.set(id, chart);
    return chart;
  }

  function moneyTick(value) {
    var n = Number(value || 0);
    if (Math.abs(n) >= 1000000) return 'R$ ' + (n / 1000000).toFixed(1).replace('.', ',') + ' mi';
    if (Math.abs(n) >= 1000) return 'R$ ' + (n / 1000).toFixed(0).replace('.', ',') + ' mil';
    return fmtCurrency(n);
  }

  function palette() {
    return ['#10b981', '#ef4444', '#f59e0b', '#2563eb', '#7c3aed', '#0891b2', '#db2777'];
  }

  function commonOptions(extra) {
    return Object.assign({
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function (ctx) {
              var value = ctx.parsed && ctx.parsed.y != null ? ctx.parsed.y : (ctx.parsed && ctx.parsed.x != null ? ctx.parsed.x : ctx.parsed);
              return ctx.dataset.label + ': ' + fmtCurrency(value || 0);
            }
          }
        }
      }
    }, extra || {});
  }

  function drawComposition(payload) {
    var rows = payload.composition || [];
    var colors = ['#10b981', '#ef4444', '#f59e0b'];
    renderChart('financeVisualCompositionChart', {
      type: 'doughnut',
      data: {
        labels: rows.map(function (r) { return r.label; }),
        datasets: [{ data: rows.map(function (r) { return Number(r.value || 0); }), backgroundColor: colors, borderColor: '#fff', borderWidth: 2 }]
      },
      options: commonOptions({
        cutout: '64%',
        onClick: function (_event, elements) {
          if (!elements || !elements.length) return;
          openDrawer('composition', rows[elements[0].index] && rows[elements[0].index].key, rows[elements[0].index] && rows[elements[0].index].label);
        }
      })
    });
  }

  function drawEvolution(payload) {
    var rows = (payload.evolution || []).filter(function (r) {
      return Number(r.received || 0) > 0 || Number(r.open || 0) > 0 || Number(r.overdue || 0) > 0;
    });
    renderChart('financeVisualEvolutionChart', {
      type: 'bar',
      data: {
        labels: rows.map(function (r) { return String(r.date || '').slice(5); }),
        datasets: [
          { type: 'bar', label: 'Recebido', data: rows.map(function (r) { return Number(r.received || 0); }), backgroundColor: '#10b981', borderRadius: 7, maxBarThickness: 18 },
          { type: 'line', label: 'Aberto', data: rows.map(function (r) { return Number(r.open || 0); }), borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: 0.3, pointRadius: 2 },
          { type: 'line', label: 'Vencido', data: rows.map(function (r) { return Number(r.overdue || 0); }), borderColor: '#ef4444', backgroundColor: '#ef4444', tension: 0.3, pointRadius: 2 }
        ]
      },
      options: commonOptions({
        interaction: { mode: 'index', intersect: false },
        scales: { y: { beginAtZero: true, ticks: { callback: moneyTick } }, x: { grid: { display: false } } },
        onClick: function (_event, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openDrawer('evolution', row.date, row.date);
        }
      })
    });
  }

  function drawAging(payload) {
    var rows = payload.aging || [];
    renderChart('financeVisualAgingChart', {
      type: 'bar',
      data: {
        labels: rows.map(function (r) { return r.label; }),
        datasets: [{ label: 'Aberto', data: rows.map(function (r) { return Number(r.value || 0); }), backgroundColor: ['#f59e0b', '#fbbf24', '#fb923c', '#f97316', '#ef4444', '#dc2626', '#991b1b'], borderRadius: 8, maxBarThickness: 24 }]
      },
      options: commonOptions({
        indexAxis: 'y',
        scales: { x: { beginAtZero: true, ticks: { callback: moneyTick } }, y: { grid: { display: false } } },
        onClick: function (_event, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openDrawer('aging', row.bucket, row.label);
        }
      })
    });
  }

  function drawVendors(payload) {
    var rows = (payload.vendors || []).slice(0, 10);
    renderChart('financeVisualVendorChart', {
      type: 'bar',
      data: {
        labels: rows.map(function (r) { return String(r.vendor_name || 'Sem vendedor').slice(0, 22); }),
        datasets: [{ label: 'Vencido', data: rows.map(function (r) { return Number(r.overdue_value || 0); }), backgroundColor: '#ef4444', borderRadius: 8, maxBarThickness: 24 }]
      },
      options: commonOptions({
        indexAxis: 'y',
        scales: { x: { beginAtZero: true, ticks: { callback: moneyTick } }, y: { grid: { display: false } } },
        onClick: function (_event, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openDrawer('vendor', row.vendor_key, row.vendor_name);
        }
      })
    });
  }

  function drawClients(payload) {
    var rows = (payload.clients_risk || []).slice(0, 10);
    renderChart('financeVisualClientRiskChart', {
      type: 'bar',
      data: {
        labels: rows.map(function (r) { return String(r.client_name || 'Cliente').slice(0, 18); }),
        datasets: [
          { type: 'bar', label: 'Vencido', data: rows.map(function (r) { return Number(r.overdue_value || 0); }), backgroundColor: '#ef4444', borderRadius: 8, maxBarThickness: 22, yAxisID: 'y' },
          { type: 'line', label: '% acum.', data: rows.map(function (r) { return Number(r.cumulative_pct || 0); }), borderColor: '#2563eb', backgroundColor: '#2563eb', tension: 0.3, yAxisID: 'y1' }
        ]
      },
      options: commonOptions({
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: { beginAtZero: true, ticks: { callback: moneyTick } },
          y1: { beginAtZero: true, max: 100, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: function (v) { return v + '%'; } } },
          x: { grid: { display: false } }
        },
        onClick: function (_event, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openDrawer('customer', row.client_key, row.client_name);
        }
      })
    });
  }

  function drawGovernance(payload) {
    var gov = payload.governance || {};
    var rows = [
      { key: 'today_actions', label: 'Cobrados hoje', value: Number(gov.today_actions || 0) },
      { key: 'returns_today', label: 'Retornos hoje', value: Number(gov.returns_today || 0) },
      { key: 'returns_overdue', label: 'Retornos atrasados', value: Number(gov.returns_overdue || 0) },
      { key: 'promises_overdue', label: 'Promessas vencidas', value: Number(gov.promises_overdue || 0) },
      { key: 'overdue_without_action', label: 'Vencidos sem acao', value: Number(gov.overdue_without_action || 0) },
      { key: 'no_vendor', label: 'Sem vendedor', value: Number(gov.no_vendor || 0) }
    ];
    renderChart('financeVisualGovernanceChart', {
      type: 'doughnut',
      data: { labels: rows.map(function (r) { return r.label; }), datasets: [{ data: rows.map(function (r) { return r.value; }), backgroundColor: palette(), borderColor: '#fff', borderWidth: 2 }] },
      options: commonOptions({
        cutout: '64%',
        onClick: function (_event, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openDrawer('governance', row.key, row.label);
        }
      })
    });
  }

  function render(payload) {
    lastPayload = payload;
    var meta = document.getElementById('financeVisualMeta');
    if (meta) meta.textContent = (payload.details || []).length + ' titulo(s) no read model visual';
    drawComposition(payload);
    drawEvolution(payload);
    drawAging(payload);
    drawVendors(payload);
    drawClients(payload);
    drawGovernance(payload);
  }

  async function load() {
    if (!document.getElementById('financeVisualAnalytics')) return;
    var r = range();
    if (!r.start || !r.end) return;
    var loadKey = r.start + '|' + r.end;
    if (activeLoadPromise && activeLoadKey === loadKey) return activeLoadPromise;
    if (abortController && activeLoadKey !== loadKey) abortController.abort();
    abortController = new AbortController();
    activeLoadKey = loadKey;
    var url = 'api/finance_visual_analytics.php?start=' + encodeURIComponent(r.start) + '&end=' + encodeURIComponent(r.end) + '&include_details=1';
    activeLoadPromise = fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' }, signal: abortController.signal })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok || !payload || payload.ok === false) {
            throw new Error((payload && payload.message) || 'Falha ao carregar analytics visual.');
          }
          render(payload);
          return payload;
        });
      })
      .finally(function () {
        activeLoadPromise = null;
      });
    return activeLoadPromise;
  }

  function metric(label, value) {
    return '<div class="finance-insight-metric"><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong></div>';
  }

  function rowValue(row) {
    return row.is_paid ? Number(row.valor_recebido || 0) : Number(row.valor_aberto || 0);
  }

  function filterRows(type, key) {
    var rows = (lastPayload && lastPayload.details) || [];
    if (!key && (type === 'composition' || type === 'aging' || type === 'vendor' || type === 'governance')) return rows.filter(function (r) { return r.is_open; });
    if (!key && type === 'customer') return rows.filter(function (r) { return r.is_overdue; });
    if (!key && type === 'evolution') return rows;
    if (type === 'open') return rows.filter(function (r) { return r.is_open; });
    if (type === 'overdue') return rows.filter(function (r) { return r.is_overdue; });
    if (type === 'due') return rows.filter(function (r) { return r.is_open && !r.is_overdue; });
    if (type === 'received') return rows.filter(function (r) { return r.is_paid; });
    if (type === 'late_clients') return rows.filter(function (r) { return r.is_overdue; });
    if (type === 'max_delay') return rows.filter(function (r) { return r.is_overdue; }).sort(function (a, b) { return Number(b.dias_atraso || 0) - Number(a.dias_atraso || 0); });
    if (type === 'composition') return filterRows(key === 'received' ? 'received' : (key === 'overdue' ? 'overdue' : 'due'));
    if (type === 'evolution') return rows.filter(function (r) { return r.data_competencia === key; });
    if (type === 'aging') return rows.filter(function (r) { return r.aging_bucket === key; });
    if (type === 'vendor') return rows.filter(function (r) { return r.vendedor_codigo_omie === key && r.is_open; });
    if (type === 'customer') return rows.filter(function (r) { return (r.cliente_codigo_omie || r.cliente) === key && r.is_overdue; });
    if (type === 'governance') {
      if (key === 'no_vendor') return rows.filter(function (r) { return r.vendor_missing && r.is_open; });
      if (key === 'overdue_without_action') return rows.filter(function (r) { return r.is_overdue && Number(r.action_count || 0) === 0; });
      if (key === 'promises_overdue') return rows.filter(function (r) { return r.last_action_type === 'promessa_pagamento' && r.next_action_date && r.next_action_date < (lastPayload.period.reference_date || '9999-12-31'); });
      if (key === 'returns_overdue') return rows.filter(function (r) { return r.next_action_date && r.next_action_date < (lastPayload.period.reference_date || '9999-12-31'); });
      if (key === 'returns_today') return rows.filter(function (r) { return r.next_action_date === (lastPayload.period.reference_date || ''); });
      return rows.filter(function (r) { return Number(r.action_count || 0) > 0; });
    }
    return rows.slice(0, 80);
  }

  function openDrawer(type, key, label) {
    if (!lastPayload) return;
    activeElement = document.activeElement;
    var drawer = document.getElementById('financeInsightDrawer');
    var backdrop = document.getElementById('financeInsightDrawerBackdrop');
    if (!drawer || !backdrop) return;
    var rows = filterRows(type, key).slice(0, 80);
    var total = rows.reduce(function (sum, row) { return sum + rowValue(row); }, 0);
    var overdueRows = rows.filter(function (row) { return row.is_overdue; });
    document.getElementById('financeInsightTitle').textContent = label || 'Detalhamento financeiro';
    document.getElementById('financeInsightSubtitle').textContent = rows.length + ' titulo(s) no recorte selecionado.';
    document.getElementById('financeInsightMetrics').innerHTML =
      metric('Valor', fmtCurrency(total)) +
      metric('Titulos', fmtNumber(rows.length)) +
      metric('Maior atraso', (overdueRows[0] ? Math.max.apply(null, overdueRows.map(function (r) { return Number(r.dias_atraso || 0); })) : 0) + 'd');
    var body = document.getElementById('financeInsightRows');
    var empty = document.getElementById('financeInsightEmpty');
    if (body) {
      body.innerHTML = rows.map(function (row) {
        return '<tr>' +
          '<td><strong>' + esc(row.document_label || row.document || row.titulo_key) + '</strong></td>' +
          '<td>' + esc(row.cliente || 'Cliente nao informado') + '</td>' +
          '<td>' + esc(row.vendedor || 'Sem vendedor definido') + '</td>' +
          '<td>' + esc(row.data_vencimento || '-') + '</td>' +
          '<td>' + esc(String(row.dias_atraso || 0)) + 'd</td>' +
          '<td><strong>' + esc(fmtCurrency(rowValue(row))) + '</strong></td>' +
          '<td>' + esc(row.acao_recomendada || '-') + '</td>' +
        '</tr>';
      }).join('');
    }
    if (empty) empty.hidden = rows.length > 0;
    var diagnostics = document.getElementById('financeInsightDiagnostics');
    if (diagnostics) diagnostics.textContent = JSON.stringify({ type: type, key: key, rows: rows.length, period: lastPayload.period }, null, 2);
    drawer.hidden = false;
    backdrop.hidden = false;
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    var closeBtn = document.getElementById('financeInsightClose');
    if (closeBtn) closeBtn.focus();
  }

  function requestDrawer(type, key, label) {
    if (lastPayload) {
      openDrawer(type, key, label);
      return;
    }

    var meta = document.getElementById('financeVisualMeta');
    if (meta) meta.textContent = 'Carregando detalhe financeiro...';
    load()
      .then(function () { openDrawer(type, key, label); })
      .catch(function (error) {
        if (meta) meta.textContent = error.message || 'Falha ao abrir detalhe financeiro.';
      });
  }

  function closeDrawer() {
    var drawer = document.getElementById('financeInsightDrawer');
    var backdrop = document.getElementById('financeInsightDrawerBackdrop');
    if (drawer) {
      drawer.hidden = true;
      drawer.setAttribute('aria-hidden', 'true');
    }
    if (backdrop) backdrop.hidden = true;
    document.body.classList.remove('modal-open');
    if (activeElement && typeof activeElement.focus === 'function') activeElement.focus();
  }

  function bind() {
    document.querySelectorAll('[data-drawer-type]').forEach(function (card) {
      if (card.dataset.financeVisualBound === '1') return;
      card.dataset.financeVisualBound = '1';
      card.addEventListener('click', function (event) {
        if (event && event.target && event.target.closest && event.target.closest('canvas')) return;
        requestDrawer(card.getAttribute('data-drawer-type') || 'summary', '', card.querySelector('h3') ? card.querySelector('h3').textContent : 'Detalhamento');
      });
    });
    document.querySelectorAll('[data-finance-insight]').forEach(function (card) {
      if (card.dataset.financeInsightBound === '1') return;
      card.dataset.financeInsightBound = '1';
      card.setAttribute('role', 'button');
      card.setAttribute('tabindex', '0');
      card.addEventListener('click', function () {
        var type = card.getAttribute('data-finance-insight') || '';
        requestDrawer(type, '', card.querySelector('.biomie-kpi-card__label') ? card.querySelector('.biomie-kpi-card__label').textContent : 'Detalhamento');
      });
      card.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        card.click();
      });
    });
    document.getElementById('financeInsightClose')?.addEventListener('click', closeDrawer);
    document.getElementById('financeInsightDrawerBackdrop')?.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeDrawer();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bind();
    load().catch(function (error) {
      var meta = document.getElementById('financeVisualMeta');
      if (meta) meta.textContent = error.message || 'Falha no visual financeiro';
    });
  });
  document.addEventListener('biomie:finance-overview-loaded', function () {
    load().catch(function () {});
  });

  window.BIomieFinanceVisual = { load: load, openDrawer: openDrawer, requestDrawer: requestDrawer };
})();

// ═══════════════════════════════════════════════════════════════════════════════
// FIN.R4.1 — Rastreabilidade NF × Boleto × Vendedor × Método de Pagamento
// ═══════════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  var B = window.BIomie || {};
  var tracePayload = null;
  var traceActiveEl = null;
  var traceMethodMetric = 'total_value';
  var traceCharts = new Map();
  var traceAbortCtrl = null;
  var traceLoadKey = '';
  var traceLoadPromise = null;

  function fmtC(v) { return B.formatCurrency ? B.formatCurrency(v || 0) : 'R$ ' + Number(v || 0).toFixed(2); }
  function fmtN(v) { return B.formatNumber ? B.formatNumber(v || 0) : String(v || 0); }
  function esc(v) { return B.escapeHtml ? B.escapeHtml(v) : String(v || ''); }
  function moneyTick(v) {
    var n = Number(v || 0);
    if (Math.abs(n) >= 1000000) return 'R$ ' + (n / 1000000).toFixed(1).replace('.', ',') + ' mi';
    if (Math.abs(n) >= 1000) return 'R$ ' + (n / 1000).toFixed(0).replace('.', ',') + ' mil';
    return fmtC(n);
  }

  function range() {
    var s = document.getElementById('dateStart');
    var e = document.getElementById('dateEnd');
    return { start: s && s.value ? s.value : '', end: e && e.value ? e.value : '' };
  }

  function destroyTraceChart(id) {
    var c = traceCharts.get(id);
    if (c && typeof c.destroy === 'function') c.destroy();
    traceCharts.delete(id);
    if (window.BIOMIE_CHARTS && typeof window.BIOMIE_CHARTS.destroyChart === 'function') {
      window.BIOMIE_CHARTS.destroyChart(id);
    }
  }

  function renderTraceChart(id, config) {
    var canvas = document.getElementById(id);
    if (!canvas || !window.Chart) return null;
    destroyTraceChart(id);
    var chart = new window.Chart(canvas.getContext('2d'), config);
    traceCharts.set(id, chart);
    return chart;
  }

  function commonOpts(extra) {
    return Object.assign({
      responsive: true, maintainAspectRatio: false, animation: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: {
        label: function (ctx) {
          var v = ctx.parsed && ctx.parsed.x != null ? ctx.parsed.x : (ctx.parsed && ctx.parsed.y != null ? ctx.parsed.y : ctx.raw);
          return (ctx.dataset.label || '') + ': ' + fmtC(v || 0);
        }
      }}}
    }, extra || {});
  }

  // ── Gráfico A: NFs com boletos em aberto (horizontal bar) ──────────────────
  function drawTraceNf(payload) {
    var rows = (payload.open_invoice_summary || []).slice(0, 10);
    renderTraceChart('financeTraceNfChart', {
      type: 'bar',
      data: {
        labels: rows.map(function (r) { return 'NF ' + (r.numero_nf || r.nota_key || '-').slice(0, 14) + ' — ' + (r.cliente_nome || '').slice(0, 18); }),
        datasets: [
          { label: 'Vencido', data: rows.map(function (r) { return Number(r.valor_vencido || 0); }), backgroundColor: '#ef4444', borderRadius: 6, maxBarThickness: 22 },
          { label: 'A vencer', data: rows.map(function (r) { return Number(r.valor_a_vencer || 0); }), backgroundColor: '#f59e0b', borderRadius: 6, maxBarThickness: 22 }
        ]
      },
      options: commonOpts({
        indexAxis: 'y',
        interaction: { mode: 'index', intersect: false },
        scales: { x: { beginAtZero: true, stacked: true, ticks: { callback: moneyTick } }, y: { stacked: true, grid: { display: false } } },
        plugins: { legend: { display: true, position: 'top', labels: { font: { size: 11 }, boxWidth: 10 } }, tooltip: { callbacks: {
          label: function (ctx) { return ctx.dataset.label + ': ' + fmtC(ctx.parsed.x || 0); }
        }}},
        onClick: function (_ev, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openTraceDrawer('nf', row.nota_key, 'NF ' + (row.numero_nf || row.nota_key || '-'), row);
        }
      })
    });
  }

  // ── Gráfico B: Top 10 métodos de pagamento ──────────────────────────────────
  function drawTraceMethod(payload, metric) {
    metric = metric || traceMethodMetric;
    traceMethodMetric = metric;
    var rows = (payload.payment_method_ranking || []).slice(0, 10);
    var metricLabels = { total_value: 'Valor total', open_value: 'Valor aberto', titles_count: 'Quantidade' };
    var isMoney = metric !== 'titles_count';
    var colors = ['#2563eb','#10b981','#f59e0b','#ef4444','#7c3aed','#0891b2','#db2777','#84cc16','#f97316','#6b7280'];
    renderTraceChart('financeTraceMethodChart', {
      type: 'bar',
      data: {
        labels: rows.map(function (r) { return r.method_label || r.method_key; }),
        datasets: [{ label: metricLabels[metric] || metric, data: rows.map(function (r) { return Number(r[metric] || 0); }), backgroundColor: colors.slice(0, rows.length), borderRadius: 6, maxBarThickness: 26 }]
      },
      options: commonOpts({
        indexAxis: 'y',
        scales: { x: { beginAtZero: true, ticks: { callback: isMoney ? moneyTick : undefined } }, y: { grid: { display: false } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: {
          label: function (ctx) { return (ctx.dataset.label || '') + ': ' + (isMoney ? fmtC(ctx.parsed.x || 0) : fmtN(ctx.parsed.x || 0)); }
        }}},
        onClick: function (_ev, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openTraceDrawer('method', row.method_key, 'Método: ' + (row.method_label || row.method_key), row);
        }
      })
    });
  }

  // ── Gráfico C: Cobrança por vendedor ────────────────────────────────────────
  function drawTraceVendor(payload) {
    var allRanking = payload.vendor_ranking || [];
    // Separate unknown vendor — show as warning card, not a chart bar
    var semVendedor = allRanking.find(function (r) { return r.is_unknown_vendor || r.vendor_key === '__sem_vendedor__'; }) || null;
    var rows = allRanking.filter(function (r) { return !r.is_unknown_vendor && r.vendor_key !== '__sem_vendedor__'; }).slice(0, 10);

    // Show / hide the "Sem vendedor" warning card
    var noDataCard = document.getElementById('financeTraceVendorNoData');
    if (noDataCard) {
      if (semVendedor && semVendedor.open_value > 0) {
        noDataCard.hidden = false;
        noDataCard.innerHTML =
          '<div class="finance-trace-vendor-alert" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-bottom:10px;display:flex;align-items:center;gap:10px;cursor:pointer;" data-vendor-missing="1">' +
          '<span style="font-size:1.4rem;">⚠</span>' +
          '<div>' +
          '<strong style="display:block;font-size:0.85rem;">' + fmtC(semVendedor.open_value) + ' sem vendedor definido</strong>' +
          '<small style="color:#92400e;">' + fmtN(semVendedor.receivable_count || semVendedor.boleto_count || 0) + ' recebíveis sem responsável — clique para revisar</small>' +
          '</div></div>';
        noDataCard.querySelector('[data-vendor-missing]').addEventListener('click', function () {
          openTraceDrawer('vendor', '__sem_vendedor__', 'Recebíveis sem vendedor', semVendedor);
        });
      } else {
        noDataCard.hidden = true;
        noDataCard.innerHTML = '';
      }
    }

    if (!rows.length) {
      destroyTraceChart('financeTraceVendorChart');
      return;
    }

    renderTraceChart('financeTraceVendorChart', {
      type: 'bar',
      data: {
        labels: rows.map(function (r) { return (r.vendor_name || 'Sem vendedor').slice(0, 22); }),
        datasets: [
          { label: 'Aberto', data: rows.map(function (r) { return Number(r.open_value || 0); }), backgroundColor: '#f59e0b', borderRadius: 6, maxBarThickness: 22 },
          { label: 'Vencido', data: rows.map(function (r) { return Number(r.overdue_value || 0); }), backgroundColor: '#ef4444', borderRadius: 6, maxBarThickness: 22 }
        ]
      },
      options: commonOpts({
        indexAxis: 'y',
        interaction: { mode: 'index', intersect: false },
        scales: { x: { beginAtZero: true, ticks: { callback: moneyTick } }, y: { grid: { display: false } } },
        plugins: { legend: { display: true, position: 'top', labels: { font: { size: 11 }, boxWidth: 10 } }, tooltip: { callbacks: {
          label: function (ctx) { return ctx.dataset.label + ': ' + fmtC(ctx.parsed.x || 0); }
        }}},
        onClick: function (_ev, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openTraceDrawer('vendor', row.vendor_key, 'Cobrança — ' + (row.vendor_name || 'Sem vendedor'), row);
        }
      })
    });
  }

  // ── Gráfico D: Cobertura NF × título (donut) ────────────────────────────────
  function drawTraceCoverage(payload) {
    var s = payload.summary || {};
    var rows = [
      { key: 'com_nf', label: 'Com NF vinculada', value: Number(s.titles_with_nf || 0), color: '#10b981' },
      { key: 'sem_nf', label: 'Sem NF vinculada', value: Number(s.titles_without_nf || 0), color: '#ef4444' },
      { key: 'sem_vendedor', label: 'Sem vendedor', value: Number(s.titles_without_vendor || 0), color: '#f59e0b' }
    ].filter(function (r) { return r.value > 0; });
    if (!rows.length) rows = [{ key: 'vazio', label: 'Sem dados', value: 1, color: '#e5e7eb' }];
    renderTraceChart('financeTraceCoverageChart', {
      type: 'doughnut',
      data: {
        labels: rows.map(function (r) { return r.label; }),
        datasets: [{ data: rows.map(function (r) { return r.value; }), backgroundColor: rows.map(function (r) { return r.color; }), borderColor: '#fff', borderWidth: 2 }]
      },
      options: commonOpts({
        cutout: '64%',
        plugins: { legend: { display: true, position: 'bottom', labels: { font: { size: 11 }, boxWidth: 10 } }, tooltip: { callbacks: {
          label: function (ctx) { return ctx.label + ': ' + fmtN(ctx.parsed || 0) + ' títulos'; }
        }}},
        onClick: function (_ev, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openTraceDrawer('coverage', row.key, row.label, row);
        }
      })
    });
  }

  // ── Gráfico E: Aging boletos ────────────────────────────────────────────────
  function drawTraceAging(payload) {
    var allRows = payload.nf_rows || [];
    var agingMap = { 'a_vencer': 0, 'vence_hoje': 0, '1_7': 0, '8_15': 0, '16_30': 0, '31_60': 0, '60_plus': 0 };
    var agingLabels = { 'a_vencer': 'A vencer', 'vence_hoje': 'Vence hoje', '1_7': '1-7 dias', '8_15': '8-15 dias', '16_30': '16-30 dias', '31_60': '31-60 dias', '60_plus': '60+ dias' };
    allRows.forEach(function (r) {
      if (!r.is_open || !r.is_boleto) return;
      var b = r.aging_bucket || 'a_vencer';
      if (agingMap[b] !== undefined) agingMap[b] += Number(r.valor_aberto || 0);
    });
    var rows = Object.keys(agingMap).map(function (k) { return { bucket: k, label: agingLabels[k] || k, value: agingMap[k] }; });
    var colors = ['#10b981','#fbbf24','#fb923c','#f97316','#ef4444','#dc2626','#991b1b'];
    renderTraceChart('financeTraceAgingChart', {
      type: 'bar',
      data: {
        labels: rows.map(function (r) { return r.label; }),
        datasets: [{ label: 'Boleto aberto', data: rows.map(function (r) { return r.value; }), backgroundColor: colors, borderRadius: 8, maxBarThickness: 24 }]
      },
      options: commonOpts({
        indexAxis: 'y',
        scales: { x: { beginAtZero: true, ticks: { callback: moneyTick } }, y: { grid: { display: false } } },
        onClick: function (_ev, elements) {
          if (!elements || !elements.length) return;
          var row = rows[elements[0].index] || {};
          openTraceDrawer('aging', row.bucket, 'Boletos: ' + row.label, row);
        }
      })
    });
  }

  // ── Tabela compacta de NFs ──────────────────────────────────────────────────
  var METHOD_LABELS = { boleto: 'Boleto', pix: 'Pix', cartao_credito: 'Cartão Cred.', cartao_debito: 'Cartão Déb.', dinheiro: 'Dinheiro', transferencia: 'Transferência', cheque: 'Cheque', deposito: 'Depósito', nao_informado: 'Não inform.', outro: 'Outro' };

  function methodBadge(method) {
    var lbl = METHOD_LABELS[method] || method || '-';
    var cls = method === 'boleto' ? 'fin-badge--boleto' : method === 'pix' ? 'fin-badge--pix' : method === 'nao_informado' ? 'fin-badge--muted' : 'fin-badge--default';
    return '<span class="fin-badge ' + cls + '">' + esc(lbl) + '</span>';
  }

  function renderTraceNfTable(payload) {
    var tbody = document.getElementById('financeTraceNfTableBody');
    if (!tbody) return;
    var rows = (payload.open_invoice_summary || []).slice(0, 10);
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;color:var(--text-3);padding:18px;">Nenhuma NF com recebível em aberto no período.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function (r) {
      var action = r.vendor_missing ? '⚠ Sem vendedor' : (r.valor_vencido > 0 ? 'Cobrar hoje' : 'Acompanhar');
      var pedidoLabel = r.pedido_id_omie && r.pedido_id_omie !== '0' ? esc(r.pedido_id_omie) : '<span style="color:var(--fin-muted)">—</span>';
      var methods = (r.methods || []).filter(function (m) { return m && m !== 'nao_informado'; });
      var methodDisplay = methods.length ? methodBadge(methods[0]) : methodBadge(r.metodo_pagamento_principal || 'nao_informado');
      return '<tr style="cursor:pointer;" data-trace-nf="' + esc(r.nota_key || '') + '">' +
        '<td><strong>' + esc(r.numero_nf || (r.nota_key ? r.nota_key.slice(-8) : '-')) + '</strong></td>' +
        '<td><small>' + pedidoLabel + '</small></td>' +
        '<td>' + esc(r.cliente_nome || 'Cliente não informado') + '</td>' +
        '<td>' + (r.vendor_missing ? '<span style="color:#f59e0b">⚠ ' + esc(r.vendedor_nome || 'Sem vendedor') + '</span>' : esc(r.vendedor_nome || '-')) + '</td>' +
        '<td>' + fmtN(r.qtd_titulos || 0) + '</td>' +
        '<td style="color:#ef4444"><strong>' + fmtC(r.valor_vencido || 0) + '</strong></td>' +
        '<td>' + fmtC(r.valor_a_vencer || 0) + '</td>' +
        '<td><strong>' + fmtC(r.valor_aberto || 0) + '</strong></td>' +
        '<td>' + (r.maior_atraso > 0 ? '<strong style="color:#ef4444">' + r.maior_atraso + 'd</strong>' : '-') + '</td>' +
        '<td>' + methodDisplay + '</td>' +
        '<td><small>' + esc(action) + '</small></td>' +
        '</tr>';
    }).join('');

    tbody.querySelectorAll('[data-trace-nf]').forEach(function (tr) {
      tr.addEventListener('click', function () {
        var nfKey = tr.getAttribute('data-trace-nf');
        var row = rows.find(function (r) { return r.nota_key === nfKey; }) || {};
        openTraceDrawer('nf', nfKey, 'NF ' + (row.numero_nf || (nfKey ? nfKey.slice(-8) : '-')), row);
      });
    });
  }

  // ── KPIs de rastreabilidade ─────────────────────────────────────────────────
  function renderTraceKpis(payload) {
    var s = payload.summary || {};
    var el = function (id) { return document.getElementById(id); };

    if (el('traceNfsOpenBoleto')) el('traceNfsOpenBoleto').textContent = fmtN(s.nfs_with_open_boleto || 0);
    if (el('traceNfsOpenBoletoSub')) el('traceNfsOpenBoletoSub').textContent = 'NFs • Aberto: ' + fmtC(s.open_total || 0);

    if (el('traceBoletosOpen')) el('traceBoletosOpen').textContent = fmtN(s.boleto_open_total || 0);
    if (el('traceBoletosOpenSub')) el('traceBoletosOpenSub').textContent = 'boletos • ' + fmtC(s.open_total || 0);

    if (el('traceBoletosOverdue')) el('traceBoletosOverdue').textContent = fmtC(s.overdue_total || 0);
    if (el('traceBoletosOverdueSub')) el('traceBoletosOverdueSub').textContent = fmtN(s.boleto_overdue_total || 0) + ' boletos • maior: ' + fmtN(s.max_delay_days || 0) + 'd';

    if (el('traceSemNf')) el('traceSemNf').textContent = fmtN(s.titles_without_nf || 0);
    if (el('traceSemNfSub')) el('traceSemNfSub').textContent = 'títulos sem NF vinculada';

    if (el('traceSemVendedor')) el('traceSemVendedor').textContent = fmtN(s.titles_without_vendor || 0);
    if (el('traceSemVendedorSub')) el('traceSemVendedorSub').textContent = 'sem responsável';

    if (el('traceMetodoPrincipal')) {
      var methodLabels = { boleto: 'Boleto', pix: 'Pix', cartao_credito: 'Cartão Cred.', cartao_debito: 'Cartão Déb.', dinheiro: 'Dinheiro', transferencia: 'Transferência', cheque: 'Cheque', deposito: 'Depósito', nao_informado: 'Não inform.', outro: 'Outro' };
      var m = s.top_payment_method || '-';
      el('traceMetodoPrincipal').textContent = methodLabels[m] || m;
    }
    if (el('traceMetodoPrincipalSub')) {
      el('traceMetodoPrincipalSub').textContent = fmtC(s.top_payment_method_value || 0) + ' no período';
    }

    var meta = document.getElementById('financeTraceMeta');
    if (meta) meta.textContent = fmtN(payload.diagnostics && payload.diagnostics.titles_loaded || 0) + ' título(s) no período';
  }

  // ── Renderização completa ───────────────────────────────────────────────────
  function renderTrace(payload) {
    tracePayload = payload;
    renderTraceKpis(payload);
    drawTraceNf(payload);
    drawTraceMethod(payload, traceMethodMetric);
    drawTraceVendor(payload);
    drawTraceCoverage(payload);
    drawTraceAging(payload);
    renderTraceNfTable(payload);
  }

  // ── Load ────────────────────────────────────────────────────────────────────
  async function loadTrace() {
    if (!document.getElementById('financeReceivableTrace')) return;
    var r = range();
    if (!r.start || !r.end) return;
    var key = r.start + '|' + r.end;
    if (traceLoadPromise && traceLoadKey === key) return traceLoadPromise;
    if (traceAbortCtrl && traceLoadKey !== key) traceAbortCtrl.abort();
    traceAbortCtrl = new AbortController();
    traceLoadKey = key;
    var url = 'api/finance_receivable_trace.php?start=' + encodeURIComponent(r.start) + '&end=' + encodeURIComponent(r.end) + '&mode=summary&status=all';
    traceLoadPromise = fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' }, signal: traceAbortCtrl.signal })
      .then(function (resp) {
        return resp.json().then(function (payload) {
          if (!resp.ok || !payload || payload.ok === false) throw new Error((payload && payload.message) || 'Falha na rastreabilidade.');
          renderTrace(payload);
          return payload;
        });
      })
      .finally(function () { traceLoadPromise = null; });
    return traceLoadPromise;
  }

  // ── Drawer de rastreabilidade ───────────────────────────────────────────────
  function metric(label, value) {
    return '<div class="finance-insight-metric"><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong></div>';
  }

  function openTraceDrawer(type, key, title, context) {
    if (!tracePayload) return;
    traceActiveEl = document.activeElement;
    var drawer = document.getElementById('financeTraceDrawer');
    var backdrop = document.getElementById('financeTraceDrawerBackdrop');
    if (!drawer || !backdrop) return;

    document.getElementById('financeTraceTitle').textContent = title || 'Detalhe';
    document.getElementById('financeTraceLabel').textContent = { nf: 'Nota Fiscal', method: 'Método de Pagamento', vendor: 'Vendedor', coverage: 'Cobertura', aging: 'Aging Boletos' }[type] || 'Rastreabilidade';

    var rows = [];
    var metrics = '';
    var subtitle = '';
    var allRows = tracePayload.nf_rows || [];

    if (type === 'nf') {
      rows = key === ''
        ? allRows.filter(function (r) { return r.is_open; })
        : allRows.filter(function (r) { return r.nota_key === key; });
      subtitle = key === '' ? rows.length + ' recebível(is) em aberto' : rows.length + ' título(s) da NF';
      var openVal = rows.reduce(function (s, r) { return s + (r.is_open ? Number(r.valor_aberto || 0) : 0); }, 0);
      var overdueVal = rows.reduce(function (s, r) { return s + Number(r.valor_vencido || 0); }, 0);
      var maxDelay = rows.reduce(function (s, r) { return Math.max(s, Number(r.dias_atraso || 0)); }, 0);
      var vendedor = (context && context.vendedor_nome) || (rows[0] && rows[0].vendedor_nome) || 'Sem vendedor definido';
      var cliente = (context && context.cliente_nome) || (rows[0] && rows[0].cliente_nome) || 'Cliente não informado';
      metrics = metric('Aberto', fmtC(openVal)) + metric('Vencido', fmtC(overdueVal)) + metric('Maior atraso', maxDelay + 'd') + metric('Vendedor', vendedor) + metric('Cliente', cliente);
    } else if (type === 'method') {
      rows = allRows.filter(function (r) { return r.normalized_payment_method === key && r.is_open; });
      subtitle = rows.length + ' título(s) com este método';
      var totVal = rows.reduce(function (s, r) { return s + Number(r.valor_aberto || 0); }, 0);
      var ovdVal = rows.reduce(function (s, r) { return s + Number(r.valor_vencido || 0); }, 0);
      metrics = metric('Aberto', fmtC(totVal)) + metric('Vencido', fmtC(ovdVal)) + metric('Títulos', String(rows.length)) + metric('NFs', String(new Set(rows.filter(function (r) { return r.nota_key; }).map(function (r) { return r.nota_key; })).size));
    } else if (type === 'vendor') {
      rows = key === ''
        ? allRows.filter(function (r) { return r.is_open; })
        : allRows.filter(function (r) { return r.vendor_key === key && r.is_open; });
      subtitle = rows.length + ' recebível(is) deste vendedor';
      var vOpenVal = rows.reduce(function (s, r) { return s + Number(r.valor_aberto || 0); }, 0);
      var vOvdVal = rows.reduce(function (s, r) { return s + Number(r.valor_vencido || 0); }, 0);
      metrics = metric('Aberto', fmtC(vOpenVal)) + metric('Vencido', fmtC(vOvdVal)) + metric('Recebíveis', String(rows.length)) + metric('Clientes', String(new Set(rows.map(function (r) { return r.client_key; })).size));
    } else if (type === 'coverage') {
      if (key === 'sem_nf') rows = allRows.filter(function (r) { return r.is_open && r.nota_key === ''; });
      else if (key === 'sem_vendedor') rows = allRows.filter(function (r) { return r.is_open && r.vendor_missing; });
      else rows = allRows.filter(function (r) { return r.is_open && r.nota_key !== ''; });
      subtitle = rows.length + ' título(s)';
      metrics = metric('Títulos', String(rows.length)) + metric('Valor aberto', fmtC(rows.reduce(function (s, r) { return s + Number(r.valor_aberto || 0); }, 0)));
    } else if (type === 'aging') {
      rows = allRows.filter(function (r) { return r.is_open && r.is_boleto && r.aging_bucket === key; });
      subtitle = rows.length + ' boleto(s) nesta faixa';
      var agVal = rows.reduce(function (s, r) { return s + Number(r.valor_aberto || 0); }, 0);
      metrics = metric('Boletos', String(rows.length)) + metric('Valor aberto', fmtC(agVal));
    }

    document.getElementById('financeTraceSubtitle').textContent = subtitle;
    document.getElementById('financeTraceMetrics').innerHTML = metrics;

    var tbody = document.getElementById('financeTraceRows');
    var empty = document.getElementById('financeTraceEmpty');

    if (tbody) {
      if (!rows.length) {
        tbody.innerHTML = '';
      } else {
        tbody.innerHTML = rows.slice(0, 80).map(function (r) {
          // Display NF number if available; fall back to last 10 chars of nota_key; never show full 44-char key inline
          var nfDisplay = r.numero_nf
            ? esc(r.numero_nf)
            : (r.nota_key ? '<span title="' + esc(r.nota_key) + '">…' + esc(r.nota_key.slice(-8)) + '</span>' : '—');
          var pedido = r.pedido_id_omie && r.pedido_id_omie !== '0' ? '<small>' + esc(r.pedido_id_omie) + '</small>' : '<span style="color:var(--fin-muted)">—</span>';
          return '<tr>' +
            '<td>' + nfDisplay + '</td>' +
            '<td>' + pedido + '</td>' +
            '<td>' + esc(r.cliente_nome || 'Não informado') + '</td>' +
            '<td>' + (r.vendor_missing ? '<span style="color:#f59e0b">⚠ ' + esc(r.vendedor_nome || 'Sem vendedor') + '</span>' : esc(r.vendedor_nome || '-')) + '</td>' +
            '<td>' + esc(r.data_vencimento || '-') + '</td>' +
            '<td>' + (r.dias_atraso > 0 ? '<strong style="color:#ef4444">' + r.dias_atraso + 'd</strong>' : '-') + '</td>' +
            '<td><strong>' + fmtC(r.valor_aberto || 0) + '</strong></td>' +
            '<td>' + methodBadge(r.normalized_payment_method || 'nao_informado') + '</td>' +
            '<td><small>' + esc(r.recommended_action || '-') + '</small></td>' +
            '</tr>';
        }).join('');
      }
    }

    if (empty) empty.hidden = rows.length > 0;

    var diag = document.getElementById('financeTraceDiagnostics');
    if (diag) diag.textContent = JSON.stringify({ type: type, key: key, rows: rows.length }, null, 2);

    drawer.hidden = false;
    backdrop.hidden = false;
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    var closeBtn = document.getElementById('financeTraceClose');
    if (closeBtn) closeBtn.focus();
  }

  function closeTraceDrawer() {
    var drawer = document.getElementById('financeTraceDrawer');
    var backdrop = document.getElementById('financeTraceDrawerBackdrop');
    if (drawer) { drawer.hidden = true; drawer.setAttribute('aria-hidden', 'true'); }
    if (backdrop) backdrop.hidden = true;
    document.body.classList.remove('modal-open');
    if (traceActiveEl && typeof traceActiveEl.focus === 'function') traceActiveEl.focus();
  }

  // ── Bind de eventos ─────────────────────────────────────────────────────────
  function bindTrace() {
    document.querySelectorAll('[data-trace-insight]').forEach(function (card) {
      if (card.dataset.traceBound === '1') return;
      card.dataset.traceBound = '1';
      function openFromKpi() {
        var insight = card.getAttribute('data-trace-insight') || '';
        if (!tracePayload) { loadTrace().then(function () { openFromKpi(); }).catch(function () {}); return; }
        var label = card.querySelector('.biomie-kpi-card__label');
        var title = label ? label.textContent : insight;
        if (insight === 'nfs_open_boleto') openTraceDrawer('nf', '', 'NFs com recebíveis em aberto', null);
        else if (insight === 'boletos_open') openTraceDrawer('method', 'all', 'Recebíveis em aberto', null);
        else if (insight === 'boletos_overdue') openTraceDrawer('aging', '', 'Boletos vencidos', null);
        else if (insight === 'sem_nf') openTraceDrawer('coverage', 'sem_nf', 'Sem NF vinculada', null);
        else if (insight === 'sem_vendedor') openTraceDrawer('coverage', 'sem_vendedor', 'Sem vendedor', null);
        else if (insight === 'metodo_principal') openTraceDrawer('method', (tracePayload.summary || {}).top_payment_method || '', 'Método principal', null);
        else openTraceDrawer('coverage', '', title, null);
      }
      card.addEventListener('click', openFromKpi);
      card.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openFromKpi(); } });
    });

    document.querySelectorAll('[data-trace-type]').forEach(function (card) {
      if (card.dataset.traceBound === '1') return;
      card.dataset.traceBound = '1';
      card.addEventListener('click', function (ev) {
        if (ev.target && ev.target.closest && ev.target.closest('canvas')) return;
        if (ev.target && ev.target.closest && ev.target.closest('.finance-trace-toggle')) return;
        if (!tracePayload) return;
        var t = card.getAttribute('data-trace-type') || '';
        var label = card.querySelector('h3') ? card.querySelector('h3').textContent : t;
        openTraceDrawer(t === 'nf_open' ? 'nf' : (t === 'payment_methods' ? 'method' : (t === 'vendor_boleto' ? 'vendor' : t)), '', label, null);
      });
    });

    var toggle = document.getElementById('traceMethodToggle');
    if (toggle) {
      toggle.querySelectorAll('[data-trace-metric]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          toggle.querySelectorAll('[data-trace-metric]').forEach(function (b) { b.classList.remove('finance-trace-btn--active'); });
          btn.classList.add('finance-trace-btn--active');
          if (tracePayload) drawTraceMethod(tracePayload, btn.getAttribute('data-trace-metric') || 'total_value');
        });
      });
    }

    var viewAll = document.getElementById('traceNfTableViewAll');
    if (viewAll) {
      viewAll.addEventListener('click', function () {
        if (!tracePayload) return;
        openTraceDrawer('nf', '', 'NFs com boleto em aberto', null);
      });
    }

    var closeBtn = document.getElementById('financeTraceClose');
    if (closeBtn) closeBtn.addEventListener('click', closeTraceDrawer);
    var backdrop = document.getElementById('financeTraceDrawerBackdrop');
    if (backdrop) backdrop.addEventListener('click', closeTraceDrawer);
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeTraceDrawer(); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindTrace();
    loadTrace().catch(function (error) {
      var meta = document.getElementById('financeTraceMeta');
      if (meta) meta.textContent = error.message || 'Falha na rastreabilidade financeira';
    });
  });
  document.addEventListener('biomie:finance-overview-loaded', function () {
    loadTrace().catch(function () {});
  });

  window.BIomieFinanceTrace = { load: loadTrace, openDrawer: openTraceDrawer };
})();
