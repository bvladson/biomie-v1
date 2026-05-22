<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
echo "PHP_SAPI: " . PHP_SAPI . "\n";
echo "start\n";
require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
echo "CliGuard loaded\n";
\BIomie\Support\CliGuard::requireCli();
echo "CliGuard passed\n";
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
echo "Bootstrap loaded\n";
\BIomie\Bootstrap::init();
echo "Bootstrap init done\n";

use BIomie\Config;
use BIomie\Database;

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
echo "Connection: $connection\n";
$pdo = Database::connect($config, $connection);
echo "PDO connected\n";
$cnt = $pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
echo "NF count: $cnt\n";
