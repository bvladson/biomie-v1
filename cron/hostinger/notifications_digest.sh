#!/bin/sh
set -u

# Digest de notificações (webhooks + sync) a cada 6h → Telegram + sininho.
# Cron (hPanel, Custom): 0 */6 * * * /bin/sh /home/.../biomie/cron/hostinger/notifications_digest.sh

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/notifications_digest.log"
LOCK_FILE="$LOCK_DIR/notifications_digest.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] notifications_digest start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" bin/notifications_digest.php --hours=6 >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" bin/notifications_digest.php --hours=6 >> "$LOG_FILE" 2>&1
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] notifications_digest finish exit=$code" >> "$LOG_FILE"
exit "$code"
