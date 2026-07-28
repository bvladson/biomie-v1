# GEO.R1.8 — Mapa Comercial Inteligente

## Objetivo

Transformar o mapa de vendas do BIomie em uma **central de decisão comercial geográfica**, com UX gerencial, insights automáticos, Territory 360 com abas, exportação e camadas analíticas planejadas.

## Microentregas

| Fase      | Título                             | Status          |
|-----------|------------------------------------|-----------------|
| GEO.R1.8.1 | UX gerencial do mapa              | ✅ Implementado  |
| GEO.R1.8.2 | Territory 360 (tabs drawer)       | 🟡 Stub          |
| GEO.R1.8.3 | Data Quality geográfico           | 🟡 Flag + modal  |
| GEO.R1.8.4 | Insights e ações comerciais       | ✅ Implementado  |
| GEO.R1.8.5 | Heatmap e clusters                | 🔵 Feature flag  |
| GEO.R1.8.6 | Seleção manual e comparação       | 🔵 Feature flag  |

---

## GEO.R1.8.1 — UX Gerencial

### Chips de foco territorial (`geoFocusChips`)

Barra de chips acima dos filtros para trocar o foco do mapa sem abrir o select:

| Chip            | Layer                    |
|-----------------|--------------------------|
| Visão completa  | mapa_composto_ceara      |
| Fortaleza       | fortaleza_bairros        |
| Grande Fortaleza| grande_fortaleza_bairros |
| RMF             | rmf_municipios           |
| Ceará municípios| ceara_municipios         |
| Metrópoles      | metro_todas_municipios   |
| Sobral          | metro_sobral_municipios  |
| Cariri          | metro_cariri_municipios  |

### Modo visual (`geoModeChips`)

Chips abaixo do título do mapa:

| Chip           | Modo          | Status         |
|----------------|---------------|----------------|
| Territórios    | territories   | ✅ Ativo        |
| Calor          | heatmap       | 🔵 Em breve     |
| Clientes       | clusters      | 🔵 Em breve     |
| Qualidade      | quality       | ✅ Mostra alerta sem bairro |

### Auto-insights (`geoInsightsCard`)

Painel lateral (abaixo do ranking) com até 5 insights automáticos calculados a partir dos dados do mapa composto:

1. Melhor território do período
2. Concentração alta (top 3 > 50%)
3. NFs sem bairro (com % do total)
4. Quantidade de territórios com venda
5. Municípios sem venda (estimativa de oportunidade)

### Exportação CSV (`geoExportCsvBtn`)

Botão abaixo do ranking que gera CSV do ranking territorial atual (respeitando filtros).

### Fix: Subtitle do drawer

`biomie-map-panel.js` corrigido: o subtítulo do drawer não mostra mais `"Fortaleza / X"` para bairros metropolitanos. Agora usa `"${city} · Ceará"` onde `city` vem de `props.municipio_nome` da feature.

---

## GEO.R1.8.2 — Territory 360 (stub)

Estrutura de tabs adicionada ao drawer:

- **Resumo** — KPIs, compradores, CNAEs (conteúdo atual)
- **Compradores** — tab placeholder (GEO.R1.8.2 futuro)
- **CNAEs** — tab placeholder
- **Ações** — recomendações comerciais (GEO.R1.8.4 futuro)

O drawer agora tem `id="geoDrawerTabs"` e classe `.geo-drawer-tabs`.

---

## GEO.R1.8.4 — `api/geo_opportunities.php`

Endpoint de insights geográficos:

```
GET api/geo_opportunities.php?date_from=2026-01-01&date_to=2026-05-31
```

### Insights calculados

| Tipo              | Lógica                                               |
|-------------------|------------------------------------------------------|
| `growth`          | Melhor território do período por faturamento          |
| `concentration`   | Top 3 > 50% do total → alerta de risco               |
| `data_quality`    | NFs sem bairro + % sobre total                       |
| `coverage`        | Quantidade de territórios com venda                  |
| `opportunity`     | Municípios CE sem venda → potencial de prospecção    |

---

## GEO.R1.8.5 / R1.8.6 — Feature flags

Modos futuros controlados por `GEO_FEATURES` em `biomie-map.js`:

```javascript
const GEO_FEATURES = {
  heatmap:       false,  // calor de vendas (Leaflet.heat)
  clusters:      false,  // cluster de clientes (Leaflet.markercluster)
  areaSelection: false,  // seleção manual de área (Leaflet.draw)
  comparison:    false,  // comparar territórios
  smartSearch:   true,
  insights:      true,
  exportCsv:     true,
  timeline:      false,
};
```

Para habilitar heatmap quando implementado:
1. Adicionar `<script src="leaflet-heat.js"></script>` no HTML
2. Mudar `GEO_FEATURES.heatmap = true`
3. Criar `api/geo_heatmap_sales.php`
4. Implementar `initHeatmapMode()` no JS

---

## Arquivos alterados / criados

| Arquivo | Tipo |
|---------|------|
| `views/mapa_vendas.html` | Alterado |
| `assets/js/biomie-map.js` | Alterado |
| `assets/js/biomie-map-panel.js` | Alterado (fix subtitle) |
| `assets/css/biomie-map.css` | Alterado |
| `api/geo_opportunities.php` | Criado |
| `tools/dev/geo_commercial_intelligence_probe.php` | Criado |
| `docs/GEO_R1_8_MAPA_COMERCIAL_INTELIGENTE.md` | Criado |

---

## Validações

```bash
php tools/dev/geo_commercial_intelligence_probe.php
php tools/dev/geo_composite_map_probe.php
php tools/dev/visual_layout_guard.php
php tools/dev/biomie_release_guard.php
php tools/dev/lint_all.php
```

---

## Critérios de aceite (GEO.R1.8.1)

1. Chips de foco aparecem acima dos filtros
2. Clicar chip muda foco do mapa sem reload total
3. Modo visual chips visíveis; Calor/Clientes marcados como "breve"
4. Insights aparecem no painel lateral após carregar mapa composto
5. Clicar "Exportar ranking CSV" gera arquivo `.csv`
6. Clicar Olho d'Água no mapa mostra "Eusébio · Ceará" no drawer (não "Fortaleza / Eusébio")
7. Drawer tem tabs Resumo / Compradores / CNAEs / Ações
8. Ranking preservado com badges de tipo territorial
9. Mobile não esmagado

## Próximas fases

- **GEO.R1.8.2**: Implementar conteúdo real de cada tab do Territory 360
- **GEO.R1.8.3**: Endpoint `api/geo_data_quality.php` + lista de clientes sem bairro
- **GEO.R1.8.5**: Heatmap com Leaflet.heat + `api/geo_heatmap_sales.php`
- **GEO.R1.8.6**: Seleção manual de área com Leaflet.draw
