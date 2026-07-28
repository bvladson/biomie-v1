# STAB.R3.5 - Health Final e remanescentes operacionais

Data: 2026-05-25
Escopo: reduzir `system_health_check` geral de `CRITICAL` para `WARNING/OK` sem alterar regra comercial.
Regras preservadas: sem alteracao em Base Comercial NF, BiVisibilityScope, Licitacao, conciliacao, FIN.R1/R2 ou regra financeira.

## Resumo executivo

Estado inicial da fase:

- `system_health_check.php --json`: `CRITICAL`;
- `jobs`: `CRITICAL` por `gold_mart_refresh` fora do SLA;
- `geo_detail_consistency`: `CRITICAL` por 2 conflitos de nomes humanos em `bairro_key`;
- `login_wallpaper`: `UNKNOWN` com severidade `critical` por cache ausente;
- `worker_queue`: `WARNING` por heartbeat/cron stale, com fila e DLQ ja limpas na STAB.R3.4.

Estado final:

- `system_health_check.php --json`: `WARNING`;
- `jobs`: `OK`;
- `freshness`: `OK`;
- `geo_detail_consistency`: `OK`;
- `login_wallpaper`: `WARNING` visual por fallback local (`curl_errno:60`), sem impacto em autenticacao;
- `worker_queue`: `WARNING` mantido por heartbeat/cron stale, com causa explicita.

## Nota de auditoria - migrations

Esta fase nao executou `migrate`.

O estado atual mostra 38 migrations descobertas, 38 aplicadas e 0 pendentes. As quatro migrations de 2026-05-22 aparecem aplicadas em 2026-05-25 11:37:40, antes das trilhas STAB.R3.4/R3.5. `schema_migrate verify` passa com 38 verificadas e 0 mismatches.

## Diagnostico inicial

Comandos:

```bash
php tools/dev/system_health_check.php --json
php healthz.php
```

Checks relevantes:

| Check | Status inicial | Causa provavel | Acionavel | Impacto usuario |
|---|---|---|---|---|
| `jobs` | CRITICAL | ultimo `gold_mart_refresh` com ~423 min, SLA 60 min | sim | dados analiticos podem ficar stale |
| `geo_detail_consistency` | CRITICAL | `boa_vista_castelao` e `sapiranga_coite` voltaram como variantes de nome humano | sim | mapa geo com alerta tecnico |
| `login_wallpaper` | UNKNOWN/critical | cache ausente; endpoint ainda nao aquecido neste ciclo | sim | visual do login, nao autenticacao |
| `worker_queue` | WARNING | heartbeat stale apos parada limpa, fila vazia | sim | operacional/cron |
| `gold_materialization` | WARNING | Gold atualizado sobre origem canonica com atraso | sim | freshness/monitoramento |
| `shadow` | WARNING | amostra baixa finance-only ja documentada na R3.3 | sim | monitoramento |

`healthz.php` permaneceu `ok=true`.

## Gold / SLA gold_mart_refresh

Dry-run:

```bash
php bin/materialize_marts.php --start=2026-05-01 --end=2026-05-31 --dry-run
```

Apply:

```bash
php bin/materialize_marts.php --start=2026-05-01 --end=2026-05-31
```

Resultado:

| Mart | Linhas |
|---|---:|
| bridge | 66 |
| comercial | 66 |
| fiscal | 424 |
| financeiro | 1158 |
| vendedor_dia | 2 |
| compradores_geo | 172 |
| vendas_cnae | 170 |
| cliente_periodo | 243 |
| produto_periodo | 162 |

`source_freshness.status=warning` por `last_canonical_at=2026-05-24 05:00:12`, mas a materializacao concluiu com `status=pronto`.

Depois:

```bash
php tools/dev/system_health_check.php --check jobs --json
```

Resultado: `OK`, `runs_last_24h=7`, `failures_last_24h=0`.

Tambem foi ajustado `app/SystemHealth/Checks/JobsHealthCheck.php` para truncar idade negativa em `0 min` quando o timestamp do banco estiver em fuso adiantado em relacao ao PHP local.

## Geo conflicts

Dry-run antes do apply:

```bash
php tools/dev/geo_keys_backfill.php --dry-run --pretty
```

Resultado:

| Metrica | Valor |
|---|---:|
| eligible_rows | 44 |
| eligible_pairs | 40 |
| display_name_conflicts | 2 |
| display_names_to_update | 8 |
| blocked_conflicts | 0 |
| missing_key_with_revenue | 0 |
| affected_revenue | R$ 0,00 |

Conflitos:

| bairro_key | Variantes | Decisao |
|---|---|---|
| `boa_vista_castelao` | `boa vista castelao`; `Boa Vista/Castelao` | mapear para nome canonico |
| `sapiranga_coite` | `sapiranga coite`; `Sapiranga/Coite` | mapear para nome canonico |

Apply:

```bash
php tools/dev/geo_keys_backfill.php --apply --pretty
```

Log:

`storage/logs/geo_keys_backfill_apply_20260525_184249.json`

Resultado:

- `updated_rows=44`;
- `updates_executed=52`;
- `display_names_updated=8`;
- `blocked_conflicts=0`;
- `missing_key_with_revenue=0`;
- `affected_revenue=R$ 0,00`.

Dry-run final:

- `eligible_rows=0`;
- `display_name_conflicts=0`;
- `display_names_to_update=0`;
- `blocked_conflicts=0`.

Depois:

```bash
php tools/dev/system_health_check.php --check geo_detail_consistency --json
```

Resultado: `OK`, 182 bairros com faturamento, `multiple_names_per_key=0`.

## Login wallpaper cache

Diagnostico:

```bash
php tools/dev/login_wallpaper_debug.php --json
```

Resultado:

- `UNSPLASH_ACCESS_KEY`: presente;
- cache: ausente.

Fetch:

```bash
php tools/dev/login_wallpaper_debug.php --fetch --json
```

Resultado:

- cache criado;
- `source=fallback`;
- ultimo erro: `curl_errno:60 [api.unsplash.com]`;
- impacto: visual do login; autenticacao nao afetada.

Correcoes de observabilidade:

- `app/Services/LoginUnsplashWallpaperService.php`: evita chamada deprecated `curl_close()` no PHP 8.5.
- `app/SystemHealth/Checks/LoginWallpaperHealthCheck.php`: reescrito em ASCII, carrega config local Unsplash como o endpoint e classifica cache ausente como `WARNING` visual, nao `UNKNOWN` critical.

Depois:

```bash
php tools/dev/system_health_check.php --check login_wallpaper --json
```

Resultado: `WARNING`, fallback local com `auth_impact=none`.

## Worker heartbeat / cron

Estado:

- fila ativa: `queued=0`, `running=0`, `failed_terminal=0`;
- DLQ nao resolvida: `0`;
- heartbeat segue stale apos parada limpa;
- cron real da Hostinger nao foi confirmado localmente.

Decisao: manter `WARNING`. Nao transformar em `OK` sem confirmar agendamento real do worker.

## Resultado do health final

```bash
php tools/dev/system_health_check.php --json
```

Resultado final: `WARNING`.

Warnings restantes e motivo:

| Check | Status | Motivo |
|---|---|---|
| `omie_sync_freshness` | WARNING | sync Omie comercial/financeiro acima do ideal |
| `worker_queue` | WARNING | heartbeat/cron stale, fila vazia e DLQ zerada |
| `gold_materialization` | WARNING | Gold fresco, mas origem canonica Omie em warning |
| `shadow` | WARNING | amostra baixa finance-only ja documentada |
| `login_wallpaper` | WARNING | fallback local por `curl_errno:60`, sem impacto em auth |

## Validacoes finais

| Comando | Resultado |
|---|---|
| `php -l app/Services/LoginUnsplashWallpaperService.php` | PASS |
| `php -l app/SystemHealth/Checks/LoginWallpaperHealthCheck.php` | PASS |
| `php -l app/SystemHealth/Checks/JobsHealthCheck.php` | PASS |
| `php tools/dev/geo_keys_backfill.php --dry-run --pretty` | PASS; 0 elegiveis, 0 conflitos |
| `php tools/dev/system_health_check.php --json` | WARNING; sem CRITICAL |
| `php tools/dev/lint_all.php` | PASS; 465 arquivos, 0 falhas |
| `php bin/schema_migrate.php verify` | PASS; 38 verificadas, 0 mismatches |
| `php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty` | PASS geral; 9/9 dominios |
| `php healthz.php` | PASS; `ok=true` |

Observacao: `system_health_check.php --json` permanece com exit code de alerta porque o estado final e `WARNING`, nao `OK`. Nao ha checks `CRITICAL` restantes nesta fase.
