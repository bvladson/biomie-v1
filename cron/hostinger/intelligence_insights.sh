#!/bin/sh
# Pontua os orçamentos abertos de todas as empresas.
#
# O venv NÃO vive dentro da release (senão cada deploy reinstalaria 200 MB e o
# rollback ficaria sem ambiente) — ele fica em shared/ e é resolvido aqui.
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}"
VENV_PYTHON="${BIOMIE_INTEL_PYTHON:-/var/www/biomie/shared/python-venv/bin/python}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/intelligence_insights.log"
LOCK_FILE="$LOCK_DIR/intelligence_insights.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1

if [ ! -x "$VENV_PYTHON" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_insights ABORTADO: venv ausente em $VENV_PYTHON" >> "$LOG_FILE"
    exit 1
fi

cd "$PROJECT_ROOT/services/python" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_insights start" >> "$LOG_FILE"

if command -v flock >/dev/null 2>&1; then
    flock -n "$LOCK_FILE" "$VENV_PYTHON" -m biomie_intel insights --company=all >> "$LOG_FILE" 2>&1
    code=$?
else
    "$VENV_PYTHON" -m biomie_intel insights --company=all >> "$LOG_FILE" 2>&1
    code=$?
fi

# Publicação das notificações: etapa DERIVADA das leituras.
#
# Duas regras, ambas aprendidas em produção:
#  1. Ela roda ANTES da linha "finish". Registrar o encerramento e só depois
#     continuar trabalhando faz o log afirmar um desfecho que ainda pode mudar.
#  2. Se ela falhar, o job NÃO falha. As leituras já foram gravadas e a home já
#     está correta; marcar `failed` aqui dispara o dead-man switch com a missão
#     cumprida — foi exatamente o que o commit f00e1c1 corrigiu no expurgo.
#     Engolir em silêncio seria o outro extremo, então a falha vai para o log
#     com marcador próprio e o código de saída do publicador fica registrado.
publish_code=0
if [ "$code" -eq 0 ]; then
    # -E 75: distingue "outro processo está publicando" de "a publicação
    # falhou". Sem isso, um lock ocupado viraria erro no log.
    if command -v flock >/dev/null 2>&1; then
        flock -n -E 75 "$LOCK_FILE" php "$PROJECT_ROOT/bin/publish_intelligence_notifications.php" >> "$LOG_FILE" 2>&1
        publish_code=$?
    else
        php "$PROJECT_ROOT/bin/publish_intelligence_notifications.php" >> "$LOG_FILE" 2>&1
        publish_code=$?
    fi

    if [ "$publish_code" -eq 75 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_insights publicacao_ignorada: lock em uso" >> "$LOG_FILE"
    elif [ "$publish_code" -ne 0 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_insights PUBLICACAO_FALHOU exit=$publish_code (leituras OK, job segue)" >> "$LOG_FILE"
    fi
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] intelligence_insights finish exit=$code publish=$publish_code" >> "$LOG_FILE"
exit "$code"
