#!/bin/bash
# enrich_finance_titulos.sh
# Enriquece nome_cliente e nome_vendedor nos títulos financeiros
# usando os dados da NF vinculada (chave_nfe).
# Rodado após finance_sync para garantir dados completos.
#
# Adicionar no hPanel: 45 2 * * * /bin/sh [caminho_completo]/enrich_finance_titulos.sh

BASEDIR="$(cd "$(dirname "$0")/../.." && pwd)"
LOG="$BASEDIR/storage/logs/enrich_finance_titulos_$(date +%Y%m%d).log"
PHP_BIN="/usr/local/bin/php"

echo "[$(date)] enrich_finance_titulos: inicio" >> "$LOG"

COMPANIES="MAG_FILIAL EXPERT UNI_DESIGN MAG_MATRIZ ERGOOFFICE"

for SLUG in $COMPANIES; do
    echo "[$(date)] enrich_finance_titulos: company=$SLUG" >> "$LOG"
    $PHP_BIN "$BASEDIR/bin/enrich_finance_titulos.php" --company="$SLUG" >> "$LOG" 2>&1
    STATUS=$?
    if [ $STATUS -ne 0 ]; then
        echo "[$(date)] enrich_finance_titulos: company=$SLUG exit=$STATUS" >> "$LOG"
    fi
done

echo "[$(date)] enrich_finance_titulos: fim" >> "$LOG"
