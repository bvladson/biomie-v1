#!/usr/bin/env bash
# Cria (ou reconfigura) o usuário restrito do serviço de inteligência na VPS.
#
#   bash tools/deploy/vps/provision_intelligence_db_user.sh
#
# Menor privilégio de verdade:
#   · SELECT apenas nas tabelas que ele precisa LER — não no banco inteiro.
#   · INSERT/UPDATE só nas DUAS tabelas que ele escreve.
#   · Sem DDL, sem DELETE, sem DROP, sem acesso a biomie_users.
#   · Só conecta de 127.0.0.1.
#
# Se o processo for comprometido ou tiver um bug, o estrago fica contido no que
# ele legitimamente usa. O usuário da aplicação PHP tem DDL em tudo — por isso
# este serviço não usa aquele.
set -euo pipefail

HOST="${BIOMIE_VPS_HOST:-179.197.238.57}"
SSH_USER="${BIOMIE_VPS_USER:-root}"
KEY="${BIOMIE_VPS_KEY:-$HOME/.ssh/id_ed25519_biomie_vps}"
SSH="ssh -o BatchMode=yes -o ConnectTimeout=15 -i $KEY ${SSH_USER}@${HOST}"

$SSH 'bash -s' <<'REMOTE'
set -euo pipefail

DB=biomie
USER_NAME=biomie_intel
ENV_FILE=/etc/biomie/intelligence.env

# Só gera senha nova se o arquivo ainda não existe — reexecutar não pode
# invalidar a credencial que o cron já está usando.
if [ -f "$ENV_FILE" ] && grep -q INTEL_DB_PASSWORD "$ENV_FILE"; then
    PASS="$(grep '^INTEL_DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2- | tr -d "\"'")"
    echo "  senha existente reaproveitada"
else
    PASS="$(head -c 32 /dev/urandom | base64 | tr -d '/+=' | head -c 28)"
    echo "  senha nova gerada"
fi

mariadb <<SQL
CREATE USER IF NOT EXISTS '${USER_NAME}'@'127.0.0.1' IDENTIFIED BY '${PASS}';
ALTER USER '${USER_NAME}'@'127.0.0.1' IDENTIFIED BY '${PASS}';

-- Zera tudo antes de reconceder: reexecutar não pode acumular privilégio.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${USER_NAME}'@'127.0.0.1';

-- LEITURA: só o que o score e o dataset consultam.
-- biomie_companies é por COLUNA de propósito: a tabela guarda omie_app_key e
-- omie_app_secret, e um job de análise não tem nada que ver credencial de
-- integração. SELECT na tabela inteira daria isso de graça.
GRANT SELECT (id, slug, nome_fantasia, razao_social, ativo)
      ON ${DB}.biomie_companies                               TO '${USER_NAME}'@'127.0.0.1';
GRANT SELECT ON ${DB}.biomie_sales_quote_followup_schedule    TO '${USER_NAME}'@'127.0.0.1';
GRANT SELECT ON ${DB}.biomie_pedidos_canonicos                TO '${USER_NAME}'@'127.0.0.1';
GRANT SELECT ON ${DB}.biomie_notas_fiscais_canonicas          TO '${USER_NAME}'@'127.0.0.1';
GRANT SELECT ON ${DB}.intelligence_quote_feedback             TO '${USER_NAME}'@'127.0.0.1';

-- ESCRITA: exatamente duas tabelas. Sem DELETE.
GRANT SELECT, INSERT, UPDATE ON ${DB}.intelligence_job_runs      TO '${USER_NAME}'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON ${DB}.intelligence_quote_scores  TO '${USER_NAME}'@'127.0.0.1';

FLUSH PRIVILEGES;
SQL

# Grupo biomieapp: é o grupo que o servidor já usa para acesso compartilhado à
# aplicação (contém www-data E biomie). Trocar para outro grupo aqui barra o
# www-data de ler a própria config.
install -d -m 750 -o root -g biomieapp /etc/biomie
cat > "$ENV_FILE" <<ENV
# Credenciais do serviço de inteligência (usuário RESTRITO, não o da aplicação).
# Gerado por tools/deploy/vps/provision_intelligence_db_user.sh
INTEL_DB_HOST=127.0.0.1
INTEL_DB_PORT=3306
INTEL_DB_NAME=${DB}
INTEL_DB_USER=${USER_NAME}
INTEL_DB_PASSWORD=${PASS}
INTEL_LOG_DIR=/var/www/biomie/shared/storage/logs/intelligence
INTEL_MODEL_DIR=/var/www/biomie/shared/storage/intelligence/models
# baseline | shadow | active  — trocar para 'active' é decisão humana.
INTEL_RUN_MODE=baseline
ENV
# O serviço roda como www-data — o MESMO usuário do php-fpm e dos demais crons,
# para o dono dos arquivos de storage ficar consistente. A barreira de segurança
# aqui é o usuário RESTRITO do banco, não o usuário do sistema operacional.
chown root:biomieapp "$ENV_FILE"
chmod 640 "$ENV_FILE"

install -d -m 2770 -o www-data -g www-data /var/www/biomie/shared/storage/logs/intelligence
install -d -m 2770 -o www-data -g www-data /var/www/biomie/shared/storage/intelligence/models

echo "  usuário ${USER_NAME}@127.0.0.1 configurado"
echo "  privilégios concedidos:"
mariadb -N -B -e "SHOW GRANTS FOR '${USER_NAME}'@'127.0.0.1'" | sed 's/^/    /'
REMOTE
