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

$rows = $pdo->query("
    SELECT numero_nf, data_emissao, data_competencia, valor_total, operation_scope, operation_scope_rule
    FROM biomie_notas_fiscais_canonicas
    WHERE numero_nf IN ('00001285','00001286')
    ORDER BY numero_nf
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r) . "\n";
}

$minMax = $pdo->query("
    SELECT
        MIN(COALESCE(data_competencia, data_emissao)) AS min_d,
        MAX(COALESCE(data_competencia, data_emissao)) AS max_d
    FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
      AND operation_scope = 'commercial'
")->fetch(PDO::FETCH_ASSOC);
echo "commercial_range: " . json_encode($minMax) . "\n";

$totals = $pdo->query("
    SELECT operation_scope, COUNT(*) AS cnt, SUM(valor_total) AS total
    FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
    GROUP BY operation_scope
    ORDER BY operation_scope
")->fetchAll(PDO::FETCH_ASSOC);
echo "scope_totals:\n";
foreach ($totals as $r) {
    echo "  " . json_encode($r) . "\n";
}
