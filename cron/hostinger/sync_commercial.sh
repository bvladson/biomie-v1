#!/bin/sh
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/sync_commercial.log"
LOCK_FILE="$LOCK_DIR/sync_commercial.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

if [ "${BIOMIE_CA_BUNDLE:-}" != "" ] && [ "${OMIE_CA_BUNDLE:-}" = "" ]; then
  export OMIE_CA_BUNDLE="$BIOMIE_CA_BUNDLE"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] sync_commercial start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json >> "$LOG_FILE" 2>&1
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] sync_commercial finish exit=$code" >> "$LOG_FILE"
exit "$code"
