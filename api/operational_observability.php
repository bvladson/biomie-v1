<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Config;
use BIomie\Http;
use BIomie\Logger;
use BIomie\OperationalObservabilityService;
use BIomie\Telemetry;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('logs.view', true);

try {
    $telemetry = Telemetry::boot($config, $logger);
    $telemetry->startRequestSpan('http.operational_observability', [
        'domain_name' => 'observability',
        'source_name' => 'api',
    ]);

    $hours = max(1, min((int) ($_GET['hours'] ?? 24), 720));
    $service = OperationalObservabilityService::boot($config, $logger);
    $payload = [
        'ok' => true,
        'dashboard' => $service->buildDashboard($hours),
    ];

    Http::json($payload);
} catch (\InvalidArgumentException $exception) {
    Http::json([
        'ok' => false,
        'message' => $exception->getMessage(),
        'request_id' => \BIomie\RequestContext::id(),
    ], 400);
} catch (Throwable $exception) {
    $logger->error('Falha ao carregar dashboard operacional de observabilidade.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao carregar observabilidade operacional.',
        'request_id' => \BIomie\RequestContext::id(),
    ], 500);
}
