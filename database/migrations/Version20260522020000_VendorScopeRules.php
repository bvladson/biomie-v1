<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260522020000';
    }

    public function getDescription(): string
    {
        return 'Fase L: cria tabela biomie_vendor_scope_rules — regras de visibilidade comercial por vendedor/unidade de negócio (licitação vs. comercial).';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_vendor_scope_rules (
                id                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo_vendedor_omie         VARCHAR(32)  NOT NULL DEFAULT '',
                nome_vendedor                VARCHAR(120) NOT NULL DEFAULT '',
                nome_normalizado             VARCHAR(120) NOT NULL DEFAULT '',
                business_unit_scope          VARCHAR(40)  NOT NULL DEFAULT 'comercial'
                    COMMENT 'comercial | licitacao | outros',
                is_biomie_commercial_visible TINYINT(1)   NOT NULL DEFAULT 1
                    COMMENT '1 = aparece no BIomie comercial, 0 = excluído',
                reason                       VARCHAR(255) NULL,
                active                       TINYINT(1)   NOT NULL DEFAULT 1,
                created_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_vendor_scope_code (codigo_vendedor_omie),
                KEY idx_vendor_scope_active (active, is_biomie_commercial_visible),
                KEY idx_vendor_scope_normalized (nome_normalizado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS biomie_vendor_scope_rules');
    }
};
