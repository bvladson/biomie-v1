<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * CAMADA DE INTELIGÊNCIA — priorização de orçamentos.
 *
 * Três tabelas, três papéis distintos:
 *
 *   intelligence_job_runs      — trilha de execução do serviço Python. Sem isto,
 *                                um job que morre calado é indistinguível de um
 *                                job que nunca foi agendado (foi exatamente o que
 *                                aconteceu com compute_forecasts por meses).
 *   intelligence_quote_scores  — o score de cada orçamento, COM as features que o
 *                                produziram. Guardar as features é o que permite
 *                                auditar "por que este orçamento é prioridade?"
 *                                meses depois, e comparar modelo vs baseline.
 *   intelligence_quote_feedback— o que o vendedor respondeu. É a ÚNICA fonte de
 *                                rótulo confiável para treino supervisionado:
 *                                hoje `resolution` da agenda está 100% NULL e
 *                                `data_faturamento` dos pedidos só tem 3 de 334.
 *
 * MODO DE OPERAÇÃO (run_mode) — o mesmo score pode ser gravado em três posturas:
 *   baseline — determinístico, é o que a tela mostra
 *   shadow   — modelo rodando em silêncio, gravado mas NUNCA exibido
 *   active   — modelo promovido a fonte da tela (só após validação humana)
 * Manter os três na mesma tabela é o que torna a comparação trivial e honesta:
 * mesma janela, mesmos orçamentos, duas linhas.
 *
 * TIPOS: company_id TINYINT UNSIGNED com FK para biomie_companies (que é
 * tinyint(3) unsigned). NÃO há FK para biomie_sales_quote_followup_schedule
 * porque o company_id DELA é bigint(20) assinado — a divergência conhecida
 * (D58). A ligação é pela chave de negócio (company_id, omie_order_id), que
 * também sobrevive ao orçamento sair e voltar da agenda.
 *
 * FUSO: timestamps em UTC (banco). Reversível: down() dropa as três tabelas.
 */
return new class extends AbstractMigration {

    private const T_RUNS     = 'intelligence_job_runs';
    private const T_SCORES   = 'intelligence_quote_scores';
    private const T_FEEDBACK = 'intelligence_quote_feedback';

    public function getVersion(): string
    {
        return '20260803210000';
    }

    public function getDescription(): string
    {
        return 'Camada de inteligência: trilha de jobs, scores de orçamento com features e feedback do vendedor.';
    }

    public function up(PDO $pdo): void
    {
        // ── Trilha de execução ────────────────────────────────────────────────
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_name VARCHAR(64) NOT NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'ex: quote_score, dataset_build, model_train',
                company_id TINYINT UNSIGNED NULL
                    COMMENT 'NULL = job rodou para todas as empresas',
                run_mode ENUM('baseline','shadow','active') NOT NULL DEFAULT 'baseline',
                status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',

                model_version VARCHAR(64) NULL COLLATE utf8mb4_unicode_ci,
                rows_in INT UNSIGNED NOT NULL DEFAULT 0,
                rows_out INT UNSIGNED NOT NULL DEFAULT 0,

                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
                finished_at DATETIME NULL COMMENT 'UTC',
                duration_ms INT UNSIGNED NULL,

                metrics_json TEXT NULL COMMENT 'métricas da execução (JSON)',
                error_message VARCHAR(2000) NULL COLLATE utf8mb4_unicode_ci,

                hostname VARCHAR(64) NULL COLLATE utf8mb4_unicode_ci,
                correlation_id VARCHAR(64) NULL COLLATE utf8mb4_unicode_ci,

                PRIMARY KEY (id),
                KEY idx_ijr_job_status (job_name, status, started_at),
                KEY idx_ijr_company (company_id, started_at),
                CONSTRAINT fk_ijr_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T_RUNS));

        // ── Scores ────────────────────────────────────────────────────────────
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                omie_order_id BIGINT NOT NULL
                    COMMENT 'chave de negócio do orçamento (= schedule.omie_order_id)',
                job_run_id BIGINT UNSIGNED NULL,

                scorer ENUM('deterministic','model') NOT NULL DEFAULT 'deterministic',
                run_mode ENUM('baseline','shadow','active') NOT NULL DEFAULT 'baseline',
                model_version VARCHAR(64) NULL COLLATE utf8mb4_unicode_ci,

                score DECIMAL(5,2) NOT NULL COMMENT '0..100, maior = mais urgente',
                priority_band ENUM('alta','media','baixa') NOT NULL,
                probability DECIMAL(6,5) NULL
                    COMMENT 'probabilidade calibrada de fechar; só o modelo preenche',

                features_json TEXT NULL
                    COMMENT 'features exatas que geraram o score — auditoria',
                reasons_json TEXT NULL
                    COMMENT 'drivers em linguagem humana, para a tela explicar o porquê',

                is_current TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT '1 = score vigente para (empresa, orçamento, scorer, modo)',
                scored_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                KEY idx_iqs_current (company_id, is_current, priority_band, score),
                KEY idx_iqs_order (company_id, omie_order_id, scorer, scored_at),
                KEY idx_iqs_run (job_run_id),
                CONSTRAINT fk_iqs_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_iqs_run FOREIGN KEY (job_run_id)
                    REFERENCES intelligence_job_runs (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T_SCORES));

        // ── Feedback do vendedor ──────────────────────────────────────────────
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                omie_order_id BIGINT NOT NULL,
                user_id INT UNSIGNED NOT NULL COMMENT 'quem respondeu',
                score_id BIGINT UNSIGNED NULL
                    COMMENT 'score que estava na tela quando respondeu — evita comparar com score de outra época',

                verdict ENUM('vai_fechar','incerto','nao_vai_fechar','ja_fechou','perdido') NOT NULL,
                expected_close_on DATE NULL,
                lost_reason VARCHAR(64) NULL COLLATE utf8mb4_unicode_ci,
                comment VARCHAR(500) NULL COLLATE utf8mb4_unicode_ci,

                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                KEY idx_iqf_order (company_id, omie_order_id, created_at),
                KEY idx_iqf_user (user_id, created_at),
                KEY idx_iqf_verdict (company_id, verdict, created_at),
                CONSTRAINT fk_iqf_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_iqf_user FOREIGN KEY (user_id)
                    REFERENCES biomie_users (id) ON DELETE CASCADE,
                CONSTRAINT fk_iqf_score FOREIGN KEY (score_id)
                    REFERENCES intelligence_quote_scores (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T_FEEDBACK));
    }

    public function down(PDO $pdo): void
    {
        // Ordem inversa das FKs.
        $this->dropTables($pdo, [self::T_FEEDBACK, self::T_SCORES, self::T_RUNS]);
    }
};
