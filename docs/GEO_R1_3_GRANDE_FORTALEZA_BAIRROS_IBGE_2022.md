# GEO.R1.3 — Grande Fortaleza por Bairros (IBGE 2022)

## Objetivo

Ativar a camada `grande_fortaleza_bairros` no mapa comercial do BIomie com bairros clicáveis
dos municípios metropolitanos que possuem geometria válida no arquivo IBGE 2022.

## Fonte

| Campo | Valor |
|-------|-------|
| Instituição | IBGE |
| Produto | Malha Censitária 2022 — Bairros por UF |
| Arquivo | `CE_bairros_CD2022.zip` |
| URL FTP | `https://geoftp.ibge.gov.br/organizacao_do_territorio/malhas_territoriais/malhas_de_setores_censitarios__divisoes_intramunicipais/censo_2022/bairros/shp/UF/CE_bairros_CD2022.zip` |
| CRS original | EPSG:4674 / SIRGAS 2000 |
| CRS de saída | EPSG:4326 / WGS84 |
| Encoding | UTF-8 |
| Total Ceará | 1.311 bairros |
| Ano do produto | 2022 |

## Arquivo de origem local

```
storage/geo/source/CE_bairros_CD2022/
  CE_bairros_CD2022.shp
  CE_bairros_CD2022.shx
  CE_bairros_CD2022.dbf
  CE_bairros_CD2022.prj
  CE_bairros_CD2022.cpg
```

## Arquivo gerado

```
assets/geo/rmf_bairros_ibge_2022.geojson   ← consumido pelo front
storage/geo/generated/rmf_bairros_ibge_2022.geojson  ← cópia de rastreabilidade
```

## Campos usados do shapefile IBGE

| Campo IBGE | Campo BIomie | Descrição |
|------------|-------------|-----------|
| `CD_MUN` | `municipio_ibge` | Código IBGE do município (7 dígitos) |
| `NM_MUN` | `municipio_nome` | Nome oficial do município |
| `CD_BAIRRO` | `bairro_ibge` | Código IBGE do bairro |
| `NM_BAIRRO` | `bairro_nome` | Nome oficial do bairro |
| — | `bairro_norm` | Nome normalizado (lowercase, sem acento) |
| — | `bairro_key` | Chave estável: `{municipio_ibge}:{bairro_norm}` |
| — | `municipio_norm` | Município normalizado (lowercase, sem acento) |
| — | `cidade_key` | Igual a `municipio_norm` |
| — | `join_key` | `{cidade_key}:{bairro_norm}` — usado pelo JS para match com API |
| — | `label` | `{bairro_nome} — {municipio_nome}` — exibido no tooltip |
| — | `source` | `IBGE_BAIRROS_CD2022` |
| — | `source_year` | `2022` |
| — | `crs_origin` | `EPSG:4674/SIRGAS2000` |
| — | `layer_id` | `grande_fortaleza_bairros` |

## Municípios RMF com bairros (IBGE 2022)

| Município | Código IBGE | Bairros |
|-----------|------------|---------|
| Caucaia | 2303709 | 56 |
| Maracanaú | 2307650 | 45 |
| Maranguape | 2307700 | 19 |
| Eusébio | 2304285 | 23 |
| Pacatuba | 2309706 | 8 |
| Itaitinga | 2306256 | 21 |
| Guaiúba | 2304954 | 9 |
| Trairi | 2313500 | 13 |
| **Total** | | **194** |

> Fortaleza (121 bairros) é excluída por padrão — continua usando `fortaleza_bairros.json`
> (fonte IPPLAN, já validada). Para incluir Fortaleza: `--include-fortaleza`.

## Municípios RMF sem bairros no IBGE 2022

Os seguintes municípios não têm bairros cadastrados no produto IBGE 2022.
Eles **não são inventados** — continuam disponíveis na camada `rmf_municipios`.

- Aquiraz (2301000)
- Horizonte (2305233)
- Pacajus (2309607)
- Chorozinho (2303956)
- Cascavel (2303501)
- Pindoretama (2310852)
- Paracuru (2310209)
- Paraipaba (2310258)
- São Gonçalo do Amarante (2312403)
- São Luís do Curu (2312601)

## Regra de fallback

| Situação | Comportamento |
|----------|--------------|
| Cliente em Caucaia com bairro no IBGE | Aparece no mapa de bairros |
| Cliente em Aquiraz (sem bairros no IBGE) | Disponível via `rmf_municipios` |
| Cliente sem cidade ou bairro cadastrado | `sem_bairro` (não aparece no mapa) |

## Matching API

A API `sales_by_neighborhood.php` suporta `layer=grande_fortaleza_bairros`:

1. Remove filtro por cidade única
2. Agrupa por `(cidade_norm, bairro_norm)`
3. Retorna `bairro_key = "caucaia:jurema"` (compound key)
4. O JS faz match via `join_key` do GeoJSON (`"caucaia:jurema"` → normaliza para `"caucaia_jurema"`)

## Limites desta fase

- Sem bairros para: Aquiraz, Horizonte, Pacajus, Chorozinho, Cascavel, Pindoretama, Paracuru, Paraipaba, São Gonçalo do Amarante, São Luís do Curu
- OSM/Overpass **não é** fonte PASS nesta fase
- Distritos e subdistritos **não são** usados como bairros
- A camada Fortaleza por bairros permanece intacta e separada

## Como regenerar o GeoJSON

```bash
# Com arquivo local já em storage/geo/source/CE_bairros_CD2022/
python tools/dev/geo_generate_rmf_bairros.py --dry-run
python tools/dev/geo_generate_rmf_bairros.py

# Com download automático do IBGE
python tools/dev/geo_generate_rmf_bairros.py --download-if-missing

# Com Fortaleza incluída
python tools/dev/geo_generate_rmf_bairros.py --include-fortaleza
```

## Como rodar o probe

```bash
php tools/dev/geo_rmf_bairros_source_probe.php
# Deve retornar: [PASS] PRONTO PARA DEPLOY
```

## Checklist de smoke visual

- [ ] Abrir `/biomie/mapa_vendas.php`
- [ ] Selecionar "Fortaleza por bairros" → bairros clicáveis, comportamento original
- [ ] Selecionar "Grande Fortaleza por bairros" → bairros metropolitanos aparecem
- [ ] Clicar em bairro de Caucaia → tooltip mostra "Bairro — Caucaia" + IBGE Bairros 2022
- [ ] Clicar em bairro de Eusébio → tooltip com município correto
- [ ] Clicar em bairro de Maracanaú → tooltip com município correto
- [ ] Clicar em bairro de Maranguape → tooltip com município correto
- [ ] Selecionar "Região Metropolitana por municípios" → municípios clicáveis (intacto)
- [ ] Botão "Enquadrar" recentra na RMF
- [ ] Mobile: mapa não esmagado
- [ ] Nenhum HTTP 500
- [ ] Nenhuma tela branca

## Decisões arquiteturais

### Por que não usar distritos/subdistritos como bairros?

O IBGE distingue `CD_DIST`/`NM_DIST` e `CD_SUBDIST`/`NM_SUBDIST` de `CD_BAIRRO`/`NM_BAIRRO`.
Usar distritos como bairros criaria inconsistência semântica com a camada de Fortaleza (que usa bairros).

### Por que não usar OSM como fonte PASS?

OSM pode ter bairros incompletos, limites desenhados por colaboradores voluntários ou divergência
com leis municipais. Para BI comercial, a fonte deve ser auditável e oficial. OSM é fallback WARN apenas.

### Por que Fortaleza é excluída por padrão?

`fortaleza_bairros.json` usa a malha IPPLAN (Instituto de Planejamento de Fortaleza), com 121 bairros
validados e já em produção. O IBGE também tem 121 bairros para Fortaleza, mas manter a fonte IPPLAN
preserva compatibilidade com os dados históricos e evita rematerialização do mart.
