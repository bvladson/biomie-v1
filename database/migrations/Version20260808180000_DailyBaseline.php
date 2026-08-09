<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string { return '20260808180000'; }
    public function getDescription(): string { return 'Faixa normal de faturamento por empresa e dia da semana (quantis empiricos).'; }

    public function up(PDO $pdo): void
    {
        // Faixa NORMAL de um dia, por empresa e dia da semana.
        //
        // ─── POR QUE QUANTIL E NAO MEDIA ± DESVIO ───────────────────────────
        // Medido na MAG_FILIAL (161 dias): media R$ 30.519, maximo R$ 671.082,
        // desvio R$ 78.162 — o desvio e 2,6x a MEDIA. Numa distribuicao assim,
        // "media mais um desvio" produz faixa que vai de negativo a R$ 108 mil e
        // cobre praticamente qualquer desfecho: uma faixa que nunca erra tambem
        // nunca informa.
        //
        // Quantil empirico nao supoe distribuicao nenhuma. p25–p75 e a faixa
        // tipica; fora de p10/p90 e dia atipico.
        //
        // ─── POR QUE POR DIA DA SEMANA ──────────────────────────────────────
        // Sabado nao se compara com terca. A MAG_FILIAL fatura em 6 dias por
        // semana (zero domingos em 161 dias) com perfis diferentes; comparar um
        // sabado com a media geral acusaria queda toda semana.
        //
        // `amostra` e `confianca` ficam GRAVADAS: sem elas, uma faixa calculada
        // sobre 3 dias tem a mesma aparencia de uma calculada sobre 28.
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intelligence_daily_baseline (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  job_run_id BIGINT UNSIGNED NULL,
  -- 1=domingo .. 7=sabado (mesma convencao de DAYOFWEEK do MariaDB, para o
  -- SQL do PHP casar sem traducao no meio do caminho)
  weekday TINYINT UNSIGNED NOT NULL,
  amostra SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  janela_dias SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  mediana_revenue DECIMAL(15,2) NULL,
  p10_revenue DECIMAL(15,2) NULL,
  p25_revenue DECIMAL(15,2) NULL,
  p75_revenue DECIMAL(15,2) NULL,
  p90_revenue DECIMAL(15,2) NULL,
  mediana_invoices DECIMAL(9,2) NULL,
  -- `sem_amostra` nao e um erro: e a resposta certa para empresa que fatura em
  -- 20 dias no total. O consumidor precisa distinguir isso de "dentro da faixa".
  confianca ENUM('alta','media','baixa','sem_amostra') NOT NULL DEFAULT 'sem_amostra',
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- SEM `is_current`, e a unique e so (empresa, dia da semana).
  --
  -- Aqui o registro e ATUALIZADO no lugar, nao versionado: o baseline e uma
  -- janela movel recalculada todo dia, sem dimensao de competencia que faca a
  -- versao anterior continuar sendo "a ultima palavra" sobre alguma coisa —
  -- diferente de `intelligence_forecasts`, onde a previsao antiga precisa
  -- sobreviver para o erro ser cobrado depois.
  --
  -- Guardar `is_current` aqui daria 7 linhas novas por empresa por dia sem
  -- ninguem para lê-las. Assim a tabela fica em 35 linhas para sempre.
  UNIQUE KEY uq_daily_baseline (company_id, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS intelligence_daily_baseline');
    }
};
