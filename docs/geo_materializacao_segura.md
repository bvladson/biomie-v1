# Materializacao geografica segura

## Objetivo

A Fase 4 torna o rebuild geografico incremental, transacional e limitado ao periodo solicitado. O mapa continua lendo apenas dados locais/materializados e nunca chama Omie em tempo real.

## DELETE global removido

O caminho antigo em `app/Modules/Geo/Infrastructure/GeoAggregateBuilder.php` executava:

```sql
DELETE FROM mart_compradores_por_geo
```

Esse comportamento foi bloqueado. O metodo antigo agora exige periodo explicito, e o novo caminho usa:

```sql
DELETE FROM mart_compradores_por_geo
WHERE competencia BETWEEN :start_comp AND :end_comp
```

Assim, reprocessar maio/2026 nao apaga janeiro, fevereiro, marco ou abril.

## Janela efetiva

`app/DataPlatform/Gold/MartRefreshWindow.php` calcula:

- periodo solicitado;
- periodo efetivo;
- competencias afetadas;
- modo `competencia` ou `open_month`;
- se o periodo e parcial.

Periodo parcial historico e recusado por padrao. Use `--force-full-month` para expandir para competencias completas. Para mes corrente, use `--allow-open-month`.

## Servico central

`app/DataPlatform/Gold/GeoPeriodRebuildService.php` e o caminho oficial para rebuild geografico por periodo.

Ele e usado por:

- `cron/geo_rebuild_period.php`;
- `app/AdminConsole/Commands/GeoRebuildPeriodCommand.php`;
- `api/admin_geo_rebuild.php`;
- job `geo.materialize`.

O servico:

- usa `CronLock`;
- abre transacao;
- deleta somente competencias afetadas;
- reconstrui `mart_cliente_periodo`;
- reconstrui `mart_compradores_por_geo`;
- faz commit;
- faz rollback em falha;
- registra pipeline quando `biomie_pipeline_jobs` existe;
- retorna before/after/deleted/inserted/assertions.

## Fonte de faturamento

Para o mart geografico moderno, a fonte e `biomie_notas_fiscais_canonicas` com:

- `status_fiscal = 'autorizada'`;
- data `data_competencia`;
- faturamento por `valor_total`.

`mart_cliente_periodo.valor_nfe` tambem e alimentado por `valor_total` no builder fiscal. O diagnostico de rebuild mostra tambem `valor_mercadorias` para auditoria, mas a Fase 4 nao troca regra comercial.

## Como rodar pelo cron

Dry-run:

```bash
php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-31 --dry-run --json
```

Rebuild com assert:

```bash
php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-31 --assert --json
```

Mes corrente parcial:

```bash
php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-13 --allow-open-month --assert
```

Historico parcial expandido para competencia cheia:

```bash
php cron/geo_rebuild_period.php --start=2026-04-10 --end=2026-04-20 --force-full-month --assert
```

Exit codes:

- `0`: OK;
- `1`: falha;
- `2`: lock em uso.

## AdminConsole

Use:

```text
geo:rebuild-period start=2026-05-01 end=2026-05-31 assert=1
geo:rebuild-period start=2026-05-01 end=2026-05-31 dry_run=1
```

`geo:materialize` continua existindo para o pipeline Geo/CNAE, mas o stage de `mart_compradores_por_geo` so roda com `start` e `end`. Sem periodo, ele pula esse stage para evitar rebuild global.

## Endpoint admin

`api/admin_geo_rebuild.php` valida permissao, CSRF, periodo e chama `GeoPeriodRebuildService`.

O endpoint aceita `start`, `end`, `assert`, `dry_run`, `force_full_month` e `allow_open_month`.

Para chamadas web longas, prefira `api/geo_materialize.php`, que enfileira job `geo.materialize` e retorna `202 Accepted`.

## Motivos zero

Motivos oficiais:

- `periodo_sem_vendas`;
- `canonical_sem_nfs`;
- `mart_cliente_periodo_nao_materializado`;
- `mart_compradores_geo_nao_materializado`;
- `geo_sem_bairro`;
- `geo_sem_match`;
- `materializacao_desatualizada`;
- `erro_materializacao`.

Mensagens operacionais:

- Canonical com dados e `mart_cliente_periodo` vazio: execute rebuild de `mart_cliente_periodo` para o periodo.
- `mart_cliente_periodo` com dados e geo mart vazio: execute rebuild geografico para o periodo.
- Geo mart com dados e mapa sem pintura: rode `geo_map_match_audit.php`.
- Sync Omie stale: execute sync Omie/canonical antes da materializacao.

## Validacao

Assert:

```bash
php tools/dev/geo_rebuild_assert.php --start=2026-05-01 --end=2026-05-31 --json
```

Diff/snapshot:

```bash
php tools/dev/geo_rebuild_diff.php --start=2026-05-01 --end=2026-05-31 --json
```

Explain:

```bash
php tools/dev/geo_materialization_explain.php --start=2026-05-01 --end=2026-05-31
```

Auditoria de match:

```bash
php tools/dev/geo_map_match_audit.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza --json
```

## Tabelas afetadas

Afetadas pelo rebuild por periodo:

- `mart_cliente_periodo`;
- `mart_compradores_por_geo`;
- `biomie_pipeline_jobs`, quando disponivel, apenas para status.

Nao devem ser afetadas:

- bronze/raw Omie;
- tabelas canonicas;
- dimensoes de clientes/bairros;
- historico fora das competencias solicitadas.

## Proximos passos

Fase 5 deve consolidar painel operacional pequeno para saneamento geografico, revisar SLAs de freshness e evoluir a fila de correcoes sugeridas sem aplicar fuzzy matching automatico em producao.
