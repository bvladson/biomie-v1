#!/usr/bin/env bash
# Volta a VPS para a release anterior (ou para uma release específica).
#
#   bash tools/deploy/vps/rollback.sh              # lista as releases e volta para a anterior
#   bash tools/deploy/vps/rollback.sh 20260801_175939
#   bash tools/deploy/vps/rollback.sh --list       # só lista
set -euo pipefail

HOST="${BIOMIE_VPS_HOST:-203.0.113.10}"
SSH_USER="${BIOMIE_VPS_USER:-root}"
KEY="${BIOMIE_VPS_KEY:-$HOME/.ssh/id_ed25519_biomie_vps}"
BASE="${BIOMIE_VPS_BASE:-/var/www/biomie}"

SSH="ssh -o BatchMode=yes -o ConnectTimeout=15 -i $KEY ${SSH_USER}@${HOST}"
TARGET="${1:-}"

if [ "$TARGET" = "--list" ]; then
    $SSH "ls -1t $BASE/releases/ && echo && echo 'live -> ' \$(basename \$(readlink -f $BASE/live))"
    exit 0
fi

$SSH "bash -s" <<REMOTE
set -euo pipefail
BASE="$BASE"
TARGET="$TARGET"
CURRENT="\$(basename "\$(readlink -f "\$BASE/live")")"

if [ -z "\$TARGET" ]; then
    TARGET="\$(ls -1t "\$BASE/releases/" | grep -v "^\$CURRENT\$" | head -1)"
    [ -n "\$TARGET" ] || { echo "ERRO: não há release anterior para voltar." >&2; exit 1; }
fi
[ -d "\$BASE/releases/\$TARGET" ] || { echo "ERRO: release \$TARGET não existe." >&2; exit 1; }

echo "live atual: \$CURRENT"
echo "voltando para: \$TARGET"
ln -sfn "\$BASE/releases/\$TARGET" "\$BASE/live.new"
mv -Tf "\$BASE/live.new" "\$BASE/live"
systemctl reload php8.3-fpm
sleep 1

VHOST=biomie.magmoveiscorporativos.com.br
CODE="\$(curl -s -o /dev/null -w '%{http_code}' --resolve "\$VHOST:443:127.0.0.1" "https://\$VHOST/biomie/login.php" || echo 000)"
echo "smoke: HTTP \$CODE"
[ "\$CODE" = "200" ] || [ "\$CODE" = "302" ] || { echo "ATENÇÃO: smoke falhou mesmo após o rollback." >&2; exit 1; }
echo "rollback OK — live = \$TARGET"
REMOTE
