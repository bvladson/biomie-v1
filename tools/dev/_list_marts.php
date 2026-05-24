<?php
require dirname(dirname(__DIR__)) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$config = new \BIomie\Config();
$pdo = \BIomie\Database::connect($config, 'db');
echo "=== All biomie_mart/bridge tables ===\n";
$stmt = $pdo->query("SHOW TABLES");
foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $t) {
    if (str_contains($t, 'mart') || str_contains($t, 'bridge')) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo "{$t}: {$n} rows\n";
    }
}
