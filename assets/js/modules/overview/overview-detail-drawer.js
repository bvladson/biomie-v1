/**
 * Overview Detail Drawer — drilldown acessível para os gráficos do dashboard.
 */
(function () {
  'use strict';

  var B = window.BIomie || (window.BIomie = {});
  var drawer, backdrop, titleEl, subtitleEl, closeBtn, tabsEl, bodyEl;
  var abortCtrl = null;
  var triggerEl = null;
  var activeChartId = '';
  var activeFilters = {};
  var previousBodyOverflow = '';
  var filterTimer = null;
  var cache = {};
  var CACHE_TTL = 60000;
  var RANKING_LIMIT = 1000;
  // Linhas atualmente visiveis por gráfico — o CSV exporta o que está na tela,
  // com os filtros aplicados, não o payload cru.
  var lastFilteredRows = {};
  // summary da API por gráfico: guarda o total do período (COUNT(*) OVER ()),
  // que é maior que o payload quando a lista foi truncada pelo LIMIT.
  var rankingSummary = {};
  var focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

  var chartTitles = {
    revenue_evolution: 'Evolução da Receita',
    vendor_ranking: 'Ranking Comercial',
    top_products: 'Produtos em destaque — Orç-PD',
    top_customers: 'Clientes em destaque — Orç-PD',
    top_invoices: 'Maiores Notas Fiscais',
    geo_compact: 'Geografia Resumida'
  };

  var chartSubtitles = {
    top_products: 'Receita, volume, frequência e preço médio dos produtos faturados no período.',
    top_customers: 'Receita, recorrência, CPF/CNPJ e responsáveis comerciais no período.'
  };

  function init() {
    drawer = document.getElementById('overviewDetailDrawer');
    backdrop = document.getElementById('overviewDetailBackdrop');
    titleEl = document.getElementById('overviewDetailTitle');
    subtitleEl = document.getElementById('overviewDetailSubtitle');
    closeBtn = document.getElementById('overviewDetailClose');
    tabsEl = document.getElementById('overviewDetailTabs');
    bodyEl = document.getElementById('overviewDetailBody');
    if (!drawer) return;

    closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) close();
    });
    drawer.addEventListener('keydown', trapFocus);

    document.addEventListener('click', function (event) {
      var button = event.target.closest('[data-drill]');
      if (!button || !button.dataset.drill) return;
      event.preventDefault();
      open({
        chartId: button.dataset.drill,
        title: chartTitles[button.dataset.drill] || 'Detalhamento',
        triggerElement: button
      });
    });
  }

  function isOpen() {
    return drawer && drawer.classList.contains('is-open');
  }

  function open(options) {
    if (!drawer) init();
    if (!drawer) return;

    var opts = options || {};
    var chartId = opts.chartId || '';
    var filters = mergeDetailFilters(opts.filters || {});

    triggerEl = opts.triggerElement || document.activeElement;
    activeChartId = chartId;
    activeFilters = filters;
    titleEl.textContent = opts.title || chartTitles[chartId] || 'Detalhamento';
    subtitleEl.textContent = opts.subtitle || chartSubtitles[chartId] || '';
    tabsEl.innerHTML = '';
    tabsEl.hidden = true;
    bodyEl.innerHTML = renderSkeleton();

    if (!isOpen()) previousBodyOverflow = document.body.style.overflow;
    drawer.setAttribute('aria-hidden', 'false');
    drawer.classList.add('is-open');
    backdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';

    setTimeout(function () {
      if (closeBtn) closeBtn.focus();
    }, 100);

    fetchDetail(chartId, filters);
  }

  function close() {
    if (!drawer) return;
    if (abortCtrl) {
      abortCtrl.abort();
      abortCtrl = null;
    }
    if (filterTimer) {
      clearTimeout(filterTimer);
      filterTimer = null;
    }

    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = previousBodyOverflow;

    if (triggerEl && typeof triggerEl.focus === 'function') triggerEl.focus();
    triggerEl = null;
  }

  function trapFocus(event) {
    if (event.key !== 'Tab') return;
    var elements = drawer.querySelectorAll(focusableSelector);
    if (!elements.length) return;
    var first = elements[0];
    var last = elements[elements.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function renderSkeleton() {
    return '<div class="drawer-skeleton" aria-label="Carregando detalhamento">' +
      '<div class="skeleton-block"></div><div class="skeleton-block"></div>' +
      '<div class="skeleton-block"></div><div class="skeleton-block"></div></div>';
  }

  function periodValues() {
    var dashboardFilters = dashboardFilterValues();
    var start = document.getElementById('dateStart');
    var end = document.getElementById('dateEnd');
    return {
      start: String(dashboardFilters.start || (start && start.value) || ''),
      end: String(dashboardFilters.end || (end && end.value) || '')
    };
  }

  function dashboardFilterValues() {
    if (window.BDashboard && typeof window.BDashboard.getFilters === 'function') {
      try {
        return window.BDashboard.getFilters() || {};
      } catch (ignore) {}
    }

    function fieldValue(id) {
      var field = document.getElementById(id);
      return field ? String(field.value || '').trim() : '';
    }

    var navRange = window.NavContext && typeof window.NavContext.getDateRange === 'function'
      ? window.NavContext.getDateRange()
      : {};
    var params;
    try {
      params = new URLSearchParams(window.location.search || '');
    } catch (ignore) {
      params = new URLSearchParams();
    }

    return {
      start: fieldValue('dateStart') || String(navRange.start || ''),
      end: fieldValue('dateEnd') || String(navRange.end || ''),
      vendor_ids_csv: fieldValue('f_dash_vendor_id') || String(params.get('vendor_ids') || ''),
      vendor_id: String(params.get('vendor_id') || ''),
      cliente: fieldValue('f_dash_cliente'),
      produto: fieldValue('f_dash_produto'),
      channel: String(params.get('channel') || '')
    };
  }

  function mergeDetailFilters(explicitFilters) {
    var dashboard = dashboardFilterValues();
    var merged = {};
    var inheritedNames = ['vendor_id', 'cliente', 'produto', 'quick', 'channel'];

    inheritedNames.forEach(function (name) {
      if (dashboard[name] != null && String(dashboard[name]).trim() !== '') {
        merged[name] = String(dashboard[name]).trim();
      }
    });

    var vendorIds = dashboard.vendor_ids_csv || dashboard.vendor_ids || '';
    if (Array.isArray(vendorIds)) vendorIds = vendorIds.join(',');
    if (String(vendorIds).trim()) merged.vendor_ids = String(vendorIds).trim();

    Object.keys(explicitFilters || {}).forEach(function (name) {
      var value = explicitFilters[name];
      if (value == null || String(value).trim() === '') delete merged[name];
      else merged[name] = value;
    });
    return merged;
  }

  function cacheKey(chartId, filters, period) {
    return chartId + ':' + period.start + ':' + period.end + ':' + JSON.stringify(filters || {});
  }

  function fetchDetail(chartId, filters) {
    var period = periodValues();
    var key = cacheKey(chartId, filters, period);
    var cached = cache[key];
    if (cached && Date.now() - cached.ts < CACHE_TTL) {
      renderDetail(chartId, cached.data);
      return;
    }

    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();

    var params = new URLSearchParams({
      chart_id: chartId,
      start: period.start,
      end: period.end
    });
    Object.keys(filters).forEach(function (name) {
      if (filters[name]) params.set(name, filters[name]);
    });
    if (chartId === 'top_products' || chartId === 'top_customers') {
      // 200 truncava de verdade: a MAG_FILIAL tem 536 produtos e 1.284 clientes
      // no ano. Quando ainda assim faltar linha, a tela avisa (ver renderTruncationNotice)
      // em vez de deixar o usuario achar que esta vendo tudo.
      params.set('limit', String(RANKING_LIMIT));
    }

    function handleResponse(response) {
      return response.text().then(function (text) {
        var data = null;
        try {
          data = text ? JSON.parse(text) : null;
        } catch (ignore) {}
        if (!response.ok) {
          throw data || {
            message: 'Erro HTTP ' + response.status,
            request_id: response.headers.get('X-Request-Id') || 'N/A',
            diagnostics: { content_type: response.headers.get('Content-Type') || 'desconhecido' }
          };
        }
        return data || {};
      });
    }

    fetch('api/overview_detail.php?' + params.toString(), {
      credentials: 'same-origin',
      signal: abortCtrl.signal
    })
      .then(handleResponse)
      .then(function (data) {
        cache[key] = { data: data, ts: Date.now() };
        abortCtrl = null;
        renderDetail(chartId, data);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        abortCtrl = null;
        renderError(error.message || 'Erro inesperado.', error.request_id, chartId, error.diagnostics);
      });
  }

  function renderError(message, requestId, chartId, diagnostics) {
    var details = '';
    if (requestId || chartId) {
      details += '<div class="overview-detail-diagnostics">';
      if (requestId) details += '<div>Req ID: ' + escapeHtml(requestId) + '</div>';
      if (chartId) details += '<div>Gráfico: ' + escapeHtml(chartId) + '</div>';
      if (diagnostics && diagnostics.handler) details += '<div>Handler: ' + escapeHtml(diagnostics.handler) + '</div>';
      details += '</div>';
    }

    bodyEl.innerHTML = '<div class="module-alert module-alert--info">' +
      '<strong>Detalhamento indisponível</strong><br>' +
      '<span>' + escapeHtml(message || 'Erro ao carregar dados.') + '</span>' +
      details +
      '<div class="overview-detail-retry"><button type="button" class="btn-detail" data-overview-retry="' +
        escapeHtml(chartId || '') + '">Tentar novamente</button></div></div>';

    var retry = bodyEl.querySelector('[data-overview-retry]');
    if (retry) {
      retry.addEventListener('click', function () {
        cache = {};
        fetchDetail(chartId || activeChartId || '', activeFilters);
      });
    }
  }

  function renderDetail(chartId, data) {
    if (!data || data.ok === false) {
      renderError(
        data ? data.message : 'Sem dados disponíveis.',
        data ? data.request_id : null,
        chartId,
        data ? data.diagnostics : null
      );
      return;
    }

    var rows = data.rows || data.items || [];
    if (!rows.length) {
      var warnings = data.meta && Array.isArray(data.meta.warnings) ? data.meta.warnings : [];
      bodyEl.innerHTML = '<div class="dashboard-empty-state">' +
        '<span class="material-symbols-rounded" aria-hidden="true">search_off</span>' +
        '<strong>Sem dados para este filtro</strong>' +
        '<p>Não foram encontrados registros para o detalhamento solicitado.</p>' +
        (warnings.length ? '<div class="small text-muted overview-detail-warnings">' +
          warnings.map(escapeHtml).join('<br>') + '</div>' : '') +
        '</div>';
      return;
    }

    if (chartId === 'top_products' || chartId === 'top_customers') {
      renderRankingDetail(chartId, rows, data.summary);
      return;
    }

    // `pipeline_period` e `monthly_comparison` delegam ao MESMO handler de
    // receita no PHP, entao tem a mesma forma de linha e usam o mesmo
    // renderizador. Sem isto, os dois cairiam na tabela generica e exibiriam as
    // chaves cruas da agregacao.
    if (chartId === 'revenue_evolution' || chartId === 'pipeline_period' ||
        chartId === 'monthly_comparison') {
      renderRevenueEvolutionDetail(chartId, rows, data.summary);
      return;
    }

    renderGenericDetail(chartId, rows, data.summary);
  }

  function renderGenericDetail(chartId, rows, summary) {
    var columns = detectColumns(chartId, rows[0]);
    var html = '<div class="overview-ranking__table-wrap"><table class="mag-table"><thead><tr>';
    columns.forEach(function (column) {
      html += '<th>' + escapeHtml(column.label) + '</th>';
    });
    html += '</tr></thead><tbody>';

    rows.forEach(function (row) {
      html += '<tr>';
      columns.forEach(function (column) {
        var value = row[column.key];
        if (column.key === 'vendor_name' && typeof B.renderGoalBadge === 'function') {
          html += '<td class="overview-detail-vendor">' +
            escapeHtml(value == null || value === '' ? '-' : value) + B.renderGoalBadge(row) + '</td>';
        } else {
          if (column.format === 'currency') value = formatCurrency(value);
          else if (column.format === 'number') value = formatNumber(value);
          else if (column.format === 'date' && typeof B.formatMetaDate === 'function') value = B.formatMetaDate(value || '');
          else value = escapeHtml(value == null || value === '' ? '-' : value);
          html += '<td>' + value + '</td>';
        }
      });
      html += '</tr>';
    });
    html += '</tbody></table></div>';

    if (summary) {
      var summaryHtml = '<div class="module-tiny-kpis overview-detail-summary">';
      Object.keys(summary).forEach(function (name) {
        summaryHtml += '<div class="mini"><span>' + escapeHtml(name.replace(/_/g, ' ')) +
          '</span><strong>' + escapeHtml(summary[name]) + '</strong></div>';
      });
      html = summaryHtml + '</div>' + html;
    }
    bodyEl.innerHTML = html;
  }

  function escapeHtml(value) {
    if (typeof B.escapeHtml === 'function') return B.escapeHtml(String(value == null ? '' : value));
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
    });
  }

  function decodeEntityText(value) {
    var text = String(value == null ? '' : value);
    var named = {
      amp: '&',
      lt: '<',
      gt: '>',
      quot: '"',
      apos: "'",
      nbsp: '\u00a0'
    };
    for (var pass = 0; pass < 3; pass += 1) {
      var decoded = text.replace(/&(#x[\da-f]+|#\d+|amp|lt|gt|quot|apos|nbsp);/gi, function (match, entity) {
        var lower = entity.toLowerCase();
        if (named[lower] != null) return named[lower];
        var codePoint = lower.indexOf('#x') === 0
          ? parseInt(lower.slice(2), 16)
          : parseInt(lower.slice(1), 10);
        if (!Number.isFinite(codePoint) || codePoint < 0 || codePoint > 0x10ffff) return match;
        try {
          return String.fromCodePoint(codePoint);
        } catch (ignore) {
          return match;
        }
      });
      if (decoded === text) break;
      text = decoded;
    }
    return text;
  }

  function numberValue(value) {
    var parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function formatCurrency(value) {
    if (typeof B.formatCurrency === 'function') return B.formatCurrency(numberValue(value));
    return numberValue(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  function formatNumber(value, maximumFractionDigits) {
    var number = numberValue(value);
    if (typeof B.formatNumber === 'function' && maximumFractionDigits == null) return B.formatNumber(number);
    return number.toLocaleString('pt-BR', {
      maximumFractionDigits: maximumFractionDigits == null ? 2 : maximumFractionDigits
    });
  }

  function normalizeText(value) {
    return String(value == null ? '' : value)
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
  }

  /**
   * Numero em pt-BR. O ponto e separador de MILHAR, nao decimal.
   *
   * A versao anterior so tratava o ponto quando havia virgula junto, entao
   * "10.000" virava 10 e "1.234.567" virava null — o filtro de receita minima
   * silenciosamente nao filtrava nada, e o usuario nao tinha como perceber.
   */
  function parseLocaleNumber(value) {
    var raw = String(value == null ? '' : value).trim().replace(/\s/g, '');
    if (!raw) return null;

    var negative = raw.indexOf('-') === 0;
    raw = raw.replace(/[^\d.,]/g, '');
    if (!raw) return null;

    if (raw.indexOf(',') >= 0) {
      // Virgula presente ⇒ ela e o decimal e todo ponto e milhar.
      raw = raw.replace(/\./g, '').replace(',', '.');
    } else if (/^\d{1,3}(\.\d{3})+$/.test(raw)) {
      // So pontos, em grupos de 3 ⇒ milhar puro ("1.500", "1.234.567").
      raw = raw.replace(/\./g, '');
    }
    // Qualquer outro caso com ponto ("1.5") fica como decimal.

    var parsed = Number(raw);
    if (!Number.isFinite(parsed)) return null;
    return negative ? -parsed : parsed;
  }

  function rawDocument(row) {
    return row.client_document || row.customer_document || row.document || row.cpf_cnpj || '';
  }

  function documentDigits(row) {
    return String(rawDocument(row)).replace(/\D/g, '');
  }

  function documentType(row) {
    var explicitType = normalizeText(
      row.client_document_type || row.customer_document_type || row.document_type || row.person_type || ''
    );
    if (explicitType === 'cpf' || explicitType === 'pf' || explicitType === 'pessoa fisica') return 'cpf';
    if (explicitType === 'cnpj' || explicitType === 'pj' || explicitType === 'pessoa juridica') return 'cnpj';
    var raw = String(rawDocument(row) || '');
    if (raw.indexOf('/') !== -1) return 'cnpj';
    if (/^\*{3}\.\*{3}\.\*{3}-\*{2}$/.test(raw)) return 'cpf';
    var digits = documentDigits(row);
    if (digits.length === 11) return 'cpf';
    if (digits.length === 14) return 'cnpj';
    return 'unknown';
  }

  function formatDocument(row) {
    var raw = String(rawDocument(row) || '').trim();
    if (!raw) return 'Não informado';
    if (/[*xX]/.test(raw)) return raw;
    var digits = documentDigits(row);
    if (digits.length === 11) return digits.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    if (digits.length === 14) return digits.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    return raw;
  }

  function sellerName(row) {
    var value = row.vendor_names || row.seller_names || row.vendor_name || row.seller_name ||
      row.salesperson_name || row.vendors || row.sellers || '';
    if (Array.isArray(value)) {
      return value.map(function (item) {
        if (typeof item === 'object' && item) {
          return item.name || item.vendor_name || item.seller_name || '';
        }
        return item;
      }).filter(Boolean).map(decodeEntityText).join(', ');
    }
    return decodeEntityText(value).trim();
  }

  function formatDate(value) {
    var raw = String(value == null ? '' : value).trim();
    if (!raw) return 'Não informado';
    var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (match) return match[3] + '/' + match[2] + '/' + match[1];
    if (typeof B.formatMetaDate === 'function') {
      var formatted = B.formatMetaDate(raw);
      if (formatted) return formatted;
    }
    return raw;
  }

  function sellerList(row) {
    var sellers = sellerName(row);
    if (!sellers) return 'Não informado';
    return sellers.split(/\s*[|;]\s*/).filter(Boolean).join(', ');
  }

  /** Desconto em R$ com o % sobre o bruto (receita + desconto). */
  function discountLabel(netValue, discountValue) {
    var net = numberValue(netValue);
    var discount = numberValue(discountValue);
    if (discount <= 0) return 'Sem desconto';
    var gross = net + discount;
    return formatCurrency(discount) +
      (gross > 0 ? ' (' + formatNumber(discount * 100 / gross, 1) + '% do bruto)' : '');
  }

  function detailItem(label, value) {
    if (value == null || value === '') return '';
    return '<div class="overview-ranking__detail-item"><span>' + escapeHtml(label) +
      '</span><strong>' + escapeHtml(value) + '</strong></div>';
  }

  /**
   * Campos que a API ja calcula na MESMA query e que a tabela nao tinha onde
   * exibir. Exibir aqui nao custa nenhuma consulta a mais.
   */
  function customerDetailPanel(row) {
    var vendors = numberValue(row.vendors_count);
    return detailItem('Primeira compra no período', formatDate(row.first_purchase)) +
      detailItem('Última compra', formatDate(row.last_purchase)) +
      detailItem('NF mais recente', row.latest_nf ? String(row.latest_nf) : 'Não informado') +
      detailItem('Mercadorias', formatCurrency(row.merchandise_total)) +
      detailItem('Desconto concedido', discountLabel(row.invoice_total, row.discount_total)) +
      detailItem(
        'Vendedores no período',
        formatNumber(vendors, 0) + (vendors > 1 ? ' — carteira compartilhada' : '')
      ) +
      detailItem('Responsáveis', sellerList(row));
  }

  function productDetailPanel(row) {
    return detailItem('Clientes que compraram', formatNumber(row.customers_count, 0)) +
      detailItem('Receita média por NF', formatCurrency(row.average_revenue_per_nf)) +
      detailItem('Qtd. média por NF', formatNumber(row.average_quantity_per_nf, 2)) +
      detailItem('Desconto concedido', discountLabel(row.revenue, row.discount_total)) +
      detailItem('Primeira venda no período', formatDate(row.first_sale)) +
      detailItem('Última venda', formatDate(row.last_sale)) +
      detailItem('NF mais recente', row.latest_nf ? String(row.latest_nf) : 'Não informado') +
      detailItem('Vendedores que venderam', formatNumber(row.vendors_count, 0)) +
      detailItem('Quem vendeu', sellerList(row));
  }

  /** Lista separada por "|" vinda do GROUP_CONCAT, ja limitada no SQL. */
  function pipeList(value) {
    return String(value == null ? '' : value)
      .split('|')
      .map(function (item) { return decodeEntityText(item).trim(); })
      .filter(function (item) { return item !== ''; });
  }

  function namesLabel(value, total) {
    var names = pipeList(value);
    if (!names.length) return null;
    var restante = numberValue(total) - names.length;
    return names.join(', ') + (restante > 0 ? ' e mais ' + formatNumber(restante, 0) : '');
  }

  /**
   * Detalhes do DIA. Tudo vem da mesma agregacao da API, entao exibir aqui nao
   * custa consulta nenhuma — mesma disciplina dos paineis de produto/cliente.
   *
   * `mediaDiaria` entra para o numero do dia ter contra o que ser lido: R$ 9 mil
   * nao diz nada sozinho; "R$ 9 mil, 40% acima da media do periodo" diz.
   */
  var DIAS_DA_SEMANA = ['', 'domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

  function weekdayLabel(row) {
    var dia = Number(row.weekday);
    return Number.isFinite(dia) && DIAS_DA_SEMANA[dia] ? DIAS_DA_SEMANA[dia] : '';
  }

  /**
   * Veredito do dia contra a faixa normal daquele dia da semana, calculada pelo
   * `daily_baseline` do servico Python.
   *
   * `sem_referencia` NAO e o mesmo que "dentro do normal": tres das quatro
   * empresas nao tem amostra para faixa nenhuma (a EXPERT tem 2 sextas-feiras
   * no historico inteiro), e dizer "normal" para elas seria afirmar o que
   * ninguem mediu.
   */
  function baselineVerdict(row) {
    var confianca = String(row.baseline_confidence || '');
    if (!confianca || confianca === 'sem_amostra') return null;
    var revenue = numberValue(row.revenue);
    var p10 = row.baseline_p10 == null ? null : numberValue(row.baseline_p10);
    var p90 = row.baseline_p90 == null ? null : numberValue(row.baseline_p90);
    if (p10 == null || p90 == null) return null;

    if (revenue > p90) return { estado: 'acima', rotulo: 'Acima do normal' };
    if (revenue < p10) return { estado: 'abaixo', rotulo: 'Abaixo do normal' };
    return { estado: 'dentro', rotulo: 'Dentro do normal' };
  }

  function baselineChip(row) {
    var veredito = baselineVerdict(row);
    if (!veredito) return '<span class="text-muted">—</span>';
    return '<span class="overview-ranking__verdict overview-ranking__verdict--' +
      veredito.estado + '">' + escapeHtml(veredito.rotulo) + '</span>';
  }

  function revenueEvolutionDetailPanel(row, mediaDiaria) {
    var revenue = numberValue(row.revenue);
    var invoices = numberValue(row.invoices_count);
    var topValue = numberValue(row.top_invoice_value);
    var topCode = decodeEntityText(row.top_invoice_code || '').trim();
    var topClient = decodeEntityText(row.top_invoice_client || '').trim();

    var maiorNf = topValue > 0
      ? formatCurrency(topValue) +
        (topCode ? ' — NF ' + topCode : '') +
        (topClient ? ' — ' + topClient : '')
      : null;

    // Concentracao: uma unica NF respondendo pelo dia inteiro nao e "um bom
    // dia", e um evento isolado — a mesma leitura que a coluna "Clientes" dos
    // produtos permite fazer.
    var concentracao = revenue > 0 && topValue > 0
      ? formatNumber(topValue * 100 / revenue, 1) + '% do dia numa NF só'
      : null;

    var comparativo = null;
    if (mediaDiaria > 0) {
      var variacao = (revenue - mediaDiaria) * 100 / mediaDiaria;
      comparativo = (variacao >= 0 ? '+' : '') + formatNumber(variacao, 1) + '% vs. média diária (' +
        formatCurrency(mediaDiaria) + ')';
    }

    // Faixa normal daquele dia da semana. Sem ela, "R$ 12.575 num sábado" nao
    // se compara com nada; com ela, o gestor sabe se precisa olhar.
    var faixa = null;
    var amostra = numberValue(row.baseline_samples);
    if (row.baseline_p25 != null && row.baseline_p75 != null && amostra > 0) {
      faixa = formatCurrency(row.baseline_p25) + ' a ' + formatCurrency(row.baseline_p75) +
        ' (mediana ' + formatCurrency(row.baseline_median) + ', ' +
        formatNumber(amostra, 0) + ' ' + weekdayLabel(row) + 's)';
    } else if (String(row.baseline_confidence || '') === 'sem_amostra') {
      faixa = 'Histórico insuficiente para esta ' + (weekdayLabel(row) || 'data');
    }

    // Faixa de agregados COMPACTA — contexto, não o conteúdo. O que o usuário
    // pediu ao abrir o dia são as NOTAS, e elas vêm logo abaixo.
    var contexto = detailItem('Ticket médio da NF', formatCurrency(
        row.average_ticket != null ? row.average_ticket : (invoices > 0 ? revenue / invoices : 0))) +
      detailItem('Faixa normal da ' + (weekdayLabel(row) || 'data'), faixa) +
      detailItem('Concentração', concentracao) +
      detailItem('Receita bruta', formatCurrency(row.gross_revenue)) +
      detailItem('Desconto concedido', discountLabel(revenue, numberValue(row.gross_revenue) - revenue)) +
      detailItem('Comparação', comparativo);

    return '<div class="overview-ranking__detail-context">' + contexto + '</div>' +
      invoiceTable(row);
  }

  /**
   * AS NOTAS DO DIA — o detalhe de verdade.
   *
   * A primeira versão deste painel mostrava só agregados (maior NF, lista de
   * clientes, lista de vendedores). Isso é RESUMO: descreve o dia sem mostrar o
   * que aconteceu nele. "Produtos em destaque" lista os 58 produtos, um por
   * linha — o equivalente aqui é listar as notas.
   */
  function invoiceTable(row) {
    var notas = Array.isArray(row.invoices) ? row.invoices : [];
    var revenue = numberValue(row.revenue);

    if (!notas.length) {
      // Dia fora do teto de carregamento não é dia sem movimento — dizer isso
      // é o mesmo erro do LIMIT sem aviso que os outros drawers já corrigiram.
      var motivo = row.invoices_loaded === false
        ? 'Notas deste dia não carregadas (período longo demais). Reduza o período para vê-las.'
        : 'Nenhuma nota detalhada para este dia.';
      return '<p class="text-muted overview-ranking__detail-empty">' + escapeHtml(motivo) + '</p>';
    }

    var html = '<div class="overview-ranking__nested"><table class="mag-table mag-table--compact">' +
      '<thead><tr><th>NF</th><th>Cliente</th><th>Vendedor</th><th>Canal</th>' +
      '<th class="is-numeric">Valor</th><th class="is-numeric">% do dia</th></tr></thead><tbody>';

    notas.forEach(function (nota) {
      var valor = numberValue(nota.revenue);
      var parte = revenue > 0 ? valor * 100 / revenue : 0;
      html += '<tr>' +
        '<td><span class="overview-ranking__code">' +
          escapeHtml(String(nota.invoice_code || '—')) + '</span></td>' +
        '<td>' + escapeHtml(decodeEntityText(nota.client)) + '</td>' +
        '<td>' + escapeHtml(decodeEntityText(nota.vendor_name)) + '</td>' +
        '<td>' + escapeHtml(String(nota.channel || '—')) + '</td>' +
        '<td class="is-numeric"><strong>' + formatCurrency(valor) + '</strong></td>' +
        '<td class="is-numeric"><span class="overview-ranking__share">' +
          formatNumber(parte, 1) + '%</span></td></tr>';
    });

    return html + '</tbody></table></div>';
  }

  /** Ultima movimentacao como timestamp ordenavel; sem data vai para o fim. */
  function recencyValue(row, isCustomer) {
    var raw = String((isCustomer ? row.last_purchase : row.last_sale) || '').slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) return 0;
    var parsed = Date.parse(raw + 'T00:00:00');
    return Number.isFinite(parsed) ? parsed : 0;
  }

  /** Uma celula de CSV: separador ";" (Excel pt-BR) e aspas duplicadas. */
  function csvCell(value) {
    var text = String(value == null ? '' : value).replace(/"/g, '""');
    return '"' + text + '"';
  }

  function csvNumber(value) {
    // Decimal com virgula para o Excel em pt-BR nao ler como texto.
    return csvCell(String(numberValue(value).toFixed(2)).replace('.', ','));
  }

  function exportRankingCsv(chartId, root) {
    var isCustomer = chartId === 'top_customers';
    var rows = lastFilteredRows[chartId] || [];
    if (!rows.length) return;

    var header = isCustomer
      ? ['#', 'Cliente', 'ID Omie', 'CPF/CNPJ', 'Tipo', 'Vendedores', 'Qtd vendedores',
         'NFs', 'Ticket medio', 'Receita', 'Mercadorias', 'Desconto', 'Share %',
         'Primeira compra', 'Ultima compra', 'NF mais recente']
      : ['#', 'Produto', 'Codigo', 'Quantidade', 'NFs', 'Clientes', 'Vendedores',
         'Preco medio', 'Receita media por NF', 'Qtd media por NF', 'Receita',
         'Desconto', 'Part. da lista %', 'Primeira venda', 'Ultima venda', 'NF mais recente'];

    var loadedRevenue = rows.reduce(function (sum, row) {
      return sum + numberValue(isCustomer ? row.invoice_total : row.revenue);
    }, 0);

    var lines = [header.map(csvCell).join(';')];
    rows.forEach(function (row, index) {
      if (isCustomer) {
        var notes = numberValue(row.notes_count);
        lines.push([
          csvCell(index + 1),
          csvCell(decodeEntityText(row.client_name)),
          csvCell(row.client_id || ''),
          csvCell(formatDocument(row)),
          csvCell(documentType(row).toUpperCase()),
          csvCell(sellerList(row)),
          csvCell(numberValue(row.vendors_count)),
          csvCell(notes),
          csvNumber(row.average_ticket != null ? row.average_ticket : (notes > 0 ? numberValue(row.invoice_total) / notes : 0)),
          csvNumber(row.invoice_total),
          csvNumber(row.merchandise_total),
          csvNumber(row.discount_total),
          csvNumber(row.share_pct),
          csvCell(formatDate(row.first_purchase)),
          csvCell(formatDate(row.last_purchase)),
          csvCell(row.latest_nf || '')
        ].join(';'));
      } else {
        var quantity = numberValue(row.quantity);
        var revenue = numberValue(row.revenue);
        lines.push([
          csvCell(index + 1),
          csvCell(decodeEntityText(row.description)),
          csvCell(row.product_code || row.sku || row.code || ''),
          csvNumber(quantity),
          csvCell(numberValue(row.orders)),
          csvCell(numberValue(row.customers_count)),
          csvCell(numberValue(row.vendors_count)),
          csvNumber(quantity > 0 ? revenue / quantity : 0),
          csvNumber(row.average_revenue_per_nf),
          csvNumber(row.average_quantity_per_nf),
          csvNumber(revenue),
          csvNumber(row.discount_total),
          csvNumber(loadedRevenue > 0 ? revenue * 100 / loadedRevenue : 0),
          csvCell(formatDate(row.first_sale)),
          csvCell(formatDate(row.last_sale)),
          csvCell(row.latest_nf || '')
        ].join(';'));
      }
    });

    var period = periodValues();
    var name = (isCustomer ? 'clientes-destaque' : 'produtos-destaque') +
      (period.start ? '_' + period.start : '') + (period.end ? '_a_' + period.end : '') + '.csv';

    // BOM: sem ele o Excel abre UTF-8 como Latin-1 e quebra os acentos.
    var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 1000);
  }

  var lastRevenueRows = [];

  /**
   * CSV com uma linha por NOTA, nao por dia.
   *
   * Exportar o agregado seria levar para o Excel o mesmo resumo que a tela ja
   * mostra. Quem exporta quer cruzar nota a nota.
   */
  function exportRevenueCsv() {
    var linhas = [[
      'Data', 'Dia da semana', 'NF', 'Cliente', 'Vendedor', 'Canal',
      'Valor', 'Valor bruto', 'Pct do dia', 'Receita do dia', 'Contra o normal'
    ].map(csvCell).join(';')];

    lastRevenueRows.forEach(function (dia) {
      var receitaDia = numberValue(dia.revenue);
      var veredito = baselineVerdict(dia);
      var notas = Array.isArray(dia.invoices) ? dia.invoices : [];
      if (!notas.length) return;
      notas.forEach(function (nota) {
        var valor = numberValue(nota.revenue);
        linhas.push([
          csvCell(String(dia.date || '').slice(0, 10)),
          csvCell(weekdayLabel(dia)),
          csvCell(nota.invoice_code || ''),
          csvCell(decodeEntityText(nota.client)),
          csvCell(decodeEntityText(nota.vendor_name)),
          csvCell(nota.channel || ''),
          csvNumber(valor),
          csvNumber(nota.gross_revenue),
          csvNumber(receitaDia > 0 ? valor * 100 / receitaDia : 0),
          csvNumber(receitaDia),
          csvCell(veredito ? veredito.rotulo : 'sem referencia')
        ].join(';'));
      });
    });

    if (linhas.length === 1) return;

    var period = periodValues();
    var nome = 'evolucao-receita' + (period.start ? '_' + period.start : '') +
      (period.end ? '_a_' + period.end : '') + '.csv';
    // BOM: sem ele o Excel abre UTF-8 como Latin-1 e quebra os acentos.
    // CRLF montado por código: escrever a sequência literal aqui já foi
    // reescrita por camada de ferramenta e quebrou o arquivo.
    var quebra = String.fromCharCode(13, 10);
    var bom = String.fromCharCode(0xFEFF);
    var blob = new Blob([bom + linhas.join(quebra)], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = nome;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function detailToggleCell(kind, index) {
    var domId = 'rankingDetail-' + kind + '-' + index;
    return '<td class="overview-ranking__rank">' +
      '<button type="button" class="overview-ranking__toggle" aria-expanded="false" aria-controls="' + domId + '">' +
        '<span class="material-symbols-rounded" aria-hidden="true">chevron_right</span>' +
        '<span class="overview-ranking__toggle-rank">' + (index + 1) + '</span>' +
        '<span class="sr-only">Ver mais detalhes</span>' +
      '</button></td>';
  }

  function detailRow(kind, index, colspan, panelHtml) {
    return '<tr class="overview-ranking__detail-row" id="rankingDetail-' + kind + '-' + index + '" hidden>' +
      '<td colspan="' + colspan + '"><div class="overview-ranking__detail">' + panelHtml + '</div></td></tr>';
  }

  function bindDetailToggles(container) {
    container.querySelectorAll('.overview-ranking__toggle').forEach(function (button) {
      button.addEventListener('click', function () {
        var target = document.getElementById(button.getAttribute('aria-controls'));
        if (!target) return;
        var expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        target.hidden = expanded;
        var icon = button.querySelector('.material-symbols-rounded');
        if (icon) icon.textContent = expanded ? 'chevron_right' : 'expand_more';
      });
    });
  }

  /** Nomes de vendedor da linha, ja separados. */
  function sellerNames(row) {
    var seller = sellerName(row);
    if (!seller) return [];
    return seller.split(/\s*[|;]\s*/).map(function (name) {
      return name.trim();
    }).filter(Boolean);
  }

  /**
   * Casamento EXATO, nunca substring.
   *
   * "ALEXANDRE SOUZA" e substring de "ALEXANDRE SOUZA - REV" — com indexOf,
   * filtrar o vendedor de varejo trazia junto as notas de revenda dele e
   * misturava os canais que a separacao varejo/revenda existe para manter
   * apartados. Mesmo caso de NUBIA OLIVEIRA e SAMANTHA ROCHA.
   */
  function rowHasSeller(row, wanted) {
    var target = normalizeText(wanted);
    return sellerNames(row).some(function (name) {
      return normalizeText(name) === target;
    });
  }

  function uniqueSellers(rows) {
    var values = {};
    rows.forEach(function (row) {
      sellerNames(row).forEach(function (name) {
        values[name] = true;
      });
    });
    return Object.keys(values).sort(function (a, b) {
      return a.localeCompare(b, 'pt-BR');
    });
  }

  /**
   * Total real do periodo (COUNT(*) OVER () no servidor, antes do LIMIT).
   * Sem isso a tela mostra a fatia carregada como se fosse o universo.
   */
  function serverTotal(chartId, summary) {
    if (!summary) return null;
    var value = chartId === 'top_customers' ? summary.clients_count : summary.products_count;
    var parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }

  function renderTruncationNotice(chartId, loaded, total) {
    if (total == null || total <= loaded) return '';
    var noun = chartId === 'top_customers' ? 'clientes' : 'produtos';
    return '<div class="module-alert module-alert--info overview-ranking__truncated">' +
      '<span class="material-symbols-rounded" aria-hidden="true">info</span>' +
      '<span>Exibindo os <strong>' + formatNumber(loaded, 0) + '</strong> maiores de <strong>' +
        formatNumber(total, 0) + '</strong> ' + noun + ' do período. ' +
        'Os percentuais abaixo são calculados sobre o que está carregado, não sobre o total. ' +
        'Reduza o período ou use os filtros para trabalhar com a lista completa.</span></div>';
  }

  function renderRankingDetail(chartId, rows, summary) {
    var isCustomer = chartId === 'top_customers';
    rankingSummary[chartId] = summary || null;
    var total = serverTotal(chartId, summary);
    var sellers = isCustomer ? uniqueSellers(rows) : [];
    var sortOptions = isCustomer
      ? [
          ['revenue_desc', 'Maior receita'],
          ['revenue_asc', 'Menor receita'],
          ['nfs_desc', 'Mais NFs'],
          ['nfs_asc', 'Menos NFs'],
          ['recency_desc', 'Compra mais recente'],
          ['recency_asc', 'Parado há mais tempo'],
          ['name_asc', 'Cliente A–Z']
        ]
      : [
          ['revenue_desc', 'Maior receita'],
          ['revenue_asc', 'Menor receita'],
          ['quantity_desc', 'Maior quantidade'],
          ['quantity_asc', 'Menor quantidade'],
          ['unit_desc', 'Maior preço médio'],
          ['customers_desc', 'Mais clientes'],
          ['customers_asc', 'Menos clientes (concentrado)'],
          ['recency_desc', 'Venda mais recente'],
          ['recency_asc', 'Parado há mais tempo'],
          ['name_asc', 'Produto A–Z']
        ];

    var html = '<section class="overview-ranking" data-ranking-kind="' +
      (isCustomer ? 'customers' : 'products') + '">' +
      '<div class="overview-ranking__toolbar" role="search" aria-label="Filtros do detalhamento">' +
        '<label class="overview-ranking__field overview-ranking__field--search">' +
          '<span>' + (isCustomer ? 'Buscar cliente, documento ou vendedor' : 'Buscar produto ou código') + '</span>' +
          '<span class="overview-ranking__input-wrap"><span class="material-symbols-rounded" aria-hidden="true">search</span>' +
            '<input type="search" data-ranking-filter="search" autocomplete="off" placeholder="' +
              (isCustomer ? 'Nome, CPF/CNPJ ou vendedor' : 'Descrição ou código do produto') + '">' +
          '</span></label>' +
        '<label class="overview-ranking__field"><span>Ordenar por</span><select data-ranking-filter="sort">' +
          sortOptions.map(function (option) {
            return '<option value="' + option[0] + '">' + escapeHtml(option[1]) + '</option>';
          }).join('') +
        '</select></label>';

    if (isCustomer) {
      html += '<label class="overview-ranking__field"><span>Tipo de documento</span>' +
        '<select data-ranking-filter="documentType">' +
          '<option value="all">CPF e CNPJ</option><option value="cnpj">Somente CNPJ</option>' +
          '<option value="cpf">Somente CPF</option><option value="unknown">Não identificado</option>' +
        '</select></label>' +
        '<label class="overview-ranking__field"><span>Vendedor</span>' +
          '<select data-ranking-filter="seller"' + (sellers.length ? '' : ' disabled') + '>' +
            '<option value="all">' + (sellers.length ? 'Todos os vendedores' : 'Vendedor não informado pela base') + '</option>' +
            sellers.map(function (seller) {
              return '<option value="' + escapeHtml(seller) + '">' + escapeHtml(seller) + '</option>';
            }).join('') +
          '</select></label>';
    } else {
      html += '<label class="overview-ranking__field"><span>Quantidade mínima</span>' +
        '<input type="text" inputmode="decimal" data-ranking-filter="minQuantity" placeholder="Ex.: 5"></label>';
    }

    html += '<label class="overview-ranking__field"><span>Receita mínima</span>' +
        '<input type="text" inputmode="decimal" data-ranking-filter="minRevenue" placeholder="R$ 0,00"></label>' +
      '<label class="overview-ranking__field"><span>Receita máxima</span>' +
        '<input type="text" inputmode="decimal" data-ranking-filter="maxRevenue" placeholder="Sem limite"></label>' +
      '<div class="overview-ranking__toolbar-action"><button type="button" class="overview-ranking__clear" data-ranking-clear>' +
        '<span class="material-symbols-rounded" aria-hidden="true">filter_alt_off</span>Limpar filtros</button></div>' +
      '</div>' +
      renderTruncationNotice(chartId, rows.length, total) +
      '<div class="overview-ranking__meta">' +
        '<span data-ranking-count role="status" aria-live="polite"></span>' +
        '<span class="overview-ranking__meta-actions">' +
          '<button type="button" class="overview-ranking__action" data-ranking-expand aria-pressed="false">' +
            '<span class="material-symbols-rounded" aria-hidden="true">unfold_more</span>Expandir todos</button>' +
          '<button type="button" class="overview-ranking__action" data-ranking-export>' +
            '<span class="material-symbols-rounded" aria-hidden="true">download</span>Exportar CSV</button>' +
        '</span>' +
        '<span class="overview-ranking__scope"><span class="material-symbols-rounded" aria-hidden="true">verified</span>' +
          'Orç-PD · remessas excluídas</span></div>' +
      '<div data-ranking-results></div>' +
      '<p class="overview-ranking__note">' +
        (isCustomer
          ? 'O CPF/CNPJ pode aparecer mascarado conforme a permissão do usuário. Clientes atendidos por mais de um vendedor exibem os responsáveis informados pela base.'
          : 'Preço médio = receita dos itens ÷ quantidade. A participação é calculada sobre a receita dos produtos carregados neste detalhamento.') +
      '</p></section>';

    bodyEl.innerHTML = html;
    var root = bodyEl.querySelector('.overview-ranking');
    root.querySelectorAll('[data-ranking-filter]').forEach(function (control) {
      var inputEvent = control.matches('input') ? 'input' : 'change';
      control.addEventListener(inputEvent, function () {
        if (inputEvent === 'input') {
          if (filterTimer) clearTimeout(filterTimer);
          filterTimer = setTimeout(function () {
            updateRankingDetail(chartId, rows, root);
          }, 120);
        } else {
          updateRankingDetail(chartId, rows, root);
        }
      });
    });

    root.querySelector('[data-ranking-expand]').addEventListener('click', function () {
      var button = this;
      var expand = button.getAttribute('aria-pressed') !== 'true';
      button.setAttribute('aria-pressed', expand ? 'true' : 'false');
      button.lastChild.nodeValue = expand ? 'Recolher todos' : 'Expandir todos';
      var icon = button.querySelector('.material-symbols-rounded');
      if (icon) icon.textContent = expand ? 'unfold_less' : 'unfold_more';
      root.querySelectorAll('.overview-ranking__toggle').forEach(function (toggle) {
        var target = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!target) return;
        toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
        target.hidden = !expand;
        var chevron = toggle.querySelector('.material-symbols-rounded');
        if (chevron) chevron.textContent = expand ? 'expand_more' : 'chevron_right';
      });
    });

    root.querySelector('[data-ranking-export]').addEventListener('click', function () {
      exportRankingCsv(chartId, root);
    });

    root.querySelector('[data-ranking-clear]').addEventListener('click', function () {
      root.querySelectorAll('[data-ranking-filter]').forEach(function (control) {
        if (control.dataset.rankingFilter === 'sort') control.value = 'revenue_desc';
        else if (control.tagName === 'SELECT') control.value = 'all';
        else control.value = '';
      });
      updateRankingDetail(chartId, rows, root);
      var search = root.querySelector('[data-ranking-filter="search"]');
      if (search) search.focus();
    });

    updateRankingDetail(chartId, rows, root);
  }

  function rankingState(root) {
    var state = {};
    root.querySelectorAll('[data-ranking-filter]').forEach(function (control) {
      state[control.dataset.rankingFilter] = control.value;
    });
    return state;
  }

  function updateRankingDetail(chartId, sourceRows, root) {
    var state = rankingState(root);
    var isCustomer = chartId === 'top_customers';
    var query = normalizeText(state.search);
    var minRevenue = parseLocaleNumber(state.minRevenue);
    var maxRevenue = parseLocaleNumber(state.maxRevenue);
    var minQuantity = parseLocaleNumber(state.minQuantity);

    var rows = sourceRows.filter(function (row) {
      var revenue = numberValue(isCustomer ? row.invoice_total : row.revenue);
      if (minRevenue != null && revenue < minRevenue) return false;
      if (maxRevenue != null && revenue > maxRevenue) return false;
      if (!isCustomer && minQuantity != null && numberValue(row.quantity) < minQuantity) return false;
      if (isCustomer && state.documentType !== 'all' && documentType(row) !== state.documentType) return false;
      if (isCustomer && state.seller !== 'all' && !rowHasSeller(row, state.seller)) return false;
      if (!query) return true;
      var searchable = isCustomer
        ? [decodeEntityText(row.client_name), rawDocument(row), documentDigits(row), sellerName(row)]
        : [decodeEntityText(row.description), row.product_code, row.sku, row.code];
      return normalizeText(searchable.join(' ')).indexOf(query) !== -1;
    });

    rows.sort(function (a, b) {
      var direction = String(state.sort || '').endsWith('_asc') ? 1 : -1;
      if (state.sort === 'name_asc') {
        var aName = decodeEntityText(isCustomer ? a.client_name : a.description);
        var bName = decodeEntityText(isCustomer ? b.client_name : b.description);
        return String(aName || '').localeCompare(String(bName || ''), 'pt-BR');
      }
      var aValue;
      var bValue;
      if (state.sort.indexOf('quantity_') === 0) {
        aValue = numberValue(a.quantity);
        bValue = numberValue(b.quantity);
      } else if (state.sort.indexOf('unit_') === 0) {
        aValue = numberValue(a.quantity) ? numberValue(a.revenue) / numberValue(a.quantity) : 0;
        bValue = numberValue(b.quantity) ? numberValue(b.revenue) / numberValue(b.quantity) : 0;
      } else if (state.sort.indexOf('nfs_') === 0) {
        aValue = numberValue(a.notes_count);
        bValue = numberValue(b.notes_count);
      } else if (state.sort.indexOf('customers_') === 0) {
        aValue = numberValue(a.customers_count);
        bValue = numberValue(b.customers_count);
      } else if (state.sort.indexOf('recency_') === 0) {
        aValue = recencyValue(a, isCustomer);
        bValue = recencyValue(b, isCustomer);
      } else {
        aValue = numberValue(isCustomer ? a.invoice_total : a.revenue);
        bValue = numberValue(isCustomer ? b.invoice_total : b.revenue);
      }
      return (aValue - bValue) * direction;
    });

    // O CSV exporta exatamente o que a tela mostra, ja filtrado e ordenado.
    lastFilteredRows[chartId] = rows;

    var count = root.querySelector('[data-ranking-count]');
    var total = serverTotal(chartId, rankingSummary[chartId]);
    var label = formatNumber(rows.length, 0) + ' de ' + formatNumber(sourceRows.length, 0) +
      (sourceRows.length === 1 ? ' resultado carregado' : ' resultados carregados');
    // Nunca deixar a fatia carregada passar por universo quando ha mais no periodo.
    if (total != null && total > sourceRows.length) {
      label += ' · ' + formatNumber(total, 0) + ' no período';
    }
    count.textContent = label;

    var exportBtn = root.querySelector('[data-ranking-export]');
    if (exportBtn) exportBtn.disabled = rows.length === 0;
    var expandBtn = root.querySelector('[data-ranking-expand]');
    if (expandBtn) {
      expandBtn.disabled = rows.length === 0;
      expandBtn.setAttribute('aria-pressed', 'false');
      expandBtn.lastChild.nodeValue = 'Expandir todos';
      var expandIcon = expandBtn.querySelector('.material-symbols-rounded');
      if (expandIcon) expandIcon.textContent = 'unfold_more';
    }

    var results = root.querySelector('[data-ranking-results]');
    results.innerHTML = rows.length
      ? renderRankingResults(chartId, rows, sourceRows)
      : '<div class="dashboard-empty-state overview-ranking__empty">' +
          '<span class="material-symbols-rounded" aria-hidden="true">filter_alt_off</span>' +
          '<strong>Nenhum resultado com estes filtros</strong>' +
          '<p>Revise a busca, os limites de receita ou os demais filtros.</p></div>';
    if (rows.length) bindDetailToggles(results);
  }

  function renderRankingResults(chartId, rows, sourceRows) {
    var isCustomer = chartId === 'top_customers';
    var loadedRevenue = sourceRows.reduce(function (sum, row) {
      return sum + numberValue(isCustomer ? row.invoice_total : row.revenue);
    }, 0);
    var filteredRevenue = rows.reduce(function (sum, row) {
      return sum + numberValue(isCustomer ? row.invoice_total : row.revenue);
    }, 0);
    var totalQuantity = rows.reduce(function (sum, row) {
      return sum + numberValue(row.quantity);
    }, 0);
    var totalNotes = rows.reduce(function (sum, row) {
      return sum + numberValue(row.notes_count);
    }, 0);
    var topFiveShare = rows.slice().sort(function (a, b) {
      return numberValue(isCustomer ? b.invoice_total : b.revenue) -
        numberValue(isCustomer ? a.invoice_total : a.revenue);
    }).slice(0, 5).reduce(function (sum, row) {
      if (isCustomer && row.share_pct != null) return sum + numberValue(row.share_pct);
      return sum + (loadedRevenue > 0 ? numberValue(row.revenue) * 100 / loadedRevenue : 0);
    }, 0);

    var kpis = isCustomer
      ? [
          ['Clientes exibidos', formatNumber(rows.length, 0)],
          ['Receita filtrada', formatCurrency(filteredRevenue)],
          ['NFs dos clientes', formatNumber(totalNotes, 0)],
          ['Ticket médio por NF', formatCurrency(totalNotes > 0 ? filteredRevenue / totalNotes : 0)],
          ['Concentração dos 5 maiores', formatNumber(topFiveShare, 1) + '%']
        ]
      : [
          ['Produtos exibidos', formatNumber(rows.length, 0)],
          ['Receita filtrada', formatCurrency(filteredRevenue)],
          ['Unidades', formatNumber(totalQuantity, 2)],
          ['Preço médio ponderado', formatCurrency(totalQuantity > 0 ? filteredRevenue / totalQuantity : 0)],
          ['Concentração dos 5 maiores', formatNumber(topFiveShare, 1) + '%']
        ];

    var html = '<div class="overview-ranking__kpis">' + kpis.map(function (kpi) {
      return '<div class="overview-ranking__kpi"><span>' + escapeHtml(kpi[0]) +
        '</span><strong>' + escapeHtml(kpi[1]) + '</strong></div>';
    }).join('') + '</div><div class="overview-ranking__table-wrap"><table class="mag-table overview-ranking__table">' +
      '<caption class="sr-only">' +
        (isCustomer ? 'Detalhamento dos clientes em destaque' : 'Detalhamento dos produtos em destaque') +
      '</caption><thead><tr>';

    if (isCustomer) {
      html += '<th scope="col">#</th><th scope="col">Cliente</th><th scope="col">CPF/CNPJ</th>' +
        '<th scope="col">Vendedor responsável</th><th scope="col" class="is-numeric">NFs</th>' +
        '<th scope="col" class="is-numeric">Ticket médio</th><th scope="col" class="is-numeric">Receita</th>' +
        '<th scope="col" class="is-numeric">Share</th></tr></thead><tbody>';

      rows.forEach(function (row, index) {
        var notes = numberValue(row.notes_count);
        var type = documentType(row);
        var vendors = numberValue(row.vendors_count);
        var clientName = decodeEntityText(row.client_name) || 'Cliente não informado';
        var clientAction = '<button type="button" class="row-inline-link overview-ranking__entity" ' +
          'data-biomie-action="open-customer" data-client-id="' + escapeHtml(row.client_id || '') +
          '" data-customer-document="' + escapeHtml(rawDocument(row)) +
          '" data-customer-name="' + escapeHtml(clientName) +
          '" title="Abrir Cliente 360">' + escapeHtml(clientName) + '</button>';
        // average_ticket vem da API (AVG real por NF); o calculo local so cobre
        // respostas antigas que ainda nao trazem o campo.
        var ticket = row.average_ticket != null
          ? numberValue(row.average_ticket)
          : (notes > 0 ? numberValue(row.invoice_total) / notes : 0);
        html += '<tr>' + detailToggleCell('customers', index) +
          '<td>' + clientAction +
            (row.client_id ? '<small>ID Omie ' + escapeHtml(row.client_id) + '</small>' : '') + '</td>' +
          '<td><span class="overview-ranking__document">' + escapeHtml(formatDocument(row)) + '</span>' +
            '<small>' + (type === 'cpf' ? 'Pessoa física' : type === 'cnpj' ? 'Pessoa jurídica' : 'Tipo não identificado') +
            '</small></td>' +
          '<td><span class="overview-ranking__seller">' + escapeHtml(sellerList(row)) + '</span>' +
            (vendors > 1 ? '<small>' + formatNumber(vendors, 0) + ' vendedores — carteira compartilhada</small>' : '') + '</td>' +
          '<td class="is-numeric">' + formatNumber(notes, 0) + '</td>' +
          '<td class="is-numeric">' + formatCurrency(ticket) + '</td>' +
          '<td class="is-numeric"><strong>' + formatCurrency(row.invoice_total) + '</strong></td>' +
          '<td class="is-numeric"><span class="overview-ranking__share">' +
            formatNumber(row.share_pct, 1) + '%</span></td></tr>' +
          detailRow('customers', index, 8, customerDetailPanel(row));
      });
    } else {
      html += '<th scope="col">#</th><th scope="col">Produto</th><th scope="col">Código</th>' +
        '<th scope="col" class="is-numeric">Qtd.</th><th scope="col" class="is-numeric">NFs</th>' +
        '<th scope="col" class="is-numeric">Clientes</th>' +
        '<th scope="col" class="is-numeric">Preço médio</th><th scope="col" class="is-numeric">Receita</th>' +
        '<th scope="col" class="is-numeric">Part. da lista</th></tr></thead><tbody>';

      rows.forEach(function (row, index) {
        var quantity = numberValue(row.quantity);
        var revenue = numberValue(row.revenue);
        var rawCode = row.product_code || row.sku || row.code || '';
        var code = rawCode || 'Não informado';
        var productName = decodeEntityText(row.description) || 'Produto não informado';
        var productAction = rawCode
          ? '<button type="button" class="row-inline-link overview-ranking__entity" ' +
              'data-biomie-action="open-product" data-product-code="' + escapeHtml(rawCode) +
              '" data-product-name="' + escapeHtml(productName) +
              '" title="Abrir Produto 360">' + escapeHtml(productName) + '</button>'
          : '<strong class="overview-ranking__entity">' + escapeHtml(productName) + '</strong>';
        var share = loadedRevenue > 0 ? revenue * 100 / loadedRevenue : 0;
        var customers = numberValue(row.customers_count);
        // 94 unidades para 1 cliente e 94 para 40 clientes sao negocios
        // diferentes; sem esta coluna a quantidade sozinha engana.
        var customersCell = customers > 0
          ? formatNumber(customers, 0) + (customers === 1 ? '<small>cliente único</small>' : '')
          : '<span class="text-muted">—</span>';
        html += '<tr>' + detailToggleCell('products', index) +
          '<td>' + productAction + '</td>' +
          '<td><span class="overview-ranking__code">' + escapeHtml(code) + '</span></td>' +
          '<td class="is-numeric">' + formatNumber(quantity, 2) + '</td>' +
          '<td class="is-numeric">' + formatNumber(row.orders, 0) + '</td>' +
          '<td class="is-numeric">' + customersCell + '</td>' +
          '<td class="is-numeric">' + formatCurrency(quantity > 0 ? revenue / quantity : 0) + '</td>' +
          '<td class="is-numeric"><strong>' + formatCurrency(revenue) + '</strong></td>' +
          '<td class="is-numeric"><span class="overview-ranking__share">' +
            formatNumber(share, 1) + '%</span></td></tr>' +
          detailRow('products', index, 9, productDetailPanel(row));
      });
    }

    return html + '</tbody></table></div>';
  }

  /**
   * Serie diaria com painel expansivel por dia.
   *
   * Substitui a tabela generica de 4 colunas, que repetia a mesma data uma vez
   * por NF num painel chamado "Evolucao" e mostrava "Pedidos: 0" em todas as
   * linhas — a coluna lia `orders`, que a API nunca devolveu para este grafico.
   */
  function renderRevenueEvolutionDetail(chartId, rows, summary) {
    var totalRevenue = rows.reduce(function (sum, row) {
      return sum + numberValue(row.revenue);
    }, 0);
    var totalInvoices = rows.reduce(function (sum, row) {
      return sum + numberValue(row.invoices_count);
    }, 0);
    var mediaDiaria = rows.length > 0 ? totalRevenue / rows.length : 0;
    var melhor = rows.reduce(function (melhorAteAgora, row) {
      return numberValue(row.revenue) > numberValue(melhorAteAgora.revenue) ? row : melhorAteAgora;
    }, rows[0]);

    var kpis = [
      ['Dias com faturamento', formatNumber(rows.length, 0)],
      ['Receita no período', formatCurrency(totalRevenue)],
      ['NFs', formatNumber(totalInvoices, 0)],
      ['Média por dia', formatCurrency(mediaDiaria)],
      ['Melhor dia', formatDate(melhor && melhor.date) + ' — ' + formatCurrency(melhor && melhor.revenue)]
    ];

    // Só entra quando ALGUM dia tem faixa. Mostrar "0 fora do normal" para uma
    // empresa sem baseline afirmaria normalidade que ninguém mediu.
    var comFaixa = rows.filter(function (row) { return baselineVerdict(row) !== null; });
    if (comFaixa.length) {
      var fora = comFaixa.filter(function (row) {
        return baselineVerdict(row).estado !== 'dentro';
      }).length;
      kpis.push(['Fora do normal', formatNumber(fora, 0) + ' de ' + formatNumber(comFaixa.length, 0) + ' dias']);
    }

    // As mesmas afordâncias de "Produtos em destaque": sem elas o drawer de
    // receita vira uma tela de leitura, enquanto o outro é de trabalho.
    var html = '<div class="overview-ranking__toolbar">' +
      '<span class="text-muted">' + formatNumber(totalInvoices, 0) + ' notas em ' +
        formatNumber(rows.length, 0) + ' dia(s)</span>' +
      '<div class="overview-ranking__actions">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-revenue-expand>' +
          '<span class="material-symbols-rounded" aria-hidden="true">unfold_more</span> Expandir todos</button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-revenue-csv>' +
          '<span class="material-symbols-rounded" aria-hidden="true">download</span> Exportar CSV</button>' +
      '</div></div>';

    html += '<div class="overview-ranking__kpis">';
    kpis.forEach(function (kpi) {
      html += '<div class="overview-ranking__kpi"><span>' + escapeHtml(kpi[0]) +
        '</span><strong>' + escapeHtml(kpi[1]) + '</strong></div>';
    });
    html += '</div>';

    html += '<div class="overview-ranking__table-wrap"><table class="mag-table"><thead><tr>' +
      '<th class="overview-ranking__rank">#</th><th>Data</th>' +
      '<th class="is-numeric">NFs</th><th class="is-numeric">Clientes</th>' +
      '<th class="is-numeric">Vendedores</th><th class="is-numeric">Ticket médio</th>' +
      '<th class="is-numeric">Receita</th><th class="is-numeric">Part.</th>' +
      '<th>Contra o normal</th>' +
      '</tr></thead><tbody>';

    rows.forEach(function (row, index) {
      var revenue = numberValue(row.revenue);
      var invoices = numberValue(row.invoices_count);
      var share = totalRevenue > 0 ? revenue * 100 / totalRevenue : 0;
      html += '<tr>' + detailToggleCell('revenue', index) +
        '<td>' + escapeHtml(formatDate(row.date)) +
          (weekdayLabel(row) ? ' <span class="overview-ranking__weekday">' +
            escapeHtml(weekdayLabel(row)) + '</span>' : '') + '</td>' +
        '<td class="is-numeric">' + formatNumber(invoices, 0) + '</td>' +
        '<td class="is-numeric">' + formatNumber(row.customers_count, 0) + '</td>' +
        '<td class="is-numeric">' + formatNumber(row.vendors_count, 0) + '</td>' +
        '<td class="is-numeric">' + formatCurrency(
            row.average_ticket != null ? row.average_ticket : (invoices > 0 ? revenue / invoices : 0)) + '</td>' +
        '<td class="is-numeric"><strong>' + formatCurrency(revenue) + '</strong></td>' +
        '<td class="is-numeric"><span class="overview-ranking__share">' +
          formatNumber(share, 1) + '%</span></td>' +
        '<td>' + baselineChip(row) + '</td></tr>' +
        detailRow('revenue', index, 9, revenueEvolutionDetailPanel(row, mediaDiaria));
    });

    html += '</tbody></table></div>';

    // Este grafico nao devolve `summary` hoje; se passar a devolver, aparece no
    // mesmo formato do restante do drawer em vez de ser silenciosamente
    // descartado.
    if (summary) {
      var resumo = '<div class="module-tiny-kpis overview-detail-summary">';
      Object.keys(summary).forEach(function (name) {
        resumo += '<div class="mini"><span>' + escapeHtml(name.replace(/_/g, ' ')) +
          '</span><strong>' + escapeHtml(summary[name]) + '</strong></div>';
      });
      html = resumo + '</div>' + html;
    }

    bodyEl.innerHTML = html;
    bindDetailToggles(bodyEl);
    lastRevenueRows = rows;

    var expandir = bodyEl.querySelector('[data-revenue-expand]');
    if (expandir) {
      expandir.addEventListener('click', function () {
        var abrir = expandir.getAttribute('data-open') !== 'true';
        expandir.setAttribute('data-open', abrir ? 'true' : 'false');
        bodyEl.querySelectorAll('.overview-ranking__toggle').forEach(function (botao) {
          if ((botao.getAttribute('aria-expanded') === 'true') !== abrir) botao.click();
        });
      });
    }
    var exportar = bodyEl.querySelector('[data-revenue-csv]');
    if (exportar) exportar.addEventListener('click', exportRevenueCsv);

    // Um dia só na tela quase sempre é clique num ponto do gráfico: o usuário
    // já disse qual dia quer, então fazê-lo clicar de novo para ver o detalhe
    // seria pedir duas vezes a mesma coisa.
    if (rows.length === 1) {
      var toggle = bodyEl.querySelector('.overview-ranking__toggle');
      if (toggle) toggle.click();
    }
  }

  function detectColumns(chartId, sample) {
    // `revenue_evolution` saiu daqui: tem renderizador proprio
    // (`renderRevenueEvolutionDetail`). O mapa antigo declarava uma coluna
    // "Pedidos" lendo `orders`, campo que a API nunca devolveu para este
    // grafico — deixar o mapa morto aqui manteria o contrato errado escrito.
    var maps = {
      vendor_ranking: [
        { key: 'vendor_name', label: 'Vendedor' },
        { key: 'orders', label: 'Pedidos', format: 'number' },
        { key: 'revenue', label: 'Receita', format: 'currency' },
        { key: 'ticket', label: 'Ticket', format: 'currency' }
      ],
      top_invoices: [
        { key: 'code', label: 'NF' },
        { key: 'issue_date', label: 'Emissão', format: 'date' },
        { key: 'client', label: 'Cliente' },
        { key: 'vendor_name', label: 'Vendedor' },
        { key: 'total', label: 'Valor', format: 'currency' }
      ],
      geo_compact: [
        { key: 'neighborhood', label: 'Bairro' },
        { key: 'revenue', label: 'Receita', format: 'currency' },
        { key: 'share_pct', label: 'Share' }
      ]
    };
    return maps[chartId] || Object.keys(sample).map(function (key) {
      return { key: key, label: key };
    });
  }

  window.BIomieOverviewDetail = { open: open, close: close, isOpen: isOpen };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
