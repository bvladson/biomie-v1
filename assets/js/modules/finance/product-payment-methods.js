(function () {
  'use strict';

  var B = window.BIomie || {};
  var payload = null;
  var loadKey = '';
  var loadPromise = null;
  var abortController = null;
  var chart = null;

  var colors = {
    boleto: '#2563eb',
    pix: '#10b981',
    cartao_credito: '#7c3aed',
    cartao_debito: '#0891b2',
    cartao: '#6d28d9',
    carteira_digital: '#db2777',
    conta_bancaria: '#475569',
    dinheiro: '#f59e0b',
    transferencia: '#0f766e',
    cheque: '#db2777',
    deposito: '#64748b',
    outro: '#94a3b8',
    nao_informado: '#d1d5db'
  };

  function esc(value) { return B.escapeHtml ? B.escapeHtml(value) : String(value || ''); }
  function fmt(value) { return B.formatNumber ? B.formatNumber(value || 0) : String(value || 0); }
  function range() {
    var start = document.getElementById('dateStart');
    var end = document.getElementById('dateEnd');
    return { start: start && start.value || '', end: end && end.value || '' };
  }
  function methodBadge(row) {
    var key = row.method_key || row.top_method_key || 'nao_informado';
    var label = row.method_label || row.top_method_label || 'Nao informado';
    return '<span class="finance-method-badge" style="--method-color:' + esc(colors[key] || colors.outro) + '">' + esc(label) + '</span>';
  }

  function render(data) {
    payload = data;
    var summary = data.summary || {};
    var top = summary.top_method || {};
    var meta = document.getElementById('financeProductMethodMeta');
    if (meta) meta.textContent = fmt(summary.products_count || 0) + ' produto(s) cruzados';

    var topNode = document.getElementById('financeProductMethodTop');
    var coverageNode = document.getElementById('financeProductMethodCoverage');
    var linksNode = document.getElementById('financeProductMethodLinks');
    var methodsNode = document.getElementById('financeProductMethodCount');
    if (topNode) topNode.innerHTML = top.method_label ? methodBadge(top) : '-';
    if (coverageNode) coverageNode.textContent = Number(summary.coverage_pct || 0).toFixed(1) + '%';
    if (linksNode) linksNode.textContent = fmt(summary.links_count || 0);
    if (methodsNode) methodsNode.textContent = fmt(summary.methods_count || 0);

    var methods = (data.methods || []).slice(0, 10);
    var canvas = document.getElementById('financeProductMethodChart');
    if (chart) chart.destroy();
    if (canvas && window.Chart) {
      chart = new window.Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
          labels: methods.map(function (row) { return row.method_label; }),
          datasets: [{ label: 'Vinculos titulo x produto', data: methods.map(function (row) { return row.titles_count; }), backgroundColor: methods.map(function (row) { return colors[row.method_key] || colors.outro; }), borderRadius: 7, maxBarThickness: 24 }]
        },
        options: {
          responsive: true, maintainAspectRatio: false, animation: false, indexAxis: 'y',
          plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return fmt(ctx.parsed.x || 0) + ' titulo(s) vinculados'; } } } },
          scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } }
        }
      });
    }

    var body = document.getElementById('financeProductMethodBody');
    if (!body) return;
    var products = data.products || [];
    body.innerHTML = products.length ? products.map(function (row) {
      return '<tr>' +
        '<td><strong>' + esc(row.product_name) + '</strong><small>' + esc(row.product_code || 'Sem SKU') + '</small></td>' +
        '<td>' + methodBadge(row) + '</td>' +
        '<td><strong>' + Number(row.top_method_share_pct || 0).toFixed(1) + '%</strong></td>' +
        '<td>' + fmt(row.titles_count || 0) + '</td>' +
        '<td>' + fmt(row.nfs_count || 0) + '</td>' +
        '<td>' + fmt(row.methods_count || 0) + '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="6" class="text-center py-3 text-muted">Sem vinculos de metodos de pagamento por produto no periodo.</td></tr>';
  }

  function load() {
    if (!document.getElementById('financeProductPaymentMethods')) return Promise.resolve(null);
    var r = range();
    if (!r.start || !r.end) return Promise.resolve(null);
    var key = r.start + '|' + r.end;
    if (payload && loadKey === key) return Promise.resolve(payload);
    if (loadPromise && loadKey === key) return loadPromise;
    if (abortController) abortController.abort();
    abortController = new AbortController();
    loadKey = key;
    loadPromise = fetch('api/finance_product_payment_methods.php?start=' + encodeURIComponent(r.start) + '&end=' + encodeURIComponent(r.end) + '&limit=20', {
      cache: 'no-store', headers: { Accept: 'application/json' }, signal: abortController.signal
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data || data.ok === false) throw new Error(data && data.message || 'Falha ao cruzar produtos e metodos.');
        render(data);
        return data;
      });
    }).catch(function (error) {
      if (error && error.name === 'AbortError') return null;
      var meta = document.getElementById('financeProductMethodMeta');
      if (meta) meta.textContent = error.message || 'Dados indisponiveis';
      return null;
    }).finally(function () { loadPromise = null; });
    return loadPromise;
  }

  document.addEventListener('biomie:finance-overview-loaded', function () {
    payload = null;
    load();
  });
  function initProductPaymentMethods() {
    window.setTimeout(function () { if (!payload) load(); }, 1200);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initProductPaymentMethods, { once: true });
  } else {
    initProductPaymentMethods();
  }
  window.BIomieFinanceProductMethods = { load: load };
})();
