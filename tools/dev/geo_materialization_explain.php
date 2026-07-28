<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['start:', 'end:', 'json', 'help']);
if (isset($opts['help']) || !isset($opts['start'], $opts['end'])) {
    echo "Usage: php tools/dev/geo_materialization_explain.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--json]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$start = (string) $opts['start'];
$end = (string) $opts['end'];
$startComp = substr($start, 0, 7);
$endComp = substr($end, 0, 7);
$payload = [
    'ok' => false,
    'status' => 'failed',
    'queries' => [],
    'recommendations' => [],
    'errors' => [],
];

try {
    $config = new Config();
    $logger = new Logger(dirname(__DIR__, 2) . '/storage/logs/biomie.log');
    [$pdo, $connection] = Database::getGeoConnection($config);
    $queries = [
        'geo_buyer_source' => [
            'sql' => "SELECT nf.codigo_cliente_omie, geo.cidade_norm, geo.bairro_norm, nf.valor_total
                FROM biomie_notas_fiscais_canonicas nf
                LEFT JOIN biomie_clientes_dim dim ON dim.codigo_cliente_omie = nf.codigo_cliente_omie
                LEFT JOIN biomie_clientes_geo_dim geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
                WHERE nf.status_fiscal = 'autorizada'
                  AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end",
            'params' => ['start' => $start, 'end' => $end],
        ],
        'customer_period_fiscal_source' => [
            'sql' => "SELECT n.codigo_cliente_omie, n.valor_total
                FROM biomie_notas_fiscais_canonicas n
                LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = n.codigo_cliente_omie
                WHERE n.codigo_cliente_omie IS NOT NULL
                  AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end",
            'params' => ['start' => $start, 'end' => $end],
        ],
        'api_sales_by_neighborhood' => [
            'sql' => "SELECT bairro_norm, SUM(faturamento)
                FROM mart_compradores_por_geo
                WHERE competencia BETWEEN :start_comp AND :end_comp
                GROUP BY bairro_norm",
            'params' => ['start_comp' => $startComp, 'end_comp' => $endComp],
        ],
        'api_geo_summary' => [
            'sql' => "SELECT COUNT(*), SUM(faturamento), COUNT(DISTINCT bairro_norm)
                FROM mart_compradores_por_geo
                WHERE competencia BETWEEN :start_comp AND :end_comp",
            'params' => ['start_comp' => $startComp, 'end_comp' => $endComp],
        ],
    ];

    foreach ($queries as $name => $query) {
        $stmt = $pdo->prepare('EXPLAIN ' . $query['sql']);
        $stmt->execute($query['params']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $payload['queries'][$name] = $rows;
        foreach ($rows as $row) {
            if (($row['key'] ?? null) === null || (string) ($row['key'] ?? '') === '') {
                $payload['recommendations'][] = "Revisar indice para {$name} na tabela " . (string) ($row['table'] ?? '?');
            }
        }
    }

    $payload['ok'] = true;
    $payload['status'] = 'ok';
    $payload['connection'] = $connection;
    $payload['recommendations'] = array_values(array_unique($payload['recommendations']));
} catch (Throwable $exception) {
    $payload['errors'][] = $exception->getMessage();
}

if (isset($opts['json'])) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "--- GEO MATERIALIZATION EXPLAIN ---\n";
    echo 'Status: ' . ($payload['status'] ?? 'failed') . "\n";
    foreach ($payload['queries'] as $name => $rows) {
        echo "\n{$name}\n";
        foreach ($rows as $row) {
            echo sprintf(
                " table=%s type=%s possible_keys=%s key=%s rows=%s Extra=%s\n",
                (string) ($row['table'] ?? '-'),
                (string) ($row['type'] ?? '-'),
                (string) ($row['possible_keys'] ?? '-'),
                (string) ($row['key'] ?? '-'),
                (string) ($row['rows'] ?? '-'),
                (string) ($row['Extra'] ?? '-')
            );
        }
    }
    foreach ((array) ($payload['recommendations'] ?? []) as $recommendation) {
        echo "RECOMENDACAO: {$recommendation}\n";
    }
    foreach ((array) ($payload['errors'] ?? []) as $error) {
        echo "ERRO: {$error}\n";
    }
}

exit(!empty($payload['ok']) ? 0 : 1);
