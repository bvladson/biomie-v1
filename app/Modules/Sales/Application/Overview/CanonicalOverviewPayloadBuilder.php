<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\CanonicalRepository;
use BIomie\CommercialGoldReconciler;
use BIomie\CommercialPlanning;
use BIomie\GoldMartRepository;
use BIomie\Logger;
use BIomie\Modules\Finance\Application\FinanceOverviewBuilder;
use BIomie\Modules\Finance\Infrastructure\FinanceReadRepository;
use BIomie\RequestContext;
use BIomie\Shared\Support\PerfMarker;
use BIomie\Support;
use BIomie\Support\DocumentMask;
use BIomie\TargetsRepository;
use PDO;
use Throwable;

final class CanonicalOverviewPayloadBuilder
{
    public function __construct(
        private readonly OverviewDiagnosticsBuilder $diagnostics = new OverviewDiagnosticsBuilder(),
        private readonly OverviewMonthComparisonBuilder $monthComparison = new OverviewMonthComparisonBuilder(),
        private readonly AttentionSignalsBuilder $attentionSignals = new AttentionSignalsBuilder(),
        private readonly PerfMarker $perf = new PerfMarker(),
    ) {
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @param array{start:string,end:string,label:string,competencia:?string} $range
     * @return array<string,mixed>
     */
    public function build(
        PDO $pdo,
        Logger $logger,
        CanonicalRepository $canonical,
        ?TargetsRepository $targetsRepository,
        ?array $snapshot,
        array $range,
        string $lastSync,
        float $saturdayWeight,
        ?string $snapshotError = null
    ): array {
        $start = $range['start'];
        $end = $range['end'];
        $this->perf->mark('canonical_core_start');
        $goldMart = new GoldMartRepository($pdo, $logger);
        $coreReconciliation = (new CommercialGoldReconciler($canonical, $goldMart))->reconcile($start, $end);
        $useGoldCore = (bool) ($coreReconciliation['reconciled'] ?? false);
        $selectedCore = $coreReconciliation[$useGoldCore ? 'gold' : 'canonical'] ?? null;
        $selectedCore = is_array($selectedCore) ? $selectedCore : [];
        $summaryRow = is_array($selectedCore['invoice_summary'] ?? null)
            ? $selectedCore['invoice_summary']
            : [];
        $planningRows = is_array($selectedCore['vendor_rows'] ?? null)
            ? $selectedCore['vendor_rows']
            : [];
        $dailyRows = is_array($selectedCore['daily_rows'] ?? null)
            ? $selectedCore['daily_rows']
            : [];
        $invoiceVendorRows = $useGoldCore
            ? array_values(array_filter(
                $planningRows,
                static fn (array $row): bool => ($row['status_comercial'] ?? '') === 'faturado',
            ))
            : $canonical->aggregateInvoicesByVendorCommercial($start, $end);
        $pendingSummary = $canonical->summarizePendingCommercial($start, $end);
        $divergences = $canonical->openDivergences(10);
        $canonicalProducts = $canonical->topProductsCommercial($start, $end, 12);
        $canonicalAuthorizedNfsCount = (int) ($summaryRow['billed_orders'] ?? 0);
        $canUseMonthlyMarts = $this->isFullCalendarMonth($start, $end);
        $canonicalClients = $canonical->topClientsCommercial($start, $end, 12);
        $topClientsSource = $canonicalClients !== [] ? 'biomie_notas_fiscais_canonicas' : 'none';
        $topProductsSource = $canonicalProducts !== [] ? 'biomie_itens_nota_fiscal' : 'none';
        $martClienteCount = 0;
        if ($useGoldCore && $canUseMonthlyMarts) {
            $goldProducts = $goldMart->topProductsCommercial($start, $end, 12);
            if ($goldProducts !== []) {
                $canonicalProducts = $goldProducts;
                $topProductsSource = 'mart_produto_periodo';
            }

            $goldClients = $goldMart->topClientsCommercial($start, $end, 12);
            $martClienteCount = count($goldClients);
            if ($goldClients !== []) {
                $canonicalClients = $goldClients;
                $topClientsSource = 'mart_cliente_periodo';
            }
        }
        $this->perf->measure('overview.canonical_core', 'canonical_core_start');

        $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
            ? $targetsRepository->getTargetsForMonths(CommercialPlanning::monthsInRange($start, $end))
            : [];
        $planning = CommercialPlanning::build($planningRows, $targetsMap, $start, $end, $saturdayWeight);
        $planningMap = [];
        foreach ($planning['vendors'] as $vendorPlan) {
            $vendorId = trim((string) ($vendorPlan['vendor_id'] ?? ''));
            $vendorName = trim((string) ($vendorPlan['vendor_name'] ?? 'Sem vendedor'));
            $planningMap[$vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName)] = $vendorPlan;
        }

        require_once __DIR__ . '/../Vendors/VendorIdentityResolver.php';
        require_once __DIR__ . '/../Vendors/VendorRealizedService.php';
        $vendorMapCanonical = $canonical->getVendorMap();
        $resolver = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMapCanonical);
        $realizedService = new \BIomie\Modules\Sales\Application\Vendors\VendorRealizedService($canonical, $resolver);
        
        $officialPayload = $useGoldCore
            ? $realizedService->summarizeAggregatedRowsByVendor($invoiceVendorRows)
            : $realizedService->summarizeByVendor($start, $end);

        $billedValue = Support::toFloat($summaryRow['billed_value'] ?? 0);
        $ordersCount = (int) ($summaryRow['billed_orders'] ?? 0);
        $grossRevenue = $billedValue;

        $merchandiseTotal = 0.0;
        $discountsTotal = 0.0;
        $vendors = [];

        // Base everything on officialPayload
        foreach ($officialPayload as $or) {
            $key = $or['vendor_id'];
            $vendors[$key] = [
                'vendor_id' => $or['vendor_id'],
                'vendor_name' => $or['vendor_name'],
                'revenue' => $or['realized'],
                'gross_revenue' => $or['realized'],
                'merchandise_total' => $or['merchandise_total'],
                'discount_total' => 0.0, // Best effort from earlier if needed, but not critical for ranking
                'orders' => $or['invoices_count'],
            ];
            $merchandiseTotal += $or['merchandise_total'];
            // discount could be summed from invoices if we really need it, but let's keep it 0.0 or calculate it
        }

        // Just to get discount_total accurately from invoiceVendorRows
        foreach ($invoiceVendorRows as $row) {
            $discount = Support::toFloat($row['descontos'] ?? 0);
            $discountsTotal += $discount;
        }

        $vendorRanking = [];
        foreach ($vendors as $key => $row) {
            $revenue = Support::money($row['revenue']);
            $orders = (int) $row['orders'];
            
            // Try to match plan
            $plan = $planningMap[$row['vendor_id']] ?? null;
            if ($plan === null) {
                // fallback to name
                $plan = $planningMap['name:' . mb_strtolower($row['vendor_name'])] ?? null;
            }

            $row['realizado_vendedor'] = $revenue;
            $row['mercadoria_faturada_vendedor'] = Support::money($row['merchandise_total']);
            $row['revenue'] = $revenue;
            $row['gross_revenue'] = Support::money($row['gross_revenue']);
            $row['merchandise_total'] = Support::money($row['merchandise_total']);
            $row['discount_total'] = Support::money($row['discount_total']);
            $row['ticket_avg'] = $orders > 0 ? Support::money($revenue / $orders) : 0.0;
            $row['share'] = $grossRevenue > 0 ? round(($revenue / $grossRevenue) * 100, 2) : 0.0;
            $row['target_period'] = Support::toFloat($plan['target_period'] ?? 0);
            $row['forecast_period'] = Support::toFloat($plan['forecast_period'] ?? 0);
            $row['gap_to_target'] = Support::toFloat($plan['gap_to_target'] ?? 0);
            $row['attainment_pct'] = Support::toFloat($plan['attainment_pct'] ?? 0);

            $vendorRanking[] = $row;
        }
        usort($vendorRanking, static fn (array $left, array $right): int => $right['revenue'] <=> $left['revenue']);

        $dailySalesMap = [];
        foreach (Support::eachDate($start, $end) as $date) {
            $dailySalesMap[$date] = [
                'date' => $date,
                'revenue' => 0.0,
                'gross_revenue' => 0.0,
                'orders' => 0,
            ];
        }

        foreach ($dailyRows as $row) {
            $status = (string) ($row['status_comercial'] ?? 'outro');
            if ($status !== 'faturado') {
                continue;
            }

            $date = (string) ($row['dia'] ?? '');
            if ($date === '' || !isset($dailySalesMap[$date])) {
                continue;
            }

            $total = Support::toFloat($row['total'] ?? 0);
            $orders = (int) ($row['pedidos'] ?? 0);

            $dailySalesMap[$date]['revenue'] += $total;
            $dailySalesMap[$date]['gross_revenue'] += $total;
            $dailySalesMap[$date]['orders'] += $orders;
        }

        $dailySales = array_values(array_map(static function (array $row): array {
            $row['revenue'] = Support::money($row['revenue']);
            $row['gross_revenue'] = Support::money($row['gross_revenue']);
            return $row;
        }, $dailySalesMap));

        $this->perf->mark('top_orders_start');
        $orders = $canonical->listTopInvoicesForDisplayCommercial($start, $end, 8);
        $this->perf->measure('overview.top_orders', 'top_orders_start');
        $topOrdersIdentityDiagnostics = $this->diagnostics->buildTopOrdersIdentityDiagnostics($orders, [
            'source_table' => 'biomie_notas_fiscais_canonicas',
            'last_materialized_at' => $lastSync,
            'total_notes_analyzed' => $ordersCount,
        ]);

        $topProducts = $canonicalProducts !== []
            ? array_map(static fn (array $row): array => [
                'product_code' => (string) ($row['product_code'] ?? ''),
                'description' => (string) ($row['description'] ?? 'Produto sem descricao'),
                'quantity' => Support::money((float) ($row['quantity'] ?? 0)),
                'revenue' => Support::money((float) ($row['revenue'] ?? 0)),
                'orders' => (int) ($row['orders'] ?? 0),
            ], $canonicalProducts)
            : (($canUseMonthlyMarts && $canonicalAuthorizedNfsCount > 0 && is_array($snapshot['top_products'] ?? null))
                ? $snapshot['top_products']
                : []);
        $alerts = is_array($snapshot['alerts'] ?? null) ? $snapshot['alerts'] : [];
        if ($canonicalClients !== []) {
            $topClientRevenue = (float) (($canonicalClients[0] ?? [])['invoice_total'] ?? 0);
            $topShare = $grossRevenue > 0 ? round($topClientRevenue / $grossRevenue * 100, 2) : 0.0;
            if ($topShare >= 35) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => 'Concentracao elevada em cliente',
                    'message' => sprintf('O principal cliente concentrou %.1f%% da receita fiscal do periodo.', $topShare),
                    'metric' => 'Top cliente',
                    'value' => $topShare,
                ];
            }
        }
        if ($alerts === []) {
            foreach ($divergences as $divergence) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => (string) ($divergence['tipo'] ?? 'Divergencia'),
                    'message' => (string) ($divergence['descricao'] ?? 'Inconsistencia detectada na camada canonica.'),
                    'metric' => 'Divergencia',
                    'value' => 1,
                ];
            }
        }

        $mode = $snapshot !== null ? 'hybrid' : 'canonical';
        $source = $snapshot !== null ? 'canonical+legacy' : 'canonical';
        $coreSourceOfTruth = $useGoldCore ? 'gold_live_reconciled' : 'canonical_live';
        $this->perf->mark('month_comparison_start');
        $monthComparisonError = null;
        try {
            $monthComparison = $this->monthComparison->build($pdo, $logger, $canonical, $targetsRepository, $start, $saturdayWeight);
        } catch (\Throwable $mcEx) {
            $monthComparison = ['available' => false, 'source' => 'error', 'rows' => [], 'reason_code' => 'builder_exception'];
            $monthComparisonError = $mcEx->getMessage();
            $logger->warning('CanonicalOverviewPayloadBuilder: month_comparison falhou.', [
                'message' => $mcEx->getMessage(),
                'request_id' => RequestContext::id(),
            ]);
        }
        $this->perf->measure('overview.month_comparison', 'month_comparison_start');
        $monthComparisonRows = is_array($monthComparison['rows'] ?? null) ? $monthComparison['rows'] : [];

        $this->perf->mark('attention_signals_start');
        $attentionResult = $this->attentionSignals->build($pdo, $logger, $start, $end, RequestContext::id(), [
            'vendor_ranking'     => $vendorRanking,
            'planning_rows'      => $planningRows,
            'gross_revenue'      => $grossRevenue,
            'pending_summary'    => $pendingSummary,
            'planning'           => $planning,
            'top_clients_source' => $topClientsSource,
        ]);
        $this->perf->measure('overview.attention_signals', 'attention_signals_start');

        $this->perf->mark('finance_summary_start');
        $financeSummary = ['available' => false, 'reason_code' => 'not_attempted', 'source' => 'empty'];
        try {
            $financeRepo    = new FinanceReadRepository(static fn (): PDO => $pdo);
            $financeBuilder = new FinanceOverviewBuilder($financeRepo);
            $financeSummary = $financeBuilder->build($start, $end, null, $grossRevenue, RequestContext::id(), $logger);
        } catch (Throwable $finEx) {
            $financeSummary = ['available' => false, 'reason_code' => 'builder_failed', 'source' => 'error'];
            $logger->warning('CanonicalOverviewPayloadBuilder: finance_summary failed.', [
                'message'    => $finEx->getMessage(),
                'request_id' => RequestContext::id(),
            ]);
        }
        $this->perf->measure('overview.finance_summary', 'finance_summary_start');

        $topClients = array_map(function (array $row) use ($grossRevenue, $topClientsSource): array {
            $invoiceTotal = (float) ($row['invoice_total'] ?? 0);
            $invoicesCount = (int) ($row['notes_count'] ?? 0);
            return [
                'client_id'        => (string) ($row['codigo_cliente_omie'] ?? ''),
                'customer_id'      => (string) ($row['codigo_cliente_omie'] ?? ''),
                'client_name'      => (string) ($row['client_name'] ?? 'Cliente nao informado'),
                'customer_name'    => (string) ($row['client_name'] ?? 'Cliente nao informado'),
                'client_document'  => (string) (DocumentMask::maskDocument((string) ($row['client_document'] ?? '')) ?? ''),
                'customer_document' => (string) (DocumentMask::maskDocument((string) ($row['client_document'] ?? '')) ?? ''),
                'notes_count'      => $invoicesCount,
                'invoices_count'   => $invoicesCount,
                'invoice_total'    => Support::money($invoiceTotal),
                'revenue'          => Support::money($invoiceTotal),
                'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
                'discount_total'   => Support::money((float) ($row['discount_total'] ?? 0)),
                'ticket_avg'       => Support::money($invoicesCount > 0 ? $invoiceTotal / $invoicesCount : 0.0),
                'last_purchase'    => (string) ($row['last_purchase'] ?? ($row['last_purchase_at'] ?? '')),
                'last_purchase_at' => (string) ($row['last_purchase'] ?? ($row['last_purchase_at'] ?? '')),
                'share_pct'        => $grossRevenue > 0 ? round($invoiceTotal / $grossRevenue * 100, 2) : 0.0,
                'source'           => $topClientsSource,
            ];
        }, $canonicalClients);

        $top5ClientConcentration = $grossRevenue > 0
            ? round(array_sum(array_map(static fn (array $row): float => (float) ($row['invoice_total'] ?? 0), array_slice($canonicalClients, 0, 5))) / $grossRevenue * 100, 2)
            : 0.0;

        $freshnessStatus = static function (?string $at): string {
            if ($at === null || $at === '') {
                return 'unknown';
            }
            $age = time() - (int) strtotime($at);
            if ($age <= 86400) return 'ok';
            if ($age <= 259200) return 'warning';
            return 'critical';
        };
        $freshnessIsStale = $freshnessStatus($lastSync) === 'critical';

        $topClientsIsEmpty = ($topClients === []);
        if ($topClientsIsEmpty) {
            if ($canonicalAuthorizedNfsCount === 0 && $freshnessIsStale) {
                $topClientsReasonCode = 'sync_stale';
                $topClientsTitle = 'Dados Omie desatualizados';
                $topClientsMessage = sprintf('A ultima sincronizacao (%s) esta atrasada. Nao e possivel confirmar se ha clientes no periodo.', $lastSync ?: '-');
                $topClientsAction = 'Execute o sync Omie e depois a materializacao Gold.';
            } elseif ($canonicalAuthorizedNfsCount === 0) {
                $topClientsReasonCode = 'no_authorized_nfs';
                $topClientsTitle = 'Sem clientes no periodo';
                $topClientsMessage = 'Nenhuma NF autorizada foi encontrada por data_competencia no periodo selecionado.';
                $topClientsAction = 'Verifique o periodo ou a sincronizacao fiscal Omie.';
            } elseif ($topClientsSource === 'none') {
                $topClientsReasonCode = 'client_identity_missing';
                $topClientsTitle = 'Clientes das notas ainda nao identificados';
                $topClientsMessage = sprintf('Existem %d NF(s) autorizada(s), mas nenhuma possui identidade de cliente utilizavel.', $canonicalAuthorizedNfsCount);
                $topClientsAction = 'Revise codigo_cliente_omie, documento e nome do destinatario na sincronizacao fiscal.';
            } else {
                $topClientsReasonCode = 'no_clients_in_period';
                $topClientsTitle = 'Nenhum cliente com venda no periodo';
                $topClientsMessage = 'O periodo selecionado nao possui clientes com NFs autorizadas identificadas.';
                $topClientsAction = 'Amplie o periodo ou revise os filtros e o mapeamento de codigo_cliente_omie.';
            }
        } else {
            $topClientsReasonCode = null;
            $topClientsTitle = null;
            $topClientsMessage = null;
            $topClientsAction = null;
        }

        $monthComparisonIsEmpty = ($monthComparisonRows === []);
        if ($monthComparisonIsEmpty) {
            if ($monthComparisonError !== null) {
                $monthCompReasonCode = 'query_error';
                $monthCompTitle = 'Erro ao calcular comparativo';
                $monthCompMessage = 'O sistema nao conseguiu calcular o comparativo mensal.';
                $monthCompAction = 'Verificar logs pelo request_id: ' . RequestContext::id();
            } elseif ($freshnessIsStale) {
                $monthCompReasonCode = 'sync_stale';
                $monthCompTitle = 'Comparativo pode estar desatualizado';
                $monthCompMessage = 'A origem Omie/canonica esta atrasada em relacao a ultima atualizacao esperada.';
                $monthCompAction = 'Executar sync Omie e materializacao.';
            } elseif ($canonicalAuthorizedNfsCount === 0) {
                $monthCompReasonCode = 'no_authorized_nfs';
                $monthCompTitle = 'Sem faturamento no periodo';
                $monthCompMessage = 'Nenhuma NF autorizada foi encontrada por data_competencia no periodo.';
                $monthCompAction = 'Verifique o filtro de datas ou a sincronizacao fiscal Omie.';
            } else {
                $monthCompReasonCode = 'not_enough_months';
                $monthCompTitle = 'Comparativo mensal insuficiente';
                $monthCompMessage = 'Nao ha meses suficientes com NFs autorizadas para montar comparacao.';
                $monthCompAction = 'Amplie o periodo para incluir pelo menos dois meses.';
            }
            $monthCompTitle = $monthCompTitle ?? 'Comparativo mensal indisponivel';
        } else {
            $monthCompReasonCode = null;
            $monthCompTitle = null;
            $monthCompMessage = null;
            $monthCompAction = null;
        }

        return [
            'ok' => true,
            'mode' => $mode,
            'source' => $source,
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => $range['label'],
                'realized_period_basis' => 'data_competencia',
            ],
            'freshness' => [
                'omie_sync_status' => $freshnessStatus($lastSync),
                'canonical_status' => $freshnessStatus($lastSync),
                'gold_status' => $useGoldCore ? 'reconciled' : 'canonical_fallback',
                'last_omie_sync_at' => $lastSync,
                'last_canonical_nf_at' => $lastSync,
                'last_gold_materialized_at' => null,
                'is_stale' => $freshnessIsStale,
                'stale_reason' => $freshnessIsStale ? 'Ultima sincronizacao ha mais de 72h' : null,
            ],
            'summary' => [
                'revenue' => $grossRevenue,
                'gross_revenue' => $grossRevenue,
                'merchandise_total' => (float) ($summaryRow['merchandise_total'] ?? $merchandiseTotal),
                'discounts_total' => (float) ($summaryRow['discounts_total'] ?? $discountsTotal),
                'orders' => $ordersCount,
                'avg_ticket' => $ordersCount > 0 ? round($grossRevenue / $ordersCount, 2) : 0.0,
                'active_vendors' => count(array_filter(
                    $vendorRanking,
                    static fn (array $row): bool => trim((string) ($row['vendor_name'] ?? '')) !== ''
                        && strcasecmp((string) ($row['vendor_name'] ?? ''), 'Sem vendedor') !== 0
                )),
                'active_clients' => (int) ($summaryRow['active_clients'] ?? count($topClients)),
                'top5_client_concentration_pct' => $top5ClientConcentration,
                'target' => Support::toFloat($planning['team']['target_period'] ?? 0),
                'forecast' => Support::toFloat($planning['team']['forecast_period'] ?? 0),
                'goal_progress' => Support::toFloat($planning['team']['goal_progress'] ?? 0),
                'pending_orders' => (int) ($pendingSummary['authorized_orders'] ?? 0) + (int) ($pendingSummary['quote_orders'] ?? 0),
                'pending_value' => (float) ($pendingSummary['authorized_value'] ?? 0) + (float) ($pendingSummary['quote_value'] ?? 0),
                'cancelled_orders' => (int) ($pendingSummary['cancelled_orders'] ?? 0),
                'cancelled_value' => (float) ($pendingSummary['cancelled_value'] ?? 0),
            ],
            'daily_sales' => $dailySales,
            'vendor_ranking' => $vendorRanking,
            'top_orders' => $orders,
            'top_products' => $topProducts,
            'top_clients' => $topClients,
            'month_comparison' => $monthComparison,
            'alerts' => $alerts,
            'attention_signals'  => $attentionResult['attention_signals'],
            'attentionSignals'   => $attentionResult['attentionSignals'],
            'attention_summary'  => $attentionResult['attention_summary'],
            'finance_summary'    => $financeSummary,
            'financeSummary'     => $financeSummary,
            'diagnostics' => [
                'top_orders_identity' => $topOrdersIdentityDiagnostics,
                'canonical_authorized_nfs_count' => $canonicalAuthorizedNfsCount,
                'mart_cliente_periodo_count' => $martClienteCount,
                'top_clients_count' => count($topClients),
                'month_comparison_count' => count($monthComparisonRows),
                'used_fallback' => false,
                'commercial_core' => CommercialGoldReconciler::diagnostics($coreReconciliation),
                'top_clients' => [
                    'source_table' => $topClientsSource,
                    'primary_source' => $topClientsSource,
                    'fallback_source' => $useGoldCore && $canUseMonthlyMarts
                        ? 'biomie_notas_fiscais_canonicas'
                        : null,
                    'fallback_used' => $useGoldCore && $canUseMonthlyMarts && $topClientsSource !== 'mart_cliente_periodo',
                    'rows_count' => count($topClients),
                    'accepted_keys' => ['top_clients', 'top_customers', 'topCustomers'],
                ],
                'top_products' => [
                    'source_table' => $topProductsSource,
                    'gold_allowed' => $useGoldCore && $canUseMonthlyMarts,
                    'fallback_source' => $useGoldCore && $canUseMonthlyMarts
                        ? 'biomie_itens_nota_fiscal'
                        : null,
                    'fallback_used' => $useGoldCore
                        && $canUseMonthlyMarts
                        && $topProductsSource !== 'mart_produto_periodo',
                    'rows_count' => count($topProducts),
                ],
                'month_comparison_error'  => $monthComparisonError,
                'month_comparison_source' => $monthComparison['source'] ?? null,
                'attention' => $attentionResult['attention_diagnostics'],
                'finance' => $financeSummary['diagnostics'] ?? ['available' => (bool) ($financeSummary['available'] ?? false), 'reason_code' => $financeSummary['reason_code'] ?? null],
                'request_id' => RequestContext::id(),
            ],
            'empty_states' => [
                'top_clients' => [
                    'is_empty' => $topClientsIsEmpty,
                    'reason_code' => $topClientsReasonCode,
                    'title' => $topClientsTitle,
                    'message' => $topClientsMessage,
                    'action' => $topClientsAction,
                ],
                'month_comparison' => [
                    'is_empty' => $monthComparisonIsEmpty,
                    'reason_code' => $monthCompReasonCode,
                    'title' => $monthCompTitle,
                    'message' => $monthCompMessage,
                    'action' => $monthCompAction,
                ],
            ],
            'meta' => [
                'generated_at' => $lastSync,
                'served_from' => $useGoldCore
                    ? 'gold-mart-reconciled+canonical-auxiliary'
                    : ($source === 'canonical' ? 'canonical-db' : 'canonical-db+legacy-snapshot'),
                'source_of_truth' => $coreSourceOfTruth,
                'commercial_core_source' => $coreSourceOfTruth,
                'commercial_core_reconciliation' => CommercialGoldReconciler::diagnostics($coreReconciliation),
                'last_sync' => $lastSync,
                'period_basis' => 'data_competencia',
                'realized_period_basis' => 'data_competencia',
                'wallet_period_basis' => 'data_emissao',
                'target_period_basis' => 'year_month',
                'request_id' => RequestContext::id(),
                'snapshot_fallback_error' => $snapshotError,
                'targets_source' => ($targetsRepository !== null && $targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
            ],
        ];
    }

    private function isFullCalendarMonth(string $start, string $end): bool
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) {
            return false;
        }

        return date('Y-m-01', $startTs) === $start
            && date('Y-m-t', $startTs) === $end
            && date('Y-m', $startTs) === date('Y-m', $endTs);
    }
}
