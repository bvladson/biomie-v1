<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\Logger;

/**
 * Caminha uma janela dia a dia e interrompe imediatamente em bloqueios da Omie.
 *
 * O cooldown persistente do OmieRateLimiter controla quando a operacao pode ser
 * reagendada. Repetir a mesma consulta dentro deste loop prolonga o bloqueio do
 * provedor e, por isso, nao e permitido.
 */
final class ChunkedWindowRunner
{
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
        $current = strtotime($start);
        $last = strtotime($end);
        $daysProcessed = 0;
        $results = [];

        while ($current <= $last) {
            $day = date('Y-m-d', $current);

            try {
                $results[] = ($dayProcessor)($day, $day);
            } catch (\RuntimeException $exception) {
                $normalized = mb_strtolower($exception->getMessage());
                if (
                    str_contains($normalized, 'redundant')
                    || str_contains($normalized, 'consumo redundante')
                    || str_contains($normalized, 'api bloqueada')
                ) {
                    $this->logger->warning(
                        'Bloqueio de consumo da Omie; janela interrompida para reagendamento seguro.',
                        ['day' => $day]
                    );
                }
                throw $exception;
            }

            $daysProcessed++;
            $current = strtotime('+1 day', $current);
        }

        return [
            'days_processed' => $daysProcessed,
            'results' => $results,
        ];
    }
}
