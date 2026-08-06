<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string { return '20260805210000'; }
    public function getDescription(): string { return 'Scores, forecasts e recomendacoes territoriais persistidos pelo Python.'; }

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intelligence_geo_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  competencia CHAR(7) NOT NULL,
  layer_id VARCHAR(64) NOT NULL,
  territory_key VARCHAR(190) NOT NULL,
  territory_label VARCHAR(255) NOT NULL,
  opportunity_score DECIMAL(6,2) NOT NULL DEFAULT 0,
  confidence ENUM('alta','media','baixa') NOT NULL DEFAULT 'baixa',
  months_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  current_revenue DECIMAL(15,2) NOT NULL DEFAULT 0,
  previous_revenue DECIMAL(15,2) NOT NULL DEFAULT 0,
  growth_pct DECIMAL(9,2) NULL,
  customers INT UNSIGNED NOT NULL DEFAULT 0,
  invoices INT UNSIGNED NOT NULL DEFAULT 0,
  average_ticket DECIMAL(15,2) NOT NULL DEFAULT 0,
  potential_revenue DECIMAL(15,2) NOT NULL DEFAULT 0,
  forecast_revenue DECIMAL(15,2) NULL,
  forecast_low DECIMAL(15,2) NULL,
  forecast_high DECIMAL(15,2) NULL,
  main_reason VARCHAR(500) NOT NULL,
  recommended_action VARCHAR(500) NOT NULL,
  features_json LONGTEXT NULL,
  model_version VARCHAR(80) NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_geo_score_version (company_id, competencia, layer_id, territory_key, model_version),
  KEY idx_geo_score_current (company_id, layer_id, is_current, opportunity_score),
  KEY idx_geo_score_competencia (company_id, competencia, layer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS intelligence_geo_scores');
    }
};
