<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getConnectionName(): string
    {
        return 'primary';
    }

    public function getVersion(): string
    {
        return '20260623010000';
    }

    public function getDescription(): string
    {
        return 'Adiciona indices compostos para autorizacao administrativa e bloqueio de tentativas de login.';
    }

    public function up(\PDO $pdo): void
    {
        $this->addIndex($pdo, 'biomie_users', 'idx_biomie_users_active_role', 'is_active, role');
        $this->addIndex(
            $pdo,
            'biomie_access_logs',
            'idx_biomie_access_logs_event_username_created',
            'event_type, username_attempted, created_at'
        );
        $this->addIndex(
            $pdo,
            'biomie_access_logs',
            'idx_biomie_access_logs_event_ip_created',
            'event_type, ip_address, created_at'
        );
    }

    public function down(\PDO $pdo): void
    {
        $this->dropIndex($pdo, 'biomie_access_logs', 'idx_biomie_access_logs_event_ip_created');
        $this->dropIndex($pdo, 'biomie_access_logs', 'idx_biomie_access_logs_event_username_created');
        $this->dropIndex($pdo, 'biomie_users', 'idx_biomie_users_active_role');
    }

    private function addIndex(\PDO $pdo, string $table, string $index, string $columns): void
    {
        if ($this->tableExists($pdo, $table) && !$this->indexExists($pdo, $table, $index)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        }
    }

    private function dropIndex(\PDO $pdo, string $table, string $index): void
    {
        if ($this->tableExists($pdo, $table) && $this->indexExists($pdo, $table, $index)) {
            $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             LIMIT 1'
        );
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index_name
             LIMIT 1'
        );
        $stmt->execute(['table' => $table, 'index_name' => $index]);
        return (bool) $stmt->fetchColumn();
    }
};
