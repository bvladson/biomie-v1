#!/usr/bin/env bash
# Metade remota do import — roda COMO ROOT dentro da VPS.
# Não chame direto: use tools/deploy/vps/db_sync_from_legacy.sh.
set -euo pipefail

DUMP="$1"      # /root/biomie-legado-<stamp>.sql.gz
APP_ROOT="$2"  # /var/www/biomie/live

[ -f "$DUMP" ] || { echo "ERRO: dump $DUMP não existe" >&2; exit 1; }
gzip -t "$DUMP" || { echo "ERRO: dump corrompido" >&2; exit 1; }

DB_NAME="$(BIOMIE_APP_ROOT="$APP_ROOT" php -r '
    require getenv("BIOMIE_APP_ROOT") . "/app/Bootstrap.php";
    \BIomie\Bootstrap::init();
    echo (new \BIomie\Config())->get("db.name", "");
')"
[ -n "$DB_NAME" ] || { echo "ERRO: não consegui ler db.name da config" >&2; exit 1; }

echo "    banco destino: $DB_NAME"

# O dump traz os triggers com DEFINER=`usuario_do_host_antigo`@`localhost`.
# Esse usuário NÃO existe na VPS. Importar assim tem dois finais ruins:
#   - sem privilégio SET USER, o import ABORTA no meio (foi o que aconteceu);
#   - com privilégio, o trigger é criado e quebra toda vez que dispara.
# Removendo o DEFINER, cada objeto nasce com o usuário que está importando
# (root@localhost) — que é exatamente como os 20 triggers já existentes estão.
echo "    importando (removendo DEFINER dos triggers)..."
set -o pipefail
gunzip -c "$DUMP" \
    | sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' \
    | mariadb "$DB_NAME"

echo "    import terminou sem erro"

# Prova de que não sobrou objeto com dono inexistente.
ORFAOS="$(mariadb -N -B -e "
    SELECT COUNT(*) FROM information_schema.triggers t
     WHERE t.trigger_schema = '$DB_NAME'
       AND NOT EXISTS (
           SELECT 1 FROM mysql.user u
            WHERE CONCAT(u.user,'@',u.host) = t.definer
       )")"
if [ "$ORFAOS" != "0" ]; then
    echo "ERRO: $ORFAOS trigger(s) ficaram com DEFINER inexistente — quebrariam em execução" >&2
    exit 1
fi
echo "    triggers órfãos: 0"
