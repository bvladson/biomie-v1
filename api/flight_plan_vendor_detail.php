<?php

/**
 * api/flight_plan_vendor_detail.php
 *
 * Endpoint legado do drawer Vendor 360.
 * Quando a camada canonical está disponível, delega para Vendor360ReadService
 * (mesmo contrato de api/vendor_detail.php).
 * Quando não está, usa FlightPlanService legado.
 *
 * Não chama a API Omie no caminho canonical.
 */

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\FlightPlanService;
use BIomie\FlightPlanV2Service;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;
use BIomie\TargetsRepository;
use BIomie\WorkingDays;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

try {
    $range     = Http::resolveDateRangeOrCompetencia();
    $vendorRef = rawurldecode(trim((string) Http::query('vendor_id')));

    Http::validateDateRange($range['start'], $range['end']);
    $start          = $range['start'];
    $end            = $range['end'];
    $saturdayWeight = (float) $config->get('flight_plan.saturday_weight', 0.33);

    $targetsRepository = null;
    $canonical         = null;
    $targetsConnection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

    if (Database::isConfigured($config, $targetsConnection)) {
        try {
            $pdo = Database::connect($config, $targetsConnection);
            $targetsRepository = new TargetsRepository($pdo, $logger);
            $repo = new CanonicalRepository($pdo, $logger);
            if ($repo->isAvailable()) {
                $canonical = $repo;
            }
        } catch (Throwable $e) {
            $logger->warning('Vendor Detail: conexao MySQL indisponivel.', [
                'message'    => $e->getMessage(),
                'connection' => $targetsConnection,
            ]);
        }
    }

    // ── Caminho canonical (fonte oficial) ──────────────────────────────────
    if ($canonical !== null) {
        require_once __DIR__ . '/../app/Modules/Sales/Application/Vendors/VendorIdentityResolver.php';
        require_once __DIR__ . '/../app/Modules/Sales/Application/Vendors/Vendor360ReadService.php';
        require_once __DIR__ . '/../app/Modules/Sales/Infrastructure/Vendor360ReadRepository.php';

        $vendorMap = $canonical->getVendorMap();
        $resolver  = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMap);
        $identity  = $resolver->resolve($vendorRef);

        $service = new \BIomie\Modules\Sales\Application\Vendors\Vendor360ReadService(
            $canonical, $resolver, $pdo
        );
        $payload = $service->buildPayload(
            $start, $end, $identity, $targetsRepository, $saturdayWeight, RequestContext::id()
        );

        Http::json($payload);
        return;
    }

    // ── Caminho legado (FlightPlanService) ─────────────────────────────────
    if ($vendorRef === '') {
        throw new InvalidArgumentException('vendor_id e obrigatorio.');
    }

    $service = new FlightPlanService($config, $cache, $logger, $analytics, $targetsRepository);
    $payload = $service->build($start, $end, false);

    $vendorTarget = null;
    foreach ($payload['vendors'] ?? [] as $vendor) {
        if ((string) ($vendor['vendor_id'] ?? '') === $vendorRef) {
            $vendorTarget = $vendor;
            break;
        }
    }

    if ($vendorTarget === null) {
        throw new InvalidArgumentException('Vendedor nao encontrado no plano de voo atual.');
    }

    $snapshot    = $analytics->getSnapshot($start, $end, false);
    $vendorOrders = [];
    $dailySalesMap = [];

    foreach (Support::eachDate($start, $end) as $date) {
        $dailySalesMap[$date] = ['date' => $date, 'gross_revenue' => 0.0, 'orders' => 0];
    }

    foreach ($snapshot['top_orders'] ?? [] as $order) {
        if ((string) ($order['vendor_id'] ?? '') !== $vendorRef) {
            continue;
        }

        $orderStatus = strtolower((string) ($order['stage'] ?? ''));
        $isCancelled = str_contains($orderStatus, 'cancelado')
            || str_contains($orderStatus, 'devolvid')
            || str_contains($orderStatus, 'recusad');

        if (!$isCancelled) {
            $date = (string) ($order['billing_date'] ?? $order['issue_date'] ?? '');
            if ($date !== '' && isset($dailySalesMap[$date])) {
                $dailySalesMap[$date]['gross_revenue'] += Support::toFloat($order['total'] ?? 0);
                $dailySalesMap[$date]['orders']++;
            }
        }

        $vendorOrders[] = $order;
    }

    usort(
        $vendorOrders,
        static fn (array $l, array $r): int => strcmp((string) ($r['issue_date'] ?? ''), (string) ($l['issue_date'] ?? ''))
    );

    Http::json([
        'ok'               => true,
        'source'           => 'legacy',
        'vendor'           => $vendorTarget,
        'period'           => ['start' => $start, 'end' => $end],
        'daily_sales'      => array_values($dailySalesMap),
        'invoices'         => [],
        'linked_orders'    => [],
        'wallet_orders'    => array_slice($vendorOrders, 0, 20),
        'orders'           => array_slice($vendorOrders, 0, 20),
        'cancelled_invoices' => [],
        'customers'        => [],
        'products'         => [],
        'summary'          => [],
        'meta'             => ['request_id' => RequestContext::id()],
    ]);

} catch (InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage(), 'request_id' => RequestContext::id()], 400);
} catch (Throwable $e) {
    $logger->error('Falha ao gerar Vendor Detail.', [
        'message'    => $e->getMessage(),
        'trace'      => $e->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);
    Http::json(['ok' => false, 'message' => 'Nao foi possivel carregar os detalhes do vendedor.', 'request_id' => RequestContext::id()], 500);
}
