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

DB="${BIOMIE_DB_NAME:-biomie}"
DEST="${BIOMIE_BACKUP_DIR:-/var/backups/biomie}"
RETENCAO_DIAS="${BIOMIE_BACKUP_RETENCAO:-14}"
LOG_DIR="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}/storage/logs/cron"
LOG_FILE="$LOG_DIR/db_backup.log"
LOCK_FILE="${BIOMIE_PROJECT_ROOT:-/var/www/biomie/live}/storage/locks/db_backup.lock"

mkdir -p "$DEST" "$LOG_DIR" "$(dirname "$LOCK_FILE")" || exit 1
chmod 700 "$DEST"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] db_backup $*" >> "$LOG_FILE"; }

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

    TAMANHO=$(du -h "$ALVO" | cut -f1)
    TABELAS=$(gzip -dc "$ALVO" | grep -c "^CREATE TABLE")
    log "OK $(basename "$ALVO") $TAMANHO $TABELAS tabelas"

    # Expurgo SO depois do dump novo estar validado: apagar o antigo antes
    # deixaria uma janela sem backup nenhum se o novo falhasse.
    APAGADOS=$(find "$DEST" -maxdepth 1 -name 'biomie-*.sql.gz' -mtime "+$RETENCAO_DIAS" -print -delete 2>/dev/null | wc -l)
    [ "$APAGADOS" -gt 0 ] && log "expurgados $APAGADOS backup(s) com mais de $RETENCAO_DIAS dias"

    RESTANTES=$(find "$DEST" -maxdepth 1 -name 'biomie-*.sql.gz' | wc -l)
    log "retidos $RESTANTES backup(s) · $(du -sh "$DEST" | cut -f1) no total"
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
log "finish exit=$code"
exit "$code"
