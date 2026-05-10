-- Fase 11 — Observabilidade e Manutenção
-- Tabela de alertas automáticos gerados pelo AlertEngine.
-- Idempotente: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS biomie_health_alerts (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    rule_code       VARCHAR(80)         NOT NULL  COMMENT 'Identificador único da regra que disparou o alerta',
    severity        ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    title           VARCHAR(200)        NOT NULL,
    message         TEXT                NOT NULL,
    context_json    JSON                NULL       COMMENT 'Dados de contexto: job_name, thresholds, valores medidos',
    triggered_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME            NULL       COMMENT 'NULL = ativo; preenchido = resolvido automaticamente',
    PRIMARY KEY (id),
    KEY idx_alerts_active    (rule_code, resolved_at),
    KEY idx_alerts_triggered (triggered_at DESC),
    KEY idx_alerts_severity  (severity, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Alertas automáticos de saúde do pipeline — gerados pelo bin/evaluate_health.php (cron horário).';
