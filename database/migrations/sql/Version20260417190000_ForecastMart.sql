-- ============================================================
-- Fase 8: mart_forecasts — camada preditiva transparente.
--
-- Projeções simples (linear, proporcional, ticket médio).
-- Confiança declarada em cada linha (0–100).
-- Sem modelos de ML — total transparência sobre o método.
-- ============================================================

CREATE TABLE IF NOT EXISTS mart_forecasts (
    id                      BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,

    tipo_forecast ENUM(
        'mes_corrente',
        'vendedor',
        'carteira',
        'regiao',
        'mix_categoria'
    ) NOT NULL COMMENT 'Dimensão da projeção',

    codigo_entidade         VARCHAR(120)     NOT NULL DEFAULT 'geral'
        COMMENT 'codigo_vendedor_omie, UF, segmento ou "geral"',
    nome_entidade           VARCHAR(255)     NOT NULL DEFAULT '',
    competencia_referencia  CHAR(7)          NOT NULL
        COMMENT 'YYYY-MM do mês projetado',

    -- Valores monetários
    valor_realizado         DECIMAL(15,2)    NOT NULL DEFAULT 0.00
        COMMENT 'Faturado até o momento no mês',
    valor_projetado         DECIMAL(15,2)    NOT NULL DEFAULT 0.00
        COMMENT 'Projeção para fechamento do mês',
    valor_meta              DECIMAL(15,2)    NULL
        COMMENT 'Meta do mês (quando disponível)',

    -- Qualidade da projeção
    confianca_pct           TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0–100 baseado em dias com dados: <7d→20, 7-13d→45, 14-20d→65, 21-27d→80, ≥28d→95',
    metodo                  VARCHAR(60)      NOT NULL DEFAULT 'linear_simples'
        COMMENT 'linear_simples | proporcional_mes | clientes_ativos_x_ticket_medio | snapshot_parcial',
    dias_base               TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Dias do mês com dados registrados',
    dias_restantes          TINYINT UNSIGNED NOT NULL DEFAULT 0,

    -- Tendência calculada sobre os 3 meses fechados anteriores
    tendencia               ENUM('crescimento','estavel','queda','inconclusivo') NOT NULL DEFAULT 'inconclusivo',
    tendencia_pct           DECIMAL(8,2)     NOT NULL DEFAULT 0.00
        COMMENT 'Variação % do trio recente vs. trio anterior',

    -- Gap para meta (NULL quando sem meta)
    gap_para_meta           DECIMAL(15,2)    NULL,
    gap_pct                 DECIMAL(8,2)     NULL,
    risco_level             ENUM('baixo','medio','alto','sem_meta') NOT NULL DEFAULT 'sem_meta',

    refreshed_at            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_forecast (tipo_forecast, codigo_entidade, competencia_referencia),
    KEY idx_forecast_competencia (competencia_referencia),
    KEY idx_forecast_tipo_risco  (tipo_forecast, risco_level),
    KEY idx_forecast_confianca   (competencia_referencia, confianca_pct)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fase 8: projeções mensais por dimensão — método linear simples, confiança declarada.';
