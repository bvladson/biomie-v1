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
Auth::requireAnyCapability(['sales.view', 'fiscal.view', 'geo.view', 'finance.view', 'admin.view'], true);

try {
    $startedAt = microtime(true);
    $query = CustomerQuery::fromRequest($_GET);
    $model = CustomerReadModelFactory::make($config);
    $payload = $model->profile(
        $query,
        method_exists(Auth::class, 'canViewFullDocuments') ? Auth::canViewFullDocuments() : false
    )->toArray();
    $payload['source_contract'] = CustomerSourceContracts::PROFILE;
    $payload['request_id'] = RequestContext::id();

    ReadModelPerformanceLogger::logCustomer(
        $config,
        $model,
        $query,
        'customer_profile',
        CustomerSourceContracts::PROFILE,
        'readmodel',
        $startedAt,
        $payload
    );

    Http::json($payload);
} catch (Throwable $exception) {
    global $logger;
    $logger?->error('customer_profile api error', [
        'exception' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'error' => 'customer_profile_error',
        'message' => 'Nao foi possivel carregar o perfil do cliente agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
