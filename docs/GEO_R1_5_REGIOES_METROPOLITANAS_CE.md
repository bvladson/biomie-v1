# GEO.R1.5 — Regiões Metropolitanas do Ceará

## Objetivo
Camadas de municípios das 3 regiões metropolitanas oficiais do Ceará.

## Camadas
| Layer ID | Arquivo | Municípios | Lei |
|----------|---------|-----------|-----|
| `rmf_municipios` | `rmf_municipios.json` | 19 | LC CE 14/1973 + att. |
| `metro_sobral_municipios` | `metro_sobral_municipios.geojson` | 18 | LC CE 97/2010 |
| `metro_cariri_municipios` | `metro_cariri_municipios.geojson` | 9 | LC CE 78/2009 |
| `metro_todas_municipios` | `metro_todas_municipios.geojson` | 27 | Combinado |

## Fonte
Derivado de `ceara_municipios_oficial.geojson` — geometria IBGE 2022, filtrada por código IBGE oficial de cada RM.

## Registry PHP
`app/Geo/GeoMetropolitanRegionRegistry.php` — lista oficial de códigos IBGE por região.

## API — matching
`layer=metro_*` → sem filtro de cidade, agrega por `cidade_norm`.

## Como regenerar
```bash
python tools/dev/geo_generate_metropolitan_regions.py --dry-run
python tools/dev/geo_generate_metropolitan_regions.py
php tools/dev/geo_metropolitan_regions_probe.php
```
