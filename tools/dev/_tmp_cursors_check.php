<?php
declare(strict_types=1);
require_once 'app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once 'app/Bootstrap.php';
\BIomie\Bootstrap::init();
$c   = \BIomie\Config::fromEnvironment();
$con = \BIomie\Database::isConfigured($c, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = \BIomie\Database::connect($c, $con);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "--- SYNC CURSORS ---\n";
$cursors = $pdo->query("SELECT * FROM biomie_sync_cursors")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cursors as $cursor) {
    echo json_encode($cursor) . "\n";
}

echo "\n--- RECENT SYNC RUNS ---\n";
$runs = $pdo->query("SELECT id, run_type, domain_name, endpoint_group, status, error_summary, started_at, finished_at FROM biomie_sync_runs ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
foreach ($runs as $run) {
    echo json_encode($run) . "\n";
}
