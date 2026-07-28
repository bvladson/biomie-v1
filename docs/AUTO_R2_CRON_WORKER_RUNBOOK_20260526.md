# AUTO.R2 - Runbook de cron e worker Hostinger

Data: 2026-05-26

## Objetivo

Rodar worker, sync Omie e materializacao sem depender de cliques manuais, com logs e lock para evitar overlap.

## Comandos hPanel

Usar modo Custom no hPanel quando possivel:

```bash
*/5 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/worker.sh
*/15 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/sync_commercial.sh
*/15 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/sync_finance.sh
0,30 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/materialize.sh
```

Geo rebuild, diario ou sob demanda:

```bash
15 2 * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/geo_rebuild.sh
```

## Variaveis uteis

Os wrappers aceitam:

- `BIOMIE_PROJECT_ROOT`
- `BIOMIE_PHP_BIN`
- `BIOMIE_CA_BUNDLE` para chamadas Omie quando o PHP nao tem CA padrao. Os wrappers de sync exportam esse valor como `OMIE_CA_BUNDLE` antes de chamar o PHP.
- `BIOMIE_FINANCE_SYNC_DELAY_SECONDS`
- `BIOMIE_GEO_START`
- `BIOMIE_GEO_END`

## Logs

Os wrappers gravam em:

- `storage/logs/cron/worker.log`
- `storage/logs/cron/sync_commercial.log`
- `storage/logs/cron/sync_finance.log`
- `storage/logs/cron/materialize.log`
- `storage/logs/cron/geo_rebuild.log`

Cada execucao registra inicio, fim e exit code.

## Locks

Se `flock` estiver disponivel, cada wrapper usa lock em:

- `storage/locks/worker.lock`
- `storage/locks/sync_commercial.lock`
- `storage/locks/sync_finance.lock`
- `storage/locks/materialize.lock`
- `storage/locks/geo_rebuild.lock`

Os comandos PHP tambem possuem locks internos para evitar execucoes concorrentes.

## Diagnostico rapido

```bash
php tools/dev/automation_runtime_diagnosis.php --pretty --since-hours=24 --include-commands --mask-secrets
php tools/dev/job_failure_report.php --pretty --domain=commercial --status=failed_terminal --limit=20
php tools/dev/system_health_check.php --json
php healthz.php
```

## Recuperacao manual segura

1. Worker:

```bash
php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55 --stop-when-empty --json
```

2. Sync comercial delta:

```bash
php cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json
```

3. Sync financeiro delta:

```bash
php cron/sync_realtime_pipeline.php --domains=finance --mode=delta --json
```

4. Materializacao Gold:

```bash
php bin/materialize_marts.php --json
```

Esse comando tambem recalcula o summary derivado da ponte NF x titulo (`biomie_bridge_nf_titulo_summary`) a partir dos vinculos existentes. Ele nao cria nem altera vinculos de conciliacao; apenas mantem o agregado usado pelo release guard alinhado com os titulos financeiros canonicos atuais.

Nao executar full sync sem diagnostico e janela aprovada.

## Criterio de saude

- OK: fila vazia ou worker recente, sync/marts dentro do SLA.
- WARNING: fila vazia com heartbeat antigo, conflitos geo documentados, wallpaper fallback visual.
- CRITICAL: jobs pendentes com worker stale, sync comercial/financeiro realmente atrasado sem canonical fallback recente, DLQ unresolved, ou erro tecnico quebrando endpoint.
