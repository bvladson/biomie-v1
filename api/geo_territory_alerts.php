<?php

declare(strict_types=1);

/**
 * api/geo_territory_alerts.php — GEO.R2.2
 *
 * Alertas comerciais automáticos por território.
 * Parâmetros:
 *   start         = YYYY-MM-DD
 *   end           = YYYY-MM-DD
 *   territory_key = chave do território
 *   territory_type = fortaleza_bairro|metro_bairro|ceara_municipio|sem_geo|sem_bairro
 *   cidade_key    = (opcional)
 *   bairro_key    = (opcional)
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Geo\GeoTerritoryAlertsService;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f     = MapFilterSupport::fromRequest($_GET);
    $start = $f['date_from'];
    $end   = $f['date_to'];

    $territory = [
        'territory_key'  => trim((string) ($_GET['territory_key']  ?? '')),
        'territory_type' => trim((string) ($_GET['territory_type'] ?? '')),
        'cidade_key'     => trim((string) ($_GET['cidade_key']     ?? '')),
        'bairro_key'     => trim((string) ($_GET['bairro_key']     ?? '')),
    ];

    if ($territory['territory_key'] === '') {
        Http::json(['ok' => false, 'error' => 'missing_territory_key', 'message' => 'territory_key obrigatório.'], 400);
    }

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);

    if (!\BIomie\Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::json(['ok' => false, 'error' => 'mart_missing', 'message' => 'mart_compradores_por_geo ausente.'], 503);
    }

    $service = new GeoTerritoryAlertsService($pdo);
    $result  = $service->getAlerts($territory, $start, $end);

    Http::json([
        'ok'           => true,
        'territory_key' => $territory['territory_key'],
        'period'       => ['start' => $start, 'end' => $end],
        'alerts'       => $result['alerts'],
        'alert_count'  => $result['alert_count'],
        'has_critical' => $result['has_critical'],
        'meta'         => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM)],
    ]);

} catch (\Throwable $e) {
    global $logger;
    $logger?->error('geo_territory_alerts error', ['msg' => $e->getMessage()]);
    Http::json(['ok' => false, 'error' => 'api_error', 'message' => 'Erro ao carregar alertas do território.', 'request_id' => RequestContext::id()], 500);
}
