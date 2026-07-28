-- Snapshot historico por competencia para Overview, Vendedores, Plano de Voo e Metas.

CREATE TABLE IF NOT EXISTS biomie_competencia_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia CHAR(7) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'canonical',
  schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  overview_payload LONGTEXT NULL,
  vendors_payload LONGTEXT NULL,
  flight_plan_payload LONGTEXT NULL,
  targets_payload LONGTEXT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_sync DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_biomie_competencia_snapshot (competencia),
  KEY idx_biomie_competencia_snapshot_last_sync (last_sync),
  KEY idx_biomie_competencia_snapshot_generated (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_competencia_sync_catalog (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia CHAR(7) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('pending','running','ready','stale','error') NOT NULL DEFAULT 'pending',
  source VARCHAR(32) NOT NULL DEFAULT 'canonical',
  duration_ms INT UNSIGNED NULL,
  orders_count INT UNSIGNED NOT NULL DEFAULT 0,
  notes_count INT UNSIGNED NOT NULL DEFAULT 0,
  vendors_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_reconciliation_at DATETIME NULL,
  snapshot_generated_at DATETIME NULL,
  last_error TEXT NULL,
  request_id VARCHAR(48) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_biomie_competencia_catalog (competencia),
  KEY idx_biomie_competencia_catalog_status (status),
  KEY idx_biomie_competencia_catalog_generated (snapshot_generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
