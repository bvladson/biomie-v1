<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260501220000';
    }

    public function getDescription(): string
    {
        return 'Historico de testes de conexao e status checks Omie.';
    }

    public function getConnectionName(): string
    {
        return 'canonical';
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSql($pdo, <<<'SQL'
CREATE TABLE IF NOT EXISTS biomie_omie_status_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NULL,
    check_type VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    endpoint VARCHAR(255) NULL,
    method VARCHAR(128) NULL,
    latency_ms INT UNSIGNED NULL,
    message TEXT NULL,
    error_code VARCHAR(64) NULL,
    error_message_sanitized TEXT NULL,
    metadata_json LONGTEXT NULL,
    actor_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_check_company_created (company_id, created_at),
    KEY idx_check_type_created (check_type, created_at),
    KEY idx_check_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_omie_status_checks']);
    }
};
