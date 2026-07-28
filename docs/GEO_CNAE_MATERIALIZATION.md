# Geo/CNAE Materialization — BIomie

## Visao geral

Materializa a camada geografica dos clientes em 9 estágios idempotentes,
alimentando os dashboards geo e CNAE a partir de `biomie_clientes_dim`.

## Arquitetura modular (Fase 5G)

```
app/GeoCnaeMaterializationService.php         — fachada; delega para os modulos abaixo
app/Modules/Geo/
  Domain/
    GeoLocation.php                           — value object: uf, cidade, bairro, bairroNorm, bairroOficial
    GeoBuyerAggregate.php                     — value object: dados de comprador por periodo/geo
    CnaeSalesAggregate.php                    — value object: dados de vendas por CNAE
  Application/
    GeoCnaeMaterializationCoordinator.php     — orquestra run() com telemetria e prerequisitos
    GeoCnaeMaterializationRunner.php          — executa os 9 estagios do pipeline
    GeoCnaeMaterializationReport.php          — factory do array de relatorio zerado + helpers
    GeoCnaeMaterializationResult.php          — struct de resultado por execucao
  Infrastructure/
    GeoNormalizer.php                         — wrapper estatico sobre GeoNameNormalizer
    OfficialNeighborhoodResolver.php          — Stage 3: match SQL contra biomie_bairros_fortaleza_dim
    GeoCustomerSourceRepository.php           — queries read-only + pickColumn + dryRunSnapshot
    GeoCnaeMartRepository.php                 — Stages 1, 2, 4, 5, 6: escrita nos marts
    GeoAggregateBuilder.php                   — Stage 7: mart_compradores_por_geo
    CnaeAggregateBuilder.php                  — Stage 8: mart_vendas_por_cnae_cnpj
    GeoCnaeMaterializationLogger.php          — wrapper de Logger com contexto de materializacao
```

## Pipeline de 9 estágios

| Stage | Descricao                                    | Classe responsavel          |
|-------|----------------------------------------------|-----------------------------|
| 1     | Criar skeleton rows em biomie_clientes_geo_dim | GeoCnaeMartRepository     |
| 2     | Normalizar bairro_norm / cidade_norm (SQL)    | GeoCnaeMartRepository       |
| 3     | Match bairro_norm contra bairros oficiais     | OfficialNeighborhoodResolver|
| 4     | Enfileirar sem match em biomie_clientes_para_enriquecer | GeoCnaeMartRepository |
| 5     | Popular biomie_vendas_geo_cliente_fact        | GeoCnaeMartRepository       |
| 6     | Popular biomie_geo_vendas_diaria              | GeoCnaeMartRepository       |
| 7     | Popular mart_compradores_por_geo              | GeoAggregateBuilder         |
| 8     | Popular mart_vendas_por_cnae_cnpj             | CnaeAggregateBuilder        |
| 9     | Relatorio de cobertura + zero_reasons         | GeoCustomerSourceRepository |

Stages 5-8 sao protegidos por verificacao de existencia de tabela (tableHealth)
e executados somente fora de dry_run.

## Métodos públicos da fachada

| Metodo                       | Descrição                                                     |
|------------------------------|---------------------------------------------------------------|
| `run(options, actorUserId)`  | Executa o pipeline completo (dry_run e rebuild suportados)    |
| `dryRunSnapshot()`           | Retorna snapshot de preflight sem gravar nada                 |
| `buildVendasPorCnae()`       | Stage 8 isolado (chamado por CnaeMaterializeCommand diretamente) |
| `pickColumn(table, list)`    | Detecta a primeira coluna existente da lista (schema detection) |

## Tabelas envolvidas

| Tabela                              | Operacao           | Responsavel                  |
|--------------------------------------|--------------------|------------------------------|
| `biomie_clientes_geo_dim`            | INSERT IGNORE + UPDATE | GeoCnaeMartRepository    |
| `biomie_clientes_para_enriquecer`    | INSERT / UPSERT    | GeoCnaeMartRepository        |
| `biomie_vendas_geo_cliente_fact`     | DELETE + INSERT    | GeoCnaeMartRepository        |
| `biomie_geo_vendas_diaria`           | DELETE + INSERT    | GeoCnaeMartRepository        |
| `mart_compradores_por_geo`           | DELETE + INSERT    | GeoAggregateBuilder          |
| `mart_vendas_por_cnae_cnpj`          | DELETE + INSERT    | CnaeAggregateBuilder         |
| `biomie_clientes_dim`                | SELECT (read-only) | GeoCustomerSourceRepository  |
| `biomie_bairros_fortaleza_dim`       | SELECT (read-only) | OfficialNeighborhoodResolver |

## Invariantes de segurança

- `INSERT IGNORE` em `biomie_clientes_geo_dim` garante skeleton row antes de qualquer UPDATE.
- Self-join em `biomie_clientes_para_enriquecer` foi removido (MariaDB error 1052 na coluna `status`).
  ODKU com `UNIQUE KEY uq_queue_cliente` trata duplicatas corretamente.
- `buildVendasPorCnae` retorna 0 imediatamente se coluna `cnae_codigo` nao existir em `biomie_clientes_dim`.
- Stages 5–8 capturados individualmente por try/catch — falha em um stage nao aborta os demais.
- `BAIRROS_EXPECTED = 121` — prerequisito validado no Coordinator antes do pipeline.
- Nenhum sleep entre stages (operacoes sao SQL em batch).

## Detecção dinâmica de colunas

`pickColumn()` e `columnExists()` consultam `information_schema.COLUMNS` por DATABASE().
Usado em Stage 1 (bairro/cidade/uf em clientes_dim), Stage 7 (fallback de bairro/cidade),
Stage 8 (cnae_source, cnae_confidence, updated_at opcionais).

## Execução via Admin Console

```
# Dry run (preflight):
geo:materialize  dry_run=1

# Execucao normal:
geo:materialize

# Rebuild completo (apaga e recria geo_dim):
geo:materialize  rebuild=1

# Apenas CNAE mart:
cnae:materialize
```

## Campos do relatório

- `skeletons_created`        — skeleton rows criadas/contadas
- `geo_fields_normalized`    — registros com bairro_norm preenchido
- `bairros_matched`          — matches contra bairros_fortaleza_dim
- `queued_for_enrichment`    — clientes sem match enfileirados
- `geo_fact_inserted`        — linhas em biomie_vendas_geo_cliente_fact
- `geo_daily_inserted`       — linhas em biomie_geo_vendas_diaria
- `compradores_geo_inserted` — linhas em mart_compradores_por_geo
- `vendas_cnae_inserted`     — linhas em mart_vendas_por_cnae_cnpj
- `coverage`                 — snapshot de cobertura geo_dim
- `zero_reasons`             — diagnosticos para contadores zerados
- `warnings`                 — avisos de stages opcionais que falharam

## Dependências

- `TableHealthService` — verifica existencia e contagem de tabelas
- `Telemetry::global()` — span tracking `geo.materialize` com eventos start/finish
- `GeoNameNormalizer` — normalização PHP-side de bairros e cidades (via GeoNormalizer)

---

## Rebuild de Período Específico (Fase 10C)

Quando `mart_cliente_periodo` não contém dados para o mês corrente (mês aberto), o
`GeoAggregateBuilder` não gera linhas no `mart_compradores_por_geo` e o mapa retorna R$0.

O pipeline padrão (`geo:materialize` via Admin Console) usa o `GeoAggregateBuilder` que
**apaga e recria** toda a tabela a partir de `mart_cliente_periodo`. Se `mart_cliente_periodo`
estiver vazio para o mês corrente, o rebuild padrão não ajuda.

### Por que o mês corrente fica fora

`cron/materialize_competencia_snapshots.php` rejeita meses ainda abertos
(`assertClosedCompetencia`). Portanto a materialização automática de `mart_cliente_periodo`
cobre apenas meses fechados.

### Solução: geo_rebuild_period

Dois caminhos:

**CLI (sysadmin/cron):**
```bash
php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-31
php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-31 --assert --json
```

**API (Admin Console):**
```
POST api/admin_geo_rebuild.php
Permissão: admin.view | settings.manage
Body: start=2026-05-01&end=2026-05-31  (form ou JSON)
Limite: máx. 3 meses por chamada
```

**Comportamento do rebuild de período:**

| Builder | Tabela alvo | Operação | Afeta outros meses? |
|---------|------------|----------|---------------------|
| `CustomerPeriodMartBuilder` | `mart_cliente_periodo` | `ON DUPLICATE KEY UPDATE` | Não |
| `GeoBuyerMartBuilder` | `mart_compradores_por_geo` | `ON DUPLICATE KEY UPDATE` | Não |

Ao contrário do `GeoAggregateBuilder`, estes builders são **não destrutivos**: atualizam
apenas as linhas do período informado, preservando dados de outras competências.

### Diagnóstico antes de decidir qual caminho usar

```bash
# Mostra onde os números somem (L1 canonical → L3 mart_cliente_periodo → L4 geo mart)
php tools/dev/geo_sales_debug.php --start=2026-05-01 --end=2026-05-31

# Verifica mínimos esperados (maio 2026)
php tools/dev/geo_sales_assert_may2026.php
```

### Detecção automática no mapa

Quando o mapa detecta `mart_compradores_por_geo` vazio para o período mas `biomie_notas_fiscais_canonicas`
com NFs autorizadas, `api/geo_summary.php` retorna `diagnostics.motivo_zero = "mart_nao_materializado"`.

O frontend `biomie-map.js` exibe um botão **"Reprocessar período"** que chama
`api/admin_geo_rebuild.php` via POST e recarrega o mapa automaticamente após o rebuild.
