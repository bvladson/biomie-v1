<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260518120000';
    }

    public function getDescription(): string
    {
        return 'Adiciona geo_quality e geo_scope em mart_compradores_por_geo. '
             . 'geo_quality: alto/medio/baixo/sem_dados (qualidade do match de bairro). '
             . 'geo_scope: bairro_fortaleza/municipio_ce/uf_brasil/sem_dados (escopo geográfico).';
    }

    public function up(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'mart_compradores_por_geo')) {
            return;
        }

        $this->maybeAddColumn(
            $pdo,
            'mart_compradores_por_geo',
            'geo_quality',
            "VARCHAR(20) NULL DEFAULT NULL COMMENT 'alto=match oficial Fortaleza; medio=bairro_norm; baixo=so cidade; sem_dados=sem geo' AFTER bairro_norm"
        );

        $this->maybeAddColumn(
            $pdo,
            'mart_compradores_por_geo',
            'geo_scope',
            "VARCHAR(30) NULL DEFAULT NULL COMMENT 'bairro_fortaleza|municipio_ce|uf_brasil|sem_dados' AFTER geo_quality"
        );

        $this->maybeAddIndex(
            $pdo,
            'mart_compradores_por_geo',
            'idx_geo_buyers_quality_scope',
            '(geo_quality, geo_scope, competencia)'
        );
    }

    public function down(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'mart_compradores_por_geo')) {
            return;
        }
        foreach (['geo_quality', 'geo_scope'] as $col) {
            if ($this->columnExists($pdo, 'mart_compradores_por_geo', $col)) {
                $pdo->exec("ALTER TABLE `mart_compradores_por_geo` DROP COLUMN `{$col}`");
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tableExists(\PDO $pdo, string $table): bool
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'"
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
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$indexName}'"
        )->fetchColumn();
        if (!$exists) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` {$columns}");
        }
    }
};
