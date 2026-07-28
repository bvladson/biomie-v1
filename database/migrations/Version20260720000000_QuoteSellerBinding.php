<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * AGENDA DE ORÇAMENTOS — Fase 10: vínculo usuário ↔ vendedor Omie.
 *
 * Pré-requisito da visão do VENDEDOR: para mostrar "a carteira dele" é preciso
 * saber qual `seller_id` (código Omie) corresponde à conta BIomie logada. Não
 * existe vínculo automático confiável — o e-mail cobre só ~5% do roster — então
 * o mapa é MANUAL, mantido no Admin.
 *
 * Modelo:
 *   · UNIQUE (company_id, seller_id) — cada código Omie pertence a UM usuário.
 *   · Um usuário PODE ter vários códigos (multi-empresa e multi-canal; ex.:
 *     o mesmo vendedor com códigos distintos em Varejo e Revenda, como já
 *     tratado no VendorAliasResolver). Por isso a chave não inclui user_id.
 *   · created_by registra quem fez o vínculo (trilha de quem-mapeou-quem).
 *
 * NÃO concede acesso por si só: o acesso vem da capability
 * `sales.quotes.followup.self` (papéis de vendedor) + do vínculo aqui. Sem os
 * dois, o vendedor não vê nada.
 *
 * FUSO: timestamps em UTC (NOW() do banco).
 * Reversível: down() remove a tabela.
 */
return new class extends AbstractMigration {

    private const TABLE = 'biomie_quote_seller_binding';

    public function getVersion(): string
    {
        return '20260720000000';
    }

    public function getDescription(): string
    {
        return 'Agenda de Orçamentos Fase 10: vínculo manual usuário↔vendedor Omie (UNIQUE company+seller_id; um usuário pode ter vários códigos).';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT NOT NULL COMMENT 'biomie_users.id',
                company_id BIGINT NOT NULL,
                seller_id VARCHAR(32) NOT NULL
                    COMMENT 'codigo_omie do vendedor (mesmo domínio de followup.seller_id)',

                created_by BIGINT NULL COMMENT 'quem fez o vínculo (biomie_users.id)',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                UNIQUE KEY uq_seller_binding (company_id, seller_id),
                KEY idx_seller_binding_user (user_id)
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
