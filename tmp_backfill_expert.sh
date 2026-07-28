#!/bin/bash
DIR="/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie"
PHP="/usr/bin/php"
COMPANY="EXPERT"
PAUSE=8
WAIT=145

run_chunk() {
    local FROM=$1
    local TO=$1
    local NUM=$2
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] === Chunk $NUM/30: $FROM ==="
    $PHP "$DIR/bin/initial_sync_company.php" --company=$COMPANY --from=$FROM --to=$TO --pause=$PAUSE --skip-marts
    local EXIT=$?
    if [ $EXIT -ne 0 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERRO chunk $NUM ($FROM) exit=$EXIT"
        exit 1
    fi
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Chunk $NUM OK."
}

wait_next() {
    local NUM=$1
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Aguardando ${WAIT}s antes do chunk $NUM..."
    sleep $WAIT
}

run_chunk "2024-01" 1;  wait_next 2
run_chunk "2024-02" 2;  wait_next 3
run_chunk "2024-03" 3;  wait_next 4
run_chunk "2024-04" 4;  wait_next 5
run_chunk "2024-05" 5;  wait_next 6
run_chunk "2024-06" 6;  wait_next 7
run_chunk "2024-07" 7;  wait_next 8
run_chunk "2024-08" 8;  wait_next 9
run_chunk "2024-09" 9;  wait_next 10
run_chunk "2024-10" 10; wait_next 11
run_chunk "2024-11" 11; wait_next 12
run_chunk "2024-12" 12; wait_next 13
run_chunk "2025-01" 13; wait_next 14
run_chunk "2025-02" 14; wait_next 15
run_chunk "2025-03" 15; wait_next 16
run_chunk "2025-04" 16; wait_next 17
run_chunk "2025-05" 17; wait_next 18
run_chunk "2025-06" 18; wait_next 19
run_chunk "2025-07" 19; wait_next 20
run_chunk "2025-08" 20; wait_next 21
run_chunk "2025-09" 21; wait_next 22
run_chunk "2025-10" 22; wait_next 23
run_chunk "2025-11" 23; wait_next 24
run_chunk "2025-12" 24; wait_next 25
run_chunk "2026-01" 25; wait_next 26
run_chunk "2026-02" 26; wait_next 27
run_chunk "2026-03" 27; wait_next 28
run_chunk "2026-04" 28; wait_next 29
run_chunk "2026-05" 29; wait_next 30
run_chunk "2026-06" 30

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === Materializando marts ==="
$PHP "$DIR/bin/materialize_marts.php" --start=2024-01-01 --end=2026-06-30 --months=1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === EXPERT backfill completo ==="
