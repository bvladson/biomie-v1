<?php

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\GeoMapSupport;
use BIomie\Bootstrap;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$options = getopt('', ['start:', 'end:', 'city:', 'json']);

$start = $options['start'] ?? date('Y-m-01');
$end = $options['end'] ?? date('Y-m-t');
$city = $options['city'] ?? 'Fortaleza';
$isJson = isset($options['json']);

$connectionData = Database::getGeoConnection($config);
$pdo = $connectionData[0];

if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
    if ($isJson) {
        echo json_encode(['error' => 'Table mart_compradores_por_geo missing']) . "\n";
    } else {
        echo "ERROR: Table mart_compradores_por_geo missing\n";
    }
    exit(1);
}

// 1. Get Mart Data
$sql = "
    SELECT
        COALESCE(NULLIF(bairro_norm, ''), 'Nao informado') AS neighborhood,
        SUM(faturamento) AS revenue
    FROM mart_compradores_por_geo
    WHERE competencia BETWEEN :start AND :end
      AND LOWER(cidade_norm) = :city
    GROUP BY COALESCE(NULLIF(bairro_norm, ''), 'Nao informado')
    ORDER BY revenue DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'start' => substr($start, 0, 7),
    'end' => substr($end, 0, 7),
    'city' => GeoMapSupport::normalizeKey($city),
]);
$martData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Normalize the mart data
$martMap = [];
foreach ($martData as $row) {
    $key = GeoMapSupport::normalizeKey((string)$row['neighborhood']);
    $martMap[$key] = [
        'original' => $row['neighborhood'],
        'key' => $key,
        'revenue' => (float)$row['revenue'],
        'matched' => false,
    ];
}

// 2. Get GeoJSON Data
$geoJsonPath = dirname(__DIR__, 2) . '/assets/geo/fortaleza_bairros.json';
$geoJsonMap = [];

if (file_exists($geoJsonPath)) {
    $geoJson = json_decode(file_get_contents($geoJsonPath), true);
    $features = $geoJson['features'] ?? [];
    $candidates = ['neighborhood', 'bairro', 'bairro_nome', 'Nome', 'nome', 'NM_BAIRRO', 'name'];

    foreach ($features as $f) {
        $props = $f['properties'] ?? [];
        $name = '';
        foreach ($candidates as $c) {
            if (isset($props[$c]) && trim((string)$props[$c]) !== '') {
                $name = trim((string)$props[$c]);
                break;
            }
        }
        $key = GeoMapSupport::normalizeKey($name);
        $geoJsonMap[$key] = [
            'original' => $name,
            'key' => $key,
            'matched' => false,
        ];
    }
}

// 3. Match
$matches = [];
$unmatchedData = [];
$unmatchedGeo = [];

foreach ($martMap as $key => &$martInfo) {
    if ($key === 'nao-informado' || $key === '') {
        $unmatchedData[] = $martInfo;
        continue;
    }
    
    if (isset($geoJsonMap[$key])) {
        $martInfo['matched'] = true;
        $geoJsonMap[$key]['matched'] = true;
        $matches[] = [
            'key' => $key,
            'mart_name' => $martInfo['original'],
            'geo_name' => $geoJsonMap[$key]['original'],
            'revenue' => $martInfo['revenue'],
        ];
    } else {
        $unmatchedData[] = $martInfo;
    }
}
unset($martInfo);

foreach ($geoJsonMap as $key => $geoInfo) {
    if (!$geoInfo['matched']) {
        $unmatchedGeo[] = $geoInfo;
    }
}

// 4. Output
$output = [
    'period' => ['start' => $start, 'end' => $end],
    'city' => $city,
    'mart_neighborhoods_count' => count($martMap),
    'geojson_neighborhoods_count' => count($geoJsonMap),
    'matches_count' => count($matches),
    'unmatched_data_neighborhoods_count' => count($unmatchedData),
    'unmatched_geojson_neighborhoods_count' => count($unmatchedGeo),
    'top_20_by_revenue' => array_slice($martMap, 0, 20),
    'unmatched_data_neighborhoods' => $unmatchedData,
    'recommendation' => count($unmatchedData) > 0 ? 'Review unmatched data neighborhoods and either map them or update GeoJSON' : 'All good',
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "--- GEO MAP MATCH AUDIT ---\n";
    echo "Period: {$start} to {$end}\n";
    echo "City: {$city}\n";
    echo "Mart Neighborhoods: {$output['mart_neighborhoods_count']}\n";
    echo "GeoJSON Neighborhoods: {$output['geojson_neighborhoods_count']}\n";
    echo "Matches: {$output['matches_count']}\n";
    echo "Unmatched Data Neighborhoods: {$output['unmatched_data_neighborhoods_count']}\n";
    echo "Recommendation: {$output['recommendation']}\n";
}
