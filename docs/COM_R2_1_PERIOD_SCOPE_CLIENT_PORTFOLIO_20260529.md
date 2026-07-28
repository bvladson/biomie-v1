# COM.R2.1 — Period Scope Consistency & Client Portfolio Foundation

**Data:** 2026-05-29
**Status:** Implementado — VLG 142/142 PASS; D29 adicionado; lint 12/12 PASS; pendente smoke visual e commit

---

## Diagnóstico encontrado

### Bug 1: `customers_count = 0` para todos os vendedores no Plano de Voo

**Causa raiz:**
- `FiscalReadRepository::aggregateInvoicedByVendorCommercial()` não incluía `COUNT(DISTINCT ...)` por cliente
- `FlightPlanV2Service::build()` nunca adicionava `customers_count` ao array de cada vendor row
- `vendor-table.js` lê `v.customers_count || 0` → sempre retornava 0

**Impacto:** Coluna "Clientes" na tabela de vendedores do Plano de Voo sempre mostrava 0, independentemente do período.

### Bug 2: Inconsistência de período entre endpoints

**Causa raiz:**
- `FiscalReadRepository::aggregateInvoicedByVendorCommercial()` usava `data_competencia BETWEEN :start AND :end` (sem COALESCE)
- `api/commercial_dashboard.php` usa `COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end`
- NFs sem `data_competencia` (campo vazio/NULL) somiam do Plano de Voo mas apareciam no dashboard

**Impacto:** Cards, rankings e realizado inconsistentes entre dashboard e plano de voo em períodos quebrados ou longos.

### Bug 3: `renderPanelState is not defined` no dashboard

**Causa raiz:**
- `renderPanelState` é definida em `app.js` (linha 482) e exposta como `window.renderPanelState` na linha 513
- `render-helpers.js` também define um fallback, mas ambos carregam com `defer`
- `biomie-states.js` carrega SEM defer — qualquer código que rode antes dos scripts defer e chame `renderPanelState` recebe ReferenceError

**Impacto:** Erro JS visível no console. Pode impedir renderização de estado vazio/erro em alguns cards.

---

## Arquivos alterados

### Correções de bugs

| Arquivo | O que mudou |
|---|---|
| `app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php` | `aggregateInvoicedByVendorCommercial`: `COUNT(DISTINCT COALESCE(NULLIF(codigo_cliente_omie,0), NULL)) AS customers_count` + `COALESCE(data_competencia, data_emissao) BETWEEN` |
| `app/FlightPlanV2Service.php` | Bucket `invoicedByVendor` acumula `customers_count`; variável `$customersCount` extraída; `customers_count` adicionado ao `$row` do vendor |
| `assets/js/biomie-states.js` | Safety guard `window.renderPanelState` adicionado ao final do IIFE (carregado sem defer) |

### Novas classes

| Arquivo | Propósito |
|---|---|
| `app/Commercial/CommercialPeriodScopeContext.php` | Contexto único de período e escopo — normaliza start/end, calcula interseção de dias por mês, proporcionaliza meta, gera cache key |
| `app/Commercial/CommercialScopeSqlFilter.php` | Gera WHERE SQL seguro por escopo — COALESCE(data_competencia, data_emissao) para todos os endpoints; usa BiVisibilityScope |
| `app/Commercial/ClientCommercialOwnershipResolver.php` | Resolve dono da carteira por prioridade: dim_responsavel (100) → primeira NF (70) → sem_dono (0). Detecta is_out_of_portfolio, is_cross_channel, is_new/recurring/recovered_customer |

### Ferramentas de diagnóstico (read-only)

| Arquivo | Propósito |
|---|---|
| `tools/dev/commercial_period_consistency_audit.php` | Compara métricas (NFs, base comercial, clientes, vendedores) entre 5 fontes para o mesmo período; detecta date_filter_gap, visibility_gap, clientes_gap |
| `tools/dev/sales_goal_period_consistency_audit.php` | Testa SalesGoalPeriodCalculator em 3 períodos; compara meta proporcional com realizado canônico |
| `tools/dev/client_commercial_ownership_audit.php` | Audita propriedade de clientes; detecta vendas fora da carteira, clientes sem dono, cross-channel |
| `tools/dev/commercial_query_performance_audit.php` | Auditoria estática de 6 endpoints críticos: COALESCE date, BiVisibilityScope, sem SELECT *, LIMIT, prepared statements |

### Guards

| Arquivo | O que mudou |
|---|---|
| `tools/dev/biomie_release_guard.php` | D29 adicionado (13 checks COM.R2.1); cabeçalho atualizado com D29 |
| `tools/dev/visual_layout_guard.php` | 13 checks COM.R2.1 adicionados (142/142 PASS) |

---

## Regras de escopo implementadas

### FiscalReadRepository.aggregateInvoicedByVendorCommercial (corrigido)
```sql
SELECT
    codigo_vendedor_omie, nome_vendedor,
    COUNT(*) AS notes_count,
    COUNT(DISTINCT COALESCE(NULLIF(codigo_cliente_omie, 0), NULL)) AS customers_count,
    COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
    COALESCE(SUM(valor_total), 0) AS invoice_total,
    COALESCE(SUM(valor_descontos), 0) AS discount_total
FROM biomie_notas_fiscais_canonicas
WHERE status_fiscal = 'autorizada'
  AND COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
  [commercialScopeFilter]
GROUP BY codigo_vendedor_omie, nome_vendedor
```

### CommercialPeriodScopeContext — meta proporcional
```
Σ (meta_mensal_vendedor × dias_selecionados_no_mes / dias_do_mes)
```
Período 15/04 a 15/05 = meta_abril × 16/30 + meta_maio × 15/31

### ClientCommercialOwnershipResolver — cadeia de dono
1. `biomie_clientes_dim.vendedor_responsavel_nome` — confidence 100
2. Vendedor da primeira NF autorizada do cliente — confidence 70
3. Sem dono — confidence 0

---

## Regra de escopo COALESCE (padronizado em todos os endpoints)
```sql
COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
```
- **Antes:** `data_competencia BETWEEN` (FlightPlanV2Service) vs `COALESCE(...)` (dashboard) → inconsistência
- **Depois:** todos os endpoints usam COALESCE — NFs sem data_competencia não somem

---

## Validações executadas

```
✔ PHP lint: 12/12 PASS
  (CommercialPeriodScopeContext, CommercialScopeSqlFilter, ClientCommercialOwnershipResolver,
   FlightPlanV2Service, FiscalReadRepository, 4 audit tools, 2 guards)
✔ JS syntax: biomie-states.js PASS (node --check)
✔ VLG: 142/142 PASS (0 FAIL)
✔ D29: 13 checks definidos e passando (estático)
⏳ Release Guard DB: pendente (requer conexão com banco)
⏳ Smoke visual: pendente aprovação no browser
⏳ Commit: pendente smoke visual aprovado
```

---

## Limitações conhecidas

1. **`customers_count` por vendedor** — a soma de distinct clients pode ter overlap se dois vendor_codes distintos (ex: CAIO - REV e CAIO - VAR) são mapeados para o mesmo vendor key. O valor será aproximado, mas correto para cada code individualmente.

2. **`ClientCommercialOwnershipResolver`** — sem override manual ainda (nenhuma migration criada). A cadeia dim→primeira NF→sem_dono é read-only.

3. **`renderPanelState` safety guard** — o fallback em `biomie-states.js` é mínimo (sem diagnostics block). A versão completa permanece em `app.js` e `render-helpers.js`.

4. **`CommercialScopeSqlFilter.SCOPE_LICITACAO`** — usa `is_biomie_commercial_visible = 0` para detectar licitação visually. Se a coluna não existir, o fallback pode incluir false positives. BiVisibilityScope já tem proteção similar.

5. **Período por `data_competencia`** — outros métodos do FiscalReadRepository ainda usam bare `data_competencia BETWEEN` em contextos onde é correto (ex: sync jobs que processam por mês de competência). Apenas `aggregateInvoicedByVendorCommercial` foi corrigido.

---

## Períodos testados (estático)

| Período | Meses cobertos | Fraction por mês |
|---|---|---|
| 2026-05-01 a 2026-05-31 | 1 (mai) | 31/31 = 1.0 |
| 2026-04-15 a 2026-05-31 | 2 (abr, mai) | 16/30 + 31/31 |
| 2026-01-01 a 2026-05-29 | 5 (jan-mai) | 1.0+1.0+1.0+1.0+29/31 |
| 2026-04-15 a 2026-05-15 | 2 (abr, mai) | 16/30 + 15/31 |

---

## Próxima fase recomendada

**COM.R2.1.1 — Smoke visual + populamento de KPIs backend:**
- Executar smoke visual nas 4 URLs de teste
- Se `customers_count` estiver OK → merge
- Se `summary-wallet` e `summary-quotes` precisarem de backend → adicionar campos em `api/commercial_dashboard.php`
- `fpOrcamentosValue` → adicionar em FlightPlanV2Service/CanonicalRepository

**COM.R3 — Orçamentos como oportunidade + Kanban pipeline semântico:**
- Populamento completo dos KPIs Carteira aceita e Orçamentos
- API de pipeline com breakdown por escopo e etapa Omie
- Kanban visual: Orçamento → Carteira → Para faturar → Faturado
