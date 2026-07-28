-- ============================================================
-- Fase 7: Inteligência comercial — mart_oportunidades
--
-- Uma linha por oportunidade detectada por período.
-- Idempotente: UNIQUE KEY garante que re-rodar o detector
-- apenas atualiza oportunidades existentes.
--
-- Tipos de oportunidade:
--   cliente_sumiu         — era recorrente, parou de comprar
--   recompra_potencial    — dormente com bom histórico, ripe para ativação
--   queda_de_compra       — comprou menos no período vs anterior
--   concentracao_cliente  — cliente representa risco de concentração
--   concentracao_geo      — região representa risco de concentração
--   crescimento_geo       — região crescendo acima da média
--   retracao_geo          — região retraindo vs período anterior
--   vendedor_sem_ativacao — vendedor com carteira de clientes dormentes
--   mix_subexplorado      — segmento relevante em uma praça mas pouco trabalhado
-- ============================================================

CREATE TABLE IF NOT EXISTS mart_oportunidades (
  id                      BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,

  -- Dimensão da entidade
  tipo_entidade           ENUM('cliente','vendedor','regiao','segmento')
                          NOT NULL,
  codigo_entidade         VARCHAR(40)       NOT NULL COMMENT 'codigo_cliente_omie, codigo_vendedor_omie, cidade_norm ou segmento_cnae',
  nome_entidade           VARCHAR(255)      NOT NULL,

  -- Tipo e prioridade
  tipo_oportunidade       ENUM(
    'cliente_sumiu',
    'recompra_potencial',
    'queda_de_compra',
    'concentracao_cliente',
    'concentracao_geo',
    'crescimento_geo',
    'retracao_geo',
    'vendedor_sem_ativacao',
    'mix_subexplorado'
  ) NOT NULL,
  score                   TINYINT UNSIGNED  NOT NULL DEFAULT 50
                          COMMENT '0-100; maior = mais urgente; 80+ = alta prioridade',

  -- Contexto do cliente/segmento
  tipo_pessoa             VARCHAR(20)       NULL,
  segmento_cnae           VARCHAR(120)      NULL,
  uf                      CHAR(2)           NULL,

  -- Responsável comercial
  codigo_vendedor_omie    VARCHAR(32)       NULL,
  nome_vendedor           VARCHAR(120)      NULL,

  -- Competência de referência da análise
  competencia_referencia  CHAR(7)           NOT NULL COMMENT 'YYYY-MM em que a oportunidade foi detectada',

  -- Valores financeiros para contextualizar o insight
  valor_referencia        DECIMAL(15,2)     NOT NULL DEFAULT 0
                          COMMENT 'Valor do período atual ou valor histórico do cliente',
  valor_comparativo       DECIMAL(15,2)     NOT NULL DEFAULT 0
                          COMMENT 'Valor do período anterior para comparação',
  variacao_pct            DECIMAL(7,2)      NOT NULL DEFAULT 0
                          COMMENT 'positivo = cresceu, negativo = caiu; 0 quando inaplicável',

  -- Métricas de apoio
  dias_sem_compra         INT UNSIGNED      NULL,
  clientes_count          INT UNSIGNED      NULL
                          COMMENT 'Qtd de clientes dormentes (para oportunidade de vendedor)',

  -- Insight gerado
  insight_resumo          VARCHAR(500)      NOT NULL,
  contexto_json           JSON              NULL
                          COMMENT 'Dados extras: histórico, período, limiares usados, etc.',

  refreshed_at            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_oportunidade (
    tipo_entidade, codigo_entidade, tipo_oportunidade, competencia_referencia
  ),
  KEY idx_oport_score           (tipo_oportunidade, score DESC),
  KEY idx_oport_vendedor        (codigo_vendedor_omie, score DESC),
  KEY idx_oport_competencia     (competencia_referencia, score DESC),
  KEY idx_oport_entidade_tipo   (tipo_entidade, competencia_referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Oportunidades comerciais detectadas automaticamente por período.';
