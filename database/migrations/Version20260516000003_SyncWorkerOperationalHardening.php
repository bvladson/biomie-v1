<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260516000003';
    }

    public function getDescription(): string
    {
        return 'Fase 7: heartbeat do worker, dead-letter, locks logicos e expansoes seguras de sync runs/cursors/webhook/jobs.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_worker_heartbeats (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                worker_id VARCHAR(120) NOT NULL,
                hostname VARCHAR(120) NULL,
                pid INT UNSIGNED NULL,
                queues VARCHAR(255) NOT NULL DEFAULT '',
                started_at DATETIME NOT NULL,
                last_heartbeat_at DATETIME NOT NULL,
                status ENUM('running','stopping','stopped','dead') NOT NULL DEFAULT 'running',
                processed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
                failed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
                memory_usage_mb DECIMAL(10,2) NULL,
                version VARCHAR(80) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_worker_heartbeat_worker (worker_id),
                KEY idx_worker_heartbeat_last (last_heartbeat_at),
                KEY idx_worker_heartbeat_status (status, last_heartbeat_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_dead_letter_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_id VARCHAR(80) NOT NULL,
                job_type VARCHAR(120) NOT NULL,
                queue VARCHAR(64) NOT NULL DEFAULT 'default',
                payload_sanitized LONGTEXT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_error_code VARCHAR(80) NULL,
                last_error_message_sanitized TEXT NULL,
                run_id VARCHAR(80) NULL,
                request_id VARCHAR(80) NULL,
                can_retry TINYINT(1) NOT NULL DEFAULT 1,
                retry_after DATETIME NULL,
                resolved_at DATETIME NULL,
                resolved_by VARCHAR(120) NULL,
                resolution_notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_dead_letter_job (job_id),
                KEY idx_dead_letter_type_failed (job_type, failed_at),
                KEY idx_dead_letter_retry (can_retry, retry_after),
                KEY idx_dead_letter_resolved (resolved_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_locks (
                lock_key VARCHAR(160) NOT NULL,
                owner VARCHAR(160) NOT NULL,
                acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (lock_key),
                KEY idx_biomie_locks_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        foreach ([
            ['biomie_sync_runs', 'run_id', 'VARCHAR(80) NULL'],
            ['biomie_sync_runs', 'duration_ms', 'INT UNSIGNED NULL'],
            ['biomie_sync_runs', 'records_seen', 'INT UNSIGNED NULL'],
            ['biomie_sync_runs', 'records_inserted', 'INT UNSIGNED NULL'],
            ['biomie_sync_runs', 'records_updated', 'INT UNSIGNED NULL'],
            ['biomie_sync_runs', 'records_skipped', 'INT UNSIGNED NULL'],
            ['biomie_sync_runs', 'records_failed', 'INT UNSIGNED NULL'],
            ['biomie_sync_runs', 'pages_processed', 'INT UNSIGNED NULL'],
            ['biomie_sync_runs', 'cursor_before', 'LONGTEXT NULL'],
            ['biomie_sync_runs', 'cursor_after', 'LONGTEXT NULL'],
            ['biomie_sync_runs', 'window_start', 'DATETIME NULL'],
            ['biomie_sync_runs', 'window_end', 'DATETIME NULL'],
            ['biomie_sync_runs', 'error_code', 'VARCHAR(80) NULL'],
            ['biomie_sync_runs', 'error_message_sanitized', 'TEXT NULL'],
            ['biomie_sync_runs', 'request_id', 'VARCHAR(80) NULL'],
            ['biomie_sync_runs', 'triggered_by', "VARCHAR(40) NULL"],
            ['biomie_sync_cursors', 'entity_type', "VARCHAR(64) NULL"],
            ['biomie_sync_cursors', 'cursor_key', "VARCHAR(120) NULL"],
            ['biomie_sync_cursors', 'cursor_value', 'LONGTEXT NULL'],
            ['biomie_sync_cursors', 'last_attempt_at', 'DATETIME NULL'],
            ['biomie_sync_cursors', 'last_error_at', 'DATETIME NULL'],
            ['biomie_sync_cursors', 'last_error_message_sanitized', 'TEXT NULL'],
            ['biomie_sync_cursors', 'last_run_id', 'VARCHAR(80) NULL'],
            ['biomie_sync_cursors', 'records_total', 'INT UNSIGNED NULL'],
            ['biomie_sync_cursors', 'is_stale', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['biomie_webhook_inbox', 'provider', "VARCHAR(64) NULL"],
            ['biomie_webhook_inbox', 'event_id', "VARCHAR(160) NULL"],
            ['biomie_webhook_inbox', 'event_type', "VARCHAR(120) NULL"],
            ['biomie_webhook_inbox', 'payload_sanitized', 'LONGTEXT NULL'],
            ['biomie_webhook_inbox', 'job_id', 'VARCHAR(80) NULL'],
            ['biomie_webhook_inbox', 'request_id', 'VARCHAR(80) NULL'],
            ['biomie_webhook_inbox', 'failed_at', 'DATETIME NULL'],
            ['biomie_jobs', 'next_attempt_at', 'DATETIME NULL'],
            ['biomie_jobs', 'backoff_strategy', "VARCHAR(80) NULL"],
            ['biomie_jobs', 'locked_at', 'DATETIME NULL'],
            ['biomie_jobs', 'lock_expires_at', 'DATETIME NULL'],
        ] as [$table, $column, $definition]) {
            $this->maybeAddColumn($pdo, $table, $column, $definition);
        }

        $this->maybeAddIndex($pdo, 'biomie_sync_runs', 'idx_sync_runs_run_id', ['run_id'], 'ALTER TABLE biomie_sync_runs ADD INDEX idx_sync_runs_run_id (run_id)');
        $this->maybeAddIndex($pdo, 'biomie_sync_runs', 'idx_sync_runs_domain_status_started', ['domain_name', 'status', 'started_at'], 'ALTER TABLE biomie_sync_runs ADD INDEX idx_sync_runs_domain_status_started (domain_name, status, started_at)');
        $this->maybeAddIndex($pdo, 'biomie_sync_cursors', 'idx_sync_cursors_domain_entity', ['domain_name', 'entity_type'], 'ALTER TABLE biomie_sync_cursors ADD INDEX idx_sync_cursors_domain_entity (domain_name, entity_type)');
        $this->maybeAddIndex($pdo, 'biomie_jobs', 'idx_biomie_jobs_available', ['queue_name', 'status', 'available_at', 'priority'], 'ALTER TABLE biomie_jobs ADD INDEX idx_biomie_jobs_available (queue_name, status, available_at, priority)');
        $this->maybeAddIndex($pdo, 'biomie_jobs', 'idx_biomie_jobs_lock', ['locked_at', 'lock_expires_at'], 'ALTER TABLE biomie_jobs ADD INDEX idx_biomie_jobs_lock (locked_at, lock_expires_at)');
        $this->maybeAddIndex($pdo, 'biomie_webhook_inbox', 'idx_webhook_provider_event', ['provider', 'event_id'], 'ALTER TABLE biomie_webhook_inbox ADD INDEX idx_webhook_provider_event (provider, event_id)');
        $this->maybeAddIndex($pdo, 'biomie_webhook_inbox', 'idx_webhook_payload_hash', ['payload_hash'], 'ALTER TABLE biomie_webhook_inbox ADD INDEX idx_webhook_payload_hash (payload_hash)');
        $this->maybeAddIndex($pdo, 'biomie_webhook_inbox', 'idx_webhook_status_received', ['status', 'received_at'], 'ALTER TABLE biomie_webhook_inbox ADD INDEX idx_webhook_status_received (status, received_at)');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_locks', 'biomie_dead_letter_jobs', 'biomie_worker_heartbeats']);
    }

    private function maybeAddColumn(\PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!$this->tableExists($pdo, $table) || $this->columnExists($pdo, $table, $column)) {
            return;
        }

        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }

    /** @param list<string> $requiredColumns */
    private function maybeAddIndex(\PDO $pdo, string $table, string $index, array $requiredColumns, string $ddl): void
    {
        if (!$this->tableExists($pdo, $table) || $this->indexExists($pdo, $table, $index)) {
            return;
        }
        foreach ($requiredColumns as $column) {
            if (!$this->columnExists($pdo, $table, $column)) {
                return;
            }
        }

        $pdo->exec($ddl);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
