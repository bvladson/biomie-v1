<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Sync\FinanceSyncCoordinator;

/**
 * Facade de sincronização financeira (títulos a receber/pagar).
 *
 * Delega toda a lógica ao FinanceSyncCoordinator.
 * Mantido para compatibilidade com injeções existentes
 * (OmieRealtimeSyncService, ReplaySyncCoordinator).
 */
final class OmieFinanceCanonicalSyncService
{
    private ?FinanceSyncCoordinator $coordinator = null;

    public function __construct(
        private readonly FinanceRepository $finance,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{
     *   titles:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *   titles_checked:int,
     *   divergences:int,
     *   duration_seconds:float
     * }
     */
    public function syncPeriod(string $start, string $end, string $source = 'cron'): array
    {
        return $this->coordinator()->syncPeriod($start, $end, $source);
    }

    private function coordinator(): FinanceSyncCoordinator
    {
        return $this->coordinator ??= new FinanceSyncCoordinator(
            $this->finance,
            $this->canonical,
            $this->logger,
        );
    }
}
