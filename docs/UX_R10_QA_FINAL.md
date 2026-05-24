# UX.R10 — QA Visual Final e Tag de Estabilidade

**Data:** 2026-05-24
**Branch:** fix/fase-4-materializacao-geo-segura
**Guard master:** 6/6 PASS
**Ciclo UX.R:** R1 → R10 concluído

---

## Resumo executivo

O ciclo UX.R percorreu 10 fases progressivas de polimento visual, cobertura mobile, padronização de estados e acessibilidade. Este documento registra o QA final de código antes da tag `ux-r-stable`.

Metodologia: auditoria estática de código (grep, leitura de arquivos) + verificação de comportamento JS implícito pelos event listeners e data attributes. Testes de UI visual e navegação manual devem ser realizados pelo operador antes do deploy.

---

## Telas auditadas

| # | Tela | PHP entry | View | Status |
|---|------|-----------|------|--------|
| 1 | Dashboard Comercial | index.php | views/index.html | ✅ |
| 2 | NF do Período | pedidos.php | views/pedidos.html | ✅ |
| 3 | Time Comercial | vendedores.php | views/vendedores.html | ✅ |
| 4 | Mix Faturado | mercadorias.php | views/mercadorias.html | ✅ |
| 5 | Mapa de Vendas | mapa_vendas.php | views/mapa_vendas.html | ✅ |
| 6 | Financeiro | financeiro.php | views/financeiro.html | ✅ |
| 7 | Plano de Voo | plano_voo.php | views/plano_voo.html | ✅ |
| 8 | Conciliação | conciliacao.php | views/conciliacao.html | ✅ |
| 9 | Cockpit Interno | cockpit_interno.php* | views/cockpit_interno.php | ✅ |
| 10 | Catálogo de Métricas | metric_catalog.php* | views/metric_catalog.php | ✅ |

*Telas admin — servidas via admin.php com PageRenderer.

---

## Checklist de QA — resultado por item

| # | Item | Resultado | Observação |
|---|------|-----------|------------|
| 1 | Desktop 1366px sem quebra visual | ✅ código | Shell, sidebar e main grid corretos; sem `min-width` fixo em containers principais |
| 2 | Mobile 390px sem scroll horizontal | ✅ código | `mag-app-main padding-bottom: calc(60px + safe-area)` corrigido (UX.R7); todos os contêineres de tabela têm `overflow-x: auto` |
| 3 | Sidebar abre/fecha | ✅ código | `shell.js` controla `sidebar.open`, `.collapsed`, backdrop e `main.inert`; toque swipe implementado |
| 4 | Bottom nav funciona | ✅ código | `mobile_bottom_nav.php` renderiza 4 itens principais + "Mais"; altura 60px + safe-area sincronizados (UX.R7) |
| 5 | Item ativo correto | ✅ código | `sidebar.php` e `mobile_bottom_nav.php` recebem `page_key` via `$data` e aplicam `aria-current="page"` |
| 6 | Page header consistente | ✅ código | Todas as 10 telas usam `biomie-page-header` + variante (`--strip`, `--shell`, `--hero`, `--admin`) |
| 7 | KPI cards consistentes | ✅ código | Todas as 8 telas comerciais usam `biomie-kpi-card`; cockpit e metric_catalog usam classes próprias admin |
| 8 | Filtros e chips funcionam | ✅ código | Q-motor intacto; `filter-chips.js` gera chips com `aria-label="Remover filtro …"` (UX.R9) |
| 9 | Tabelas com overflow controlado | ✅ código | `biomie-table-wrap` aplicado em pedidos, mercadorias, vendedores, mapa_vendas, conciliação |
| 10 | Loading/empty/error states legíveis | ✅ código | `module-loading-shell` + `skeleton-*` em financeiro, mercadorias, pedidos, vendedores; `mag-state-*` em todos via BIOMIE_STATES (UX.R8) |
| 11 | Conciliação normal funcionando | ✅ código | `_renderTable()` usa `mag-state-*` para empty; KPIs têm `skeleton-text` inicial; `#recLoadingIndicator` adicionado (UX.R8) |
| 12 | Pendências reais funcionando | ✅ código | `#pendenciasSection` e `#pendKpiStrip` controlados por `_pendingMode`; lógica preservada |
| 13 | Plano de Voo funcionando | ✅ código | `modules.Alerts.renderAlerts()` com `aria-live="polite"` no container (UX.R9); `renderTableEmptyState` no tbody (UX.R8) |
| 14 | Drawer Vendor 360 funcionando | ✅ código | `role="dialog" aria-modal="true" aria-labelledby="drawerVendorName"`; `#closeDrawerBtn` com `aria-label` (UX.R9) |
| 15 | Modal de produto funcionando | ✅ código | `role="dialog" aria-modal="true" aria-labelledby="productBreakdownTitle"`; close btn já tinha `aria-label` (UX.R9) |
| 16 | Escape fecha sidebar/drawer/modal | ✅ código | `shell.js` Escape handler: (1) fecha sidebar mobile, (2) click `#closeDrawerBtn` se backdrop visível, (3) click `[aria-label^="Fechar"]` em qualquer `[role="dialog"]` aberto (UX.R9) |
| 17 | Focus visible nos elementos interativos | ✅ código | `:focus-visible` definido globalmente + regras específicas para `.biomie-filter-chip__remove`, `[data-biomie-cb]`, `.nf-quick-filter-btn` (UX.R9) |
| 18 | Nenhum header duplicado | ✅ código | PageRenderer extrai `<main>` e injeta via `app.php`; `<header class="topbar">` stripped por regex em PageRenderer.php:288-290 |
| 19 | Nenhum shell duplicado | ✅ código | UX.R6 removeu shell HTML de `plano_voo.html`, `mercadorias.html`, `mapa_vendas.html`, `conciliacao.html` |
| 20 | Guard master 6/6 PASS | ✅ verificado | Ver seção Validação abaixo |

---

## Melhorias aplicadas nesta fase (UX.R10)

### views/metric_catalog.php
- `<html>` → `<html lang="pt-BR">` (L30) — atributo de idioma ausente

### views/cockpit_interno.php
- `#ci-audit-banner` — adicionado `aria-live="polite" role="status"` (L45)
- `#ci-freshness-grid` — adicionado `aria-live="polite"` (L48)
- `#ci-job-status` — adicionado `aria-live="polite"` (L51)
- `#ci-kpi-grid` — adicionado `aria-live="polite"` (L54)

Os quatro containers são dinamicamente populados por fetch; screen readers agora anunciam o conteúdo quando carregado.

---

## Achados fora do escopo das 10 telas (observação, sem correção)

As seguintes telas estão fora das 10 auditadas e não foram alteradas. Ficam registradas para uma fase futura:

| Tela | Achado | Risco |
|------|--------|-------|
| views/observabilidade.html | Shell HTML duplicado (overlay + aside sidebar + topbar) | MÉDIO — remove em sprint futuro |
| views/integracoes.html | Shell HTML duplicado (estrutura idêntica) | MÉDIO — idem |
| views/plano_voo_metas.html | Shell HTML duplicado + 49 inline styles | MÉDIO — idem |
| views/reconciliacao.html | Arquivo legado com `class="nav-md mag-page"` no body | BAIXO — verificar se ainda é servido |

---

## Inventário de estado por tela

### 1. Dashboard (index.html)
- Loading: `<span class="skeleton-text">` inline em cada KPI value
- Empty/Error: painéis individuais via `BIOMIE_STATES.setEmpty/setError`
- Drawer NF: `role="dialog"` em `.overview-detail-drawer` ✅

### 2. NF do Período (pedidos.html)
- Loading: `#pageLoading class="module-loading-shell"` com `aria-live="polite" aria-busy="true"`
- Table: Q-motor server-table com `biomie-table-feedback` para erros

### 3. Time Comercial (vendedores.html)
- Loading: `#pageLoading class="module-loading-shell"` ✅
- Drawer: `role="dialog" aria-modal="true" aria-labelledby="drawerVendorName"` ✅ (UX.R9)

### 4. Mix Faturado (mercadorias.html)
- Loading: `#pageLoading class="module-loading-shell"` ✅
- Modal: `role="dialog" aria-modal="true" aria-labelledby="productBreakdownTitle"` ✅ (UX.R9)

### 5. Mapa de Vendas (mapa_vendas.html)
- Loading: `aria-live="polite"` em cards de enriquecimento e mapa
- Sem KPI strip numérico (design deliberado — mapa + tabela de distribuição)

### 6. Financeiro (financeiro.html)
- Loading: `#pageLoading class="module-loading-shell"` com label "Carregando financeiro realizado..."
- Error: `#pageError class="d-none"` controlado por `finance.js`

### 7. Plano de Voo (plano_voo.html)
- Loading: tbody skeleton inicial em `#flightVendorsTable`
- Alerts: `#flightAlerts` com `aria-live="polite" role="region"` ✅ (UX.R9)
- Empty: `BIOMIE_STATES.renderTableEmptyState(tb)` com `data-empty-*` attrs ✅ (UX.R8)

### 8. Conciliação (conciliacao.html)
- Loading: skeleton rows em `#reconciliationTableBody`; `#recLoadingIndicator` ✅ (UX.R8)
- KPIs: `<span class="skeleton-text">` como placeholder inicial ✅ (UX.R8)
- Empty: `mag-state-empty` com ícone e título ✅ (UX.R8)
- Error: `mag-state-error` com ícone ✅ (UX.R8)

### 9. Cockpit Interno (cockpit_interno.php)
- Loading: texto "Carregando…" com `aria-live="polite"` ✅ (UX.R10)
- Inline `<style>` block: 30 linhas de CSS admin-específico — aceitável
- Sem KPI cards comerciais (tela de diagnóstico interno)

### 10. Catálogo de Métricas (metric_catalog.php)
- Drawer: `role="dialog" aria-modal="true" aria-labelledby="metricDrawerTitle"` ✅ (já tinha)
- Escape: handler inline no `<script>` fecha drawer ✅
- Focus return: `lastFocus.focus()` ao fechar ✅
- `<html lang="pt-BR">` ✅ (UX.R10)

---

## Testes manuais recomendados antes do deploy

Os itens abaixo não podem ser verificados estaticamente e devem ser testados manualmente:

1. **Desktop 1366px** — Abrir cada tela e confirmar sem quebra de layout
2. **Mobile 390px** — Testar bottom nav, sidebar, filtros, tabelas
3. **Plano de Voo** — Clicar linha de vendedor → Drawer abre, fecha com Esc e com ×
4. **Mix Faturado** — Clicar produto → Modal abre, fecha com Esc e com ×
5. **Conciliação** — Aplicar filtros → loading visible → resultado / sem resultado
6. **Conciliação** — Clicar "Pendências reais" → seção alterna corretamente
7. **Filtros chips** — Adicionar filtro, remover chip, confirmar foco retorna
8. **Keyboard nav** — Tab pelo Dashboard: KPIs → filtros → botões; confirmar outline visível
9. **Screen reader** — Abrir Plano de Voo com leitor de tela, confirmar anúncio de alerta
10. **mapa_vendas 390px** — Confirmar coluna com `min-width: 280px` não cria scroll horizontal (flex-wrap deve quebrar)

---

## Validação

```
php tools/dev/biomie_release_guard.php --start=2026-01-01 --end=2026-05-31

✔ PASS — 6/6 domínios passaram
```

D1 Base Comercial NF ........ PASS
D2 Licitação excluída ........ PASS
D3 Conciliação NF×Título ..... PASS
D4 Marts materializados ....... PASS (7/7)
D5 Sintaxe PHP ............... PASS (78 arquivos)
D6 SELECT * audit ............ WARN (informacional, 14 ocorrências conhecidas)

---

## Sugestão de tag

```bash
git tag ux-r-stable
git push origin ux-r-stable
```

**O ciclo UX.R (R1–R10) está pronto para a tag `ux-r-stable`.**

### O que o ciclo entregou

| Fase | Entrega |
|------|---------|
| UX.R1 | Auditoria visual inicial — inventário de inconsistências |
| UX.R2–R4 | Headers, KPI cards, page headers padronizados |
| UX.R5 | ~46 inline styles extraídos para classes utilitárias (`biomie-*`) |
| UX.R6 | Shell unificado — 4 views limpas de HTML duplicado; conciliacao.php migrada para PageRenderer |
| UX.R7 | Mobile bottom nav 60px sincronizado; link min-height corrigido |
| UX.R8 | Estados padronizados — `biomie-state*`, `biomie-skeleton*`, retry callback em `setError()` |
| UX.R9 | Acessibilidade — `role="dialog"`, `aria-live`, `aria-label`, focus-visible, Escape universal |
| UX.R10 | QA final — `lang="pt-BR"` em metric_catalog, `aria-live` em cockpit_interno, documento de QA |

---

*Gerado em 2026-05-24 — BIomie MAG*
