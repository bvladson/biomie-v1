/*
 * BIomie — Painel da Diretoria (modo TV) v4
 * Contexto explícito, escopo por canal, estados confiáveis e atualização resiliente.
 */
(function () {
  'use strict';

  var body = document.body;
  var API_URL = body.getAttribute('data-api') || 'api/board_consolidated.php';
  var CONTEXT_ID = body.getAttribute('data-context') || '';
  var REFRESH_SEC = Math.max(20, parseInt(body.getAttribute('data-refresh') || '300', 10));
  var RETRY_DELAYS = [5, 15, 60, 60, 120];

  var els = {
    period: document.getElementById('tvbPeriod'),
    clock: document.getElementById('tvbClock'),
    date: document.getElementById('tvbDate'),
    fullscreen: document.getElementById('tvbFullscreen'),
    connection: document.getElementById('tvbConnection'),
    connectionText: document.getElementById('tvbConnectionText'),
    groupRevenue: document.getElementById('tvbGroupRevenue'),
    groupTarget: document.getElementById('tvbGroupTarget'),
    groupGauge: document.getElementById('tvbGroupGauge'),
    groupGaugeAnn: document.getElementById('tvbGroupGaugeAnn'),
    groupPct: document.getElementById('tvbGroupPct'),
    groupAnnPct: document.getElementById('tvbGroupAnnPct'),
    groupOrders: document.getElementById('tvbGroupOrders'),
    groupChannels: document.getElementById('tvbGroupChannels'),
    groupCoverage: document.getElementById('tvbGroupCoverage'),
    leader: document.getElementById('tvbLeader'),
    leaderRevenue: document.getElementById('tvbLeaderRevenue'),
    leaderAtt: document.getElementById('tvbLeaderAtt'),
    groupAnnualRevenue: document.getElementById('tvbGroupAnnualRevenue'),
    groupAnnualTarget: document.getElementById('tvbGroupAnnualTarget'),
    groupAnnualPctBadge: document.getElementById('tvbGroupAnnualPctBadge'),
    companySummary: document.getElementById('tvbCompanySummary'),
    grid: document.getElementById('tvbGrid'),
    status: document.getElementById('tvbStatus'),
    error: document.getElementById('tvbError'),
    errorMsg: document.getElementById('tvbErrorMsg'),
    retry: document.getElementById('tvbRetry'),
  };

  var state = {
    mode: 'loading',
    inFlight: false,
    controller: null,
    timer: null,
    timeout: null,
    retryAttempt: 0,
    nextLoadAt: null,
    generatedAt: null,
    sourceAt: null,
    hasSnapshot: false,
    statusMessage: '',
    retryRedirect: '',
  };

  function finite(value) {
    if (value === null || value === undefined || value === '') return null;
    var parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }

  function brl(value) {
    var parsed = finite(value);
    if (parsed === null) return '—';
    var absolute = Math.abs(parsed);
    if (absolute >= 1000000) {
      return 'R$\u00a0' + (parsed / 1000000).toFixed(2).replace('.', ',') + '\u00a0mi';
    }
    if (absolute >= 1000) {
      return 'R$\u00a0' + (parsed / 1000).toLocaleString('pt-BR', {
        minimumFractionDigits: absolute < 10000 ? 1 : 0,
        maximumFractionDigits: 1,
      }) + '\u00a0mil';
    }
    return 'R$\u00a0' + parsed.toLocaleString('pt-BR', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    });
  }

  function number(value) {
    return (finite(value) || 0).toLocaleString('pt-BR');
  }

  function pct(value, digits) {
    var parsed = finite(value);
    return parsed === null
      ? '—'
      : parsed.toLocaleString('pt-BR', {
          minimumFractionDigits: digits || 0,
          maximumFractionDigits: digits || 0,
        }) + '%';
  }

  function plural(value, singular, pluralForm) {
    return number(value) + ' ' + (Number(value) === 1 ? singular : pluralForm);
  }

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function parseDate(value) {
    if (!value) return null;
    var date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function shortDateTime(value) {
    var date = parseDate(value);
    if (!date) return 'horário indisponível';
    return date.toLocaleString('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    });
  }

  function humanAge(date) {
    if (!date) return 'agora';
    var seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'há ' + seconds + 's';
    var minutes = Math.round(seconds / 60);
    if (minutes < 60) return 'há ' + minutes + ' min';
    var hours = Math.round(minutes / 60);
    return 'há ' + hours + 'h';
  }

  function humanSeconds(seconds) {
    var value = Math.max(0, Math.round(seconds));
    if (value < 60) return value + 's';
    return Math.ceil(value / 60) + ' min';
  }

  function goalClass(status) {
    if (status === 'achieved' || status === 'on_track') return 'goal-good';
    if (status === 'attention') return 'goal-warn';
    if (status === 'risk') return 'goal-bad';
    return 'goal-neutral';
  }

  function goalLabel(status) {
    if (status === 'achieved') return 'Meta atingida';
    if (status === 'on_track') return 'No ritmo';
    if (status === 'attention') return 'Atenção ao ritmo';
    if (status === 'risk') return 'Abaixo do ritmo';
    return 'Sem meta cadastrada';
  }

  function goalColor(status) {
    if (status === 'achieved' || status === 'on_track') return 'var(--tvb-good)';
    if (status === 'attention') return 'var(--tvb-warn)';
    if (status === 'risk') return 'var(--tvb-bad)';
    return 'var(--tvb-accent)';
  }

  function deltaHtml(company) {
    var delta = finite(company.revenue_delta_pct);
    if (delta === null) {
      if (company.comparison_status === 'no_previous_base' && finite(company.revenue) > 0) {
        return '<span class="tvb-delta tvb-delta--new">nova base</span>';
      }
      return '<span class="tvb-delta tvb-delta--neutral">sem comparação</span>';
    }
    var direction = delta > 0.05 ? 'up' : (delta < -0.05 ? 'down' : 'neutral');
    var arrow = direction === 'up' ? '↗' : (direction === 'down' ? '↘' : '→');
    var sign = delta > 0 ? '+' : '';
    return '<span class="tvb-delta tvb-delta--' + direction + '">' +
      arrow + ' ' + sign + esc(pct(delta, 1)) +
      '</span>';
  }

  function tickClock() {
    var now = new Date();
    if (els.clock) {
      els.clock.textContent =
        String(now.getHours()).padStart(2, '0') + ':' +
        String(now.getMinutes()).padStart(2, '0');
    }
    if (els.date) {
      var text = now.toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
      });
      els.date.textContent = text.charAt(0).toUpperCase() + text.slice(1);
    }
  }

  function flash(element) {
    if (!element || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    element.classList.remove('tvb-flash');
    void element.offsetWidth;
    element.classList.add('tvb-flash');
  }

  function setMode(mode, label) {
    state.mode = mode;
    if (els.connection) els.connection.setAttribute('data-state', mode);
    if (els.connectionText) els.connectionText.textContent = label;
  }

  function showError(show, message, actionLabel) {
    if (!els.error) return;
    els.error.hidden = !show;
    if (show && els.errorMsg) els.errorMsg.textContent = message;
    if (els.retry) {
      els.retry.hidden = !show;
      els.retry.textContent = actionLabel || 'Tentar agora';
    }
  }

  function setGauge(element, value, property, label, status) {
    if (!element) return;
    var numeric = finite(value);
    element.style.setProperty(property, numeric === null ? 0 : Math.min(100, Math.max(0, numeric)));
    element.style.setProperty('--gauge-color', goalColor(status));
    if (numeric === null) {
      element.removeAttribute('aria-valuenow');
      element.setAttribute('aria-valuetext', 'Meta não disponível');
    } else {
      element.setAttribute('aria-valuenow', String(Math.round(Math.min(100, Math.max(0, numeric)))));
      element.setAttribute('aria-valuetext', label + ': ' + pct(numeric, 1));
    }
  }

  function renderGroup(group, period, dataStatus) {
    var unavailable = dataStatus === 'unavailable';
    var revenue = unavailable ? null : group.revenue;
    var coverage = group.target_coverage || {};
    var annualCoverage = group.annual_target_coverage || {};
    var partial = coverage.complete === false;
    var annualPartial = annualCoverage.complete === false;
    var attainment = partial ? group.paired_attainment_pct : group.attainment_pct;
    var annualAttainment = annualPartial
      ? group.annual_paired_attainment_pct
      : group.annual_attainment_pct;
    var pace = partial ? group.paired_pace_pct : group.pace_pct;
    var groupStatus = goalClassNameFromPace(group.target, attainment, pace);
    var annualStatus = goalClassNameFromPace(
      group.annual_target,
      annualAttainment,
      annualPartial ? group.annual_paired_pace_pct : group.annual_pace_pct
    );

    if (els.period && period) {
      els.period.textContent = period.exact_label || period.label || '';
    }
    if (els.groupRevenue) {
      els.groupRevenue.textContent = brl(revenue);
      flash(els.groupRevenue);
    }
    if (els.groupTarget) {
      if (unavailable) {
        els.groupTarget.textContent = 'Fonte fiscal indisponível';
      } else if (finite(group.target) > 0) {
        els.groupTarget.textContent = (partial ? 'Meta parcial ' : 'Meta ') + brl(group.target);
      } else {
        els.groupTarget.textContent = 'Sem meta consolidada';
      }
    }
    if (els.groupOrders) {
      els.groupOrders.textContent = unavailable
        ? 'Volume indisponível'
        : plural(group.orders, 'NF', 'NFs') + ' · projeção ' + brl(group.projection);
    }
    if (els.groupChannels) {
      els.groupChannels.innerHTML = unavailable
        ? ''
        : '<span><i class="tvb-channel-dot tvb-channel-dot--retail"></i>Varejo ' +
            esc(brl(group.retail_revenue)) + '</span>' +
          '<span><i class="tvb-channel-dot tvb-channel-dot--bid"></i>Licitação ' +
            esc(brl(group.bidding_revenue)) + '</span>';
    }

    setGauge(els.groupGauge, unavailable ? null : attainment, '--pct', 'Atingimento mensal', groupStatus);
    if (els.groupPct) {
      els.groupPct.textContent = finite(group.target) > 0 && !unavailable ? pct(attainment, 0) : '—';
    }
    setGauge(
      els.groupGaugeAnn,
      unavailable ? null : annualAttainment,
      '--ann-pct',
      'Atingimento acumulado',
      annualStatus
    );
    if (els.groupAnnPct) {
      els.groupAnnPct.textContent =
        finite(group.annual_target) > 0 && !unavailable ? pct(annualAttainment, 0) : '—';
    }

    if (els.groupAnnualRevenue) {
      els.groupAnnualRevenue.textContent = brl(unavailable ? null : group.annual_revenue);
      flash(els.groupAnnualRevenue);
    }
    if (els.groupAnnualTarget) {
      els.groupAnnualTarget.textContent = finite(group.annual_target) > 0
        ? (annualPartial ? 'Meta YTD parcial ' : 'Meta YTD ') + brl(group.annual_target)
        : 'Sem meta YTD consolidada';
    }
    if (els.groupAnnualPctBadge) {
      els.groupAnnualPctBadge.innerHTML = finite(group.annual_target) > 0 && !unavailable
        ? '<div class="tvb-hero__pct-badge ' + goalBadgeClass(annualStatus) + '">' +
            '<span>' + esc(pct(annualAttainment, 1)) +
            (annualPartial ? ' no recorte com meta' : ' da meta YTD') + '</span>' +
          '</div>'
        : '';
    }
    if (els.groupCoverage) {
      if (finite(group.target) <= 0) {
        els.groupCoverage.textContent = 'Nenhuma empresa possui meta no mês';
      } else if (partial) {
        els.groupCoverage.textContent =
          'Meta em ' + number(coverage.companies_with_target) + '/' +
          number(coverage.companies_total) + ' empresas · ' +
          pct(coverage.revenue_pct, 1) + ' da receita coberta';
      } else {
        els.groupCoverage.textContent = 'Cobertura completa de metas';
      }
    }

    if (els.leader) {
      els.leader.textContent = group.leader && group.leader.name ? group.leader.name : 'Sem faturamento';
    }
    if (els.leaderRevenue) {
      els.leaderRevenue.textContent = group.leader
        ? brl(group.leader.revenue) + ' · ' + pct(group.leader.share_pct, 1) + ' do grupo'
        : '—';
    }
    if (els.leaderAtt) {
      if (group.leader && finite(group.leader.pace_pct) !== null) {
        els.leaderAtt.textContent = pct(group.leader.pace_pct, 0) + ' do ritmo esperado';
      } else {
        els.leaderAtt.textContent = 'Ranking por receita MTD';
      }
    }
  }

  function goalClassNameFromPace(target, attainment, pace) {
    if (finite(target) === null || Number(target) <= 0) return 'no_target';
    if ((finite(attainment) || 0) >= 100) return 'achieved';
    if ((finite(pace) || 0) >= 95) return 'on_track';
    if ((finite(pace) || 0) >= 75) return 'attention';
    return 'risk';
  }

  function goalBadgeClass(status) {
    if (status === 'achieved' || status === 'on_track') return 'good';
    if (status === 'attention') return 'warn';
    return status === 'no_target' ? 'neutral' : 'bad';
  }

  function salesBadge(company) {
    if (company.sales_status === 'unavailable') {
      return '<span class="tvb-state-badge tvb-state-badge--bad">Dados indisponíveis</span>';
    }
    if (company.integration_status === 'error') {
      return '<span class="tvb-state-badge tvb-state-badge--warn">Integração com alerta</span>';
    }
    if (company.sales_status === 'no_movement') {
      return '<span class="tvb-state-badge tvb-state-badge--neutral">Sem venda no mês</span>';
    }
    if (company.target_status === 'partial') {
      return '<span class="tvb-state-badge tvb-state-badge--warn">Meta parcial · ' +
        esc(goalLabel(company.goal_status)) + '</span>';
    }
    return '<span class="tvb-state-badge ' + goalBadgeClass(company.goal_status) + '">' +
      esc(goalLabel(company.goal_status)) + '</span>';
  }

  function gapLabel(company) {
    var gap = finite(company.gap);
    if (finite(company.target) === null || Number(company.target) <= 0 || gap === null) return '';
    return gap > 0
      ? 'Faltam ' + brl(gap)
      : brl(Math.abs(gap)) + ' acima da meta';
  }

  function cardHtml(company) {
    var unavailable = company.sales_status === 'unavailable';
    var hasTarget = finite(company.target) !== null && Number(company.target) > 0;
    var targetPartial = company.target_status === 'partial';
    var annualTargetPartial = company.annual_target_status === 'partial';
    var attainment = finite(targetPartial ? company.paired_attainment_pct : company.attainment_pct);
    var annualAttainment = finite(
      annualTargetPartial ? company.annual_paired_attainment_pct : company.annual_attainment_pct
    );
    var barWidth = attainment === null ? 0 : Math.min(100, Math.max(0, attainment));
    var annualBarWidth = annualAttainment === null ? 0 : Math.min(100, Math.max(0, annualAttainment));
    var statusClass = goalClass(company.goal_status);
    var primaryChannel = company.primary_channel === 'licitacao' ? 'Licitação' : 'Varejo';
    var classes = 'tvb-card ' + statusClass +
      (company.rank === 1 && finite(company.revenue) > 0 ? ' tvb-card--leader' : '') +
      (company.sales_status === 'no_movement' ? ' tvb-card--no-movement' : '') +
      (unavailable ? ' tvb-card--unavailable' : '');
    var revenueText = unavailable ? '—' : brl(company.revenue);
    var annualRevenueText = unavailable ? '—' : brl(company.annual_revenue);
    var sourceText = company.last_source_at
      ? 'Fonte ' + shortDateTime(company.last_source_at)
      : 'Frescor da fonte indisponível';

    var goalBlock = hasTarget
      ? '<div class="tvb-card__goal-head">' +
          '<span>' + esc(targetPartial ? 'Meta parcial' : goalLabel(company.goal_status)) + '</span>' +
          '<strong>' + esc(pct(attainment, 1)) + '</strong>' +
        '</div>' +
        '<div class="tvb-bar-row">' +
          '<div class="tvb-bar" role="progressbar" aria-label="Atingimento de ' + esc(company.name) +
            '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' +
            esc(Math.round(Math.min(100, Math.max(0, attainment || 0)))) + '">' +
            '<div class="tvb-bar__fill" style="width:' + barWidth + '%"></div>' +
            '<span class="tvb-bar__target" aria-hidden="true"></span>' +
          '</div>' +
        '</div>' +
        '<div class="tvb-card__goal-meta">' +
          '<span>' + (company.target_grain === 'seller'
            ? 'Meta de ' + esc(number(company.target_sellers_count)) + ' vendedores '
            : 'Meta ') + esc(brl(company.target)) + '</span>' +
          '<span>' + esc(gapLabel(company)) + '</span>' +
        '</div>' +
        '<div class="tvb-card__pace">' +
          (targetPartial ? 'Cobertura ' + esc(pct(company.target_coverage_pct, 1)) + ' · ' : '') +
          'ritmo ' + esc(pct(company.pace_pct, 0)) +
          ' · projeção ' + esc(brl(company.projection)) + '</div>'
      : '<div class="tvb-card__no-target">' +
          '<strong>Sem meta cadastrada</strong>' +
          '<span>Receita preservada, sem avaliação vermelha.</span>' +
        '</div>';

    var annualGoal = finite(company.annual_target) > 0
      ? '<span>' +
          (annualTargetPartial
            ? 'Meta parcial ' + esc(brl(company.annual_target)) + ' · ' +
              esc(number(company.annual_target_months_configured)) + '/' +
              esc(number(company.annual_target_months_expected)) + ' meses'
            : 'Meta ' + esc(brl(company.annual_target))) +
          ' · ' + esc(pct(annualAttainment, 1)) + '</span>'
      : '<span>Sem meta YTD</span>';

    return '<article class="' + classes + '" style="--card-color:' + esc(company.color || '#3b82f6') + '">' +
      '<div class="tvb-card__inner">' +
        '<div class="tvb-card__head">' +
          '<div class="tvb-card__badge">' +
            '<span class="tvb-card__badge-initials">' + esc(company.initials || '') + '</span>' +
            (company.logo
              ? '<img class="tvb-card__badge-logo" src="' + esc(company.logo) +
                  '" alt="" loading="lazy" onerror="this.remove()">'
              : '') +
          '</div>' +
          '<div class="tvb-card__identity">' +
            '<div class="tvb-card__name">' + esc(company.name) + '</div>' +
            '<div class="tvb-card__scope">' + esc(primaryChannel) + '</div>' +
          '</div>' +
          '<div class="tvb-card__rank">#' + esc(company.rank) + ' receita</div>' +
        '</div>' +

        '<div class="tvb-card__status-row">' +
          salesBadge(company) +
          deltaHtml(company) +
        '</div>' +

        '<div class="tvb-card__section">' +
          '<div class="tvb-card__section-label">Faturamento MTD</div>' +
          '<div class="tvb-card__revenue">' + revenueText + '</div>' +
          '<div class="tvb-card__comparison">vs. ' + esc(company.comparison_status === 'comparable'
            ? 'mesmo período anterior'
            : 'período anterior sem base') + '</div>' +
        '</div>' +

        '<div class="tvb-card__stats">' +
          '<div><strong>' + esc(unavailable ? '—' : number(company.orders)) + '</strong><span>NFs</span></div>' +
          '<div><strong>' + esc(unavailable ? '—' : brl(company.ticket_avg)) + '</strong><span>ticket</span></div>' +
          '<div><strong>' + esc(unavailable ? '—' : number(company.customers)) + '</strong><span>clientes</span></div>' +
        '</div>' +

        '<div class="tvb-card__goal ' + statusClass + '">' + goalBlock + '</div>' +

        '<div class="tvb-card__annual">' +
          '<div class="tvb-card__annual-head">' +
            '<div>' +
              '<div class="tvb-card__section-label">Acumulado YTD</div>' +
              '<div class="tvb-card__revenue--sm">' + annualRevenueText + '</div>' +
            '</div>' +
            '<div class="tvb-card__annual-meta">' + annualGoal + '</div>' +
          '</div>' +
          (finite(company.annual_target) > 0
            ? '<div class="tvb-bar tvb-bar--annual" role="progressbar" aria-label="Atingimento YTD de ' +
                esc(company.name) + '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' +
                esc(Math.round(Math.min(100, Math.max(0, annualAttainment || 0)))) + '">' +
                '<div class="tvb-bar__fill" style="width:' + annualBarWidth + '%"></div>' +
                '<span class="tvb-bar__target" aria-hidden="true"></span>' +
              '</div>'
            : '') +
        '</div>' +

        '<div class="tvb-card__source">' +
          '<span class="tvb-card__source-dot" data-state="' + esc(company.integration_status) + '"></span>' +
          esc(sourceText) +
        '</div>' +
      '</div>' +
    '</article>';
  }

  function renderCompanies(companies, group, sourceAt) {
    if (!els.grid) return;
    if (!Array.isArray(companies) || companies.length === 0) {
      els.grid.innerHTML =
        '<div class="tvb-empty"><strong>Nenhuma empresa acessível</strong>' +
        '<span>Revise o vínculo de empresas deste usuário.</span></div>';
      if (els.companySummary) els.companySummary.textContent = '0 empresas no escopo';
      return;
    }

    els.grid.innerHTML = companies.map(cardHtml).join('');
    if (els.companySummary) {
      var summary = number(group.companies_with_sales) + '/' +
        number(group.companies_count) + ' com faturamento no mês';
      if (sourceAt) summary += ' · dados até ' + shortDateTime(sourceAt);
      els.companySummary.textContent = summary;
    }
  }

  function render(data) {
    var group = data.group || {};
    var period = data.period || {};
    renderGroup(group, period, data.data_status);
    renderCompanies(data.companies || [], group, data.source_refreshed_at);
  }

  function responsePayload(response) {
    return response.text().then(function (text) {
      if (!text) return null;
      try {
        return JSON.parse(text);
      } catch (error) {
        return null;
      }
    });
  }

  function requestError(kind, message, status, redirect) {
    var error = new Error(message);
    error.kind = kind;
    error.status = status || 0;
    error.redirect = redirect || '';
    return error;
  }

  function schedule(seconds) {
    if (state.timer) clearTimeout(state.timer);
    var delay = Math.max(1, seconds);
    state.nextLoadAt = new Date(Date.now() + delay * 1000);
    state.timer = setTimeout(load, delay * 1000);
  }

  function handleFailure(error) {
    if (error && error.kind === 'context') {
      state.retryRedirect = error.redirect || 'select_empresa.php?context_reset=1';
      setMode('context', 'Sessão expirada');
      state.statusMessage = 'O contexto desta tela expirou.';
      showError(
        true,
        'A sessão ou a empresa selecionada expirou. Reconecte o painel.',
        'Reconectar'
      );
      return;
    }

    var offline = !navigator.onLine || (error && error.kind === 'network');
    var forbidden = error && error.kind === 'forbidden';
    var label = offline ? 'Sem conexão' : (forbidden ? 'Acesso negado' : 'Atualização falhou');
    var message = offline
      ? 'Sem conexão com o servidor. Os últimos dados continuam na tela.'
      : (forbidden
          ? 'Este usuário não possui acesso ao Painel da Diretoria.'
          : 'Não foi possível atualizar os dados. O último resultado foi preservado.');
    setMode(offline ? 'offline' : 'error', label);
    state.statusMessage = error && error.message ? error.message : message;
    showError(true, message, 'Tentar agora');

    var retryIndex = Math.min(state.retryAttempt, RETRY_DELAYS.length - 1);
    var retryIn = forbidden ? 120 : RETRY_DELAYS[retryIndex];
    state.retryAttempt++;
    schedule(retryIn);
  }

  function load() {
    if (state.inFlight) return;
    if (!CONTEXT_ID) {
      handleFailure(requestError('context', 'Contexto ausente.', 403, 'select_empresa.php?context_reset=1'));
      return;
    }

    state.inFlight = true;
    state.nextLoadAt = null;
    setMode(state.hasSnapshot ? 'updating' : 'loading', state.hasSnapshot ? 'Atualizando' : 'Carregando');
    if (!state.hasSnapshot) showError(false);

    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    state.controller = controller;
    state.timeout = setTimeout(function () {
      if (controller) controller.abort();
    }, 15000);

    var options = {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'X-BIOMIE-Context': CONTEXT_ID,
      },
    };
    if (controller) options.signal = controller.signal;

    fetch(API_URL, options)
      .then(function (response) {
        return responsePayload(response).then(function (payload) {
          var redirect = response.headers.get('X-BIOMIE-Context-Redirect') ||
            (payload && payload.redirect) || '';
          var contextInvalid = response.headers.get('X-BIOMIE-Context-Invalid') === '1';
          if (contextInvalid || response.status === 401 || response.status === 409) {
            throw requestError(
              'context',
              payload && payload.message ? payload.message : 'Sessão ou contexto inválido.',
              response.status,
              redirect
            );
          }
          if (response.status === 403) {
            throw requestError(
              'forbidden',
              payload && payload.message ? payload.message : 'Acesso negado.',
              403
            );
          }
          if (!response.ok) {
            throw requestError(
              'server',
              payload && payload.message ? payload.message : 'HTTP ' + response.status,
              response.status
            );
          }
          if (!payload || payload.ok !== true) {
            throw requestError(
              'data',
              payload && payload.message ? payload.message : 'Resposta de dados inválida.',
              response.status
            );
          }
          return payload;
        });
      })
      .then(function (data) {
        render(data);
        state.generatedAt = parseDate(data.generated_at) || new Date();
        state.sourceAt = parseDate(data.source_refreshed_at);
        state.hasSnapshot = true;
        state.retryAttempt = 0;
        state.statusMessage = '';
        state.retryRedirect = '';

        var warnings = Array.isArray(data.warnings) ? data.warnings : [];
        if (data.data_status === 'unavailable') {
          setMode('error', 'Fonte indisponível');
          showError(
            true,
            'A fonte fiscal está indisponível; valores não foram convertidos em zero.',
            'Tentar agora'
          );
          schedule(RETRY_DELAYS[0]);
        } else if (warnings.length > 0) {
          setMode('warning', 'Dados parciais');
          showError(true, warnings[0].message || 'Parte dos dados está indisponível.', 'Atualizar');
          schedule(REFRESH_SEC);
        } else {
          setMode('live', 'Ao vivo');
          showError(false);
          schedule(REFRESH_SEC);
        }
        updateFreshness();
      })
      .catch(function (error) {
        var normalized = error;
        if (error && (error.name === 'AbortError' || error instanceof TypeError)) {
          normalized = requestError(
            'network',
            error.name === 'AbortError' ? 'Tempo limite excedido.' : 'Falha de rede.'
          );
        }
        handleFailure(normalized);
      })
      .finally(function () {
        clearTimeout(state.timeout);
        state.timeout = null;
        state.controller = null;
        state.inFlight = false;
      });
  }

  function updateFreshness() {
    if (!els.status) return;

    if (!state.hasSnapshot) {
      var initial = state.statusMessage || (state.inFlight ? 'Consultando dados fiscais…' : 'Aguardando atualização…');
      if (state.nextLoadAt) {
        initial += ' · nova tentativa em ' +
          humanSeconds((state.nextLoadAt.getTime() - Date.now()) / 1000);
      }
      els.status.textContent = initial;
      return;
    }

    var status = 'Painel gerado ' + humanAge(state.generatedAt);
    if (state.sourceAt) status += ' · fonte ' + humanAge(state.sourceAt);
    if (state.mode === 'offline' || state.mode === 'error' || state.mode === 'warning') {
      status += ' · último resultado preservado';
    }
    if (state.nextLoadAt) {
      status += ' · ' +
        (state.mode === 'offline' || state.mode === 'error'
          ? 'nova tentativa em '
          : 'próxima em ') +
        humanSeconds((state.nextLoadAt.getTime() - Date.now()) / 1000);
    }
    els.status.textContent = status;

    if (
      state.generatedAt &&
      Date.now() - state.generatedAt.getTime() > REFRESH_SEC * 2 * 1000 &&
      (state.mode === 'live' || state.mode === 'updating')
    ) {
      setMode('stale', 'Dados atrasados');
    }
  }

  function toggleFullscreen() {
    var doc = document;
    var operation;
    var result;
    if (!doc.fullscreenElement) {
      operation = (doc.documentElement.requestFullscreen || doc.documentElement.webkitRequestFullscreen);
      if (operation) result = operation.call(doc.documentElement);
    } else {
      operation = doc.exitFullscreen || doc.webkitExitFullscreen;
      if (operation) result = operation.call(doc);
    }
    if (result && typeof result.catch === 'function') result.catch(function () {});
  }

  function updateFullscreenButton() {
    if (!els.fullscreen) return;
    var active = Boolean(document.fullscreenElement);
    els.fullscreen.setAttribute('aria-label', active ? 'Sair da tela cheia' : 'Entrar em tela cheia');
    els.fullscreen.setAttribute('title', active ? 'Sair da tela cheia' : 'Tela cheia');
    var icon = els.fullscreen.querySelector('.material-symbols-rounded');
    if (icon) icon.textContent = active ? 'fullscreen_exit' : 'fullscreen';
  }

  if (els.fullscreen) els.fullscreen.addEventListener('click', toggleFullscreen);
  document.addEventListener('fullscreenchange', updateFullscreenButton);
  if (els.retry) {
    els.retry.addEventListener('click', function () {
      if (state.retryRedirect) {
        window.location.href = state.retryRedirect;
        return;
      }
      if (state.timer) clearTimeout(state.timer);
      state.retryAttempt = 0;
      load();
    });
  }

  window.addEventListener('online', function () {
    if (state.timer) clearTimeout(state.timer);
    state.retryAttempt = 0;
    load();
  });
  window.addEventListener('offline', function () {
    setMode('offline', 'Sem conexão');
    showError(true, 'Sem conexão. Os últimos dados continuam na tela.', 'Tentar agora');
    updateFreshness();
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') load();
  });

  tickClock();
  updateFullscreenButton();
  setInterval(tickClock, 1000);
  setInterval(updateFreshness, 1000);
  load();
}());
