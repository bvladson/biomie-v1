<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260621040000';
    }

    public function getDescription(): string
    {
        return 'Cria trilha auditavel para redacoes de seguranca em migrations historicas.';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_security_migration_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                schema_version VARCHAR(32) NOT NULL,
                old_checksum CHAR(64) NOT NULL,
                new_checksum CHAR(64) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_security_migration_version (schema_version, changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_security_migration_audit']);
    }
};
