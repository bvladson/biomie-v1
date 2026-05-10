<?php

declare(strict_types=1);

namespace BIomie\Queue;

interface JobQueueInterface
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function enqueue(string $queue, string $type, array $payload = [], array $options = []): array;

    /**
     * @param list<string> $queues
     * @return array<string,mixed>|null
     */
    public function claimNext(array $queues = [], int $limit = 1): ?array;

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $result
     */
    public function complete(array $job, array $result = []): void;

    /**
     * @param array<string,mixed> $job
     */
    public function fail(array $job, string $message, bool $retryable = true): void;

    /**
     * @return array<string,mixed>
     */
    public function summary(): array;

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $jobId): ?array;
}
