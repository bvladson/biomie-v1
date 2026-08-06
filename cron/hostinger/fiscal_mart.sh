#!/bin/sh
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
PYTHON_BIN="${BIOMIE_PYTHON_BIN:-/var/www/biomie/shared/python-venv/bin/python}"
ENV_FILE="${BIOMIE_INTEL_ENV:-/etc/biomie/intelligence.env}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/fiscal_mart.log"
LOCK_FILE="$LOCK_DIR/fiscal_mart.lock"
mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

run_all() {
  result=0
  for slug in MAG_FILIAL EXPERT UNI_DESIGN MAG_MATRIZ ERGOOFFICE; do
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] fiscal_mart company=$slug" >> "$LOG_FILE"
    "$PHP_BIN" bin/fiscal_build_mart.php --company="$slug" >> "$LOG_FILE" 2>&1 || result=$?
    if [ "$result" -eq 0 ]; then
      "$PHP_BIN" bin/fiscal_persist_projection.php --company="$slug" >> "$LOG_FILE" 2>&1 || result=$?
    fi
  done
  if [ "$result" -eq 0 ]; then
    set -a
    . "$ENV_FILE"
    set +a
    (cd "$PROJECT_ROOT/services/python" && "$PYTHON_BIN" -m biomie_intel fiscal-coherence --company=all) >> "$LOG_FILE" 2>&1 || result=$?
  fi
  return "$result"
}

echo "[$(date '+%Y-%m-%d %H:%M:%S')] fiscal_mart start" >> "$LOG_FILE"
if command -v flock >/dev/null 2>&1; then
  ( flock -n 9 || exit 75; run_all ) 9>"$LOCK_FILE"
  code=$?
else
  run_all; code=$?
fi
echo "[$(date '+%Y-%m-%d %H:%M:%S')] fiscal_mart finish exit=$code" >> "$LOG_FILE"
exit "$code"
