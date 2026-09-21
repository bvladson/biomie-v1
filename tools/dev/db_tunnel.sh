#!/usr/bin/env bash
# Túnel da máquina de desenvolvimento até o MariaDB de PRODUÇÃO (VPS).
#
# O banco não é exposto à internet: a porta local 33306 atravessa o SSH até
# 127.0.0.1:3306 da VPS. A config local usa o usuário SOMENTE-LEITURA
# biomie_ro_local e declara DB_EXPECTED_TARGET=production, então auditoria e
# diagnóstico leem os dados reais sem conseguir escrever.
#
#   bash tools/dev/db_tunnel.sh        # primeiro plano; Ctrl+C fecha
#   php tools/dev/whereami.php         # em outro terminal: confere o alvo
#
# Até 21/09/2026 a config local apontava para o banco antigo da Hostinger,
# congelado desde agosto — diagnósticos feitos daqui liam dados velhos.
set -euo pipefail

HOST="${BIOMIE_VPS_HOST:-179.197.238.57}"
KEY="${BIOMIE_VPS_KEY:-$HOME/.ssh/id_ed25519_biomie_vps}"
PORT="${BIOMIE_DB_TUNNEL_PORT:-33306}"

echo "túnel: 127.0.0.1:${PORT} -> ${HOST} (MariaDB 127.0.0.1:3306) — Ctrl+C para fechar"
exec ssh -N \
    -o ExitOnForwardFailure=yes \
    -o ServerAliveInterval=30 \
    -o BatchMode=yes \
    -i "$KEY" \
    -L "127.0.0.1:${PORT}:127.0.0.1:3306" \
    "root@${HOST}"
