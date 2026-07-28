/**
 * AGENDA DE ORÇAMENTOS — grid mensal próprio + painel lateral sob demanda (Fase 6).
 *
 * DUAS CHAMADAS por desenho: `calendar` (agregado, leve) no mês e `day` só quando
 * o gestor seleciona uma data. Sem prefetch — prefetch de todos os dias recriaria
 * o payload único disfarçado. Sobre HTTP/2 as duas trocas dividem a mesma conexão.
 *
 * ⚠️ DATAS CIVIS SÃO STRINGS 'YYYY-MM-DD' — SEMPRE.
 * NUNCA use `new Date('2026-07-18')`: o ECMAScript trata a forma somente-data como
 * UTC e, ao ler em America/Fortaleza (UTC-3), o dia RETROCEDE. Toda a aritmética
 * de calendário aqui usa Date.UTC explicitamente e volta para string via ymd().
 *
 * A API entrega SEMÂNTICA de urgência (urgency + urgency_rank); a cor é escolha
 * desta camada, respeitando o tema da empresa.
 */
(function () {
  'use strict';

  const API = {
    calendar: 'api/quote_agenda_calendar.php',
    day: 'api/quote_agenda_day.php',
    summary: 'api/quote_agenda_summary.php',
    charge: 'api/quote_agenda_charge.php',
    freshness: 'api/quote_agenda_freshness.php',
    history: 'api/quote_agenda_history.php',
  };
  const PAGE_SELLER_IDS = (() => {
    try {
      const params = new URLSearchParams(window.location.search || '');
      const nav = window.NavContext && typeof window.NavContext.get === 'function'
        ? window.NavContext
        : null;
      const raw = String(
        params.get('vendor_ids')
        || params.get('seller_ids')
        || params.get('seller_id')
        || (nav && (nav.get('vendor_ids') || nav.get('vendor_id')))
        || ''
      );
      return Array.from(new Set(raw.split(',')
        .map((id) => String(id || '').trim())
        .filter((id) => id && id !== '0' && id.length <= 100)))
        .slice(0, 50);
    } catch (e) {
      return [];
    }
  })();

  const scopedGetUrl = (url) => {
    if (!PAGE_SELLER_IDS.length || /[?&]seller_ids?=/.test(url)) return url;
    const key = PAGE_SELLER_IDS.length === 1 ? 'seller_id' : 'seller_ids';
    const value = PAGE_SELLER_IDS.length === 1 ? PAGE_SELLER_IDS[0] : PAGE_SELLER_IDS.join(',');
    return `${url}${url.includes('?') ? '&' : '?'}${key}=${encodeURIComponent(value)}`;
  };

  /**
   * ALLOWLIST das chaves canônicas da API. A UI NÃO recalcula a classificação —
   * apenas traduz o que o servidor decidiu. Valor fora da lista cai em 'normal'.
   */
  const URGENCY_ALLOWED = ['normal', 'future', 'today', 'due', 'overdue', 'critical'];
  const URGENCY_LABEL = {
    normal: 'No prazo', future: 'No prazo',
    today: 'Cobrar hoje', due: 'Cobrar hoje',
    overdue: 'Atrasada', critical: 'Crítica',
  };
  const safeUrgency = (u) => (URGENCY_ALLOWED.includes(u) ? u : 'normal');

  const WEEKDAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

  // ── Datas civis: só string + Date.UTC ─────────────────────────────────────────
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;
  /** Dia da semana (0-6) de 'YYYY-MM-DD' sem passar por fuso local. */
  const weekdayOf = (s) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
  };
  const daysInMonth = (y, m) => new Date(Date.UTC(y, m, 0)).getUTCDate();
  /** "Hoje" em Fortaleza, como string civil — sem conversão implícita. */
  const todayCivil = () => {
    const f = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'America/Fortaleza', year: 'numeric', month: '2-digit', day: '2-digit',
    });
    return f.format(new Date()); // en-CA já entrega YYYY-MM-DD
  };
  const dayNumber = (s) => Number(s.slice(8, 10));

  const money = (v) => v === null || v === undefined
    ? '—'
    : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);

  // ── Estado ────────────────────────────────────────────────────────────────────
  const state = {
    year: 0,
    month: 0,          // 1-12
    selectedDate: null, // 'YYYY-MM-DD'
    days: new Map(),    // 'YYYY-MM-DD' -> agregado
    calendarHash: null,
    canManage: false,
    canHistory: false,
    dayRequestSeq: 0,   // impede resposta atrasada de sobrescrever o dia atual
    dayAbort: null,     // AbortController da requisição em voo
    panelOpener: null,  // elemento que abriu o painel — o foco volta para ele
  };

  const $ = (sel) => document.querySelector(sel);

  // ── Fetch ─────────────────────────────────────────────────────────────────────
  async function getJson(url, { signal } = {}) {
    const res = await fetch(scopedGetUrl(url), {
      signal,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || !body || body.ok === false) {
      const err = new Error((body && body.message) || `Falha ${res.status}`);
      err.status = res.status;
      err.code = (body && body.error) || `http_${res.status}`;
      err.body = body;
      throw err;
    }
    return body;
  }

  // ── Frescor ───────────────────────────────────────────────────────────────────
  function renderFreshness(f) {
    const el = $('#qaFreshness');
    if (!el || !f) return;
    if (!f.is_stale) {
      el.hidden = true;
      el.textContent = '';
      return;
    }
    el.hidden = false;
    el.className = 'qa-banner qa-banner--stale';
    el.innerHTML = `
      <span class="material-symbols-rounded" aria-hidden="true">sync_problem</span>
      <span>${escapeHtml(f.message || 'DADOS DO OMIE DESATUALIZADOS')}</span>`;
  }

  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
  }

  // ── KPIs ──────────────────────────────────────────────────────────────────────
  async function loadSummary() {
    try {
      const body = await getJson(API.summary);
      const k = body.data.kpis;
      setKpi('qaKpiToday', k.charge_today, 'today');
      setKpi('qaKpiOverdue', k.overdue, 'overdue');
      setKpi('qaKpiOpen', k.open, 'normal');
      setKpi('qaKpiCritical', k.critical, 'critical');

      const q = body.data.data_quality;
      const prov = $('#qaProvisional');
      if (prov) {
        const n = q.provisional_dates_count || 0;
        prov.hidden = n === 0;
        prov.textContent = `Datas provisórias: ${n} de ${q.total_count}`;
      }
      renderFreshness(body.freshness);
    } catch (e) {
      console.warn('[agenda] KPIs indisponíveis', e);
    }
  }

  function setKpi(id, v, urgency) {
    const el = document.getElementById(id);
    if (!el || !v) return;
    el.setAttribute('data-urgency', safeUrgency(urgency));
    el.querySelector('[data-kpi-count]').textContent = v.count ?? 0;
    const val = el.querySelector('[data-kpi-value]');
    if (val) {
      val.textContent = money(v.total_value);
      val.title = v.unknown_value_count > 0
        ? `${v.known_value_count} com valor conhecido · ${v.unknown_value_count} sem valor no canônico`
        : '';
    }
  }

  // ── Calendário ────────────────────────────────────────────────────────────────
  async function loadCalendar(silent = false) {
    const start = ymd(state.year, state.month, 1);
    const end = ymd(state.year, state.month, daysInMonth(state.year, state.month));
    const url = `${API.calendar}?start=${start}&end=${end}`;

    if (!silent) renderGridSkeleton();
    try {
      const body = await getJson(url);
      state.days = new Map((body.data.days || []).map((d) => [d.date, d]));
      state.calendarHash = body.freshness ? body.freshness.snapshot_hash : null;
      renderGrid();
      renderFreshness(body.freshness);
    } catch (e) {
      if (!silent) renderGridError(e);
    }
  }

  function renderGridSkeleton() {
    renderMonthLabel();
    const grid = $('#qaGrid');
    if (grid) grid.innerHTML = '<div class="qa-grid-loading">Carregando…</div>';
  }

  function renderGridError(e) {
    renderMonthLabel();
    const grid = $('#qaGrid');
    if (!grid) return;
    const code = String(e?.code || '');
    const retryable = ![
      'feature_disabled',
      'company_feature_disabled',
      'company_feature_not_configured',
      'seller_not_authorized',
    ].includes(code);
    const message = code === 'feature_disabled'
      ? 'A Agenda de Orçamentos está temporariamente pausada.'
      : (code === 'company_feature_disabled' || code === 'company_feature_not_configured')
        ? 'A Agenda de Orçamentos ainda não foi ativada para esta empresa.'
        : 'Não foi possível carregar o calendário.';
    grid.innerHTML = `<div class="qa-grid-error">
      <p>${escapeHtml(message)}</p>
      ${retryable ? '<button type="button" class="btn-secondary btn-sm" data-qa-retry-calendar>Tentar novamente</button>' : ''}
    </div>`;
    grid.querySelector('[data-qa-retry-calendar]')?.addEventListener('click', () => loadCalendar());
    console.warn('[agenda] calendário', e);
  }

  function renderMonthLabel() {
    const label = $('#qaMonthLabel');
    if (label && state.month >= 1 && state.month <= 12) {
      label.textContent = `${MONTHS[state.month - 1]} ${state.year}`;
    }
  }

  function renderGrid() {
    const grid = $('#qaGrid');
    if (!grid) return;

    renderMonthLabel();

    const total = daysInMonth(state.year, state.month);
    const firstWd = weekdayOf(ymd(state.year, state.month, 1));
    const hoje = todayCivil();

    let html = '<div class="qa-week qa-week--head" role="row">';
    WEEKDAYS.forEach((w) => { html += `<div class="qa-wd" role="columnheader">${w}</div>`; });
    html += '</div><div class="qa-days" role="rowgroup">';

    for (let i = 0; i < firstWd; i++) html += '<div class="qa-cell qa-cell--empty" aria-hidden="true"></div>';

    for (let d = 1; d <= total; d++) {
      const date = ymd(state.year, state.month, d);
      const agg = state.days.get(date);
      const isToday = date === hoje;
      const isSel = date === state.selectedDate;

      const urg = agg ? safeUrgency(agg.day_urgency) : null;
      const cls = ['qa-cell'];
      if (agg) cls.push('qa-cell--has');
      if (isToday) cls.push('qa-cell--today');
      if (isSel) cls.push('qa-cell--selected');

      const parts = [];
      if (agg) {
        // Urgência em TEXTO ("Crítica · 3"), nunca só a cor (WCAG 1.4.1).
        parts.push(`<span class="qa-cell-urgency">${URGENCY_LABEL[urg]} · ${agg.quote_count}</span>`);
        parts.push(`<span class="qa-cell-count">${agg.quote_count} ${agg.quote_count === 1 ? 'cobrança' : 'cobranças'}</span>`);
        parts.push(`<span class="qa-cell-value">${money(agg.total_value)}</span>`);
        const flags = [];
        if (agg.overdue_count) flags.push(`${agg.overdue_count} atrasada${agg.overdue_count > 1 ? 's' : ''}`);
        if (agg.critical_count) flags.push(`${agg.critical_count} crítica${agg.critical_count > 1 ? 's' : ''}`);
        if (agg.unknown_value_count) flags.push(`${agg.unknown_value_count} sem valor`);
        if (flags.length) parts.push(`<span class="qa-cell-flags">${escapeHtml(flags.join(' · '))}</span>`);
      }

      html += `<button type="button" class="${cls.join(' ')}" data-qa-date="${date}"
                 ${urg ? `data-urgency="${urg}"` : ''}
                 role="gridcell" aria-selected="${isSel}"
                 aria-label="${d} de ${MONTHS[state.month - 1]}${agg
                   ? `, ${agg.quote_count} cobranças, ${URGENCY_LABEL[urg]}` : ', sem cobranças'}">
                 <span class="qa-cell-day">${d}</span>${parts.join('')}
               </button>`;
    }
    html += '</div>';
    grid.innerHTML = html;
  }

  // ── Painel lateral (sob demanda) ──────────────────────────────────────────────
  async function selectDay(date) {
    state.selectedDate = date;
    renderGrid();                 // destaca a célula imediatamente
    openPanel();
    renderPanelSkeleton(date);

    // Novo clique cancela a requisição anterior…
    if (state.dayAbort) state.dayAbort.abort();
    const ctrl = new AbortController();
    state.dayAbort = ctrl;
    // …e o contador impede que uma resposta atrasada sobrescreva o dia atual.
    const seq = ++state.dayRequestSeq;

    try {
      const body = await getJson(`${API.day}?date=${encodeURIComponent(date)}`, { signal: ctrl.signal });
      if (seq !== state.dayRequestSeq) return;   // resposta obsoleta: descarta
      renderPanel(date, body.data.items || [], body.freshness);

      // Snapshot mudou desde o calendário: mostra a lista e atualiza o mês em silêncio.
      const h = body.freshness ? body.freshness.snapshot_hash : null;
      if (h && state.calendarHash && h !== state.calendarHash) {
        loadCalendar(true);
      }
    } catch (e) {
      if (e.name === 'AbortError' || seq !== state.dayRequestSeq) return;
      renderPanelError(date, e);  // calendário permanece funcional
    }
  }

  // Painel NÃO-MODAL: sem focus trap (ver comentário em quote-agenda.css). O
  // contrato de teclado é: foco entra ao abrir, Esc fecha, foco VOLTA para a
  // célula que abriu — sem isso o teclado é devolvido ao topo do documento e o
  // usuário perde o lugar no calendário.
  function openPanel() {
    const panel = $('#qaPanel');
    if (!panel) return;
    // Guarda a origem ANTES de mover o foco. Só um elemento da página: se o
    // painel já estava aberto, a origem original é preservada.
    if (!panel.hasAttribute('data-open')) {
      state.panelOpener = document.activeElement;
    }
    panel.setAttribute('data-open', 'true');
    panel.setAttribute('tabindex', '-1');
    panel.focus({ preventScroll: true });
  }

  function closePanel() {
    const panel = $('#qaPanel');
    panel?.removeAttribute('data-open');
    state.selectedDate = null;
    renderGrid();                 // recria as células: a origem some do DOM

    // Devolve o foco à célula do dia recém-desenhada; se ela não existir mais
    // (troca de mês), o foco fica onde está em vez de saltar para o topo.
    const opener = state.panelOpener;
    state.panelOpener = null;
    if (!opener) return;
    const date = opener.getAttribute?.('data-qa-date');
    const target = date ? $(`[data-qa-date="${date}"]`) : (opener.isConnected ? opener : null);
    target?.focus({ preventScroll: true });
  }

  function renderPanelSkeleton(date) {
    setPanelTitle(date);
    const body = $('#qaPanelBody');
    if (body) {
      body.innerHTML = '<div class="qa-skeleton"><div></div><div></div><div></div></div>';
    }
  }

  function renderPanelError(date, e) {
    setPanelTitle(date);
    const body = $('#qaPanelBody');
    if (!body) return;
    body.innerHTML = `<div class="qa-panel-error">
        <p>Não foi possível carregar os orçamentos deste dia.</p>
        <p class="qa-muted">${escapeHtml(e.message || '')}</p>
        <button type="button" class="btn-primary btn-sm" data-qa-retry-day>Tentar novamente</button>
      </div>`;
    body.querySelector('[data-qa-retry-day]')?.addEventListener('click', () => selectDay(date));
  }

  function setPanelTitle(date) {
    const t = $('#qaPanelTitle');
    if (t) t.textContent = `${dayNumber(date)} de ${MONTHS[Number(date.slice(5, 7)) - 1]}`;
  }

  function renderPanel(date, items, freshness) {
    setPanelTitle(date);
    renderFreshness(freshness);
    const body = $('#qaPanelBody');
    if (!body) return;

    if (!items.length) {
      body.innerHTML = '<p class="qa-empty">Nenhuma cobrança programada para este dia.</p>';
      return;
    }

    body.innerHTML = items.map((i) => {
      const urg = safeUrgency(i.urgency);
      const vendor = i.seller_identified
        ? escapeHtml(i.seller_name || i.seller_id)
        : '<span class="qa-warn">Vendedor não identificado</span>';
      const valor = i.canonical_available
        ? money(i.value)
        : '<span class="qa-warn" title="Sem registro canônico no momento">valor indisponível</span>';
      const idade = `${escapeHtml(i.age_label)}${i.age_note
        ? ` <span class="qa-note" title="${escapeHtml(i.age_note)}">·&nbsp;provisória</span>` : ''}`;

      return `<article class="qa-item" data-urgency="${urg}" data-qa-item="${escapeHtml(i.omie_order_id)}">
        <header class="qa-item-head">
          <span class="qa-item-num">Orçamento ${escapeHtml(i.numero_pedido || i.omie_order_id)}</span>
          <span class="qa-item-value">${valor}</span>
        </header>
        <p><span class="qa-badge">${URGENCY_LABEL[urg]}</span></p>
        <p class="qa-item-customer">${escapeHtml(i.customer || 'Cliente não informado')}</p>
        <dl class="qa-item-meta">
          <div><dt>Vendedor</dt><dd>${vendor}</dd></div>
          <div><dt>Idade</dt><dd>${idade}</dd></div>
          <div><dt>Cobrança</dt><dd>${i.charge_count} de ${i.total_steps}</dd></div>
          <div><dt>Previsão</dt><dd>${i.forecast_on ? escapeHtml(i.forecast_on) : '—'}</dd></div>
        </dl>
        <footer class="qa-item-actions">
          <button type="button" class="btn-secondary btn-sm" data-qa-copy="${escapeHtml(i.numero_pedido || i.omie_order_id)}">
            Copiar nº
          </button>
          ${state.canHistory ? `<button type="button" class="btn-secondary btn-sm"
                  data-qa-history="${i.followup_id}"
                  aria-expanded="false">Histórico</button>` : ''}
          ${state.canManage ? `<button type="button" class="btn-primary btn-sm"
              data-qa-charge="${escapeHtml(i.omie_order_id)}"
              data-qa-expected="${i.charge_count}">Cobrança realizada</button>` : ''}
        </footer>
        <div class="qa-history" data-qa-history-for="${i.followup_id}" hidden></div>
      </article>`;
    }).join('');
  }

  // ── Histórico (trilha de cobranças) ───────────────────────────────────────────
  /** Formata instante UTC para leitura em Fortaleza (o datetime= fica em UTC). */
  function fmtInstant(iso) {
    return new Intl.DateTimeFormat('pt-BR', {
      timeZone: 'America/Fortaleza', day: '2-digit', month: '2-digit',
      hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));   // ISO COM hora e Z: conversão é correta e desejada
  }

  async function toggleHistory(btn) {
    const id = btn.getAttribute('data-qa-history');
    const box = document.querySelector(`[data-qa-history-for="${id}"]`);
    if (!box) return;

    if (!box.hidden) {                       // recolhe
      box.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      return;
    }

    box.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    box.innerHTML = '<p class="qa-muted">Carregando histórico…</p>';

    try {
      const body = await getJson(`${API.history}?followup_id=${encodeURIComponent(id)}`);
      renderHistory(box, body.data.events || []);
    } catch (e) {
      // Falha no histórico NÃO esconde os dados principais do orçamento.
      box.innerHTML = `<p class="qa-muted">Não foi possível carregar o histórico.
        <button type="button" class="btn-secondary btn-sm" data-qa-history="${id}">Tentar novamente</button></p>`;
    }
  }

  function renderHistory(box, events) {
    if (!events.length) {
      box.innerHTML = '<p class="qa-muted">Nenhuma cobrança registrada.</p>';
      return;
    }
    // Lista FINITA e ordenada: <ol>/<li>. Nada de role="feed" (não é fluxo infinito).
    box.innerHTML = `<ol class="qa-timeline">${events.map((ev) => {
      const gestor = escapeHtml(ev.manager?.name || (ev.manager?.id ? `Gestor #${ev.manager.id}` : 'Gestor'));
      const vend = escapeHtml(ev.seller?.name || ev.seller?.id || 'vendedor não identificado');
      return `<li class="qa-timeline-item">
        <time datetime="${escapeHtml(ev.charged_at)}">${fmtInstant(ev.charged_at)}</time>
        <p><strong>${vend}</strong> foi cobrado por <strong>${gestor}</strong></p>
        <p class="qa-muted">Cobrança ${ev.charge_number}${ev.new_step ? ` · passo ${ev.previous_step}→${ev.new_step}` : ''}${
          ev.next_followup_on ? ` · próxima em ${escapeHtml(ev.next_followup_on)}` : ''}</p>
      </li>`;
    }).join('')}</ol>`;
  }

  // ── Cobrança ──────────────────────────────────────────────────────────────────
  async function registerCharge(btn) {
    const orderId = btn.getAttribute('data-qa-charge');
    const expected = Number(btn.getAttribute('data-qa-expected'));
    const orderFollowupId = btn.closest('[data-qa-item]')?.querySelector('[data-qa-history]')?.getAttribute('data-qa-history');
    btn.disabled = true;
    btn.textContent = 'Registrando…';

    try {
      const res = await fetch(API.charge, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          // UUID por operação lógica: retry de rede não vira cobrança dupla.
          'Idempotency-Key': (crypto.randomUUID && crypto.randomUUID())
            || `${Date.now()}-${Math.random().toString(16).slice(2)}`,
          'X-CSRF-Token': document.querySelector('meta[name="biomie-csrf"]')?.content || '',
        },
        body: JSON.stringify({ omie_order_id: orderId, expected_charge_count: expected }),
      });
      const body = await res.json().catch(() => null);

      if (!res.ok) {
        const code = body && body.error;
        alert(code === 'stale_followup_state'
          ? 'A agenda mudou desde que a tela foi carregada. Atualizando…'
          : (body && body.message) || 'Não foi possível registrar a cobrança.');
      }

      // A cobrança NÃO altera o snapshot_hash — recarrega dia, mês, KPIs…
      if (state.selectedDate) await selectDay(state.selectedDate);
      await Promise.all([loadCalendar(true), loadSummary()]);
      // …e reabre o histórico do item cobrado, já com o novo evento.
      const btn2 = document.querySelector(`[data-qa-history="${orderFollowupId}"]`);
      if (btn2 && orderFollowupId) { toggleHistory(btn2); }
    } catch (e) {
      alert('Falha de rede ao registrar a cobrança.');
      btn.disabled = false;
      btn.textContent = 'Cobrança realizada';
    }
  }

  // ── Eventos ───────────────────────────────────────────────────────────────────
  function bind() {
    document.addEventListener('click', (ev) => {
      const cell = ev.target.closest('[data-qa-date]');
      if (cell) { selectDay(cell.getAttribute('data-qa-date')); return; }

      const charge = ev.target.closest('[data-qa-charge]');
      if (charge) { registerCharge(charge); return; }

      const hist = ev.target.closest('[data-qa-history]');
      if (hist) { toggleHistory(hist); return; }

      const copy = ev.target.closest('[data-qa-copy]');
      if (copy) {
        navigator.clipboard?.writeText(copy.getAttribute('data-qa-copy'));
        copy.textContent = 'Copiado!';
        setTimeout(() => { copy.textContent = 'Copiar nº'; }, 1500);
        return;
      }

      if (ev.target.closest('[data-qa-close]')) closePanel();
    });

    $('#qaPrev')?.addEventListener('click', () => shiftMonth(-1));
    $('#qaNext')?.addEventListener('click', () => shiftMonth(1));
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && $('#qaPanel')?.hasAttribute('data-open')) closePanel();
    });
  }

  function shiftMonth(delta) {
    let m = state.month + delta;
    let y = state.year;
    if (m < 1) { m = 12; y -= 1; }
    if (m > 12) { m = 1; y += 1; }
    state.year = y; state.month = m;
    state.selectedDate = null;
    closePanel();
    loadCalendar();
  }

  // ── Boot ──────────────────────────────────────────────────────────────────────
  function init() {
    const root = document.getElementById('quoteAgendaRoot');
    if (!root) return;
    state.canManage = root.getAttribute('data-can-manage') === '1';
    state.canHistory = root.getAttribute('data-can-history') === '1';

    const hoje = todayCivil();
    state.year = Number(hoje.slice(0, 4));
    state.month = Number(hoje.slice(5, 7));

    bind();
    loadCalendar();
    loadSummary();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
