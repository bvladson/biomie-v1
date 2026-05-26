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

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "--- CHECKING DB JOBS ---\n";
if (tableExists($pdo, 'biomie_jobs')) {
    $rows = $pdo->query("SELECT status, COUNT(*) AS total FROM biomie_jobs GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo json_encode($r) . "\n";
    }
    
    echo "\n--- RECENT 10 DB JOBS ---\n";
    $recent = $pdo->query("SELECT id, job_type, status, attempts, max_attempts, available_at, started_at, finished_at, failed_at, left(last_error, 100) as last_error_trunc FROM biomie_jobs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($recent as $r) {
        echo json_encode($r) . "\n";
    }
} else {
    echo "biomie_jobs table does not exist.\n";
}

echo "\n--- CHECKING WORKER HEARTBEATS ---\n";
if (tableExists($pdo, 'biomie_worker_heartbeats')) {
    $hb = $pdo->query("SELECT * FROM biomie_worker_heartbeats ORDER BY last_heartbeat_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($hb as $r) {
        echo json_encode($r) . "\n";
    }
} else {
    echo "biomie_worker_heartbeats table does not exist.\n";
}
