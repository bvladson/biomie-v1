#!/bin/sh
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/worker.log"
LOCK_FILE="$LOCK_DIR/worker.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] worker start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55 --stop-when-empty --json >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55 --stop-when-empty --json >> "$LOG_FILE" 2>&1
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] worker finish exit=$code" >> "$LOG_FILE"
exit "$code"
