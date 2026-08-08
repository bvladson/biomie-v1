#!/bin/sh
# Dump diario do banco do BIomie.
#
# POR QUE ISTO PRECISOU EXISTIR: em 2026-08-07 a VPS tinha ZERO backup
# automatizado. Os unicos dumps eram tres arquivos soltos em /root, todos de
# 2026-08-03, feitos a mao durante o corte da migracao. Nenhum cron, nenhum
# mysqldump agendado, nenhuma retencao. E este projeto ja perdeu producao uma
# vez (2026-07-10: aplicacao apagada do servidor, site em 404 — o banco
# sobreviveu por sorte, nao por processo).
#
# O QUE ESTE BACKUP PROTEGE, E O QUE NAO PROTEGE
#
# Protege do erro humano e do bug: DROP errado, UPDATE sem WHERE, migration que
# corrompeu dado, sync que gravou lixo. Esse e o risco de longe mais provavel.
#
# NAO protege de perda do disco nem de comprometimento da maquina, porque mora
# no MESMO disco do banco. Copia externa e uma decisao separada e continua em
# aberto — esta linha existe para que ninguem leia "temos backup" e conclua algo
# mais forte do que e verdade.
set -u

# 0077 ANTES de qualquer criacao. Sem isto o dump nascia 0644 — 130 MB com o
# negocio inteiro das cinco empresas legivel por qualquer conta do sistema. A
# permissao 0700 do diretorio mascarava o problema, entao ele so apareceria no
# dia em que alguem copiasse o arquivo para outro lugar. Descoberto testando o
# proprio backup.
umask 0077

DB="${BIOMIE_DB_NAME:-biomie}"
DEST="${BIOMIE_BACKUP_DIR:-/var/backups/biomie}"
RETENCAO_DIAS="${BIOMIE_BACKUP_RETENCAO:-14}"
LOG_DIR="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}/storage/logs/cron"
LOG_FILE="$LOG_DIR/db_backup.log"
LOCK_FILE="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}/storage/locks/db_backup.lock"

mkdir -p "$DEST" "$LOG_DIR" "$(dirname "$LOCK_FILE")" || exit 1
# Dono root, nao a conta da aplicacao: se o processo do app for comprometido, ele
# nao pode apagar nem adulterar os backups — que e metade do valor de ter backup.
chown root:root "$DEST" 2>/dev/null || true
chmod 700 "$DEST"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] db_backup $*" >> "$LOG_FILE"; }

# ---------------------------------------------------------------- COPIA EXTERNA
#
# O dump local protege do risco mais provavel (DROP errado, UPDATE sem WHERE,
# migration que corrompeu dado). NAO protege de perda do disco nem de
# comprometimento da maquina, porque mora no mesmo disco do banco. Esta etapa
# fecha esse buraco levando o dump para OUTRA maquina.
#
# Destino: o host antigo da Hostinger, que ja e do usuario e ja contem os mesmos
# dados (o legado). Nao e um terceiro novo, e um segundo lugar.
#
# LIMITE HONESTO: quem controla a VPS controla a chave, entao um comprometimento
# total da VPS alcanca as copias. Mitigado (chave dedicada, restrita por IP de
# origem, sem tty e sem encaminhamento), nao eliminado. Imutabilidade de verdade
# exigiria object-lock no destino, que hospedagem compartilhada nao oferece.
OFFSITE_HOST="${BIOMIE_OFFSITE_HOST:-82.112.247.199}"
OFFSITE_PORT="${BIOMIE_OFFSITE_PORT:-65002}"
OFFSITE_USER="${BIOMIE_OFFSITE_USER:-u106688109}"
OFFSITE_KEY="${BIOMIE_OFFSITE_KEY:-/root/.ssh/id_ed25519_offsite}"
OFFSITE_DIR="${BIOMIE_OFFSITE_DIR:-backups/biomie-db}"
OFFSITE_RETENCAO="${BIOMIE_OFFSITE_RETENCAO:-7}"

# ------------------------------------------------------- STATUS PARA O SENTINEL
#
# Um backup que para de rodar em silencio nao e melhor que backup nenhum — e
# pior, porque produz confianca. Mas o OpsSentinel roda como www-data e
# /var/backups/biomie e 0700 root: ele NAO consegue olhar o diretorio, e afrouxar
# essa permissao para ganhar monitoramento seria trocar a protecao pelo alarme.
#
# Entao o script PUBLICA um resumo — so metadados, nenhum dado do negocio — num
# arquivo que o www-data le. O sentinel julga o frescor a partir dele.
STATUS_DIR="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}/storage/ops"
STATUS_FILE="$STATUS_DIR/backup-status.json"

publicar_status() {
    RETIDOS="$1"
    OFF_OK=$(cat "$DEST/.offsite-last-ok" 2>/dev/null || echo "")
    mkdir -p "$STATUS_DIR" 2>/dev/null
    # 0644 de proposito: sao metadados (datas, contagens, tamanho), e o sentinel
    # precisa ler. Os dumps continuam 0600 root.
    cat > "$STATUS_FILE" <<JSON
{
  "last_ok": "$(date -u '+%Y-%m-%dT%H:%M:%SZ')",
  "arquivo": "$(basename "${ALVO:-}")",
  "bytes": $(stat -c%s "${ALVO:-/dev/null}" 2>/dev/null || echo 0),
  "retidos_local": ${RETIDOS:-0},
  "retencao_dias": ${RETENCAO_DIAS:-0},
  "offsite_host": "$OFFSITE_HOST",
  "offsite_last_ok": "$OFF_OK"
}
JSON
    chmod 644 "$STATUS_FILE" 2>/dev/null
}

offsite_ssh() {
    ssh -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new \
        -i "$OFFSITE_KEY" -p "$OFFSITE_PORT" "$OFFSITE_USER@$OFFSITE_HOST" "$@"
}

enviar_offsite() {
    ARQUIVO="$1"
    NOME=$(basename "$ARQUIVO")

    if [ ! -r "$OFFSITE_KEY" ]; then
        log "OFFSITE IGNORADO: chave ausente em $OFFSITE_KEY"
        return 1
    fi

    if ! scp -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new \
            -i "$OFFSITE_KEY" -P "$OFFSITE_PORT" -q \
            "$ARQUIVO" "$OFFSITE_USER@$OFFSITE_HOST:$OFFSITE_DIR/" 2>>"$LOG_FILE"; then
        log "OFFSITE FALHOU: erro no envio de $NOME"
        return 1
    fi

    # HASH DOS DOIS LADOS. Transferencia interrompida deixa um arquivo com
    # tamanho plausivel que abre no gzip e restaura pela metade — exatamente o
    # modo de falha que o marcador "Dump completed" pega no dump local. Comparar
    # o sha256 e o equivalente para a copia.
    LOCAL_HASH=$(sha256sum "$ARQUIVO" | cut -d' ' -f1)
    REMOTO_HASH=$(offsite_ssh "sha256sum '$OFFSITE_DIR/$NOME' 2>/dev/null | cut -d' ' -f1")
    if [ "$LOCAL_HASH" != "$REMOTO_HASH" ]; then
        log "OFFSITE FALHOU: hash diverge (local=$(echo "$LOCAL_HASH" | cut -c1-12) remoto=$(echo "${REMOTO_HASH:-vazio}" | cut -c1-12)) — copia removida"
        offsite_ssh "rm -f '$OFFSITE_DIR/$NOME'" >/dev/null 2>&1
        return 1
    fi

    REMOTOS=$(offsite_ssh "find '$OFFSITE_DIR' -maxdepth 1 -name 'biomie-*.sql.gz' -mtime +$OFFSITE_RETENCAO -delete 2>/dev/null; find '$OFFSITE_DIR' -maxdepth 1 -name 'biomie-*.sql.gz' | wc -l")
    log "OFFSITE OK $NOME verificado por sha256 · $REMOTOS copia(s) em $OFFSITE_HOST"
    date -u '+%Y-%m-%dT%H:%M:%SZ' > "$DEST/.offsite-last-ok" 2>/dev/null
    return 0
}

DUMP_BIN=""
for c in mariadb-dump mysqldump; do
    command -v "$c" >/dev/null 2>&1 && DUMP_BIN="$c" && break
done
[ -n "$DUMP_BIN" ] || { log "ABORTADO: nem mariadb-dump nem mysqldump encontrados"; exit 1; }

executar() {
    ALVO="$DEST/biomie-$(date '+%Y%m%d_%H%M%S').sql.gz"

    # Espaco livre ANTES de comecar: um dump que enche o disco derruba o banco
    # junto, e ai o backup passa de rede de seguranca a causa do incidente.
    LIVRE_MB=$(df -Pm "$DEST" | awk 'NR==2{print $4}')
    if [ "${LIVRE_MB:-0}" -lt 2048 ]; then
        log "ABORTADO: so ${LIVRE_MB}MB livres em $DEST (minimo 2048)"
        return 1
    fi

    # --single-transaction: InnoDB, dump consistente SEM travar a escrita. Sem
    # isso o dump segura lock durante minutos e o sync da Omie falha por
    # timeout no meio da madrugada.
    # --routines/--events/--triggers: restaurar so tabela deixa o banco mudo.
    if ! $DUMP_BIN --single-transaction --quick --routines --events --triggers \
            --default-character-set=utf8mb4 "$DB" 2>>"$LOG_FILE" | gzip -c > "$ALVO"; then
        log "FALHOU: erro durante o dump de $DB"
        rm -f "$ALVO"
        return 1
    fi

    # UM DUMP TRUNCADO PARECE UM DUMP BOM. O arquivo existe, tem tamanho, abre
    # no gzip — e restaura pela metade. O mariadb-dump escreve "Dump completed"
    # na ultima linha SO quando terminou; conferir isso e a diferenca entre ter
    # backup e achar que tem.
    if ! gzip -dc "$ALVO" 2>/dev/null | tail -3 | grep -q "Dump completed"; then
        log "FALHOU: dump sem marcador de conclusao — truncado, descartado"
        rm -f "$ALVO"
        return 1
    fi

    chmod 600 "$ALVO"
    TAMANHO=$(du -h "$ALVO" | cut -f1)
    TABELAS=$(gzip -dc "$ALVO" | grep -c "^CREATE TABLE")
    log "OK $(basename "$ALVO") $TAMANHO $TABELAS tabelas"

    # Expurgo SO depois do dump novo estar validado: apagar o antigo antes
    # deixaria uma janela sem backup nenhum se o novo falhasse.
    APAGADOS=$(find "$DEST" -maxdepth 1 -name 'biomie-*.sql.gz' -mtime "+$RETENCAO_DIAS" -print -delete 2>/dev/null | wc -l)
    [ "$APAGADOS" -gt 0 ] && log "expurgados $APAGADOS backup(s) com mais de $RETENCAO_DIAS dias"

    RESTANTES=$(find "$DEST" -maxdepth 1 -name 'biomie-*.sql.gz' | wc -l)
    log "retidos $RESTANTES backup(s) · $(du -sh "$DEST" | cut -f1) no total"
    publicar_status "$RESTANTES"

    # A copia externa vem DEPOIS do dump validado e do expurgo: so faz sentido
    # levar para fora um arquivo que ja se provou integro.
    #
    # Falha aqui NAO invalida o backup local — ele existe e protege do risco mais
    # provavel. Mas tambem nao pode virar sucesso silencioso: sai no codigo 10,
    # que o log do cron registra e que se distingue de "o dump falhou" (1).
    if ! enviar_offsite "$ALVO"; then
        publicar_status "$RESTANTES"
        return 10
    fi
    publicar_status "$RESTANTES"
    return 0
}

log "start"
if command -v flock >/dev/null 2>&1; then
    ( flock -n 9 || exit 75; executar ) 9>"$LOCK_FILE"
    code=$?
else
    executar; code=$?
fi
[ "$code" -eq 75 ] && log "IGNORADO: outra execucao em andamento"
[ "$code" -eq 10 ] && log "ATENCAO: backup local OK, copia externa FALHOU"
log "finish exit=$code"
exit "$code"
