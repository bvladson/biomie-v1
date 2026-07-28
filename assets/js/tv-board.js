/*
 * BIomie — Painel da Diretoria (modo TV) v8
 * Hidratação inicial, contexto explícito e atualização resiliente.
 */
(function () {
  'use strict';

  var body = document.body;
  var API_URL = body.getAttribute('data-api') || 'api/board_consolidated.php';
  var CONTEXT_ID = body.getAttribute('data-context') || '';
  var REFRESH_SEC = Math.max(20, parseInt(body.getAttribute('data-refresh') || '300', 10));
  var RETRY_DELAYS = [5, 15, 60, 300, 900];
  var SOURCE_RETRY_DELAYS = [15, 60, 300, 900];

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
    initialData: document.getElementById('tvbInitialData'),
  };

  var state = {
    mode: 'loading',
    inFlight: false,
    controller: null,
    timer: null,
    timeout: null,
    heartbeat: null,
    retryAttempt: 0,
    sourceRetryAttempt: 0,
    nextLoadAt: null,
    generatedAt: null,
    sourceAt: null,
    lastCheckedAt: null,
    hasSnapshot: false,
    statusMessage: '',
    retryRedirect: '',
    etag: '',
    renderedEtag: '',
    gridMarkup: '',
    lastDataStatus: '',
    lastWarnings: [],
  };

  var formatters = {
    number: new Intl.NumberFormat('pt-BR'),
    integer: new Intl.NumberFormat('pt-BR', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }),
    oneDecimal: new Intl.NumberFormat('pt-BR', {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    }),
    flexibleDecimal: new Intl.NumberFormat('pt-BR', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 1,
    }),
    dateTime: new Intl.DateTimeFormat('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    }),
    longDate: new Intl.DateTimeFormat('pt-BR', {
      weekday: 'long',
      day: '2-digit',
      month: 'long',
    }),
  };

  function setText(element, value) {
    if (!element) return;
    var text = String(value == null ? '' : value);
    if (element.textContent !== text) element.textContent = text;
  }

  function setHtml(element, value) {
    if (!element || element.innerHTML === value) return;
    element.innerHTML = value;
  }

  function setAttribute(element, name, value) {
    if (!element || element.getAttribute(name) === value) return;
    element.setAttribute(name, value);
  }

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
      return 'R$\u00a0' +
        (absolute < 10000 ? formatters.oneDecimal : formatters.flexibleDecimal)
          .format(parsed / 1000) +
        '\u00a0mil';
    }
    return 'R$\u00a0' + formatters.integer.format(parsed);
  }

  function number(value) {
    return formatters.number.format(finite(value) || 0);
  }

  function pct(value, digits) {
    var parsed = finite(value);
    return parsed === null
      ? '—'
      : (digits === 1 ? formatters.oneDecimal : formatters.integer).format(parsed) + '%';
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
    return formatters.dateTime.format(date);
  }

  function humanAge(date) {
    if (!date) return 'agora';
    var seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'há menos de 1 min';
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
      setText(
        els.clock,
        String(now.getHours()).padStart(2, '0') + ':' +
        String(now.getMinutes()).padStart(2, '0')
      );
    }
    if (els.date) {
      var text = formatters.longDate.format(now);
      setText(els.date, text.charAt(0).toUpperCase() + text.slice(1));
    }
  }

  function setMode(mode, label) {
    state.mode = mode;
    if (els.connection && els.connection.getAttribute('data-state') !== mode) {
      els.connection.setAttribute('data-state', mode);
    }
    setText(els.connectionText, label);
  }

  function showError(show, message, actionLabel) {
    if (!els.error) return;
    if (els.error.hidden !== !show) els.error.hidden = !show;
    if (show) setText(els.errorMsg, message);
    if (els.retry) {
      if (els.retry.hidden !== !show) els.retry.hidden = !show;
      setText(els.retry, actionLabel || 'Tentar agora');
    }
  }

  function setGauge(element, value, property, label, status) {
    if (!element) return;
    var numeric = finite(value);
    var clamped = numeric === null ? 0 : Math.min(100, Math.max(0, numeric));
    var color = goalColor(status);
    if (element.style.getPropertyValue(property) !== String(clamped)) {
      element.style.setProperty(property, clamped);
    }
    if (element.style.getPropertyValue('--gauge-color') !== color) {
      element.style.setProperty('--gauge-color', color);
    }
    if (numeric === null) {
      element.removeAttribute('aria-valuenow');
      setAttribute(element, 'aria-valuetext', 'Meta não disponível');
    } else {
      setAttribute(element, 'aria-valuenow', String(Math.round(clamped)));
      setAttribute(element, 'aria-valuetext', label + ': ' + pct(numeric, 1));
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
      setText(els.period, period.exact_label || period.label || '');
    }
    if (els.groupRevenue) {
      setText(els.groupRevenue, brl(revenue));
    }
    if (els.groupTarget) {
      if (unavailable) {
        setText(els.groupTarget, 'Fonte fiscal indisponível');
      } else if (finite(group.target) > 0) {
        setText(els.groupTarget, (partial ? 'Meta parcial ' : 'Meta ') + brl(group.target));
      } else {
        setText(els.groupTarget, 'Sem meta consolidada');
      }
    }
    if (els.groupOrders) {
      setText(els.groupOrders, unavailable
        ? 'Volume indisponível'
        : plural(group.orders, 'NF', 'NFs') + ' · projeção ' + brl(group.projection));
    }
    if (els.groupChannels) {
      setHtml(els.groupChannels, unavailable
        ? ''
        : '<span><i class="tvb-channel-dot tvb-channel-dot--retail"></i>Varejo ' +
            esc(brl(group.retail_revenue)) + '</span>' +
          '<span><i class="tvb-channel-dot tvb-channel-dot--bid"></i>Licitação ' +
            esc(brl(group.bidding_revenue)) + '</span>');
    }

    setGauge(els.groupGauge, unavailable ? null : attainment, '--pct', 'Atingimento mensal', groupStatus);
    if (els.groupPct) {
      setText(els.groupPct, finite(group.target) > 0 && !unavailable ? pct(attainment, 0) : '—');
    }
    setGauge(
      els.groupGaugeAnn,
      unavailable ? null : annualAttainment,
      '--ann-pct',
      'Atingimento acumulado',
      annualStatus
    );
    if (els.groupAnnPct) {
      setText(
        els.groupAnnPct,
        finite(group.annual_target) > 0 && !unavailable ? pct(annualAttainment, 0) : '—'
      );
    }

    if (els.groupAnnualRevenue) {
      setText(els.groupAnnualRevenue, brl(unavailable ? null : group.annual_revenue));
    }
    if (els.groupAnnualTarget) {
      setText(els.groupAnnualTarget, finite(group.annual_target) > 0
        ? (annualPartial ? 'Meta YTD parcial ' : 'Meta YTD ') + brl(group.annual_target)
        : 'Sem meta YTD consolidada');
    }
    if (els.groupAnnualPctBadge) {
      setHtml(els.groupAnnualPctBadge, finite(group.annual_target) > 0 && !unavailable
        ? '<div class="tvb-hero__pct-badge ' + goalBadgeClass(annualStatus) + '">' +
            '<span>' + esc(pct(annualAttainment, 1)) +
            (annualPartial ? ' no recorte com meta' : ' da meta YTD') + '</span>' +
          '</div>'
        : '');
    }
    if (els.groupCoverage) {
      if (finite(group.target) <= 0) {
        setText(els.groupCoverage, 'Nenhuma empresa possui meta no mês');
      } else if (partial) {
        setText(els.groupCoverage,
          'Meta em ' + number(coverage.companies_with_target) + '/' +
          number(coverage.companies_total) + ' empresas · ' +
          pct(coverage.revenue_pct, 1) + ' da receita coberta');
      } else {
        setText(els.groupCoverage, 'Cobertura completa de metas');
      }
    }

    if (els.leader) {
      setText(els.leader, group.leader && group.leader.name ? group.leader.name : 'Sem faturamento');
    }
    if (els.leaderRevenue) {
      setText(els.leaderRevenue, group.leader
        ? brl(group.leader.revenue) + ' · ' + pct(group.leader.share_pct, 1) + ' do grupo'
        : '—');
    }
    if (els.leaderAtt) {
      if (group.leader && finite(group.leader.pace_pct) !== null) {
        setText(els.leaderAtt, pct(group.leader.pace_pct, 0) + ' do ritmo esperado');
      } else {
        setText(els.leaderAtt, 'Ranking por receita MTD');
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
                  '" alt="" width="42" height="42" loading="lazy" decoding="async" onerror="this.remove()">'
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
      var emptyMarkup =
        '<div class="tvb-empty"><strong>Nenhuma empresa acessível</strong>' +
        '<span>Revise o vínculo de empresas deste usuário.</span></div>';
      if (state.gridMarkup !== emptyMarkup) {
        els.grid.innerHTML = emptyMarkup;
        state.gridMarkup = emptyMarkup;
      }
      setText(els.companySummary, '0 empresas no escopo');
      return;
    }

    var markup = companies.map(cardHtml).join('');
    if (state.gridMarkup !== markup) {
      els.grid.innerHTML = markup;
      state.gridMarkup = markup;
    }
    if (els.companySummary) {
      var summary = number(group.companies_with_sales) + '/' +
        number(group.companies_count) + ' com faturamento no mês';
      if (sourceAt) summary += ' · dados até ' + shortDateTime(sourceAt);
      setText(els.companySummary, summary);
    }
  }

  function render(data) {
    var group = data.group || {};
    var period = data.period || {};
    renderGroup(group, period, data.data_status);
    renderCompanies(data.companies || [], group, data.source_refreshed_at);
  }

  function hydrateInitialSnapshot() {
    var node = els.initialData;
    if (!node) return false;

    var etag = node.getAttribute('data-etag') || '';
    var data = null;
    try {
      data = JSON.parse(node.textContent || '');
    } catch (error) {
      data = null;
    }
    if (typeof node.remove === 'function') {
      node.remove();
    } else if (node.parentNode) {
      node.parentNode.removeChild(node);
    }
    els.initialData = null;

    if (
      !data ||
      data.ok !== true ||
      !data.meta ||
      String(data.meta.context_id || '') !== CONTEXT_ID
    ) {
      return false;
    }

    render(data);
    state.generatedAt = parseDate(data.generated_at) || new Date();
    state.sourceAt = parseDate(data.source_refreshed_at);
    state.lastCheckedAt = state.generatedAt;
    state.hasSnapshot = true;
    state.retryAttempt = 0;
    state.statusMessage = '';
    if (etag) {
      state.etag = etag;
      state.renderedEtag = etag;
    } else {
      state.renderedEtag = String(data.generated_at || Date.now());
    }

    applySnapshotState(
      data.data_status,
      Array.isArray(data.warnings) ? data.warnings : []
    );
    updateFreshness();
    return true;
  }

  function responsePayload(response) {
    return response.json().catch(function () {
      return null;
    });
  }

  function requestError(kind, message, status, redirect) {
    var error = new Error(message);
    error.kind = kind;
    error.status = status || 0;
    error.redirect = redirect || '';
    return error;
  }

  function schedule(seconds, withJitter) {
    if (state.timer) clearTimeout(state.timer);
    state.timer = null;
    if (document.hidden || !navigator.onLine) {
      state.nextLoadAt = null;
      return;
    }
    var jitter = withJitter && seconds >= 60 ? 0.9 + Math.random() * 0.2 : 1;
    var delay = Math.max(1, seconds * jitter);
    state.nextLoadAt = new Date(Date.now() + delay * 1000);
    state.timer = setTimeout(load, delay * 1000);
  }

  function applySnapshotState(dataStatus, warnings) {
    warnings = Array.isArray(warnings) ? warnings : [];
    state.lastDataStatus = dataStatus || '';
    state.lastWarnings = warnings;

    if (dataStatus === 'unavailable') {
      var retryIndex = Math.min(state.sourceRetryAttempt, SOURCE_RETRY_DELAYS.length - 1);
      var retryIn = SOURCE_RETRY_DELAYS[retryIndex];
      state.sourceRetryAttempt++;
      setMode('error', 'Fonte indisponível');
      showError(
        true,
        'A fonte fiscal está indisponível; o último resultado válido continua na tela.',
        'Tentar agora'
      );
      schedule(retryIn, retryIn >= 60);
      return;
    }

    state.sourceRetryAttempt = 0;
    if (warnings.length > 0) {
      setMode('warning', 'Dados parciais');
      showError(true, warnings[0].message || 'Parte dos dados está indisponível.', 'Atualizar');
    } else {
      setMode('live', 'Ao vivo');
      showError(false);
    }
    schedule(REFRESH_SEC, true);
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

    if (forbidden || !navigator.onLine || document.hidden) {
      state.nextLoadAt = null;
      return;
    }
    var retryIndex = Math.min(state.retryAttempt, RETRY_DELAYS.length - 1);
    var retryIn = RETRY_DELAYS[retryIndex];
    state.retryAttempt++;
    schedule(retryIn, retryIn >= 60);
  }

  function load() {
    if (state.inFlight) return;
    if (document.hidden) return;
    if (!navigator.onLine) {
      handleFailure(requestError('network', 'Sem conexão.'));
      return;
    }
    if (!CONTEXT_ID) {
      handleFailure(requestError('context', 'Contexto ausente.', 403, 'select_empresa.php?context_reset=1'));
      return;
    }

    if (state.timer) clearTimeout(state.timer);
    state.timer = null;
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
      cache: 'no-cache',
      headers: {
        'Accept': 'application/json',
        'X-BIOMIE-Context': CONTEXT_ID,
      },
    };
    if (state.etag) options.headers['If-None-Match'] = state.etag;
    if (controller) options.signal = controller.signal;

    fetch(API_URL, options)
      .then(function (response) {
        var responseEtag = response.headers.get('ETag') || '';
        if (response.status === 304 && state.hasSnapshot) {
          return {
            notModified: true,
            etag: responseEtag || state.etag,
          };
        }
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
          return {
            notModified: false,
            etag: responseEtag,
            data: payload,
          };
        });
      })
      .then(function (result) {
        state.lastCheckedAt = new Date();
        state.retryAttempt = 0;
        state.statusMessage = '';
        state.retryRedirect = '';
        if (result.etag) state.etag = result.etag;

        if (result.notModified) {
          applySnapshotState(state.lastDataStatus, state.lastWarnings);
          updateFreshness();
          return;
        }

        var data = result.data;
        if (!result.etag || result.etag !== state.renderedEtag) {
          render(data);
          state.renderedEtag = result.etag || String(data.generated_at || Date.now());
        }
        state.generatedAt = parseDate(data.generated_at) || new Date();
        state.sourceAt = parseDate(data.source_refreshed_at);
        state.hasSnapshot = true;

        var warnings = Array.isArray(data.warnings) ? data.warnings : [];
        applySnapshotState(data.data_status, warnings);
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
      setText(els.status, initial);
      return;
    }

    var status = 'Painel verificado ' + humanAge(state.lastCheckedAt || state.generatedAt);
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
    setText(els.status, status);

    if (
      state.lastCheckedAt &&
      Date.now() - state.lastCheckedAt.getTime() > REFRESH_SEC * 2 * 1000 &&
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
    els.fullscreen.setAttribute('aria-pressed', active ? 'true' : 'false');
    els.fullscreen.setAttribute('data-active', active ? 'true' : 'false');
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
      state.sourceRetryAttempt = 0;
      load();
    });
  }

  window.addEventListener('online', function () {
    if (state.timer) clearTimeout(state.timer);
    state.timer = null;
    state.retryAttempt = 0;
    load();
  });
  window.addEventListener('offline', function () {
    if (state.timer) clearTimeout(state.timer);
    state.timer = null;
    state.nextLoadAt = null;
    setMode('offline', 'Sem conexão');
    showError(true, 'Sem conexão. Os últimos dados continuam na tela.', 'Tentar agora');
    updateFreshness();
  });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (state.timer) clearTimeout(state.timer);
      state.timer = null;
      state.nextLoadAt = null;
      if (state.heartbeat) clearTimeout(state.heartbeat);
      state.heartbeat = null;
      return;
    }
    heartbeat();
    var lastCheckAge = state.lastCheckedAt
      ? (Date.now() - state.lastCheckedAt.getTime()) / 1000
      : REFRESH_SEC;
    if (lastCheckAge >= Math.min(60, REFRESH_SEC)) {
      load();
    } else {
      schedule(REFRESH_SEC - lastCheckAge, true);
    }
  });

  function heartbeat() {
    if (state.heartbeat) clearTimeout(state.heartbeat);
    state.heartbeat = null;
    if (document.hidden) return;

    tickClock();
    updateFreshness();
    var retrySeconds = state.nextLoadAt
      ? (state.nextLoadAt.getTime() - Date.now()) / 1000
      : Infinity;
    var delay = retrySeconds <= 60 ? 1000 : 30000;
    state.heartbeat = setTimeout(heartbeat, delay);
  }

  var hydrated = hydrateInitialSnapshot();
  updateFullscreenButton();
  if (hydrated && !navigator.onLine) {
    setMode('offline', 'Sem conexão');
    showError(true, 'Sem conexão com o servidor. Os últimos dados continuam na tela.', 'Tentar agora');
  } else if (!hydrated && navigator.onLine && !document.hidden) {
    load();
  } else if (!hydrated && !navigator.onLine) {
    setMode('offline', 'Sem conexão');
    showError(true, 'Sem conexão. O painel atualizará quando a rede voltar.', 'Tentar agora');
  }
  heartbeat();
}());
