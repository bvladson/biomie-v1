(function () {
  'use strict';

  var allowedPages = new Set([
    'index', 'finance', 'orders', 'products', 'vendors',
    'rota-comercial', 'geografia', 'conciliacao', 'observability'
  ]);

  var translations = {
    'indicadores': 'Resumo do periodo',
    'dashboard comercial': 'Resumo comercial',
    'indicadores orcamento pd': 'Resumo comercial Orcamento-PD',
    'visao visual': 'Leitura rapida',
    'financeiro visual': 'Visao financeira do periodo',
    'composicao da carteira': 'Como esta dividido o dinheiro',
    'evolucao da carteira': 'Movimento do caixa e valores a receber',
    'aging de recebiveis': 'Valores por tempo de atraso',
    'governanca da cobranca': 'Controle da equipe de cobranca',
    'rastreabilidade de recebiveis': 'Origem dos valores a receber',
    'rastreabilidade financeira': 'Origem dos valores financeiros',
    'recebiveis operacional nf pedido vendedor': 'De onde vem os valores a receber',
    'sem nf vinculada': 'Sem nota fiscal identificada',
    'sem vendedor': 'Sem responsavel definido',
    'metodo principal': 'Forma de pagamento mais usada',
    'cobertura nf titulo': 'Notas com financeiro identificado',
    'aging de recebiveis em aberto': 'Valores abertos por tempo de atraso',
    'metodos e canais por produto': 'Como os produtos sao pagos',
    'metodos e canais mais usados para os produtos': 'Formas de pagamento mais usadas por produto',
    'uso geral por metodo canal': 'Formas de pagamento mais escolhidas',
    'metodo canal dominante por produto': 'Forma preferida em cada produto',
    'analises': 'Entenda os resultados',
    'detalhamento': 'Veja os detalhes',
    'painel de governanca da cobranca': 'Acompanhamento da equipe de cobranca',
    'revisoes de vinculo': 'Dados para conferir',
    'acionada': 'Carteira ja contatada',
    '% acionada': 'Carteira ja contatada',
    'produtividade dos operadores': 'Contatos realizados pela equipe',
    'governanca por vendedor': 'Acompanhamento por vendedor',
    'agenda de retornos follow ups': 'Proximos contatos agendados',
    'sla da cobranca por aging': 'Prazo de contato por tempo de atraso',
    'fila de risco prioritaria': 'Clientes para cobrar primeiro',
    'busca de titulos': 'Encontre um recebimento',
    'titulos financeiros': 'Recebimentos e valores a receber',
    'previsto x realizado': 'Quanto esperavamos e quanto entrou',
    'aging buckets': 'Prioridade por tempo de atraso',
    'pipeline orc pd': 'Etapas das vendas Orc-PD',
    'pipeline orcamento pd': 'Etapas das vendas Orcamento-PD',
    'vinculos da leitura fiscal': 'Conferencia das notas fiscais',
    'dados carregados': 'Atualizacao dos dados',
    'top bairros': 'Bairros que mais vendem',
    'semantica comercial dos escopos': 'O que entra em cada resultado',
    'produtos no mix': 'Produtos vendidos',
    '% mix': '% das vendas',
    'score de acao comercial': 'Prioridade comercial',
    'como o score e calculado': 'Como a prioridade e calculada',
    'produtos com score 40': 'Produtos com oportunidade relevante',
    'score 75': 'Alta prioridade comercial',
    'participacao no mix': 'Participacao nas vendas',
    'mix de produtos faturados orcamento pd': 'Produtos que formam o faturamento',
    'risco operacional por giro': 'Produtos com risco de falta',
    'concentracao de cliente unico': 'Produtos dependentes de um cliente',
    'produtos para acao': 'Produtos com oportunidade agora',
    'aderencia vendedor produto': 'Quem vende melhor cada produto',
    'risco operacional': 'Atencao para reposicao',
    'concentrados': 'Dependentes de poucos clientes',
    'parados': 'Sem venda recente',
    'campeoes': 'Produtos que mais vendem',
    'com acao sugerida': 'Oportunidades encontradas',
    'recompra provavel': 'Clientes perto de recomprar',
    'nf orc pd': 'Notas faturadas Orc-PD',
    'conversao': 'Pedidos que viraram nota',
    'backlog': 'Pedidos aguardando faturamento',
    'carteirizacao': 'Clientes sob responsabilidade',
    'meta proporcional': 'Meta do periodo',
    'meta prop': 'Meta do periodo',
    'meta': 'Meta do periodo',
    'realizado por nf': 'Vendas faturadas',
    'realizado nf': 'Vendas faturadas',
    'realizado': 'Vendas faturadas',
    'realizado oficial': 'Vendas faturadas confirmadas',
    'ating': '% da meta',
    'atingimento': 'Percentual da meta',
    'gap proporcional': 'Quanto falta para a meta',
    'gap': 'Quanto falta',
    'projecao': 'Previsao ate o fim do periodo',
    'projecao de fechamento': 'Previsao de fechamento',
    'ritmo diario': 'Vendas por dia',
    'pulso diario': 'Vendas por dia',
    'realizado x meta': 'Vendas versus meta',
    'detalhamento da equipe orcamento pd': 'Resultados da equipe Orcamento-PD',
    'base por vendedor orcamento pd': 'Resultados por vendedor Orcamento-PD',
    'total vendido': 'Faturamento no mapa',
    'bairros com venda': 'Regioes com vendas',
    'melhor bairro': 'Regiao que mais vendeu',
    'sem bairro': 'Clientes sem localizacao',
    'mapa comercial do ceara': 'Onde estamos vendendo no Ceara',
    'ranking de bairros': 'Regioes que mais vendem',
    'principais cnaes': 'Segmentos que mais compram',
    'conciliados': 'Tudo conferido',
    'divergentes': 'Valores para conferir',
    'pedido s nf': 'Pedidos sem nota fiscal',
    'nf s pedido': 'Notas sem pedido',
    'nf s titulo': 'Notas sem cobranca lancada',
    'conciliacao detalhada': 'Conferencia detalhada',
    'processamentos': 'Atualizacoes automaticas',
    'fluxos por dominio': 'Atualizacoes por area',
    'chamadas ao omie': 'Consultas ao Omie',
    'divergencias por regra': 'Diferencas encontradas',
    'tempo de resposta': 'Velocidade do sistema',
    'cobertura das bases': 'Qualidade dos dados',
    'bases acompanhadas': 'Dados acompanhados',
    'atualizacao e completude': 'Bases atualizadas e completas',
    'atualizacao das visoes': 'Atualizacao dos paineis',
    'fontes monitoradas': 'Velocidade por fonte',
    'ocorrencias registradas': 'Alertas recentes',
    'comparativo por origem': 'Onde os numeros ainda diferem',
    'pipeline': 'Etapas das vendas',
    'mix': 'Participacao nas vendas',
    'aging': 'Tempo de atraso',
    'sla': 'Prazo de atendimento',
    'sla %': '% dentro do prazo',
    'score': 'Prioridade',
    'forecast': 'Previsao',
    'follow ups': 'Proximos contatos',
    'governanca': 'Acompanhamento',
    'rastreabilidade': 'Origem dos valores',
    'diagnostico': 'Conferencia dos dados',
    'cobertura': 'Percentual identificado',
    'qualidade do vinculo': 'Qualidade da identificacao',
    'top 10 por score': '10 maiores prioridades',
    'realizado pipeline': 'Vendas faturadas + oportunidades em andamento',
    'faturamento materializado no filtro': 'Faturamento encontrado nos filtros',
    'notas analisadas no mart': 'Notas encontradas no periodo',
    'top 10 por volume outros': 'Produtos mais vendidos e demais produtos',
    'sla de contato vencido': 'Prazo de contato ultrapassado',
    'acionamento e controle de sla da carteira de cada vendedor': 'Contatos e prazos da carteira de cada vendedor',
    'acionamento e cobertura de cobranca por faixa de atraso': 'Contatos realizados conforme o tempo de atraso'
  };

  var help = {
    'vendas faturadas': 'Valor confirmado por notas fiscais autorizadas.',
    'quanto falta para a meta': 'Diferenca entre a meta do periodo e as vendas faturadas.',
    'previsao ate o fim do periodo': 'Estimativa de fechamento usando o ritmo atual e a carteira.',
    'pedidos aguardando faturamento': 'Pedidos aceitos que ainda nao viraram nota fiscal.',
    'clientes sob responsabilidade': 'Clientes atribuidos a cada vendedor.',
    'notas com financeiro identificado': 'Notas fiscais ligadas aos respectivos valores a receber.',
    'carteira ja contatada': 'Percentual dos valores em atraso que ja recebeu uma acao de cobranca.',
    'valores por tempo de atraso': 'Valores pendentes separados pela quantidade de dias de atraso.',
    'etapas das vendas orc pd': 'Etapa atual das oportunidades e pedidos comerciais.',
    'produtos com risco de falta': 'Sinal baseado em vendas recentes; nao representa saldo fisico confirmado.',
    'valores para conferir': 'Registros com diferencas entre pedido, nota fiscal ou financeiro.'
  };

  var textSelector = [
    '.biomie-kpi-card__label', '.section-label', '.chart-card-header h3',
    '.fin-panel-head h2', '.table-card-title', '.table-card-header h3',
    '.finance-visual-card__head h3', '.finance-visual-head h2',
    '.fp-card-header h3', '.wallboard-card-header h3', '.biomie-map-card__head h2',
    '.dashboard-panel__header h3',
    '.chart-card-header small', '.table-card-header small', '.finance-visual-card__head small',
    '.fp-card-header small', '.biomie-kpi-card__sub', '.fin-panel-head p',
    '.finance-visual-head p', '.filter-label', '.detail-drawer-title',
    '.biomie-drawer__tab', '[role="tab"]', 'th', '[data-commercial-language]',
    'h1', 'h2', 'h3', 'h4', 'summary', 'button',
    '.finance-reading-guide strong', '.finance-reading-guide p'
  ].join(',');

  function normalize(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-zA-Z0-9%]+/g, ' ').trim().toLowerCase();
  }

  function categoryFor(value) {
    var text = normalize(value);
    if (/cancel|atras|vencid|risco|diverg|falta|sem nota|sem responsavel|conferir/.test(text)) return 'attention';
    if (/meta|oportun|orcamento|previsao|projecao|etapas das vendas/.test(text)) return 'growth';
    if (/recebid|fatur|vendid|caixa|dinheiro|ticket|pagamento/.test(text)) return 'revenue';
    if (/produto|mercadoria|item|mix/.test(text)) return 'product';
    if (/cliente|vendedor|equipe|responsabilidade|contato/.test(text)) return 'people';
    if (/mapa|bairro|regiao|territorio|localizacao/.test(text)) return 'territory';
    if (/carteira|aberto|pendente|aguardando/.test(text)) return 'pending';
    return 'information';
  }

  function translateTextNode(node) {
    var key = normalize(node.nodeValue);
    if (!key || !translations[key]) return;
    var leading = node.nodeValue.match(/^\s*/)[0];
    var trailing = node.nodeValue.match(/\s*$/)[0];
    node.nodeValue = leading + translations[key] + trailing;
  }

  function translateElement(element) {
    if (!element || element.closest('.mag-technical-diagnostics, .finance-insight-diagnostics, [data-technical-language]')) return;
    Array.from(element.childNodes).forEach(function (node) {
      if (node.nodeType === Node.TEXT_NODE) translateTextNode(node);
    });
    if (!element.children.length) {
      var key = normalize(element.textContent);
      if (translations[key]) element.textContent = translations[key];
    }
  }

  function enhanceCard(card) {
    if (!card || card.dataset.commercialEnhanced === '1') return;
    var label = card.querySelector('.biomie-kpi-card__label');
    if (!label) return;
    card.dataset.commercialEnhanced = '1';
    card.dataset.businessCategory = categoryFor(label.textContent);
    card.classList.add('business-card');
    var helpText = help[normalize(label.textContent)];
    if (helpText) {
      card.dataset.commercialHelp = helpText;
    }
  }

  function enhanceHeading(heading) {
    if (!heading || heading.dataset.commercialHeading === '1') return;
    heading.dataset.commercialHeading = '1';
    heading.dataset.businessCategory = categoryFor(heading.textContent);
    heading.classList.add('business-heading');
    var panel = heading.closest('.chart-card, .fin-panel, .table-card, .fp-card, .wallboard-card, .dashboard-panel, .finance-visual-card');
    if (panel && !panel.closest('.mag-technical-diagnostics, [data-technical-language]')) {
      panel.dataset.businessCategory = categoryFor(heading.textContent);
      panel.classList.add('business-panel');
    }
  }

  function ensureColorGuide() {
    if (document.body.dataset.page === 'finance' || document.querySelector('.business-color-guide')) return;
    var anchor = document.querySelector('.biomie-page-header, .page-header, main > header');
    if (!anchor || !anchor.parentNode) return;
    var guide = document.createElement('details');
    guide.className = 'business-color-guide';
    guide.innerHTML =
      '<summary><span class="material-symbols-rounded">palette</span><strong>Como ler as cores</strong><small>Abra para ver o significado dos cards.</small></summary>' +
      '<div class="business-color-guide__items">' +
        '<span data-guide-tone="revenue">Verde: vendas e dinheiro confirmado</span>' +
        '<span data-guide-tone="growth">Azul: metas e oportunidades</span>' +
        '<span data-guide-tone="pending">Ambar: valores pendentes</span>' +
        '<span data-guide-tone="attention">Vermelho: atencao e risco</span>' +
        '<span data-guide-tone="product">Violeta: produtos</span>' +
        '<span data-guide-tone="people">Teal: clientes e equipe</span>' +
        '<span data-guide-tone="territory">Laranja: regioes</span>' +
      '</div>';
    anchor.insertAdjacentElement('afterend', guide);
  }

  function process(root) {
    var scope = root && root.querySelectorAll ? root : document;
    if (root && root.matches && root.matches(textSelector)) translateElement(root);
    scope.querySelectorAll(textSelector).forEach(translateElement);
    scope.querySelectorAll('.biomie-kpi-card').forEach(enhanceCard);
    scope.querySelectorAll('.section-label, .chart-card-header h3, .fin-panel-head h2, .table-card-header h3, .finance-visual-card__head h3, .fp-card-header h3, .wallboard-card-header h3, .dashboard-panel__header h3').forEach(enhanceHeading);
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!allowedPages.has(document.body.dataset.page || '')) return;
    document.body.classList.add('commercial-language-enabled');
    ensureColorGuide();
    process(document);
    new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(function (node) {
          if (node.nodeType === Node.ELEMENT_NODE) process(node);
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  });
})();
