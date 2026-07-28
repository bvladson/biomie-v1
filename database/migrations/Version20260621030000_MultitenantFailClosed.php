<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260621030000';
    }

    public function getDescription(): string
    {
        return 'Fecha defaults de tenant, corrige uniques globais e completa governanca multiempresa.';
    }

    public function up(\PDO $pdo): void
    {
        $this->fixTenantUniqueKeys($pdo);
        $this->addChannelInputs($pdo);
        $this->seedErgoChannelRule($pdo);
        $this->grantActiveCompaniesToAdministrators($pdo);
        $this->repairOperationalState($pdo);
        $this->removeCompanyDefaults($pdo);
    }

    public function down(\PDO $pdo): void
    {
        // Hardening fail-closed e reparos operacionais nao devem ser revertidos.
    }

    private function fixTenantUniqueKeys(\PDO $pdo): void
    {
        $this->replaceUnique(
            $pdo,
            'biomie_sales_targets_monthly',
            'uq_vendor_month',
            'uq_company_vendor_month',
            'company_id, vendor_id, `year_month`'
        );
        $this->replaceUnique(
            $pdo,
            'biomie_eventos_canonicos',
            'uq_canonical_event_idempotency',
            'uq_company_event_idempotency',
            'company_id, idempotency_hash'
        );
        $this->replaceUnique(
            $pdo,
            'biomie_omie_bronze',
            'uq_bronze_idempotency',
            'uq_company_bronze_idempotency',
            'company_id, idempotency_key'
        );
        $this->replaceUnique(
            $pdo,
            'biomie_jobs',
            'uq_biomie_jobs_idempotency',
            'uq_company_jobs_idempotency',
            'company_id, idempotency_key'
        );
    }

    private function addChannelInputs(\PDO $pdo): void
    {
        if ($this->tableExists($pdo, 'biomie_pedidos_canonicos')
            && !$this->columnExists($pdo, 'biomie_pedidos_canonicos', 'observacoes')
        ) {
            $pdo->exec(
                'ALTER TABLE biomie_pedidos_canonicos
                 ADD COLUMN observacoes TEXT NULL AFTER status_pedido_omie'
            );
        }
        if ($this->tableExists($pdo, 'biomie_notas_fiscais_canonicas')
            && !$this->columnExists($pdo, 'biomie_notas_fiscais_canonicas', 'observacoes')
        ) {
            $pdo->exec(
                'ALTER TABLE biomie_notas_fiscais_canonicas
                 ADD COLUMN observacoes TEXT NULL AFTER status_fiscal'
            );
        }
    }

    private function seedErgoChannelRule(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_channel_classification_rules')) {
            return;
        }
        $pdo->exec(
            "INSERT IGNORE INTO biomie_channel_classification_rules
                (company_id, priority, rule_type, match_value, target_channel, confidence)
             VALUES (5, 999, 'default', '', 'licitacao', 'alta')"
        );
    }

    private function grantActiveCompaniesToAdministrators(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_user_companies')) {
            return;
        }
        $pdo->exec(<<<'SQL'
            INSERT IGNORE INTO biomie_user_companies
                (user_id, company_id, can_varejo, can_licitacao,
                 default_canal, can_admin, is_default)
            SELECT u.id,
                   c.id,
                   IF(c.canal_padrao = 'varejo', 1, 0),
                   IF(c.canal_padrao = 'licitacao', 1, 0),
                   c.canal_padrao,
                   1,
                   0
              FROM biomie_users u
              JOIN biomie_companies c ON c.ativo = 1
             WHERE u.is_active = 1
               AND u.role = 'admin'
        SQL);
    }

    private function repairOperationalState(\PDO $pdo): void
    {
        if ($this->tableExists($pdo, 'biomie_sync_runs')) {
            $pdo->exec(
                "UPDATE biomie_sync_runs
                    SET status = 'failed_terminal',
                        finished_at = COALESCE(finished_at, NOW()),
                        error_message_sanitized = COALESCE(
                            NULLIF(error_message_sanitized, ''),
                            'Run expirado automaticamente pelo hardening multiempresa.'
                        )
                  WHERE status = 'running'
                    AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
        }

        if ($this->tableExists($pdo, 'biomie_company_integrations')
            && $this->tableExists($pdo, 'biomie_sync_runs')
        ) {
            $pdo->exec(<<<'SQL'
                UPDATE biomie_company_integrations i
                JOIN (
                    SELECT company_id, MAX(finished_at) AS last_success_at
                      FROM biomie_sync_runs
                     WHERE status IN ('completed', 'completed_with_warnings')
                     GROUP BY company_id
                ) r ON r.company_id = i.company_id
                   SET i.last_success_at = GREATEST(
                       COALESCE(i.last_success_at, '1970-01-01 00:00:00'),
                       r.last_success_at
                   )
                 WHERE i.provider = 'omie'
            SQL);
        }
    }

    private function removeCompanyDefaults(\PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT c.TABLE_NAME, c.COLUMN_TYPE
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t
                 ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
                AND t.TABLE_NAME = c.TABLE_NAME
                AND t.TABLE_TYPE = 'BASE TABLE'
              WHERE c.TABLE_SCHEMA = DATABASE()
                AND c.COLUMN_NAME = 'company_id'"
        );
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $column) {
            $table = (string) $column['TABLE_NAME'];
            $type = (string) $column['COLUMN_TYPE'];
            $pdo->exec("UPDATE `{$table}` SET company_id = 1 WHERE company_id IS NULL");
            $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN company_id {$type} NOT NULL");
        }
    }

    private function replaceUnique(
        \PDO $pdo,
        string $table,
        string $oldIndex,
        string $newIndex,
        string $columns
    ): void {
        if (!$this->tableExists($pdo, $table) || $this->indexExists($pdo, $table, $newIndex)) {
            return;
        }
        if ($this->indexExists($pdo, $table, $oldIndex)) {
            $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$oldIndex}`");
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$newIndex}` ({$columns})");
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column_name'
        );
        $stmt->execute(['table' => $table, 'column_name' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index_name'
        );
        $stmt->execute(['table' => $table, 'index_name' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
