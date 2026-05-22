<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold;

use BIomie\Logger;
use PDO;
use Throwable;

final class MartTransactionRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @param callable():array<string,mixed> $callback
     * @return array<string,mixed>
     */
    public function run(string $operation, array $context, callable $callback): array
    {
        $startedAt = microtime(true);
        $startedTransaction = false;

        $this->logger->info('MartTransactionRunner: inicio.', [
            'operation' => $operation,
            'context' => $context,
        ]);

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $result = $callback();

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
            $this->logger->info('MartTransactionRunner: commit.', [
                'operation' => $operation,
                'duration_ms' => $durationMs,
            ]);

            return [
                'ok' => true,
                'operation' => $operation,
                'duration_ms' => $durationMs,
                'result' => $result,
            ];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
            $this->logger->error('MartTransactionRunner: rollback.', [
                'operation' => $operation,
                'duration_ms' => $durationMs,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
