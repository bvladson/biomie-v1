# BIomie - Mapa de graficos por pagina

Este mapa evita repeticao de KPIs e graficos entre paginas. O index deve resumir. As paginas especificas devem aprofundar.

## Index

Objetivo: resumo executivo comercial.

Graficos permitidos:
- Evolucao da receita resumida (`salesTimelineChart`).
- Ranking comercial Top 5/Top 8 (`vendorsBarChart`).
- Pipeline complementar compacto (`dailyMixedChart`).
- Resumo geografico leve quando a camada gold estiver pronta.

Nao usar:
- Tabela completa de NF.
- Grafico financeiro detalhado.
- Grafico completo do mapa.
- Ranking extenso de produtos.

Sem dados: orientar para revisar periodo/filtros ou abrir a pagina detalhada.

## NF do Periodo

Objetivo: leitura fiscal do periodo.

Graficos permitidos:
- Receita por dia e status fiscal (`ordersStatusChart`).
- Ranking fiscal por vendedor quando houver vendedor confiavel (`ordersVendorChart`).

Preferir tabela:
- Maiores notas fiscais.
- Documentos sem cliente.
- Cancelamentos detalhados.

Sem dados: "Nenhuma nota fiscal emitida no periodo."

## Time Comercial

Objetivo: acompanhar desempenho, carteira e detalhe do vendedor.

Graficos permitidos:
- Pipeline por vendedor (`vendorGroupedBar`).
- Ritmo diario do vendedor selecionado (`drawerDailyChart`).
- Pipeline compacto quando a pagina estiver filtrada (`dailyMixedChart`).

Nao usar:
- Grafico financeiro detalhado.
- Mapa geografico.

Sem dados: mostrar "Sem vendedor" ou EmptyState, preservando a tabela como leitura principal.

## Mix Faturado

Objetivo: entender produtos e composicao do faturamento.

Graficos permitidos:
- Produtos em destaque (`productsRevenueChart`) como barra horizontal.
- Participacao de produtos (`productsShareChart`) quando labels forem legiveis.

Preferir tabela:
- Produtos com nomes longos.
- Itens detalhados.

Sem dados: "Sem produtos faturados no periodo."

## Rota Comercial / Plano de Voo

Objetivo: meta, ritmo, carteira e proximo passo comercial.

Graficos/componentes permitidos:
- Atingimento de meta como bullet/progresso (`achievementBulletWrap`).
- Ritmo do periodo como linha acumulada (`flightPlanChart`).
- Drawer diario do vendedor (`drawerDailyChart`).
- Cenarios de fechamento como barras HTML (`forecastTripletsWrap`).

Nao usar:
- Ranking fiscal completo da pagina NF.
- Grafico complexo quando so ha realizado e meta.

Sem meta: mostrar EmptyState com acao "Abrir metas".

## Mapa de Vendas / Geografia

Objetivo: leitura comercial territorial.

Graficos permitidos:
- Top compradores do bairro selecionado (`buyerChartCanvas`).
- Top CNAEs do bairro selecionado (`cnaeChartCanvas`).
- Mapa Leaflet como leitura principal, nao Chart.js.

Sem dados: mapa cinza/EmptyState e mensagem por filtro, migration ou materializacao.

## Financeiro

Objetivo: caixa, vencidos, recebidos e risco.

Graficos/componentes permitidos:
- Tendencia financeira agregada (`financeTrendChartCanvas`).
- Aging bucket como componente visual/tabela (`financeAgingChart`).
- Distribuicao por vendedor/conta quando ajudar drilldown (`financeVendorsChart`, `financeAccountsChart`).
- Previsao x realizado (`financeForecastChart`).

Preferir tabela:
- Top clientes em atraso.
- Titulos individuais.

Sem sync financeiro: "Os dados financeiros ainda nao foram sincronizados com o Omie."

## Conexao Omie

Objetivo: status operacional de integracoes.

Graficos permitidos:
- Jobs por status, sync por dominio ou erros por tipo apenas se houver historico local confiavel.

Preferir cards/tabelas:
- Ultimo sync.
- Status de APIs.
- Tabelas SQL relacionadas.
- Logs recentes sanitizados.

Nao usar: grafico comercial ou dado mockado.

## Administracao

Objetivo: controle, diagnostico e manutencao.

Graficos permitidos:
- Apenas quando houver historico administrativo real.

Preferir cards/tabelas:
- Usuarios.
- Logs.
- Diagnostico.
- Migrations.

## Padrao tecnico

- Todo canvas deve estar em `.mag-chart-container` ou container equivalente com altura definida.
- Criar, atualizar e destruir graficos via `window.BIOMIE_CHARTS` ou helper local integrado.
- Usar `animation: false`.
- Usar `update('none')` em atualizacoes.
- Usar decimation apenas em linhas com muitos pontos.
- Nunca renderizar Chart.js com dataset vazio.
- Erro de API vira ErrorState.
- Migration/sync ausente vira EmptyState especifico.
