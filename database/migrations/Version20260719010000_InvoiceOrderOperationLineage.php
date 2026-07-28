<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    private const TABLE = 'biomie_notas_fiscais_canonicas';

    public function getVersion(): string
    {
        return '20260719010000';
    }

    public function getDescription(): string
    {
        return 'Preserva o codigo pedido.opPedido como lineage proprio da NF canonica.';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::TABLE)) {
            return;
        }

        if (!$this->columnExists($pdo, self::TABLE, 'pedido_operacao_omie')) {
            $pdo->exec(
                'ALTER TABLE ' . self::TABLE . '
                 ADD COLUMN pedido_operacao_omie VARCHAR(64) NULL AFTER projeto_id_omie'
            );
        }

        if (!$this->indexExists($pdo, self::TABLE, 'idx_nf_company_order_operation')) {
            $pdo->exec(
                'ALTER TABLE ' . self::TABLE . '
                 ADD INDEX idx_nf_company_order_operation (company_id, pedido_operacao_omie)'
            );
        }
    }

    public function down(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::TABLE)) {
            return;
        }

        if ($this->indexExists($pdo, self::TABLE, 'idx_nf_company_order_operation')) {
            $pdo->exec('ALTER TABLE ' . self::TABLE . ' DROP INDEX idx_nf_company_order_operation');
        }
        if ($this->columnExists($pdo, self::TABLE, 'pedido_operacao_omie')) {
            $pdo->exec('ALTER TABLE ' . self::TABLE . ' DROP COLUMN pedido_operacao_omie');
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

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND INDEX_NAME = :index_name'
        );
        $stmt->execute(['table_name' => $table, 'index_name' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
