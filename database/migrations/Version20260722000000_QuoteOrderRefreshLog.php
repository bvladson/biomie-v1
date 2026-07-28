<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * AGENDA DE ORÇAMENTOS — Fase 2: log de decisão por pedido + fonte do debounce.
 *
 * Uma tabela, duas exigências:
 *
 *  1. LOG DE DECISÃO POR PEDIDO — por que cada orçamento foi mantido, resolvido
 *     ou deixado de lado. Sem isso, "o orçamento sumiu da Agenda" é indepurável.
 *  2. DEBOUNCE POR PEDIDO — `MAX(checked_at)` por (empresa, pedido) diz se já
 *     consultamos esse registro há menos de 60s. A Omie restringe chamadas
 *     redundantes ao MESMO registro nessa janela; o debounce respeita isso sem
 *     depender de estado em memória (que morre entre execuções do worker).
 *
 * Guardar a decisão SEMPRE — inclusive `retry` — é o que permite provar depois
 * que uma falha de rede não desativou nada.
 *
 * ─── MODO × MUTAÇÃO SÃO DUAS COISAS ─────────────────────────────────────────
 * `is_dry_run` diz COMO rodou; `applied` diz SE mudou algo. Uma coluna só não
 * distinguiria "simulei", "rodei de verdade e nada precisava mudar" e "rodei de
 * verdade e mudei" — e essa diferença é justamente o que se quer auditar.
 *   is_dry_run=1, applied=0 → simulação
 *   is_dry_run=0, applied=0 → execução real sem mutação (keep sem pendência, retry)
 *   is_dry_run=0, applied=1 → mutação efetivada
 * O default is_dry_run=1 falha para o lado seguro: um chamador novo que esqueça
 * de informar o modo é registrado como simulação, nunca como execução real.
 *
 * `source` NÃO tem default de propósito: um fluxo novo que esqueça de informar a
 * origem falha em vez de contaminar a auditoria fingindo ser webhook.
 * Valores esperados: webhook | manual | cli | scanner.
 *
 * `company_id` é BIGINT para casar com as 5 tabelas irmãs da Agenda (é com elas
 * que este log faz join). NOTA: `biomie_companies.id` é TINYINT(3) UNSIGNED —
 * divergência PRÉ-EXISTENTE em todo o módulo, rastreada pelo guard D55; não é
 * introduzida aqui nem corrigida aqui.
 *
 * Sem IF NOT EXISTS: numa migration versionada, a tabela já existir significa
 * estado inesperado (talvez parcial ou com outra estrutura) e DEVE interromper
 * o deploy, não ser silenciada por um aviso.
 *
 * FUSO: timestamps em UTC (NOW() do banco).
 * Reversível: down() remove a tabela. Puramente ADITIVA.
 */
return new class extends AbstractMigration {

    private const TABLE = 'biomie_quote_order_refresh_log';

    public function getVersion(): string
    {
        return '20260722000000';
    }

    public function getDescription(): string
    {
        return 'Agenda de Orçamentos Fase 2: log de decisão por pedido (keep/resolve/vanished/retry) + base do debounce de 60s.';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id BIGINT NOT NULL,
                omie_order_id BIGINT NOT NULL,

                decision VARCHAR(16) NOT NULL
                    COMMENT 'keep | resolve | vanished | retry — retry NUNCA desativa',
                resolution VARCHAR(16) NULL
                    COMMENT 'converted|invoiced|cancelled|returned|closed|vanished (só quando desativa)',
                etapa_seen VARCHAR(8) NULL COMMENT 'etapa devolvida pelo Omie, quando legível',
                reason VARCHAR(255) NOT NULL COMMENT 'motivo legível da decisão',

                source VARCHAR(16) NOT NULL
                    COMMENT 'webhook | manual | cli | scanner — SEM default: origem ausente deve falhar',

                http_status SMALLINT UNSIGNED NULL,

                is_dry_run TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT '1 = simulação. Default seguro: chamador que esquecer o modo vira simulação',
                applied TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT '1 = houve mutação de fato no acompanhamento',

                checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC — base do debounce',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                KEY idx_qorl_debounce (company_id, omie_order_id, checked_at),
                KEY idx_qorl_recent (company_id, checked_at),
                KEY idx_qorl_decision (company_id, decision, checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::TABLE));
    }

    public function down(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => self::TABLE]);
        if ((int) $stmt->fetchColumn() > 0) {
            $pdo->exec('DROP TABLE ' . self::TABLE);
        }
    }
};
