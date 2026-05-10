<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260503000000';
    }

    public function getDescription(): string
    {
        return 'Adiciona enrichment_source e enriched_at em biomie_clientes_dim para rastreamento de origem do enriquecimento.';
    }

    public function up(\PDO $pdo): void
    {
        $additions = [
            'enrichment_source' => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `enrichment_source` VARCHAR(50) NULL AFTER `enrichment_status`",
            'enriched_at'       => "ALTER TABLE `biomie_clientes_dim` ADD COLUMN `enriched_at` DATETIME NULL AFTER `enrichment_source`",
        ];

        foreach ($additions as $col => $sql) {
            if (!$this->columnExists($pdo, 'biomie_clientes_dim', $col)) {
                $pdo->exec($sql);
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        foreach (['enrichment_source', 'enriched_at'] as $col) {
            if ($this->columnExists($pdo, 'biomie_clientes_dim', $col)) {
                $pdo->exec("ALTER TABLE `biomie_clientes_dim` DROP COLUMN `{$col}`");
            }
        }
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
