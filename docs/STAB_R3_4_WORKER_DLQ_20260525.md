# STAB.R3.4 - Worker, fila e dead letters

Data: 2026-05-25
Escopo: diagnostico e estabilizacao operacional de worker/fila/DLQ.
Regras preservadas: sem executar `migrate`, sem alteracao em `database/migrations`, Base Comercial NF, BiVisibilityScope, Licitacao, conciliacao ou FIN.R1/R2.

## Resumo executivo

O health apontava `worker_queue` em `WARNING` por:

- 2 dead letters nao resolvidas;
- heartbeat do worker sem atualizacao recente;
- fila em arquivo sem pendencias.

A fase classificou as duas dead letters antes de qualquer acao. Ambas eram historicas e foram comprovadamente superadas por execucoes posteriores bem-sucedidas. Foi executado archive auditavel apenas em metadados da tabela `biomie_dead_letter_jobs`; nenhum job foi reenfileirado, nenhum dado foi apagado e nenhuma regra comercial foi alterada.

Resultado final do escopo:

| Item | Antes | Depois |
|---|---:|---:|
| jobs queued/running | 0 / 0 | 0 / 0 |
| failed terminal em arquivo | 0 | 0 |
| dead letters nao resolvidas | 2 | 0 |
| redrives executados | 0 | 0 |
| archive metadata updates | 0 | 2 |
| heartbeat worker | stale | stale documentado |

O `worker_queue` passou a reportar `WARNING` com causa explicita: fila vazia e DLQ limpa, mas cron/heartbeat do worker parece parado e precisa confirmacao no Hostinger.

## Fontes reais

| Fonte | Resultado |
|---|---|
| fila ativa | `storage/cache/jobs` via `FileJobQueue` |
| tabela de fila DB | `biomie_jobs` existe, sem registros |
| tabela DLQ | `biomie_dead_letter_jobs` |
| tabela heartbeat | `biomie_worker_heartbeats` |
| worker real | `cron/worker.php` |
| script Hostinger | `cron/hostinger/worker.sh` |
| cron esperado | `*/5 * * * * php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=240 --stop-when-empty --json` |

Observacao: a entrada real do painel/cron Hostinger nao foi verificada localmente. A evidencia indireta e o ultimo heartbeat em producao.

## Tooling criado

### Relatorio read-only

```bash
php tools/dev/dead_letter_report.php --pretty
php tools/dev/dead_letter_report.php --json
```

Responsabilidades:

- mapear fila em arquivo;
- mapear `biomie_jobs`;
- mapear `biomie_dead_letter_jobs`;
- mapear `biomie_worker_heartbeats`;
- mascarar payloads;
- classificar DLQs conhecidas;
- indicar decisao segura.

### Archive/redrive auditavel

```bash
php tools/dev/dead_letter_redrive.php --archive --all-archiveable --reason="..." --json
php tools/dev/dead_letter_redrive.php --archive --all-archiveable --apply --reason="..." --json
```

Protecoes:

- `--dry-run` e o padrao sem `--apply`;
- `--reason` obrigatorio;
- archive permitido somente para classificacao `archive_with_reason`;
- redrive separado de archive;
- log JSON em `storage/logs`;
- payload mascarado;
- nenhum delete.

## Dead letters classificadas

| id | job_type | failed_at | erro | classificacao | decisao |
|---:|---|---|---|---|---|
| 4 | `omie.delta.sync` | 2026-05-21 01:05:08 | `Sync delta Omie falhou.` | `legacy_delta_superseded_by_later_success` | archive com motivo, sem redrive |
| 1 | `commercial.materialize` | 2026-05-18 19:35:02 | `Call to undefined method BIomie\GoldMartBuilder::getLastStage()` | `legacy_code_bug_superseded` | archive com motivo, sem redrive |

### DLQ 4 - `omie.delta.sync`

Payload seguro:

```json
{"actor_user_id":1,"domains":["commercial","finance"],"run_id":"e0602b64-cb36-3776-5b43-e8b59988983e"}
```

Evidencia:

- dominios do payload: `commercial`, `finance`;
- apos a falha, foram encontrados syncs delta bem-sucedidos:
  - `commercial`: 353 execucoes;
  - `finance`: 179 execucoes.

Decisao: nao redrive. Arquivar metadado como superseded por execucoes posteriores.

### DLQ 1 - `commercial.materialize`

Payload seguro:

```json
{"actor_user_id":1,"end":null,"start":null}
```

Evidencia:

- erro raiz era legado: `GoldMartBuilder` ainda nao expunha `getLastStage()`;
- o codigo atual possui `getLastStage()` e demais accessors de debug;
- apos a falha, foram encontradas 304 materializacoes `gold` bem-sucedidas.

Decisao: nao redrive. Arquivar metadado como superseded por materializacoes posteriores.

## Comandos executados

Diagnostico:

```bash
php tools/dev/worker_queue_debug.php --failed --json
php tools/dev/omie_sync_status.php --json
php tools/dev/dead_letter_report.php --pretty
php tools/dev/dead_letter_report.php --json
```

Dry-run:

```bash
php tools/dev/dead_letter_redrive.php --archive --all-archiveable --reason="STAB.R3.4 dry-run: arquivar DLQs legadas superseded por execucoes posteriores" --json
```

Resultado:

- planned_rows = 2;
- updates_executed = 0;
- jobs_requeued = 0;
- log: `storage/logs/dead_letter_archive_20260525_183533.json`.

Apply:

```bash
php tools/dev/dead_letter_redrive.php --archive --all-archiveable --apply --reason="STAB.R3.4: arquivar DLQs legadas superseded por execucoes posteriores verificadas" --json
```

Resultado:

- planned_rows = 2;
- updates_executed = 2;
- jobs_requeued = 0;
- log: `storage/logs/dead_letter_archive_20260525_183558.json`.

## Worker heartbeat

Ultimo heartbeat:

| Campo | Valor |
|---|---|
| worker_id | `br-asc-web1659.main-hosting.eu:3259288:2618ad7e` |
| queues | `high,default,low` |
| started_at | 2026-05-24 18:35:01 |
| last_heartbeat_at | 2026-05-24 18:35:01 |
| status | `stopped` |
| processed_jobs | 0 |
| failed_jobs | 0 |

Classificacao: `cron_stale_after_clean_stop`.

Interpretacao:

- o worker encerrou limpo com fila vazia;
- nao ha job pendente preso;
- o heartbeat esta antigo para um cron esperado a cada 5 minutos;
- a entrada real do cron Hostinger precisa ser confirmada no painel/SSH.

Correcao no health:

- antes, a DLQ dominava o `worker_queue` warning;
- depois do archive, o check nao volta a `OK` silencioso se o heartbeat estiver stale;
- agora o health aponta: `Worker sem heartbeat recente; fila vazia, mas o cron pode estar parado.`

## Decisoes

- As duas DLQs foram arquivadas com motivo auditavel.
- Nenhum redrive foi executado.
- Nenhuma dead letter foi apagada.
- Nenhum dado de negocio foi alterado.
- O heartbeat stale ficou documentado como pendencia operacional de cron.

## Validacoes

Resultados registrados:

| Comando | Resultado |
|---|---|
| `php -l tools/dev/dead_letter_report.php` | PASS |
| `php -l tools/dev/dead_letter_redrive.php` | PASS |
| `php -l app/SystemHealth/Checks/WorkerQueueHealthCheck.php` | PASS |
| `php tools/dev/dead_letter_report.php --pretty` | PASS; DLQ nao resolvida = 0 |
| `php tools/dev/system_health_check.php --check worker_queue --json` | WARNING com causa explicita de heartbeat stale |

Validacoes finais da fase:

| Comando | Resultado |
|---|---|
| `php tools/dev/lint_all.php` | PASS; 465 arquivos |
| `php bin/schema_migrate.php verify` | PASS; 38 verificadas, 0 mismatches |
| `php bin/schema_migrate.php status` | PASS; 38 descobertas, 38 aplicadas, 0 pendentes |
| `php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty` | PASS geral; 9/9 dominios |
| `php healthz.php` | PASS; `ok=true`, DB conectado |
| `php tools/dev/system_health_check.php --check worker_queue --json` | WARNING; DLQ = 0, heartbeat stale |
| `php tools/dev/system_health_check.php --json` | CRITICAL geral por causas fora do escopo desta fase |

## Nota de auditoria - estado de migrations

Esta fase nao executou `migrate`.

O estado atual de migrations mudou em relacao ao acompanhamento iniciado na STAB.R3.1: `php bin/schema_migrate.php status` mostra 38 migrations descobertas, 38 aplicadas e 0 pendentes. As quatro migrations de 2026-05-22 aparecem aplicadas em 2026-05-25 11:37:40, antes da execucao desta trilha de worker/DLQ.

O estado atual esta saudavel porque `php bin/schema_migrate.php verify` passou com 38 verificadas e 0 mismatches. A observacao fica registrada aqui apenas para preservar o rastro temporal: a STAB.R3.4 nao foi a fase que aplicou essas migrations.

## Pendencias fora do escopo

O health geral continua `CRITICAL`, mas nao por DLQ:

- `jobs`: ultimo `gold_mart_refresh` acima do SLA de 60 minutos;
- `geo_detail_consistency`: 2 `bairro_key` com nomes humanos conflitantes;
- `login_wallpaper`: cache ainda nao chamado neste ciclo.

Esses itens nao foram alterados nesta fase para manter o escopo em worker/fila/DLQ.
