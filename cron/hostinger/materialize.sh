#!/bin/sh
cd /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie || exit 1
/usr/bin/php bin/materialize_marts.php --json >> storage/logs/cron_materialize.log 2>&1
