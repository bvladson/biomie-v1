#!/bin/sh
cd /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie || exit 1
/usr/bin/php cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json >> storage/logs/cron_sync_commercial.log 2>&1
