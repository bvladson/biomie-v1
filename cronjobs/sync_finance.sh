#!/bin/bash
# sync_finance.sh — Sincronização financeira
DIR="$(cd "$(dirname "$0")/.." && pwd)"
/usr/bin/php -f "$DIR/cron/sync_finance.php" >> "$DIR/storage/logs/cron_finance.log" 2>&1
