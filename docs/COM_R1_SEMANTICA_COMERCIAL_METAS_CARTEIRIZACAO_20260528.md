# COM.R1 — Semântica Comercial, Metas Proporcionais e Carteirização

**Data:** 2026-05-28 (consolidado)  
**Status:** Implementado — 28/28 guard PASS

---

## 1. Definições Oficiais

### O que é Orçamento?
Proposta ou intenção comercial ainda não convertida em pedido aceito. Mapeado à etapa Omie 10 ("Orçamento - PD"). **Não entra no realizado. Não entra na carteira firme.** Pode aparecer em painel de funil/oportunidade.

- `CommercialPipelineClassifier::SCOPE_ORCAMENTO`
- `should_count_as_realized = false`
- `should_count_as_portfolio = false`

### O que é Carteira?
Pedido aceito ainda não faturado. Representa o backlog comercial firme. Mapeado à etapa Omie 20 ("Pedido/Autorizado"). **Não entra no realizado.** Entra em backlog e projeção.

- `SCOPE_CARTEIRA_ACEITA` (indiferenciado)
- `SCOPE_CARTEIRA_VAREJO` (canal Varejo)
- `SCOPE_CARTEIRA_REVENDA` (canal Revenda)
- `should_count_as_portfolio = true`
- `should_count_as_realized = false`

### O que é Faturado?
Venda com NF autorizada (NF-e emitida e autorizada pela SEFAZ). Sai da carteira. Entra no realizado. **Base Comercial NF** — única fonte de verdade para realizado.

- `SCOPE_FATURADO` (etapa 50)
- `should_count_as_realized = true`
- Fonte: `biomie_notas_fiscais_canonicas WHERE status_fiscal = 'autorizada'`

### O que é Entrega Finalizada?
Status operacional pós-venda (etapa 60 Omie). **Não gera nova receita. Não duplica faturamento.** R$ 652.976 detectados em 2026.

- `SCOPE_ENTREGA_FINALIZADA`
- `should_count_as_realized = false` (a NF já foi contada quando autorizada)
- `is_delivered = true`

### Regra de Varejo/Revenda
Canais/subtipos da carteira. Separados por sufixo no nome do vendedor:
- `CAIO - VAR` → varejo
- `CAIO - REV` → revenda
- São canais **distintos** — nunca aliases equivalentes.
- `business_unit_scope = varejo | revenda`

### Regra de Licitação
Escopo separado do Orç-PD. Não contamina rankings, metas, mix ou visão padrão.

- `CommercialPipelineClassifier::SCOPE_LICITACAO`
- Excluído por `BiVisibilityScope` na camada canônica.
- Meta "Licitação" aparece separada no `sales_goal_period_audit`.

### Regra de Remessa
Escopo operacional separado (movimentação fiscal/logística). Não entra no faturamento comercial padrão.

- `CommercialPipelineClassifier::SCOPE_REMESSA`
- Visível apenas quando filtro "Remessas" ou "Todas as NFs" estiver ativo.

---

## 2. Fórmula de Meta Proporcional

**Implementado em:** `app/Support/SalesGoalPeriodCalculator.php`

Para cada mês que intersecta o período selecionado:

```
meta_proporcional_mes = meta_mensal × (dias_selecionados_no_mes / dias_do_mes)
meta_total_periodo    = soma das meta_proporcional_mes de todos os meses
```

### Exemplo 1: 01/01/2026 até 28/05/2026
```
Jan: meta_jan × 31/31 = 100% de jan
Fev: meta_fev × 28/28 = 100% de fev
Mar: meta_mar × 31/31 = 100% de mar
Abr: meta_abr × 30/30 = 100% de abr
Mai: meta_mai × 28/31 = 90,3% de mai
```
Resultado validado: **R$ 4.160.677** (jan-mai/2026)

### Exemplo 2: 15/04/2026 até 15/05/2026
```
Abr: meta_abr × 16/30 = 53,3% de abr
Mai: meta_mai × 15/31 = 48,4% de mai
```

### Regras gerais
- Meta proporcional é usada nos **cards principais** (plano_voo, vendedores)
- Meta anual é **contexto** — não usada no atingimento de períodos parciais
- **Realizado nunca é proporcional** — sempre soma o faturado real no intervalo
- Período quebrado (ex: 15/04-15/05) é suportado nativamente

---

## 3. Regra de Carteirização

**Implementado em:** `app/Support/ClientPortfolioOwnershipResolver.php` (delega a `CustomerOwnershipResolver`)

### Prioridade de dono do cliente

| Prioridade | Fonte | Confidence |
|---|---|---|
| 1 | `biomie_clientes_dim.vendedor_responsavel_nome` | 100 |
| 2 | Vendedor da primeira NF autorizada do cliente | 70 |
| 3 | Vendedor do primeiro pedido autorizado | 60 |
| 4 | Sem evidência | 0 |

### Regra de venda fora da carteira
Quando o vendedor da NF ≠ dono da carteira do cliente:
- `is_out_of_portfolio = true`
- `conflict_type = sold_by_other_vendor`
- **Não bloqueia a venda** — apenas sinaliza governança comercial

### Regras especiais
- CAIO-REV e CAIO-VAR são donos de **canais distintos** — um conflito entre eles é `cross_channel_conflict`, não alias
- Dono inativo → `owner_inactive_review` (não é conflito operacional)
- Vendedor inativo na venda → `sale_vendor_inactive_warning`

---

## 4. Perguntas respondidas pelo BIomie

| Pergunta | Resposta | Fonte |
|---|---|---|
| O que é orçamento? | Etapa 10, `should_count_as_realized=false` | CommercialPipelineClassifier |
| O que é carteira? | Etapa 20, `should_count_as_portfolio=true` | CommercialPipelineClassifier |
| O que é faturado? | NF autorizada, etapa 50 | Base Comercial NF |
| Quanto realizou no período? | `realized_period` por NF autorizada | SalesGoalPeriodCalculator |
| Qual era a meta proporcional? | `goal_proportional` | SalesGoalPeriodCalculator |
| Qual o gap real? | `gap_proportional = meta - realizado` | SalesGoalPeriodCalculator |
| Quanto em carteira? | Soma etapa 20 (pedidos autorizados) | CommercialPipelineClassifier |
| Quanto é orçamento? | Soma etapa 10 | CommercialPipelineClassifier |
| Quem é dono de cada cliente? | `owner_vendor_name` + `owner_source` | ClientPortfolioOwnershipResolver |
| Quem vendeu para cliente de outro? | `is_out_of_portfolio = true` | ClientPortfolioOwnershipResolver |
| Quais vendas contam para a meta? | `should_count_in_vendor_goal = true` | CommercialPipelineClassifier |

---

## 5. Ferramentas de diagnóstico

| Ferramenta | Propósito |
|---|---|
| `commercial_stage_semantics_audit.php` | Mapeia etapas Omie e classifica escopos |
| `sales_goal_period_audit.php` | Audita metas vs realizado por período |
| `client_portfolio_ownership_audit.php` | Audita dono da carteira e vendas fora |
| `customer_ownership_conflict_audit.php` | Detalha conflitos de carteira por NF |
| `customer_ownership_source_audit.php` | Investiga fontes de dono disponíveis |

---

## 6. Limitações Omie

1. **Varejo/Revenda não vêm diferenciados no payload** — exige `ListarEtapasFaturamento` para rótulos; o BIomie usa heurística de sufixo no nome do vendedor.
2. **Etapa 60 (Entrega Finalizada)** é pós-venda, não nova receita — cuidado ao comparar com realizado.
3. **`uInc` do bronze** (usuário Omie que criou o cliente) ainda não tem dados suficientes para carteirização confiável — fallback usa primeira venda.
4. **CAIO BRUNO - REV** é ghost vendor — aparece em NFs mas não está cadastrado sob esse nome exato no roster.

---

## 7. Arquivos alterados/criados nesta fase

| Arquivo | Tipo | Fase |
|---|---|---|
| `app/Support/CommercialPipelineClassifier.php` | Criado (COM.R1.2) + SCOPE_LICITACAO/REMESSA adicionados | COM.R1 |
| `app/Support/SalesGoalPeriodCalculator.php` | Criado | COM.R1.3 |
| `app/Support/CustomerOwnershipResolver.php` | Criado | COM.R1.4 |
| `app/Support/ClientPortfolioOwnershipResolver.php` | Criado | COM.R1 FASE 6 |
| `tools/dev/commercial_stage_semantics_audit.php` | Criado | COM.R1 FASE 0 |
| `tools/dev/sales_goal_period_audit.php` | Criado | COM.R1.3 FASE 2 |
| `tools/dev/client_portfolio_ownership_audit.php` | Criado | COM.R1 FASE 5 |
| `views/plano_voo.html` | KPI label "Meta proporcional" | COM.R1 FASE 4 |
| `tools/dev/biomie_release_guard.php` | D27 adicionado | COM.R1 FASE 8 |

---

## 8. Validações executadas

```
✔ lint_all 483/483 PASS
✔ schema_migrate verify PASS
✔ biomie_release_guard 28/28 PASS (inclui D27)
✔ commercial_stage_semantics_audit PASS
✔ sales_goal_period_audit PASS (01/01-28/05 e período quebrado)
✔ client_portfolio_ownership_audit PASS
✔ healthz OK
```

---

## 9. Próxima fase recomendada

**COM.R2 — Separação visual Varejo/Revenda/Licitação:**
- Filtro de canal na tabela de vendedores (Varejo | Revenda | Representante)
- Licitação visualmente em seção separada ou com badge
- Remessa visualmente separada com filtro explícito
- Drill-down de carteira por canal no Vendor 360
