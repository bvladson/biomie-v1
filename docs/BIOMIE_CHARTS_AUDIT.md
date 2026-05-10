# BIomie - Auditoria de graficos

Auditoria da Fase 5 para padronizar Chart.js, containers e estados visuais. A regra adotada foi manter os calculos e fontes existentes, corrigindo apenas renderizacao, proporcao, loading/empty/error e destruicao de instancias.

## Resumo

| Pagina | Grafico | Canvas/Alvo | Fonte | Diagnostico | Correcao |
| --- | --- | --- | --- | --- | --- |
| Index | Evolucao da receita | `salesTimelineChart` | `api/overview.php` / dados agregados do periodo | Chart.js direto, sem EmptyState especifico quando dataset vinha vazio. | Passou por helper local integrado a `BIOMIE_CHARTS`, com destruicao antes de recriar e estado vazio padrao. |
| Index | Comparativo do periodo | `monthComparisonChart` | Historico agregado recebido no overview | Retornava silenciosamente sem dados. | EmptyState "Historico insuficiente para comparacao". |
| Index | Ranking comercial | `vendorsBarChart` | Ranking agregado por vendedor | Podia renderizar muitos nomes ou ficar em branco sem ranking. | Top limitado no script existente e EmptyState de vendedor sem faturamento. |
| Index | Pipeline complementar | `dailyMixedChart` | Serie diaria agregada | Retornava em branco quando nao havia dias. | EmptyState de movimentacao diaria/pipeline. |
| Index | Share PF x PJ | `indexProfileChart` | `api/geo_summary.php` / geo executivo | Helper proprio criava Chart direto. | `index_geo.js` agora delega a `BIOMIE_CHARTS` quando disponivel. |
| Index | Top CNAE geo | `indexCnaeChart` | `api/sales_by_cnae.php` / gold CNAE | Tinha fallback proprio, mas fora do padrao global. | Fallback preservado e render seguro integrado ao helper global. |
| Index | Top cidades | `indexCitiesChart` | `api/top_buyers_by_geo.php` / geo agregado | Tinha fallback proprio. | Render seguro integrado ao helper global. |
| Index | Top bairros | `indexNeighborhoodsChart` | `api/sales_by_neighborhood.php` / gold geografia | Tinha fallback proprio. | Render seguro integrado ao helper global. |
| Time Comercial | Pipeline por vendedor | `vendorGroupedBar`, `dailyMixedChart`, `drawerDailyChart` | `api/vendors.php` e detalhe do vendedor | Usava os mesmos builders do dashboard, com risco de canvas sem altura no drawer. | Containers padronizados e renderizacao reaproveita o helper integrado de `app.js`. |
| NF do Periodo | Status por dia | `ordersStatusChart` | Notas, carteira e canceladas agregadas por data | Canvas podia ficar vazio sem datas. | EmptyState "Nenhuma nota fiscal emitida no periodo". |
| NF do Periodo | Ranking fiscal por vendedor | `ordersVendorChart` | Agregado por vendedor a partir dos dados carregados | Retornava silenciosamente sem ranking. | EmptyState de vendedor sem faturamento. |
| Mix Faturado | Produtos em destaque | `productsRevenueChart` | Produtos agregados | Labels longos podiam pesar; sem dados gerava vazio. | Bar horizontal preservado, EmptyState para ausencia de produtos. |
| Mix Faturado | Participacao de produtos | `productsShareChart` | Produtos agregados | Canvas branco sem mix. | EmptyState para composicao de produtos. |
| Rota Comercial | Ritmo/meta acumulada | `flightPlanChart` | `api/flight_plan.php` ou fallback overview | Usava Chart.js direto e HTML inline para falta de meta. | `flight-plan-v2.js` usa `BIOMIE_CHARTS.renderChartSafe`; falta de meta vira EmptyState com acao "Abrir metas". |
| Rota Comercial | Atingimento de meta | `achievementBulletWrap` | `api/flight_plan.php` | HTML bullet, nao canvas. | Mantido como componente visual por ser melhor que grafico complexo para dois valores. |
| Rota Comercial | Cenarios de fechamento | `forecastTripletsWrap` | `api/flight_plan.php` | HTML bars, nao canvas. | Mantido; EmptyState ja existia para ausencia de carteira. |
| Rota Comercial | Drawer diario do vendedor | `drawerDailyChart` | `api/flight_plan_vendor_detail.php` | Chart.js direto e podia ficar em branco. | Render seguro com EmptyState "Sem vendas diarias para este vendedor". |
| Mapa de Vendas | Top compradores | `buyerChartCanvas` | `api/top_buyers_by_geo.php` | Usava helper antigo sem EmptyState. | `biomie-chart-helpers.js` agora delega ao helper global e renderiza EmptyState. |
| Mapa de Vendas | Top CNAEs | `cnaeChartCanvas` | `api/sales_by_cnae.php` | Usava helper antigo sem EmptyState. | Delegado a `BIOMIE_CHARTS.renderHorizontalBar`. |
| Financeiro | Tendencia/caixa | `financeTrendChartCanvas` injetado | Dados financeiros agregados | Chart direto em canvas injetado. | `finance.js` ganhou `renderCanvasChart` integrado a `BIOMIE_CHARTS`. |
| Financeiro | Aging de vencidos | `financeAgingChart` | Buckets agregados | HTML chart; ja tinha EmptyState. | Mantido como HTML, mais claro para aging. |
| Financeiro | Distribuicoes auxiliares | `financeVendorsChart`, `financeAccountsChart` | Linhas financeiras agregadas | Chart direto, mas com EmptyState proprio. | Integrado ao helper global mantendo drilldown. |
| Financeiro | Previsao x realizado | `financeForecastChart` | Forecast agregado | Chart direto. | Integrado ao helper global, animacao desativada. |
| Conexao Omie | Status operacional | Sem canvas principal | Logs/jobs/status locais | Nao ha grafico Chart.js obrigatorio; cards/status sao mais adequados quando nao ha historico. | Mantido sem grafico falso. |
| Administracao | Diagnostico | Sem canvas principal | Diagnostico admin | Nao ha grafico Chart.js necessario. | Mantido em tabelas/cards para evitar poluicao. |

## Riscos encontrados

- `assets/js/app.js` concentrava muitos `new Chart` sem uma camada comum de EmptyState.
- `assets/js/biomie-chart-helpers.js` renderizava barras do mapa sem destruir instancias via `Chart.getChart`.
- `assets/js/flight-plan-v2.js` ainda criava graficos diretamente e usava HTML inline para falta de meta.
- `assets/js/index_geo.js` tinha fallback proprio, mas fora do helper global.
- `assets/js/finance.js` ja tinha EmptyState proprio, mas nao participava do registro global.
- `assets/js/flight-plan.js` permanece como legado/cache; a pagina atual usa `flight-plan-v2.js`.

## Criterio aplicado

- Canvas sem dados nao deve ficar branco.
- Canvas deve viver dentro de container com altura definida ou classe existente equivalente.
- Toda recriacao deve destruir a instancia anterior.
- Ranking visual fica limitado; lista completa deve ir para tabela/pagina detalhada.
- Front-end apenas formata/renderiza dados ja agregados.
