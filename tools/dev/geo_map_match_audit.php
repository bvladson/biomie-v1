<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\NeighborhoodNormalizer;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['start:', 'end:', 'city:', 'json']);
$start = (string) ($options['start'] ?? date('Y-m-01'));
$end = (string) ($options['end'] ?? date('Y-m-t'));
$city = (string) ($options['city'] ?? 'Fortaleza');
$isJson = isset($options['json']);
$root = dirname(__DIR__, 2);

const GEO_NAME_CANDIDATES = [
    'Nome',
    'nome',
    'NOME',
    'bairro',
    'Bairro',
    'BAIRRO',
    'bairro_nome',
    'nome_bairro',
    'NM_BAIRRO',
    'NM_BAIRRO_',
    'NM_NOME',
    'NOM_BAIRRO',
    'name',
    'Name',
];

/** @return array{features:int,name_property:string,named_features:int,without_name:int,entries:list<array<string,mixed>>,errors:list<string>} */
function loadGeoJsonAudit(string $root): array
{
    $path = $root . '/assets/geo/fortaleza_bairros.json';
    $errors = [];
    if (!is_file($path)) {
        return [
            'features' => 0,
            'name_property' => '',
            'named_features' => 0,
            'without_name' => 0,
            'entries' => [],
            'errors' => ['GeoJSON nao encontrado em assets/geo/fortaleza_bairros.json'],
        ];
    }

    $raw = file_get_contents($path);
    $json = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($json)) {
        return [
            'features' => 0,
            'name_property' => '',
            'named_features' => 0,
            'without_name' => 0,
            'entries' => [],
            'errors' => ['GeoJSON invalido ou sem parse JSON'],
        ];
    }

    $features = is_array($json['features'] ?? null) ? $json['features'] : [];
    $nameProperty = detectGeoProperty($features);
    $entries = [];
    $withoutName = 0;

    foreach ($features as $index => $feature) {
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $name = extractNeighborhoodName($props, $nameProperty);
        if ($name === '') {
            $withoutName++;
        }
        $entries[] = makeEntry($name, 'geojson', ['feature_index' => $index + 1]);
    }

    return [
        'features' => count($features),
        'name_property' => $nameProperty,
        'named_features' => count($features) - $withoutName,
        'without_name' => $withoutName,
        'entries' => $entries,
        'errors' => $errors,
    ];
}

/** @param list<mixed> $features */
function detectGeoProperty(array $features): string
{
    $counts = array_fill_keys(GEO_NAME_CANDIDATES, 0);
    foreach ($features as $feature) {
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        foreach (GEO_NAME_CANDIDATES as $candidate) {
            $value = $props[$candidate] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                $counts[$candidate]++;
            }
        }
    }

    $bestKey = '';
    $bestCount = 0;
    foreach (GEO_NAME_CANDIDATES as $candidate) {
        if ($counts[$candidate] > $bestCount) {
            $bestKey = $candidate;
            $bestCount = $counts[$candidate];
        }
    }
    return $bestKey;
}

/** @param array<string,mixed> $props */
function extractNeighborhoodName(array $props, string $detectedProperty): string
{
    if ($detectedProperty !== '' && isset($props[$detectedProperty]) && trim((string) $props[$detectedProperty]) !== '') {
        return trim((string) $props[$detectedProperty]);
    }
    foreach (GEO_NAME_CANDIDATES as $candidate) {
        if (isset($props[$candidate]) && trim((string) $props[$candidate]) !== '') {
            return trim((string) $props[$candidate]);
        }
    }
    return '';
}

/** @param array<string,mixed> $extra */
function makeEntry(?string $name, string $source, array $extra = []): array
{
    $display = NeighborhoodNormalizer::displayName($name);
    $explain = NeighborhoodNormalizer::explain($display);
    return $extra + [
        'source' => $source,
        'original' => $display,
        'key' => $explain['final'],
        'normalized' => $explain['collapsed_spaces'],
        'alias_applied' => $explain['alias_applied'],
    ];
}

/** @param list<array<string,mixed>> $entries @return array<string,array<string,mixed>> */
function indexByKey(array $entries): array
{
    $index = [];
    foreach ($entries as $entry) {
        $key = (string) ($entry['key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!isset($index[$key])) {
            $index[$key] = $entry;
            continue;
        }
        $index[$key]['revenue'] = (float) ($index[$key]['revenue'] ?? 0) + (float) ($entry['revenue'] ?? 0);
    }
    return $index;
}

/** @param list<array<string,mixed>> $entries @return list<array<string,mixed>> */
function duplicatedKeys(array $entries): array
{
    $groups = [];
    foreach ($entries as $entry) {
        $key = (string) ($entry['key'] ?? '');
        $name = (string) ($entry['original'] ?? '');
        if ($key === '' || $name === '') {
            continue;
        }
        $groups[$key][$name] = true;
    }

    $duplicates = [];
    foreach ($groups as $key => $names) {
        $originals = array_keys($names);
        if (count($originals) <= 1) {
            continue;
        }
        $hasKnownAlias = false;
        $collapsed = [];
        foreach ($originals as $original) {
            $explain = NeighborhoodNormalizer::explain($original);
            $hasKnownAlias = $hasKnownAlias || $explain['alias_applied'] !== null;
            $collapsed[] = $explain['collapsed_spaces'];
        }
        $sameAfterBasicNormalization = count(array_unique($collapsed)) === 1;
        $duplicates[] = [
            'normalized_key' => $key,
            'originals' => $originals,
            'risk' => ($hasKnownAlias || $sameAfterBasicNormalization) ? 'baixo' : 'medio',
        ];
    }
    return $duplicates;
}

function tryConnect(?string &$error = null): ?PDO
{
    try {
        $config = new Config();
        return Database::getGeoConnection($config)[0];
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        return null;
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

/** @return list<array<string,mixed>> */
function loadDimensionEntries(PDO $pdo): array
{
    if (!tableExists($pdo, 'biomie_bairros_fortaleza_dim')) {
        return [];
    }
    $rows = $pdo->query(
        'SELECT bairro_nome AS name, bairro_nome_normalizado AS db_key, ativo FROM biomie_bairros_fortaleza_dim WHERE ativo = 1 ORDER BY bairro_nome'
    )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return makeEntry((string) ($row['name'] ?? ''), 'dimension', [
            'db_key' => (string) ($row['db_key'] ?? ''),
            'active' => (int) ($row['ativo'] ?? 0) === 1,
        ]);
    }, $rows);
}

/** @return list<array<string,mixed>> */
function loadApiEquivalentEntries(PDO $pdo, string $start, string $end, string $city): array
{
    if (!tableExists($pdo, 'mart_compradores_por_geo')) {
        return [];
    }
    $startComp = substr($start, 0, 7);
    $endComp = substr($end, 0, 7);
    $cityKey = NeighborhoodNormalizer::normalizeCity($city);
    $hasDim = tableExists($pdo, 'biomie_bairros_fortaleza_dim');

    if ($hasDim) {
        $sql = "
            SELECT
                b.bairro_nome AS neighborhood,
                b.bairro_nome_normalizado AS db_key,
                COALESCE(SUM(m.faturamento), 0) AS revenue,
                COALESCE(SUM(m.qtd_notas), 0) AS invoices
            FROM biomie_bairros_fortaleza_dim b
            LEFT JOIN mart_compradores_por_geo m
              ON m.bairro_norm = b.bairro_nome_normalizado
             AND m.competencia BETWEEN :start_comp AND :end_comp
             AND (LOWER(m.cidade_norm) = :city OR m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '')
            WHERE b.ativo = 1
            GROUP BY b.bairro_nome, b.bairro_nome_normalizado
            ORDER BY revenue DESC, b.bairro_nome ASC
        ";
    } else {
        $sql = "
            SELECT
                COALESCE(NULLIF(bairro_norm, ''), 'Nao informado') AS neighborhood,
                COALESCE(NULLIF(bairro_norm, ''), '') AS db_key,
                COALESCE(SUM(faturamento), 0) AS revenue,
                COALESCE(SUM(qtd_notas), 0) AS invoices
            FROM mart_compradores_por_geo
            WHERE competencia BETWEEN :start_comp AND :end_comp
              AND (LOWER(cidade_norm) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')
            GROUP BY COALESCE(NULLIF(bairro_norm, ''), 'Nao informado')
            ORDER BY revenue DESC
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'start_comp' => $startComp,
        'end_comp' => $endComp,
        'city' => $cityKey,
    ]);

    $entries = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $entries[] = makeEntry((string) ($row['neighborhood'] ?? ''), 'api_equivalent', [
            'db_key' => (string) ($row['db_key'] ?? ''),
            'revenue' => (float) ($row['revenue'] ?? 0),
            'invoices' => (int) ($row['invoices'] ?? 0),
        ]);
    }
    return $entries;
}

/** @return list<array<string,mixed>> */
function loadMartEntries(PDO $pdo, string $start, string $end, string $city): array
{
    if (!tableExists($pdo, 'mart_compradores_por_geo')) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(NULLIF(bairro_norm, ''), 'Nao informado') AS neighborhood,
            COALESCE(SUM(faturamento), 0) AS revenue,
            COALESCE(SUM(qtd_notas), 0) AS invoices
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :start_comp AND :end_comp
           AND (LOWER(cidade_norm) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')
         GROUP BY COALESCE(NULLIF(bairro_norm, ''), 'Nao informado')
         ORDER BY revenue DESC"
    );
    $stmt->execute([
        'start_comp' => substr($start, 0, 7),
        'end_comp' => substr($end, 0, 7),
        'city' => NeighborhoodNormalizer::normalizeCity($city),
    ]);

    $entries = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $entries[] = makeEntry((string) ($row['neighborhood'] ?? ''), 'mart', [
            'revenue' => (float) ($row['revenue'] ?? 0),
            'invoices' => (int) ($row['invoices'] ?? 0),
        ]);
    }
    return $entries;
}

function countEmptyNeighborhoodRows(PDO $pdo, string $start, string $end, string $city): int
{
    if (!tableExists($pdo, 'mart_compradores_por_geo')) {
        return 0;
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :start_comp AND :end_comp
           AND (LOWER(cidade_norm) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')
           AND (bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(bairro_norm) = 'nao informado')"
    );
    $stmt->execute([
        'start_comp' => substr($start, 0, 7),
        'end_comp' => substr($end, 0, 7),
        'city' => NeighborhoodNormalizer::normalizeCity($city),
    ]);
    return (int) $stmt->fetchColumn();
}

/** @return list<array<string,mixed>> */
function collationAudit(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('biomie_bairros_fortaleza_dim','mart_compradores_por_geo','mart_cliente_periodo','biomie_clientes_dim','biomie_clientes_geo_dim')
               AND (COLUMN_NAME LIKE '%bairro%' OR COLUMN_NAME LIKE '%cidade%' OR COLUMN_NAME LIKE '%city%' OR COLUMN_NAME LIKE '%neighborhood%')
             ORDER BY TABLE_NAME, COLUMN_NAME"
        );
        $rows = $stmt?->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }

    return array_map(static function (array $row): array {
        $collation = (string) ($row['COLLATION_NAME'] ?? '');
        return [
            'table' => (string) ($row['TABLE_NAME'] ?? ''),
            'column' => (string) ($row['COLUMN_NAME'] ?? ''),
            'charset' => (string) ($row['CHARACTER_SET_NAME'] ?? ''),
            'collation' => $collation,
            'case_insensitive' => str_contains($collation, '_ci'),
            'probably_accent_insensitive' => str_contains($collation, '_ci') && !str_contains($collation, '_0900_as_'),
            'recommendation' => 'Nao alterar collation nesta fase; manter normalizacao em PHP/JS.',
        ];
    }, $rows);
}

/** @param list<array<string,mixed>> $sourceEntries @param array<string,array<string,mixed>> $targetIndex @return list<array<string,mixed>> */
function suggestTypos(array $sourceEntries, array $targetIndex): array
{
    $targetKeys = array_keys($targetIndex);
    $suggestions = [];
    foreach ($sourceEntries as $entry) {
        $key = (string) ($entry['key'] ?? '');
        if ($key === '' || $key === 'nao informado' || isset($targetIndex[$key])) {
            continue;
        }
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($targetKeys as $candidate) {
            $distance = levenshtein($key, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }
        if ($best !== null && $bestDistance <= 3) {
            $suggestions[] = [
                'original' => (string) ($entry['original'] ?? ''),
                'key' => $key,
                'candidate' => $best,
                'distance' => $bestDistance,
                'action' => 'revisar_alias_manualmente',
            ];
        }
    }
    return array_slice($suggestions, 0, 20);
}

/** @param list<array<string,mixed>> $entries @return list<array<string,mixed>> */
function aliasesUsed(array $entries): array
{
    $aliases = [];
    foreach ($entries as $entry) {
        $alias = $entry['alias_applied'] ?? null;
        if (!is_array($alias)) {
            continue;
        }
        if (($alias['alias_key'] ?? '') === ($alias['canonical_key'] ?? '')) {
            continue;
        }
        $aliases[] = [
            'original' => (string) ($entry['original'] ?? ''),
            'normalized' => (string) ($entry['normalized'] ?? ''),
            'canonical' => (string) ($entry['key'] ?? ''),
            'motivo' => (string) ($alias['motivo'] ?? ''),
            'origem' => (string) ($alias['origem'] ?? ''),
        ];
    }
    return array_values(array_unique($aliases, SORT_REGULAR));
}

$geo = loadGeoJsonAudit($root);
$dbError = null;
$pdo = tryConnect($dbError);
$dimensionEntries = [];
$apiEntries = [];
$martEntries = [];
$emptyRows = 0;
$collations = [];
$databaseAvailable = $pdo instanceof PDO;
$dbWarnings = [];
$dimHasNomeKey = false;

if ($pdo instanceof PDO) {
    try {
        $dimHasNomeKey = tableExists($pdo, 'biomie_bairros_fortaleza_dim')
            && columnExists($pdo, 'biomie_bairros_fortaleza_dim', 'nome_key');
        $dimensionEntries = loadDimensionEntries($pdo);
        $apiEntries = loadApiEquivalentEntries($pdo, $start, $end, $city);
        $martEntries = loadMartEntries($pdo, $start, $end, $city);
        $emptyRows = countEmptyNeighborhoodRows($pdo, $start, $end, $city);
        $collations = collationAudit($pdo);
    } catch (Throwable $exception) {
        $dbWarnings[] = $exception->getMessage();
    }
} elseif ($dbError !== null) {
    $dbWarnings[] = 'Banco indisponivel para auditoria parcial: ' . $dbError;
}

$geoIndex = indexByKey($geo['entries']);
$apiIndex = indexByKey($apiEntries);
$apiKeys = array_values(array_filter(array_keys($apiIndex), static fn (string $key): bool => $key !== 'nao informado'));
$matchedApiKeys = array_values(array_filter($apiKeys, static fn (string $key): bool => isset($geoIndex[$key])));
$unmatchedApi = array_values(array_filter($apiEntries, static function (array $entry) use ($geoIndex): bool {
    $key = (string) ($entry['key'] ?? '');
    return $key !== '' && $key !== 'nao informado' && !isset($geoIndex[$key]);
}));
$unmatchedGeo = array_values(array_filter($geo['entries'], static function (array $entry) use ($apiIndex): bool {
    $key = (string) ($entry['key'] ?? '');
    return $key !== '' && !isset($apiIndex[$key]);
}));
$matchRate = count($apiKeys) > 0 ? round((count($matchedApiKeys) / count($apiKeys)) * 100, 2) : 0.0;
$allEntries = array_merge($geo['entries'], $dimensionEntries, $apiEntries, $martEntries);

$recommendations = [];
if ($geo['features'] !== 121) {
    $recommendations[] = 'Verificar GeoJSON: esperado 121 features para Fortaleza.';
}
if (!$databaseAvailable) {
    $recommendations[] = 'Configurar acesso ao banco para auditar API/dimensao/mart.';
}
if (count($unmatchedApi) > 0) {
    $recommendations[] = 'Revisar bairros da API sem match e criar alias somente apos validacao humana.';
}
if ($emptyRows > 0) {
    $recommendations[] = 'Verificar clientes/notas sem bairro antes da Fase 3.';
}

$payload = [
    'status' => $geo['features'] > 0 && $geo['name_property'] !== '' ? 'ok' : 'warning',
    'period' => ['start' => $start, 'end' => $end],
    'city' => $city,
    'database_available' => $databaseAvailable,
    'warnings' => array_merge($geo['errors'], $dbWarnings),
    'geojson' => [
        'features' => $geo['features'],
        'name_property' => $geo['name_property'],
        'named_features' => $geo['named_features'],
        'features_without_name' => $geo['without_name'],
    ],
    'api' => [
        'rows' => count($apiEntries),
        'neighborhoods' => count($apiIndex),
    ],
    'dimension' => [
        'neighborhoods' => count($dimensionEntries),
        'has_nome_key_column' => $dimHasNomeKey,
        'recommendation' => $dimHasNomeKey
            ? 'Dimensao ja possui nome_key.'
            : 'Tabela usa bairro_nome_normalizado; avaliar nome_key/index na Fase 3 se necessario.',
    ],
    'mart' => [
        'neighborhoods' => count(indexByKey($martEntries)),
        'empty_neighborhood_rows' => $emptyRows,
    ],
    'match' => [
        'matched' => count($matchedApiKeys),
        'unmatched_api' => count($unmatchedApi),
        'unmatched_geojson' => count($unmatchedGeo),
        'match_rate_percent' => $matchRate,
    ],
    'total_geojson_features' => $geo['features'],
    'geojson_name_property' => $geo['name_property'],
    'total_geojson_named' => $geo['named_features'],
    'total_dim_bairros' => count($dimensionEntries),
    'total_api_bairros' => count($apiEntries),
    'total_mart_bairros' => count(indexByKey($martEntries)),
    'matched_api_to_geojson' => count($matchedApiKeys),
    'unmatched_api_to_geojson' => count($unmatchedApi),
    'unmatched_geojson_to_api' => count($unmatchedGeo),
    'match_rate_percent' => $matchRate,
    'examples_unmatched_api' => array_slice(array_map(static fn (array $entry): array => [
        'original' => $entry['original'] ?? '',
        'key' => $entry['key'] ?? '',
        'revenue' => $entry['revenue'] ?? 0,
    ], $unmatchedApi), 0, 10),
    'examples_unmatched_geojson' => array_slice(array_map(static fn (array $entry): array => [
        'original' => $entry['original'] ?? '',
        'key' => $entry['key'] ?? '',
    ], $unmatchedGeo), 0, 10),
    'aliases_used' => aliasesUsed($allEntries),
    'suspected_typos' => suggestTypos($apiEntries, $geoIndex),
    'duplicated_normalized_keys' => duplicatedKeys($allEntries),
    'empty_neighborhood_rows' => $emptyRows,
    'charset_collation' => $collations,
    'recommendations' => $recommendations,
];

if ($isJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($payload['status'] === 'ok' ? 0 : 1);
}

echo "--- GEO MAP MATCH AUDIT ---\n";
echo "Status: {$payload['status']}\n";
echo "Periodo: {$start} ate {$end}\n";
echo "Cidade: {$city}\n";
echo "GeoJSON: {$payload['geojson']['features']} features, propriedade {$payload['geojson']['name_property']}, sem nome {$payload['geojson']['features_without_name']}\n";
echo "Dimensao: {$payload['total_dim_bairros']} bairros\n";
echo "API equivalente: {$payload['total_api_bairros']} bairros\n";
echo "Mart: {$payload['total_mart_bairros']} bairros, {$payload['empty_neighborhood_rows']} linhas sem bairro\n";
echo "Match API x GeoJSON: {$payload['matched_api_to_geojson']}/{$payload['total_api_bairros']} ({$payload['match_rate_percent']}%)\n";
if ($payload['examples_unmatched_api'] !== []) {
    echo "API sem match (exemplos):\n";
    foreach ($payload['examples_unmatched_api'] as $item) {
        echo " - {$item['original']} [{$item['key']}]\n";
    }
}
if ($payload['recommendations'] !== []) {
    echo "Recomendacoes:\n";
    foreach ($payload['recommendations'] as $recommendation) {
        echo " - {$recommendation}\n";
    }
}
if ($payload['warnings'] !== []) {
    echo "Avisos:\n";
    foreach ($payload['warnings'] as $warning) {
        echo " - {$warning}\n";
    }
}

exit($payload['status'] === 'ok' ? 0 : 1);
