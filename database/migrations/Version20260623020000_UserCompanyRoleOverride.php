<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260623020000';
    }

    public function getDescription(): string
    {
        return 'Adiciona role_override em biomie_user_companies para permissoes por empresa (matriz usuario x empresa x capacidade).';
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'biomie_user_companies', 'role_override')) {
            $pdo->exec(
                "ALTER TABLE `biomie_user_companies`
                 ADD COLUMN `role_override` VARCHAR(20) NULL DEFAULT NULL
                 COMMENT 'Quando definido, substitui o role global do usuario para esta empresa. Valores: viewer|analyst|manager|admin'
                 AFTER `default_canal`"
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        if ($this->columnExists($pdo, 'biomie_user_companies', 'role_override')) {
            $pdo->exec('ALTER TABLE `biomie_user_companies` DROP COLUMN `role_override`');
        }
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
        );
        $stmt->execute(['t' => $table, 'i' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
