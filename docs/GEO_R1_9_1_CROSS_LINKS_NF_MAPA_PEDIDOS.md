# GEO.R1.9.1 — Cross-links NF ↔ Mapa ↔ Pedidos

**Status:** FECHADA  
**Data:** 2026-05-31  
**Guard:** D40 — 41/41 PASS | VLG 392/392 PASS

---

## Objetivo

Conectar os três pontos de entrada comercial — mapa territorial, tabela de NFs e pedidos — em uma navegação coesa. Um usuário que vê um pico de faturamento no mapa agora consegue perfurar até o pedido em dois cliques.

---

## Entregas

### 1. `api/geo_invoice_detail.php`
- Detalha uma NF a partir do `nf_id` com contexto geo: território, bairro, cidade
- Retorna itens (produtos, qtd, valor unitário, total) via JOIN em tabela de itens de NF
- Fallback graceful quando não há itens mapeados

### 2. `api/company_cnae_ranking.php`
- Ranking de CNAEs por faturamento no período filtrado
- Suporta `cidade_key`, `bairro_key`, `territory_type` para recorte territorial
- Usado pelo strip no index.html

### 3. Expand de itens no drawer NF do mapa (`biomie-map.js`)
- Botão "Itens ↓" em cada linha da paginação de NFs
- Carrega `api/geo_invoice_detail.php` e renderiza tabela inline de produtos
- Link "Ver em pedidos ↗" na cabeça do painel NFs, com `date_from`/`date_to` propagados

### 4. Coluna "Mapa" em `nf-periodo-table.js`
- Nova coluna na tabela de NFs do dashboard comercial
- Cada linha tem ícone "Ver no mapa" que abre `mapa_vendas.php?layer=mapa_composto_ceara`
- Filtros de período e vendedor propagados via query string

### 5. Botão "Ver no mapa" em `pedidos.html`
- Link persistente no topo da página de pedidos
- Atualizado dinamicamente via `MutationObserver` sobre os seletores de data
- Aponta sempre para `mapa_vendas.php?layer=mapa_composto_ceara&date_from=...&date_to=...`

### 6. Strip de Empresas e CNAEs em `index.html`
- Nova seção `#indexGeoCnaeStrip` logo abaixo do KPI strip
- Carrega `api/company_cnae_ranking.php` no `applyFilters`
- Exibe chips de CNAE com contagem de empresas e faturamento
- Link "Ver mapa ↗" para o mapa composto

---

## Smoke Checklist

- [ ] Abrir `mapa_vendas.php`
- [ ] Clicar Aracati → aba Notas → expandir uma NF → ver itens
- [ ] Clicar "Ver em pedidos ↗" → confirmar período propagado
- [ ] Abrir `pedidos.php` → confirmar botão "Ver no mapa" visível
- [ ] Mudar período em pedidos → confirmar link do mapa atualiza
- [ ] Abrir `index.php` → confirmar strip de CNAEs aparece
- [ ] Abrir dashboard comercial (pedidos.html) → confirmar coluna "Mapa" na tabela NFs

---

## Guard

| Check | Arquivo |
|---|---|
| `geo_r191_invoice_detail_api` | `api/geo_invoice_detail.php` |
| `geo_r191_cnae_ranking_api` | `api/company_cnae_ranking.php` |
| `geo_r191_expand_nf_btn` | `biomie-map.js` → `geo-nf-expand-btn` |
| `geo_r191_ver_pedidos_link` | `biomie-map.js` → `"Ver em pedidos"` |
| `geo_r191_ver_mapa_nf_table` | `nf-periodo-table.js` → `"Ver no mapa"` |
| `geo_r191_ver_mapa_pedidos` | `pedidos.html` → `pedidosMapLink` |
| `geo_r191_cnae_strip_index` | `index.html` → `indexGeoCnaeStrip` |
| `geo_r191_smoke_probe` | `tools/dev/geo_r191_smoke_probe.php` |

**D40:** `geo_invoice_detail + cnae_ranking + expand btn + ver-pedidos + ver-mapa + cnae strip`
