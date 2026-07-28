<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\SecretMask;
use BIomie\Support\TenantContext;
use PDO;

final class SyncPipelineRepository
{
    private const TABLE_WEBHOOK_INBOX = 'biomie_webhook_inbox';
    private const TABLE_SYNC_CURSORS = 'biomie_sync_cursors';
    private const TABLE_SYNC_RUNS = 'biomie_sync_runs';

    private bool $schemaReady = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly ?int $tenantCompanyId = null,
    ) {
    }

    public static function boot(Config $config, Logger $logger): ?self
    {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (!Database::isConfigured($config, $connection)) {
            return null;
        }

        try {
            $repository = new self(Database::connect($config, $connection), $logger);
            $repository->ensureSchema();
            return $repository;
        } catch (\Throwable $exception) {
            $logger->warning('Pipeline de sync indisponivel; seguindo sem inbox duravel.', [
                'message' => $exception->getMessage(),
                'connection' => $connection,
            ]);
            return null;
        }
    }

    /**
     * Abre o repositório para painéis de leitura sem executar DDL, ALTER TABLE
     * ou consultas de evolução de schema. Tabelas ausentes devem ser tratadas
     * pelo consumidor como fonte indisponível.
     */
    public static function bootReadOnly(Config $config, Logger $logger): ?self
    {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (!Database::isConfigured($config, $connection)) {
            return null;
        }

        try {
            $repository = new self(Database::connect($config, $connection), $logger);
            $repository->schemaReady = true;
            return $repository;
        } catch (\Throwable $exception) {
            $logger->warning('Pipeline de sync indisponivel para leitura operacional.', [
                'message' => $exception->getMessage(),
                'connection' => $connection,
            ]);
            return null;
        }
    }

    public function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_webhook_inbox (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                domain_name VARCHAR(32) NOT NULL,
                endpoint_group VARCHAR(64) NOT NULL,
                topic_name VARCHAR(64) NULL,
                source_app VARCHAR(64) NULL,
                correlation_id VARCHAR(64) NULL,
                candidate_key VARCHAR(191) NULL,
                dedupe_key CHAR(64) NOT NULL,
                payload_hash CHAR(64) NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'webhook',
                status ENUM(
                    'recebido',
                    'persistido',
                    'enfileirado',
                    'processado',
                    'falhou_temporariamente',
                    'falhou_definitivamente',
                    'ignorado_por_deduplicacao'
                ) NOT NULL DEFAULT 'persistido',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                error_summary VARCHAR(255) NULL,
                payload_json LONGTEXT NOT NULL,
                headers_json LONGTEXT NULL,
                metadata_json LONGTEXT NULL,
                result_json LONGTEXT NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processing_started_at DATETIME NULL,
                processed_at DATETIME NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_webhook_status_available (status, available_at, id),
                KEY idx_webhook_domain_received (domain_name, received_at),
                KEY idx_webhook_dedupe (dedupe_key, received_at),
                KEY idx_webhook_correlation (correlation_id),
                KEY idx_webhook_candidate (candidate_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_sync_cursors (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                domain_name VARCHAR(32) NOT NULL,
                endpoint_group VARCHAR(64) NOT NULL,
                last_event_at DATETIME NULL,
                last_source_id VARCHAR(64) NULL,
                repair_from DATETIME NULL,
                repair_to DATETIME NULL,
                next_delta_at DATETIME NULL,
                safety_window_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
                last_success_at DATETIME NULL,
                last_run_started_at DATETIME NULL,
                last_run_finished_at DATETIME NULL,
                rule_version VARCHAR(32) NOT NULL DEFAULT 'phase4-v1',
                status ENUM(
                    'idle',
                    'running',
                    'completed',
                    'completed_with_warnings',
                    'failed_retryable',
                    'failed_terminal'
                ) NOT NULL DEFAULT 'idle',
                last_error TEXT NULL,
                cursor_meta_json LONGTEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sync_cursor (domain_name, endpoint_group),
                KEY idx_sync_cursor_next (next_delta_at, status),
                KEY idx_sync_cursor_success (last_success_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_sync_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_type VARCHAR(32) NOT NULL,
                domain_name VARCHAR(32) NOT NULL,
                endpoint_group VARCHAR(64) NOT NULL DEFAULT 'all',
                trigger_source VARCHAR(32) NOT NULL DEFAULT 'cron',
                correlation_id VARCHAR(64) NULL,
                period_start DATETIME NULL,
                period_end DATETIME NULL,
                status ENUM(
                    'running',
                    'completed',
                    'completed_with_warnings',
                    'failed_retryable',
                    'failed_terminal'
                ) NOT NULL DEFAULT 'running',
                items_discovered INT UNSIGNED NOT NULL DEFAULT 0,
                items_processed INT UNSIGNED NOT NULL DEFAULT 0,
                items_failed INT UNSIGNED NOT NULL DEFAULT 0,
                context_json LONGTEXT NULL,
                result_json LONGTEXT NULL,
                error_summary TEXT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sync_runs_domain_started (domain_name, started_at),
                KEY idx_sync_runs_status (status, started_at),
                KEY idx_sync_runs_type (run_type, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->ensurePhase7Columns();
        $this->schemaReady = true;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $headers
     * @param array<string,mixed> $metadata
     * @return array{
     *   inbox_id:int,
     *   status:string,
     *   duplicate:bool,
     *   dedupe_key:string,
     *   correlation_id:string
     * }
     */
    public function ingestWebhook(
        string $domain,
        string $endpointGroup,
        array $payload,
        array $headers = [],
        ?string $candidateKey = null,
        array $metadata = [],
        string $source = 'webhook',
        int $dedupeWindowSeconds = 300,
    ): array {
        $this->ensureSchema();
        $companyId = $this->companyId();
        $metadata['company_id'] = $companyId;

        $fingerprint = OperationalPayloadFingerprint::webhookRecord(
            $domain,
            $endpointGroup,
            $payload,
            $headers,
            $candidateKey,
            $metadata
        );

        $threshold = date('Y-m-d H:i:s', time() - max($dedupeWindowSeconds, 0));
        $duplicate = $this->findRecentDuplicate((string) $fingerprint['dedupe_key'], $threshold);
        $status = $duplicate !== null ? 'ignorado_por_deduplicacao' : 'persistido';
        $correlationId = trim((string) ($metadata['correlation_id'] ?? RequestContext::correlationId()));

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_webhook_inbox (
                company_id, domain_name, endpoint_group, topic_name, source_app,
                correlation_id, candidate_key, dedupe_key, payload_hash,
                source, status, attempts, error_summary, payload_json,
                headers_json, metadata_json, result_json, received_at,
                available_at, processing_started_at, processed_at
            ) VALUES (
                :company_id, :domain_name, :endpoint_group, :topic_name, :source_app,
                :correlation_id, :candidate_key, :dedupe_key, :payload_hash,
                :source, :status, 0, NULL, :payload_json,
                :headers_json, :metadata_json, NULL, NOW(),
                NOW(), NULL, NULL
            )
        SQL);

        $stmt->execute([
            'company_id' => $companyId,
            'domain_name' => $domain,
            'endpoint_group' => $endpointGroup,
            'topic_name' => $metadata['topic_name'] ?? null,
            'source_app' => $metadata['source_app'] ?? null,
            'correlation_id' => $correlationId !== '' ? $correlationId : null,
            'candidate_key' => $candidateKey !== null && $candidateKey !== '' ? $candidateKey : null,
            'dedupe_key' => $fingerprint['dedupe_key'],
            'payload_hash' => $fingerprint['payload_hash'],
            'source' => $source,
            'status' => $status,
            'payload_json' => $fingerprint['payload_json'],
            'headers_json' => $fingerprint['headers_json'],
            'metadata_json' => $fingerprint['metadata_json'],
        ]);

        return [
            'inbox_id' => (int) $this->pdo->lastInsertId(),
            'status' => $status,
            'duplicate' => $duplicate !== null,
            'dedupe_key' => $fingerprint['dedupe_key'],
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * @param list<string> $domains
     * @return array<string,mixed>|null
     */
    public function claimNextWebhook(array $domains = []): ?array
    {
        $this->ensureSchema();

        $this->pdo->beginTransaction();
        try {
            $params = ['company_id' => $this->companyId()];
            $sql = <<<'SQL'
                SELECT *
                FROM biomie_webhook_inbox
                WHERE company_id = :company_id
                  AND status IN ('persistido', 'falhou_temporariamente')
                  AND available_at <= NOW()
            SQL;

            if ($domains !== []) {
                $placeholders = [];
                foreach (array_values($domains) as $index => $domain) {
                    $key = 'domain_' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $domain;
                }
                $sql .= ' AND domain_name IN (' . implode(', ', $placeholders) . ')';
            }

            $sql .= ' ORDER BY received_at ASC, id ASC LIMIT 1 FOR UPDATE';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(<<<'SQL'
                UPDATE biomie_webhook_inbox
                SET status = 'enfileirado',
                    attempts = attempts + 1,
                    error_summary = NULL,
                    processing_started_at = NOW(),
                    updated_at = NOW()
                WHERE company_id = :company_id AND id = :id
            SQL);
            $update->execute(['company_id' => $this->companyId(), 'id' => $row['id']]);
            $this->pdo->commit();

            $row['status'] = 'enfileirado';
            $row['attempts'] = (int) ($row['attempts'] ?? 0) + 1;
            $row['processing_started_at'] = date('Y-m-d H:i:s');

            return $row;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    public function markWebhookProcessed(int $id, array $result = []): void
    {
        $this->updateWebhookState($id, 'processado', null, 0, $result);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function markWebhookTemporaryFailure(int $id, string $message, int $delaySeconds = 300, array $result = []): void
    {
        $this->updateWebhookState($id, 'falhou_temporariamente', $message, $delaySeconds, $result);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function markWebhookPermanentFailure(int $id, string $message, array $result = []): void
    {
        $this->updateWebhookState($id, 'falhou_definitivamente', $message, 0, $result);
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function upsertCursor(string $domain, string $endpointGroup, array $values = []): array
    {
        $this->ensureSchema();

        if (array_key_exists('last_error', $values) && $values['last_error'] !== null) {
            $values['last_error'] = SecretMask::sanitizeErrorMessage((string) $values['last_error'], 500);
        }

        $current = $this->getCursor($domain, $endpointGroup);
        $record = SyncCursorState::merge($current, $domain, $endpointGroup, $values);

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_sync_cursors (
                company_id, domain_name, endpoint_group, last_event_at, last_source_id,
                repair_from, repair_to, next_delta_at, safety_window_minutes,
                last_success_at, last_run_started_at, last_run_finished_at,
                rule_version, status, last_error, cursor_meta_json, updated_at
            ) VALUES (
                :company_id, :domain_name, :endpoint_group, :last_event_at, :last_source_id,
                :repair_from, :repair_to, :next_delta_at, :safety_window_minutes,
                :last_success_at, :last_run_started_at, :last_run_finished_at,
                :rule_version, :status, :last_error, :cursor_meta_json, NOW()
            )
            ON DUPLICATE KEY UPDATE
                last_event_at = VALUES(last_event_at),
                last_source_id = VALUES(last_source_id),
                repair_from = VALUES(repair_from),
                repair_to = VALUES(repair_to),
                next_delta_at = VALUES(next_delta_at),
                safety_window_minutes = VALUES(safety_window_minutes),
                last_success_at = VALUES(last_success_at),
                last_run_started_at = VALUES(last_run_started_at),
                last_run_finished_at = VALUES(last_run_finished_at),
                rule_version = VALUES(rule_version),
                status = VALUES(status),
                last_error = VALUES(last_error),
                cursor_meta_json = VALUES(cursor_meta_json),
                updated_at = NOW()
        SQL);
        $stmt->execute(['company_id' => $this->companyId()] + $record);

        return $this->getCursor($domain, $endpointGroup) ?? $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getCursor(string $domain, string $endpointGroup): ?array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT *
            FROM biomie_sync_cursors
            WHERE company_id = :company_id
              AND domain_name = :domain_name
              AND endpoint_group = :endpoint_group
            LIMIT 1
        SQL);
        $stmt->execute([
            'company_id' => $this->companyId(),
            'domain_name' => $domain,
            'endpoint_group' => $endpointGroup,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param list<string> $domains
     * @return list<array<string,mixed>>
     */
    public function listCursors(array $domains = []): array
    {
        $this->ensureSchema();

        $sql = 'SELECT * FROM biomie_sync_cursors WHERE company_id = :company_id';
        $params = ['company_id' => $this->companyId()];
        if ($domains !== []) {
            $placeholders = [];
            foreach (array_values($domains) as $index => $domain) {
                $key = 'domain_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $domain;
            }
            $sql .= ' AND domain_name IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY domain_name ASC, endpoint_group ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function startRun(
        string $runType,
        string $domain,
        string $endpointGroup,
        array $context = [],
    ): int {
        $this->ensureSchema();
        $context['company_id'] = $this->companyId();

        $contextJson = $context !== [] ? OperationalPayloadFingerprint::encodeJson(OperationalPayloadFingerprint::normalizeValue($context)) : null;

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_sync_runs (
                company_id, run_type, domain_name, endpoint_group, trigger_source,
                correlation_id, period_start, period_end, status,
                items_discovered, items_processed, items_failed,
                context_json, result_json, error_summary, started_at, finished_at
            ) VALUES (
                :company_id, :run_type, :domain_name, :endpoint_group, :trigger_source,
                :correlation_id, :period_start, :period_end, 'running',
                0, 0, 0,
                :context_json, NULL, NULL, NOW(), NULL
            )
        SQL);
        $stmt->execute([
            'company_id' => $this->companyId(),
            'run_type' => $runType,
            'domain_name' => $domain,
            'endpoint_group' => $endpointGroup,
            'trigger_source' => (string) ($context['trigger_source'] ?? 'cron'),
            'correlation_id' => ($context['correlation_id'] ?? null) ?: null,
            'period_start' => ($context['period_start'] ?? null) ?: null,
            'period_end' => ($context['period_end'] ?? null) ?: null,
            'context_json' => $contextJson,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->updateRunOperationalMeta($id, $runType, $domain, $context);

        return $id;
    }

    /**
     * @param array<string,mixed> $result
     */
    public function finishRun(
        int $runId,
        string $status,
        array $result = [],
        ?string $error = null,
    ): void {
        $this->ensureSchema();
        $error = $error !== null ? SecretMask::sanitizeErrorMessage($error, 500) : null;

        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE biomie_sync_runs
            SET status = :status,
                items_discovered = :items_discovered,
                items_processed = :items_processed,
                items_failed = :items_failed,
                result_json = :result_json,
                error_summary = :error_summary,
                finished_at = NOW(),
                updated_at = NOW()
            WHERE company_id = :company_id AND id = :id
        SQL);
        $stmt->execute([
            'company_id' => $this->companyId(),
            'id' => $runId,
            'status' => $status,
            'items_discovered' => (int) ($result['items_discovered'] ?? 0),
            'items_processed' => (int) ($result['items_processed'] ?? 0),
            'items_failed' => (int) ($result['items_failed'] ?? 0),
            'result_json' => $result !== [] ? OperationalPayloadFingerprint::encodeJson(OperationalPayloadFingerprint::normalizeValue($result)) : null,
            'error_summary' => $error,
        ]);

        $this->updateRunFinishMeta($runId, $result, $error);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecentRuns(int $limit = 20): array
    {
        $this->ensureSchema();

        $limit = max(1, min($limit, 200));
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT * FROM biomie_sync_runs
              WHERE company_id = :company_id
              ORDER BY started_at DESC, id DESC LIMIT %d',
            $limit
        ));
        $stmt->execute(['company_id' => $this->companyId()]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeOperationalState(int $runLimit = 20): array
    {
        $this->ensureSchema();

        $counts = [
            'recebido' => 0,
            'persistido' => 0,
            'enfileirado' => 0,
            'processado' => 0,
            'falhou_temporariamente' => 0,
            'falhou_definitivamente' => 0,
            'ignorado_por_deduplicacao' => 0,
        ];

        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT
                SUM(CASE WHEN status = 'recebido' THEN 1 ELSE 0 END) AS count_recebido,
                SUM(CASE WHEN status = 'persistido' THEN 1 ELSE 0 END) AS count_persistido,
                SUM(CASE WHEN status = 'enfileirado' THEN 1 ELSE 0 END) AS count_enfileirado,
                SUM(CASE WHEN status = 'processado' THEN 1 ELSE 0 END) AS count_processado,
                SUM(CASE WHEN status = 'falhou_temporariamente' THEN 1 ELSE 0 END) AS count_falhou_temporariamente,
                SUM(CASE WHEN status = 'falhou_definitivamente' THEN 1 ELSE 0 END) AS count_falhou_definitivamente,
                SUM(CASE WHEN status = 'ignorado_por_deduplicacao' THEN 1 ELSE 0 END) AS count_ignorado_por_deduplicacao,
                SUM(CASE WHEN status IN ('persistido', 'enfileirado', 'falhou_temporariamente') THEN 1 ELSE 0 END) AS pending_total,
                MIN(CASE WHEN status IN ('persistido', 'enfileirado', 'falhou_temporariamente') THEN received_at END) AS oldest_received_at
            FROM biomie_webhook_inbox
            WHERE company_id = :company_id
        SQL);
        $stmt->execute(['company_id' => $this->companyId()]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (array_keys($counts) as $status) {
            $counts[$status] = (int) ($pending['count_' . $status] ?? 0);
        }
        $oldest = (string) ($pending['oldest_received_at'] ?? '');
        $pendingAgeSeconds = null;
        if ($oldest !== '') {
            $pendingAgeSeconds = max(0, time() - strtotime($oldest));
        }

        return [
            'webhook_inbox' => [
                'counts' => $counts,
                'pending_total' => (int) ($pending['pending_total'] ?? 0),
                'oldest_pending_at' => $oldest !== '' ? $oldest : null,
                'oldest_pending_age_seconds' => $pendingAgeSeconds,
            ],
            'cursors' => $this->listCursors(),
            'recent_runs' => $this->listRecentRuns($runLimit),
        ];
    }

    private function findRecentDuplicate(string $dedupeKey, string $threshold): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT id, status
            FROM biomie_webhook_inbox
            WHERE company_id = :company_id
              AND dedupe_key = :dedupe_key
              AND received_at >= :threshold
            ORDER BY id DESC
            LIMIT 1
        SQL);
        $stmt->execute([
            'company_id' => $this->companyId(),
            'dedupe_key' => $dedupeKey,
            'threshold' => $threshold,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function ensurePhase7Columns(): void
    {
        foreach ([
            [self::TABLE_SYNC_RUNS, 'run_id', 'VARCHAR(80) NULL'],
            [self::TABLE_SYNC_RUNS, 'duration_ms', 'INT UNSIGNED NULL'],
            [self::TABLE_SYNC_RUNS, 'records_seen', 'INT UNSIGNED NULL'],
            [self::TABLE_SYNC_RUNS, 'records_inserted', 'INT UNSIGNED NULL'],
            [self::TABLE_SYNC_RUNS, 'records_updated', 'INT UNSIGNED NULL'],
            [self::TABLE_SYNC_RUNS, 'records_skipped', 'INT UNSIGNED NULL'],
            [self::TABLE_SYNC_RUNS, 'records_failed', 'INT UNSIGNED NULL'],
            [self::TABLE_SYNC_RUNS, 'pages_processed', 'INT UNSIGNED NULL'],
            [self::TABLE_SYNC_RUNS, 'cursor_before', 'LONGTEXT NULL'],
            [self::TABLE_SYNC_RUNS, 'cursor_after', 'LONGTEXT NULL'],
            [self::TABLE_SYNC_RUNS, 'window_start', 'DATETIME NULL'],
            [self::TABLE_SYNC_RUNS, 'window_end', 'DATETIME NULL'],
            [self::TABLE_SYNC_RUNS, 'error_code', 'VARCHAR(80) NULL'],
            [self::TABLE_SYNC_RUNS, 'error_message_sanitized', 'TEXT NULL'],
            [self::TABLE_SYNC_RUNS, 'request_id', 'VARCHAR(80) NULL'],
            [self::TABLE_SYNC_RUNS, 'triggered_by', 'VARCHAR(40) NULL'],
            [self::TABLE_SYNC_CURSORS, 'entity_type', 'VARCHAR(64) NULL'],
            [self::TABLE_SYNC_CURSORS, 'cursor_key', 'VARCHAR(120) NULL'],
            [self::TABLE_SYNC_CURSORS, 'cursor_value', 'LONGTEXT NULL'],
            [self::TABLE_SYNC_CURSORS, 'last_attempt_at', 'DATETIME NULL'],
            [self::TABLE_SYNC_CURSORS, 'last_error_at', 'DATETIME NULL'],
            [self::TABLE_SYNC_CURSORS, 'last_error_message_sanitized', 'TEXT NULL'],
            [self::TABLE_SYNC_CURSORS, 'last_run_id', 'VARCHAR(80) NULL'],
            [self::TABLE_SYNC_CURSORS, 'records_total', 'INT UNSIGNED NULL'],
            [self::TABLE_SYNC_CURSORS, 'is_stale', 'TINYINT(1) NOT NULL DEFAULT 0'],
            [self::TABLE_WEBHOOK_INBOX, 'provider', 'VARCHAR(64) NULL'],
            [self::TABLE_WEBHOOK_INBOX, 'event_id', 'VARCHAR(160) NULL'],
            [self::TABLE_WEBHOOK_INBOX, 'event_type', 'VARCHAR(120) NULL'],
            [self::TABLE_WEBHOOK_INBOX, 'payload_sanitized', 'LONGTEXT NULL'],
            [self::TABLE_WEBHOOK_INBOX, 'job_id', 'VARCHAR(80) NULL'],
            [self::TABLE_WEBHOOK_INBOX, 'request_id', 'VARCHAR(80) NULL'],
            [self::TABLE_WEBHOOK_INBOX, 'failed_at', 'DATETIME NULL'],
        ] as [$table, $column, $definition]) {
            $this->maybeAddColumn($table, $column, $definition);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function updateRunOperationalMeta(int $id, string $runType, string $domain, array $context): void
    {
        $assignments = [];
        $params = ['id' => $id, 'company_id' => $this->companyId()];
        $values = [
            'run_id' => (string) ($context['run_id'] ?? $context['correlation_id'] ?? RequestContext::id()),
            'triggered_by' => (string) ($context['trigger_source'] ?? 'cron'),
            'request_id' => RequestContext::id(),
            'window_start' => ($context['period_start'] ?? null) ?: null,
            'window_end' => ($context['period_end'] ?? null) ?: null,
        ];

        foreach ($values as $column => $value) {
            if ($this->columnExists(self::TABLE_SYNC_RUNS, $column)) {
                $assignments[] = "{$column} = :{$column}";
                $params[$column] = $value;
            }
        }
        if ($assignments === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE biomie_sync_runs SET ' . implode(', ', $assignments) .
            ' WHERE company_id = :company_id AND id = :id'
        );
        $stmt->execute($params);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function updateRunFinishMeta(int $id, array $result, ?string $error): void
    {
        $assignments = [];
        $params = ['id' => $id, 'company_id' => $this->companyId()];
        $values = [
            'duration_ms' => isset($result['duration_ms']) ? (int) $result['duration_ms'] : null,
            'records_seen' => (int) ($result['items_discovered'] ?? $result['records_seen'] ?? 0),
            'records_inserted' => (int) ($result['records_inserted'] ?? $result['inserted'] ?? 0),
            'records_updated' => (int) ($result['records_updated'] ?? $result['updated'] ?? 0),
            'records_skipped' => (int) ($result['records_skipped'] ?? $result['skipped'] ?? 0),
            'records_failed' => (int) ($result['items_failed'] ?? $result['records_failed'] ?? 0),
            'pages_processed' => (int) ($result['pages_processed'] ?? 0),
            'error_code' => $error !== null ? $this->errorCode($error) : null,
            'error_message_sanitized' => $error,
        ];

        foreach ($values as $column => $value) {
            if ($this->columnExists(self::TABLE_SYNC_RUNS, $column)) {
                $assignments[] = "{$column} = :{$column}";
                $params[$column] = $value;
            }
        }
        if ($assignments === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE biomie_sync_runs SET ' . implode(', ', $assignments) .
            ' WHERE company_id = :company_id AND id = :id'
        );
        $stmt->execute($params);
    }

    private function maybeAddColumn(string $table, string $column, string $definition): void
    {
        if (!$this->tableExists($table) || $this->columnExists($table, $column)) {
            return;
        }

        try {
            $this->pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        } catch (\Throwable $exception) {
            $this->logger->warning('SyncPipelineRepository: falha ao aplicar coluna expansiva.', [
                'table' => $table,
                'column' => $column,
                'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 160),
            ]);
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function errorCode(string $error): string
    {
        $lower = mb_strtolower($error);
        return match (true) {
            str_contains($lower, 'timeout') => 'timeout',
            str_contains($lower, '429'), str_contains($lower, 'rate') => 'rate_limit',
            str_contains($lower, 'credencial'), str_contains($lower, 'app_key'), str_contains($lower, 'app_secret') => 'credentials_error',
            str_contains($lower, 'schema'), str_contains($lower, 'tabela') => 'schema_error',
            default => 'sync_error',
        };
    }

    /**
     * @param array<string,mixed> $result
     */
    private function updateWebhookState(
        int $id,
        string $status,
        ?string $message,
        int $delaySeconds,
        array $result,
    ): void {
        $this->ensureSchema();
        $message = $message !== null ? SecretMask::sanitizeErrorMessage($message, 500) : null;

        $availableAt = $status === 'falhou_temporariamente'
            ? date('Y-m-d H:i:s', time() + max($delaySeconds, 60))
            : date('Y-m-d H:i:s');

        $processedAt = in_array($status, ['processado', 'falhou_definitivamente'], true)
            ? date('Y-m-d H:i:s')
            : null;

        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE biomie_webhook_inbox
            SET status = :status,
                error_summary = :error_summary,
                result_json = :result_json,
                available_at = :available_at,
                processed_at = :processed_at,
                updated_at = NOW()
            WHERE company_id = :company_id AND id = :id
        SQL);
        $stmt->execute([
            'company_id' => $this->companyId(),
            'id' => $id,
            'status' => $status,
            'error_summary' => $message,
            'result_json' => $result !== [] ? OperationalPayloadFingerprint::encodeJson(OperationalPayloadFingerprint::normalizeValue($result)) : null,
            'available_at' => $availableAt,
            'processed_at' => $processedAt,
        ]);
    }

    /**
     * @param array<string,mixed> $values
     */
    private function resolveCursorDate(?string $current, array $values, string $key, string $mode): ?string
    {
        if (!array_key_exists($key, $values)) {
            return $current;
        }

        $incoming = $values[$key];
        if ($incoming === null || $incoming === '') {
            return null;
        }

        $incomingValue = (string) $incoming;
        if ($current === null || $current === '') {
            return $incomingValue;
        }

        if ($mode === 'min') {
            return strcmp($incomingValue, $current) < 0 ? $incomingValue : $current;
        }

        return strcmp($incomingValue, $current) > 0 ? $incomingValue : $current;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function resolveCursorSourceId(?string $current, array $values): ?string
    {
        if (!array_key_exists('last_source_id', $values)) {
            return $current;
        }

        $incoming = $values['last_source_id'];
        if ($incoming === null || $incoming === '') {
            return null;
        }

        return (string) $incoming;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function resolvePlainValue(mixed $current, array $values, string $key): mixed
    {
        if (!array_key_exists($key, $values)) {
            return $current;
        }

        return $values[$key];
    }

    /**
     * @param array<string,mixed> $values
     */
    private function resolveJsonValue(?string $current, array $values, string $key): ?string
    {
        if (!array_key_exists($key, $values)) {
            return $current;
        }

        $incoming = $values[$key];
        if ($incoming === null || $incoming === []) {
            return null;
        }

        if (is_string($incoming)) {
            return $incoming;
        }

        return OperationalPayloadFingerprint::encodeJson(OperationalPayloadFingerprint::normalizeValue($incoming));
    }

    private function encodeJson(mixed $value): string
    {
        return OperationalPayloadFingerprint::encodeJson($value);
    }

    private function normalizeValue(mixed $value): mixed
    {
        return OperationalPayloadFingerprint::normalizeValue($value);
    }

    private function isList(array $value): bool
    {
        return array_is_list($value);
    }

    private function companyId(): int
    {
        return $this->tenantCompanyId ?? TenantContext::companyId();
    }
}
