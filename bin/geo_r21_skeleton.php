<?php
declare(strict_types=1);
/**
 * GEO.R2.1 — Inserção de skeleton rows para clientes sem geo_dim
 * Garante que TODAS as NFs participem do JOIN (mesmo como sem_cidade).
 * Uso: php bin/geo_r21_skeleton.php [--dry-run]
 */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$dryRun = in_array('--dry-run', $argv ?? []);
$config = new \BIomie\Config();
$pdo    = \BIomie\Database::connect($config, 'db');

echo "[GEO.R2.1 SKELETON] " . date('Y-m-d H:i:s') . " dry=" . ($dryRun?'true':'false') . "\n\n";

// Baseline
$beforeGeo  = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
$beforeAuth = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
$beforeCli  = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
echo "  ANTES: NFs geo=$beforeGeo/$beforeAuth (" . round($beforeGeo/$beforeAuth*100,1) . "%) | clientes_geo=$beforeCli\n\n";

// Find clients in NFs completely absent from geo_dim
$missing = $pdo->prepare("
    SELECT DISTINCT nf.codigo_cliente_omie, nf.nome_cliente, COUNT(*) AS nfs,
           COALESCE(SUM(nf.valor_total),0) AS valor,
           geo_dim.cep, geo_dim.cidade_raw
    FROM biomie_notas_fiscais_canonicas nf
    LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = nf.codigo_cliente_omie
    LEFT JOIN biomie_clientes_geo_dim geo_dim ON geo_dim.codigo_cliente_omie = nf.codigo_cliente_omie
    WHERE nf.status_fiscal = 'autorizada'
      AND g.codigo_cliente_omie IS NULL
      AND nf.codigo_cliente_omie IS NOT NULL
    GROUP BY nf.codigo_cliente_omie, nf.nome_cliente, geo_dim.cep, geo_dim.cidade_raw
    ORDER BY valor DESC
");
$missing->execute();
$rows = $missing->fetchAll(\PDO::FETCH_ASSOC);

echo "  Clientes sem geo_dim: " . count($rows) . "\n\n";
if (!$rows) {
    echo "  Nenhum cliente faltando — cobertura 100%!\n";
    exit(0);
}

$inserted = 0;
foreach ($rows as $r) {
    printf("  %-55s %3d NFs  R$%10s\n",
        substr($r['nome_cliente']??'',0,54), $r['nfs'],
        number_format((float)$r['valor'],2,',','.'));
    if (!$dryRun) {
        // Insert skeleton (empty city = sem_cidade in mart, but NF will participate in JOIN)
        $pdo->prepare("
            INSERT IGNORE INTO biomie_clientes_geo_dim
                (codigo_cliente_omie, cidade_norm, bairro_norm, cidade_key, bairro_key,
                 match_source, updated_at)
            VALUES (:cod, '', '', '', '', 'skeleton_sem_geo', NOW())
        ")->execute([':cod' => $r['codigo_cliente_omie']]);
        $inserted++;
    } else { $inserted++; }
}

echo "\n  Skeletons " . ($dryRun?'(simulados)':'inseridos') . ": $inserted\n\n";

// After
$afterGeo = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
$afterCli = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
$afterSem = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
echo "  DEPOIS: NFs geo=$afterGeo/$beforeAuth (" . round($afterGeo/$beforeAuth*100,1) . "%) | clientes_geo=$afterCli | sem_cidade=$afterSem\n";
echo "  GANHO: +" . ($afterGeo-$beforeGeo) . " NFs participando do JOIN\n";

if (!$dryRun && $inserted > 0) {
    echo "\n  >> Execute: php bin/materialize_marts.php para re-materializar marts geo\n";
}
echo "\n[GEO.R2.1 SKELETON] Concluído\n";
