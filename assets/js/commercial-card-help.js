(function () {
  'use strict';

  var portalId = 'businessCardHelpPopover';
  var drawerId = 'businessCardHelpDrawer';
  var backdropId = 'businessCardHelpBackdrop';
  var page = document.body ? (document.body.dataset.page || 'geral') : 'geral';
  var openTrigger = null;
  var drawerTrigger = null;
  var pinned = false;
  var showTimer = 0;
  var hideTimer = 0;
  var copyTimer = 0;

  var categoryMeta = {
    revenue: { label: 'Resultado confirmado', icon: 'paid' },
    growth: { label: 'Meta e oportunidade', icon: 'trending_up' },
    pending: { label: 'Valor pendente', icon: 'pending_actions' },
    attention: { label: 'Ponto de atenção', icon: 'error' },
    product: { label: 'Produto e mercadoria', icon: 'inventory_2' },
    people: { label: 'Cliente e equipe', icon: 'groups' },
    territory: { label: 'Região e território', icon: 'location_on' },
    information: { label: 'Leitura do resultado', icon: 'insights' }
  };

  var catalog = {
    'vendas faturadas': {
      summary: 'Valor confirmado pelas notas fiscais autorizadas no período.',
      read: 'Use este número como a principal referência do faturamento realizado.',
      scope: 'Considera o período e os filtros ativos.',
      icon: 'paid', tone: 'revenue'
    },
    'vendas faturadas confirmadas': {
      summary: 'Valor de vendas já confirmado pela emissão fiscal.',
      read: 'Representa receita realizada, diferente de pedidos ainda em carteira.',
      icon: 'verified', tone: 'revenue'
    },
    'faturamento orc pd': {
      summary: 'Base comercial das notas fiscais classificadas como Orçamento-PD.',
      read: 'Mostra quanto foi efetivamente faturado neste canal comercial.',
      scope: 'Inclui varejo e licitação; o filtro de operação isola um dos canais. Remessas e intra-grupo ficam fora.',
      icon: 'receipt_long', tone: 'revenue'
    },
    'notas faturadas orc pd': {
      summary: 'Quantidade ou valor das notas autorizadas do canal Orçamento-PD.',
      read: 'Ajuda a entender o volume de documentos que formam o faturamento.',
      icon: 'receipt_long', tone: 'revenue'
    },
    'nfs orc pd': {
      summary: 'Notas fiscais autorizadas que formam o resultado Orçamento-PD.',
      read: 'Compare com o faturamento para entender volume e valor médio.',
      icon: 'receipt_long', tone: 'revenue'
    },
    'venda media orc pd': {
      summary: 'Valor médio faturado por nota fiscal Orçamento-PD.',
      read: 'Um valor maior indica vendas médias mais relevantes por documento.',
      icon: 'sell', tone: 'revenue'
    },
    'ticket medio orc pd': {
      summary: 'Valor médio das notas fiscais Orçamento-PD no recorte atual.',
      read: 'Use junto da quantidade de notas para avaliar qualidade do faturamento.',
      icon: 'sell', tone: 'revenue'
    },
    'clientes': {
      summary: 'Quantidade de clientes presentes no resultado filtrado.',
      read: 'Ajuda a medir alcance comercial e concentração do resultado.',
      icon: 'groups', tone: 'people'
    },
    'vendedores': {
      summary: 'Quantidade de vendedores com participação no resultado.',
      read: 'Use para avaliar cobertura e distribuição do desempenho da equipe.',
      icon: 'person_play', tone: 'people'
    },
    'canceladas': {
      summary: 'Documentos ou valores cancelados no período selecionado.',
      read: 'Quanto maior, maior a perda potencial que merece investigação.',
      icon: 'cancel', tone: 'attention'
    },
    'carteira aceita': {
      summary: 'Pedidos aceitos que ainda aguardam faturamento.',
      read: 'É uma oportunidade futura e ainda não compõe as vendas faturadas.',
      icon: 'inbox', tone: 'pending'
    },
    'carteira': {
      summary: 'Valor comercial ainda em andamento no recorte atual.',
      read: 'Interprete como potencial futuro, separado do resultado já faturado.',
      icon: 'pending_actions', tone: 'pending'
    },
    'pedidos em carteira': {
      summary: 'Quantidade de pedidos aceitos que ainda aguardam faturamento.',
      read: 'Use para acompanhar o trabalho que pode virar venda faturada.',
      scope: 'Não entra nas vendas faturadas enquanto a nota fiscal não for emitida.',
      icon: 'pending_actions', tone: 'pending'
    },
    'orcamentos': {
      summary: 'Propostas comerciais registradas antes da confirmação da venda.',
      read: 'Mostra o volume de oportunidades que pode avançar para pedido.',
      icon: 'description', tone: 'growth'
    },
    'mercadoria orc pd': {
      summary: 'Valor dos itens vendidos nas notas fiscais Orçamento-PD.',
      read: 'Mostra o peso das mercadorias antes de outros componentes comerciais.',
      icon: 'inventory_2', tone: 'product'
    },
    'produtos vendidos': {
      summary: 'Quantidade de produtos diferentes presentes nas vendas.',
      read: 'Use para acompanhar variedade e amplitude do portfólio vendido.',
      icon: 'category', tone: 'product'
    },
    'produtos distintos': {
      summary: 'Quantidade de produtos únicos encontrados no recorte.',
      read: 'Um número maior indica maior variedade de itens movimentados.',
      icon: 'category', tone: 'product'
    },
    'qtd vendida': {
      summary: 'Soma das unidades de produtos vendidas no período.',
      read: 'Mostra volume físico comercial, não o valor do faturamento.',
      icon: 'shopping_bag', tone: 'product'
    },
    'descontos': {
      summary: 'Valor de descontos concedidos nas vendas do período.',
      read: 'Compare com o faturamento para acompanhar impacto sobre a receita.',
      icon: 'money_off', tone: 'attention'
    },
    'atencao para reposicao': {
      summary: 'Produtos com sinal de giro que pode exigir reposição.',
      read: 'É um alerta comercial baseado em movimentação, não saldo físico confirmado.',
      icon: 'warning', tone: 'attention'
    },
    'ultima venda': {
      summary: 'Data da venda mais recente encontrada para o produto.',
      read: 'Quanto mais antiga, maior o sinal de baixa movimentação.',
      icon: 'schedule', tone: 'product'
    },
    'recorrentes': {
      summary: 'Produtos ou clientes com repetição de compra identificada.',
      read: 'Indica comportamento consistente que pode apoiar ações de retenção.',
      icon: 'repeat', tone: 'growth'
    },
    'dependentes de poucos clientes': {
      summary: 'Produtos cuja receita está concentrada em poucos compradores.',
      read: 'Concentração alta aumenta o impacto da perda de um cliente.',
      icon: 'person', tone: 'attention'
    },
    'sem venda recente': {
      summary: 'Produtos sem movimentação comercial recente.',
      read: 'Use para revisar estoque, exposição ou estratégia comercial.',
      icon: 'pause_circle', tone: 'attention'
    },
    'produtos que mais vendem': {
      summary: 'Produtos com maior contribuição para as vendas do período.',
      read: 'Mostra os itens que sustentam maior parcela do resultado.',
      icon: 'star', tone: 'product'
    },
    'oportunidades encontradas': {
      summary: 'Produtos ou clientes com ação comercial sugerida.',
      read: 'Priorize os itens com melhor combinação de potencial e urgência.',
      icon: 'rocket_launch', tone: 'growth'
    },
    'clientes perto de recomprar': {
      summary: 'Clientes próximos do intervalo esperado para uma nova compra.',
      read: 'Use como lista de contato para antecipar a próxima venda.',
      icon: 'refresh', tone: 'growth'
    },
    'meta do periodo': {
      summary: 'Parte da meta correspondente ao período selecionado.',
      read: 'Compare com vendas faturadas para acompanhar o avanço real.',
      icon: 'flag', tone: 'growth'
    },
    'metas pendentes': {
      summary: 'Vendedores com vendas ou carteira no período, mas ainda sem meta definida.',
      read: 'Regularize primeiro esses casos para que o ritmo e a previsão da equipe fiquem completos.',
      scope: 'Conta somente vendedores com atividade comercial encontrada no período.',
      icon: 'notification_important', tone: 'attention'
    },
    'sem vendas': {
      summary: 'Vendedores com meta definida que ainda não possuem venda faturada no período.',
      read: 'Use como fila de acompanhamento para entender bloqueios e priorizar apoio.',
      scope: 'Vendedores com meta pendente aparecem separados no indicador Metas pendentes.',
      icon: 'person_alert', tone: 'attention'
    },
    '% da meta': {
      summary: 'Percentual da meta do período já alcançado.',
      read: '100% significa que a meta proporcional foi atingida.',
      icon: 'track_changes', tone: 'growth'
    },
    'percentual da meta': {
      summary: 'Percentual da meta do período já alcançado.',
      read: '100% significa que a meta proporcional foi atingida.',
      icon: 'track_changes', tone: 'growth'
    },
    'quanto falta para a meta': {
      summary: 'Diferença entre a meta do período e as vendas faturadas.',
      read: 'Quanto menor, mais próximo o resultado está da meta.',
      icon: 'route', tone: 'growth'
    },
    'quanto falta': {
      summary: 'Valor que ainda precisa ser realizado para alcançar a meta.',
      read: 'Zero indica que o objetivo selecionado já foi atingido.',
      icon: 'route', tone: 'growth'
    },
    'previsao de fechamento': {
      summary: 'Estimativa de vendas até o encerramento do período.',
      read: 'Compare com a meta para antecipar necessidade de ação comercial.',
      scope: 'É uma projeção baseada nos dados atuais, não um resultado confirmado.',
      icon: 'query_stats', tone: 'growth'
    },
    'previsao ate o fim do periodo': {
      summary: 'Estimativa de vendas até o encerramento do período.',
      read: 'Compare com a meta para antecipar necessidade de ação comercial.',
      scope: 'É uma projeção baseada nos dados atuais, não um resultado confirmado.',
      icon: 'query_stats', tone: 'growth'
    },
    'acima da meta': {
      summary: 'Vendedores que já alcançaram ou superaram a meta proporcional.',
      read: 'Mostra onde o ritmo comercial está saudável.',
      icon: 'trending_up', tone: 'revenue'
    },
    'abaixo da meta': {
      summary: 'Vendedores que ainda não alcançaram a meta proporcional.',
      read: 'Use para direcionar acompanhamento e apoio comercial.',
      icon: 'trending_down', tone: 'attention'
    },
    'pedidos aguardando faturamento': {
      summary: 'Pedidos aceitos que ainda não viraram nota fiscal.',
      read: 'Representam potencial futuro e ainda não entram nas vendas faturadas.',
      icon: 'pending_actions', tone: 'pending'
    },
    'pedidos sem nota fiscal': {
      summary: 'Pedidos encontrados sem nota fiscal de faturamento vinculada.',
      read: 'Use para acompanhar pendências ou conferir o vínculo documental.',
      icon: 'receipt_long', tone: 'attention'
    },
    'pedidos que viraram nota': {
      summary: 'Percentual de pedidos que avançaram para faturamento.',
      read: 'Percentuais maiores indicam melhor conversão em receita confirmada.',
      icon: 'published_with_changes', tone: 'growth'
    },
    'fora da carteira': {
      summary: 'Vendas feitas por responsável diferente do dono da carteira do cliente.',
      read: 'Use para revisar atribuição comercial e possíveis conflitos.',
      icon: 'group_off', tone: 'attention'
    },
    'a receber': {
      summary: 'Total de valores financeiros ainda pendentes de recebimento.',
      read: 'Reúne valores vencidos e valores que ainda vão vencer.',
      icon: 'account_balance', tone: 'pending'
    },
    'vencido': {
      summary: 'Valor que passou da data de vencimento e ainda está aberto.',
      read: 'Quanto maior, maior a prioridade de cobrança e risco financeiro.',
      icon: 'warning', tone: 'attention'
    },
    'a vencer': {
      summary: 'Valor aberto cuja data de vencimento ainda não chegou.',
      read: 'Mostra entradas financeiras esperadas para os próximos períodos.',
      icon: 'event_available', tone: 'pending'
    },
    'recebido no mes': {
      summary: 'Dinheiro efetivamente recebido durante o mês.',
      read: 'Use como referência de entrada realizada no caixa.',
      icon: 'payments', tone: 'revenue'
    },
    'clientes em atraso': {
      summary: 'Quantidade de clientes com ao menos um valor vencido.',
      read: 'Ajuda a medir alcance da inadimplência, além do valor total.',
      icon: 'groups', tone: 'attention'
    },
    'maior atraso': {
      summary: 'Maior quantidade de dias de atraso encontrada no recorte.',
      read: 'Atrasos antigos normalmente exigem prioridade e abordagem específica.',
      icon: 'timer', tone: 'attention'
    },
    'nfs com recebiveis em aberto': {
      summary: 'Notas fiscais que ainda possuem valores financeiros pendentes.',
      read: 'Use para ligar o faturamento à cobrança que ainda precisa acontecer.',
      icon: 'receipt_long', tone: 'pending'
    },
    'recebiveis em aberto': {
      summary: 'Valores a receber ainda não liquidados.',
      read: 'Mostra o tamanho da carteira financeira pendente.',
      icon: 'payments', tone: 'pending'
    },
    'recebiveis vencidos': {
      summary: 'Valores a receber cuja data de vencimento já passou.',
      read: 'É a parcela da carteira que exige atenção de cobrança.',
      icon: 'warning_amber', tone: 'attention'
    },
    'sem nota fiscal identificada': {
      summary: 'Valores financeiros sem uma nota fiscal vinculada.',
      read: 'Use para conferir a origem do recebível e melhorar a rastreabilidade.',
      icon: 'link_off', tone: 'attention'
    },
    'sem responsavel definido': {
      summary: 'Registros sem vendedor ou responsável comercial identificado.',
      read: 'A ausência dificulta cobrança, comissão e acompanhamento.',
      icon: 'person_off', tone: 'attention'
    },
    'forma de pagamento mais usada': {
      summary: 'Método ou canal de pagamento mais frequente no recorte.',
      read: 'Ajuda a entender a preferência dos clientes e produtos.',
      icon: 'credit_card', tone: 'information'
    },
    '% vencido': {
      summary: 'Percentual da carteira aberta que já está vencida.',
      read: 'Quanto maior, maior a exposição financeira que exige cobrança.',
      icon: 'percent', tone: 'attention'
    },
    'cobrados hoje': {
      summary: 'Quantidade de ações de cobrança registradas hoje.',
      read: 'Use para acompanhar atividade diária da equipe.',
      icon: 'call', tone: 'people'
    },
    'promessas de pagamento': {
      summary: 'Compromissos de pagamento registrados durante a cobrança.',
      read: 'Acompanhe os vencimentos para confirmar se os acordos foram cumpridos.',
      icon: 'handshake', tone: 'growth'
    },
    'dados para conferir': {
      summary: 'Registros cujo vínculo de origem precisa ser revisado.',
      read: 'Corrigir esses casos melhora a confiança dos relatórios.',
      icon: 'find_in_page', tone: 'attention'
    },
    'sem acao de cobranca': {
      summary: 'Valores abertos sem contato de cobrança registrado.',
      read: 'Use como fila de trabalho para reduzir pendências sem acompanhamento.',
      icon: 'running_with_errors', tone: 'attention'
    },
    'retornos hoje': {
      summary: 'Contatos de cobrança agendados para hoje.',
      read: 'Use como agenda operacional da equipe financeira.',
      icon: 'today', tone: 'people'
    },
    'retornos atrasados': {
      summary: 'Contatos agendados cuja data prevista já passou.',
      read: 'Priorize para recuperar compromissos de acompanhamento.',
      icon: 'history', tone: 'attention'
    },
    'promessas vencidas': {
      summary: 'Promessas de pagamento não cumpridas até a data combinada.',
      read: 'Esses casos exigem retomada rápida da cobrança.',
      icon: 'running_with_errors', tone: 'attention'
    },
    'vencidos sem acao': {
      summary: 'Valores vencidos sem histórico de cobrança registrado.',
      read: 'É uma fila prioritária para iniciar contato.',
      icon: 'report_problem', tone: 'attention'
    },
    'carteira ja contatada': {
      summary: 'Percentual da carteira vencida que já recebeu ação de cobrança.',
      read: 'Percentuais maiores indicam melhor cobertura do trabalho da equipe.',
      icon: 'percent', tone: 'people'
    },
    'tudo conferido': {
      summary: 'Registros conciliados sem diferença relevante entre as fontes.',
      read: 'Representa documentos cuja relação está consistente.',
      icon: 'verified', tone: 'revenue'
    },
    'valores para conferir': {
      summary: 'Registros com diferença entre pedido, nota fiscal ou financeiro.',
      read: 'Use como fila de revisão para corrigir divergências.',
      icon: 'rule', tone: 'attention'
    },
    'notas sem pedido': {
      summary: 'Notas fiscais sem pedido comercial correspondente identificado.',
      read: 'Use para conferir a origem da venda e completar o vínculo.',
      icon: 'link_off', tone: 'attention'
    },
    'notas sem cobranca lancada': {
      summary: 'Notas fiscais sem título financeiro correspondente identificado.',
      read: 'Pode indicar valor ainda não lançado para recebimento.',
      icon: 'request_quote', tone: 'attention'
    },
    'valor divergente': {
      summary: 'Diferença de valor identificada entre documentos relacionados.',
      read: 'Revise os documentos para entender e corrigir a diferença.',
      icon: 'difference', tone: 'attention'
    },
    'parcelados': {
      summary: 'Documentos financeiros divididos em mais de uma parcela.',
      read: 'Considere as parcelas ao comparar nota fiscal e recebimentos.',
      icon: 'splitscreen', tone: 'information'
    },
    'total': {
      summary: 'Quantidade ou valor total encontrado no recorte atual.',
      read: 'Use como referência geral antes de analisar os grupos detalhados.',
      icon: 'functions', tone: 'information'
    },
    'pendencias reais': {
      summary: 'Diferenças que permanecem relevantes após as regras de conciliação.',
      read: 'Priorize estes casos porque exigem revisão operacional.',
      icon: 'priority_high', tone: 'attention'
    },
    'titulo nao lancado': {
      summary: 'Nota fiscal sem cobrança financeira lançada.',
      read: 'Verifique se o recebível precisa ser criado ou vinculado.',
      icon: 'post_add', tone: 'attention'
    },
    'pagamento a vista': {
      summary: 'Documentos identificados como pagamento em parcela única.',
      read: 'Ajuda a separar operações à vista das vendas parceladas.',
      icon: 'payments', tone: 'revenue'
    },
    'base comercial': {
      summary: 'Valor comercial usado como referência para comparação dos documentos.',
      read: 'Use para conferir se pedido, nota e financeiro estão coerentes.',
      icon: 'calculate', tone: 'information'
    },
    'faturamento no mapa': {
      summary: 'Valor faturado distribuído pelas regiões identificadas.',
      read: 'Use para comparar força comercial entre territórios.',
      icon: 'payments', tone: 'territory'
    },
    'regioes com vendas': {
      summary: 'Quantidade de regiões que registraram vendas no período.',
      read: 'Mostra a amplitude territorial alcançada.',
      icon: 'pin_drop', tone: 'territory'
    },
    'regiao que mais vendeu': {
      summary: 'Região com maior faturamento no período selecionado.',
      read: 'Use como referência de força e concentração territorial.',
      icon: 'workspace_premium', tone: 'territory'
    },
    'clientes sem localizacao': {
      summary: 'Clientes cujos dados não permitiram identificar uma região.',
      read: 'Corrigir endereços melhora a confiança das análises geográficas.',
      icon: 'location_off', tone: 'attention'
    }
  };

  var pageCatalog = {
    'finance:carteira': {
      summary: 'Valores financeiros ainda abertos para recebimento.',
      read: 'Separe vencido de a vencer para definir prioridade e previsão de caixa.',
      icon: 'account_balance_wallet', tone: 'pending'
    },
    'orders:carteira': {
      summary: 'Pedidos aceitos que aguardam a emissão da nota fiscal.',
      read: 'É potencial de faturamento futuro e não entra no realizado.',
      icon: 'pending_actions', tone: 'pending'
    },
    'rota-comercial:carteira': {
      summary: 'Potencial comercial disponível para apoiar o alcance da meta.',
      read: 'Compare com quanto falta para avaliar cobertura da meta.',
      icon: 'work_history', tone: 'growth'
    },

    'rota-comercial:meta do periodo': {
      summary: 'Meta mensal convertida em meta proporcional ao período selecionado, ponderada pelo tipo de cada dia.',
      read: 'Quanto mais dias úteis no intervalo, maior a fatia da meta. Compare com Vendas faturadas para saber se o ritmo é suficiente.',
      scope: 'Seg–Sex peso 1,0 · Sábado peso 0,33 · Domingo peso 0,0. Fórmula: Σ (Meta mensal × peso_intervalo ÷ peso_total_mês).',
      action: 'Altere o período nos filtros para ver como a meta proporcional cresce ou diminui com diferentes intervalos.',
      icon: 'flag', tone: 'growth'
    },
    'rota-comercial:vendas faturadas': {
      summary: 'Soma das notas fiscais autorizadas no período. É o faturamento comercial efetivamente confirmado.',
      read: 'Principal número de resultado. Compara diretamente com a Meta do período para medir atingimento real.',
      scope: 'Usa data de competência fiscal. Inclui varejo e licitação; exclui Remessa, intra-grupo e Entrega Finalizada.',
      action: 'Clique em um vendedor na tabela para ver quais notas formam o faturamento dele.',
      icon: 'paid', tone: 'revenue'
    },
    'rota-comercial:quanto falta para a meta': {
      summary: 'Diferença entre a Meta do período e as Vendas faturadas. Zero quando a meta já foi atingida.',
      read: 'Indica quanto ainda precisa ser faturado. Compare com Pedidos em carteira para ver se o gap pode ser coberto.',
      scope: 'Calculado como MAX(meta – realizado, 0). Nunca exibe valor negativo mesmo quando o realizado supera a meta.',
      action: 'Ordene a tabela por "Quanto falta" para identificar quem está mais crítico e priorizar ação comercial.',
      icon: 'route', tone: 'growth'
    },
    'rota-comercial:previsao de fechamento': {
      summary: 'Estimativa de faturamento até o fim do período com base no ritmo atual e nos pedidos em carteira.',
      read: 'Se a previsão superar a meta, o cenário é positivo. Abaixo de 70% da meta é sinal crítico que exige ação imediata.',
      scope: 'Fórmula: (realizado ÷ peso_dias_decorridos × peso_total) + (carteira_aceita × 80%). Risco crítico < 70% · atenção < 95% · no_track ≥ 95%.',
      action: 'Use junto de "Quanto falta" e "Pedidos em carteira" para decidir quais vendedores precisam de apoio urgente.',
      icon: 'query_stats', tone: 'growth'
    },
    'rota-comercial:pedidos em carteira': {
      summary: 'Valor total dos pedidos aceitos que ainda aguardam emissão de nota fiscal.',
      read: 'Representa potencial de faturamento futuro. Cada pedido entra na Previsão de fechamento com peso de 80%.',
      scope: 'Pedidos com status aceito ou autorizado sem nota fiscal emitida. Orçamentos (etapa 10) não são carteira — ficam separados.',
      action: 'Pedidos de alto valor na carteira podem cobrir o gap da meta. Priorize faturá-los antes do encerramento do período.',
      icon: 'pending_actions', tone: 'pending'
    },
    'rota-comercial:sem vendas': {
      summary: 'Quantidade de vendedores com meta cadastrada no período que ainda não possuem nenhuma nota fiscal emitida.',
      read: 'São os casos que mais precisam de acompanhamento imediato. Cada um representa meta e previsão zeradas.',
      scope: 'Conta apenas vendedores com meta em biomie_sales_targets_monthly e realizado = R$ 0 no período. Vendedores sem meta aparecem em Metas pendentes.',
      action: 'Use o filtro rápido "Sem venda" para ver a lista e priorizar contato comercial com cada um.',
      icon: 'person_alert', tone: 'attention'
    },
    'rota-comercial:metas pendentes': {
      summary: 'Vendedores que geraram vendas ou carteira no período mas ainda não têm meta cadastrada.',
      read: 'Sem meta, o sistema não calcula atingimento nem previsão para estes vendedores. O resultado geral fica incompleto.',
      scope: 'Conta vendedores com ao menos uma NF ou pedido no período sem entrada em biomie_sales_targets_monthly.',
      action: 'Clique em "Editar metas" no topo da página para cadastrar as metas pendentes e completar o quadro.',
      icon: 'notification_important', tone: 'attention'
    },
    'rota-comercial:acima da meta': {
      summary: 'Vendedores que já atingiram ou superaram a meta proporcional do período.',
      read: 'Indica ritmo comercial saudável. Use para reconhecer desempenho e entender o que está funcionando bem.',
      scope: 'Atingimento ≥ 100% da meta proporcional. Visível quando a visualização por atingimento está expandida.',
      icon: 'trending_up', tone: 'revenue'
    },
    'rota-comercial:abaixo da meta': {
      summary: 'Vendedores com venda no período que ainda não atingiram a meta proporcional.',
      read: 'É o grupo com maior potencial de mudar o resultado geral. Priorize os que estão entre 70% e 99% da meta.',
      scope: 'Atingimento entre 1% e 99% da meta proporcional. Visível quando a visualização por atingimento está expandida.',
      icon: 'trending_down', tone: 'attention'
    },
    'rota-comercial:licitacao': {
      summary: 'Faturamento do canal Licitação, separado do escopo Orç-PD padrão.',
      read: 'Este valor não entra nas metas comerciais nem no realizado da visão padrão. É um canal com ciclo e tributação próprios.',
      scope: 'Excluído do escopo comercial por BiVisibilityScope. Exibido aqui apenas para contexto sobre a movimentação total.',
      icon: 'gavel', tone: 'information'
    },
    'rota-comercial:remessa': {
      summary: 'Movimentação fiscal de remessa — operação logística ou fiscal que não configura venda comercial.',
      read: 'Remessas não entram no faturamento comercial nem nas metas. São operações de transferência entre estoques.',
      scope: 'Excluída do escopo comercial padrão. Aparece aqui apenas para transparência sobre a movimentação fiscal total.',
      icon: 'local_shipping', tone: 'information'
    },
    'rota-comercial:entrega finalizada': {
      summary: 'Registros classificados como etapa 60 (pós-venda / entrega finalizada) no Omie.',
      read: 'Não duplica faturamento — é uma etapa operacional posterior à NF já emitida. Exibido para contexto operacional.',
      scope: 'Etapa 60 no Omie indica conclusão do ciclo pós-venda. Não representa uma nova venda nem nova meta.',
      icon: 'verified', tone: 'information'
    },
    'rota-comercial:orcamentos': {
      summary: 'Propostas comerciais na etapa 10 (orçamento), antes de virar pedido confirmado ou nota fiscal.',
      read: 'Orçamentos mostram o pipeline inicial mas são incertos. Não entram no realizado nem na carteira aceita.',
      scope: 'Etapa 10 no Omie. Para entrar na carteira, o orçamento precisa ser aceito e convertido em pedido (etapa seguinte).',
      icon: 'description', tone: 'growth'
    },
    'rota-comercial:ritmo diario de vendas': {
      summary: 'Evolução diária das vendas acumuladas comparada com a meta proporcional dia a dia.',
      read: 'Quando a linha de vendas fica acima da meta projetada, o ritmo é positivo. Quando cai abaixo, há gap a recuperar.',
      scope: 'Meta diária projetada usando peso por tipo de dia: seg–sex=1,0; sábado=0,33; domingo=0,0.',
      action: 'Passe o mouse sobre cada ponto para ver o acumulado de vendas e a meta proporcional daquele dia.',
      icon: 'show_chart', tone: 'growth'
    },
    'rota-comercial:vendas versus meta': {
      summary: 'Comparação visual de faturamento e meta por vendedor com indicação de risco comercial.',
      read: 'Barras que superam a linha de meta indicam bom desempenho. Barras curtas mostram quem precisa de apoio prioritário.',
      scope: 'Cores de risco: verde = acima da meta (≥ 95%) · amarelo = atenção (≥ 70%) · vermelho = crítico (< 70%).',
      action: 'Passe o mouse em cada barra para ver faturamento, meta e previsão de fechamento do vendedor.',
      icon: 'bar_chart', tone: 'growth'
    },
    'rota-comercial:vendedores': {
      summary: 'Tabela completa dos vendedores no período — metas, faturamento, atingimento, carteira e risco.',
      read: 'Use as colunas para comparar vendedores por ritmo e distância da meta. Ordene por "Quanto falta" para priorizar ação.',
      scope: 'Clique em uma linha para abrir o Vendor 360 com histórico, mix de produtos e análise completa do vendedor.',
      action: 'Ordene e filtre a tabela antes de abrir um Vendor 360 — isso ajuda a focar nos casos prioritários.',
      icon: 'groups', tone: 'people'
    },

    'products:recorrentes': {
      summary: 'Produtos com padrão consistente de recompra.',
      read: 'Use para proteger recorrência e planejar próximas abordagens.',
      icon: 'repeat', tone: 'product'
    },
    'products:dependentes de poucos clientes': {
      summary: 'Produtos com receita concentrada em poucos clientes.',
      read: 'Quanto maior a concentração, maior o risco comercial.',
      icon: 'person', tone: 'attention'
    },

    // ── Dashboard (index) — KPI Strip ────────────────────────────────────────
    'index:vendas faturadas': {
      summary: 'Soma do valor base de todas as NFs autorizadas Orç-PD no período. É o principal resultado comercial da operação.',
      read: 'Compare com o gráfico de evolução abaixo e com a meta no Plano de Voo. Não inclui carteira, orçamentos, remessas ou licitação.',
      scope: 'Usa data de competência fiscal (não data de emissão). Inclui varejo e licitação; exclui Remessa, intra-grupo e Entrega Finalizada.',
      action: 'Aplique filtros de vendedor, cliente ou produto para decompor de onde vem este valor.',
      icon: 'paid', tone: 'revenue'
    },
    'index:notas faturadas orc pd': {
      summary: 'Quantidade de notas fiscais autorizadas no canal Orçamento-PD no período.',
      read: 'Divida pelo valor de Vendas faturadas para entender o ticket médio. Muitas NFs pequenas versus poucas grandes têm impacto operacional e comercial diferente.',
      scope: 'Conta documentos únicos por número de NF autorizada. NFs canceladas não entram.',
      action: 'Use junto de Venda média para entender se o crescimento de receita vem de mais notas ou de notas maiores.',
      icon: 'receipt_long', tone: 'revenue'
    },
    'index:venda media orc pd': {
      summary: 'Faturamento Orç-PD dividido pela quantidade de NFs autorizadas — valor médio de cada documento emitido.',
      read: 'Ticket em queda com volume constante pode indicar desconto excessivo ou mudança de mix. Ticket em alta com menos NFs pode ser venda concentrada em poucos clientes.',
      scope: 'Calculado como: Vendas faturadas ÷ Notas faturadas Orç-PD. NFs canceladas não entram em nenhum dos dois lados.',
      icon: 'sell', tone: 'revenue'
    },
    'index:clientes': {
      summary: 'Quantidade de clientes distintos com ao menos uma NF Orç-PD autorizada no período.',
      read: 'Poucos clientes com faturamento alto indicam concentração de receita — risco de queda repentina se um deles parar de comprar. Monitor os maiores no painel "Clientes em destaque".',
      scope: 'Conta CNPJ/CPF únicos vinculados às NFs autorizadas Orç-PD. Um cliente que comprou 5 vezes conta como 1.',
      icon: 'groups', tone: 'people'
    },
    'index:canceladas': {
      summary: 'Valor total das notas fiscais canceladas no período — receita revertida antes ou após a emissão.',
      read: 'Cancelamentos frequentes podem indicar erro operacional, devolução ou problema de crédito. Compare com o faturamento para medir o peso real sobre o resultado.',
      scope: 'Soma do valor_total das NFs com status cancelado no período. Esse valor já está excluído de Vendas faturadas.',
      action: 'Filtre por vendedor para identificar quem concentra mais cancelamentos e investigar a causa.',
      icon: 'cancel', tone: 'attention'
    },
    'index:carteira aceita': {
      summary: 'Valor dos pedidos já aceitos pelo cliente que ainda não viraram nota fiscal. É o próximo faturamento mais provável.',
      read: 'A carteira representa receita a curto prazo. Compare com "Quanto falta" no Plano de Voo para ver se há cobertura da meta.',
      scope: 'Pedidos com etapa aceito ou autorizado sem NF de saída vinculada. Orçamentos (etapa 10) ficam no card separado.',
      action: 'Acesse o Plano de Voo para ver a carteira por vendedor e priorizar os pedidos de maior valor para faturamento.',
      icon: 'inbox', tone: 'pending'
    },
    'index:orcamentos': {
      summary: 'Valor total das propostas comerciais na etapa 10 (orçamento no Omie) ainda não convertidas em pedido.',
      read: 'Orçamentos não são carteira firme. A taxa de conversão orçamento → pedido → NF mede a eficiência do pipeline inicial.',
      scope: 'Pedidos com etapa 10 no Omie. Não entram em Carteira aceita e não pesam na previsão de fechamento do Plano de Voo.',
      action: 'Use o filtro rápido "Sem pedido" para identificar NFs sem pedido de origem e validar se orçamentos estão sendo aproveitados.',
      icon: 'description', tone: 'growth'
    },

    // ── Dashboard (index) — Painéis e gráficos ───────────────────────────────
    'index:evolucao do faturamento orc pd': {
      summary: 'Faturamento Orç-PD diário ao longo do período — receita e quantidade de NFs por dia com média móvel de 7 dias.',
      read: 'Picos isolados podem indicar concentração em poucos clientes ou pedidos grandes. Queda contínua no final do período é sinal de atenção comercial.',
      scope: 'Agrupado por data de emissão da NF. A linha tracejada é a média móvel de 7 dias para suavizar variações.',
      action: 'Passe o mouse para ver faturamento e NFs em cada dia. Clique em "Detalhar" para abrir a visão completa por período.',
      icon: 'show_chart', tone: 'revenue'
    },
    'index:ranking comercial orc pd': {
      summary: 'Receita e ticket médio de cada vendedor no canal Orç-PD — quem gerou mais faturamento no período.',
      read: 'Vendedores com faturamento alto e ticket baixo dependem de volume. Com ticket alto e poucos clientes há risco de concentração. Compare os dois para entender a qualidade da venda.',
      scope: 'Ordenado por faturamento decrescente. Exclui vendedores sem NF autorizada no período. Inclui licitação; remessas e intra-grupo excluídos.',
      action: 'Clique em "Equipe" para abrir a análise completa com metas, atingimento, carteira e risco no Plano de Voo.',
      icon: 'leaderboard', tone: 'revenue'
    },
    'index:produtos em destaque orc pd': {
      summary: 'Os produtos com maior participação no faturamento Orç-PD — mix de receita por item no período.',
      read: 'Concentração em poucos produtos aumenta risco operacional. Um produto que some do topo inesperadamente merece investigação de giro e estoque.',
      scope: 'Agrupa por produto. Itens de frete ou outras despesas podem aparecer separados dos produtos físicos.',
      action: 'Clique em "Detalhar" ou no nome do produto para abrir o Product 360 com histórico, clientes e análise de giro.',
      icon: 'inventory_2', tone: 'product'
    },
    'index:clientes em destaque orc pd': {
      summary: 'Clientes com maior receita no período — faturamento, frequência de NFs, ticket médio e data da última compra.',
      read: '"Última compra" distante sinaliza cliente em silêncio — candidato a reativação. Alta concentração nos primeiros da lista é alerta de risco de receita.',
      scope: 'Ordenado por faturamento decrescente. O gráfico de rosca ao lado mostra a participação percentual de cada cliente no total.',
      action: 'Clique no nome do cliente para abrir o Dossier 360 com histórico completo de compras e produtos adquiridos.',
      icon: 'groups', tone: 'people'
    },
    'index:etapas das vendas orc pd': {
      summary: 'Composição do pipeline comercial: quanto foi faturado, quanto está em carteira e quanto foi cancelado no período.',
      read: 'Quando carteira e cancelamentos crescem juntos e o faturamento cai, há represamento de pedidos. Acompanhe a taxa de conversão para entender onde o pipeline trava.',
      scope: 'Faturado = NF autorizada Orç-PD. Carteira = pedido aceito sem NF emitida. Cancelado = NF ou pedido cancelado no período.',
      action: 'Use junto dos cards de Carteira aceita e Canceladas no topo para confirmar o peso de cada etapa.',
      icon: 'stacked_bar_chart', tone: 'information'
    },
    'index:maiores nfs orcamento pd': {
      summary: 'As notas fiscais com maior valor no período — cliente, vendedor, itens e base comercial de cada documento.',
      read: 'NFs muito grandes podem distorcer o ticket médio e a análise de concentração. Verifique se há cliente ou vendedor recorrente dominando os topos.',
      scope: 'Ordenado por base comercial decrescente. Exclui NFs canceladas. A paginação mostra o próximo bloco de documentos.',
      action: 'Clique no ícone de lupa em qualquer linha para abrir a NF completa com itens, pedido e recebível financeiro vinculado.',
      icon: 'receipt_long', tone: 'revenue'
    },
    'index:bairros que mais vendem': {
      summary: 'Ranking dos bairros com maior faturamento — concentração geográfica da receita no período.',
      read: 'Bairros no topo com queda de share merecem atenção. Bairros ausentes que estiveram no topo em outros períodos são candidatos a reativação.',
      scope: 'Usa a localização normalizada dos clientes vinculados às NFs autorizadas Orç-PD. Clientes sem endereço não entram.',
      action: 'Clique em "Mapa" para ver a distribuição completa no mapa interativo com heatmap e drilldown por território.',
      icon: 'location_on', tone: 'territory'
    },
    'index:recebimentos e risco': {
      summary: 'Leitura financeira do período: quanto foi recebido em caixa, quanto está aberto, quanto está vencido e a taxa de conversão.',
      read: 'Vencido alto com baixo caixa é sinal de pressão financeira. Monitore os clientes na tabela por valor e dias de atraso — os mais antigos exigem ação prioritária.',
      scope: 'Recebido = baixas no período. Em aberto = saldo a receber. Vencido = a receber com data passada. Conv. caixa = Recebido ÷ Faturado no período.',
      action: 'Clique em um cliente da tabela para abrir os títulos vencidos. Use "Financeiro" no topo para análise completa de recebíveis.',
      icon: 'account_balance_wallet', tone: 'attention'
    },
    'index:pontos de atencao': {
      summary: 'Alertas automáticos gerados com base nos dados do período — risco comercial, financeiro e metas em aberto.',
      read: 'Cada alerta tem critério específico. Priorize os de maior impacto financeiro. Alertas desaparecem quando a situação é corrigida.',
      scope: 'Recalculados a cada atualização. Baseados em: vendedores sem meta, clientes em atraso, carteira represada, queda de ritmo e inadimplência.',
      icon: 'warning', tone: 'attention'
    },
    'index:comparativo mensal': {
      summary: 'Faturamento Orç-PD do mês atual comparado com os meses anteriores recentes — variação de receita no tempo.',
      read: 'Um mês abaixo da média histórica pede análise do ritmo diário e da carteira. Um mês acima pode ser sazonalidade ou campanha — entenda o que sustentou o resultado.',
      scope: 'Compara faturamento Orç-PD mensal. O mês atual pode estar parcial dependendo da data final nos filtros.',
      action: 'Use junto da "Evolução do faturamento" para identificar se a variação é de ritmo diário ou de volume de clientes e pedidos.',
      icon: 'bar_chart', tone: 'information'
    }
  };

  var knowledgeCatalog = {
    'vendas faturadas': {
      formula: 'Soma da base comercial das notas fiscais autorizadas no período.',
      includes: ['Notas fiscais autorizadas', 'Período e filtros selecionados'],
      excludes: ['Pedidos ainda não faturados', 'Notas canceladas', 'Remessas e vendas intra-grupo'],
      related: ['Notas faturadas', 'Venda média', 'Canceladas', 'Carteira aceita'],
      questions: ['O resultado está concentrado em poucos clientes?', 'O ritmo atual é suficiente para o período?', 'A carteira cobre uma eventual queda do faturamento?']
    },
    'faturamento orc pd': {
      formula: 'Soma da base comercial das notas fiscais autorizadas classificadas como Orçamento-PD.',
      includes: ['Notas autorizadas Orçamento-PD', 'Base comercial da nota', 'Filtros ativos'],
      excludes: ['Remessas', 'Licitações', 'Notas canceladas', 'Pedidos sem nota fiscal'],
      related: ['NFs Orç-PD', 'Ticket médio Orç-PD', 'Descontos', 'Carteira'],
      questions: ['Quais clientes e vendedores formam a maior parte do valor?', 'O ticket médio cresceu junto do faturamento?', 'Há carteira suficiente para manter o ritmo?']
    },
    'notas faturadas orc pd': {
      formula: 'Contagem das notas fiscais autorizadas classificadas como Orçamento-PD.',
      includes: ['Notas autorizadas no recorte', 'Documentos Orçamento-PD'],
      excludes: ['Notas canceladas', 'Pedidos ainda sem nota'],
      related: ['Vendas faturadas', 'Venda média Orç-PD', 'Canceladas'],
      questions: ['O crescimento veio de mais notas ou de notas maiores?', 'Existem cancelamentos relevantes?', 'Quais vendedores concentram os documentos?']
    },
    'nfs orc pd': {
      formula: 'Contagem das notas fiscais autorizadas classificadas como Orçamento-PD.',
      includes: ['Notas autorizadas no recorte', 'Documentos Orçamento-PD'],
      excludes: ['Notas canceladas', 'Pedidos ainda sem nota'],
      related: ['Faturamento Orç-PD', 'Ticket médio Orç-PD', 'Canceladas'],
      questions: ['O crescimento veio de mais notas ou de notas maiores?', 'Existem cancelamentos relevantes?', 'Quais vendedores concentram os documentos?']
    },
    'ticket medio orc pd': {
      formula: 'Faturamento Orçamento-PD dividido pela quantidade de notas fiscais Orçamento-PD.',
      includes: ['Base comercial faturada', 'Notas autorizadas do mesmo recorte'],
      excludes: ['Notas canceladas', 'Pedidos não faturados'],
      related: ['Faturamento Orç-PD', 'NFs Orç-PD', 'Descontos'],
      questions: ['O valor médio cresceu ou caiu?', 'Poucas notas muito grandes estão distorcendo a média?', 'Os descontos estão reduzindo o ticket?']
    },
    'venda media orc pd': {
      formula: 'Vendas faturadas Orçamento-PD divididas pela quantidade de notas fiscais autorizadas.',
      includes: ['Base comercial faturada', 'Notas autorizadas do mesmo recorte'],
      excludes: ['Notas canceladas', 'Pedidos não faturados'],
      related: ['Vendas faturadas', 'Notas faturadas Orç-PD', 'Descontos'],
      questions: ['O valor médio cresceu ou caiu?', 'Poucas notas muito grandes estão distorcendo a média?', 'Os descontos estão reduzindo a venda média?']
    },
    'carteira': {
      formula: 'Soma dos pedidos aceitos que ainda aguardam faturamento no recorte atual.',
      includes: ['Pedidos aceitos ainda não faturados', 'Filtros comerciais ativos'],
      excludes: ['Vendas já faturadas', 'Pedidos cancelados'],
      related: ['Vendas faturadas', 'Quanto falta para a meta', 'Pedidos sem nota fiscal'],
      questions: ['Qual parte da carteira pode faturar primeiro?', 'Há pedidos parados ou sem vínculo?', 'A carteira cobre o objetivo restante?']
    },
    'carteira aceita': {
      formula: 'Soma dos pedidos aceitos que ainda não foram faturados.',
      includes: ['Pedidos aceitos em andamento'],
      excludes: ['Pedidos faturados', 'Pedidos cancelados'],
      related: ['Vendas faturadas', 'Orçamentos', 'Canceladas'],
      questions: ['A carteira está envelhecendo?', 'Quais pedidos têm maior valor?', 'Há impedimentos para faturar?']
    },
    'canceladas': {
      formula: 'Soma ou contagem dos documentos cancelados no período e filtros atuais.',
      includes: ['Documentos com situação cancelada'],
      excludes: ['Notas autorizadas', 'Pedidos válidos em carteira'],
      related: ['Vendas faturadas', 'Notas faturadas', 'Carteira'],
      questions: ['Quais motivos se repetem?', 'Qual vendedor ou cliente concentra cancelamentos?', 'O valor cancelado está crescendo?']
    },
    'descontos': {
      formula: 'Soma dos descontos concedidos nos documentos do recorte.',
      includes: ['Descontos registrados nas vendas filtradas'],
      excludes: ['Reduções sem vínculo com os documentos do recorte'],
      related: ['Faturamento Orç-PD', 'Ticket médio Orç-PD', 'Produtos vendidos'],
      questions: ['Quais produtos recebem mais desconto?', 'O desconto está aumentando sem elevar volume?', 'Há concentração por vendedor ou cliente?']
    },
    'meta do periodo': {
      formula: 'Σ (Meta mensal × peso_intervalo ÷ peso_total_mês). Peso por dia: Seg–Sex = 1,0 · Sábado = 0,33 · Domingo = 0,0. Cada mês do período é calculado separadamente e somado.',
      includes: ['Metas ativas cadastradas por vendedor', 'Dias do período selecionado com peso por tipo de dia'],
      excludes: ['Metas inativas ou não cadastradas', 'Dias do mês fora do intervalo selecionado', 'Domingos (peso zero)'],
      related: ['Vendas faturadas', 'Quanto falta para a meta', 'Previsão de fechamento', '% da meta'],
      questions: ['O ritmo diário atual é suficiente para bater a meta?', 'Quais vendedores ainda não têm meta cadastrada?', 'A carteira aceita cobre o valor que ainda falta?']
    },
    '% da meta': {
      formula: '(realizado_período ÷ meta_período) × 100. Pode ultrapassar 100% se o faturamento superar a meta proporcional.',
      includes: ['Vendas faturadas por NF autorizada no período', 'Meta proporcional calculada pelo peso dos dias'],
      excludes: ['Carteira aceita e orçamentos (ainda não faturados)', 'Vendedores sem meta definida (aparecem em Metas pendentes)'],
      related: ['Meta do período', 'Quanto falta para a meta', 'Previsão de fechamento', 'Sem vendas'],
      questions: ['O percentual está compatível com os dias já transcorridos?', 'Quais vendedores estão abaixo de 70% (risco crítico)?', 'Algum vendedor já superou 100% e pode apoiar outros?']
    },
    'quanto falta para a meta': {
      formula: 'MAX(meta_período – realizado_período, 0). Nunca negativo: quando o realizado supera a meta, o valor exibido é R$ 0.',
      includes: ['Meta proporcional ao período (ponderada por tipo de dia)', 'Vendas faturadas por NF autorizada'],
      excludes: ['Carteira aceita e orçamentos (ainda não faturados)', 'Vendedores sem meta (aparecem em Metas pendentes)'],
      related: ['Meta do período', 'Vendas faturadas', 'Pedidos em carteira', 'Previsão de fechamento'],
      questions: ['Quanto precisa ser faturado por dia útil para fechar a meta?', 'A carteira aceita cobre o gap restante?', 'Quais vendedores têm o maior gap proporcional?']
    },
    'previsao de fechamento': {
      formula: 'Provável = (realizado ÷ peso_dias_decorridos × peso_total_período) + (carteira_aceita × 0,80). Critérios de risco: crítico se provável < 70% da meta · atenção se < 95% · no_track se ≥ 95%.',
      includes: ['Vendas faturadas até a data atual', 'Pedidos aceitos aguardando NF (contribuem com 80% do valor)', 'Peso ponderado dos dias já decorridos no período'],
      excludes: ['Orçamentos (etapa 10 — incertos, não entram na previsão)', 'Pedidos cancelados', 'Domingos e feriados (peso zero)'],
      related: ['Meta do período', 'Pedidos em carteira', 'Quanto falta para a meta', 'Ritmo diário de vendas'],
      questions: ['A previsão supera a meta? Se não, quanto falta?', 'Quais vendedores estão em risco crítico (< 70%)?', 'A carteira aceita é suficiente para recuperar o gap?']
    },
    'pedidos que viraram nota': {
      formula: 'Quantidade de pedidos faturados dividida pela quantidade de pedidos elegíveis, multiplicada por 100.',
      includes: ['Pedidos elegíveis', 'Pedidos com nota fiscal vinculada'],
      excludes: ['Registros fora do período e dos filtros'],
      related: ['Pedidos aguardando faturamento', 'Pedidos sem nota fiscal', 'Notas faturadas Orç-PD'],
      questions: ['Onde a conversão está travando?', 'Quais pedidos estão há mais tempo sem nota?', 'Existe diferença relevante entre vendedores?']
    },
    'recebiveis em aberto': {
      formula: 'Soma dos saldos financeiros ainda não liquidados.',
      includes: ['Títulos com saldo em aberto', 'Vencidos e a vencer'],
      excludes: ['Títulos totalmente recebidos', 'Registros cancelados'],
      related: ['Recebíveis vencidos', 'A vencer', 'Recebido no mês'],
      questions: ['Quanto vence nos próximos dias?', 'Qual cliente concentra o saldo?', 'Quais valores precisam de cobrança?']
    },
    'a receber': {
      formula: 'Soma dos saldos financeiros ainda não liquidados.',
      includes: ['Títulos com saldo em aberto', 'Vencidos e a vencer'],
      excludes: ['Títulos totalmente recebidos', 'Registros cancelados'],
      related: ['Vencido', 'A vencer', 'Recebido no mês'],
      questions: ['Quanto vence nos próximos dias?', 'Qual cliente concentra o saldo?', 'Quais valores precisam de cobrança?']
    },
    'recebiveis vencidos': {
      formula: 'Soma dos saldos em aberto cuja data de vencimento já passou.',
      includes: ['Títulos vencidos com saldo'],
      excludes: ['Títulos recebidos', 'Valores ainda a vencer'],
      related: ['Recebíveis em aberto', 'Clientes em atraso', 'Sem ação de cobrança'],
      questions: ['Quem deve ser cobrado primeiro?', 'Há promessas de pagamento vencidas?', 'Qual faixa de atraso concentra mais valor?']
    },
    'vencido': {
      formula: 'Soma dos saldos em aberto cuja data de vencimento já passou.',
      includes: ['Títulos vencidos com saldo'],
      excludes: ['Títulos recebidos', 'Valores ainda a vencer'],
      related: ['A receber', 'A vencer', 'Clientes em atraso'],
      questions: ['Quem deve ser cobrado primeiro?', 'Qual faixa de atraso concentra mais valor?', 'Há títulos sem responsável?']
    },
    'a vencer': {
      formula: 'Soma dos saldos em aberto cuja data de vencimento ainda não chegou.',
      includes: ['Títulos abertos dentro do prazo'],
      excludes: ['Valores vencidos', 'Títulos já recebidos'],
      related: ['A receber', 'Vencido', 'Recebido no mês'],
      questions: ['Quanto deve entrar em breve?', 'Há concentração em poucos clientes?', 'Quais vencimentos precisam de acompanhamento preventivo?']
    },
    'recebido no mes': {
      formula: 'Soma das baixas e recebimentos confirmados no mês indicado.',
      includes: ['Recebimentos confirmados', 'Baixas dentro do recorte'],
      excludes: ['Títulos ainda em aberto'],
      related: ['A receber', 'Vencido', 'Meios de recebimento'],
      questions: ['O recebido acompanha o esperado?', 'Quais contas e meios concentram as entradas?', 'Quem mais contribuiu para o caixa?']
    },
    'conciliados': {
      formula: 'Contagem ou valor dos registros com vínculo identificado entre pedido, nota fiscal e título financeiro.',
      includes: ['Registros com vínculos encontrados'],
      excludes: ['Pendências e vínculos ausentes'],
      related: ['Divergentes', 'NF s/ Título', 'Pedido s/ NF'],
      questions: ['Qual é a cobertura da conferência?', 'Quais vínculos ainda faltam?', 'As divergências têm impacto financeiro?']
    },
    'divergentes': {
      formula: 'Contagem ou valor dos registros cuja conferência encontrou diferença.',
      includes: ['Registros com diferença identificada'],
      excludes: ['Registros conciliados sem diferença'],
      related: ['Conciliados', 'Valor divergente', 'Pendências reais'],
      questions: ['Qual diferença tem maior impacto?', 'A causa se repete?', 'Quem pode corrigir cada pendência?']
    },
    '% vencido': {
      formula: 'Valor vencido dividido pelo total a receber, multiplicado por 100.',
      includes: ['Saldo vencido', 'Saldo total a receber do mesmo recorte'],
      excludes: ['Títulos já recebidos'],
      related: ['Vencido', 'A receber', 'Clientes em atraso'],
      questions: ['O percentual está aumentando?', 'Quais clientes explicam a maior parte?', 'Qual faixa de atraso exige prioridade?']
    },
    '% acionada': {
      formula: 'Parte da carteira elegível que já possui ação de cobrança registrada.',
      includes: ['Títulos elegíveis com ação identificada'],
      excludes: ['Títulos sem ação registrada'],
      related: ['Sem ação de cobrança', 'Cobrados hoje', 'Retornos atrasados'],
      questions: ['A equipe está cobrindo os casos prioritários?', 'Quais valores ainda não receberam ação?', 'Há retornos atrasados?']
    },
    'clientes em atraso': {
      formula: 'Contagem de clientes com pelo menos um título vencido e saldo em aberto.',
      includes: ['Clientes com saldo vencido'],
      excludes: ['Clientes apenas com valores a vencer ou já recebidos'],
      related: ['Recebíveis vencidos', 'Maior atraso', 'Sem ação de cobrança'],
      questions: ['Quais clientes concentram mais valor?', 'Quem está há mais tempo em atraso?', 'Há cobrança ou promessa registrada?']
    },
    'maior atraso': {
      formula: 'Maior quantidade de dias de atraso encontrada entre os títulos vencidos do recorte.',
      includes: ['Títulos vencidos com saldo em aberto'],
      excludes: ['Títulos recebidos e valores a vencer'],
      related: ['Recebíveis vencidos', 'Clientes em atraso', 'Prioridade por tempo de atraso'],
      questions: ['Qual cliente possui o maior atraso?', 'Existe ação recente registrada?', 'O valor justifica escalonamento?']
    },
    'metodo principal': {
      formula: 'Método ou canal com maior participação nos registros do período.',
      includes: ['Métodos identificados nos títulos vinculados'],
      excludes: ['Registros sem método identificado quando separados'],
      related: ['Formas de pagamento mais usadas', 'Recebido no mês', 'Produtos'],
      questions: ['O método dominante muda por produto?', 'Há concentração excessiva?', 'Existem registros sem método identificado?']
    },
    'nfs com recebiveis em aberto': {
      formula: 'Contagem de notas fiscais vinculadas a pelo menos um título com saldo em aberto.',
      includes: ['Notas com títulos financeiros abertos vinculados'],
      excludes: ['Notas sem saldo aberto ou sem vínculo confirmado'],
      related: ['Recebíveis em aberto', 'Sem NF vinculada', 'Cobertura NF × título'],
      questions: ['Quais notas concentram maior saldo?', 'Há parcelas vencidas?', 'O vendedor e o pedido estão identificados?']
    },
    'sem nf vinculada': {
      formula: 'Contagem ou valor dos títulos financeiros sem nota fiscal identificada.',
      includes: ['Títulos cuja nota fiscal não foi localizada'],
      excludes: ['Títulos com vínculo de nota confirmado'],
      related: ['NFs com recebíveis em aberto', 'Revisões de vínculo', 'Cobertura NF × título'],
      questions: ['Existe número de documento para recuperar o vínculo?', 'Qual é o impacto financeiro?', 'A origem pode ser confirmada pelo pedido?']
    },
    'sem vendedor': {
      formula: 'Contagem de registros financeiros sem vendedor ou responsável comercial identificado.',
      includes: ['Registros sem responsável resolvido'],
      excludes: ['Registros com vendedor identificado'],
      related: ['Valor sem vendedor', 'Revisões de vínculo', 'Cobrança por vendedor'],
      questions: ['O vendedor pode ser recuperado pela NF ou pedido?', 'Quais valores têm maior impacto?', 'Quem assumirá a cobrança enquanto o vínculo é corrigido?']
    },
    'titulos sem vendedor': {
      formula: 'Contagem de títulos financeiros sem vendedor ou responsável comercial identificado.',
      includes: ['Títulos sem responsável resolvido'],
      excludes: ['Títulos com vendedor identificado'],
      related: ['Valor sem vendedor', 'Sem NF vinculada', 'Revisões de vínculo'],
      questions: ['O vendedor pode ser recuperado pela NF ou pedido?', 'Quais títulos são prioritários?', 'A ausência afeta a cobrança?']
    },
    'valor sem vendedor': {
      formula: 'Soma dos saldos financeiros sem vendedor ou responsável comercial identificado.',
      includes: ['Saldo dos registros sem responsável resolvido'],
      excludes: ['Saldo já atribuído a um vendedor'],
      related: ['Títulos sem vendedor', 'Revisões de vínculo', 'Recebíveis em aberto'],
      questions: ['Qual é o maior valor sem responsável?', 'O vínculo pode ser recuperado?', 'Quem acompanhará estes títulos?']
    },
    'sem acao de cobranca': {
      formula: 'Contagem ou valor dos títulos elegíveis sem ação de cobrança registrada.',
      includes: ['Títulos elegíveis sem contato ou ação identificada'],
      excludes: ['Títulos já acionados'],
      related: ['% acionada', 'Recebíveis vencidos', 'Fila de risco prioritária'],
      questions: ['Quais casos devem ser acionados hoje?', 'Quem é o responsável?', 'Qual valor está sem acompanhamento?']
    },
    'promessas de pagamento': {
      formula: 'Contagem de compromissos de pagamento registrados para títulos em acompanhamento.',
      includes: ['Promessas registradas no recorte'],
      excludes: ['Ações sem promessa de pagamento'],
      related: ['Promessas vencidas', 'Retornos hoje', 'Recebíveis vencidos'],
      questions: ['Quais promessas vencem hoje?', 'Qual valor está comprometido?', 'Existem promessas vencidas sem retorno?']
    },
    'promessas vencidas': {
      formula: 'Contagem de promessas cuja data combinada passou sem baixa confirmada.',
      includes: ['Promessas vencidas ainda pendentes'],
      excludes: ['Promessas cumpridas ou ainda dentro do prazo'],
      related: ['Promessas de pagamento', 'Retornos atrasados', 'Recebíveis vencidos'],
      questions: ['Quem precisa de retorno imediato?', 'Qual valor está comprometido?', 'A promessa deve ser renegociada?']
    },
    'revisoes de vinculo': {
      formula: 'Contagem de registros sinalizados para conferir a relação entre financeiro, nota fiscal, pedido ou vendedor.',
      includes: ['Registros com vínculo ausente ou duvidoso'],
      excludes: ['Registros com origem confirmada'],
      related: ['Sem NF vinculada', 'Sem vendedor', 'Divergentes'],
      questions: ['Qual revisão tem maior impacto?', 'Há identificadores suficientes para corrigir?', 'A causa se repete em outros registros?']
    },
    'pendencias reais': {
      formula: 'Contagem dos casos que continuam sem vínculo ou resolução após a conferência disponível.',
      includes: ['Pendências confirmadas no recorte'],
      excludes: ['Diferenças já explicadas ou conciliadas'],
      related: ['Conciliados', 'Divergentes', 'NF s/ Título'],
      questions: ['Qual pendência afeta mais valor?', 'Quem pode resolvê-la?', 'Existe padrão na causa?']
    },
    'valor divergente': {
      formula: 'Soma do impacto financeiro associado aos registros divergentes.',
      includes: ['Diferenças de valor identificadas na conferência'],
      excludes: ['Registros conciliados sem diferença'],
      related: ['Divergentes', 'Conciliados', 'Pendências reais'],
      questions: ['Qual divergência concentra maior valor?', 'A diferença é fiscal, comercial ou financeira?', 'Qual correção deve ser priorizada?']
    },

    'pedidos em carteira': {
      formula: 'Soma dos valores dos pedidos com status aceito ou autorizado que ainda não possuem nota fiscal emitida no período selecionado.',
      includes: ['Pedidos aceitos e não cancelados', 'Pedidos autorizados sem NF vinculada'],
      excludes: ['Pedidos cancelados', 'Pedidos já faturados com NF emitida', 'Orçamentos (etapa 10 — ainda não confirmados)'],
      related: ['Previsão de fechamento', 'Quanto falta para a meta', 'Vendas faturadas'],
      questions: ['Os pedidos em carteira cobrem o gap da meta?', 'Há pedidos parados há muitos dias sem NF?', 'Quais vendedores têm mais carteira disponível para fechar?']
    },
    'sem vendas': {
      formula: 'Contagem de vendedores com meta ativa em biomie_sales_targets_monthly cuja soma de vendas faturadas (NF autorizada) no período é zero.',
      includes: ['Vendedores com meta cadastrada no período', 'Com realizado = R$ 0 no intervalo'],
      excludes: ['Vendedores sem meta (aparecem em Metas pendentes)', 'Vendedores inativos sem qualquer atividade'],
      related: ['Metas pendentes', 'Abaixo da meta', 'Meta do período', 'Previsão de fechamento'],
      questions: ['Qual é o motivo de cada vendedor sem venda?', 'Há pedidos em carteira ou orçamentos em andamento para eles?', 'É um período de ausência ou bloqueio operacional?']
    },
    'metas pendentes': {
      formula: 'Contagem de vendedores com ao menos uma NF autorizada ou pedido aceito no período que não possuem entrada em biomie_sales_targets_monthly para o intervalo.',
      includes: ['Vendedores com NF ou pedido no período', 'Sem meta cadastrada no mês correspondente'],
      excludes: ['Vendedores sem qualquer atividade comercial no período'],
      related: ['Sem vendas', 'Meta do período', 'Acima da meta', 'Previsão de fechamento'],
      questions: ['Quem precisa ter meta cadastrada imediatamente?', 'Qual é o faturamento gerado por eles sem meta?', 'Esse faturamento está sendo ignorado no atingimento geral da equipe?']
    },

    // ── Dashboard (index) — painéis ──────────────────────────────────────────
    'evolucao do faturamento orc pd': {
      formula: 'Faturamento Orç-PD agrupado por data de emissão da NF. Linha principal = faturamento diário. Linha tracejada = média móvel de 7 dias.',
      includes: ['NFs autorizadas Orç-PD no período', 'Data de emissão como eixo temporal'],
      excludes: ['Remessas', 'Licitação', 'NFs canceladas', 'Entrega Finalizada'],
      related: ['Vendas faturadas', 'Notas faturadas Orç-PD', 'Ranking comercial', 'Comparativo mensal'],
      questions: ['O ritmo está acelerando ou desacelerando?', 'Há picos isolados que distorcem a média?', 'A queda no final do período é tendência ou efeito de data?']
    },
    'ranking comercial orc pd': {
      formula: 'Faturamento Orç-PD somado por vendedor. Ticket médio = faturamento do vendedor ÷ quantidade de NFs do vendedor no período.',
      includes: ['Vendedores com ao menos uma NF autorizada Orç-PD no período'],
      excludes: ['Vendedores sem NF no período', 'Remessas e vendas intra-grupo'],
      related: ['Venda média Orç-PD', 'Clientes', 'Plano de Voo', 'Carteira aceita'],
      questions: ['Quem sustenta a maior parte do faturamento?', 'O ticket de cada vendedor está crescendo?', 'Há dependência excessiva de um único vendedor?']
    },
    'produtos em destaque orc pd': {
      formula: 'Faturamento Orç-PD somado por produto (SKU/descrição). Exibe os de maior participação no total do período.',
      includes: ['Itens de NFs autorizadas Orç-PD', 'Agrupados por produto'],
      excludes: ['Itens de NFs canceladas', 'Remessas e vendas intra-grupo'],
      related: ['Venda média Orç-PD', 'Clientes', 'Carteira aceita', 'Product 360'],
      questions: ['Quais produtos sustentam a receita?', 'Há produto que sumiu do topo?', 'Os itens no topo têm estoque suficiente?']
    },
    'clientes em destaque orc pd': {
      formula: 'Faturamento Orç-PD somado por cliente. Exibe os de maior valor com frequência de NFs, ticket médio e data da última compra.',
      includes: ['Clientes com ao menos uma NF Orç-PD autorizada no período'],
      excludes: ['Clientes sem NF no período', 'Remessas e vendas intra-grupo'],
      related: ['Vendas faturadas', 'Clientes', 'Bairros que mais vendem', 'Carteira aceita'],
      questions: ['Há concentração nos 3 primeiros?', 'Algum cliente importante sumiu do ranking?', 'A data da última compra dos grandes clientes está recente?']
    },
    'etapas das vendas orc pd': {
      formula: 'Faturado = NFs autorizadas Orç-PD. Carteira = pedidos aceitos sem NF emitida. Cancelado = NFs ou pedidos com status cancelado. Agrupados por semana ou mês.',
      includes: ['NFs Orç-PD autorizadas, em carteira e canceladas'],
      excludes: ['Orçamentos (etapa 10)', 'Remessas e vendas intra-grupo'],
      related: ['Vendas faturadas', 'Carteira aceita', 'Canceladas', 'Orçamentos'],
      questions: ['A proporção faturado/carteira está saudável?', 'Os cancelamentos cresceram?', 'Há represamento de carteira sem NF?']
    },
    'maiores nfs orcamento pd': {
      formula: 'NFs Orç-PD autorizadas ordenadas por base comercial decrescente. Exibe cliente, vendedor, quantidade de itens e valor.',
      includes: ['NFs autorizadas Orç-PD no período', 'Resolvidas com cliente e vendedor identificados'],
      excludes: ['NFs canceladas', 'Remessas e vendas intra-grupo'],
      related: ['Venda média Orç-PD', 'Clientes em destaque', 'Ranking comercial', 'Recebimentos e risco'],
      questions: ['Quais NFs podem distorcer o ticket médio?', 'Há clientes repetidos nos topos?', 'O recebível dessas NFs está identificado?']
    },
    'bairros que mais vendem': {
      formula: 'Faturamento Orç-PD somado por bairro normalizado do cliente. Share = faturamento do bairro ÷ total com bairro identificado.',
      includes: ['Clientes com bairro identificado na NF autorizada Orç-PD'],
      excludes: ['Clientes sem endereço ou bairro não resolvido'],
      related: ['Clientes em destaque', 'Vendas faturadas', 'Mapa de vendas'],
      questions: ['Qual bairro perdeu share?', 'Há bairros novos entrando no ranking?', 'Clientes sem localização estão reduzindo a cobertura geográfica?']
    },
    'recebimentos e risco': {
      formula: 'Recebido = baixas financeiras confirmadas no período. Em aberto = saldo de títulos não liquidados. Vencido = saldo com data de vencimento passada. Conv. caixa = Recebido ÷ Faturado.',
      includes: ['Títulos financeiros vinculados às NFs do período', 'Clientes com ao menos um título vencido'],
      excludes: ['Títulos de fora do período selecionado'],
      related: ['Vendas faturadas', 'Carteira aceita', 'Pontos de atenção', 'Financeiro'],
      questions: ['A conversão de caixa está compatível com o faturamento?', 'Quais clientes concentram o vencido?', 'Há títulos vencidos sem ação de cobrança?']
    },
    'pontos de atencao': {
      formula: 'Alertas gerados por regras automáticas: vendedores sem meta, clientes em atraso, carteira represada, queda de ritmo e outras anomalias detectadas nos dados do período.',
      includes: ['Alertas de risco comercial e financeiro calculados na atualização mais recente'],
      excludes: ['Alertas já resolvidos ou fora do período ativo'],
      related: ['Sem vendas', 'Recebimentos e risco', 'Metas pendentes', 'Carteira aceita'],
      questions: ['Qual alerta tem maior impacto financeiro?', 'Qual pode ser resolvido hoje?', 'Há alerta recorrente que indica problema estrutural?']
    },
    'comparativo mensal': {
      formula: 'Faturamento Orç-PD somado por mês. O mês atual pode estar parcial se a data final nos filtros for anterior ao último dia do mês.',
      includes: ['NFs autorizadas Orç-PD de cada mês do período', 'Meses anteriores para comparação histórica'],
      excludes: ['Remessas, Licitação e NFs canceladas'],
      related: ['Evolução do faturamento Orç-PD', 'Vendas faturadas', 'Ranking comercial', 'Plano de Voo'],
      questions: ['O mês atual está acima ou abaixo da média histórica?', 'Há sazonalidade clara?', 'O crescimento é sustentado ou pontual?']
    }
  };

  knowledgeCatalog['percentual da meta'] = knowledgeCatalog['% da meta'];
  knowledgeCatalog['quanto falta'] = knowledgeCatalog['quanto falta para a meta'];
  knowledgeCatalog['previsao ate o fim do periodo'] = knowledgeCatalog['previsao de fechamento'];
  knowledgeCatalog['recebiveis vencidos'] = knowledgeCatalog['vencido'];
  knowledgeCatalog['notas faturadas orc pd'] = knowledgeCatalog['nfs orc pd'];
  knowledgeCatalog['venda media orc pd'] = knowledgeCatalog['ticket medio orc pd'];

  var knowledgeTopics = [
    {
      pattern: /forma de pagamento|metodo|meio de recebimento|conta|canal/,
      profile: {
        formula: 'Agrupa os registros pelo método, meio, canal ou conta identificado.',
        includes: ['Registros vinculados ao recorte atual'],
        excludes: ['Registros sem identificação quando apresentados separadamente'],
        related: ['Recebido no mês', 'Produtos', 'Clientes'],
        questions: ['Qual método é mais usado?', 'O padrão muda por produto ou cliente?', 'Há dependência excessiva de um canal?']
      }
    },
    {
      pattern: /produto|mercadoria|item|mix/,
      profile: {
        formula: 'Agrupa os documentos e valores pelos produtos vinculados às vendas.',
        includes: ['Produtos encontrados nos documentos filtrados'],
        excludes: ['Itens fora do recorte atual'],
        related: ['Faturamento', 'Quantidade vendida', 'Clientes', 'Ticket médio'],
        questions: ['Quais produtos sustentam o resultado?', 'Há produtos dependentes de poucos clientes?', 'Qual produto merece reposição ou ação comercial?']
      }
    },
    {
      pattern: /cliente|vendedor|equipe|operador|responsavel/,
      profile: {
        formula: 'Agrupa os registros pelo cliente ou responsável identificado.',
        includes: ['Participantes vinculados aos registros filtrados'],
        excludes: ['Registros sem vínculo quando apresentados separadamente'],
        related: ['Faturamento', 'Carteira', 'Risco', 'Ticket médio'],
        questions: ['Há concentração em poucas pessoas ou clientes?', 'Quem precisa de ação imediata?', 'O resultado é consistente ou pontual?']
      }
    },
    {
      pattern: /bairro|regiao|territorio|mapa|cnae/,
      profile: {
        formula: 'Agrupa os resultados pela localização ou classificação disponível para cada cliente.',
        includes: ['Clientes com localização ou classificação identificada'],
        excludes: ['Registros sem localização quando apresentados separadamente'],
        related: ['Faturamento', 'Clientes', 'Notas fiscais'],
        questions: ['Onde o resultado está concentrado?', 'Quais áreas têm espaço para crescer?', 'Há clientes sem localização para corrigir?']
      }
    },
    {
      pattern: /conciliacao|conferencia|vinculo|rastreabilidade|diverg/,
      profile: {
        formula: 'Cruza os identificadores disponíveis entre pedido, nota fiscal e título financeiro.',
        includes: ['Registros com dados suficientes para conferência'],
        excludes: ['Relações que não podem ser confirmadas sem identificador'],
        related: ['Conciliados', 'Divergentes', 'Pendências reais'],
        questions: ['Qual é o impacto das pendências?', 'Quais vínculos podem ser corrigidos?', 'A origem dos valores está confiável?']
      }
    }
  ];

  var topicCatalog = [
    {
      pattern: /ranking|maiores|top |destaque|lider/,
      definition: {
        summary: 'Compara os participantes com maior resultado dentro do assunto apresentado.',
        read: 'Os primeiros colocados concentram os maiores valores ou quantidades.',
        action: 'Passe o mouse para consultar valores; clique quando houver detalhamento.',
        icon: 'leaderboard'
      }
    },
    {
      pattern: /evolucao|movimento|ritmo|pulso|por dia|por mes|tendencia/,
      definition: {
        summary: 'Mostra como o resultado mudou ao longo do período selecionado.',
        read: 'Observe altas, quedas e mudanças de ritmo para antecipar decisões.',
        action: 'Passe o mouse sobre cada ponto ou barra para consultar o período.',
        icon: 'show_chart'
      }
    },
    {
      pattern: /composicao|participacao|dividido|mix|fatia|share/,
      definition: {
        summary: 'Mostra como o total está distribuído entre grupos ou categorias.',
        read: 'Cores e tamanhos maiores indicam maior participação no resultado.',
        action: 'Use a legenda e passe o mouse para consultar cada participação.',
        icon: 'donut_large'
      }
    },
    {
      pattern: /tempo de atraso|prioridade por tempo|faixa de atraso|aging/,
      definition: {
        summary: 'Organiza valores pendentes conforme a quantidade de dias de atraso.',
        read: 'Faixas mais antigas normalmente exigem maior prioridade de cobrança.',
        action: 'Selecione uma faixa quando disponível para ver os registros relacionados.',
        icon: 'hourglass_top',
        tone: 'attention'
      }
    },
    {
      pattern: /risco|prioridade|concentracao|para cobrar primeiro/,
      definition: {
        summary: 'Destaca os casos com maior impacto ou urgência para atuação.',
        read: 'Comece pelos maiores valores e sinais mais críticos.',
        action: 'Clique em um item quando disponível para entender sua origem.',
        icon: 'priority_high',
        tone: 'attention'
      }
    },
    {
      pattern: /vendedor|equipe|operador|responsavel|cobranca/,
      definition: {
        summary: 'Compara resultados e responsabilidades entre pessoas da equipe.',
        read: 'Use para reconhecer desempenho, carga de trabalho e pontos de atenção.',
        action: 'Passe o mouse ou clique em um responsável para consultar detalhes.',
        icon: 'groups',
        tone: 'people'
      }
    },
    {
      pattern: /produto|mercadoria|item|estoque/,
      definition: {
        summary: 'Apresenta o desempenho comercial dos produtos no recorte atual.',
        read: 'Compare receita, quantidade e clientes para entender a qualidade do resultado.',
        action: 'Clique no produto quando disponível para abrir sua visão completa.',
        icon: 'inventory_2',
        tone: 'product'
      }
    },
    {
      pattern: /cliente/,
      definition: {
        summary: 'Apresenta clientes relevantes para o resultado ou ação indicada.',
        read: 'Observe valor, recorrência e risco para definir a melhor abordagem.',
        action: 'Clique no cliente quando disponível para abrir seu detalhamento.',
        icon: 'groups',
        tone: 'people'
      }
    },
    {
      pattern: /bairro|regiao|mapa|territorio|cnae|segmento/,
      definition: {
        summary: 'Mostra onde o resultado comercial está distribuído.',
        read: 'Use para identificar regiões fortes, concentração e espaços para crescer.',
        action: 'Clique em uma região quando disponível para aprofundar a análise.',
        icon: 'map',
        tone: 'territory'
      }
    },
    {
      pattern: /forma de pagamento|metodo|canal|conta meio|como os produtos sao pagos/,
      definition: {
        summary: 'Mostra as formas de pagamento mais utilizadas no recorte.',
        read: 'Use para entender preferência dos clientes e comportamento por produto.',
        action: 'Alterne a visão ou selecione um método quando os controles estiverem disponíveis.',
        icon: 'credit_card'
      }
    },
    {
      pattern: /conferencia|conciliacao|vinculo|origem|rastreabilidade|identificad/,
      definition: {
        summary: 'Confere a relação entre pedidos, notas fiscais e valores financeiros.',
        read: 'Quanto maior a identificação, maior a confiança sobre a origem dos valores.',
        action: 'Abra os registros sinalizados para revisar diferenças ou vínculos ausentes.',
        icon: 'fact_check'
      }
    },
    {
      pattern: /esperavamos|previsto|previsao|meta|projecao/,
      definition: {
        summary: 'Compara o objetivo ou valor esperado com o resultado atual.',
        read: 'Use a diferença para antecipar necessidade de ação.',
        action: 'Passe o mouse sobre cada período ou grupo para comparar os valores.',
        icon: 'query_stats',
        tone: 'growth'
      }
    },
    {
      pattern: /faturamento|receita|recebimento|recebido|vendas/,
      definition: {
        summary: 'Apresenta valores comerciais ou financeiros realizados no recorte.',
        read: 'Use como referência de resultado confirmado e compare sua composição.',
        action: 'Passe o mouse ou clique nos elementos quando houver detalhamento.',
        icon: 'paid',
        tone: 'revenue'
      }
    }
  ];

  var panelSelectors = [
    '.chart-card', '.table-card', '.dashboard-panel', '.fin-panel',
    '.finance-visual-card', '.fp-card', '.wallboard-card', '.biomie-map-card',
    '.mag-card', '.omie-card'
  ].join(',');

  var headingSelectors = [
    '.chart-card-header h3', '.table-card-header h3', '.table-card-title',
    '.dashboard-panel__header h3', '.fin-panel-head h2',
    '.finance-visual-card__head h3', '.finance-visual-head h2',
    '.fp-card-header h3', '.wallboard-card-header h3', '.biomie-map-card__head h2',
    '.mag-card-header h3', '.mag-card-header h2', '.omie-card-label'
  ].join(',');

  function normalize(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-zA-Z0-9%]+/g, ' ').trim().toLowerCase();
  }

  function slug(value) {
    return normalize(value).replace(/%/g, 'pct').replace(/\s+/g, '-').slice(0, 72) || 'resultado';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function categoryFor(value) {
    var text = normalize(value);
    if (/cancel|atras|vencid|risco|diverg|falta|sem nota|sem responsavel|conferir|pendencia/.test(text)) return 'attention';
    if (/meta|oportun|orcamento|previsao|projecao|conversao|crescimento/.test(text)) return 'growth';
    if (/recebid|fatur|vendid|caixa|dinheiro|ticket|pagamento|conciliad/.test(text)) return 'revenue';
    if (/produto|mercadoria|item|mix|estoque/.test(text)) return 'product';
    if (/cliente|vendedor|equipe|responsabilidade|contato|operador/.test(text)) return 'people';
    if (/mapa|bairro|regiao|territorio|localizacao|cnae/.test(text)) return 'territory';
    if (/carteira|aberto|pendente|aguardando|a receber|a vencer/.test(text)) return 'pending';
    return 'information';
  }

  function titleFor(target) {
    var title = target.querySelector('.biomie-kpi-card__label') || target.querySelector(headingSelectors);
    return title ? title.textContent.trim() : '';
  }

  function subtitleFor(target) {
    var subtitle = target.querySelector('.biomie-kpi-card__sub, .chart-card-header small, .table-card-header small, .dashboard-panel__header small, .fin-panel-head p, .finance-visual-card__head small, .finance-visual-head p, .fp-card-header small, .wallboard-card-header small, .biomie-map-card__head small, .mag-card-header small, .mag-card-header p, .omie-card-message');
    return subtitle ? subtitle.textContent.replace(/\s+/g, ' ').trim() : '';
  }

  function targetKind(target) {
    if (target.classList.contains('biomie-kpi-card')) return 'kpi';
    if (target.querySelector('canvas, .chart-shell, .mag-chart-container, .fin-chart-stage')) return 'chart';
    if (target.querySelector('table, .table-responsive, .fin-table-wrap')) return 'table';
    return 'panel';
  }

  function genericDefinition(target, title, category) {
    var kind = targetKind(target);
    var subtitle = subtitleFor(target);
    var summary = subtitle || 'Apresenta ' + title.toLowerCase() + ' conforme o período e os filtros selecionados.';
    var read = 'Compare o resultado com os demais indicadores para entender o cenário.';
    var action = '';

    if (kind === 'chart') {
      read = 'Observe cores, tamanho e evolução dos elementos para comparar os resultados.';
      action = 'Passe o mouse sobre o gráfico para ver valores e detalhes de cada ponto.';
    } else if (kind === 'table') {
      read = 'Cada linha representa um registro ou grupo relacionado a este resultado.';
      action = 'Use busca, filtros e ordenação; quando disponível, clique na linha para detalhar.';
    } else if (kind === 'panel') {
      read = 'Use este painel para aprofundar a leitura do assunto apresentado.';
    } else if (category === 'attention') {
      read = 'Valores maiores indicam mais casos que precisam de atenção.';
    } else if (category === 'growth') {
      read = 'Use como apoio para decidir onde concentrar a próxima ação comercial.';
    } else if (category === 'pending') {
      read = 'Este valor ainda não representa dinheiro ou venda confirmada.';
    }

    if (kind === 'kpi' && isInteractive(target)) {
      action = 'Clique no card para abrir os registros que formam este resultado.';
    }

    return {
      summary: summary,
      read: read,
      action: action,
      scope: 'A leitura respeita o período e os filtros ativos da página.',
      icon: categoryMeta[category].icon,
      tone: category
    };
  }

  function isInteractive(target) {
    return target.matches('button, a, [role="button"], [tabindex], [onclick], [data-drilldown], [data-detail], [data-action], .clickable, .is-clickable')
      || !!target.querySelector('a, button, [role="button"], [data-drilldown], [data-detail], [data-action]');
  }

  function definitionFor(target, title) {
    var key = normalize(title);
    var category = target.dataset.businessCategory || categoryFor(title);
    var explicit = target.dataset.cardHelp;
    var legacyTitle = target.getAttribute('title');
    var inheritedHelp = target.dataset.commercialHelp ? { summary: target.dataset.commercialHelp } : {};
    var topic = topicCatalog.find(function (item) { return item.pattern.test(key); });
    var definition = pageCatalog[page + ':' + key] || catalog[explicit] || catalog[key];
    var result = Object.assign({}, genericDefinition(target, title, category), topic ? topic.definition : {}, inheritedHelp, definition || {});

    if (legacyTitle && !(definition && definition.scope)) result.scope = legacyTitle;

    if (!result.action) {
      var kind = targetKind(target);
      if (kind === 'chart') result.action = 'Passe o mouse sobre o gráfico para consultar cada valor.';
      if (kind === 'table') result.action = 'Use os filtros e clique em uma linha quando houver detalhamento.';
    }

    result.tone = result.tone || category;
    result.icon = result.icon || categoryMeta[result.tone].icon;
    result.title = title;
    result.kind = targetKind(target);
    return result;
  }

  function meaningfulText(element) {
    if (!element) return '';
    var clone = element.cloneNode(true);
    clone.querySelectorAll('button, [data-business-help-trigger], .skeleton-text').forEach(function (item) { item.remove(); });
    var text = clone.textContent.replace(/\s+/g, ' ').trim();
    return /^[-—–]$/.test(text) ? '' : text;
  }

  function valueFor(target) {
    var value = target.querySelector('.biomie-kpi-card__value, .mag-card-value, .omie-card-value, [data-card-value]');
    return meaningfulText(value);
  }

  function snapshotFor(target, definition) {
    var valueNode = target.querySelector('.biomie-kpi-card__value, .mag-card-value, .omie-card-value, [data-card-value]');
    var value = valueFor(target);
    var kindLabels = {
      chart: 'Visualização comparativa em gráfico',
      table: 'Registros detalhados em tabela',
      panel: 'Painel de análise e acompanhamento'
    };
    return {
      value: value || kindLabels[definition.kind] || 'Indicador de resultado',
      subtitle: subtitleFor(target),
      binding: valueNode ? (valueNode.getAttribute('data-bind') || valueNode.id || '') : '',
      chartId: target.dataset.chartId || target.dataset.drawerType || '',
      kind: definition.kind
    };
  }

  function defaultKnowledge(definition) {
    var profiles = {
      revenue: {
        formula: 'Formado pelos registros confirmados que atendem ao período e aos filtros atuais.',
        includes: ['Resultados confirmados no recorte atual'],
        excludes: ['Registros fora do período ou dos filtros'],
        related: ['Volume', 'Valor médio', 'Evolução no período'],
        questions: ['O resultado está crescendo?', 'Quem mais contribui?', 'Há concentração ou dependência?']
      },
      growth: {
        formula: 'Compara o resultado atual com um objetivo, previsão ou oportunidade.',
        includes: ['Registros elegíveis no recorte atual'],
        excludes: ['Itens fora dos critérios apresentados'],
        related: ['Resultado confirmado', 'Valor pendente', 'Evolução'],
        questions: ['Qual é a distância para o objetivo?', 'O ritmo atual é suficiente?', 'Qual ação tem maior potencial?']
      },
      pending: {
        formula: 'Soma ou organiza valores ainda em andamento e não confirmados como resultado final.',
        includes: ['Registros pendentes dentro do recorte'],
        excludes: ['Resultados já concluídos ou cancelados'],
        related: ['Resultado confirmado', 'Tempo em aberto', 'Prioridades'],
        questions: ['O que pode ser concluído primeiro?', 'Há itens parados?', 'Quem é responsável pela próxima ação?']
      },
      attention: {
        formula: 'Reúne registros que atendem aos critérios de risco, atraso, cancelamento ou conferência.',
        includes: ['Casos sinalizados no recorte atual'],
        excludes: ['Registros sem o sinal de atenção apresentado'],
        related: ['Impacto financeiro', 'Responsável', 'Tempo da pendência'],
        questions: ['Qual caso tem maior impacto?', 'A causa se repete?', 'Qual ação deve acontecer primeiro?']
      },
      product: {
        formula: 'Agrupa vendas, quantidades ou oportunidades pelos produtos vinculados.',
        includes: ['Produtos encontrados nos registros filtrados'],
        excludes: ['Produtos fora do recorte atual'],
        related: ['Receita', 'Quantidade', 'Clientes', 'Recorrência'],
        questions: ['Quais produtos sustentam o resultado?', 'Há concentração em poucos clientes?', 'Qual produto pede uma ação agora?']
      },
      people: {
        formula: 'Agrupa os registros pelas pessoas ou empresas vinculadas ao resultado.',
        includes: ['Clientes, vendedores ou responsáveis identificados'],
        excludes: ['Registros sem vínculo quando tratados separadamente'],
        related: ['Resultado', 'Carteira', 'Concentração', 'Risco'],
        questions: ['Quem mais contribui?', 'Quem precisa de acompanhamento?', 'Há concentração excessiva?']
      },
      territory: {
        formula: 'Agrupa os registros pela localização ou classificação territorial identificada.',
        includes: ['Registros com território identificado'],
        excludes: ['Registros sem localização quando tratados separadamente'],
        related: ['Receita', 'Clientes', 'Cobertura territorial'],
        questions: ['Onde estão os melhores resultados?', 'Onde há espaço para crescer?', 'Quais registros precisam de localização?']
      },
      information: {
        formula: 'Organiza os registros que atendem ao período e aos filtros atuais.',
        includes: ['Dados disponíveis para o assunto apresentado'],
        excludes: ['Registros fora do recorte atual'],
        related: ['Período', 'Filtros ativos', 'Origem dos dados'],
        questions: ['O que mudou?', 'Qual item merece atenção?', 'Existe detalhe disponível para confirmar a leitura?']
      }
    };
    return profiles[definition.tone] || profiles.information;
  }

  function knowledgeFor(definition) {
    var key = normalize(definition.title);
    var topic = knowledgeTopics.find(function (item) { return item.pattern.test(key); });
    return Object.assign({}, defaultKnowledge(definition), topic ? topic.profile : {}, knowledgeCatalog[key] || {});
  }

  function readContextValue(binding) {
    var nodes = Array.from(document.querySelectorAll('[data-bind="' + binding + '"]'));
    for (var index = 0; index < nodes.length; index += 1) {
      var value = meaningfulText(nodes[index]);
      if (value) return value;
    }
    return '';
  }

  function isVisibleContext(element) {
    var node = element;
    while (node && node !== document.body) {
      if (node.hidden || node.getAttribute('aria-hidden') === 'true' || node.style.display === 'none') return false;
      if (window.getComputedStyle && window.getComputedStyle(node).display === 'none') return false;
      node = node.parentElement;
    }
    return true;
  }

  function activeFilters() {
    var seen = {};
    return Array.from(document.querySelectorAll('.biomie-filter-chip, .filter-chip, .mag-orders-filter-chip'))
      .filter(isVisibleContext)
      .map(meaningfulText)
      .filter(function (value) {
        if (!value || value.length > 100 || seen[value]) return false;
        seen[value] = true;
        return true;
      })
      .slice(0, 12);
  }

  function pageContext() {
    var values = [
      ['Período', readContextValue('period-label')],
      ['Visão atual', readContextValue('summary-mode')],
      ['Atualizado em', readContextValue('last-sync')],
      ['Fonte das informações', readContextValue('sync-source')],
      ['Dados disponíveis', readContextValue('sync-coverage')],
      ['Conferido até', readContextValue('sync-reconciled-until')]
    ].filter(function (item) { return item[1]; });
    return { values: values, filters: activeFilters() };
  }

  function visualizationText(kind) {
    if (kind === 'chart') return 'O gráfico resume padrões e comparações. Passe o mouse nos elementos, use a legenda e abra detalhes quando houver ação disponível.';
    if (kind === 'table') return 'A tabela permite conferir os registros que formam o resultado. Use busca, filtros e ordenação antes de comparar linhas.';
    if (kind === 'panel') return 'O painel reúne uma leitura temática. Compare seus blocos e abra os detalhamentos disponíveis para confirmar a análise.';
    return 'O indicador resume um resultado importante. Compare seu valor com os indicadores relacionados e abra o detalhamento quando disponível.';
  }

  function arrayList(items, className) {
    if (!items || !items.length) return '';
    return '<ul class="' + className + '">' + items.map(function (item) {
      return '<li>' + escapeHtml(item) + '</li>';
    }).join('') + '</ul>';
  }

  function chips(items, emptyText) {
    var values = items && items.length ? items : [emptyText];
    return '<div class="business-help-drawer__chips">' + values.map(function (item) {
      return '<span>' + escapeHtml(item) + '</span>';
    }).join('') + '</div>';
  }

  function contextGrid(items) {
    if (!items.length) return '<p class="business-help-drawer__muted">As informações de período e atualização não estão disponíveis neste momento.</p>';
    return '<dl class="business-help-drawer__context-grid">' + items.map(function (item) {
      return '<div><dt>' + escapeHtml(item[0]) + '</dt><dd>' + escapeHtml(item[1]) + '</dd></div>';
    }).join('') + '</dl>';
  }

  function drawerSection(icon, title, content, className) {
    if (!content) return '';
    return '<section class="business-help-drawer__section ' + (className || '') + '">' +
      '<h3><span class="material-symbols-rounded" aria-hidden="true">' + icon + '</span>' + escapeHtml(title) + '</h3>' +
      content +
    '</section>';
  }

  function ensurePortal() {
    var portal = document.getElementById(portalId);
    if (portal) return portal;

    portal = document.createElement('aside');
    portal.id = portalId;
    portal.className = 'business-help-popover';
    portal.setAttribute('role', 'dialog');
    portal.setAttribute('aria-modal', 'false');
    portal.setAttribute('aria-hidden', 'true');
    portal.addEventListener('pointerenter', cancelHide);
    portal.addEventListener('pointerleave', scheduleHide);
    portal.addEventListener('click', function (event) {
      event.stopPropagation();
    });
    document.body.appendChild(portal);
    return portal;
  }

  function ensureDrawer() {
    var drawer = document.getElementById(drawerId);
    if (drawer) return drawer;

    var backdrop = document.createElement('div');
    backdrop.id = backdropId;
    backdrop.className = 'business-help-drawer-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    backdrop.addEventListener('click', closeDrawer);

    drawer = document.createElement('aside');
    drawer.id = drawerId;
    drawer.className = 'business-help-drawer';
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');
    drawer.setAttribute('aria-hidden', 'true');
    drawer.addEventListener('click', function (event) { event.stopPropagation(); });

    document.body.appendChild(backdrop);
    document.body.appendChild(drawer);
    return drawer;
  }

  function deepDiveText(definition, snapshot, knowledge, context) {
    var lines = [
      definition.title,
      '',
      'Valor exibido agora: ' + snapshot.value,
      snapshot.subtitle ? 'Sobre este resultado: ' + snapshot.subtitle : '',
      '',
      'O que mostra: ' + definition.summary,
      'Como usar na decisão: ' + definition.read,
      'De onde vem o resultado: ' + knowledge.formula,
      '',
      'Considerado no resultado: ' + knowledge.includes.join('; '),
      'Fora do resultado: ' + knowledge.excludes.join('; '),
      'Compare antes de decidir: ' + knowledge.related.join('; '),
      '',
      'Perguntas para agir:',
      knowledge.questions.map(function (item) { return '- ' + item; }).join('\n'),
      '',
      context.values.map(function (item) { return item[0] + ': ' + item[1]; }).join('\n'),
      'Filtros ativos: ' + (context.filters.length ? context.filters.join('; ') : 'Nenhum filtro adicional visível')
    ];
    return lines.filter(function (line, index) { return line !== '' || lines[index - 1] !== ''; }).join('\n').trim();
  }

  function copyDeepDive(button) {
    var text = button.dataset.copyText || '';
    function confirmed() {
      clearTimeout(copyTimer);
      var original = button.innerHTML;
      button.innerHTML = '<span class="material-symbols-rounded" aria-hidden="true">check</span> Explicação copiada';
      button.classList.add('is-copied');
      copyTimer = window.setTimeout(function () {
        button.innerHTML = original;
        button.classList.remove('is-copied');
      }, 1800);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(confirmed).catch(function () {});
      return;
    }
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
      if (document.execCommand('copy')) confirmed();
    } catch (error) {}
    textarea.remove();
  }

  function renderDrawer(trigger) {
    var drawer = ensureDrawer();
    var definition = trigger._businessHelp;
    var target = trigger._businessHelpTarget;
    var meta = categoryMeta[definition.tone] || categoryMeta.information;
    var snapshot = snapshotFor(target, definition);
    var knowledge = knowledgeFor(definition);
    var context = pageContext();
    var technical = [snapshot.binding ? 'Campo do valor: ' + snapshot.binding : '', snapshot.chartId ? 'Gráfico relacionado: ' + snapshot.chartId : '', 'Código desta ajuda: ' + (target.dataset.cardHelpId || '')].filter(Boolean);

    drawer.dataset.businessCategory = definition.tone;
    drawer.setAttribute('aria-label', 'Explicação completa sobre ' + definition.title);
    drawer.innerHTML =
      '<header class="business-help-drawer__header">' +
        '<div class="business-help-drawer__heading">' +
          '<span class="business-help-drawer__icon material-symbols-rounded" aria-hidden="true">' + escapeHtml(definition.icon) + '</span>' +
          '<div><span class="business-help-drawer__eyebrow">' + escapeHtml(meta.label) + '</span><h2>' + escapeHtml(definition.title) + '</h2></div>' +
        '</div>' +
        '<button class="business-help-drawer__close" type="button" aria-label="Fechar explicação completa"><span class="material-symbols-rounded" aria-hidden="true">close</span></button>' +
      '</header>' +
      '<div class="business-help-drawer__body">' +
        '<section class="business-help-drawer__hero">' +
          '<span>Valor exibido agora</span><strong>' + escapeHtml(snapshot.value) + '</strong>' +
          (snapshot.subtitle ? '<p>' + escapeHtml(snapshot.subtitle) + '</p>' : '') +
        '</section>' +
        drawerSection('visibility', 'Leitura rápida', '<p><strong>O que mostra:</strong> ' + escapeHtml(definition.summary) + '</p><p><strong>Como usar na decisão:</strong> ' + escapeHtml(definition.read) + '</p>') +
        drawerSection('calculate', 'De onde vem o resultado', '<p>' + escapeHtml(knowledge.formula) + '</p><p class="business-help-drawer__rule"><span class="material-symbols-rounded" aria-hidden="true">rule</span>' + escapeHtml(definition.scope || 'Respeita o período e os filtros ativos.') + '</p>') +
        '<div class="business-help-drawer__split">' +
          drawerSection('add_circle', 'Considerado no resultado', arrayList(knowledge.includes, 'business-help-drawer__list business-help-drawer__list--include')) +
          drawerSection('remove_circle', 'Fora do resultado', arrayList(knowledge.excludes, 'business-help-drawer__list business-help-drawer__list--exclude')) +
        '</div>' +
        drawerSection('compare_arrows', 'Compare antes de decidir', chips(knowledge.related, 'Outros resultados desta página')) +
        drawerSection('filter_alt', 'Recorte atual', chips(context.filters, 'Nenhum filtro adicional visível')) +
        drawerSection('calendar_month', 'Período e atualização', contextGrid(context.values)) +
        drawerSection('checklist', 'Perguntas para agir', arrayList(knowledge.questions, 'business-help-drawer__checklist')) +
        drawerSection('touch_app', 'Próximo passo sugerido', '<p>' + escapeHtml(definition.action || 'Compare este resultado com os indicadores relacionados e abra o detalhamento disponível.') + '</p>') +
        drawerSection('bar_chart', 'Como explorar', '<p>' + escapeHtml(visualizationText(snapshot.kind)) + '</p>') +
        (technical.length ? '<details class="business-help-drawer__technical"><summary>Conferência do sistema (opcional)</summary>' + arrayList(technical, 'business-help-drawer__technical-list') + '</details>' : '') +
      '</div>' +
      '<footer class="business-help-drawer__footer">' +
        '<button class="business-help-drawer__copy" type="button"><span class="material-symbols-rounded" aria-hidden="true">content_copy</span> Copiar leitura</button>' +
        '<button class="business-help-drawer__done" type="button">Fechar leitura</button>' +
      '</footer>';

    drawer.querySelector('.business-help-drawer__close').addEventListener('click', closeDrawer);
    drawer.querySelector('.business-help-drawer__done').addEventListener('click', closeDrawer);
    var copyButton = drawer.querySelector('.business-help-drawer__copy');
    copyButton.dataset.copyText = deepDiveText(definition, snapshot, knowledge, context);
    copyButton.addEventListener('click', function () { copyDeepDive(copyButton); });
  }

  function openDrawer(trigger) {
    drawerTrigger = trigger;
    closePopover();
    renderDrawer(trigger);
    var drawer = ensureDrawer();
    var backdrop = document.getElementById(backdropId);
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    backdrop.classList.add('is-open');
    backdrop.setAttribute('aria-hidden', 'false');
    document.body.classList.add('business-help-drawer-open');
    window.setTimeout(function () {
      var closeButton = drawer.querySelector('.business-help-drawer__close');
      if (closeButton) closeButton.focus();
    }, 0);
  }

  function closeDrawer() {
    var drawer = document.getElementById(drawerId);
    var backdrop = document.getElementById(backdropId);
    if (drawer) {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
    }
    if (backdrop) {
      backdrop.classList.remove('is-open');
      backdrop.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('business-help-drawer-open');
    var trigger = drawerTrigger;
    drawerTrigger = null;
    if (trigger && document.contains(trigger)) trigger.focus();
  }

  function line(icon, label, value, className) {
    if (!value) return '';
    return '<div class="business-help-popover__line ' + (className || '') + '">' +
      '<span class="material-symbols-rounded" aria-hidden="true">' + icon + '</span>' +
      '<div><strong>' + escapeHtml(label) + '</strong><p>' + escapeHtml(value) + '</p></div>' +
      '</div>';
  }

  function renderPopover(trigger) {
    var portal = ensurePortal();
    var definition = trigger._businessHelp;
    var meta = categoryMeta[definition.tone] || categoryMeta.information;
    portal.dataset.businessCategory = definition.tone;
    portal.setAttribute('aria-label', 'Ajuda sobre ' + definition.title);
    portal.innerHTML =
      '<div class="business-help-popover__topline"></div>' +
      '<header class="business-help-popover__header">' +
        '<span class="business-help-popover__icon material-symbols-rounded" aria-hidden="true">' + escapeHtml(definition.icon) + '</span>' +
        '<div><span class="business-help-popover__eyebrow">' + escapeHtml(meta.label) + '</span>' +
        '<strong class="business-help-popover__title">' + escapeHtml(definition.title) + '</strong></div>' +
        '<button class="business-help-popover__close" type="button" aria-label="Fechar explicação"><span class="material-symbols-rounded" aria-hidden="true">close</span></button>' +
      '</header>' +
      '<div class="business-help-popover__body">' +
        line('visibility', 'O que mostra', definition.summary) +
        line('lightbulb', 'Como usar na decisão', definition.read) +
        line('touch_app', 'Como explorar', definition.action, 'business-help-popover__line--action') +
        line('rule', 'O que considerar', definition.scope, 'business-help-popover__line--scope') +
      '</div>' +
      '<footer class="business-help-popover__footer">' +
        '<button class="business-help-popover__deep-dive" type="button"><span class="material-symbols-rounded" aria-hidden="true">menu_book</span> Ver leitura comercial <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span></button>' +
      '</footer>';

    portal.querySelector('.business-help-popover__close').addEventListener('click', closePopover);
    portal.querySelector('.business-help-popover__deep-dive').addEventListener('click', function () { openDrawer(trigger); });
    portal.setAttribute('aria-hidden', 'false');
    portal.classList.add('is-open');
    trigger.setAttribute('aria-expanded', 'true');
    trigger.setAttribute('aria-describedby', portalId);
    positionPopover();
  }

  function positionPopover() {
    if (!openTrigger) return;
    var portal = ensurePortal();
    var rect = openTrigger.getBoundingClientRect();
    var target = openTrigger._businessHelpTarget;
    var anchorRect = target && target.classList.contains('biomie-kpi-card')
      ? target.getBoundingClientRect()
      : rect;
    var margin = 10;
    var gap = 10;
    var width = Math.min(330, window.innerWidth - margin * 2);
    portal.style.width = width + 'px';
    portal.style.left = margin + 'px';
    portal.style.top = margin + 'px';

    var popRect = portal.getBoundingClientRect();
    var placeBelow = anchorRect.top < popRect.height + gap + margin;
    var top = placeBelow ? anchorRect.bottom + gap : anchorRect.top - popRect.height - gap;
    var left = rect.left + (rect.width / 2) - (popRect.width / 2);
    left = Math.max(margin, Math.min(left, window.innerWidth - popRect.width - margin));
    top = Math.max(margin, Math.min(top, window.innerHeight - popRect.height - margin));

    portal.dataset.placement = placeBelow ? 'bottom' : 'top';
    portal.style.left = Math.round(left) + 'px';
    portal.style.top = Math.round(top) + 'px';
    portal.style.setProperty('--business-help-arrow-left', Math.round(Math.max(18, Math.min(rect.left + rect.width / 2 - left, popRect.width - 18))) + 'px');
  }

  function openPopover(trigger, shouldPin) {
    clearTimeout(showTimer);
    clearTimeout(hideTimer);
    if (openTrigger && openTrigger !== trigger) {
      openTrigger.setAttribute('aria-expanded', 'false');
      openTrigger.removeAttribute('aria-describedby');
    }
    openTrigger = trigger;
    pinned = !!shouldPin;
    renderPopover(trigger);
  }

  function closePopover() {
    clearTimeout(showTimer);
    clearTimeout(hideTimer);
    var portal = ensurePortal();
    portal.classList.remove('is-open');
    portal.setAttribute('aria-hidden', 'true');
    if (openTrigger) {
      openTrigger.setAttribute('aria-expanded', 'false');
      openTrigger.removeAttribute('aria-describedby');
    }
    openTrigger = null;
    pinned = false;
  }

  function scheduleShow(trigger) {
    clearTimeout(hideTimer);
    if (openTrigger === trigger) return;
    clearTimeout(showTimer);
    showTimer = window.setTimeout(function () { openPopover(trigger, false); }, 240);
  }

  function scheduleHide() {
    clearTimeout(showTimer);
    if (pinned) return;
    clearTimeout(hideTimer);
    hideTimer = window.setTimeout(closePopover, 140);
  }

  function cancelHide() {
    clearTimeout(hideTimer);
  }

  function createTrigger(target, titleElement, definition) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'business-help-trigger';
    button.dataset.businessHelpTrigger = '1';
    button.setAttribute('aria-label', 'Entenda este card: ' + definition.title);
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', portalId);
    button.innerHTML = '<span class="material-symbols-rounded" aria-hidden="true">info</span>';
    button._businessHelp = definition;
    button._businessHelpTarget = target;

    button.addEventListener('pointerenter', function () { scheduleShow(button); });
    button.addEventListener('pointerleave', scheduleHide);
    button.addEventListener('focus', function () { openPopover(button, false); });
    button.addEventListener('blur', scheduleHide);
    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (openTrigger === button && pinned) closePopover();
      else openPopover(button, true);
    });
    button.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.stopPropagation();
        closePopover();
        button.focus();
      }
    });

    titleElement.classList.add('business-help-title');
    titleElement.appendChild(button);
    target.dataset.cardHelpId = page + '.' + slug(definition.title);
    target.dataset.businessHelpTitle = normalize(definition.title);
    target.dataset.cardHelpCoverage = catalog[normalize(definition.title)] || pageCatalog[page + ':' + normalize(definition.title)] ? 'specific' : 'contextual';
    target.dataset.businessCategory = definition.tone;
    target.classList.add('business-help-enabled');
    target.removeAttribute('title');
  }

  function enhanceTarget(target) {
    if (!target) return;
    if (target.closest('.mag-technical-diagnostics, .finance-insight-diagnostics, [data-technical-language], [data-card-help-disabled]')) return;

    var titleElement = target.classList.contains('biomie-kpi-card')
      ? target.querySelector('.biomie-kpi-card__label')
      : target.querySelector(headingSelectors);
    if (!titleElement) return;

    var existingTrigger = titleElement.querySelector('[data-business-help-trigger]');
    var titleClone = titleElement.cloneNode(true);
    titleClone.querySelectorAll('[data-business-help-trigger]').forEach(function (trigger) { trigger.remove(); });
    var title = titleClone.textContent.replace(/\s+/g, ' ').trim();
    if (!title) return;
    if (existingTrigger && target.dataset.businessHelpTitle === normalize(title)) return;
    if (existingTrigger) existingTrigger.remove();

    target.dataset.businessHelpEnhanced = '1';
    createTrigger(target, titleElement, definitionFor(target, title));
  }

  function process(root) {
    var scope = root && root.querySelectorAll ? root : document;
    if (root && root.closest) {
      var closestTarget = root.closest('.biomie-kpi-card, ' + panelSelectors);
      if (closestTarget) enhanceTarget(closestTarget);
    }
    if (root && root.matches && (root.matches('.biomie-kpi-card') || root.matches(panelSelectors))) enhanceTarget(root);
    scope.querySelectorAll('.biomie-kpi-card, ' + panelSelectors).forEach(enhanceTarget);
  }

  function getCoverage() {
    var targets = Array.from(document.querySelectorAll('.biomie-kpi-card, ' + panelSelectors))
      .filter(function (target) {
        return !target.closest('.mag-technical-diagnostics, .finance-insight-diagnostics, [data-technical-language], [data-card-help-disabled]');
      });
    var enhanced = targets.filter(function (target) { return target.dataset.businessHelpEnhanced === '1'; });
    var specific = enhanced.filter(function (target) { return target.dataset.cardHelpCoverage === 'specific'; });
    return {
      total: targets.length,
      enhanced: enhanced.length,
      specific: specific.length,
      contextual: enhanced.length - specific.length,
      coveragePct: targets.length ? Math.round(enhanced.length / targets.length * 100) : 100
    };
  }

  function init() {
    if (!document.body || document.body.dataset.cardHelpDisabled === '1') return;
    document.body.classList.add('commercial-card-help-enabled');
    ensurePortal();
    ensureDrawer();
    process(document);

    new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        if (mutation.type === 'characterData') {
          process(mutation.target.parentElement || document);
          return;
        }
        mutation.addedNodes.forEach(function (node) {
          if (node.nodeType !== Node.ELEMENT_NODE) return;
          if (node.closest('#' + portalId + ', #' + drawerId + ', #' + backdropId)) return;
          process(node);
        });
      });
    }).observe(document.body, { childList: true, characterData: true, subtree: true });

    document.addEventListener('click', function (event) {
      if (!openTrigger || !pinned) return;
      var portal = ensurePortal();
      if (!portal.contains(event.target) && event.target !== openTrigger) closePopover();
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && drawerTrigger) {
        event.preventDefault();
        closeDrawer();
        return;
      }
      if (event.key === 'Escape' && openTrigger) closePopover();
      if (event.key === 'Tab' && drawerTrigger) {
        var drawer = ensureDrawer();
        var focusable = Array.from(drawer.querySelectorAll('button, a[href], summary, [tabindex]:not([tabindex="-1"])'))
          .filter(function (item) { return !item.disabled; });
        if (!focusable.length) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });
    window.addEventListener('resize', positionPopover, { passive: true });
    window.addEventListener('scroll', positionPopover, { passive: true, capture: true });
  }

  window.BIomieCardHelp = {
    refresh: function () { process(document); },
    getCoverage: getCoverage,
    catalog: catalog,
    knowledgeCatalog: knowledgeCatalog,
    close: function () { closePopover(); closeDrawer(); }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
