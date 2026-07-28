<?php

declare(strict_types=1);

namespace BIomie\Queue;

use BIomie\Logger;
use BIomie\RequestContext;
use BIomie\Support\SecretMask;
use BIomie\Support\TenantContext;

final class FileJobQueue implements JobQueueInterface
{
    private string $jobsDir;

    /**
     * @var list<string>
     */
    private array $statuses = [
        'queued',
        'running',
        'completed',
        'failed_retryable',
        'failed_terminal',
        'cancelled',
    ];

    public function __construct(private readonly string $baseDir, private readonly ?Logger $logger = null)
    {
        $this->jobsDir = rtrim($this->baseDir, '/\\') . '/jobs';
        if (!is_dir($this->jobsDir)) {
            @mkdir($this->jobsDir, 0775, true);
        }
    }

    public function enqueue(string $queue, string $type, array $payload = [], array $options = []): array
    {
        $payload['company_id'] ??= TenantContext::companyId();
        $payload['canal'] ??= TenantContext::canal();
        $queue = $this->slug($queue !== '' ? $queue : 'default');
        $type = trim($type);
        $priority = max(0, min(9, (int) ($options['priority'] ?? 5)));
        $maxAttempts = max(1, (int) ($options['max_attempts'] ?? 3));
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = $this->idempotencyKey($queue, $type, $payload);
        }

        $jobId = substr(hash('sha256', $idempotencyKey), 0, 40);
        $existing = $this->find($jobId);
        if ($existing !== null && in_array((string) ($existing['status'] ?? ''), ['queued', 'running', 'failed_retryable'], true)) {
            return [
                'queued' => false,
                'job_id' => $jobId,
                'job_uuid' => (string) ($existing['job_uuid'] ?? $jobId),
                'status' => (string) ($existing['status'] ?? 'queued'),
                'idempotency_key' => $idempotencyKey,
                'queue' => $queue,
                'type' => $type,
            ];
        }

        $now = date(DATE_ATOM);
        $job = [
            'job_id' => $jobId,
            'job_uuid' => $this->uuidFromHash($jobId),
            'idempotency_key' => $idempotencyKey,
            'queue' => $queue,
            'type' => $type,
            'job_type' => $type,
            'payload' => $payload,
            'status' => 'queued',
            'priority' => $priority,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => (string) ($options['available_at'] ?? $now),
            'next_attempt_at' => (string) ($options['available_at'] ?? $now),
            'backoff_strategy' => (string) ($options['backoff_strategy'] ?? 'exponential_jitter'),
            'created_at' => $now,
            'updated_at' => $now,
            'request_id' => RequestContext::id(),
            'created_by' => $options['created_by'] ?? null,
        ];

        $path = $this->jobPath('queued', $queue, $jobId);
        $this->writeJson($path, $job);
        $this->logger?->info('job.enqueued', [
            'job_id' => $jobId,
            'idempotency_key' => $idempotencyKey,
            'queue' => $queue,
            'type' => $type,
            'request_id' => RequestContext::id(),
        ]);

        return [
            'queued' => true,
            'job_id' => $jobId,
            'job_uuid' => $job['job_uuid'],
            'status' => 'queued',
            'idempotency_key' => $idempotencyKey,
            'queue' => $queue,
            'type' => $type,
        ];
    }

    public function claimNext(array $queues = [], int $limit = 1): ?array
    {
        $allowedQueues = array_values(array_filter(array_map(fn (string $queue): string => $this->slug($queue), $queues)));
        $candidates = array_merge(
            glob($this->jobsDir . '/queued_job_*.json') ?: [],
            glob($this->jobsDir . '/failed_retryable_job_*.json') ?: []
        );
        $jobs = [];
        foreach ($candidates as $path) {
            $job = $this->readJson($path);
            if (!is_array($job)) {
                continue;
            }
            $queue = (string) ($job['queue'] ?? 'default');
            if ($allowedQueues !== [] && !in_array($queue, $allowedQueues, true)) {
                continue;
            }
            if (!$this->isAvailable($job)) {
                continue;
            }
            $jobs[] = ['path' => $path, 'job' => $job];
        }

        usort($jobs, static function (array $a, array $b): int {
            $left = $a['job'];
            $right = $b['job'];
            return [
                (int) ($left['priority'] ?? 5),
                (string) ($left['available_at'] ?? ''),
                (string) ($left['created_at'] ?? ''),
            ] <=> [
                (int) ($right['priority'] ?? 5),
                (string) ($right['available_at'] ?? ''),
                (string) ($right['created_at'] ?? ''),
            ];
        });

        foreach ($jobs as $candidate) {
            $job = $candidate['job'];
            $jobId = (string) ($job['job_id'] ?? '');
            $queue = (string) ($job['queue'] ?? 'default');
            if ($jobId === '') {
                continue;
            }

            $runPath = $this->jobPath('running', $queue, $jobId);
            if (!@rename((string) $candidate['path'], $runPath)) {
                continue;
            }

            $job['status'] = 'running';
            $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
            $job['started_at'] = date(DATE_ATOM);
            $job['reserved_at'] = $job['started_at'];
            $job['locked_at'] = $job['started_at'];
            $job['lock_expires_at'] = date(DATE_ATOM, time() + 600);
            $job['updated_at'] = $job['started_at'];
            $job['locked_by'] = gethostname() ?: 'worker';
            $job['_path'] = $runPath;
            $this->writeJson($runPath, $job);

            return $job;
        }

        return null;
    }

    public function complete(array $job, array $result = []): void
    {
        $path = (string) ($job['_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return;
        }

        $finishedAt = date(DATE_ATOM);
        $job['status'] = 'completed';
        $job['finished_at'] = $finishedAt;
        $job['updated_at'] = $finishedAt;
        $job['result'] = $result;
        unset($job['_path']);
        $target = $this->jobPath('completed', (string) ($job['queue'] ?? 'default'), (string) ($job['job_id'] ?? ''));
        $this->writeJson($path, $job);
        @rename($path, $target);
        $this->logger?->info('job.completed', [
            'job_id' => $job['job_id'] ?? null,
            'type' => $job['type'] ?? null,
            'attempts' => $job['attempts'] ?? null,
        ]);
        $this->pruneOld();
    }

    public function fail(array $job, string $message, bool $retryable = true): void
    {
        $path = (string) ($job['_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return;
        }

        $message = SecretMask::sanitizeErrorMessage($message, 500);
        $attempts = (int) ($job['attempts'] ?? 1);
        $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
        $canRetry = $retryable && $attempts < $maxAttempts;
        $status = $canRetry ? 'failed_retryable' : 'failed_terminal';
        $now = date(DATE_ATOM);

        $job['status'] = $status;
        $job['last_error'] = $message;
        $job['error_summary'] = substr($message, 0, 255);
        $job['updated_at'] = $now;
        $job['failed_at'] = $now;
        $job['locked_by'] = null;
        $job['locked_at'] = null;
        $job['lock_expires_at'] = null;
        if ($canRetry) {
            $retryAfter = date(DATE_ATOM, time() + $this->retryDelaySeconds($attempts));
            $job['available_at'] = $retryAfter;
            $job['next_attempt_at'] = $retryAfter;
        } else {
            $job['finished_at'] = $now;
            $job['next_attempt_at'] = null;
        }
        unset($job['_path']);

        $target = $this->jobPath($status, (string) ($job['queue'] ?? 'default'), (string) ($job['job_id'] ?? ''));
        $this->writeJson($path, $job);
        @rename($path, $target);
        $this->logger?->error($canRetry ? 'job.failed_retryable' : 'job.failed_terminal', [
            'job_id' => $job['job_id'] ?? null,
            'type' => $job['type'] ?? null,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'message' => $message,
        ]);
    }

    public function summary(): array
    {
        $counts = [];
        foreach ($this->statuses as $status) {
            $counts[$status] = count(glob($this->jobsDir . "/{$status}_job_*.json") ?: []);
        }

        return [
            'driver' => 'file',
            'jobs_dir' => $this->jobsDir,
            'counts' => $counts,
            'oldest_queued_at' => $this->oldestDate(['queued', 'failed_retryable']),
        ];
    }

    public function releaseExpiredLocks(int $ttlSeconds = 900): int
    {
        $released = 0;
        foreach (glob($this->jobsDir . '/running_job_*.json') ?: [] as $path) {
            $job = $this->readJson($path);
            if (!is_array($job)) {
                continue;
            }

            $expiresAt = (string) ($job['lock_expires_at'] ?? '');
            $startedAt = (string) ($job['started_at'] ?? '');
            $expired = $expiresAt !== ''
                ? ((strtotime($expiresAt) ?: PHP_INT_MAX) <= time())
                : ($startedAt !== '' && (strtotime($startedAt) ?: time()) <= time() - $ttlSeconds);
            if (!$expired) {
                continue;
            }

            $attempts = (int) ($job['attempts'] ?? 1);
            $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
            $status = $attempts < $maxAttempts ? 'failed_retryable' : 'failed_terminal';
            $now = date(DATE_ATOM);
            $job['status'] = $status;
            $job['last_error'] = 'Job preso liberado por lock expirado.';
            $job['error_summary'] = 'Job preso liberado por lock expirado.';
            $job['failed_at'] = $now;
            $job['updated_at'] = $now;
            $job['locked_by'] = null;
            $job['locked_at'] = null;
            $job['lock_expires_at'] = null;
            if ($status === 'failed_retryable') {
                $job['available_at'] = $now;
                $job['next_attempt_at'] = $now;
            } else {
                $job['finished_at'] = $now;
                $job['next_attempt_at'] = null;
            }
            unset($job['_path']);

            $target = $this->jobPath($status, (string) ($job['queue'] ?? 'default'), (string) ($job['job_id'] ?? ''));
            $this->writeJson($path, $job);
            @rename($path, $target);
            $released++;
        }

        return $released;
    }

    /**
     * @param list<string> $statuses
     * @return list<array<string,mixed>>
     */
    public function listJobs(array $statuses = [], int $limit = 50): array
    {
        $statuses = $statuses !== [] ? $statuses : $this->statuses;
        $limit = max(1, min(500, $limit));
        $rows = [];
        foreach ($statuses as $status) {
            foreach (glob($this->jobsDir . '/' . $status . '_job_*.json') ?: [] as $path) {
                $job = $this->readJson($path);
                if (is_array($job)) {
                    $rows[] = $this->publicJob($job);
                }
            }
        }

        usort($rows, static fn (array $left, array $right): int =>
            strcmp((string) ($right['updated_at'] ?? $right['created_at'] ?? ''), (string) ($left['updated_at'] ?? $left['created_at'] ?? ''))
        );

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function retry(string $jobId): ?array
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return null;
        }

        foreach (['failed_terminal', 'failed_retryable'] as $status) {
            foreach (glob($this->jobsDir . "/{$status}_job_*_{$jobId}.json") ?: [] as $path) {
                $job = $this->readJson($path);
                if (!is_array($job)) {
                    continue;
                }
                $now = date(DATE_ATOM);
                $job['status'] = 'queued';
                $job['available_at'] = $now;
                $job['next_attempt_at'] = $now;
                $job['updated_at'] = $now;
                $job['locked_by'] = null;
                $job['locked_at'] = null;
                $job['lock_expires_at'] = null;
                unset($job['_path']);
                $target = $this->jobPath('queued', (string) ($job['queue'] ?? 'default'), (string) ($job['job_id'] ?? ''));
                $this->writeJson($path, $job);
                @rename($path, $target);
                return $this->publicJob($job);
            }
        }

        return null;
    }

    public function find(string $jobId): ?array
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return null;
        }

        foreach ($this->statuses as $status) {
            $matches = glob($this->jobsDir . "/{$status}_job_*_{$jobId}.json") ?: [];
            foreach ($matches as $path) {
                $job = $this->readJson($path);
                if (is_array($job)) {
                    return $this->publicJob($job);
                }
            }
        }

        return null;
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        return $this->find(substr(hash('sha256', $idempotencyKey), 0, 40));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function idempotencyKey(string $queue, string $type, array $payload = []): string
    {
        ksort($payload);
        return $queue . '|' . $type . '|' . hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function jobPath(string $status, string $queue, string $jobId): string
    {
        return sprintf('%s/%s_job_%s_%s.json', $this->jobsDir, $status, $this->slug($queue), $jobId);
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?: 'default';
        return trim($value, '_') ?: 'default';
    }

    /**
     * @param array<string,mixed> $job
     */
    private function isAvailable(array $job): bool
    {
        $availableAt = (string) ($job['available_at'] ?? '');
        if ($availableAt === '') {
            return true;
        }

        $timestamp = strtotime($availableAt);
        return $timestamp === false || $timestamp <= time();
    }

    private function retryDelaySeconds(int $attempts): int
    {
        $nextAttempt = $attempts + 1;
        $base = match (true) {
            $nextAttempt <= 1 => 0,
            $nextAttempt === 2 => 30,
            $nextAttempt === 3 => 120,
            $nextAttempt === 4 => 600,
            default => 1800,
        };

        if ($base === 0) {
            return 0;
        }

        return $base + random_int(0, max(1, (int) floor($base * 0.15)));
    }

    private function uuidFromHash(string $hash): string
    {
        $hex = str_pad(substr($hash, 0, 32), 32, '0');
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function writeJson(string $path, array $payload): void
    {
        $temp = $path . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }

        @file_put_contents($temp, $json, LOCK_EX);
        @rename($temp, $path);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function publicJob(array $job): array
    {
        unset($job['_path']);
        return $job;
    }

    private function pruneOld(int $maxCompleted = 50): void
    {
        foreach (['completed', 'failed_terminal'] as $status) {
            $files = glob($this->jobsDir . "/{$status}_job_*.json") ?: [];
            if (count($files) <= $maxCompleted) {
                continue;
            }

            sort($files);
            foreach (array_slice($files, 0, count($files) - $maxCompleted) as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * @param list<string> $statuses
     */
    private function oldestDate(array $statuses): ?string
    {
        $oldest = null;
        foreach ($statuses as $status) {
            foreach (glob($this->jobsDir . '/' . $status . '_job_*.json') ?: [] as $path) {
                $job = $this->readJson($path);
                if (!is_array($job)) {
                    continue;
                }
                $date = (string) ($job['available_at'] ?? $job['created_at'] ?? '');
                if ($date === '') {
                    continue;
                }
                if ($oldest === null || strcmp($date, $oldest) < 0) {
                    $oldest = $date;
                }
            }
        }

        return $oldest;
    }
}
