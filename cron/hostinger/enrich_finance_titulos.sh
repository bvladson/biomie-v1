#!/bin/sh
# enrich_finance_titulos.sh
# Enriquece nome_cliente e nome_vendedor nos títulos financeiros
# usando os dados da NF vinculada (chave_nfe).
# Rodado após finance_sync para garantir dados completos.
#
# Adicionar no hPanel: 45 2 * * * /bin/sh [caminho_completo]/enrich_finance_titulos.sh

set -u

BASEDIR="${BIOMIE_PROJECT_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)}"
LOG_DIR="$BASEDIR/storage/logs/cron"
LOG="$LOG_DIR/enrich_finance_titulos.log"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"

umask 002
mkdir -p "$LOG_DIR" || exit 1

if [ ! -x "$PHP_BIN" ]; then
    echo "[$(date)] enrich_finance_titulos: PHP indisponivel em $PHP_BIN" >> "$LOG"
    exit 127
fi

echo "[$(date)] enrich_finance_titulos: inicio" >> "$LOG"

COMPANIES="MAG_FILIAL EXPERT UNI_DESIGN MAG_MATRIZ ERGOOFFICE"
FAILED=0

for SLUG in $COMPANIES; do
    echo "[$(date)] enrich_finance_titulos: company=$SLUG" >> "$LOG"
    if "$PHP_BIN" "$BASEDIR/bin/enrich_finance_titulos.php" --company="$SLUG" >> "$LOG" 2>&1; then
        STATUS=0
    else
        STATUS=$?
        FAILED=$((FAILED + 1))
        echo "[$(date)] enrich_finance_titulos: company=$SLUG exit=$STATUS" >> "$LOG"
    fi
done

if [ "$FAILED" -ne 0 ]; then
    echo "[$(date)] enrich_finance_titulos: fim failures=$FAILED" >> "$LOG"
    exit 1
fi

echo "[$(date)] enrich_finance_titulos: fim failures=0" >> "$LOG"
exit 0
