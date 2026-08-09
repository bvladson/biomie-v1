(function (B) {
  'use strict';

  // ── Sync / meta badges ────────────────────────────────────────────────────

  B.renderSyncStateBadges = function (data) {
    var meta           = (data && data.meta) || {};
    var badges         = [];
    var servedFrom     = B.normalizeServedFrom(meta.served_from || (data && data.source) || '');
    var signalStatus   = String(meta.signal_status || '').toLowerCase();
    var openDivergences = Number(meta.open_divergences || 0);

    if (servedFrom === 'canonical-db')          badges.push('<span class="mag-status-chip mag-status-live">Leitura principal</span>');
    else if (servedFrom === 'historical-read-model') badges.push('<span class="mag-status-chip mag-status-warning">Snapshot historico</span>');
    else if (servedFrom === 'legacy-fallback')   badges.push('<span class="mag-status-chip mag-status-warning">Fallback legado</span>');
    else if (servedFrom === 'legacy')            badges.push('<span class="mag-status-chip mag-status-demo">Leitura legado</span>');

    if (signalStatus === 'partial_window')       badges.push('<span class="mag-status-chip mag-status-warning">Cobertura parcial</span>');
    else if (signalStatus === 'sync_missing')    badges.push('<span class="mag-status-chip mag-status-warning">Sync ausente</span>');
    else if (signalStatus === 'empty_after_sync') badges.push('<span class="mag-status-chip mag-status-demo">Periodo vazio</span>');

    if (meta.has_retry_or_delay) badges.push('<span class="mag-status-chip mag-status-warning">Pipeline com atraso</span>');
    if (openDivergences > 0)     badges.push('<span class="mag-status-chip mag-status-warning">' + B.formatNumber(openDivergences) + ' divergencia(s)</span>');

    return badges.join(' ');
  };

  B.updateGlobalMeta = function (data) {
    B.setText('[data-bind="period-label"]', (data && data.period && data.period.label) || '-');
    var syncTime = data && data.meta && (data.meta.freshness_at || data.meta.last_sync || data.meta.generated_at);
    B.setText('[data-bind="last-sync"]', syncTime ? new Date(syncTime).toLocaleString('pt-BR') : '-');
    var _meta = (data && data.meta) || {};
    var _rpb  = _meta.realized_period_basis || _meta.period_basis || '';
    var _rpbLabel = _rpb === 'data_competencia' ? 'Realizado: NF autorizada (data_competencia)'
                  : _rpb === 'issue_date'       ? 'Realizado: emissao de pedido'
                  : _rpb !== ''                 ? 'Base: ' + _rpb
                  : '-';
    B.setText('[data-bind="summary-period-basis"]', _rpbLabel);
    B.setHtml('[data-bind="summary-mode"]', B.renderStatusBadge((data && data.mode) || 'demo'));
    B.setText('[data-bind="sync-source"]', B.humanizeServedFrom((data && data.meta && data.meta.served_from) || (data && data.source) || ''));
    B.setText('[data-bind="sync-coverage"]', B.resolveCoverageLabel(data));
    B.setText('[data-bind="sync-reconciled-until"]', B.formatMetaDate((data && data.meta && data.meta.reconciled_until) || (data && data.reconciled_until) || ''));
    B.setHtml('[data-bind="sync-warning"]', B.renderSyncStateBadges(data) || '<span class="mag-status-chip mag-status-live">Leitura principal</span>');

    var servedFrom = String((data && data.meta && data.meta.served_from) || '');
    var liveDot    = document.querySelector('.live-dot');
    if (liveDot) {
      liveDot.classList.remove('stale', 'error');
      if (servedFrom.includes('legacy') || String((data && data.meta && data.meta.signal_status) || '').includes('partial')) {
        liveDot.classList.add('stale');
        liveDot.title = 'Cobertura parcial ou fallback';
      } else if (servedFrom.includes('error')) {
        liveDot.classList.add('error');
        liveDot.title = 'Usando ultimo snapshot valido';
      } else {
        liveDot.title = 'Dados atualizados';
      }
    }
  };

  // ── Summary cards ─────────────────────────────────────────────────────────

  B.renderSummaryCards = function (summary, mode) {
    summary = summary || {};
    var target      = Number(summary.target || 0);
    var billed      = Number(summary.gross_revenue != null ? summary.gross_revenue : (summary.revenue || 0));
    var pending     = Number(summary.pending_value || 0);
    var coveragePct = target > 0 ? (((billed + pending) / target) * 100) : 0;

    B.setText('[data-bind="summary-revenue"]',           B.formatCurrency(summary.gross_revenue != null ? summary.gross_revenue : summary.revenue));
    B.setText('[data-bind="summary-merchandise"]',       B.formatCurrency(summary.merchandise_total));
    B.setText('[data-bind="summary-discounts"]',         B.formatCurrency(summary.discounts_total));
    B.setText('[data-bind="summary-orders"]',            B.formatNumber(summary.orders));
    B.setText('[data-bind="summary-ticket"]',            B.formatCurrency(summary.avg_ticket));
    B.setText('[data-bind="summary-vendors"]',           B.formatNumber(summary.active_vendors));
    B.setText('[data-bind="summary-clients"]',           B.formatNumber(summary.active_clients));
    B.setText('[data-bind="summary-top5-clients"]',      Number(summary.top5_client_concentration_pct || 0).toFixed(1) + '%');
    B.setText('[data-bind="summary-pending-orders"]',    B.formatNumber(summary.pending_orders));
    B.setText('[data-bind="summary-pending-value"]',     B.formatCurrency(summary.pending_value));
    // COM.R2.2 — Carteira aceita e Orçamentos (mapeados de pending_value e wallet_value)
    B.setText('[data-bind="summary-wallet"]',  B.formatCurrency(summary.wallet_value != null ? summary.wallet_value : summary.pending_value));
    B.setText('[data-bind="summary-quotes"]',  B.formatCurrency(summary.quotes_value != null ? summary.quotes_value : 0));
    B.setText('[data-bind="summary-cancelled-orders"]',  B.formatNumber(summary.cancelled_orders));
    B.setText('[data-bind="summary-cancelled-value"]',   B.formatCurrency(summary.cancelled_value));
    B.setText('[data-bind="summary-target"]',            B.formatCurrency(summary.target));
    B.setText('[data-bind="summary-forecast"]',          B.formatCurrency(summary.forecast));
    B.setText('[data-bind="summary-coverage"]',          Number(coveragePct || 0).toFixed(1) + '%');
    B.setText('[data-bind="summary-progress"]',          Number(summary.goal_progress || 0).toFixed(1) + '%');
    B.setHtml('[data-bind="summary-mode"]',              B.renderStatusBadge(mode));
    document.querySelectorAll('[data-bind="summary-progress-bar"]').forEach(function (bar) {
      var progress = Math.max(0, Math.min(100, Number(summary.goal_progress || 0)));
      bar.style.width = progress + '%';
      bar.setAttribute('aria-valuenow', String(progress));
    });
    document.querySelectorAll('[data-bind="summary-target-label"]').forEach(function (el) {
      el.textContent = 'Meta do periodo:';
    });
  };

  B.summaryHasSignal = function (summary) {
    if (!summary) return false;
    return [
      Number(summary.gross_revenue != null ? summary.gross_revenue : (summary.revenue || 0)),
      Number(summary.orders || 0),
      Number(summary.pending_value || 0),
      Number(summary.pending_orders || 0),
      Number(summary.cancelled_value || 0),
      Number(summary.active_clients || 0),
      Number(summary.active_vendors || 0)
    ].some(function (v) { return v > 0; });
  };

  B.vendorsPayloadHasSignal = function (data) {
    if (!data) return false;
    var ss = data.summary_status || {};
    return [
      Number(ss.billed_value || 0), Number(ss.billed_orders || 0),
      Number(ss.authorized_value || 0), Number(ss.authorized_orders || 0),
      Number(ss.cancelled_value || 0), Number(ss.cancelled_orders || 0),
      Number((data.summary && data.summary.target) || 0),
      Number((data.vendor_status_matrix || []).length || 0)
    ].some(function (v) { return v > 0; });
  };

  B.mergeDashboardSummary = function (overviewSummary, vendorsPayload) {
    var merged        = Object.assign({}, overviewSummary || {});
    var vendorSummary = (vendorsPayload && vendorsPayload.summary) || {};
    var ss            = (vendorsPayload && vendorsPayload.summary_status) || {};
    var activeVendorRows = Array.isArray(vendorsPayload && vendorsPayload.vendor_status_matrix)
      ? vendorsPayload.vendor_status_matrix.filter(function (r) {
          return Number(r.billed_value || 0) > 0 || Number(r.authorized_value || 0) > 0 || Number(r.cancelled_value || 0) > 0;
        }).length
      : 0;

    if (!Number(merged.gross_revenue != null ? merged.gross_revenue : (merged.revenue || 0)) && Number(ss.billed_value || 0) > 0) {
      merged.gross_revenue = Number(ss.billed_value || 0);
      merged.revenue = Number(ss.billed_value || 0);
    }
    if (!Number(merged.orders || 0) && Number(ss.billed_orders || 0) > 0)             merged.orders = Number(ss.billed_orders || 0);
    if (!Number(merged.pending_value || 0) && Number(ss.authorized_value || 0) > 0)   merged.pending_value = Number(ss.authorized_value || 0);
    if (!Number(merged.pending_orders || 0) && Number(ss.authorized_orders || 0) > 0) merged.pending_orders = Number(ss.authorized_orders || 0);
    if (!Number(merged.cancelled_value || 0) && Number(ss.cancelled_value || 0) > 0)  merged.cancelled_value = Number(ss.cancelled_value || 0);
    if (!Number(merged.cancelled_orders || 0) && Number(ss.cancelled_orders || 0) > 0) merged.cancelled_orders = Number(ss.cancelled_orders || 0);
    if (!Number(merged.avg_ticket || 0) && Number(ss.billed_orders || 0) > 0)
      merged.avg_ticket = Number(ss.billed_value || 0) / Math.max(1, Number(ss.billed_orders || 0));
    if (!Number(merged.active_vendors || 0) && Number(vendorSummary.active_vendors || 0) > 0) merged.active_vendors = Number(vendorSummary.active_vendors || 0);
    if (!Number(merged.active_vendors || 0) && activeVendorRows > 0) merged.active_vendors = activeVendorRows;
    if (!Number(merged.target || 0) && Number(vendorSummary.target || 0) > 0)         merged.target = Number(vendorSummary.target || 0);
    if (!Number(merged.forecast || 0) && Number(vendorSummary.forecast || 0) > 0)     merged.forecast = Number(vendorSummary.forecast || 0);
    if (!Number(merged.goal_progress || 0) && Number(vendorSummary.goal_progress || 0) > 0) merged.goal_progress = Number(vendorSummary.goal_progress || 0);
    return merged;
  };

  B.vendorsMatrixForHome = function (vendorsPayload, overviewPayload) {
    if (Array.isArray(vendorsPayload && vendorsPayload.vendor_status_matrix) && vendorsPayload.vendor_status_matrix.length) {
      return vendorsPayload.vendor_status_matrix.map(function (row) {
        return {
          vendor_id: row.vendor_id || row.vendor_ref || row.codigo_vendedor_omie || '',
          vendor_name: row.vendor_name || 'Sem vendedor',
          revenue: Number(row.billed_value || 0),
          orders: Number(row.billed_orders || 0),
          ticket_avg: Number(row.avg_ticket_billed || 0),
          gross_revenue: Number(row.billed_value || 0)
        };
      });
    }
    return Array.isArray(overviewPayload && overviewPayload.vendor_ranking) ? overviewPayload.vendor_ranking : [];
  };

  B.overviewSalesAsStatus = function (dailySales) {
    return (dailySales || []).map(function (row) {
      return { date: row.date || '', billed_value: Number(row.gross_revenue != null ? row.gross_revenue : (row.revenue || 0)), billed_orders: Number(row.orders || 0), authorized_value: 0, authorized_orders: 0, cancelled_value: 0, cancelled_orders: 0 };
    });
  };

  // ── Status meter ──────────────────────────────────────────────────────────

  B.renderStatusMeter = function (summaryStatus) {
    summaryStatus = summaryStatus || {};
    var billed     = Number(summaryStatus.billed_value    || 0);
    var authorized = Number(summaryStatus.authorized_value || 0);
    var cancelled  = Number(summaryStatus.cancelled_value  || 0);
    var total      = billed + authorized + cancelled;

    B.setText('[data-bind="pipeline-billed"]',     B.formatCurrency(billed));
    B.setText('[data-bind="pipeline-authorized"]',  B.formatCurrency(authorized));
    B.setText('[data-bind="pipeline-cancelled"]',   B.formatCurrency(cancelled));

    var meter = document.getElementById('statusMeterBar');
    if (!meter) return;
    meter.style.setProperty('--meter-billed', total > 0 ? (billed     / total * 100) + '%' : '0%');
    meter.style.setProperty('--meter-auth',   total > 0 ? (authorized / total * 100) + '%' : '0%');
    meter.style.setProperty('--meter-cancel', total > 0 ? (cancelled  / total * 100) + '%' : '0%');
  };

  // ── Weather ───────────────────────────────────────────────────────────────

  B.weatherCodeMeta = function (code, isDay) {
    var day = !!isDay;
    var map = {
      0: { icon: day ? 'sunny' : 'clear_night', description: day ? 'Ceo limpo' : 'Noite limpa' },
      1: { icon: day ? 'partly_cloudy_day' : 'partly_cloudy_night', description: 'Predominio de sol' },
      2: { icon: day ? 'partly_cloudy_day' : 'partly_cloudy_night', description: 'Parcialmente nublado' },
      3: { icon: 'cloud', description: 'Nublado' },
      45: { icon: 'foggy', description: 'Neblina' }, 48: { icon: 'foggy', description: 'Neblina intensa' },
      51: { icon: 'grain', description: 'Garoa fraca' }, 53: { icon: 'grain', description: 'Garoa' }, 55: { icon: 'grain', description: 'Garoa intensa' },
      61: { icon: 'rainy', description: 'Chuva fraca' }, 63: { icon: 'rainy', description: 'Chuva moderada' }, 65: { icon: 'rainy_heavy', description: 'Chuva forte' },
      71: { icon: 'weather_snowy', description: 'Neve fraca' }, 73: { icon: 'weather_snowy', description: 'Neve' }, 75: { icon: 'weather_snowy', description: 'Neve forte' },
      80: { icon: 'rainy', description: 'Pancadas isoladas' }, 81: { icon: 'rainy_heavy', description: 'Pancadas moderadas' }, 82: { icon: 'rainy_heavy', description: 'Pancadas fortes' },
      95: { icon: 'thunderstorm', description: 'Trovoadas' }, 96: { icon: 'thunderstorm', description: 'Trovoadas com granizo' }, 99: { icon: 'thunderstorm', description: 'Trovoadas severas' }
    };
    return map[Number(code)] || { icon: day ? 'partly_cloudy_day' : 'partly_cloudy_night', description: 'Clima indisponivel' };
  };

  B.renderWeather = function (weather) {
    var dock = document.getElementById('weatherDock');
    if (!dock) return;
    var iconEl = document.getElementById('weatherIcon');
    if (!weather || weather.ok === false) {
      B.setText('#weatherDescription', (weather && weather.message) || 'Clima indisponivel');
      B.setText('#weatherTemperature', '--');
      if (iconEl) iconEl.textContent = 'cloud_off';
      dock.title = (weather && weather.message) || 'Nao foi possivel carregar o clima agora.';
      return;
    }
    var current  = weather.current  || {};
    var today    = weather.today    || {};
    var location = weather.location || {};
    var meta      = B.weatherCodeMeta(current.weather_code, current.is_day);
    var temperature = Math.round(Number(current.temperature || 0)) + ' C';
    var range       = Math.round(Number(today.temperature_max || 0)) + ' / ' + Math.round(Number(today.temperature_min || 0));
    var rain        = B.formatNumber(today.precipitation_probability_max || 0) + '%';
    var wind        = Number(current.wind_speed || 0).toFixed(0) + ' km/h';
    var updatedAt   = current.time ? new Date(current.time).toLocaleString('pt-BR') : '--';

    B.setText('#weatherDescription', meta.description);
    B.setText('#weatherTemperature', temperature);
    if (iconEl) iconEl.textContent = meta.icon;
    dock.title = [location.label || 'Fortaleza, CE', meta.description, 'Max/Min ' + range, 'Chuva ' + rain, 'Vento ' + wind, 'Atualizado ' + updatedAt].join(' | ');
  };

  // ── Table / row renderers ─────────────────────────────────────────────────

  B.renderTable = function (targetId, columns, rows, emptyMessage) {
    var target = document.getElementById(targetId);
    if (!target) return;
    if (!rows.length) {
      target.innerHTML = '<tr><td colspan="' + columns.length + '" class="text-center py-4 text-muted">' + B.escapeHtml(emptyMessage || 'Sem dados no periodo.') + '</td></tr>';
      return;
    }
    target.innerHTML = rows.map(function (row) {
      return '<tr>' + columns.map(function (col) { return '<td>' + col.render(row) + '</td>'; }).join('') + '</tr>';
    }).join('');
  };

  B.commercialIcon = function (icon) {
    return '<span class="commercial-entity__icon"><span class="material-symbols-rounded" aria-hidden="true">' + B.escapeHtml(icon || 'category') + '</span></span>';
  };

  B.commercialSignal = function (icon, label, tone) {
    var toneClass = tone ? ' commercial-signal--' + tone : '';
    return '<span class="commercial-signal' + toneClass + '"><span class="material-symbols-rounded" aria-hidden="true">' + B.escapeHtml(icon || 'circle') + '</span>' + B.escapeHtml(label || '-') + '</span>';
  };

  B.commercialMetric = function (label, value, tone) {
    var toneClass = tone ? ' commercial-metric--' + tone : '';
    return '<div class="commercial-metric' + toneClass + '"><span class="commercial-metric__label">' + B.escapeHtml(label || '') + '</span><strong class="commercial-metric__value">' + B.escapeHtml(String(value == null ? '-' : value)) + '</strong></div>';
  };

  B.maskDocumentForDisplay = function (value) {
    var raw    = String(value || '').trim();
    if (!raw) return '';
    if (raw.includes('****')) return raw;
    var digits = raw.replace(/\D+/g, '');
    if (digits.length === 11) return '***.***.***-**';
    if (digits.length === 14) return digits.slice(0, 2) + '.' + digits.slice(2, 5) + '.' + digits.slice(5, 8) + '/****-**';
    return '';
  };

  B.isBlankCustomerName = function (value) {
    var n = String(value || '').trim().replace(/\s+/g, ' ').toUpperCase();
    return !n || n === '-' || n === 'NULL' || n === '0' || /^CLIENTE\s+N.?O\s+INFORMADO$/.test(n);
  };

  B.renderCustomerIdentityCell = function (row) {
    var rawDoc  = String((row && (row.cliente_documento || row.client_document || row.customer_document)) || '');
    var masked  = B.maskDocumentForDisplay(rawDoc);
    var name    = String((row && (row.cliente || row.client_name)) || '').trim();
    if (B.isBlankCustomerName(name)) name = masked || 'Cliente nao informado';
    var missing = name === 'Cliente nao informado';
    var docLine = masked || 'Sem documento vinculado';
    var clientId = String((row && (row.client_id || row.customer_id || row.codigo_cliente_omie)) || '').trim();
    var noteKey = String((row && row.nota_key) || '').trim();
    var orderCode = String((row && (row.origin_order_code || row.codigo_integracao || row.codigo_pedido || row.codigo)) || '').trim();
    var docPayload = rawDoc.indexOf('*') >= 0 ? '' : rawDoc;
    var canOpen = clientId || docPayload || noteKey || orderCode;
    var nameHtml = canOpen
      ? '<button type="button" class="row-inline-link" data-biomie-action="open-customer" data-codigo-cliente-omie="' + B.escapeHtml(clientId) + '" data-cliente-documento="' + B.escapeHtml(docPayload) + '" data-cliente-nome="' + B.escapeHtml(name) + '" data-nota-key="' + B.escapeHtml(noteKey) + '" data-pedido-codigo="' + B.escapeHtml(orderCode) + '"><strong>' + B.escapeHtml(name) + '</strong></button>'
      : '<strong>' + B.escapeHtml(name) + '</strong>';
    return '<div class="identity-cell' + (missing ? ' identity-cell--attention' : '') + '">' + nameHtml + '<span>' + B.escapeHtml(docLine) + '</span></div>';
  };

  /**
   * Estado do vendedor na ESCADA de metas.
   *
   * ─── O DEFEITO QUE ISTO CORRIGE ───────────────────────────────────────────
   * A versão anterior era `pct >= 100 || realized >= target`, e o segundo termo
   * comparava o realizado com `target_month`/`target_period` — o COMPROMISSO-BASE.
   * Como `pct` já era consciente da escada, o `||` passava por cima dele:
   *
   *   SAMANTHA · realizado R$ 49.306 · compromisso R$ 40.000 · desafio R$ 80.000
   *   pct = 61,6% (correto, primeira condição falsa)
   *   realized >= 40.000 -> TRUE -> troféu "Meta batida"
   *
   * A mesma linha exibia "🏆 Meta batida" ao lado de "Meta 2/4 · 61,6% do
   * desafio", com R$ 150.694 ainda a percorrer até o topo (40k + 80/130/200k).
   * Duas afirmações opostas a três centímetros uma da outra.
   *
   * ─── OS QUATRO ESTADOS ────────────────────────────────────────────────────
   *   sem_meta  — não há meta cadastrada; não se afirma nada
   *   abaixo    — nenhum nível conquistado ainda
   *   parcial   — conquistou N de M; AINDA HÁ ESCADA (é o caso da queixa)
   *   completa  — todos os níveis; só aqui existe troféu
   *
   * Funciona com escada de um nível só (o compromisso vira o topo) e com o
   * payload legado, que não traz `tiers_total`.
   */
  B.goalLadderState = function (vendor) {
    var vazio = { state: 'sem_meta', achieved: 0, total: 0, isTop: false };
    if (!vendor) return vazio;

    var realized = Number(
      vendor.realized_period != null ? vendor.realized_period
        : (vendor.realized != null ? vendor.realized
          : (vendor.revenue != null ? vendor.revenue : (vendor.billed_value || 0)))
    );

    var total = Number(vendor.tiers_total != null ? vendor.tiers_total : (vendor.levels_total || 0));
    var achieved = Number(vendor.achieved_level != null ? vendor.achieved_level : (vendor.current_tier || 0));

    // Payload sem escada (legado): o compromisso é o único nível existente.
    if (!(total > 0)) {
      var base = Number(
        vendor.base_target != null ? vendor.base_target
          : (vendor.target_month != null ? vendor.target_month
            : (vendor.target_period != null ? vendor.target_period : (vendor.target || 0)))
      );
      if (!(base > 0)) return vazio;
      total = 1;
      achieved = realized >= base ? 1 : 0;
    }

    var completa = vendor.all_levels_achieved === true || achieved >= total;
    return {
      state: completa ? 'completa' : (achieved > 0 ? 'parcial' : 'abaixo'),
      achieved: achieved,
      total: total,
      isTop: completa,
      remaining: Math.max(total - achieved, 0),
      overMaximum: Number(vendor.over_maximum_amount || 0),
    };
  };

  /**
   * `true` SÓ quando a escada inteira foi vencida.
   *
   * Mantém o nome porque é consumido pela tabela de vendedores e pelo drawer de
   * Overview; o que muda é a pergunta que ele responde — de "passou do
   * compromisso?" para "acabou a escada?".
   */
  B.isGoalAchieved = function (vendor) {
    return B.goalLadderState(vendor).isTop;
  };

  B.renderGoalBadge = function (vendor) {
    var ladder = B.goalLadderState(vendor);

    if (ladder.state === 'completa') {
      var extra = ladder.overMaximum > 0
        ? ' · superação ' + B.formatCurrency(ladder.overMaximum)
        : '';
      return '<span class="mag-goal-badge mag-goal-badge--achieved" title="Todos os ' +
        ladder.total + ' níveis conquistados' + B.escapeHtml(extra) + '">' +
        '<span aria-hidden="true">🏆</span><span>Meta máxima</span></span>';
    }

    // O caso que gerava a contradição: compromisso vencido, escada em aberto.
    // Diz o que foi conquistado E o que falta, na mesma etiqueta.
    if (ladder.state === 'parcial') {
      return '<span class="mag-goal-badge mag-goal-badge--partial" title="' +
        ladder.achieved + ' de ' + ladder.total + ' níveis conquistados — faltam ' +
        ladder.remaining + '">' +
        '<span aria-hidden="true">✓</span><span>Meta ' + ladder.achieved + '/' + ladder.total +
        '</span></span>';
    }

    return '';
  };

  /**
   * A CÉLULA DE META — uma implementação para as duas telas.
   *
   * Antes existiam duas cópias quase idênticas: uma em
   * `modules/flight-plan/vendor-table.js` e outra aqui, na tabela de
   * vendedores. Duas cópias do mesmo conceito divergem, e estas já haviam
   * divergido — o plano de voo nunca mostrou troféu, a de vendedores mostrava.
   *
   * `opts.showNextStep` liga a linha prescritiva ("faltam X · Y/dia"): ela
   * depende de `active_gap` e `new_daily_target`, que só a tabela de vendedores
   * recebe hoje.
   */
  B.renderTargetLadderCell = function (row, opts) {
    opts = opts || {};
    var fmt = B.formatCurrency;
    var realized = Number(
      row.billed_merchandise != null ? row.billed_merchandise
        : (row.realized_period != null ? row.realized_period : (row.billed_value || 0))
    );
    var baseTarget = Number(row.base_target || row.target_period || 0);
    var target_ = Number(row.active_target != null ? row.active_target : (row.maximum_target || baseTarget));
    var tiersTotal = Number(row.tiers_total || 0);

    if (!(tiersTotal > 1 && target_ > 0)) {
      return target_ > 0
        ? fmt(target_)
        : '<span class="fp-status-badge fp-status-risk">Definir meta</span>';
    }

    var curTier = Number(row.current_tier || 0);
    var nextVal = row.next_tier_value != null ? Number(row.next_tier_value) : null;
    var levels = Array.isArray(row.tier_levels)
      ? row.tier_levels.map(Number).filter(function (v) { return v > 0; })
      : [];
    var topLevel = levels.length ? levels[levels.length - 1] : target_;

    var html = '<span class="fp-target-base"><small>Compromisso</small> ' +
      B.escapeHtml(fmt(baseTarget)) + '</span>';

    html += row.all_levels_achieved
      ? '<br><span class="fp-target-active fp-target-active--done"><small>Meta máxima conquistada</small> ' +
        B.escapeHtml(fmt(Number(row.maximum_target || topLevel))) + '</span>'
      : (nextVal != null
        ? '<br><span class="fp-target-active"><small>Desafio ativo</small> ' +
          B.escapeHtml(fmt(nextVal)) + '</span>'
        : '');

    var tierTitle = curTier >= tiersTotal
      ? 'Todos os ' + tiersTotal + ' níveis conquistados'
      : (nextVal != null
        ? 'Próximo nível: ' + fmt(nextVal) + ' (falta ' + fmt(Number(row.next_tier_gap || 0)) + ')'
        : '');

    html += '<br><span class="fp-tier-badge" title="' + B.escapeHtml(tierTitle) + '">' +
      '<span class="material-symbols-rounded">' + (curTier >= tiersTotal ? 'military_tech' : 'stairs') +
      '</span>Meta ' + Math.min(curTier + (curTier < tiersTotal ? 1 : 0), tiersTotal) + '/' + tiersTotal +
      (curTier >= tiersTotal ? ' ✓' : '') + '</span>';

    if (levels.length) {
      var fillPct = Math.min(100, topLevel > 0 ? (realized / topLevel) * 100 : 0);
      var marks = levels.map(function (lvl) {
        var pos = Math.min(100, topLevel > 0 ? (lvl / topLevel) * 100 : 0);
        return '<span class="tier-progress__mark' + (realized >= lvl ? ' tier-progress__mark--done' : '') +
          '" style="left:' + pos.toFixed(1) + '%" title="' + B.escapeHtml(fmt(lvl)) + '"></span>';
      }).join('');
      html += '<span class="tier-progress"><span class="tier-progress__fill" style="width:' +
        fillPct.toFixed(1) + '%"></span>' + marks + '</span>';
    }

    if (row.all_levels_achieved) {
      if (Number(row.over_maximum_amount || 0) > 0) {
        html += '<small class="fp-tier-step">Superação: ' +
          B.escapeHtml(fmt(Number(row.over_maximum_amount))) + '</small>';
      }
    } else if (row.step_progress_pct != null) {
      html += '<small class="fp-tier-step">' +
        Number(row.step_progress_pct).toFixed(1) + '% do caminho neste nível</small>';
    }

    if (opts.showNextStep) html += B.renderLadderNextStep(row);
    return html;
  };

  /**
   * A LINHA PRESCRITIVA: quanto falta e quanto por dia.
   *
   * "61,6% do desafio" diz onde a pessoa está; não diz o que fazer hoje.
   * `new_daily_target` já vinha calculado pela API e não era exibido em lugar
   * nenhum.
   *
   * Quando a escada acabou, o passo seguinte deixa de ser "corra mais" e passa
   * a ser a PROPOSTA de próximo patamar — que o Python calcula e que é
   * deliberadamente uma sugestão, porque meta mexe em comissão.
   */
  B.renderLadderNextStep = function (row) {
    if (row.all_levels_achieved) {
      var sugestao = row.recommended_next_target != null ? Number(row.recommended_next_target) : null;
      return sugestao > 0
        ? '<small class="fp-tier-next fp-tier-next--proposal" title="Sugestão do modelo; a meta oficial só muda com aprovação">' +
          '<span class="material-symbols-rounded" aria-hidden="true">trending_up</span>' +
          'Próximo patamar sugerido: ' + B.escapeHtml(B.formatCurrency(sugestao)) + '</small>'
        : '';
    }

    var falta = Number(row.active_gap != null ? row.active_gap : (row.next_tier_gap || 0));
    if (!(falta > 0)) return '';

    var porDia = Number(row.new_daily_target || 0);
    return '<small class="fp-tier-next">' +
      '<span class="material-symbols-rounded" aria-hidden="true">flag</span>' +
      'Faltam ' + B.escapeHtml(B.formatCurrency(falta)) +
      (porDia > 0 ? ' · ' + B.escapeHtml(B.formatCurrency(porDia)) + '/dia útil' : '') +
      '</small>' + B.renderLadderOdds(row);
  };

  /**
   * CHANCE DE CADA NÍVEL, calculada pelo serviço Python.
   *
   * É o que responde "vale a pena correr atrás da Meta 3?" — sem isso, a escada
   * mostra os degraus mas não diz quais estão ao alcance. Exemplo real:
   * SAMANTHA em 100% / 63,8% / 32,4% / 5,6% nos quatro níveis.
   *
   * ─── O PORTÃO DE CONFIANÇA ─────────────────────────────────────────────────
   * `ladder_confidence` vem do erro histórico do vendedor. Com 2 a 5 meses de
   * base, quase todos caem em `baixa` — e nesse caso a chance É EXIBIDA, mas
   * marcada, porque número sem lastro apresentado como conclusão é pior que
   * ausência de número. É a mesma trava que o forecast já aplica na home.
   */
  B.renderLadderOdds = function (row) {
    var niveis = Array.isArray(row.level_probabilities) ? row.level_probabilities : [];
    if (!niveis.length) return '';

    var pendentes = niveis.filter(function (n) {
      return n && n.achieved !== true && n.probability != null;
    });
    if (!pendentes.length) return '';

    var confianca = String(row.ladder_confidence || 'sem_dados');
    var fraca = confianca === 'baixa' || confianca === 'sem_dados';

    var chips = pendentes.map(function (n) {
      var pct = Math.round(Number(n.probability) * 100);
      var forca = pct >= 60 ? 'alta' : (pct >= 25 ? 'media' : 'baixa');
      return '<span class="fp-odds__item fp-odds__item--' + forca + '" title="' +
        B.escapeHtml('Meta ' + n.level + ' — ' + B.formatCurrency(Number(n.target || 0))) + '">' +
        'M' + n.level + ' ' + pct + '%</span>';
    }).join('');

    return '<span class="fp-odds' + (fraca ? ' fp-odds--weak' : '') + '" title="' +
      (fraca
        ? 'Histórico curto demais para levar estas chances a sério — registradas para conferência depois'
        : 'Chance estimada de alcançar cada nível ainda em aberto') + '">' +
      '<small class="fp-odds__label">Chance</small>' + chips +
      (fraca ? '<small class="fp-odds__warn">baixa confiança</small>' : '') +
      '</span>';
  };

  B.renderSellerIdentityCell = function (row, fromPage) {
    fromPage = fromPage || 'index.html';
    var vendorName = String((row && row.vendor_name) || '').trim() || 'Sem vendedor';
    if (vendorName === 'Sem vendedor') return '<span style="color:var(--text-3)">Sem vendedor</span>';
    var vHref     = window.NavContext
      ? window.NavContext.buildUrl('vendedores.php', { vendor_nome: vendorName, from: fromPage })
      : 'vendedores.php';
    var vendorId  = B.escapeHtml(String((row && (row.vendor_id || row.vendor_ref || row.codigo_vendedor_omie)) || ''));
    var badgeHtml = B.renderGoalBadge(row);
    return '<div style="display:flex;align-items:center;gap:6px;"><a href="' + B.escapeHtml(vHref) + '" class="bmie-ctx-link" style="font-weight:600;" title="Ver Vendor 360" data-biomie-action="open-vendor" data-vendor-id="' + vendorId + '" data-vendor-name="' + B.escapeHtml(vendorName) + '"><span class="material-symbols-rounded" style="font-size:14px; vertical-align:middle; margin-right:4px;">insights</span>' + B.escapeHtml(vendorName) + '</a>' + badgeHtml + '</div>';
  };

  B.renderTopOrdersIdentityDiagnostics = function (diagnostics) {
    var details = document.getElementById('topOrdersIdentityDiagnostics');
    var grid    = document.getElementById('topOrdersIdentityDiagnosticsGrid');
    if (!details || !grid) return;
    if (!diagnostics || typeof diagnostics !== 'object') { details.hidden = true; grid.innerHTML = ''; return; }
    var customers = diagnostics.customers || {};
    var sellers   = diagnostics.sellers   || {};
    var rows = [
      ['Notas analisadas', diagnostics.total_notes_analyzed != null ? diagnostics.total_notes_analyzed : 0],
      ['Clientes por dimensao', customers.resolved_by_dimension != null ? customers.resolved_by_dimension : 0],
      ['Clientes por destinatario NF', customers.resolved_by_nf_recipient != null ? customers.resolved_by_nf_recipient : 0],
      ['Clientes por nome fantasia', customers.resolved_by_fantasy_name != null ? customers.resolved_by_fantasy_name : 0],
      ['Clientes por documento', customers.resolved_by_document != null ? customers.resolved_by_document : 0],
      ['Clientes nao informados', customers.not_informed != null ? customers.not_informed : 0],
      ['Documentos ausentes', customers.document_missing != null ? customers.document_missing : 0],
      ['Vendedores resolvidos', sellers.resolved != null ? sellers.resolved : 0],
      ['Sem codigo de vendedor', sellers.without_code != null ? sellers.without_code : 0],
      ['Codigo de vendedor nao encontrado', sellers.code_not_found != null ? sellers.code_not_found : 0],
      ['Dimensao clientes atualizada em', customers.dimension_last_synced_at || '-'],
      ['Fonte', diagnostics.source_table || '-'],
      ['Ultima materializacao', diagnostics.last_materialized_at || '-']
    ];
    grid.innerHTML = rows.map(function (r) { return '<span>' + B.escapeHtml(r[0]) + '</span><strong>' + B.escapeHtml(String(r[1])) + '</strong>'; }).join('');
    details.hidden = false;
  };

  B.normalizeInvoiceRow = function (type, row) {
    var source       = row && typeof row === 'object' ? row : {};
    var issueDate    = source.issue_date || source.competencia_date || source.billing_date || source.status_date || '';
    var total        = source.gross_total != null ? source.gross_total : (source.total != null ? source.total : (source.revenue || 0));
    var merchandise  = source.merchandise_total != null ? source.merchandise_total : (source.revenue || 0);
    var discount     = source.discount_total != null ? source.discount_total : (source.discount || 0);
    var client       = source.cliente || source.client_name || 'Cliente nao informado';
    var clientDoc    = B.maskDocumentForDisplay(source.cliente_documento || source.client_document || source.customer_document || '');
    var vendorName   = source.vendor_name || 'Sem vendedor';
    var vendorId     = source.vendor_id || source.vendor_ref || source.codigo_vendedor_omie || '';
    var code         = source.codigo || source.nf_code || 'Documento';
    var integration  = source.codigo_integracao || source.document_key || source.nota_key || '';
    var originOrder  = source.origin_order_code || source.codigo_integracao || '';
    var itemsQty     = source.items_qty != null ? source.items_qty : (source.quantity || 0);
    var unitValue    = source.unit_value != null ? source.unit_value : null;
    var stage        = source.stage || source.status_group || (type === 'product-breakdown' ? 'item_fiscal' : 'documento');
    return {
      type: type, code: String(code), integration: String(integration), originOrder: String(originOrder),
      client: String(client), clientDocument: String(clientDoc), vendorName: String(vendorName), vendorId: String(vendorId),
      issueDate: String(issueDate), stage: String(stage), itemsQty: Number(itemsQty || 0),
      total: Number(total || 0), merchandise: Number(merchandise || 0), discount: Number(discount || 0),
      unitValue: unitValue == null ? null : Number(unitValue || 0),
      documentKey: String(source.document_key || ''), noteKey: String(source.nota_key || ''),
      extraLabel: type === 'product-breakdown' ? 'Item fiscal' : 'Documento',
    };
  };

  B.renderInvoiceDetailGrid = function (entry) {
    var rows = [
      ['Documento', entry.code], ['Origem/Pedido', entry.originOrder || '—'],
      ['Cliente', entry.client], ['Documento cliente', entry.clientDocument || '—'],
      ['Vendedor', entry.vendorName], ['Data base', B.formatMetaDate(entry.issueDate || '') || '—'],
      ['Status', entry.stage || '—'], ['Itens/Qtd.', B.formatNumber(entry.itemsQty || 0)],
      ['Mercadoria', B.formatCurrency(entry.merchandise || 0)], ['Desconto', B.formatCurrency(entry.discount || 0)],
      ['Total', B.formatCurrency(entry.total || 0)],
      ['Chave/Ref.', entry.documentKey || entry.integration || entry.noteKey || '—'],
    ];
    if (entry.unitValue != null) rows.splice(9, 0, ['Valor unitario', B.formatCurrency(entry.unitValue || 0)]);
    return rows.map(function (r) {
      return '<div class="invoice-detail-cell"><span class="invoice-detail-cell__label">' + B.escapeHtml(r[0]) + '</span><strong class="invoice-detail-cell__value">' + B.escapeHtml(String(r[1] || '—')) + '</strong></div>';
    }).join('');
  };

  // ── Product breakdown ─────────────────────────────────────────────────────

  B.productBreakdownMessageCatalog = function (code) {
    var map = {
      canonical_disabled:    { trigger: 'Detalhamento indisponivel: leitura fiscal canonical ainda nao habilitada.', modal: 'Este ambiente ainda nao habilitou a leitura fiscal canonical para abrir o detalhamento por documento.' },
      coverage_pending:      { trigger: 'Detalhamento indisponivel: cobertura fiscal ainda em sincronizacao.',      modal: 'A camada canonical ainda nao tem cobertura suficiente para detalhar este periodo.' },
      canonical_unavailable: { trigger: 'Detalhamento indisponivel: leitura canonical falhou neste periodo.',        modal: 'A leitura canonical falhou neste periodo e o detalhamento por documento foi bloqueado para evitar dados inconsistentes.' },
      legacy_snapshot_mode:  { trigger: 'Detalhamento indisponivel enquanto o painel estiver em snapshot legado.',  modal: 'O painel foi servido por snapshot legado neste periodo, entao o detalhamento por documento nao foi liberado.' }
    };
    return map[String(code || '')] || { trigger: 'Detalhamento indisponivel neste periodo.', modal: 'O detalhamento por documento nao esta disponivel neste periodo.' };
  };

  B.productBreakdownPrimaryMessage = function (state, context) {
    context = context || 'trigger';
    if (state.available) return context === 'modal' ? 'Detalhamento disponivel para este produto.' : 'Abrir detalhamento por documento';
    var catalog = B.productBreakdownMessageCatalog(state.code);
    return String(state.reason || catalog[context] || catalog.trigger);
  };

  B.renderProductBreakdownEmptyState = function (state) {
    if (state.available) return '<strong>Sem documentos para este produto.</strong><span>Nenhum documento encontrado para este produto no periodo selecionado.</span>';
    var parts = ['<strong>' + B.escapeHtml(B.productBreakdownPrimaryMessage(state, 'modal')) + '</strong>'];
    if (state.detail)     parts.push('<span>' + B.escapeHtml(state.detail) + '</span>');
    if (state.resolution) parts.push('<span class="product-breakdown-empty-note">' + B.escapeHtml(state.resolution) + '</span>');
    return parts.join('');
  };

  B.resolveProductBreakdownState = function (data) {
    var meta      = (data && data.meta && data.meta.product_breakdown) || {};
    var available = meta.available === true && (data && data.source) !== 'legacy';
    return {
      available: available, status: String(meta.status || (available ? 'available' : 'unavailable')),
      code: String(meta.code || (available ? 'available' : 'legacy_snapshot_mode')),
      reason: String(meta.reason || ''), detail: String(meta.detail || (data && data.meta && data.meta.fallback_reason) || ''),
      resolution: String(meta.resolution || ''), source: String(meta.source || (data && data.source) || ''),
    };
  };

  B.renderProductBreakdownTrigger = function (row, state) {
    var rawDesc = row.description || '';
    var description = (rawDesc && rawDesc !== 'Produto sem descricao') ? rawDesc : (row.product_code || 'Produto sem descricao');
    var productCode = row.product_code || '';
    var disabled    = !state.available;
    var hint        = B.productBreakdownPrimaryMessage(state, 'trigger');
    var breakdownBtn = '<button type="button" class="product-breakdown-trigger commercial-entity__title" data-product-code="' + B.escapeHtml(productCode) + '" data-product-label="' + B.escapeHtml(description) + '"' + (disabled ? ' disabled aria-disabled="true" title="' + B.escapeHtml(hint) + '"' : '') + ' title="' + B.escapeHtml(description) + '"><strong>' + B.escapeHtml(description) + '</strong></button>';
    var p360Btn = productCode
      ? '<button type="button" class="commercial-action" data-biomie-action="open-product" data-product-code="' + B.escapeHtml(productCode) + '" data-product-name="' + B.escapeHtml(description) + '" title="Product 360"><span class="material-symbols-rounded" aria-hidden="true">open_in_new</span>Product 360</button>'
      : '';
    return '<div class="pd-trigger-cell commercial-entity">' + B.commercialIcon('inventory_2') + '<div class="commercial-entity__body">' + breakdownBtn + '<div class="commercial-entity__meta"><span class="material-symbols-rounded" aria-hidden="true">sell</span>' + B.escapeHtml(productCode || 'Sem SKU') + '</div><div class="commercial-signals">' + B.commercialSignal(disabled ? 'sync_problem' : 'receipt_long', disabled ? 'Detalhamento indisponivel' : 'Ver documentos', disabled ? 'neutral' : 'success') + '</div>' + p360Btn + '</div></div>';
  };

  B.renderProductBreakdownRows = function (rows) {
    return (rows || []).map(function (row) {
      var nfCode = String(row.nf_code || row.numero_nf || '').trim();
      var orderCode = String(row.origin_order_code || row.codigo_pedido || '').trim();
      var vendorId = String(row.vendor_id || row.codigo_vendedor_omie || '').trim();
      var vendorName = String(row.vendor_name || row.nome_vendedor || 'Sem vendedor').trim();
      var clientId = String(row.client_id || row.codigo_cliente_omie || '').trim();
      var clientName = String(row.client_name || row.cliente || 'Cliente nao informado').trim();
      var rawDocument = String(row.client_document || row.cliente_documento || '');
      var docPayload = rawDocument.indexOf('*') >= 0 ? '' : rawDocument;
      // nota_key / invoice_key are the canonical identifiers; document_key is kept as chave_nfe fallback
      var notaKey = String(row.nota_key || row.invoice_key || '').trim();
      var chaveNfe = String(row.chave_nfe || row.document_key || '').trim();
      var nfCell = nfCode
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-invoice"' +
          (notaKey ? ' data-nota-key="' + B.escapeHtml(notaKey) + '"' : '') +
          ' data-numero-nf="' + B.escapeHtml(nfCode) + '"' +
          (chaveNfe ? ' data-chave-nfe="' + B.escapeHtml(chaveNfe) + '"' : '') +
          '>' + B.escapeHtml(nfCode) + '</button>'
        : '<span style="color:var(--text-3)">-</span>';
      var orderCell = orderCode
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-order" data-pedido-codigo="' + B.escapeHtml(orderCode) + '">' + B.escapeHtml(orderCode) + '</button>'
        : '<span style="color:var(--text-3)">-</span>';
      var vendorCell = vendorName && vendorName !== 'Sem vendedor'
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-vendor" data-vendor-id="' + B.escapeHtml(vendorId) + '" data-vendor-name="' + B.escapeHtml(vendorName) + '">' + B.escapeHtml(vendorName) + '</button>'
        : B.escapeHtml(vendorName || 'Sem vendedor');
      var clientCell = (clientId || docPayload)
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-customer" data-codigo-cliente-omie="' + B.escapeHtml(clientId) + '" data-cliente-documento="' + B.escapeHtml(docPayload) + '" data-cliente-nome="' + B.escapeHtml(clientName) + '" data-pedido-codigo="' + B.escapeHtml(orderCode) + '">' + B.escapeHtml(clientName) + '</button>'
        : B.escapeHtml(clientName);
      var scopeLabel = String(row.scope_label || row.operation_scope || row.scope || 'Orc-PD').trim();
      var value = row.revenue != null ? row.revenue : (row.product_total != null ? row.product_total : 0);
      var discount = row.discount != null ? row.discount : (row.discount_total != null ? row.discount_total : 0);
      return '<tr><td><strong>' + nfCell + '</strong></td><td>' + orderCell + '</td><td>' + vendorCell + '</td><td>' + clientCell + '</td><td>' + B.formatMetaDate(row.competencia_date || row.data_competencia || row.issue_date || '') + '</td><td>' + B.formatNumber(row.quantity || 0) + '</td><td>' + B.formatCurrency(value || 0) + '</td><td>' + B.formatCurrency(discount || 0) + '</td><td><span class="mag-status-chip mag-status-ok">' + B.escapeHtml(scopeLabel) + '</span></td></tr>';
    }).join('');
  };

  // ── Alerts / top clients ──────────────────────────────────────────────────

  B.renderAlerts = function (alerts) {
    var target = document.getElementById('alertsList');
    if (!target) return;
    if (!alerts || !alerts.length) {
      target.innerHTML = '<article class="insight-row"><strong>Sem alerta critico</strong><span>Operacao sem desvios relevantes no periodo consultado.</span></article>';
      return;
    }
    target.innerHTML = alerts.map(function (alert) {
      var value = B.formatAlertValue(alert);
      return '<article class="insight-row"><div><strong>' + B.escapeHtml(alert.title) + '</strong><span>' + B.escapeHtml(alert.message) + '</span></div><div class="insight-metric"><small>' + B.escapeHtml(alert.metric || 'Indicador') + '</small><strong>' + value + '</strong></div></article>';
    }).join('');
  };

  B.renderAttentionSignals = function (container, signals, summary, diagnostics) {
    if (!container) return;
    if (!signals || !signals.length) {
      container.innerHTML = '<div class="mag-signal mag-signal--success"><div class="mag-signal__body"><strong class="mag-signal__title">Nenhum ponto critico no periodo</strong><p class="mag-signal__description">Operacao sem desvios relevantes nos filtros atuais.</p></div></div>';
      return;
    }

    var severityLabels = { danger: 'Critico', warning: 'Atencao', success: 'Positivo', info: 'Info' };
    var categoryLabels = { destaque: 'Destaque', carteira: 'Carteira', cancelamento: 'Cancelamento', meta: 'Meta', financeiro: 'Financeiro', operacao: 'Operacao', dados: 'Dados' };

    function isValidActionUrl(url) {
      if (!url || typeof url !== 'string') return false;
      var lower = url.toLowerCase().trim();
      if (lower.indexOf('javascript:') === 0) return false;
      if (lower.indexOf('data:') === 0) return false;
      if (lower.indexOf('http://') === 0 || lower.indexOf('https://') === 0) return false;
      return true;
    }

    function renderSignal(s) {
      var sev       = s.severity || 'info';
      var category  = B.escapeHtml(categoryLabels[s.category] || s.category || '');
      var sevLabel  = B.escapeHtml(severityLabels[sev] || sev);
      var title     = B.escapeHtml(s.title || '');
      var value     = s.value != null ? B.escapeHtml(String(s.value)) : null;
      var desc      = B.escapeHtml(s.description || '');
      var amount    = (s.amount != null && s.amount !== '' && !isNaN(Number(s.amount))) ? B.formatCurrency(Number(s.amount)) : null;
      var amountLbl = s.amount_label ? B.escapeHtml(s.amount_label) : null;
      var basis     = s.period_basis ? B.escapeHtml(s.period_basis) : null;
      var action    = (s.action && isValidActionUrl(s.action.url))
        ? '<a href="' + B.escapeHtml(s.action.url) + '" class="mag-signal__action">' + B.escapeHtml(s.action.label || 'Ver mais') + '</a>'
        : '';
      return '<div class="mag-signal mag-signal--' + B.escapeHtml(sev) + '">'
        + '<div class="mag-signal__body">'
        + '<div class="mag-signal__header">'
        + (category ? '<span class="mag-signal__category">' + category + '</span>' : '')
        + '<span class="mag-badge mag-badge--' + B.escapeHtml(sev) + '">' + sevLabel + '</span>'
        + '</div>'
        + '<strong class="mag-signal__title">' + title + '</strong>'
        + (value ? '<div class="mag-signal__value">' + value + '</div>' : '')
        + (amount ? '<div class="mag-signal__amount"><small>' + (amountLbl || 'Valor') + '</small> ' + amount + '</div>' : '')
        + (desc ? '<p class="mag-signal__description">' + desc + '</p>' : '')
        + ((basis || action) ? '<div class="mag-signal__footer">'
            + (basis ? '<span class="mag-signal__basis">Base: ' + basis + '</span>' : '')
            + action
            + '</div>' : '')
        + '</div>'
        + '</div>';
    }

    var html = signals.map(renderSignal).join('');

    if (summary && summary.hidden_count > 0) {
      html += '<div class="mag-signal-more"><small>' + Number(summary.hidden_count) + ' sinal(is) oculto(s). Refine o periodo para ver todos.</small></div>';
    }

    if (diagnostics && (diagnostics.rules_failed > 0 || (diagnostics.errors && diagnostics.errors.length))) {
      var reqId = (diagnostics.errors && diagnostics.errors[0] && diagnostics.errors[0].request_id) || '';
      html += '<details class="mag-signal-diag"><summary>Diagnostico</summary>'
        + '<small>Regras: ' + Number(diagnostics.rules_evaluated || 0) + ' | Falhas: ' + Number(diagnostics.rules_failed || 0) + ' | Ignoradas: ' + Number(diagnostics.rules_skipped || 0)
        + (reqId ? ' | request_id: ' + B.escapeHtml(reqId) : '')
        + '</small></details>';
    }

    container.innerHTML = html;
  };

  B.renderTopClients = function (rows) {
    var target = document.getElementById('topClientsTable');
    if (!target) return;
    if (!rows || !rows.length) { target.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Sem clientes faturados no periodo.</td></tr>'; return; }
    target.innerHTML = rows.map(function (row) {
      var rawName        = B.isBlankCustomerName(row.client_name) ? 'Cliente nao informado' : row.client_name;
      var cName          = B.escapeHtml(rawName);
      var rawDocument    = String(row.client_document || row.cliente_documento || '');
      var maskedDocument = B.maskDocumentForDisplay(rawDocument);
      var clientId       = String(row.client_id || row.codigo_cliente_omie || '').trim();
      var docPayload     = rawDocument.indexOf('*') >= 0 ? '' : rawDocument;
      var clientCell     = (clientId || docPayload)
        ? '<button type="button" class="row-inline-link" data-biomie-action="open-customer" data-codigo-cliente-omie="' + B.escapeHtml(clientId) + '" data-cliente-documento="' + B.escapeHtml(docPayload) + '" data-cliente-nome="' + cName + '" title="Ver Cliente 360"><strong>' + cName + '</strong></button>'
        : '<strong>' + cName + '</strong>';
      return '<tr><td>' + clientCell + (maskedDocument ? '<div class="small text-muted">' + B.escapeHtml(maskedDocument) + '</div>' : '') + '</td><td>' + B.formatNumber(row.notes_count || 0) + '</td><td>' + B.formatCurrency(row.invoice_total || 0) + '</td><td>' + Number(row.share_pct || 0).toFixed(1) + '%</td></tr>';
    }).join('');
  };

  B.renderCommercialHealth = function (data) {
    var badge = document.getElementById('commercialHealthBadge');
    var info  = document.getElementById('commercialHealthInfo');
    var table = document.getElementById('commercialHealthClientsTable');
    if (!badge || !info || !table) return;
    if (!data || data.available === false) {
      badge.textContent  = 'Opcional';
      info.className     = 'module-alert module-alert--info';
      info.innerHTML     = '<strong>Sem enriquecimento financeiro</strong><span>' + B.escapeHtml((data && data.message) || 'Modulo financeiro Omie indisponivel para este painel.') + '</span>';
      table.innerHTML    = '<tr><td colspan="4" class="text-center py-4 text-muted">Sem dados financeiros adicionais.</td></tr>';
      B.setText('[data-bind="health-received"]', '-');  B.setText('[data-bind="health-open"]', '-');
      B.setText('[data-bind="health-overdue"]', '-');   B.setText('[data-bind="health-cash-conversion"]', '-');
      return;
    }
    var summary   = data.summary || {};
    badge.textContent  = 'Financeiro Omie';
    info.className     = 'module-alert module-alert--success';
    info.innerHTML     = '<strong>Leitura de caixa pronta</strong><span>Recebido ' + B.formatCurrency(summary.received) + ' | em aberto ' + B.formatCurrency(summary.open) + ' | atraso ' + Number(summary.delinquency_rate || 0).toFixed(1) + '%.</span>';
    B.setText('[data-bind="health-received"]',        B.formatCurrency(summary.received));
    B.setText('[data-bind="health-open"]',            B.formatCurrency(summary.open));
    B.setText('[data-bind="health-overdue"]',         B.formatCurrency(summary.overdue));
    B.setText('[data-bind="health-cash-conversion"]', Number(summary.cash_conversion_pct || 0).toFixed(1) + '%');
    var overdueRows = data.top_overdue_clients || [];
    if (!overdueRows.length) { table.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Sem clientes vencidos no periodo consultado.</td></tr>'; return; }
    table.innerHTML = overdueRows.map(function (row) {
      return '<tr><td><strong>' + B.escapeHtml(row.client_name || 'Cliente nao informado') + '</strong>' + (row.cause_label ? '<div class="small text-muted">' + B.escapeHtml(row.cause_label) + '</div>' : '') + '</td><td>' + B.formatCurrency(row.overdue || 0) + '</td><td>' + B.formatNumber(row.titles || 0) + '</td><td>' + B.formatNumber(row.max_days_late || 0) + ' dias</td></tr>';
    }).join('');
  };

  B.renderAreaCards = function (overview, health) {
    var summary = (overview && overview.summary) || {};
    var orders  = Number(summary.orders != null ? summary.orders : (summary.billed_orders || 0));

    var fiscalEl = document.getElementById('areaCardFiscal');
    if (fiscalEl) fiscalEl.textContent = orders > 0 ? B.formatNumber(orders) : '-';

    var finEl = document.getElementById('areaCardFinanceiro');
    if (finEl) {
      var overdue = health && health.summary && health.summary.overdue != null ? health.summary.overdue : null;
      finEl.textContent = overdue != null ? B.formatCurrency(overdue) : '-';
    }

    var geoEl = document.getElementById('areaCardGeo');
    if (geoEl) {
      var neighborhoods = window.__BIOMIE_BOOTSTRAP__ && window.__BIOMIE_BOOTSTRAP__.geo_index && window.__BIOMIE_BOOTSTRAP__.geo_index.neighborhoods && window.__BIOMIE_BOOTSTRAP__.geo_index.neighborhoods.data;
      var count = Array.isArray(neighborhoods) ? neighborhoods.length : null;
      geoEl.textContent = count != null ? B.formatNumber(count) : '-';
    }

    var rotaEl = document.getElementById('areaCardRota');
    if (rotaEl) {
      var vendors = (overview && (overview.vendors_summary || overview.vendors)) || [];
      var activeCount = Array.isArray(vendors) ? vendors.filter(function (v) { return Number(v.billed_value != null ? v.billed_value : (v.revenue || 0)) > 0; }).length : null;
      rotaEl.textContent = activeCount != null ? B.formatNumber(activeCount) : '-';
    }

    var omieEl = document.getElementById('areaCardOmie');
    if (omieEl) {
      var syncTime = overview && overview.meta && (overview.meta.freshness_at || overview.meta.last_sync || overview.meta.generated_at);
      omieEl.textContent = syncTime ? new Date(syncTime).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '-';
    }
  };

  // ── Pipeline / vendor table ───────────────────────────────────────────────

  B.renderPipelineTable = function (targetId, matrix) {
    var target = document.getElementById(targetId);
    if (!target) return;
    if (!matrix.length) { target.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-muted">Sem vendedor no periodo.</td></tr>'; return; }
    target.innerHTML = matrix.map(function (row) {
      var conversionClass = row.conversion_rate >= 70 ? 'billed' : row.conversion_rate >= 40 ? 'authorized' : 'cancelled';
      var vRef       = String(row.vendor_ref || row.vendor_id || '');
      var merc       = row.billed_merchandise != null ? row.billed_merchandise : row.billed_value;
      var baseTarget = Number(row.base_target || row.target_period || 0);
      var target_    = Number(row.active_target != null ? row.active_target : (row.maximum_target || baseTarget));
      var attPct     = Number(row.active_attainment_pct != null ? row.active_attainment_pct : row.attainment_pct || 0);
      var tiersTotalRow = Number(row.tiers_total || 0);
      var badgeHtml  = B.renderGoalBadge(row);
      var achieved   = B.isGoalAchieved(row);
      var rowClass   = (achieved ? 'mag-goal-row--achieved ' : '') + (vRef ? 'commercial-row--interactive' : '');
      var rowAttrs   = vRef ? ' data-vendor-open-ref="' + B.escapeHtml(vRef) + '" tabindex="0" role="button" aria-label="Abrir detalhes de ' + B.escapeHtml(row.vendor_name || 'vendedor') + '"' : '';
      // COM.R1.4 — carteirização badge
      var conflictsOut = Number(row.conflicts_out_of_portfolio || 0);
      var conflictBadge = conflictsOut > 0
        ? B.commercialSignal('warning', B.formatNumber(conflictsOut) + ' fora', 'warning')
        : B.commercialSignal('verified_user', 'Sem conflito', 'neutral');
      // COM.R1.5 — channel badge + inactive badge
      var isActive     = row.is_active !== false;
      var channelLabel = { revenda: 'REV', varejo: 'VAR', representante: 'REP', licitacao: 'LIC' }[String(row.vendor_channel || '')];
      var channelBadge = channelLabel
        ? '<span style="display:inline-block;font-size:9px;font-weight:700;padding:1px 4px;border-radius:3px;letter-spacing:.3px;text-transform:uppercase;background:var(--surface-muted);color:var(--text-2);margin-left:3px;">' + channelLabel + '</span>'
        : '';
      var inactiveBadge = !isActive
        ? '<span style="display:inline-block;font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#f3f4f6;color:#9ca3af;margin-left:3px;" title="Vendedor inativo — histórico">Inativo</span>'
        : '';
      var rowStyle = !isActive ? ' style="opacity:0.6;"' : '';
      var vendorEntity = '<div class="commercial-entity">' + B.commercialIcon('person') + '<div class="commercial-entity__body"><div class="commercial-entity__title">' + B.escapeHtml(row.vendor_name) + channelBadge + inactiveBadge + badgeHtml + '</div><div class="commercial-signals">' + B.commercialSignal('receipt_long', B.formatNumber(row.billed_orders) + ' NFs', 'success') + B.commercialSignal('request_quote', B.formatNumber(row.authorized_orders) + ' carteira', 'warning') + B.commercialSignal('cancel', B.formatNumber(row.cancelled_orders) + ' canceladas', Number(row.cancelled_orders) > 0 ? 'danger' : 'neutral') + '</div></div></div>';
      // Fase 2: célula de meta vinda do helper compartilhado com o plano de
      // voo. Antes eram duas cópias quase idênticas do mesmo bloco, e elas já
      // haviam divergido — o plano nunca mostrou troféu, esta tela mostrava.
      var metaCellExtra = tiersTotalRow > 1 && target_ > 0
        ? B.renderTargetLadderCell(row, { showNextStep: true })
        : B.renderLadderNextStep(row);
      return '<tr class="' + rowClass.trim() + '"' + rowStyle + rowAttrs + '><td>' + vendorEntity + '</td><td>' + B.commercialMetric('Base comercial', B.formatCurrency(merc), 'accent') + '</td><td>' + B.commercialMetric('Desafio ativo', target_ > 0 ? B.formatCurrency(target_) : '-') + metaCellExtra + '</td><td>' + B.commercialMetric('% do desafio', target_ > 0 ? attPct.toFixed(1) + '%' : '-', achieved ? 'accent' : '') + '</td><td><span class="' + B.vendorRiskClass(row.risk_status) + '">' + B.vendorRiskLabel(row.risk_status) + '</span></td><td>' + B.commercialMetric('Carteira', B.formatCurrency(row.authorized_value), 'warning') + '</td><td>' + B.commercialMetric('Canceladas', B.formatCurrency(row.cancelled_value), Number(row.cancelled_value) > 0 ? 'danger' : '') + '</td><td><span class="badge-status ' + conversionClass + '">' + row.conversion_rate + '%</span></td><td>' + B.commercialMetric('Ticket medio', B.formatCurrency(row.avg_ticket_billed)) + '</td><td>' + conflictBadge + '</td></tr>';
    }).join('');
    target.querySelectorAll('tr[data-vendor-open-ref]').forEach(function (row) {
      var openVendor = function () {
        if (typeof window.openVendorDrawer === 'function') window.openVendorDrawer(row.getAttribute('data-vendor-open-ref') || '');
      };
      row.addEventListener('click', function (event) {
        if (event.target.closest('a, button, input, select, textarea')) return;
        openVendor();
      });
      row.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        openVendor();
      });
    });
  };

  B.renderOperationalAlerts = function (targetId, alerts) {
    var target = document.getElementById(targetId);
    if (!target) return;
    if (!alerts || !alerts.length) { target.innerHTML = '<div class="module-alert module-alert--info"><strong>Sem alerta</strong><span>Time sem alerta operacional no periodo.</span></div>'; return; }
    target.innerHTML = alerts.map(function (alert) {
      var level = alert.level === 'danger' ? 'danger' : alert.level === 'warning' ? 'warning' : 'info';
      return '<div class="module-alert module-alert--' + level + '"><strong>' + B.escapeHtml(alert.title) + '</strong><span>' + B.escapeHtml(alert.message) + '</span></div>';
    }).join('');
  };

  // ── Badge helpers (Fase 7 — Design System MAG) ──────────────────────────

  B.sourceBadge = function (source) {
    source = String(source || '').toLowerCase().trim();
    var map = {
      'canonical':              ['canonical', 'canonical-db', 'canonical_db'],
      'gold':                   ['gold', 'gold_mart', 'mart'],
      'fallback':               ['fallback', 'legacy', 'legacy-fallback', 'nf_fallback'],
      'cache':                  ['cache', 'cached', 'snapshot'],
      'empty':                  ['empty', 'none', '']
    };
    var labels = {
      'canonical': 'Canonical',
      'gold':      'Gold',
      'fallback':  'Fallback',
      'cache':     'Cache',
      'empty':     'Sem dados'
    };
    var match = 'empty';
    for (var key in map) {
      if (map[key].indexOf(source) !== -1) { match = key; break; }
    }
    return '<span class="mag-badge mag-badge--source-' + match + '">' + labels[match] + '</span>';
  };

  B.freshnessBadge = function (status) {
    status = String(status || '').toLowerCase().trim();
    var map = {
      'ok':       ['ok', 'fresh', 'live', 'updated', 'atualizado'],
      'warning':  ['warning', 'stale', 'partial', 'delayed', 'attention'],
      'critical': ['critical', 'error', 'outdated', 'missing', 'empty'],
      'unknown':  ['unknown', '', 'unavailable']
    };
    var labels = {
      'ok':       'Atualizado',
      'warning':  'Atenção',
      'critical': 'Crítico',
      'unknown':  'Desconhecido'
    };
    var match = 'unknown';
    for (var key in map) {
      if (map[key].indexOf(status) !== -1) { match = key; break; }
    }
    return '<span class="mag-badge mag-badge--fresh-' + match + '">' + labels[match] + '</span>';
  };

  B.periodBasisBadge = function (periodBasis) {
    periodBasis = String(periodBasis || '').toLowerCase().trim();
    var label;
    if (periodBasis === 'data_competencia' || periodBasis === 'competencia') {
      label = 'data_competencia';
    } else if (periodBasis === 'data_emissao' || periodBasis === 'issue_date') {
      label = 'data_emissão';
    } else if (periodBasis === 'year_month') {
      label = 'ano/mês';
    } else if (periodBasis !== '') {
      label = periodBasis;
    } else {
      return '';
    }
    return '<span class="mag-badge mag-badge--source-canonical" title="Base de período">' + B.escapeHtml(label) + '</span>';
  };

  B.emptyStateHtml = function (opts) {
    opts = opts || {};
    var icon    = opts.icon    || 'inbox';
    var title   = opts.title   || 'Sem dados';
    var text    = opts.text    || 'Nenhum registro encontrado para o período.';
    var action  = opts.action  || null;
    var debug   = opts.debug   || null;
    var compact = opts.compact ? ' mag-empty--compact' : '';
    var html = '<div class="mag-empty' + compact + '">';
    html += '<div class="mag-empty-icon"><span class="material-symbols-rounded" aria-hidden="true">' + B.escapeHtml(icon) + '</span></div>';
    html += '<strong class="mag-empty-title">' + B.escapeHtml(title) + '</strong>';
    html += '<p class="mag-empty-text">' + B.escapeHtml(text) + '</p>';
    if (action) {
      html += '<button type="button" class="mag-empty-action">' + B.escapeHtml(action) + '</button>';
    }
    if (debug) {
      html += '<details class="mag-empty-debug"><summary>Detalhes técnicos</summary><pre>' + B.escapeHtml(JSON.stringify(debug, null, 2)) + '</pre></details>';
    }
    html += '</div>';
    return html;
  };

})(window.BIomie || (window.BIomie = {}));

// ── Alias global legado — garante que renderPanelState nunca derruba dashboard
// mesmo se app.js ainda não carregou (cache stale) ou chamada fora do namespace.
(function () {
  'use strict';
  function _fallbackRenderPanelState(container, state) {
    if (!container) return;
    state = state || {};
    var target = container.querySelector('.dashboard-panel__body')
      || container.querySelector('.panel-body')
      || container;
    var empty = target.querySelector('.dashboard-empty-state');
    if (!empty) {
      empty = document.createElement('div');
      empty.className = 'dashboard-empty-state';
      target.appendChild(empty);
    }
    empty.hidden = false;
    var icon  = state.state === 'error' ? 'error' : 'info';
    var title = String(state.title   || 'Dados indisponíveis');
    var msg   = String(state.message || 'Não foi possível carregar este painel.');
    empty.innerHTML =
      '<span class="material-symbols-rounded">' + icon + '</span>' +
      '<strong>' + title + '</strong>' +
      '<p>' + msg + '</p>';
  }

  // ── Finance Overview card ─────────────────────────────────────────────────

  B.renderFinanceOverview = function (container, data) {
    if (!container) return;
    var fs = data && (data.finance_summary || data.financeSummary || data.financial_summary || data);
    if (!fs || fs.available === false) {
      var reasonCode = (fs && fs.reason_code) || 'unavailable';
      var emptyMsg   = reasonCode === 'finance_table_missing' || reasonCode === 'table_not_found'
        ? 'Tabela de titulos financeiros nao encontrada.'
        : reasonCode === 'finance_table_empty' || reasonCode === 'table_empty'
          ? 'Nenhum titulo financeiro cadastrado.'
          : 'Dados de recebimentos indisponiveis no momento.';
      container.innerHTML =
        '<div class="mag-finance-empty">' +
        '<span class="material-symbols-rounded">account_balance_wallet</span>' +
        '<p>' + B.escapeHtml(emptyMsg) + '</p>' +
        (reasonCode ? '<code class="mag-finance-reason">' + B.escapeHtml(reasonCode) + '</code>' : '') +
        '</div>';
      return;
    }

    var totals  = fs.totals   || {};
    var counts  = fs.counts   || {};
    var risk    = fs.risk     || {};
    var overdue = Array.isArray(fs.overdue_customers) ? fs.overdue_customers : [];

    var riskLevel  = String(risk.level || 'unknown').toLowerCase();
    var riskClass  = riskLevel === 'critical'  ? 'mag-finance-risk--critical'
                   : riskLevel === 'attention' ? 'mag-finance-risk--attention'
                   : riskLevel === 'ok'        ? 'mag-finance-risk--ok'
                   : 'mag-finance-risk--unknown';
    var riskLabel  = riskLevel === 'critical'  ? 'Risco critico'
                   : riskLevel === 'attention' ? 'Atencao'
                   : riskLevel === 'ok'        ? 'Saudavel'
                   : 'Indeterminado';

    var convPct = totals.cash_conversion_pct != null ? Number(totals.cash_conversion_pct).toFixed(1) + '%' : '-';

    var kpis = [
      { label: 'Recebido',    value: B.formatCurrency(totals.received),   count: B.formatNumber(counts.received_titles) + ' tit.' },
      { label: 'Em aberto',   value: B.formatCurrency(totals.open),       count: B.formatNumber(counts.open_titles) + ' tit.' },
      { label: 'Vencido',     value: B.formatCurrency(totals.overdue),    count: B.formatNumber(counts.overdue_titles) + ' tit.' },
      { label: 'Conv. caixa', value: convPct,                             count: '' },
    ];

    var kpiHtml = kpis.map(function (k) {
      return '<div class="mag-finance-kpi">' +
        '<span class="mag-finance-kpi__label">' + B.escapeHtml(k.label) + '</span>' +
        '<span class="mag-finance-kpi__value">' + B.escapeHtml(k.value) + '</span>' +
        (k.count ? '<span class="mag-finance-kpi__count">' + B.escapeHtml(k.count) + '</span>' : '') +
        '</div>';
    }).join('');

    var overdueSection = '';
    if (overdue.length) {
      overdueSection = '<div class="mag-finance-overdue"><table class="mag-finance-table"><thead><tr><th>Cliente</th><th>Vencido</th><th>Tit.</th><th>Max dias</th></tr></thead><tbody>' +
        overdue.slice(0, 5).map(function (r) {
          return '<tr><td>' + B.escapeHtml(r.customer_name || 'N/I') + '</td>' +
            '<td>' + B.formatCurrency(r.overdue_amount || 0) + '</td>' +
            '<td>' + B.formatNumber(r.titles_count || 0) + '</td>' +
            '<td>' + B.formatNumber(r.max_days_overdue || 0) + 'd</td></tr>';
        }).join('') +
        '</tbody></table></div>';
    } else if ((totals.overdue || 0) === 0) {
      overdueSection = '<p class="mag-finance-no-overdue"><span class="material-symbols-rounded">check_circle</span> Sem inadimplência no período.</p>';
    }

    container.innerHTML =
      '<div class="mag-finance-grid">' + kpiHtml + '</div>' +
      '<div class="mag-finance-risk ' + B.escapeHtml(riskClass) + '">' +
        '<span class="material-symbols-rounded">' + (riskLevel === 'critical' ? 'warning' : riskLevel === 'ok' ? 'check_circle' : 'info') + '</span>' +
        '<span>' + B.escapeHtml(riskLabel) + '</span>' +
      '</div>' +
      overdueSection;
  };

  // ── Monthly Comparison executive view ────────────────────────────────────

  B.renderMonthlyComparison = function (container, data) {
    if (!container) return;
    var mc = data && (data.month_comparison || data.monthly_comparison || data.monthlyComparison || data);
    var isRich = mc && typeof mc === 'object' && !Array.isArray(mc) && mc.current;
    if (!isRich || !mc.available) {
      container.innerHTML = '';
      return;
    }

    var cur  = mc.current  || {};
    var prev = mc.previous || {};
    var avg3 = mc.last_3_months_avg || null;
    var proj = mc.projection || null;
    var del  = mc.deltas    || {};
    var ins  = Array.isArray(mc.insights) ? mc.insights : [];
    var period = mc.period || {};

    function deltaBadge(pct) {
      if (pct == null) return '';
      var cls = pct > 0 ? 'mag-delta--positive' : pct < 0 ? 'mag-delta--negative' : 'mag-delta--neutral';
      return '<span class="mag-delta ' + cls + '">' + (pct > 0 ? '+' : '') + Number(pct).toFixed(1) + '%</span>';
    }

    var rows = [
      ['Mes atual', cur.label || cur.year_month || '-', B.formatCurrency(cur.revenue), cur.target > 0 ? B.formatCurrency(cur.target) : '-', cur.attainment_pct != null ? Number(cur.attainment_pct).toFixed(1) + '%' : '-', deltaBadge(del.vs_previous_pct)],
      ['Mes anterior', prev.label || prev.year_month || '-', B.formatCurrency(prev.revenue), prev.target > 0 ? B.formatCurrency(prev.target) : '-', prev.attainment_pct != null ? Number(prev.attainment_pct).toFixed(1) + '%' : '-', ''],
      avg3 ? ['Media 3 meses', '-', B.formatCurrency(avg3.revenue), '-', '-', deltaBadge(del.vs_3m_avg_pct)] : null,
    ].filter(Boolean);

    var tableHtml = '<table class="mag-month-comparison__table"><thead><tr>' +
      '<th></th><th>Periodo</th><th>Receita</th><th>Meta</th><th>Ating.</th><th>vs anterior</th>' +
      '</tr></thead><tbody>' +
      rows.map(function (r) {
        return '<tr><td class="mag-month-comparison__rowlabel">' + B.escapeHtml(r[0]) + '</td>' +
          r.slice(1).map(function (v, i) {
            return '<td' + (i === r.length - 2 ? ' class="mag-month-comparison__delta"' : '') + '>' + v + '</td>';
          }).join('') + '</tr>';
      }).join('') +
      '</tbody></table>';

    var projHtml = '';
    if (proj && period.is_month_in_progress) {
      projHtml = '<div class="mag-month-comparison__projection">' +
        '<span class="material-symbols-rounded">trending_up</span>' +
        '<span>Projecao ao ritmo atual: <strong>' + B.formatCurrency(proj.probable) + '</strong>' +
        ' (' + B.formatNumber(period.elapsed_days || 0) + '/' + B.formatNumber(period.calendar_days || 0) + ' dias)</span>' +
        '</div>';
    }

    var insHtml = '';
    if (ins.length) {
      insHtml = '<ul class="mag-insight-list">' +
        ins.slice(0, 6).map(function (insight) {
          var sev = String(insight.severity || 'info');
          var pct = insight.value_pct != null ? ' (' + (insight.value_pct > 0 ? '+' : '') + Number(insight.value_pct).toFixed(1) + '%)' : '';
          return '<li class="mag-insight mag-insight--' + B.escapeHtml(sev) + '">' +
            '<span class="mag-insight__label">' + B.escapeHtml(insight.label || '') + pct + '</span>' +
            '<span class="mag-insight__detail">' + B.escapeHtml(insight.detail || '') + '</span></li>';
        }).join('') +
        '</ul>';
    }

    container.innerHTML =
      '<div class="mag-month-comparison">' +
        tableHtml +
        projHtml +
        insHtml +
      '</div>';
  };

  window.BIomie = window.BIomie || {};
  window.BIomie.renderPanelState = window.BIomie.renderPanelState || _fallbackRenderPanelState;
  window.renderPanelState        = window.renderPanelState        || _fallbackRenderPanelState;
})();
