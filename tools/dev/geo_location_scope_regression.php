<?php

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\GeoLocationScope;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$failures = [];
$strict = GeoLocationScope::cityPredicate('m', ':city');
if (str_contains(strtolower($strict), 'is null') || str_contains($strict, "TRIM(m.cidade_norm) = ''")) {
    $failures[] = 'Named-city predicate accepts an empty city.';
}

$files = [
    'app/Geo/GeoSalesLayerBuilder.php',
    'api/geo_summary.php',
    'api/sales_by_neighborhood.php',
    'api/top_buyers_by_geo.php',
    'app/AdminConsole/Commands/GeoMapStatusCommand.php',
    'app/Support/GeoTraceDiagnostics.php',
];
$badPattern = "/LOWER\\s*\\([^\\n]*cidade_norm[^\\n]*\\)\\s*=\\s*:[a-z_]+\\s+OR\\s+[^\\n]*cidade_norm\\s+IS\\s+NULL/i";
foreach ($files as $file) {
    $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    if ($contents !== false && preg_match($badPattern, $contents) === 1) {
        $failures[] = "{$file} still conflates an empty city with a named city.";
    }
}

$pdo = Database::connect(new Config(), 'db');
$stmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN " . GeoLocationScope::cityPredicate('m', ':city_named') . " THEN 1 ELSE 0 END) AS named_city,
        SUM(CASE WHEN m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '' THEN 1 ELSE 0 END) AS no_city,
        SUM(CASE WHEN " . GeoLocationScope::cityPredicate('m', ':city_overlap') . "
                  AND (m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '')
                 THEN 1 ELSE 0 END) AS overlap
     FROM mart_compradores_por_geo m"
);
$stmt->execute([':city_named' => 'fortaleza', ':city_overlap' => 'fortaleza']);
$counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
if ((int) ($counts['overlap'] ?? -1) !== 0) {
    $failures[] = 'Named-city and no-city scopes overlap in the mart.';
}

echo json_encode([
    'ok' => $failures === [],
    'failures' => $failures,
    'counts' => array_map('intval', $counts),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
