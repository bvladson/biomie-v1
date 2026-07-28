<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260611000000';
    }

    public function getDescription(): string
    {
        return 'Adiciona hash da fonte materializada para evitar reescrita de clientes sem mudanca.';
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'biomie_clientes_dim', 'materialization_hash')) {
            $pdo->exec(
                'ALTER TABLE biomie_clientes_dim '
                . 'ADD COLUMN materialization_hash CHAR(64) NULL AFTER source_priority'
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        if ($this->columnExists($pdo, 'biomie_clientes_dim', 'materialization_hash')) {
            $pdo->exec('ALTER TABLE biomie_clientes_dim DROP COLUMN materialization_hash');
        }
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
