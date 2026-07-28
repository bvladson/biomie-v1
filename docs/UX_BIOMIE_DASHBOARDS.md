# UX BIomie — Dashboards

**Fase:** 8 — UX BIomie + Drilldown Comercial/Fiscal
**Status:** Implementado

## Tokens de altura de gráficos

Definidos em `assets/css/theme-tokens.css`:

```css
--biomie-chart-h-sm:     240px;   /* sparklines, mini charts */
--biomie-chart-h-md:     320px;   /* standard chart */
--biomie-chart-h-lg:     380px;   /* featured chart */
--biomie-chart-h-xl:     420px;   /* hero chart */
--biomie-chart-h-drawer: 160px;   /* inline chart in drawers */
```

Use sempre esses tokens ao definir altura de canvas, evitando valores ad-hoc.

## Classes shell de gráfico

Definidas em `assets/css/module-pages.css`:

| Classe | Uso |
|---|---|
| `.chart-shell` | Container base. Aplica `--biomie-chart-h-md` por padrão. |
| `.chart-shell--sm` | Sparklines e mini gráficos (240px) |
| `.chart-shell--lg` | Gráficos com mais dados (380px) |
| `.chart-shell--xl` | Hero charts (420px) |
| `.chart-shell--drawer` | Gráficos em drawers laterais (160px) |
| `.chart-shell--compact` | Modo compacto para grids apertados |

**Exemplo:**
```html
<div class="chart-shell chart-shell--lg">
  <canvas id="meu-grafico"></canvas>
</div>
```

## Grid de dashboard

```html
<!-- Cards de KPI -->
<div class="dashboard-grid dashboard-grid--cards">
  <div class="kpi-card kpi-card--primary">
    <div class="kpi-card__label">Receita</div>
    <div class="kpi-card__value">R$ 1.234.567</div>
    <div class="kpi-card__sub">+12% vs. mês anterior</div>
  </div>
</div>

<!-- Gráficos -->
<div class="dashboard-grid dashboard-grid--charts">
  <div class="chart-shell chart-shell--lg">
    <canvas id="chart-revenue"></canvas>
  </div>
</div>
```

## Drawer lateral

O componente `.biomie-drawer` é um painel lateral fixo (direita), 520px max-width, com transição suave.

**Anatomia:**
```
.biomie-drawer
  .biomie-drawer__header
    .biomie-drawer__title
    .biomie-drawer__subtitle
    .biomie-drawer__close
  .biomie-drawer__tabs
    .biomie-drawer__tab (+ .is-active)
  .biomie-drawer__body
    .biomie-drawer__section
    .biomie-drawer__stats
      .biomie-drawer__stat
        .biomie-drawer__stat-label
        .biomie-drawer__stat-value
    .biomie-drawer__list-item
    .biomie-drawer__badge
    .biomie-drawer__empty
```

**Abrir/fechar via JS:**
```js
// Vendor 360
BIomieVendorDetail.open(vendorId, { start: '2025-01-01', end: '2025-12-31' });

// Pedido
BIomieOrderDetail.open(codigoPedido);

// Nota Fiscal
BIomieInvoiceDetail.open({ numero_nf: '12345' });
BIomieInvoiceDetail.open({ nota_key: 'abc...', chave_nfe: '...' }); // alternativo
```

Todos os drawers fecham com `Escape` ou clique no botão `×`.

## Barra de busca de pedidos

```html
<div class="orders-search-bar">
  <div class="orders-search-bar__input-wrap">
    <span class="material-symbols-outlined orders-search-bar__icon">search</span>
    <input class="orders-search-bar__input" type="search" placeholder="Buscar pedido, cliente, NF..." />
  </div>
  <div class="orders-search-bar__filters">
    <!-- filtros inline: vendedor, status, período, valor -->
  </div>
  <button class="orders-search-bar__btn">Buscar</button>
</div>
```

Endpoint: `api/orders_search.php` — ver `docs/DRILLDOWN_COMERCIAL_FISCAL.md`.

## Drawer: acessibilidade e performance (Fase 8B)

### Acessibilidade obrigatória

- `role="dialog"` + `aria-modal="true"` + `aria-labelledby` no container
- Botão fechar com `aria-label="Fechar painel do X"`
- Foco vai para o botão fechar ao abrir (`closeBtn.focus()` com 80ms delay)
- Tab / Shift+Tab presos no drawer (focus trap via `_focusTrapHandler`)
- Escape fecha o drawer e devolve foco ao `openerEl` (elemento que abriu)
- Tabs têm `role="tab"` e `aria-selected`

### Performance obrigatória

| Padrão | Implementação |
|---|---|
| Skeleton first | Abre drawer imediatamente, mostra esqueleto, depois renderiza |
| AbortController | Cancela request anterior se drawer abrir com novo item |
| Cache 60s | `_cache[key] = { ts, data }` — re-usa se aberto dentro de 1 min |
| Event delegation | `drilldown-router.js` — 1 listener para toda a página |
| Debounce 300ms | Busca na barra de pedidos |

### Segurança no drawer

- Todos os dados dinâmicos passam por `esc(v)` (escapeHtml)
- URLs DANFE/XML verificadas: somente `https://` ou `http://` — `safeUrl()` em `invoice-detail/index.js`
- `target="_blank"` sempre com `rel="noopener noreferrer"`
- `raw_available` só expõe link de debug se retornado pelo servidor

### Estados de drawer

| Estado | Classe / elemento |
|---|---|
| Carregando | `.biomie-drawer__empty` com texto "Carregando..." |
| Skeleton | Divs com `background: var(--skeleton-base)` |
| Vazio | `.biomie-drawer__empty` com mensagem contextual por aba |
| Erro | `.biomie-drawer__empty` com mensagem de erro |
| Conteúdo | Render normal por aba |

## Roteamento de drilldown (Fase 8B)

O arquivo `assets/js/modules/drilldown-router.js` instala **um único listener** no document:

```js
document.addEventListener('click', function (e) {
  var trigger = e.target.closest('[data-biomie-action]');
  // roteia para BIomieVendorDetail / BIomieOrderDetail / BIomieInvoiceDetail
});
```

Qualquer elemento com `data-biomie-action` em qualquer lugar da página (incluindo dentro dos drawers) dispara o drilldown correspondente.

## Princípios visuais

1. **Altura por token** — nunca `height: 300px` fixo; usar `var(--biomie-chart-h-md)`.
2. **Sem cores ad-hoc** — usar `var(--page-accent)`, `var(--success)`, `var(--danger)` etc.
3. **Drawer sobre overlay** — o drawer não bloqueia o fundo com overlay escuro, favorecendo contexto.
4. **Estado vazio amigável** — toda seção vazia usa `.biomie-drawer__empty` com mensagem clara.
5. **Responsive** — drawers colapsam para 100% de largura em mobile (`< 640px`).
6. **Progressive enhancement** — se JS falhar, links de vendedor navegam normalmente.
