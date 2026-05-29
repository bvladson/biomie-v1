# COM.R1.4 — Carteirização Visual + Metas Proporcionais

**Data:** 2026-05-28  
**Status:** Concluído — 24/24 guard PASS, 100/100 visual layout PASS

---

## O que é carteira

Carteira é o conjunto de clientes cuja responsabilidade comercial pertence a um vendedor específico. A fonte primária de verdade é `biomie_clientes_dim.vendedor_responsavel_nome` (confidence=100). Quando esse campo não existe, o sistema faz fallback para o vendedor da primeira NF autorizada do cliente (confidence=70) e depois para o primeiro pedido autorizado (confidence=60).

Carteira **não** é um status de pedido. Ela é uma dimensão de governança comercial, não de faturamento.

## O que é orçamento

Orçamento (escopo semântico `orcamento`) são pedidos em etapas de funil que ainda não foram faturados nem aceitos em carteira. Em geral mapeiam para etapas numéricas baixas (10, 20) no Omie. **Não compõe realizado e não compõe carteira.**

## O que é realizado

Realizado é o faturamento oficial por NF autorizada, lido de `biomie_notas_fiscais_canonicas` onde `status_fiscal = 'autorizada'`. Esta é a **Base Comercial NF** — imutável e independente de qualquer etapa de pedido.

## O que é entrega finalizada

Entrega finalizada (escopo semântico `entrega_finalizada`) são pedidos em etapa 60 do Omie, que representa pós-venda / entrega. **Não gera nova receita.** O valor é R$ 652.976 identificado nos dados de 2026. O CommercialPipelineClassifier classifica etapa 60 como `entrega_finalizada_NOT_new_revenue`.

## Como a meta proporcional é calculada

Usa `SalesGoalPeriodCalculator` (dias corridos):

```
Para cada mês que intersecta o período:
  meta_proporcional_mes = meta_mensal × (dias_intersectados / dias_no_mes)
meta_total_periodo = soma das metas proporcionais por mês
```

Exemplos:
- Jan–28/Mai/2026 = 4 meses completos + (28/31 × meta_Mai) = R$ 4.160.677
- 15/Abr–15/Mai/2026 = (16/30 × meta_Abr) + (15/31 × meta_Mai)

A meta proporcional aparece no campo `target_period` de cada vendedor na resposta de `api/canonical_vendors.php`, e no campo `goal_period.meta_proporcional_total` para o time.

## Como o dono do cliente é definido

`CustomerOwnershipResolver::resolve()` aplica a seguinte cadeia de prioridade:

| Prioridade | Fonte | Confidence |
|---|---|---|
| 1 | `biomie_clientes_dim.vendedor_responsavel_nome` | 100 |
| 2 | Vendedor da primeira NF autorizada do cliente | 70 |
| 3 | Vendedor do primeiro pedido autorizado do cliente | 60 |
| 4 | Sem evidência | 0 |

A comparação de vendedores é feita em uppercase, sem acentos, sem espaços múltiplos. Nomes genéricos ("Sem vendedor", "Não informado", "Enviado via API", "Licitação") são ignorados.

## Como conflitos são sinalizados

Um **conflito de carteirização** ocorre quando o vendedor que fez a venda (NF) é diferente do dono do cliente (resolução acima). O sistema:

1. Identifica o par `(sale_vendor, owner_vendor)` por NF
2. Normaliza ambos os nomes
3. Se forem diferentes e ambos forem não-genéricos → `is_out_of_portfolio = true`
4. Define `conflict_type = sold_by_other_vendor`
5. Gera `conflict_label = "Cliente de {owner} vendido por {sale_vendor}"`
6. Define `display_badge = 'Fora da carteira'` e `severity = 'warn'`

O campo `conflicts_out_of_portfolio` em cada vendedor na resposta de `api/canonical_vendors.php` conta quantas NFs foram vendidas fora da carteira. O campo `ownership_summary.total_conflicts_out` dá o total do time.

## Por que venda fora da carteira não é bloqueada

1. O ERP (Omie) não bloqueia: a base de clientes é compartilhada.
2. Bloquear no BIomie seria contrafactual — a venda já existe no ERP.
3. O objetivo é **sinalizar para governança**, não impedir operações.
4. Há casos legítimos de cobertura de carteira (férias, ausência de vendedor).

## Impacto esperado por página

### vendedores.html
- Seção "Carteirização" com 3 KPIs: Meta proporcional, Realizado por NF, Fora da carteira
- Tabela de vendedores: coluna "Carteira" com badge compacto para vendas fora da carteira
- Drawer do vendedor: seção "CARTEIRIZAÇÃO" com detalhes de conflitos

### api/canonical_vendors.php
- Cada vendedor recebe `target_period`, `realized_by_nf`, `attainment_pct`, `risk_status`, `conflicts_out_of_portfolio`, `conflicts_value_out`, `clients_invaded`
- Resposta global inclui `goal_period` (meta proporcional do time) e `ownership_summary` (total de conflitos)

### api/customer_ownership_conflicts.php
- Endpoint REST com modos `summary` e `invoices`
- Filtros: `start`, `end`, `vendor`, `owner`, `conflict_only`, `limit`, `page`, `sort`
- Cada NF retorna `is_out_of_portfolio`, `conflict_type`, `display_badge`, `severity`

## Limitações

1. A auditoria `customer_ownership_conflict_audit.php` usa `--sample=N` (padrão 50) — o total real de conflitos é maior do que o mostrado na amostra. O total real de 30 conflitos foi detectado pelo `customer_ownership_source_audit.php` em COM.R1.3.
2. `biomie_clientes_dim.vendedor_responsavel_nome` depende da sincronização da dimensão de clientes. Se a dimensão estiver desatualizada, a confiança cai para fallback via NF/pedido.
3. O campo `uInc` do bronze (usuário Omie que criou o cadastro) ainda não tem dados suficientes para ser usado como fonte de dono.
4. Aliases de vendedores (ex: "CAIO - REV" vs "CAIO BRUNO - REV") não são automaticamente resolvidos como a mesma pessoa — normalização futura pode usar um mapa de aliases.

## Próxima fase recomendada

**COM.R1.5 — Aprimoramento de carteirização:**
- Mapa de aliases de vendedores (ex: CAIO-REV → CAIO BRUNO-REV)
- Drill-down de conflitos por cliente em vendedores.php
- Filtro `conflict_only` na tabela de vendedores
- Card "Venda fora da carteira" no plano_voo.php
- Desbloquear `--apply` no `commercial_stage_stale_repair.php`
- Mapear uInc do bronze como quarta fonte de dono (confidence=50)
