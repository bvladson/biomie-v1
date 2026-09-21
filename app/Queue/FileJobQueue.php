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
        'failed_retryable',
        'completed',
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
        return $this->withMutationLock(fn (): array => $this->enqueueLocked($queue, $type, $payload, $options));
    }

    private function enqueueLocked(string $queue, string $type, array $payload, array $options): array
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
        $dedupeStatuses = ['queued', 'running', 'failed_retryable'];
        if (!empty($options['dedupe_completed'])) {
            $dedupeStatuses[] = 'completed';
        }
        if ($existing !== null && in_array((string) ($existing['status'] ?? ''), $dedupeStatuses, true)) {
            if (($existing['status'] ?? '') !== 'completed' && !empty($options['coalesce_running'])) {
                $existing['last_requested_at'] = date(DATE_ATOM);
                $existing['updated_at'] = $existing['last_requested_at'];
                if ($existing['status'] === 'running') {
                    $existing['rerun_requested_at'] = $existing['last_requested_at'];
                }
                $this->writeJson($this->jobPath((string) $existing['status'], (string) $existing['queue'], $jobId), $existing);
            }
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
        foreach (['completed', 'failed_terminal', 'cancelled'] as $oldStatus) {
            $oldPath = $this->jobPath($oldStatus, $queue, $jobId);
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }
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
        return $this->withMutationLock(fn (): ?array => $this->claimNextLocked($queues, $limit));
    }

    private function claimNextLocked(array $queues, int $limit): ?array
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
            unset($job['rerun_requested_at']);
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
        $this->withMutationLock(fn () => $this->completeLocked($job, $result));
    }

    private function completeLocked(array $job, array $result): void
    {
        $path = (string) ($job['_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return;
        }

        $job = $this->readJson($path) ?? $job;
        $finishedAt = date(DATE_ATOM);
        if (!empty($job['rerun_requested_at'])) {
            $job['status'] = 'queued';
            $job['attempts'] = 0;
            $job['created_at'] = $job['rerun_requested_at'];
            $job['available_at'] = $finishedAt;
            $job['next_attempt_at'] = $finishedAt;
            $job['updated_at'] = $finishedAt;
            $job['previous_result'] = $result;
            $job['locked_by'] = null;
            $job['locked_at'] = null;
            $job['lock_expires_at'] = null;
            unset($job['_path'], $job['rerun_requested_at'], $job['finished_at'], $job['started_at'],
                $job['reserved_at'], $job['failed_at'], $job['last_error'], $job['error_summary'], $job['result']);
            $this->writeJson($path, $job);
            $this->moveJob($path, $this->jobPath('queued', (string) $job['queue'], (string) $job['job_id']));
            $this->logger?->info('job.completed_and_requeued', [
                'job_id' => $job['job_id'],
                'type' => $job['type'],
                'reason' => 'Novo sinal recebido durante a execução.',
            ]);
            return;
        }
        $job['status'] = 'completed';
        $job['finished_at'] = $finishedAt;
        $job['updated_at'] = $finishedAt;
        $job['result'] = $result;
        unset($job['_path']);
        $target = $this->jobPath('completed', (string) ($job['queue'] ?? 'default'), (string) ($job['job_id'] ?? ''));
        $this->writeJson($path, $job);
        $this->moveJob($path, $target);
        $this->logger?->info('job.completed', [
            'job_id' => $job['job_id'] ?? null,
            'type' => $job['type'] ?? null,
            'attempts' => $job['attempts'] ?? null,
        ]);
        $this->pruneOld();
    }

    public function fail(array $job, string $message, bool $retryable = true): void
    {
        $this->withMutationLock(fn () => $this->failLocked($job, $message, $retryable));
    }

    private function failLocked(array $job, string $message, bool $retryable): void
    {
        $path = (string) ($job['_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return;
        }

        $job = $this->readJson($path) ?? $job;
        $message = SecretMask::sanitizeErrorMessage($message, 500);
        $attempts = (int) ($job['attempts'] ?? 1);
        $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
        $canRetry = $retryable && $attempts < $maxAttempts;
        $status = $canRetry ? 'failed_retryable' : 'failed_terminal';
        $now = date(DATE_ATOM);

        $job['status'] = $status;
        $job['last_error'] = $message;
        // mb_substr, não substr: um corte em byte no meio de um acento gera
        // UTF-8 inválido e json_encode devolve false — o arquivo de estado do
        // job não é gravado e a falha se perde inteira.
        $job['error_summary'] = mb_substr($message, 0, 255);
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
        $this->moveJob($path, $target);
        $this->logger?->error($canRetry ? 'job.failed_retryable' : 'job.failed_terminal', [
            'job_id' => $job['job_id'] ?? null,
            'type' => $job['type'] ?? null,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'message' => $message,
        ]);
    }

    /**
     * Devolve um job à fila sem consumir tentativa. Usado quando outro processo
     * ainda possui um lock operacional; contenção não é falha do payload.
     *
     * @param array<string,mixed> $job
     */
    public function defer(array $job, string $message, int $delaySeconds = 60): void
    {
        $this->withMutationLock(fn () => $this->deferLocked($job, $message, $delaySeconds));
    }

    private function deferLocked(array $job, string $message, int $delaySeconds): void
    {
        $path = (string) ($job['_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return;
        }

        $job = $this->readJson($path) ?? $job;
        $now = date(DATE_ATOM);
        $availableAt = date(DATE_ATOM, time() + max(1, $delaySeconds));
        $message = SecretMask::sanitizeErrorMessage($message, 500);
        $job['status'] = 'queued';
        $job['attempts'] = max(0, (int) ($job['attempts'] ?? 1) - 1);
        $job['last_error'] = $message;
        $job['error_summary'] = mb_substr($message, 0, 255);
        $job['available_at'] = $availableAt;
        $job['next_attempt_at'] = $availableAt;
        $job['updated_at'] = $now;
        $job['locked_by'] = null;
        $job['locked_at'] = null;
        $job['lock_expires_at'] = null;
        unset($job['_path']);

        $target = $this->jobPath('queued', (string) ($job['queue'] ?? 'default'), (string) ($job['job_id'] ?? ''));
        $this->writeJson($path, $job);
        $this->moveJob($path, $target);
        $this->logger?->info('job.deferred', [
            'job_id' => $job['job_id'] ?? null,
            'type' => $job['type'] ?? null,
            'available_at' => $availableAt,
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
        return $this->withMutationLock(fn (): int => $this->releaseExpiredLocksLocked($ttlSeconds));
    }

    private function releaseExpiredLocksLocked(int $ttlSeconds): int
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
            $this->moveJob($path, $target);
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
        return $this->withMutationLock(fn (): ?array => $this->retryLocked($jobId));
    }

    private function retryLocked(string $jobId): ?array
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
                $this->moveJob($path, $target);
                return $this->publicJob($job);
            }
        }

        return null;
    }

    /**
     * Cancela um job ainda pendente sem apagar seu histórico. Se o worker já o
     * tiver reservado, a transição não ocorre e o chamador recebe null.
     *
     * @return array<string,mixed>|null
     */
    public function cancel(string $jobId, string $reason): ?array
    {
        return $this->withMutationLock(fn (): ?array => $this->cancelLocked($jobId, $reason));
    }

    /**
     * Cancela somente se o snapshot observado pelo chamador ainda for o estado
     * pendente atual. Isso protege limpezas contra uma transição concorrente de
     * worker entre a inspeção e a ação.
     *
     * @param array<string,mixed> $expected
     * @return array<string,mixed>|null
     */
    public function cancelIfUnchanged(string $jobId, string $reason, array $expected): ?array
    {
        return $this->withMutationLock(function () use ($jobId, $reason, $expected): ?array {
            $current = $this->find($jobId);
            if ($current === null || $this->jobFingerprint($current) !== $this->jobFingerprint($expected)) {
                return null;
            }

            return $this->cancelLocked($jobId, $reason);
        });
    }

    private function cancelLocked(string $jobId, string $reason): ?array
    {
        $jobId = trim($jobId);
        if (preg_match('/^[a-f0-9]{40}$/D', $jobId) !== 1) {
            return null;
        }

        foreach (['queued', 'failed_retryable'] as $status) {
            foreach (glob($this->jobsDir . "/{$status}_job_*_{$jobId}.json") ?: [] as $path) {
                $job = $this->readJson($path);
                if (!is_array($job)) {
                    continue;
                }

                $now = date(DATE_ATOM);
                $reason = SecretMask::sanitizeErrorMessage($reason, 500);
                $job['status'] = 'cancelled';
                $job['cancel_reason'] = $reason;
                $job['finished_at'] = $now;
                $job['updated_at'] = $now;
                $job['next_attempt_at'] = null;
                $job['locked_by'] = null;
                $job['locked_at'] = null;
                $job['lock_expires_at'] = null;
                unset($job['_path']);

                $target = $this->jobPath(
                    'cancelled',
                    (string) ($job['queue'] ?? 'default'),
                    (string) ($job['job_id'] ?? '')
                );
                $this->writeJson($path, $job);
                $this->moveJob($path, $target);
                $this->logger?->info('job.cancelled', [
                    'job_id' => $job['job_id'] ?? null,
                    'type' => $job['type'] ?? null,
                    'reason' => $reason,
                ]);

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
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) {
            throw new \RuntimeException('Falha ao persistir o estado do job.');
        }

        $this->moveJob($temp, $path);
    }

    private function moveJob(string $source, string $target): void
    {
        if (!rename($source, $target)) {
            throw new \RuntimeException('Falha ao realizar a transição do job.');
        }
    }

    /** O lock cobre apenas operações locais curtas, nunca a execução do job. */
    private function withMutationLock(callable $operation): mixed
    {
        $handle = fopen($this->jobsDir . '/.mutation.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível abrir o lock da fila.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Não foi possível adquirir o lock da fila.');
            }
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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

    /**
     * @param array<string,mixed> $job
     */
    private function jobFingerprint(array $job): string
    {
        unset($job['_path']);
        ksort($job);
        return hash('sha256', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function pruneOld(int $maxCompleted = 50): void
    {
        foreach (['completed', 'failed_terminal'] as $status) {
            $files = glob($this->jobsDir . "/{$status}_job_*.json") ?: [];
            if (count($files) <= $maxCompleted) {
                continue;
            }

            // Conclusões recentes sustentam a deduplicação de inbox. Hashes de
            // nomes não representam idade: preserve 24h e ordene pelo término.
            $dates = [];
            foreach ($files as $path) {
                $job = $this->readJson($path);
                $dates[$path] = strtotime((string) ($job['finished_at'] ?? $job['updated_at'] ?? '')) ?: time();
            }
            usort($files, static fn (string $left, string $right): int => $dates[$left] <=> $dates[$right]);
            foreach (array_slice($files, 0, count($files) - $maxCompleted) as $path) {
                if ($dates[$path] < time() - 86400) {
                    @unlink($path);
                }
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
