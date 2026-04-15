<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\FiscalSync\FiscalCanonicalMapper;
use BIomie\FiscalSync\FiscalDivergenceDetector;
use BIomie\FiscalSync\FiscalPartyResolver;
use BIomie\FiscalSync\FiscalSupport;

final class OmieFiscalSyncService
{
    private const INVOICE_SYNC_NAME = 'fiscal_invoices_sync';
    private const ORDER_SYNC_NAME = 'pending_orders_sync';
    private const STATE_PHASE = 'chunked';

    /** @var array<string,string> */
    private array $vendorMap = [];
    private ?FiscalSupport $support = null;
    private ?FiscalPartyResolver $partyResolver = null;
    private ?FiscalCanonicalMapper $canonicalMapper = null;
    private ?FiscalDivergenceDetector $divergenceDetector = null;

    public function __construct(
        private readonly Config $config,
        private readonly OmieRepository $omie,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{
     *     vendors_synced:int,
     *     invoices:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *     invoices_checked:int,
     *     pending_orders:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *     orders:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *     pending_orders_checked:int,
     *     orders_checked:int,
     *     divergences:int,
     *     duration_seconds:float
     * }
     */
    public function syncPeriod(string $start, string $end, string $source = 'cron'): array
    {
        $t0 = microtime(true);
        $this->canonical->ensureFiscalSchema();

        $this->logger->info('Sync canonico fiscal iniciado.', [
            'start' => $start,
            'end' => $end,
            'source' => $source,
        ]);

        $vendorCount = $this->syncVendors();
        $invoiceResult = $this->syncInvoicesChunked($start, $end, $source, $vendorCount);
        $pendingResult = $this->syncPendingOrdersChunked($start, $end, $source, $vendorCount);
        $duration = round(microtime(true) - $t0, 2);

        $this->logger->info('Sync canonico fiscal finalizado.', [
            'duration_seconds' => $duration,
            'vendors' => $vendorCount,
            'invoices' => $invoiceResult['stats'],
            'pending_orders' => $pendingResult['stats'],
            'divergences' => $invoiceResult['divergences'] + $pendingResult['divergences'],
        ]);

        return [
            'vendors_synced' => $vendorCount,
            'invoices' => $invoiceResult['stats'],
            'invoices_checked' => $this->support()->sumStats($invoiceResult['stats']),
            'pending_orders' => $pendingResult['stats'],
            'orders' => $pendingResult['stats'],
            'pending_orders_checked' => $this->support()->sumStats($pendingResult['stats']),
            'orders_checked' => $this->support()->sumStats($pendingResult['stats']),
            'divergences' => $invoiceResult['divergences'] + $pendingResult['divergences'],
            'duration_seconds' => $duration,
        ];
    }

    public function syncVendors(): int
    {
        $vendors = $this->omie->listVendors();
        $count = 0;

        $this->vendorMap = [];
        foreach ($vendors as $vendor) {
            if (!is_array($vendor)) {
                continue;
            }

            $codigo = trim((string) ($vendor['codigo'] ?? ''));
            $nome = trim((string) ($vendor['nome'] ?? ''));
            if ($codigo === '' || $nome === '') {
                continue;
            }

            $email = trim((string) ($vendor['email'] ?? ''));
            $comissao = Support::toFloat($vendor['comissao'] ?? 0);

            $this->canonical->upsertVendor($codigo, $nome, $email, $comissao);
            $this->vendorMap[$codigo] = $nome;
            $count++;
        }

        $this->logger->info('Vendedores sincronizados para a camada fiscal.', ['total' => $count]);

        return $count;
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function syncInvoicesChunked(string $start, string $end, string $source, int $vendorCount): array
    {
        $stats = $this->support()->emptyStats();
        $divergences = 0;
        $state = $this->canonical->prepareSyncState(
            $start,
            $end,
            $source,
            self::INVOICE_SYNC_NAME,
            self::STATE_PHASE
        );
        $this->canonical->markVendorsSynced(
            $start,
            $end,
            $vendorCount,
            $source,
            self::INVOICE_SYNC_NAME,
            self::STATE_PHASE
        );

        $current = strtotime($start);
        $last = strtotime($end);
        if ($state && !empty($state['checkpoint_date'])) {
            $checkpointTs = strtotime((string) $state['checkpoint_date']);
            if ($checkpointTs >= $current) {
                $current = $checkpointTs;
            }
        }

        while ($current <= $last) {
            $day = date('Y-m-d', $current);
            $checkpointPage = 1;
            $checkpointBatch = 0;

            $this->canonical->advanceOrdersCheckpoint(
                $start,
                $end,
                $day,
                $checkpointPage,
                $checkpointBatch,
                $source,
                $vendorCount,
                $stats,
                self::INVOICE_SYNC_NAME,
                self::STATE_PHASE
            );

            $attempts = 0;
            $success = false;
            while (!$success && $attempts < 3) {
                try {
                    $dayResult = $this->processInvoiceDay($day, $source);
                    $stats = $this->support()->mergeStats($stats, $dayResult['stats']);
                    $divergences += $dayResult['divergences'];
                    $checkpointBatch = $dayResult['checkpoint_batch'];

                    $nextDay = date('Y-m-d', strtotime('+1 day', $current));
                    if (strtotime($nextDay) <= $last) {
                        $this->canonical->advanceOrdersCheckpoint(
                            $start,
                            $end,
                            $nextDay,
                            1,
                            0,
                            $source,
                            $vendorCount,
                            $stats,
                            self::INVOICE_SYNC_NAME,
                            self::STATE_PHASE
                        );
                    }

                    $success = true;
                } catch (\RuntimeException $exception) {
                    if (str_contains($exception->getMessage(), 'REDUNDANT')) {
                        $this->logger->warning('Rate limit ao sincronizar notas fiscais; aplicando backoff.', [
                            'day' => $day,
                            'attempt' => $attempts + 1,
                        ]);
                        sleep(10);
                        $attempts++;
                        continue;
                    }

                    $this->canonical->markSyncFailed(
                        $start,
                        $end,
                        $exception->getMessage(),
                        $source,
                        $vendorCount,
                        $stats,
                        $day,
                        $checkpointPage,
                        $checkpointBatch,
                        null,
                        self::INVOICE_SYNC_NAME,
                        self::STATE_PHASE
                    );
                    throw $exception;
                } catch (\Throwable $exception) {
                    $this->canonical->markSyncFailed(
                        $start,
                        $end,
                        $exception->getMessage(),
                        $source,
                        $vendorCount,
                        $stats,
                        $day,
                        $checkpointPage,
                        $checkpointBatch,
                        null,
                        self::INVOICE_SYNC_NAME,
                        self::STATE_PHASE
                    );
                    throw $exception;
                }
            }

            if (!$success) {
                $this->canonical->markSyncFailed(
                    $start,
                    $end,
                    'Falha recorrente na sincronizacao fiscal.',
                    $source,
                    $vendorCount,
                    $stats,
                    $day,
                    $checkpointPage,
                    $checkpointBatch,
                    date('Y-m-d H:i:s', time() + 10),
                    self::INVOICE_SYNC_NAME,
                    self::STATE_PHASE
                );
                throw new \RuntimeException('Falha recorrente ao sincronizar notas fiscais em ' . $day);
            }

            $current = strtotime('+1 day', $current);
        }

        $this->canonical->markSyncCompleted(
            $start,
            $end,
            $source,
            $vendorCount,
            $stats,
            $end,
            1,
            0,
            self::INVOICE_SYNC_NAME,
            self::STATE_PHASE
        );

        return [
            'stats' => $stats,
            'divergences' => $divergences,
        ];
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int,checkpoint_batch:int}
     */
    private function processInvoiceDay(string $day, string $source): array
    {
        $normalized = [];
        $divergences = 0;
        $checkpointBatch = 0;
        $orderFlags = $this->buildOrderFlagsMap($this->omie->listPendingOrders($day, $day));

        foreach (['N', 'C'] as $status) {
            $rawInvoices = $this->omie->listInvoices($day, $day, $status);
            $cancelled = $status === 'C';

            foreach ($rawInvoices as $rawInvoice) {
                if (!is_array($rawInvoice)) {
                    continue;
                }

                $canonical = $this->normalizeInvoiceToCanonical($rawInvoice, $cancelled, $source, $orderFlags);
                if ($canonical === null) {
                    continue;
                }

                $divergences += $this->detectInvoiceDivergences($canonical);
                $normalized[] = $canonical;
            }

            $checkpointBatch++;
        }

        $stats = $this->canonical->replaceInvoicesWindow($day, $day, $normalized);

        return [
            'stats' => $stats,
            'divergences' => $divergences,
            'checkpoint_batch' => $checkpointBatch,
        ];
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function syncPendingOrdersChunked(string $start, string $end, string $source, int $vendorCount): array
    {
        $stats = $this->support()->emptyStats();
        $divergences = 0;
        $state = $this->canonical->prepareSyncState(
            $start,
            $end,
            $source,
            self::ORDER_SYNC_NAME,
            self::STATE_PHASE
        );
        $this->canonical->markVendorsSynced(
            $start,
            $end,
            $vendorCount,
            $source,
            self::ORDER_SYNC_NAME,
            self::STATE_PHASE
        );

        $current = strtotime($start);
        $last = strtotime($end);
        if ($state && !empty($state['checkpoint_date'])) {
            $checkpointTs = strtotime((string) $state['checkpoint_date']);
            if ($checkpointTs >= $current) {
                $current = $checkpointTs;
            }
        }

        while ($current <= $last) {
            $day = date('Y-m-d', $current);
            $checkpointPage = 1;
            $checkpointBatch = 0;

            $this->canonical->advanceOrdersCheckpoint(
                $start,
                $end,
                $day,
                $checkpointPage,
                $checkpointBatch,
                $source,
                $vendorCount,
                $stats,
                self::ORDER_SYNC_NAME,
                self::STATE_PHASE
            );

            $attempts = 0;
            $success = false;
            while (!$success && $attempts < 3) {
                try {
                    $dayResult = $this->processPendingOrderDay($day, $source);
                    $stats = $this->support()->mergeStats($stats, $dayResult['stats']);
                    $divergences += $dayResult['divergences'];
                    $checkpointBatch = $dayResult['checkpoint_batch'];

                    $nextDay = date('Y-m-d', strtotime('+1 day', $current));
                    if (strtotime($nextDay) <= $last) {
                        $this->canonical->advanceOrdersCheckpoint(
                            $start,
                            $end,
                            $nextDay,
                            1,
                            0,
                            $source,
                            $vendorCount,
                            $stats,
                            self::ORDER_SYNC_NAME,
                            self::STATE_PHASE
                        );
                    }

                    $success = true;
                } catch (\RuntimeException $exception) {
                    if (str_contains($exception->getMessage(), 'REDUNDANT')) {
                        $this->logger->warning('Rate limit ao sincronizar carteira; aplicando backoff.', [
                            'day' => $day,
                            'attempt' => $attempts + 1,
                        ]);
                        sleep(10);
                        $attempts++;
                        continue;
                    }

                    $this->canonical->markSyncFailed(
                        $start,
                        $end,
                        $exception->getMessage(),
                        $source,
                        $vendorCount,
                        $stats,
                        $day,
                        $checkpointPage,
                        $checkpointBatch,
                        null,
                        self::ORDER_SYNC_NAME,
                        self::STATE_PHASE
                    );
                    throw $exception;
                } catch (\Throwable $exception) {
                    $this->canonical->markSyncFailed(
                        $start,
                        $end,
                        $exception->getMessage(),
                        $source,
                        $vendorCount,
                        $stats,
                        $day,
                        $checkpointPage,
                        $checkpointBatch,
                        null,
                        self::ORDER_SYNC_NAME,
                        self::STATE_PHASE
                    );
                    throw $exception;
                }
            }

            if (!$success) {
                $this->canonical->markSyncFailed(
                    $start,
                    $end,
                    'Falha recorrente na sincronizacao da carteira.',
                    $source,
                    $vendorCount,
                    $stats,
                    $day,
                    $checkpointPage,
                    $checkpointBatch,
                    date('Y-m-d H:i:s', time() + 10),
                    self::ORDER_SYNC_NAME,
                    self::STATE_PHASE
                );
                throw new \RuntimeException('Falha recorrente ao sincronizar carteira em ' . $day);
            }

            $current = strtotime('+1 day', $current);
        }

        $this->canonical->markSyncCompleted(
            $start,
            $end,
            $source,
            $vendorCount,
            $stats,
            $end,
            1,
            0,
            self::ORDER_SYNC_NAME,
            self::STATE_PHASE
        );

        return [
            'stats' => $stats,
            'divergences' => $divergences,
        ];
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int,checkpoint_batch:int}
     */
    private function processPendingOrderDay(string $day, string $source): array
    {
        $rawOrders = $this->omie->listPendingOrders($day, $day);
        $clientMap = $this->buildClientMap($rawOrders);
        $normalized = [];
        $divergences = 0;

        foreach ($rawOrders as $rawOrder) {
            if (!is_array($rawOrder)) {
                continue;
            }

            $canonical = $this->normalizePendingOrderToCanonical($rawOrder, $clientMap, $source);
            if ($canonical === null) {
                continue;
            }

            $divergences += $this->detectOrderDivergences($canonical);
            $normalized[] = $canonical;
        }

        $stats = $this->canonical->replacePendingOrdersWindow($day, $day, $normalized);

        return [
            'stats' => $stats,
            'divergences' => $divergences,
            'checkpoint_batch' => $normalized === [] ? 0 : 1,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     * @return array<string,mixed>|null
     */
    private function normalizeInvoiceToCanonical(array $raw, bool $cancelled, string $source, array $orderFlags = []): ?array
    {
        return $this->canonicalMapper()->normalizeInvoiceToCanonical(
            $raw,
            $cancelled,
            $source,
            $this->vendorMap,
            $orderFlags
        );
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @return array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}>
     */
    private function buildOrderFlagsMap(array $orders): array
    {
        return $this->support()->buildOrderFlagsMap($orders);
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     */
    private function isAuthorizedInvoiceFromOmie(array $raw, array $orderFlags): bool
    {
        return $this->support()->isAuthorizedInvoiceFromOmie($raw, $orderFlags);
    }

    /**
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     * @return array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}|null
     */
    private function resolveOrderFlags(array $orderFlags, mixed ...$values): ?array
    {
        return $this->support()->resolveOrderFlags($orderFlags, ...$values);
    }

    /**
     * @return list<string>
     */
    private function buildOrderLookupKeys(mixed ...$values): array
    {
        return $this->support()->buildOrderLookupKeys(...$values);
    }

    private function isTruthyFlag(mixed $value): bool
    {
        return $this->support()->isTruthyFlag($value);
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,array<string,mixed>> $clientMap
     * @return array<string,mixed>|null
     */
    private function normalizePendingOrderToCanonical(array $raw, array $clientMap, string $source): ?array
    {
        return $this->canonicalMapper()->normalizePendingOrderToCanonical($raw, $clientMap, $source, $this->vendorMap);
    }

    /**
     * @param array<string,mixed> $canonical
     */
    private function detectInvoiceDivergences(array $canonical): int
    {
        return $this->divergenceDetector()->detectInvoiceDivergences($canonical, $this->vendorMap);
    }

    /**
     * @param array<string,mixed> $canonical
     */
    private function detectOrderDivergences(array $canonical): int
    {
        return $this->divergenceDetector()->detectOrderDivergences($canonical, $this->vendorMap);
    }

    private function buildInvoiceKey(int $noteId, string $accessKey, string $number, string $serie, ?string $issueDate, int $clientCode): string
    {
        if ($noteId > 0) {
            return 'omie:' . $noteId;
        }

        if ($accessKey !== '') {
            return 'key:' . $accessKey;
        }

        $parts = array_filter([
            $number !== '' ? $number : null,
            $serie !== '' ? $serie : null,
            $issueDate,
            $clientCode > 0 ? (string) $clientCode : null,
        ]);

        return $parts === [] ? '' : 'doc:' . implode(':', $parts);
    }

    private function classifyStatus(string $etapa, string $statusPedido): string
    {
        return $this->support()->classifyStatus($etapa, $statusPedido);
    }

    private function extractVendorId(array $raw): string
    {
        return $this->partyResolver()->extractVendorId($raw);
    }

    private function resolveVendorName(string $vendorId, array $raw): string
    {
        return $this->partyResolver()->resolveVendorName($vendorId, $raw, $this->vendorMap);
    }

    private function extractClientCode(array $raw): int
    {
        return $this->partyResolver()->extractClientCode($raw);
    }

    private function resolveClientName(array $raw, array $clientData): string
    {
        return $this->partyResolver()->resolveClientName($raw, $clientData);
    }

    private function resolveClientDocument(array $raw, array $clientData): string
    {
        return $this->partyResolver()->resolveClientDocument($raw, $clientData);
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

    /**
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    private function emptyStats(): array
    {
        return $this->support()->emptyStats();
    }

    /**
     * @param array{inserted:int,updated:int,unchanged:int,skipped:int} $current
     * @param array{inserted:int,updated:int,unchanged:int,skipped:int} $delta
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    private function mergeStats(array $current, array $delta): array
    {
        return $this->support()->mergeStats($current, $delta);
    }

    /**
     * @param array{inserted:int,updated:int,unchanged:int,skipped:int} $stats
     */
    private function sumStats(array $stats): int
    {
        return $this->support()->sumStats($stats);
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
