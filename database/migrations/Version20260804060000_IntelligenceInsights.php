<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * INSIGHTS — leituras narrativas calculadas pelo serviço Python.
 *
 * A home mostra muitos números e poucas conclusões. "R$ 70.607 faturados" não
 * diz se isso é bom; "faturamento 34% abaixo das 4 semanas anteriores, puxado
 * por 2 vendedores" diz. Esta tabela guarda essas leituras já prontas para a
 * tela, calculadas fora do request.
 *
 * POR QUE UMA TABELA, e não cálculo na hora: as janelas comparativas varrem
 * meses de NF. Fazer isso no carregamento da home colocaria segundos no tempo
 * de resposta de uma página que precisa abrir rápido.
 *
 * `valid_until` existe porque insight vencido é pior que insight nenhum — a
 * leitura some sozinha se o job parar, em vez de apresentar conclusão velha
 * como se fosse de hoje.
 *
 * `metric_json` guarda os números que sustentam a frase. Sem isso a tela vira
 * opinião não auditável: quem discorda não tem como conferir a conta.
 */
return new class extends AbstractMigration {

    private const T = 'intelligence_insights';

    public function getVersion(): string
    {
        return '20260804060000';
    }

    public function getDescription(): string
    {
        return 'Insights narrativos por empresa, calculados pelo serviço Python e consumidos pela home.';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                job_run_id BIGINT UNSIGNED NULL,

                kind VARCHAR(48) NOT NULL COLLATE utf8mb4_unicode_ci
                    COMMENT 'ex: tendencia_receita, concentracao_cliente, ritmo_mes',
                severity ENUM('positivo','neutro','atencao','critico') NOT NULL DEFAULT 'neutro',
                -- Ordena a tela sem depender da severidade: dois "atenção" podem
                -- ter pesos muito diferentes para o gestor.
                rank_weight SMALLINT NOT NULL DEFAULT 0,

                title VARCHAR(120) NOT NULL COLLATE utf8mb4_unicode_ci,
                body VARCHAR(400) NOT NULL COLLATE utf8mb4_unicode_ci,
                metric_json TEXT NULL COMMENT 'números que sustentam a frase — auditoria',
                link_url VARCHAR(200) NULL COLLATE utf8mb4_unicode_ci
                    COMMENT 'para onde a leitura leva (drilldown)',

                is_current TINYINT(1) NOT NULL DEFAULT 1,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
                valid_until DATETIME NOT NULL COMMENT 'UTC — insight vencido não aparece',

                PRIMARY KEY (id),
                KEY idx_ii_current (company_id, is_current, valid_until, rank_weight),
                KEY idx_ii_kind (company_id, kind, computed_at),
                CONSTRAINT fk_ii_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_ii_run FOREIGN KEY (job_run_id)
                    REFERENCES intelligence_job_runs (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T));
    }

    public function down(PDO $pdo): void
    {
        $this->dropTables($pdo, [self::T]);
    }
};
