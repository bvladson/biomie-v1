# COM.R3 — Comercial 360: Carteira, Recorrência, Metas, Product 360 + Estoque, Otimização de Páginas
**Data:** 2026-05-30  
**Base:** COM.R2.2 fechado (31/31 PASS, healthz ok=true, 1.163 NFs Jan-Mai/2026)

---

## Problemas atacados

| Problema | Solução |
|---|---|
| Gráficos anuais pesados/incompletos | `CommercialPeriodBucket` (≤45d→daily, 46-180d→weekly, >180d→monthly) + `chart-helpers.js` (animation:false, decimation, destroyChart) |
| Metas para períodos quebrados (ex: 15/02→15/03) | `SalesGoalPeriodCalculator` já existia; exposto via `sales_goal_broken_period_audit.php` |
| Carteira de vendedores = pedidos em aberto (errado) | `ClientPortfolio360Resolver` + `api/client_portfolio_360.php` com carteira por clientes atribuídos |
| Product 360 sem estoque | 3 migrations + `Product360Builder` com graceful empty_state |
| pedidos.php estica em períodos grandes | `api/nf_periodo_table.php` (paginação server-side, DataTables-like) + `nf-periodo-table.js` |
| Sem aggregador unificado de métricas | `CommercialMetricsAggregator` (summary, timeSeries, vendorRanking, productTop, clientTop, pipelineScope, goalsSummary) |

---

## Arquivos criados

### Fase 0 — Diagnósticos (read-only)
- `tools/dev/commercial_360_period_audit.php` — compara canônico NF/pedidos vs marts
- `tools/dev/sales_goal_broken_period_audit.php` — testa meta proporcional para 5 períodos (incl. 15/02→15/03)
- `tools/dev/client_portfolio_360_audit.php` — portfolio stats (com dono, sem dono, recorrentes, novos, conflitos)
- `tools/dev/product_360_stock_source_audit.php` — detecta tabelas/payload de estoque (greenfield confirmado)
- `tools/dev/nf_periodo_performance_audit.php` — conta NFs no período, índices, API sem paginação

### Fase 1 — Core Infrastructure
- `app/Commercial/CommercialMetricDate.php` — thin wrapper de `KpiDateContract::fiscal()` com nomenclatura comercial
- `app/Commercial/CommercialPeriodBucket.php` — granularidade ≤45/46-180/>180 dias; `buildBuckets()` para eixo de gráfico
- `app/Commercial/CommercialMetricsAggregator.php` — aggregador read-only: summary/timeSeries/vendorRanking/productTop/clientTop/pipelineScope/goalsSummary

### Fase 3 — Portfolio 360
- `app/Commercial/ClientPortfolio360Resolver.php` — resolve dono do cliente (dim→primeira_nf→sem_dono), recorrência (2+ meses distintos), novos, conflitos
- `api/client_portfolio_360.php` — 7 modos: summary/by_vendor/clients/conflicts/recurring/new/drawer

### Fase 4 — Product 360 + Estoque
- `database/migrations/Version20260530000001_EstoqueLocaisDim.php` — `biomie_estoque_locais_dim`
- `database/migrations/Version20260530000002_ProdutosEstoqueSaldos.php` — `biomie_produtos_estoque_saldos`
- `database/migrations/Version20260530000003_ProdutosEstoqueMovimentos.php` — `biomie_produtos_estoque_movimentos`
- `app/Support/Product360Builder.php` — summary/invoices/clients/vendors/stock/stockMovements por produto; graceful empty_state
- `tools/dev/product_360_audit.php` — valida top 5 produtos: drawer, clientes, vendedores, estoque

### Fase 5 — NF Paginada
- `api/nf_periodo_table.php` — paginação server-side (DataTables-like: draw/recordsTotal/recordsFiltered/data/summary)
- `assets/js/modules/commercial/nf-periodo-table.js` — módulo ES6: init/updatePeriod/updateVendor, busca, sort, paginação

### Fase 6 — Chart Helpers
- `assets/js/core/chart-helpers.js` — `resolveBucketMode/createTimeSeriesBar/createTimeSeriesLine/createDoughnut/destroyChart/updateChartData`

### Fase 7 — Guards
- `tools/dev/biomie_release_guard.php` — adicionado D31 (18 checks, informacional)
- `tools/dev/visual_layout_guard.php` — adicionados 20 checks COM.R3

---

## Fontes de dados

| Dado | Fonte | Estado |
|---|---|---|
| NFs comerciais | `biomie_notas_fiscais_canonicas` | Populada (1.163 NFs Jan-Mai) |
| Pedidos | `biomie_pedidos_canonicos` | Populada |
| Dono do cliente | `biomie_clientes_dim.vendedor_responsavel_nome` | Pode estar vazio se enriquecimento não executado |
| Estoque saldos | `biomie_produtos_estoque_saldos` | Greenfield — vazia |
| Estoque movimentos | `biomie_produtos_estoque_movimentos` | Greenfield — vazia |
| Locais de estoque | `biomie_estoque_locais_dim` | Greenfield — vazia |
| Metas mensais | `biomie_sales_targets_monthly` | Depende de configuração |

---

## Decisões semânticas

### Carteira do vendedor
**Prioridade de dono:**
1. `biomie_clientes_dim.vendedor_responsavel_nome` (confidence 100)
2. Vendedor da primeira NF autorizada do cliente (confidence 70)
3. Sem dono (confidence 0)

A carteira é o conjunto de clientes atribuídos ao vendedor, independente de faturamento no período.

### Cliente ativo no período
Cliente com NF autorizada Orç-PD em `COALESCE(data_competencia, data_emissao)` dentro do período.

### Cliente recorrente
2 ou mais compras em **meses distintos** (não apenas NFs distintas). Evita falso positivo de múltiplas NFs no mesmo dia.

### Cliente novo
`primeira_nf_autorizada >= start_period`.

### Fora da carteira
`vendedor_da_NF ≠ dono_da_carteira`. Apenas sinalizado (`severity: info`). **Não bloqueia faturamento.**

### Meta proporcional
`Σ(meta_mensal × dias_intersectados / dias_do_mês)` — por dias corridos. Implementado em `SalesGoalPeriodCalculator`. Selftest: 4/4 PASS.

### Granularidade temporal (COM.R3)
- ≤ 45 dias → diário
- 46–180 dias → semanal  
- > 180 dias → mensal

> `CommercialTimeBucket` (COM.R2) permanece inalterado com thresholds ≤62/63-180/>180 para compatibilidade retroativa. `CommercialPeriodBucket` (COM.R3) usa os novos limites.

---

## Stock: estado atual e próximos passos

As 3 tabelas de estoque foram criadas via migration idempotente. Estão **vazias**.

O sync via Omie (`ConsultarEstoque`, `ConsultarLocaisEstoque`, `ConsultarMovimentoEstoque`) é escopo de **COM.R4+**.

`Product360Builder.stock()` retorna `empty_state: 'Estoque ainda não sincronizado'` quando as tabelas estiverem vazias.

---

## Comandos de validação

```bash
# Fase 0 — Diagnósticos
php tools/dev/commercial_360_period_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
php tools/dev/sales_goal_broken_period_audit.php --start=2026-02-15 --end=2026-03-15 --pretty
php tools/dev/client_portfolio_360_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
php tools/dev/product_360_stock_source_audit.php --pretty
php tools/dev/nf_periodo_performance_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
php tools/dev/product_360_audit.php --start=2026-01-01 --end=2026-05-29 --pretty

# Guards
php tools/dev/biomie_release_guard.php --start=2026-01-01 --end=2026-05-29 --pretty
php tools/dev/visual_layout_guard.php --pretty
php healthz.php
php bin/schema_migrate.php verify
```

---

## Riscos restantes

1. **`biomie_clientes_dim.vendedor_responsavel_nome` vazio para muitos clientes:** O `ClientPortfolio360Resolver` faz fallback para primeira NF (confidence 70), mas a qualidade do portfolio depende do enriquecimento ter rodado.

2. **Tabelas de estoque greenfield:** Sem dados de estoque até COM.R4+. Product 360 mostra empty_state explícito.

3. **NF table server-side sem migração do HTML:** `api/nf_periodo_table.php` está criado. A view `views/pedidos.html` ainda precisa de JS wiring para usar o novo endpoint em vez de carregar tudo de uma vez. Isso é item de smoke visual.

4. **CommercialMetricsAggregator não é usado pelos endpoints existentes ainda:** `api/commercial_dashboard.php` e `api/canonical_vendors.php` continuam com suas queries diretas. A migração gradual para usar o aggregator é próximo passo.

5. **`ClientPortfolio360Resolver.resolvePortfolioByVendor` com fallback para primeira NF é O(N×M):** Cada cliente dispara 2 queries (`isRecurringClient` e `firstNfDate`). Para portfolios grandes, pode ser lento. Otimização via batch query é improvement futuro.

---

## Smoke visual obrigatório antes de commit/tag

| URL | Critério |
|---|---|
| `/biomie/index.php?start=2026-01-01&end=2026-05-29` | Dashboard carrega Jan-Mai, carteira e orçamentos preenchidos |
| `/biomie/plano_voo.php?start=2026-01-01&end=2026-05-29` | Meta proporcional coerente |
| `/biomie/plano_voo.php?start=2026-02-15&end=2026-03-15` | Meta quebrada por dias (não mês cheio) |
| `/biomie/vendedores.php?start=2026-01-01&end=2026-05-29` | Clientes não zerados, carteira popula |
| `/biomie/pedidos.php?start=2026-01-01&end=2026-05-29` | Tabela não estica a página |
| `/biomie/mercadorias.php?start=2026-01-01&end=2026-05-29` | Product 360 abre sem erro, aba Estoque mostra empty state controlado |

---

**Commit sugerido (após smoke visual aprovado):**  
`feat: add commercial 360 portfolio product stock and period governance`

**Tag sugerida:**  
`com-r3-commercial-360-portfolio-product-stock-stable`
