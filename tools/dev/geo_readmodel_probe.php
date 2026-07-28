<?php

declare(strict_types=1);

/**
 * Smoke probe for the new GeoReadModel facade.
 *
 * Usage:
 *   php tools/dev/geo_readmodel_probe.php
 *   php tools/dev/geo_readmodel_probe.php --json
 */

$root = dirname(__DIR__, 2);
require $root . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $root . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$argv = $GLOBALS['argv'] ?? [];
$args = array_slice($argv, 1);
$json = in_array('--json', $args, true) || in_array('--format=json', $args, true);

$config = new \BIomie\Config();
$query = \BIomie\DataPlatform\Query\GeoQuery::fromRequest([
    'date_from' => '2026-01-01',
    'date_to' => date('Y-m-d'),
    'layer' => 'mapa_composto_ceara',
]);
$model = \BIomie\DataPlatform\ReadModel\GeoReadModelFactory::make($config);

$summary = $model->summary($query)->toArray();
$composite = $model->compositeMap($query)->toArray();
$neighborhoods = $model->legacyNeighborhoodPayload($query, [
    'date_from' => '2026-01-01',
    'date_to' => date('Y-m-d'),
    'layer' => 'mapa_composto_ceara',
]);
$territoryRequest = [
    'date_from' => '2026-01-01',
    'date_to' => date('Y-m-d'),
    'layer' => 'mapa_composto_ceara',
    'territory_type' => 'fortaleza_bairro',
    'cidade_key' => 'fortaleza',
    'bairro_key' => 'centro',
];
$territoryQuery = \BIomie\DataPlatform\Query\GeoQuery::fromRequest($territoryRequest);
$territoryInvoices = $model->legacyTerritoryInvoicesPayload($territoryQuery, $territoryRequest, false);
$territoryBuyers = $model->legacyTerritoryBuyersPayload($territoryQuery, $territoryRequest, false);
$territoryCnaes = $model->legacyTerritoryCnaesPayload($territoryQuery, $territoryRequest);

$payload = [
    'ok' => true,
    'summary_contract' => $summary['meta']['source']['contract'] ?? null,
    'map_contract' => $composite['meta']['source']['contract'] ?? null,
    'summary_revenue' => (float) (($summary['data']['summary']['revenue'] ?? 0)),
    'summary_customers' => (int) (($summary['data']['summary']['customers'] ?? 0)),
    'segments' => count($summary['data']['segments'] ?? []),
    'top_buyers' => count($summary['data']['top_buyers'] ?? []),
    'top_cnaes' => count($summary['data']['top_cnaes'] ?? []),
    'territories' => count($composite['data']['territories'] ?? []),
    'ranking' => count($composite['data']['ranking'] ?? []),
    'neighborhood_rows' => count($neighborhoods['data'] ?? []),
    'territory_invoices' => count($territoryInvoices['items'] ?? []),
    'territory_buyers' => count($territoryBuyers['data'] ?? []),
    'territory_cnaes' => count($territoryCnaes['items'] ?? []),
    'warnings' => $summary['warnings'] ?? [],
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

echo "GeoReadModel probe\n";
echo "Summary contract: {$payload['summary_contract']}\n";
echo "Map contract: {$payload['map_contract']}\n";
echo "Revenue: {$payload['summary_revenue']}\n";
echo "Customers: {$payload['summary_customers']}\n";
echo "Segments: {$payload['segments']} | buyers: {$payload['top_buyers']} | cnaes: {$payload['top_cnaes']}\n";
echo "Territories: {$payload['territories']} | ranking: {$payload['ranking']}\n";
echo "Neighborhood rows: {$payload['neighborhood_rows']} | territory invoices: {$payload['territory_invoices']} | territory buyers: {$payload['territory_buyers']} | territory cnaes: {$payload['territory_cnaes']}\n";
if ($payload['warnings'] !== []) {
    echo "Warnings:\n";
    foreach ($payload['warnings'] as $warning) {
        echo "  - {$warning}\n";
    }
}
