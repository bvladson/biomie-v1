<?php

declare(strict_types=1);

namespace BIomie\Queue;

use BIomie\Logger;
use BIomie\RequestContext;
use PDO;

final class DatabaseJobQueue implements JobQueueInterface
{
    public function __construct(private readonly PDO $pdo, private readonly ?Logger $logger = null)
    {
    }

    public function enqueue(string $queue, string $type, array $payload = [], array $options = []): array
    {
        $queue = trim($queue) !== '' ? trim($queue) : 'default';
        $type = trim($type);
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            ksort($payload);
            $idempotencyKey = $queue . '|' . $type . '|' . hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }

        $jobUuid = $this->uuid();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare(
            'INSERT INTO biomie_jobs
                (job_uuid, idempotency_key, queue_name, job_type, payload_json, status, priority, max_attempts, available_at, company_id, created_by, request_id)
             VALUES
                (:job_uuid, :idempotency_key, :queue_name, :job_type, :payload_json, :status, :priority, :max_attempts, :available_at, :company_id, :created_by, :request_id)
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'job_uuid' => $jobUuid,
            'idempotency_key' => $idempotencyKey,
            'queue_name' => $queue,
            'job_type' => $type,
            'payload_json' => $payloadJson === false ? '{}' : $payloadJson,
            'status' => 'queued',
            'priority' => max(0, min(9, (int) ($options['priority'] ?? 5))),
            'max_attempts' => max(1, (int) ($options['max_attempts'] ?? 3)),
            'available_at' => (string) ($options['available_at'] ?? date('Y-m-d H:i:s')),
            'company_id' => $options['company_id'] ?? null,
            'created_by' => $options['created_by'] ?? null,
            'request_id' => RequestContext::id(),
        ]);

        $job = $this->findByIdempotencyKey($idempotencyKey);
        $this->logger?->info('job.enqueued', [
            'driver' => 'database',
            'queue' => $queue,
            'type' => $type,
            'idempotency_key' => $idempotencyKey,
            'job_id' => $job['job_id'] ?? null,
        ]);

        return [
            'queued' => true,
            'job_id' => (string) ($job['job_id'] ?? $jobUuid),
            'job_uuid' => (string) ($job['job_uuid'] ?? $jobUuid),
            'status' => (string) ($job['status'] ?? 'queued'),
            'idempotency_key' => $idempotencyKey,
            'queue' => $queue,
            'type' => $type,
        ];
    }

    public function claimNext(array $queues = [], int $limit = 1): ?array
    {
        $queues = array_values(array_filter(array_map('strval', $queues)));
        $whereQueue = '';
        $params = ['now' => date('Y-m-d H:i:s')];
        if ($queues !== []) {
            $placeholders = [];
            foreach ($queues as $index => $queue) {
                $key = 'queue_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $queue;
            }
            $whereQueue = 'AND queue_name IN (' . implode(',', $placeholders) . ')';
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM biomie_jobs
                 WHERE status IN ('queued', 'failed_retryable')
                   AND available_at <= :now
                   {$whereQueue}
                 ORDER BY priority ASC, available_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE biomie_jobs
                 SET status = 'running',
                     attempts = attempts + 1,
                     reserved_at = NOW(),
                     started_at = NOW(),
                     locked_by = :locked_by,
                     locked_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $update->execute([
                'locked_by' => gethostname() ?: 'worker',
                'id' => (int) $row['id'],
            ]);
            $this->pdo->commit();

            $job = $this->normalizeRow($row);
            $job['status'] = 'running';
            $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
            return $job;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function complete(array $job, array $result = []): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE biomie_jobs
             SET status = 'completed', finished_at = NOW(), locked_by = NULL, locked_until = NULL, updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute(['id' => (int) ($job['id'] ?? 0)]);
        $this->logger?->info('job.completed', ['driver' => 'database', 'job_id' => $job['job_id'] ?? null, 'result' => $result]);
    }

    public function fail(array $job, string $message, bool $retryable = true): void
    {
        $attempts = (int) ($job['attempts'] ?? 1);
        $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
        $canRetry = $retryable && $attempts < $maxAttempts;
        $status = $canRetry ? 'failed_retryable' : 'failed_terminal';
        $delaySeconds = $canRetry ? min(300, max(5, (2 ** max(0, $attempts - 1)) * 10)) : 0;

        $stmt = $this->pdo->prepare(
            "UPDATE biomie_jobs
             SET status = :status,
                 failed_at = NOW(),
                 finished_at = IF(:terminal = 1, NOW(), finished_at),
                 available_at = IF(:terminal = 1, available_at, DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND)),
                 last_error = :last_error,
                 error_summary = :error_summary,
                 locked_by = NULL,
                 locked_until = NULL,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'status' => $status,
            'terminal' => $canRetry ? 0 : 1,
            'last_error' => $message,
            'error_summary' => substr($message, 0, 255),
            'id' => (int) ($job['id'] ?? 0),
        ]);
        $this->logger?->error($canRetry ? 'job.failed_retryable' : 'job.failed_terminal', [
            'driver' => 'database',
            'job_id' => $job['job_id'] ?? null,
            'message' => $message,
        ]);
    }

    public function summary(): array
    {
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS total FROM biomie_jobs GROUP BY status');
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return [
            'driver' => 'database',
            'counts' => $counts,
        ];
    }

    public function find(string $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM biomie_jobs WHERE job_uuid = :job_uuid OR CAST(id AS CHAR) = :id LIMIT 1');
        $stmt->execute(['job_uuid' => $jobId, 'id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    private function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM biomie_jobs WHERE idempotency_key = :idempotency_key LIMIT 1');
        $stmt->execute(['idempotency_key' => $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'job_id' => (string) ($row['job_uuid'] ?? ''),
            'job_uuid' => (string) ($row['job_uuid'] ?? ''),
            'idempotency_key' => (string) ($row['idempotency_key'] ?? ''),
            'queue' => (string) ($row['queue_name'] ?? 'default'),
            'type' => (string) ($row['job_type'] ?? ''),
            'job_type' => (string) ($row['job_type'] ?? ''),
            'payload' => is_array($payload) ? $payload : [],
            'status' => (string) ($row['status'] ?? ''),
            'priority' => (int) ($row['priority'] ?? 5),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'max_attempts' => (int) ($row['max_attempts'] ?? 3),
            'created_at' => $row['created_at'] ?? null,
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'error_summary' => $row['error_summary'] ?? null,
            'request_id' => $row['request_id'] ?? null,
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
