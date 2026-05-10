-- Fase 5: rastreamento de execuções dos jobs de materialização (camada gold)
-- Cada linha representa uma execução de um job (mart refresh) para um período.
-- Status lifecycle: running → completed | failed | partial

CREATE TABLE IF NOT EXISTS biomie_pipeline_jobs (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    job_name        VARCHAR(120)      NOT NULL COMMENT 'Ex: gold_mart_refresh, gold_mart_refresh_comercial',
    period_start    DATE              NOT NULL,
    period_end      DATE              NOT NULL,
    status          ENUM('running','completed','failed','partial') NOT NULL DEFAULT 'running',
    rows_processed  INT UNSIGNED      NOT NULL DEFAULT 0   COMMENT 'Soma de todas as linhas afetadas nos marts',
    counts_json     JSON              NULL                  COMMENT 'Detalhamento por mart: {bridge:N, vendedor_dia:N, ...}',
    error_message   TEXT              NULL,
    started_at      DATETIME          NOT NULL,
    completed_at    DATETIME          NULL,
    duration_ms     INT UNSIGNED      NULL                  COMMENT 'Duração total do job em milissegundos',
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_job_period (job_name, period_start, period_end),
    INDEX idx_status_started (status, started_at),
    INDEX idx_latest_completed (job_name, completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rastreamento de execuções dos jobs de materialização gold.';
