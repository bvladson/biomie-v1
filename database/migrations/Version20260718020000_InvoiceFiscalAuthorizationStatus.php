<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    /** @var list<string> */
    private const TABLES = [
        'biomie_notas_fiscais_canonicas',
        'mart_fiscal_nfe',
        'mart_fiscal_items',
    ];

    public function getVersion(): string
    {
        return '20260718020000';
    }

    public function getDescription(): string
    {
        return 'Classificacao fiscal fail-closed: preserva NF sem evidencia de autorizacao como nao_autorizada.';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->columnExists($pdo, $table, 'status_fiscal')) {
                continue;
            }

            $pdo->exec(sprintf(
                "ALTER TABLE `%s` MODIFY COLUMN status_fiscal ENUM('autorizada','cancelada','nao_autorizada') NOT NULL DEFAULT 'nao_autorizada'",
                $table,
            ));
        }
    }

    public function down(PDO $pdo): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->columnExists($pdo, $table, 'status_fiscal')) {
                continue;
            }

            // Rollback conserva a exclusao de receita. Bancos antigos nao
            // conhecem nao_autorizada, portanto o fallback mais seguro e
            // cancelada (nunca autorizada).
            $pdo->exec(sprintf(
                "UPDATE `%s` SET status_fiscal = 'cancelada' WHERE status_fiscal = 'nao_autorizada'",
                $table,
            ));
            $pdo->exec(sprintf(
                "ALTER TABLE `%s` MODIFY COLUMN status_fiscal ENUM('autorizada','cancelada') NOT NULL DEFAULT 'autorizada'",
                $table,
            ));
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
