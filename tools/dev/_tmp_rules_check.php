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

echo "=== biomie_operation_scope_rules (colunas) ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM biomie_operation_scope_rules")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c2) {
    echo "  {$c2['Field']} {$c2['Type']}\n";
}

echo "\n=== biomie_operation_scope_rules (ativos) ===\n";
$rows = $pdo->query("SELECT * FROM biomie_operation_scope_rules WHERE is_active=1 ORDER BY priority LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
if (!$rows) {
    echo "  (nenhuma regra ativa)\n";
}
