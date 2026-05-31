# GEO.R1.6 — Regiões de Planejamento IPECE Oficiais

## Objetivo
Substituir `ceara_regioes.json` (bbox derivado) por `ceara_regioes_ipece_oficial.geojson` com geometria real dissolvida dos municípios IBGE por região IPECE.

## Arquivo gerado
`assets/geo/ceara_regioes_ipece_oficial.geojson` — 14 features, ~60 KB, WGS84, `geometry_source=dissolved_ibge_municipal_boundaries`

## 14 Regiões de Planejamento
Grande Fortaleza, Litoral Norte, Litoral Oeste / Vale do Curu, Litoral Leste, Maciço de Baturité, Sertão Central, Sertão de Canindé, Serra da Ibiapaba, Sertão de Sobral, Sertão dos Crateús, Sertão dos Inhamuns, Vale do Jaguaribe, Centro-Sul, Cariri

## Método
Dissolve geopandas da malha municipal IBGE 2022 por região IPECE. Todos os 184 municípios mapeados. Geometria resultante são polígonos reais (não bounding box).

## Decisões
- Distritos e subdistritos não são usados
- OSM não é fonte
- Sem WARN de bbox — a geometria é derivada de fonte oficial IBGE dissolvida por região IPECE

## Como regenerar
```bash
python tools/dev/geo_generate_ceara_regioes_ipece.py --dry-run
python tools/dev/geo_generate_ceara_regioes_ipece.py
php tools/dev/geo_ceara_regioes_ipece_probe.php
```
