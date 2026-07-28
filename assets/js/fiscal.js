/* ══════════════════════════════════════════════════════════════════
   BIomie — Fiscal Intelligence JS
   Accent: indigo #6366f1
══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── palette ─────────────────────────────────────────────────── */
  const C = {
    icms  : '#6366f1',
    pis   : '#8b5cf6',
    cofins: '#a78bfa',
    ipi   : '#c4b5fd',
    iss   : '#ddd6fe',
    cbs   : '#10b981',
    ibs   : '#34d399',
    trend : '#6366f1',
    fat   : 'rgba(99,102,241,0.15)',
  };

  /* ── private alert store (replaces window.__fisAlerts) ──────── */
  let _fisAlerts = {};

  /* ── Help content dictionary ─────────────────────────────────── */
  const HELP = {
    faturamento: {
      title: 'Faturamento Bruto',
      what: 'Soma do valor total de todas as Notas Fiscais com status "autorizada" emitidas no período. Inclui o ICMS, PIS, COFINS, IPI e ISS embutidos no preço — é a receita bruta antes de qualquer dedução.',
      formula: 'Σ( valor_total )  das NFs com  status = "autorizada"\n  e  data_emissao ∈ [início, fim]\n\nNFs = COUNT( nota_key )  no mesmo filtro',
      reading: 'Este é o topo da sua DRE Fiscal. Todos os percentuais de carga tributária são calculados sobre este valor. Queda no faturamento com carga% estável indica problema comercial, não fiscal.',
      action: 'Compare com o mês anterior e com a meta definida no Plano de Voo. Se cair enquanto a carga% sobe, revise o mix de produtos e os NCMs.'
    },
    tributos_totais: {
      title: 'Tributos Totais',
      what: 'Soma de todos os tributos destacados nas NFs de saída: ICMS + PIS + COFINS + IPI + ISS. É o custo tributário bruto das vendas — o que a empresa repassará ao governo antes de compensar créditos das compras.',
      formula: 'Σ( icms + pis + cofins + ipi + iss )  por NF autorizada\n\nCarga% = Tributos Totais ÷ Faturamento × 100\n\n% de cada imposto = imposto ÷ Tributos Totais × 100',
      reading: 'Empresas comerciais no Lucro Real têm carga bruta típica entre 22% e 35%. Acima de 35% exige revisão de NCM, CFOP e benefícios fiscais. Este valor não considera créditos das compras — consulte "Apuração Gerencial" para o saldo líquido.',
      action: 'Se a carga% crescer sem mudança de mix, verifique se algum produto mudou de NCM ou CFOP recentemente. Acione a seção NCM para identificar os outliers.'
    },
    icms: {
      title: 'ICMS — Imposto sobre Circulação de Mercadorias e Serviços',
      what: 'Tributo estadual do Ceará que incide sobre saídas de mercadorias, energia, comunicações e transporte. A alíquota interna padrão no CE é 20% (incluindo 2% do FECOP). Pode variar por produto via Decreto estadual de benefícios fiscais.',
      formula: 'Σ( valor_icms )  das NFs autorizadas no período\n\n% do total tributário = ICMS ÷ Tributos Totais × 100\n\nAlíquota efetiva = ICMS ÷ Faturamento × 100\n\nCom FECOP: base 18% + 2% para produtos supérfluos = 20%',
      reading: 'Normalmente representa 55–70% do total de tributos em empresas comerciais cearenses. No Lucro Real, o ICMS das entradas (compras) gera crédito que reduz o saldo a recolher. Alíquota efetiva acima de 18% indica itens sem benefício fiscal aplicado.',
      action: 'Se o ICMS efetivo for maior que o esperado, verifique na tabela NCM quais produtos têm alíquota acima da média. Consulte a tabela de benefícios fiscais do CE para os NCMs mais relevantes.'
    },
    pis_cofins: {
      title: 'PIS + COFINS — Contribuições Federais sobre a Receita',
      what: 'Contribuições federais calculadas sobre a receita bruta das vendas. No Lucro Real com apuração não-cumulativa, os créditos das compras abatam os débitos das vendas — é o principal mecanismo de redução da carga federal.',
      formula: 'Σ( valor_pis + valor_cofins )  das NFs autorizadas\n\nLucro Real não-cumulativo:\n  PIS saídas   = valor_total × 1,65%\n  COFINS saídas = valor_total × 7,60%\n  PIS + COFINS = 9,25% sobre a receita bruta\n\n% do faturamento = (PIS + COFINS) ÷ Faturamento × 100',
      reading: 'No regime não-cumulativo, cada compra de mercadoria para revenda gera crédito de PIS 1,65% + COFINS 7,6% que abate os débitos das saídas. Se a carga líquida de PIS+COFINS for próxima de 9,25%, há pouco aproveitamento de créditos — oportunidade de melhora.',
      action: 'Cruce com o "Crédito Potencial" na seção Compras. Se crédito potencial > 15% dos débitos de PIS+COFINS, acione o contador para revisar o SPED e garantir o aproveitamento.'
    },
    ipi: {
      title: 'IPI — Imposto sobre Produtos Industrializados',
      what: 'Tributo federal que incide sobre saídas de produtos industrializados pelo próprio contribuinte. Para empresas puramente comerciais (sem processo de industrialização), o IPI das saídas é normalmente zero — o IPI vem destacado nas compras do fabricante, mas não é repassado nas revendas.',
      formula: 'Σ( valor_ipi )  das NFs autorizadas no período\nAlíquotas variam por produto conforme a TIPI\n(Tabela de Incidência do IPI)\n\nIPI saída > 0 em empresa comercial → verificar CFOP',
      reading: 'Empresa comercial sem industrialização com IPI relevante nas saídas pode estar emitindo com CFOP incorreto (ex: 5101 "venda de produto industrializado" ao invés de 5102 "venda de mercadoria adquirida ou recebida de terceiros") ou classificando produtos com NCM de industrializado.',
      action: 'Acesse a tabela NCM e filtre por alíq_icms > 0 onde a empresa não industrializa. Se IPI > 2% do faturamento em empresa comercial, convoque revisão fiscal urgente com o contador.'
    },
    cbs_info: {
      title: 'CBS — Contribuição sobre Bens e Serviços (Informativo 2026)',
      what: 'A CBS substitui PIS e COFINS a partir da Reforma Tributária. É um tributo federal de alíquota única com plena não-cumulatividade — crédito integral de todas as compras. Em 2026, os valores no campo vCbs da NF-e são informativos. Não há recolhimento obrigatório ainda.',
      formula: 'Σ( valor_cbs )  dos itens do mart fiscal\n(campo vCbs da NF-e, extraído do XML)\n\nAlíquota de referência CBS 2026: ~9,9% sobre o valor\nIBS (estadual+municipal) ~26,5% somado ao CBS = 36,4%\n\nRedução estimada CBS vs PIS+COFINS:\n  Diferença = CBS − (PIS + COFINS) atuais',
      reading: 'Se CBS < PIS+COFINS atual → tendência de queda na carga federal com a reforma (bom para empresas com poucas compras de não-contribuintes). Se CBS > PIS+COFINS atual → atenção ao impacto no preço de venda a partir de 2027.',
      action: 'Use os dados da seção Reforma para simular impacto na precificação a partir de 2027. Compartilhe com o contador para planejamento tributário antecipado — a transição entre CBS e PIS/COFINS ocorre entre 2026 e 2033.'
    },
    notas_analisadas: {
      title: 'Notas Fiscais Analisadas',
      what: 'Contagem total de NFs no período selecionado, independente do status: autorizadas + canceladas. É o universo completo de documentos fiscais que serviu como base para todos os demais cálculos da página.',
      formula: 'COUNT( nota_key )  WHERE data_emissao ∈ [início, fim]\n(sem filtro de status — inclui canceladas)',
      reading: 'Se o número for muito menor do que o esperado para o período (ex: empresa emite 200 NFs/mês e aparece 50), verifique se o período está correto ou se as notas foram sincronizadas do Omie. Número zerado = sem dados no período.',
      action: 'Se notas analisadas divergir do total no ERP, sincronize novamente ou ajuste o intervalo de datas. Em caso de persistência, verifique se há NFs em rascunho não autorizadas na SEFAZ.'
    },
    notas_alerta: {
      title: 'Notas com Alerta Fiscal',
      what: 'Quantidade de NFs que possuem pelo menos um dos 9 tipos de alerta: ICMS zero em item > R$ 500, produto sem NCM, XML/chave NF-e ausente, sem título financeiro vinculado, carga acima da média do período, CBS/IBS zero no mart, CFOP incomum (ex: 6000 em NF local), ou PIS/COFINS divergente do esperado.',
      formula: 'COUNT( DISTINCT nota_key )\n  WHERE tem alerta de qualquer tipo\n\n% alertas = Notas com alerta ÷ Total NFs × 100\n\nPeso dos alertas no Score de Risco:\n  XML pendente → ×3.0 (crítico)\n  Sem NCM      → ×2.0 (alto)\n  ICMS zero    → ×2.0 (alto)\n  Sem título   → ×1.0 (médio)',
      reading: 'Até 3% de alertas é aceitável para particularidades pontuais (ex: NF de remessa que legitimamente tem ICMS zero). Entre 3-10%: processo de emissão precisa de revisão. Acima de 10%: problema sistêmico — risco de autuação fiscal.',
      action: 'Clique em "Alertas Fiscais" para ver o detalhamento por tipo. Priorize na ordem: XMLs pendentes → itens sem NCM → ICMS zero → sem título financeiro.'
    },
    xmls_pendentes: {
      title: 'XMLs / Chaves NF-e Pendentes',
      what: 'Quantidade de NFs que não possuem a chave de acesso de 44 dígitos (chave NF-e) vinculada no sistema. Sem o XML, não é possível escriturar no SPED Fiscal, apresentar em fiscalização da SEFAZ ou usar como prova em auditoria.',
      formula: 'COUNT( nota_key )\n  WHERE chave_nfe IS NULL\n     OR TRIM( chave_nfe ) = ""\n\nChave NF-e = 44 dígitos gerados na autorização\n(identificação única do documento na SEFAZ)',
      reading: 'Zero é o único alvo aceitável. Cada NF sem XML é um passivo fiscal latente. Em caso de fiscalização, NF sem XML válido pode ser considerada inidônea pelo Fisco — gerando multas de 50–150% do valor do imposto.',
      action: 'Abra cada NF sem XML no Omie e vincule a chave de acesso. Se a NF foi cancelada na SEFAZ sem contrapartida, emita uma NF de entrada (devolução). Se perdeu a chave, busque no portal da SEFAZ-CE com o CNPJ e a data.'
    },
    carga_media: {
      title: 'Carga Tributária Média por NF',
      what: 'Média aritmética da carga tributária de cada NF individualmente, não ponderada pelo valor. Diferente da carga geral do período (que dá peso maior às NFs de maior valor), esta métrica representa a nota "típica" da empresa.',
      formula: 'AVG( valor_trib_total ÷ NULLIF(valor_total, 0) × 100 )\n  por NF com valor_total > 0 e status = "autorizada"\n\nServido como referência para os alertas de\n"carga acima da média" nos cards individuais:\n  Amarelo = carga da NF > 110% desta média\n  Vermelho = carga da NF > 125% desta média',
      reading: 'Quanto mais próximo da carga geral do período, mais homogêneo é o mix de produtos. Grande diferença entre carga média por NF e carga geral indica poucas NFs de alto valor puxando a média geral.',
      action: 'Use a ordenação por "Carga%" na tabela de NFs para identificar as notas muito acima desta referência. Investigue se há erro de NCM ou CFOP nas outliers.'
    },
    maior_carga: {
      title: 'Maior Carga Fiscal Individual',
      what: 'A carga tributária percentual mais alta encontrada entre todas as NFs autorizadas do período. Representa o caso extremo — a NF onde mais proporção do faturamento foi para tributos.',
      formula: 'MAX( valor_trib_total ÷ NULLIF(valor_total, 0) × 100 )\n  entre NFs com valor_total > 0 e status = "autorizada"\n\nEx: NF de R$ 10.000 com R$ 4.200 em tributos\n  → carga individual = 42%',
      reading: 'Cargas acima de 50% são quase sempre erro: NCM errado, CFOP de transferência aplicado em venda, benefício fiscal não configurado, ou item que deveria ser isento tributado normalmente. Entre 35-50%: verifique se é produto com substituição tributária elevada.',
      action: 'Acesse a tabela de NFs, ordene por Carga% (decrescente) e abra o drawer de detalhes da primeira linha. Veja os itens, NCMs e alíquotas. Se confirmar erro, emita carta de correção ou NF complementar e acione o contador.'
    },
    divergencia_fiscal: {
      title: 'Divergência Fiscal × Financeiro',
      what: 'Diferença entre o total de NFs de saída autorizadas e o total de títulos financeiros (boletos, PIX, cheques) gerados para os mesmos clientes no período. Identifica receita faturada sem cobrança ou cobranças sem nota.',
      formula: 'Divergência = Σ(valor_total NFs autorizadas)\n            − Σ(valor_total títulos financeiros vinculados)\n\nPositivo = NF sem título (faturou mas não cobrou)\nNegativo = título sem NF (cobrou mas não emitiu NF)\nZero     = perfeito alinhamento fiscal-financeiro',
      reading: 'Divergência positiva > 5% do faturamento representa risco real de receita não cobrada ou NFs emitidas com erro que precisam ser canceladas/corrigidas. Divergência negativa indica passivo fiscal — clientes pagando sem NF, sujeito a autuação.',
      action: 'Acesse Financeiro > Recebíveis e filtre por NFs sem título vinculado. Verifique se são vendas a prazo ainda sem boleto gerado, NFs de bonificação ou NFs emitidas com erro de valor. Cada caso tem um tratamento diferente.'
    },
    score_risco: {
      title: 'Score de Risco Fiscal',
      what: 'Pontuação de 0 a 100 que agrega todos os alertas fiscais em um único número, ponderando cada tipo de problema pela sua gravidade legal e fiscal. Permite monitorar a saúde fiscal da empresa ao longo do tempo com uma única métrica.',
      formula: 'Score = [\n  (xmls_pendentes   × 3.0) +\n  (sem_ncm          × 2.0) +\n  (icms_zero        × 2.0) +\n  (sem_titulo       × 1.0) +\n  (canceladas_rel.  × 0.5)\n] ÷ total_nfs × 100\n\nNormalizado: máximo = 100\n\n0–9:   Baixo risco ✓\n10–24: Atenção ⚠\n≥ 25:  Risco alto — ação urgente ✗',
      reading: 'Empresas saudáveis ficam abaixo de 10. O score sobe rapidamente quando há XMLs pendentes (peso 3×) — um único lote de NFs sem XML pode levar o score de 0 para 30 em um período. Acompanhe a evolução mês a mês.',
      action: 'Priorize na ordem de peso: (1) Vincule os XMLs pendentes, (2) complete os NCMs faltantes, (3) investigue os ICMS zerados, (4) gere os títulos financeiros faltantes. Cada ação reduz o score proporcionalmente.'
    },
    tributos_brutos: {
      title: 'Tributos Brutos das Saídas',
      what: 'Soma de todos os tributos destacados nas NFs de saída antes de qualquer compensação por créditos das entradas ou retenções na fonte. É o "débito tributário bruto" — o que a empresa deve ao governo antes de abater o que pagou nas compras.',
      formula: 'Σ( ICMS + ICMS-ST + DIFAL + FECOP + PIS + COFINS + IPI + ISS )\n  das NFs autorizadas no período\n\nCarga bruta% = Tributos brutos ÷ Faturamento × 100',
      reading: 'É o "deve" antes do "haver". Sempre será maior ou igual ao saldo líquido a recolher. Compare com o mês anterior: crescimento acima do crescimento do faturamento indica aumento de carga — verifique mudanças de mix, novos CFOPs ou alteração de alíquotas.',
      action: 'Use a planilha de competência no modo "Por Imposto" para ver qual tributo cresceu mais. Leve essa análise para o contador com os dados do período anterior para identificar a causa raiz.'
    },
    creditos_entrada: {
      title: 'Créditos de Entrada (Potencial)',
      what: 'Tributos destacados nas NFs de entrada (compras de fornecedores) que potencialmente podem ser aproveitados como crédito, compensando os débitos das saídas. O adjetivo "Potencial" significa que a validação final é sempre da contabilidade — nem todo destaque gera crédito automático.',
      formula: 'Σ( icms + pis + cofins + ipi )  das entradas do Omie\n  filtradas por CST e CFOP compatíveis com crédito\n\nRegra de ouro (Lucro Real):\n  Mercadoria para revenda:    crédito integral\n  Serviço/insumo da atividade: crédito integral\n  Uso e consumo:               sem crédito (até 2028)\n  Ativo imobilizado:           1/48 avos/mês\n\nÉ "Pendente" quando as entradas do Omie ainda\nnão foram sincronizadas para o período',
      reading: 'No Lucro Real, cada R$ 1,00 de crédito potencial confirmado reduz em R$ 1,00 o saldo a recolher de PIS/COFINS ou ICMS. É o principal instrumento de otimização tributária sem nenhum risco legal.',
      action: 'Leve este relatório ao contador com o detalhamento das entradas (aba Compras) e solicite a revisão do aproveitamento no SPED Fiscal. Créditos não aproveitados são pagamento a maior de tributos — é dinheiro que pode ser recuperado.'
    },
    retencoes: {
      title: 'Retenções na Fonte Identificadas',
      what: 'Tributos que o tomador de serviços (seu cliente) retém na fonte e recolhe diretamente ao governo em seu nome. Reduzem o valor que você precisa recolher por conta própria, pois o pagamento já foi feito pelo tomador.',
      formula: 'Σ( pis_retido + cofins_retido + csll_retida + irrf_retido )\n  dos títulos financeiros no Omie (contas a receber)\n  com marcação de retenção\n\nObrigatoriedade: tomadores que pagam serviços acima de\n  R$ 215,05 (PIS/COFINS/CSLL) ou R$ 10 (IRRF)\n  devem reter e recolher em nome do prestador',
      reading: 'Empresas prestadoras de serviços (NFS-e) geralmente têm retenções significativas. Empresas de comércio de mercadorias (NF-e) raramente têm retenções — se aparecer valor alto, pode ser retenção em contratos de prestação de serviço específicos.',
      action: 'Verifique se as retenções registradas no Omie estão sendo compensadas no SPED e nas DARFs mensais. Retenção registrada mas não compensada = pagamento duplo ao fisco.'
    },
    saldo_liquido: {
      title: 'Saldo Líquido Gerencial',
      what: 'Estimativa do custo tributário real do período, após compensar os créditos das compras e as retenções na fonte. É o valor que efetivamente "sai do caixa" da empresa para tributos — o número que deve guiar decisões de precificação e margem.',
      formula: 'Saldo Líquido = Tributos Brutos\n              − Créditos de Entrada\n              − Retenções na Fonte\n\nCarga líquida% = Saldo Líquido ÷ Faturamento × 100\n\nImpacto no preço:\n  Para cada R$ 1,00 vendido,\n  R$ (carga_liquida%) vai para tributos',
      reading: 'É o número que deve ser usado em precificação, análise de margem e planejamento de caixa. Uma empresa com carga bruta de 28% e créditos de 9% tem carga líquida de ~19% — e é essa que define a competitividade do preço de venda.',
      action: 'Use a carga líquida como base para calcular o preço de venda mínimo e a margem real. Se o saldo líquido crescer mais rápido que o faturamento, é hora de revisar a política de compras ou negociar com o contador uma estratégia de crédito mais agressiva.'
    },
    dre_receita_bruta: {
      title: 'Receita Bruta — DRE Fiscal',
      what: 'Total das NFs autorizadas no período. Representa o topo da Demonstração do Resultado — a receita antes de qualquer dedução. Inclui todos os tributos embutidos no preço, pois no modelo de NF-e eles compõem o valor total da nota.',
      formula: 'Σ( valor_total )  das NFs com status = "autorizada"\n\nIdêntico ao KPI Faturamento.\nServido como topo da equação fiscal:\n  Receita Bruta − Tributos = Receita Líquida Fiscal',
      reading: 'Topo da cascata da DRE Fiscal. Todos os percentuais de carga são calculados em relação a este valor. Importante: pela legislação contábil, tributos como ICMS, PIS, COFINS não são receita da empresa — são "passantes" que a empresa arrecada para o governo.',
      action: 'Para fins de gestão, monitore a evolução mês a mês. Queda na receita bruta sem queda na carga% = problema comercial; queda na receita bruta com subida na carga% = combinação preocupante que exige ação imediata.'
    },
    dre_tributos: {
      title: 'Tributos — DRE Fiscal',
      what: 'Soma dos tributos que a legislação classifica como deduções da receita bruta: ICMS, PIS, COFINS, IPI e ISS. Pela teoria contábil, esses valores não pertencem à empresa — ela arrecada em nome do Estado e repassa.',
      formula: 'Σ( valor_icms + valor_pis + valor_cofins + valor_ipi + valor_iss )\n  das NFs autorizadas\n\nCarga% = Tributos ÷ Receita Bruta × 100\n\nTributos "por dentro" (ICMS, ISS):\n  São calculados sobre o valor total (base inclusiva)\nTributos "por fora" (IPI):\n  São somados ao valor dos produtos',
      reading: 'Esta linha mostra quanto da sua receita bruta "passa" pelo caixa mas vai direto para o governo. Para setores com ICMS alto (ex: distribuidoras com alíquota 20%), essa linha costuma consumir 25-30% da receita bruta.',
      action: 'A variação desta linha deve acompanhar proporcionalmente a variação da receita bruta. Se crescer mais rápido, identifique qual tributo puxou e por quê (mudança de produto, CFOP, alíquota ou benefício perdido).'
    },
    dre_receita_liquida: {
      title: 'Receita Líquida Fiscal',
      what: 'O que efetivamente fica na empresa após deduzir todos os tributos sobre as vendas. É a base para calcular o Custo dos Produtos Vendidos (CPV), a margem bruta e o EBITDA no DRE completo. Representa a receita "limpa" de impostos sobre a circulação.',
      formula: 'Receita Líquida Fiscal = Receita Bruta − Tributos\n\nCarga% = Tributos ÷ Receita Bruta × 100\n\nPara cada R$ 1,00 faturado:\n  R$ carga%   → governo (tributos)\n  R$ (1−carga%) → empresa (antes do CPV)',
      reading: 'Este é o ponto de partida do DRE gerencial. A margem bruta (após CPV) e o EBITDA são sempre calculados sobre a Receita Líquida, nunca sobre a Bruta. Empresas que confundem os dois superestimam a rentabilidade.',
      action: 'Use a Receita Líquida Fiscal como denominador nos seus indicadores de margem. Se a carga% estiver crescendo, avalie: (1) benefícios fiscais disponíveis, (2) mudança de regime tributário, (3) revisão de preços para proteger a margem líquida.'
    },
    compras_periodo: {
      title: 'Compras Registradas no Período',
      what: 'Valor total das NFs de entrada (compras de fornecedores) sincronizadas do Omie. Inclui mercadorias para revenda, materiais, serviços contratados e equipamentos — tudo que gerou NF de entrada no período.',
      formula: 'Σ( valor_total )  das NFs de entrada\n  com data_entrada ∈ [início, fim]\n  sincronizadas via API do Omie\n\nÍndice de compras = Compras ÷ Faturamento × 100\nEx: compras = 70% do fat → margem bruta ≤ 30%',
      reading: 'Compare sempre com o faturamento. Se compras crescerem mais rápido que as vendas, o estoque está acumulando ou os custos estão subindo. Índice acima de 75% pressiona severamente a margem bruta.',
      action: 'Cruce com o faturamento mês a mês. Divergência crescente entre as duas curvas indica necessidade de revisão de política de estoque ou renegociação com fornecedores. Leve para o gestor financeiro com os dados de giro de estoque.'
    },
    credito_potencial: {
      title: 'Crédito Fiscal Potencial nas Entradas',
      what: 'Soma dos tributos destacados nas NFs de entrada (compras) que potencialmente podem ser aproveitados como crédito fiscal, reduzindo o saldo a recolher de ICMS, PIS e COFINS. É chamado "potencial" porque a validação e escrituração final são responsabilidade do contador.',
      formula: 'Σ( icms + pis_cofins + ipi )  das entradas elegíveis\n\nElegibilidade aproximada (Lucro Real):\n  CFOP 1102/2102/1403/2403 = mercadoria p/ revenda → ✓\n  CFOP 1101/2101           = insumo/produção       → ✓\n  CFOP 1556/2556           = equipamento (1/48/mês) → parcial\n  CFOP 1910–1999           = uso e consumo         → ✗\n\n"Potencial" ≠ "aproveitado" — SPED decide o real',
      reading: 'No Lucro Real não-cumulativo, cada R$ 1,00 de crédito potencial confirmado abate R$ 1,00 dos seus débitos de PIS/COFINS ou ICMS. Se crédito potencial ≈ 9,25% das compras, está sendo aproveitado integralmente. Se muito menor, há oportunidade de recuperação.',
      action: 'Leve o relatório de entradas ao contador e solicite confronto com o SPED: créditos lançados vs créditos disponíveis. A diferença representa pagamento a maior que pode ser compensado ou restituído retroativamente (últimos 5 anos).'
    },
    icms_entradas: {
      title: 'ICMS nas Entradas (Crédito Estadual)',
      what: 'Valor total do ICMS destacado nas NFs de compra pelos fornecedores. No Lucro Real com apuração não-cumulativa do ICMS, este valor gera crédito que pode abater o ICMS das suas saídas, reduzindo o saldo a recolher ao Estado.',
      formula: 'Σ( valor_icms )  das NFs de entrada no Omie\n\nCrédito efetivo depende do CST de cada item:\n  CST 00, 20, 40, 51 = crédito integral (via de regra)\n  CST 10, 30        = crédito parcial (ICMS-ST)\n  CST 60            = sem crédito (ICMS-ST já recolhido)\n\nRazão crédito/débito = ICMS entradas ÷ ICMS saídas',
      reading: 'Quanto maior a razão crédito/débito, menor o ICMS líquido a recolher. Fornecedores do Simples Nacional não destacam ICMS na nota — zero crédito para você. Fornecedores do Lucro Presumido/Real geralmente destacam alíquota cheia.',
      action: 'Se você tem ICMS alto nas saídas mas pouco ICMS nas entradas, avalie priorizar fornecedores fora do Simples Nacional para as compras mais representativas em volume, garantindo transferência de crédito.'
    },
    fornecedores: {
      title: 'Fornecedores Movimentados',
      what: 'Quantidade de fornecedores distintos (identificados por CNPJ/CPF) que emitiram NFs de entrada registradas no período. Mede a diversificação da base de fornecimento.',
      formula: 'COUNT( DISTINCT cnpj_fornecedor )\n  das NFs de entrada do período\n\nNúmero de itens = Σ( qtd_itens ) das mesmas NFs',
      reading: 'Alta concentração em 1–2 fornecedores (ex: 80% do volume em um único CNPJ) gera risco de ruptura e reduz poder de negociação. Para efeitos fiscais, se o principal fornecedor for do Simples Nacional, você perde crédito de ICMS/PIS/COFINS no volume total das compras com ele.',
      action: 'Avalie o regime tributário dos 5 maiores fornecedores. Quando o volume de compras for alto, prefira fornecedores que destacam ICMS e PIS/COFINS para maximizar seus créditos — o impacto pode ser maior do que um desconto marginal no preço de compra.'
    },
    sphere_federal: {
      title: 'Esfera Federal — PIS, COFINS e Retenções',
      what: 'Tributos federais incidentes sobre a receita e serviços da empresa. PIS e COFINS são contribuições calculadas sobre o faturamento bruto. As retenções são valores já recolhidos pelo tomador em seu nome, reduzindo o que precisa ser recolhido por conta própria.',
      formula: 'PIS saídas   = valor_total × 1,65%  (Lucro Real)\nCOFINS saídas = valor_total × 7,60%  (Lucro Real)\n\nCSRF (retenção PIS+COFINS+CSLL):\n  Obrigatória em pagamentos de serviços > R$ 215,05\n  PIS 0,65% + COFINS 3% + CSLL 1% = 4,65%\n  Recolhida pelo tomador, não por você',
      reading: 'O saldo líquido federal = (PIS+COFINS saídas) − (créditos de entrada PIS+COFINS) − CSRF retida. No Lucro Real, créditos de entradas robustos podem zerar ou até gerar saldo credor de PIS/COFINS.',
      action: 'Compare o saldo líquido federal mês a mês. Saldo credor de PIS/COFINS pode ser compensado com outros tributos federais (IRPJ, CSLL, IPI) — acione o contador para aproveitar essa oportunidade.'
    },
    sphere_estadual: {
      title: 'Esfera Estadual — ICMS e Variantes no Ceará',
      what: 'Tributos estaduais do Ceará incidentes nas operações de circulação de mercadorias e serviços de transporte/comunicação. Inclui quatro modalidades: ICMS próprio, ICMS-ST (substituição tributária), DIFAL (diferencial de alíquota em operações interestaduais) e FECOP (fundo estadual adicional).',
      formula: 'ICMS próprio: alíquota interna = 18% (+ 2% FECOP)\n  Base de cálculo inclusiva: BC = Valor ÷ (1 − alíq)\n\nICMS-ST: ICMS recolhido antecipadamente pelo remetente\n  MVA (Margem de Valor Agregado) varia por produto\n\nDIFAL: operações interestaduais p/ consumidor final\n  DIFAL = (alíq_interna_CE − alíq_interestadual) × BC\n\nFECOP: 2% adicional sobre produtos supérfluos\n  (bebidas, cosméticos, cigarro, armas, embarcações)',
      reading: 'O saldo a recolher de ICMS = débitos (saídas) − créditos (entradas). Se créditos > débitos em vários meses consecutivos, verifique se há crédito acumulado que pode ser transferido a terceiros ou ressarcido (Decreto 28.059/06 CE).',
      action: 'Monitore a conta gráfica do ICMS mensalmente com o contador. DIFAL crescente indica aumento de compras interestaduais com consumidor final — verifique se o recolhimento está correto no DARE ou DIFAL declaração.'
    },
    sphere_municipal: {
      title: 'Esfera Municipal — ISS e NFS-e em Fortaleza',
      what: 'Imposto Sobre Serviços de qualquer natureza, de competência do município de Fortaleza. Incide exclusivamente sobre prestação de serviços. A NFS-e (Nota Fiscal de Serviço Eletrônica) é o documento que comprova a operação.',
      formula: 'ISS próprio = Base de cálculo × alíquota\n  Alíquota Fortaleza: 2% a 5% conforme a LC 159/2013\n  Mínimo nacional: 2% (LC 157/2016)\n  Máximo nacional: 5% (LC 116/2003)\n\nISS retido pelo tomador:\n  Quando o tomador é obrigado a reter = você NÃO recolhe\n  Quando o tomador não retém = você recolhe via DASN\n\nNFS-e = contagem de NFs de serviço autorizadas na SEFIN',
      reading: 'Se ISS retido ≈ ISS total, quase todo o ISS já foi recolhido pelo tomador — risco baixo de DASN em atraso. Se ISS retido < ISS total, a diferença precisa ser recolhida via guia própria no portal da SEFIN Fortaleza.',
      action: 'Cruze o ISS declarado aqui com os DASNs recolhidos mensalmente. Se houver discrepância, acione o contador para regularização antes de eventual fiscalização da SEFIN — a multa mínima de ISS em Fortaleza é 75% do imposto.'
    },
    reforma: {
      title: 'Reforma Tributária — CBS e IBS (Informativos 2026)',
      what: 'A partir de 2026, o sistema tributário brasileiro inicia uma transição que vai até 2033. CBS (federal) substitui PIS+COFINS. IBS (estadual e municipal) substitui ICMS+ISS. Em 2026, os valores são informativos nas NFs para que empresas se preparem — sem recolhimento obrigatório ainda.',
      formula: 'CBS (federal):       alíquota estimada ~9,9%\nIBS-UF (estadual):   alíquota estimada ~13,4% (CE)\nIBS-Município:       alíquota estimada ~13,1% (FOR)\nTotal CBS+IBS:       ~36,4% sobre o valor dos bens/serv\n\nNão-cumulatividade plena:\n  Crédito de 100% dos inputs na cadeia\n  Sem exceções de CST ou CFOP\n\n2026–2032: coexistência com tributos atuais\n2033: extinção total do ICMS, ISS, PIS, COFINS',
      reading: 'Empresas que hoje compram muito de fornecedores do Simples Nacional (sem crédito de ICMS/PIS/COFINS) podem se beneficiar muito da reforma — a não-cumulatividade plena do IBS dará crédito mesmo sobre compras de micro/pequenas empresas.',
      action: 'Use a seção "Reforma Tributária" da página para simular o impacto do CBS+IBS nos seus produtos. Renegocie contratos de longo prazo que se encerram após 2027 para incluir cláusula de revisão de preço pela reforma tributária.'
    },
  };

  /* ── Help popover ────────────────────────────────────────────── */
  function openHelpPopover(key) {
    const entry = HELP[key];
    if (!entry) return;
    const popover  = document.getElementById('fisHelpPopover');
    const backdrop = document.getElementById('fisHelpBackdrop');
    const title    = document.getElementById('fisHelpPopoverTitle');
    const body     = document.getElementById('fisHelpPopoverBody');
    if (!popover || !backdrop || !title || !body) return;

    title.textContent = entry.title;
    body.innerHTML = [
      `<div class="fis-help-section"><div class="fis-help-section__label">O que é</div><div class="fis-help-section__text">${entry.what}</div></div>`,
      entry.formula ? `<div class="fis-help-section"><div class="fis-help-section__label">Como é calculado</div><pre class="fis-help-formula">${entry.formula}</pre></div>` : '',
      `<div class="fis-help-section"><div class="fis-help-section__label">Como interpretar</div><div class="fis-help-section__text">${entry.reading}</div></div>`,
      `<div class="fis-help-section"><div class="fis-help-section__label">O que fazer</div><div class="fis-help-section__text">${entry.action}</div></div>`,
    ].join('');

    backdrop.classList.add('is-open');
    popover.classList.add('is-open');
    document.getElementById('fisHelpPopoverClose')?.focus();
  }

  function closeHelpPopover() {
    document.getElementById('fisHelpPopover')?.classList.remove('is-open');
    document.getElementById('fisHelpBackdrop')?.classList.remove('is-open');
  }

  /* ── state ───────────────────────────────────────────────────── */
  const state = {
    start   : '',
    end     : '',
    page    : 1,
    perPage : 25,
    vendor  : '',
    client  : '',
    search  : '',
    status  : 'autorizada',
    nfSort  : 'data_emissao',
    nfDirection: 'desc',
    avgCarga: 0,
    settlementRows: [],
    settlementDetail: 'summary',
    settlementSearch: '',
    purchasePage: 1,
    purchasePerPage: 25,
    purchaseSearch: '',
    purchaseSort: 'data_entrada',
    purchaseDirection: 'desc',
    martOk  : false,
    charts  : {},
  };

  /* ── BIomie globals (safe reference) ────────────────────────── */
  const BG = window.BIomie || {};

  function todayIso() {
    if (BG.todayIso) return BG.todayIso();
    const d = new Date();
    return d.getFullYear() + '-' +
           String(d.getMonth() + 1).padStart(2, '0') + '-' +
           String(d.getDate()).padStart(2, '0');
  }

  /* ── helpers ─────────────────────────────────────────────────── */
  const $ = id => document.getElementById(id);
  const fmtR = v => 'R$ ' + parseFloat(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
  const fmtP = v => parseFloat(v || 0).toFixed(1) + '%';
  const clamp = (n, lo, hi) => Math.min(Math.max(n, lo), hi);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
  }[ch]));

  function buildUrl(base, extra = {}) {
    const common = { start: state.start, end: state.end };
    if (state.status) common.status = state.status;
    if (state.vendor) common.vendedor = state.vendor;
    if (state.client) common.cliente = state.client;
    const p = new URLSearchParams({ ...common, ...extra });
    return `${base}?${p}`;
  }

  function currencyTick(value) {
    const n = Number(value || 0);
    if (Math.abs(n) < 1000) return fmtR(n).replace(',00', '');
    return 'R$ ' + (n / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil';
  }

  function niceScale(values, preferredStep = 10000) {
    const max = Math.max(0, ...values.map(Number).filter(Number.isFinite));
    if (max <= 0) return { max: preferredStep, step: preferredStep / 5 };
    const rough = max / 5;
    const magnitude = 10 ** Math.floor(Math.log10(rough || 1));
    const normalized = rough / magnitude;
    const factor = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
    const step = max <= 50000 ? preferredStep : factor * magnitude;
    return { max: Math.ceil(max / step) * step, step };
  }

  function confidenceFor(row) {
    if (row.status === 'cancelada') return { key: 'cancel', label: 'Cancelada', icon: 'cancel' };
    if (!row.has_xml) return { key: 'critical', label: 'XML pendente', icon: 'link_off' };
    if (Number(row.sem_ncm_count || 0) > 0) return { key: 'warning', label: 'Sem NCM', icon: 'category_off' };
    if (Number(row.icms_zero_count || 0) > 0) return { key: 'warning', label: 'Imposto zerado', icon: 'percent' };
    if (!row.has_titulo) return { key: 'warning', label: 'Divergência', icon: 'sync_problem' };
    return { key: 'ok', label: 'Fiscal OK', icon: 'verified' };
  }

  function cargaLevel(carga) {
    const avg = Number(state.avgCarga || 0);
    if (!Number.isFinite(carga) || avg <= 0) return 'neutral';
    if (carga > avg * 1.25) return 'critical';
    if (carga > avg * 1.10) return 'warning';
    return 'ok';
  }

  function destroyChart(key) {
    if (state.charts[key]) { state.charts[key].destroy(); delete state.charts[key]; }
  }

  /* ── init ────────────────────────────────────────────────────── */
  function init() {
    const today = todayIso();
    const yr    = today.slice(0, 4);
    state.start = yr + '-01-01';
    state.end   = yr + '-12-31';

    syncDateInputs();
    arrangeSections();
    wireFilters();
    checkMart();
    loadAll();
  }

  function arrangeSections() {
    const main = document.querySelector('main.fis-shell');
    if (!main) return;
    [
      'fis-section-context',
      'fis-section-spheres',
      'fis-section-purchases',
      'fis-section-settlement',
      'fis-section-intelligence',
      'fis-section-kpis',
      'fis-section-reform',
      'fis-section-nf',
      'fis-section-vendors',
      'fis-section-ncm',
      'fis-section-alerts',
      'fis-section-dre',
    ].forEach(id => {
      const section = $(id);
      if (section) main.appendChild(section);
    });
  }

  function syncDateInputs() {
    const s = $('fisDateStart'), e = $('fisDateEnd');
    if (s) s.value = state.start;
    if (e) e.value = state.end;
  }

  /* ── filter wiring ───────────────────────────────────────────── */
  function wireFilters() {
    const apply = () => applyPeriod();

    const applyBtn = $('fisApply');
    if (applyBtn) applyBtn.addEventListener('click', apply);

    const ds = $('fisDateStart'), de = $('fisDateEnd');
    if (ds) ds.addEventListener('change', () => { state.start = ds.value; });
    if (de) de.addEventListener('change', () => { state.end   = de.value; });

    document.querySelectorAll('[data-fis-preset]').forEach(btn => {
      btn.addEventListener('click', () => {
        const p = btn.dataset.fisPreset;
        setPreset(p);
        syncDateInputs();
        applyPeriod();
      });
    });

    const nfSearch = $('fisNfSearch');
    if (nfSearch) {
      let t;
      nfSearch.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => { state.search = nfSearch.value.trim(); state.page = 1; loadNfList(); }, 380);
      });
    }

    const nfStatus = $('fisNfStatus');
    if (nfStatus) nfStatus.addEventListener('change', () => {
      state.status = nfStatus.value;
      const top = $('fisStatusTop');
      if (top) top.value = state.status;
      state.page = 1;
      loadAll();
    });

    const nfVendor = $('fisNfVendor');
    if (nfVendor) {
      let t;
      nfVendor.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => { state.vendor = nfVendor.value.trim(); state.page = 1; loadNfList(); }, 380);
      });
    }

    const buildBtn = $('fisBuildMart');
    if (buildBtn) buildBtn.addEventListener('click', runBuildMart);
    const bannerBuildBtn = $('fisMartBuildBtn');
    if (bannerBuildBtn) bannerBuildBtn.addEventListener('click', runBuildMart);

    const topStatus = $('fisStatusTop');
    if (topStatus) topStatus.addEventListener('change', () => {
      state.status = topStatus.value;
      if (nfStatus) nfStatus.value = state.status;
    });
    const topVendor = $('fisVendorTop');
    if (topVendor) topVendor.addEventListener('input', () => { state.vendor = topVendor.value.trim(); });
    const topClient = $('fisClientTop');
    if (topClient) topClient.addEventListener('input', () => { state.client = topClient.value.trim(); });

    ['fisCompany', 'fisDocType', 'fisRegime'].forEach(id => {
      const el = $(id);
      if (!el) return;
      el.disabled = true;
      el.title = 'Filtro preparado; a fonte fiscal ainda não fornece este campo.';
    });

    $('fisExportCsv')?.addEventListener('click', exportCsv);
    $('fisExportPdf')?.addEventListener('click', () => window.print());
    $('fisSendAccountant')?.addEventListener('click', sendToAccountant);

    // NF table sorting
    document.querySelectorAll('[data-nf-sort]').forEach(th => {
      th.addEventListener('click', () => {
        const field = th.dataset.nfSort;
        if (state.nfSort === field) {
          state.nfDirection = state.nfDirection === 'asc' ? 'desc' : 'asc';
        } else {
          state.nfSort = field;
          state.nfDirection = (field === 'nome_cliente' || field === 'nome_vendedor' || field === 'numero_nf') ? 'asc' : 'desc';
        }
        state.page = 1;
        loadNfList();
      });
    });

    // NF per-page
    $('fisNfPerPage')?.addEventListener('change', event => {
      state.perPage = Number(event.target.value || 25);
      state.page = 1;
      loadNfList();
    });

    // Smooth scroll for header navigation links
    document.querySelectorAll('[href^="#fis-section-"]').forEach(link => {
      link.addEventListener('click', event => {
        event.preventDefault();
        const target = document.querySelector(link.getAttribute('href'));
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
    $('fisSettlementDetail')?.addEventListener('change', event => {
      state.settlementDetail = event.target.value;
      renderSettlementTable();
    });
    $('fisSettlementSearch')?.addEventListener('input', event => {
      state.settlementSearch = event.target.value.trim().toLowerCase();
      renderSettlementTable();
    });

    let purchaseTimer;
    $('fisPurchaseSearch')?.addEventListener('input', event => {
      clearTimeout(purchaseTimer);
      purchaseTimer = setTimeout(() => {
        state.purchaseSearch = event.target.value.trim();
        state.purchasePage = 1;
        loadPurchases();
      }, 320);
    });
    $('fisPurchasePerPage')?.addEventListener('change', event => {
      state.purchasePerPage = Number(event.target.value || 25);
      state.purchasePage = 1;
      loadPurchases();
    });
    $('fisSyncPurchases')?.addEventListener('click', syncPurchases);

    // Help popover delegation (works on dynamically rendered cards)
    document.addEventListener('click', e => {
      const btn = e.target.closest('.fis-help-btn');
      if (btn && btn.dataset.help) { e.stopPropagation(); openHelpPopover(btn.dataset.help); }
    });

    document.querySelectorAll('[data-purchase-sort]').forEach(button => {
      button.addEventListener('click', () => {
        const field = button.dataset.purchaseSort;
        if (state.purchaseSort === field) {
          state.purchaseDirection = state.purchaseDirection === 'asc' ? 'desc' : 'asc';
        } else {
          state.purchaseSort = field;
          state.purchaseDirection = field === 'fornecedor_nome' ? 'asc' : 'desc';
        }
        state.purchasePage = 1;
        loadPurchases();
      });
    });
  }

  function setPreset(p) {
    const today = todayIso();
    const d = new Date(today);
    const yr = d.getFullYear(), mo = d.getMonth() + 1;
    const pad = n => String(n).padStart(2, '0');
    const lastDay = n => new Date(d.getFullYear(), n, 0).getDate();

    switch (p) {
      case 'year':    state.start = `${yr}-01-01`;                   state.end = `${yr}-12-31`; break;
      case 'month':   state.start = `${yr}-${pad(mo)}-01`;           state.end = `${yr}-${pad(mo)}-${lastDay(mo)}`; break;
      case 'rolling12': {
        const f = new Date(d); f.setFullYear(f.getFullYear() - 1); f.setDate(f.getDate() + 1);
        state.start = f.toISOString().slice(0, 10); state.end = today; break;
      }
      case 'prevyear': state.start = `${yr - 1}-01-01`; state.end = `${yr - 1}-12-31`; break;
    }
  }

  function applyPeriod() {
    const s = $('fisDateStart'), e = $('fisDateEnd');
    if (s && s.value) state.start = s.value;
    if (e && e.value) state.end   = e.value;
    state.page = 1;
    loadAll();
  }

  function loadAll() {
    loadFiscalContext();
    loadPurchases();
    loadSettlement();
    loadSummary();
    loadNfList();
    if (state.martOk) {
      loadNcm(false);
      loadReform();
    }
    loadAlerts();
  }

  function loadPurchases() {
    const tbody = $('fisPurchaseTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="10" class="fis-loading-cell">Carregando entradas de compras…</td></tr>';
    const url = buildUrl('api/fiscal_entries.php', {
      page: state.purchasePage,
      per_page: state.purchasePerPage,
      q: state.purchaseSearch,
      sort: state.purchaseSort,
      direction: state.purchaseDirection,
    });
    fetch(url)
      .then(response => response.json())
      .then(data => {
        if (!data.ok) throw new Error(data.message || 'Falha ao carregar entradas');
        renderPurchaseKpis(data.summary || {});
        renderPurchaseRows(data.rows || []);
        renderPurchasePagination(data);
        renderPurchaseSource(data.source || {});
        renderPurchaseSort();
      })
      .catch(() => {
        tbody.innerHTML = '<tr><td colspan="10" class="fis-loading-cell">Entradas de compras indisponíveis.</td></tr>';
      });
  }

  function renderPurchaseKpis(summary) {
    const el = $('fisPurchaseKpis');
    if (!el) return;
    const cards = [
      {
        icon: 'shopping_cart',
        tone: 'purchase',
        label: 'Compras no período',
        value: summary.compras_fmt || fmtR(0),
        note: `${Number(summary.documentos || 0).toLocaleString('pt-BR')} documento(s) de entrada`,
        help: 'compras_periodo',
      },
      {
        icon: 'savings',
        tone: 'credit',
        label: 'Crédito potencial mapeado',
        value: summary.credito_potencial_fmt || fmtR(0),
        note: 'Sujeito à validação de aproveitamento',
        help: 'credito_potencial',
      },
      {
        icon: 'account_balance',
        tone: 'tax',
        label: 'ICMS destacado nas entradas',
        value: summary.icms_fmt || fmtR(0),
        note: 'Base para conciliação estadual',
        help: 'icms_entradas',
      },
      {
        icon: 'groups',
        tone: 'supplier',
        label: 'Fornecedores movimentados',
        value: Number(summary.fornecedores || 0).toLocaleString('pt-BR'),
        note: `${Number(summary.itens || 0).toLocaleString('pt-BR')} item(ns) recebidos`,
        help: 'fornecedores',
      },
    ];
    el.innerHTML = cards.map(card => `
      <article class="fis-business-card fis-business-card--${card.tone}">
        <div class="fis-business-card__icon"><span class="material-symbols-rounded">${card.icon}</span></div>
        <div class="fis-business-card__body">
          <small>${card.label}<button class="fis-help-btn fis-help-btn--inline" data-help="${card.help}" aria-label="Ajuda: ${card.label}" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button></small>
          <strong>${card.value}</strong>
          <span>${card.note}</span>
        </div>
      </article>
    `).join('');
  }

  function renderPurchaseRows(rows) {
    const tbody = $('fisPurchaseTbody');
    if (!tbody) return;
    if (!rows.length) {
      tbody.innerHTML = `
        <tr><td colspan="10" class="fis-loading-cell">
          Nenhuma entrada encontrada neste período. Atualize o Omie ou amplie o intervalo.
        </td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(row => {
      const fiscalRefs = [
        row.ncm_list ? `NCM ${row.ncm_list}` : '',
        row.cfop_list ? `CFOP ${row.cfop_list}` : '',
      ].filter(Boolean).join(' · ') || 'Classificação não informada';
      return `
        <tr>
          <td><span class="fis-nf-number">${esc(row.numero_documento || '-')}</span><small class="fis-cell-note">${row.itens_count} item(ns)</small></td>
          <td>${esc(row.data_label || '-')}</td>
          <td class="fis-ellipsis" title="${esc(row.fornecedor_nome || '')}">${esc(row.fornecedor_nome || '-')}</td>
          <td><span class="fis-tax-reference">${esc(fiscalRefs)}</span></td>
          <td class="fis-r fis-bold">${row.valor_total}</td>
          <td class="fis-r">${row.icms}</td>
          <td class="fis-r">${row.pis_cofins}</td>
          <td class="fis-r">${row.ipi}</td>
          <td class="fis-r"><strong class="fis-credit-value">${row.credito_potencial}</strong></td>
          <td><span class="fis-confidence fis-confidence--warning"><span class="material-symbols-rounded">fact_check</span>Validar crédito</span></td>
        </tr>`;
    }).join('');
  }

  function renderPurchasePagination(data) {
    const el = $('fisPurchasePagination');
    if (!el) return;
    const page = Number(data.page || 1);
    const pages = Number(data.pages || 0);
    const total = Number(data.total || 0);
    let html = `<span class="fis-pagination__summary">${total.toLocaleString('pt-BR')} entrada(s)</span>`;
    if (pages > 1) {
      html += `<button class="fis-pag-btn" onclick="FIS.goPurchasePage(${page - 1})" ${page <= 1 ? 'disabled' : ''}>‹</button>`;
      const start = Math.max(1, page - 2);
      const end = Math.min(pages, page + 2);
      for (let current = start; current <= end; current++) {
        html += `<button class="fis-pag-btn${current === page ? ' fis-pag-btn--active' : ''}" onclick="FIS.goPurchasePage(${current})">${current}</button>`;
      }
      html += `<button class="fis-pag-btn" onclick="FIS.goPurchasePage(${page + 1})" ${page >= pages ? 'disabled' : ''}>›</button>`;
    }
    el.innerHTML = html;
  }

  function renderPurchaseSource(source) {
    const el = $('fisPurchaseSource');
    if (!el) return;
    const active = source.status === 'active';
    el.classList.toggle('fis-source-status--active', active);
    el.textContent = active
      ? `Omie atualizado ${source.last_sync_at || ''}`.trim()
      : 'Omie de entrada aguardando sincronização';
  }

  function renderPurchaseSort() {
    document.querySelectorAll('[data-purchase-sort]').forEach(header => {
      const active = header.dataset.purchaseSort === state.purchaseSort;
      header.classList.toggle('is-sorted', active);
      header.dataset.sortDirection = active ? state.purchaseDirection : '';
    });
  }

  function syncPurchases() {
    const button = $('fisSyncPurchases');
    if (!button || button.disabled) return;
    button.disabled = true;
    const original = button.innerHTML;
    button.innerHTML = '<span class="material-symbols-rounded fis-spin">sync</span>Atualizando…';
    const csrf = document.querySelector('meta[name="biomie-csrf"]')?.content || '';
    fetch(buildUrl('api/fiscal_sync_entries.php'), {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
    })
      .then(async response => {
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Falha ao atualizar');
        loadPurchases();
        loadSettlement();
      })
      .catch(error => alert(error.message || 'Não foi possível atualizar as entradas.'))
      .finally(() => {
        button.disabled = false;
        button.innerHTML = original;
      });
  }

  function loadSettlement() {
    fetch(buildUrl('api/fiscal_settlement.php'))
      .then(r => r.json())
      .then(data => {
        if (!data.ok) throw new Error(data.message || 'Falha ao carregar apuração fiscal');
        state.settlementRows = data.rows || [];
        renderSettlementKpis(data.totals || {}, data.coverage || {});
        renderSettlementCharts(state.settlementRows);
        renderSettlementTable();
      })
      .catch(() => {
        const tbody = $('fisSettlementTbody');
        if (tbody) tbody.innerHTML = '<tr><td class="fis-loading-cell">Apuração indisponível.</td></tr>';
      });
  }

  function renderSettlementKpis(totals, coverage) {
    const el = $('fisSettlementKpis');
    if (!el) return;
    const creditsConfirmed = coverage.credits_are_confirmed === true;
    const creditsMapped = coverage.inputs && coverage.inputs !== 'pending';
    el.innerHTML = `
      <article class="fis-settlement-kpi"><small>Tributos brutos<button class="fis-help-btn fis-help-btn--inline" data-help="tributos_brutos" aria-label="Ajuda: Tributos brutos" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button></small><strong>${totals.tributos_brutos_fmt || fmtR(0)}</strong><span>Carga ${fmtP(totals.carga_bruta_pct)}</span></article>
      <article class="fis-settlement-kpi fis-settlement-kpi--credit"><small>Créditos de entrada<button class="fis-help-btn fis-help-btn--inline" data-help="creditos_entrada" aria-label="Ajuda: Créditos de entrada" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button></small><strong>${creditsMapped ? (totals.creditos_totais_fmt || fmtR(0)) : 'Pendente'}</strong><span>${creditsConfirmed ? 'Créditos conciliados' : (creditsMapped ? 'Potencial mapeado no Omie; validar aproveitamento' : 'Aguardando notas de entrada do Omie')}</span></article>
      <article class="fis-settlement-kpi fis-settlement-kpi--retention"><small>Retenções identificadas<button class="fis-help-btn fis-help-btn--inline" data-help="retencoes" aria-label="Ajuda: Retenções" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button></small><strong>${totals.retencoes_totais_fmt || fmtR(0)}</strong><span>PIS, COFINS, CSLL e IRRF disponíveis no Omie</span></article>
      <article class="fis-settlement-kpi fis-settlement-kpi--net"><small>Saldo líquido gerencial<button class="fis-help-btn fis-help-btn--inline" data-help="saldo_liquido" aria-label="Ajuda: Saldo líquido" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button></small><strong>${totals.saldo_liquido_estimado_fmt || fmtR(0)}</strong><span>Carga líquida ${fmtP(totals.carga_liquida_pct)}</span></article>
    `;
    const badge = $('fisSettlementCoverage');
    if (badge) {
      badge.textContent = creditsConfirmed
        ? 'Créditos conciliados'
        : (creditsMapped ? 'Créditos potenciais — validar com a contabilidade' : 'Saldo parcial — entradas pendentes');
      badge.classList.toggle('fis-coverage-badge--warning', !creditsConfirmed);
    }
  }

  function renderSettlementCharts(rows) {
    destroyChart('settlement');
    destroyChart('settlementComposition');
    if (!rows.length) return;

    const labels = rows.map(row => row.label);
    const gross = rows.map(row => Number(row._tributos_brutos || 0));
    const credits = rows.map(row => Number(row._creditos_totais || 0) + Number(row._retencoes_totais || 0));
    const net = rows.map(row => Number(row._saldo_liquido_estimado || 0));
    const scale = niceScale([...gross, ...net]);
    const canvas = $('fisSettlementCanvas');
    if (canvas) state.charts.settlement = new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'Tributos brutos', data: gross, backgroundColor: '#6366f1', borderRadius: 5 },
          { label: 'Créditos + retenções', data: credits, backgroundColor: '#10b981', borderRadius: 5 },
          { label: 'Saldo líquido', data: net, type: 'line', borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.08)', tension: .32, pointRadius: 3, fill: false },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
          tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${fmtR(ctx.parsed.y)}` } },
        },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, suggestedMax: scale.max, ticks: { stepSize: scale.step, callback: currencyTick } },
        },
      },
    });

    const sum = key => rows.reduce((total, row) => total + Number(row['_' + key] || 0), 0);
    const values = [
      sum('debito_icms') + sum('debito_icms_st') + sum('debito_difal') + sum('debito_fecop'),
      sum('debito_pis'),
      sum('debito_cofins'),
      sum('debito_ipi'),
      sum('debito_iss'),
    ];
    const composition = $('fisSettlementCompositionCanvas');
    // Paleta categórica validada (mag-chart-defaults) — slot fixo por tributo.
    const fisCatPalette = (window.MagChartDefaults && window.MagChartDefaults.categoricalPalette)
      || ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7'];
    if (composition) state.charts.settlementComposition = new Chart(composition, {
      type: 'doughnut',
      data: {
        labels: ['ICMS/CE', 'PIS', 'COFINS', 'IPI', 'ISS'],
        datasets: [{ data: values, backgroundColor: fisCatPalette.slice(0, 5), borderColor: '#ffffff', borderWidth: 2 }],
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '64%',
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 9, font: { size: 10 } } },
          tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${fmtR(ctx.parsed)}` } },
        },
      },
    });
  }

  function renderSettlementTable() {
    const head = $('fisSettlementHead');
    const tbody = $('fisSettlementTbody');
    if (!head || !tbody) return;
    const rows = state.settlementRows.filter(row =>
      !state.settlementSearch || String(row.label || row.competencia).toLowerCase().includes(state.settlementSearch)
    );

    if (state.settlementDetail === 'taxes') {
      head.innerHTML = '<tr><th>Competência</th><th class="fis-r">ICMS/CE</th><th class="fis-r">ICMS-ST</th><th class="fis-r">DIFAL</th><th class="fis-r">FECOP</th><th class="fis-r">PIS</th><th class="fis-r">COFINS</th><th class="fis-r">IPI</th><th class="fis-r">ISS</th></tr>';
      tbody.innerHTML = rows.length ? rows.map(row => `
        <tr><td>${esc(row.label)}</td>
          <td class="fis-r">${fmtR(row._debito_icms)}</td><td class="fis-r">${fmtR(row._debito_icms_st)}</td>
          <td class="fis-r">${fmtR(row._debito_difal)}</td><td class="fis-r">${fmtR(row._debito_fecop)}</td>
          <td class="fis-r">${fmtR(row._debito_pis)}</td><td class="fis-r">${fmtR(row._debito_cofins)}</td>
          <td class="fis-r">${fmtR(row._debito_ipi)}</td><td class="fis-r">${fmtR(row._debito_iss)}</td></tr>
      `).join('') : '<tr><td colspan="9" class="fis-loading-cell">Nenhuma competência.</td></tr>';
      return;
    }

    head.innerHTML = '<tr><th>Competência</th><th class="fis-r">Faturamento</th><th class="fis-r">Tributos brutos</th><th class="fis-r">Créditos</th><th class="fis-r">Retenções</th><th class="fis-r">Saldo líquido</th><th class="fis-r">Carga bruta</th><th class="fis-r">Carga líquida</th><th>Cobertura</th></tr>';
    tbody.innerHTML = rows.length ? rows.map(row => `
      <tr><td>${esc(row.label)}</td><td class="fis-r">${row.faturamento_fmt}</td>
        <td class="fis-r fis-bold">${row.tributos_brutos_fmt}</td>
        <td class="fis-r">${row.input_coverage === 'pending' ? '<span class="fis-pending-value">Pendente</span>' : `${row.creditos_totais_fmt}<small class="fis-cell-note">potencial</small>`}</td>
        <td class="fis-r">${row.retencoes_totais_fmt}</td><td class="fis-r fis-bold">${row.saldo_liquido_estimado_fmt}</td>
        <td class="fis-r">${fmtP(row.carga_bruta_pct)}</td><td class="fis-r">${fmtP(row.carga_liquida_pct)}</td>
        <td><span class="fis-confidence fis-confidence--${row.input_coverage === 'complete' ? 'ok' : 'warning'}">${row.input_coverage === 'pending' ? 'Entradas pendentes' : (row.input_coverage === 'partial' ? 'Omie · validar' : 'Conciliada')}</span></td></tr>
    `).join('') : '<tr><td colspan="9" class="fis-loading-cell">Nenhuma competência.</td></tr>';
  }

  function loadFiscalContext() {
    fetch(buildUrl('api/fiscal_context.php'))
      .then(r => r.json())
      .then(data => {
        if (!data.ok) throw new Error(data.message || 'Falha ao carregar contexto fiscal');
        renderFiscalContext(data.context || {});
        renderFiscalSpheres(data.spheres || {});
      })
      .catch(() => {
        const el = $('fisContextGrid');
        const spheres = $('fisSphereGrid');
        const coverage = $('fisContextCoverage');
        if (spheres) spheres.innerHTML = '<div class="fis-loading-cell">Tributos por esfera indisponiveis.</div>';
        if (coverage) coverage.textContent = 'Cobertura indisponivel';
        if (el) el.innerHTML = '<div class="fis-loading-cell">Contexto fiscal indisponível.</div>';
      });
  }

  function renderFiscalContext(context) {
    const establishment = context.establishment || {};
    // O regime vem do backend (TaxRegime), por empresa: Lucro Real (MAG e MAG
    // Filial), Lucro Presumido (Ergo Office) ou Simples/EPP (Expert e Uni Design).
    // Cada um tem tributos e alíquotas diferentes.
    const regime = context.regime || {};
    const regimeLabel = regime.label || 'Não configurado';

    const grid = $('fisContextGrid');
    if (grid) {
      // Carga esperada de PIS/COFINS conforme o regime (no Simples vai no DAS).
      const cargaLabel = regime.pis_cofins_total_pct != null
        ? `${String(regime.pis_cofins_total_pct).replace('.', ',')}% (${regime.pis_cofins_regime === 'nao_cumulativo' ? 'não-cumulativo' : 'cumulativo'})`
        : (regime.code === 'simples_nacional'
            ? `DAS ${regime.anexo || 'Anexo I'} · ${String(regime.das_faixa_pct?.min ?? 4).replace('.', ',')}% a ${String(regime.das_faixa_pct?.max ?? 19).replace('.', ',')}%`
            : '—');

      grid.innerHTML = [
        ['Regime tributário', regimeLabel, 'receipt_long'],
        ['PIS/COFINS esperado', cargaLabel, 'percent'],
        ['UF', establishment.uf || 'CE', 'map'],
        ['Município', `${establishment.municipio || 'Fortaleza'} · IBGE ${establishment.codigo_ibge || '2304400'}`, 'location_on'],
        ['Operação principal', establishment.operation_profile === 'comercio_mercadorias' ? 'Comércio / mercadorias' : (establishment.operation_profile || 'Não configurada'), 'storefront'],
      ].map(([label, value, icon]) => `
        <div class="fis-context-card"><span class="material-symbols-rounded">${icon}</span><div><small>${label}</small><strong>${esc(value)}</strong></div></div>
      `).join('');
    }

    // Explicação do regime (como ler a apuração desta empresa).
    const regimeNote = $('fisRegimeNote');
    if (regimeNote) {
      regimeNote.innerHTML = regime.summary
        ? `<strong>${esc(regimeLabel)}</strong> — ${esc(regime.summary)}<br><span class="fis-regime-note__reading">${esc(regime.reading || '')}</span>`
        : '';
      regimeNote.hidden = !regime.summary;
    }

    const strip = $('fisModuleStrip');
    if (strip) strip.innerHTML = (context.modules || []).map(module => `
      <span class="fis-module-chip fis-module-chip--${module.status}">
        <i></i>${esc(module.label)}
      </span>
    `).join('');

    const sources = context.sources || [];
    const active = sources.filter(source => source.status === 'active').length;
    const prepared = sources.filter(source => source.status === 'prepared').length;
    const coverage = $('fisContextCoverage');
    if (coverage) coverage.textContent = `${active} fonte ativa · ${prepared} preparadas`;
  }

  function renderFiscalSpheres(spheres) {
    const el = $('fisSphereGrid');
    if (!el) return;
    const federal = spheres.federal || {};
    const stateTax = spheres.state || {};
    const municipal = spheres.municipal || {};
    const reform = spheres.reform || {};
    const coverage = spheres.coverage || {};

    const blocks = [
      {
        tone: 'federal', icon: 'account_balance', title: 'Federal — Lucro Real',
        rows: [['PIS saídas', fmtR(federal.pis)], ['COFINS saídas', fmtR(federal.cofins)], ['CSRF retida', fmtR((federal.pis_retido || 0) + (federal.cofins_retido || 0) + (federal.csll_retida || 0))]],
        note: coverage.accounting ? 'Contábil conectado' : 'IRPJ/CSLL aguardam ECD/ECF ou balancete',
        help: 'sphere_federal',
      },
      {
        tone: 'state', icon: 'map', title: 'Estadual — ICMS Ceará',
        rows: [['ICMS saídas', fmtR(stateTax.icms)], ['ICMS-ST', fmtR(stateTax.icms_st)], ['DIFAL destino', fmtR(stateTax.difal_destino)], ['FECOP', fmtR(stateTax.fecop)]],
        note: coverage.inputs ? 'Débitos e créditos disponíveis' : 'Créditos de entrada ficam para a última fase',
        help: 'sphere_estadual',
      },
      {
        tone: 'municipal', icon: 'location_city', title: 'Municipal — ISS Fortaleza',
        rows: [['Serviços identificados', fmtR(municipal.servicos)], ['ISS', fmtR(municipal.iss)], ['ISS retido', fmtR(municipal.iss_retido)], ['NFS-e', Number(municipal.nfse || 0).toLocaleString('pt-BR')]],
        note: coverage.nfse ? 'NFS-e conectada' : 'Estrutura pronta; fonte NFS-e ainda não conectada',
        help: 'sphere_municipal',
      },
      {
        tone: 'reform', icon: 'balance', title: 'Reforma Tributária',
        rows: [['CBS informativa', fmtR(reform.cbs)], ['IBS Ceará', fmtR(reform.ibs_uf)], ['IBS Município', fmtR(reform.ibs_municipio)]],
        note: 'Valores informativos; não representam apuração definitiva',
        help: 'reforma',
      },
    ];

    el.innerHTML = blocks.map(block => `
      <article class="fis-sphere-card fis-sphere-card--${block.tone}">
        <header>
          <span class="material-symbols-rounded">${block.icon}</span>
          <h3>${block.title}</h3>
          <button class="fis-help-btn fis-help-btn--sphere" data-help="${block.help}" aria-label="Ajuda: ${block.title}" title="Entender este bloco tributário"><span class="material-symbols-rounded">help</span></button>
        </header>
        <div>${block.rows.map(([label, value]) => `<p><span>${label}</span><strong>${value}</strong></p>`).join('')}</div>
        <small>${block.note}</small>
      </article>
    `).join('');
  }

  /* ── Mart check ─────────────────────────────────────────────── */
  function checkMart() {
    fetch('api/fiscal_summary.php?' + new URLSearchParams({ start: state.start, end: state.end }))
      .then(r => r.json())
      .then(d => {
        const mart = d.mart || {};
        state.martOk = !!mart.exists;
        updateMartBanner(mart);
        if (state.martOk) { loadNcm(false); loadReform(); }
      })
      .catch(() => {});
  }

  function updateMartBanner(mart) {
    const banner = $('fisMartBanner');
    const txt    = $('fisMartBannerText');
    if (!banner || !txt) return;

    if (mart.exists) {
      banner.style.borderColor = 'rgba(16,185,129,0.28)';
      banner.style.background  = 'rgba(16,185,129,0.07)';
      banner.style.color       = '#047857';
      txt.textContent = `Mart fiscal ativo · ${Number(mart.rows || 0).toLocaleString('pt-BR')} itens · atualizado ${mart.built_at || '-'}`;
    } else {
      txt.textContent = 'Mart fiscal não construído — seções NCM e Reforma Tributária indisponíveis.';
    }
  }

  function runBuildMart() {
    const btn = $('fisBuildMart');
    if (btn) { btn.disabled = true; btn.textContent = 'Construindo...'; }

    fetch('api/fiscal_build_mart.php', { method: 'POST' })
      .then(r => r.json())
      .then(d => {
        if (btn) { btn.disabled = false; btn.textContent = 'Construir Mart'; }
        if (d.ok) {
          state.martOk = true;
          updateMartBanner({ exists: true, rows: (d.result && d.result.inserted) || 0, built_at: 'agora' });
          loadNcm(true);   // force=true to bypass stale cache
          loadReform();
        } else {
          alert('Erro ao construir mart: ' + (d.message || 'desconhecido'));
        }
      })
      .catch(() => { if (btn) { btn.disabled = false; btn.textContent = 'Construir Mart'; } });
  }

  /* ── Summary ─────────────────────────────────────────────────── */
  function loadSummary() {
    const row = $('fisKpiRow');
    if (row) row.innerHTML = [0,1,2,3,4,5].map(() => '<div class="fis-kpi-skeleton"></div>').join('');

    fetch(buildUrl('api/fiscal_summary.php'))
      .then(r => r.json())
      .then(d => {
        if (!d.ok) return;
        state.avgCarga = Number(d.intelligence?.carga_media_nf || d.kpis?.carga_pct || 0);
        renderSummaryKpis(d.kpis, d.mart);
        renderIntelligence(d.intelligence || {}, d.period || {});
        renderDre(d.dre || {});
        renderTrendChart(d.trend || []);
        renderComposition(d.kpis);
        renderVendors(d.vendors || []);
        updateMartBanner(d.mart || {});
        state.martOk = !!(d.mart && d.mart.exists);
      })
      .catch(() => { if (row) row.innerHTML = '<div class="fis-loading-cell">Erro ao carregar resumo.</div>'; });
  }

  function renderIntelligence(data, period) {
    const el = $('fisIntelGrid');
    if (!el) return;
    const cards = [
      ['Notas analisadas', Number(data.notas_analisadas || 0).toLocaleString('pt-BR'), 'description', 'neutral', 'notas_analisadas'],
      ['Notas com alerta', Number(data.notas_com_alerta || 0).toLocaleString('pt-BR'), 'notification_important', data.notas_com_alerta > 0 ? 'warning' : 'ok', 'notas_alerta'],
      ['XMLs pendentes', Number(data.xmls_pendentes || 0).toLocaleString('pt-BR'), 'link_off', data.xmls_pendentes > 0 ? 'critical' : 'ok', 'xmls_pendentes'],
      ['Carga média por NF', fmtP(data.carga_media_nf), 'percent', 'neutral', 'carga_media'],
      ['Maior carga fiscal', fmtP(data.maior_carga_fiscal), 'trending_up', 'warning', 'maior_carga'],
      ['Divergência fiscal × financeiro', data.divergencia_fiscal_financeiro || fmtR(0), 'sync_problem', data._divergencia_fiscal_financeiro > 0 ? 'critical' : 'ok', 'divergencia_fiscal'],
      ['Score de risco fiscal', `${Number(data.score_risco_fiscal || 0).toFixed(1)}/100`, 'shield_with_heart', data.score_risco_fiscal >= 25 ? 'critical' : data.score_risco_fiscal >= 10 ? 'warning' : 'ok', 'score_risco'],
    ];
    el.innerHTML = cards.map(([label, value, icon, tone, helpKey]) => `
      <article class="fis-intel-card fis-intel-card--${tone}">
        <span class="material-symbols-rounded">${icon}</span>
        <div>
          <small>${label}<button class="fis-help-btn fis-help-btn--inline" data-help="${helpKey}" aria-label="Ajuda: ${label}" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button></small>
          <strong>${value}</strong>
        </div>
      </article>
    `).join('');
    const meta = $('fisIntelMeta');
    if (meta && period.start && period.end) meta.textContent = `${period.start} a ${period.end}`;
  }

  function renderDre(data) {
    const el = $('fisDreGrid');
    if (!el) return;
    el.innerHTML = `
      <div class="fis-dre-card"><small>Receita bruta<button class="fis-help-btn fis-help-btn--inline" data-help="dre_receita_bruta" aria-label="Ajuda: Receita bruta" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button></small><strong>${data.receita_bruta || fmtR(0)}</strong></div>
      <div class="fis-dre-operator">−</div>
      <div class="fis-dre-card fis-dre-card--tax"><small>Tributos<button class="fis-help-btn fis-help-btn--inline" data-help="dre_tributos" aria-label="Ajuda: Tributos DRE" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button></small><strong>${data.tributos || fmtR(0)}</strong></div>
      <div class="fis-dre-operator">=</div>
      <div class="fis-dre-card fis-dre-card--result"><small>Receita líquida fiscal<button class="fis-help-btn fis-help-btn--inline" data-help="dre_receita_liquida" aria-label="Ajuda: Receita líquida" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button></small><strong>${data.receita_liquida_fiscal || fmtR(0)}</strong><span>Carga ${fmtP(data.carga_pct)}</span></div>
    `;
  }

  function renderSummaryKpis(kpis, mart) {
    const row = $('fisKpiRow');
    if (!row || !kpis) return;

    const cargatual = kpis.carga_pct || 0;
    const cbsTotal  = mart && mart.exists ? kpis._cbs || 0 : null;

    const cards = [
      { label: 'Faturamento', value: fmtR(kpis._fat), sub: `${kpis.total_nfs || 0} NFs`, c: '#6366f1', total: true, help: 'faturamento' },
      { label: 'Tributos Totais', value: fmtR(kpis._trib), sub: `Carga: ${fmtP(cargatual)}`, c: '#8b5cf6', help: 'tributos_totais' },
      { label: 'ICMS', value: fmtR(kpis._icms), sub: fmtP(kpis._trib > 0 ? kpis._icms / kpis._trib * 100 : 0), c: '#6366f1', help: 'icms' },
      { label: 'PIS + COFINS', value: fmtR((kpis._pis || 0) + (kpis._cofins || 0)), sub: 'Contribuições', c: '#a78bfa', help: 'pis_cofins' },
      { label: 'IPI', value: fmtR(kpis._ipi), sub: 'Industrial', c: '#c4b5fd', help: 'ipi' },
      cbsTotal !== null
        ? { label: 'CBS Info.¹', value: fmtR(cbsTotal), sub: 'Reforma 2026', c: '#10b981', help: 'cbs_info' }
        : { label: 'NFs Canceladas', value: kpis.canceladas || 0, sub: 'no período', c: '#94a3b8', help: 'notas_analisadas' },
    ];

    row.innerHTML = cards.map(c => `
      <div class="fis-kpi-card${c.total ? ' fis-kpi-card--total' : ''}" style="--fis-c:${c.c}">
        <div class="fis-kpi__header">
          <div class="fis-kpi__label">${c.label}</div>
          <button class="fis-help-btn" data-help="${c.help}" aria-label="Ajuda: ${c.label}" title="Entender este indicador"><span class="material-symbols-rounded">help</span></button>
        </div>
        <div class="fis-kpi__value">${c.value}</div>
        <div class="fis-kpi__sub">${c.sub}</div>
      </div>
    `).join('');
  }

  function renderTrendChart(trend) {
    destroyChart('trend');
    const canvas = $('fisTrendCanvas');
    if (!canvas || !trend.length) return;

    const labels  = trend.map(r => r.label || r.month);
    const icms    = trend.map(r => r.icms       || 0);
    const pis     = trend.map(r => r.pis        || 0);
    const cofins  = trend.map(r => r.cofins     || 0);
    const ipi     = trend.map(r => r.ipi        || 0);
    const fat     = trend.map(r => r.faturamento || 0);
    const taxScale = niceScale([...icms, ...pis, ...cofins, ...ipi]);

    const maxFat = Math.max(...fat, 1);

    state.charts.trend = new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'ICMS',   data: icms,   backgroundColor: C.icms,   stack: 'trib' },
          { label: 'PIS',    data: pis,    backgroundColor: C.pis,    stack: 'trib' },
          { label: 'COFINS', data: cofins, backgroundColor: C.cofins, stack: 'trib' },
          { label: 'IPI',    data: ipi,    backgroundColor: C.ipi,    stack: 'trib' },
          {
            label: 'Faturamento', data: fat, type: 'line',
            borderColor: C.trend, backgroundColor: C.fat,
            tension: 0.38, pointRadius: 3, fill: true, yAxisID: 'fat',
          },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        animation: { duration: 360 },
        plugins: {
          legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
          tooltip: {
            callbacks: {
              label: ctx => ` ${ctx.dataset.label}: ${fmtR(ctx.parsed.y)}`,
            },
          },
        },
        scales: {
          x: { grid: { display: false } },
          y: {
            stacked: true,
            beginAtZero: true,
            suggestedMax: taxScale.max,
            ticks: { stepSize: taxScale.step, callback: currencyTick },
            grid: { color: 'rgba(0,0,0,0.06)' },
          },
          fat: {
            type: 'linear', position: 'right', display: false,
            min: 0, max: maxFat * 1.15,
          },
        },
      },
    });
  }

  function renderComposition(kpis) {
    const el = $('fisCompositionPanel');
    if (!el || !kpis) return;

    const trib = kpis._trib || 0;
    const items = [
      { label: 'ICMS',   v: kpis._icms   || 0, c: C.icms },
      { label: 'PIS',    v: kpis._pis    || 0, c: C.pis },
      { label: 'COFINS', v: kpis._cofins || 0, c: C.cofins },
      { label: 'IPI',    v: kpis._ipi    || 0, c: C.ipi },
      { label: 'ISS',    v: kpis._iss    || 0, c: C.iss },
    ].filter(i => i.v > 0);

    const max = Math.max(...items.map(i => i.v), 1);

    el.innerHTML = items.map(i => {
      const pct = trib > 0 ? (i.v / trib * 100).toFixed(1) : '0.0';
      const barW = clamp(i.v / max * 100, 0, 100).toFixed(1);
      return `
        <div class="fis-comp-row">
          <div class="fis-comp-label">
            <span class="fis-comp-dot" style="background:${i.c}"></span>${i.label}
          </div>
          <div>
            <div class="fis-comp-bar-wrap">
              <div class="fis-comp-bar-fill" style="width:${barW}%;background:${i.c}"></div>
            </div>
          </div>
          <div class="fis-comp-value">${fmtR(i.v)}</div>
          <div class="fis-comp-pct">${pct}%</div>
        </div>
      `;
    }).join('');
  }

  /* ── Vendors ─────────────────────────────────────────────────── */
  function renderVendors(vendors) {
    const grid = $('fisVendorGrid');
    if (!grid) return;
    if (!vendors.length) { grid.innerHTML = '<div class="fis-loading-cell">Nenhum vendedor no período.</div>'; return; }

    const maxFat = Math.max(...vendors.map(v => v._fat || 0), 1);

    grid.innerHTML = vendors.map(v => {
      const carga = parseFloat(v.carga_pct || 0);
      const barW  = clamp((v._fat || 0) / maxFat * 100, 0, 100).toFixed(1);
      const badgeColor = carga > 32 ? '#dc2626' : carga > 26 ? '#d97706' : '#16a34a';
      const badgeBg    = carga > 32 ? 'rgba(220,38,38,0.10)' : carga > 26 ? 'rgba(217,119,6,0.10)' : 'rgba(22,163,74,0.10)';
      return `
        <div class="fis-vendor-card">
          <div class="fis-vendor-card__name">${v.nome || 'Sem vendedor'}</div>
          <div class="fis-vendor-card__kpis">
            <div class="fis-vendor-card__kpi">
              <small>Faturamento</small>
              <strong>${fmtR(v._fat)}</strong>
            </div>
            <div class="fis-vendor-card__kpi">
              <small>Tributos</small>
              <strong>${fmtR(v._trib)}</strong>
            </div>
            <div class="fis-vendor-card__kpi">
              <small>Carga</small>
              <span class="fis-carga-badge" style="background:${badgeBg};color:${badgeColor}">${fmtP(carga)}</span>
            </div>
          </div>
          <div class="fis-vendor-bar-wrap">
            <div class="fis-vendor-bar-fill" style="width:${barW}%"></div>
          </div>
        </div>
      `;
    }).join('');
  }

  /* ── NF List ─────────────────────────────────────────────────── */
  function loadNfList() {
    const tbody = $('fisNfTbody');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td class="fis-loading-cell" colspan="14">Carregando...</td></tr>`;

    const extra = { page: state.page, per_page: state.perPage, sort: state.nfSort, direction: state.nfDirection };
    if (state.status) extra.status = state.status;
    if (state.search) extra.q = state.search;
    if (state.client) extra.cliente = state.client;
    if (state.vendor) extra.vendedor = state.vendor;

    fetch(buildUrl('api/fiscal_nf_list.php', extra))
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="14">Erro.</td></tr>'; return; }
        renderNfRows(d.rows || []);
        renderNfPagination({ page: d.page, total_pages: d.pages, total: d.total });
        renderNfSort();
      })
      .catch(() => { tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="14">Erro de rede.</td></tr>'; });
  }

  function renderNfRows(rows) {
    const tbody = $('fisNfTbody');
    if (!tbody) return;
    if (!rows.length) { tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="14">Nenhuma NF no período.</td></tr>'; return; }

    tbody.innerHTML = rows.map(r => {
      const carga = Number(r.carga_pct ?? (r._fat > 0 ? r._trib / r._fat * 100 : 0));
      const level = cargaLevel(carga);
      const confidence = confidenceFor(r);
      return `
        <tr>
          <td><span class="fis-nf-number">${esc(r.numero_nf || '-')}</span></td>
          <td>${esc(r.data_label || '-')}</td>
          <td class="fis-ellipsis" title="${esc(r.nome_cliente || '')}">${esc(r.nome_cliente || '-')}</td>
          <td>${esc(r.nome_vendedor || '-')}</td>
          <td class="fis-r fis-bold">${fmtR(r._fat)}</td>
          <td class="fis-r fis-revenue-net">${r.receita_pos_tributos || fmtR(r._receita_pos)}</td>
          <td class="fis-r" style="color:#6366f1">${r.icms || 'R$ 0,00'}</td>
          <td class="fis-r" style="color:#0ea5e9">${r.pis || 'R$ 0,00'}</td>
          <td class="fis-r" style="color:#10b981">${r.cofins || 'R$ 0,00'}</td>
          <td class="fis-r" style="color:#f59e0b">${r.ipi || 'R$ 0,00'}</td>
          <td class="fis-r fis-bold">${fmtR(r._trib)}</td>
          <td class="fis-r"><span class="fis-carga-pill fis-carga-pill--${level}" title="Média do período: ${fmtP(state.avgCarga)}">${fmtP(carga)}</span></td>
          <td>
            <span class="fis-confidence fis-confidence--${confidence.key}"><span class="material-symbols-rounded">${confidence.icon}</span>${confidence.label}</span>
          </td>
          <td>
            <button class="fis-nf-link" onclick="FIS.openNfDrawer(decodeURIComponent('${encodeURIComponent(r.nota_key)}'))">
              <span class="material-symbols-rounded">receipt_long</span>Ver detalhes
            </button>
          </td>
        </tr>
      `;
    }).join('');
  }

  function renderNfPagination(pg) {
    const el = $('fisNfPagination');
    if (!el) return;
    const { page = 1, total_pages = 1, total = 0 } = pg;
    if (total_pages <= 1) { el.innerHTML = `<span style="font-size:11px;color:var(--fis-muted)">${total} registros</span>`; return; }

    let html = `<span style="font-size:11px;color:var(--fis-muted);margin-right:8px">${total} registros</span>`;
    html += `<button class="fis-pag-btn" onclick="FIS.goPage(${page - 1})" ${page <= 1 ? 'disabled' : ''}>&lsaquo;</button>`;

    const start = Math.max(1, page - 2);
    const end   = Math.min(total_pages, page + 2);
    for (let i = start; i <= end; i++) {
      html += `<button class="fis-pag-btn${i === page ? ' fis-pag-btn--active' : ''}" onclick="FIS.goPage(${i})">${i}</button>`;
    }

    html += `<button class="fis-pag-btn" onclick="FIS.goPage(${page + 1})" ${page >= total_pages ? 'disabled' : ''}>&rsaquo;</button>`;
    el.innerHTML = html;
  }

  function renderNfSort() {
    document.querySelectorAll('[data-nf-sort]').forEach(th => {
      const active = th.dataset.nfSort === state.nfSort;
      th.classList.toggle('is-sorted', active);
      th.dataset.sortDirection = active ? state.nfDirection : '';
    });
  }

  /* ── NCM ─────────────────────────────────────────────────────── */
  function loadNcm(force = false) {
    const tbody = $('fisNcmTbody');
    if (tbody) tbody.innerHTML = `<tr><td class="fis-loading-cell" colspan="9">Carregando NCM...</td></tr>`;

    const ncmUrl = force ? buildUrl('api/fiscal_ncm.php', { force: '1' }) : buildUrl('api/fiscal_ncm.php');
    fetch(ncmUrl)
      .then(r => r.json())
      .then(d => {
        if (!d.ok) {
          if (tbody) tbody.innerHTML = `<tr><td class="fis-loading-cell" colspan="9">Erro ao carregar NCM.</td></tr>`;
          return;
        }
        if (!d.has_mart) {
          if (tbody) tbody.innerHTML = `<tr><td class="fis-loading-cell" colspan="9">Mart não construído. Use o botão acima.</td></tr>`;
          return;
        }
        renderNcmTable(d.ranking || []);
        renderCfopList(d.cfops  || []);
      })
      .catch(() => {
        if (tbody) tbody.innerHTML = `<tr><td class="fis-loading-cell" colspan="9">Erro de rede.</td></tr>`;
      });
  }

  function renderNcmTable(ranking) {
    const tbody = $('fisNcmTbody');
    if (!tbody) return;
    if (!ranking.length) { tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="9">Nenhum NCM.</td></tr>'; return; }

    tbody.innerHTML = ranking.map(r => `
      <tr>
        <td style="font-family:monospace;font-weight:700;color:var(--fis-accent)">${r.ncm || '-'}</td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${r.produto || ''}">${r.produto || '-'}</td>
        <td class="fis-r fis-bold">${r.faturamento}</td>
        <td class="fis-r">${r.trib_total}</td>
        <td class="fis-r">${fmtP(r.carga_pct)}</td>
        <td class="fis-r">${Number(r.nfs || 0).toLocaleString('pt-BR')}</td>
        <td><span class="fis-cfop-code">${r.principal_cfop || '-'}</span><small class="fis-cfop-desc">${esc(r.principal_cfop_desc || '')}</small></td>
        <td class="fis-r">${fmtP(r.aliq_icms)}</td>
        <td>
          <button class="fis-nf-link" onclick="FIS.openNcmDrawer('${r.ncm}')">
            <span class="material-symbols-rounded">search</span>Produtos
          </button>
        </td>
      </tr>
    `).join('');
  }

  function renderCfopList(cfops) {
    const el = $('fisCfopList');
    if (!el || !cfops.length) return;

    const maxFat = Math.max(...cfops.map(c => c._fat || 0), 1);
    el.innerHTML = cfops.map(c => `
      <div class="fis-cfop-row">
        <span class="fis-cfop-code" title="${esc(c.descricao || '')}">${c.cfop}</span>
        <div class="fis-cfop-bar-wrap">
          <div class="fis-cfop-bar-fill" style="width:${clamp(c._fat / maxFat * 100, 0, 100).toFixed(1)}%"></div>
        </div>
        <span class="fis-cfop-val" title="${esc(c.descricao || '')}">${c.faturamento}<small>${esc(c.descricao || '')}</small></span>
      </div>
    `).join('');
  }

  /* ── Reform ─────────────────────────────────────────────────── */
  function loadReform() {
    const sec = $('fis-section-reform');

    fetch(buildUrl('api/fiscal_reform.php'))
      .then(r => r.json())
      .then(d => {
        if (!d.ok || !d.has_mart) {
          const msg = $('fisReformMsg');
          if (msg) msg.textContent = 'Mart não construído — seção indisponível.';
          return;
        }
        renderReformKpis(d.totals);
        renderReformChart(d.trend || []);
        renderReformNcm(d.ncm_rank || []);
      })
      .catch(() => {});
  }

  function renderReformKpis(t) {
    const el = $('fisReformKpis');
    if (!el || !t) return;

    el.innerHTML = `
      <div class="fis-reform-card">
        <div class="fis-reform-card__label">Faturamento</div>
        <div class="fis-reform-card__value">${t.faturamento}</div>
      </div>
      <div class="fis-reform-card">
        <div class="fis-reform-card__label">Carga Atual (ICMS+PIS+COFINS+IPI)</div>
        <div class="fis-reform-card__value">${t.carga_atual}</div>
        <small style="color:var(--fis-muted);font-size:10px">${t.carga_atual_pct}% do faturamento</small>
      </div>
      <div class="fis-reform-card">
        <div class="fis-reform-card__label">CBS + IBS Informativo¹</div>
        <div class="fis-reform-card__value">${t.carga_nova_info}</div>
        <small style="color:var(--fis-muted);font-size:10px">${t.carga_nova_pct}% do faturamento</small>
      </div>
      <div class="fis-reform-card fis-reform-card--highlight">
        <div class="fis-reform-card__label">Diferença informativa</div>
        <div class="fis-reform-card__value">${t.reducao_absoluta}</div>
        <span class="fis-reform-card__badge">${t.reducao_pct}% vs carga atual</span>
      </div>
    `;
  }

  function renderReformChart(trend) {
    destroyChart('reform');
    const canvas = $('fisReformCanvas');
    if (!canvas || !trend.length) return;

    const labels   = trend.map(r => r.label);
    const cAtual   = trend.map(r => r.carga_atual || 0);
    const cNova    = trend.map(r => r.carga_nova  || 0);
    const scale    = niceScale([...cAtual, ...cNova]);

    state.charts.reform = new Chart(canvas, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Carga Atual',  data: cAtual, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,0.10)', tension: 0.38, fill: true },
          { label: 'CBS+IBS Info.', data: cNova, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.08)', tension: 0.38, fill: true, borderDash: [5, 3] },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        animation: { duration: 300 },
        plugins: {
          tooltip: { callbacks: { label: c => ` ${c.dataset.label}: ${fmtR(c.parsed.y)}` } },
          legend:  { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
        },
        scales: {
          x: { grid: { display: false } },
          y: {
            beginAtZero: true,
            suggestedMax: scale.max,
            ticks: { stepSize: scale.step, callback: currencyTick },
            grid: { color: 'rgba(0,0,0,0.06)' },
          },
        },
      },
    });
  }

  function renderReformNcm(ncm) {
    const el = $('fisReformNcmList');
    if (!el || !ncm.length) return;

    el.innerHTML = ncm.map(r => `
      <div class="fis-reform-ncm-row">
        <span class="fis-reform-ncm-code">${r.ncm || '-'}</span>
        <span class="fis-reform-ncm-name" title="${r.produto || ''}">${r.produto || '-'}</span>
        <span class="fis-reform-ncm-val">${r.cbs}</span>
      </div>
    `).join('');
  }

  /* ── Alerts ─────────────────────────────────────────────────── */
  function loadAlerts() {
    const grid = $('fisAlertGrid');
    if (grid) grid.innerHTML = '<div class="fis-loading-cell">Carregando alertas...</div>';

    fetch(buildUrl('api/fiscal_alerts.php'))
      .then(r => r.json())
      .then(d => {
        if (!d.ok) return;
        renderAlerts(d.alerts || {});
      })
      .catch(() => {});
  }

  const ALERT_ICONS = {
    icms_zero: 'percent',
    canceladas: 'cancel',
    sem_titulo: 'sync_problem',
    sem_ncm: 'category_off',
    xml_pendente: 'link_off',
    carga_acima_media: 'trending_up',
    cbs_ibs_zero: 'balance',
    cfop_incomum: 'route',
    pis_cofins_divergente: 'difference',
  };

  function renderAlerts(alerts) {
    const grid = $('fisAlertGrid');
    if (!grid) return;

    const keys = Object.keys(alerts);
    if (!keys.length) { grid.innerHTML = '<div class="fis-loading-cell">Sem alertas.</div>'; return; }

    grid.innerHTML = keys.map(k => {
      const a   = alerts[k];
      const sev = a.severity || 'info';
      const ico = ALERT_ICONS[k] || 'warning';
      return `
        <div class="fis-alert-card fis-alert-card--${sev}" onclick="FIS.openAlertDrawer('${k}')">
          <div class="fis-alert-card__head">
            <div class="fis-alert-card__icon"><span class="material-symbols-rounded">${ico}</span></div>
            <div class="fis-alert-card__title">${esc(a.label)}</div>
          </div>
          <div class="fis-alert-card__count">${a.count}</div>
          <div class="fis-alert-card__total">${esc(a.total)}</div>
          <div class="fis-alert-card__desc">${esc(a.desc)}</div>
          <div class="fis-alert-card__cta"><span class="material-symbols-rounded">arrow_forward</span>Ver detalhes</div>
        </div>
      `;
    }).join('');

    _fisAlerts = alerts;
  }

  /* ── NF Drawer ───────────────────────────────────────────────── */
  function showDrawer(drawerId, backdropId) {
    $(drawerId)?.classList.add('open');
    $(backdropId)?.classList.add('open');
    document.body.classList.add('fis-drawer-active');
  }

  function hideDrawer(drawerId, backdropId) {
    $(drawerId)?.classList.remove('open');
    $(backdropId)?.classList.remove('open');
    if (!document.querySelector('.detail-drawer.open')) document.body.classList.remove('fis-drawer-active');
  }

  function openNfDrawer(notaKey) {
    const drawer = $('fisNfDrawer');
    if (!drawer) return;
    showDrawer('fisNfDrawer', 'fisNfDrawerBackdrop');

    const title   = $('fisNfDrawerTitle');
    const kpis    = $('fisNfDrawerKpis');
    const tbody   = $('fisNfItemsTbody');

    if (title)  title.textContent   = 'Nota Fiscal ' + notaKey.split('-').pop();
    if (kpis)   kpis.innerHTML      = '<div class="fis-loading-cell">Carregando...</div>';
    if (tbody)  tbody.innerHTML     = '<tr><td class="fis-loading-cell" colspan="9">Carregando itens...</td></tr>';

    fetch(`api/fiscal_nf_detail.php?nota_key=${encodeURIComponent(notaKey)}`)
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { if (tbody) tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="9">Erro.</td></tr>'; return; }
        if (title) title.textContent = `Nota Fiscal ${d.header?.numero_nf || notaKey.split('-').pop()}`;
        const sub = $('fisNfDrawerSub');
        if (sub) sub.textContent = [d.header?.data_emissao, d.header?.nome_cliente, d.header?.documento].filter(Boolean).join(' · ');
        renderNfDrawerKpis(d.header, kpis);
        renderNfDrawerItems(d.items || [], tbody, d.has_items);
      })
      .catch(() => { if (tbody) tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="9">Erro de rede.</td></tr>'; });
  }

  function renderNfDrawerKpis(h, el) {
    if (!el || !h) return;
    const cards = [
      { label: 'Faturamento', v: h.valor_total || 'R$ 0,00' },
      { label: 'Receita pós-tributos', v: h.receita_pos_tributos || 'R$ 0,00' },
      { label: 'Tributos',    v: h.trib_total  || 'R$ 0,00' },
      { label: 'ICMS',        v: h.icms        || 'R$ 0,00' },
      { label: 'Carga',       v: fmtP(h.carga_pct || 0) },
      { label: 'Vendedor',    v: h.nome_vendedor || '-' },
      { label: 'Status',      v: h.status || '-' },
      { label: 'XML',         v: h.has_xml ? 'Vinculado' : 'Pendente' },
    ];
    el.innerHTML = cards.map(c => `
      <div class="fis-drawer-kpi"><small>${esc(c.label)}</small><strong>${esc(c.v)}</strong></div>
    `).join('') + `
      <div class="fis-drawer-audit">
        <span class="fis-confidence fis-confidence--${h.has_xml && !h.has_missing_ncm && !h.has_icms_zero ? 'ok' : 'warning'}">
          <span class="material-symbols-rounded">fact_check</span>
          ${h.has_xml && !h.has_missing_ncm && !h.has_icms_zero ? 'Fiscal OK' : 'Requer revisão'}
        </span>
        ${h.has_missing_ncm ? '<span>Produto sem NCM</span>' : ''}
        ${h.has_icms_zero ? '<span>ICMS zerado em item relevante</span>' : ''}
        ${!h.has_xml ? '<span>XML/chave pendente</span>' : ''}
        ${h.has_xml && h.chave_nfe ? `<button type="button" class="fis-nf-link" data-copy="${esc(h.chave_nfe)}"><span class="material-symbols-rounded">content_copy</span>Copiar chave XML</button>` : ''}
      </div>
    `;
    el.querySelector('[data-copy]')?.addEventListener('click', event => {
      navigator.clipboard?.writeText(event.currentTarget.dataset.copy || '');
      event.currentTarget.textContent = 'Chave copiada';
    });
  }

  function renderNfDrawerItems(items, tbody, hasItems) {
    if (!tbody) return;
    if (!hasItems || !items.length) {
      tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="13">Itens disponíveis somente após construir o mart fiscal.</td></tr>';
      return;
    }
    tbody.innerHTML = items.map(it => `
      <tr>
        <td style="font-family:monospace;font-size:11px;color:var(--fis-muted)">${it.seq || '-'}</td>
        <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(it.descricao || '')}">${esc(it.descricao || '-')}</td>
        <td style="font-family:monospace;font-size:11px;color:var(--fis-accent)">${esc(it.ncm || '-')}</td>
        <td style="font-family:monospace;font-size:11px">${esc(it.cfop || '-')}</td>
        <td class="fis-r">${Number(it.quantidade || 0).toLocaleString('pt-BR', {maximumFractionDigits:2})}</td>
        <td class="fis-r fis-bold">${fmtR(it._fat)}</td>
        <td class="fis-r" style="color:#6366f1">${it.valor_icms || 'R$ 0,00'}</td>
        <td class="fis-r" style="color:#0ea5e9">${it.valor_pis  || 'R$ 0,00'}</td>
        <td class="fis-r" style="color:#10b981">${it.valor_cofins || 'R$ 0,00'}</td>
        <td class="fis-r" style="color:#f59e0b">${it.valor_ipi  || 'R$ 0,00'}</td>
        <td class="fis-r fis-bold">${fmtR(it._trib)}</td>
        <td class="fis-r" style="color:#8b5cf6" title="CBS informativo">${it.cbs_info || 'R$ 0,00'}</td>
        <td class="fis-r" style="color:#06b6d4" title="IBS informativo">${it.ibs_info || 'R$ 0,00'}</td>
      </tr>
    `).join('');
  }

  function closeNfDrawer() {
    hideDrawer('fisNfDrawer', 'fisNfDrawerBackdrop');
  }

  /* ── NCM Drawer ──────────────────────────────────────────────── */
  function openNcmDrawer(ncm) {
    const drawer = $('fisNcmDrawer');
    if (!drawer) return;
    showDrawer('fisNcmDrawer', 'fisNcmDrawerBackdrop');

    const title = $('fisNcmDrawerTitle');
    const tbody = $('fisNcmProductsTbody');

    if (title) title.textContent  = 'NCM ' + ncm;
    if (tbody) tbody.innerHTML    = '<tr><td class="fis-loading-cell" colspan="6">Carregando...</td></tr>';

    fetch(buildUrl('api/fiscal_ncm.php', { ncm }))
      .then(r => r.json())
      .then(d => {
        if (!d.ok || !d.products.length) {
          if (tbody) tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="6">Sem produtos.</td></tr>';
          return;
        }
        tbody.innerHTML = d.products.map(p => `
          <tr>
            <td style="font-family:monospace;font-size:11px">${esc(p.codigo || '-')}</td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(p.descricao || '-')}</td>
            <td class="fis-r">${p.quantidade}</td>
            <td class="fis-r fis-bold">${p.faturamento}</td>
            <td class="fis-r" style="color:#6366f1">${p.icms}</td>
            <td class="fis-r">${p.trib_total}</td>
            <td class="fis-r">${fmtP(p.carga_pct)}</td>
            <td class="fis-r" style="color:var(--fis-muted);font-size:11px">${fmtP(p.aliq_icms)}</td>
          </tr>
        `).join('');
      })
      .catch(() => { if (tbody) tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="6">Erro.</td></tr>'; });
  }

  function closeNcmDrawer() {
    hideDrawer('fisNcmDrawer', 'fisNcmDrawerBackdrop');
  }

  /* ── Alert Drawer ────────────────────────────────────────────── */
  function openAlertDrawer(type) {
    const alerts = _fisAlerts;
    const a      = alerts[type];
    if (!a) return;

    const drawer = $('fisAlertDrawer');
    if (!drawer) return;
    showDrawer('fisAlertDrawer', 'fisAlertDrawerBackdrop');

    const title = $('fisAlertDrawerTitle');
    const sub   = $('fisAlertDrawerSub');
    const tbody = $('fisAlertRowsTbody');

    if (title) title.textContent = a.label;
    if (sub)   sub.textContent   = `${a.count} registros · ${a.total}`;

    const rows  = a.rows || [];
    if (!tbody) return;
    if (!rows.length) { tbody.innerHTML = '<tr><td class="fis-loading-cell" colspan="6">Nenhum registro.</td></tr>'; return; }

    tbody.innerHTML = rows.map(r => `
      <tr>
        <td style="font-family:monospace;font-size:11px">${esc(r.numero_nf || '-')}</td>
        <td>${esc(r.data_label || '-')}</td>
        <td style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.nome_cliente || '-')}</td>
        <td>${esc(r.nome_vendedor || '-')}</td>
        <td class="fis-r fis-bold">${fmtR(r._fat)}</td>
        <td>
          <button class="fis-nf-link" onclick="FIS.openNfDrawer('${r.nota_key}')">
            <span class="material-symbols-rounded">receipt_long</span>NF
          </button>
        </td>
      </tr>
    `).join('');
  }

  function closeAlertDrawer() {
    hideDrawer('fisAlertDrawer', 'fisAlertDrawerBackdrop');
  }

  function exportCsv() {
    const params = {
      page: 1,
      per_page: 100,
      status: state.status || 'todos',
      q: state.search,
      cliente: state.client,
      vendedor: state.vendor,
    };
    fetch(buildUrl('api/fiscal_nf_list.php', params))
      .then(r => r.json())
      .then(data => {
        if (!data.ok) throw new Error('Falha ao exportar');
        const headers = ['NF','Data','Cliente','Vendedor','Faturamento','Receita pós-tributos','ICMS','PIS','COFINS','IPI','Tributos','Carga %','Confiança'];
        const rows = (data.rows || []).map(r => {
          const confidence = confidenceFor(r).label;
          return [r.numero_nf,r.data_emissao,r.nome_cliente,r.nome_vendedor,r.valor_total,r.receita_pos_tributos,r.icms,r.pis,r.cofins,r.ipi,r.trib_total,fmtP(r.carga_pct),confidence];
        });
        const csv = '\uFEFF' + [headers, ...rows].map(row => row.map(value => `"${String(value ?? '').replace(/"/g, '""')}"`).join(';')).join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `fiscal-${state.start}-${state.end}.csv`;
        link.click();
        URL.revokeObjectURL(url);
      })
      .catch(() => alert('Não foi possível gerar o CSV.'));
  }

  function sendToAccountant() {
    const subject = encodeURIComponent(`BIomie Fiscal Intelligence — ${state.start} a ${state.end}`);
    const body = encodeURIComponent(
      `Segue o resumo fiscal do período ${state.start} a ${state.end}.\n\n` +
      `Abra o BIomie para consultar notas, alertas, NCM/CFOP, CBS/IBS e DRE fiscal.`
    );
    window.location.href = `mailto:?subject=${subject}&body=${body}`;
  }

  /* ── Backdrop close ─────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.fis-drawer-close').forEach(btn => {
      btn.addEventListener('click', () => {
        const drawer = btn.closest('.detail-drawer');
        if (drawer?.id === 'fisNfDrawer') closeNfDrawer();
        if (drawer?.id === 'fisNcmDrawer') closeNcmDrawer();
        if (drawer?.id === 'fisAlertDrawer') closeAlertDrawer();
      });
    });

    document.querySelectorAll('.detail-drawer-backdrop').forEach(bd => {
      bd.addEventListener('click', () => {
        if (bd.id === 'fisNfDrawerBackdrop') closeNfDrawer();
        if (bd.id === 'fisNcmDrawerBackdrop') closeNcmDrawer();
        if (bd.id === 'fisAlertDrawerBackdrop') closeAlertDrawer();
      });
    });

    document.getElementById('fisHelpPopoverClose')?.addEventListener('click', closeHelpPopover);
    document.getElementById('fisHelpBackdrop')?.addEventListener('click', closeHelpPopover);

    document.addEventListener('keydown', event => {
      if (event.key !== 'Escape') return;
      closeHelpPopover();
      closeNfDrawer();
      closeNcmDrawer();
      closeAlertDrawer();
    });

    init();
  });

  /* ── Public API ─────────────────────────────────────────────── */
  window.FIS = {
    openNfDrawer,
    openNcmDrawer,
    openAlertDrawer,
    closeNfDrawer,
    closeNcmDrawer,
    closeAlertDrawer,
    goPage: (p) => { state.page = p; loadNfList(); },
    goPurchasePage: (p) => {
      state.purchasePage = Math.max(1, p);
      loadPurchases();
    },
    reload: loadAll,
  };

})();
