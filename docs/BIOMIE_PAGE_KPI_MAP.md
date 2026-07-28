# BIomie — Mapa de KPIs, gráficos e tabelas por página

## Objetivo do documento

Este documento define o papel de cada página do BIomie para evitar duplicação de indicadores, gráficos e tabelas. O `index.php` consolida a visão executiva. As páginas internas aprofundam uma área específica. Toda mudança de escopo de uma página deve ser registrada aqui na seção **Decisões tomadas**.

---

## Regras gerais

1. O `index.php` é resumo executivo — resume, não duplica.
2. Páginas internas são drill-down — aprofundam, não repetem o index.
3. Um KPI só deve aparecer em mais de uma página se tiver contexto diferente (ex: receita no index é resumo; receita em pedidos é detalhe fiscal).
4. O mesmo valor não deve aparecer com nomes diferentes na mesma página.
5. Gráficos grandes ficam nas páginas detalhadas.
6. O index usa versões resumidas (Top 5, mini-charts).
7. Tabelas do index: máximo 5 linhas.
8. Diagnóstico técnico fica sempre recolhível (`<details>`).
9. EmptyState é obrigatório quando não houver dados.
10. Cada gráfico precisa ter pergunta de negócio clara antes de ser criado.

---

## Página: index.php — Painel Executivo

### Objetivo
Resumo executivo do BIomie. Primeira tela que o usuário vê. Mostra o estado atual do negócio em uma única leitura sem necessidade de navegar em outras páginas para entender a saúde geral.

### Público principal
Diretoria, gestão comercial, analistas de BI.

### Perguntas que a página deve responder
- Como está o faturamento no período?
- Quantas notas foram emitidas?
- Como está o caixa / recebíveis?
- Existe alerta operacional crítico?
- A integração Omie está saudável?
- Qual módulo precisa de atenção?
- Para qual página devo ir para investigar?

### KPIs principais

| KPI | Definição | Fonte | Endpoint | Observação |
|-----|-----------|-------|----------|-----------|
| Receita | Faturamento bruto do período | `overview.summary.gross_revenue` | `api/overview.php` | Valor principal da tela |
| Notas fiscais | Quantidade de NF autorizadas | `overview.summary.orders` | `api/overview.php` | Complemento fiscal |
| Ticket médio | Receita / NF | `overview.summary.ticket` | `api/overview.php` | Qualidade da venda |
| Clientes faturados | Clientes com NF no período | `overview.summary.clients` | `api/overview.php` | Cobertura de carteira |
| Carteira / Backlog | Pedidos autorizados fora do faturado | `vendors.summary_status.authorized_value` | `api/vendors.php` | Potencial de receita |
| Recebido | Valor efetivamente recebido | `health.summary.received` | `api/commercial_health.php` | Caixa real |
| Em aberto | Títulos a vencer | `health.summary.open` | `api/commercial_health.php` | Risco de caixa |
| Vencido | Títulos inadimplentes | `health.summary.overdue` | `api/commercial_health.php` | Risco financeiro |

### KPIs complementares (seção "Complementos do período")

| KPI | Definição | Fonte | Observação |
|-----|-----------|-------|-----------|
| Mix faturado | Receita de mercadoria (sem serviços) | `summary.merchandise` | Não repetir junto com Receita sem explicar diferença |
| Desconto | Valor de desconto aplicado | `summary.discounts` | Indicador de margem |
| Canceladas | Valor de NF canceladas | `summary.cancelled_value` | Indicador de risco |

### Cards de área — seção "Módulos"

| Card | Métrica exibida | Link | Fonte |
|------|----------------|------|-------|
| Fiscal | Notas faturadas | `pedidos.php` | `summary.orders` |
| Financeiro | Títulos vencidos | `financeiro.php` | `health.summary.overdue` |
| Geografia | Bairros com venda | `mapa_vendas.php` | `geo_index.neighborhoods.data.length` |
| Rota Comercial | Vendedores ativos | `plano_voo.php` | Contagem de vendedores com faturamento |
| Omie | Última sincronização | `integracoes.php` | `overview.meta.freshness_at` |

### Gráficos permitidos

| Gráfico | Canvas ID | Tipo | Objetivo | Limite |
|---------|-----------|------|----------|--------|
| Evolução da receita | `salesTimelineChart` | Linha | Tendência diária | Todo o período |
| Ranking comercial | `vendorsBarChart` | Barra | Top vendedores | 5 vendedores |
| Pipeline diário | `dailyMixedChart` | Misto | Volume vs. valor | Todo o período |
| Comparativo de período | `monthComparisonChart` | Barra | Mês atual vs. meses anteriores | Recolhido por default |
| Share PF×PJ | `indexProfileChart` | Rosca | Perfil da carteira | Recolhido — seção "Perfis" |
| Top Segmentos (CNAE) | `indexCnaeChart` | Rosca | Segmentos compradores | Recolhido — seção "Perfis" |
| Top Cidades | `indexCitiesChart` | Barra | Cidades fora da capital | Recolhido — seção "Perfis" |
| Top Bairros (Fortaleza) | `indexNeighborhoodsChart` | Barra | Bairros da praça base | Recolhido — seção "Perfis" |

> Os gráficos de perfil e geografia ficam dentro de `<details id="geo-executivo">` recolhido por default. Isso é progressive disclosure aceitável.

### Tabelas permitidas

| Tabela | ID | Colunas principais | Limite | Fonte |
|--------|----|--------------------|--------|-------|
| Top pedidos | `topOrdersTable` | NF, Cliente, Vendedor, Itens, Valor | **5 linhas** | `overview.top_orders` |
| Top produtos | `topProductsTable` | Produto, Qtd, NF, Receita | **5 linhas** | `overview.top_products` |
| Top clientes | `topClientsTable` | Cliente, NF, Receita, Ticket | **5 linhas** | `overview.top_clients` |
| Clientes em atraso | `commercialHealthClientsTable` | Cliente, Vencido, Títulos, Dias | **5 linhas** | `health.top_overdue_clients` |

### O que NÃO deve aparecer
- Tabela completa de NF (pertence a `pedidos.php`)
- Ranking fiscal detalhado com todos os vendedores
- Mapa completo de bairros (pertence a `mapa_vendas.php`)
- Diagnóstico Omie completo (pertence a `integracoes.php`)
- Formulário de edição de metas (pertence a `plano_voo_metas.php`)
- Migrations pendentes ou logs técnicos
- Stack traces ou payloads brutos
- Tabelas com mais de 5 linhas

### Links de drill-down
- Top pedidos → `pedidos.php`
- Financeiro → `financeiro.php`
- Mapa → `mapa_vendas.php`
- Metas → `plano_voo.php`
- Omie → `integracoes.php`

### Diagnóstico
- Topbar-meta: período, modo, origem, cobertura — colapsado (`.mag-diagnostics-collapsed`)
- Diagnóstico comercial — dentro de `<details>` recolhido

---

## Página: pedidos.php — NF do Período

### Objetivo
Detalhar notas fiscais emitidas no período. Leitura fiscal completa: receita, mercadoria, desconto, carteira, cancelamentos e maiores notas.

### Público principal
Gestor comercial, analista fiscal, diretoria.

### Perguntas que a página deve responder
- Quanto foi emitido em NF no período?
- Quantas notas foram emitidas?
- Quais foram as maiores notas?
- Quais vendedores tiveram faturamento fiscal?
- Houve cancelamentos? Quanto?
- Qual é a carteira/backlog fiscal?

### KPIs principais

| KPI | data-bind | Definição | Fonte |
|-----|-----------|-----------|-------|
| Receita | `summary-revenue` | Faturamento bruto NF | `api/orders.php` |
| Mercadoria | `summary-merchandise` | Valor de produtos | `api/orders.php` |
| Descontos | `summary-discounts` | Desconto aplicado | `api/orders.php` |
| NF | `summary-orders` | Notas autorizadas | `api/orders.php` |
| Carteira | `summary-pending-orders` | Pedidos fora do faturado | `api/orders.php` |
| Canceladas | `summary-cancelled-value` | Valor cancelado | `api/orders.php` |
| Ticket médio | `summary-ticket` | Receita / NF | `api/orders.php` |
| Vendedores | `summary-vendors` | Com faturamento no período | `api/orders.php` |

### Hero contextual (forecast-box)
Exibe **Carteira fiscal** (`summary-pending-orders`) e **Cancelado** (`summary-cancelled-value`) como contexto rápido. Link "Ver metas e ritmo" aponta para `plano_voo.php`.

### Gráficos permitidos

| Gráfico | Canvas ID | Tipo | Objetivo |
|---------|-----------|------|----------|
| Evolução NF | `ordersStatusChart` | Linha/Barra | Faturado vs. carteira vs. cancelado por dia |
| Ranking por vendedor | `ordersVendorChart` | Barra | Receita fiscal por vendedor |

### Tabelas permitidas

| Tabela | ID | Colunas | Limite | Fonte |
|--------|-----|---------|--------|-------|
| NF emitidas | `ordersTable` | NF, Cliente, Vendedor, Data, Valor | Paginação | `overview.orders` |
| Pedidos em carteira | `pendingOrdersTable` | Pedido, Cliente, Vendedor, Data, Valor | Paginação | `overview.pending_orders` |
| NF canceladas | `cancelledOrdersTable` | NF, Cliente, Vendedor, Data, Valor | Paginação | `overview.cancelled_orders` |

### O que NÃO deve aparecer
- Progress bar de meta (pertence a `plano_voo.php`)
- Indicadores de atingimento percentual de meta
- Títulos financeiros (pertence a `financeiro.php`)
- Mapa de bairros (pertence a `mapa_vendas.php`)
- Diagnóstico Omie completo (pertence a `integracoes.php`)
- Administração de usuários

### Diagnóstico
- Topbar-meta: período, modo, origem, cobertura — colapsado

---

## Página: mercadorias.php — Mix Faturado

### Objetivo
Detalhar a composição do mix de produtos faturados: quais itens mais contribuem para a receita, volume faturado e participação de cada produto.

### Público principal
Gestor de produto, gestor comercial, analista de BI.

### Perguntas que a página deve responder
- Quais produtos mais geraram receita?
- Qual é o volume faturado por produto?
- Qual é o ticket médio do período?
- Quais vendedores movimentaram o mix?
- Existe produto sem código SKU?

### KPIs principais

| KPI | data-bind | Definição | Fonte |
|-----|-----------|-----------|-------|
| Receita | `summary-revenue` | Faturamento bruto | `api/products.php` |
| Mercadoria | `summary-merchandise` | Valor de produtos | `api/products.php` |
| Descontos | `summary-discounts` | Desconto total | `api/products.php` |
| NF | `summary-orders` | Notas que formam o mix | `api/products.php` |
| Ticket médio | `summary-ticket` | Receita / NF | `api/products.php` |
| Vendedores | `summary-vendors` | Com produto faturado | `api/products.php` |

### Hero contextual (forecast-box)
Exibe **Ticket médio** (`summary-ticket`) como contexto qualitativo do mix. Link "Ver metas e ritmo" aponta para `plano_voo.php`.

### Gráficos permitidos

| Gráfico | Canvas ID | Tipo | Objetivo |
|---------|-----------|------|----------|
| Produtos por receita | `productsRevenueChart` | Barra horizontal | Ranking de receita por produto |
| Share do mix | `productsShareChart` | Rosca | Participação percentual dos top produtos |

### Tabelas permitidas

| Tabela | ID | Colunas | Limite | Fonte |
|--------|-----|---------|--------|-------|
| Mix faturado | `productsTable` | Produto, SKU, Qtd, NF, Receita | 20 linhas | `api/products.php` |
| Breakdown do produto | `productBreakdownTable` | Detalhe | Drawer | `api/products.php` |

### O que NÃO deve aparecer
- Progress bar de meta (pertence a `plano_voo.php`)
- Forecast percentual de atingimento
- Títulos financeiros
- Mapa de bairros
- Administração de usuários

### Diagnóstico
- Topbar-meta: período, modo, origem, cobertura — colapsado

---

## Página: financeiro.php — Carteira Financeira

### Objetivo
Mostrar a posição financeira de caixa e recebíveis: títulos recebidos, a vencer, vencidos, aging de inadimplência e clientes em atraso.

### Público principal
Financeiro, diretoria, gestor de risco.

### Perguntas que a página deve responder
- Quanto foi recebido no período?
- Quanto está em aberto?
- Quanto está vencido (inadimplente)?
- Quais clientes estão em atraso?
- Qual é o aging da inadimplência?
- O módulo financeiro foi sincronizado com o Omie?

### KPIs principais

| KPI | data-bind (finance.js) | Definição | Fonte | Endpoint |
|-----|------------------------|-----------|-------|----------|
| Recebido | `health-received` | Títulos liquidados | `finance_overview.summary.received` | `api/finance_overview.php` |
| Em aberto | `health-open` | Títulos a vencer | `finance_overview.summary.open` | `api/finance_overview.php` |
| Vencido | `health-overdue` | Títulos vencidos | `finance_overview.summary.overdue` | `api/finance_overview.php` |
| Conversão de caixa | `health-cash-conversion` | % recebido / emitido | `finance_overview.summary.cash_conversion_pct` | `api/finance_overview.php` |

### Gráficos permitidos

| Gráfico | Canvas ID | Tipo | Objetivo |
|---------|-----------|------|----------|
| Tendência financeira | `financeTrendChart` | Linha | Evolução de recebimentos |
| Status financeiro | `financeStatusChart` | Barra | Recebido vs. em aberto vs. vencido |
| Ranking de vencidos | `financeVendorOverdueChart` | Barra | Vencidos por vendedor/segmento |
| Aging de inadimplência | `financeAgingChart` | Barra | Faixas de atraso (0-30, 31-60…) |
| Forecast de caixa | `financeForecastChart` | Linha | Projeção de recebimentos |

### Tabelas permitidas

| Tabela | ID | Colunas | Limite | Fonte |
|--------|-----|---------|--------|-------|
| Recebidos | `financeReceivedTable` | Cliente, Data, Valor | 20 | `api/finance_overview.php` |
| Em aberto | `financeOpenTable` | Cliente, Vencimento, Valor, Dias | 20 | `api/finance_overview.php` |
| Clientes em atraso | `financeClientsTable` | Cliente, Vencido, Títulos, Maior atraso | 20 | `api/finance_overview.php` |

> **Atenção**: Recebido ≠ Faturado. Nunca apresentar recebido como sinônimo de receita fiscal.

### O que NÃO deve aparecer
- Ranking de NF fiscais (pertence a `pedidos.php`)
- Produtos em destaque (pertence a `mercadorias.php`)
- Mapa de bairros (pertence a `mapa_vendas.php`)
- Edição de metas
- Diagnóstico Omie completo

### Diagnóstico
- Topbar-meta: colapsado
- Estado de sync financeiro: badge "Financeiro Omie" ou "Sem enriquecimento financeiro"

---

## Página: mapa_vendas.php — Geografia Comercial

### Objetivo
Leitura territorial das vendas por cidade e bairro. Onde estão concentrados os compradores, qual o faturamento por região e quais segmentos (CNAEs) dominam cada bairro.

### Público principal
Gestor de território, gestor comercial, analista de BI.

### Perguntas que a página deve responder
- Onde estão concentradas as vendas?
- Quais bairros mais faturaram?
- Quais bairros não têm dados mapeados?
- Quais compradores estão em cada bairro?
- Quais CNAEs se destacam por bairro?
- Há registros sem bairro?

### KPIs principais

| KPI | Element ID | Definição | Fonte | Endpoint |
|-----|------------|-----------|-------|----------|
| Total vendido | `geoKpiRevenue` | Faturamento no recorte geográfico | `geo_summary.summary.revenue` | `api/geo_summary.php` |
| Bairros com venda | `geoKpiAreas` | Bairros com qualquer faturamento | `geo_summary.summary.neighborhoods_with_sales` | `api/geo_summary.php` |
| Melhor bairro | `geoKpiTopArea` | Bairro de maior receita | Calculado no front | `api/sales_by_neighborhood.php` |
| Clientes | `geoKpiClients` | Compradores mapeados | `geo_summary.summary.customers` | `api/geo_summary.php` |
| Notas | `geoKpiNotes` | NF no mart gold | `geo_summary.summary.invoices` | `api/geo_summary.php` |
| Sem bairro | `geoKpiNoArea` | Registros sem bairro informado | `geo_summary.summary.rows_without_neighborhood` | `api/geo_summary.php` |

### Gráficos / Mapa permitidos

| Elemento | ID | Tipo | Objetivo | Fonte | Tabela gold |
|----------|-----|------|----------|-------|-------------|
| Mapa coroplético | `map-container` | Leaflet coroplético | Faturamento por bairro (cor = intensidade) | `api/geo_materialize.php` + `api/sales_by_neighborhood.php` | `mart_compradores_por_geo` |
| Top compradores por bairro | `buyerChartCanvas` | Barra horizontal | Maiores compradores do bairro selecionado | `api/top_buyers_by_geo.php` | `mart_compradores_por_geo` |
| Top CNAEs por bairro | `cnaeChartCanvas` | Barra horizontal | Segmentos do bairro selecionado | `api/top_clients_by_cnae.php` | `mart_compradores_por_geo` |

### Tabelas permitidas

| Tabela | ID | Colunas | Limite | Fonte |
|--------|-----|---------|--------|-------|
| Principais compradores (bairro selecionado) | `topBuyersTable` | Cliente, NF, Faturamento | 10 | `api/top_buyers_by_geo.php` |
| Principais CNAEs (bairro selecionado) | `cnaeRevenueTable` | CNAE, Clientes, Faturamento | 10 | `api/top_clients_by_cnae.php` |
| Ranking de bairros | `scopeRankingTable` | Bairro, Faturamento, Posição | 20 | `api/sales_by_neighborhood.php` |
| Detalhamento por bairro | `drillDownTable` | Bairro, Clientes, NF, Faturamento, Share | Todos | `api/sales_by_neighborhood.php` |

### O que NÃO deve aparecer
- Tabela completa de NF (pertence a `pedidos.php`)
- Ranking fiscal por vendedor (pertence a `pedidos.php` ou `vendedores.php`)
- Títulos financeiros (pertence a `financeiro.php`)
- Edição de metas
- Stack trace ou erro técnico sem contexto

### Diagnóstico (recolhível — `<details>`)
- GeoJSON URL, quantidade de features, propriedade detectada, features sem nome
- Endpoint usado, status HTTP, tempo de resposta, filtros ativos
- Tabela gold, notas no mart, bairros com vendas, match rate, registros sem bairro
- Última materialização, erro sanitizado

### Normalização (regra crítica)
- PHP `GeoMapSupport::normalizeKey()` e JS `normalizeKey()` devem produzir output idêntico
- Ambos removem acentos, caracteres não `[a-z0-9 ]`, espaços duplos, e lowercaseiam
- Bairros com `/` (ex: "Boa Vista / Castelão") devem normalizar para "boa vista castelao" em ambos os lados

---

## Página: plano_voo.php — Rota Comercial

### Objetivo
Acompanhar metas comerciais, plano de voo, realizado, carteira e ritmo. Referência para o time de vendas e gestão.

### Público principal
Gestor comercial, vendedores, diretoria.

### Perguntas que a página deve responder
- Qual é a meta do período?
- Quanto já foi realizado?
- Quanto falta para bater a meta?
- O ritmo atual é suficiente para bater a meta?
- Qual é a carteira/backlog?
- Qual vendedor está acima/abaixo?

### KPIs principais

| KPI | data-bind | Definição | Fonte |
|-----|-----------|-----------|-------|
| Realizado | `fp-realizado` | Faturamento no período | `api/flight_plan.php` |
| NF | `fp-notas` | Notas fiscais | `api/flight_plan.php` |
| Carteira | `fp-pedidos-carteira` | Backlog de pedidos | `api/flight_plan.php` |
| Projeção | `fp-projecao` | Projeção de fechamento | `api/flight_plan.php` |
| Nova meta/dia | `fp-nova-meta-dia` | Meta diária necessária para atingir | `api/flight_plan.php` |
| Backlog | `fp-backlog` | Pedidos em aberto | `api/flight_plan.php` |
| Cancelado | `fp-cancelado` | Valor cancelado | `api/flight_plan.php` |
| Ticket | `fp-ticket` | Ticket médio do período | `api/flight_plan.php` |
| Linha 85% | `fp-linha-85` | Nível de alerta de meta | `api/flight_plan.php` |
| Cobertura | `fp-cobertura` | % de realizado vs. meta | `api/flight_plan.php` |
| Sem meta | `fp-sem-meta` | Vendedores sem meta cadastrada | `api/flight_plan.php` |

### Gráficos permitidos

| Gráfico | Canvas ID | Tipo | Objetivo |
|---------|-----------|------|----------|
| Plano de voo | `flightPlanChart` | Linha + meta | Realizado vs. meta ao longo do período |
| Diário do vendedor | `drawerDailyChart` | Barra | Detalhe diário do vendedor selecionado (drawer) |

### Tabelas permitidas

| Tabela | ID | Colunas | Limite |
|--------|-----|---------|--------|
| Rota da equipe | `flightVendorsTable` | Vendedor, Meta, Realizado, %, Carteira, Projeção | Todos os vendedores |

### O que NÃO deve aparecer
- Tabela completa de NF
- Mapa de bairros
- Diagnóstico Omie completo
- Produtos em destaque (salvo se fizer parte da meta)
- Administração de usuários

### Ação administrativa
- Botão "Editar metas" aparece no `pageHeader` apenas para admin (`Auth::isAdmin()`)
- Link aponta para `plano_voo_metas.php`

### EmptyState
- "Não há meta cadastrada para este período. Acesse 'Editar metas' para configurar."

---

## Página: plano_voo_metas.php — Edição de Metas

### Objetivo
Interface administrativa para cadastrar e editar metas comerciais por vendedor e período.

### Público principal
Administrador, gestor comercial com permissão de admin.

### Tabelas
- `targetsFormTable`: formulário de metas por vendedor

### O que NÃO deve aparecer
- Análise de desempenho completa (pertence a `plano_voo.php`)
- Dados financeiros
- Qualquer conteúdo não relacionado à edição de metas

---

## Página: vendedores.php — Desempenho da Equipe

### Objetivo
Leitura consolidada do desempenho de cada vendedor: faturamento, conversão, carteira e ritmo individual.

### Público principal
Gestor comercial, diretoria.

### Perguntas que a página deve responder
- Como cada vendedor performou no período?
- Qual é a taxa de conversão do time?
- Qual é o backlog por vendedor?
- Como está o ritmo diário de cada vendedor?

### KPIs principais

| KPI | data-bind | Definição | Fonte |
|-----|-----------|-----------|-------|
| Faturado | `status-billed-value` | Receita faturada | `api/vendors.php` |
| NF faturadas | `status-billed-orders` | Notas no faturado | `api/vendors.php` |
| Autorizado | `status-auth-value` | Pedidos em carteira | `api/vendors.php` |
| Autorizados | `status-auth-orders` | Qtd. de pedidos | `api/vendors.php` |
| Cancelado | `status-cancel-value` | Valor cancelado | `api/vendors.php` |
| Cancelados | `status-cancel-orders` | Qtd. cancelada | `api/vendors.php` |
| Ticket | `summary-ticket` | Ticket médio do time | `api/vendors.php` |

### Gráficos permitidos

| Gráfico | Canvas ID | Tipo | Objetivo |
|---------|-----------|------|----------|
| Ranking agrupado | `vendorGroupedBar` | Barra agrupada | Receita + ticket por vendedor |
| Pipeline diário | `dailyMixedChart` | Misto | Status de pipeline por dia |
| Diário do vendedor | `drawerDailyChart` | Barra | Detalhe diário no drawer |

### Tabelas permitidas

| Tabela | ID | Colunas | Limite |
|--------|-----|---------|--------|
| Equipe comercial | `vendorsTable` | Vendedor, Faturado, NF, Carteira, Cancelado, Ticket, Conversão | Todos |

### O que NÃO deve aparecer
- Progresso de meta (pertence a `plano_voo.php`)
- Títulos financeiros individuais
- Produtos por vendedor em detalhe (pertence a `mercadorias.php`)
- Mapa de clientes (pertence a `mapa_vendas.php`)

---

## Página: integracoes.php — Conexão Omie

### Objetivo
Monitorar a saúde da integração com o Omie: credenciais, últimas sincronizações, jobs, domínios e tabelas disponíveis.

### Público principal
Administrador técnico, analista de dados.

### Perguntas que a página deve responder
- As credenciais Omie estão configuradas?
- A API Omie respondeu no último teste?
- Quando foi a última sincronização?
- Quais domínios foram sincronizados?
- Há jobs com erro?
- As tabelas necessárias existem no banco?
- A materialização está atualizada?

### KPIs / Cards

| Card | Definição | Endpoint |
|------|-----------|----------|
| Status credenciais | Configurado / Não configurado | `api/omie_test_connection.php` |
| Última sincronização comercial | Timestamp | `api/status.php` |
| Última sincronização financeira | Timestamp | `api/status.php` |
| Jobs pendentes | Qtd. de jobs na fila | `api/status.php` |
| Jobs com erro | Qtd. de jobs em erro | `api/status.php` |
| Última materialização | Timestamp | `api/status.php` |

### Tabelas permitidas
- Últimos jobs de sync
- Últimos erros
- Tabelas esperadas × existentes
- Status de endpoints Omie

### O que NÃO deve aparecer
- Ranking comercial
- Produtos em destaque
- Edição de metas
- Dados financeiros detalhados
- `app_secret` ou credenciais expostas

### Segurança
- `app_key` deve ser mascarada na interface
- `app_secret` nunca deve ser exibido
- Teste de conexão é ação explícita (botão)

---

## Página: observabilidade.php — Confiança dos Dados

### Objetivo
Diagnóstico operacional do pipeline: cobertura das bases, jobs processados, sincronizações Omie, saúde do pipeline, regras de qualidade, latência e sinais recentes.

### Público principal
Administrador técnico, analista de dados, time de engenharia.

### Tabelas

| Tabela | ID | Objetivo |
|--------|-----|----------|
| Datasets | `obsDatasetsBody` | Cobertura das bases de dados |
| Jobs | `obsJobsBody` | Jobs processados e status |
| Omie | `obsOmieBody` | Sincronizações Omie |
| Pipeline health | `obsPipelineHealthBody` | Saúde do pipeline |
| Regras | `obsRulesBody` | Regras de qualidade de dados |
| Shadow | `obsShadowBody` | Tabelas shadow/staging |
| Falhas | `obsFailuresBody` | Falhas recentes |
| Latência | `obsLatencyBody` | Tempo de resposta dos endpoints |

### O que NÃO deve aparecer
- KPIs comerciais (pertence ao `index.php`)
- Dados de clientes individuais
- Informações financeiras sensíveis

---

## Página: reconciliacao.php — Conciliação Fiscal

### Objetivo
Conciliação de notas fiscais: identificar NFs que entraram no Omie sem passar pelo pipeline canônico, ou vice-versa.

### Público principal
Administrador técnico, analista fiscal.

### O que NÃO deve aparecer
- Análise comercial
- Dados financeiros não relacionados à conciliação fiscal

---

## Página: admin.php — Administração

### Objetivo
Administrar usuários, permissões, migrations e diagnóstico técnico do ambiente.

### Público principal
Administrador do sistema.

### Tabelas permitidas
- Usuários e perfis
- Permissões por módulo
- Migrations pendentes / executadas
- Logs administrativos
- Diagnóstico de ambiente

### O que NÃO deve aparecer
- Ranking comercial
- Dados financeiros detalhados
- Mapa de bairros
- Produtos em destaque
- Rota comercial

### Segurança
- Ações destrutivas requerem confirmação
- CSRF obrigatório em formulários de modificação
- Stack trace não deve aparecer no front-end

---

## Matriz de duplicação

| Indicador / Gráfico / Tabela | Página principal | Index? | Páginas detalhadas | Observação |
|------------------------------|-----------------|--------|-------------------|------------|
| Receita fiscal | `pedidos.php` | Sim — resumo | Também em `mercadorias.php` como contexto | Não confundir com recebido financeiro |
| Notas fiscais (qtd.) | `pedidos.php` | Sim — resumo | Também em `mercadorias.php` e `plano_voo.php` como contexto | |
| Ticket médio | `pedidos.php` | Sim — KPI principal | Também em `vendedores.php` e `mercadorias.php` | |
| Clientes faturados | `pedidos.php` | Sim | Também em `vendedores.php` | |
| Carteira / Backlog | `vendedores.php` | Sim — resumo | Também em `pedidos.php` e `plano_voo.php` | |
| Recebido | `financeiro.php` | Sim — area card | Não deve aparecer em outras páginas | Recebido ≠ Faturado |
| Vencido / Inadimplência | `financeiro.php` | Sim — area card | Não | |
| Mapa por bairro | `mapa_vendas.php` | Apenas mini card | Não em outras páginas | Mapa completo só em `mapa_vendas.php` |
| Top bairros (chart) | `mapa_vendas.php` | Recolhido em `<details>` | Não | Progresssive disclosure aceitável |
| Meta / Atingimento | `plano_voo.php` | Apenas mini card de área | Não deve aparecer com progress bar em `pedidos.php` ou `mercadorias.php` | |
| Forecast de vendas | `plano_voo.php` | Não | Não | |
| Produtos em destaque | `mercadorias.php` | Top 5 tabela | Não | |
| Ranking de vendedores | `vendedores.php` | Top 5 no gráfico | Também em `pedidos.php` por contexto fiscal | Perspectivas diferentes |
| Status Omie | `integracoes.php` | Apenas area card | Não | |
| Jobs / Erros Omie | `integracoes.php` | Não | Não | |
| Migrations | `admin.php` | Apenas alerta se pendente | Não | |
| Pipeline / Observabilidade | `observabilidade.php` | Não | Não | |

---

## Regras de escolha de visualização

### Usar card KPI quando
- Há um número principal para leitura imediata
- Não há série temporal
- A decisão depende de um único valor

### Usar gráfico de linha quando
- Há evolução no tempo
- Há tendência ou ritmo a mostrar
- Quantidade de pontos é razoável (> 5)

### Usar barras verticais quando
- Há ranking entre categorias
- Labels são curtos
- Comparação entre períodos

### Usar barras horizontais quando
- Nomes das categorias são longos (ex: nomes de clientes, produtos)
- Ranking Top 5 / Top 10

### Usar rosca/pizza quando
- Há share/participação percentual
- Número de fatias ≤ 5
- O todo (100%) é relevante

### Usar tabela quando
- O usuário precisa identificar registros específicos
- Há nomes, documentos, datas relevantes
- A precisão é mais importante que a tendência
- Há mais de 10 categorias

### Usar mapa quando
- O dado depende fundamentalmente de localização
- Bairros ou cidades são o foco da análise

### NÃO usar gráfico quando
- Há apenas 1 valor (use card KPI)
- Todos os valores estão zerados (use EmptyState)
- A tabela comunica melhor
- Labels ficam ilegíveis (< 4px por rótulo)
- O gráfico repete exatamente outro já visível na página
- Pizza tem > 5 fatias

---

## Regras visuais para gráficos

1. Todo gráfico deve ter título claro no `wallboard-card-header`.
2. Todo gráfico deve ter subtítulo curto (o que o gráfico mede, não como foi calculado).
3. Todo gráfico deve usar container com altura definida via classe CSS.
4. Todo gráfico deve usar `lazyChart()` + `IntersectionObserver` se estiver fora do viewport inicial.
5. Todo gráfico deve ter EmptyState se `data.length === 0`.
6. Todo gráfico deve ter ErrorState se o endpoint retornar erro.
7. Tooltip deve formatar valores monetários com `formatCurrency()`.
8. Eixo Y monetário usa `formatCurrencyShort()`.
9. Máximo de 5 séries por gráfico; acima disso, agrupar em "Outros".
10. Não usar vermelho para dado neutro — vermelho = alerta/risco.
11. Não usar gráfico 3D.
12. Não usar pizza com mais de 5 fatias.
13. Não usar labels sobrepostas — se inevitável, trocar para tabela.
14. Não usar gridline pesada (`borderColor` deve ter `alpha < 0.15`).
15. Não usar legenda quando há apenas uma série.
16. Não usar animação pesada — `animation: { duration: 300 }`.

### Classes de tamanho

| Classe | Altura | Uso |
|--------|--------|-----|
| `.mag-chart-sm` | 220px | Mini charts, rosca, share |
| `.mag-chart-md` | 320px | Charts principais de página |
| `.mag-chart-lg` | 420px | Charts de detalhe / investigação |

---

## Regras visuais para tabelas

1. Tabela do index: máximo **5 linhas** visíveis.
2. Tabela de página detalhada: paginação ou limite definido no spec da página.
3. Toda tabela deve ter título e descrição no `wallboard-card-header`.
4. Cabeçalho leve (`mag-table-clean`).
5. Hover suave — `background` em `rgba(0,0,0,0.025)`.
6. Valores monetários alinhados à direita (`text-align: right`).
7. Datas formatadas em `dd/mm/aaaa`.
8. Documento (CPF/CNPJ) sempre mascarado com `DataMasker`.
9. Cliente nunca vazio — usar fallback `"Cliente não informado"`.
10. Linha vazia vira EmptyState com mensagem explicativa.
11. Colunas técnicas (IDs internos, hashes) ficam ocultas ou em diagnóstico recolhível.
12. Botão "Ver todos" / link deve levar à página detalhada correspondente.
13. Não repetir a mesma tabela completa em mais de uma página.

---

## Regras de fonte de dados

| Camada | Uso | Exemplo |
|--------|-----|---------|
| **Gold mart** | Leitura rápida de dashboards | `mart_compradores_por_geo`, `mart_vendas_por_cnae_cnpj` |
| **Canonical** | Detalhe validado | `canonical_orders`, `canonical_clients` |
| **Bronze / payload** | Apenas diagnóstico e reparo | Payloads Omie brutos |
| **Omie API live** | **Nunca** para dashboards | Apenas em sync/jobs |

### Regras de fallback
1. Se gold estiver ausente → mostrar EmptyState com ação de materialização
2. Se canonical tem dados mas gold não tem → indicar "materialização pendente"
3. Se Omie API falhou → mostrar último dado em cache com timestamp
4. Se endpoint retornar HTTP 409 → exibir estado amigável de "dados não preparados"
5. Bronze/payload bruto nunca deve ser exibido no front-end de produção

---

## EmptyStates por página

| Página | Mensagem | Ação recomendada |
|--------|----------|-----------------|
| `index.php` | "Não há dados suficientes para montar o resumo executivo." | Verificar sincronização Omie |
| `pedidos.php` | "Nenhuma nota fiscal encontrada para o período selecionado." | Alterar período ou verificar filtros |
| `mercadorias.php` | "Nenhum produto encontrado para o período selecionado." | Alterar período |
| `financeiro.php` | "Os dados financeiros ainda não foram sincronizados com o Omie." | Executar sync financeiro |
| `mapa_vendas.php` | "O mapa ainda não foi materializado para o período selecionado." | Executar materialização |
| `plano_voo.php` | "Não há meta cadastrada para este período." | Acessar "Editar metas" |
| `vendedores.php` | "Nenhum vendedor com faturamento no período." | Alterar período |
| `integracoes.php` | "Ainda não há status recente de sincronização." | Executar sync |
| `observabilidade.php` | "Nenhum dado de observabilidade disponível." | Verificar pipeline |
| `admin.php` | "Nenhum registro encontrado." | — |

---

## Decisões tomadas

### Remover progress bar de meta de `pedidos.html` e `mercadorias.html`
- **O que foi removido**: `forecast-box` com `summary-target`, `summary-progress-bar`, `summary-progress` (pedidos) e `summary-forecast` (mercadorias)
- **De onde saiu**: hero section de `views/pedidos.html` e `views/mercadorias.html`
- **Para onde foi**: esses indicadores pertencem exclusivamente a `plano_voo.php`
- **Motivo**: progress bar de meta em páginas fiscais confunde o usuário — ele pensa que a "meta" é de NF emitidas, quando na verdade é o plano de voo comercial
- **Substituição**: pedidos mostra "Carteira fiscal" (backlog + cancelado); mercadorias mostra "Contexto do mix" (ticket médio) com link para `plano_voo.php`

### Tabelas do index limitadas a 5 linhas (PATCH 10)
- **O que foi mudado**: `slice(0, 8)` → `slice(0, 5)` em topOrders, topProducts, topClients
- **Motivo**: index é resumo executivo; 8 linhas transforma a seção em drill-down indevido

### 4 KPIs duplicados removidos do index (PATCH 10)
- **O que foi removido**: Notas fiscais, Ticket médio, Clientes ativos, Carteira (seção "Indicadores complementares")
- **Motivo**: esses 4 valores já apareciam com os mesmos `data-bind` nos KPIs principais do topo; exibir duas vezes com nomes diferentes é o que o spec chama de "mesmo valor com nomes diferentes"
- **Sobreviveram**: Mix faturado, Desconto, Canceladas (únicos — não aparecem nos KPIs principais)

### Area cards de módulos adicionados ao index (PATCH 10)
- **O que foi adicionado**: seção "Módulos" com 5 cards (Fiscal, Financeiro, Geografia, Rota Comercial, Omie)
- **Motivo**: substituir a navegação implícita por navegação explícita; usuário vê onde deve ir para aprofundar

### Geo charts mantidos no index em `<details>` colapsado
- **Decisão**: manter `indexProfileChart`, `indexCnaeChart`, `indexCitiesChart`, `indexNeighborhoodsChart` recolhidos
- **Motivo**: progressive disclosure — dado complementar disponível sem poluir a visão principal
- **Condição**: se o card de área "Geografia" for suficiente, esses charts podem ser removidos em iteração futura

---

## Endpoints por página — referência rápida

| Página | Endpoint principal | Endpoints secundários |
|--------|-------------------|-----------------------|
| `index.php` | `api/overview.php` | `api/vendors.php`, `api/commercial_health.php`, `api/weather.php` |
| `pedidos.php` | `api/orders.php` | — |
| `mercadorias.php` | `api/products.php` | — |
| `financeiro.php` | `api/finance_overview.php` | — |
| `mapa_vendas.php` | `api/geo_summary.php` | `api/sales_by_neighborhood.php`, `api/top_buyers_by_geo.php`, `api/top_clients_by_cnae.php`, `api/geo_materialize.php` |
| `plano_voo.php` | `api/flight_plan.php` | — |
| `vendedores.php` | `api/vendors.php` | — |
| `integracoes.php` | `api/status.php` | `api/omie_test_connection.php`, `api/omie_status.php`, `api/canonical_sync.php` |
| `observabilidade.php` | `api/operational_observability.php` | — |

---

## Tabelas gold / canonical por página — referência rápida

| Página | Tabela gold principal | Tabela canonical |
|--------|----------------------|-----------------|
| `index.php` | `mart_compradores_por_geo` (geo), canonical snapshot | `canonical_orders`, `canonical_clients` |
| `pedidos.php` | — | `canonical_orders` |
| `mercadorias.php` | — | `canonical_orders` (produtos extraídos) |
| `financeiro.php` | — | Títulos Omie (finance canonical) |
| `mapa_vendas.php` | `mart_compradores_por_geo` | — |
| `plano_voo.php` | — | Metas + `canonical_orders` |
| `vendedores.php` | — | `canonical_orders` (agrupado por vendedor) |
| `integracoes.php` | — | Status tables |
| `observabilidade.php` | — | Pipeline metadata tables |
