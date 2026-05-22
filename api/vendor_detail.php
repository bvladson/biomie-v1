<?php

/**
 * api/vendor_detail.php — Fonte oficial do drawer Vendor 360.
 *
 * Usa exclusivamente dados locais (canonical + marts):
 *   - biomie_notas_fiscais_canonicas   → invoices, realized
 *   - biomie_notas_fiscais_itens_canonicos → products
 *   - mart_bridge_pedido_nfe_titulo    → linked_orders, wallet_orders
 *   - biomie_sales_targets_monthly     → targets
 *
 * Não chama a API Omie.
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\TargetsRepository;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('sales.view', true);

try {
    $range     = Http::resolveDateRangeOrCompetencia();
    $vendorRef = rawurldecode(trim((string) Http::query('vendor_id')));

    if ($vendorRef === '') {
        Http::json(['ok' => false, 'message' => 'vendor_id e obrigatorio.', 'request_id' => RequestContext::id()], 400);
        return;
    }

    Http::validateDateRange($range['start'], $range['end']);
    $start = $range['start'];
    $end   = $range['end'];

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados nao configurado.', 'request_id' => RequestContext::id()], 503);
        return;
    }

    $pdo  = Database::connect($config, $conn);
    $repo = new CanonicalRepository($pdo, $logger);

    if (!$repo->isAvailable()) {
        Http::json(['ok' => false, 'message' => 'Camada canonical indisponivel.', 'request_id' => RequestContext::id()], 503);
        return;
    }

    require_once __DIR__ . '/../app/Modules/Sales/Application/Vendors/VendorIdentityResolver.php';
    require_once __DIR__ . '/../app/Modules/Sales/Application/Vendors/Vendor360ReadService.php';
    require_once __DIR__ . '/../app/Modules/Sales/Infrastructure/Vendor360ReadRepository.php';

    $vendorMap = $repo->getVendorMap();
    $resolver  = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMap);
    $identity  = $resolver->resolve($vendorRef);

    $targetsRepository = null;
    try {
        $targetsRepository = new TargetsRepository($pdo, $logger);
    } catch (Throwable $e) {
        $logger->warning('Vendor Detail: metas indisponiveis.', [
            'message'    => $e->getMessage(),
            'request_id' => RequestContext::id(),
        ]);
    }

    $saturdayWeight = (float) $config->get('flight_plan.saturday_weight', 0.33);

    $service = new \BIomie\Modules\Sales\Application\Vendors\Vendor360ReadService($repo, $resolver, $pdo);
    $payload = $service->buildPayload($start, $end, $identity, $targetsRepository, $saturdayWeight, RequestContext::id());

    Http::json($payload);

} catch (InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage(), 'request_id' => RequestContext::id()], 400);
} catch (Throwable $e) {
    $logger->error('Falha no vendor_detail.', ['message' => $e->getMessage(), 'request_id' => RequestContext::id()]);
    Http::json(['ok' => false, 'message' => 'Nao foi possivel carregar os dados do vendedor.', 'request_id' => RequestContext::id()], 500);
}
