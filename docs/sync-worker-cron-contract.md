# Contrato Sync, Worker e Gold

Este projeto separa explicitamente quatro etapas operacionais:

1. Sync Omie: busca delta/reconcile e grava bruto/canonico local.
2. Worker: processa jobs assincronos enfileirados pela interface.
3. Materializacao Gold: recalcula marts com os dados locais disponiveis.
4. Freshness: informa se sync, canonico, fila e Gold estao recentes.

Regras de operacao:

- GET de pagina/status nao chama sync pesado nem API Omie.
- Botoes web agendam job quando a operacao pode demorar.
- `api/omie_delta_sync.php` agenda `omie.delta.sync` e requer worker ativo.
- `api/sync.php` atualiza visao local/snapshot; nao deve ser rotulado como sync Omie.
- Gold atualizado nao significa Omie atualizado.
- Se Gold estiver recente e canonico stale, mostrar: `Gold atualizado com dados locais, mas origem Omie/canonica esta desatualizada.`
- Toda falha publica deve ser sanitizada e conter `request_id`/`job_id`/`run_id` quando existir.

Freshness oficial:

- `ok`: ate 24h.
- `warning`: entre 24h e 72h.
- `critical`: acima de 72h.
- `unknown`: sem registro confiavel.

Comandos manuais seguros:

```bash
php cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json
php cron/sync_realtime_pipeline.php --domains=finance --mode=delta --json
php cron/sync_realtime_pipeline.php --domains=commercial,fiscal --mode=reconcile --reconcile=7 --json
php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=120 --json
php bin/materialize_marts.php --json
php tools/dev/sync_pipeline_debug.php --domain=all --json
php tools/dev/worker_queue_debug.php --failed --json
php tools/dev/omie_freshness_debug.php --json
```

Pipeline diario recomendado:

1. Rodar sync delta comercial/fiscal/financeiro.
2. Processar fila/worker.
3. Rodar reconcile curto para ultimos 7 dias.
4. Materializar Gold.
5. Conferir System Health/freshness.

Pipeline de recuperacao:

1. Pausar materializacao automatica se a origem estiver stale.
2. Rodar reconcile da janela afetada.
3. Validar canonico com `tools/dev/omie_freshness_debug.php`.
4. Materializar Gold.
5. Rodar `tools/dev/sync_pipeline_debug.php`.
6. Liberar dashboards.
