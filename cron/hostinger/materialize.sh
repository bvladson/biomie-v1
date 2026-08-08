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
LOG_FILE="$LOG_DIR/materialize.log"
LOCK_FILE="$LOCK_DIR/materialize.lock"
SYNC_COMMERCIAL_LOCK="$LOCK_DIR/sync_commercial.lock"
SYNC_FINANCE_LOCK="$LOCK_DIR/sync_finance.lock"
START_DELAY_SECONDS="${BIOMIE_MATERIALIZE_START_DELAY_SECONDS:-180}"
WAIT_SYNC_SECONDS="${BIOMIE_MATERIALIZE_WAIT_SYNC_SECONDS:-180}"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] materialize start" >> "$LOG_FILE"

if [ "$START_DELAY_SECONDS" -gt 0 ] 2>/dev/null; then
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] materialize delay=${START_DELAY_SECONDS}s before lock check" >> "$LOG_FILE"
  sleep "$START_DELAY_SECONDS"
fi

wait_for_lock_release() {
  lock_file="$1"
  label="$2"
  waited=0

  while ! flock -n "$lock_file" true >/dev/null 2>&1; do
    if [ "$waited" -ge "$WAIT_SYNC_SECONDS" ]; then
      echo "[$(date '+%Y-%m-%d %H:%M:%S')] materialize abort: $label still running after ${WAIT_SYNC_SECONDS}s" >> "$LOG_FILE"
      return 1
    fi

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] materialize waiting for $label lock (${waited}s)" >> "$LOG_FILE"
    sleep 5
    waited=$((waited + 5))
  done

  return 0
}

COMPANIES="${BIOMIE_MATERIALIZE_COMPANIES:-MAG_FILIAL EXPERT UNI_DESIGN ERGOOFFICE MAG_MATRIZ}"

if command -v flock >/dev/null 2>&1; then
  wait_for_lock_release "$SYNC_COMMERCIAL_LOCK" "sync_commercial" || exit 75
  wait_for_lock_release "$SYNC_FINANCE_LOCK" "sync_finance" || exit 75

  # Segura o lock de materializacao pela duracao do loop (libera ao sair).
  exec 9>"$LOCK_FILE"
  if ! flock -n 9; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] materialize skip: lock em uso" >> "$LOG_FILE"
    exit 0
  fi
fi

# bin/materialize_marts.php EXIGE --company; iteramos sobre as empresas ativas
# (era o bug: chamada sem --company saia com exit=2 toda execucao).
code=0
for SLUG in $COMPANIES; do
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] materialize company=$SLUG" >> "$LOG_FILE"
  "$PHP_BIN" bin/materialize_marts.php --company="$SLUG" --json >> "$LOG_FILE" 2>&1
  c=$?
  if [ "$c" -ne 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] materialize WARN company=$SLUG exit=$c" >> "$LOG_FILE"
    code=$c
  fi
done

echo "[$(date '+%Y-%m-%d %H:%M:%S')] materialize finish exit=$code" >> "$LOG_FILE"
exit "$code"
