#!/bin/sh
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/compute_opportunities.log"
LOCK_FILE="$LOCK_DIR/compute_opportunities.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] compute_opportunities start" >> "$LOG_FILE"

run_all_companies() {
  result=0
  for slug in MAG_FILIAL EXPERT UNI_DESIGN MAG_MATRIZ ERGOOFFICE; do
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] compute_opportunities company=$slug" >> "$LOG_FILE"
    "$PHP_BIN" bin/compute_opportunities.php --company="$slug" >> "$LOG_FILE" 2>&1 || result=$?
  done
  return "$result"
}

if command -v flock >/dev/null 2>&1; then
  (
    flock -n 9 || exit 75
    run_all_companies
  ) 9>"$LOCK_FILE"
  code=$?
else
  run_all_companies
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] compute_opportunities finish exit=$code" >> "$LOG_FILE"
exit "$code"
