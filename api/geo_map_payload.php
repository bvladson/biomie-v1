<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\ReadModel\GeoReadModelFactory;
use BIomie\DataPlatform\Support\FeatureFlags;
use BIomie\DataPlatform\Support\ReadModelPerformanceLogger;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $startedAt = microtime(true);
    $query = GeoQuery::fromRequest($_GET);
    $geo = GeoReadModelFactory::make($config);
    $response = $geo->mapPayload($query)->toArray();

    $response['source'] = 'gold';
    $response['source_contract'] = 'geo.map_payload.v1';
    $response['request_id'] = RequestContext::id();
    $response['feature_flags'] = [
        'geo_readmodel_v2' => FeatureFlags::geoReadModelV2($config),
        'geo_single_payload' => FeatureFlags::geoSinglePayload($config),
    ];

    ReadModelPerformanceLogger::logGeo(
        $config,
        $geo,
        $query,
        'geo_map_payload',
        'geo.map_payload.v1',
        'single_payload',
        $startedAt,
        $response
    );

    Http::json($response);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('geo_map_payload api error', ['exception' => $exception->getMessage()]);

    Http::json([
        'ok' => false,
        'error' => 'geo_map_payload_error',
        'message' => 'Nao foi possivel carregar o payload consolidado do mapa agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
