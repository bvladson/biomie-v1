# Phase 10G — Index BI Redesign

> Documento de referência para a reestruturação do index.php de relatório pesado para cockpit executivo.

## Princípios

1. **Gráficos no centro** — KPIs são apoio, gráficos são protagonistas
2. **Leitura at-a-glance** — Primeira dobra entrega insights sem scroll
3. **Drilldown in-page** — Detail drawer 70-82vw, sem navegar
4. **Mapa fora do index** — Geo compacto no index, mapa completo em `mapa_vendas.php`
5. **Acessibilidade** — Drawer com `role="dialog"`, focus trap, Escape fecha

## Layout (12-column grid)

```
┌────────────────────── KPI Strip (6 colunas compactas) ──────────────────────┐
│  Vendas  │  Pedidos  │  Ticket  │  Clientes  │  Em aberto  │    Sync       │
└─────────────────────────────────────────────────────────────────────────────┘

┌── Filtros ──────────────────────────────────────────────────────────────────┐
│  [Data inicio]  [Data fim]  [Mes] [Ano] [12m]  [Aplicar]  [Atualizar]      │
└─────────────────────────────────────────────────────────────────────────────┘

┌── Row 1 ────────────────────────────────────────────────────────────────────┐
│  Evolução da Receita (7 cols, 320px)  │  Ranking Comercial (5 cols, 320px) │
└────────────────────────────────────────────────────────────────────────────┘

┌── Row 2 ────────────────────────────────────────────────────────────────────┐
│  Top Produtos (4, 260px)  │  Top Clientes (4, 260px)  │  Pipeline (4, 260)  │
└─────────────────────────────────────────────────────────────────────────────┘

┌── Row 3 ────────────────────────────────────────────────────────────────────┐
│  Maiores NFs — tabela acionável (8 cols)  │  Geo Compacto (4 cols)          │
└─────────────────────────────────────────────────────────────────────────────┘

┌── Below the fold ───────────────────────────────────────────────────────────┐
│  Recebimentos  │  Alertas  │  Comparativo mensal                            │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Arquivos Modificados/Criados

| Arquivo | Ação | Propósito |
|---------|------|-----------|
| `assets/css/theme-tokens.css` | MODIFY | Tokens dashboard (gap, card-h, chart-h, drawer-w) |
| `assets/css/shell-index.css` | MODIFY | Grid 12-col, panels, chart shells, drawer CSS |
| `assets/css/mag-design-system.css` | MODIFY | KPI strip compact (88px, 24px font) |
| `views/index.html` | REWRITE | Nova estrutura com dashboard-grid |
| `assets/js/app.js` | MODIFY | vendorsBarChart direto, horizontal bars, NF table |
| `assets/js/core/chart-factory.js` | MODIFY | createHorizontalBarChart |
| `assets/js/modules/overview/overview-detail-drawer.js` | NEW | Drawer 70-82vw com a11y |
| `api/overview_detail.php` | NEW | API thin → OverviewDetailBuilder |
| `app/.../OverviewDetailBuilder.php` | NEW | Handler per chart_id |

## Chart Registry

| Canvas ID | Tipo | Altura | Localização |
|-----------|------|--------|-------------|
| `salesTimelineChart` | line+bar | 320px | Row 1, span-7 |
| `vendorsBarChart` | bar+line | 320px | Row 1, span-5 |
| `topProductsChart` | horizontal bar | 260px | Row 2, span-4 |
| `topClientsChart` | horizontal bar | 260px | Row 2, span-4 |
| `dailyMixedChart` | stacked bar+line | 260px | Row 2, span-4 |
| `monthComparisonChart` | bar+line | 220px | Below fold, span-4 |

## Drilldown Flow

```
[Click em gráfico/botão Detalhar]
  → BIomieOverviewDetail.open({ chartId, filters })
  → GET api/overview_detail.php?chart_id=X&start=Y&end=Z
  → OverviewDetailBuilder delegates to handler
  → Drawer renders table with auto-detected columns
  → Escape / backdrop click / close button → close()
  → Focus returns to trigger element
```

## Cache

- Overview Detail Drawer: in-memory JS cache, 60s TTL
- API: reuses existing `Cache::adaptiveTtl()` quando necessário
- CSS/JS: filemtime versioning via `PageRenderer::withMagDesignSystem()`
