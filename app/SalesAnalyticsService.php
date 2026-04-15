<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\SalesAnalytics\SalesDatasetNormalizer;
use BIomie\SalesAnalytics\SalesStatusBlockBuilder;
use BIomie\SalesAnalytics\SalesSupport;
use BIomie\SalesAnalytics\SalesTargetCalculator;
use DateTimeImmutable;

final class SalesAnalyticsService
{
    private const SNAPSHOT_CACHE_VERSION = 2;
    private ?SalesSupport $salesSupport = null;
    private ?SalesDatasetNormalizer $datasetNormalizer = null;
    private ?SalesTargetCalculator $targetCalculator = null;
    private ?SalesStatusBlockBuilder $statusBlockBuilder = null;

    public function __construct(
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly Logger $logger,
        private readonly ?OmieRepository $repository = null,
        private readonly ?RefreshQueue $queue = null,
    ) {
    }

    public static function cacheKey(string $start, string $end): string
    {
        return sprintf('snapshot_fiscal_v%d_%s_%s', self::SNAPSHOT_CACHE_VERSION, $start, $end);
    }

    public function getSnapshot(string $start, string $end, bool $forceRefresh = false): array
    {
        $key = self::cacheKey($start, $end);
        $ttlMinutes = (int) $this->config->get('analytics.cache_ttl_minutes', 60);
        $ttlSeconds = max($ttlMinutes, 1) * 60;
        $staleTtlSeconds = max($ttlMinutes * 24, 720) * 60; // stale válido por 24x o TTL (mín. 12h)

        // 1. Cache fresco — serve direto
        if (!$forceRefresh) {
            $cached = $this->cache->get($key, $ttlSeconds);
            if (is_array($cached)) {
                $cached['meta']['cache'] = $this->cache->getMeta($key);
                $cached['meta']['served_from'] = 'cache-fresh';
                return $cached;
            }

            // 2. Cache stale — serve o último válido + agenda rebuild
            $meta = $this->cache->getMeta($key);
            $age = (int)($meta['age_seconds'] ?? PHP_INT_MAX);
            if ($age <= $staleTtlSeconds) {
                $stale = $this->cache->read($key);
                if (is_array($stale)) {
                    $queueInfo = $this->queue?->enqueue('sales', $key, ['start' => $start, 'end' => $end]);
                    $stale['meta']['cache'] = $meta;
                    $stale['meta']['served_from'] = 'cache-stale';
                    $stale['meta']['refresh_queued'] = (bool)($queueInfo['queued'] ?? false);
                    return $stale;
                }
            }
        }

        // 3. Build novo — com fallback stale-if-error
        $fallback = $this->cache->read($key);
        try {
            $snapshot = $this->buildSnapshot($start, $end);
            $this->cache->put($key, $snapshot);
            $snapshot['meta']['cache'] = $this->cache->getMeta($key);
            $snapshot['meta']['served_from'] = $forceRefresh ? 'manual-refresh' : 'live-build';
            return $snapshot;
        } catch (\Throwable $exception) {
            $this->logger->error('Build falhou, usando stale-if-error', ['message' => $exception->getMessage()]);
            if (is_array($fallback)) {
                $fallback['meta']['cache'] = $this->cache->getMeta($key);
                $fallback['meta']['served_from'] = 'stale-if-error';
                $fallback['meta']['fallback_reason'] = $exception->getMessage();
                return $fallback;
            }
            throw $exception;
        }
    }

    private function buildSnapshot(string $start, string $end): array
    {
        $useDemo = !$this->config->hasOmieCredentials() && (bool) $this->config->get('analytics.demo_mode_when_unconfigured', true);

        try {
            if ($useDemo || $this->repository === null) {
                $orders = DemoData::orders($start, $end);
                $vendors = DemoData::vendors();
                $mode = 'demo';
            } else {
                $vendors = $this->salesSupport()->normalizeVendors($this->repository->listVendors());
                [$orders, $mode] = $this->buildLiveCommercialDataset($start, $end);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Falha ao gerar snapshot; usando demo fallback', ['message' => $exception->getMessage()]);
            $orders = DemoData::orders($start, $end);
            $vendors = DemoData::vendors();
            $mode = 'demo-fallback';
        }

        return $this->aggregate($orders, $vendors, $start, $end, $mode);
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:string}
     */
    private function buildLiveCommercialDataset(string $start, string $end): array
    {
        if ($this->repository === null) {
            return [DemoData::orders($start, $end), 'demo'];
        }

        try {
            $rawInvoices = $this->repository->listInvoices($start, $end, 'N');
            $rawCancelledInvoices = $this->repository->listInvoices($start, $end, 'C');
            $rawPendingOrders = $this->repository->listPendingOrders($start, $end);

            $clientMap = $this->buildClientMap($rawPendingOrders);
            $orderFlags = $this->buildOrderFlagsMap($rawPendingOrders);
            $pendingOrders = array_values(array_filter(
                $this->normalizeOrders($rawPendingOrders, $clientMap),
                static fn (array $row): bool => in_array((string) ($row['status_group'] ?? ''), ['autorizado', 'outro'], true)
            ));

            $orders = array_merge(
                $this->normalizeInvoices($rawInvoices, false, $orderFlags),
                $this->normalizeInvoices($rawCancelledInvoices, true, $orderFlags),
                $pendingOrders
            );

            return [$orders, 'live-fiscal'];
        } catch (\Throwable $exception) {
            $this->logger->warning('Falha na leitura fiscal; usando fallback por pedidos.', [
                'message' => $exception->getMessage(),
                'period_start' => $start,
                'period_end' => $end,
            ]);

            $rawOrders = $this->repository->listOrdersPipeline($start, $end);
            $clientMap = $this->buildClientMap($rawOrders);

            return [$this->normalizeOrders($rawOrders, $clientMap), 'live-orders-fallback'];
        }
    }

    private function buildClientMap(array $orders): array
    {
        if ($this->repository === null) {
            return [];
        }

        $clientCodes = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $clientCode = $this->extractClientCode($order);
            if ($clientCode > 0) {
                $clientCodes[] = $clientCode;
            }
        }

        if ($clientCodes === []) {
            return [];
        }

        return $this->repository->listClientsSummaryByCodes($clientCodes);
    }

    /**
     * Normaliza NF-e emitidas/canceladas para o mesmo contrato usado no front-end.
     *
     * A API oficial `ListarNF` retorna notas no array `nfCadastro`, com filtro
     * `filtrar_por_status = N|C`, datas de emissão (`dEmi*`) e cancelamento (`dCan*`).
     */
    private function normalizeInvoices(array $invoices, bool $cancelled, array $orderFlags = []): array
    {
        return $this->datasetNormalizer()->normalizeInvoices($invoices, $cancelled, $orderFlags);
    }

    private function aggregate(array $orders, array $vendors, string $start, string $end, string $mode): array
    {
        $vendorMap = [];
        foreach ($vendors as $vendor) {
            foreach ($this->buildVendorLookupKeys($vendor['codigo'] ?? $vendor['vendor_id'] ?? '') as $vendorKey) {
                $vendorMap[$vendorKey] = $vendor;
            }
        }

        $grossRevenue = 0.0;
        $merchandiseRevenue = 0.0;
        $discountsTotal = 0.0;
        $daily = [];
        $vendorRanking = [];
        $productRanking = [];
        $alerts = [];
        $ordersWithoutBillingDate = 0;
        $ordersWithoutVendor = 0;
        $ordersWithoutClient = 0;
        $revenueOrdersCount = 0;
        $billedRows = [];

        foreach (Support::eachDate($start, $end) as $date) {
            $daily[$date] = [
                'date' => $date,
                'gross_revenue' => 0.0,
                'merchandise_revenue' => 0.0,
                'discounts' => 0.0,
                'orders' => 0,
            ];
        }

        foreach ($orders as $index => $order) {
            $grossTotal = Support::toFloat($order['gross_total'] ?? $order['total'] ?? 0);
            $merchandiseTotal = Support::toFloat($order['merchandise_total'] ?? 0);
            $discountTotal = Support::toFloat($order['discount_total'] ?? 0);

            // Classificar status para decidir o que conta na receita
            $orderStatus = $order['status_group'] ?? self::classifyStatus((string) ($order['stage'] ?? ''));

            // Cancelados não entram na receita nem no ranking de vendedores
            $isFiscalInvoice = (string) ($order['source_entity'] ?? '') === 'fiscal_invoice';
            $countsAsRevenue = $orderStatus === 'faturado'
                && ($isFiscalInvoice || str_contains($mode, 'orders-fallback') || !$this->config->hasOmieCredentials());

            if ($countsAsRevenue) {
                $grossRevenue += $grossTotal;
                $merchandiseRevenue += $merchandiseTotal;
                $discountsTotal += $discountTotal;
                $revenueOrdersCount++;
                $billedRows[] = $order;
            }

            $billingDate = (string) ($order['billing_date'] ?? '');
            if ($billingDate === '') {
                $billingDate = (string) ($order['issue_date'] ?? $start);
                if (!$isFiscalInvoice) {
                    $ordersWithoutBillingDate++;
                }
            }
            if (!isset($daily[$billingDate])) {
                $daily[$billingDate] = [
                    'date' => $billingDate,
                    'gross_revenue' => 0.0,
                    'merchandise_revenue' => 0.0,
                    'discounts' => 0.0,
                    'orders' => 0,
                ];
            }
            if ($countsAsRevenue) {
                $daily[$billingDate]['gross_revenue'] += $grossTotal;
                $daily[$billingDate]['merchandise_revenue'] += $merchandiseTotal;
                $daily[$billingDate]['discounts'] += $discountTotal;
                $daily[$billingDate]['orders']++;
            }

            $vendorId = (string) ($order['vendor_id'] ?? '');
            $vendorName = trim((string) ($order['vendor_name'] ?? ''));
            if ($vendorName === '') {
                foreach ($this->buildVendorLookupKeys($vendorId) as $vendorKey) {
                    if (!isset($vendorMap[$vendorKey])) {
                        continue;
                    }

                    $vendorName = (string) ($vendorMap[$vendorKey]['nome'] ?? '');
                    if ($vendorName !== '') {
                        break;
                    }
                }
            }
            if ($vendorName === '') {
                $vendorName = 'Sem vendedor';
                $ordersWithoutVendor++;
            }

            $orders[$index]['vendor_id'] = $vendorId;
            $orders[$index]['vendor_name'] = $vendorName;

            // Vendor ranking: só faturado + autorizado (exclui cancelado)
            if ($countsAsRevenue) {
                if (!isset($vendorRanking[$vendorName])) {
                $vendorRanking[$vendorName] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => $vendorName,
                    'revenue' => 0.0,
                    'gross_revenue' => 0.0,
                    'merchandise_total' => 0.0,
                    'discount_total' => 0.0,
                    'orders' => 0,
                    'items_qty' => 0,
                ];
            }
            $vendorRanking[$vendorName]['revenue'] += $grossTotal;
            $vendorRanking[$vendorName]['gross_revenue'] += $grossTotal;
            $vendorRanking[$vendorName]['merchandise_total'] += $merchandiseTotal;
            $vendorRanking[$vendorName]['discount_total'] += $discountTotal;
            $vendorRanking[$vendorName]['orders']++;
            $vendorRanking[$vendorName]['items_qty'] += (int) ($order['items_qty'] ?? 0);
            } // end countsAsRevenue

            $customerName = trim((string) ($order['cliente'] ?? ''));
            if ($customerName === '' || $customerName === 'Cliente não informado') {
                $ordersWithoutClient++;
            }

            if (!$countsAsRevenue) {
                continue;
            }

            foreach (($order['items'] ?? []) as $item) {
                $productKey = (string) ($item['product_code'] ?? $item['description'] ?? 'Sem SKU');
                if (!isset($productRanking[$productKey])) {
                    $productRanking[$productKey] = [
                        'product_code' => (string) ($item['product_code'] ?? ''),
                        'description' => (string) ($item['description'] ?? 'Produto sem descrição'),
                        'quantity' => 0.0,
                        'revenue' => 0.0,
                        'orders' => 0,
                    ];
                }
                $productRanking[$productKey]['quantity'] += Support::toFloat($item['quantity'] ?? 0);
                $productRanking[$productKey]['revenue'] += Support::toFloat($item['total'] ?? 0);
                $productRanking[$productKey]['orders']++;
            }
        }

        $ordersCount = $revenueOrdersCount;
        $ticketAverage = $ordersCount > 0 ? $grossRevenue / $ordersCount : 0;
        $baseMonthlyTarget = Support::toFloat($this->config->get('analytics.monthly_target', 0));
        $targetForPeriod = $this->calculateTargetForPeriod($start, $end, $baseMonthlyTarget);
        $daysTotal = count(Support::eachDate($start, $end));
        $today = new DateTimeImmutable('today');
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);

        if ($today < $startDate) {
            $daysElapsed = 0;
            $forecast = 0.0;
        } elseif ($today > $endDate) {
            $daysElapsed = $daysTotal;
            $forecast = $grossRevenue;
        } else {
            $daysElapsed = max(1, (int) $today->diff($startDate)->format('%a') + 1);
            $forecast = ($grossRevenue / $daysElapsed) * $daysTotal;
        }

        $progress = $targetForPeriod > 0 ? min(100, ($grossRevenue / $targetForPeriod) * 100) : 0;

        foreach ($vendorRanking as &$vendorRow) {
            $vendorRow['ticket_avg'] = $vendorRow['orders'] > 0 ? $vendorRow['revenue'] / $vendorRow['orders'] : 0;
            $vendorRow['share'] = $grossRevenue > 0 ? ($vendorRow['revenue'] / $grossRevenue) * 100 : 0;
        }
        unset($vendorRow);

        usort($billedRows, static fn (array $a, array $b): int => Support::toFloat($b['gross_total'] ?? $b['total'] ?? 0) <=> Support::toFloat($a['gross_total'] ?? $a['total'] ?? 0));
        $vendorRanking = array_values($vendorRanking);
        usort($vendorRanking, static fn (array $a, array $b): int => Support::toFloat($b['revenue']) <=> Support::toFloat($a['revenue']));
        $productRanking = array_values($productRanking);
        usort($productRanking, static fn (array $a, array $b): int => Support::toFloat($b['revenue']) <=> Support::toFloat($a['revenue']));
        ksort($daily);

        if ($targetForPeriod > 0 && $progress < 85) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Atingimento abaixo do piso',
                'message' => 'O faturamento ainda está abaixo de 85% do ritmo esperado para a meta do período.',
                'metric' => 'Atingimento',
                'value' => round($progress, 2),
            ];
        }

        // ── Status blocks (pipeline completo) ────────────
        $statusBlocks = $this->buildStatusBlocks($orders, $vendorRanking, $start, $end);

        if (!empty($vendorRanking)) {
            $topVendor = $vendorRanking[0];
            $alerts[] = [
                'level' => 'info',
                'title' => 'Maior vendedor do período',
                'message' => $topVendor['vendor_name'] . ' lidera o período em faturamento.',
                'metric' => 'Receita',
                'value' => round((float) $topVendor['revenue'], 2),
            ];
        }

        if ($ordersWithoutBillingDate > 0) {
            $this->logger->warning('Pedidos sem data de faturamento retornada pela API; usando fallback.', [
                'total_orders' => $ordersCount,
                'orders_without_billing_date' => $ordersWithoutBillingDate,
                'period_start' => $start,
                'period_end' => $end,
            ]);
        }

        if ($ordersWithoutVendor > 0) {
            $this->logger->warning('Pedidos sem vendedor identificado na resposta da API.', [
                'total_orders' => $ordersCount,
                'orders_without_vendor' => $ordersWithoutVendor,
                'period_start' => $start,
                'period_end' => $end,
            ]);
        }

        if ($ordersWithoutClient > 0) {
            $this->logger->warning('Pedidos sem cliente resolvido após a consolidação.', [
                'total_orders' => $ordersCount,
                'orders_without_client' => $ordersWithoutClient,
                'period_start' => $start,
                'period_end' => $end,
            ]);
        }

        return [
            'ok' => true,
            'mode' => $mode,
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' até ' . date('d/m/Y', strtotime($end)),
            ],
            'summary' => [
                'revenue' => Support::money($grossRevenue),
                'gross_revenue' => Support::money($grossRevenue),
                'merchandise_total' => Support::money($merchandiseRevenue),
                'discounts_total' => Support::money($discountsTotal),
                'net_after_discount' => Support::money($merchandiseRevenue - $discountsTotal),
                'orders' => $ordersCount,
                'avg_ticket' => Support::money($ticketAverage),
                'active_vendors' => count(array_filter($vendorRanking, static fn (array $row): bool => ($row['vendor_name'] ?? '') !== 'Sem vendedor')),
                'pending_orders' => (int) ($statusBlocks['summary_status']['authorized_orders'] ?? 0),
                'pending_value' => Support::money((float) ($statusBlocks['summary_status']['authorized_value'] ?? 0)),
                'cancelled_orders' => (int) ($statusBlocks['summary_status']['cancelled_orders'] ?? 0),
                'cancelled_value' => Support::money((float) ($statusBlocks['summary_status']['cancelled_value'] ?? 0)),
                'target' => Support::money($targetForPeriod),
                'forecast' => Support::money($forecast),
                'goal_progress' => round($progress, 2),
            ],
            'daily_sales' => array_values(array_map(static fn (array $row): array => [
                'date' => $row['date'],
                'revenue' => Support::money($row['gross_revenue']),
                'gross_revenue' => Support::money($row['gross_revenue']),
                'merchandise_total' => Support::money($row['merchandise_revenue']),
                'discounts_total' => Support::money($row['discounts']),
                'orders' => (int) $row['orders'],
            ], $daily)),
            'vendor_ranking' => array_map(static fn (array $row): array => [
                'vendor_id' => $row['vendor_id'],
                'vendor_name' => $row['vendor_name'],
                'revenue' => Support::money($row['revenue']),
                'gross_revenue' => Support::money($row['gross_revenue']),
                'merchandise_total' => Support::money($row['merchandise_total']),
                'discount_total' => Support::money($row['discount_total']),
                'orders' => (int) $row['orders'],
                'items_qty' => (int) $row['items_qty'],
                'ticket_avg' => Support::money($row['ticket_avg']),
                'share' => round((float) $row['share'], 2),
            ], $vendorRanking),
            'top_orders' => array_map(static fn (array $row): array => [
                'codigo' => (string) ($row['codigo'] ?? ''),
                'codigo_integracao' => (string) ($row['codigo_integracao'] ?? ''),
                'cliente' => (string) ($row['cliente'] ?? 'Cliente não informado'),
                'cliente_documento' => (string) ($row['customer_document'] ?? ''),
                'cliente_codigo' => (string) ($row['customer_code'] ?? ''),
                'vendor_id' => (string) ($row['vendor_id'] ?? ''),
                'vendor_name' => (string) ($row['vendor_name'] ?? 'Sem vendedor'),
                'issue_date' => (string) ($row['issue_date'] ?? ''),
                'billing_date' => (string) ($row['billing_date'] ?? ''),
                'stage' => (string) ($row['stage'] ?? ''),
                'items_qty' => (int) ($row['items_qty'] ?? 0),
                'gross_total' => Support::money($row['gross_total'] ?? $row['total'] ?? 0),
                'merchandise_total' => Support::money($row['merchandise_total'] ?? 0),
                'discount_total' => Support::money($row['discount_total'] ?? 0),
                'total' => Support::money($row['gross_total'] ?? $row['total'] ?? 0),
            ], $billedRows),
            'top_products' => array_map(static fn (array $row): array => [
                'product_code' => $row['product_code'],
                'description' => $row['description'],
                'quantity' => Support::money($row['quantity']),
                'revenue' => Support::money($row['revenue']),
                'orders' => (int) $row['orders'],
            ], $productRanking),
            'alerts' => $alerts,
            'summary_status' => $statusBlocks['summary_status'],
            'vendor_status_matrix' => $statusBlocks['vendor_status_matrix'],
            'daily_status' => $statusBlocks['daily_status'],
            'funnel' => $statusBlocks['funnel'],
            'alerts_operational' => $statusBlocks['alerts_operational'],
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'snapshot_version' => self::SNAPSHOT_CACHE_VERSION,
                'realized_basis' => 'fiscal_invoices_non_cancelled',
                'pending_basis' => 'sales_orders_open',
                'cancelled_basis' => 'fiscal_invoices_cancelled',
            ],
        ];
    }

    private function normalizeVendors(array $vendors): array
    {
        return $this->salesSupport()->normalizeVendors($vendors);
    }

    /**
     * @return list<string>
     */
    private function buildVendorLookupKeys(mixed $vendorId): array
    {
        return $this->salesSupport()->buildVendorLookupKeys($vendorId);
    }

    /**
     * @return array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}>
     */
    private function buildOrderFlagsMap(array $orders): array
    {
        return $this->salesSupport()->buildOrderFlagsMap($orders);
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     */
    private function isAuthorizedInvoiceFromOmie(array $invoice, array $orderFlags): bool
    {
        return $this->salesSupport()->isAuthorizedInvoiceFromOmie($invoice, $orderFlags);
    }

    /**
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     * @return array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}|null
     */
    private function resolveOrderFlags(array $orderFlags, mixed ...$values): ?array
    {
        return $this->salesSupport()->resolveOrderFlags($orderFlags, ...$values);
    }

    /**
     * @return list<string>
     */
    private function buildOrderLookupKeysFromValues(mixed ...$values): array
    {
        return $this->salesSupport()->buildOrderLookupKeysFromValues(...$values);
    }

    private function classifyOrderStatusGroup(array $order, string $stage, string $statusPedido): string
    {
        return $this->salesSupport()->classifyOrderStatusGroup($order, $stage, $statusPedido);
    }

    private function isTruthyFlag(mixed $value): bool
    {
        return $this->salesSupport()->isTruthyFlag($value);
    }

    private function normalizeOrders(array $orders, array $clientMap = []): array
    {
        return $this->datasetNormalizer()->normalizeOrders($orders, $clientMap);
    }

    private function extractClientCode(array $order): int
    {
        return $this->salesSupport()->extractClientCode($order);
    }

    private function calculateTargetForPeriod(string $start, string $end, float $monthlyTarget): float
    {
        return $this->targetCalculator()->calculateTargetForPeriod($start, $end, $monthlyTarget);
    }

    /**
     * Classifica o status bruto do Omie em um grupo can�nico.
     * Omie retorna c�digos num�ricos em cabecalho.etapa:
     *   10=Or�amento, 20=Pedido/Autorizado, 50=Faturado, 60=Entregue, 70=Cancelado, 80=Devolvido
     * Tamb�m pode vir como texto: "FATURADO", "AUTORIZADO", "CANCELADO", etc.
     */
    private static function classifyStatus(string $raw): string
    {
        return (new SalesSupport())->classifyStatus($raw);
    }

    /**
     * Gera blocos de status: summary_status, vendor_status_matrix, daily_status, funnel, alerts_operational.
     */
    public function buildStatusBlocks(array $orders, array $vendorRanking, string $start, string $end): array
    {
        return $this->statusBlockBuilder()->buildStatusBlocks($orders, $vendorRanking, $start, $end);
    }

    private function salesSupport(): SalesSupport
    {
        return $this->salesSupport ??= new SalesSupport();
    }

    private function datasetNormalizer(): SalesDatasetNormalizer
    {
        return $this->datasetNormalizer ??= new SalesDatasetNormalizer($this->salesSupport());
    }

    private function targetCalculator(): SalesTargetCalculator
    {
        return $this->targetCalculator ??= new SalesTargetCalculator();
    }

    private function statusBlockBuilder(): SalesStatusBlockBuilder
    {
        return $this->statusBlockBuilder ??= new SalesStatusBlockBuilder($this->salesSupport());
    }
}
