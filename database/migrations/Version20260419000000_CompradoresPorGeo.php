<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260419000000';
    }

    public function getDescription(): string
    {
        return 'Mart analitico de compradores por geografia.';
    }

    public function getConnectionName(): string
    {
        return 'canonical';
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSql($pdo, <<<'SQL'
CREATE TABLE IF NOT EXISTS mart_compradores_por_geo (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competencia CHAR(7) NOT NULL,
  codigo_cliente_omie BIGINT NULL,
  cliente_nome VARCHAR(255) NULL,
  documento VARCHAR(30) NULL,
  uf CHAR(2) NULL,
  cidade_norm VARCHAR(120) NULL,
  bairro_norm VARCHAR(120) NULL,
  regiao_nome VARCHAR(120) NULL,
  cnae_codigo VARCHAR(20) NULL,
  cnae_descricao VARCHAR(255) NULL,
  segmento_macro VARCHAR(80) NULL,
  qtd_notas INT UNSIGNED NOT NULL DEFAULT 0,
  faturamento DECIMAL(15,2) NOT NULL DEFAULT 0,
  ticket_medio DECIMAL(15,2) NOT NULL DEFAULT 0,
  ultima_compra DATE NULL,
  refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cliente_geo_competencia (
    competencia,
    codigo_cliente_omie,
    uf,
    cidade_norm,
    bairro_norm
  ),
  KEY idx_geo_competencia (competencia, uf, cidade_norm, bairro_norm),
  KEY idx_regiao (competencia, regiao_nome),
  KEY idx_faturamento (competencia, faturamento),
  KEY idx_cnae (competencia, cnae_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['mart_compradores_por_geo']);
    }
};
