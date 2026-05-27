# FIN.R4.3 — Financeiro Operacional 360: NF, Pedido, Título, Cliente e Vendedor

**Data:** 2026-05-27
**Fase:** FIN.R4.3 (evolução da FIN.R4.2)
**Status:** Implementado — pendente smoke visual em produção

---

## 1. Problema Identificado

Tela financeiro.php mostrava volume mas não entregava rastreabilidade operacional:

| Sintoma | Causa | Fase |
|---|---|---|
| Drawer muito estreito (scroll horizontal) | CSS `min-width: 580px; max-width: 780px` | P7 |
| Texto concatenado sem estrutura (`...45426Cliente nao informado`) | Chave NF de 44 chars no 1° `<td>` sem truncamento | P8 |
| "Cobrança por vendedor" = 1 barra gigante "Sem vendedor" | `buildVendorRanking` filtrava apenas `is_boleto=true`; `__sem_vendedor__` ficava sozinho | P6/P2 |
| Labels "Boleto" para títulos PIX/não informado | Rótulos de KPI/seções hardcoded como "boleto" | P4 |
| Tabela NF sem coluna Pedido | Coluna ausente no HTML e no `renderTraceNfTable` | P5 |
| Títulos duplicados no builder (JOIN bridge) | `fetchTitles` sem deduplicação por `titulo_key` | P2 |

---

## 2. Fases Implementadas

### Phase 1 — Diagnóstico de Gaps
- **`tools/dev/finance_trace_linkage_gap_report.php`** (novo, read-only)
- Audita 9 tabelas, 15 categorias de cobertura
- Produz: NF linkage %, vendor gap %, client gap %, recovery opportunities, trace quality score (A/B/C/D)
- Categorias: NF direct/bridge/mart, pedido direct/mart, vendor via título/NF, cliente via título/NF, chave_nfe não bridgeada, bridge confidence, documento_origem distribution, root causes, recommendations

### Phase 2 — FinanceReceivableTraceBuilder
- **`fetchTitles()`**: deduplicação PHP por `titulo_key` — mantém a row com maior `confidence_score` quando bridge JOIN produz duplicatas
- **`buildVendorRanking()`**: removido filtro `is_boleto` → mostra TODOS os recebíveis abertos por vendedor; `__sem_vendedor__` separado como `$unknown` e appendado no final; novos campos `is_unknown_vendor`, `receivable_count`, `methods[]`
- **`buildOpenInvoiceSummary()`**: adicionados `pedido_id_omie`, `vendor_missing`, `methods[]` por NF
- **`makeTitleIdentityKey()`**: método público estático para chave composta `nota_key|pedido|vendor_key|client_key`

### Phase 4 — Rename Labels
- HTML: "Boletos em aberto" → "Recebíveis em aberto", "Boletos vencidos" → "Recebíveis vencidos", "NFs com boletos" → "NFs com recebíveis", "Boletos por vendedor" → "Cobrança por vendedor", "Aging de boletos" → "Aging de recebíveis"
- JS: KPI subtitle, chart onClick labels, drawer subtitle labels

### Phase 5 — Coluna Pedido na Tabela NF
- `<thead>` da tabela: "Boletos" → "Recebíveis", "Emissão" → "Pedido"
- `renderTraceNfTable()`: mostra `pedido_id_omie` na coluna Pedido (com `—` quando ausente)
- NF display: mostra `numero_nf` preferencialmente; fallback para últimos 8 chars de `nota_key`

### Phase 6 — Vendor Chart Fix
- `drawTraceVendor()`: separa `__sem_vendedor__` do array antes de renderizar o chart
- Se `semVendedor.open_value > 0`: mostra card de aviso amarelo com valor e contador, clicável para abrir drawer
- Chart mostra apenas vendedores conhecidos (top 10)
- ID `financeTraceVendorNoData` no HTML para o card de aviso

### Phase 7 — Drawer Width
- CSS `.finance-trace-drawer`: `width: min(50vw, 980px); min-width: 720px`
- Mobile `(max-width: 760px)`: `width: 100%; min-width: 0`

### Phase 8 — Drawer Rendering
- `openTraceDrawer('nf', '', ...)` (View All): mostra `is_open` rows em vez de `nota_key === ''`
- `openTraceDrawer('vendor', '', ...)`: mostra todos `is_open` em vez de somente `is_boleto`
- Truncamento de `nota_key`: mostra `…<last-8-chars>` com `title` do full key; nunca expõe 44 chars inline
- Coluna Pedido no drawer: mostra `pedido_id_omie` ou `—`
- Vendor na tabela: `⚠ <nome>` em amber quando `vendor_missing`
- `tbody.innerHTML = ''` quando rows vazio antes de mostrar `empty`

### Phase 9 — Method Badges
- `methodBadge(method)`: retorna `<span class="fin-badge fin-badge--<tipo>">Label</span>`
- Cores: boleto=azul, pix=verde, nao_informado=cinza, default=roxo
- Usado na tabela NF e no drawer

### Phase 12 — Guards + Docs
- **`tools/dev/visual_layout_guard.php`**: +10 novos checks FIN.R4.3
- **`tools/dev/biomie_release_guard.php`**: D20 informacional; versão `FIN.R4.3`
- **`docs/FIN_R4_3_RECEIVABLE_360_20260527.md`**: este arquivo

---

## 3. Arquivos Modificados

| Arquivo | Mudança |
|---|---|
| `app/Support/FinanceReceivableTraceBuilder.php` | Phase 2: dedup, vendor ranking, pedido NF, makeTitleIdentityKey |
| `views/financeiro.html` | Phase 4/5/6: labels, Pedido col, vendor nodata card, drawer thead |
| `assets/js/modules/finance/visual-analytics.js` | Phase 4/5/6/8/9: methodBadge, renderTraceNfTable, drawTraceVendor, openTraceDrawer |
| `assets/css/finance-realizado.css` | Phase 7/9: drawer width, badge CSS |
| `tools/dev/biomie_release_guard.php` | Phase 12: D20 + versão |
| `tools/dev/visual_layout_guard.php` | Phase 12: +10 checks FIN.R4.3 |

## 4. Arquivos Criados

| Arquivo | Função |
|---|---|
| `tools/dev/finance_trace_linkage_gap_report.php` | Phase 1: diagnóstico de gaps NF/vendor/client/pedido |
| `docs/FIN_R4_3_RECEIVABLE_360_20260527.md` | Este arquivo |

---

## 5. O Que Não Foi Alterado

- Regras financeiras de valor, status, data
- Sync, cron, worker, materialização de marts
- Base Comercial NF / BiVisibilityScope
- Schema/migrations
- Bridge NF × título
- Licitação excluída
- Conciliação financeira

---

## 6. Validações

```bash
php -l app/Support/FinanceReceivableTraceBuilder.php
php -l tools/dev/finance_trace_linkage_gap_report.php
php -l tools/dev/biomie_release_guard.php
php -l tools/dev/visual_layout_guard.php

php tools/dev/finance_trace_linkage_gap_report.php --start=2026-05-01 --end=2026-05-31 --pretty
php tools/dev/visual_layout_guard.php
php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
php healthz.php
```

---

## 7. Pendências

| Item | Prioridade |
|---|---|
| Smoke visual em produção: verificar drawer 50vw, vendor chart sem "Sem vendedor" gigante, badges de método | Alta |
| Executar gap report em produção: confirmar % cobertura NF/vendor | Alta |
| Phase 3: reorganizar em tabs/chips (Visão Geral, Recebíveis por NF, Cobrança por Vendedor, Métodos) | Média |
| Phase 10: bloco `identity` na API drawer com nf/pedido/cliente/vendedor/metodo | Média |
| Phase 11: `FinanceTraceIdentityResolver` para centralizar resolução NF/Vendor/Client | Baixa |
| Smoke após próximo sync financeiro: confirmar ListarContasReceber populando forma_pagamento | Alta |
