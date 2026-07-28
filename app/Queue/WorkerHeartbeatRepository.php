<?php

declare(strict_types=1);

namespace BIomie\Queue;

use PDO;

final class WorkerHeartbeatRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
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
    }

    /**
     * @param list<string> $queues
     */
    public function start(string $workerId, array $queues, string $version = 'dev'): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_worker_heartbeats (
                worker_id, hostname, pid, queues, started_at, last_heartbeat_at,
                status, processed_jobs, failed_jobs, memory_usage_mb, version
            ) VALUES (
                :worker_id, :hostname, :pid, :queues, NOW(), NOW(),
                'running', 0, 0, :memory_usage_mb, :version
            )
            ON DUPLICATE KEY UPDATE
                hostname = VALUES(hostname),
                pid = VALUES(pid),
                queues = VALUES(queues),
                started_at = NOW(),
                last_heartbeat_at = NOW(),
                status = 'running',
                processed_jobs = 0,
                failed_jobs = 0,
                memory_usage_mb = VALUES(memory_usage_mb),
                version = VALUES(version),
                updated_at = NOW()
        SQL);
        $stmt->execute([
            'worker_id' => $workerId,
            'hostname' => gethostname() ?: null,
            'pid' => getmypid() ?: null,
            'queues' => implode(',', $queues),
            'memory_usage_mb' => self::memoryUsageMb(),
            'version' => $version,
        ]);
    }

    public function heartbeat(string $workerId, int $processedJobs, int $failedJobs, string $status = 'running'): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE biomie_worker_heartbeats
            SET last_heartbeat_at = NOW(),
                status = :status,
                processed_jobs = :processed_jobs,
                failed_jobs = :failed_jobs,
                memory_usage_mb = :memory_usage_mb,
                updated_at = NOW()
            WHERE worker_id = :worker_id
        SQL);
        $stmt->execute([
            'worker_id' => $workerId,
            'status' => $status,
            'processed_jobs' => $processedJobs,
            'failed_jobs' => $failedJobs,
            'memory_usage_mb' => self::memoryUsageMb(),
        ]);
    }

    public function stop(string $workerId, int $processedJobs, int $failedJobs): void
    {
        $this->heartbeat($workerId, $processedJobs, $failedJobs, 'stopped');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->query(
            'SELECT * FROM biomie_worker_heartbeats ORDER BY last_heartbeat_at DESC, id DESC LIMIT 1'
        );
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $latest = $this->latest();
        if ($latest === null) {
            return [
                'status' => 'unknown',
                'message' => 'Worker nunca registrou heartbeat.',
                'latest' => null,
                'age_seconds' => null,
            ];
        }

        $lastAt = (string) ($latest['last_heartbeat_at'] ?? '');
        $ageSeconds = $lastAt !== '' ? max(0, time() - (strtotime($lastAt) ?: time())) : null;
        $status = match (true) {
            $ageSeconds === null => 'unknown',
            $ageSeconds <= 120 => 'ok',
            $ageSeconds <= 600 => 'warning',
            default => 'critical',
        };

        return [
            'status' => $status,
            'message' => match ($status) {
                'ok' => 'Worker ativo com heartbeat recente.',
                'warning' => 'Worker sem heartbeat recente.',
                'critical' => 'Worker inativo ou travado.',
                default => 'Worker sem leitura conclusiva.',
            },
            'latest' => $latest,
            'age_seconds' => $ageSeconds,
        ];
    }

    private static function memoryUsageMb(): float
    {
        return round(memory_get_usage(true) / 1048576, 2);
    }
}
