#!/bin/sh
# BIomie — Materializa gold mart para todas as empresas ativas
# Uso: sh cron/materialize_all_companies.sh [--months=N]
# Cron: */30 * * * * /bin/sh /path/biomie/cron/materialize_all_companies.sh >> storage/logs/materialize.log 2>&1

PHP=/usr/bin/php
ROOT="$(CDPATH= cd "$(dirname "$0")/.." && pwd)"

EXTRA="$*"

COMPANIES="MAG_FILIAL EXPERT UNI_DESIGN ERGOOFFICE MAG_MATRIZ"

echo "=== materialize_all_companies $(date '+%Y-%m-%d %H:%M:%S') ==="

for SLUG in $COMPANIES; do
    echo "--- company=$SLUG ---"
    $PHP "$ROOT/bin/materialize_marts.php" --company="$SLUG" $EXTRA
    STATUS=$?
    if [ $STATUS -ne 0 ]; then
        echo "[WARN] company=$SLUG exit=$STATUS"
    fi
done

echo "=== done $(date '+%Y-%m-%d %H:%M:%S') ==="
