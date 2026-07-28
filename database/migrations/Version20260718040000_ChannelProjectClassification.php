<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    private const TABLE = 'biomie_channel_classification_rules';

    public function getVersion(): string
    {
        return '20260718040000';
    }

    public function getDescription(): string
    {
        return 'Permite classificar canal comercial por lista de projetos Omie.';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, self::TABLE, 'rule_type')) {
            return;
        }

        $pdo->exec(
            "ALTER TABLE `" . self::TABLE . "`
             MODIFY COLUMN rule_type ENUM(
                'manual',
                'vendedor_nome_like',
                'cliente_cnpj_list',
                'pedido_obs_like',
                'projeto_id_list',
                'default'
             ) NOT NULL"
        );
    }

    public function down(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, self::TABLE, 'rule_type')) {
            return;
        }

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM `" . self::TABLE . "` WHERE rule_type = 'projeto_id_list'"
        );
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException(
                'Rollback recusado: existem regras ativas ou historicas por projeto Omie.'
            );
        }

        $pdo->exec(
            "ALTER TABLE `" . self::TABLE . "`
             MODIFY COLUMN rule_type ENUM(
                'manual',
                'vendedor_nome_like',
                'cliente_cnpj_list',
                'pedido_obs_like',
                'default'
             ) NOT NULL"
        );
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
