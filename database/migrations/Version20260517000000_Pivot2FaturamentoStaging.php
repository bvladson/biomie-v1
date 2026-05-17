<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260517000000';
    }

    public function getDescription(): string
    {
        return 'Staging table para reconciliacao Pivot2 x canônico (NFs consolidadas do Omie).';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS omie_pivot2_faturamento_staging (
                id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                data_emissao        DATE            NULL,
                numero_nf           VARCHAR(32)     NOT NULL,
                cep                 VARCHAR(20)     NULL,
                cidade              VARCHAR(120)    NULL,
                cliente_nome        VARCHAR(255)    NULL,
                cnae_principal      TEXT            NULL,
                documento           VARCHAR(32)     NULL,
                contato             VARCHAR(120)    NULL,
                mdfe_numero         VARCHAR(60)     NULL,
                vendedor_nome       VARCHAR(120)    NULL,
                total_mercadoria    DECIMAL(15,2)   NOT NULL DEFAULT 0,
                desconto            DECIMAL(15,2)   NOT NULL DEFAULT 0,
                valor_icms_st       DECIMAL(15,2)   NOT NULL DEFAULT 0,
                seguro              DECIMAL(15,2)   NOT NULL DEFAULT 0,
                valor_ipi           DECIMAL(15,2)   NOT NULL DEFAULT 0,
                total_nota_fiscal   DECIMAL(15,2)   NOT NULL DEFAULT 0,
                source_file         VARCHAR(120)    NULL,
                imported_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY ux_pivot2_nf_doc_valor (numero_nf, documento(32), total_nota_fiscal),
                KEY idx_pivot2_data      (data_emissao),
                KEY idx_pivot2_vendedor  (vendedor_nome(80)),
                KEY idx_pivot2_documento (documento(32)),
                KEY idx_pivot2_cidade    (cidade(60))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS omie_pivot2_faturamento_staging');
    }
};
