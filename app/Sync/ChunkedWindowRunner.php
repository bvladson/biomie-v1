<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\Logger;

/**
 * Loop diário com backoff em REDUNDANT.
 *
 * Caminha um intervalo de datas dia a dia chamando $dayProcessor para cada dia.
 * Gerenciamento de checkpoint (legado ou cursor) é responsabilidade do chamador.
 */
final class ChunkedWindowRunner
{
    private const MAX_RETRIES = 3;
    private const BACKOFF_SECONDS = 10;

    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param \Closure(string $dayStart, string $dayEnd): array<string,mixed> $dayProcessor
     * @return array{days_processed:int, results:list<array<string,mixed>>}
     */
    public function walk(string $start, string $end, \Closure $dayProcessor): array
    {
        $current       = strtotime($start);
        $last          = strtotime($end);
        $daysProcessed = 0;
        $results       = [];

        while ($current <= $last) {
            $day      = date('Y-m-d', $current);
            $attempts = 0;
            $success  = false;

            while (!$success && $attempts < self::MAX_RETRIES) {
                try {
                    $results[] = ($dayProcessor)($day, $day);
                    $success   = true;
                } catch (\RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'REDUNDANT')) {
                        $this->logger->warning('Rate limit (REDUNDANT) — aplicando backoff.', [
                            'day'     => $day,
                            'attempt' => $attempts + 1,
                        ]);
                        if (PHP_SAPI !== 'cli') {
                            $this->logger->warning('Backoff de janela Omie suprimido fora de CLI; use fila/worker.', [
                                'day' => $day,
                                'attempt' => $attempts + 1,
                            ]);
                            throw $e;
                        }

                        sleep(self::BACKOFF_SECONDS);
                        $attempts++;
                        continue;
                    }
                    throw $e;
                }
            }

            if (!$success) {
                throw new \RuntimeException(
                    sprintf('Chunked walk abortado por excesso de falhas REDUNDANT em %s', $day)
                );
            }

            $daysProcessed++;
            $current = strtotime('+1 day', $current);
        }

        return [
            'days_processed' => $daysProcessed,
            'results'        => $results,
        ];
    }
}
