# AUTO.R1 - Automacao real do pipeline BIomie

Data: 2026-05-26

## Resumo executivo

A fila/worker deixou de estar em CRITICAL depois de uma execucao controlada do worker:

- file queue: 0 pendentes, 0 running, 0 failed_terminal
- DLQ: 0 unresolved
- worker heartbeat: OK apos `php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55 --stop-when-empty --json`
- gold materialization: OK apos `php bin/materialize_marts.php --json`
- system health: CRITICAL -> WARNING, sem checks CRITICAL restantes

O bloqueio real encontrado nao era DLQ: era cadeia CA/SSL ausente para chamadas Omie em ambiente sem `curl.cainfo`/`openssl.cafile`. O erro observado foi:

`SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)`

## Diagnostico antes

Comando:

```bash
php tools/dev/automation_runtime_diagnosis.php --pretty --since-hours=24 --include-commands --mask-secrets
```

Resultado inicial:

- status: CRITICAL
- pending_jobs: 0 no filesystem local
- worker heartbeat: critical/stale antes da execucao manual
- commercial sync: WARNING
- finance sync: CRITICAL
- recent errors: failed_terminal commercial e finance por SSL/CA Omie

## Acoes executadas

1. Criado `tools/dev/automation_runtime_diagnosis.php` read-only.
2. Criado `tools/dev/job_failure_report.php` read-only.
3. Wrappers Hostinger endurecidos:
   - `cron/hostinger/worker.sh`
   - `cron/hostinger/sync_commercial.sh`
   - `cron/hostinger/sync_finance.sh`
   - `cron/hostinger/materialize.sh`
   - `cron/hostinger/geo_rebuild.sh`
4. Wrappers espelho em `tools/cron/*.sh`.
5. `OmieClient` passou a aceitar CA bundle seguro via:
   - `OMIE_CA_BUNDLE`
   - `CURL_CA_BUNDLE`
   - `curl.cainfo`
   - `openssl.cafile`
   - paths Linux comuns de CA
6. Removido ruído runtime de `curl_close()` deprecated no PHP 8.5.
7. Executado worker manual controlado.
8. Executado sync financeiro controlado com CA local temporario.
9. Executada materializacao Gold.
10. O materializador Gold passou a recalcular `biomie_bridge_nf_titulo_summary` a partir dos vinculos existentes em `biomie_bridge_nf_titulo_items`, sem criar, remover ou reclassificar vinculos NF x titulo.

## Observacoes do sync financeiro

O primeiro delta financeiro sem CA configurado falhou por SSL e gerou registro failed_terminal em `biomie_sync_runs`.

Depois, com `OMIE_CA_BUNDLE` local apontando para um CA bundle existente na maquina de desenvolvimento, o cron financeiro de maio executou a etapa de sincronizacao e registrou em log:

- titles checked: 991
- inserted: 602
- unchanged: 389
- divergences: 1
- duration: 284.61s

O processo local ficou preso depois da etapa de escrita, durante a montagem do snapshot pos-sync, e foi encerrado manualmente para nao deixar processo pendurado. A escrita canonica ja havia sido registrada em log antes do encerramento.

## Estado depois

Comando:

```bash
php tools/dev/system_health_check.php --json
```

Resultado:

- overall: WARNING
- worker_queue: OK
- omie_sync_freshness: OK por fallback canonico real (`canonical_fallback`)
- gold_materialization: OK
- geo_detail_consistency: WARNING por 2 nomes humanos conflitantes, sem bug de propagacao
- login_wallpaper: WARNING por SSL Unsplash/fallback visual
- shadow: WARNING esperado

## Ponte NF x titulo

Apos o sync financeiro controlado, o release guard D3 detectou um falso bloqueio em C15: 5 NFs tinham `biomie_bridge_nf_titulo_summary.valor_titulos_total` divergente da soma atual de `biomie_titulos_financeiros_canonicos.valor_liquido`.

Causa: `bin/materialize_marts.php` atualizava os marts Gold, mas nao recalculava o summary da ponte NF x titulo depois de mudancas nos titulos financeiros canonicos.

Correcao aplicada:

- criado `NfTituloBridgeSummaryBuilder`;
- integrado ao `GoldRefreshCoordinator`;
- refresh restrito ao summary derivado;
- nenhum titulo financeiro original alterado;
- nenhuma NF alterada;
- nenhum vinculo NF x titulo criado, removido ou reclassificado.

Validacao:

```bash
php bin/materialize_marts.php --start=2026-05-01 --end=2026-05-31 --json
php tools/dev/reconciliation_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
```

Resultado:

- `bridge_summary`: 576 linhas afetadas pelo refresh idempotente;
- D3/C15: PASS;
- reconciliation_guard: 30/30 PASS.

## Failed terminal commercial backfill

Comando:

```bash
php tools/dev/job_failure_report.php --pretty --domain=commercial --status=failed_terminal --limit=20
```

Classificacao:

- `omie_ssl_connection_error`
- recomendacao: corrigir cadeia CA/SSL antes de redrive
- nao houve redrive automatico
- nao houve delecao/arquivamento automatico

## Pendencias

1. Configurar `OMIE_CA_BUNDLE` no ambiente Hostinger se o PHP nao resolver CA por padrao.
2. Confirmar logs reais em `storage/logs/cron/*.log` apos o hPanel executar os wrappers.
3. Avaliar em fase futura se `cron/sync_finance.php` deve registrar sucesso em `biomie_sync_runs` ou omitir o snapshot pos-sync em modo cron.
