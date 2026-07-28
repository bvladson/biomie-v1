<?php

declare(strict_types=1);

/**
 * Probe read-only for the Gold geo marts.
 *
 * It answers one specific question:
 *   "Does mart_compradores_por_geo say a buyer has no geo while
 *    biomie_clientes_dim already has cidade/bairro/CNAE?"
 *
 * Usage:
 *   php tools/dev/geo_gold_mart_probe.php
 *   php tools/dev/geo_gold_mart_probe.php --date-from=2026-01-01 --date-to=2026-06-02
 *   php tools/dev/geo_gold_mart_probe.php --format=json
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);
require_once $root . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$options = getopt('', ['date-from::', 'date-to::', 'start::', 'end::', 'sample::', 'json', 'format::']);
$json = isset($options['json']) || strtolower((string) ($options['format'] ?? '')) === 'json';
$sampleLimit = max(1, min(50, (int) ($options['sample'] ?? 10)));

$config = new \BIomie\Config();
$pdo = \BIomie\Database::connect($config, 'db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function ggmpTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function ggmpColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function ggmpCoalesceExpr(PDO $pdo, string $table, string $alias, array $columns): string
{
    $parts = [];
    foreach ($columns as $column) {
        if (ggmpColumnExists($pdo, $table, $column)) {
            $parts[] = "NULLIF({$alias}.`{$column}`, '')";
        }
    }
    if ($parts === []) {
        return "''";
    }
    return 'COALESCE(' . implode(', ', $parts) . ", '')";
}

function ggmpFetchOne(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function ggmpFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if (!ggmpTableExists($pdo, 'mart_compradores_por_geo') || !ggmpTableExists($pdo, 'biomie_clientes_dim')) {
    $payload = [
        'ok' => false,
        'status' => 'missing_tables',
        'message' => 'mart_compradores_por_geo ou biomie_clientes_dim ausente.',
    ];
    echo $json
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
        : $payload['message'] . PHP_EOL;
    exit(5);
}

$range = ggmpFetchOne(
    $pdo,
    'SELECT MIN(competencia) AS min_comp, MAX(competencia) AS max_comp FROM mart_compradores_por_geo'
);
$startInput = trim((string) ($options['date-from'] ?? $options['start'] ?? ''));
$endInput = trim((string) ($options['date-to'] ?? $options['end'] ?? ''));
$startComp = $startInput !== '' ? substr($startInput, 0, 7) : (string) ($range['min_comp'] ?? '');
$endComp = $endInput !== '' ? substr($endInput, 0, 7) : (string) ($range['max_comp'] ?? '');

if (!preg_match('/^\d{4}-\d{2}$/', $startComp) || !preg_match('/^\d{4}-\d{2}$/', $endComp)) {
    $payload = [
        'ok' => false,
        'status' => 'invalid_period',
        'message' => 'Periodo invalido ou mart vazio.',
        'period' => ['start_comp' => $startComp, 'end_comp' => $endComp],
    ];
    echo $json
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
        : $payload['message'] . PHP_EOL;
    exit(1);
}

$dimCityExpr = ggmpCoalesceExpr($pdo, 'biomie_clientes_dim', 'd', ['cidade_normalizada', 'cidade']);
$dimNeighborhoodExpr = ggmpCoalesceExpr($pdo, 'biomie_clientes_dim', 'd', ['bairro_normalizado', 'bairro']);
$dimCnaeExpr = ggmpCoalesceExpr($pdo, 'biomie_clientes_dim', 'd', ['cnae_codigo']);
$dimCityPresent = "({$dimCityExpr} <> '' AND LOWER(TRIM({$dimCityExpr})) NOT IN ('nao informada', 'nao informado', 'na'))";
$dimNeighborhoodPresent = "({$dimNeighborhoodExpr} <> '' AND LOWER(TRIM({$dimNeighborhoodExpr})) NOT IN ('nao informado', 'nao informada', 'na'))";
$dimCnaePresent = "({$dimCnaeExpr} <> '' AND {$dimCnaeExpr} <> 'SEM_CNAE')";

$martCityMissing = "(m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '' OR LOWER(TRIM(m.cidade_norm)) IN ('nao informada', 'nao informado', 'na'))";
$martNeighborhoodMissing = "(m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '' OR LOWER(TRIM(m.bairro_norm)) IN ('nao informado', 'nao informada'))";
$martCnaeMissing = "(m.cnae_codigo IS NULL OR TRIM(m.cnae_codigo) = '' OR m.cnae_codigo = 'SEM_CNAE')";

$params = ['start_comp' => $startComp, 'end_comp' => $endComp];

$summary = ggmpFetchOne(
    $pdo,
    "SELECT
        COUNT(*) AS rows_count,
        COUNT(DISTINCT m.codigo_cliente_omie) AS customers,
        COALESCE(SUM(m.faturamento), 0) AS revenue,
        COALESCE(SUM(m.qtd_notas), 0) AS invoices,
        SUM(CASE WHEN {$martCityMissing} THEN 1 ELSE 0 END) AS rows_missing_city,
        COALESCE(SUM(CASE WHEN {$martCityMissing} THEN m.faturamento ELSE 0 END), 0) AS revenue_missing_city,
        SUM(CASE WHEN {$martNeighborhoodMissing} THEN 1 ELSE 0 END) AS rows_missing_neighborhood,
        COALESCE(SUM(CASE WHEN {$martNeighborhoodMissing} THEN m.faturamento ELSE 0 END), 0) AS revenue_missing_neighborhood,
        SUM(CASE WHEN {$martCnaeMissing} THEN 1 ELSE 0 END) AS rows_missing_cnae,
        COALESCE(SUM(CASE WHEN {$martCnaeMissing} THEN m.faturamento ELSE 0 END), 0) AS revenue_missing_cnae
     FROM mart_compradores_por_geo m
     WHERE m.competencia BETWEEN :start_comp AND :end_comp",
    $params
);

$recoverableGeo = ggmpFetchOne(
    $pdo,
    "SELECT
        COUNT(*) AS rows_count,
        COUNT(DISTINCT m.codigo_cliente_omie) AS customers,
        COALESCE(SUM(m.faturamento), 0) AS revenue,
        SUM(CASE WHEN {$dimCityPresent} THEN 1 ELSE 0 END) AS dim_has_city,
        SUM(CASE WHEN {$dimNeighborhoodPresent} THEN 1 ELSE 0 END) AS dim_has_neighborhood
     FROM mart_compradores_por_geo m
     LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = m.codigo_cliente_omie
     WHERE m.competencia BETWEEN :start_comp AND :end_comp
       AND (({$martCityMissing} AND {$dimCityPresent})
            OR ({$martNeighborhoodMissing} AND {$dimNeighborhoodPresent}))",
    $params
);

$recoverableCnae = ggmpFetchOne(
    $pdo,
    "SELECT
        COUNT(*) AS rows_count,
        COUNT(DISTINCT m.codigo_cliente_omie) AS customers,
        COALESCE(SUM(m.faturamento), 0) AS revenue,
        SUM(CASE WHEN {$dimCnaePresent} THEN 1 ELSE 0 END) AS dim_has_cnae
     FROM mart_compradores_por_geo m
     LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = m.codigo_cliente_omie
     WHERE m.competencia BETWEEN :start_comp AND :end_comp
       AND {$martCnaeMissing}
       AND {$dimCnaePresent}",
    $params
);

$samples = ggmpFetchAll(
    $pdo,
    "SELECT
        m.competencia,
        m.codigo_cliente_omie,
        COALESCE(m.cliente_nome, d.nome_cliente) AS cliente_nome,
        m.documento,
        m.cidade_norm AS mart_cidade,
        m.bairro_norm AS mart_bairro,
        m.cnae_codigo AS mart_cnae,
        {$dimCityExpr} AS dim_cidade,
        {$dimNeighborhoodExpr} AS dim_bairro,
        {$dimCnaeExpr} AS dim_cnae,
        m.qtd_notas,
        m.faturamento
     FROM mart_compradores_por_geo m
     LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = m.codigo_cliente_omie
     WHERE m.competencia BETWEEN :start_comp AND :end_comp
       AND (({$martCityMissing} AND {$dimCityPresent})
            OR ({$martNeighborhoodMissing} AND {$dimNeighborhoodPresent})
            OR ({$martCnaeMissing} AND {$dimCnaePresent}))
     ORDER BY m.faturamento DESC
     LIMIT {$sampleLimit}",
    $params
);

$chiodi = ggmpFetchAll(
    $pdo,
    "SELECT
        m.competencia,
        m.codigo_cliente_omie,
        COALESCE(m.cliente_nome, d.nome_cliente) AS cliente_nome,
        m.cidade_norm AS mart_cidade,
        m.bairro_norm AS mart_bairro,
        m.cnae_codigo AS mart_cnae,
        {$dimCityExpr} AS dim_cidade,
        {$dimNeighborhoodExpr} AS dim_bairro,
        {$dimCnaeExpr} AS dim_cnae,
        m.faturamento
     FROM mart_compradores_por_geo m
     LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = m.codigo_cliente_omie
     WHERE m.competencia BETWEEN :start_comp AND :end_comp
       AND UPPER(COALESCE(m.cliente_nome, d.nome_cliente, '')) LIKE '%CHIODI%'
     ORDER BY m.competencia DESC
     LIMIT {$sampleLimit}",
    $params
);

$recoverableGeoRows = (int) ($recoverableGeo['rows_count'] ?? 0);
$recoverableCnaeRows = (int) ($recoverableCnae['rows_count'] ?? 0);
$hasRecoverableGap = $recoverableGeoRows > 0 || $recoverableCnaeRows > 0;
$unknownCnaeRows = max(0, (int) ($summary['rows_missing_cnae'] ?? 0) - $recoverableCnaeRows);

$payload = [
    'ok' => !$hasRecoverableGap,
    'status' => $hasRecoverableGap ? 'data_gap' : ($unknownCnaeRows > 0 ? 'cnae_enrichment_required' : 'ok'),
    'period' => ['start_comp' => $startComp, 'end_comp' => $endComp],
    'summary' => [
        'rows_count' => (int) ($summary['rows_count'] ?? 0),
        'customers' => (int) ($summary['customers'] ?? 0),
        'revenue' => (float) ($summary['revenue'] ?? 0),
        'invoices' => (int) ($summary['invoices'] ?? 0),
        'rows_missing_city' => (int) ($summary['rows_missing_city'] ?? 0),
        'revenue_missing_city' => (float) ($summary['revenue_missing_city'] ?? 0),
        'rows_missing_neighborhood' => (int) ($summary['rows_missing_neighborhood'] ?? 0),
        'revenue_missing_neighborhood' => (float) ($summary['revenue_missing_neighborhood'] ?? 0),
        'rows_missing_cnae' => (int) ($summary['rows_missing_cnae'] ?? 0),
        'revenue_missing_cnae' => (float) ($summary['revenue_missing_cnae'] ?? 0),
    ],
    'recoverable_geo_from_clientes_dim' => [
        'rows_count' => $recoverableGeoRows,
        'customers' => (int) ($recoverableGeo['customers'] ?? 0),
        'revenue' => (float) ($recoverableGeo['revenue'] ?? 0),
        'dim_has_city' => (int) ($recoverableGeo['dim_has_city'] ?? 0),
        'dim_has_neighborhood' => (int) ($recoverableGeo['dim_has_neighborhood'] ?? 0),
    ],
    'recoverable_cnae_from_clientes_dim' => [
        'rows_count' => $recoverableCnaeRows,
        'customers' => (int) ($recoverableCnae['customers'] ?? 0),
        'revenue' => (float) ($recoverableCnae['revenue'] ?? 0),
        'dim_has_cnae' => (int) ($recoverableCnae['dim_has_cnae'] ?? 0),
    ],
    'unknown_cnae' => [
        'rows_count' => $unknownCnaeRows,
        'revenue' => (float) ($summary['revenue_missing_cnae'] ?? 0) - (float) ($recoverableCnae['revenue'] ?? 0),
    ],
    'samples' => $samples,
    'chiodi' => $chiodi,
    'next_action' => $hasRecoverableGap
        ? 'Run geo:rebuild-period so Gold marts consume clientes_dim geo/CNAE.'
        : ($unknownCnaeRows > 0
            ? 'Geo mart is aligned. Run CNAE enrichment/materialization for clients still without CNAE in clientes_dim.'
            : 'Gold mart is aligned with clientes_dim for this probe window.'),
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($payload['ok'] ? 0 : 2);
}

echo "=== GEO GOLD MART PROBE ===" . PHP_EOL;
echo "Periodo: {$startComp} ate {$endComp}" . PHP_EOL;
echo "Linhas mart: " . $payload['summary']['rows_count']
    . " | clientes: " . $payload['summary']['customers']
    . " | NFs: " . $payload['summary']['invoices']
    . " | faturamento: " . number_format((float) $payload['summary']['revenue'], 2, ',', '.') . PHP_EOL;
echo "Sem cidade no mart: " . $payload['summary']['rows_missing_city']
    . " | R$ " . number_format((float) $payload['summary']['revenue_missing_city'], 2, ',', '.') . PHP_EOL;
echo "Sem bairro no mart: " . $payload['summary']['rows_missing_neighborhood']
    . " | R$ " . number_format((float) $payload['summary']['revenue_missing_neighborhood'], 2, ',', '.') . PHP_EOL;
echo "Sem CNAE no mart: " . $payload['summary']['rows_missing_cnae']
    . " | R$ " . number_format((float) $payload['summary']['revenue_missing_cnae'], 2, ',', '.') . PHP_EOL;
echo "Geo recuperavel via clientes_dim: " . $payload['recoverable_geo_from_clientes_dim']['rows_count']
    . " linhas | " . $payload['recoverable_geo_from_clientes_dim']['customers']
    . " clientes | R$ " . number_format((float) $payload['recoverable_geo_from_clientes_dim']['revenue'], 2, ',', '.') . PHP_EOL;
echo "CNAE recuperavel via clientes_dim: " . $payload['recoverable_cnae_from_clientes_dim']['rows_count']
    . " linhas | " . $payload['recoverable_cnae_from_clientes_dim']['customers']
    . " clientes | R$ " . number_format((float) $payload['recoverable_cnae_from_clientes_dim']['revenue'], 2, ',', '.') . PHP_EOL;
echo "CNAE ainda desconhecido na dimensao: " . $payload['unknown_cnae']['rows_count']
    . " linhas | R$ " . number_format((float) $payload['unknown_cnae']['revenue'], 2, ',', '.') . PHP_EOL;

if ($samples !== []) {
    echo PHP_EOL . "Amostras recuperaveis:" . PHP_EOL;
    foreach ($samples as $row) {
        echo "- {$row['competencia']} | {$row['cliente_nome']} | mart=("
            . ($row['mart_cidade'] ?? '') . ' / ' . ($row['mart_bairro'] ?? '') . ' / ' . ($row['mart_cnae'] ?? '')
            . ") dim=(" . ($row['dim_cidade'] ?? '') . ' / ' . ($row['dim_bairro'] ?? '') . ' / ' . ($row['dim_cnae'] ?? '')
            . ") R$ " . number_format((float) ($row['faturamento'] ?? 0), 2, ',', '.') . PHP_EOL;
    }
}

if ($chiodi !== []) {
    echo PHP_EOL . "Caso CHIODI:" . PHP_EOL;
    foreach ($chiodi as $row) {
        echo "- {$row['competencia']} | mart=("
            . ($row['mart_cidade'] ?? '') . ' / ' . ($row['mart_bairro'] ?? '') . ' / ' . ($row['mart_cnae'] ?? '')
            . ") dim=(" . ($row['dim_cidade'] ?? '') . ' / ' . ($row['dim_bairro'] ?? '') . ' / ' . ($row['dim_cnae'] ?? '')
            . ") R$ " . number_format((float) ($row['faturamento'] ?? 0), 2, ',', '.') . PHP_EOL;
    }
}

echo PHP_EOL . "Status: " . strtoupper((string) $payload['status']) . PHP_EOL;
echo "Proxima acao: " . $payload['next_action'] . PHP_EOL;

exit($payload['ok'] ? 0 : 2);
