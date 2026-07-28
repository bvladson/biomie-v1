<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\ReadModel\GeoReadModelFactory;
use BIomie\DataPlatform\Support\FeatureFlags;
use BIomie\DataPlatform\Support\ReadModelPerformanceLogger;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\GlobalHomeDataGuard;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);
GlobalHomeDataGuard::requireUnrestricted();

// Fallback SQL legado (390 linhas) removido em 2026-07-28 — mesma decisao e mesma
// prova de geo_summary.php: 4 meses de biomie.log (29/03 a 28/07), 64 chamadas
// reais a este endpoint e ZERO ocorrencias de "sales_by_neighborhood readmodel
// fallback". O GeoReadModel e o unico caminho; falha agora e explicita.
try {
    $startedAt = microtime(true);
    $query = GeoQuery::fromRequest($_GET);
    $geo = GeoReadModelFactory::make($config);
    $payload = $geo->legacyNeighborhoodPayload($query, $_GET);
    $payload['feature_flag_active'] = FeatureFlags::geoReadModelV2($config);
    $payload['request_id'] = RequestContext::id();

    ReadModelPerformanceLogger::logGeo(
        $config,
        $geo,
        $query,
        'sales_by_neighborhood',
        'geo.sales_by_neighborhood.v1',
        'readmodel',
        $startedAt,
        $payload
    );

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('sales_by_neighborhood readmodel error', [
        'exception' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'error' => 'geo_readmodel_error',
        'message' => 'Nao foi possivel carregar bairros agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
