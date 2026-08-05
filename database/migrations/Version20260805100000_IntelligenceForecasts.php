<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * FORECAST PERSISTIDO — a previsão para de existir só dentro do processo.
 *
 * Hoje `python -m biomie_intel forecast` calcula, imprime JSON e o resultado
 * morre com o processo. Isso tem três consequências que esta tabela resolve:
 *
 *  1. NÃO DÁ PARA COBRAR O ERRO. Um forecast que ninguém guarda nunca pode ser
 *     confrontado com o realizado. "Erro histórico de 18%" vira autodeclaração:
 *     o número sai do mesmo cálculo que produziu a previsão. Guardando a
 *     previsão COM a data em que foi feita (`computed_at`) e a competência que
 *     ela projeta, o backtest passa a comparar o que foi dito na época com o
 *     que aconteceu depois.
 *  2. A TELA NÃO PODE MOSTRAR. A home precisa ler algo pronto; recalcular no
 *     request varreria meses de NF dentro do carregamento da página.
 *  3. O JOB NÃO TEM DEAD-MAN SWITCH. Sem linha gravada, "forecast parado há
 *     três dias" é indistinguível de "forecast rodando normalmente" — o mesmo
 *     modo de falha que manteve `compute_forecasts` marcado como "nunca
 *     executou" por meses neste projeto.
 *
 * ─── DECISÕES DE MODELAGEM ──────────────────────────────────────────────────
 *
 * `is_current` + histórico: cada execução INSERE e rebaixa a anterior, em vez
 * de sobrescrever. Sobrescrever apagaria justamente a série de previsões que
 * torna o erro auditável.
 *
 * `dimensao`/`entidade_codigo`: a mesma tabela guarda empresa, canal e vendedor.
 * Separar em três tabelas triplicaria o código de leitura para armazenar a mesma
 * forma de dado.
 *
 * `valor_min`/`valor_max` são NOT NULL de propósito: previsão sem banda é
 * palpite apresentado como número. Se o módulo não consegue estimar o erro, ele
 * devolve `ok=false` e não grava.
 *
 * `meta_valor` NULL ≠ 0: empresa sem meta cadastrada não é empresa com meta
 * zero — a segunda faria o risco de não bater a meta dar sempre 0%.
 */
return new class extends AbstractMigration {

    private const T = 'intelligence_forecasts';

    public function getVersion(): string
    {
        return '20260805100000';
    }

    public function getDescription(): string
    {
        return 'Forecast Python persistido por empresa/canal/vendedor, com banda, meta e erro auditavel.';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                job_run_id BIGINT UNSIGNED NULL,

                dimensao ENUM('empresa','canal','vendedor') NOT NULL,
                -- 'empresa' para a linha agregada; código do canal ou do vendedor
                -- nas demais. Nunca NULL: chave composta com NULL não deduplica.
                entidade_codigo VARCHAR(64) NOT NULL COLLATE utf8mb4_unicode_ci,
                entidade_nome VARCHAR(160) NOT NULL COLLATE utf8mb4_unicode_ci,

                competencia CHAR(7) NOT NULL COMMENT 'AAAA-MM que a previsao projeta',

                realizado DECIMAL(15,2) NOT NULL DEFAULT 0,
                projecao DECIMAL(15,2) NOT NULL,
                valor_min DECIMAL(15,2) NOT NULL COMMENT 'piso da faixa provavel',
                valor_max DECIMAL(15,2) NOT NULL COMMENT 'teto da faixa provavel',

                meta_valor DECIMAL(15,2) NULL COMMENT 'NULL = sem meta cadastrada, nao meta zero',
                risco_nao_bater DECIMAL(5,4) NULL COMMENT 'NULL quando nao ha meta para comparar',

                erro_historico DECIMAL(5,4) NOT NULL COMMENT 'MAPE leave-one-out da serie',
                meses_historicos TINYINT UNSIGNED NOT NULL,
                dados_usados SMALLINT UNSIGNED NOT NULL COMMENT 'linhas da serie que sustentam a conta',
                fatores_json TEXT NULL COMMENT 'o que empurrou a projecao — auditoria da frase',

                is_current TINYINT(1) NOT NULL DEFAULT 1,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                KEY idx_if_current (company_id, is_current, dimensao, competencia),
                -- Backtest: "o que foi previsto para 2026-08, e quando".
                KEY idx_if_backtest (company_id, dimensao, entidade_codigo, competencia, computed_at),
                CONSTRAINT fk_if_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_if_run FOREIGN KEY (job_run_id)
                    REFERENCES intelligence_job_runs (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T));
    }

    public function down(PDO $pdo): void
    {
        $this->dropTables($pdo, [self::T]);
    }
};
