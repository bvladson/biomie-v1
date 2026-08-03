#!/usr/bin/env bash
# Instala (ou remove) o agendamento do BIomie na VPS.
#
#   bash tools/deploy/vps/install_cron.sh           # instala DESLIGADO (arquivo .disabled)
#   bash tools/deploy/vps/install_cron.sh --enable  # instala e LIGA
#   bash tools/deploy/vps/install_cron.sh --disable # desliga sem apagar
#   bash tools/deploy/vps/install_cron.sh --status  # mostra o estado atual
set -euo pipefail

HOST="${BIOMIE_VPS_HOST:-179.197.238.57}"
SSH_USER="${BIOMIE_VPS_USER:-root}"
KEY="${BIOMIE_VPS_KEY:-$HOME/.ssh/id_ed25519_biomie_vps}"
SSH="ssh -o BatchMode=yes -o ConnectTimeout=15 -i $KEY ${SSH_USER}@${HOST}"

cd "$(git rev-parse --show-toplevel)"
MODE="${1:-install}"

case "$MODE" in
  --status)
    $SSH 'bash -s' <<'REMOTE'
if [ -f /etc/cron.d/biomie ]; then
    echo "ESTADO: LIGADO (/etc/cron.d/biomie)"
    grep -cE '^[0-9*]' /etc/cron.d/biomie | xargs echo "  jobs ativos:"
elif [ -f /etc/cron.d/biomie.disabled ]; then
    echo "ESTADO: instalado mas DESLIGADO (/etc/cron.d/biomie.disabled)"
else
    echo "ESTADO: não instalado"
fi
REMOTE
    ;;

  --disable)
    $SSH 'set -e; if [ -f /etc/cron.d/biomie ]; then mv /etc/cron.d/biomie /etc/cron.d/biomie.disabled; echo "cron DESLIGADO"; else echo "já estava desligado"; fi'
    ;;

  --enable)
    # cron ignora arquivos em /etc/cron.d com ponto no nome — por isso o .disabled funciona como chave.
    $SSH "cat > /etc/cron.d/biomie.disabled" < tools/deploy/vps/biomie.cron
    $SSH 'set -e
        chown root:root /etc/cron.d/biomie.disabled
        chmod 644 /etc/cron.d/biomie.disabled
        mv /etc/cron.d/biomie.disabled /etc/cron.d/biomie
        systemctl restart cron
        echo "cron LIGADO:"; grep -cE "^[0-9*]" /etc/cron.d/biomie | xargs echo "  jobs:"'
    ;;

  install)
    $SSH "cat > /etc/cron.d/biomie.disabled" < tools/deploy/vps/biomie.cron
    $SSH 'set -e
        chown root:root /etc/cron.d/biomie.disabled
        chmod 644 /etc/cron.d/biomie.disabled
        rm -f /etc/cron.d/biomie
        echo "instalado DESLIGADO em /etc/cron.d/biomie.disabled"
        echo "para ligar: bash tools/deploy/vps/install_cron.sh --enable"'
    ;;

  *)
    echo "uso: install_cron.sh [--enable|--disable|--status]" >&2; exit 2 ;;
esac
