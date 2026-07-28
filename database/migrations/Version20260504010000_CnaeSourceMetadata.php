<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string    { return '20260504010000'; }
    public function getDescription(): string { return 'Rastreabilidade de fonte CNAE em mart_vendas_por_cnae_cnpj e tabela biomie_cnae_dim.'; }
    public function getConnectionName(): string { return 'canonical'; }

    public function up(\PDO $pdo): void
    {
        // Idempotent: add source-tracking columns to mart_vendas_por_cnae_cnpj
        $this->executeSql($pdo, "
            ALTER TABLE mart_vendas_por_cnae_cnpj
              ADD COLUMN IF NOT EXISTS cnae_source      VARCHAR(40) NULL COMMENT 'nf_canonical|pedido_canonical|clientes_dim|unknown',
              ADD COLUMN IF NOT EXISTS cnae_match_method VARCHAR(40) NULL COMMENT 'codigo_omie|documento_normalizado|direto',
              ADD COLUMN IF NOT EXISTS cnae_confidence  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 nivel de confianca da fonte',
              ADD COLUMN IF NOT EXISTS updated_at       DATETIME NULL DEFAULT NULL
        ");

        // Reference table for CNAE codes and descriptions
        $this->executeSql($pdo, "
            CREATE TABLE IF NOT EXISTS biomie_cnae_dim (
              cnae_codigo            VARCHAR(20)  NOT NULL,
              cnae_codigo_normalizado VARCHAR(20) NULL,
              cnae_descricao         VARCHAR(255) NULL,
              origem                 VARCHAR(40)  NULL,
              created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (cnae_codigo),
              KEY idx_cnae_norm (cnae_codigo_normalizado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_cnae_dim']);
        // Columns intentionally left in mart_vendas_por_cnae_cnpj on rollback
        // to avoid accidental data loss — remove manually if needed.
    }
};
