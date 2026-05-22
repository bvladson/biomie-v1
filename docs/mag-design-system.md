# BIomie — Design System MAG

## Visão geral

O Design System MAG é a camada visual centralizada do BIomie. Ele garante consistência entre Painel Comercial, Time Comercial, Geografia, Mix Faturado, Financeiro, Rota Comercial, Saúde do Sistema e todas as demais páginas.

**Princípios:**
- Fundo claro, cards brancos, bordas suaves, sombras leves
- Vermelho MAG (`#b11812`) apenas como acento/ação crítica — nunca como cor de fundo
- Sidebar idêntico em todas as páginas
- Troca de página não pode parecer troca de sistema

---

## Arquivos principais

| Arquivo | Responsabilidade |
|---|---|
| `assets/css/theme-tokens.css` | Tokens base: cores, radius, sombras, tipografia, sidebar |
| `assets/css/mag-design-system.css` | Componentes globais: cards, empty states, badges, drawers, sidebar compact, tabelas, botões |
| `assets/css/module-pages.css` | Layout de páginas: grids, filtros, toolbars, responsividade |
| `assets/css/biomie-map.css` | Especialização do mapa (herda tokens globais) |
| `assets/css/mag-clean.css` | Overrides de limpeza para páginas legado |
| `assets/css/mag-adminlte-clean.css` | Overrides para componentes legado AdminLTE |

**Ordem de carregamento (injetada por PageRenderer):**
1. `theme-tokens.css`
2. `mag-design-system.css`
3. `module-pages.css` + `mag-clean.css` + `mag-adminlte-clean.css` (via PageAssetRegistry)
4. CSS específico da página (ex: `biomie-map.css` só em `mapa_vendas.php`)

---

## Tokens

### Tokens de cor

```css
/* Marca */
--mag-color-brand:        #b11812;   /* vermelho MAG — apenas acento/crítico */
--mag-color-brand-dark:   #8f120e;

/* Paleta semântica */
--mag-color-success:  #16a34a;
--mag-color-warning:  #f59e0b;
--mag-color-danger:   #dc2626;
--mag-color-info:     #0ea5e9;

/* Aliases para tokens base (definidos em theme-tokens.css) */
--mag-color-bg:           var(--mag-bg);        /* #f6f7fb */
--mag-color-surface:      var(--mag-surface);   /* #ffffff */
--mag-color-border:       var(--mag-border);    /* #e5e7eb */
--mag-color-text:         var(--mag-text);      /* #1f2937 */
--mag-color-muted:        var(--mag-muted);     /* #667085 */
```

### Tokens de espaçamento

```css
--mag-space-1: 4px;
--mag-space-2: 8px;
--mag-space-3: 12px;
--mag-space-4: 16px;
--mag-space-5: 20px;
--mag-space-6: 24px;
```

### Tokens de tipografia

```css
--mag-font-xs: 11px;
--mag-font-sm: 12px;
--mag-font-md: 14px;
--mag-font-lg: 18px;
--mag-font-xl: 24px;
```

### Tokens de radius e sombra

```css
--mag-radius:        16px;   /* padrão de card */
--mag-radius-sm:     10px;
--mag-radius-lg:     22px;
--mag-radius-pill:   999px;
--mag-shadow-soft:   0 10px 30px rgba(15, 23, 42, .06);
--mag-shadow-card:   0 16px 38px rgba(15, 23, 42, .08);
--mag-shadow-drawer: 0 24px 80px rgba(15, 23, 42, .18);
```

---

## Sidebar

O sidebar é um único partial: `views/partials/sidebar.php`.  
Incluído por `views/layouts/app.php` em todas as páginas autenticadas.

### Grupos e itens

| Grupo | Item | page_key | href |
|---|---|---|---|
| Comercial | Painel Comercial | dashboard | index.php |
| Comercial | Time Comercial | time-comercial | vendedores.php |
| Comercial | Geografia | geografia | mapa_vendas.php |
| Operacao | NF do Período | nf-periodo | pedidos.php |
| Operacao | Mix Faturado | mix-faturado | mercadorias.php |
| Operacao | Financeiro | financeiro | financeiro.php |
| Operacao | Rota Comercial | rota-comercial | plano_voo.php |
| Inteligencia | Indicadores | indicadores | index.php#cards |
| Inteligencia | Análises | analises | index.php#graficos |
| Inteligencia | Mesa | mesa | index.php#tabelas |
| Sistema | Conexão Omie | omie | integracoes.php |
| Sistema | Administração | admin | admin.php |
| Sistema | Conciliação | conciliacao | reconciliacao.php |
| Sistema | Observabilidade | observabilidade | observabilidade.php |
| Sistema | Cockpit Interno MAG | cockpit-interno | cockpit_interno.php |
| Sistema | Catálogo de KPIs | catalogo-kpis | metric_catalog.php |
| Sistema | Saúde do Sistema | system-health | system_health.php |

### Classes CSS do sidebar

```
.mag-sidebar           — container lateral
.sidebar-logo          — área do logo
.sidebar-profile       — área do usuário
.mag-sidebar-nav       — nav principal
.mag-nav-group         — seção (Comercial, Operação, etc.)
.mag-nav-group--system — estilo especial para grupo Sistema
.nav-section-label     — label da seção (botão colapsável)
.mag-nav-group__chevron— ícone de colapso
.mag-nav-group__items  — itens da seção
.nav-item              — link de menu
.nav-item.active       — item ativo
.menu-icon             — ícone material do item
.nav-label             — texto do item
.mag-sidebar-version   — versão no rodapé
```

### Como marcar a página ativa

O PHP resolve automaticamente via `$currentPageKey` / `$activePage`. Cada página define `$pageKey` antes do render.

---

## Cards

### `.mag-kpi-card` — Card KPI padrão (já existente)

```html
<div class="mag-kpi-card">
  <div class="mag-kpi-card__head">
    <span class="mag-kpi-card__title">Realizado</span>
    <span class="mag-kpi-card__icon material-symbols-rounded">payments</span>
  </div>
  <div class="mag-kpi-card__value">R$ 128.400</div>
  <div class="mag-kpi-card__subtitle">vs. meta do período</div>
  <div class="mag-kpi-card__meta">Fonte: canonical NFs autorizadas</div>
</div>
```

### `.mag-card--kpi` — Variante compacta (Fase 7)

```html
<div class="mag-card mag-card--kpi">
  <div class="mag-card-icon-row">
    <span class="mag-kpi-card__title">Clientes</span>
    <span class="mag-kpi-card__icon material-symbols-rounded">group</span>
  </div>
  <div class="mag-card-value">347</div>
  <div class="mag-card-meta">No período selecionado</div>
</div>
```

### `.mag-card--compact`

```html
<div class="mag-card mag-card--kpi mag-card--compact">
  ...
</div>
```

---

## Empty States

### `.mag-empty` — Empty state padronizado

```html
<div class="mag-empty">
  <div class="mag-empty-icon">
    <span class="material-symbols-rounded">inbox</span>
  </div>
  <strong class="mag-empty-title">Sem clientes no período</strong>
  <p class="mag-empty-text">
    Nenhuma NF autorizada foi encontrada entre 01/05 e 31/05.
    Verifique se o sync comercial rodou após esse período.
  </p>
  <button type="button" class="mag-empty-action">Verificar Sync</button>
  <details class="mag-empty-debug">
    <summary>Detalhes técnicos</summary>
    <pre>{ "source": "canonical", "count": 0 }</pre>
  </details>
</div>
```

### Tipos padronizados e mensagens recomendadas

| Tipo | Ícone | Título | Texto |
|---|---|---|---|
| `no_data` | inbox | Sem dados | Nenhum registro encontrado para o período. |
| `no_period_data` | date_range | Sem dados no período | Nenhuma NF autorizada entre {start} e {end}. |
| `not_materialized` | hourglass_empty | Mart não materializado | Os dados existem no canônico, mas a camada Gold ainda não foi atualizada. |
| `sync_stale` | sync_problem | Dados Omie desatualizados | Última sincronização comercial há mais de 12 horas. |
| `canonical_empty` | inventory | Canônico sem NFs | Sync completo mas sem NFs autorizadas no período. |
| `gold_empty` | layers | Gold sem dados | Mart materializado mas vazio para esse filtro. |
| `missing_neighborhood` | location_off | Sem bairro informado | Clientes sem bairro cadastrado no Omie. Atualize o cadastro. |
| `no_permission` | lock | Sem permissão | Você não tem acesso a este recurso. |
| `query_error` | error | Erro na consulta | Falha ao buscar dados. Tente novamente. |

### Helper JS

```js
// Gera HTML de empty state
var html = BIomie.emptyStateHtml({
  icon:    'inbox',
  title:   'Sem clientes no período',
  text:    'Nenhuma NF autorizada foi encontrada.',
  action:  'Verificar Sync',    // opcional
  debug:   { source: 'canonical', count: 0 },  // opcional
  compact: false  // opcional — usa .mag-empty--compact
});
el.innerHTML = html;
```

---

## Badges

### Source badges

| Classe | Significado | Cor |
|---|---|---|
| `.mag-badge--source-canonical` | Leitura direta do canônico | Azul |
| `.mag-badge--source-gold` | Mart Gold/materializado | Âmbar |
| `.mag-badge--source-fallback` | Fallback legado ou NF fallback | Roxo |
| `.mag-badge--source-cache` | Resposta cacheada | Ciano |
| `.mag-badge--source-empty` | Sem dados | Cinza |

### Freshness badges

| Classe | Significado | Cor |
|---|---|---|
| `.mag-badge--fresh-ok` | Dados atualizados | Verde |
| `.mag-badge--fresh-warning` | Atenção — pode estar desatualizado | Âmbar |
| `.mag-badge--fresh-critical` | Crítico — sync ausente ou erro | Vermelho |
| `.mag-badge--fresh-unknown` | Status desconhecido | Cinza |

### Exemplos HTML

```html
<span class="mag-badge mag-badge--source-canonical">Canonical</span>
<span class="mag-badge mag-badge--fresh-ok">Atualizado</span>
<span class="mag-badge mag-badge--fresh-warning mag-badge--sm">Atenção</span>
```

### Helpers JS

```js
// Retorna HTML do badge de fonte
BIomie.sourceBadge('canonical');        // → <span class="mag-badge mag-badge--source-canonical">Canonical</span>
BIomie.sourceBadge('gold');             // → Gold
BIomie.sourceBadge('fallback');         // → Fallback

// Retorna HTML do badge de freshness
BIomie.freshnessBadge('ok');            // → Atualizado
BIomie.freshnessBadge('stale');         // → Atenção
BIomie.freshnessBadge('error');         // → Crítico

// Retorna badge da base de período
BIomie.periodBasisBadge('data_competencia');  // → data_competencia
BIomie.periodBasisBadge('data_emissao');      // → data_emissão
BIomie.periodBasisBadge('year_month');         // → ano/mês
```

---

## Drawers

### Estrutura HTML padrão

```html
<!-- Backdrop -->
<div class="mag-drawer-backdrop" id="my-drawer-backdrop" aria-hidden="true"></div>

<!-- Drawer -->
<aside
  class="mag-drawer mag-drawer--right"
  id="my-drawer"
  role="dialog"
  aria-modal="true"
  aria-labelledby="my-drawer-title"
  tabindex="-1"
>
  <div class="mag-drawer-header">
    <div>
      <h2 class="mag-drawer-title" id="my-drawer-title">Título</h2>
      <p class="mag-drawer-subtitle">Subtítulo ou período</p>
    </div>
    <button class="mag-drawer-close" aria-label="Fechar" data-drawer-close>
      <span class="material-symbols-rounded">close</span>
    </button>
  </div>

  <div class="mag-drawer-tabs" role="tablist">
    <button class="mag-drawer-tab is-active" role="tab">Resumo</button>
    <button class="mag-drawer-tab" role="tab">NFs</button>
    <button class="mag-drawer-tab" role="tab">Clientes</button>
  </div>

  <div class="mag-drawer-body">
    <div class="mag-drawer-section">
      <!-- conteúdo -->
    </div>
  </div>

  <div class="mag-drawer-footer">
    <button class="btn-secondary">Cancelar</button>
    <button class="btn-primary">Confirmar</button>
  </div>
</aside>
```

### Variantes

| Classe | Largura |
|---|---|
| `.mag-drawer` | 540px padrão |
| `.mag-drawer--wide` | 720px |
| `.mag-drawer--right` | Ancora na direita (padrão) |

### Regras de acessibilidade

- `role="dialog"` + `aria-modal="true"` no container
- `aria-labelledby` aponta para o título
- ESC deve fechar o drawer
- Foco deve ir para o drawer ao abrir (`drawer.focus()`)
- Foco deve retornar ao elemento que abriu ao fechar
- Tab não pode sair do drawer sem fechar
- Scroll interno no `.mag-drawer-body`
- Não abrir drawer dentro de drawer sem controle de z-index

---

## Grids de página

### `.mag-kpi-grid` / `.mag-page-kpis`

3 colunas em desktop, 2 em tablet, 1 em mobile.

```html
<div class="mag-kpi-grid">
  <div class="mag-kpi-card">...</div>
  <div class="mag-kpi-card">...</div>
  <div class="mag-kpi-card">...</div>
</div>
```

### `.dashboard-kpi-strip`

6 colunas compactas para o dashboard principal.

---

## Quando usar cada classe

| Situação | Use |
|---|---|
| Card de KPI alto nível | `.mag-kpi-card` |
| Card de KPI menor/grid compacto | `.mag-card.mag-card--kpi` |
| Card compacto com pouco espaço | `.mag-card--compact` |
| Card de tabela | `.chart-card` + `.table-card` |
| Drawer de detalhe | `.mag-drawer` |
| Drawer largo (produto/NF) | `.mag-drawer.mag-drawer--wide` |
| Empty state padrão | `.mag-empty` |
| Empty state em espaço pequeno | `.mag-empty.mag-empty--compact` |
| Badge de fonte | `.mag-badge.mag-badge--source-*` |
| Badge de freshness | `.mag-badge.mag-badge--fresh-*` |
| Badge geral (status) | `.mag-status-chip.mag-status-live/warning` |

---

## Páginas migradas para o stack novo

Todas as páginas autenticadas já usam:
- `views/layouts/app.php` (inclui sidebar.php + topbar.php)
- `PageRenderer::renderDocument()` (injeta theme-tokens + mag-design-system)
- `PageAssetRegistry` (carrega module-pages.css + mag-clean.css)
- `body.mag-page.mag-clean-page.mag-app-shell-page`

### Sidebar
O sidebar é compartilhado por todas as páginas via `views/layouts/app.php`.  
Configuração centralizada em `views/config/menu.php`.

---

## Regras absolutas do design

1. Nunca usar `background: #b11812` em cards ou páginas — vermelho MAG é só para ações críticas
2. Nunca criar sidebar próprio por página — usar sempre `views/partials/sidebar.php`
3. Nunca criar CSS de card/empty/badge duplicado — usar as classes do design system
4. Empty states nunca ficam brancos/soltos — usar `.mag-empty` com ícone + título + texto
5. Badges de fonte sempre acompanham dados — não mostrar dado sem origem
6. Drawers sempre com ESC + foco correto — sem exceção
