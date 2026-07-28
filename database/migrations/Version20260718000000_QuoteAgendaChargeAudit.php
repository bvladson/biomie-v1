<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * AGENDA DE ORÇAMENTOS — Fase 5: auditoria idempotente da cobrança.
 *
 * Registra TODA execução de "Cobrança realizada" e serve de trava de
 * idempotência. Contrato alinhado ao draft IETF Idempotency-Key:
 *
 *   · UNIQUE (company_id, idempotency_key) — a chave é por operação lógica
 *   · request_fingerprint (SHA-256 determinístico) detecta reuso indevido:
 *     mesma chave + fingerprint DIFERENTE ⇒ 422 Unprocessable Content
 *   · response_status/response_body_json guardam a resposta da 1ª execução;
 *     o replay devolve exatamente o mesmo status e corpo (+ Idempotency-Replayed)
 *   · completed_at NULL ⇒ operação ainda em andamento ⇒ 409
 *
 * A tabela é auditoria PERMANENTE: as chaves não expiram no MVP.
 *
 * FUSO: todos os timestamps em UTC (NOW() do banco).
 * `previous_*`/`new_*` guardam o antes/depois para trilha completa.
 *
 * Reversível: down() remove a tabela.
 */
return new class extends AbstractMigration {

    private const TABLE = 'biomie_quote_followup_charge_audit';

    public function getVersion(): string
    {
        return '20260718000000';
    }

    public function getDescription(): string
    {
        return 'Agenda de Orçamentos Fase 5: auditoria idempotente de cobrança (UNIQUE company+idempotency_key, fingerprint, replay de resposta).';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id BIGINT NOT NULL,
                followup_id BIGINT UNSIGNED NULL,
                omie_order_id BIGINT NOT NULL,

                idempotency_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
                    COMMENT 'UUID por operação lógica (client: crypto.randomUUID()). Collation binária: comparação exata.',
                request_fingerprint CHAR(64) NOT NULL
                    COMMENT 'SHA-256 determinístico do que altera o resultado. Mesma chave + fingerprint diferente = 422.',

                manager_user_id BIGINT NULL,

                previous_followup_step TINYINT UNSIGNED NULL,
                new_followup_step TINYINT UNSIGNED NULL,
                previous_charge_count INT UNSIGNED NULL,
                new_charge_count INT UNSIGNED NULL,
                previous_next_followup_on DATE NULL,
                new_next_followup_on DATE NULL,

                response_status SMALLINT UNSIGNED NULL COMMENT 'status da 1a execução — replay devolve o mesmo',
                response_body_json MEDIUMTEXT NULL COMMENT 'corpo da 1a execução — replay devolve o mesmo',

                charged_at DATETIME NULL COMMENT 'UTC — instante da cobrança efetiva',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC — reserva da chave',
                completed_at DATETIME NULL COMMENT 'UTC — NULL = ainda em andamento (⇒ 409)',

                PRIMARY KEY (id),
                UNIQUE KEY uq_quote_charge_idempotency (company_id, idempotency_key),
                KEY idx_quote_charge_followup (company_id, followup_id, charged_at),
                KEY idx_quote_charge_order (company_id, omie_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::TABLE));
    }

    public function down(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => self::TABLE]);
        if ((int) $stmt->fetchColumn() > 0) {
            $pdo->exec('DROP TABLE ' . self::TABLE);
        }
    }
};
