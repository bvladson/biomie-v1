<?php

declare(strict_types=1);

namespace BIomie\Queue;

use BIomie\OperationalPayloadFingerprint;
use BIomie\RequestContext;
use BIomie\Support\SecretMask;
use PDO;

final class DeadLetterRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
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
    }

    /**
     * @param array<string,mixed> $job
     */
    public function recordJobFailure(array $job, string $message, bool $canRetry = true, ?string $retryAfter = null): void
    {
        $this->ensureSchema();

        $payload = is_array($job['payload'] ?? null) ? SecretMask::redactArray($job['payload']) : [];
        $payloadJson = $payload !== []
            ? OperationalPayloadFingerprint::encodeJson(OperationalPayloadFingerprint::normalizeValue($payload))
            : null;
        $sanitized = SecretMask::sanitizeErrorMessage($message, 500);

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_dead_letter_jobs (
                job_id, job_type, queue, payload_sanitized, attempts,
                failed_at, last_error_code, last_error_message_sanitized,
                run_id, request_id, can_retry, retry_after, resolved_at,
                resolved_by, resolution_notes
            ) VALUES (
                :job_id, :job_type, :queue, :payload_sanitized, :attempts,
                NOW(), :last_error_code, :last_error_message_sanitized,
                :run_id, :request_id, :can_retry, :retry_after, NULL,
                NULL, NULL
            )
            ON DUPLICATE KEY UPDATE
                attempts = VALUES(attempts),
                failed_at = NOW(),
                last_error_code = VALUES(last_error_code),
                last_error_message_sanitized = VALUES(last_error_message_sanitized),
                run_id = VALUES(run_id),
                request_id = VALUES(request_id),
                can_retry = VALUES(can_retry),
                retry_after = VALUES(retry_after),
                resolved_at = NULL,
                resolved_by = NULL,
                resolution_notes = NULL,
                updated_at = NOW()
        SQL);
        $stmt->execute([
            'job_id' => (string) ($job['job_id'] ?? $job['job_uuid'] ?? ''),
            'job_type' => (string) ($job['type'] ?? $job['job_type'] ?? ''),
            'queue' => (string) ($job['queue'] ?? 'default'),
            'payload_sanitized' => $payloadJson,
            'attempts' => (int) ($job['attempts'] ?? 0),
            'last_error_code' => self::errorCode($sanitized),
            'last_error_message_sanitized' => $sanitized,
            'run_id' => (string) ($job['run_id'] ?? ''),
            'request_id' => (string) ($job['request_id'] ?? RequestContext::id()),
            'can_retry' => $canRetry ? 1 : 0,
            'retry_after' => $retryAfter,
        ]);
    }

    public function markResolved(string $jobId, ?string $resolvedBy = null, ?string $notes = null): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE biomie_dead_letter_jobs
            SET resolved_at = NOW(),
                resolved_by = :resolved_by,
                resolution_notes = :resolution_notes,
                updated_at = NOW()
            WHERE job_id = :job_id
        SQL);
        $stmt->execute([
            'job_id' => $jobId,
            'resolved_by' => $resolvedBy,
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * @return array<string,int>
     */
    public function counts(): array
    {
        $this->ensureSchema();

        $row = $this->pdo->query(<<<'SQL'
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS unresolved,
                SUM(CASE WHEN resolved_at IS NULL AND can_retry = 1 THEN 1 ELSE 0 END) AS retryable,
                SUM(CASE WHEN failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS last_24h
            FROM biomie_dead_letter_jobs
        SQL)->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'unresolved' => (int) ($row['unresolved'] ?? 0),
            'retryable' => (int) ($row['retryable'] ?? 0),
            'last_24h' => (int) ($row['last_24h'] ?? 0),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listUnresolved(int $limit = 50): array
    {
        $this->ensureSchema();
        $limit = max(1, min(200, $limit));

        $stmt = $this->pdo->query(sprintf(
            'SELECT * FROM biomie_dead_letter_jobs WHERE resolved_at IS NULL ORDER BY failed_at DESC, id DESC LIMIT %d',
            $limit
        ));

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function errorCode(string $message): string
    {
        $lower = mb_strtolower($message);
        return match (true) {
            str_contains($lower, 'credencial'), str_contains($lower, 'app_key'), str_contains($lower, 'app_secret') => 'credentials_error',
            str_contains($lower, 'timeout') => 'timeout',
            str_contains($lower, '429'), str_contains($lower, 'rate') => 'rate_limit',
            str_contains($lower, 'schema'), str_contains($lower, 'tabela') => 'schema_error',
            default => 'job_failed',
        };
    }
}
