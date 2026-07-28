<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Metas múltiplas (níveis) por vendedor — opcional.
 *
 * Adiciona tiers_json a biomie_sales_targets_monthly: lista JSON ordenada de
 * valores de meta ADICIONAIS além da meta base (target_amount = Meta 1).
 * Ex: target_amount=80000, tiers_json="[120000,160000,200000]"
 *   → Meta 1: 80k, Meta 2: 120k, Meta 3: 160k, Meta 4: 200k.
 *
 * Retrocompatível: NULL/vazio = comportamento atual (meta única).
 * TEXT (não JSON) por compatibilidade MariaDB.
 */
return new class extends AbstractMigration {

    public function getVersion(): string
    {
        return '20260709000000';
    }

    public function getDescription(): string
    {
        return 'Metas múltiplas por vendedor — coluna tiers_json em biomie_sales_targets_monthly (níveis opcionais além da meta base).';
    }

    public function up(PDO $pdo): void
    {
        if ($this->columnExists($pdo, 'biomie_sales_targets_monthly', 'tiers_json')) {
            return;
        }

        $pdo->exec(
            'ALTER TABLE biomie_sales_targets_monthly
             ADD COLUMN tiers_json TEXT NULL DEFAULT NULL AFTER target_amount'
        );
    }

    public function down(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'biomie_sales_targets_monthly', 'tiers_json')) {
            return;
        }

        $pdo->exec('ALTER TABLE biomie_sales_targets_monthly DROP COLUMN tiers_json');
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :t
               AND COLUMN_NAME  = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
