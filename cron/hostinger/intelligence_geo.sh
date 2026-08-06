#!/bin/sh
set -eu

ROOT="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}"
PYTHON="/var/www/biomie/shared/python-venv/bin/python"
ENV_FILE="/etc/biomie/intelligence.env"
LOG_DIR="/var/www/biomie/shared/storage/logs/intelligence"

mkdir -p "$LOG_DIR"
set -a
. "$ENV_FILE"
set +a
cd "$ROOT/services/python"
exec "$PYTHON" -m biomie_intel geo-intelligence --company=all >> "$LOG_DIR/geo-intelligence-cron.log" 2>&1
