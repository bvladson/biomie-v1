<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260608000000';
    }

    public function getDescription(): string
    {
        return 'Cria regras de exclusao fisica persistente para impedir reinsercao pela sincronizacao canonica.';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_data_exclusion_rules (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type VARCHAR(40) NOT NULL DEFAULT 'generic',
                identifier_type VARCHAR(64) NOT NULL,
                identifier_value VARCHAR(191) NOT NULL,
                reason VARCHAR(255) NOT NULL DEFAULT '',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_data_exclusion_identifier (identifier_type, identifier_value),
                KEY idx_data_exclusion_active (active, entity_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS biomie_data_exclusion_rules');
    }
};
