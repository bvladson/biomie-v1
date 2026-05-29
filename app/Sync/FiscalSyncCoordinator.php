<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\CanonicalRepository;
use BIomie\FiscalSync\FiscalCanonicalMapper;
use BIomie\FiscalSync\FiscalDivergenceDetector;
use BIomie\FiscalSync\FiscalPartyResolver;
use BIomie\FiscalSync\FiscalSupport;
use BIomie\Logger;
use BIomie\OmieRepository;
use BIomie\Support;
use BIomie\Support\OmieVendorStatusMapper;

/**
 * Coordenador de sincronização do domínio fiscal (NF-e + carteira de pedidos).
 *
 * Substitui OmieFiscalSyncService como implementação canônica.
 * Usa ChunkedWindowRunner para os loops diários de notas e carteira,
 * eliminando o boilerplate de checkpoint copy-paste.
 */
final class FiscalSyncCoordinator
{
    /** @var array<string,string> */
    private array $vendorMap = [];
    private ?FiscalSupport $support = null;
    private ?FiscalPartyResolver $partyResolver = null;
    private ?FiscalCanonicalMapper $canonicalMapper = null;
    private ?FiscalDivergenceDetector $divergenceDetector = null;

    public function __construct(
        private readonly OmieRepository $omie,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
        private readonly ChunkedWindowRunner $chunker,
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
        $t0 = microtime(true);
        $this->canonical->ensureFiscalSchema();

        $this->logger->info('FiscalSyncCoordinator: sincronização iniciada.', [
            'start'  => $start,
            'end'    => $end,
            'source' => $source,
        ]);

        $vendorCount   = $this->syncVendors();
        $invoiceResult = $this->syncInvoices($start, $end, $source);
        $pendingResult = $this->syncPendingOrders($start, $end, $source);
        $duration      = round(microtime(true) - $t0, 2);

        $this->logger->info('FiscalSyncCoordinator: sincronização finalizada.', [
            'duration_seconds' => $duration,
            'vendors'          => $vendorCount,
            'invoices'         => $invoiceResult['stats'],
            'pending_orders'   => $pendingResult['stats'],
            'divergences'      => $invoiceResult['divergences'] + $pendingResult['divergences'],
        ]);

        return [
            'vendors_synced'         => $vendorCount,
            'invoices'               => $invoiceResult['stats'],
            'invoices_checked'       => $this->support()->sumStats($invoiceResult['stats']),
            'pending_orders'         => $pendingResult['stats'],
            'orders'                 => $pendingResult['stats'],
            'pending_orders_checked' => $this->support()->sumStats($pendingResult['stats']),
            'orders_checked'         => $this->support()->sumStats($pendingResult['stats']),
            'divergences'            => $invoiceResult['divergences'] + $pendingResult['divergences'],
            'duration_seconds'       => $duration,
        ];
    }

    public function syncVendors(): int
    {
        $vendors = $this->omie->listVendors();
        $count   = 0;
        $statusMapper = new OmieVendorStatusMapper();

        $this->vendorMap = [];
        foreach ($vendors as $vendor) {
            if (!is_array($vendor)) {
                continue;
            }

            $codigo = trim((string) ($vendor['codigo'] ?? ''));
            $nome   = trim((string) ($vendor['nome'] ?? ''));
            if ($codigo === '' || $nome === '') {
                continue;
            }

            $email    = trim((string) ($vendor['email'] ?? ''));
            $comissao = Support::toFloat($vendor['comissao'] ?? 0);
            $ativo    = $statusMapper->isActive($vendor, true);

            $this->canonical->upsertVendor($codigo, $nome, $email, $comissao, $ativo);
            $this->vendorMap[$codigo] = $nome;
            $count++;
        }

        $this->logger->info('Vendedores sincronizados (fiscal).', ['total' => $count]);

        return $count;
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function syncInvoices(string $start, string $end, string $source): array
    {
        $stats       = $this->support()->emptyStats();
        $divergences = 0;

        $run = $this->chunker->walk($start, $end, function (string $day) use ($source): array {
            return $this->processInvoiceDay($day, $source);
        });

        foreach ($run['results'] as $dayResult) {
            $stats       = $this->support()->mergeStats($stats, (array) ($dayResult['stats'] ?? []));
            $divergences += (int) ($dayResult['divergences'] ?? 0);
        }

        return ['stats' => $stats, 'divergences' => $divergences];
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function syncPendingOrders(string $start, string $end, string $source): array
    {
        $stats       = $this->support()->emptyStats();
        $divergences = 0;

        $run = $this->chunker->walk($start, $end, function (string $day) use ($source): array {
            return $this->processPendingOrderDay($day, $source);
        });

        foreach ($run['results'] as $dayResult) {
            $stats       = $this->support()->mergeStats($stats, (array) ($dayResult['stats'] ?? []));
            $divergences += (int) ($dayResult['divergences'] ?? 0);
        }

        return ['stats' => $stats, 'divergences' => $divergences];
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function processInvoiceDay(string $day, string $source): array
    {
        $normalized  = [];
        $divergences = 0;
        $orderFlags  = $this->support()->buildOrderFlagsMap($this->omie->listPendingOrders($day, $day));

        foreach (['N', 'C'] as $status) {
            $rawInvoices = $this->omie->listInvoices($day, $day, $status);
            $cancelled   = $status === 'C';

            foreach ($rawInvoices as $rawInvoice) {
                if (!is_array($rawInvoice)) {
                    continue;
                }

                $canonical = $this->canonicalMapper()->normalizeInvoiceToCanonical(
                    $rawInvoice,
                    $cancelled,
                    $source,
                    $this->vendorMap,
                    $orderFlags
                );
                if ($canonical === null) {
                    continue;
                }

                $divergences += $this->divergenceDetector()->detectInvoiceDivergences($canonical, $this->vendorMap);
                $normalized[] = $canonical;
            }
        }

        $stats = $this->canonical->replaceInvoicesWindow($day, $day, $normalized);

        return ['stats' => $stats, 'divergences' => $divergences];
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function processPendingOrderDay(string $day, string $source): array
    {
        $rawOrders   = $this->omie->listPendingOrders($day, $day);
        $clientMap   = $this->buildClientMap($rawOrders);
        $normalized  = [];
        $divergences = 0;

        foreach ($rawOrders as $rawOrder) {
            if (!is_array($rawOrder)) {
                continue;
            }

            $canonical = $this->canonicalMapper()->normalizePendingOrderToCanonical(
                $rawOrder,
                $clientMap,
                $source,
                $this->vendorMap
            );
            if ($canonical === null) {
                continue;
            }

            $divergences += $this->divergenceDetector()->detectOrderDivergences($canonical, $this->vendorMap);
            $normalized[] = $canonical;
        }

        $stats = $this->canonical->replacePendingOrdersWindow($day, $day, $normalized);

        return ['stats' => $stats, 'divergences' => $divergences];
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @return array<string,array<string,mixed>>
     */
    private function buildClientMap(array $orders): array
    {
        $codes = $this->partyResolver()->collectClientCodes($orders);

        return $codes === [] ? [] : $this->omie->listClientsSummaryByCodes($codes);
    }

    private function support(): FiscalSupport
    {
        return $this->support ??= new FiscalSupport();
    }

    private function partyResolver(): FiscalPartyResolver
    {
        return $this->partyResolver ??= new FiscalPartyResolver();
    }

    private function canonicalMapper(): FiscalCanonicalMapper
    {
        return $this->canonicalMapper ??= new FiscalCanonicalMapper($this->support(), $this->partyResolver());
    }

    private function divergenceDetector(): FiscalDivergenceDetector
    {
        return $this->divergenceDetector ??= new FiscalDivergenceDetector($this->canonical);
    }
}
