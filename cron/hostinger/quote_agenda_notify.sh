#!/bin/sh
set -u

# Agenda de Orçamentos — notificações ao gestor.
# Dois modos (o evento vem de --only), agendados em cadências diferentes:
#   BIOMIE_QA_NOTIFY_MODE=digest  → resumo diário, 1x pela manhã (dedup por dia)
#   BIOMIE_QA_NOTIFY_MODE=stale   → alerta de fonte vencida, frequente (cooldown 6h)
# Fonte vencida NUNCA vira cobrança: o digest se auto-suprime nesse caso.

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
# Modo: 1º argumento (uso no cron), senão env, senão digest.
MODE="${1:-${BIOMIE_QA_NOTIFY_MODE:-digest}}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/quote_agenda_notify.log"
LOCK_FILE="$LOCK_DIR/quote_agenda_notify_${MODE}.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] quote_agenda_notify start mode=$MODE" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" bin/quote_agenda_notify.php --all --only="$MODE" --apply >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" bin/quote_agenda_notify.php --all --only="$MODE" --apply >> "$LOG_FILE" 2>&1
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] quote_agenda_notify finish mode=$MODE exit=$code" >> "$LOG_FILE"
exit "$code"
