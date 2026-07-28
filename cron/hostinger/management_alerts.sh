#!/bin/sh
set -u

# Alertas de negocio (diretoria) via Telegram/webhook.
# Cron (hPanel, Custom):
#   0 11 * * *        /bin/sh /home/.../biomie/cron/hostinger/management_alerts.sh digest
#   0 12,17,21 * * *  /bin/sh /home/.../biomie/cron/hostinger/management_alerts.sh events

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/management_alerts.log"

MODE="${1:-events}"
case "$MODE" in
  digest) FLAG="--digest" ;;
  *)      FLAG="--events" ;;
esac
LOCK_FILE="$LOCK_DIR/management_alerts_${MODE}.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] management_alerts $MODE start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" bin/management_alerts.php "$FLAG" >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" bin/management_alerts.php "$FLAG" >> "$LOG_FILE" 2>&1
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] management_alerts $MODE finish exit=$code" >> "$LOG_FILE"
exit "$code"
