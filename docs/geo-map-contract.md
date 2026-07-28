# Geo Map 360 — Contrato e Arquitetura

**Endpoint:** `GET api/geo_neighborhood_detail.php`  
**Auth:** módulo `geo`  
**Fonte de verdade:** `mart_compradores_por_geo` (agregado) + `biomie_notas_fiscais_canonicas` (NFs individuais) + `biomie_clientes_geo_dim` (enriquecimento geográfico)

---

## Conceito

Geo Map 360 é a visão analítica completa de um bairro a partir de dados locais materializados.  
Responde as perguntas:

- Quanto esse bairro comprou?
- Quantos clientes únicos?
- Quais CNAEs predominam?
- Quais NFs sustentam esse número?
- Quais vendedores atuaram nesse bairro?
- Como evoluiu no tempo?
- O dado é fresco ou desatualizado?

---

## Fonte de verdade

| Dimensão | Fonte | Campo temporal |
|---|---|---|
| Resumo / Compradores / CNAEs | `mart_compradores_por_geo` | `competencia` (YYYY-MM) |
| NFs individuais | `biomie_notas_fiscais_canonicas` + `biomie_clientes_geo_dim` | `data_competencia` |
| Vendedores | `biomie_notas_fiscais_canonicas` + `biomie_clientes_geo_dim` | `data_competencia` |
| Nome oficial do bairro | `biomie_bairros_fortaleza_dim` | — |

**Regra fiscal:** Realizado nunca usa `data_emissao`. Sempre `data_competencia` ou `competencia`.

---

## Parâmetros da API

| Parâmetro | Tipo | Obrigatório | Default |
|---|---|---|---|
| `neighborhood_key` | string (normalizado) | sim | — |
| `neighborhood` | string (display) | não | `neighborhood_key` |
| `city` | string | não | `Fortaleza` |
| `start` | YYYY-MM-DD | não | 1º dia do mês atual |
| `end` | YYYY-MM-DD | não | hoje |
| `limit` | int (1–200) | não | 50 |
| `tab` | string | não | null (carrega tudo) |

**Tabs válidas:** `resumo`, `compradores`, `cnaes`, `nfs`, `vendedores`, `evolucao`, `diagnostico`

---

## Contrato de resposta

```json
{
  "ok": true,
  "source": "gold_mart|empty",
  "neighborhood": {
    "key": "aldeota",
    "name": "Aldeota",
    "display_name": "Aldeota",
    "city": "Fortaleza"
  },
  "period": { "start": "YYYY-MM-DD", "end": "YYYY-MM-DD" },
  "period_basis": {
    "realized": "data_competencia",
    "source": "mart_compradores_por_geo"
  },
  "summary": { ... },
  "buyers": [...],
  "cnaes": [...],
  "invoices": [...],
  "vendors": [...],
  "timeline": [...],
  "data_quality": { ... },
  "empty_states": { ... },
  "meta": { ... }
}
```

---

## summary — campos

| Campo | Tipo | Descrição |
|---|---|---|
| `neighborhood_key` | string | Chave normalizada |
| `neighborhood_name` | string | Nome de exibição (oficial se disponível) |
| `city` | string | Cidade |
| `revenue` | money | Receita no período |
| `invoices_count` | int | NFs no período |
| `customers_count` | int | Clientes únicos |
| `cnaes_count` | int | CNAEs distintos |
| `avg_ticket` | money | Ticket médio por NF |
| `first_sale_at` | YYYY-MM | Primeira competência com venda |
| `last_sale_at` | YYYY-MM | Última competência com venda |
| `source` | string | `gold_mart` ou `empty` |
| `realized_period_basis` | string | Sempre `data_competencia` |

---

## buyers — campos por item

| Campo | Descrição |
|---|---|
| `codigo_cliente_omie` | ID interno do cliente |
| `customer_name` | Nome do cliente |
| `document_masked` | Documento mascarado |
| `revenue` | Receita do cliente no bairro/período |
| `invoices_count` | Notas do cliente |
| `participation_pct` | % da receita do bairro |
| `bairro_norm` | Bairro normalizado |
| `cidade_norm` | Cidade normalizada |

---

## cnaes — campos por item

| Campo | Descrição |
|---|---|
| `cnae_codigo` | Código CNAE (ou `SEM_CNAE`) |
| `cnae_descricao` | Descrição |
| `customers_count` | Clientes únicos com esse CNAE |
| `revenue` | Receita |
| `invoices_count` | Notas |
| `participation_pct` | % da receita do bairro |

---

## Freshness SLA

| Status | Condição |
|---|---|
| `ok` | Último refresh ≤ 24h |
| `warning` | 24h–72h |
| `critical` | > 72h |
| `unknown` | Sem registro |

---

## empty_states — reason codes

### empty_states.sales

| Código | Condição |
|---|---|
| `sync_stale` | Freshness crítica + sem receita |
| `no_sales_in_period` | Sem receita (sync atual) |

---

## Abas do drawer Geo 360

| Tab | Dados |
|---|---|
| **Resumo** | KPIs (receita, clientes, NFs, ticket, CNAEs), badges source/freshness, warnings |
| **Compradores** | Top clientes do bairro com % participação; botão open-customer |
| **CNAEs** | Breakdown por CNAE; % participação |
| **NFs** | NFs autorizadas com bairro enriquecido; botão open-invoice |
| **Vendedores** | Vendedores com participação; botão open-vendor |
| **Evolução** | Chart.js: receita + clientes por competência |
| **Diagnóstico** | data_quality, period_basis, request_id, generated_at |

---

## Carregamento sob demanda

- Abre o drawer → carrega apenas `tab=resumo`.
- Clicar em cada aba dispara `api/geo_neighborhood_detail.php?tab=<key>` lazily.
- Cache local de 90s por `neighborhood_key + start + end + tab`.

---

## Disparador — drilldown-router

```html
<button data-biomie-action="open-neighborhood"
        data-neighborhood-key="aldeota"
        data-neighborhood-name="Aldeota"
        data-city="Fortaleza">
  Geo 360
</button>
```

O drilldown-router intercepta o clique e chama:
```js
BIomieGeoDetail.open({
  neighborhoodKey: 'aldeota',
  neighborhood: 'Aldeota',
  city: 'Fortaleza',
  start: '...',
  end: '...'
});
```

---

## Botão no painel do mapa

O painel `biomie-map-panel.js` expõe o botão `#geo360Btn` que é:
- Oculto em `setInitial()`
- Preenchido com `data-neighborhood-key` e `data-neighborhood-name` em `renderNeighborhood()`
- Clicável via drilldown-router → abre drawer Geo 360

---

## Índices recomendados

```sql
-- Bairro + competência (acesso principal)
ALTER TABLE mart_compradores_por_geo
  ADD INDEX idx_geo_mart_bairro_comp (bairro_norm, competencia);

-- CNAE + competência
ALTER TABLE mart_compradores_por_geo
  ADD INDEX idx_geo_mart_cnae_comp (cnae_codigo, competencia, bairro_norm);

-- Cliente + competência
ALTER TABLE mart_compradores_por_geo
  ADD INDEX idx_geo_mart_cliente_comp (codigo_cliente_omie, competencia);

-- Bairro normalizado na dim geo
ALTER TABLE biomie_clientes_geo_dim
  ADD INDEX idx_clientes_geo_bairro_norm (bairro_norm);

-- Código cliente na dim geo
ALTER TABLE biomie_clientes_geo_dim
  ADD INDEX idx_clientes_geo_codigo (codigo_cliente_omie);

-- Bairro normalizado na dim oficial
ALTER TABLE biomie_bairros_fortaleza_dim
  ADD INDEX idx_bairros_dim_nome_norm (bairro_nome_normalizado, ativo);
```

Aplicar via `database/migrations/Version20260516000002_GeoMapIndexes.php` (usa `maybeAddIndex` — seguro em produção).

---

## Arquivos criados / modificados na Fase 6

| Arquivo | Tipo | Ação |
|---|---|---|
| `app/PageAssetRegistry.php` | PHP | `'geografia'` carrega todos os drawers + chart.umd.js |
| `app/Modules/Sales/Infrastructure/Geo360ReadRepository.php` | PHP | Novo — queries canônicas e gold para bairro |
| `app/Modules/Sales/Application/Geo/Geo360ReadService.php` | PHP | Novo — orquestração Geo 360 |
| `api/geo_neighborhood_detail.php` | PHP | Novo — endpoint Geo 360 |
| `assets/js/modules/drilldown-router.js` | JS | Adicionado `open-neighborhood` action |
| `assets/js/modules/geo-detail/tabs.js` | JS | Novo — renderizadores de aba |
| `assets/js/modules/geo-detail/charts.js` | JS | Novo — Chart.js para evolução |
| `assets/js/modules/geo-detail/index.js` | JS | Novo — drawer BIomieGeoDetail |
| `assets/js/biomie-map-panel.js` | JS | `renderNeighborhood` preenche `#geo360Btn`; `setInitial` oculta botão |
| `views/mapa_vendas.html` | HTML | Adicionado `#geo360Btn` no painel KPI |
| `database/migrations/Version20260516000002_GeoMapIndexes.php` | PHP | Novo — 6 índices geo |
| `tools/dev/geo_360_debug.php` | PHP | Novo — CLI de diagnóstico |
| `docs/geo-map-contract.md` | Markdown | Este arquivo |

---

## Debug CLI

```bash
php tools/dev/geo_360_debug.php --neighborhood=BAIRRO_NORM [--city=fortaleza] [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--explain]
```

**Alertas automáticos:**
- `warning` se mart tem 0 linhas mas summary retornou receita
- `warning` se sem receita para o bairro no período
- `critical` se freshness > 72h
- `critical` se `period_basis.realized != 'data_competencia'`

## Escopo de Operação (Fase H)

**O mapa geo e todos os drilldowns usam exclusivamente `operation_scope = 'commercial'`.**

- `mart_geo_buyer` é materializado apenas com NFs `commercial`.
- O fragmento `commercialScopeFragment()` no `GeoBuyerMartBuilder` garante que re-materialização pós-H3 limpa remessas do mart.
- Auditoria de contaminação: `php tools/dev/operation_scope_mart_audit.php`
- Ver contrato completo: `docs/omie-operation-scope-contract.md`
