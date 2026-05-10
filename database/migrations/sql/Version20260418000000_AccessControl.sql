-- Fase 12 — Segurança, Acesso e LGPD
-- Idempotente: ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS.

-- ──────────────────────────────────────────────────────────────────────────────
-- 1. biomie_access_logs — coluna module para categorizar o domínio acessado
-- ──────────────────────────────────────────────────────────────────────────────
ALTER TABLE biomie_access_logs
  ADD COLUMN IF NOT EXISTS module VARCHAR(40) NULL
    COMMENT 'Módulo acessado: commercial, financial, fiscal, geo, dossier, export, observability...'
    AFTER event_type;

ALTER TABLE biomie_access_logs
  ADD INDEX IF NOT EXISTS idx_access_logs_module (module, created_at);

-- ──────────────────────────────────────────────────────────────────────────────
-- 2. biomie_data_export_log — registra cada exportação de dados (LGPD art. 37)
--    Mantido separado do access_log para facilitar relatórios de conformidade.
-- ──────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS biomie_data_export_log (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED        NOT NULL,
    username        VARCHAR(80)         NOT NULL,
    format          VARCHAR(20)         NOT NULL DEFAULT 'json' COMMENT 'json | csv',
    module          VARCHAR(40)         NOT NULL COMMENT 'ex: orders, clients, financial',
    filters_json    JSON                NULL     COMMENT 'Filtros aplicados (period, vendor_id, etc.)',
    row_count       INT UNSIGNED        NOT NULL DEFAULT 0,
    ip_address      VARCHAR(45)         NOT NULL DEFAULT '',
    user_agent      VARCHAR(255)        NOT NULL DEFAULT '',
    exported_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_export_user    (user_id, exported_at),
    KEY idx_export_module  (module, exported_at),
    KEY idx_export_date    (exported_at DESC),
    CONSTRAINT fk_export_log_user FOREIGN KEY (user_id) REFERENCES biomie_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Trilha de auditoria de exportações — LGPD art. 37. Nunca deletar registros.';

-- ──────────────────────────────────────────────────────────────────────────────
-- 3. biomie_users — suporte a módulos restritos por usuário (override granular)
--    modules_json = null → usa apenas o perfil do role
--    modules_json = ["commercial"] → acesso apenas ao módulo informado
-- ──────────────────────────────────────────────────────────────────────────────
ALTER TABLE biomie_users
  ADD COLUMN IF NOT EXISTS modules_json JSON NULL
    COMMENT 'Override de módulos permitidos para este usuário específico. NULL = usa o role padrão.'
    AFTER role;
