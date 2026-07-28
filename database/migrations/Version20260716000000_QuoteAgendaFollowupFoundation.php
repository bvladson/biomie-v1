<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * AGENDA DE ORÇAMENTOS — Fase 3: fundação operacional.
 *
 * Três tabelas, nenhuma UI, todas as flags desligadas:
 *
 *  1. biomie_sales_quote_followup_schedule — estado operacional do acompanhamento.
 *  2. biomie_quote_followup_policy         — política de cobrança por empresa (1/3/7/14).
 *  3. biomie_quote_live_snapshot_sync      — estado/frescor do snapshot vivo por empresa.
 *     Fonte ESPECÍFICA do banner "dados do Omie desatualizados" — NÃO reutilizar o
 *     pending_orders_sync genérico (parado desde 2026-04 e alimentado por outro caminho).
 *
 * CONVENÇÃO DE NOMES (fechada na Fase 3):
 *   · _at → instante com data E hora
 *   · _on → dia civil (DATE). A previsão do Omie NÃO tem hora ⇒ quote_forecast_on.
 *
 * POLÍTICA DE FUSO (fechada na Fase 2):
 *   · timestamps técnicos/auditoria (_at) → UTC
 *   · quote_created_at → horário comercial ORIGINAL de Fortaleza (vem de data_inclusao_omie)
 *   · next_followup_on → DATE: a cobrança é diária, não por minuto (elimina a classe
 *     inteira de bugs de virada do dia)
 *   · NUNCA comparar NOW() UTC com DATETIME local sem conversão explícita.
 *
 * Reversível: down() remove as três tabelas.
 */
return new class extends AbstractMigration {

    private const T_SCHEDULE = 'biomie_sales_quote_followup_schedule';
    private const T_POLICY   = 'biomie_quote_followup_policy';
    private const T_SNAPSHOT = 'biomie_quote_live_snapshot_sync';

    public function getVersion(): string
    {
        return '20260716000000';
    }

    public function getDescription(): string
    {
        return 'Agenda de Orçamentos Fase 3: biomie_sales_quote_followup_schedule + política 1/3/7/14 por empresa + estado quote_live_snapshot_sync.';
    }

    public function up(PDO $pdo): void
    {
        // ── 1. Acompanhamento operacional ────────────────────────────────────────
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id BIGINT NOT NULL,
                omie_order_id BIGINT NOT NULL COMMENT 'codigo_pedido do Omie — ja passa de 32 bits',
                seller_id VARCHAR(32) NULL COMMENT 'NULL = vendedor nao identificado (urgencia critica na Agenda)',

                quote_created_at DATETIME NOT NULL
                    COMMENT 'Horario comercial ORIGINAL de Fortaleza (nao UTC). Obrigatorio: o resolver sempre entrega uma data.',
                quote_created_source VARCHAR(32) NOT NULL DEFAULT 'omie_dinc'
                    COMMENT 'Ordem de confianca: omie_dinc > omie_stage_history > first_seen > legacy_fallback. first_seen ANTES de legacy_fallback porque data_emissao legado pode conter previsao e gerar idade critica falsa.',
                quote_forecast_on DATE NULL
                    COMMENT 'Previsao de faturamento (dia civil — o Omie nao envia hora). So urgencia, nunca data de cobranca.',

                next_followup_on DATE NULL COMMENT 'Cobranca e diaria: DATE, nunca DATETIME.',
                followup_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
                charge_count INT UNSIGNED NOT NULL DEFAULT 0,

                last_manager_charge_at DATETIME NULL COMMENT 'UTC',
                last_manager_user_id BIGINT NULL,

                last_seen_at DATETIME NULL COMMENT 'UTC — ultima presenca no snapshot vivo',
                missing_since DATETIME NULL COMMENT 'UTC — ausencia NAO resolve nada sozinha',
                reconciliation_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,

                resolved_at DATETIME NULL COMMENT 'UTC',
                resolution ENUM('converted','invoiced','cancelled','returned','closed','vanished') NULL
                    COMMENT 'vanished so com inexistencia CONFIRMADA na origem — nunca por ausencia',

                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                UNIQUE KEY uq_quote_followup_company_order (company_id, omie_order_id),
                KEY idx_qf_company_due (company_id, next_followup_on, resolved_at),
                KEY idx_qf_company_seller (company_id, seller_id),
                KEY idx_qf_company_unresolved (company_id, resolved_at),
                KEY idx_qf_missing (company_id, missing_since),
                KEY idx_qf_source (company_id, quote_created_source)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T_SCHEDULE));

        // ── 2. Política de cobrança por empresa ──────────────────────────────────
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id BIGINT NOT NULL,
                cadence_days_json VARCHAR(120) NOT NULL DEFAULT '[1,3,7,14]'
                    CHECK (json_valid(cadence_days_json)),
                cadence_model ENUM('absolute_from_creation','relative_to_last_charge')
                    NOT NULL DEFAULT 'absolute_from_creation'
                    COMMENT 'absoluto desde a criacao: o atraso do gestor NAO reinicia o relogio',
                weekend_policy ENUM('roll_forward','none') NOT NULL DEFAULT 'roll_forward'
                    COMMENT 'sabado/domingo avancam para segunda',
                holiday_policy ENUM('none','company_calendar') NOT NULL DEFAULT 'none'
                    COMMENT 'feriados: versao futura (nao ha calendario hoje)',
                critical_after_step TINYINT UNSIGNED NOT NULL DEFAULT 4,
                stale_after_minutes INT UNSIGNED NOT NULL DEFAULT 45
                    COMMENT 'SLA do banner de frescor: 45min p/ um scanner que roda a cada 15min',
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_quote_policy_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T_POLICY));

        // ── 3. Estado/frescor do snapshot vivo (fonte do banner) ─────────────────
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id BIGINT NOT NULL,
                started_at DATETIME NULL COMMENT 'UTC',
                completed_at DATETIME NULL COMMENT 'UTC — conclusao da ultima tentativa (sucesso ou nao)',
                last_successful_at DATETIME NULL
                    COMMENT 'UTC — SO avanca em sucesso. NUNCA zerar numa tentativa que falhou: e a base do banner de frescor.',
                status ENUM('running','success','failed','skipped') NOT NULL DEFAULT 'running',
                live_total INT UNSIGNED NULL COMMENT 'total etapa 10 no Omie',
                eligible_total INT UNSIGNED NULL COMMENT 'apos HardDataExclusionPolicy (ex.: Licitacao fora)',
                snapshot_hash CHAR(64) NULL,
                duration_ms INT UNSIGNED NULL,
                last_error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_quote_snapshot_company (company_id),
                KEY idx_qs_last_success (company_id, last_successful_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T_SNAPSHOT));

        // Política default DESLIGADA para todas as empresas ativas.
        if ($this->tableExists($pdo, 'biomie_companies')) {
            $pdo->exec(sprintf(
                "INSERT IGNORE INTO %s
                    (company_id, cadence_days_json, cadence_model, weekend_policy, holiday_policy,
                     critical_after_step, stale_after_minutes, enabled)
                 SELECT id, '[1,3,7,14]', 'absolute_from_creation', 'roll_forward', 'none', 4, 45, 0
                   FROM biomie_companies WHERE ativo = 1",
                self::T_POLICY
            ));
        }
    }

    public function down(PDO $pdo): void
    {
        foreach ([self::T_SNAPSHOT, self::T_POLICY, self::T_SCHEDULE] as $t) {
            if ($this->tableExists($pdo, $t)) {
                $pdo->exec('DROP TABLE ' . $t);
            }
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
