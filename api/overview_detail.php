<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/Modules/Sales/Application/Overview/OverviewDetailBuilder.php';

use BIomie\Auth;
use BIomie\Http;
use BIomie\Http\ErrorBoundary;
use BIomie\Modules\Sales\Application\Overview\OverviewDetailBuilder;
use BIomie\RequestContext;

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

    $filters = array_filter([
        'date' => trim((string) ($_GET['date'] ?? '')),
        'vendor_id' => trim((string) ($_GET['vendor_id'] ?? '')),
        'vendor_name' => trim((string) ($_GET['vendor_name'] ?? '')),
        'product_code' => trim((string) ($_GET['product_code'] ?? '')),
        'customer_document' => trim((string) ($_GET['customer_document'] ?? '')),
        'nf_key' => trim((string) ($_GET['nf_key'] ?? '')),
        'bairro' => trim((string) ($_GET['bairro'] ?? '')),
        'city' => trim((string) ($_GET['city'] ?? $_GET['cidade'] ?? '')),
    ], static fn (string $v): bool => $v !== '');

    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $builder = OverviewDetailBuilder::boot();
    $payload = $builder->build($chartId, $start, $end, $filters, $limit, $offset);
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
