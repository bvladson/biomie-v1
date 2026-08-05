/**
 * Painel "Leitura da operação" na home.
 *
 * Mostra as conclusões que os números já permitem tirar — calculadas em Python,
 * fora do request, e guardadas com prazo de validade.
 *
 * PRINCÍPIOS
 *  · Some quando não há leitura. A faixa é um grid auto-fit: com um painel só,
 *    o irmão ocupa a largura toda. É assim que o espaço vazio deixa de existir
 *    em vez de virar buraco.
 *  · Toda frase carrega os números que a sustentam, recolhidos num <details>.
 *    Quem quer a conclusão lê a frase; quem desconfia confere a conta.
 *  · Falha de rede não pinta erro — o bloco simplesmente não aparece.
 */
(function () {
  'use strict';

  var panel = document.getElementById('indexInsights');
  if (!panel) return;

  var listEl = document.getElementById('qiList');
  var stampEl = document.getElementById('qiStamp');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var ROTULOS = {
    receita_recente: 'Últimas 4 semanas', receita_anterior: '4 semanas antes',
    variacao: 'Variação', notas_recente: 'Notas (recente)', notas_anterior: 'Notas (antes)',
    cliente: 'Cliente', share: 'Participação', receita_cliente: 'Receita do cliente',
    receita_total: 'Receita total', clientes_no_periodo: 'Clientes no período',
    realizado: 'Realizado', dias_corridos: 'Dias corridos', dias_no_mes: 'Dias no mês',
    projecao: 'Projeção', vendedor: 'Vendedor', antes: 'Antes', depois: 'Depois',
    queda_relativa: 'Queda', meta: 'Meta do mês', faixa_min: 'Faixa mínima',
    faixa_max: 'Faixa máxima', risco_nao_bater: 'Risco de não bater a meta',
    erro_historico: 'Erro histórico', dados_usados: 'Registros usados',
    meses_historicos: 'Meses históricos', fatores: 'Principais fatores',
    ultima_atualizacao: 'Última atualização', dimensao: 'Dimensão', entidade: 'Entidade'
  };

  var MONETARIOS = /^(receita|realizado|projecao|antes|depois|meta|faixa_)/;
  var PERCENTUAIS = /^(variacao|share|queda_relativa|risco_nao_bater|erro_historico|crescimento)$/;

  function fmt(chave, valor) {
    if (valor === null || valor === undefined) return '—';
    if (typeof valor === 'number') {
      if (PERCENTUAIS.test(chave)) return (valor * 100).toFixed(0) + '%';
      if (MONETARIOS.test(chave)) {
        try {
          return valor.toLocaleString('pt-BR', {
            style: 'currency', currency: 'BRL', maximumFractionDigits: 0
          });
        } catch (e) { return 'R$ ' + Math.round(valor); }
      }
      return String(Math.round(valor * 100) / 100);
    }
    if (Array.isArray(valor)) return valor.join(' · ');
    return String(valor);
  }

  function metricasHtml(m) {
    var chaves = Object.keys(m || {});
    if (!chaves.length) return '';
    var celulas = chaves.map(function (k) {
      return '<div><span class="qi-metric-k">' + esc(ROTULOS[k] || k) + ':</span> '
        + '<span class="qi-metric-v">' + esc(fmt(k, m[k])) + '</span></div>';
    }).join('');
    return '<details class="qi-metrics"><summary>Como foi calculado</summary>'
      + '<div class="qi-metrics-grid">' + celulas + '</div></details>';
  }

  function itemHtml(i) {
    var link = i.link_url
      ? '<a class="qi-link" href="' + esc(i.link_url) + '">Ver detalhe →</a>'
      : '';
    return '<li class="qi-item" data-sev="' + esc(i.severity) + '">'
      + '<span class="qi-item-title">' + esc(i.title) + '</span>'
      + '<span class="qi-item-body">' + esc(i.body) + '</span>'
      + metricasHtml(i.metrics)
      + link
      + '</li>';
  }

  function quandoTexto(iso) {
    if (!iso) return '';
    try {
      var d = new Date(iso.replace(' ', 'T') + 'Z');
      var min = Math.round((Date.now() - d.getTime()) / 60000);
      if (min < 2) return 'agora';
      if (min < 60) return 'há ' + min + ' min';
      var h = Math.round(min / 60);
      return 'há ' + h + (h > 1 ? ' horas' : ' hora');
    } catch (e) { return ''; }
  }

  fetch('api/insights_summary.php', {
    credentials: 'same-origin', headers: { 'Accept': 'application/json' }
  })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (json) {
      var itens = json && json.ok ? (json.data.insights || []) : [];
      if (!itens.length) { panel.hidden = true; return; }
      listEl.innerHTML = itens.map(itemHtml).join('');
      if (stampEl) stampEl.textContent = quandoTexto(itens[0].computed_at);
      panel.hidden = false;
    })
    .catch(function () { panel.hidden = true; });
})();
