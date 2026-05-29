# COM.R1.3 — Semântica Comercial nos Painéis, Metas Proporcionais e Carteirização

**Data:** 2026-05-28  
**Guards:** D24 PASS, 23/23, lint 478/478, PHPStan OK  
**Domínio:** Semântica Comercial — Dashboards, Metas, Carteirização

---

## 1. Diferença entre Orçamento, Carteira, Realizado e Entrega

| Conceito | etapa | Entra no realizado? | Entra na carteira? | Observação |
|---|---|---|---|---|
| **Orçamento** | 10 | ❌ | ❌ | Funil/proposta; mostra em painéis de funil |
| **Carteira Aceita** | 20 | ❌ | ✅ | Pedido aprovado aguardando NF |
| **Faturado** | 50 | ✅ | ❌ | NF emitida; realizado por NF autorizada |
| **Entrega Finalizada** | 60 | ❌ | ❌ | Pós-venda; NF já existia em etapa 50 |
| **Cancelado** | 70 | ❌ | ❌ | Excluído de todos os indicadores |
| **Devolvido** | 80 | ❌ | ❌ | Excluído; devolução tem regra própria |

---

## 2. Regra Oficial de Realizado por NF

**O realizado oficial do BIomie SEMPRE vem de `biomie_notas_fiscais_canonicas` com `status_fiscal = 'autorizada'`, respeitando `BiVisibilityScope`.**

- Não usa `status_comercial = 'faturado'` dos pedidos canônicos como base
- Não duplica quando etapa avança de 50 → 60
- Base preservada em COM.R1.3: **R$ 2.387.506,11 / 548 NFs** no período 2026-01-01 a 2026-05-28

---

## 3. Regra Proporcional de Metas (SalesGoalPeriodCalculator)

```
Para cada mês M interceptado pelo período [start, end]:
  dias_intersectados = dias de M que estão dentro do período
  meta_proporcional_M = meta_mensal_M * dias_intersectados / dias_totais_do_mes_M

meta_total_período = soma de meta_proporcional_M para todos M
```

### Exemplos validados:

| Período | Regra |
|---|---|
| 2026-01-01 → 2026-05-28 | Jan+Fev+Mar+Abr cheios + 28/31 de Mai |
| 2026-04-15 → 2026-05-15 | 16/30 de Abr + 15/31 de Mai |
| 2026-05-01 → 2026-05-31 | Meta cheia de Mai |
| 2026-01-01 → 2026-12-31 | Soma das 12 metas mensais cheias |

Self-test `SalesGoalPeriodCalculator::selfTest()`: **4/4 PASS**.

**NOTA:** `CommercialPlanning::resolveTargetForPeriod()` usa dias úteis ponderados (mais preciso para metas de venda). `SalesGoalPeriodCalculator` usa dias corridos (mais transparente e auditável). Ambas são proporcionais.

---

## 4. Impacto nos Painéis (auditado em 2026-01-01 a 2026-05-28)

| Situação | Antes | Depois (classificador) |
|---|---|---|
| Etapa 60 como "faturado" | R$ 652.976 (139 pedidos) | R$ 0 como realizado; R$ 329.976 como entrega_finalizada |
| Realizado por NF | R$ 2.387.506 (548 NFs) | R$ 2.387.506 — **inalterado** |
| Orçamentos abertos (etapa 10) | Misturado com "outro" | R$ 1.022.674 como `orcamento` |
| Carteira aceita (etapa 20) | Mostrado como "autorizado" | R$ 33.967 como `carteira_aceita` |

---

## 5. Carteirização de Clientes — Diagnóstico

### Fontes disponíveis:

| Prioridade | Fonte | Confiança | Disponível |
|---|---|---|---|
| 1 | `biomie_clientes_dim.vendedor_responsavel_nome` | high | ✅ |
| 2 | `bronze.infoCadastro.uInc` → vendedor | high (se mapeado) | ✗ sem dados |
| 3 | Primeira NF autorizada do cliente | medium | ✅ |
| 4 | Primeiro pedido autorizado | medium | ✅ |

### Conflitos detectados (jan-mai/2026):
- **30 casos** de venda fora da carteira
- **R$ 66.111** em NFs emitidas por vendedor diferente do dono da carteira
- Casos mais frequentes: CAIO-REV vs CAIO BRUNO-REV (clientes compartilhados)

### Regra proposta:
1. Usar `vendedor_responsavel_nome` da dim (confidence=100 por pedido, 90 por NF)
2. Sinalizar conflito quando `NF.nome_vendedor ≠ dim.vendedor_responsavel_nome`
3. **Não bloquear** venda — apenas registrar e exibir no painel do vendedor

---

## 6. Proposta Visual para Carteirização (FASE 6 — não implementada)

| Página | Proposta |
|---|---|
| `vendedores.php` | Card "Vendas fora da carteira" — clientes de outro vendedor |
| `plano_voo.php` | Coluna "Própria/Fora" em tabela de vendedores |
| `financeiro.php` | Cobrança com indicador "dono do cliente" |
| `admin/cockpit` | Qualidade geral da carteirização (% clientes com dono) |

---

## 7. Arquivos entregues

| Arquivo | Tipo | Função |
|---|---|---|
| `app/Support/SalesGoalPeriodCalculator.php` | Novo | Meta proporcional por dias corridos, self-test |
| `tools/dev/commercial_dashboard_semantics_impact_audit.php` | Novo | Impacto nos painéis |
| `tools/dev/sales_goal_period_audit.php` | Novo | Meta proporcional vs realizado por vendedor/mês |
| `tools/dev/customer_ownership_source_audit.php` | Novo | Donos de carteira e conflitos |
| D24 em `biomie_release_guard.php` | Atualizado | 12 checks COM.R1.3 |

---

## 8. Guards

```
biomie_release_guard.php:  23/23 PASS (D24 PASS)
lint_all.php:              478/478 PASS
schema_migrate verify:     OK
PHPStan level=0:           No errors
healthz.php:               ok=true db=true
```

---

## 9. Limitações e Riscos

- `SalesGoalPeriodCalculator` usa dias corridos; `CommercialPlanning` usa dias úteis ponderados — resultados podem divergir levemente
- Vendedores sem meta cadastrada (`target_amount=0`) aparecem com atingimento 0% mesmo com vendas
- `uInc` no bronze de clientes não tem dados ainda — origem do cadastro de clientes não mapeada para vendedores
- Conflitos CAIO-REV vs CAIO BRUNO-REV sugerem que clientes varejo/revenda têm fronteira fluída — carteirização precisa de regra de negócio explícita

---

## 10. Próximos Passos (COM.R1.4)

1. **Integrar `SalesGoalPeriodCalculator` em `vendedores.php`** — substituir cálculo atual
2. **Separar carteira e orçamento nos cards** de `plano_voo.php` e `index.php`
3. **Desbloquear `commercial_stage_stale_repair.php --apply`** (COM.R1.3+)
4. **Mapear `uInc` → vendedor** via `ListarVendedores` para dono original do cadastro
5. **Criar card "Venda fora da carteira"** em `vendedores.php`
