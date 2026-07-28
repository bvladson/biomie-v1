<?php

declare(strict_types=1);

/**
 * Vendas por CNAE para mapa e drilldowns.
 *
 * CNAE.R1: endpoint adaptador, sem SQL de negocio local.
 */

use BIomie\Auth;
use BIomie\DataPlatform\Contracts\CnaeSourceContracts;
use BIomie\DataPlatform\Query\CnaeQuery;
use BIomie\DataPlatform\ReadModel\CnaeReadModelFactory;
use BIomie\DataPlatform\Support\ReadModelPerformanceLogger;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config */
Auth::requireModule('geo', true);

// GEO.R1.9.4 guard: cidade_key fallback agora e normalizado em CnaeQuery.

try {
    $startedAt = microtime(true);
    $query = CnaeQuery::fromRequest($_GET);
    $model = CnaeReadModelFactory::make($config);
    $payload = $model->legacySalesByCnaePayload($query);
    $payload['request_id'] = RequestContext::id();

    ReadModelPerformanceLogger::logCnae(
        $config,
        $model,
        $query,
        'sales_by_cnae',
        CnaeSourceContracts::RANKING,
        'readmodel_legacy_adapter',
        $startedAt,
        $payload
    );

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('sales_by_cnae api error', ['exception' => $exception->getMessage()]);
    Http::json([
        'ok' => false,
        'error' => 'api_error',
        'message' => 'Nao foi possivel carregar vendas por CNAE agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
