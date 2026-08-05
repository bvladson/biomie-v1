<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * A CAUSA MAJORITARIA DAS FALHAS DE SYNC — o ENUM do banco ficou para tras.
 *
 * ─── O QUE ESTAVA ACONTECENDO ───────────────────────────────────────────────
 * `CanonicalSyncStateRepository::prepareSyncState()` grava `phase = 'chunked'`.
 * O `CREATE TABLE IF NOT EXISTS` do proprio repositorio declara
 *
 *     phase ENUM('idle','vendors_synced','orders_running','completed','failed','chunked')
 *
 * mas a tabela EM PRODUCAO nao tem 'chunked':
 *
 *     phase ENUM('idle','vendors_synced','orders_running','completed','failed')
 *
 * `CREATE TABLE IF NOT EXISTS` nunca altera tabela que ja existe. O codigo
 * evoluiu, o banco nao, e ninguem percebeu porque a divergencia nao tem
 * sintoma proprio — ela so aparece na hora de gravar.
 *
 * Sob STRICT_TRANS_TABLES o INSERT vira SQLSTATE[01000] 1265 "Data truncated
 * for column 'phase'" e derruba a run inteira: 224 execucoes de sync so nas
 * primeiras horas de instrumentacao. ANTES do STRICT, o mesmo write virava a
 * string vazia em SILENCIO — por isso 504 das 505 linhas da tabela estao com
 * `phase = ''`, o sentinela de ENUM invalido do MySQL.
 *
 * Ou seja: as falhas de agora nao sao uma regressao. Sao a corrupcao antiga
 * finalmente ficando visivel.
 *
 * ─── ORDEM DAS OPERACOES (importa) ──────────────────────────────────────────
 * O reparo das linhas vem ANTES do ALTER, de proposito. Um `ALTER TABLE MODIFY`
 * sobre coluna com valor invalido presente pode falhar sob STRICT — e apostar
 * no comportamento do servidor dentro de uma migration de producao seria trocar
 * um problema conhecido por um desconhecido. Reparando primeiro para membros
 * que JA sao validos, o ALTER encontra a coluna limpa.
 *
 * ─── COMO AS 504 LINHAS SAO REPARADAS ───────────────────────────────────────
 * Pelo que a propria linha diz, nao pelo que supomos que ela queria dizer:
 *   · `completed_at` preenchido        -> 'completed'
 *   · sem conclusao e com `last_error`  -> 'failed'
 *   · o resto                          -> 'idle' (o default da coluna)
 *
 * O ultimo grupo NAO vira 'chunked'. Seria a suposicao mais provavel — e
 * exatamente por isso nao entra: sao estados abandonados ha meses, e afirmar a
 * fase em que pararam nao acrescenta nada que `checkpoint_date` e `started_at`
 * ja nao registrem. Normalizar para o default e honesto; inferir nao.
 *
 * Sem tabela de auditoria aqui, ao contrario do reparo de `tipo_pessoa`: aquilo
 * era dado de negocio que alimentava os marts; isto e metadado operacional de
 * sync, e a trilha (periodo, checkpoint, timestamps) permanece intacta na
 * propria linha.
 */
return new class extends AbstractMigration {

    private const T = 'biomie_sync_state';

    /** Dominio que o codigo ja declara em CanonicalSyncStateRepository. */
    private const DOMINIO = "'idle','vendors_synced','orders_running','completed','failed','chunked'";

    public function getVersion(): string
    {
        return '20260805120000';
    }

    public function getDescription(): string
    {
        return "Alinha o ENUM phase de biomie_sync_state ao dominio do codigo ('chunked') e repara o sentinela ''.";
    }

    public function up(PDO $pdo): void
    {
        $tabela = self::T;

        // 1. Sentinela fora do caminho, usando apenas membros ja validos.
        $pdo->exec("UPDATE {$tabela} SET phase = 'completed'
                     WHERE phase = '' AND completed_at IS NOT NULL");
        $pdo->exec("UPDATE {$tabela} SET phase = 'failed'
                     WHERE phase = '' AND completed_at IS NULL
                       AND last_error IS NOT NULL AND last_error <> ''");
        $pdo->exec("UPDATE {$tabela} SET phase = 'idle' WHERE phase = ''");

        // 2. Agora sim: o banco passa a aceitar o que o codigo grava.
        $pdo->exec(sprintf(
            "ALTER TABLE %s MODIFY COLUMN phase ENUM(%s) NOT NULL DEFAULT 'idle'",
            $tabela,
            self::DOMINIO
        ));
    }

    public function down(PDO $pdo): void
    {
        // Estreitar de volta exigiria decidir o que fazer com as linhas que
        // estiverem em 'chunked' — e reintroduziria a falha que esta migration
        // existe para acabar. O caminho de volta e reverter o codigo, nao o
        // dominio da coluna.
        $pdo->exec(sprintf(
            "ALTER TABLE %s MODIFY COLUMN phase ENUM(%s) NOT NULL DEFAULT 'idle'",
            self::T,
            self::DOMINIO
        ));
    }
};
