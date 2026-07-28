#!/bin/bash
DIR="/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie"
PHP="/usr/bin/php"
COMPANY="EXPERT"
PAUSE=8
WAIT=150
LOG="$DIR/storage/logs/backfill_expert_recent.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG"; }

run_chunk() {
    local FROM=$1
    local NUM=$2
    log "=== Chunk $NUM/12: $FROM ==="
    $PHP "$DIR/bin/initial_sync_company.php" \
        --company=$COMPANY --from=$FROM --to=$FROM \
        --pause=$PAUSE --skip-marts 2>&1 | tee -a "$LOG"
    local EXIT=${PIPESTATUS[0]}
    if [ $EXIT -ne 0 ]; then
        log "ERRO chunk $NUM ($FROM) exit=$EXIT — abortando."
        exit 1
    fi
    log "Chunk $NUM OK."
}

log "=== EXPERT backfill recente iniciado (2025-07 -> 2026-06) ==="

run_chunk "2025-07"  1;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2025-08"  2;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2025-09"  3;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2025-10"  4;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2025-11"  5;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2025-12"  6;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2026-01"  7;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2026-02"  8;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2026-03"  9;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2026-04" 10;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2026-05" 11;  log "Aguardando ${WAIT}s..."; sleep $WAIT
run_chunk "2026-06" 12

log "=== Canonical sync concluído. Materializando marts... ==="
$PHP "$DIR/bin/materialize_marts.php" \
    --company=$COMPANY \
    --start=2025-07-01 --end=2026-06-30 --months=1 2>&1 | tee -a "$LOG"

log "=== EXPERT backfill recente COMPLETO ==="
