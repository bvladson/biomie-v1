#!/bin/sh
set -u

# Raiz DERIVADA da propria localizacao do script, nao chumbada.
#
# O default aqui era o caminho do HOST ANTIGO. Funcionava so porque
# /etc/cron.d/biomie define BIOMIE_PROJECT_ROOT -- tire a variavel, ou rode o
# script a mao, e ele passa a apontar para um diretorio de OUTRA maquina, em
# silencio. Derivar de $0 esta certo nas duas maquinas e em qualquer release.
PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/daily_integrity.log"
LOCK_FILE="$LOCK_DIR/daily_integrity.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

CHECK_DATE="${BIOMIE_INTEGRITY_DATE:-$(date '+%Y-%m-%d')}"
YESTERDAY="$(date -d "$CHECK_DATE -1 day" '+%Y-%m-%d' 2>/dev/null || date '+%Y-%m-%d')"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] daily_integrity start date=$CHECK_DATE" >> "$LOG_FILE"

run_check() {
  "$PHP_BIN" tools/dev/daily_sales_integrity_check.php --date="$1" --pretty >> "$LOG_FILE" 2>&1
}

if command -v flock >/dev/null 2>&1; then
  (
    flock -n 9 || exit 0
    run_check "$CHECK_DATE"
    code=$?
    if [ "$code" -ne 0 ]; then
      echo "[$(date '+%Y-%m-%d %H:%M:%S')] daily_integrity fallback date=$YESTERDAY" >> "$LOG_FILE"
      run_check "$YESTERDAY"
    fi
    exit "$code"
  ) 9>"$LOCK_FILE"
  code=$?
else
  run_check "$CHECK_DATE"
  code=$?
  if [ "$code" -ne 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] daily_integrity fallback date=$YESTERDAY" >> "$LOG_FILE"
    run_check "$YESTERDAY"
  fi
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] daily_integrity finish exit=$code" >> "$LOG_FILE"
exit "$code"
