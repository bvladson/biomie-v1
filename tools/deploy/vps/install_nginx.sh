#!/usr/bin/env bash
# Instala a config do nginx da VPS a partir do repositório.
#
#   bash tools/deploy/vps/install_nginx.sh --diff     # mostra o que mudaria
#   bash tools/deploy/vps/install_nginx.sh            # aplica (testa antes de recarregar)
#
# A config vivia SÓ no servidor. Uma reinstalação levaria junto correções que
# custaram diagnóstico — como a ordem dos rewrites do webhook.
#
# ARMADILHA: o nginx carrega TODOS os arquivos de sites-enabled/. Backup com
# sufixo .bak DENTRO dessa pasta vira server block duplicado e derruba o
# `nginx -t`. Por isso o backup vai para /root/nginx-backups/.
set -euo pipefail

HOST="${BIOMIE_VPS_HOST:-203.0.113.10}"
SSH_USER="${BIOMIE_VPS_USER:-root}"
KEY="${BIOMIE_VPS_KEY:-$HOME/.ssh/id_ed25519_biomie_vps}"
SSH="ssh -o BatchMode=yes -o ConnectTimeout=15 -i $KEY ${SSH_USER}@${HOST}"
REMOTO=/etc/nginx/sites-enabled/biomie.magmoveiscorporativos.com.br

cd "$(git rev-parse --show-toplevel)"
LOCAL=tools/deploy/vps/nginx/biomie.conf
[ -f "$LOCAL" ] || { echo "ERRO: $LOCAL não existe." >&2; exit 1; }

if [ "${1:-}" = "--diff" ]; then
    $SSH "cat $REMOTO" > /tmp/nginx_remoto.conf 2>/dev/null || true
    diff -u /tmp/nginx_remoto.conf "$LOCAL" && echo "  idêntico ao servidor" || true
    exit 0
fi

$SSH "mkdir -p /root/nginx-backups && cp -a $REMOTO /root/nginx-backups/biomie-\$(date +%Y%m%d_%H%M%S).conf"
$SSH "cat > $REMOTO" < "$LOCAL"
$SSH 'set -e
    if nginx -t 2>/tmp/nginx_test.err; then
        systemctl reload nginx
        echo "  config aplicada e nginx recarregado"
    else
        echo "  ERRO no teste — restaurando o backup mais recente:"
        sed "s/^/    /" /tmp/nginx_test.err
        cp -a "$(ls -1t /root/nginx-backups/biomie-*.conf | head -1)" /etc/nginx/sites-enabled/biomie.magmoveiscorporativos.com.br
        nginx -t >/dev/null 2>&1 && echo "  restaurado" || echo "  ATENCAO: restauracao tambem falhou"
        exit 1
    fi'

VHOST=biomie.magmoveiscorporativos.com.br
$SSH "curl -s -o /dev/null -w '  smoke login: HTTP %{http_code}\n' --resolve $VHOST:443:127.0.0.1 https://$VHOST/biomie/login.php"
