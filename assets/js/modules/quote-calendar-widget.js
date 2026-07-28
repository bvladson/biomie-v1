/**
 * AGENDA DE ORÇAMENTOS — widget de calendário compacto e reutilizável.
 *
 * Um mês por vez, células coloridas pela MAIOR urgência de cobrança do dia.
 * Consome api/quote_agenda_calendar.php (mesmo contrato da tela Agenda) e
 * funciona em qualquer contêiner: home (empresa toda) e drawer do vendedor
 * (escopado por seller_id). "Dinâmico" = navega meses e recarrega sob demanda.
 *
 * Datas SÃO strings civis 'YYYY-MM-DD' + Date.UTC — nunca new Date("YYYY-MM-DD"),
 * que o ECMAScript interpreta como UTC e desloca o dia em America/Fortaleza.
 *
 * A cor é escolha da UI (data-urgency + CSS); o servidor só manda a semântica.
 *
 * Uso:
 *   var cal = QuoteCalendarWidget.mount(el, { sellerId: '8788268737' });
 *   cal.reload();          // recarrega o mês atual
 *   cal.destroy();         // remove listeners
 */
(function () {
  'use strict';

  var API = 'api/quote_agenda_calendar.php';
  var WEEKDAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  var MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
  var URG_LABEL = { critical: 'Crítica', overdue: 'Atrasada', today: 'Hoje', normal: 'No prazo' };
  var URG_ALLOWED = { critical: 1, overdue: 1, today: 1, normal: 1 };

  function pad(n) { return String(n).length < 2 ? '0' + n : String(n); }
  function ymd(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); }
  function weekdayOf(s) { var p = s.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])).getUTCDay(); }
  function daysInMonth(y, m) { return new Date(Date.UTC(y, m, 0)).getUTCDate(); }
  function todayCivil() {
    return new Intl.DateTimeFormat('en-CA', {
      timeZone: 'America/Fortaleza', year: 'numeric', month: '2-digit', day: '2-digit'
    }).format(new Date());
  }
  function safeUrg(u) { return URG_ALLOWED[u] ? u : 'normal'; }
  function normalizeSellerIds(value) {
    var raw = Array.isArray(value) ? value : String(value || '').split(',');
    var seen = {};
    var ids = [];
    raw.forEach(function (candidate) {
      var id = String(candidate == null ? '' : candidate).trim();
      if (!id || id === '0' || id.length > 100 || seen[id] || ids.length >= 50) return;
      seen[id] = true;
      ids.push(id);
    });
    return ids;
  }
  function money(v) {
    if (v === null || v === undefined) return '—';
    try { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v); }
    catch (e) { return 'R$ ' + v; }
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }

  function mount(root, opts) {
    if (!root) return null;
    opts = opts || {};
    var sellerIds = normalizeSellerIds(opts.sellerIds || opts.sellerId || '');
    var today = todayCivil();
    var state = {
      year: +today.slice(0, 4),
      month: +today.slice(5, 7),
      days: {},
      loading: false,
      abort: null,
      requestSeq: 0,
      destroyed: false
    };

    root.classList.add('qcw');
    root.innerHTML =
      '<div class="qcw-head">' +
        '<button type="button" class="qcw-nav" data-qcw-prev aria-label="Mês anterior"><span class="material-symbols-rounded">chevron_left</span></button>' +
        '<div class="qcw-title" data-qcw-title>—</div>' +
        '<button type="button" class="qcw-nav" data-qcw-next aria-label="Próximo mês"><span class="material-symbols-rounded">chevron_right</span></button>' +
        '<button type="button" class="qcw-nav" data-qcw-refresh aria-label="Atualizar agora" title="Atualizar agora"><span class="material-symbols-rounded">refresh</span></button>' +
      '</div>' +
      '<div class="qcw-fresh" data-qcw-fresh></div>' +
      '<div class="qcw-grid" data-qcw-grid></div>' +
      '<div class="qcw-legend">' +
        '<span data-urgency="overdue"><i></i>Atrasada</span>' +
        '<span data-urgency="today"><i></i>Hoje</span>' +
        '<span data-urgency="normal"><i></i>No prazo</span>' +
        '<span data-urgency="critical"><i></i>Crítica</span>' +
      '</div>';

    var titleEl = root.querySelector('[data-qcw-title]');
    var gridEl  = root.querySelector('[data-qcw-grid]');
    var freshEl = root.querySelector('[data-qcw-fresh]');

    function renderTitle() {
      titleEl.textContent = MONTHS[state.month - 1] + ' ' + state.year;
    }

    function monthRange() {
      var last = daysInMonth(state.year, state.month);
      return { start: ymd(state.year, state.month, 1), end: ymd(state.year, state.month, last) };
    }

    function renderGrid() {
      renderTitle();
      var total = daysInMonth(state.year, state.month);
      var firstWd = weekdayOf(ymd(state.year, state.month, 1));
      var hoje = todayCivil();

      var html = '<div class="qcw-wdrow">';
      for (var w = 0; w < 7; w++) html += '<div class="qcw-wd">' + WEEKDAYS[w] + '</div>';
      html += '</div><div class="qcw-days">';
      for (var i = 0; i < firstWd; i++) html += '<div class="qcw-cell qcw-cell--empty"></div>';
      for (var d = 1; d <= total; d++) {
        var date = ymd(state.year, state.month, d);
        var agg = state.days[date];
        var cls = 'qcw-cell';
        if (agg) cls += ' qcw-cell--has';
        if (date === hoje) cls += ' qcw-cell--today';
        var urg = agg ? safeUrg(agg.day_urgency) : '';
        var title = agg
          ? (agg.quote_count + ' cobrança(s) · ' + URG_LABEL[urg] + (agg.total_value != null ? ' · ' + money(agg.total_value) : ''))
          : '';
        html += '<div class="' + cls + '" data-qcw-date="' + date + '"' + (urg ? ' data-urgency="' + urg + '"' : '') +
                (title ? ' title="' + esc(title) + '"' : '') + '>' +
                  '<span class="qcw-daynum">' + d + '</span>' +
                  (agg ? '<span class="qcw-badge">' + agg.quote_count + '</span>' : '') +
                '</div>';
      }
      html += '</div>';
      gridEl.innerHTML = html;
    }

    function load() {
      if (state.loading) { if (state.abort) state.abort.abort(); }
      var requestSeq = ++state.requestSeq;
      var requestedSellerIds = sellerIds.join(',');
      state.loading = true;
      renderTitle();
      gridEl.innerHTML = '<div class="qcw-msg">Carregando…</div>';
      freshEl.textContent = '';
      if (typeof opts.onLoading === 'function') {
        try {
          opts.onLoading({
            sellerIds: sellerIds.slice(),
            sellerId: sellerIds.length === 1 ? sellerIds[0] : ''
          });
        } catch (loadingError) {}
      }
      var r = monthRange();
      var url = API + '?start=' + r.start + '&end=' + r.end;
      if (sellerIds.length) url += '&seller_ids=' + encodeURIComponent(sellerIds.join(','));
      if (sellerIds.length === 1) url += '&seller_id=' + encodeURIComponent(sellerIds[0]);
      var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      state.abort = ctrl;
      fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' },
        signal: ctrl ? ctrl.signal : undefined
      })
        .then(function (resp) {
          return resp.json().catch(function () { return null; }).then(function (body) {
            if (!resp.ok || !body || body.ok === false) {
              var err = new Error((body && body.message) || ('Falha HTTP ' + resp.status));
              err.status = resp.status;
              err.code = (body && body.error) || (resp.status === 401 ? 'auth' : 'http_' + resp.status);
              err.body = body;
              throw err;
            }
            return body;
          });
        })
        .then(function (body) {
          if (
            state.destroyed
            || requestSeq !== state.requestSeq
            || requestedSellerIds !== sellerIds.join(',')
          ) return;
          state.loading = false;
          state.days = {};
          var days = (body && body.data && body.data.days) || [];
          for (var i = 0; i < days.length; i++) state.days[days[i].date] = days[i];

          // O RESUMO vem do open_summary (ponto-no-tempo, servidor), NUNCA da
          // soma dos dias do mês — senão um orçamento com cobrança fora do mês
          // exibido sumiria da conta e o card/miniagenda/Agenda divergiriam.
          var k = (body && body.data && body.data.open_summary) || null;
          var sum = {
            quotes:   k && k.open     ? +(k.open.count || 0)     : 0,
            overdue:  k && k.overdue  ? +(k.overdue.count || 0)  : 0,
            critical: k && k.critical ? +(k.critical.count || 0) : 0,
            today:    k && k.charge_today ? +(k.charge_today.count || 0) : 0,
            value:    k && k.open ? +(k.open.total_value || 0) : 0,
            unknownValue: k && k.open ? +(k.open.unknown_value_count || 0) : 0,
            monthLabel: MONTHS[state.month - 1] + ' ' + state.year
          };
          renderGrid();
          var f = body && body.freshness;
          if (f) {
            if (f.is_stale) freshEl.innerHTML = '<span class="qcw-stale">Dados do Omie desatualizados' + (f.age_minutes != null ? ' (há ' + f.age_minutes + ' min)' : '') + '</span>';
            else if (f.last_successful_at) freshEl.textContent = 'Atualizado' + (f.age_minutes != null ? ' há ' + f.age_minutes + ' min' : ' agora');
          }
          if (typeof opts.onLoad === 'function') { try { opts.onLoad(sum); } catch (e) {} }
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          if (
            state.destroyed
            || requestSeq !== state.requestSeq
            || requestedSellerIds !== sellerIds.join(',')
          ) return;
          state.loading = false;
          var code = String((err && err.code) || '');
          var retryable = true;
          var msg = 'Não foi possível carregar o calendário.';
          if (code === 'feature_disabled') {
            msg = 'Agenda de Orçamentos temporariamente pausada.';
            retryable = false;
          } else if (code === 'company_feature_disabled' || code === 'company_feature_not_configured') {
            msg = 'Agenda de Orçamentos ainda não ativada para esta empresa.';
            retryable = false;
          } else if (code === 'seller_not_authorized') {
            msg = 'Este vendedor não está no seu escopo de acesso.';
            retryable = false;
          } else if (code === 'tenant_context_unavailable' || code === 'tenant_unavailable') {
            msg = 'Selecione uma empresa para consultar a Agenda.';
            retryable = false;
          } else if (code === 'auth' || (err && err.status === 401)) {
            msg = 'Sessão expirada. Entre novamente.';
            retryable = false;
          }
          gridEl.innerHTML = '<div class="qcw-msg qcw-msg--err" role="status">' + esc(msg) +
            (retryable ? ' <button type="button" class="qcw-retry" data-qcw-retry>Tentar novamente</button>' : '') +
            '</div>';
          if (typeof opts.onError === 'function') {
            try {
              opts.onError({ code: code || 'network_error', status: err && err.status, message: msg, retryable: retryable });
            } catch (callbackError) {}
          }
        });
    }

    /**
     * Botão "Atualizar": recarrega o LOCAL na hora e, em paralelo, PEDE uma
     * sincronização remota. Nunca espera a Omie — o pedido é fire-and-forget e
     * o servidor decide, pelo cooldown, se enfileira. Escopo é a empresa da
     * sessão; nenhuma outra é tocada.
     */
    function manualRefresh() {
        load();   // leitura local imediata — o gestor vê o resultado agora

        var meta = document.querySelector('meta[name="biomie-csrf"]');
        var csrf = meta ? meta.getAttribute('content') : '';
        fetch('api/quote_agenda_refresh_request.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf })
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (body) {
                var rs = body && body.data && body.data.remote_sync;
                if (!rs || !freshEl) return;
                // Só informa; não bloqueia nada. O dado local já está na tela.
                if (rs.queued) {
                    freshEl.textContent = 'Sincronização solicitada — os números se atualizam em instantes.';
                } else if (rs.retry_after_seconds > 0) {
                    freshEl.textContent = 'Dados locais atualizados. Nova sincronização em ' + rs.retry_after_seconds + 's.';
                }
            })
            .catch(function () { /* pedido remoto é acessório: falhar não afeta a leitura local */ });
    }

    function shift(delta) {
      var m = state.month + delta;
      state.year += Math.floor((m - 1) / 12);
      state.month = ((m - 1) % 12 + 12) % 12 + 1;
      load();
    }

    function onClick(e) {
      // O widget pode estar dentro de um <a> (card clicável na home): qualquer
      // controle interno precisa cancelar a navegação, senão navegar month/refresh
      // levaria o gestor para fora da página.
      if (e.target.closest('[data-qcw-prev]'))    { e.preventDefault(); shift(-1); return; }
      if (e.target.closest('[data-qcw-next]'))    { e.preventDefault(); shift(1);  return; }
      if (e.target.closest('[data-qcw-refresh]')) { e.preventDefault(); manualRefresh(); return; }
      if (e.target.closest('[data-qcw-retry]'))   { e.preventDefault(); load();    return; }
      var day = e.target.closest('.qcw-cell--has');
      if (day && typeof opts.onDayClick === 'function') {
        e.preventDefault();
        opts.onDayClick(day.getAttribute('data-qcw-date'), state.days[day.getAttribute('data-qcw-date')] || null);
      }
    }
    root.addEventListener('click', onClick);

    renderTitle();
    load();
    return {
      reload: load,
      setSeller: function (s) {
        sellerIds = normalizeSellerIds(s);
        load();
      },
      setSellers: function (values) {
        sellerIds = normalizeSellerIds(values);
        load();
      },
      destroy: function () {
        state.destroyed = true;
        state.requestSeq++;
        if (state.abort) state.abort.abort();
        root.removeEventListener('click', onClick);
      }
    };
  }

  window.QuoteCalendarWidget = { mount: mount };
}());
