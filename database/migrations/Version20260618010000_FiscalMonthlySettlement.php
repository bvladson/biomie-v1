<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260618010000';
    }

    public function getDescription(): string
    {
        return 'ReadModel mensal de apuracao fiscal: debitos, creditos, retencoes, custo e saldo liquido gerencial.';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mart_fiscal_monthly_settlement (
  competencia CHAR(7) NOT NULL,
  faturamento DECIMAL(15,2) NOT NULL DEFAULT 0,
  debito_icms DECIMAL(15,2) NOT NULL DEFAULT 0,
  debito_icms_st DECIMAL(15,2) NOT NULL DEFAULT 0,
  debito_difal DECIMAL(15,2) NOT NULL DEFAULT 0,
  debito_fecop DECIMAL(15,2) NOT NULL DEFAULT 0,
  debito_pis DECIMAL(15,2) NOT NULL DEFAULT 0,
  debito_cofins DECIMAL(15,2) NOT NULL DEFAULT 0,
  debito_ipi DECIMAL(15,2) NOT NULL DEFAULT 0,
  debito_iss DECIMAL(15,2) NOT NULL DEFAULT 0,
  retencao_pis DECIMAL(15,2) NOT NULL DEFAULT 0,
  retencao_cofins DECIMAL(15,2) NOT NULL DEFAULT 0,
  retencao_csll DECIMAL(15,2) NOT NULL DEFAULT 0,
  retencao_irrf DECIMAL(15,2) NOT NULL DEFAULT 0,
  credito_icms DECIMAL(15,2) NOT NULL DEFAULT 0,
  credito_pis DECIMAL(15,2) NOT NULL DEFAULT 0,
  credito_cofins DECIMAL(15,2) NOT NULL DEFAULT 0,
  credito_ipi DECIMAL(15,2) NOT NULL DEFAULT 0,
  credito_iss DECIMAL(15,2) NOT NULL DEFAULT 0,
  tributos_brutos DECIMAL(15,2) NOT NULL DEFAULT 0,
  creditos_totais DECIMAL(15,2) NOT NULL DEFAULT 0,
  retencoes_totais DECIMAL(15,2) NOT NULL DEFAULT 0,
  saldo_liquido_estimado DECIMAL(15,2) NOT NULL DEFAULT 0,
  carga_bruta_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
  carga_liquida_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
  input_coverage ENUM('complete','partial','pending') NOT NULL DEFAULT 'pending',
  accounting_coverage ENUM('complete','partial','pending') NOT NULL DEFAULT 'pending',
  refreshed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (competencia),
  KEY idx_fiscal_settlement_refresh (refreshed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['mart_fiscal_monthly_settlement']);
    }
};
