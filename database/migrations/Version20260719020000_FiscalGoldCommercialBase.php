<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    private const TABLE = 'mart_fiscal_nfe';

    public function getVersion(): string
    {
        return '20260719020000';
    }

    public function getDescription(): string
    {
        return 'Preserva a base comercial reconciliada em cada NF do mart fiscal Gold.';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::TABLE)
            || $this->columnExists($pdo, self::TABLE, 'valor_base_comercial_nf')) {
            return;
        }

        $pdo->exec(
            'ALTER TABLE ' . self::TABLE . '
             ADD COLUMN valor_base_comercial_nf DECIMAL(18,2) NULL AFTER valor_total'
        );
    }

    public function down(PDO $pdo): void
    {
        if ($this->tableExists($pdo, self::TABLE)
            && $this->columnExists($pdo, self::TABLE, 'valor_base_comercial_nf')) {
            $pdo->exec(
                'ALTER TABLE ' . self::TABLE . ' DROP COLUMN valor_base_comercial_nf'
            );
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
