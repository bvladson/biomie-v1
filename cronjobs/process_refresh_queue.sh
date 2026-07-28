#!/bin/bash
# process_refresh_queue.sh — Processar fila de refresh
DIR="$(cd "$(dirname "$0")/.." && pwd)"
/usr/bin/php -f "$DIR/cron/process_refresh_queue.php" -- 5 >> "$DIR/storage/logs/cron_queue.log" 2>&1
