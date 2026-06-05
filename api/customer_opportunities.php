<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\DataPlatform\Contracts\CustomerSourceContracts;
use BIomie\DataPlatform\Query\CustomerQuery;
use BIomie\DataPlatform\ReadModel\CustomerReadModelFactory;
use BIomie\DataPlatform\Support\ReadModelPerformanceLogger;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config */
Auth::requireAnyCapability(['sales.view', 'geo.view', 'admin.view'], true);

try {
    $startedAt = microtime(true);
    $query = CustomerQuery::fromRequest($_GET);
    $model = CustomerReadModelFactory::make($config);
    $payload = $model->opportunities($query)->toArray();
    $payload['source_contract'] = CustomerSourceContracts::OPPORTUNITIES;
    $payload['request_id'] = RequestContext::id();

    ReadModelPerformanceLogger::logCustomer(
        $config,
        $model,
        $query,
        'customer_opportunities',
        CustomerSourceContracts::OPPORTUNITIES,
        'readmodel',
        $startedAt,
        $payload
    );

    Http::json($payload);
} catch (Throwable $exception) {
    global $logger;
    $logger?->error('customer_opportunities api error', [
        'exception' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'error' => 'customer_opportunities_error',
        'message' => 'Nao foi possivel carregar oportunidades de clientes agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
