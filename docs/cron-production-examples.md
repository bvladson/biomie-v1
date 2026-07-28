# Exemplos de Cron de Producao

Substitua `/CAMINHO/biomie` pelo diretorio real do projeto e ajuste o binario PHP quando necessario.

## Cron com flock

```cron
*/10 * * * * /usr/bin/flock -n /tmp/biomie-sync-commercial.lock php /CAMINHO/biomie/cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json >> /CAMINHO/biomie/storage/logs/cron-commercial.log 2>&1
*/20 * * * * /usr/bin/flock -n /tmp/biomie-sync-finance.lock php /CAMINHO/biomie/cron/sync_realtime_pipeline.php --domains=finance --mode=delta --json >> /CAMINHO/biomie/storage/logs/cron-finance.log 2>&1
*/5 * * * * /usr/bin/flock -n /tmp/biomie-worker.lock php /CAMINHO/biomie/cron/worker.php --queue=high,default,low --limit=50 --max-runtime=240 --json >> /CAMINHO/biomie/storage/logs/worker.log 2>&1
0 3 * * * /usr/bin/flock -n /tmp/biomie-materialize.lock php /CAMINHO/biomie/bin/materialize_marts.php --json >> /CAMINHO/biomie/storage/logs/materialize.log 2>&1
```

Regras:

- Cada rotina critica usa lock proprio.
- Nao rode dois syncs do mesmo dominio ao mesmo tempo.
- Materializacao Gold deve rodar depois do sync, nao como substituta dele.
- Em hospedagem compartilhada, prefira worker curto via cron.
- Em VPS/dedicado, prefira Supervisor ou systemd.

## Supervisor

```ini
[program:biomie-worker]
command=php /CAMINHO/biomie/cron/worker.php --queue=high,default,low --limit=100 --max-runtime=3600 --sleep=2 --json
directory=/CAMINHO/biomie
autostart=true
autorestart=true
startsecs=5
startretries=3
user=USUARIO_WEB
redirect_stderr=true
stdout_logfile=/CAMINHO/biomie/storage/logs/supervisor-worker.log
stopwaitsecs=30
```

## Fallback Hostinger/Compartilhado

Quando nao houver Supervisor, use o cron do painel com `flock` e `--max-runtime` curto:

```cron
*/5 * * * * /usr/bin/flock -n /tmp/biomie-worker.lock php /CAMINHO/biomie/cron/worker.php --queue=high,default,low --limit=50 --max-runtime=240 --stop-when-empty --json >> /CAMINHO/biomie/storage/logs/worker.log 2>&1
```
