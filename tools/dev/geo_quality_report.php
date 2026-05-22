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
$asJson = isset($options['json']);

function connectForQuality(?string &$error = null): ?PDO
{
    try {
        $config = new Config();
        return Database::getGeoConnection($config)[0];
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        return null;
    }
}

function tableExistsQuality(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function columnExistsQuality(PDO $pdo, string $table, string $column): bool
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

/** @return array<string,string> */
function officialNeighborhoodIndex(PDO $pdo): array
{
    $index = [];
    if (tableExistsQuality($pdo, 'biomie_bairros_fortaleza_dim')) {
        $rows = $pdo->query('SELECT bairro_nome FROM biomie_bairros_fortaleza_dim WHERE ativo = 1')?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $name) {
            $key = NeighborhoodNormalizer::normalizeKey((string) $name);
            if ($key !== '') {
                $index[$key] = NeighborhoodNormalizer::displayName((string) $name);
            }
        }
    }

    if ($index !== []) {
        return $index;
    }

    $path = dirname(__DIR__, 2) . '/assets/geo/fortaleza_bairros.json';
    $raw = is_file($path) ? file_get_contents($path) : false;
    $json = $raw !== false ? json_decode($raw, true) : null;
    $features = is_array($json['features'] ?? null) ? $json['features'] : [];
    foreach ($features as $feature) {
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $name = (string) ($props['Nome'] ?? $props['nome'] ?? $props['bairro'] ?? '');
        $key = NeighborhoodNormalizer::normalizeKey($name);
        if ($key !== '') {
            $index[$key] = NeighborhoodNormalizer::displayName($name);
        }
    }
    return $index;
}

/** @param array<string,string> $officialIndex */
function bestCandidate(string $key, array $officialIndex): ?array
{
    if ($key === '') {
        return null;
    }
    $bestKey = null;
    $bestDistance = PHP_INT_MAX;
    foreach (array_keys($officialIndex) as $candidate) {
        $distance = levenshtein($key, $candidate);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestKey = $candidate;
        }
    }
    if ($bestKey === null || $bestDistance > 3) {
        return null;
    }
    $maxLen = max(strlen($key), strlen($bestKey), 1);
    return [
        'key' => $bestKey,
        'name' => $officialIndex[$bestKey],
        'score_aproximado' => round(1 - ($bestDistance / $maxLen), 2),
        'distance' => $bestDistance,
    ];
}

/** @return array<string,mixed> */
function clientQuality(PDO $pdo): array
{
    if (!tableExistsQuality($pdo, 'biomie_clientes_dim')) {
        return [
            'table_available' => false,
            'clientes_com_bairro' => 0,
            'clientes_sem_bairro' => 0,
            'clientes_cidade_fortaleza' => 0,
            'clientes_cidade_fortaleza_variacao' => 0,
            'city_variants' => [],
        ];
    }

    $hasBairro = columnExistsQuality($pdo, 'biomie_clientes_dim', 'bairro');
    $hasCidade = columnExistsQuality($pdo, 'biomie_clientes_dim', 'cidade');
    if (!$hasBairro && !$hasCidade) {
        return ['table_available' => true, 'warning' => 'Tabela sem colunas bairro/cidade esperadas'];
    }

    $rows = $pdo->query(
        'SELECT '
        . ($hasBairro ? 'bairro' : 'NULL AS bairro')
        . ', '
        . ($hasCidade ? 'cidade' : 'NULL AS cidade')
        . ' FROM biomie_clientes_dim'
    )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $withNeighborhood = 0;
    $withoutNeighborhood = 0;
    $fortaleza = 0;
    $fortalezaVariant = 0;
    $cityVariants = [];

    foreach ($rows as $row) {
        $bairro = NeighborhoodNormalizer::displayName((string) ($row['bairro'] ?? ''));
        $cidade = NeighborhoodNormalizer::displayName((string) ($row['cidade'] ?? ''));
        if ($bairro !== '') {
            $withNeighborhood++;
        } else {
            $withoutNeighborhood++;
        }
        if (NeighborhoodNormalizer::isFortaleza($cidade)) {
            $fortaleza++;
            if ($cidade !== '' && $cidade !== 'Fortaleza') {
                $fortalezaVariant++;
                $cityVariants[$cidade] = ($cityVariants[$cidade] ?? 0) + 1;
            }
        }
    }

    arsort($cityVariants);
    return [
        'table_available' => true,
        'clientes_total' => count($rows),
        'clientes_com_bairro' => $withNeighborhood,
        'clientes_sem_bairro' => $withoutNeighborhood,
        'clientes_cidade_fortaleza' => $fortaleza,
        'clientes_cidade_fortaleza_variacao' => $fortalezaVariant,
        'city_variants' => array_slice($cityVariants, 0, 20, true),
    ];
}

/** @param array<string,string> $officialIndex @return array<string,mixed> */
function martQuality(PDO $pdo, string $start, string $end, string $city, array $officialIndex): array
{
    if (!tableExistsQuality($pdo, 'mart_compradores_por_geo')) {
        return [
            'table_available' => false,
            'faturamento_com_bairro' => 0.0,
            'faturamento_sem_bairro' => 0.0,
            'top_clientes_sem_bairro' => [],
            'bairros_sem_match' => [],
            'top_bairros_suspeitos' => [],
            'correcoes_sugeridas' => [],
        ];
    }

    $params = [
        'start_comp' => substr($start, 0, 7),
        'end_comp' => substr($end, 0, 7),
        'city' => NeighborhoodNormalizer::normalizeCity($city),
    ];
    $cityWhere = "(LOWER(cidade_norm) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')";
    $periodWhere = 'competencia BETWEEN :start_comp AND :end_comp';

    $revenueStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN bairro_norm IS NOT NULL AND TRIM(bairro_norm) <> '' AND LOWER(bairro_norm) <> 'nao informado' THEN faturamento ELSE 0 END), 0) AS revenue_with_neighborhood,
            COALESCE(SUM(CASE WHEN bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(bairro_norm) = 'nao informado' THEN faturamento ELSE 0 END), 0) AS revenue_without_neighborhood,
            SUM(CASE WHEN bairro_norm IS NOT NULL AND TRIM(bairro_norm) <> '' AND LOWER(bairro_norm) <> 'nao informado' THEN 1 ELSE 0 END) AS rows_with_neighborhood,
            SUM(CASE WHEN bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(bairro_norm) = 'nao informado' THEN 1 ELSE 0 END) AS rows_without_neighborhood
         FROM mart_compradores_por_geo
         WHERE {$periodWhere} AND {$cityWhere}"
    );
    $revenueStmt->execute($params);
    $revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $topStmt = $pdo->prepare(
        "SELECT
            COALESCE(NULLIF(cliente_nome, ''), 'Nao informado') AS cliente_nome,
            COALESCE(NULLIF(documento, ''), '') AS documento,
            COALESCE(SUM(faturamento), 0) AS faturamento,
            COALESCE(SUM(qtd_notas), 0) AS notas,
            MAX(cidade_norm) AS cidade_norm
         FROM mart_compradores_por_geo
         WHERE {$periodWhere}
           AND {$cityWhere}
           AND (bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(bairro_norm) = 'nao informado')
         GROUP BY cliente_nome, documento
         ORDER BY faturamento DESC
         LIMIT 20"
    );
    $topStmt->execute($params);
    $topClients = $topStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $neighborhoodStmt = $pdo->prepare(
        "SELECT
            COALESCE(NULLIF(bairro_norm, ''), 'Nao informado') AS bairro,
            COALESCE(SUM(faturamento), 0) AS faturamento,
            COALESCE(SUM(qtd_notas), 0) AS notas,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS clientes
         FROM mart_compradores_por_geo
         WHERE {$periodWhere} AND {$cityWhere}
         GROUP BY COALESCE(NULLIF(bairro_norm, ''), 'Nao informado')
         ORDER BY faturamento DESC"
    );
    $neighborhoodStmt->execute($params);

    $unmatched = [];
    $suspects = [];
    $suggested = [];
    foreach ($neighborhoodStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $original = NeighborhoodNormalizer::displayName((string) ($row['bairro'] ?? ''));
        $key = NeighborhoodNormalizer::normalizeKey($original);
        if ($key === '' || $key === 'nao informado' || isset($officialIndex[$key])) {
            continue;
        }
        $candidate = bestCandidate($key, $officialIndex);
        $item = [
            'bairro_original' => $original,
            'bairro_key' => $key,
            'faturamento' => (float) ($row['faturamento'] ?? 0),
            'notas' => (int) ($row['notas'] ?? 0),
            'clientes' => (int) ($row['clientes'] ?? 0),
            'candidato_provavel' => $candidate['name'] ?? null,
            'score_aproximado' => $candidate['score_aproximado'] ?? null,
        ];
        $unmatched[] = $item;
        $suspects[] = $item;
        if ($candidate !== null) {
            $suggested[] = [
                'tipo' => 'bairro_sem_match',
                'valor_original' => $original,
                'valor_normalizado' => $key,
                'sugestao' => $candidate['key'],
                'acao' => 'criar_alias',
                'risco' => ($candidate['distance'] ?? 99) <= 2 ? 'baixo' : 'medio',
            ];
        }
    }

    return [
        'table_available' => true,
        'faturamento_com_bairro' => (float) ($revenue['revenue_with_neighborhood'] ?? 0),
        'faturamento_sem_bairro' => (float) ($revenue['revenue_without_neighborhood'] ?? 0),
        'linhas_com_bairro' => (int) ($revenue['rows_with_neighborhood'] ?? 0),
        'linhas_sem_bairro' => (int) ($revenue['rows_without_neighborhood'] ?? 0),
        'top_clientes_sem_bairro' => array_map(static fn (array $row): array => [
            'cliente_nome' => (string) ($row['cliente_nome'] ?? ''),
            'faturamento' => (float) ($row['faturamento'] ?? 0),
            'notas' => (int) ($row['notas'] ?? 0),
            'cidade_key' => (string) ($row['cidade_norm'] ?? ''),
        ], $topClients),
        'bairros_sem_match' => $unmatched,
        'top_bairros_suspeitos' => array_slice($suspects, 0, 20),
        'top_aliases_necessarios' => array_slice($suggested, 0, 20),
        'correcoes_sugeridas' => $suggested,
    ];
}

$dbError = null;
$pdo = connectForQuality($dbError);
$warnings = [];
$officialIndex = [];
$clientQuality = [];
$martQuality = [];

if ($pdo instanceof PDO) {
    try {
        $officialIndex = officialNeighborhoodIndex($pdo);
        $clientQuality = clientQuality($pdo);
        $martQuality = martQuality($pdo, $start, $end, $city, $officialIndex);
    } catch (Throwable $exception) {
        $warnings[] = $exception->getMessage();
    }
} else {
    $warnings[] = 'Banco indisponivel: ' . ($dbError ?? 'sem detalhes');
}

$payload = [
    'status' => $pdo instanceof PDO ? 'ok' : 'partial',
    'period' => ['start' => $start, 'end' => $end],
    'city' => $city,
    'database_available' => $pdo instanceof PDO,
    'warnings' => $warnings,
    'official_neighborhoods' => count($officialIndex),
    'clientes' => $clientQuality,
    'mart' => $martQuality,
    'respostas' => [
        'clientes_com_bairro' => $clientQuality['clientes_com_bairro'] ?? 0,
        'clientes_sem_bairro' => $clientQuality['clientes_sem_bairro'] ?? 0,
        'clientes_cidade_fortaleza' => $clientQuality['clientes_cidade_fortaleza'] ?? 0,
        'clientes_cidade_fortaleza_variacao' => $clientQuality['clientes_cidade_fortaleza_variacao'] ?? 0,
        'bairros_nao_batem_dimensao' => count($martQuality['bairros_sem_match'] ?? []),
        'faturamento_com_bairro' => $martQuality['faturamento_com_bairro'] ?? 0.0,
        'faturamento_sem_bairro' => $martQuality['faturamento_sem_bairro'] ?? 0.0,
        'top_20_clientes_sem_bairro' => $martQuality['top_clientes_sem_bairro'] ?? [],
        'top_20_bairros_suspeitos' => $martQuality['top_bairros_suspeitos'] ?? [],
        'top_aliases_necessarios' => $martQuality['top_aliases_necessarios'] ?? [],
    ],
    'correcoes_sugeridas' => $martQuality['correcoes_sugeridas'] ?? [],
    'recommendations' => [
        'Nao aplicar fuzzy matching automaticamente em producao.',
        'Revisar correcoes_sugeridas antes de criar aliases oficiais.',
        'Na Fase 3, considerar nome_key/index na dimensao oficial e saneamento de clientes sem bairro.',
    ],
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "--- GEO QUALITY REPORT ---\n";
echo "Status: {$payload['status']}\n";
echo "Periodo: {$start} ate {$end}\n";
echo "Cidade: {$city}\n";
echo "Bairros oficiais: {$payload['official_neighborhoods']}\n";
echo "Clientes com bairro: " . ($payload['respostas']['clientes_com_bairro'] ?? 0) . "\n";
echo "Clientes sem bairro: " . ($payload['respostas']['clientes_sem_bairro'] ?? 0) . "\n";
echo "Faturamento com bairro: " . number_format((float) ($payload['respostas']['faturamento_com_bairro'] ?? 0), 2, ',', '.') . "\n";
echo "Faturamento sem bairro: " . number_format((float) ($payload['respostas']['faturamento_sem_bairro'] ?? 0), 2, ',', '.') . "\n";
echo "Bairros sem match: " . ($payload['respostas']['bairros_nao_batem_dimensao'] ?? 0) . "\n";
if (($payload['correcoes_sugeridas'] ?? []) !== []) {
    echo "Correcoes sugeridas:\n";
    foreach (array_slice($payload['correcoes_sugeridas'], 0, 10) as $item) {
        echo " - {$item['valor_original']} [{$item['valor_normalizado']}] => {$item['sugestao']} ({$item['acao']}, risco {$item['risco']})\n";
    }
}
if ($warnings !== []) {
    echo "Avisos:\n";
    foreach ($warnings as $warning) {
        echo " - {$warning}\n";
    }
}

exit(0);
