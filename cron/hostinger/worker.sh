#!/bin/sh
cd /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie || exit 1
/usr/bin/php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=240 --stop-when-empty --json >> storage/logs/cron_worker.log 2>&1
