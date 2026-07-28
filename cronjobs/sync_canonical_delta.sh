#!/bin/bash
# sync_canonical_delta.sh — Sincronização delta do mês atual
# Uso no hPanel: Cron Job > Type: Custom > Command: /home/uXXXX/domains/magmoveiscorporativos.com.br/public_html/biomie/cronjobs/sync_canonical_delta.sh
DIR="$(cd "$(dirname "$0")/.." && pwd)"
/usr/bin/php -f "$DIR/cron/sync_canonical.php" >> "$DIR/storage/logs/cron_canonical.log" 2>&1
