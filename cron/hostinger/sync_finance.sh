#!/bin/sh
cd /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie || exit 1

# Offset operacional: o cron dispara a cada 15 min, mas o financeiro espera 7 min
# para nao rodar exatamente junto do sync comercial.
sleep 420

/usr/bin/php cron/sync_realtime_pipeline.php --domains=finance --mode=delta --json >> storage/logs/cron_sync_finance.log 2>&1
