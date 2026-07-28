<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Central de Notificações — barramento único que alimenta o sininho in-app,
 * o painel admin e os digests Telegram. Multi-empresa: company_id NULL = global;
 * leitura é por usuário (biomie_notification_reads).
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260627120000';
    }

    public function getDescription(): string
    {
        return 'Cria biomie_notifications + biomie_notification_reads (central de notificacoes multi-empresa).';
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_notifications')) {
            $pdo->exec(<<<'SQL'
                CREATE TABLE biomie_notifications (
                    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    company_id   INT UNSIGNED NULL COMMENT 'NULL = global (todas as empresas)',
                    category     VARCHAR(32)  NOT NULL COMMENT 'sync|webhook|business|security|system',
                    severity     VARCHAR(16)  NOT NULL DEFAULT 'info' COMMENT 'info|success|warning|critical',
                    title        VARCHAR(200) NOT NULL,
                    body         TEXT NULL,
                    link         VARCHAR(255) NULL,
                    source       VARCHAR(64)  NULL,
                    dedupe_key   VARCHAR(191) NULL,
                    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at   DATETIME NULL,
                    INDEX idx_company_created (company_id, created_at),
                    INDEX idx_created (created_at),
                    INDEX idx_dedupe (dedupe_key, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        }

        if (!$this->tableExists($pdo, 'biomie_notification_reads')) {
            $pdo->exec(<<<'SQL'
                CREATE TABLE biomie_notification_reads (
                    notification_id BIGINT UNSIGNED NOT NULL,
                    user_id         INT UNSIGNED NOT NULL,
                    read_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (notification_id, user_id),
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS biomie_notification_reads');
        $pdo->exec('DROP TABLE IF EXISTS biomie_notifications');
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
