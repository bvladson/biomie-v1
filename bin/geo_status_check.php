<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$c = new \BIomie\Config();
$p = \BIomie\Database::connect($c, 'db');

$geo  = (int)$p->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
$semB = (int)$p->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE bairro_norm IS NULL OR TRIM(bairro_norm)=''")->fetchColumn();
echo "geo_dim total: $geo\n";
echo "sem_bairro: $semB\n";

try {
    $q = (int)$p->query("SELECT COUNT(*) FROM biomie_clientes_para_enriquecer WHERE status IN ('pendente', 'processando')")->fetchColumn();
    echo "queue active: $q\n";
} catch (Exception $e) {
    echo "queue_table: " . $e->getMessage() . "\n";
}

// NF coverage
try {
    $r = $p->query("SELECT COUNT(*) AS n, SUM(CASE WHEN geo.codigo_cliente_omie IS NOT NULL THEN 1 ELSE 0 END) AS wg FROM biomie_notas_fiscais_canonicas nf LEFT JOIN biomie_clientes_geo_dim geo ON geo.codigo_cliente_omie=nf.codigo_cliente_omie WHERE nf.status_fiscal='autorizada'")->fetch(\PDO::FETCH_ASSOC);
    $pct = $r['n'] > 0 ? round($r['wg']/$r['n']*100,1) : 0;
    echo "NF geo coverage: {$r['wg']}/{$r['n']} ($pct%)\n";
} catch (Exception $e) {
    echo "coverage_err: " . $e->getMessage() . "\n";
}

// mart_compradores sem cidade
$semC = (int)$p->query("SELECT COUNT(*) FROM mart_compradores_por_geo WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
$martTotal = (int)$p->query("SELECT COUNT(*) FROM mart_compradores_por_geo")->fetchColumn();
$semCPct = $martTotal > 0 ? round($semC/$martTotal*100,1) : 0;
echo "mart sem_cidade: $semC/$martTotal ($semCPct%)\n";
