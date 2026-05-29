#!/bin/sh
SCRIPT_DIR=$(CDPATH= cd "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd "$SCRIPT_DIR/../.." && pwd)
export BIOMIE_PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-$PROJECT_ROOT}"
exec "$PROJECT_ROOT/cron/hostinger/daily_integrity.sh"
