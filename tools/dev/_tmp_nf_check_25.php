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

echo "--- CHECKING SPECIFIC NFs ---\n";
$query1 = "
SELECT
  numero_nf,
  data_emissao,
  data_competencia,
  operation_scope,
  is_biomie_commercial_visible,
  valor_total,
  valor_frete,
  valor_outras_despesas,
  valor_seguro,
  valor_ipi,
  valor_base_comercial_nf,
  codigo_vendedor_omie,
  pedido_id_omie
FROM biomie_notas_fiscais_canonicas
WHERE numero_nf IN (
  '00001367','00001368','00001369','00001370','00001371','00001372','00001373',
  '00001374','00001375','00001376','00001377','00001378','00001379','00001380'
)
ORDER BY numero_nf;
";

$rows1 = $pdo->query($query1)->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows1)) {
    echo "No matching NFs found in biomie_notas_fiscais_canonicas!\n";
} else {
    foreach ($rows1 as $r) {
        echo json_encode($r) . "\n";
    }
}

echo "\n--- DAILY COVERAGE ---\n";
$query2 = "
SELECT
  DATE(data_emissao) AS dia,
  COUNT(*) AS nfs,
  SUM(valor_total) AS total_nf,
  SUM(COALESCE(valor_frete,0)) AS frete,
  SUM(COALESCE(valor_outras_despesas,0)) AS outras,
  SUM(COALESCE(valor_seguro,0)) AS seguro,
  SUM(COALESCE(valor_base_comercial_nf,
      valor_total
      - COALESCE(valor_frete,0)
      - COALESCE(valor_outras_despesas,0)
      - COALESCE(valor_seguro,0)
  )) AS base_comercial
FROM biomie_notas_fiscais_canonicas
WHERE data_emissao BETWEEN '2026-05-23' AND '2026-05-25'
  AND operation_scope = 'commercial'
  AND COALESCE(is_biomie_commercial_visible, 1) = 1
GROUP BY DATE(data_emissao)
ORDER BY dia;
";

$rows2 = $pdo->query($query2)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows2 as $r) {
    echo json_encode($r) . "\n";
}
