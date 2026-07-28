#!/bin/sh
set -u

# Agenda de Orçamentos — snapshot vivo do Omie (etapa=10) por empresa.
# Mantém biomie_quote_live_snapshot_sync fresco; a tela NUNCA consulta o Omie.
# Cadência sugerida: a cada 15 min, deslocada do sync_commercial (0,15,30,45)
# para reduzir contenção de lock. O scanner já espera o lock por até 120s.

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/quote_agenda_scan.log"
LOCK_FILE="$LOCK_DIR/quote_agenda_scan.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

if [ "${BIOMIE_CA_BUNDLE:-}" != "" ] && [ "${OMIE_CA_BUNDLE:-}" = "" ]; then
  export OMIE_CA_BUNDLE="$BIOMIE_CA_BUNDLE"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] quote_agenda_scan start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" bin/quote_agenda_scan.php --all --apply >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" bin/quote_agenda_scan.php --all --apply >> "$LOG_FILE" 2>&1
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] quote_agenda_scan finish exit=$code" >> "$LOG_FILE"
exit "$code"
