#!/bin/sh
# finance_future_receivables.sh — FIN.R5.1
#
# Importa contas a receber com vencimento FUTURO (mês corrente → +18 meses)
# para todas as empresas, 1x/dia. Necessário porque o delta de 15 min cobre
# só a janela quente (~2h para trás): parcelas com vencimento em meses à
# frente nunca entram nela — sem este job, "a vencer" fica sempre zerado.
#
# Adicionar no hPanel: 15 3 * * * /bin/sh [caminho_completo]/finance_future_receivables.sh
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/finance_future.log"
LOCK_FILE="$LOCK_DIR/finance_future.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

if [ "${BIOMIE_CA_BUNDLE:-}" != "" ] && [ "${OMIE_CA_BUNDLE:-}" = "" ]; then
  export OMIE_CA_BUNDLE="$BIOMIE_CA_BUNDLE"
fi

START=$(date +%Y-%m-01)
END=$(date -d "+18 months" +%Y-%m-01)

echo "[$(date)] finance_future: janela $START -> $END" >> "$LOG_FILE"

if command -v flock > /dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" cron/sync_all_companies.php --domains=finance --mode=backfill --start="$START" --end="$END" --json >> "$LOG_FILE" 2>&1
else
  "$PHP_BIN" cron/sync_all_companies.php --domains=finance --mode=backfill --start="$START" --end="$END" --json >> "$LOG_FILE" 2>&1
fi

echo "[$(date)] finance_future: fim exit=$?" >> "$LOG_FILE"
