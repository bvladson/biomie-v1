<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string { return '20260805220000'; }
    public function getDescription(): string { return 'Funil persistido de coerencia e anomalias fiscais por empresa e competencia.'; }

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intelligence_fiscal_coherence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  competencia CHAR(7) NOT NULL,
  canonical_invoices INT UNSIGNED NOT NULL DEFAULT 0,
  mart_invoices INT UNSIGNED NOT NULL DEFAULT 0,
  mart_items INT UNSIGNED NOT NULL DEFAULT 0,
  canonical_revenue DECIMAL(15,2) NOT NULL DEFAULT 0,
  mart_revenue DECIMAL(15,2) NOT NULL DEFAULT 0,
  missing_revenue DECIMAL(15,2) NOT NULL DEFAULT 0,
  coverage_pct DECIMAL(7,2) NOT NULL DEFAULT 0,
  mart_built_at DATETIME NULL,
  freshness_hours DECIMAL(9,2) NULL,
  status ENUM('ok','warning','critical','empty') NOT NULL DEFAULT 'empty',
  reasons_json LONGTEXT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fiscal_coherence_status (company_id, is_current, status, competencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intelligence_fiscal_anomalies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  competencia CHAR(7) NOT NULL,
  anomaly_type VARCHAR(64) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  entity_key VARCHAR(190) NOT NULL DEFAULT '',
  title VARCHAR(255) NOT NULL,
  detail VARCHAR(1000) NOT NULL,
  affected_rows INT UNSIGNED NOT NULL DEFAULT 0,
  affected_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  evidence_json LONGTEXT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fiscal_anomaly_status (company_id, is_current, severity, competencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intelligence_fiscal_projections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  competencia CHAR(7) NOT NULL,
  regime VARCHAR(32) NOT NULL,
  assessed_tax DECIMAL(15,2) NOT NULL DEFAULT 0,
  projected_tax DECIMAL(15,2) NOT NULL DEFAULT 0,
  invisible_tax DECIMAL(15,2) NOT NULL DEFAULT 0,
  input_documents INT UNSIGNED NOT NULL DEFAULT 0,
  linked_invoices_pct DECIMAL(7,2) NOT NULL DEFAULT 0,
  confidence ENUM('alta','media','baixa') NOT NULL DEFAULT 'baixa',
  projection_json LONGTEXT NOT NULL,
  model_version VARCHAR(64) NOT NULL,
  projected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fiscal_projection_history (company_id, competencia, projected_at),
  KEY idx_fiscal_projection_confidence (company_id, confidence, projected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS intelligence_fiscal_anomalies');
        $pdo->exec('DROP TABLE IF EXISTS intelligence_fiscal_coherence');
        $pdo->exec('DROP TABLE IF EXISTS intelligence_fiscal_projections');
    }
};
