<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

use BIomie\CanonicalRepository;
use BIomie\FlightPlanV2Service;
use BIomie\Modules\Sales\Infrastructure\Vendor360ReadRepository;
use BIomie\Support;
use BIomie\TargetsRepository;
use PDO;

/**
 * Monta o payload completo do drawer Vendor 360 usando apenas dados locais.
 * Não chama a API Omie.
 *
 * Contrato de retorno:
 * {
 *   ok, source, vendor, period, summary, targets,
 *   invoices, linked_orders, wallet_orders, cancelled_invoices,
 *   customers, products, daily_sales, data_quality, meta
 * }
 */
final class Vendor360ReadService
{
    private Vendor360ReadRepository $v360Repo;

    public function __construct(
        private readonly CanonicalRepository $canonical,
        private readonly VendorIdentityResolver $resolver,
        PDO $pdo
    ) {
        $this->v360Repo = new Vendor360ReadRepository($pdo);
    }

    /**
     * @param array<string,mixed> $identity  Resultado de VendorIdentityResolver::resolve()
     * @return array<string,mixed>
     */
    public function buildPayload(
        string $start,
        string $end,
        array $identity,
        ?TargetsRepository $targets,
        float $saturdayWeight,
        string $requestId = ''
    ): array {
        $vendorId  = (string) ($identity['vendor_id'] ?? '');
        $isGeneric = (bool)   ($identity['is_generic'] ?? false);

        // ── 1. Plano de voo / metas ──────────────────────────────────────────
        $planRow         = [];
        $planDiagnostics = null;
        if (!$isGeneric && $vendorId !== '') {
            try {
                $plan = (new FlightPlanV2Service($this->canonical, $targets, $saturdayWeight))
                    ->build($start, $end, null);
                foreach ($plan['vendors'] ?? [] as $row) {
                    if (trim((string) ($row['vendor_id'] ?? '')) === $vendorId) {
                        $planRow = $row;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $planDiagnostics = $e->getMessage();
            }
        }

        // ── 2. NFs autorizadas (fonte oficial do realizado) ──────────────────
        $allInvoices  = $this->filterByIdentity(
            $this->canonical->listInvoicedOrdersForDisplayCommercial($start, $end, null),
            $identity
        );

        // ── 3. Carteira (pedidos autorizados sem NF) ─────────────────────────
        $allPipeline  = $this->filterByIdentity(
            $this->canonical->listPipelineOrdersForDisplayCommercial($start, $end, null),
            $identity
        );

        // ── 4. NFs canceladas ────────────────────────────────────────────────
        $allCancelled = $this->filterByIdentity(
            $this->canonical->listCancelledInvoicesForDisplayCommercial($start, $end),
            $identity
        );

        // ── 5. Pedidos vinculados via bridge + carteira da bridge ────────────
        $linkedOrders    = [];
        $walletOrders    = [];
        $bridgeAvailable = false;
        $linkedSource    = 'bridge';

        if (!$isGeneric && $vendorId !== '') {
            $bridgeAvailable = $this->v360Repo->isBridgeAvailable($start, $end);
            if ($bridgeAvailable) {
                $linkedOrders = $this->v360Repo->linkedOrdersByVendor($start, $end, $vendorId, 50);
                $walletOrders = $this->v360Repo->walletOrdersByVendor($start, $end, $vendorId, 50);
            }

            // Fallback: bridge vazia mas NFs existem — derivar linked_orders das próprias NFs.
            // Ocorre quando biomie_pedidos_canonicos não foi sincronizado para o período,
            // mas as NFs já estão na camada canônica com pedido_id_omie preenchido.
            if (empty($linkedOrders) && !empty($allInvoices)) {
                $linkedSource = 'nf_fallback';
                foreach ($allInvoices as $inv) {
                    $orderId   = trim((string) ($inv['pedido_id_omie'] ?? ''));
                    $orderCode = trim((string) ($inv['origin_order_code'] ?? $inv['codigo_integracao'] ?? ''));
                    $invoiceKey = trim((string) ($inv['nota_key'] ?? ''));
                    if ($orderId === '' && $orderCode === '' && $invoiceKey === '') {
                        continue;
                    }
                    $val = (float) ($inv['gross_total'] ?? $inv['total'] ?? 0);
                    $linkedOrders[] = [
                        'order_code'        => $orderCode,
                        'pedido_id_omie'    => $orderId,
                        'client_id'         => (string) ($inv['customer_id'] ?? $inv['codigo_cliente_omie'] ?? ''),
                        'client_name'       => (string) ($inv['cliente'] ?? ''),
                        'client_document'   => (string) ($inv['cliente_documento'] ?? ''),
                        'order_value'       => Support::money($val),
                        'merchandise_value' => Support::money((float) ($inv['merchandise_total'] ?? $val)),
                        'invoice_key'       => $invoiceKey,
                        'invoice_value'     => Support::money($val),
                        'bridge_status'     => 'nf_sem_pedido_canonico',
                        'status_comercial'  => 'faturado',
                        'status_fiscal'     => (string) ($inv['status_fiscal'] ?? 'autorizada'),
                        'issue_date'        => (string) ($inv['issue_date'] ?? ''),
                        'billing_date'      => (string) ($inv['billing_date'] ?? ''),
                        'invoice_date'      => (string) ($inv['competencia_date'] ?? $inv['data_competencia'] ?? ''),
                        'codigo'            => $orderCode,
                        'cliente'           => (string) ($inv['cliente'] ?? ''),
                        'total'             => Support::money($val),
                        'gross_total'       => Support::money($val),
                        '_source'           => 'nf_fallback',
                    ];
                }
            }

            // Carteira: fallback para pipeline quando bridge não disponível
            if (empty($walletOrders)) {
                $walletOrders = array_slice($allPipeline, 0, 50);
            }
        } else {
            $walletOrders = array_slice($allPipeline, 0, 50);
        }

        // ── 6. Produtos das NFs autorizadas ──────────────────────────────────
        $products = [];
        if (!$isGeneric && $vendorId !== '') {
            $products = $this->v360Repo->topProductsByVendorCommercial($start, $end, $vendorId, 50);
        }

        // ── 7. Totais ────────────────────────────────────────────────────────
        $invoiceTotal    = 0.0;
        $merchandiseTotal = 0.0;
        $discountTotal   = 0.0;
        $customerSet     = [];

        foreach ($allInvoices as $row) {
            $invoiceTotal    += (float) ($row['gross_total'] ?? $row['total'] ?? 0);
            $merchandiseTotal += (float) ($row['merchandise_total'] ?? 0);
            $discountTotal   += (float) ($row['discount_total'] ?? 0);
            $cid = trim((string) ($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? ''));
            if ($cid !== '') {
                $customerSet[$cid] = true;
            }
        }

        $openTotal = 0.0;
        foreach ($allPipeline as $row) {
            $openTotal += (float) ($row['gross_total'] ?? $row['total'] ?? 0);
            $cid = trim((string) ($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? ''));
            if ($cid !== '') {
                $customerSet[$cid] = true;
            }
        }

        $cancelledTotal = 0.0;
        foreach ($allCancelled as $row) {
            $cancelledTotal += (float) ($row['gross_total'] ?? $row['total'] ?? 0);
        }

        $invoicesCount = count($allInvoices);

        // ── 8. Metas e atingimento ───────────────────────────────────────────
        $targetPeriod  = (float) ($planRow['target_period'] ?? 0);
        $attainmentPct = $targetPeriod > 0 ? round(($invoiceTotal / $targetPeriod) * 100, 2) : 0.0;
        $gapToTarget   = Support::money(max($targetPeriod - $invoiceTotal, 0));

        if (isset($planRow['attainment_pct'])) {
            $attainmentPct = (float) $planRow['attainment_pct'];
        }
        if (isset($planRow['gap_to_target'])) {
            $gapToTarget = Support::money((float) $planRow['gap_to_target']);
        }

        // ── 9. Clientes e série diária ───────────────────────────────────────
        $topCustomers = $this->buildTopCustomers($allInvoices, 8);
        $dailySales   = $this->buildDailySales($start, $end, $allInvoices);
        $lastSync     = $this->canonical->lastSyncAt($start, $end) ?: null;

        // ── 10. Payload do vendedor ──────────────────────────────────────────
        $realizedOfficial = Support::money($invoiceTotal);
        $targetMoney      = Support::money($targetPeriod);

        $vendorPayload = array_merge([
            'vendor_key'                   => $vendorId,
            'vendor_id'                    => $vendorId,
            'vendor_name'                  => $identity['vendor_name'],
            'id'                           => $vendorId,
            'name'                         => $identity['vendor_name'],
            'aliases'                      => $identity['aliases'] ?? [],
            'is_generic'                   => $isGeneric,
            'resolved_by'                  => $identity['resolved_by'] ?? null,
            'warnings'                     => $identity['warnings'] ?? [],
            'target_period'                => $targetMoney,
            'target_month'                 => Support::money((float) ($planRow['target_month'] ?? 0)),
            'goal'                         => $targetMoney,
            'realized_period'              => $realizedOfficial,
            'realizado_vendedor'           => $realizedOfficial,
            'realized'                     => $realizedOfficial,
            'mercadoria_faturada_vendedor' => Support::money($merchandiseTotal),
            'invoice_total'                => $realizedOfficial,
            'merchandise_total'            => Support::money($merchandiseTotal),
            'gap_to_target'                => $gapToTarget,
            'gap'                          => $gapToTarget,
            'attainment_pct'               => $attainmentPct,
            'attainment'                   => $attainmentPct,
            'forecast_period'              => Support::money((float) ($planRow['forecast_period'] ?? $invoiceTotal)),
            'forecasts'                    => $planRow['forecasts'] ?? [],
            'risk_status'                  => $planRow['risk_status'] ?? ($targetPeriod > 0 ? 'OK' : 'SEM_META'),
            'new_daily_target'             => Support::money((float) ($planRow['new_daily_target'] ?? 0)),
            'line_85_value'                => Support::money((float) ($planRow['line_85_value'] ?? ($targetPeriod * 0.85))),
            'line_85_gap'                  => Support::money((float) ($planRow['line_85_gap'] ?? max($targetPeriod * 0.85 - $invoiceTotal, 0))),
            'ticket_avg'                   => $invoicesCount > 0 ? Support::money($invoiceTotal / $invoicesCount) : 0.0,
        ], $planRow);

        // Garantir aliases corretos após merge com planRow
        $vendorPayload['id']                = (string) $vendorId;
        $vendorPayload['name']              = (string) $identity['vendor_name'];
        $vendorPayload['realized_period']   = $realizedOfficial;
        $vendorPayload['realizado_vendedor']= $realizedOfficial;
        $vendorPayload['realized']          = $realizedOfficial;
        $vendorPayload['goal']              = $targetMoney;
        $vendorPayload['gap']               = $gapToTarget;
        $vendorPayload['attainment']        = $attainmentPct;

        // ── 11. Montagem final ───────────────────────────────────────────────
        $dataQuality = $invoicesCount > 0 || count($allPipeline) > 0
            ? 'operational'
            : ($targetPeriod > 0 ? 'meta_only' : 'empty');

        return [
            'ok'     => true,
            'source' => 'canonical',
            'mode'   => 'canonical',

            'vendor' => $vendorPayload,

            'period' => ['start' => $start, 'end' => $end],

            'summary' => [
                // Valores
                'realized_total'      => $realizedOfficial,
                'merchandise_total'   => Support::money($merchandiseTotal),
                'discount_total'      => Support::money($discountTotal),
                'cancelled_total'     => Support::money($cancelledTotal),
                'open_total'          => Support::money($openTotal),
                // Contadores separados
                'invoices_count'      => $invoicesCount,
                'linked_orders_count' => count($linkedOrders),
                'wallet_orders_count' => count($walletOrders),
                'cancelled_count'     => count($allCancelled),
                'customers_count'     => count($customerSet),
                'products_count'      => count($products),
                'ticket_avg'          => $invoicesCount > 0 ? Support::money($invoiceTotal / $invoicesCount) : 0.0,
                // Alias legado — orders_count era ambíguo; agora aponta para linked_orders_count.
                // wallet_orders_count é o campo correto para a carteira.
                'orders_count'        => count($linkedOrders),
                'orders_count_legacy' => true,
                'deprecated_fields'   => [
                    'orders_count' => 'Use linked_orders_count (pedidos com NF via bridge) ou wallet_orders_count (carteira sem NF)',
                ],
                'gross_total'         => $realizedOfficial,
                'invoice_total'       => $realizedOfficial,
            ],

            'targets' => [
                'target_period'  => $targetMoney,
                'target_month'   => Support::money((float) ($planRow['target_month'] ?? 0)),
                'attainment_pct' => $attainmentPct,
                'gap_to_target'  => $gapToTarget,
                'risk_status'    => $planRow['risk_status'] ?? 'SEM_META',
                'target_status'  => $targetPeriod > 0 ? 'found' : 'missing',
            ],

            // Listas principais
            'invoices'           => array_slice($allInvoices, 0, 50),
            'linked_orders'      => $linkedOrders,
            'wallet_orders'      => array_slice($walletOrders, 0, 50),
            'cancelled_invoices' => array_slice($allCancelled, 0, 30),
            'customers'          => $topCustomers,
            'products'           => $products,
            'daily_sales'        => $dailySales,

            // Aliases legado para backward-compat
            'orders'           => array_slice($walletOrders, 0, 30),
            'pipeline_orders'  => array_slice($allPipeline, 0, 30),
            'series'           => $dailySales,

            'data_quality' => $dataQuality,

            'meta' => [
                'source_of_truth'      => 'canonical',
                'realized_source_table'=> 'biomie_notas_fiscais_canonicas',
                'bridge_source_table'  => 'mart_bridge_pedido_nfe_titulo',
                'target_source_table'  => 'biomie_sales_targets_monthly',
                'realized_filter'      => [
                    'status_fiscal' => 'autorizada',
                    'date_field'    => 'data_competencia',
                    'start'         => $start,
                    'end'           => $end,
                ],
                'join_key'             => 'vendor_id/codigo_vendedor_omie',
                'value_basis'          => 'invoice_total',
                'period_basis'          => 'data_competencia',   // backward compat (realized)
                'realized_period_basis' => 'data_competencia',   // NFs autorizadas — data_competencia
                'wallet_period_basis'   => 'data_emissao',       // carteira — data_emissao do pedido
                'target_period_basis'   => 'year_month',         // metas — competencia mensal
                'generated_at'         => date(DATE_ATOM),
                'last_sync'            => $lastSync,
                'last_success_at'      => $lastSync,
                'identity_resolution'  => $identity,
                'plan_diagnostics'     => $planDiagnostics,
                'bridge_available'     => $bridgeAvailable,
                'linked_source'        => $linkedSource,
                'linked_source_hint'   => match(true) {
                    $linkedSource !== 'nf_fallback' => 'mart_bridge_pedido_nfe_titulo',
                    !$bridgeAvailable               => 'Bridge nao disponivel para o periodo (mart nao materializado). Linked orders derivados das proprias NFs via pedido_id_omie. Execute gold_mart_refresh apos sincronizar pedidos.',
                    default                         => 'Bridge disponivel mas vazia para este vendedor no periodo. NFs existem sem pedido canonico correspondente. Ressincronize biomie_pedidos_canonicos e rematerialize mart_bridge_pedido_nfe_titulo.',
                },
                'mart_bridge_count'    => count($linkedOrders) + count($walletOrders),
                'invoices_count'       => $invoicesCount,
                'linked_orders_count'  => count($linkedOrders),
                'products_count'       => count($products),
                'wallet_orders_count'  => count($walletOrders),
                'request_id'           => $requestId,
            ],
        ];
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $rows */
    private function filterByIdentity(array $rows, array $identity): array
    {
        return array_values(
            array_filter($rows, fn (array $row): bool => $this->rowMatchesIdentity($row, $identity))
        );
    }

    /** @param array<string,mixed> $row */
    private function rowMatchesIdentity(array $row, array $identity): bool
    {
        $isGeneric   = (bool) ($identity['is_generic'] ?? false);
        $rowVendorId = trim((string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
        $rowVendorName = trim((string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? ''));

        if ($isGeneric) {
            return $rowVendorId === '' || $this->normalizeVendorName($rowVendorName) === 'sem vendedor';
        }

        $vendorId = trim((string) ($identity['vendor_id'] ?? ''));
        if ($vendorId !== '' && $rowVendorId !== '' && $rowVendorId === $vendorId) {
            return true;
        }

        $vendorName = trim((string) ($identity['vendor_name'] ?? ''));
        return $vendorName !== ''
            && $rowVendorName !== ''
            && $this->normalizeVendorName($rowVendorName) === $this->normalizeVendorName($vendorName);
    }

    private function normalizeVendorName(string $value): string
    {
        $decoded    = str_contains($value, '%') ? rawurldecode($value) : $value;
        $normalized = trim(mb_strtolower($decoded));
        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    /** @param list<array<string,mixed>> $invoices */
    private function buildTopCustomers(array $invoices, int $limit): array
    {
        $map = [];
        foreach ($invoices as $row) {
            $key = trim((string) ($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? $row['cliente'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = (string) ($row['cliente'] ?? $row['customer_name'] ?? $key);
            $date  = (string) ($row['competencia_date'] ?? $row['data_competencia'] ?? $row['status_date'] ?? '');
            $value = (float) ($row['gross_total'] ?? $row['total'] ?? 0);

            $map[$key]['id']           = $key;
            $map[$key]['label']        = $label;
            $map[$key]['client_name']  = $label;
            $map[$key]['value']        = (float) ($map[$key]['value'] ?? 0) + $value;
            $map[$key]['invoice_total']= $map[$key]['value'];
            $map[$key]['orders']       = ((int) ($map[$key]['orders'] ?? 0)) + 1;
            $map[$key]['notes_count']  = $map[$key]['orders'];
            if ($date !== '' && $date > (string) ($map[$key]['last_purchase'] ?? '')) {
                $map[$key]['last_purchase'] = $date;
            }
        }

        uasort($map, static fn ($a, $b) => $b['value'] <=> $a['value']);

        $result = [];
        foreach (array_slice($map, 0, $limit, true) as $data) {
            $notes = (int)   ($data['notes_count'] ?? 0);
            $value = (float) ($data['value'] ?? 0);
            $result[] = [
                'id'           => $data['id'],
                'label'        => $data['label'],
                'client_name'  => $data['client_name'],
                'value'        => Support::money($value),
                'invoice_total'=> Support::money($value),
                'orders'       => $notes,
                'notes_count'  => $notes,
                'ticket_avg'   => $notes > 0 ? Support::money($value / $notes) : 0.0,
                'last_purchase'=> $data['last_purchase'] ?? null,
            ];
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $invoices */
    private function buildDailySales(string $start, string $end, array $invoices): array
    {
        $daily = [];
        foreach (Support::eachDate($start, $end) as $date) {
            $daily[$date] = [
                'date'          => $date,
                'gross_revenue' => 0.0,
                'invoice_total' => 0.0,
                'orders'        => 0,
                'notes_count'   => 0,
            ];
        }

        foreach ($invoices as $row) {
            $date = (string) ($row['competencia_date'] ?? $row['data_competencia'] ?? $row['status_date'] ?? '');
            if ($date === '' || !isset($daily[$date])) {
                continue;
            }
            $value = (float) ($row['gross_total'] ?? $row['total'] ?? 0);
            $daily[$date]['gross_revenue'] += $value;
            $daily[$date]['invoice_total'] += $value;
            $daily[$date]['orders']++;
            $daily[$date]['notes_count']++;
        }

        foreach ($daily as &$row) {
            $row['gross_revenue'] = Support::money((float) $row['gross_revenue']);
            $row['invoice_total'] = Support::money((float) $row['invoice_total']);
        }
        unset($row);

        return array_values($daily);
    }
}
