<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260522030000';
    }

    public function getDescription(): string
    {
        return 'Fase L: adiciona colunas de visibilidade comercial (is_biomie_commercial_visible, business_unit_scope, visibility_rule_source, visibility_checked_at) nas tabelas canônicas e na dimensão de vendedores.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        // ── biomie_notas_fiscais_canonicas ─────────────────────────────────────
        $this->maybeAddColumn(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'business_unit_scope',
            "ALTER TABLE biomie_notas_fiscais_canonicas
             ADD COLUMN business_unit_scope VARCHAR(40) NULL DEFAULT NULL
             COMMENT 'comercial | licitacao | outros'
             AFTER operation_scope_checked_at"
        );
        $this->maybeAddColumn(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'is_biomie_commercial_visible',
            "ALTER TABLE biomie_notas_fiscais_canonicas
             ADD COLUMN is_biomie_commercial_visible TINYINT(1) NOT NULL DEFAULT 1
             COMMENT 'Fase L: 0 = excluido do BIomie comercial'
             AFTER business_unit_scope"
        );
        $this->maybeAddColumn(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'visibility_rule_source',
            "ALTER TABLE biomie_notas_fiscais_canonicas
             ADD COLUMN visibility_rule_source VARCHAR(80) NULL DEFAULT NULL
             AFTER is_biomie_commercial_visible"
        );
        $this->maybeAddColumn(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'visibility_checked_at',
            "ALTER TABLE biomie_notas_fiscais_canonicas
             ADD COLUMN visibility_checked_at DATETIME NULL DEFAULT NULL
             AFTER visibility_rule_source"
        );
        $this->maybeAddIndex(
            $pdo,
            'biomie_notas_fiscais_canonicas',
            'idx_nf_visibility_scope',
            ['is_biomie_commercial_visible', 'status_fiscal', 'data_competencia'],
            "ALTER TABLE biomie_notas_fiscais_canonicas
             ADD INDEX idx_nf_visibility_scope (is_biomie_commercial_visible, status_fiscal, data_competencia)"
        );

        // ── biomie_pedidos_canonicos ───────────────────────────────────────────
        $this->maybeAddColumn(
            $pdo,
            'biomie_pedidos_canonicos',
            'business_unit_scope',
            "ALTER TABLE biomie_pedidos_canonicos
             ADD COLUMN business_unit_scope VARCHAR(40) NULL DEFAULT NULL
             COMMENT 'comercial | licitacao | outros'
             AFTER operation_scope_checked_at"
        );
        $this->maybeAddColumn(
            $pdo,
            'biomie_pedidos_canonicos',
            'is_biomie_commercial_visible',
            "ALTER TABLE biomie_pedidos_canonicos
             ADD COLUMN is_biomie_commercial_visible TINYINT(1) NOT NULL DEFAULT 1
             COMMENT 'Fase L: 0 = excluido do BIomie comercial'
             AFTER business_unit_scope"
        );
        $this->maybeAddColumn(
            $pdo,
            'biomie_pedidos_canonicos',
            'visibility_rule_source',
            "ALTER TABLE biomie_pedidos_canonicos
             ADD COLUMN visibility_rule_source VARCHAR(80) NULL DEFAULT NULL
             AFTER is_biomie_commercial_visible"
        );
        $this->maybeAddColumn(
            $pdo,
            'biomie_pedidos_canonicos',
            'visibility_checked_at',
            "ALTER TABLE biomie_pedidos_canonicos
             ADD COLUMN visibility_checked_at DATETIME NULL DEFAULT NULL
             AFTER visibility_rule_source"
        );
        $this->maybeAddIndex(
            $pdo,
            'biomie_pedidos_canonicos',
            'idx_ped_visibility_scope',
            ['is_biomie_commercial_visible', 'status_comercial', 'data_emissao'],
            "ALTER TABLE biomie_pedidos_canonicos
             ADD INDEX idx_ped_visibility_scope (is_biomie_commercial_visible, status_comercial, data_emissao)"
        );

        // ── biomie_titulos_financeiros_canonicos ───────────────────────────────
        $this->maybeAddColumn(
            $pdo,
            'biomie_titulos_financeiros_canonicos',
            'is_biomie_commercial_visible',
            "ALTER TABLE biomie_titulos_financeiros_canonicos
             ADD COLUMN is_biomie_commercial_visible TINYINT(1) NOT NULL DEFAULT 1
             COMMENT 'Fase L: 0 = excluido do BIomie comercial'
             AFTER nome_vendedor"
        );
        $this->maybeAddColumn(
            $pdo,
            'biomie_titulos_financeiros_canonicos',
            'visibility_rule_source',
            "ALTER TABLE biomie_titulos_financeiros_canonicos
             ADD COLUMN visibility_rule_source VARCHAR(80) NULL DEFAULT NULL
             AFTER is_biomie_commercial_visible"
        );

        // ── biomie_vendedores_omie ─────────────────────────────────────────────
        $this->maybeAddColumn(
            $pdo,
            'biomie_vendedores_omie',
            'business_unit_scope',
            "ALTER TABLE biomie_vendedores_omie
             ADD COLUMN business_unit_scope VARCHAR(40) NULL DEFAULT NULL
             COMMENT 'comercial | licitacao | outros'
             AFTER ativo"
        );
        $this->maybeAddColumn(
            $pdo,
            'biomie_vendedores_omie',
            'is_biomie_commercial_visible',
            "ALTER TABLE biomie_vendedores_omie
             ADD COLUMN is_biomie_commercial_visible TINYINT(1) NOT NULL DEFAULT 1
             COMMENT 'Fase L: 0 = excluido do BIomie comercial'
             AFTER business_unit_scope"
        );
        $this->maybeAddIndex(
            $pdo,
            'biomie_vendedores_omie',
            'idx_vendor_visibility',
            ['is_biomie_commercial_visible'],
            "ALTER TABLE biomie_vendedores_omie
             ADD INDEX idx_vendor_visibility (is_biomie_commercial_visible)"
        );
    }

    public function down(\PDO $pdo): void
    {
        $drops = [
            ['biomie_notas_fiscais_canonicas',      'visibility_checked_at'],
            ['biomie_notas_fiscais_canonicas',      'visibility_rule_source'],
            ['biomie_notas_fiscais_canonicas',      'is_biomie_commercial_visible'],
            ['biomie_notas_fiscais_canonicas',      'business_unit_scope'],
            ['biomie_pedidos_canonicos',            'visibility_checked_at'],
            ['biomie_pedidos_canonicos',            'visibility_rule_source'],
            ['biomie_pedidos_canonicos',            'is_biomie_commercial_visible'],
            ['biomie_pedidos_canonicos',            'business_unit_scope'],
            ['biomie_titulos_financeiros_canonicos','visibility_rule_source'],
            ['biomie_titulos_financeiros_canonicos','is_biomie_commercial_visible'],
            ['biomie_vendedores_omie',              'is_biomie_commercial_visible'],
            ['biomie_vendedores_omie',              'business_unit_scope'],
        ];
        foreach ($drops as [$table, $col]) {
            if ($this->tableExists($pdo, $table) && $this->columnExists($pdo, $table, $col)) {
                try {
                    $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$col}`");
                } catch (\Throwable) {}
            }
        }
        foreach (['biomie_notas_fiscais_canonicas' => 'idx_nf_visibility_scope',
                  'biomie_pedidos_canonicos'       => 'idx_ped_visibility_scope',
                  'biomie_vendedores_omie'         => 'idx_vendor_visibility'] as $table => $idx) {
            $this->maybeDropIndex($pdo, $table, $idx);
        }
    }

    private function maybeAddColumn(\PDO $pdo, string $table, string $column, string $ddl): void
    {
        if (!$this->tableExists($pdo, $table) || $this->columnExists($pdo, $table, $column)) {
            return;
        }
        $pdo->exec($ddl);
    }

    private function maybeDropIndex(\PDO $pdo, string $table, string $index): void
    {
        if (!$this->tableExists($pdo, $table) || !$this->indexExists($pdo, $table, $index)) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }

    private function maybeAddIndex(\PDO $pdo, string $table, string $index, array $requiredColumns, string $ddl): void
    {
        if (!$this->tableExists($pdo, $table)) {
            return;
        }
        foreach ($requiredColumns as $col) {
            if (!$this->columnExists($pdo, $table, $col)) {
                return;
            }
        }
        if ($this->indexExists($pdo, $table, $index)) {
            return;
        }
        $pdo->exec($ddl);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
