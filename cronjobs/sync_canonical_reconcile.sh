#!/bin/bash
# sync_canonical_reconcile.sh — Reconciliação dos últimos 60 dias
# Uso no hPanel: Cron Job > Type: Custom > Daily 3am UTC
DIR="$(cd "$(dirname "$0")/.." && pwd)"
/usr/bin/php -f "$DIR/cron/sync_canonical.php" -- --reconcile 60 >> "$DIR/storage/logs/cron_canonical.log" 2>&1
