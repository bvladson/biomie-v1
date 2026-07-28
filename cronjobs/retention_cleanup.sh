#!/bin/sh
DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
/usr/bin/php -f "$DIR/cron/retention_cleanup.php" -- --apply --batch-size=1000 --max-batches=100 --json >> "$DIR/storage/logs/cron_retention.log" 2>&1
