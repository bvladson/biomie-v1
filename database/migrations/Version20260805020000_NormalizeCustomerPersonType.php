<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Remove o sentinela ENUM '' historico e impede que consumidores da dimensao
 * recebam um valor que nao pertence ao dominio canonico.
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260805020000';
    }

    public function getDescription(): string
    {
        return "Normaliza tipo_pessoa invalido em clientes_dim e no historico do mart, preservando auditoria.";
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_tipo_pessoa_repair_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration_version VARCHAR(20) NOT NULL,
                source_table VARCHAR(64) NOT NULL,
                source_row_id BIGINT UNSIGNED NOT NULL,
                company_id TINYINT UNSIGNED NULL,
                previous_value VARCHAR(30) NOT NULL,
                repaired_value VARCHAR(30) NOT NULL,
                repaired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tipo_pessoa_repair (migration_version, source_table, source_row_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->beginTransaction();
        try {
            $pdo->exec(<<<'SQL'
                INSERT IGNORE INTO biomie_tipo_pessoa_repair_audit
                    (migration_version, source_table, source_row_id, company_id, previous_value, repaired_value)
                SELECT '20260805020000', 'biomie_clientes_dim', id, company_id, '', 'desconhecido'
                FROM biomie_clientes_dim
                WHERE tipo_pessoa = ''
            SQL);
            $pdo->exec("UPDATE biomie_clientes_dim SET tipo_pessoa = 'desconhecido' WHERE tipo_pessoa = ''");

            $pdo->exec(<<<'SQL'
                INSERT IGNORE INTO biomie_tipo_pessoa_repair_audit
                    (migration_version, source_table, source_row_id, company_id, previous_value, repaired_value)
                SELECT '20260805020000', 'mart_cliente_periodo', id, company_id, '', 'desconhecido'
                FROM mart_cliente_periodo
                WHERE tipo_pessoa = ''
            SQL);
            $pdo->exec("UPDATE mart_cliente_periodo SET tipo_pessoa = 'desconhecido' WHERE tipo_pessoa = ''");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function down(PDO $pdo): void
    {
        // Reparacao de dados deliberadamente irreversivel. A trilha permanece
        // disponivel para auditoria, sem reintroduzir o sentinela invalido.
    }
};
