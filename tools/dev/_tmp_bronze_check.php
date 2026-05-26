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

echo "--- CHECKING BRONZE LAYER ---\n";
// Let's search for "00001367" or others in payload_json
$stmt = $pdo->prepare("
    SELECT id, domain_name, entity_name, omie_method, natural_key, provider_key, ingested_at
    FROM biomie_omie_bronze
    WHERE payload_json LIKE :pattern
");
$stmt->execute(['pattern' => '%00001367%']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No raw payloads containing '00001367' found in biomie_omie_bronze.\n";
} else {
    foreach ($rows as $r) {
        echo json_encode($r) . "\n";
    }
}

// Let's count recent bronze records
echo "\n--- RECENT BRONZE COUNT ---\n";
$stmt2 = $pdo->query("
    SELECT DATE(ingested_at) AS dia, domain_name, COUNT(*) AS count
    FROM biomie_omie_bronze
    WHERE ingested_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(ingested_at), domain_name
    ORDER BY dia DESC, domain_name
");
$rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows2 as $r) {
    echo json_encode($r) . "\n";
}
