# GEO.R1.1 — Geometrias Oficiais IBGE/IPECE

**Data:** 2026-05-30
**Fase:** GEO.R1.1 — continuação de GEO.R1 (fundação publicada)
**Status:** PASS — geometrias oficiais IBGE importadas

---

## Objetivo

Substituir os polígonos aproximados/stub da GEO.R1 por geometrias oficiais obtidas via API pública do IBGE. Manter Fortaleza por bairros intacta. Não alterar dados comerciais, NFs ou materialização.

---

## Fontes Oficiais Usadas

| Dado | Fonte | URL |
|---|---|---|
| 184 municípios CE — geometria | IBGE Malhas API v2, resolução 5 | `servicodados.ibge.gov.br/api/v2/malhas/23?formato=geo+json&resolucao=5` |
| 184 municípios CE — nomes | IBGE Localidades API v1 | `servicodados.ibge.gov.br/api/v1/localidades/estados/CE/municipios` |
| 19 municípios RMF — geometria | Derivado de ceara_mun.json | Filtrado por códigos IBGE da LC 180/2018 |
| 14 regiões IPECE — bbox | Derivado de ceara_mun.json | Bounding box dos municípios por região |
| Fortaleza bairros | IPPLAN/Fortaleza em Bairros | Preservado sem alteração |

**Não usadas:** ViaCEP, Overpass, fontes manuais, polígonos inventados.

---

## Arquivos Criados/Modificados

### GeoJSONs (assets/geo/)

| Arquivo | Antes | Depois | Fonte |
|---|---|---|---|
| `ceara_mun.json` | 5 features stub | **184 features IBGE oficial** | IBGE Malhas resolucao=5 |
| `rmf_municipios.json` | 19 features centroid+bbox | **19 features IBGE oficial** | Derivado de ceara_mun.json |
| `ceara_regioes.json` | 14 features centroid±radius | **14 bboxes IBGE-derivados** | Bboxes dos municípios IBGE |
| `fortaleza_bairros.json` | 121 features IPPLAN | **121 features — INTACTO** | Não alterado |

### Ferramentas (tools/dev/)

| Arquivo | Propósito |
|---|---|
| `geo_import_ibge_ce_municipios.php` | Importador idempotente — baixa IBGE e gera ceara_mun.json + rmf_municipios.json |
| `geo_import_ipece_regioes.php` | Importador regiões — deriva de ceara_mun.json ou importa arquivo oficial IPECE |
| `geo_official_geometry_coverage_audit.php` | Diagnóstico de cobertura territorial das vendas |

### Migrations (database/migrations/)

| Arquivo | Propósito |
|---|---|
| `Version20260530200001_GeoTerritoryCeIbgeFix.php` | Corrige códigos IBGE incorretos em biomie_geo_municipios_ce |

### Guards (tools/dev/)

| Arquivo | Adição |
|---|---|
| `biomie_release_guard.php` | D39 — validação de geometrias oficiais IBGE/IPECE |
| `visual_layout_guard.php` | 11 novos checks GEO.R1.1 |

---

## Correções de Códigos IBGE

Identificados e corrigidos em biomie_geo_municipios_ce e na migration:

| Município | Código Incorreto | Código Correto IBGE |
|---|---|---|
| Horizonte | 2306256 (=Itaitinga) | **2305233** |
| Itaitinga | 2306306 (=Itapajé) | **2306256** |
| Pindoretama | 2311264 (=Quiterianópolis) | **2310852** |
| Trairi | 2313401 (=Tianguá) | **2313500** |
| Tianguá (adicionado) | — | **2313161** |

---

## O Que Ficou Como WARN

| Item | Status | Motivo | Próxima ação |
|---|---|---|---|
| `grande_fortaleza_bairros.json` | WARN | Geometria de bairros adjacentes não disponível | Aguardar malha oficial de Eusébio/Maracanaú/Caucaia |
| `ceara_regioes.json` | WARN | Bounding boxes derivados, não polígonos exatos de região | Usar `--from-file` quando arquivo oficial IPECE for obtido |
| Eusébio/Maracanaú no GeoJSON | WARN | Cidade normalizada sem match no ceara_mun | Verificar `municipio_norm` vs `cidade_norm` |

---

## Diferença entre Camadas

| Camada | Seletor | Geometria | Granularidade |
|---|---|---|---|
| Fortaleza bairros | `fortaleza_bairros` | IPPLAN 121 bairros — **oficial** | Bairro |
| Grande Fortaleza | `grande_fortaleza_bairros` | **PENDENTE** — aguardar malha adjacente | Bairro |
| RMF municípios | `rmf_municipios` | **IBGE oficial** — 19 municípios | Município |
| Ceará municípios | `ceara_municipios` | **IBGE oficial** — 184 municípios | Município |
| Regiões IPECE | `ceara_regioes` | IBGE-derivado (bbox) — 14 regiões | Macrorregião |

---

## Comandos Executados

```bash
# Import IBGE official geometries
php tools/dev/geo_import_ibge_ce_municipios.php --pretty

# Generate IPECE regions (derived from IBGE)
php tools/dev/geo_import_ipece_regioes.php --pretty

# Coverage audit
php tools/dev/geo_official_geometry_coverage_audit.php --start=2026-01-01 --end=2026-05-31 --pretty

# Apply IBGE code corrections
php bin/schema_migrate.php migrate
```

---

## Resultados dos Audits

### geo_geometry_audit.php
```
PASS  Fortaleza · Bairros 807.4 KB — 121 features
WARN  Grande Fortaleza · Bairros disponíveis (arquivo pendente)
PASS  RMF · Municípios 13.7 KB — 19 features
PASS  Ceará · Municípios 168.2 KB — 184 features
PASS  Ceará · Regiões IPECE 5.1 KB — 14 features
→ 4 PASS / 1 WARN / 0 FAIL
```

### geo_layer_consistency_audit.php
```
→ 13/13 PASS
```

### biomie_release_guard.php
```
PASS  D39 GEO.R1.1 Geometrias Oficiais IBGE/IPECE
PASS  D38 GEO.R1 Same-Map Ceará/RMF Expansion
→ 40/40 domínios passaram
```

### visual_layout_guard.php
```
→ 248/248 PASS (11 novos checks GEO.R1.1)
```

### Coverage Audit (jan-mai 2026)
```
Total: R$3.286.726 | 1.175 NFs
Com cidade: 87.6% | Sem cidade: 12.4% → atualizar Omie
Com bairro: 87.6% | Sem bairro: 12.4% → atualizar Omie
Fortaleza sem bairro oficial: 15.1%
```

---

## Smoke Visual — Checklist Pendente

Execute no browser após deploy:

- [ ] Fortaleza por bairros abre igual antes
- [ ] 121 bairros de Fortaleza continuam visíveis
- [ ] Ranking não estica o mapa
- [ ] Seletor troca para RMF municípios → mostra polígonos oficiais
- [ ] Eusébio, Maracanaú, Caucaia aparecem como município
- [ ] Seletor troca para Ceará municípios → 184 cidades
- [ ] Seletor troca para Ceará regiões → 14 macrorregiões (bbox)
- [ ] Empty state para grande_fortaleza_bairros (sem geometria)
- [ ] Drawer abre ao clicar no território
- [ ] Escopo Orç-PD/Remessa/Licitação/Todas funciona
- [ ] Console sem erro JS

---

## Rollback Seguro

Os arquivos anteriores são preservados com sufixo `.bak.YYYYMMDD_HHmmss`:
```bash
ls assets/geo/*.bak.*   # listar backups
# Para restaurar:
cp assets/geo/ceara_mun.json.bak.YYYYMMDD_HHmmss assets/geo/ceara_mun.json
```

---

## Próximos Passos — GEO.R1.2

1. **Bairros de Eusébio:** Ativar após Lei Municipal nº 1.800/2021 ter GeoJSON confiável
2. **Maracanaú e Caucaia:** Aguardar plano diretor com geometria de bairros
3. **IPECE oficial:** Quando obtido, executar:
   ```bash
   php tools/dev/geo_import_ipece_regioes.php --from-file=/path/to/ipece.geojson
   ```
4. **Match improvement:** Normalizar `cidade_norm` no mart para bater com `municipio_norm` (ex: `juazeiro_do_norte` vs `juazeiro do norte`)

---

## Regra de Ouro

> Bairro adjacente só entra com geometria oficial validada.  
> Município sem match aparece no diagnóstico, não no mapa como erro.  
> Não consultar ViaCEP em request web.  
> Não alterar dados Omie automaticamente.
