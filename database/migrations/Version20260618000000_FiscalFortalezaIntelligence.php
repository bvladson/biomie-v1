<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260618000000';
    }

    public function getDescription(): string
    {
        return 'Estrutura fiscal Fortaleza/CE: estabelecimento, fontes, snapshots tributarios e dimensoes fiscais do mart.';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_establishments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_system VARCHAR(32) NOT NULL DEFAULT 'omie',
  source_company_id VARCHAR(64) NULL,
  cnpj VARCHAR(20) NULL,
  razao_social VARCHAR(200) NULL,
  uf CHAR(2) NOT NULL DEFAULT 'CE',
  municipio VARCHAR(120) NOT NULL DEFAULT 'Fortaleza',
  codigo_ibge CHAR(7) NOT NULL DEFAULT '2304400',
  regime_tributario VARCHAR(40) NOT NULL DEFAULT 'lucro_real',
  operation_profile VARCHAR(80) NOT NULL DEFAULT 'comercio_mercadorias',
  inscricao_estadual VARCHAR(32) NULL,
  inscricao_municipal VARCHAR(32) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fiscal_establishment_source (source_system, source_company_id),
  KEY idx_fiscal_establishment_primary (is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_source_registry (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_code VARCHAR(40) NOT NULL,
  source_label VARCHAR(100) NOT NULL,
  direction ENUM('saida','entrada','ambos') NOT NULL,
  document_scope VARCHAR(80) NOT NULL,
  connection_mode ENUM('api','file','database','manual','pending') NOT NULL DEFAULT 'pending',
  status ENUM('active','prepared','unavailable','disabled') NOT NULL DEFAULT 'prepared',
  priority SMALLINT NOT NULL DEFAULT 100,
  notes VARCHAR(500) NULL,
  last_sync_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fiscal_source_code (source_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_document_tax_snapshot (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nota_key VARCHAR(80) NOT NULL,
  source_system VARCHAR(32) NOT NULL DEFAULT 'omie',
  document_model VARCHAR(12) NOT NULL DEFAULT 'nfe',
  direction ENUM('saida','entrada') NOT NULL DEFAULT 'saida',
  origin_uf CHAR(2) NULL,
  origin_city_ibge CHAR(7) NULL,
  destination_uf CHAR(2) NULL,
  destination_city_ibge CHAR(7) NULL,
  valor_servicos DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_icms_st DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_difal_destino DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_difal_origem DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_fecop DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_iss DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_iss_retido DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_pis_retido DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_cofins_retido DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_csll_retido DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_irrf DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_csrf DECIMAL(14,2) NOT NULL DEFAULT 0,
  base_cbs DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_cbs DECIMAL(14,4) NOT NULL DEFAULT 0,
  base_ibs DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_ibs_uf DECIMAL(14,4) NOT NULL DEFAULT 0,
  valor_ibs_municipio DECIMAL(14,4) NOT NULL DEFAULT 0,
  is_informative TINYINT(1) NOT NULL DEFAULT 1,
  source_hash CHAR(64) NULL,
  built_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fiscal_snapshot_nota (nota_key),
  KEY idx_fiscal_snapshot_model (document_model, direction),
  KEY idx_fiscal_snapshot_destination (destination_uf, destination_city_ibge)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $columns = [
            'document_model' => "VARCHAR(12) NOT NULL DEFAULT 'nfe' AFTER numero_nf",
            'direction' => "ENUM('saida','entrada') NOT NULL DEFAULT 'saida' AFTER document_model",
            'destination_uf' => "CHAR(2) NULL AFTER documento_cliente",
            'destination_city' => "VARCHAR(120) NULL AFTER destination_uf",
            'destination_city_ibge' => "CHAR(7) NULL AFTER destination_city",
            'cst_icms' => "VARCHAR(8) NULL AFTER origem_produto",
            'cst_pis' => "VARCHAR(8) NULL AFTER cst_icms",
            'cst_cofins' => "VARCHAR(8) NULL AFTER cst_pis",
            'valor_icms_st' => "DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER valor_icms",
            'valor_difal_destino' => "DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER valor_icms_st",
            'valor_difal_origem' => "DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER valor_difal_destino",
            'valor_fecop' => "DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER valor_difal_origem",
            'valor_ibs_uf' => "DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER valor_ibs",
            'valor_ibs_municipio' => "DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER valor_ibs_uf",
        ];
        foreach ($columns as $column => $definition) {
            if (!$this->columnExists($pdo, 'mart_fiscal_items', $column)) {
                $pdo->exec("ALTER TABLE mart_fiscal_items ADD COLUMN `{$column}` {$definition}");
            }
        }

        $pdo->exec(
            "INSERT INTO fiscal_establishments
                (source_system, source_company_id, uf, municipio, codigo_ibge, regime_tributario, operation_profile, is_primary)
             VALUES ('omie', 'primary', 'CE', 'Fortaleza', '2304400', 'lucro_real', 'comercio_mercadorias', 1)
             ON DUPLICATE KEY UPDATE uf=VALUES(uf), municipio=VALUES(municipio),
               codigo_ibge=VALUES(codigo_ibge), regime_tributario=VALUES(regime_tributario),
               operation_profile=VALUES(operation_profile), is_primary=1"
        );

        $sources = [
            ['omie_saida', 'Omie — notas de saída', 'saida', 'NF-e/NFC-e emitidas', 'api', 'active', 10, 'Fonte atual do BIomie.'],
            ['omie_entrada', 'Omie — notas de entrada', 'entrada', 'Entradas registradas no Omie', 'api', 'prepared', 50, 'Ativar na última fase após reconciliação.'],
            ['secran_entrada', 'Secran/Contabilidade — entradas', 'entrada', 'Entradas e documentos contábeis', 'pending', 'prepared', 60, 'Contrato de integração pendente.'],
            ['nfse_fortaleza', 'NFS-e Fortaleza', 'ambos', 'Serviços prestados e tomados', 'pending', 'prepared', 40, 'Estrutura preparada; fonte ainda não conectada.'],
            ['contabil_ecf', 'Contábil / ECD / ECF', 'ambos', 'Lucro Real, IRPJ e CSLL', 'pending', 'prepared', 70, 'Necessário para apuração gerencial confiável.'],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO fiscal_source_registry
              (source_code, source_label, direction, document_scope, connection_mode, status, priority, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE source_label=VALUES(source_label), direction=VALUES(direction),
               document_scope=VALUES(document_scope), connection_mode=VALUES(connection_mode),
               status=VALUES(status), priority=VALUES(priority), notes=VALUES(notes)'
        );
        foreach ($sources as $source) {
            $stmt->execute($source);
        }
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'fiscal_document_tax_snapshot',
            'fiscal_source_registry',
            'fiscal_establishments',
        ]);
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
