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
LOG_FILE="$LOG_DIR/geo_rebuild.log"
LOCK_FILE="$LOCK_DIR/geo_rebuild.lock"
START_DATE="${BIOMIE_GEO_START:-$(date '+%Y-%m-01')}"
END_DATE="${BIOMIE_GEO_END:-$(date '+%Y-%m-%d')}"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_rebuild start start=$START_DATE end=$END_DATE" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" cron/geo_rebuild_period.php --start="$START_DATE" --end="$END_DATE" --assert --allow-open-month --json >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" cron/geo_rebuild_period.php --start="$START_DATE" --end="$END_DATE" --assert --allow-open-month --json >> "$LOG_FILE" 2>&1
  code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_rebuild finish exit=$code" >> "$LOG_FILE"
exit "$code"
