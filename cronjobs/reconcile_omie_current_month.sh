#!/bin/bash
# reconcile_omie_current_month.sh - Snapshot diario da conciliacao do mes atual
DIR="$(cd "$(dirname "$0")/.." && pwd)"
/usr/bin/php -f "$DIR/cron/reconcile_omie.php" >> "$DIR/storage/logs/cron_canonical.log" 2>&1
