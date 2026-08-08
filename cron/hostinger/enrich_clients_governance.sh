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
LOG_FILE="$LOG_DIR/enrich_clients_governance.log"
LOCK_FILE="$LOCK_DIR/enrich_clients_governance.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] enrich_clients_governance start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" bin/enrich_clients_governance.php >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" bin/enrich_clients_governance.php >> "$LOG_FILE" 2>&1
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] enrich_clients_governance finish exit=$code" >> "$LOG_FILE"
exit "$code"
