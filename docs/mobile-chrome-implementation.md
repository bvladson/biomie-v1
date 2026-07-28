# BIomie — Implementação Mobile Chrome (Fase 1 + 2)

## Arquivos entregues

| Arquivo | Tipo | Propósito |
|---|---|---|
| `assets/css/mag-mobile.css` | CSS | Todos os estilos mobile (zero impacto desktop) |
| `assets/js/mobile-shell.js` | JS | Comportamentos mobile: nav, drawers, filtros, cards |
| `views/partials/mobile_topbar.php` | PHP | Topbar mobile fixa com hambúrguer e título dinâmico |
| `views/partials/mobile_bottom_nav.php` | PHP | Bottom navigation com 5 atalhos |
| `tools/dev/mobile_smoke_check.php` | CLI | 19 checks de integridade do pacote mobile |
| `docs/mobile-chrome-implementation.md` | Doc | Este arquivo |

## APIs expostas

```js
// Verificar se está em mobile
BIomieMobileShell.isMobile()                  // → boolean

// Lazy render de gráficos
BIomie.mobile.whenVisible(element, callback)  // callback chamado ao entrar na viewport

// Inicialização manual (casos especiais)
BIomieMobileShell.initFilterSheets()
BIomieMobileShell.initCardTables()
BIomieMobileShell.applyMobileChartTweaks()
```

## Roteiro de teste manual

### Pré-requisito

Abrir o Chrome DevTools → aba **Device Mode** (ícone de celular, ou `Ctrl+Shift+M`).

---

### 1. Viewports a testar

| Preset | Resolução | O que verificar |
|---|---|---|
| iPhone SE | 375 × 667 | Layout compacto, bottom nav visível |
| iPhone 14 Pro | 393 × 852 | safe-area-inset, viewport-fit=cover |
| Pixel 7 | 412 × 915 | Sidebar overlay, drawers bottom sheet |
| Samsung Galaxy S21 | 360 × 800 | KPIs em 2 colunas (≤400px) |
| iPad Mini | 768 × 1024 | **Desktop layout deve prevalecer — mobile CSS não deve ativar** |

---

### 2. Checklist por funcionalidade

#### Topbar mobile
- [ ] Aparece apenas em mobile (≤767px)
- [ ] Título sincroniza com o `.topbar-badge` ou `h1` da página
- [ ] Hambúrguer aciona a sidebar corretamente (via `#menu_toggle`)
- [ ] Sidebar abre como overlay, sem deslocar conteúdo
- [ ] Toque fora da sidebar fecha-a

#### Bottom navigation
- [ ] Exibida apenas em mobile
- [ ] Item ativo corresponde à página atual (via `body[data-page]` ou pathname)
- [ ] `aria-current="page"` no link ativo
- [ ] Toque em item navega corretamente
- [ ] Padding inferior correto em iPhone (safe-area-inset-bottom)

#### Tabelas → cards (`data-mobile-card-table`)
- [ ] Vendedores: tabela vira cards com rótulo em cada célula
- [ ] Mix Faturado: tabela de produtos vira cards
- [ ] NF Período: tabela de notas vira cards (emitidas e canceladas)
- [ ] Drawer de cliente: tabela interna vira cards
- [ ] Drawer de pedido: tabela interna vira cards
- [ ] Em desktop (768px+): tabelas exibidas normalmente

#### Filtros → bottom sheet
- [ ] Em mobile: botão "Filtros" aparece substituindo a filter bar
- [ ] Toque no botão abre o painel de baixo para cima com animação
- [ ] Handle visual (barra cinza) no topo do painel
- [ ] Botão ✕ fecha o painel
- [ ] Toque no backdrop fecha o painel
- [ ] Tecla ESC fecha o painel
- [ ] Foco vai para o primeiro input ao abrir
- [ ] Foco retorna ao botão Filtros ao fechar
- [ ] Em desktop: filter bar visível normalmente (sem botão mobile)
- [ ] Testar em: Dashboard, Vendedores, Mix, Mapa, Plano de Voo, NF Período, Financeiro

#### Drawers (bottom sheet mobile)
- [ ] Vendor 360: abre como bottom sheet 95vh
- [ ] Geo 360: abre como bottom sheet 95vh
- [ ] Product Detail: abre como bottom sheet 75vh
- [ ] Customer Detail: abre como bottom sheet 75vh
- [ ] Order Detail: abre como bottom sheet 75vh
- [ ] Invoice Detail: abre como bottom sheet 75vh
- [ ] Swipe para baixo (≥90px no header) fecha o drawer
- [ ] Handle visual (barra cinza via `::before`) aparece no topo
- [ ] Conteúdo scrollável dentro do drawer (`-webkit-overflow-scrolling: touch`)

#### Viewport e safe-area
- [ ] Todos os `views/*.html` têm `viewport-fit=cover`
- [ ] `PageRenderer` injeta `viewport-fit=cover` em páginas PHP dinâmicas
- [ ] Conteúdo não fica atrás da barra de endereço do Chrome (scroll correto)
- [ ] Bottom nav não fica atrás do indicador home do iPhone

#### Mapa interativo (mapa_vendas)
- [ ] `touch-action: pan-x pan-y` permite zoom com dois dedos sem travar a página
- [ ] Mapa tem altura mínima 220px em mobile
- [ ] Drawer de bairro abre como bottom sheet (95vh)

---

### 3. Rede e desempenho (Chrome DevTools)

#### Slow 3G + CPU 4x throttle

1. DevTools → Network → **Slow 3G**
2. DevTools → Performance → **CPU 4x slowdown**
3. Carregar `index.php` e medir:

| Métrica | Meta |
|---|---|
| LCP (Largest Contentful Paint) | < 3.5s |
| CLS (Cumulative Layout Shift) | < 0.1 |
| INP (Interaction to Next Paint) | < 200ms |
| Tap targets (botões) | ≥ 44×44px |

4. Verificar que gráficos **não animam** em mobile (animation.duration = 0)
5. Verificar que labels de eixo são reduzidos (maxTicksLimit = 4)

#### Lighthouse Mobile

1. DevTools → Lighthouse → **Mobile** → Generate report
2. Verificar ausência de warnings sobre:
   - Tap targets muito pequenos
   - Viewport não configurado
   - Conteúdo mais largo que a tela

---

### 4. Smoke check automatizado

```bash
# Verificação rápida (human-readable)
php tools/dev/mobile_smoke_check.php

# Saída JSON para CI
php tools/dev/mobile_smoke_check.php --json
# Exit code 0 = tudo OK, exit code 2 = falha
```

O smoke check cobre 19 pontos:

1. `mag-mobile.css` registrado em BASE_CSS
2. `mobile-shell.js` registrado em BASE_JS
3. `assets/css/mag-mobile.css` existe (com tamanho)
4. `assets/js/mobile-shell.js` existe (com tamanho)
5. `views/partials/mobile_topbar.php` existe
6. `views/partials/mobile_bottom_nav.php` existe
7. `app.php` inclui `mobile_topbar.php`
8. `app.php` inclui `mobile_bottom_nav.php`
9. `PageRenderer` injeta `viewport-fit=cover`
10. `viewport-fit=cover` em todos os `views/*.html`
11. CSS tem `@media (max-width: 767px)`
12. JS expõe `window.BIomieMobileShell`
13. Topbar tem `data-mobile-sidebar-toggle`
14. Bottom nav define 5 destinos
15. CSS tem `@media (max-width: 400px)`
16. CSS tem `touch-action` (mapa/scroll)
17. JS usa `MutationObserver`
18. JS detecta `body[data-page]`
19. JS acessa `BIomieVendorDetail` com guarda

---

### 5. Critérios de aceite

| # | Critério | Como verificar |
|---|---|---|
| 1 | Topbar mobile fixa sem deslocar conteúdo | Scroll: topbar permanece no topo |
| 2 | Bottom nav com 5 atalhos e item ativo correto | Navegar por todas as páginas |
| 3 | Sidebar abre como overlay (não push) | Tap hambúrguer em mobile |
| 4 | Drawers como bottom sheet com swipe | Tap swipe-down no header do drawer |
| 5 | Filtros como bottom sheet | Tap "Filtros" em cada página |
| 6 | Tabelas como cards com rótulos | Inspecionar `[data-label]` nos `<td>` |
| 7 | `BIomie.mobile.whenVisible()` disponível | `typeof BIomie.mobile.whenVisible` → "function" |
| 8 | Gráficos sem animação em mobile | Renderização imediata (duration=0) |
| 9 | `viewport-fit=cover` em todas as páginas | Smoke check check 9 e 10 |
| 10 | Desktop inalterado (768px+) | Resize para 1024px: comportamento idêntico ao anterior |
| 11 | `MobileExperienceHealthCheck` em `/system_health` | Status OK ou warning com detalhes |
| 12 | Smoke check 19/19 | `php tools/dev/mobile_smoke_check.php` |
| 13 | Sem JS errors no console | DevTools Console: zero erros críticos |

---

## Regras absolutas (não violar)

- Desktop nunca muda (todas as regras mobile em `@media (max-width: 767px)`)
- Nenhuma lógica de negócio alterada
- Nenhum endpoint alterado
- Nenhum cálculo ou sync Omie alterado
- Todo comportamento novo tem fallback sem JS
- `mobile-shell.js` delega sidebar ao `shell.js` existente — não reimplementa
