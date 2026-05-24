# UX.R1 — Auditoria Visual e Usabilidade Executiva

> Versão: 1.0 | Auditado em: 2026-05-23
> Release base: Q.12 (Guard master 6/6 PASS)
> Escopo: diagnóstico apenas — nenhuma regra de negócio alterada

---

## Resumo Executivo

O BIomie Comercial tem uma base visual sólida: tokens de design bem definidos, layout minimalista coerente com o tema MAG, componentes reutilizáveis e boa cobertura de estados de loading/empty. Os problemas encontrados são principalmente de **inconsistência estrutural acumulada ao longo das fases Q** — cada fase adicionou variações de componentes sem consolidar os anteriores.

**Principais vetores de inconsistência:**
1. **3 padrões de cabeçalho de página** coexistindo sem critério claro
2. **5 variações de KPI cards** (nenhuma é a mesma entre todas as telas)
3. **Inline styles** presentes em quase todas as telas — muitos trivialmente movíveis para CSS
4. **Layout shell** — algumas telas têm sidebar completo, outras não têm nenhum
5. **Botões de filtro** com 4 classes diferentes para a mesma função visual

O impacto real é baixo em telas desktop de alta resolução, mas significativo em **mobile** e para o **usuário executivo** que transita entre telas e percebe a falta de coerência.

**Potencial de ganho:** 80% das melhorias são CSS/HTML puros, sem tocar em lógica de negócio.

---

## Pontos Críticos (P0 — Corrigir antes de qualquer release UX)

| # | Achado | Tela | Severidade | Esforço |
|---|--------|------|-----------|---------|
| C1 | `financeSearchSummary` tem `display:none` e `display:flex` na mesma declaração inline — elemento **nunca exibido** | financeiro | **Alta** | Pequeno |
| C2 | Tabela de conciliação não tem loading skeleton — exibe texto plano "Carregando..." | conciliacao | Alta | Pequeno |
| C3 | `cockpit_interno.php` sem `<!DOCTYPE html>` e `<html lang>` — documento inválido | cockpit_interno | Alta | Pequeno |
| C4 | `<style>` block inline no `<head>` da conciliação define `.rec-*` classes — não carregado fora do contexto PHP | conciliacao | Alta | Médio |
| C5 | Filter row de mercadorias usa `<td>` dentro de `<thead>` — HTML inválido | mercadorias | Alta | Pequeno |

---

## Ganhos Rápidos (impacto alto, esforço baixo)

| # | Achado | Tela(s) | Impacto |
|---|--------|---------|---------|
| G1 | Textos sem acento: "Visao", "Periodo", "Analises", "Mes" em labels visíveis | todas | Clareza |
| G2 | KPI sub em index.html usa `<small style="font-size:10px;...">` em vez de `.mag-kpi-card__sub` | index | Visual |
| G3 | `remittanceSummaryCard` (100% inline style) — mover para classe `.mag-remittance-alert` | index | Visual |
| G4 | Botão "Limpar" em dashboardExtraFilters sem ícone — inconsistente com outros "Limpar" | index | Clareza |
| G5 | `pend-class-btn active` não remove `active` de outros botões quando selecionado (CSS-only fix) | conciliacao | Clareza |
| G6 | `fp-kpi-card` com `style="border-left: 3px solid var(--page-accent);"` inline — trivialmente movível para `.fp-kpi-card--accent` | plano_voo | Visual |
| G7 | `vendedoresMetaBand` renderizado com innerHTML inline-style: criar classe `.mag-meta-band` | vendedores | Visual |
| G8 | `<section class="alerts-op">` sem estado vazio — fica em branco quando não há alertas | vendedores | Clareza |

---

## Backlog Priorizado

### P0 — Correções críticas de clareza/erro visual
> Bloqueantes de qualidade mínima — corrigir antes de qualquer release UX.R2

- **P0.1** Corrigir `display:none + display:flex` duplicado em `#financeSearchSummary` → financeiro.html:168
- **P0.2** Adicionar skeleton de loading à tabela de conciliação (substituir texto plano) → conciliacao.html:278-285
- **P0.3** Adicionar `<!DOCTYPE html>` e `<html lang="pt-BR">` ao cockpit_interno.php
- **P0.4** Mover `.rec-*` inline CSS para `assets/css/filters.css` ou novo `reconciliation.css`
- **P0.5** Corrigir `<td>` → `<th>` no filter row de mercadorias dentro de `<thead>` → mercadorias.html:210-222

### P1 — Padronização de cards, labels e empty states
> Máximo impacto visual com risco zero de regressão lógica

- **P1.1** Unificar 5 variações de KPI card em 2 variantes canônicas: `mag-kpi-card` (leitura rápida) e `fp-kpi-card` (cockpit operacional)
- **P1.2** Padronizar header de página em 2 padrões: `mag-page-title` (telas de visão geral) e `fp-header-clean` (telas operacionais/tabelas)
- **P1.3** Corrigir textos sem acento em labels visíveis (G1): "Visão", "Período", "Análises", "Mês"
- **P1.4** Criar classe `.mag-meta-band` para `vendedoresMetaBand` — remover inline styles do JS
- **P1.5** Adicionar empty state ao `<section class="alerts-op">` quando não há alertas operacionais
- **P1.6** Tornar `.remittanceSummaryCard` uma classe CSS reutilizável — padrão amarelo de alerta contextual
- **P1.7** Unificar botões de filtro: usar `btn-preset` como base e `nf-quick-filter-btn` como modificador; remover uso duplo de `btn-preset nf-quick-filter-btn`
- **P1.8** Substituir todos `<small style="font-size:10px;...">` dentro de `.mag-kpi-card` pelo uso correto de `.mag-kpi-card__sub`

### P2 — Mobile e responsividade
> Telas principais precisam funcionar em 375-430px

- **P2.1** Dashboard index: dois filter bars empilhados colapsam para 4+ linhas no mobile — agrupar visualmente ou usar bottom sheet
- **P2.2** Financeiro: `class="row g-4"` / `col-xl-4` (Bootstrap) quebra no mobile sem Bootstrap; verificar/substituir por flex/grid nativo
- **P2.3** Conciliação: `fp-grid-layout` com tabela de 13 colunas não tem scroll horizontal explícito — adicionar `overflow-x:auto` ao container
- **P2.4** Mapa: `biomie-side-panel` com 3 cards em coluna fica inacessível no mobile — converter para accordion ou tabs
- **P2.5** Plano de Voo: `fp-grid-layout` (main + charts) sem breakpoint definido para < 768px
- **P2.6** Vendedores: tabela com 9 colunas em mobile — o atributo `data-mobile-card-table` está presente; verificar se o CSS de card-table está aplicado corretamente
- **P2.7** FAB de metas (`fp-mobile-metas-fab`) em plano_voo é o único FAB do sistema — avaliar se padrão deve ser expandido para outras telas

### P3 — Polimento visual fino
> Refinamentos sem impacto funcional

- **P3.1** Inline styles de page header em vendedores.html e plano_voo.html — `style="font-size:1.15rem;font-weight:700..."` — extrair para `.mag-page-header-compact`
- **P3.2** `fp-kpi-card` com border-left accent → `.fp-kpi-card--primary` CSS class
- **P3.3** Cockpit interno: cores hardcoded (`#d1fae5`, `#064e3b`) → substituir por tokens `--success-soft` / `--success-strong`
- **P3.4** Status chips inline em vendedores (NF Orç-PD / Carteira / Canceladas) → extrair para `.mag-status-band-item` 
- **P3.5** `#geoPresetThisMonth/Last3/ThisYear` separados do filter preset principal no mapa — unificar visual com os outros `btn-preset`
- **P3.6** `topbar-meta` em mercadorias tem 7 spans visíveis no topbar — reduzir para 2-3 itens críticos, mover o resto para diagnostics
- **P3.7** `product-breakdown-modal` usa `hidden` boolean; outros usam `d-none` — padronizar

### P4 — Acessibilidade e microinterações
> Qualidade percebida e a11y

- **P4.1** `aria-expanded="false"` em `<summary>` dos `<details>` não atualiza para `true` quando aberto — adicionar JS toggle
- **P4.2** `commercialHealthBadge` sem `aria-label` descriptivo — usuário de screen reader vê apenas "Carregando"
- **P4.3** Focus ring definido via `var(--focus-ring)` no tema mas `btn-preset` / `nf-quick-filter-btn` não herdam — verificar
- **P4.4** `data-mobile-skip` em colunas de tabela sem ARIA correspondente — colunas desaparecem visualmente mas continuam no DOM
- **P4.5** `hourglass_empty` como estado de carregamento em conciliação e pedidos — usar animação (spin) ou skeleton para clareza
- **P4.6** Botão "Pendências reais" em conciliação sem `aria-pressed` para indicar estado ativo

---

## Auditoria por Tela

### T1 — index.html / Painel Comercial

| Critério | Estado | Nota |
|----------|--------|------|
| Clareza dos KPIs | ✔ OK | 6 KPIs claros na strip |
| Labels comerciais | ⚠ Parcial | "Orç-PD" correto mas subtítulos com `<small>` inline sem classe |
| Consistência Base Comercial NF | ✔ OK | "Base Comercial" na tabela de NFs está correto |
| Excesso de cards/blocos | ⚠ Parcial | 3 seções de grid + diagnostics: densa mas estruturada |
| Hierarquia visual | ✔ OK | `anim d1/d2/d3/d4` bem escalonado |
| Espaçamento | ⚠ Parcial | Dois filter bars sem separador visual claro |
| Tabelas | ✔ OK | `data-empty-*` correto, paginação implementada |
| Filtros e chips | ✔ OK | Chips container presente |
| Empty states | ⚠ Parcial | `setTimeout(3000)` fallback — frágil |
| Loading states | ✔ OK | Skeleton grid/chart/table presente |
| Error states | ✔ OK | `#pageError` com `d-none` |
| Responsividade mobile | ⚠ Parcial | Dois filter bars colapsam mal |
| Acessibilidade | ⚠ Parcial | `aria-live` no loading, mas `commercialHealthBadge` sem label |
| Botões e ações | ⚠ Parcial | "Limpar" sem ícone vs outros com ícone |
| Coerência tema MAG | ✔ OK | Segue theme-tokens |

**Achados específicos:**
- `#remittanceSummaryCard` 100% inline — candidato P1
- KPI sub com `<small style="font-size:10px">` em vez de `.mag-kpi-card__sub` — P1.8
- `module-tiny-kpis` usa divs `.mini` não documentados no design system

---

### T2 — pedidos.html / NF do Período

| Critério | Estado | Nota |
|----------|--------|------|
| Clareza dos KPIs | ✔ OK | 8 KPI cards com data-accent |
| Labels comerciais | ✔ OK | Hero chips corretos |
| Consistência Base Comercial NF | ✔ OK | "Base Comercial" na coluna de tabela |
| Excesso de cards | ⚠ Parcial | 8 KPI cards — mais que qualquer outra tela |
| Hierarquia visual | ✔ OK | Tabs de escopo NF bem implementadas |
| Espaçamento | ✔ OK | Section labels claros |
| Tabelas | ✔ OK | 9 colunas, filtros por coluna, sort |
| Filtros | ✔ OK | Quick filters + search bar + column filters |
| Empty states | ✔ OK | `data-empty-*` em todas as tbodies |
| Loading | ✔ OK | Skeleton cols-4 / cols-2 / table |
| Error | ✔ OK | `#pageError.d-none` |
| Responsividade | ⚠ Parcial | Sem sidebar — layout simplificado funciona |
| Acessibilidade | ✔ OK | `role="tab"` nas abas de escopo |
| Botões | ✔ OK | Filtros rápidos com ícones |
| Coerência tema | ⚠ Parcial | Usa `kpi-card` em vez de `mag-kpi-card` |

**Achados específicos:**
- `kpi-footer`/`kpi-pulse` com tags redundantes ("Commercial", "Mix") — valor unclear para usuário
- "Pedidos em carteira" e "Notas canceladas" sem filtros/search — P3

---

### T3 — vendedores.html / Time Comercial

| Critério | Estado | Nota |
|----------|--------|------|
| Clareza dos KPIs | ✔ OK | `status-kpi` com 6 indicadores claros |
| Labels comerciais | ✔ OK | "NF Orç-PD", "Carteira", "Conversão" claros |
| Consistência Base Comercial NF | ✔ OK | Coluna "Merc. Orç-PD" na tabela |
| Excesso de cards | ✔ OK | 6 status-kpis, compactos |
| Hierarquia | ✔ OK | Header → KPIs → Chart → Tabela |
| Espaçamento | ✔ OK | Section labels bem usados |
| Tabelas | ✔ OK | 9 colunas, sort, column filters, pagination |
| Filtros | ✔ OK | Sort chips + busca por coluna |
| Empty states | ✔ OK | `data-empty-*` em vendorsTable |
| Loading | ✔ OK | Skeleton grid + charts + table |
| Error | ✔ OK | `#pageError` |
| Responsividade | ✔ OK | Sem sidebar, layout simples |
| Acessibilidade | ⚠ Parcial | Drawer sem `role="dialog"` |
| Botões | ✔ OK | Drawer com `btn-icon` para fechar |
| Coerência | ⚠ Parcial | Page header 100% inline style |

**Achados específicos:**
- Status chips no header: 100% inline style — P3
- `vendedoresMetaBand` renderizado com innerHTML inline — P1.4
- `alerts-op` sem empty state — G8

---

### T4 — mercadorias.html / Mix Faturado

| Critério | Estado | Nota |
|----------|--------|------|
| Clareza dos KPIs | ✔ OK | 6 KPI cards, mercadoria/faturamento/produtos/desconto/ticket/NFs |
| Labels comerciais | ✔ OK | "Mercadoria Orç-PD" e "Faturamento Orç-PD" consistentes |
| Consistência Base Comercial NF | ✔ OK | "Base comercial da NF" no subtítulo |
| Excesso de cards | ✔ OK | 6 cards, todos relevantes |
| Hierarquia | ✔ OK | Shell completo, topbar, hero, seções |
| Espaçamento | ✔ OK | Section labels, gaps consistentes |
| Tabelas | ⚠ Parcial | Filter row usa `<td>` em `<thead>` — inválido (C5) |
| Filtros | ✔ OK | Sort + column filters + search |
| Empty states | ✔ OK | `data-empty-*` em productsTable |
| Loading | ✔ OK | Skeleton detalhado |
| Error | ✔ OK |  |
| Responsividade | ⚠ Parcial | `topbar-meta` com 7 spans — muito denso no mobile |
| Acessibilidade | ⚠ Parcial | `product-breakdown-modal` sem `role="dialog"` |
| Botões | ✔ OK | Breakdown com close button |
| Coerência | ⚠ Parcial | Única tela com sidebar completo + topbar na estrutura |

**Achados específicos:**
- ÚNICA tela com sidebar — cria inconsistência de navegação grave
- `topbar-meta` com 7 data-binds visíveis — mover para `<details>` como em outras telas
- `product-breakdown-modal` usa `hidden` boolean vs `d-none` class

---

### T5 — mapa_vendas.html / Mapa de Vendas

| Critério | Estado | Nota |
|----------|--------|------|
| Clareza dos KPIs | ✔ OK | 6 KPI cards com ID binding (não data-bind) |
| Labels comerciais | ✔ OK | "Total vendido", "Bairros com venda" claros |
| Consistência Base Comercial NF | N/A | Mapa não exibe Base Comercial NF diretamente |
| Excesso de cards | ✔ OK | Layout mapa + side panel bem distribuído |
| Hierarquia | ✔ OK | Map → side panel → wallboard tables |
| Espaçamento | ✔ OK | Gaps e seções claros |
| Tabelas | ✔ OK | Ranking e drilldown com empty states |
| Filtros | ✔ OK | 6 filtros (data/cidade/bairro/cnae/vendedor), presets separados |
| Empty states | ✔ OK | `data-empty-*` em tbodies |
| Loading | ⚠ Parcial | `geoFeedback.d-none` mas sem skeleton visual no mapa |
| Error | ✔ OK | `geoMapState` com aria-live |
| Responsividade | ✗ Problema | `biomie-side-panel` (3 cards) colapsado ao lado do mapa — ilegível em mobile |
| Acessibilidade | ✔ OK | `aria-label` no mapa e no side panel |
| Botões | ✔ OK | Geo360Btn com `data-biomie-action` |
| Coerência | ⚠ Parcial | `wallboard-card` vs `fp-card` vs `dashboard-panel` — 3 padrões de card |

**Achados específicos:**
- Diagnóstico técnico com 28 campos — útil mas intimidador; já está em `<details>` (OK)
- KPI cards usam `id="geoKpiRevenue"` via ID binding em vez de `data-bind` — P3
- Presets de período duplicados (main bar + `geoQuickPresets`)

---

### T6 — financeiro.html / Carteira Financeira

| Critério | Estado | Nota |
|----------|--------|------|
| Clareza dos KPIs | ✔ OK | 6 KPI cards relevantes |
| Labels comerciais | N/A | Financeiro é admin — sem escopo comercial |
| Consistência Base Comercial NF | N/A | Financeiro não exibe Base Comercial NF |
| Excesso de cards | ⚠ Parcial | 5+ seções de análise depois dos KPIs — muito conteúdo |
| Hierarquia | ✔ OK | KPIs → Análises → Busca → Detalhamento claro |
| Espaçamento | ⚠ Parcial | `row g-4 mb-4` Bootstrap vs mag system |
| Tabelas | ✔ OK | Busca server-side, aging filter, quick filters |
| Filtros | ✔ OK | Quick filters, column filters, chips |
| Empty states | ✔ OK | `data-empty-*` nas tabelas principais |
| Loading | ✔ OK | Skeleton loading detalhado com label |
| Error | ✔ OK | |
| Responsividade | ✗ Problema | `col-xl-4` Bootstrap quebra sem Bootstrap CSS |
| Acessibilidade | ✔ OK | `fin-bulk-bar` aria |
| Botões | ✔ OK | Bulk bar com export CSV |
| Coerência | ✗ Problema | Mix de `row/col-xl-*` Bootstrap legacy com classes mag- |

**Achados específicos:**
- `x_panel` — classe AdminLTE legada — P3
- `financeSearchSummary` tem bug de display duplicado (C1) — bloqueante
- `fin-panel-stack` não documentado no design system
- `mag-secondary-chart` / `mag-secondary-chart-row` — usados ad-hoc

---

### T7 — plano_voo.html / Plano de Voo

| Critério | Estado | Nota |
|----------|--------|------|
| Clareza dos KPIs | ✔ OK | 6 KPI cards no strip |
| Labels comerciais | ✔ OK | "Realizado Oficial", "Projecao", "Carteira" claros |
| Consistência Base Comercial NF | ✔ OK | Base comercial usada internamente via API |
| Excesso de cards | ✔ OK | Strip compacto, 2 KPIs opcionais ocultos |
| Hierarquia | ✔ OK | Header → KPIs → Table+Charts grid |
| Espaçamento | ✔ OK | Seções bem espaçadas |
| Tabelas | ✔ OK | 12 colunas com `data-mobile-skip`, sort |
| Filtros | ✔ OK | Status chips, busca vendedor, presets |
| Empty states | ⚠ Parcial | Tabela inicia com inline skeleton div — diferente do padrão |
| Loading | ⚠ Parcial | `loadingIndicator` no topbar mas sem skeleton de página |
| Error | ✔ OK | |
| Responsividade | ✔ OK | FAB mobile para metas é bom padrão |
| Acessibilidade | ✔ OK | |
| Botões | ✔ OK | biomie-btn--primary/secondary consistentes |
| Coerência | ⚠ Parcial | Page header e main com inline styles de background |

**Achados específicos:**
- Inline skeleton inicial na tabela (diferente de `module-loading-shell`) — P1
- `style="background:var(--surface-muted);min-height:100vh;"` em `.main` e em `<main>` inline — P3
- Mobile FAB é bom padrão a ser replicado em outras telas que têm ação primária

---

### T8 — conciliacao.html / Conciliação

| Critério | Estado | Nota |
|----------|--------|------|
| Clareza dos KPIs | ✔ OK | 8 KPI cards (+ 4 em modo pendências) claros |
| Labels comerciais | ✔ OK | "Base Comercial NF × Título" no cabeçalho |
| Consistência Base Comercial NF | ✔ OK | Coluna e badge corretos |
| Excesso de cards | ✔ OK | Dois strips de KPI, somente um visível por vez |
| Hierarquia | ✔ OK | Header → Filters → KPI strip → Table |
| Espaçamento | ✔ OK | |
| Tabelas | ✔ OK | 13 colunas (conciliação) / 8 (pendências) |
| Filtros | ✔ OK | Motor Q completo: busca global, vendedor, cliente, pedido, NF + quick filters |
| Empty states | ✗ Problema | Sem `data-empty-*` nas tbodies — estado inicial manual |
| Loading | ✗ Problema | Texto plano "Carregando..." sem skeleton (C2) |
| Error | ✔ OK | |
| Responsividade | ✗ Problema | Tabela de 13 colunas sem overflow-x explícito |
| Acessibilidade | ⚠ Parcial | "Pendências reais" sem `aria-pressed` |
| Botões | ✔ OK | Padrão `biomie-btn--primary` |
| Coerência | ✗ Problema | CSS inline no `<head>` para classes rec-* (C4) |

**Achados específicos:**
- `.rec-*` classes inline no `<head>` — mover para `filters.css` ou `reconciliation.css`
- `pendingClassFilter` 100% inline style — P1
- Falta `data-empty-*` nas tbodies de conciliação — P1

---

### T9 — cockpit_interno.php / Cockpit Interno

| Critério | Estado | Nota |
|----------|--------|------|
| Clareza | ✔ OK | KPIs de sistema bem agrupados |
| Labels | ✔ OK | |
| Excesso de blocos | ✔ OK | Admin view, densidade é aceitável |
| Hierarquia | ✔ OK | Seções claras com `ci-section-title` |
| Coerência | ⚠ Parcial | Usa tokens `--color-*` (alias antigo) em vez de `--mag-*` ou `--text-*` |
| HTML válido | ✗ Problema | Sem `<!DOCTYPE>` e `<html lang>` (C3) |
| Cores | ⚠ Parcial | `.ci-audit-banner--ok/fail` usa cores hardcoded |

---

## Componentes Reutilizáveis Sugeridos

Estes componentes aparecem em múltiplas telas com variações desnecessárias. Consolidar.

### 1. `.mag-kpi-strip` + `.mag-kpi-card` (canônico)
Substituir: `kpi-card`, `fp-kpi-card`, `status-kpi`, `rec-kpi-card`
- Variante `.mag-kpi-card--accent-left` para border-left colorido
- Variante `.mag-kpi-card--compact` para strips operacionais
- Variante `.mag-kpi-card--hero` para o destaque no forecast-box

### 2. `.mag-page-header` (dois modos)
- `.mag-page-header--overview`: com h1, descrição e action buttons (índex, financeiro, mapa)
- `.mag-page-header--operational`: compacto com title + badges (plano_voo, conciliacao, vendedores)
Substituir: `mag-page-title`, `fp-header-clean`, `module-hero` (exceto pedidos/mercadorias que usam hero pattern)

### 3. `.mag-filter-bar` (único padrão)
Substituir: `filter-bar mag-report-toolbar mag-clean-toolbar`, `fp-filters-clean`, `biomie-map-toolbar`
Adicionar variante `.mag-filter-bar--compact` para telas operacionais

### 4. `.mag-status-band` (barra de status resumido)
Novo componente para o padrão de "NF Orç-PD / Carteira / Canceladas" em vendedores
Substituir o inline HTML gerado por JS

### 5. `.mag-contextual-alert` (alertas contextuais coloridos)
Substituir: `remittanceSummaryCard` inline, `geoEnrichCard`, alerts de scope
Variantes: `--yellow` (remessa), `--blue` (info), `--red` (risco)

---

## Riscos da Fase UX.R2

| Risco | Mitigação |
|-------|-----------|
| CSS refactor pode quebrar responsividade do mapa Leaflet | Testar `biomie-map.css` isolado antes de mexer em `module-pages.css` |
| Unificação de KPI cards pode alterar comportamento de `data-bind` | Manter atributos `data-bind` intocados; mudar só classe CSS |
| Remoção de `x_panel`/`col-xl-*` Bootstrap pode quebrar financeiro | Auditar quais estilos dependem de Bootstrap antes de remover |
| Mover `.rec-*` para arquivo externo pode quebrar load order | Garantir que novo arquivo CSS é carregado ANTES do script de reconciliação |
| Normalização de acentos pode quebrar buscas por texto exato em PHP/JS | Verificar se algum PHP ou JS usa o texto como seletor antes de alterar |

---

## Critérios de Aceite para UX.R2

### Obrigatórios
- [ ] Guard master permanece 6/6 PASS após qualquer mudança
- [ ] Nenhuma regra de negócio alterada (Base Comercial NF, BiVisibilityScope, operation_scope)
- [ ] Todos os P0 resolvidos
- [ ] Pelo menos 5 P1 resolvidos
- [ ] Financeiro funciona sem `col-xl-*` Bootstrap

### Desejáveis
- [ ] `mag-kpi-card` é o único padrão de KPI em ≥ 6 das 9 telas
- [ ] Zero `<style>` blocks inline no `<head>` de qualquer view
- [ ] Conciliação tem skeleton de loading padrão
- [ ] Textos com acento corretos em todos os labels visíveis
- [ ] Mobile: 3 telas principais (index, plano_voo, conciliacao) funcionam sem scroll horizontal involuntário em 390px

### Proibidos em UX.R2
- Alterar lógica de `data-bind` ou atributos funcionais
- Alterar queries SQL, PayloadBuilders ou regras de filtro
- Alterar `BiVisibilityScope`, `FiscalValueBasis`, `operation_scope`
- Alterar `assets/js/core/` (motor de filtros Q)
- Remover `data-empty-*` de qualquer tabela

---

## Inventário de Inline Styles por Tela

(Contagem aproximada de blocos `style=` para priorização)

| Tela | Inline styles | Candidatos a extrair |
|------|--------------|---------------------|
| financeiro.html | ~45 | Alta — inclui seções inteiras |
| index.html | ~30 | Alta — remittance card, filter chips |
| vendedores.html | ~20 | Média — header, status chips, meta band |
| plano_voo.html | ~18 | Média — header, main background |
| conciliacao.html | ~15 | Alta — pendingClassFilter, rec-kpi-strip |
| mapa_vendas.html | ~10 | Baixa — detalhes menores |
| mercadorias.html | ~8 | Baixa — filter sort bar |
| pedidos.html | ~6 | Baixa |
| cockpit_interno.php | ~4 | Baixa |
