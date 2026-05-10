# Metric Registry — BIomie

All 29 KPIs catalogued in `app/DataPlatform/Semantic/MetricRegistry.php`.

**22 active · 7 draft · 0 deprecated**

_Source of truth: `MetricRegistry::build()` — this document is generated from the registry. For authoritative data run `php tools/dev/semantic_audit.php --markdown`._

---

## Sales (7 KPIs)

| ID | Name | Status | Source | Table | Official Date | Permission | Freshness |
|---|---|---|---|---|---|---|---|
| `vendas_comerciais` | Vendas Comerciais | active | gold | `mart_fiscal_nfe` | `COALESCE(data_competencia, data_emissao)` | sales.view | sales |
| `pedidos_faturados` | Pedidos Faturados | active | gold | `mart_cliente_periodo` | `COALESCE(data_faturamento, data_emissao)` | sales.view | sales |
| `pedidos_em_aberto` | Pedidos em Aberto | **draft** | canonical | `biomie_pedidos_canonicos` | `data_emissao` | sales.view | sales |
| `ranking_vendedor` | Ranking de Vendedores | active | gold | `mart_vendedor_dia` | `data_referencia` | sales.view | sales |
| `ticket_medio_pedido` | Ticket Medio por Pedido | **draft** | gold | `mart_fiscal_nfe` | `COALESCE(data_competencia, data_emissao)` | sales.view | sales |
| `top_clientes` | Top Clientes | active | gold | `mart_cliente_periodo` | `competencia` | sales.view | sales |
| `top_produtos` | Top Produtos | active | gold | `mart_produto_periodo` | `competencia` | sales.view | sales |

**Draft notes:**
- `pedidos_em_aberto` — regra de status_comercial "aberto" não formalizada.
- `ticket_medio_pedido` — denominador (avg vs sum/count) pendente de decisão.

---

## Fiscal (5 KPIs)

| ID | Name | Status | Source | Table | Official Date | Permission | Freshness |
|---|---|---|---|---|---|---|---|
| `receita_fiscal` | Receita Fiscal | active | gold | `mart_fiscal_nfe` | `COALESCE(data_competencia, data_emissao)` | fiscal.view | fiscal |
| `notas_autorizadas` | Notas Autorizadas | active | gold | `mart_fiscal_nfe` | `COALESCE(data_competencia, data_emissao)` | fiscal.view | fiscal |
| `notas_canceladas` | Notas Canceladas | active | gold | `mart_fiscal_nfe` | `COALESCE(data_competencia, data_emissao)` | fiscal.view | fiscal |
| `valor_cancelado` | Valor Cancelado | active | gold | `mart_fiscal_nfe` | `COALESCE(data_competencia, data_emissao)` | fiscal.view | fiscal |
| `devolucoes` | Devoluções | **draft** | canonical | `biomie_notas_fiscais_canonicas` | `data_emissao` | fiscal.view | fiscal |

**Draft notes:**
- `devolucoes` — identificação por CFOP/tipo_nf não formalizada.

---

## Finance (5 KPIs)

| ID | Name | Status | Source | Table | Official Date | Permission | Freshness |
|---|---|---|---|---|---|---|---|
| `receita_realizada` | Receita Realizada | active | canonical | `biomie_titulos_financeiros_canonicos` | `data_liquidacao` | finance.view | finance |
| `contas_a_receber` | Contas a Receber | active | canonical | `biomie_titulos_financeiros_canonicos` | `data_vencimento` | finance.view | finance |
| `inadimplencia_total` | Inadimplencia Total | active | canonical | `biomie_titulos_financeiros_canonicos` | `data_vencimento` | finance.view | finance |
| `titulos_vencidos` | Titulos Vencidos | active | canonical | `biomie_titulos_financeiros_canonicos` | `data_vencimento` | finance.view | finance |
| `aging_recebiveis` | Aging de Recebíveis | **draft** | canonical | `biomie_titulos_financeiros_canonicos` | `data_vencimento` | finance.view | finance |

**Draft notes:**
- `aging_recebiveis` — buckets de atraso (0-30, 31-60, 61-90, >90 dias) não formalizados.

---

## Customers (2 KPIs)

| ID | Name | Status | Source | Table | Official Date | Permission | Freshness |
|---|---|---|---|---|---|---|---|
| `clientes_ativos` | Clientes Ativos | **draft** | gold | `mart_cliente_periodo` | `competencia` | sales.view | customers |
| `clientes_recorrentes` | Clientes Recorrentes | **draft** | gold | `mart_cliente_periodo` | `competencia` | sales.view | customers |

**Draft notes:**
- `clientes_ativos` — definição de "ativo = compra nos últimos 90 dias" pendente de confirmação de fonte.
- `clientes_recorrentes` — agrupamento por cliente não consolidado em coluna única.

---

## Geo (3 KPIs)

| ID | Name | Status | Source | Table | Official Date | Permission | Freshness |
|---|---|---|---|---|---|---|---|
| `compradores_por_bairro` | Compradores por Bairro | active | gold | `mart_compradores_por_geo` | `competencia` | geo.view | geo |
| `vendas_por_cidade` | Vendas por Cidade | active | gold | `biomie_geo_vendas_diaria` | `data_referencia` | geo.view | geo |
| `vendas_por_uf` | Vendas por UF | active | gold | `biomie_geo_vendas_diaria` | `data_referencia` | geo.view | geo |

---

## CNAE (2 KPIs)

| ID | Name | Status | Source | Table | Official Date | Permission | Freshness |
|---|---|---|---|---|---|---|---|
| `vendas_por_cnae` | Vendas por CNAE | active | gold | `mart_vendas_por_cnae_cnpj` | `competencia` | geo.view | cnae |
| `ticket_medio_por_cnae` | Ticket Medio por CNAE | **draft** | gold | `mart_vendas_por_cnae_cnpj` | `competencia` | geo.view | cnae |

**Draft notes:**
- `ticket_medio_por_cnae` — denominador (faturamento / qtd_notas) pendente de definição oficial.

---

## Operations & Omie (5 KPIs)

| ID | Name | Domain | Status | Source | Table | Official Date | Permission | Freshness |
|---|---|---|---|---|---|---|---|---|
| `omie_sync_freshness` | Freshness Sync Omie | omie | active | operational | `biomie_pipeline_jobs` | `completed_at` | jobs.view | omie |
| `jobs_falhos` | Jobs com Falha | operations | active | operational | `biomie_pipeline_jobs` | `started_at` | jobs.view | operations |
| `webhooks_pendentes` | Webhooks Pendentes | operations | active | operational | `biomie_omie_webhooks` | `received_at` | jobs.view | operations |
| `shadow_divergencias` | Shadow Divergencias | shadow | active | operational | `biomie_shadow_validations` | `validated_at` | logs.view | operational |
| `coverage_canonical` | Cobertura Canonical | shadow | active | operational | `biomie_canonical_coverage` | `measured_at` | logs.view | operational |

---

## Include / Exclude Rules Summary

| KPI | Include Rule | Exclude Rule |
|---|---|---|
| `vendas_comerciais` | `status_fiscal = 'autorizada'` | `status_fiscal IN ('cancelada','denegada','inutilizada')` |
| `receita_fiscal` | `status_fiscal = 'autorizada'` | same as above |
| `notas_autorizadas` | `status_fiscal = 'autorizada'` | — |
| `notas_canceladas` | `status_fiscal IN ('cancelada','denegada')` | — |
| `valor_cancelado` | `status_fiscal IN ('cancelada','denegada')` | — |
| `pedidos_faturados` | `status_comercial = 'faturado'` | — |
| `contas_a_receber` | `valor_aberto > 0 AND data_liquidacao IS NULL` | — |
| `inadimplencia_total` | `valor_aberto > 0 AND data_vencimento < CURDATE() AND data_liquidacao IS NULL` | — |
| `titulos_vencidos` | `data_vencimento < CURDATE() AND data_liquidacao IS NULL` | — |
| `receita_realizada` | `data_liquidacao IS NOT NULL` | — |
| `compradores_por_bairro` | `bairro_norm != ''` | — |
| `jobs_falhos` | `status = 'failed'` | — |
| `webhooks_pendentes` | `status = 'pending'` | — |
| `shadow_divergencias` | `has_divergence = 1` | — |

---

## See Also

- [SEMANTIC_LAYER.md](SEMANTIC_LAYER.md) — concepts, how to add KPIs, freshness thresholds
- [KPI_CONTRACTS.md](KPI_CONTRACTS.md) — endpoint-level usage rules and source_of_truth continuity
