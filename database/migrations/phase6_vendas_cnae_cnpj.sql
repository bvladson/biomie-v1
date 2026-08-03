CREATE TABLE IF NOT EXISTS mart_vendas_por_cnae_cnpj (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia CHAR(7) NOT NULL,
  documento_normalizado VARCHAR(20) NULL,
  codigo_cliente_omie BIGINT NULL,
  cliente_nome VARCHAR(255) NULL,
  cnae_codigo VARCHAR(20) NULL,
  cnae_descricao VARCHAR(255) NULL,
  segmento_macro VARCHAR(80) NULL,
  uf CHAR(2) NULL,
  cidade_norm VARCHAR(120) NULL,
  bairro_norm VARCHAR(120) NULL,
  qtd_notas INT UNSIGNED NOT NULL DEFAULT 0,
  faturamento DECIMAL(15,2) NOT NULL DEFAULT 0,
  ticket_medio DECIMAL(15,2) NOT NULL DEFAULT 0,
  primeira_compra DATE NULL,
  ultima_compra DATE NULL,
  refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cnae_cliente_competencia (
    competencia,
    codigo_cliente_omie,
    documento_normalizado
  ),
  KEY idx_comp_cnae (competencia, cnae_codigo),
  KEY idx_comp_segmento (competencia, segmento_macro),
  KEY idx_comp_geo (competencia, uf, cidade_norm, bairro_norm),
  KEY idx_comp_faturamento (competencia, faturamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
