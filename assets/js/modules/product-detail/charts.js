(function () {
  'use strict';

  function _cv(name, fallback) {
    var v = getComputedStyle(document.body || document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }

  var B = window.BIomie || {};
  B.ProductDetail = B.ProductDetail || {};

  B.ProductDetail.Charts = {

    /**
     * Renders the timeline (evolução) tab inside the product drawer body.
     * Destroys any previous Chart.js instance before rendering.
     */
    renderTimeline: function (data) {
      var T       = B.ProductDetail.Tabs;
      var rows    = (data && data.timeline) || [];
      var tmeta   = (data && data.timeline_meta) || {};
      var es      = (data && data.empty_states && data.empty_states.sales) || {};

      if (!rows.length) {
        return es.is_empty
          ? (T ? T.empty(es.message || 'Produto sem dados de evolucao no periodo.') : '<div class="biomie-drawer__empty">Produto sem dados de evolucao no periodo.</div>')
          : '<div class="biomie-drawer__empty">Sem dados de evolucao temporal para este produto.</div>';
      }

      var bucketTitle = tmeta.bucket_title || 'Evolução';
      var canvasId = 'pd-timeline-chart-' + Date.now();
      var html = '<h4 class="pd-section-title" style="margin:0 0 8px;">' + B.ProductDetail.Tabs.esc(bucketTitle) + '</h4>' +
        '<div class="pd-chart-wrap"><canvas id="' + canvasId + '" height="220"></canvas></div>' +
        '<div class="pd-chart-note" style="font-size:0.75rem;color:var(--text-3);margin-top:8px;">Base: COALESCE(data_competencia, data_emissao)</div>';

      // Defer chart render until after DOM insertion
      window.setTimeout(function () {
        B.ProductDetail.Charts._drawTimeline(canvasId, rows);
      }, 50);

      return html;
    },

    _drawTimeline: function (canvasId, rows) {
      var canvas = document.getElementById(canvasId);
      if (!canvas || !window.Chart) return;

      // Destroy existing instance if any
      if (B._destroyChart) {
        try { B._destroyChart(canvasId); } catch (e) {}
      }
      if (window.BIOMIE_CHARTS && typeof BIOMIE_CHARTS.destroyChart === 'function') {
        try { BIOMIE_CHARTS.destroyChart(canvasId); } catch (e) {}
      }

      var labels   = rows.map(function (r) { return String(r.label || r.bucket || ''); });
      var revenues = rows.map(function (r) { return Number(r.revenue_raw != null ? r.revenue_raw : (r.revenue || 0)); });
      var qtys     = rows.map(function (r) { return Number(r.quantity || 0); });

      try {
        new Chart(canvas, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Receita (R$)',
                data: revenues,
                backgroundColor: _cv('--chart-revenue', '#96140a') + 'b3',
                borderRadius: 4,
                yAxisID: 'y'
              },
              {
                label: 'Quantidade',
                data: qtys,
                type: 'line',
                borderColor: _cv('--chart-neutral', '#64748b'),
                backgroundColor: 'transparent',
                tension: 0.3,
                pointRadius: 3,
                yAxisID: 'y2'
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'top' } },
            scales: {
              y:  { position: 'left',  beginAtZero: true, title: { display: true, text: 'Receita' } },
              y2: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Qtd' } }
            }
          }
        });
      } catch (e) {}
    }
  };

  window.BIomie = B;
}());
