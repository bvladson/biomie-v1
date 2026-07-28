<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260502120000';
    }

    public function getDescription(): string
    {
        return 'Enriquece biomie_clientes_dim com documento, vendedor responsavel, geo e metricas; expande biomie_clientes_geo_dim.';
    }

    public function getConnectionName(): string
    {
        return 'canonical';
    }

    public function up(\PDO $pdo): void
    {
        // ── biomie_clientes_dim: new columns ─────────────────────────────────
        $dimAdditions = [
            'documento_normalizado'            => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `documento_normalizado` VARCHAR(20) NULL AFTER `cpf_cnpj`",
            'documento_mascarado'              => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `documento_mascarado` VARCHAR(30) NULL AFTER `documento_normalizado`",
            'tipo_pessoa'                      => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `tipo_pessoa` ENUM('PF','PJ','OUTRO') NULL AFTER `tipo_documento`",
            'cliente_nome_exibicao'            => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `cliente_nome_exibicao` VARCHAR(255) NULL AFTER `nome_fantasia`",
            'cidade'                           => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `cidade` VARCHAR(120) NULL",
            'uf'                               => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `uf` CHAR(2) NULL",
            'bairro'                           => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `bairro` VARCHAR(120) NULL",
            'cep'                              => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `cep` VARCHAR(10) NULL",
            'cidade_normalizada'               => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `cidade_normalizada` VARCHAR(120) NULL",
            'bairro_normalizado'               => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `bairro_normalizado` VARCHAR(120) NULL",
            'geo_status'                       => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `geo_status` VARCHAR(30) NULL DEFAULT 'pending_enrichment'",
            'vendedor_responsavel_codigo_omie' => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `vendedor_responsavel_codigo_omie` VARCHAR(32) NULL",
            'vendedor_responsavel_nome'        => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `vendedor_responsavel_nome` VARCHAR(120) NULL",
            'vendedor_responsavel_origem'      => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `vendedor_responsavel_origem` VARCHAR(30) NULL",
            'vendedor_responsavel_confidence'  => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `vendedor_responsavel_confidence` TINYINT UNSIGNED NOT NULL DEFAULT 0",
            'vendedor_responsavel_updated_at'  => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `vendedor_responsavel_updated_at` DATETIME NULL",
            'ultima_nf_em'                     => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `ultima_nf_em` DATE NULL",
            'ultima_nf_cancelada_em'           => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `ultima_nf_cancelada_em` DATE NULL",
            'ultimo_pedido_em'                 => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `ultimo_pedido_em` DATE NULL",
            'total_pedidos'                    => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `total_pedidos` INT UNSIGNED NOT NULL DEFAULT 0",
            'total_nf_autorizadas'             => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `total_nf_autorizadas` INT UNSIGNED NOT NULL DEFAULT 0",
            'total_nf_canceladas'              => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `total_nf_canceladas` INT UNSIGNED NOT NULL DEFAULT 0",
            'total_receita_autorizada'         => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `total_receita_autorizada` DECIMAL(15,2) NOT NULL DEFAULT 0",
            'total_cancelado'                  => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `total_cancelado` DECIMAL(15,2) NOT NULL DEFAULT 0",
            'enrichment_status'                => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `enrichment_status` VARCHAR(50) NULL",
            'source_priority'                  => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `source_priority` TINYINT UNSIGNED NOT NULL DEFAULT 99",
            'created_at'                       => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at'                       => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];

        foreach ($dimAdditions as $col => $sql) {
            if (!$this->columnExists($pdo, 'biomie_clientes_dim', $col)) {
                $pdo->exec($sql);
            }
        }

        // ── biomie_clientes_dim: new indexes ─────────────────────────────────
        $dimIndexes = [
            'idx_clientes_dim_doc'     => "ALTER TABLE `biomie_clientes_dim` ADD INDEX `idx_clientes_dim_doc` (`documento_normalizado`)",
            'idx_clientes_dim_vend'    => "ALTER TABLE `biomie_clientes_dim` ADD INDEX `idx_clientes_dim_vend` (`vendedor_responsavel_codigo_omie`)",
            'idx_clientes_dim_geo'     => "ALTER TABLE `biomie_clientes_dim` ADD INDEX `idx_clientes_dim_geo` (`uf`, `cidade_normalizada`)",
            'idx_clientes_dim_updated' => "ALTER TABLE `biomie_clientes_dim` ADD INDEX `idx_clientes_dim_updated` (`updated_at`)",
        ];

        foreach ($dimIndexes as $idxName => $sql) {
            if (!$this->indexExists($pdo, 'biomie_clientes_dim', $idxName)) {
                $pdo->exec($sql);
            }
        }

        // ── biomie_clientes_geo_dim: new columns ─────────────────────────────
        if ($this->tableExists($pdo, 'biomie_clientes_geo_dim')) {
            $geoAdditions = [
                'cliente_dim_id'        => "ALTER TABLE `biomie_clientes_geo_dim` ADD COLUMN `cliente_dim_id` BIGINT UNSIGNED NULL",
                'documento_normalizado' => "ALTER TABLE `biomie_clientes_geo_dim` ADD COLUMN `documento_normalizado` VARCHAR(20) NULL",
                'cliente_nome_exibicao' => "ALTER TABLE `biomie_clientes_geo_dim` ADD COLUMN `cliente_nome_exibicao` VARCHAR(255) NULL",
                'geo_status'            => "ALTER TABLE `biomie_clientes_geo_dim` ADD COLUMN `geo_status` VARCHAR(30) NULL DEFAULT 'pending_enrichment'",
            ];

            foreach ($geoAdditions as $col => $sql) {
                if (!$this->columnExists($pdo, 'biomie_clientes_geo_dim', $col)) {
                    $pdo->exec($sql);
                }
            }

            if (!$this->indexExists($pdo, 'biomie_clientes_geo_dim', 'idx_geo_dim_doc')) {
                $pdo->exec("ALTER TABLE `biomie_clientes_geo_dim` ADD INDEX `idx_geo_dim_doc` (`documento_normalizado`)");
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        // Reversible: remove only the columns added by this migration.
        // Existing columns (cpf_cnpj, nome_cliente, etc.) are untouched.
        $removeDim = [
            'documento_normalizado', 'documento_mascarado', 'tipo_pessoa',
            'cliente_nome_exibicao', 'cidade', 'uf', 'bairro', 'cep',
            'cidade_normalizada', 'bairro_normalizado', 'geo_status',
            'vendedor_responsavel_codigo_omie', 'vendedor_responsavel_nome',
            'vendedor_responsavel_origem', 'vendedor_responsavel_confidence',
            'vendedor_responsavel_updated_at', 'ultima_nf_em',
            'ultima_nf_cancelada_em', 'ultimo_pedido_em', 'total_pedidos',
            'total_nf_autorizadas', 'total_nf_canceladas',
            'total_receita_autorizada', 'total_cancelado',
            'enrichment_status', 'source_priority', 'created_at', 'updated_at',
        ];

        foreach ($removeDim as $col) {
            if ($this->columnExists($pdo, 'biomie_clientes_dim', $col)) {
                $pdo->exec("ALTER TABLE `biomie_clientes_dim` DROP COLUMN `{$col}`");
            }
        }

        if ($this->tableExists($pdo, 'biomie_clientes_geo_dim')) {
            foreach (['cliente_dim_id', 'documento_normalizado', 'cliente_nome_exibicao', 'geo_status'] as $col) {
                if ($this->columnExists($pdo, 'biomie_clientes_geo_dim', $col)) {
                    $pdo->exec("ALTER TABLE `biomie_clientes_geo_dim` DROP COLUMN `{$col}`");
                }
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i"
        );
        $stmt->execute(['t' => $table, 'i' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
        );
        $stmt->execute(['t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
