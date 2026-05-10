-- ============================================================
-- BIomie - Phase 2: Geographic and Client Dimensions
-- Executar para criar estruturas de Enriquecimento e Analise
-- ============================================================

CREATE TABLE IF NOT EXISTS biomie_clientes_dim (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo_cliente_omie BIGINT NOT NULL,
  cpf_cnpj VARCHAR(20) NULL,
  tipo_documento VARCHAR(10) NULL,
  nome_cliente VARCHAR(255) NOT NULL DEFAULT 'Cliente sem nome',
  nome_fantasia VARCHAR(255) NULL,
  natureza_cliente VARCHAR(100) NULL,
  cnae_codigo VARCHAR(20) NULL,
  cnae_descricao VARCHAR(255) NULL,
  segmento_macro VARCHAR(50) NOT NULL DEFAULT 'PJ_SEM_CNAE',
  primeira_compra_em DATE NULL,
  ultima_compra_em DATE NULL,
  notas_12m INT UNSIGNED NOT NULL DEFAULT 0,
  faturado_12m DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_recorrente TINYINT(1) NOT NULL DEFAULT 0,
  recorrencia_faixa VARCHAR(50) NULL,
  sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_codigo_cliente_omie (codigo_cliente_omie),
  KEY idx_cliente_cnae (cnae_codigo),
  KEY idx_cliente_segmento (segmento_macro),
  KEY idx_cliente_recorrencia (is_recorrente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_clientes_geo_dim (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo_cliente_omie BIGINT NOT NULL,
  cep VARCHAR(20) NULL,
  uf VARCHAR(2) NULL,
  municipio_ibge VARCHAR(20) NULL,
  codigo_cidade_omie VARCHAR(20) NULL,
  cidade_raw VARCHAR(255) NULL,
  cidade_norm VARCHAR(120) NULL,
  bairro_raw VARCHAR(255) NULL,
  bairro_norm VARCHAR(120) NULL,
  logradouro VARCHAR(255) NULL,
  numero VARCHAR(50) NULL,
  complemento VARCHAR(255) NULL,
  lat DECIMAL(10,8) NULL,
  lng DECIMAL(11,8) NULL,
  bairro_oficial_id_fortaleza INT NULL,
  bairro_oficial_nome_fortaleza VARCHAR(150) NULL,
  match_source VARCHAR(50) NULL,
  match_confidence DECIMAL(5,2) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_geo_cliente_omie (codigo_cliente_omie),
  KEY idx_geo_norm (uf, cidade_norm, bairro_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_vendas_geo_cliente_fact (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  data_referencia DATE NOT NULL,
  nf_id BIGINT NULL,
  pedido_id BIGINT NULL,
  codigo_cliente_omie BIGINT NULL,
  codigo_vendedor_omie VARCHAR(32) NULL,
  status_fiscal VARCHAR(50) NOT NULL DEFAULT 'outro',
  uf VARCHAR(2) NULL,
  cidade VARCHAR(120) NULL,
  bairro VARCHAR(120) NULL,
  tipo_documento VARCHAR(10) NULL,
  segmento_macro VARCHAR(50) NULL,
  is_recorrente TINYINT(1) NOT NULL DEFAULT 0,
  valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_mercadorias DECIMAL(15,2) NOT NULL DEFAULT 0,
  descontos DECIMAL(15,2) NOT NULL DEFAULT 0,
  quantidade_itens DECIMAL(15,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_vendas_geo_data (data_referencia),
  KEY idx_vendas_geo_cliente (codigo_cliente_omie),
  KEY idx_vendas_geo_uf_cidade (uf, cidade),
  KEY idx_vendas_geo_segmento (segmento_macro),
  KEY idx_vendas_geo_data_uf_cidade_bairro (data_referencia, uf, cidade, bairro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_geo_vendas_diaria (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  escopo_geo ENUM('bairro_fortaleza', 'cidade_ce', 'uf_brasil') NOT NULL,
  data_referencia DATE NOT NULL,
  uf VARCHAR(2) NOT NULL DEFAULT '',
  cidade_norm VARCHAR(120) NOT NULL DEFAULT '',
  bairro_norm VARCHAR(120) NOT NULL DEFAULT '',
  segmento_macro VARCHAR(50) NOT NULL DEFAULT 'OUTRO',
  tipo_documento VARCHAR(10) NOT NULL DEFAULT 'PJ',
  is_recorrente TINYINT(1) NOT NULL DEFAULT 0,
  vendas_qtd INT UNSIGNED NOT NULL DEFAULT 0,
  clientes_unicos INT UNSIGNED NOT NULL DEFAULT 0,
  valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  valor_mercadorias DECIMAL(15,2) NOT NULL DEFAULT 0,
  ticket_medio DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_geo_diaria_data_escopo (data_referencia, escopo_geo),
  KEY idx_geo_diaria_scope_location (escopo_geo, uf, cidade_norm, bairro_norm),
  UNIQUE KEY uq_geo_vendas_diaria (
    escopo_geo, data_referencia, uf, cidade_norm, bairro_norm, segmento_macro, tipo_documento, is_recorrente
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_clientes_para_enriquecer (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo_cliente_omie BIGINT NOT NULL,
  source_origin VARCHAR(64) NOT NULL DEFAULT 'canonica_nf',
  data_descoberta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultima_tentativa DATETIME NULL,
  status ENUM('pendente', 'processando', 'concluido', 'falha') NOT NULL DEFAULT 'pendente',
  erro TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_cliente (codigo_cliente_omie),
  KEY idx_queue_status_data (status, data_descoberta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
