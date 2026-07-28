<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260517000002';
    }

    public function getDescription(): string
    {
        return 'Adiciona colunas bairro_key/cidade_key em marts geo. Chave tecnica estavel: lowercase, underscore, sem acento.';
    }

    public function up(\PDO $pdo): void
    {
        // mart_compradores_por_geo
        if ($this->tableExists($pdo, 'mart_compradores_por_geo')) {
            $this->maybeAddColumn($pdo, 'mart_compradores_por_geo', 'cidade_key',  "VARCHAR(120) NULL DEFAULT NULL AFTER cidade_norm");
            $this->maybeAddColumn($pdo, 'mart_compradores_por_geo', 'bairro_key',  "VARCHAR(160) NULL DEFAULT NULL AFTER bairro_norm");
            $this->maybeAddColumn($pdo, 'mart_compradores_por_geo', 'cidade_nome', "VARCHAR(120) NULL DEFAULT NULL AFTER cidade_key");
            $this->maybeAddColumn($pdo, 'mart_compradores_por_geo', 'bairro_nome', "VARCHAR(160) NULL DEFAULT NULL AFTER bairro_key");

            $this->maybeAddIndex(
                $pdo,
                'mart_compradores_por_geo',
                'idx_geo_buyers_city_bairro_key_comp',
                '(cidade_key, bairro_key, competencia)'
            );
        }

        // mart_vendas_por_cnae_cnpj
        if ($this->tableExists($pdo, 'mart_vendas_por_cnae_cnpj')) {
            $this->maybeAddColumn($pdo, 'mart_vendas_por_cnae_cnpj', 'cidade_key', "VARCHAR(120) NULL DEFAULT NULL");
            $this->maybeAddColumn($pdo, 'mart_vendas_por_cnae_cnpj', 'bairro_key', "VARCHAR(160) NULL DEFAULT NULL");

            $this->maybeAddIndex(
                $pdo,
                'mart_vendas_por_cnae_cnpj',
                'idx_cnae_city_bairro_key_comp',
                '(cidade_key, bairro_key, competencia)'
            );
        }

        // biomie_clientes_dim
        if ($this->tableExists($pdo, 'biomie_clientes_dim')) {
            $this->maybeAddColumn($pdo, 'biomie_clientes_dim', 'cidade_key', "VARCHAR(120) NULL DEFAULT NULL");
            $this->maybeAddColumn($pdo, 'biomie_clientes_dim', 'bairro_key', "VARCHAR(160) NULL DEFAULT NULL");

            $this->maybeAddIndex(
                $pdo,
                'biomie_clientes_dim',
                'idx_clientes_dim_city_bairro_key',
                '(cidade_key, bairro_key)'
            );
        }

        // biomie_clientes_geo_dim
        if ($this->tableExists($pdo, 'biomie_clientes_geo_dim')) {
            $this->maybeAddColumn($pdo, 'biomie_clientes_geo_dim', 'cidade_key', "VARCHAR(120) NULL DEFAULT NULL");
            $this->maybeAddColumn($pdo, 'biomie_clientes_geo_dim', 'bairro_key', "VARCHAR(160) NULL DEFAULT NULL");

            $this->maybeAddIndex(
                $pdo,
                'biomie_clientes_geo_dim',
                'idx_geo_dim_city_bairro_key',
                '(cidade_key, bairro_key)'
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        foreach ([
            'mart_compradores_por_geo'  => ['cidade_key', 'bairro_key', 'cidade_nome', 'bairro_nome'],
            'mart_vendas_por_cnae_cnpj' => ['cidade_key', 'bairro_key'],
            'biomie_clientes_dim'       => ['cidade_key', 'bairro_key'],
            'biomie_clientes_geo_dim'   => ['cidade_key', 'bairro_key'],
        ] as $table => $columns) {
            if (!$this->tableExists($pdo, $table)) {
                continue;
            }
            foreach ($columns as $column) {
                if ($this->columnExists($pdo, $table, $column)) {
                    $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
                }
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tableExists(\PDO $pdo, string $table): bool
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        )->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'"
        )->fetchColumn() > 0;
    }

    private function maybeAddColumn(\PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!$this->columnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function maybeAddIndex(\PDO $pdo, string $table, string $indexName, string $columns): void
    {
        $exists = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = '{$table}' AND index_name = '{$indexName}'"
        )->fetchColumn();

        if (!$exists) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` {$columns}");
        }
    }
};
