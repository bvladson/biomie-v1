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
LOG_FILE="$LOG_DIR/sync_finance.log"
LOCK_FILE="$LOCK_DIR/sync_finance.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

if [ "${BIOMIE_CA_BUNDLE:-}" != "" ] && [ "${OMIE_CA_BUNDLE:-}" = "" ]; then
  export OMIE_CA_BUNDLE="$BIOMIE_CA_BUNDLE"
fi

# Offset operacional: o cron dispara a cada 15 min, mas o financeiro espera
# 7 min para nao rodar exatamente junto do sync comercial.
sleep "${BIOMIE_FINANCE_SYNC_DELAY_SECONDS:-420}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] sync_finance start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" cron/sync_all_companies.php --domains=finance --mode=delta --json >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" cron/sync_all_companies.php --domains=finance --mode=delta --json >> "$LOG_FILE" 2>&1
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] sync_finance finish exit=$code" >> "$LOG_FILE"
exit "$code"
