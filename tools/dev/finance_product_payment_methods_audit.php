<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$opts = getopt('', ['start:', 'end:', 'pretty']);
$start = (string) ($opts['start'] ?? date('Y-01-01'));
$end = (string) ($opts['end'] ?? date('Y-m-d'));
$config = new \BIomie\Config();
$connection = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = \BIomie\Database::connect($config, $connection);
$payload = (new \BIomie\Support\FinanceProductPaymentMethodBuilder($pdo))->build($start, $end, 20);

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (isset($opts['pretty']) ? JSON_PRETTY_PRINT : 0)) . PHP_EOL;
