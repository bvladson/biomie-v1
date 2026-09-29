#!/bin/sh
set -u

# Sentinela de integridade comercial — vendas escondidas ou descartadas
# (exclusão dura contra licitação, NF do Omie fora do BIomie, NF nunca
# classificada, pedido × NF com canal divergente, acesso de um canal só,
# "Todas as operações" desligado). Alerta por Telegram/webhook e no sininho,
# com dedup de 24 h. Incidente de origem: 29/09/2026 (Uni Design e MAG Filial).

# Raiz DERIVADA da propria localizacao do script, nao chumbada.
PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/commercial_integrity.log"
LOCK_FILE="$LOCK_DIR/commercial_integrity.lock"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] commercial_integrity start" >> "$LOG_FILE"
if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" bin/commercial_integrity_check.php --notify >> "$LOG_FILE" 2>&1
  code=$?
else
  "$PHP_BIN" bin/commercial_integrity_check.php --notify >> "$LOG_FILE" 2>&1
  code=$?
fi
echo "[$(date '+%Y-%m-%d %H:%M:%S')] commercial_integrity finish exit=$code" >> "$LOG_FILE"
# Exit 1/2 é o próprio resultado da checagem (alerta/crítico), não falha do job.
exit 0
