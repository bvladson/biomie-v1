<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    private const TABLE = 'biomie_notas_fiscais_canonicas';

    public function getVersion(): string
    {
        return '20260718010000';
    }

    public function getDescription(): string
    {
        return 'Preserva projeto Omie e garante identidade unica company+nota_id_omie na NF canonica.';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::TABLE)) {
            return;
        }

        if (!$this->indexExists($pdo, self::TABLE, 'uq_company_nf_omie_id')) {
            $duplicates = (int) $pdo->query(
                'SELECT COUNT(*) FROM (
                    SELECT company_id, nota_id_omie
                      FROM ' . self::TABLE . '
                     WHERE nota_id_omie IS NOT NULL
                     GROUP BY company_id, nota_id_omie
                    HAVING COUNT(*) > 1
                ) duplicate_identities'
            )->fetchColumn();
            if ($duplicates > 0) {
                throw new RuntimeException(
                    'Existem identidades Omie de NF duplicadas; reconcilie sem apagar dados antes de criar uq_company_nf_omie_id.'
                );
            }
        }

        if (!$this->columnExists($pdo, self::TABLE, 'projeto_id_omie')) {
            $pdo->exec(
                'ALTER TABLE ' . self::TABLE . '
                 ADD COLUMN projeto_id_omie BIGINT NULL AFTER pedido_id_omie'
            );
        }

        if (!$this->indexExists($pdo, self::TABLE, 'idx_nf_company_project')) {
            $pdo->exec(
                'ALTER TABLE ' . self::TABLE . '
                 ADD INDEX idx_nf_company_project (company_id, projeto_id_omie)'
            );
        }

        if (!$this->indexExists($pdo, self::TABLE, 'uq_company_nf_omie_id')) {
            $pdo->exec(
                'ALTER TABLE ' . self::TABLE . '
                 ADD UNIQUE KEY uq_company_nf_omie_id (company_id, nota_id_omie)'
            );
        }
    }

    public function down(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::TABLE)) {
            return;
        }

        if ($this->indexExists($pdo, self::TABLE, 'idx_nf_company_project')) {
            $pdo->exec('ALTER TABLE ' . self::TABLE . ' DROP INDEX idx_nf_company_project');
        }
        if ($this->indexExists($pdo, self::TABLE, 'uq_company_nf_omie_id')) {
            $pdo->exec('ALTER TABLE ' . self::TABLE . ' DROP INDEX uq_company_nf_omie_id');
        }
        if ($this->columnExists($pdo, self::TABLE, 'projeto_id_omie')) {
            $pdo->exec('ALTER TABLE ' . self::TABLE . ' DROP COLUMN projeto_id_omie');
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
