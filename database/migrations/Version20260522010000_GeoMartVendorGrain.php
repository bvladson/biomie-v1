<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260522010000';
    }

    public function getDescription(): string
    {
        return 'Q.6.DB: evolui mart_compradores_por_geo — adiciona vendedor ao grain (codigo_vendedor_omie + nome_vendedor), recria unique key para incluir vendedor, adiciona idx_geo_vendedor.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'mart_compradores_por_geo')) {
            return;
        }

        // 1. Add vendor columns (NOT NULL DEFAULT '' / 'Sem vendedor' — consistent with canonical_sales pattern).
        //    Existing rows will have empty strings; re-materialization fills the correct values.
        $this->maybeAddColumn(
            $pdo,
            'mart_compradores_por_geo',
            'codigo_vendedor_omie',
            "ALTER TABLE mart_compradores_por_geo"
            . " ADD COLUMN codigo_vendedor_omie VARCHAR(32) NOT NULL DEFAULT '' AFTER refreshed_at"
        );

        $this->maybeAddColumn(
            $pdo,
            'mart_compradores_por_geo',
            'nome_vendedor',
            "ALTER TABLE mart_compradores_por_geo"
            . " ADD COLUMN nome_vendedor VARCHAR(255) NOT NULL DEFAULT 'Sem vendedor' AFTER codigo_vendedor_omie"
        );

        // 2. Drop the old unique key (grain change: vendor not included there).
        //    Safe even if existing rows have empty vendor — all existing rows are unique on the old key,
        //    so adding vendor = '' does not create duplicates on the new key.
        $this->maybeDropIndex($pdo, 'mart_compradores_por_geo', 'uq_cliente_geo_competencia');

        // 3. New unique key — vendor is now part of the grain.
        $this->maybeAddIndex(
            $pdo,
            'mart_compradores_por_geo',
            'uq_vendedor_cliente_geo_comp',
            ['competencia', 'codigo_vendedor_omie', 'codigo_cliente_omie', 'uf', 'cidade_norm', 'bairro_norm'],
            "ALTER TABLE mart_compradores_por_geo"
            . " ADD UNIQUE KEY uq_vendedor_cliente_geo_comp"
            . " (competencia, codigo_vendedor_omie, codigo_cliente_omie, uf, cidade_norm, bairro_norm)"
        );

        // 4. Lookup index for vendor filter queries (competencia + codigo_vendedor_omie).
        $this->maybeAddIndex(
            $pdo,
            'mart_compradores_por_geo',
            'idx_geo_vendedor',
            ['competencia', 'codigo_vendedor_omie'],
            "ALTER TABLE mart_compradores_por_geo"
            . " ADD INDEX idx_geo_vendedor (competencia, codigo_vendedor_omie)"
        );
    }

    public function down(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'mart_compradores_por_geo')) {
            return;
        }

        foreach (['idx_geo_vendedor', 'uq_vendedor_cliente_geo_comp'] as $idx) {
            $this->maybeDropIndex($pdo, 'mart_compradores_por_geo', $idx);
        }

        // Restore original unique key (best-effort — may collide if mart was not re-materialized).
        if (!$this->indexExists($pdo, 'mart_compradores_por_geo', 'uq_cliente_geo_competencia')) {
            try {
                $pdo->exec(
                    "ALTER TABLE mart_compradores_por_geo"
                    . " ADD UNIQUE KEY uq_cliente_geo_competencia"
                    . " (competencia, codigo_cliente_omie, uf, cidade_norm, bairro_norm)"
                );
            } catch (\Throwable) {}
        }

        foreach (['nome_vendedor', 'codigo_vendedor_omie'] as $col) {
            if ($this->columnExists($pdo, 'mart_compradores_por_geo', $col)) {
                try {
                    $pdo->exec("ALTER TABLE `mart_compradores_por_geo` DROP COLUMN `{$col}`");
                } catch (\Throwable) {}
            }
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
