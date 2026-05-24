<?php
if (PHP_SAPI !== 'cli') exit('CLI only');
require_once dirname(__FILE__, 2) . '/app/Bootstrap.php';
BIomie\Bootstrap::init();
$c = new BIomie\Config();
$p = BIomie\Database::connect($c, 'db');
echo "=== biomie_titulos_financeiros_canonicos ===\n";
foreach ($p->query("DESCRIBE biomie_titulos_financeiros_canonicos") as $r) {
    echo $r['Field'] . ' ' . $r['Type'] . "\n";
}
echo "\n=== biomie_pedidos_canonicos ===\n";
foreach ($p->query("DESCRIBE biomie_pedidos_canonicos") as $r) {
    echo $r['Field'] . ' ' . $r['Type'] . "\n";
}
