<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\GeoAnalyticsService;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $range = Http::resolveDateRangeOrCompetencia();
    Http::validateDateRange($range['start'], $range['end']);
    
    $start = $range['start'];
    $end = $range['end'];
    $uf = trim((string) Http::query('uf', 'CE'));
    $level = trim((string) Http::query('level', $uf === 'ALL' ? 'uf' : 'city'));

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];
    $sourceDb = $connectionData[1];
    $service = new GeoAnalyticsService($pdo, $logger);
    $payload = $service->salesByCity($start, $end, $uf, $level);
    $rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    $isEmpty = $rows === [];

    Http::json([
        'ok' => true,
        'period' => $range,
        'target' => $uf === 'ALL' && $level === 'uf' ? 'UFS' : 'CIDADES',
        'data' => $rows,
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'scope' => $meta['scope'] ?? ($uf === 'ALL' ? 'ufs' : 'cidade_ce'),
            'source_mode' => $meta['source_mode'] ?? 'canonical_fallback',
            'empty' => $isEmpty,
            'empty_reason' => $isEmpty ? 'no_geo_aggregate_for_period' : null,
            'pipeline_ready' => (bool) ($meta['pipeline_ready'] ?? false),
            'source_db' => $sourceDb
        ],
    ]);
} catch (\Throwable $exception) {
    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao carregar cidades/ufs.',
        'request_id' => RequestContext::id(),
    ], 500);
}
