#!/bin/sh
# Score, forecast e recomendacao por territorio — persistidos.
#
# Este script era o unico da familia de inteligencia fora do padrao: rodava sem
# lock, sem conferir o venv e gravava o log num diretorio proprio. As tres
# coisas importam:
#
#   · SEM LOCK, duas execucoes sobrepostas gravam a mesma competencia em
#     paralelo. `persist` faz UPDATE is_current=0 e depois INSERT ... ON
#     DUPLICATE KEY: intercaladas, uma zera o que a outra acabou de marcar como
#     vigente e o mapa fica sem score vigente ate a rodada seguinte.
#   · SEM A CONFERENCIA DO VENV, um venv ausente virava erro de shell solto em
#     vez da linha ABORTADO que os outros cinco escrevem — e o motivo real
#     ficava invisivel para quem fosse ler o log.
#   · LOG EM OUTRO DIRETORIO fazia quem abrisse storage/logs/cron/ ver cinco
#     jobs e concluir que o geo nao roda.
#
# O env NAO e mais carregado aqui: `config.load_settings()` le
# /etc/biomie/intelligence.env sozinho, como ja acontece nos outros cinco. Fazer
# `set -a; . "$ENV_FILE"` exportava a senha do banco para o ambiente do processo
# e de todos os filhos, sem necessidade.
#
# O venv NAO vive dentro da release (senao cada deploy reinstalaria 200 MB e o
# rollback ficaria sem ambiente) — ele fica em shared/ e e resolvido aqui.
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}"
VENV_PYTHON="${BIOMIE_INTEL_PYTHON:-/var/www/biomie/shared/python-venv/bin/python}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/intelligence_geo.log"
LOCK_FILE="$LOCK_DIR/intelligence_geo.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1

if [ ! -x "$VENV_PYTHON" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_geo ABORTADO: venv ausente em $VENV_PYTHON" >> "$LOG_FILE"
    exit 1
fi

cd "$PROJECT_ROOT/services/python" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_geo start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
    flock -n "$LOCK_FILE" "$VENV_PYTHON" -m biomie_intel geo-intelligence --company=all >> "$LOG_FILE" 2>&1
    code=$?
else
    "$VENV_PYTHON" -m biomie_intel geo-intelligence --company=all >> "$LOG_FILE" 2>&1
    code=$?
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_geo finish exit=$code" >> "$LOG_FILE"
exit "$code"
