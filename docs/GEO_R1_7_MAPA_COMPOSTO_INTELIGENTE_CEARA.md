# GEO.R1.7 — Mapa Composto Inteligente do Ceará

## Objetivo

Transformar o mapa de vendas de um seletor de camadas isoladas em um **mapa composto unificado**, onde Fortaleza por bairros, Grande Fortaleza por bairros metropolitanos, municípios RMF sem bairro e demais municípios do Ceará aparecem juntos em uma única visualização comercial.

## Problema resolvido

### Bug Olho d'Água / Fortaleza

Ao clicar em um bairro metropolitano como "Olho d'Água" (Eusébio), o drawer mostrava "Fortaleza / Fortaleza" porque o código usava o valor fixo de `cityFilter` (`value="Fortaleza"` no HTML) como cidade do território.

**Correção:** O drawer e o tooltip agora usam as propriedades reais da feature GeoJSON:
- `props.municipio_nome` → "Eusébio"
- `props.cidade_key` → "eusebio"
- `props.join_key` → "eusebio:olho_dagua"

Nunca mais `city = $('cityFilter')?.value` para camadas multi-cidade.

### Comportamento anterior (problema)

- Usuário precisava escolher: Fortaleza, Grande Fortaleza, Ceará, ou Regiões Metropolitanas — separadamente
- Trocar de camada destruía e recriava o mapa inteiro
- Não havia contexto visual dos outros territórios ao redor

### Comportamento novo (GEO.R1.7)

- Modo padrão: **Visão completa do Ceará** — tudo junto em um mapa
- O seletor virou **Foco do mapa** (filtro de navegação, não substituição de camada)
- Cores indicam o tipo territorial + intensidade de faturamento

## Hierarquia territorial

| Tipo                    | Fonte                              | Cor base     | Join key                  |
|-------------------------|------------------------------------|--------------|---------------------------|
| Fortaleza bairros       | `fortaleza_bairros.json`           | Azul         | `bairro_norm` ex: `aldeota` |
| Grande Fortaleza bairros| `rmf_bairros_ibge_2022.geojson`    | Verde/teal   | `cidade:bairro` ex: `eusebio:olho_dagua` |
| RMF sem bairro IBGE     | `ceara_municipios_oficial.geojson` | Roxo         | `cidade_key` ex: `aquiraz` |
| Ceará restante          | `ceara_municipios_oficial.geojson` | Âmbar/laranja| `cidade_key` ex: `sobral` |

## Paleta de cores

```
fortaleza_bairro:       noSale=#E5E7EB  low=#DBEAFE  mid=#60A5FA  high=#1D4ED8  stroke=#1E40AF
metro_bairro:           noSale=#E5E7EB  low=#CCFBF1  mid=#2DD4BF  high=#0F766E  stroke=#0F766E
rmf_municipio_fallback: noSale=#E5E7EB  low=#EDE9FE  mid=#A78BFA  high=#6D28D9  stroke=#5B21B6
ceara_municipio:        noSale=#E5E7EB  low=#FEF3C7  mid=#F59E0B  high=#B45309  stroke=#92400E
```

A intensidade (low/mid/high) é calculada **separadamente por tipo territorial** para evitar que Fortaleza apague visualmente os municípios do interior.

## Fontes geográficas

| Camada                        | Arquivo                              | Fonte                    | Ano  | Features |
|-------------------------------|--------------------------------------|--------------------------|------|----------|
| Fortaleza bairros             | `fortaleza_bairros.json`             | Legado BIomie            | —    | 121      |
| Grande Fortaleza bairros      | `rmf_bairros_ibge_2022.geojson`      | IBGE Bairros CD2022      | 2022 | ≥194     |
| Ceará municípios              | `ceara_municipios_oficial.geojson`   | IBGE Malha Municipal     | 2022 | 184      |
| Regiões Metropolitanas        | `metro_*_municipios.geojson`         | LCs Estaduais CE         | —    | —        |
| Regiões de Planejamento IPECE | `ceara_regioes_ipece_oficial.geojson`| IPECE                    | —    | 14       |

## Regras de sobreposição no modo composto

1. Carregar `ceara_municipios_oficial.geojson` como base
2. **Excluir** polígonos municipais de Fortaleza e dos 8 municípios RMF com bairros IBGE (via `RMF_WITH_BAIRROS_CODES`)
3. Adicionar bairros metropolitanos (`rmf_bairros_ibge_2022.geojson`) acima
4. Adicionar bairros de Fortaleza no topo
5. Municípios RMF sem bairros permanecem como polígono municipal (cor roxa)

### Municípios excluídos do polígono municipal (têm bairros detalhados)

Códigos IBGE: `2304400` (Fortaleza), `2303709` (Caucaia), `2304285` (Eusébio), `2304954` (Guaiúba), `2306256` (Itaitinga), `2307650` (Maracanaú), `2307700` (Maranguape), `2309706` (Pacatuba), `2313500` (Trairi)

### Municípios RMF como polígono municipal (sem bairros IBGE)

Aquiraz, Horizonte, Pacajus, Chorozinho, Cascavel, Pindoretama, Paracuru, Paraipaba, São Gonçalo do Amarante, São Luís do Curu

## Endpoints

| Endpoint                       | Propósito                                    |
|-------------------------------|----------------------------------------------|
| `api/geo_composite_sales.php` | Dados de vendas para modo composto (todo CE) |
| `api/geo_summary.php`         | Resumo global (outros modos)                  |
| `api/sales_by_neighborhood.php` | Dados por bairro (outros modos)             |
| `api/top_buyers_by_geo.php`   | Compradores no drawer (todos os modos)        |
| `api/sales_by_cnae.php`       | CNAEs no drawer (todos os modos)              |

### Payload `geo_composite_sales.php`

```json
{
  "ok": true,
  "layer": "mapa_composto_ceara",
  "territories": {
    "aldeota": { "territory_type": "fortaleza_bairro", ... },
    "eusebio:olho_dagua": { "territory_type": "metro_bairro", ... },
    "aquiraz": { "territory_type": "rmf_municipio_fallback", ... },
    "sobral": { "territory_type": "ceara_municipio", ... }
  },
  "scales": { "fortaleza_bairro": {"max": 0}, ... },
  "ranking": [...],
  "summary": { "total_vendido": 0, "territorios_com_venda": 0, ... }
}
```

## Arquitetura JS (funções principais)

| Função                       | Responsabilidade                                         |
|-----------------------------|----------------------------------------------------------|
| `loadCommercialData()`       | Roteador — despacha para `initCompositeMap()` no modo composto |
| `initCompositeMap()`         | Orquestra geometrias + vendas + renderização completa    |
| `loadCompositeGeometries()`  | Carrega/une os 3 GeoJSONs (com cache `state.compositeGeoJson`) |
| `loadCompositeSalesData()`   | Chama `geo_composite_sales.php` com filtros atuais       |
| `styleCompositeFeature()`    | Estilo por tipo + receita relativa                        |
| `getTerritoryType()`         | Classifica feature por `territory_type` ou `layer_id`   |
| `getFeatureCompositeJoinKey()` | Deriva join_key por tipo territorial                   |
| `getFeatureTerritoryLabel()` | Label correto usando `municipio_nome` real da feature    |
| `getCompositeColor()`        | Cor por tipo e receita proporcional ao máximo do tipo    |
| `renderCompositeLegend()`    | Legenda composta 4 tipos + sem venda                     |
| `renderCompositeRanking()`   | Ranking territorial com badges de tipo                   |
| `_geoJsonCache`              | Cache persistente de GeoJSON entre troca de filtros      |

## Validações

Executar antes do deploy:

```bash
php tools/dev/geo_composite_map_probe.php
php tools/dev/geo_rmf_bairros_source_probe.php
php tools/dev/geo_ceara_municipios_source_probe.php
php tools/dev/visual_layout_guard.php
php tools/dev/biomie_release_guard.php
php tools/dev/lint_all.php
php tools/dev/schema_migrate.php verify
```

## Critérios de aceite visual

1. `/biomie/mapa_vendas.php` abre diretamente no modo composto
2. Fortaleza em azul (bairros), Grande Fortaleza em verde/teal (bairros), RMF sem bairro em roxo, Ceará interior em âmbar
3. Clicar Centro → "Centro — Fortaleza"
4. Clicar Olho d'Água → "Olho d'Água — Eusébio" (nunca Fortaleza)
5. Clicar Jurema → "Jurema — Caucaia"
6. Clicar Pajuçara → "Pajuçara — Maracanaú"
7. Clicar Sobral → município do Ceará em âmbar
8. Seletor "Foco do mapa" mostra "Visão completa do Ceará" como padrão
9. Regiões de Planejamento NÃO aparecem no seletor
10. Ranking lateral mostra badges de tipo por território
11. KPIs: "Territórios com venda" e "Melhor território"

## Commit e tag

```
feat: add composite Ceará sales map with territorial hierarchy
```

Tag: `geo-r1-7-composite-ceara-sales-map`
