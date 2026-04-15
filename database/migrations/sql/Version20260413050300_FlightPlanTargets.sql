-- Fase 2 do Plano de Voo no BIomie
-- Executar no phpMyAdmin do banco MySQL do módulo comercial.

CREATE TABLE IF NOT EXISTS biomie_sales_targets_monthly (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_id VARCHAR(32) NOT NULL,
  vendor_name VARCHAR(120) NULL,
  year_month CHAR(7) NOT NULL,
  target_amount DECIMAL(15,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BRL',
  source ENUM('manual','import','api') NOT NULL DEFAULT 'manual',
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(64) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendor_month (vendor_id, year_month),
  KEY idx_month (year_month),
  KEY idx_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_sales_targets_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vendor_id VARCHAR(32) NOT NULL,
  year_month CHAR(7) NOT NULL,
  old_target_amount DECIMAL(15,2) NULL,
  new_target_amount DECIMAL(15,2) NOT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  changed_by VARCHAR(64) NULL,
  change_reason VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_vendor_month (vendor_id, year_month),
  KEY idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exemplos iniciais. Ajuste vendor_id e valores conforme seu cadastro real.
-- INSERT INTO biomie_sales_targets_monthly (vendor_id, vendor_name, year_month, target_amount, source, notes, created_by)
-- VALUES
-- ('10', 'Alexandre', '2026-03', 100000.00, 'manual', 'Meta inicial da fase 2', 'admin'),
-- ('11', 'Ana Karyne', '2026-03', 120000.00, 'manual', 'Meta inicial da fase 2', 'admin'),
-- ('12', 'Gabriel', '2026-03', 80000.00, 'manual', 'Meta inicial da fase 2', 'admin');
