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
    private const BULK_WINDOW_DAYS = 31;

    /** @var array<string,string> */
    private array $vendorMap = [];
    private ?FiscalSupport $support = null;
    private ?FiscalPartyResolver $partyResolver = null;
    private ?FiscalCanonicalMapper $canonicalMapper = null;
    private ?FiscalDivergenceDetector $divergenceDetector = null;
    /** @var array<string,list<array<string,mixed>>> */
    private array $pendingOrdersCache = [];

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

    /**
     * Historical NF-only recovery entry point.
     *
     * Deliberately excludes pending-order replacement and order-stage writes.
     * This keeps the `omie:nfe:backfill` command bounded to the fiscal entity
     * named by the operator while still loading the vendor/order references
     * needed to normalize invoice identities and authorization safely.
     *
     * @return array{
     *   vendors_synced:int,
     *   invoices:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *   invoices_checked:int,
     *   divergences:int,
     *   duration_seconds:float
     * }
     */
    public function backfillInvoicesOnly(string $start, string $end, string $source = 'cron-backfill'): array
    {
        if (!$this->isBackfillSource($source)) {
            throw new \InvalidArgumentException('A recuperacao exclusiva de NF exige uma origem de backfill.');
        }

        $t0 = microtime(true);
        $this->canonical->ensureFiscalSchema();
        $vendorsSynced = $this->syncVendors();
        $result = $this->syncInvoices($start, $end, $source);
        $duration = round(microtime(true) - $t0, 2);

        $this->logger->info('FiscalSyncCoordinator: backfill exclusivo de NF finalizado.', [
            'start' => $start,
            'end' => $end,
            'source' => $source,
            'duration_seconds' => $duration,
            'vendors' => $vendorsSynced,
            'invoices' => $result['stats'],
            'divergences' => $result['divergences'],
        ]);

        return [
            'vendors_synced' => $vendorsSynced,
            'invoices' => $result['stats'],
            'invoices_checked' => $this->support()->sumStats($result['stats']),
            'divergences' => $result['divergences'],
            'duration_seconds' => $duration,
        ];
    }

    public function syncVendors(): int
    {
        $localMap = $this->canonical->getVendorMap();
        $lastLocalSync = 0;
        foreach ($localMap as $vendor) {
            $lastLocalSync = max(
                $lastLocalSync,
                strtotime($vendor['sincronizado_em']) ?: 0
            );
        }

        if ($localMap !== [] && $lastLocalSync >= time() - 86400) {
            $this->vendorMap = [];
            foreach ($localMap as $codigo => $vendor) {
                $this->vendorMap[(string) $codigo] = $vendor['nome'];
            }
            $this->logger->info('Cadastro local recente de vendedores reutilizado.', [
                'total' => count($this->vendorMap),
                'last_sync_at' => date('Y-m-d H:i:s', $lastLocalSync),
            ]);
            return count($this->vendorMap);
        }

        try {
            $vendors = $this->omie->listVendors();
        } catch (\Throwable $exception) {
            if ($localMap === []) {
                throw $exception;
            }

            $this->vendorMap = [];
            foreach ($localMap as $codigo => $vendor) {
                $this->vendorMap[(string) $codigo] = $vendor['nome'];
            }
            $this->logger->warning('Omie indisponivel para vendedores; usando cadastro local existente.', [
                'total' => count($this->vendorMap),
                'message' => $exception->getMessage(),
            ]);
            return count($this->vendorMap);
        }

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
        if ($start !== $end) {
            $stats = $this->support()->emptyStats();
            $divergences = 0;
            // Historical recovery is deliberately day-scoped. Besides making
            // checkpoints resumable, this keeps the Omie page-count invariant
            // small enough that a single bad response cannot poison a month.
            $windowDays = $this->isBackfillSource($source) ? 1 : self::BULK_WINDOW_DAYS;
            foreach ($this->windowRanges($start, $end, $windowDays) as [$windowStart, $windowEnd]) {
                $result = $this->processInvoiceWindow($windowStart, $windowEnd, $source);
                $stats = $this->support()->mergeStats($stats, $result['stats']);
                $divergences += $result['divergences'];
            }
            return ['stats' => $stats, 'divergences' => $divergences];
        }

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
        if ($start !== $end) {
            $stats = $this->support()->emptyStats();
            $divergences = 0;
            foreach ($this->windowRanges($start, $end) as [$windowStart, $windowEnd]) {
                $result = $this->processPendingOrderWindow($windowStart, $windowEnd, $source);
                $stats = $this->support()->mergeStats($stats, $result['stats']);
                $divergences += $result['divergences'];
            }
            return ['stats' => $stats, 'divergences' => $divergences];
        }

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
        return $this->processInvoiceWindow($day, $day, $source);
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function processInvoiceWindow(string $start, string $end, string $source): array
    {
        $normalized  = [];
        $divergences = 0;
        $isBackfill  = $this->isBackfillSource($source);

        // ListarNF already separates authorized and cancelled documents in a
        // historical recovery. Looking up the pending-order endpoint for every
        // day adds an unrelated API traversal (and can never reliably describe
        // an invoice that was billed months ago). Keep the normal operational
        // enrichment, but make the historical command truly NF-only in both
        // mutation and upstream consumption.
        $orderFlags = $isBackfill
            ? []
            : $this->support()->buildOrderFlagsMap($this->pendingOrders($start, $end));

        foreach (['N', 'C'] as $status) {
            // The operational cancellation pass remains based on cancellation
            // date. A historical backfill must reconcile both statuses against
            // the same issue-date window, otherwise old cancellations disappear
            // from the day/month where the invoice was originally emitted.
            $rawInvoices = $isBackfill
                ? $this->omie->listInvoicesByIssueDate($start, $end, $status)
                : $this->omie->listInvoices($start, $end, $status);
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

        // Nunca substitua destrutivamente uma janela fiscal. O passe N usa
        // emissao, enquanto o passe C operacional usa cancelamento; apagar por
        // data_competencia (sempre emissao) faria uma NF cancelada historica
        // desaparecer ao reprocessar o mes de origem. A chave natural torna o
        // lote idempotente e o passe C atualiza o mesmo documento por upsert.
        $stats = $this->canonical->upsertInvoicesBatch($normalized);

        return ['stats' => $stats, 'divergences' => $divergences];
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function processPendingOrderDay(string $day, string $source): array
    {
        return $this->processPendingOrderWindow($day, $day, $source);
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function processPendingOrderWindow(string $start, string $end, string $source): array
    {
        $rawOrders   = $this->pendingOrders($start, $end);
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

        $stats = $this->canonical->replacePendingOrdersWindow($start, $end, $normalized);

        return ['stats' => $stats, 'divergences' => $divergences];
    }

    /** @return list<array<string,mixed>> */
    private function pendingOrders(string $start, string $end): array
    {
        $key = $start . ':' . $end;
        return $this->pendingOrdersCache[$key]
            ??= $this->omie->listPendingOrders($start, $end);
    }

    /** @return list<array{0:string,1:string}> */
    private function windowRanges(string $start, string $end, int $days = self::BULK_WINDOW_DAYS): array
    {
        $ranges = [];
        $current = strtotime($start);
        $last = strtotime($end);

        while ($current <= $last) {
            $windowEnd = min(strtotime('+' . ($days - 1) . ' days', $current), $last);
            $ranges[] = [date('Y-m-d', $current), date('Y-m-d', $windowEnd)];
            $current = strtotime('+1 day', $windowEnd);
        }

        return $ranges;
    }

    private function isBackfillSource(string $source): bool
    {
        return str_contains(strtolower(trim($source)), 'backfill');
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
