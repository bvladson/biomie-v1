<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\FinanceCollectionActionRepository;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$repository = new FinanceCollectionActionRepository($pdo);
$startedAt = microtime(true);
$available = $repository->available();
$count = $available
    ? (int) $pdo->query('SELECT COUNT(*) FROM biomie_finance_collection_actions')->fetchColumn()
    : 0;
$latest = $available ? $repository->list(null, null, null, 1) : [];

echo json_encode([
    'ok' => $available,
    'connection' => $connection,
    'table' => FinanceCollectionActionRepository::TABLE,
    'rows' => $count,
    'latest' => $latest,
    'query_ms' => round((microtime(true) - $startedAt) * 1000, 2),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($available ? 0 : 1);
