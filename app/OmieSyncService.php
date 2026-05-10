<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Sync\ChunkedWindowRunner;
use BIomie\Sync\CommercialSyncCoordinator;

/**
 * Facade de sincronização comercial (vendedores + pedidos).
 *
 * Delega toda a lógica ao CommercialSyncCoordinator.
 * Mantido para compatibilidade com injeções existentes.
 */
final class OmieSyncService
{
    private ?CommercialSyncCoordinator $coordinator = null;

    public function __construct(
        private readonly Config $config,
        private readonly OmieRepository $omie,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{
     *     vendors_synced: int,
     *     orders: array{inserted: int, updated: int, unchanged: int, skipped: int},
     *     orders_checked: int,
     *     divergences: int,
     *     duration_seconds: float
     * }
     */
    public function syncPeriod(string $start, string $end, string $fonte = 'cron'): array
    {
        return $this->coordinator()->syncPeriod($start, $end, $fonte);
    }

    public function syncVendors(): int
    {
        return $this->coordinator()->syncVendors();
    }

    private function coordinator(): CommercialSyncCoordinator
    {
        return $this->coordinator ??= new CommercialSyncCoordinator(
            $this->omie,
            $this->canonical,
            $this->logger,
            new ChunkedWindowRunner($this->logger),
        );
    }
}
