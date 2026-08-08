<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string { return '20260808120000'; }
    public function getDescription(): string { return 'Risco de atraso em recebiveis e anomalias operacionais na telemetria.'; }

    public function up(PDO $pdo): void
    {
        // ── Risco de atraso por titulo em aberto ─────────────────────────────
        //
        // `universo` NAO e detalhe: e o que torna o numero interpretavel.
        //
        // Em titulo de cartao/adquirente, `data_vencimento` e a data em que a
        // ADQUIRENTE repassa o dinheiro, nao uma obrigacao do cliente. O cliente
        // ja pagou no ato. Medido em producao: cartao tem media de -139 dias
        // entre liquidacao e vencimento e 0% de atraso — nao porque paga bem,
        // mas porque atraso ali e impossivel por construcao.
        //
        // Misturar os dois universos produz "1,9% de inadimplencia" para o grupo
        // todo, que e a media entre uma taxa real e uma taxa que nao existe.
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intelligence_receivable_risk (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  job_run_id BIGINT UNSIGNED NULL,
  titulo_key VARCHAR(120) NOT NULL,
  codigo_cliente_omie BIGINT NULL,
  nome_cliente VARCHAR(200) NULL,
  forma_pagamento VARCHAR(64) NULL,
  -- credito_real  = o cliente deve numa data (boleto, PIX a prazo, transferencia)
  -- imediato      = cartao/adquirente; `vencimento` e repasse, nao obrigacao
  -- indefinido    = forma ausente; nao da para afirmar nem uma coisa nem outra
  universo ENUM('credito_real','imediato','indefinido') NOT NULL DEFAULT 'indefinido',
  valor_aberto DECIMAL(15,2) NOT NULL DEFAULT 0,
  data_vencimento DATE NULL,
  dias_para_vencer INT NULL COMMENT 'negativo = ja vencido',
  -- NULL, nunca 0: "nao da para pontuar" e diferente de "risco zero". Titulo de
  -- cartao e titulo de cliente sem historico caem aqui.
  risk_score DECIMAL(5,2) NULL,
  risk_band ENUM('alta','media','baixa') NULL,
  reasons_json TEXT NULL,
  features_json TEXT NULL,
  model_version VARCHAR(40) NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rr_current (company_id, is_current, risk_band, risk_score),
  KEY idx_rr_titulo (company_id, titulo_key, computed_at),
  KEY idx_rr_venc (company_id, is_current, data_vencimento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        // ── Anomalias operacionais vindas da telemetria ──────────────────────
        //
        // Sem company_id de proposito: span de sync e de HTTP e infraestrutura,
        // nao dado de tenant. Forcar uma empresa aqui inventaria um recorte que
        // o dado nao tem.
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intelligence_ops_anomalies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_run_id BIGINT UNSIGNED NULL,
  anomaly_type ENUM('taxa_erro','latencia','volume_sumiu') NOT NULL,
  domain_name VARCHAR(32) NOT NULL,
  span_name VARCHAR(128) NOT NULL,
  severity ENUM('info','atencao','critico') NOT NULL DEFAULT 'atencao',
  title VARCHAR(160) NOT NULL,
  body VARCHAR(400) NOT NULL,
  observado DECIMAL(18,4) NULL,
  baseline DECIMAL(18,4) NULL,
  variacao_pct DECIMAL(10,2) NULL,
  amostra_recente INT UNSIGNED NOT NULL DEFAULT 0,
  amostra_baseline INT UNSIGNED NOT NULL DEFAULT 0,
  metric_json TEXT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ops_current (is_current, severity, anomaly_type),
  KEY idx_ops_span (domain_name, span_name, computed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS intelligence_ops_anomalies');
        $pdo->exec('DROP TABLE IF EXISTS intelligence_receivable_risk');
    }
};
