/**
 * FIN.R3.3 — Governança das Ações de Cobrança JS Module.
 * Consolidates operator productivity, SLAs, follow-ups, and risk queue prioritization.
 */
(function () {
  'use strict';

  var B = window.BIomie || {};
  var lastLoadedRiskQueue = [];
  var abortCtrl = null;
  var debounceTimer = null;

  var state = {
    page: 1,
    sort: 'prioridade',
    direction: 'asc',
    quick: 'all'
  };

  var ACTION_LABELS = {
    cobrado_hoje: 'Cobrado hoje',
    whatsapp_enviado: 'WhatsApp enviado',
    ligacao_realizada: 'Ligacao realizada',
    email_enviado: 'E-mail enviado',
    aguardando_retorno: 'Aguardando retorno',
    promessa_pagamento: 'Promessa de pagamento',
    validar_baixa_caixa: 'Validar baixa no caixa',
    revisar_vinculo_financeiro: 'Revisar vinculo financeiro'
  };

  var QUICK_LABELS = {
    critical: 'Criticos',
    broken_promises: 'Promessas vencidas',
    no_action: 'Titulos sem acao',
    due_today: 'Retornos de hoje',
    open_link_review: 'Revisoes de vinculo'
  };

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
    var node = byId('govFeedback');
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
      vendor: valueOf('govFilterVendor'),
      actor_user_id: valueOf('govFilterActor'),
      action_type: valueOf('govFilterActionType'),
      q: valueOf('govFilterQ'),
      only_critical: checked('govFilterCriticalOnly') || state.quick === 'critical' ? 1 : 0,
      only_broken_promises: state.quick === 'broken_promises' ? 1 : 0,
      only_no_action: state.quick === 'no_action' ? 1 : 0,
      only_due_today: state.quick === 'due_today' ? 1 : 0,
      only_open_link_review: state.quick === 'open_link_review' ? 1 : 0,
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
    params.set('per_page', '50');
    params.set('sort', filters.sort || 'prioridade');
    params.set('direction', filters.direction || 'asc');
    if (filters.vendor) params.set('vendor', filters.vendor);
    if (filters.actor_user_id) params.set('actor_user_id', filters.actor_user_id);
    if (filters.action_type) params.set('action_type', filters.action_type);
    if (filters.q) params.set('q', filters.q);
    if (filters.only_critical) params.set('only_critical', '1');
    if (filters.only_broken_promises) params.set('only_broken_promises', '1');
    if (filters.only_no_action) params.set('only_no_action', '1');
    if (filters.only_due_today) params.set('only_due_today', '1');
    if (filters.only_open_link_review) params.set('only_open_link_review', '1');
    return params;
  }

  function saveUrl(filters) {
    var params = new URLSearchParams(window.location.search);
    var set = function (key, value) {
      if (value) params.set(key, value);
      else params.delete(key);
    };
    set('gov_vendor', filters.vendor);
    set('gov_actor', filters.actor_user_id);
    set('gov_action_type', filters.action_type);
    set('gov_q', filters.q);
    set('gov_critical', filters.only_critical ? '1' : '');
    set('gov_quick', state.quick !== 'all' ? state.quick : '');
    set('gov_sort', filters.sort !== 'prioridade' ? filters.sort : '');
    set('gov_dir', filters.direction !== 'asc' ? filters.direction : '');
    set('gov_page', filters.page > 1 ? String(filters.page) : '');
    window.history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''));
  }

  function restoreUrl() {
    var params = new URLSearchParams(window.location.search);
    setValue('govFilterVendor', params.get('gov_vendor') || '');
    setValue('govFilterActor', params.get('gov_actor') || '');
    setValue('govFilterActionType', params.get('gov_action_type') || '');
    setValue('govFilterQ', params.get('gov_q') || '');
    setChecked('govFilterCriticalOnly', params.get('gov_critical') === '1');
    state.quick = params.get('gov_quick') || 'all';
    state.sort = params.get('gov_sort') || 'prioridade';
    state.direction = params.get('gov_dir') || 'asc';
    state.page = Number(params.get('gov_page') || '1') || 1;
    resetQuickActive(state.quick);
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

  function govStatusBadge(status) {
    if (status === 'ok') return '<span class="fin-badge fin-badge-green">OK</span>';
    if (status === 'atencao') return '<span class="fin-badge fin-badge-gold">ATENÇÃO</span>';
    if (status === 'critico') return '<span class="fin-badge fin-badge-red">CRÍTICO</span>';
    if (status === 'sem_vendedor') return '<span class="fin-badge fin-badge-gray">SEM VENDEDOR</span>';
    return '<span class="fin-badge fin-badge-gray">' + escapeHtml(status).toUpperCase() + '</span>';
  }

  function clearChip(key) {
    if (key === 'vendor') setValue('govFilterVendor', '');
    if (key === 'actor') setValue('govFilterActor', '');
    if (key === 'action_type') setValue('govFilterActionType', '');
    if (key === 'q') setValue('govFilterQ', '');
    if (key === 'critical') setChecked('govFilterCriticalOnly', false);
    if (key === 'quick') {
      state.quick = 'all';
      resetQuickActive('all');
    }
    load(1);
  }

  function renderChips(filters) {
    var node = byId('govChips');
    if (!node) return;
    var chips = [];
    if (filters.vendor) chips.push(['vendor', 'Vendedor: ' + filters.vendor]);
    if (filters.actor_user_id) chips.push(['actor', 'Operador: ' + filters.actor_user_id]);
    if (filters.action_type) chips.push(['action_type', 'Acao: ' + (ACTION_LABELS[filters.action_type] || filters.action_type)]);
    if (filters.q) chips.push(['q', 'Busca: ' + filters.q]);
    if (state.quick !== 'all') chips.push(['quick', 'Visao: ' + (QUICK_LABELS[state.quick] || state.quick)]);
    if (filters.only_critical && state.quick === 'all') chips.push(['critical', 'Somente criticos']);

    if (!chips.length) {
      node.style.display = 'none';
      node.innerHTML = '';
      return;
    }

    if (B.FChips && B.FChips.render) {
      B.FChips.render('govChips', chips.map(function (chip) {
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
      return '<button class="biomie-filter-chip" type="button" data-gov-chip="' + chip[0] + '">' +
        escapeHtml(chip[1]) + ' <span aria-hidden="true">x</span></button>';
    }).join('');
  }

  function renderKpis(summary) {
    summary = summary || {};
    setText('govKpiCobradosHoje', formatNumber(summary.cobrados_hoje || 0));
    setText('govKpiRetornosHoje', formatNumber(summary.retornos_hoje || 0));
    setText('govKpiRetornosAtrasados', formatNumber(summary.retornos_atrasados || 0));
    setText('govKpiPromessasVencidas', formatNumber(summary.promessas_pagamento_vencidas || 0));
    setText('govKpiVencidosSemAcao', formatNumber(summary.titulos_sem_acao_vencidos || 0));
    setText('govKpiSemVendedor', formatNumber(summary.sem_vendedor_definido || 0));
    
    var total = Number(summary.titulos_abertos || 0);
    var withAction = total - Number(summary.titulos_sem_acao || 0);
    var pct = total > 0 ? (withAction / total) * 100 : 0.0;
    setText('govKpiPctAcionada', pct.toFixed(1).replace('.', ',') + '%');
  }

  function renderProductivity(rows) {
    var target = byId('govProductivityBody');
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<tr><td colspan="10" class="text-center py-3 text-muted">Nenhuma produtividade registrada.</td></tr>';
      return;
    }
    target.innerHTML = rows.map(function (row) {
      var period = '-';
      if (row.primeira_acao_at && row.ultima_acao_at) {
        period = formatDateBR(row.primeira_acao_at.substring(0, 10)) + ' a ' + formatDateBR(row.ultima_acao_at.substring(0, 10));
      }
      return '<tr>' +
        '<td><strong>' + escapeHtml(row.actor_name) + '</strong></td>' +
        '<td>' + formatNumber(row.total_acoes) + '</td>' +
        '<td>' + formatNumber(row.titulos_unicos) + '</td>' +
        '<td>' + formatNumber(row.clientes_unicos) + '</td>' +
        '<td>' + formatNumber(row.whatsapp_enviado) + '</td>' +
        '<td>' + formatNumber(row.ligacao_realizada) + '</td>' +
        '<td>' + formatNumber(row.email_enviado) + '</td>' +
        '<td>' + formatNumber(row.promessa_pagamento) + '</td>' +
        '<td>' + formatNumber(row.aguardando_retorno) + '</td>' +
        '<td><small class="text-muted">' + escapeHtml(period) + '</small></td>' +
        '</tr>';
    }).join('');
  }

  function renderVendorGov(rows) {
    var target = byId('govVendorGovBody');
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Nenhum vendedor encontrado.</td></tr>';
      return;
    }
    target.innerHTML = rows.map(function (row) {
      var pct = Number(row.percentual_carteira_acionada || 0).toFixed(1).replace('.', ',') + '%';
      return '<tr>' +
        '<td><strong>' + escapeHtml(row.nome_vendedor) + '</strong></td>' +
        '<td>' + formatCurrency(row.valor_aberto) + '</td>' +
        '<td>' + formatCurrency(row.valor_vencido) + '</td>' +
        '<td>' + pct + '</td>' +
        '<td>' + govStatusBadge(row.status_governanca) + '</td>' +
        '</tr>';
    }).join('');
  }

  function renderSlaAging(rows) {
    var target = byId('govSlaAgingBody');
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Sem dados para calcular o prazo de atendimento.</td></tr>';
      return;
    }
    target.innerHTML = rows.map(function (row) {
      var pct = Number(row.percentual_sla || 0).toFixed(1).replace('.', ',') + '%';
      return '<tr>' +
        '<td><strong>' + escapeHtml(row.label) + '</strong></td>' +
        '<td>' + formatNumber(row.qtd_titulos) + '</td>' +
        '<td>' + formatCurrency(row.valor_aberto) + '</td>' +
        '<td>' + formatNumber(row.qtd_com_acao) + '</td>' +
        '<td><strong>' + pct + '</strong></td>' +
        '</tr>';
    }).join('');
  }

  function renderFollowups(followups) {
    var target = byId('govFollowupsBody');
    if (!target) return;
    followups = followups || {};
    var list = [].concat(followups.vencidos || [], followups.hoje || [], followups.proximos || []);
    if (!list.length) {
      target.innerHTML = '<tr><td colspan="9" class="text-center py-3 text-muted">Nenhum retorno agendado.</td></tr>';
      return;
    }
    target.innerHTML = list.map(function (row) {
      var statusLabel = '';
      if (row.dias_para_retorno < 0) {
        statusLabel = ' <span class="fin-badge fin-badge-red" style="font-size:9px;padding:2px 4px;">Atrasado ' + Math.abs(row.dias_para_retorno) + 'd</span>';
      } else if (row.dias_para_retorno === 0) {
        statusLabel = ' <span class="fin-badge fin-badge-gold" style="font-size:9px;padding:2px 4px;">Hoje</span>';
      } else {
        statusLabel = ' <span class="fin-badge fin-badge-green" style="font-size:9px;padding:2px 4px;">Em ' + row.dias_para_retorno + 'd</span>';
      }
      
      var delayBadge = row.dias_atraso > 0 ? '<span class="fin-badge fin-badge-red" style="font-size:10px;padding:2px 4px;">' + row.dias_atraso + 'd</span>' : '<span class="fin-badge fin-badge-green" style="font-size:10px;padding:2px 4px;">No prazo</span>';
      
      return '<tr>' +
        '<td>' + actionBadge({ action_type: row.action_type }) + '</td>' +
        '<td><strong>' + escapeHtml(row.numero_documento) + '</strong></td>' +
        '<td><div class="fin-doc-cell"><strong>' + escapeHtml(row.cliente) + '</strong></div></td>' +
        '<td>' + formatDateBR(row.next_action_date) + statusLabel + '</td>' +
        '<td>' + escapeHtml(row.actor) + '</td>' +
        '<td><small class="text-muted" title="' + escapeHtml(row.note) + '">' + escapeHtml(row.note) + '</small></td>' +
        '<td><strong>' + formatCurrency(row.valor_aberto) + '</strong></td>' +
        '<td>' + formatDateBR(row.vencimento) + '</td>' +
        '<td>' + delayBadge + '</td>' +
        '</tr>';
    }).join('');
  }

  function riskNfCell(row) {
    var parts = [];
    var nfLabel   = String(row.nf_label || '').trim();
    var nf        = String(row.numero_nf || '').trim();
    var notaKey   = String(row.nota_key || '').trim();
    var pedidoLabel = String(row.pedido_label || '').trim();
    var pedido    = String(row.pedido_id_omie || row.codigo_pedido_origem || '').trim();
    var gap       = String(row.gap_reason || '').trim();

    if (nfLabel) {
      var nfTitle = row.nf_chave_nfe || notaKey || nfLabel;
      parts.push('<span class="fin-tag" title="' + escapeHtml(nfTitle) + '">' + escapeHtml(nfLabel) + '</span>');
    } else if (nf) {
      parts.push('<span class="fin-tag">NF ' + escapeHtml(nf) + '</span>');
    } else if (notaKey) {
      var short = notaKey.length > 12 ? '…' + notaKey.slice(-8) : notaKey;
      parts.push('<span class="fin-tag" title="' + escapeHtml(notaKey) + '">NF ' + escapeHtml(short) + '</span>');
    }
    if (pedidoLabel) {
      parts.push('<span class="fin-tag" style="background:var(--color-blue-50,#eff6ff);color:var(--color-blue-700,#1d4ed8);">' + escapeHtml(pedidoLabel) + '</span>');
    } else if (pedido && pedido !== '0') {
      parts.push('<span class="fin-tag" style="background:var(--color-blue-50,#eff6ff);color:var(--color-blue-700,#1d4ed8);">Ped. ' + escapeHtml(pedido) + '</span>');
    }
    if (!parts.length && gap && gap !== 'ok') {
      parts.push('<span class="fin-badge fin-badge-gray" style="font-size:10px;" title="' + escapeHtml(gap) + '">Sem vínculo</span>');
    }
    if (!parts.length) {
      parts.push('<span style="color:var(--text-3,#9ca3af);">—</span>');
    }
    return '<div style="display:flex;flex-direction:column;gap:2px;">' + parts.join('') + '</div>';
  }

  function renderRiskQueue(rows) {
    var target = byId('govRiskQueueBody');
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<tr><td colspan="11" class="text-center py-4 text-muted">Nenhum título crítico na fila de risco operacional.</td></tr>';
      return;
    }
    target.innerHTML = rows.map(function (row) {
      var levelClass = row.prioridade_nivel === 1 ? 'red' : (row.prioridade_nivel === 2 ? 'gold' : 'green');
      var priorityBadge = '<span class="fin-badge fin-badge-' + levelClass + '">' + escapeHtml(row.prioridade_label) + '</span>';

      var isNoVendor = row.vendedor_key === '__sem_vendedor__';
      var actionsHtml = '';
      if (isNoVendor) {
        actionsHtml = '<button class="btn-xs-action btn-revisar-vinculo" data-fc-title-key="' + escapeHtml(row.titulo_key) + '" data-fc-client-id="' + escapeHtml(row.codigo_cliente_omie) + '" data-fc-vendor-id="' + escapeHtml(row.codigo_vendedor_omie) + '">Revisar vínculo</button>';
      } else {
        actionsHtml = '<div style="display:flex;gap:4px;">' +
          '<button class="btn-xs-action btn-registrar-cobranca" data-fc-title-key="' + escapeHtml(row.titulo_key) + '" data-fc-client-id="' + escapeHtml(row.codigo_cliente_omie) + '" data-fc-vendor-id="' + escapeHtml(row.codigo_vendedor_omie) + '">Registrar cobrança</button>' +
          '<button class="btn-xs-action btn-marcar-cobrado" data-fc-title-key="' + escapeHtml(row.titulo_key) + '" data-fc-client-id="' + escapeHtml(row.codigo_cliente_omie) + '" data-fc-vendor-id="' + escapeHtml(row.codigo_vendedor_omie) + '">Marcar cobrado</button>' +
          '</div>';
      }

      var lastActBadge = row.ultima_acao ? actionBadge(row.ultima_acao) : '<span class="fin-badge fin-badge-gray" style="font-size:10px;padding:2px 4px;">Sem ação</span>';

      var vendorGap = (isNoVendor && row.gap_reason && row.gap_reason !== 'ok')
        ? '<small class="fin-gap-hint" title="' + escapeHtml(row.gap_reason) + '">Revisar vínculo</small>'
        : '';

      var scoreHtml = (typeof row.trace_quality_score === 'number' && row.trace_quality_score < 75)
        ? '<span class="fin-badge fin-badge-gray" style="font-size:9px;padding:1px 4px;" title="Qualidade da identificação: ' + row.trace_quality_score + '/100">Dados: ' + row.trace_quality_score + '</span>'
        : '';

      return '<tr>' +
        '<td>' + priorityBadge + '</td>' +
        '<td><div class="fin-doc-cell"><strong>' + escapeHtml(row.prioridade_motivo) + '</strong>' + lastActBadge + '</div></td>' +
        '<td><div class="fin-doc-cell"><strong>' + escapeHtml(row.nome_cliente || 'Cliente não informado') + '</strong>' +
        (row.documento_cliente ? '<small>' + escapeHtml(row.documento_cliente) + '</small>' : '') + '</div></td>' +
        '<td><div class="fin-doc-cell">' + escapeHtml(row.nome_vendedor || '—') + vendorGap + '</div></td>' +
        '<td><div class="fin-doc-cell"><strong>' + escapeHtml(row.numero_documento || '—') + '</strong>' + scoreHtml + '</div></td>' +
        '<td>' + riskNfCell(row) + '</td>' +
        '<td>' + formatDateBR(row.data_vencimento) + '</td>' +
        '<td>' + (row.dias_atraso > 0 ? '<span class="fin-badge fin-badge-red">' + row.dias_atraso + 'd</span>' : '<span class="fin-badge fin-badge-green">No prazo</span>') + '</td>' +
        '<td><strong>' + formatCurrency(row.valor_aberto) + '</strong></td>' +
        '<td><strong>' + escapeHtml(row.acao_recomendada) + '</strong></td>' +
        '<td>' + actionsHtml + '</td>' +
        '</tr>';
    }).join('');
  }

  function renderPagination(pagination) {
    var node = byId('govPagination');
    if (!node || !pagination) return;
    if (pagination.total <= pagination.per_page) {
      node.style.display = 'none';
      node.innerHTML = '';
      return;
    }
    node.style.display = 'flex';
    var html = '<span style="margin-right:auto;">' + formatNumber(pagination.total) + ' título(s) - pag. ' +
      pagination.page + '/' + pagination.total_pages + '</span>';
    if (pagination.has_prev) html += '<button class="fin-page-btn" type="button" data-gov-page="' + (pagination.page - 1) + '">Anterior</button>';
    var from = Math.max(1, pagination.page - 2);
    var to = Math.min(pagination.total_pages, pagination.page + 2);
    for (var i = from; i <= to; i++) {
      html += '<button class="fin-page-btn' + (i === pagination.page ? ' active' : '') + '" type="button" data-gov-page="' + i + '">' + i + '</button>';
    }
    if (pagination.has_next) html += '<button class="fin-page-btn" type="button" data-gov-page="' + (pagination.page + 1) + '">Próxima</button>';
    node.innerHTML = html;
  }

  function updateSortHeaders() {
    document.querySelectorAll('[data-gov-sort]').forEach(function (th) {
      var icon = th.querySelector('.nf-sort-icon');
      if (!icon) return;
      if (th.dataset.govSort === state.sort) {
        icon.innerHTML = state.direction === 'asc' ? '&#8593;' : '&#8595;';
        th.classList.add('nf-th-active-sort');
      } else {
        icon.innerHTML = '&#8597;';
        th.classList.remove('nf-th-active-sort');
      }
    });
  }

  function load(page) {
    var filters = currentFilters(page || 1);
    if (!filters.start || !filters.end) {
      feedback('Selecione um período para carregar o painel de governança.', false);
      return;
    }
    state.page = filters.page;
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();

    feedback('Carregando governança de cobrança...', false);
    window.fetch('api/finance_collection_governance.php?' + queryFor(filters).toString(), {
      signal: abortCtrl.signal,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.redirected) throw new Error('Sessão expirada. Recarregue a página.');
        return response.json();
      })
      .then(function (payload) {
        if (!payload.ok) {
          throw new Error(payload.message || 'Falha ao carregar governança.');
        }
        feedback('');
        saveUrl(filters);
        lastLoadedRiskQueue = payload.risk_queue || [];
        renderKpis(payload.summary || {});
        renderProductivity(payload.productivity || []);
        renderVendorGov(payload.vendor_governance || []);
        renderFollowups(payload.followups || {});
        renderSlaAging(payload.sla_aging || []);
        renderRiskQueue(payload.risk_queue || []);
        renderPagination(payload.pagination || null);
        renderChips(filters);
        updateSortHeaders();
        
        var total = payload.pagination ? payload.pagination.total : 0;
        setText('govRiskMeta', total ? formatNumber(total) + ' título(s) crítico(s) na fila' : '');
        initMobileTables();
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        feedback(error.message || 'Falha ao carregar governança de cobrança.', true);
      });
  }

  function debouncedLoad() {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(function () { load(1); }, 320);
  }

  function resetFilters() {
    ['govFilterVendor', 'govFilterActor', 'govFilterActionType', 'govFilterQ'].forEach(function (id) { setValue(id, ''); });
    setChecked('govFilterCriticalOnly', false);
    state.page = 1;
    state.sort = 'prioridade';
    state.direction = 'asc';
    state.quick = 'all';
    resetQuickActive('all');
    load(1);
  }

  function applyQuick(kind) {
    resetQuickActive(kind);
    state.quick = kind;
    if (kind === 'critical') {
      setChecked('govFilterCriticalOnly', true);
    } else {
      setChecked('govFilterCriticalOnly', false);
    }
    load(1);
  }

  function resetQuickActive(kind) {
    document.querySelectorAll('[data-gov-quick]').forEach(function (btn) {
      btn.classList.toggle('nf-quick-filter-btn--active', btn.dataset.govQuick === kind);
    });
  }

  function initMobileTables() {
    if (window.BIomieMobileShell && window.BIomieMobileShell.initCardTables) {
      window.BIomieMobileShell.initCardTables();
    }
  }

  function openGovDrawer(titleKey, clientId, vendorId, isRevisarVinculo) {
    byId('collectionActionBackdrop').style.display = 'block';
    var drawer = byId('collectionActionDrawer');
    drawer.style.display = 'flex';
    drawer.offsetHeight;
    drawer.classList.add('open');

    setValue('fcFormTituloKey', titleKey);
    setValue('fcFormClienteId', clientId);
    setValue('fcFormVendedorId', vendorId);

    setText('fcDrawerSubtitle', 'Título ID: ' + titleKey);
    
    var titleData = lastLoadedRiskQueue.find(function(item) { return item.titulo_key === titleKey; }) || {};
    
    setText('fcInfoClient', titleData.nome_cliente || '-');
    setText('fcInfoVendor', titleData.nome_vendedor || '-');
    setText('fcInfoDueDate', formatDateBR(titleData.data_vencimento));
    setText('fcInfoOpenValue', formatCurrency(titleData.valor_aberto));

    if (isRevisarVinculo) {
      setValue('fcFormActionType', 'revisar_vinculo_financeiro');
    } else {
      setValue('fcFormActionType', '');
    }
    setValue('fcFormNextDate', '');
    setValue('fcFormNote', '');
    
    byId('fcDrawerAlert').style.display = 'none';
    loadGovHistory(titleKey);
  }

  function loadGovHistory(titleKey) {
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

  function quickMarkGovCobrado(titleKey, clientId, vendorId) {
    feedback('Registrando cobrança...', false);
    
    var data = {
      titulo_key: titleKey,
      cliente_codigo_omie: clientId,
      vendedor_codigo_omie: vendorId,
      action_type: 'cobrado_hoje',
      note: 'Marcação rápida realizada pelo painel de governança',
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
        if (window.BIomieFinanceCollection && window.BIomieFinanceCollection.reload) {
          window.BIomieFinanceCollection.reload();
        }
      })
      .catch(function (err) {
        feedback(err.message || 'Erro ao marcar cobrado.', true);
      });
  }

  function bindEvents() {
    ['govFilterVendor', 'govFilterActor', 'govFilterQ'].forEach(function (id) {
      var node = byId(id);
      if (node) node.addEventListener('input', debouncedLoad);
    });
    ['govFilterActionType', 'govFilterCriticalOnly'].forEach(function (id) {
      var node = byId(id);
      if (node) node.addEventListener('change', function () { load(1); });
    });
    
    var apply = byId('govApply');
    if (apply) apply.addEventListener('click', function () { load(1); });
    
    var reset = byId('govReset');
    if (reset) reset.addEventListener('click', resetFilters);
    
    document.addEventListener('biomie:finance-period-changed', function () {
      load(1);
    });

    // Intercept clicks on tables for pagination & sorting
    document.addEventListener('click', function (event) {
      var page = event.target.closest('[data-gov-page]');
      if (page) {
        load(Number(page.dataset.govPage || '1') || 1);
        return;
      }
      var sort = event.target.closest('[data-gov-sort]');
      if (sort) {
        var field = sort.dataset.govSort;
        if (state.sort === field) {
          state.direction = state.direction === 'asc' ? 'desc' : 'asc';
        } else {
          state.sort = field;
          state.direction = 'asc';
        }
        load(state.page || 1);
        return;
      }
      var chip = event.target.closest('[data-gov-chip]');
      if (chip) {
        clearChip(chip.dataset.govChip);
        return;
      }
    });

    // Intercept quick buttons
    document.querySelectorAll('[data-gov-quick]').forEach(function (button) {
      button.addEventListener('click', function () {
        applyQuick(button.dataset.govQuick || 'all');
      });
    });

    // Intercept drawer events inside our Governance section specifically
    var govSection = byId('financeGovernanceSection');
    if (govSection) {
      govSection.addEventListener('click', function (event) {
        var btnRevisar = event.target.closest('.btn-revisar-vinculo');
        if (btnRevisar) {
          event.stopPropagation();
          openGovDrawer(btnRevisar.dataset.fcTitleKey, btnRevisar.dataset.fcClientId, btnRevisar.dataset.fcVendorId, true);
          return;
        }
        var btnRegistrar = event.target.closest('.btn-registrar-cobranca');
        if (btnRegistrar) {
          event.stopPropagation();
          openGovDrawer(btnRegistrar.dataset.fcTitleKey, btnRegistrar.dataset.fcClientId, btnRegistrar.dataset.fcVendorId, false);
          return;
        }
        var btnCobrado = event.target.closest('.btn-marcar-cobrado');
        if (btnCobrado) {
          event.stopPropagation();
          quickMarkGovCobrado(btnCobrado.dataset.fcTitleKey, btnCobrado.dataset.fcClientId, btnCobrado.dataset.fcVendorId);
          return;
        }
      });
    }

    document.addEventListener('biomie:finance-collection-action-recorded', function () {
      load(state.page || 1);
    });
  }

  function init() {
    if (!byId('financeGovernanceSection')) return;
    restoreUrl();
    bindEvents();
    window.setTimeout(function () { load(state.page || 1); }, 60);
    window.BIomieFinanceGovernance = {
      reload: function () { load(1); },
      getState: function () { return currentFilters(state.page); }
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
