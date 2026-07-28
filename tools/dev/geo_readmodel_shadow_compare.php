<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\ReadModel\GeoReadModelFactory;
use BIomie\Support\CliGuard;

$root = dirname(dirname(__DIR__));

require $root . '/app/Support/CliGuard.php';
CliGuard::requireCli();
require $root . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['start:', 'end:', 'layer:', 'city:', 'cnae:', 'seller_id:', 'format:', 'json']);
$json = isset($opts['json']) || (string) ($opts['format'] ?? '') === 'json';
$start = (string) ($opts['start'] ?? date('Y-01-01'));
$end = (string) ($opts['end'] ?? date('Y-m-d'));
$layer = (string) ($opts['layer'] ?? GeoQuery::DEFAULT_LAYER);

try {
    $request = [
        'date_from' => $start,
        'date_to' => $end,
        'layer' => $layer,
    ];
    foreach (['city', 'cnae', 'seller_id'] as $key) {
        if (isset($opts[$key]) && trim((string) $opts[$key]) !== '') {
            $request[$key] = (string) $opts[$key];
        }
    }

    $config = new Config();
    [$pdo] = Database::getGeoConnection($config);
    $query = GeoQuery::fromRequest($request);
    $geo = GeoReadModelFactory::make($config, $pdo);

    $readSummary = $geo->legacySummaryPayload($query)['summary'] ?? [];
    $readComposite = $geo->legacyCompositePayload($query)['summary'] ?? [];
    $direct = directGoldSummary($pdo, $query);

    $checks = [
        compareMoney('summary.revenue', (float) ($direct['revenue'] ?? 0), (float) ($readSummary['revenue'] ?? 0)),
        compareInt('summary.customers', (int) ($direct['customers'] ?? 0), (int) ($readSummary['customers'] ?? 0)),
        compareInt('summary.invoices', (int) ($direct['invoices'] ?? 0), (int) ($readSummary['invoices'] ?? 0)),
        compareInt('summary.rows_without_neighborhood', (int) ($direct['rows_without_neighborhood'] ?? 0), (int) ($readSummary['rows_without_neighborhood'] ?? 0)),
        compareMoney('composite.total_vendido', (float) ($direct['revenue'] ?? 0), (float) ($readComposite['total_vendido'] ?? 0)),
        compareInt('composite.clientes', (int) ($direct['customers'] ?? 0), (int) ($readComposite['clientes'] ?? 0)),
        compareInt('composite.notas', (int) ($direct['invoices'] ?? 0), (int) ($readComposite['notas'] ?? 0)),
    ];

    $failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['pass']));
    $payload = [
        'ok' => $failed === [],
        'period' => ['start' => $start, 'end' => $end],
        'layer' => $layer,
        'source_contracts' => ['geo.sales_by_territory.v1', 'geo.map_payload.v1'],
        'direct_gold' => $direct,
        'checks' => $checks,
        'failed' => $failed,
    ];

    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo 'Geo ReadModel shadow compare' . PHP_EOL;
        echo "Periodo: {$start} ate {$end} | layer: {$layer}" . PHP_EOL;
        foreach ($checks as $check) {
            $status = $check['pass'] ? 'OK' : 'FAIL';
            echo "[{$status}] {$check['metric']}: legacy={$check['legacy']} readmodel={$check['readmodel']} diff={$check['diff']}" . PHP_EOL;
        }
        echo $failed === [] ? 'PASS' . PHP_EOL : ('FAIL: ' . count($failed) . ' divergencia(s)' . PHP_EOL);
    }

    exit($failed === [] ? 0 : 2);
} catch (Throwable $exception) {
    $payload = [
        'ok' => false,
        'error' => 'geo_readmodel_shadow_compare_error',
        'message' => $exception->getMessage(),
    ];
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo 'Erro: ' . $exception->getMessage() . PHP_EOL;
    }
    exit(1);
}

/** @return array<string,mixed> */
function directGoldSummary(PDO $pdo, GeoQuery $query): array
{
    $where = ['competencia BETWEEN :start_comp AND :end_comp'];
    $params = [
        'start_comp' => $query->startComp,
        'end_comp' => $query->endComp,
    ];

    if (!$query->isMetroNeighborhoodLayer() && !$query->isMultiCityLayer() && $query->city !== null) {
        $where[] = "LOWER(TRIM(COALESCE(cidade_norm, ''))) = :city";
        $params['city'] = $query->city;
    }
    if ($query->neighborhood !== null) {
        $where[] = 'LOWER(TRIM(bairro_norm)) = :neighborhood';
        $params['neighborhood'] = $query->neighborhood;
    }
    if ($query->cnae !== null) {
        if (strtoupper($query->cnae) === 'SEM_CNAE') {
            $where[] = "(cnae_codigo IS NULL OR TRIM(cnae_codigo) = '')";
        } else {
            $where[] = '(cnae_codigo = :cnae OR LOWER(cnae_descricao) LIKE :cnae_like)';
            $params['cnae'] = $query->cnae;
            $params['cnae_like'] = '%' . mb_strtolower($query->cnae) . '%';
        }
    }
    if ($query->sellerId !== null && columnExists($pdo, 'mart_compradores_por_geo', 'nome_vendedor')) {
        $where[] = 'LOWER(nome_vendedor) LIKE :seller_pct';
        $params['seller_pct'] = '%' . mb_strtolower(trim($query->sellerId)) . '%';
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(faturamento), 0) AS revenue,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers,
            COALESCE(SUM(qtd_notas), 0) AS invoices,
            SUM(CASE WHEN bairro_norm IS NULL OR TRIM(bairro_norm) = '' THEN 1 ELSE 0 END) AS rows_without_neighborhood
        FROM mart_compradores_por_geo
        WHERE " . implode(' AND ', $where)
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** @return array{metric:string,legacy:string,readmodel:string,diff:string,pass:bool} */
function compareMoney(string $metric, float $legacy, float $readmodel): array
{
    $diff = round($readmodel - $legacy, 4);
    return [
        'metric' => $metric,
        'legacy' => number_format($legacy, 2, '.', ''),
        'readmodel' => number_format($readmodel, 2, '.', ''),
        'diff' => number_format($diff, 4, '.', ''),
        'pass' => abs($diff) <= 0.01,
    ];
}

/** @return array{metric:string,legacy:string,readmodel:string,diff:string,pass:bool} */
function compareInt(string $metric, int $legacy, int $readmodel): array
{
    $diff = $readmodel - $legacy;
    return [
        'metric' => $metric,
        'legacy' => (string) $legacy,
        'readmodel' => (string) $readmodel,
        'diff' => (string) $diff,
        'pass' => $diff === 0,
    ];
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}
