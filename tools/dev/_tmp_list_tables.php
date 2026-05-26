<?php
declare(strict_types=1);
require_once 'app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once 'app/Bootstrap.php';
\BIomie\Bootstrap::init();

$c = \BIomie\Config::fromEnvironment();

foreach (['db', 'flight_plan_db'] as $con) {
    if (\BIomie\Database::isConfigured($c, $con)) {
        try {
            $pdo = \BIomie\Database::connect($c, $con);
            echo "--- TABLES IN {$con} ---\n";
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $t) {
                echo "  - {$t}\n";
            }
        } catch (\Throwable $e) {
            echo "Error connecting to {$con}: " . $e->getMessage() . "\n";
        }
    }
}
