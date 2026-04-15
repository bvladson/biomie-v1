CREATE TABLE IF NOT EXISTS biomie_reconciliacoes_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_type VARCHAR(32) NOT NULL DEFAULT 'omie_csv',
  source_file VARCHAR(255) NOT NULL DEFAULT '',
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  notes_csv INT UNSIGNED NOT NULL DEFAULT 0,
  notes_system INT UNSIGNED NOT NULL DEFAULT 0,
  differences_count INT UNSIGNED NOT NULL DEFAULT 0,
  matches_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_user_id BIGINT NULL,
  created_user_name VARCHAR(120) NULL,
  latest_for_period TINYINT(1) NOT NULL DEFAULT 1,
  report_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reconciliation_period (period_start, period_end, latest_for_period),
  KEY idx_reconciliation_created (created_at),
  KEY idx_reconciliation_source (source_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_reconciliacoes_vendedores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  vendor_key VARCHAR(180) NOT NULL DEFAULT '',
  vendor_name VARCHAR(160) NOT NULL DEFAULT 'Sem vendedor',
  has_difference TINYINT(1) NOT NULL DEFAULT 0,
  payload_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reconciliation_vendor_run (run_id),
  KEY idx_reconciliation_vendor_name (vendor_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_reconciliacoes_diferencas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  note_key VARCHAR(120) NOT NULL DEFAULT '',
  note_ref VARCHAR(80) NOT NULL DEFAULT '',
  difference_type VARCHAR(40) NOT NULL DEFAULT 'mismatch',
  payload_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reconciliation_diff_run (run_id),
  KEY idx_reconciliation_diff_note (note_ref),
  KEY idx_reconciliation_diff_type (difference_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
