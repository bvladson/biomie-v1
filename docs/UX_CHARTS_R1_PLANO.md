# UX.CHARTS.R1 — Plano de Governança Visual dos Gráficos BIomie

**Data:** 2026-05-29  
**Status:** Em execução

---

## Diagnóstico Inicial

### Estrutura atual

- **31 canvas** distribuídos em 7 views (index, financeiro, mapa_vendas, vendedores, pedidos, mercadorias, plano_voo)
- **Dois sistemas de renderização**: `renderChartSafe()` (chart-helpers.js) e `B._renderChart()` (chart-factory.js); mais `new Chart()` direto em flight-plan.js, product-detail, geo-detail
- **Legenda global**: `legend: { display: false }` em TODOS os gráficos por padrão (mag-chart-defaults.js)
- **Sem sistema de cores semânticas**: apenas paleta de 7 cores sem significado de negócio
- **Sem insight textual**: nenhum gráfico tem frase de leitura gerencial
- **Sem `aria-label`** nos canvas
- **Sem `data-chart-purpose`** nos containers

### Problemas encontrados

| Problema | Localização | Criticidade |
|---|---|---|
| Multi-série sem legenda | createGroupedBarChart, createMixedDailyChart | Alta |
| Eixo duplo sem legenda visível | createLineChart, createRevenueTicketChart | Alta |
| Doughnut com potencial de muitas categorias | productsRevenueChart, financeVisualCompositionChart | Média |
| Mapa usa revenue/max linear (outlier distorce escala) | biomie-map.js getColorByRevenue | Média |
| Plano de voo mostra valores diários, não acumulado | flight-plan/charts.js renderLine | Alta |
| Cores sem significado consistente | todos os arquivos | Média |
| Sem insight gerencial em nenhum gráfico | todos | Média |
| flight-plan usa `new Chart()` direto, sem governança | flight-plan.js | Baixa |

### Eixos duplos identificados (yAxisID: 'y1')
1. `createLineChart` — Revenue (barras) + NF count (linha) 
2. `createRevenueTicketChart` — Revenue (barras) + Ticket médio (linha)
3. `financeVisualCompositionChart` — Valor (barras) + % acumulada (linha)

---

## Arquivos que serão alterados

### Criados
- `assets/js/core/chart-governance.js` — camada de governança visual
- `tools/dev/chart_governance_audit.php` — auditoria automática
- `docs/UX_CHARTS_R1_GOVERNANCA_VISUAL_GRAFICOS.md` — documentação final

### Modificados
- `assets/css/mag-design-system.css` — classes mag-chart-insight, mag-chart-legend, etc.
- `assets/js/mag-chart-defaults.js` — semanticPalette + getSemanticColor
- `assets/js/chart-helpers.js` — integrar governance após renderChartSafe
- `assets/js/core/chart-factory.js` — adicionar legendas e insights nas funções existentes
- `assets/js/biomie-map.js` — bucket-based color scale + melhor tooltip
- `assets/js/modules/flight-plan/charts.js` — acumulado vs acumulado + insight
- `assets/js/modules/vendor-detail/charts.js` — legendas em multi-série
- `assets/js/modules/finance/charts.js` — se necessário para insights

---

## Decisão por página

### Dashboard (index.html)
- `salesTimelineChart`: legenda HTML externa obrigatória; insight de tendência
- `vendorsBarChart` (createRevenueTicketChart): simplificar eixo duplo; ticket no tooltip
- `topProductsChart` / `topClientsChart`: manter doughnut se ≤10 categorias; insight de concentração
- `dailyMixedChart`: legenda HTML obrigatória (NF, Carteira, Canceladas); cores semânticas
- `monthComparisonChart`: legenda; insight de % vs meta

### Financeiro (financeiro.html)
- Gráficos FIN.R4 (Visual Analytics): adicionar insights onde dados estiverem disponíveis
- Manter drilldowns intactos
- NÃO alterar lógica de cálculo ou FIN.R3.2

### Mapa (mapa_vendas.html)
- `getColorByRevenue`: usar buckets quando API fornece; fallback para percentil
- Legenda mapa: 5 faixas (sem vendas, baixo, médio, alto, muito alto)
- Tooltip enriquecido: bairro, receita, participação, clientes, NFs

### Vendedores (vendedores.html)
- `vendorGroupedBar`: legenda HTML (NF, Carteira, Canceladas); insight de líder
- `dailyMixedChart`: legenda obrigatória
- `drawerDailyChart`: legenda apenas se multi-série

### Pedidos (pedidos.html)
- `ordersStatusChart`: legenda HTML; cores semânticas
- `ordersVendorChart`: insight de concentração

### Mercadorias (mercadorias.html)
- `productsRevenueChart` / `productsShareChart`: se muitas categorias → simplificar
- Insight de concentração top 5

### Plano de voo (plano_voo.html)
- `flightPlanChart`: calcular acumulado no frontend; mostrar realizado acumulado vs meta acumulada
- `achievementChart`: cores por faixa (≥100% success, 80-99% warning, <80% danger)
- Insights de rota

---

## Critérios de aceite

1. Nenhum cálculo comercial/fiscal/financeiro alterado
2. Todas as páginas carregam sem erro JS
3. Multi-série com legenda HTML visível
4. Eixo duplo tem legenda ou foi simplificado
5. Mapa usa escala coherente com legenda
6. Plano de voo mostra acumulado quando dados disponíveis
7. Empty states funcionando
8. Responsividade mobile preservada
9. `chart_governance_audit.php` executa sem erro crítico
10. Documentação criada
