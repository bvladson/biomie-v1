<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$today = date('Y-m-d');
$start = date('Y-m-d', strtotime($today . ' -30 days'));
$logStart = date('Y-m-d 00:00:00', strtotime($start . ' -180 days'));

$queries = [
    'finance_titles' => sprintf(
        "SELECT titulo_key FROM biomie_titulos_financeiros_canonicos
         WHERE data_vencimento BETWEEN '%s' AND '%s'
           AND status_titulo NOT IN ('liquidado','recebido','pago')
           AND COALESCE(valor_aberto,0)>0",
        $start,
        $today
    ),
    'finance_collection_logs' => sprintf(
        "SELECT id FROM biomie_admin_logs
         WHERE event_type='finance.collection_action'
           AND created_at BETWEEN '%s' AND '%s 23:59:59'
         ORDER BY id ASC LIMIT 10000",
        $logStart,
        $today
    ),
    'geo_bundle_base' => sprintf(
        "SELECT SUM(faturamento), COUNT(DISTINCT codigo_cliente_omie), SUM(qtd_notas)
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN '%s' AND '%s'",
        substr(date('Y-01-01'), 0, 7),
        substr($today, 0, 7)
    ),
];

$results = [];
foreach ($queries as $name => $sql) {
    $results[$name] = $pdo->query('EXPLAIN ' . $sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

echo json_encode([
    'ok' => true,
    'connection' => $connection,
    'period' => ['start' => $start, 'end' => $today],
    'plans' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
