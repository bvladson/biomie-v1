<?php
declare(strict_types=1);
$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pdo = \BIomie\Database::connect(new \BIomie\Config(), 'db');

echo "[GEO.R2.1] " . date('Y-m-d H:i:s') . "\n";

// Check schema first
$cols = array_column($pdo->query("SHOW COLUMNS FROM biomie_clientes_geo_dim")->fetchAll(\PDO::FETCH_ASSOC), 'Field');
echo "Columns: " . implode(', ', $cols) . "\n";

// Count missing
$n = (int)$pdo->query("
    SELECT COUNT(DISTINCT nf.codigo_cliente_omie)
    FROM biomie_notas_fiscais_canonicas nf
    LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = nf.codigo_cliente_omie
    WHERE nf.status_fiscal = 'autorizada'
      AND nf.codigo_cliente_omie IS NOT NULL
      AND g.codigo_cliente_omie IS NULL
")->fetchColumn();
echo "Missing clients: $n\n";

if ($n === 0) { echo "Nothing to do.\n"; exit(0); }

// Bulk INSERT IGNORE using a subquery
$result = $pdo->exec("
    INSERT IGNORE INTO biomie_clientes_geo_dim
        (codigo_cliente_omie, cidade_norm, bairro_norm, cidade_key, bairro_key, match_source)
    SELECT DISTINCT nf.codigo_cliente_omie, '', '', '', '', 'skeleton_sem_geo'
    FROM biomie_notas_fiscais_canonicas nf
    LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = nf.codigo_cliente_omie
    WHERE nf.status_fiscal = 'autorizada'
      AND nf.codigo_cliente_omie IS NOT NULL
      AND g.codigo_cliente_omie IS NULL
");
echo "Inserted: $result rows\n";

$afterGeo = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
$total    = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
$geoCli   = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
echo "NFs with geo: $afterGeo/$total (" . round($afterGeo/$total*100,1) . "%)\n";
echo "geo_dim total: $geoCli\n";
echo "Done.\n";
