#!/bin/sh
# Mede se o score territorial ORDENA — nao se ele existe.
#
# SEMANAL, e nao diario, de proposito: o backtest observa o mes seguinte a cada
# competencia de referencia. Rodar todo dia recalcularia exatamente o mesmo
# conjunto de janelas fechadas e gastaria CPU para reproduzir o mesmo numero. A
# resposta so muda quando um mes novo termina.
#
# O venv NAO vive dentro da release (senao cada deploy reinstalaria 200 MB e o
# rollback ficaria sem ambiente) — ele fica em shared/ e e resolvido aqui.
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}"
VENV_PYTHON="${BIOMIE_INTEL_PYTHON:-/var/www/biomie/shared/python-venv/bin/python}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/geo_backtest.log"
LOCK_FILE="$LOCK_DIR/geo_backtest.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1

if [ ! -x "$VENV_PYTHON" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_backtest ABORTADO: venv ausente em $VENV_PYTHON" >> "$LOG_FILE"
    exit 1
fi

cd "$PROJECT_ROOT/services/python" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_backtest start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
    flock -n "$LOCK_FILE" "$VENV_PYTHON" -m biomie_intel geo-backtest --company=all >> "$LOG_FILE" 2>&1
    code=$?
else
    "$VENV_PYTHON" -m biomie_intel geo-backtest --company=all >> "$LOG_FILE" 2>&1
    code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_backtest finish exit=$code" >> "$LOG_FILE"
exit "$code"
