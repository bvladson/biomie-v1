<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\GeoTraceDiagnostics;
use BIomie\Support\NeighborhoodNormalizer;
use BIomie\Support\SecretMask;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['start:', 'end:', 'city:', 'json']);
$start = (string) ($options['start'] ?? date('Y-m-01'));
$end = (string) ($options['end'] ?? date('Y-m-t'));
$city = (string) ($options['city'] ?? 'Fortaleza');
$asJson = isset($options['json']);

$dbError = null;
$pdo = null;
try {
    $pdo = Database::getGeoConnection(new Config())[0];
} catch (Throwable $exception) {
    $dbError = SecretMask::sanitizeErrorMessage($exception->getMessage());
}

function diffRows(?PDO $pdo, string $sql, array $params = []): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return [];
    }
}

function classifyDiff(float $base, float $delta): string
{
    $abs = abs($delta);
    if ($abs < 0.01) {
        return 'ok';
    }
    $rate = $base > 0 ? ($abs / $base) * 100 : 100;
    if ($rate <= 1) {
        return 'alerta_baixo';
    }
    if ($rate <= 5) {
        return 'alerta_medio';
    }
    return 'critico';
}

$trace = $pdo instanceof PDO ? GeoTraceDiagnostics::build($pdo, $start, $end, $city) : [];
$startComp = substr($start, 0, 7);
$endComp = substr($end, 0, 7);
$cityKey = NeighborhoodNormalizer::normalizeCity($city);

$rawNfs = $pdo instanceof PDO && GeoTraceDiagnostics::tableExists($pdo, 'biomie_omie_bronze')
    ? diffRows($pdo, "
        SELECT COUNT(*) AS nfs
        FROM biomie_omie_bronze
        WHERE entity_name = 'invoice'
          AND STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.dEmi')), '%d/%m/%Y') BETWEEN :start AND :end
    ", ['start' => $start, 'end' => $end])[0] ?? ['nfs' => 0]
    : ['nfs' => 0];

$lostClients = $pdo instanceof PDO && GeoTraceDiagnostics::tableExists($pdo, 'mart_cliente_periodo') && GeoTraceDiagnostics::tableExists($pdo, 'mart_compradores_por_geo')
    ? diffRows($pdo, "
        SELECT cp.codigo_cliente_omie, MAX(cp.nome_cliente) AS nome_cliente, SUM(cp.valor_nfe) AS receita, SUM(cp.nfes_emitidas) AS nfs
        FROM mart_cliente_periodo cp
        LEFT JOIN mart_compradores_por_geo g
          ON g.codigo_cliente_omie = cp.codigo_cliente_omie
         AND g.competencia = cp.competencia
         AND LOWER(TRIM(COALESCE(g.cidade_norm, ''))) = :city
        WHERE cp.competencia BETWEEN :start_comp AND :end_comp
          AND (cp.valor_nfe > 0 OR cp.nfes_emitidas > 0)
          AND g.id IS NULL
        GROUP BY cp.codigo_cliente_omie
        ORDER BY receita DESC
        LIMIT 20
    ", ['start_comp' => $startComp, 'end_comp' => $endComp, 'city' => $cityKey])
    : [];

$lostNeighborhoods = $pdo instanceof PDO && GeoTraceDiagnostics::tableExists($pdo, 'mart_compradores_por_geo')
    ? diffRows($pdo, "
        SELECT bairro_norm, SUM(faturamento) AS receita, SUM(qtd_notas) AS nfs
        FROM mart_compradores_por_geo
        WHERE competencia BETWEEN :start_comp AND :end_comp
          AND LOWER(TRIM(COALESCE(cidade_norm, ''))) = :city
          AND (bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(bairro_norm) = 'nao informado')
        GROUP BY bairro_norm
        ORDER BY receita DESC
        LIMIT 20
    ", ['start_comp' => $startComp, 'end_comp' => $endComp, 'city' => $cityKey])
    : [];

$canonicalRevenue = (float) ($trace['canonical']['receita_total'] ?? 0);
$martClienteRevenue = (float) ($trace['mart_cliente_periodo']['receita'] ?? 0);
$geoRevenue = (float) ($trace['mart_compradores_por_geo']['receita'] ?? 0);
$diffCanonicalVsMart = $canonicalRevenue - $martClienteRevenue;
$diffMartVsGeo = $martClienteRevenue - $geoRevenue;

$payload = [
    'status' => $pdo instanceof PDO ? 'ok' : 'partial',
    'period' => ['start' => $start, 'end' => $end, 'city' => $city],
    'database_available' => $pdo instanceof PDO,
    'database_error' => $dbError,
    'total_nfs_omie_raw' => (int) ($rawNfs['nfs'] ?? 0),
    'total_nfs_canonical' => (int) ($trace['canonical']['nfs_no_periodo'] ?? 0),
    'total_nfs_mart_cliente' => (int) ($trace['mart_cliente_periodo']['nfs'] ?? 0),
    'total_nfs_geo_mart' => (int) ($trace['mart_compradores_por_geo']['nfs'] ?? 0),
    'receita_omie_raw' => null,
    'receita_canonical' => $canonicalRevenue,
    'receita_mart_cliente' => $martClienteRevenue,
    'receita_geo_mart' => $geoRevenue,
    'diferenca_canonical_vs_mart_cliente' => $diffCanonicalVsMart,
    'diferenca_mart_cliente_vs_geo_mart' => $diffMartVsGeo,
    'classificacao_canonical_vs_mart_cliente' => classifyDiff($canonicalRevenue, $diffCanonicalVsMart),
    'classificacao_mart_cliente_vs_geo_mart' => classifyDiff($martClienteRevenue, $diffMartVsGeo),
    'bairros_perdidos' => $lostNeighborhoods,
    'clientes_perdidos' => $lostClients,
    'nfs_perdidas' => [
        'canonical_vs_mart_cliente' => max(0, (int) ($trace['canonical']['nfs_validas_mapa'] ?? 0) - (int) ($trace['mart_cliente_periodo']['nfs'] ?? 0)),
        'mart_cliente_vs_geo_mart' => max(0, (int) ($trace['mart_cliente_periodo']['nfs'] ?? 0) - (int) ($trace['mart_compradores_por_geo']['nfs'] ?? 0)),
    ],
    'diagnosis' => $trace['diagnosis'] ?? [],
    'next_action' => $trace['next_action'] ?? null,
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "--- GEO LAYER DIFF ---\n";
echo "Status: {$payload['status']}\n";
echo "Periodo: {$start} ate {$end} | Cidade: {$city}\n";
echo "NFs raw/canonical/mart_cliente/geo: {$payload['total_nfs_omie_raw']}/{$payload['total_nfs_canonical']}/{$payload['total_nfs_mart_cliente']}/{$payload['total_nfs_geo_mart']}\n";
echo "Receita canonical: " . number_format($canonicalRevenue, 2, ',', '.') . "\n";
echo "Receita mart_cliente: " . number_format($martClienteRevenue, 2, ',', '.') . " | diff=" . number_format($diffCanonicalVsMart, 2, ',', '.') . " ({$payload['classificacao_canonical_vs_mart_cliente']})\n";
echo "Receita geo_mart: " . number_format($geoRevenue, 2, ',', '.') . " | diff=" . number_format($diffMartVsGeo, 2, ',', '.') . " ({$payload['classificacao_mart_cliente_vs_geo_mart']})\n";
echo "Clientes perdidos: " . count($lostClients) . " | Bairros perdidos/sem bairro: " . count($lostNeighborhoods) . "\n";
echo "Diagnostico: " . ($payload['diagnosis']['technical_message'] ?? '-') . "\n";
if ($dbError !== null) {
    echo "Aviso banco: {$dbError}\n";
}
