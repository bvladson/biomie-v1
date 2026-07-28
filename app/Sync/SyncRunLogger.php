<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\SyncPipelineRepository;
use BIomie\Support\SecretMask;
use Throwable;

/**
 * Thin wrapper over SyncPipelineRepository for structured sync run logging.
 * All error messages are sanitized via SecretMask before persisting.
 * Never throws — failures are silently swallowed so sync logic is not interrupted.
 */
final class SyncRunLogger
{
    public function __construct(private readonly ?SyncPipelineRepository $repo) {}

    /**
     * Open a sync run record and return its run ID (0 if unavailable).
     *
     * @param array<string,mixed> $context
     */
    public function start(
        string $runType,
        string $domain,
        string $endpointGroup = 'all',
        array $context = [],
    ): int {
        if ($this->repo === null) {
            return 0;
        }
        try {
            return $this->repo->startRun($runType, $domain, $endpointGroup, $context);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    public function finishSuccess(int $runId, array $result = []): void
    {
        $this->finish($runId, 'completed', $result, null);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function finishPartial(int $runId, array $result = [], ?string $warning = null): void
    {
        $this->finish($runId, 'completed_with_warnings', $result, $warning);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function finishFailure(int $runId, Throwable|string $error, array $result = []): void
    {
        $message = $error instanceof Throwable
            ? SecretMask::sanitizeErrorMessage($error->getMessage())
            : SecretMask::sanitizeErrorMessage($error);
        $this->finish($runId, 'failed_retryable', $result, $message);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function finishTerminal(int $runId, Throwable|string $error, array $result = []): void
    {
        $message = $error instanceof Throwable
            ? SecretMask::sanitizeErrorMessage($error->getMessage())
            : SecretMask::sanitizeErrorMessage($error);
        $this->finish($runId, 'failed_terminal', $result, $message);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function finish(int $runId, string $status, array $result, ?string $error): void
    {
        if ($this->repo === null || $runId <= 0) {
            return;
        }
        try {
            $this->repo->finishRun($runId, $status, $result, $error);
        } catch (Throwable) {
            // Never throw from logger — sync logic must not be interrupted by logging failures
        }
    }
}
