<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260618020000';
    }

    public function getDescription(): string
    {
        return 'Notas de entrada Omie e itens tributarios para compras e creditos fiscais potenciais.';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_purchase_entries (
  entry_key VARCHAR(80) NOT NULL,
  omie_entry_id BIGINT UNSIGNED NOT NULL,
  numero_documento VARCHAR(40) NOT NULL,
  data_entrada DATE NOT NULL,
  competencia CHAR(7) NOT NULL,
  fornecedor_id BIGINT UNSIGNED NULL,
  fornecedor_nome VARCHAR(200) NULL,
  comprador_id BIGINT UNSIGNED NULL,
  status_entrada VARCHAR(30) NOT NULL DEFAULT 'registrada',
  valor_mercadorias DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_descontos DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_frete DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_seguro DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_outros DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_icms DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_pis DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_cofins DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_ipi DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_cbs DECIMAL(15,4) NOT NULL DEFAULT 0,
  valor_ibs DECIMAL(15,4) NOT NULL DEFAULT 0,
  credito_potencial DECIMAL(15,2) NOT NULL DEFAULT 0,
  itens_count INT UNSIGNED NOT NULL DEFAULT 0,
  source_system VARCHAR(20) NOT NULL DEFAULT 'omie',
  source_hash CHAR(64) NOT NULL,
  synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (entry_key),
  UNIQUE KEY uq_fiscal_purchase_omie (omie_entry_id),
  KEY idx_fiscal_purchase_date (data_entrada),
  KEY idx_fiscal_purchase_competencia (competencia),
  KEY idx_fiscal_purchase_supplier (fornecedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_purchase_entry_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_key VARCHAR(80) NOT NULL,
  item_sequence INT UNSIGNED NOT NULL,
  omie_item_id BIGINT UNSIGNED NULL,
  omie_product_id BIGINT UNSIGNED NULL,
  ncm VARCHAR(20) NULL,
  cfop VARCHAR(12) NULL,
  quantidade DECIMAL(15,4) NOT NULL DEFAULT 0,
  valor_unitario DECIMAL(15,4) NOT NULL DEFAULT 0,
  valor_desconto DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_total_item DECIMAL(15,2) NOT NULL DEFAULT 0,
  cst_icms VARCHAR(8) NULL,
  base_icms DECIMAL(15,2) NOT NULL DEFAULT 0,
  aliq_icms DECIMAL(9,4) NOT NULL DEFAULT 0,
  valor_icms DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_icms_st DECIMAL(15,2) NOT NULL DEFAULT 0,
  cst_pis VARCHAR(8) NULL,
  base_pis DECIMAL(15,2) NOT NULL DEFAULT 0,
  aliq_pis DECIMAL(9,4) NOT NULL DEFAULT 0,
  valor_pis DECIMAL(15,2) NOT NULL DEFAULT 0,
  cst_cofins VARCHAR(8) NULL,
  base_cofins DECIMAL(15,2) NOT NULL DEFAULT 0,
  aliq_cofins DECIMAL(9,4) NOT NULL DEFAULT 0,
  valor_cofins DECIMAL(15,2) NOT NULL DEFAULT 0,
  cst_ipi VARCHAR(8) NULL,
  base_ipi DECIMAL(15,2) NOT NULL DEFAULT 0,
  aliq_ipi DECIMAL(9,4) NOT NULL DEFAULT 0,
  valor_ipi DECIMAL(15,2) NOT NULL DEFAULT 0,
  base_cbs_ibs DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_cbs DECIMAL(15,4) NOT NULL DEFAULT 0,
  valor_ibs DECIMAL(15,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fiscal_purchase_item (entry_key, item_sequence),
  KEY idx_fiscal_purchase_item_ncm (ncm),
  KEY idx_fiscal_purchase_item_cfop (cfop),
  CONSTRAINT fk_fiscal_purchase_item_entry
    FOREIGN KEY (entry_key) REFERENCES fiscal_purchase_entries(entry_key)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'fiscal_purchase_entry_items',
            'fiscal_purchase_entries',
        ]);
    }
};
