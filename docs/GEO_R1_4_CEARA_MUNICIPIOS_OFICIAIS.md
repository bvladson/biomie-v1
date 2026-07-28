# GEO.R1.4 — Ceará Municípios Oficiais

## Objetivo
Camada `ceara_municipios` com 184 municípios clicáveis, schema oficial, matching robusto e multi-cidade API.

## Arquivo gerado
`assets/geo/ceara_municipios_oficial.geojson` — 184 features, ~210 KB, WGS84

## Fonte
IBGE Malha Municipal Digital 2022 (resolução=5). API: `servicodados.ibge.gov.br/api/v3/malhas/estados/23`

## Campos por feature
`layer_id`, `source`, `source_year`, `crs_origin`, `generated_at`, `codigo_ibge`, `municipio_nome`, `municipio_norm`, `uf`, `cidade_key`, `label`, `join_key`

## API — matching multi-cidade
`layer=ceara_municipios` → API remove filtro de cidade única, agrega por `cidade_norm`, retorna `bairro_key=cidade_norm`.

## Como regenerar
```bash
python tools/dev/geo_generate_ceara_municipios.py --dry-run --use-existing
python tools/dev/geo_generate_ceara_municipios.py --use-existing
php tools/dev/geo_ceara_municipios_source_probe.php
```
