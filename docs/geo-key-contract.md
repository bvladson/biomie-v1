# Contrato: Chaves de Bairro (bairro_key / bairro_norm / bairro_nome)

> Versão: 2026-05-17  
> Implementado em: Fase 4 — Materialização Geo Segura

---

## Campos de bairro — definição canônica

| Campo | Exemplo | Formato | Uso |
|---|---|---|---|
| `bairro_key` | `prefeito_jose_walter` | `[a-z0-9_]` only, underscore-separated | **Chave técnica estável** — JOIN, URL param, comparação |
| `bairro_norm` | `prefeito jose walter` | `[a-z0-9 ]` only, space-separated | **Legado** — mantido para retrocompatibilidade |
| `bairro_nome` | `Prefeito José Walter` | Display, com acentos e maiúsculas | **Somente exibição** — nunca usar como lookup key |
| `cidade_key` | `fortaleza` | `[a-z0-9_]` only | Chave técnica estável da cidade |

### Regras de formação do `bairro_key`

1. Strip de acentos/cedilha via PHP `Normalizer::normalize(FORM_D)` + remove `\p{Mn}` (PHP, não SQL — SQL não trata UTF-8 accented chars corretamente)
2. Lowercase
3. Substituir qualquer sequência de `[^a-z0-9]` por `_`
4. Colapsar `__` em `_`
5. Strip de `_` no início e fim

**Exemplos:**

| Input | `bairro_key` |
|---|---|
| `Cocó` | `coco` |
| `Prefeito José Walter` | `prefeito_jose_walter` |
| `Antônio Bezerra` | `antonio_bezerra` |
| `Conjunto Ceará` | `conjunto_ceara` |
| `José de Alencar` | `jose_de_alencar` |
| `Fátima` | `fatima` |
| `Messejana` | `messejana` |
| `Centro` | `centro` |

### Gerado por

`GeoKeyNormalizer::makeNeighborhoodKey(?string $bairro): string`  
`GeoKeyNormalizer::makeCityKey(?string $city): string`

Localizado em [app/Support/Geo/GeoKeyNormalizer.php](../app/Support/Geo/GeoKeyNormalizer.php).

---

## Tabelas e colunas

### `mart_compradores_por_geo`

Adicionado via migration `Version20260517000002_GeoKeysNormalization`:

| Coluna | Tipo | Índice |
|---|---|---|
| `bairro_key` | `VARCHAR(160)` | `idx_geo_buyers_city_bairro_key_comp (cidade_key, bairro_key, competencia)` |
| `cidade_key` | `VARCHAR(120)` | incluído acima |
| `bairro_nome` | `VARCHAR(160)` | — |
| `cidade_nome` | `VARCHAR(120)` | — |

### `mart_vendas_por_cnae_cnpj`

| Coluna | Tipo |
|---|---|
| `bairro_key` | `VARCHAR(160)` |
| `cidade_key` | `VARCHAR(120)` |

### `biomie_clientes_dim` e `biomie_clientes_geo_dim`

Ambas recebem `bairro_key VARCHAR(160)` e `cidade_key VARCHAR(120)`.

---

## Como os bairro_keys são populados

### Novos dados (após migration)

`GeoAggregateBuilder` chama `backfillGeoKeys()` após cada INSERT em `mart_compradores_por_geo`. O método:

1. Busca pares `(cidade_norm, bairro_norm)` com `bairro_key IS NULL`
2. Calcula `bairro_key` via `GeoKeyNormalizer::makeNeighborhoodKey()` (PHP)
3. Emite `UPDATE` por par

### Dados legados (backfill manual)

```bash
# Dry-run (mostra o que seria feito):
php tools/dev/geo_keys_backfill.php --dry-run --table=all

# Aplicar:
php tools/dev/geo_keys_backfill.php --apply --table=all

# Tabela específica:
php tools/dev/geo_keys_backfill.php --apply --table=mart_compradores_por_geo
```

---

## Como os endpoints filtram

### Estratégia de filtro (quando `bairro_key` col existe)

```sql
WHERE (bairro_key = :bk
   OR  (bairro_key IS NULL AND bairro_norm COLLATE utf8mb4_general_ci = :bk_fb))
```

Isso garante que linhas ainda não backfilladas (col `NULL`) continuem aparecendo via COLLATE accent-insensitive.

### Quando `bairro_key` col não existe (pré-migration)

```sql
WHERE bairro_norm COLLATE utf8mb4_general_ci = :bk
```

Fallback puro via COLLATE — funciona mas não tem índice.

### Resolução de parâmetros nos endpoints

Os endpoints aceitam `bairro_key` (prioritário) ou `bairro_norm`/`neighborhood` (legado):

```
bairro_key=coco           → GeoKeyNormalizer::makeNeighborhoodKey('coco')      = 'coco'
bairro_norm=prefeito+jose → GeoKeyNormalizer::makeNeighborhoodKey('prefeito jose') = 'prefeito_jose'
neighborhood=Cocó         → GeoKeyNormalizer::makeNeighborhoodKey('Cocó')      = 'coco'
```

**Endpoints afetados:** `api/top_buyers_by_geo.php`, `api/sales_by_cnae.php`, `api/sales_by_neighborhood.php`, `api/geo_neighborhood_detail.php`

---

## GeoJSON — enriquecimento em loadGeoJson()

Ao carregar o arquivo GeoJSON, `loadGeoJson()` em [assets/js/biomie-map.js](../assets/js/biomie-map.js) enriquece cada feature:

```js
feature.properties.bairro_key  = feature.properties.bairro_key  || normalizeNeighborhoodKey(name);
feature.properties.bairro_nome = feature.properties.bairro_nome || name;
feature.properties.city_key    = feature.properties.city_key    || 'fortaleza';
```

O clique num polígono usa `feature.properties.bairro_key` diretamente — sem re-normalizar no clique, evitando divergência entre JS e PHP.

### normalizeNeighborhoodKey() no JS

Idêntico ao PHP:

```js
function normalizeNeighborhoodKey(value) {
  return String(value ?? '').trim()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/_+/g, '_')
    .replace(/^_|_$/g, '');
}
```

---

## Como diagnosticar problemas de clique sem compradores

### Passo 1 — Testar normalização

```bash
php tools/dev/geo_sales_debug.php --bairro="Cocó" --normalize-only
# Saída: bairro_key=coco, bairro_norm=coco

php tools/dev/geo_sales_debug.php --bairro="Prefeito José Walter" --normalize-only
# Saída: bairro_key=prefeito_jose_walter, bairro_norm=prefeito jose walter
```

### Passo 2 — Verificar consistência das chaves no mart

```bash
php tools/dev/geo_sales_debug.php --check-keys
# Mostra: bairro_key col, rows sem key, maiúsculas, acentos, conflitos de nome
```

### Passo 3 — Auditoria completa de um bairro

```bash
php tools/dev/geo_sales_debug.php \
  --start=2026-05-01 --end=2026-05-31 \
  --bairro-key=coco \
  --json
```

Verifica: mart rows, top buyers, variantes de bairro_norm, cobertura geo_dim.

### Passo 4 — Verificar consistência via health check

```bash
php bin/system_health_check.php --check geo_detail_consistency
```

Retorna `ok` quando:
- Coluna `bairro_key` existe
- 0 linhas com faturamento sem `bairro_key`
- `bairro_norm` sem maiúsculas
- `bairro_norm` sem acentos

### SQL diagnóstico direto

```sql
-- Top bairros por chave
SELECT cidade_key, bairro_key, COUNT(*), ROUND(SUM(faturamento),2) AS fat
FROM mart_compradores_por_geo
GROUP BY cidade_key, bairro_key
ORDER BY fat DESC LIMIT 20;

-- Inconsistências de normalização
SELECT bairro_norm, COUNT(*) AS rows
FROM mart_compradores_por_geo
WHERE bairro_norm != LOWER(bairro_norm)
   OR bairro_key IS NULL
GROUP BY bairro_norm
LIMIT 20;
```

---

## Backfill + Rematerialização (ordem de execução no servidor)

```bash
# 1. Aplicar migration (adiciona colunas bairro_key/cidade_key)
php bin/migrate.php

# 2. Dry-run do backfill
php tools/dev/geo_keys_backfill.php --dry-run --table=all

# 3. Aplicar backfill
php tools/dev/geo_keys_backfill.php --apply --table=all

# 4. Rematerializar marts (novos dados virão com bairro_key preenchido)
php bin/materialize_marts.php

# 5. Limpar cache
php bin/cache_clear.php --all --opcache

# 6. Verificar saúde
php bin/system_health_check.php --check geo_detail_consistency
```

---

## 10 bairros de validação obrigatória

| Bairro (display) | `bairro_key` esperado |
|---|---|
| Centro | `centro` |
| Cocó | `coco` |
| Aldeota | `aldeota` |
| Messejana | `messejana` |
| Prefeito José Walter | `prefeito_jose_walter` |
| Fátima | `fatima` |
| Antônio Bezerra | `antonio_bezerra` |
| Pedras | `pedras` |
| Bom Futuro | `bom_futuro` |
| Henrique Jorge | `henrique_jorge` |

Para cada bairro, verificar:
1. `--normalize-only` retorna o `bairro_key` esperado
2. `mart_compradores_por_geo` tem linhas com o `bairro_key` preenchido
3. Clique no mapa abre o painel com compradores
4. `geo_neighborhood_detail.php?bairro_key=X` retorna dados
5. `top_buyers_by_geo.php?bairro_key=X` retorna compradores
6. `sales_by_cnae.php?bairro_key=X` retorna CNAEs (se disponível)
7. Health check `geo_detail_consistency` retorna `ok`
8. Sem warning de "Fonte: NF direta" no painel (indica mart materializado)
