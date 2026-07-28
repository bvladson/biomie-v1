<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260623000000';
    }

    public function getDescription(): string
    {
        return 'Fecha registros sem empresa, isola acoes de cobranca e impede company_id zero nos read models legados.';
    }

    public function up(\PDO $pdo): void
    {
        $this->isolateFinanceCollectionActions($pdo);
        $this->removeInvalidTenantRows($pdo);

        foreach ([
            'biomie_finance_collection_actions',
            'biomie_clientes_dim',
            'biomie_clientes_geo_dim',
            'biomie_clientes_para_enriquecer',
            'mart_forecasts',
            'mart_oportunidades',
        ] as $table) {
            $this->addPositiveCompanyConstraint($pdo, $table);
        }
    }

    public function down(\PDO $pdo): void
    {
        // Isolamento e limpeza de registros sem empresa nao devem ser revertidos.
    }

    private function isolateFinanceCollectionActions(\PDO $pdo): void
    {
        $table = 'biomie_finance_collection_actions';
        if (!$this->tableExists($pdo, $table)) {
            return;
        }

        if (!$this->columnExists($pdo, $table, 'company_id')) {
            $pdo->exec(
                "ALTER TABLE `{$table}`
                 ADD COLUMN company_id TINYINT UNSIGNED NULL AFTER id"
            );
        }

        $pdo->exec(<<<'SQL'
            UPDATE biomie_finance_collection_actions a
            JOIN (
                SELECT titulo_key, MIN(company_id) AS company_id
                FROM biomie_titulos_financeiros_canonicos
                WHERE company_id > 0
                GROUP BY titulo_key
                HAVING COUNT(DISTINCT company_id) = 1
            ) t ON t.titulo_key = a.titulo_key
            SET a.company_id = t.company_id
            WHERE a.company_id IS NULL OR a.company_id = 0
        SQL);

        // Acoes antigas sem correspondencia unica nao podem ser expostas a uma empresa arbitraria.
        $pdo->exec(
            'DELETE FROM biomie_finance_collection_actions
             WHERE company_id IS NULL OR company_id = 0'
        );
        $pdo->exec(
            "ALTER TABLE `{$table}`
             MODIFY COLUMN company_id TINYINT UNSIGNED NOT NULL"
        );

        $this->addIndex(
            $pdo,
            $table,
            'idx_fin_collection_company_title_created',
            'company_id, titulo_key, created_at'
        );
        $this->addIndex(
            $pdo,
            $table,
            'idx_fin_collection_company_client_created',
            'company_id, cliente_codigo_omie, created_at'
        );
        $this->addIndex(
            $pdo,
            $table,
            'idx_fin_collection_company_vendor_created',
            'company_id, vendedor_codigo_omie, created_at'
        );
    }

    private function removeInvalidTenantRows(\PDO $pdo): void
    {
        // Ordem evita manter filas e dimensoes filhas apontando para dados sem empresa.
        foreach ([
            'biomie_clientes_para_enriquecer',
            'biomie_clientes_geo_dim',
            'biomie_clientes_dim',
            'mart_forecasts',
            'mart_oportunidades',
        ] as $table) {
            if ($this->tableExists($pdo, $table) && $this->columnExists($pdo, $table, 'company_id')) {
                $pdo->exec("DELETE FROM `{$table}` WHERE company_id = 0");
            }
        }
    }

    private function addPositiveCompanyConstraint(\PDO $pdo, string $table): void
    {
        if (!$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, 'company_id')) {
            return;
        }

        $constraint = 'chk_' . substr(preg_replace('/[^a-z0-9_]+/i', '_', $table) ?: 'tenant', 0, 48) . '_company_pos';
        if ($this->constraintExists($pdo, $table, $constraint)) {
            return;
        }

        $pdo->exec(
            "ALTER TABLE `{$table}`
             ADD CONSTRAINT `{$constraint}` CHECK (company_id > 0)"
        );
    }

    private function addIndex(\PDO $pdo, string $table, string $index, string $columns): void
    {
        if (!$this->indexExists($pdo, $table, $index)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        }
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column_name'
        );
        $stmt->execute(['table' => $table, 'column_name' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index_name'
        );
        $stmt->execute(['table' => $table, 'index_name' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function constraintExists(\PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND CONSTRAINT_NAME = :constraint_name'
        );
        $stmt->execute(['table' => $table, 'constraint_name' => $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
