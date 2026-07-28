<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/Modules/Sales/Application/Overview/OverviewDetailBuilder.php';

use BIomie\Auth;
use BIomie\Commercial\CommercialScopeDeniedException;
use BIomie\Http;
use BIomie\Http\ErrorBoundary;
use BIomie\Modules\Sales\Application\Overview\OverviewDetailBuilder;
use BIomie\RequestContext;
use BIomie\Support\VendorScopeFilter;

ErrorBoundary::runApi(function (): void {
    Auth::requireCapability('sales.view', true);

    $chartId = trim((string) ($_GET['chart_id'] ?? ''));
    $start = trim((string) ($_GET['start'] ?? $_GET['date_from'] ?? ''));
    $end = trim((string) ($_GET['end'] ?? $_GET['date_to'] ?? ''));

    if ($chartId === '') {
        Http::validationError('Parametro chart_id obrigatorio.', ['param' => 'chart_id']);
    }
    if ($start === '' || $end === '') {
        Http::validationError('Parametros start/end obrigatorios.', ['start' => $start, 'end' => $end]);
    }
    Http::validateDateRange($start, $end);

    $supported = OverviewDetailBuilder::supportedChartIds();
    if (!in_array($chartId, $supported, true)) {
        Http::json([
            'ok' => false,
            'error' => 'unsupported_chart_id',
            'message' => 'Este painel ainda nao possui detalhamento configurado.',
            'chart_id' => $chartId,
            'supported' => $supported,
            'request_id' => RequestContext::id(),
        ], 422);
    }

    try {
        $requestedVendorIds = VendorScopeFilter::normalizeRequestedIds(
            $_GET['vendor_ids'] ?? ($_GET['vendor_id'] ?? '')
        );
    } catch (\InvalidArgumentException) {
        Http::validationError('Filtro de vendedores invalido.', ['param' => 'vendor_ids']);
    }

    $sortBy = trim((string) ($_GET['sort_by'] ?? $_GET['sort'] ?? ''));
    $sortOrder = strtolower(trim((string) ($_GET['sort_order'] ?? $_GET['direction'] ?? '')));
    if ($sortOrder !== '' && !in_array($sortOrder, ['asc', 'desc'], true)) {
        Http::validationError('Direcao de ordenacao invalida.', ['param' => 'sort_order']);
    }

    $filters = array_filter([
        'date' => trim((string) ($_GET['date'] ?? '')),
        'vendor_ids' => implode(',', $requestedVendorIds),
        'vendor_id' => trim((string) ($_GET['vendor_id'] ?? '')),
        'vendor_name' => trim((string) ($_GET['vendor_name'] ?? $_GET['vendor_nome'] ?? $_GET['vendedor'] ?? '')),
        'client' => trim((string) ($_GET['client'] ?? $_GET['cliente'] ?? $_GET['customer_name'] ?? '')),
        'product' => trim((string) ($_GET['product'] ?? $_GET['produto'] ?? $_GET['product_description'] ?? '')),
        'product_code' => trim((string) ($_GET['product_code'] ?? '')),
        'customer_document' => trim((string) ($_GET['customer_document'] ?? '')),
        'customer_type' => trim((string) ($_GET['customer_type'] ?? '')),
        'search' => trim((string) ($_GET['search'] ?? $_GET['q'] ?? '')),
        'sort_by' => $sortBy,
        'sort_order' => $sortOrder,
        'nf_key' => trim((string) ($_GET['nf_key'] ?? '')),
        'bairro' => trim((string) ($_GET['bairro'] ?? '')),
        'city' => trim((string) ($_GET['city'] ?? $_GET['cidade'] ?? '')),
    ], static fn (string $v): bool => $v !== '');

    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    try {
        $builder = OverviewDetailBuilder::boot();
        $payload = $builder->build($chartId, $start, $end, $filters, $limit, $offset);
    } catch (CommercialScopeDeniedException) {
        Http::json([
            'ok' => false,
            'error' => 'commercial_scope_denied',
            'message' => 'O filtro comercial solicitado nao e autorizado.',
            'request_id' => RequestContext::id(),
        ], 403);
    }
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

    $payload['chart_id'] = $payload['chart_id'] ?? $chartId;
    $payload['request_id'] = RequestContext::id();
    $payload['meta'] = array_merge($meta, [
        'request_id' => RequestContext::id(),
        'period' => ['start' => $start, 'end' => $end],
        'filters' => $filters,
    ]);

    if (($payload['ok'] ?? true) === true && $rows === []) {
        $payload['status'] = 'empty';
        $payload['meta']['warnings'] = array_values(array_unique(array_merge(
            is_array($payload['meta']['warnings'] ?? null) ? $payload['meta']['warnings'] : [],
            ['Sem dados para este recorte.']
        )));
    } elseif (($payload['ok'] ?? true) === false) {
        $payload['status'] = $payload['status'] ?? 'warning';
        $payload['error'] = $payload['error'] ?? 'detail_warning';
    } else {
        $payload['status'] = $payload['status'] ?? 'ok';
    }

    Http::json($payload);
}, [
    'api' => 'overview_detail',
    'route' => 'api/overview_detail.php',
    'chart_id' => (string) ($_GET['chart_id'] ?? ''),
]);
