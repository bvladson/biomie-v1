<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\SyncPipelineRepository;
use BIomie\Support\DatabaseErrorEnvelope;
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
     * @param array<string,mixed> $context
     */
    public function finishFailure(int $runId, Throwable|string $error, array $result = [], array $context = []): void
    {
        [$message, $result] = $this->describe($error, $result, $runId, $context);
        $this->finish($runId, 'failed_retryable', $result, $message);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $context
     */
    public function finishTerminal(int $runId, Throwable|string $error, array $result = [], array $context = []): void
    {
        [$message, $result] = $this->describe($error, $result, $runId, $context);
        $this->finish($runId, 'failed_terminal', $result, $message);
    }

    /**
     * P2/Etapa 1 — de uma exceção sai o resumo `[SQLSTATE/code] msg` e o
     * detalhe estruturado em `result.error_detail`. Sem isso, deadlock,
     * timeout de lock, conexão perdida e truncamento de coluna gravavam
     * todos a mesma frase de seis palavras.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $context
     * @return array{0:string,1:array<string,mixed>}
     */
    private function describe(Throwable|string $error, array $result, int $runId, array $context): array
    {
        if (!$error instanceof Throwable) {
            return [SecretMask::sanitizeErrorMessage($error), $result];
        }

        $captured = DatabaseErrorEnvelope::capture($error, ['run_id' => $runId] + $context);
        $result['error_detail'] = $captured['detail'];

        return [$captured['summary'], $result];
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
