<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Phase 0.1 — Correcoes pos-fundacao multi-empresa
 *
 * 1. biomie_user_companies.default_canal — canal aberto automaticamente no login
 * 2. biomie_company_targets — metas flexiveis por empresa + canal + competencia
 *    Coluna: competencia CHAR(7) no padrao YYYY-MM (igual ao restante do sistema)
 *    NOTA: year_month e palavra reservada no MariaDB 11.x — usar competencia
 * 3. Seed: 12 meses de meta para MAG Filial Varejo (R$ 350.000/mes, 2026)
 * 4. Usuarios existentes: default_canal = varejo (todos sao varejo hoje)
 */
return new class extends AbstractMigration {

    public function getVersion(): string
    {
        return '20260620000000';
    }

    public function getDescription(): string
    {
        return 'Phase 0.1 — default_canal em user_companies e biomie_company_targets (meta flexivel por empresa+canal+competencia).';
    }

    public function up(PDO $pdo): void
    {
        $this->addDefaultCanalToUserCompanies($pdo);
        $this->createCompanyTargets($pdo);
        $this->seedMagFilialVarejoTargets($pdo);
        $this->setExistingUsersDefaultCanal($pdo);
    }

    // ── 1. default_canal ──────────────────────────────────────────────────

    private function addDefaultCanalToUserCompanies(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = "biomie_user_companies"
               AND COLUMN_NAME  = "default_canal"'
        );
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $pdo->exec(<<<'SQL'
ALTER TABLE biomie_user_companies
  ADD COLUMN default_canal ENUM('varejo','licitacao') NOT NULL DEFAULT 'varejo'
  AFTER can_licitacao
SQL);
    }

    // ── 2. biomie_company_targets ─────────────────────────────────────────

    private function createCompanyTargets(PDO $pdo): void
    {
        // year_month e palavra reservada no MariaDB 11.x — coluna nomeada competencia
        // para consistencia com mart_cliente_periodo, mart_vendedor_dia, etc.
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS biomie_company_targets (
  id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  company_id  TINYINT UNSIGNED NOT NULL,
  canal       ENUM('varejo','licitacao') NOT NULL,
  competencia CHAR(7)          NOT NULL,
  meta_valor  DECIMAL(15,2)    NOT NULL DEFAULT 0,
  ativo       TINYINT(1)       NOT NULL DEFAULT 1,
  created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_canal_comp (company_id, canal, competencia),
  KEY idx_ct_company_canal (company_id, canal),
  KEY idx_ct_competencia   (competencia),
  CONSTRAINT fk_ct_company FOREIGN KEY (company_id)
    REFERENCES biomie_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    // ── 3. Seed MAG Filial Varejo — 2026 ─────────────────────────────────

    private function seedMagFilialVarejoTargets(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
INSERT IGNORE INTO biomie_company_targets
  (company_id, canal, competencia, meta_valor, ativo)
VALUES
  (1, 'varejo', '2026-01', 350000.00, 1),
  (1, 'varejo', '2026-02', 350000.00, 1),
  (1, 'varejo', '2026-03', 350000.00, 1),
  (1, 'varejo', '2026-04', 350000.00, 1),
  (1, 'varejo', '2026-05', 350000.00, 1),
  (1, 'varejo', '2026-06', 350000.00, 1),
  (1, 'varejo', '2026-07', 350000.00, 1),
  (1, 'varejo', '2026-08', 350000.00, 1),
  (1, 'varejo', '2026-09', 350000.00, 1),
  (1, 'varejo', '2026-10', 350000.00, 1),
  (1, 'varejo', '2026-11', 350000.00, 1),
  (1, 'varejo', '2026-12', 350000.00, 1)
SQL);
    }

    // ── 4. Usuarios existentes: default_canal = varejo ───────────────────

    private function setExistingUsersDefaultCanal(PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE biomie_user_companies
             SET default_canal = 'varejo'
             WHERE company_id = 1"
        );
    }

    // ── DOWN ──────────────────────────────────────────────────────────────

    public function down(PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_company_targets']);
    }
};
