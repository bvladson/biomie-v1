<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;
use BIomie\Modules\Customers\Application\CustomerDetail\CustomerDetailBuilder;

require __DIR__ . '/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

Auth::requireAnyCapability([
    'sales.view',
    'fiscal.view',
    'geo.view',
    'finance.view',
    'admin.view',
], true);

try {
    $filters = MapFilterSupport::fromRequest($_GET);

    $input = $_GET;
    $input['start'] = $filters['date_from'];
    $input['end'] = $filters['date_to'];
    $input['date_from'] = $filters['date_from'];
    $input['date_to'] = $filters['date_to'];
    $input['period_start'] = $filters['date_from'];
    $input['period_end'] = $filters['date_to'];
    $input['city_norm'] = $filters['city'] ?? '';
    $input['bairro_norm'] = $filters['neighborhood'] ?? '';
    $input['city'] = $input['city'] ?? $input['cidade'] ?? ($filters['filters_received']['city'] ?? '');
    $input['bairro'] = $input['bairro'] ?? $input['neighborhood'] ?? ($filters['filters_received']['neighborhood'] ?? '');
    $input['include'] = trim((string) ($_GET['include'] ?? 'orders,invoices,items,finance,timeline'));

    [$pdo] = Database::getGeoConnection($config);
    $builder = new CustomerDetailBuilder($pdo);
    $payload = $builder->build($input);

    $payload['meta']['request_id'] = RequestContext::id();
    $payload['meta']['generated_at'] = date(DATE_ATOM);

    if (!Auth::canViewFullDocuments()) {
        $payload = maskCustomerDocuments($payload);
    }

    Http::json($payload);
} catch (Throwable $exception) {
    global $logger;
    $logger?->error('customer_detail api error', [
        'exception' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'error' => 'api_error',
        'message' => 'Nao foi possivel carregar o Cliente 360 agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function maskCustomerDocuments(array $payload): array
{
    if (isset($payload['customer']) && is_array($payload['customer'])) {
        $doc = (string) ($payload['customer']['documento'] ?? '');
        if ($doc !== '') {
            $payload['customer']['documento'] = DocumentMask::maskDocument($doc, 'Nao informado') ?? 'Nao informado';
        }
    }

    if (isset($payload['meta']['diagnostics']) && is_array($payload['meta']['diagnostics'])) {
        foreach (['cliente_documento_recebido', 'documento_norm_recebido'] as $key) {
            $doc = (string) ($payload['meta']['diagnostics'][$key] ?? '');
            if ($doc !== '') {
                $payload['meta']['diagnostics'][$key] = DocumentMask::maskDocument($doc, 'Nao informado') ?? 'Nao informado';
            }
        }
    }

    return $payload;
}
