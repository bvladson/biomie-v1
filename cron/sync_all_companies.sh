#!/bin/sh
# BIomie — Sync Omie para todas as empresas ativas
# Uso: sh cron/sync_all_companies.sh [--domains=commercial] [--mode=delta]
# Cron: */15 * * * * /bin/sh /path/biomie/cron/sync_all_companies.sh --domains=commercial --mode=delta >> storage/logs/sync-commercial.log 2>&1

PHP=/usr/bin/php
ROOT="$(CDPATH= cd "$(dirname "$0")/.." && pwd)"

EXTRA="$*"

COMPANIES="MAG_FILIAL EXPERT UNI_DESIGN ERGOOFFICE MAG_MATRIZ"

echo "=== sync_all_companies $(date '+%Y-%m-%d %H:%M:%S') args=$EXTRA ==="

for SLUG in $COMPANIES; do
    echo "--- company=$SLUG ---"
    $PHP "$ROOT/cron/sync_realtime_pipeline.php" --company="$SLUG" $EXTRA
    STATUS=$?
    if [ $STATUS -ne 0 ]; then
        echo "[WARN] company=$SLUG exit=$STATUS"
    fi
done

echo "=== done $(date '+%Y-%m-%d %H:%M:%S') ==="
