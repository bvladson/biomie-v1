<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$c = new \BIomie\Config();
$p = \BIomie\Database::connect($c, 'db');

echo "=== ALL TABLES IN DATABASE ===\n";
$tables = $p->query("SHOW TABLES")->fetchAll(\PDO::FETCH_NUM);
foreach ($tables as $r) {
    $t = $r[0];
    try {
        $n = (int)$p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t = $n rows\n";
    } catch (\Exception $e) {
        echo "$t = ERR\n";
    }
}
