<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Modules\Sales\Application\Products\ProductMixReadService;
use BIomie\Modules\Sales\Infrastructure\ProductMixReadRepository;

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['start:', 'end:', 'json', 'explain']);
$start = (string) ($options['start'] ?? date('Y-m-01'));
$end = (string) ($options['end'] ?? date('Y-m-d'));
$config = new Config();
$logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$repo = new ProductMixReadRepository($pdo);
$payload = (new ProductMixReadService($repo))->build($start, $end, 50);
$result = [
    'period' => ['start' => $start, 'end' => $end],
    'summary' => $payload['summary'] ?? [],
    'diagnostics' => $payload['diagnostics'] ?? [],
    'first_products' => array_slice($payload['products'] ?? [], 0, 5),
];
if (isset($options['explain'])) {
    $stmt = $pdo->prepare("EXPLAIN SELECT i.codigo_produto, SUM(i.valor_total) FROM biomie_notas_fiscais_itens_canonicos i INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key WHERE n.status_fiscal = 'autorizada' AND n.data_competencia BETWEEN :start AND :end GROUP BY i.codigo_produto");
    $stmt->execute(['start' => $start, 'end' => $end]);
    $result['explain'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
