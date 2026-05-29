/**
 * FIN.R3 - Collection portfolio by vendor.
 * Reads only canonical enriched finance titles through api/finance_collection_by_vendor.php.
 */
(function () {
  'use strict';

  var B = window.BIomie || {};
  var escapeHtml = function (value) {
    if (B.escapeHtml) return B.escapeHtml(value);
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var formatCurrency = function (value) {
    if (B.formatCurrency) return B.formatCurrency(value);
    return 'R$ ' + Number(value || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
  var formatNumber = function (value) {
    if (B.formatNumber) return B.formatNumber(value);
    return Number(value || 0).toLocaleString('pt-BR');
  };
  var formatDateBR = function (value) {
    if (!value) return '-';
    if (B.formatMetaDate) return B.formatMetaDate(value);
    var parts = String(value).split('-');
    return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : String(value);
  };

  var STATUS_LABELS = {
    aberto: 'Em aberto',
    vencido: 'Vencido',
    vence_hoje: 'Vence hoje',
    a_vencer: 'A vencer',
    liquidado: 'Recebido',
    recebido: 'Recebido',
    pago: 'Recebido'
  };
  var AGING_LABELS = {
    a_vencer: 'A vencer',
    vence_hoje: 'Vence hoje',
    '1_7': '1-7 dias',
    '8_15': '8-15 dias',
    '16_30': '16-30 dias',
    '31_60': '31-60 dias',
    '60_plus': '60+ dias'
  };

  var state = {
    page: 1,
    sort: 'atraso',
    direction: 'desc'
  };
  var lastLoadedData = [];
  var abortCtrl = null;
  var debounceTimer = null;

  function byId(id) { return document.getElementById(id); }
  function valueOf(id) {
    var node = byId(id);
    return node ? String(node.value || '').trim() : '';
  }
  function checked(id) {
    var node = byId(id);
    return !!(node && node.checked);
  }
  function setValue(id, value) {
    var node = byId(id);
    if (node) node.value = value || '';
  }
  function setChecked(id, value) {
    var node = byId(id);
    if (node) node.checked = !!value;
  }
  function setText(id, value) {
    var node = byId(id);
    if (node) node.textContent = value;
  }

  function getRange() {
    if (B.getDateRange) {
      var range = B.getDateRange();
      if (range && range.start && range.end) return range;
    }
    return {
      start: valueOf('dateStart'),
      end: valueOf('dateEnd')
    };
  }

  function feedback(message, isError) {
    var node = byId('fcFeedback');
    if (!node) return;
    if (!message) {
      node.style.display = 'none';
      node.textContent = '';
      return;
    }
    node.style.display = 'block';
    node.textContent = message;
    node.className = 'biomie-table-feedback ' + (isError ? 'biomie-table-feedback--error' : 'biomie-table-feedback--info');
  }

  function currentFilters(page) {
    var range = getRange();
    return {
      start: range.start || '',
      end: range.end || '',
      q: valueOf('fcQ'),
      vendor: valueOf('fcVendor'),
      client: valueOf('fcClient'),
      status: valueOf('fcStatus'),
      aging: valueOf('fcAging'),
      valueMin: valueOf('fcValueMin'),
      valueMax: valueOf('fcValueMax'),
      onlyOverdue: checked('fcOnlyOverdue'),
      onlyNoVendor: checked('fcOnlyNoVendor'),
      page: page || state.page || 1,
      sort: state.sort,
      direction: state.direction
    };
  }

  function queryFor(filters) {
    var params = new URLSearchParams();
    params.set('start', filters.start);
    params.set('end', filters.end);
    params.set('page', String(filters.page || 1));
    params.set('per_page', '25');
    params.set('sort', filters.sort || 'atraso');
    params.set('direction', filters.direction || 'desc');
    if (filters.q) params.set('q', filters.q);
    if (filters.vendor) params.set('filters[vendedor]', filters.vendor);
    if (filters.client) params.set('filters[cliente]', filters.client);
    if (filters.status) params.set('status', filters.status);
    if (filters.aging) params.set('aging', filters.aging);
    if (filters.valueMin) params.set('valor_min', filters.valueMin);
    if (filters.valueMax) params.set('valor_max', filters.valueMax);
    if (filters.onlyOverdue) params.set('only_overdue', '1');
    if (filters.onlyNoVendor) params.set('only_no_vendor', '1');
    return params;
  }

  function saveUrl(filters) {
    var params = new URLSearchParams(window.location.search);
    var set = function (key, value) {
      if (value) params.set(key, value);
      else params.delete(key);
    };
    set('fcol_q', filters.q);
    set('fcol_vendor', filters.vendor);
    set('fcol_client', filters.client);
    set('fcol_status', filters.status);
    set('fcol_aging', filters.aging);
    set('fcol_min', filters.valueMin);
    set('fcol_max', filters.valueMax);
    set('fcol_overdue', filters.onlyOverdue ? '1' : '');
    set('fcol_no_vendor', filters.onlyNoVendor ? '1' : '');
    set('fcol_sort', filters.sort !== 'atraso' ? filters.sort : '');
    set('fcol_dir', filters.direction !== 'desc' ? filters.direction : '');
    set('fcol_page', filters.page > 1 ? String(filters.page) : '');
    window.history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''));
  }

  function restoreUrl() {
    var params = new URLSearchParams(window.location.search);
    setValue('fcQ', params.get('fcol_q') || '');
    setValue('fcVendor', params.get('fcol_vendor') || '');
    setValue('fcClient', params.get('fcol_client') || '');
    setValue('fcStatus', params.get('fcol_status') || '');
    setValue('fcAging', params.get('fcol_aging') || '');
    setValue('fcValueMin', params.get('fcol_min') || '');
    setValue('fcValueMax', params.get('fcol_max') || '');
    setChecked('fcOnlyOverdue', params.get('fcol_overdue') === '1');
    setChecked('fcOnlyNoVendor', params.get('fcol_no_vendor') === '1');
    state.sort = params.get('fcol_sort') || 'atraso';
    state.direction = params.get('fcol_dir') || 'desc';
    state.page = Number(params.get('fcol_page') || '1') || 1;
  }

  function renderChips(filters) {
    var node = byId('fcChips');
    if (!node) return;
    var chips = [];
    if (filters.q) chips.push(['q', 'Busca: ' + filters.q]);
    if (filters.vendor) chips.push(['vendor', 'Vendedor: ' + filters.vendor]);
    if (filters.client) chips.push(['client', 'Cliente: ' + filters.client]);
    if (filters.status) chips.push(['status', STATUS_LABELS[filters.status] || filters.status]);
    if (filters.aging) chips.push(['aging', 'Aging: ' + (AGING_LABELS[filters.aging] || filters.aging)]);
    if (filters.valueMin) chips.push(['valueMin', 'Valor >= R$ ' + filters.valueMin]);
    if (filters.valueMax) chips.push(['valueMax', 'Valor <= R$ ' + filters.valueMax]);
    if (filters.onlyOverdue) chips.push(['onlyOverdue', 'Apenas vencidos']);
    if (filters.onlyNoVendor) chips.push(['onlyNoVendor', 'Sem vendedor']);

    if (!chips.length) {
      node.style.display = 'none';
      node.innerHTML = '';
      return;
    }

    if (B.FChips && B.FChips.render) {
      B.FChips.render('fcChips', chips.map(function (chip) {
        return { key: chip[0], label: chip[1] };
      }), function (key) {
        if (key === '__all__') resetFilters();
        else clearChip(key);
      });
      return;
    }

    node.style.display = 'flex';
    node.style.flexWrap = 'wrap';
    node.style.gap = '6px';
    node.innerHTML = chips.map(function (chip) {
      return '<button class="biomie-filter-chip" type="button" data-fc-chip="' + chip[0] + '">' +
        escapeHtml(chip[1]) + ' <span aria-hidden="true">x</span></button>';
    }).join('');
  }

  function clearChip(key) {
    if (key === 'q') setValue('fcQ', '');
    if (key === 'vendor') setValue('fcVendor', '');
    if (key === 'client') setValue('fcClient', '');
    if (key === 'status') setValue('fcStatus', '');
    if (key === 'aging') setValue('fcAging', '');
    if (key === 'valueMin') setValue('fcValueMin', '');
    if (key === 'valueMax') setValue('fcValueMax', '');
    if (key === 'onlyOverdue') setChecked('fcOnlyOverdue', false);
    if (key === 'onlyNoVendor') setChecked('fcOnlyNoVendor', false);
    load(1);
  }

  function badge(label, kind) {
    return '<span class="fin-badge fin-badge-' + kind + '">' + escapeHtml(label) + '</span>';
  }

  function statusBadge(row) {
    if (row.sem_vendedor) return badge('Sem vendedor', 'gold');
    if (Number(row.dias_atraso || 0) > 0) return badge('Vencido', 'red');
    if (row.aging_bucket === 'vence_hoje') return badge('Vence hoje', 'gold');
    return badge('A vencer', 'green');
  }

  function delayLabel(days) {
    days = Number(days || 0);
    if (days <= 0) return badge('No prazo', 'green');
    return badge(days + 'd', days <= 15 ? 'gold' : 'red');
  }

  function formatPercent(value) {
    return Number(value || 0).toFixed(1).replace('.', ',') + '%';
  }

  function compactRef(value, size) {
    value = String(value == null ? '' : value).trim();
    if (!value) return '';
    size = size || 10;
    if (value.length <= size) return value;
    return value.slice(0, 4) + '...' + value.slice(-Math.max(4, size - 7));
  }

  function renderOriginRef(row) {
    row = row || {};
    var items = [];
    var nfLabel   = String(row.nf_label || '').trim();
    var pedidoLabel = String(row.pedido_label || '').trim();
    var nfRaw     = String(row.nota_key || '').trim();
    var pedidoRaw = String(row.codigo_pedido_origem || row.pedido_id_omie || '').trim();
    var origin    = String(row.documento_origem_ref || row.nf_pedido || '').trim();

    if (nfLabel) {
      var nfTitle = row.nf_chave_nfe || row.nf_nota_key || nfLabel;
      items.push('<span class="fin-tag" title="' + escapeHtml(nfTitle) + '">' + escapeHtml(nfLabel) + '</span>');
    } else if (nfRaw) {
      items.push('<span class="fin-tag" title="' + escapeHtml(nfRaw) + '">NF ' + escapeHtml(compactRef(nfRaw, 12)) + '</span>');
    }
    if (pedidoLabel) {
      items.push('<span class="fin-tag" style="background:var(--color-blue-50,#eff6ff);color:var(--color-blue-700,#1d4ed8);">' + escapeHtml(pedidoLabel) + '</span>');
    } else if (pedidoRaw && pedidoRaw !== '0') {
      items.push('<span class="fin-tag" title="' + escapeHtml(pedidoRaw) + '">Pedido ' + escapeHtml(compactRef(pedidoRaw, 12)) + '</span>');
    }
    if (!items.length && origin) {
      items.push('<span class="fin-tag" title="' + escapeHtml(origin) + '">Ref ' + escapeHtml(compactRef(origin, 14)) + '</span>');
    }
    if (!items.length) return '<span class="text-muted">-</span>';
    return '<div class="fc-origin-list">' + items.join('') + '</div>';
  }

  function renderUnresolvedVendorAlert(bucket) {
    var node = byId('fcUnresolvedVendorAlert');
    if (!node) return;
    if (!bucket || !bucket.count) {
      node.style.display = 'none';
      node.innerHTML = '';
      return;
    }
    var gapsHtml = '';
    if (bucket.top_gap_reasons) {
      var reasons = Object.keys(bucket.top_gap_reasons).slice(0, 3);
      if (reasons.length) {
        gapsHtml = '<div style="margin-top:4px;font-size:11px;opacity:0.8;">Motivos: ' +
          reasons.map(function (k) { return escapeHtml(k) + ' (' + bucket.top_gap_reasons[k] + ')'; }).join(' · ') +
          '</div>';
      }
    }
    node.style.display = 'block';
    node.innerHTML =
      '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' +
      '<strong>⚠ ' + bucket.count + ' título(s) sem vendedor resolvido</strong>' +
      '<span style="font-size:12px;opacity:0.75;">nesta página</span>' +
      '<button class="btn-xs-action btn-revisar-vinculo" style="margin-left:auto;" id="fcAlertVerSemVendedor">Ver todos sem vendedor</button>' +
      '</div>' + gapsHtml;
    var btn = byId('fcAlertVerSemVendedor');
    if (btn) {
      btn.addEventListener('click', function () {
        setChecked('fcOnlyNoVendor', true);
        load(1);
      });
    }
  }

  function renderKpis(kpis) {
    kpis = kpis || {};
    var totalOpen = Number(kpis.total_aberto || 0);
    var totalOverdue = Number(kpis.total_vencido || 0);
    var totalDue = Number(kpis.total_a_vencer || 0);
    var overduePct = totalOpen > 0 ? (totalOverdue / totalOpen) * 100 : 0;
    setText('fcPortfolioCompact', formatCurrency(totalOpen) + ' aberto | ' + formatCurrency(totalOverdue) + ' vencido | ' + formatCurrency(totalDue) + ' a vencer');
    setText('fcVendorCount', formatNumber(kpis.vendedores_com_pendencia || 0));
    setText('fcClientCount', formatNumber(kpis.clientes_com_pendencia || 0));
    setText('fcNoVendorCount', formatNumber(kpis.titulos_sem_vendedor || 0));
    setText('fcNoVendorValueCard', formatCurrency(kpis.valor_sem_vendedor || 0));
    setText('fcMaxDelay', formatNumber(kpis.maior_atraso_dias || 0) + ' dias');
    setText('fcOverduePct', formatPercent(overduePct));
    setText('fcKpiCobradosHoje', formatNumber(kpis.titulos_cobrados_hoje || 0));
    setText('fcKpiPromessas', formatNumber(kpis.promessas_pagamento || 0));
    setText('fcKpiRevisoes', formatNumber(kpis.revisoes_vinculo_abertas || 0));
    setText('fcKpiSemAcao', formatNumber(kpis.pendentes_sem_acao || 0));
  }

  function renderRanking(rows) {
    var target = byId('fcRankingBody');
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">Nenhum vendedor com pendencia para os filtros atuais.</td></tr>';
      return;
    }
    target.innerHTML = rows.map(function (row) {
      var vendorSub = row.sem_vendedor
        ? '<small style="color:var(--warning-text,#b45309);">Títulos sem vínculo resolvido</small>'
        : '<small>' + formatNumber(row.qtd_titulos) + ' titulo(s)</small>';
      return '<tr data-fc-vendor-row="' + escapeHtml(row.nome_vendedor) + '" data-fc-no-vendor="' + (row.sem_vendedor ? '1' : '0') + '">' +
        '<td><div class="fin-doc-cell"><strong>' + escapeHtml(row.nome_vendedor) + '</strong>' +
        vendorSub + '</div></td>' +
        '<td><strong>' + formatCurrency(row.valor_aberto) + '</strong></td>' +
        '<td>' + formatCurrency(row.valor_vencido) + '</td>' +
        '<td>' + formatCurrency(row.valor_a_vencer) + '</td>' +
        '<td>' + formatNumber(row.qtd_titulos) + '</td>' +
        '<td>' + formatNumber(row.qtd_clientes) + '</td>' +
        '<td>' + delayLabel(row.maior_atraso) + '</td>' +
        '<td>' + Number(row.percentual_vencido || 0).toFixed(1).replace('.', ',') + '%</td>' +
      '</tr>';
    }).join('');
  }

  function renderAging(rows) {
    var target = byId('fcAgingBody');
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-muted">Nenhum titulo em aberto no recorte.</td></tr>';
      return;
    }
    target.innerHTML = rows.map(function (row) {
      return '<tr data-fc-aging-row="' + escapeHtml(row.bucket) + '">' +
        '<td><strong>' + escapeHtml(row.label) + '</strong></td>' +
        '<td>' + formatNumber(row.qtd_titulos) + '</td>' +
        '<td><strong>' + formatCurrency(row.valor_aberto) + '</strong></td>' +
      '</tr>';
    }).join('');
  }

  function actionBadge(latestAction) {
    if (!latestAction || !latestAction.action_type) {
      return '<span class="fin-badge fin-badge-gray" style="margin-top:2px;display:inline-block;padding:2px 6px;font-size:10px;">Sem ação</span>';
    }
    var type = latestAction.action_type;
    var label = latestAction.action_type.replace(/_/g, ' ');
    label = label.charAt(0).toUpperCase() + label.slice(1);
    
    var kind = 'gray';
    if (type === 'cobrado_hoje') kind = 'green';
    else if (type === 'whatsapp_enviado' || type === 'ligacao_realizada' || type === 'email_enviado') kind = 'blue';
    else if (type === 'aguardando_retorno' || type === 'promessa_pagamento') kind = 'gold';
    else if (type === 'validar_baixa_caixa' || type === 'revisar_vinculo_financeiro') kind = 'red';
    
    return '<span class="fin-badge fin-badge-' + kind + '" style="margin-top:2px;display:inline-block;padding:2px 6px;font-size:10px;" title="' + escapeHtml(latestAction.note || '') + '">' + escapeHtml(label) + '</span>';
  }

  function renderRows(rows) {
    var target = byId('fcTableBody');
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">Nenhum titulo acionavel para os filtros atuais.</td></tr>';
      initMobileTables();
      return;
    }
    target.innerHTML = rows.map(function (row) {
      var tKey     = escapeHtml(row.titulo_key || row.id || '');
      var clientId = escapeHtml(row.cliente_id || '');
      var vendorId = escapeHtml(row.vendedor_id || '');

      var actionsHtml = '';
      if (row.sem_vendedor) {
        actionsHtml = '<button class="btn-xs-action btn-revisar-vinculo" data-fc-title-key="' + tKey + '" data-fc-client-id="' + clientId + '" data-fc-vendor-id="' + vendorId + '">Revisar vínculo</button>';
      } else {
        actionsHtml = '<div style="display:flex;gap:4px;flex-wrap:wrap;">' +
          '<button class="btn-xs-action btn-registrar-cobranca" data-fc-title-key="' + tKey + '" data-fc-client-id="' + clientId + '" data-fc-vendor-id="' + vendorId + '">Registrar</button>' +
          '<button class="btn-xs-action btn-marcar-cobrado" data-fc-title-key="' + tKey + '" data-fc-client-id="' + clientId + '" data-fc-vendor-id="' + vendorId + '">Cobrado</button>' +
          '</div>';
      }

      var clienteNome   = row.cliente_nome_resolvido || row.cliente || 'Cliente nao informado';
      var vendorNome    = row.vendedor_nome_resolvido || row.vendedor || '';
      var vendorSource  = String(row.vendedor_source || '');
      var docCliente    = row.cliente_documento || row.documento_cliente || '';
      var methodLabel   = String(row.payment_method_label || row.forma_pagamento || '').trim();
      var traceScore    = typeof row.trace_quality_score === 'number' ? row.trace_quality_score : -1;
      var gapReason     = String(row.gap_reason || '').trim();

      var vendorChip = vendorNome
        ? '<span class="fin-tag" style="font-size:10px;padding:1px 5px;margin-top:2px;background:var(--color-blue-50,#eff6ff);color:var(--color-blue-700,#1d4ed8);" title="' + escapeHtml(vendorSource) + '">' + escapeHtml(vendorNome) + '</span>'
        : '<span class="fin-tag" style="font-size:10px;padding:1px 5px;margin-top:2px;background:var(--warning-bg-light,#fffbeb);color:var(--warning-text,#b45309);">Sem vendedor</span>';

      var scoreChip = (traceScore >= 0 && traceScore < 75)
        ? '<span class="fin-badge fin-badge-gray" style="font-size:9px;padding:1px 4px;margin-left:4px;" title="Qualidade do vínculo: ' + traceScore + '/100 — ' + escapeHtml(gapReason) + '">Q:' + traceScore + '</span>'
        : '';

      var methodChip = methodLabel
        ? '<span class="fin-badge fin-badge-gray" style="font-size:10px;padding:2px 5px;">' + escapeHtml(methodLabel) + '</span>'
        : '';

      return '<tr>' +
        '<td><div class="fin-doc-cell">' +
          '<strong>' + escapeHtml(clienteNome) + '</strong>' +
          (docCliente ? '<small>' + escapeHtml(docCliente) + '</small>' : '') +
          '<div style="margin-top:2px;">' + vendorChip + '</div>' +
        '</div></td>' +
        '<td><div class="fin-doc-cell">' +
          '<span class="fin-tag">' + escapeHtml(row.especie || 'Titulo') + '</span>' +
          '<strong>' + escapeHtml(row.documento || row.id || '-') + '</strong>' +
          (methodChip ? '<div style="margin-top:2px;">' + methodChip + '</div>' : '') +
        '</div></td>' +
        '<td>' + renderOriginRef(row) + '</td>' +
        '<td>' + formatDateBR(row.vencimento) + '</td>' +
        '<td>' + delayLabel(row.dias_atraso) + '</td>' +
        '<td><strong>' + formatCurrency(row.valor_aberto) + '</strong></td>' +
        '<td>' + statusBadge(row) + scoreChip + '</td>' +
        '<td><div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">' +
          '<strong class="fc-action-label" style="font-size:11px;">' + escapeHtml(row.acao_recomendada || 'Acompanhar vencimento') + '</strong>' +
          actionBadge(row.latest_action) +
          '<div style="margin-top:2px;">' + actionsHtml + '</div>' +
        '</div></td>' +
      '</tr>';
    }).join('');
    initMobileTables();
  }

  function renderPagination(pagination) {
    var node = byId('fcPagination');
    if (!node || !pagination) return;
    if (pagination.total <= pagination.per_page) {
      node.style.display = 'none';
      node.innerHTML = '';
      return;
    }
    node.style.display = 'flex';
    var html = '<span style="margin-right:auto;">' + formatNumber(pagination.total) + ' titulo(s) - pag. ' +
      pagination.page + '/' + pagination.total_pages + '</span>';
    if (pagination.has_prev) html += '<button class="fin-page-btn" type="button" data-fc-page="' + (pagination.page - 1) + '">Anterior</button>';
    var from = Math.max(1, pagination.page - 2);
    var to = Math.min(pagination.total_pages, pagination.page + 2);
    for (var i = from; i <= to; i++) {
      html += '<button class="fin-page-btn' + (i === pagination.page ? ' active' : '') + '" type="button" data-fc-page="' + i + '">' + i + '</button>';
    }
    if (pagination.has_next) html += '<button class="fin-page-btn" type="button" data-fc-page="' + (pagination.page + 1) + '">Proxima</button>';
    node.innerHTML = html;
  }

  function updateSortHeaders() {
    document.querySelectorAll('[data-fc-sort]').forEach(function (th) {
      var icon = th.querySelector('.nf-sort-icon');
      if (!icon) return;
      if (th.dataset.fcSort === state.sort) {
        icon.innerHTML = state.direction === 'asc' ? '&#8593;' : '&#8595;';
        th.classList.add('nf-th-active-sort');
      } else {
        icon.innerHTML = '&#8597;';
        th.classList.remove('nf-th-active-sort');
      }
    });
  }

  function renderAll(payload, filters) {
    renderKpis(payload.kpis || {});
    renderRanking(payload.ranking || []);
    renderAging(payload.aging || []);
    renderUnresolvedVendorAlert(payload.unresolved_vendor_bucket || null);
    renderRows(payload.data || []);
    renderPagination(payload.pagination || null);
    renderChips(filters);
    updateSortHeaders();
    var total = payload.pagination ? payload.pagination.total : 0;
    setText('fcMeta', total ? formatNumber(total) + ' titulo(s) filtrados' : '');
    setText('fcTableMeta', total ? formatNumber(total) + ' titulo(s)' : '');
  }

  function load(page) {
    var filters = currentFilters(page || 1);
    if (!filters.start || !filters.end) {
      feedback('Selecione um periodo para carregar a carteira.', false);
      return;
    }
    state.page = filters.page;
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();

    feedback('Carregando carteira por vendedor...', false);
    window.fetch('api/finance_collection_by_vendor.php?' + queryFor(filters).toString(), {
      signal: abortCtrl.signal,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.redirected) throw new Error('Sessao expirada. Recarregue a pagina.');
        return response.json();
      })
      .then(function (payload) {
        if (!payload.ok) {
          throw new Error((payload.error && payload.error.message) || payload.message || 'Falha ao carregar carteira.');
        }
        feedback('');
        saveUrl(filters);
        lastLoadedData = payload.data || [];
        renderAll(payload, filters);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        feedback((error && error.message) || 'Falha ao carregar carteira por vendedor.', true);
      });
  }

  function debouncedLoad() {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(function () { load(1); }, 320);
  }

  function resetFilters() {
    ['fcQ', 'fcVendor', 'fcClient', 'fcStatus', 'fcAging', 'fcValueMin', 'fcValueMax'].forEach(function (id) { setValue(id, ''); });
    setChecked('fcOnlyOverdue', false);
    setChecked('fcOnlyNoVendor', false);
    state.page = 1;
    state.sort = 'atraso';
    state.direction = 'desc';
    document.querySelectorAll('[data-fc-quick]').forEach(function (btn) { btn.classList.toggle('nf-quick-filter-btn--active', btn.dataset.fcQuick === 'all'); });
    load(1);
  }

  function applyQuick(kind) {
    resetQuickActive(kind);
    if (kind === 'all') {
      setValue('fcStatus', '');
      setValue('fcAging', '');
      setChecked('fcOnlyOverdue', false);
      setChecked('fcOnlyNoVendor', false);
    } else if (kind === 'overdue') {
      setValue('fcStatus', 'vencido');
      setValue('fcAging', '');
      setChecked('fcOnlyOverdue', true);
      setChecked('fcOnlyNoVendor', false);
      state.sort = 'atraso';
      state.direction = 'desc';
    } else if (kind === 'today') {
      setValue('fcStatus', 'vence_hoje');
      setValue('fcAging', 'vence_hoje');
      setChecked('fcOnlyOverdue', false);
      setChecked('fcOnlyNoVendor', false);
    } else if (kind === 'no_vendor') {
      setChecked('fcOnlyNoVendor', true);
      setValue('fcVendor', '');
    } else if (kind === '60_plus') {
      setValue('fcStatus', 'vencido');
      setValue('fcAging', '60_plus');
      setChecked('fcOnlyOverdue', true);
      setChecked('fcOnlyNoVendor', false);
      state.sort = 'atraso';
      state.direction = 'desc';
    }
    load(1);
  }

  function resetQuickActive(kind) {
    document.querySelectorAll('[data-fc-quick]').forEach(function (btn) {
      btn.classList.toggle('nf-quick-filter-btn--active', btn.dataset.fcQuick === kind);
    });
  }

  function initMobileTables() {
    if (window.BIomieMobileShell && window.BIomieMobileShell.initCardTables) {
      window.BIomieMobileShell.initCardTables();
    }
  }

  function openDrawer(titleKey, clientId, vendorId, isRevisarVinculo) {
    byId('collectionActionBackdrop').style.display = 'block';
    var drawer = byId('collectionActionDrawer');
    drawer.style.display = 'flex';
    drawer.offsetHeight;
    drawer.classList.add('open');

    setValue('fcFormTituloKey', titleKey);
    setValue('fcFormClienteId', clientId);
    setValue('fcFormVendedorId', vendorId);

    var titleData = lastLoadedData.find(function (item) { return (item.titulo_key || item.id) === titleKey; }) || {};

    setText('fcDrawerSubtitle', 'Título: ' + (titleData.documento || titleKey));

    // Cliente
    setText('fcInfoClient', titleData.cliente_nome_resolvido || titleData.cliente || '-');
    var clientDocEl = byId('fcInfoClientDoc');
    if (clientDocEl) clientDocEl.textContent = titleData.cliente_documento || titleData.documento_cliente || '';

    // Vendedor — show as chip with source hint
    var vendorEl = byId('fcInfoVendor');
    if (vendorEl) {
      var vNome   = titleData.vendedor_nome_resolvido || titleData.vendedor || '';
      var vSrc    = String(titleData.vendedor_source || '');
      if (vNome) {
        var vColor = titleData.sem_vendedor ? 'color:var(--warning-text,#b45309);background:var(--warning-bg-light,#fffbeb);border-color:var(--warning-border,#fde68a);' : 'color:var(--color-blue-700,#1d4ed8);background:var(--color-blue-50,#eff6ff);border-color:#bfdbfe;';
        vendorEl.innerHTML = '<span class="fin-tag" style="' + vColor + '" title="' + escapeHtml(vSrc) + '">' + escapeHtml(vNome) + '</span>';
      } else {
        vendorEl.innerHTML = '<span class="fin-badge fin-badge-gray" style="font-size:11px;">Sem vendedor</span>';
      }
    }

    setText('fcInfoDueDate', formatDateBR(titleData.vencimento));
    setText('fcInfoOpenValue', formatCurrency(titleData.valor_aberto));

    // NF / Pedido / Método chips
    var nfPedidoEl = byId('fcInfoNfPedido');
    if (nfPedidoEl) {
      var chips = [];
      var nfLbl = String(titleData.nf_label || '').trim();
      var pedLbl = String(titleData.pedido_label || '').trim();
      var methodLbl = String(titleData.payment_method_label || titleData.forma_pagamento || '').trim();
      var nfSrc = String(titleData.nf_source || '').trim();
      if (nfLbl) chips.push('<span class="fin-tag" title="' + escapeHtml(titleData.nf_chave_nfe || nfLbl) + ' — via ' + escapeHtml(nfSrc) + '">' + escapeHtml(nfLbl) + '</span>');
      if (pedLbl) chips.push('<span class="fin-tag" style="background:var(--color-blue-50,#eff6ff);color:var(--color-blue-700,#1d4ed8);">' + escapeHtml(pedLbl) + '</span>');
      if (methodLbl) chips.push('<span class="fin-badge fin-badge-gray" style="font-size:10px;">' + escapeHtml(methodLbl) + '</span>');
      nfPedidoEl.innerHTML = chips.length ? chips.join('') : '<span style="color:var(--text-3,#9ca3af);font-size:11px;">Sem vínculo NF/Pedido</span>';
    }

    // Trace quality alert
    var traceEl = byId('fcInfoTraceQuality');
    if (traceEl) {
      var score = typeof titleData.trace_quality_score === 'number' ? titleData.trace_quality_score : -1;
      var gap   = String(titleData.gap_reason || '').trim();
      var missing = Array.isArray(titleData.missing_fields) ? titleData.missing_fields : [];
      if (score >= 0 && score < 75) {
        traceEl.style.display = 'block';
        traceEl.innerHTML = '<strong>Qualidade do vínculo: ' + score + '/100</strong>' +
          (gap ? ' — ' + escapeHtml(gap) : '') +
          (missing.length ? '<br>Campos ausentes: ' + escapeHtml(missing.join(', ')) : '');
      } else {
        traceEl.style.display = 'none';
      }
    }

    // Diagnostic section
    var diagEl = byId('fcDiagnosticContent');
    if (diagEl) {
      var parts = [];
      parts.push('<strong>Título:</strong> ' + escapeHtml(titleData.documento || titleKey));
      if (titleData.nota_key) parts.push('<strong>nota_key:</strong> ' + escapeHtml(String(titleData.nota_key).slice(0, 20)) + (String(titleData.nota_key).length > 20 ? '…' : ''));
      if (titleData.nf_chave_nfe) parts.push('<strong>chave_nfe:</strong> ' + escapeHtml(String(titleData.nf_chave_nfe).slice(0, 20)) + '…');
      if (titleData.nf_label) parts.push('<strong>NF:</strong> ' + escapeHtml(titleData.nf_label) + (titleData.nf_source ? ' <em>(' + escapeHtml(titleData.nf_source) + ')</em>' : ''));
      if (titleData.pedido_label) parts.push('<strong>Pedido:</strong> ' + escapeHtml(titleData.pedido_label));
      if (titleData.vendedor_nome_resolvido) parts.push('<strong>Vendedor:</strong> ' + escapeHtml(titleData.vendedor_nome_resolvido) + (titleData.vendedor_source ? ' <em>(' + escapeHtml(titleData.vendedor_source) + ')</em>' : ''));
      var scoreVal = typeof titleData.trace_quality_score === 'number' ? titleData.trace_quality_score : '?';
      parts.push('<strong>Qualidade:</strong> ' + scoreVal + '/100');
      diagEl.innerHTML = parts.join('<br>');
    }

    if (isRevisarVinculo) {
      setValue('fcFormActionType', 'revisar_vinculo_financeiro');
    } else {
      setValue('fcFormActionType', '');
    }
    setValue('fcFormNextDate', '');
    setValue('fcFormNote', '');

    byId('fcDrawerAlert').style.display = 'none';
    loadHistory(titleKey);
  }

  function closeDrawer() {
    var drawer = byId('collectionActionDrawer');
    drawer.classList.remove('open');
    window.setTimeout(function() {
      drawer.style.display = 'none';
      byId('collectionActionBackdrop').style.display = 'none';
    }, 300);
  }

  function loadHistory(titleKey) {
    var listNode = byId('fcHistoryList');
    listNode.innerHTML = '<p style="font-size:0.8rem;color:var(--text-3);font-style:italic;">Carregando histórico...</p>';
    
    window.fetch('api/finance_collection_action.php?titulo_key=' + encodeURIComponent(titleKey), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload.ok) throw new Error(payload.error && payload.error.message || 'Erro ao carregar.');
        
        var history = payload.data || [];
        if (!history.length) {
          listNode.innerHTML = '<p style="font-size:0.8rem;color:var(--text-3);font-style:italic;">Nenhuma ação registrada para este título.</p>';
          return;
        }
        
        listNode.innerHTML = history.map(function (item) {
          var label = (item.action_type || '').replace(/_/g, ' ');
          label = label.charAt(0).toUpperCase() + label.slice(1);
          var nextDateInfo = item.next_action_date ? ' | Próxima: ' + formatDateBR(item.next_action_date) : '';
          
          return '<div class="fc-history-item" style="margin-bottom: 8px; border: 1px solid var(--mag-border, #e2e8f0); padding: 8px; border-radius: 4px; background: var(--bg-hover, #f8fafc);">' +
            '<div class="fc-history-meta" style="display:flex; justify-content:space-between; color:var(--text-3); font-size:10px; margin-bottom: 4px;">' +
            '<span><strong>' + escapeHtml(item.actor_full_name) + '</strong></span>' +
            '<span>' + formatDateBR(item.created_at.substring(0, 10)) + ' ' + item.created_at.substring(11, 16) + '</span>' +
            '</div>' +
            '<div style="font-weight:600; font-size:11px; margin-bottom:2px; color: var(--text-1);">' + escapeHtml(label) + nextDateInfo + '</div>' +
            (item.note ? '<div style="color:var(--text-2); font-size:11px; word-break:break-word;">' + escapeHtml(item.note) + '</div>' : '') +
            '</div>';
        }).join('');
      })
      .catch(function (err) {
        listNode.innerHTML = '<p style="font-size:0.8rem;color:var(--text-danger,#ef4444);font-style:italic;">Erro ao carregar histórico: ' + escapeHtml(err.message) + '</p>';
      });
  }

  function submitAction(e) {
    if (e) e.preventDefault();
    
    var alertNode = byId('fcDrawerAlert');
    alertNode.style.display = 'none';
    
    var data = {
      titulo_key: valueOf('fcFormTituloKey'),
      cliente_codigo_omie: valueOf('fcFormClienteId'),
      vendedor_codigo_omie: valueOf('fcFormVendedorId'),
      action_type: valueOf('fcFormActionType'),
      note: valueOf('fcFormNote'),
      next_action_date: valueOf('fcFormNextDate')
    };

    window.fetch('api/finance_collection_action.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      body: JSON.stringify(data),
      credentials: 'same-origin'
    })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload.ok) throw new Error(payload.error && payload.error.message || 'Erro ao salvar.');
        load(state.page);
        closeDrawer();
      })
      .catch(function (err) {
        alertNode.style.display = 'block';
        alertNode.className = 'biomie-table-feedback biomie-table-feedback--error';
        alertNode.textContent = err.message || 'Erro desconhecido.';
      });
  }

  function quickMarkCobrado(titleKey, clientId, vendorId) {
    feedback('Registrando cobrança...', false);
    
    var data = {
      titulo_key: titleKey,
      cliente_codigo_omie: clientId,
      vendedor_codigo_omie: vendorId,
      action_type: 'cobrado_hoje',
      note: 'Marcação rápida realizada pela carteira',
      next_action_date: ''
    };

    window.fetch('api/finance_collection_action.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      body: JSON.stringify(data),
      credentials: 'same-origin'
    })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload.ok) throw new Error(payload.error && payload.error.message || 'Erro ao registrar.');
        load(state.page);
      })
      .catch(function (err) {
        feedback(err.message || 'Erro ao marcar cobrado.', true);
      });
  }

  function injectStyles() {
    if (document.getElementById('fcActionStyles')) return;
    var style = document.createElement('style');
    style.id = 'fcActionStyles';
    style.textContent = 
      '.btn-xs-action { font-family: inherit; font-size: 11px; font-weight: 500; padding: 4px 8px; border-radius: 4px; border: 1px solid var(--mag-border, #e2e8f0); background-color: var(--card-bg, #ffffff); color: var(--text-1, #1e293b); cursor: pointer; transition: all 0.2s ease; }' +
      '.btn-xs-action:hover { background-color: var(--bg-hover, #f1f5f9); border-color: var(--text-3, #94a3b8); }' +
      '.btn-marcar-cobrado { background-color: var(--success-bg-light, #ecfdf5); color: var(--success-text, #0f766e); border-color: var(--success-border, #a7f3d0); }' +
      '.btn-marcar-cobrado:hover { background-color: #d1fae5; border-color: #34d399; }' +
      '.btn-revisar-vinculo { background-color: var(--warning-bg-light, #fffbeb); color: var(--warning-text, #b45309); border-color: var(--warning-border, #fde68a); }' +
      '.btn-revisar-vinculo:hover { background-color: #fef3c7; border-color: #fbbf24; }' +
      '.fin-badge-blue { background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }';
    document.head.appendChild(style);
  }

  function bindEvents() {
    ['fcQ', 'fcVendor', 'fcClient', 'fcValueMin', 'fcValueMax'].forEach(function (id) {
      var node = byId(id);
      if (node) node.addEventListener('input', debouncedLoad);
    });
    ['fcStatus', 'fcAging', 'fcOnlyOverdue', 'fcOnlyNoVendor'].forEach(function (id) {
      var node = byId(id);
      if (node) node.addEventListener('change', function () { load(1); });
    });
    var apply = byId('fcApply');
    if (apply) apply.addEventListener('click', function () { load(1); });
    var reset = byId('fcReset');
    if (reset) reset.addEventListener('click', resetFilters);
    var globalApply = byId('applyFilters');
    if (globalApply) globalApply.addEventListener('click', function () { window.setTimeout(function () { load(1); }, 0); });

    document.addEventListener('click', function (event) {
      var chip = event.target.closest('[data-fc-chip]');
      if (chip) {
        clearChip(chip.dataset.fcChip);
        return;
      }
      var page = event.target.closest('[data-fc-page]');
      if (page) {
        load(Number(page.dataset.fcPage || '1') || 1);
        return;
      }
      var sort = event.target.closest('[data-fc-sort]');
      if (sort) {
        var field = sort.dataset.fcSort;
        if (state.sort === field) {
          state.direction = state.direction === 'asc' ? 'desc' : 'asc';
        } else {
          state.sort = field;
          state.direction = 'desc';
        }
        load(state.page || 1);
        return;
      }
      var vendorRow = event.target.closest('[data-fc-vendor-row]');
      if (vendorRow) {
        setChecked('fcOnlyNoVendor', vendorRow.dataset.fcNoVendor === '1');
        setValue('fcVendor', vendorRow.dataset.fcNoVendor === '1' ? '' : vendorRow.dataset.fcVendorRow);
        load(1);
        return;
      }
      var agingRow = event.target.closest('[data-fc-aging-row]');
      if (agingRow) {
        setValue('fcAging', agingRow.dataset.fcAgingRow);
        load(1);
        return;
      }
      var btnRevisar = event.target.closest('.btn-revisar-vinculo');
      if (btnRevisar) {
        openDrawer(btnRevisar.dataset.fcTitleKey, btnRevisar.dataset.fcClientId, btnRevisar.dataset.fcVendorId, true);
        return;
      }
      var btnRegistrar = event.target.closest('.btn-registrar-cobranca');
      if (btnRegistrar) {
        openDrawer(btnRegistrar.dataset.fcTitleKey, btnRegistrar.dataset.fcClientId, btnRegistrar.dataset.fcVendorId, false);
        return;
      }
      var btnCobrado = event.target.closest('.btn-marcar-cobrado');
      if (btnCobrado) {
        quickMarkCobrado(btnCobrado.dataset.fcTitleKey, btnCobrado.dataset.fcClientId, btnCobrado.dataset.fcVendorId);
        return;
      }
    });

    document.querySelectorAll('[data-fc-quick]').forEach(function (button) {
      button.addEventListener('click', function () {
        applyQuick(button.dataset.fcQuick || 'all');
      });
    });

    var closeBtn = byId('fcCloseDrawerBtn');
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    
    var backdrop = byId('collectionActionBackdrop');
    if (backdrop) backdrop.addEventListener('click', closeDrawer);
    
    var form = byId('fcActionForm');
    if (form) form.addEventListener('submit', submitAction);
  }

  function init() {
    if (!byId('financeCollectionSection')) return;
    injectStyles();
    restoreUrl();
    bindEvents();
    window.setTimeout(function () { load(state.page || 1); }, 40);
    window.BIomieFinanceCollection = {
      reload: function () { load(1); },
      getState: function () { return currentFilters(state.page); }
    };
  }

  document.addEventListener('DOMContentLoaded', init);
})();
