#!/bin/sh
# Expurgo das tabelas de telemetria e da trilha de sync.
#
# POR QUE ISTO PRECISOU EXISTIR: `cron/retention_cleanup.php` e
# `TelemetryRetentionService` ja existiam, com politica escrita para spans,
# metricas e eventos -- e NUNCA foram agendados. Zero linhas no cron, zero logs.
# Codigo de retencao que ninguem executa nao e retencao, e a aparencia dela.
#
# O que estava crescendo sem limite, medido em 2026-08-09:
#
#   biomie_operational_spans    463 mil linhas   440 MB
#   biomie_operational_metrics  538 mil linhas   210 MB
#   biomie_sync_runs             57 mil linhas    64 MB  (677/dia, sem politica)
#
# `biomie_sync_runs` importa alem do espaco: `api/omie_status.php` a consulta
# SEIS vezes por request, e ela crescendo e o motivo de aquele endpoint ter
# degradado para 5s de media.
#
# --apply e obrigatorio: sem ele o script so simula. Aqui passamos de proposito,
# mas rodar a mao sem a flag e a forma segura de ver o que sairia.
#
# 04:40 de domingo: semanal basta (o volume diario nao chega perto do limite) e
# domingo de madrugada e a janela mais quieta -- nao concorre com sync,
# materializacao nem com os jobs de inteligencia.
set -u

PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/retention_cleanup.log"
LOCK_FILE="$LOCK_DIR/retention_cleanup.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] retention_cleanup start" >> "$LOG_FILE"

# Lotes pequenos e teto de lotes: apagar centenas de milhares de linhas numa
# transacao so travaria as tabelas que o sync escreve o tempo todo.
if command -v flock >/dev/null 2>&1; then
    flock -n -E 75 "$LOCK_FILE" "$PHP_BIN" "$PROJECT_ROOT/cron/retention_cleanup.php" \
        --apply --batch-size=2000 --max-batches=200 >> "$LOG_FILE" 2>&1
    code=$?
else
    "$PHP_BIN" "$PROJECT_ROOT/cron/retention_cleanup.php" \
        --apply --batch-size=2000 --max-batches=200 >> "$LOG_FILE" 2>&1
    code=$?
fi

if [ "$code" -eq 75 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] retention_cleanup IGNORADO: outra execucao em andamento" >> "$LOG_FILE"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] retention_cleanup finish exit=$code" >> "$LOG_FILE"
exit "$code"
