#!/bin/sh
# Previsao mensal por empresa, canal e vendedor — PERSISTIDA.
#
# Roda uma vez por dia, de madrugada, depois do sync comercial: a previsao do
# dia precisa enxergar as notas do dia anterior ja canonicas. Rodar de hora em
# hora nao melhoraria nada — a projecao mensal muda pouco dentro do mesmo dia e
# cada execucao grava uma linha nova por entidade, de proposito, para o erro
# poder ser cobrado depois.
#
# O venv NAO vive dentro da release (senao cada deploy reinstalaria 200 MB e o
# rollback ficaria sem ambiente) — ele fica em shared/ e e resolvido aqui.
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}"
VENV_PYTHON="${BIOMIE_INTEL_PYTHON:-/var/www/biomie/shared/python-venv/bin/python}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/intelligence_forecast.log"
LOCK_FILE="$LOCK_DIR/intelligence_forecast.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1

if [ ! -x "$VENV_PYTHON" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_forecast ABORTADO: venv ausente em $VENV_PYTHON" >> "$LOG_FILE"
    exit 1
fi

cd "$PROJECT_ROOT/services/python" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_forecast start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
    flock -n "$LOCK_FILE" "$VENV_PYTHON" -m biomie_intel forecast-mensal --company=all >> "$LOG_FILE" 2>&1
    code=$?
else
    "$VENV_PYTHON" -m biomie_intel forecast-mensal --company=all >> "$LOG_FILE" 2>&1
    code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_forecast finish exit=$code" >> "$LOG_FILE"
exit "$code"
