<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * AGENDA DE ORÇAMENTOS — Fase 2 (corretiva): CHECK constraints do log de decisão.
 *
 * A tabela biomie_quote_order_refresh_log (migration 20260722000000) declarou
 * `source` como NOT NULL SEM default para que uma origem ausente falhasse. Mas o
 * sql_mode deste servidor NÃO é estrito (NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION):
 * o MariaDB inseriria '' em silêncio em vez de recusar. A garantia, então, existia
 * só no código (QuoteOrderRefresher::SOURCES) — o banco ficava sem rede.
 *
 * Esta migration fecha isso no NÍVEL DO BANCO. check_constraint_checks está ligado
 * (global=1, session=1), então estes CHECKs são efetivamente aplicados: qualquer
 * INSERT/UPDATE que os viole é REJEITADO, venha de onde vier.
 *
 * ─── OS QUATRO INVARIANTES ──────────────────────────────────────────────────
 *  1. chk_qorl_source     — origem só pode ser webhook|manual|cli|scanner.
 *                           Mata o '' silencioso e qualquer origem inventada.
 *  2. chk_qorl_decision   — decisão só pode ser keep|resolve|vanished|retry.
 *  3. chk_qorl_execution_state — is_dry_run e applied são 0/1, e a combinação
 *                           (is_dry_run=1 AND applied=1) é IMPOSSÍVEL: uma
 *                           simulação nunca pode ter mutado nada. Espelha, no
 *                           banco, o que o refresher já garante em código.
 *  4. chk_qorl_resolution — coerência decisão↔resolução:
 *                           keep/retry  ⇒ resolution IS NULL (não desativou);
 *                           resolve/vanished ⇒ resolution NOT NULL e não-vazia
 *                           (desativou: precisa dizer por quê).
 *
 * A tabela está VAZIA em produção quando esta migration roda (nenhuma escrita
 * produtiva ocorreu ainda), então ADD CONSTRAINT não tem linha legada a validar.
 *
 * Não altera a migration 20260722000000 (já aplicada) — é uma migration NOVA,
 * puramente aditiva. Reversível: down() remove os quatro CHECKs na ordem inversa.
 */
return new class extends AbstractMigration {

    private const TABLE = 'biomie_quote_order_refresh_log';

    public function getVersion(): string
    {
        return '20260722010000';
    }

    public function getDescription(): string
    {
        return 'Agenda de Orçamentos Fase 2 (corretiva): CHECK constraints do log de decisão (source, decision, execution_state, resolution).';
    }

    public function up(PDO $pdo): void
    {
        $t = self::TABLE;
        $this->executeStatements($pdo, [
            // 1. Origem fechada — encerra o '' silencioso do sql_mode não-estrito.
            "ALTER TABLE {$t} ADD CONSTRAINT chk_qorl_source
                CHECK (source IN ('webhook','manual','cli','scanner'))",

            // 2. Decisão fechada ao vocabulário de QuoteOrderDecision.
            "ALTER TABLE {$t} ADD CONSTRAINT chk_qorl_decision
                CHECK (decision IN ('keep','resolve','vanished','retry'))",

            // 3. Modo × mutação: 0/1 e nunca simulação-que-mutou.
            "ALTER TABLE {$t} ADD CONSTRAINT chk_qorl_execution_state
                CHECK (is_dry_run IN (0,1) AND applied IN (0,1)
                       AND NOT (is_dry_run = 1 AND applied = 1))",

            // 4. Coerência decisão↔resolução.
            "ALTER TABLE {$t} ADD CONSTRAINT chk_qorl_resolution
                CHECK (
                    (decision IN ('keep','retry') AND resolution IS NULL)
                    OR (decision IN ('resolve','vanished')
                        AND resolution IS NOT NULL AND resolution <> '')
                )",
        ]);
    }

    public function down(PDO $pdo): void
    {
        $t = self::TABLE;
        // Ordem inversa da criação.
        $this->executeStatements($pdo, [
            "ALTER TABLE {$t} DROP CONSTRAINT chk_qorl_resolution",
            "ALTER TABLE {$t} DROP CONSTRAINT chk_qorl_execution_state",
            "ALTER TABLE {$t} DROP CONSTRAINT chk_qorl_decision",
            "ALTER TABLE {$t} DROP CONSTRAINT chk_qorl_source",
        ]);
    }
};
