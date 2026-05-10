<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Sync\ChunkedWindowRunner;
use BIomie\Sync\FiscalSyncCoordinator;

/**
 * Facade de sincronização fiscal (NF-e + carteira de pedidos + vendedores).
 *
 * Delega toda a lógica ao FiscalSyncCoordinator.
 * Mantido para compatibilidade com injeções existentes
 * (OmieRealtimeSyncService, ReplaySyncCoordinator).
 */
final class OmieFiscalSyncService
{
    private ?FiscalSyncCoordinator $coordinator = null;

    public function __construct(
        private readonly Config $config,
        private readonly OmieRepository $omie,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{
     *   vendors_synced:int,
     *   invoices:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *   invoices_checked:int,
     *   pending_orders:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *   orders:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *   pending_orders_checked:int,
     *   orders_checked:int,
     *   divergences:int,
     *   duration_seconds:float
     * }
     */
    public function syncPeriod(string $start, string $end, string $source = 'cron'): array
    {
        return $this->coordinator()->syncPeriod($start, $end, $source);
    }

    public function syncVendors(): int
    {
        return $this->coordinator()->syncVendors();
    }

    private function coordinator(): FiscalSyncCoordinator
    {
        return $this->coordinator ??= new FiscalSyncCoordinator(
            $this->omie,
            $this->canonical,
            $this->logger,
            new ChunkedWindowRunner($this->logger),
        );
    }
}
