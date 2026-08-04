/**
 * Painel "Ação de hoje" na home.
 *
 * Mostra os poucos orçamentos que mudam o resultado do dia, já ordenados pelo
 * score e com o MOTIVO de cada um. Sem o motivo, um número na tela não muda o
 * comportamento de ninguém — o vendedor precisa saber por que aquele subiu.
 *
 * PRINCÍPIOS DE EXIBIÇÃO
 *  · O painel FICA ESCONDIDO quando não há nada para agir. Um bloco vazio
 *    ocupando espaço na home é pior que bloco nenhum.
 *  · Falha de rede não mostra erro vermelho aqui: este painel é enriquecimento,
 *    não o conteúdo principal. Ele simplesmente não aparece.
 *  · "Sem pontuação" é dito explicitamente. Se o job ainda não rodou, a home
 *    não pode fingir que está tudo calmo — ausência de cálculo não é ausência
 *    de urgência.
 *  · Acompanha o filtro de vendedor da página: trocar o recorte tem que trocar
 *    esta lista, senão a home mostra prioridade de quem não está sendo olhado.
 */
(function () {
  'use strict';

  var panel = document.getElementById('indexQuotePriority');
  if (!panel) return;

  var listEl = document.getElementById('qpList');
  var countEl = document.getElementById('qpCount');
  var noteEl = document.getElementById('qpNote');
  var openEl = panel.querySelector('.qp-open');

  var pedido = 0; // guarda contra resposta atrasada sobrescrever a mais nova

  function brl(v) {
    try {
      return Number(v || 0).toLocaleString('pt-BR', {
        style: 'currency', currency: 'BRL', maximumFractionDigits: 0
      });
    } catch (e) { return 'R$ ' + (v || 0); }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function sellerParams() {
    try {
      var p = new URLSearchParams(window.location.search || '');
      var ids = p.get('vendor_ids') || p.get('vendor_id')
        || (window.NavContext && (window.NavContext.get('vendor_ids') || window.NavContext.get('vendor_id')))
        || '';
      return String(ids || '').trim();
    } catch (e) { return ''; }
  }

  function itemHtml(q) {
    var href = 'quote_agenda.php?omie_order_id=' + encodeURIComponent(q.omie_order_id);
    var ids = sellerParams();
    if (ids) href += '&vendor_ids=' + encodeURIComponent(ids) + '&seller_ids=' + encodeURIComponent(ids);

    var reasons = (q.reasons || []).map(function (r) {
      return '<span class="qp-reason">' + esc(r) + '</span>';
    }).join('');

    // Valor ausente é dito, não escondido: "R$ 0" seria mentira.
    var valor = (q.value === null || q.value === undefined)
      ? '<span class="qp-value qp-value--unknown">sem valor</span>'
      : '<span class="qp-value">' + esc(brl(q.value)) + '</span>';

    return '<li>'
      + '<a class="qp-item" href="' + esc(href) + '">'
      + '<span class="qp-score" data-band="' + esc(q.band) + '">' + Math.round(q.score) + '</span>'
      + '<span class="qp-main">'
      + '<span class="qp-customer">' + esc(q.customer || ('Pedido ' + q.numero_pedido)) + '</span>'
      + '<span class="qp-reasons">' + reasons + '</span>'
      + '</span>'
      + valor
      + '</a></li>';
  }

  function render(data) {
    var topo = (data && data.topo) || [];
    var banda = (data && data.por_banda) || {};
    var acionaveis = (banda.alta || 0) + (banda.media || 0);

    // Nada para agir ⇒ o painel some. Bloco vazio na home é ruído.
    if (!topo.length || acionaveis === 0) {
      panel.hidden = true;
      return;
    }

    // Só entram os que pedem ação; "baixa" na lista diluiria o painel.
    var mostrar = topo.filter(function (q) { return q.band !== 'baixa'; });
    if (!mostrar.length) { panel.hidden = true; return; }

    listEl.innerHTML = mostrar.map(itemHtml).join('');

    var alta = banda.alta || 0;
    countEl.textContent = alta > 0
      ? alta + (alta > 1 ? ' urgentes' : ' urgente') + ' de ' + data.total_abertos + ' em aberto'
      : acionaveis + ' para acompanhar de ' + data.total_abertos + ' em aberto';

    if (data.sem_pontuacao > 0) {
      noteEl.textContent = data.sem_pontuacao + ' orçamento(s) ainda sem pontuação — '
        + 'o cálculo de prioridade roda de 2 em 2 horas.';
      noteEl.hidden = false;
    } else {
      noteEl.hidden = true;
    }

    var ids = sellerParams();
    if (openEl) {
      openEl.setAttribute('href', ids
        ? 'quote_agenda.php?vendor_ids=' + encodeURIComponent(ids) + '&seller_ids=' + encodeURIComponent(ids)
        : 'quote_agenda.php');
    }

    panel.hidden = false;
  }

  function load() {
    var meu = ++pedido;
    var url = 'api/quote_priority_summary.php?limit=5';
    var ids = sellerParams();
    if (ids) url += '&vendor_ids=' + encodeURIComponent(ids) + '&seller_ids=' + encodeURIComponent(ids);

    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (json) {
        if (meu !== pedido) return;           // chegou tarde: já há pedido mais novo
        if (json && json.ok) render(json.data);
        else panel.hidden = true;
      })
      .catch(function () {
        // Enriquecimento não derruba a home nem pinta erro: só não aparece.
        if (meu === pedido) panel.hidden = true;
      });
  }

  load();

  // O filtro de vendedor da página muda o recorte — a prioridade tem que seguir.
  document.addEventListener('biomie:dashboard-filtered', load);
})();
