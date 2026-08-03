#!/usr/bin/env bash
# Copia o banco do host ANTIGO (Hostinger compartilhado) para a VPS.
#
#   bash tools/deploy/vps/db_sync_from_legacy.sh --backup-only  # só salva o banco da VPS
#   bash tools/deploy/vps/db_sync_from_legacy.sh --check        # compara os dois lados
#   bash tools/deploy/vps/db_sync_from_legacy.sh                # backup + dump + import
#
# O dump é HOT (--single-transaction): não precisa parar o host antigo, e o
# snapshot sai consistente porque as tabelas são InnoDB.
#
# As credenciais dos dois bancos NUNCA passam pelo terminal: cada lado lê o
# próprio config/.env.local.php e monta o comando dentro do servidor.
set -euo pipefail

VPS_HOST="${BIOMIE_VPS_HOST:-179.197.238.57}"
VPS_USER="${BIOMIE_VPS_USER:-root}"
VPS_KEY="${BIOMIE_VPS_KEY:-$HOME/.ssh/id_ed25519_biomie_vps}"
VPS_ROOT="${BIOMIE_VPS_BASE:-/var/www/biomie}/live"

OLD_HOST="${BIOMIE_OLD_HOST:-82.112.247.199}"
OLD_PORT="${BIOMIE_OLD_PORT:-65002}"
OLD_USER="${BIOMIE_OLD_USER:-u106688109}"
OLD_ROOT="${BIOMIE_OLD_ROOT:-/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie}"

SSH_VPS="ssh -o BatchMode=yes -o ConnectTimeout=20 -i $VPS_KEY ${VPS_USER}@${VPS_HOST}"
SSH_OLD="ssh -o BatchMode=yes -o ConnectTimeout=20 -p $OLD_PORT ${OLD_USER}@${OLD_HOST}"
STAMP="$(date '+%Y%m%d_%H%M%S')"

step() { echo; echo "==> $*"; }
fail() { echo "ERRO: $*" >&2; exit 1; }

# Imprime, no servidor, atribuições de shell com as credenciais — é consumido
# por eval dentro do próprio servidor, nunca volta para a máquina local.
creds_php() {
cat <<'PHP'
$root = getenv('BIOMIE_APP_ROOT');
require $root . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$c = new \BIomie\Config();
foreach ([['DB_HOST','db.host','localhost'],['DB_PORT','db.port','3306'],
          ['DB_NAME','db.name',''],['DB_USER','db.user',''],['DB_PASS','db.pass','']] as $k) {
    printf("%s=%s\n", $k[0], escapeshellarg((string) $c->get($k[1], $k[2])));
}
PHP
}

MODE="${1:-full}"

# --------------------------------------------------------------- comparação
if [ "$MODE" = "--check" ]; then
    step "comparando os dois bancos"
    for side in OLD VPS; do
        if [ "$side" = "OLD" ]; then SSH="$SSH_OLD"; ROOT="$OLD_ROOT"; else SSH="$SSH_VPS"; ROOT="$VPS_ROOT"; fi
        echo "--- $side"
        $SSH "BIOMIE_APP_ROOT='$ROOT' php -r $(printf '%q' "$(creds_php)") > /tmp/.c_$$ && . /tmp/.c_$$ && rm -f /tmp/.c_$$
            MYSQL_PWD=\"\$DB_PASS\" mysql -h \"\$DB_HOST\" -P \"\$DB_PORT\" -u \"\$DB_USER\" -N -B \"\$DB_NAME\" -e \"
              SELECT CONCAT('  ultimo sync : ', IFNULL(MAX(COALESCE(finished_at,started_at)),'-')) FROM biomie_sync_runs;
              SELECT CONCAT('  sync_runs   : ', COUNT(*)) FROM biomie_sync_runs;
              SELECT CONCAT('  bronze      : ', COUNT(*)) FROM biomie_omie_bronze;
              SELECT CONCAT('  eventos     : ', COUNT(*)) FROM biomie_eventos_canonicos;
              SELECT CONCAT('  tabelas     : ', COUNT(*)) FROM information_schema.tables WHERE table_schema=DATABASE();
              SELECT CONCAT('  tamanho MB  : ', ROUND(SUM(data_length+index_length)/1024/1024)) FROM information_schema.tables WHERE table_schema=DATABASE();\" " 2>&1 | grep -v "^Warning"
    done
    exit 0
fi

# ------------------------------------------------- 1. backup do banco da VPS
step "backup do banco atual da VPS"
BACKUP="/root/biomie-vps-antes-do-corte-${STAMP}.sql.gz"
$SSH_VPS "BIOMIE_APP_ROOT='$VPS_ROOT' php -r $(printf '%q' "$(creds_php)") > /tmp/.c_vps && . /tmp/.c_vps && rm -f /tmp/.c_vps
    MYSQL_PWD=\"\$DB_PASS\" mysqldump -h \"\$DB_HOST\" -P \"\$DB_PORT\" -u \"\$DB_USER\" \
        --single-transaction --quick --routines --triggers --events --no-tablespaces \
        \"\$DB_NAME\" | gzip -1 > $BACKUP
    ls -lh $BACKUP | awk '{print \"    \" \$5, \$9}'"

if [ "$MODE" = "--backup-only" ]; then
    step "só backup pedido — nada foi alterado"
    exit 0
fi

# ---------------------------------------- 2. dump do host antigo -> VPS
# Passa pela máquina local porque os dois servidores não se conhecem por chave.
step "dump HOT do host antigo, transmitindo para a VPS (pode demorar)"
REMOTE_DUMP="/root/biomie-legado-${STAMP}.sql.gz"
$SSH_OLD "BIOMIE_APP_ROOT='$OLD_ROOT' php -r $(printf '%q' "$(creds_php)") > /tmp/.c_old && . /tmp/.c_old && rm -f /tmp/.c_old
    MYSQL_PWD=\"\$DB_PASS\" mysqldump -h \"\$DB_HOST\" -P \"\$DB_PORT\" -u \"\$DB_USER\" \
        --single-transaction --quick --routines --triggers --events --no-tablespaces \
        --default-character-set=utf8mb4 \"\$DB_NAME\" | gzip -1" \
  | $SSH_VPS "cat > $REMOTE_DUMP"

$SSH_VPS "ls -lh $REMOTE_DUMP | awk '{print \"    recebido: \" \$5}'
    gzip -t $REMOTE_DUMP && echo '    integridade do gzip OK' || { echo 'ERRO: dump corrompido'; exit 1; }"

# ------------------------------------------------------- 3. importar na VPS
step "importando na VPS"
$SSH_VPS "BIOMIE_APP_ROOT='$VPS_ROOT' php -r $(printf '%q' "$(creds_php)") > /tmp/.c_vps && . /tmp/.c_vps && rm -f /tmp/.c_vps
    gunzip -c $REMOTE_DUMP | MYSQL_PWD=\"\$DB_PASS\" mysql -h \"\$DB_HOST\" -P \"\$DB_PORT\" -u \"\$DB_USER\" \"\$DB_NAME\"
    echo '    import concluído'"

step "conferindo"
bash "$0" --check

echo
echo "backup do estado anterior: $BACKUP (na VPS)"
echo "dump importado:            $REMOTE_DUMP (na VPS)"
