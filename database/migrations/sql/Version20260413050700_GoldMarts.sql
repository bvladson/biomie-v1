-- ============================================================
-- BIomie - Camada Gold: Marts Consolidados por Domínio
-- Versão: 20260413050700
--
-- Hierarquia de dependência:
--   bronze (biomie_omie_bronze)
--   → silver (biomie_pedidos_canonicos, biomie_notas_fiscais_canonicas,
--             biomie_titulos_financeiros_canonicos)
--   → gold  (mart_* abaixo)
--
-- Regra central:
--   comercial não manda no fiscal;
--   fiscal não manda no financeiro;
--   o consolidado executivo nasce da ponte entre os três.
-- ============================================================

-- ----------------------------------------------------------
-- 1. mart_comercial_pedido
--    Gold do domínio comercial: um row por pedido, com flags
--    de cobertura fiscal e financeira pré-computadas.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS mart_comercial_pedido (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia             CHAR(7)         NOT NULL COMMENT 'YYYY-MM de data_faturamento ?? data_emissao',
  pedido_id_omie          BIGINT          NOT NULL,
  codigo_pedido           VARCHAR(32)     NOT NULL DEFAULT '',
  codigo_vendedor_omie    VARCHAR(32)     NOT NULL DEFAULT '',
  nome_vendedor           VARCHAR(120)    NOT NULL DEFAULT 'Sem vendedor',
  codigo_cliente_omie     BIGINT          NULL,
  nome_cliente            VARCHAR(200)    NOT NULL DEFAULT 'Cliente nao informado',
  documento_cliente       VARCHAR(20)     NULL,
  status_comercial        ENUM('faturado','autorizado','cancelado','outro') NOT NULL DEFAULT 'outro',
  data_emissao            DATE            NULL,
  data_faturamento        DATE            NULL,
  valor_total             DECIMAL(15,2)   NOT NULL DEFAULT 0,
  valor_mercadorias       DECIMAL(15,2)   NOT NULL DEFAULT 0,
  valor_descontos         DECIMAL(15,2)   NOT NULL DEFAULT 0,
  -- cobertura cross-domain (pré-computada pelo builder)
  tem_nfe                 TINYINT(1)      NOT NULL DEFAULT 0,
  tem_titulo              TINYINT(1)      NOT NULL DEFAULT 0,
  bridge_status           VARCHAR(32)     NOT NULL DEFAULT 'pedido_sem_nf',
  refreshed_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mart_pedido              (pedido_id_omie),
  KEY idx_mart_pedido_comp_vendor        (competencia, codigo_vendedor_omie),
  KEY idx_mart_pedido_status_fat         (status_comercial, data_faturamento),
  KEY idx_mart_pedido_bridge             (bridge_status, competencia),
  KEY idx_mart_pedido_cliente            (codigo_cliente_omie, competencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 2. mart_fiscal_nfe
--    Gold do domínio fiscal: um row por NF-e, com flags de
--    origem comercial e cobertura financeira.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS mart_fiscal_nfe (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia             CHAR(7)         NOT NULL COMMENT 'YYYY-MM de data_competencia ?? data_emissao',
  nota_key                VARCHAR(80)     NOT NULL,
  numero_nf               VARCHAR(32)     NOT NULL DEFAULT '',
  codigo_vendedor_omie    VARCHAR(32)     NOT NULL DEFAULT '',
  nome_vendedor           VARCHAR(120)    NOT NULL DEFAULT 'Sem vendedor',
  codigo_cliente_omie     BIGINT          NULL,
  nome_cliente            VARCHAR(200)    NOT NULL DEFAULT 'Cliente nao informado',
  status_fiscal           ENUM('autorizada','cancelada') NOT NULL DEFAULT 'autorizada',
  data_emissao            DATE            NULL,
  data_competencia        DATE            NULL,
  valor_total             DECIMAL(15,2)   NOT NULL DEFAULT 0,
  valor_mercadorias       DECIMAL(15,2)   NOT NULL DEFAULT 0,
  valor_descontos         DECIMAL(15,2)   NOT NULL DEFAULT 0,
  -- cobertura cross-domain
  pedido_id_omie          BIGINT          NULL,
  tem_pedido              TINYINT(1)      NOT NULL DEFAULT 0,
  tem_titulo              TINYINT(1)      NOT NULL DEFAULT 0,
  bridge_status           VARCHAR(32)     NOT NULL DEFAULT 'nf_sem_titulo',
  refreshed_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mart_nfe                 (nota_key),
  KEY idx_mart_nfe_comp_vendor           (competencia, codigo_vendedor_omie),
  KEY idx_mart_nfe_status                (status_fiscal, data_competencia),
  KEY idx_mart_nfe_bridge                (bridge_status, competencia),
  KEY idx_mart_nfe_pedido                (pedido_id_omie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3. mart_financeiro_titulo
--    Gold do domínio financeiro: um row por título, com flags
--    de origem fiscal e comercial, e aging pré-calculado.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS mart_financeiro_titulo (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia             CHAR(7)         NOT NULL COMMENT 'YYYY-MM de data_competencia ?? data_vencimento',
  titulo_key              VARCHAR(96)     NOT NULL,
  numero_documento        VARCHAR(64)     NOT NULL DEFAULT '',
  status_titulo           VARCHAR(32)     NOT NULL DEFAULT 'outro',
  especie_documento       VARCHAR(64)     NULL,
  codigo_vendedor_omie    VARCHAR(32)     NOT NULL DEFAULT '',
  nome_vendedor           VARCHAR(120)    NOT NULL DEFAULT 'Sem vendedor',
  codigo_cliente_omie     BIGINT          NULL,
  nome_cliente            VARCHAR(200)    NOT NULL DEFAULT 'Cliente nao informado',
  documento_cliente       VARCHAR(20)     NULL,
  data_vencimento         DATE            NULL,
  data_liquidacao         DATE            NULL,
  data_competencia        DATE            NULL,
  valor_aberto            DECIMAL(15,2)   NOT NULL DEFAULT 0,
  valor_recebido          DECIMAL(15,2)   NOT NULL DEFAULT 0,
  dias_atraso             INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'calculado no refresh; 0 se nao vencido',
  -- cobertura cross-domain
  nota_key                VARCHAR(80)     NULL,
  pedido_id_omie          BIGINT          NULL,
  tem_nfe                 TINYINT(1)      NOT NULL DEFAULT 0,
  tem_pedido              TINYINT(1)      NOT NULL DEFAULT 0,
  bridge_status           VARCHAR(32)     NOT NULL DEFAULT 'titulo_sem_pedido',
  refreshed_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mart_titulo              (titulo_key),
  KEY idx_mart_titulo_comp_vendor        (competencia, codigo_vendedor_omie),
  KEY idx_mart_titulo_vencimento         (data_vencimento, status_titulo),
  KEY idx_mart_titulo_bridge             (bridge_status, competencia),
  KEY idx_mart_titulo_cliente            (codigo_cliente_omie, competencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. mart_bridge_pedido_nfe_titulo  (o mart central)
--    Um row por pedido. Explica o caminho completo:
--    pedido → NF-e → título. Divergências cross-domain ficam
--    visíveis aqui sem precisar cruzar tabelas na requisição.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS mart_bridge_pedido_nfe_titulo (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia             CHAR(7)         NOT NULL,
  pedido_id_omie          BIGINT          NOT NULL,
  codigo_pedido           VARCHAR(32)     NOT NULL DEFAULT '',
  codigo_vendedor_omie    VARCHAR(32)     NOT NULL DEFAULT '',
  nome_vendedor           VARCHAR(120)    NOT NULL DEFAULT 'Sem vendedor',
  codigo_cliente_omie     BIGINT          NULL,
  nome_cliente            VARCHAR(200)    NOT NULL DEFAULT 'Cliente nao informado',
  documento_cliente       VARCHAR(20)     NULL,
  -- domínio comercial
  status_comercial        ENUM('faturado','autorizado','cancelado','outro') NOT NULL DEFAULT 'outro',
  data_emissao            DATE            NULL,
  data_faturamento        DATE            NULL,
  valor_pedido            DECIMAL(15,2)   NOT NULL DEFAULT 0,
  valor_mercadorias_pedido DECIMAL(15,2)  NOT NULL DEFAULT 0,
  -- domínio fiscal (NULL = pedido sem NF-e)
  nota_key                VARCHAR(80)     NULL,
  status_fiscal           VARCHAR(32)     NULL,
  data_competencia_nfe    DATE            NULL,
  valor_nfe               DECIMAL(15,2)  NOT NULL DEFAULT 0,
  -- domínio financeiro (NULL = sem título gerado)
  titulo_key              VARCHAR(96)     NULL,
  status_titulo           VARCHAR(32)     NULL,
  data_vencimento         DATE            NULL,
  data_liquidacao         DATE            NULL,
  valor_aberto            DECIMAL(15,2)  NOT NULL DEFAULT 0,
  valor_recebido          DECIMAL(15,2)  NOT NULL DEFAULT 0,
  -- status consolidado da ponte
  bridge_status           ENUM(
    'pedido_sem_nf',       -- comercial sem cobertura fiscal
    'nf_sem_titulo',       -- fiscal sem cobertura financeira
    'nf_cancelada',        -- NF emitida mas depois cancelada
    'titulo_sem_pedido',   -- título sem pedido rastreável
    'completo',            -- todas três camadas presentes e consistentes
    'cancelado'            -- pedido cancelado
  ) NOT NULL DEFAULT 'pedido_sem_nf',
  refreshed_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bridge_pedido            (pedido_id_omie),
  KEY idx_bridge_comp_vendor             (competencia, codigo_vendedor_omie),
  KEY idx_bridge_comp_client             (competencia, codigo_cliente_omie),
  KEY idx_bridge_status                  (bridge_status, competencia),
  KEY idx_bridge_nota                    (nota_key),
  KEY idx_bridge_titulo                  (titulo_key),
  KEY idx_bridge_data                    (data_emissao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 5. mart_vendedor_dia
--    Agregação vendor × dia, das três fontes.
--    Substitui GROUP BY em tempo de requisição nos endpoints.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS mart_vendedor_dia (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia             CHAR(7)         NOT NULL,
  dia                     DATE            NOT NULL,
  codigo_vendedor_omie    VARCHAR(32)     NOT NULL DEFAULT '',
  nome_vendedor           VARCHAR(120)    NOT NULL DEFAULT 'Sem vendedor',
  -- comercial
  pedidos_faturados       INT UNSIGNED    NOT NULL DEFAULT 0,
  valor_faturado          DECIMAL(15,2)   NOT NULL DEFAULT 0,
  mercadorias_faturado    DECIMAL(15,2)   NOT NULL DEFAULT 0,
  descontos_faturado      DECIMAL(15,2)   NOT NULL DEFAULT 0,
  pedidos_autorizados     INT UNSIGNED    NOT NULL DEFAULT 0,
  valor_autorizado        DECIMAL(15,2)   NOT NULL DEFAULT 0,
  mercadorias_autorizadas DECIMAL(15,2)   NOT NULL DEFAULT 0,
  pedidos_cancelados      INT UNSIGNED    NOT NULL DEFAULT 0,
  valor_cancelado         DECIMAL(15,2)   NOT NULL DEFAULT 0,
  -- fiscal
  nfes_emitidas           INT UNSIGNED    NOT NULL DEFAULT 0,
  valor_nfe               DECIMAL(15,2)   NOT NULL DEFAULT 0,
  mercadorias_nfe         DECIMAL(15,2)   NOT NULL DEFAULT 0,
  -- financeiro
  titulos_recebidos       INT UNSIGNED    NOT NULL DEFAULT 0,
  valor_recebido          DECIMAL(15,2)   NOT NULL DEFAULT 0,
  titulos_abertos         INT UNSIGNED    NOT NULL DEFAULT 0,
  valor_aberto            DECIMAL(15,2)   NOT NULL DEFAULT 0,
  refreshed_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendedor_dia             (competencia, dia, codigo_vendedor_omie),
  KEY idx_vendedor_dia_range             (dia),
  KEY idx_vendedor_dia_competencia       (competencia),
  KEY idx_vendedor_dia_vendor            (codigo_vendedor_omie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 6. mart_cliente_periodo
--    Agregação cliente × competência, das três fontes.
--    Substitui topClientsCommercial() e queries de inadimplência.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS mart_cliente_periodo (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia             CHAR(7)         NOT NULL,
  codigo_cliente_omie     BIGINT          NOT NULL,
  nome_cliente            VARCHAR(200)    NOT NULL DEFAULT 'Cliente nao informado',
  documento_cliente       VARCHAR(20)     NULL,
  -- comercial
  pedidos_faturados       INT UNSIGNED    NOT NULL DEFAULT 0,
  valor_faturado          DECIMAL(15,2)   NOT NULL DEFAULT 0,
  mercadorias_faturado    DECIMAL(15,2)   NOT NULL DEFAULT 0,
  -- fiscal
  nfes_emitidas           INT UNSIGNED    NOT NULL DEFAULT 0,
  valor_nfe               DECIMAL(15,2)   NOT NULL DEFAULT 0,
  -- financeiro
  titulos_total           INT UNSIGNED    NOT NULL DEFAULT 0,
  valor_aberto            DECIMAL(15,2)   NOT NULL DEFAULT 0,
  valor_recebido          DECIMAL(15,2)   NOT NULL DEFAULT 0,
  dias_atraso_max         INT UNSIGNED    NOT NULL DEFAULT 0,
  refreshed_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cliente_periodo          (competencia, codigo_cliente_omie),
  KEY idx_cliente_periodo_competencia    (competencia),
  KEY idx_cliente_periodo_valor          (competencia, valor_faturado),
  KEY idx_cliente_periodo_atraso         (competencia, dias_atraso_max)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 7. mart_produto_periodo
--    Agregação produto × competência a partir dos itens de NF-e.
--    Substitui topProductsCommercial() sem GROUP BY em itens.
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS mart_produto_periodo (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia             CHAR(7)         NOT NULL,
  codigo_produto          VARCHAR(60)     NOT NULL DEFAULT '',
  descricao_produto       VARCHAR(255)    NOT NULL DEFAULT 'Produto sem descricao',
  nfes_count              INT UNSIGNED    NOT NULL DEFAULT 0,
  quantidade              DECIMAL(15,4)   NOT NULL DEFAULT 0,
  valor_total             DECIMAL(15,2)   NOT NULL DEFAULT 0,
  valor_desconto          DECIMAL(15,2)   NOT NULL DEFAULT 0,
  refreshed_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_produto_periodo          (competencia, codigo_produto),
  KEY idx_produto_periodo_competencia    (competencia),
  KEY idx_produto_periodo_valor          (competencia, valor_total)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
