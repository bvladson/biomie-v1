<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * BACKTEST TERRITORIAL — o score precisa provar que ordena.
 *
 * ─── POR QUE ESTA TABELA VEM ANTES DO RESTO DO ROADMAP GEO ──────────────────
 * Hoje ha 610 scores territoriais vigentes em producao: 553 em confianca
 * "baixa", 57 em "media", NENHUM em "alta". Um score que nao separa e
 * indistinguivel de nao ter score — foi exatamente o que aconteceu com a
 * primeira calibracao do score de orcamentos, que colocou 100% da base na banda
 * "alta" e so foi descoberto quando alguem mediu a dispersao.
 *
 * Sem esta tabela, "priorize o territorio X" e uma afirmacao que ninguem pode
 * cobrar. Com ela, a pergunta vira aritmetica: os territorios que o score
 * colocou no topo faturaram mais no mes seguinte que os demais, ou nao?
 *
 * ─── A METRICA QUE MANDA E O LIFT, NAO A PRECISAO ───────────────────────────
 * `precisao_top_n` sozinha engana: se 70% de TODOS os territorios cresceram no
 * mes, acertar 70% do top nao e merito nenhum. Por isso `precisao_base` — a
 * taxa da populacao inteira — e gravada ao lado, e o veredito olha o LIFT:
 * quanto o grupo priorizado cresceu dividido por quanto o resto cresceu.
 * Lift <= 1 significa que a ordenacao nao vale nada.
 *
 * ─── SEM VEREDITO INVENTADO ─────────────────────────────────────────────────
 * `veredito` inclui `amostra_insuficiente` de proposito. O mart geografico tem
 * 8 meses de historico; com 4 janelas de avaliacao, declarar "o score funciona"
 * seria repetir o erro do AUC 0,94 que era vies de sobrevivencia. Amostra
 * pequena tem que APARECER, nao virar silencio nem otimismo.
 *
 * ─── O QUE E GRAVADO E IMUTAVEL ─────────────────────────────────────────────
 * Cada linha guarda o que foi PREVISTO na epoca e o que foi OBSERVADO depois.
 * `is_current` marca a rodada vigente, mas o historico permanece: e ele que
 * permite ver se o modelo esta melhorando ou piorando ao longo dos meses.
 */
return new class extends AbstractMigration {

    private const T = 'intelligence_geo_backtests';

    public function getVersion(): string
    {
        return '20260806020000';
    }

    public function getDescription(): string
    {
        return 'Backtest territorial: mede se o score de oportunidade realmente ordena, com lift contra a taxa base.';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                job_run_id BIGINT UNSIGNED NULL,

                layer_id VARCHAR(48) NOT NULL COLLATE utf8mb4_unicode_ci
                    COMMENT 'ceara_municipios, fortaleza_bairros, ...',
                -- Competencia em que o score FOI CALCULADO. O observado vem depois dela.
                competencia_referencia CHAR(7) NOT NULL,
                horizonte_meses TINYINT UNSIGNED NOT NULL DEFAULT 1,

                territorios_avaliados SMALLINT UNSIGNED NOT NULL,
                top_n TINYINT UNSIGNED NOT NULL,

                -- ORDENACAO: o score coloca no topo quem de fato cresce mais?
                lift_top_n DECIMAL(8,4) NULL
                    COMMENT 'crescimento medio do top / crescimento medio do resto; <=1 nao ordena',
                precisao_top_n DECIMAL(5,4) NULL COMMENT 'fracao do top que cresceu',
                precisao_base DECIMAL(5,4) NULL
                    COMMENT 'fracao da POPULACAO que cresceu — sem ela a precisao nao diz nada',
                spearman DECIMAL(6,4) NULL COMMENT 'correlacao de ordem entre score e crescimento',

                -- PREVISAO: a projecao acertou, e a faixa cobriu?
                mape DECIMAL(8,4) NULL COMMENT 'erro medio absoluto percentual da projecao',
                cobertura_faixa_pct DECIMAL(5,2) NULL
                    COMMENT 'quantos realizados cairam dentro de [forecast_low, forecast_high]',

                veredito ENUM('ordena','nao_ordena','amostra_insuficiente') NOT NULL,
                motivo VARCHAR(240) NOT NULL COLLATE utf8mb4_unicode_ci,
                detalhe_json TEXT NULL COMMENT 'top avaliado, previsto x observado — auditoria',

                model_version VARCHAR(40) NOT NULL COLLATE utf8mb4_unicode_ci,
                is_current TINYINT(1) NOT NULL DEFAULT 1,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                UNIQUE KEY uq_geo_backtest (company_id, layer_id, competencia_referencia, horizonte_meses, model_version),
                KEY idx_geo_backtest_current (company_id, is_current, layer_id),
                CONSTRAINT fk_geo_backtest_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_geo_backtest_run FOREIGN KEY (job_run_id)
                    REFERENCES intelligence_job_runs (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T));
    }

    public function down(PDO $pdo): void
    {
        $this->dropTables($pdo, [self::T]);
    }
};
