#!/usr/bin/env bash
# Instala a rotacao dos logs de cron do BIomie em /etc/logrotate.d/biomie.
#
#   bash tools/deploy/vps/install_logrotate.sh            # instala e valida
#   bash tools/deploy/vps/install_logrotate.sh --status   # so mostra o estado
#
# Como install_cron.sh e provision_intelligence_db_user.sh, este passo NAO e
# feito pelo deploy: mexe em configuracao do sistema, fora da release, e ligar
# isso e decisao de operacao.
set -euo pipefail

ORIGEM="$(cd "$(dirname "$0")" && pwd)/logrotate/biomie"
DESTINO="/etc/logrotate.d/biomie"

if [ "${1:-}" = "--status" ]; then
    if [ -f "$DESTINO" ]; then
        echo "instalado: $DESTINO"
        echo "--- estado do logrotate ---"
        grep -A3 'biomie' /var/lib/logrotate/status 2>/dev/null || echo "(ainda nao rotacionou)"
        echo "--- tamanho atual ---"
        du -sh /var/www/biomie/shared/storage/logs/cron 2>/dev/null || true
    else
        echo "NAO instalado (esperado em $DESTINO)"
        exit 1
    fi
    exit 0
fi

[ -f "$ORIGEM" ] || { echo "ERRO: $ORIGEM nao encontrado" >&2; exit 1; }

# CRLF aqui quebra o parser do logrotate do mesmo jeito que quebrou o cron.d —
# e, como la, o sintoma e o arquivo INTEIRO ser ignorado, em silencio.
if grep -q $'\r' "$ORIGEM"; then
    echo "ERRO: $ORIGEM tem CRLF; o logrotate ignoraria o arquivo inteiro." >&2
    exit 1
fi

install -m 0644 -o root -g root "$ORIGEM" "$DESTINO"
echo "instalado em $DESTINO"

# Valida ANTES de confiar: um arquivo invalido em logrotate.d faz o logrotate
# falhar para TODOS os servicos da maquina, nao so para o BIomie.
if ! logrotate --debug "$DESTINO" >/tmp/logrotate-check.out 2>&1; then
    cat /tmp/logrotate-check.out >&2
    rm -f "$DESTINO"
    echo "ERRO: config invalida — removida para nao quebrar a rotacao dos outros servicos." >&2
    exit 1
fi
echo "config validada (logrotate --debug OK)"
echo
echo "estado atual:"
du -sh /var/www/biomie/shared/storage/logs/cron 2>/dev/null || true
