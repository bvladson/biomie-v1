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

// O fallback SQL legado (630 linhas) foi removido em 2026-07-28.
//
// Era um SEGUNDO caminho de leitura para a mesma pergunta, com montagem de WHERE
// e escopo de tenant proprios, que so executava se o GeoReadModel lancasse. Prova
// de que estava morto em producao: 4 meses de biomie.log (29/03 a 28/07), 75
// chamadas reais a este endpoint e ZERO ocorrencias de "geo_summary readmodel
// fallback" — com o logger de erro comprovadamente funcionando no mesmo periodo.
//
// Manter duas rotas divergentes para a mesma leitura e exatamente o padrao que
// causou o vazamento cross-empresa de 27/06 (mart_ lido fora do choke-point).
// Agora o GeoReadModel e o unico caminho, e a falha e explicita em vez de
// degradar silenciosamente para SQL paralelo.
//
// `feature_flag_active` continua no payload: a flag nao decide mais nada AQUI,
// mas segue valendo para os endpoints geo ainda nao convertidos.
try {
    $startedAt = microtime(true);
    $query = GeoQuery::fromRequest($_GET);
    $geo = GeoReadModelFactory::make($config);
    $payload = $geo->legacySummaryPayload($query, Auth::canViewFullDocuments());
    $payload['read_model'] = 'GeoReadModel';
    $payload['feature_flag_active'] = FeatureFlags::geoReadModelV2($config);
    $payload['request_id'] = RequestContext::id();

    ReadModelPerformanceLogger::logGeo(
        $config,
        $geo,
        $query,
        'geo_summary',
        'geo.sales_by_territory.v1',
        'readmodel',
        $startedAt,
        $payload
    );

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('geo_summary readmodel error', [
        'exception' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'error' => 'geo_readmodel_error',
        'message' => 'Nao foi possivel carregar o resumo geografico agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
