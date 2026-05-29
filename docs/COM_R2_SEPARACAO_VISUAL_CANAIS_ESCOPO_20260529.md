# COM.R2 — Separação Visual de Canais e Escopos Comerciais

**Data:** 2026-05-29
**Status:** Implementado — D28 (20 checks), VLG ampliado; pendente smoke visual e commit

---

## Objetivo

Transformar a semântica comercial validada na COM.R1 em camada visual clara e operacional nos painéis, separando Varejo, Revenda, Licitação e Remessa, sem alterar nenhum dado, cálculo ou regra de negócio.

---

## Páginas alteradas

| Página | O que mudou |
|---|---|
| `views/vendedores.html` | Filtro de canal (Orç-PD / Varejo / Revenda / Representante / Licitação) |
| `views/plano_voo.html` | Coluna Canal na tabela de vendedores; painel de Escopos separados (Licitação, Remessa, Entrega Finalizada) |
| `views/pedidos.html` | Tab Licitação separada; alerta visual de escopo separado |
| `views/index.html` | Label "Realizado NF" com tooltip explicando Base Comercial NF |

---

## Regra visual de Varejo

- Vendedores com sufixo `- VAR` ou `- VAREJO` no nome → canal `varejo`
- Badge visual: **VAR** (classe `mag-badge--channel-var`)
- Filtro de canal: botão "Varejo" no header da tabela
- Não confundir com Revenda — canais distintos

## Regra visual de Revenda

- Vendedores com sufixo `- REV` ou `- REVENDA` no nome → canal `revenda`
- Badge visual: **REV** (classe `mag-badge--channel-rev`)
- Filtro de canal: botão "Revenda" no header da tabela

## Regra CAIO - REV

- Canal Revenda. Badge REV.
- Meta, realizado e carteira separados de CAIO - VAR.

## Regra CAIO - VAR

- Canal Varejo. Badge VAR.
- Meta, realizado e carteira separados de CAIO - REV.
- Nunca aliases equivalentes.

## Regra Licitação

- Escopo separado do Orç-PD padrão.
- **Não aparece no ranking comercial padrão**, metas Orç-PD ou dashboard executivo.
- Visível apenas com filtro explícito "Licitação".
- No plano_voo: aparece no card compacto "Escopos separados" (collapsible, fora do ranking).
- No pedidos.html: tab separada com alerta explicativo.
- Filtro canal: botão "Licitação" (tom neutro — indica escopo diferente).

## Regra Remessa

- Escopo operacional separado (movimentação fiscal/logística).
- **Não entra no faturamento comercial padrão.**
- Visível apenas no tab "Remessa" de pedidos.php.
- No plano_voo: aparece no card "Escopos separados" (collapsible).

## Regra Orçamento

- Proposta/intenção, etapa 10 Omie.
- Não entra no realizado, não entra na carteira firme.
- Separado visualmente dos outros KPIs.

## Regra Carteira

- Pedidos aceitos não faturados (etapa 20).
- KPI "Carteira aceita" separado do "Realizado NF".
- Tooltip em plano_voo.html: "Pedidos aceitos ainda não faturados (carteira aceita)".

## Regra Realizado NF

- Exclusivamente por NF autorizada.
- **Fonte**: `biomie_notas_fiscais_canonicas WHERE status_fiscal = 'autorizada'`.
- Tooltip em index.html: "NF autorizada Orç-PD. Remessas e Licitação excluídas."
- Label consistente em todas as páginas: "Realizado NF" ou "Realizado por NF".

## Regra Entrega Finalizada

- Etapa 60 Omie, pós-venda.
- Aparece no card "Escopos separados" do plano_voo (collapsible).
- Tooltip: "não duplica faturamento".

---

## Filtros novos

| Filtro | Onde | Comportamento |
|---|---|---|
| Canal (Orç-PD/Varejo/Revenda/Representante/Licitação) | vendedores.html | Passa `channel=` para API; filtra tabela |
| Tab Licitação | pedidos.html | Mostra NFs de licitação + alerta escopo separado |
| Escopos separados | plano_voo.html | Painel collapsible com Licitação, Remessa, Entrega Finalizada |

---

## Arquivos criados/modificados

| Arquivo | Tipo | Fase |
|---|---|---|
| `app/Support/CommercialChannelPresenter.php` | Criado | FASE 1 |
| `tools/dev/commercial_visual_scope_audit.php` | Criado | FASE 0 |
| `views/vendedores.html` | Filtro Remessa + tab nav Vendor 360 (6 abas) | FASE 2/3 |
| `views/plano_voo.html` | Coluna Canal + fpScopesPanel com card Orçamentos | FASE 4 |
| `views/pedidos.html` | Tab Licitação + Licitação no select + `nfSemanticLegend` | FASE 5/6 |
| `views/index.html` | Label "Realizado NF" + KPIs Carteira aceita e Orçamentos | FASE 7 |
| `assets/js/app.js` | `BIomieVendorsChannel` passado à API | FASE 3 |
| `assets/js/modules/vendors/page-drilldown.js` | Handler do filtro de canal | FASE 3 |
| `tools/dev/biomie_release_guard.php` | D27+D28 adicionados; D28 com 20 checks | FASE 8 |
| `tools/dev/visual_layout_guard.php` | 18 checks COM.R2 adicionados | FASE 9 |

**Cron scripts fora do escopo desta fase** (não incluir no commit COM.R2):
- `cron/hostinger/materialize.sh` — hardening de lock/sequenciamento
- `cron/hostinger/sync_commercial.sh` — modo BIOMIE_SYNC_MODE
- `cron/hostinger/daily_integrity.sh` — novo script de integridade diária

---

## Mudanças COM.R2 — Detalhes por página

### vendedores.html
- Filtro "Remessa" adicionado ao canal filter (junto a Orç-PD/Revenda/Varejo/Representante/Licitação)
- Drawer Vendor 360 com 5 abas: **Resumo** | **Realizado NF** | **Carteira** | **Carteirização** | **Escopos**
- Tab nav controlado por JS inline (`BIomieV360ResetTabs` + click handler no `drawerTabNav`)
- Cada aba mostra seção semântica clara (Real. NF ≠ Carteira ≠ Escopos separados)

### plano_voo.html
- Card "Orçamentos" adicionado ao `fpScopesPanel` (junto a Licitação, Remessa, Entrega Finalizada)
- Bind: `id="fpOrcamentosValue"` + `id="fpOrcamentosSub"` para preenchimento via JS

### pedidos.html
- `value="licitacao"` adicionado ao select `f_col_tipo` (filtro de coluna NF)
- Bloco `nfSemanticLegend` adicionado antes da seção "Pedidos em carteira": legenda visual com badges coloridos para Orç-PD / Remessa / Licitação / Entrega Finalizada
- Tooltip "Pedidos em carteira" corrigido: "Pedidos aceitos ainda não faturados (carteira aceita). Não entra no realizado."

### index.html
- KPI `summary-wallet` adicionado: **Carteira aceita** — pedidos aceitos não faturados
- KPI `summary-quotes` adicionado: **Orçamentos** — propostas em aberto
- Ambos com tooltips semânticos completos (orçamento ≠ carteira, carteira ≠ realizado)

---

## Validações executadas

```
✔ PHP lint: 7/7 PASS (CommercialChannelPresenter, commercial_visual_scope_audit,
            biomie_release_guard, visual_layout_guard, CommercialPipelineClassifier,
            SalesGoalPeriodCalculator, ClientPortfolioOwnershipResolver)
✔ JS syntax: app.js, page-drilldown.js — PASS (node --check)
✔ Markers VLG (todos): data-vendor-channel="remessa", drawerTabNav, fpOrcamentosValue,
            value="licitacao", nfSemanticLegend, summary-wallet, summary-quotes — PASS
✔ D28: 20 checks definidos; cabeçalho do guard atualizado com D27+D28
✔ VLG: 8 checks adicionais COM.R2 (total 18 checks COM.R2 na fase)
⏳ Smoke visual: pendente aprovação no browser
⏳ Commit: pendente smoke visual aprovado
```

---

## Limitações conhecidas

1. **Backend channel filter** — `api/canonical_vendors.php` já suporta `?channel=` (COM.R1.5). Os dados vêm de `ActiveVendorResolver` que usa sufixo do nome. Vendedores sem sufixo padrão ficam como `outro`.

2. **plano_voo.php** — a coluna Canal na tabela de vendedores exibe o badge via render, mas o dado `vendor_channel` precisa ser exposto pelo builder de plano de voo (FlightPlanV2). Atualmente o dado vem de `canonical_vendors` que inclui o campo.

3. **pedidos.html tab Licitação** — o tab ativa o scope `licitacao`, mas a API de pedidos/NFs precisa suportar esse escopo na camada de dados. Por enquanto exibe as NFs onde o vendedor tem canal `licitacao` (detectado pelo `VendorAliasResolver`).

4. **KPIs summary-wallet e summary-quotes** — os binds `data-bind="summary-wallet"` e `data-bind="summary-quotes"` foram adicionados ao HTML. O preenchimento depende de `api/commercial_dashboard.php` expor esses campos. Ficam com `-` até o backend estar pronto (sem quebrar dados existentes).

5. **fpOrcamentosValue** — bind adicionado ao fpScopesPanel. O preenchimento depende do builder FlightPlanV2 expor o valor. Até lá exibe `-` (sem regressão).

6. **Cron scripts** — `materialize.sh`, `sync_commercial.sh` e `daily_integrity.sh` têm melhorias no working tree mas são **fora do escopo COM.R2**. Devem ser commitados separadamente (ex: "ops: hardening cron — lock coordination e backfill mode").

7. **nf_periodo.html** — a página não existe como arquivo separado. A semântica de NF por período está em `pedidos.html`. O filtro de tipo e a legenda semântica cobrem o escopo equivalente.

---

## Próxima fase recomendada

**COM.R3 — Orçamentos como oportunidade + Kanban semântico:**
- Card "Orçamentos em aberto" no dashboard executivo
- Visão Kanban com escopos: Orçamento → Carteira → Para faturar → Faturado
- Drill-down por canal (Varejo vs Revenda vs Representante)
- API de pipeline com breakdown por escopo
