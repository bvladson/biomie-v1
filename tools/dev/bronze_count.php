<?php
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$pdo = \BIomie\Database::connect(new \BIomie\Config());
$cnt = $pdo->query("SELECT COUNT(*), SUM(LENGTH(payload_json))/1024/1024 FROM biomie_omie_bronze WHERE omie_method='ListarNF'")->fetch(PDO::FETCH_NUM);
echo "Bronze ListarNF rows: {$cnt[0]}, total payload size: " . round((float)$cnt[1], 1) . " MB\n";
