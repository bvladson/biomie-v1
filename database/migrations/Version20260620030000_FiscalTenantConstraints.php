<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260620030000';
    }

    public function getDescription(): string
    {
        return 'Torna itens de entrada fiscal e sua chave estrangeira isolados por empresa.';
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'fiscal_purchase_entry_items')
            || !$this->tableExists($pdo, 'fiscal_purchase_entries')
        ) {
            return;
        }

        if ($this->foreignKeyExists($pdo, 'fiscal_purchase_entry_items', 'fk_fiscal_purchase_item_entry')) {
            $pdo->exec(
                'ALTER TABLE fiscal_purchase_entry_items
                 DROP FOREIGN KEY fk_fiscal_purchase_item_entry'
            );
        }

        if ($this->indexExists($pdo, 'fiscal_purchase_entry_items', 'uq_fiscal_purchase_item')) {
            $pdo->exec(
                'ALTER TABLE fiscal_purchase_entry_items
                 DROP INDEX uq_fiscal_purchase_item'
            );
        }

        if (!$this->indexExists($pdo, 'fiscal_purchase_entry_items', 'uq_company_fiscal_purchase_item')) {
            $pdo->exec(
                'ALTER TABLE fiscal_purchase_entry_items
                 ADD UNIQUE KEY uq_company_fiscal_purchase_item
                    (company_id, entry_key, item_sequence)'
            );
        }

        if (!$this->foreignKeyExists($pdo, 'fiscal_purchase_entry_items', 'fk_company_fiscal_purchase_item_entry')) {
            $pdo->exec(
                'ALTER TABLE fiscal_purchase_entry_items
                 ADD CONSTRAINT fk_company_fiscal_purchase_item_entry
                 FOREIGN KEY (company_id, entry_key)
                 REFERENCES fiscal_purchase_entries (company_id, entry_key)
                 ON DELETE CASCADE'
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'fiscal_purchase_entry_items')) {
            return;
        }

        if ($this->foreignKeyExists($pdo, 'fiscal_purchase_entry_items', 'fk_company_fiscal_purchase_item_entry')) {
            $pdo->exec(
                'ALTER TABLE fiscal_purchase_entry_items
                 DROP FOREIGN KEY fk_company_fiscal_purchase_item_entry'
            );
        }
        if ($this->indexExists($pdo, 'fiscal_purchase_entry_items', 'uq_company_fiscal_purchase_item')) {
            $pdo->exec(
                'ALTER TABLE fiscal_purchase_entry_items
                 DROP INDEX uq_company_fiscal_purchase_item'
            );
        }
        if (!$this->indexExists($pdo, 'fiscal_purchase_entry_items', 'uq_fiscal_purchase_item')) {
            $pdo->exec(
                'ALTER TABLE fiscal_purchase_entry_items
                 ADD UNIQUE KEY uq_fiscal_purchase_item (entry_key, item_sequence)'
            );
        }
        if (!$this->foreignKeyExists($pdo, 'fiscal_purchase_entry_items', 'fk_fiscal_purchase_item_entry')) {
            $pdo->exec(
                'ALTER TABLE fiscal_purchase_entry_items
                 ADD CONSTRAINT fk_fiscal_purchase_item_entry
                 FOREIGN KEY (entry_key)
                 REFERENCES fiscal_purchase_entries (entry_key)
                 ON DELETE CASCADE'
            );
        }
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index_name'
        );
        $stmt->execute(['table' => $table, 'index_name' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function foreignKeyExists(\PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA=DATABASE()
               AND TABLE_NAME=:table
               AND CONSTRAINT_NAME=:constraint_name
               AND CONSTRAINT_TYPE=\'FOREIGN KEY\''
        );
        $stmt->execute(['table' => $table, 'constraint_name' => $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
