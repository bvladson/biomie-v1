<?php
declare(strict_types=1);
/**
 * GEO.R2.1 — Enriquecimento geo LOCAL (sem API externa)
 * Usa biomie_clientes_dim.cidade + bairro + cep para preencher geo_dim
 * Uso: php bin/geo_r21_local_enrich.php [--dry-run]
 */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$dryRun = in_array('--dry-run', $argv ?? []);
$config = new \BIomie\Config();
$pdo    = \BIomie\Database::connect($config, 'db');

echo "[GEO.R2.1 LOCAL] " . date('Y-m-d H:i:s') . " dry=" . ($dryRun?'true':'false') . "\n\n";

function normKey(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $from = 'àáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞ';
    $to   = 'aaaaaaaceeeeiiiidnoooooouuuuytya aaaaaaaceeeeiiiidnoooooouu uuyt ';
    $s = strtr($s, mb_str_split($from, 1, 'UTF-8'), mb_str_split($to, 1, 'UTF-8'));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s ?? '', '_');
}

// ── Baseline ─────────────────────────────────────────────────────────────────
$beforeNfGeo  = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
$beforeNfAuth = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
$beforeSemCid = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
$beforeTotal  = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
echo "  ANTES: NFs geo=$beforeNfGeo/$beforeNfAuth (" . round($beforeNfGeo/$beforeNfAuth*100,1) . "%) | clientes=$beforeTotal | sem_cidade=$beforeSemCid\n\n";

// ── 1. Insert clients absent from geo_dim but with city in clientes_dim ───────
echo "  [1] Inserindo clientes ausentes da geo_dim com cidade em clientes_dim...\n";
$absent = $pdo->query("
    SELECT d.codigo_cliente_omie, d.nome_cliente,
           d.cidade, d.bairro, d.estado, d.cep
    FROM biomie_clientes_dim d
    LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = d.codigo_cliente_omie
    WHERE g.codigo_cliente_omie IS NULL
      AND d.cidade IS NOT NULL AND TRIM(d.cidade) != ''
")->fetchAll(\PDO::FETCH_ASSOC);

echo "    Encontrados: " . count($absent) . " clientes\n";
$inserted = 0;
foreach ($absent as $cli) {
    $cidadeNorm = normKey($cli['cidade']);
    $bairroNorm = !empty($cli['bairro']) ? normKey($cli['bairro']) : '';
    if (empty($cidadeNorm)) continue;
    printf("    + %-50s %s / %s\n", substr($cli['nome_cliente']??'',0,49), $cidadeNorm, $bairroNorm?:'-');
    if (!$dryRun) {
        $pdo->prepare("
            INSERT IGNORE INTO biomie_clientes_geo_dim
                (codigo_cliente_omie, cidade_norm, bairro_norm, cidade_key, bairro_key, updated_at)
            VALUES (:cod, :cidade, :bairro, :ckey, :bkey, NOW())
        ")->execute([':cod'=>$cli['codigo_cliente_omie'],':cidade'=>$cidadeNorm,':bairro'=>$bairroNorm,':ckey'=>$cidadeNorm,':bkey'=>$bairroNorm]);
        $inserted++;
    } else { $inserted++; }
}
echo "    Inseridos: $inserted\n\n";

// ── 2. Update sem_cidade using clientes_dim ───────────────────────────────────
echo "  [2] Atualizando geo_dim sem cidade usando clientes_dim...\n";
$needCity = $pdo->query("
    SELECT g.codigo_cliente_omie, d.nome_cliente, d.cidade, d.bairro
    FROM biomie_clientes_geo_dim g
    INNER JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = g.codigo_cliente_omie
    WHERE (g.cidade_norm IS NULL OR TRIM(g.cidade_norm) = '')
      AND d.cidade IS NOT NULL AND TRIM(d.cidade) != ''
")->fetchAll(\PDO::FETCH_ASSOC);
echo "    Encontrados: " . count($needCity) . " sem cidade\n";
$cityUpdated = 0;
foreach ($needCity as $cli) {
    $cidadeNorm = normKey($cli['cidade']);
    $bairroNorm = !empty($cli['bairro']) ? normKey($cli['bairro']) : '';
    if (empty($cidadeNorm)) continue;
    printf("    ↑ %-50s %s\n", substr($cli['nome_cliente']??'',0,49), $cidadeNorm);
    if (!$dryRun) {
        $pdo->prepare("UPDATE biomie_clientes_geo_dim SET cidade_norm=:c, cidade_key=:c, bairro_norm=COALESCE(NULLIF(bairro_norm,''),:b), bairro_key=COALESCE(NULLIF(bairro_key,''),:b), updated_at=NOW() WHERE codigo_cliente_omie=:cod")->execute([':c'=>$cidadeNorm,':b'=>$bairroNorm,':cod'=>$cli['codigo_cliente_omie']]);
        $cityUpdated++;
    } else { $cityUpdated++; }
}
echo "    Atualizados: $cityUpdated\n\n";

// ── 3. Insert NF clients with no geo and no clientes_dim ─────────────────────
echo "  [3] Clientes em NFs sem geo e sem clientes_dim...\n";
$nfOnly = $pdo->query("
    SELECT DISTINCT nf.codigo_cliente_omie, nf.nome_cliente, COUNT(*) AS nfs
    FROM biomie_notas_fiscais_canonicas nf
    LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=nf.codigo_cliente_omie
    LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie=nf.codigo_cliente_omie
    WHERE nf.status_fiscal='autorizada' AND g.codigo_cliente_omie IS NULL
      AND (d.codigo_cliente_omie IS NULL OR d.cidade IS NULL OR TRIM(d.cidade)='')
    GROUP BY nf.codigo_cliente_omie, nf.nome_cliente
    ORDER BY nfs DESC
")->fetchAll(\PDO::FETCH_ASSOC);
echo "    " . count($nfOnly) . " clientes sem endereço em nenhuma fonte local\n";
$skeletonInserted = 0;
foreach ($nfOnly as $cli) {
    printf("    ✘ %-50s %d NFs (sem endereço — skeleton sem_cidade)\n", substr($cli['nome_cliente']??'',0,49), $cli['nfs']);
    // Insert skeleton so they appear in mart (as sem_cidade)
    if (!$dryRun) {
        $pdo->prepare("INSERT IGNORE INTO biomie_clientes_geo_dim (codigo_cliente_omie, cidade_norm, bairro_norm, cidade_key, bairro_key, updated_at) VALUES (:cod, '', '', '', '', NOW())")->execute([':cod'=>$cli['codigo_cliente_omie']]);
        $skeletonInserted++;
    } else { $skeletonInserted++; }
}
echo "    Skeletons criados: $skeletonInserted\n\n";

// ── After ─────────────────────────────────────────────────────────────────────
$afterNfGeo  = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
$afterSemCid = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
$afterTotal  = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();

echo "  DEPOIS: NFs geo=$afterNfGeo/$beforeNfAuth (" . round($afterNfGeo/$beforeNfAuth*100,1) . "%) | clientes=$afterTotal | sem_cidade=$afterSemCid\n";
echo "  GANHO: +" . ($afterNfGeo-$beforeNfGeo) . " NFs mapeadas | +" . ($afterTotal-$beforeTotal) . " novos clientes geo\n";

if (!$dryRun && ($inserted + $cityUpdated + $skeletonInserted) > 0) {
    echo "\n  >> Execute: php bin/materialize_marts.php para re-materializar marts geo\n";
}
echo "\n[GEO.R2.1 LOCAL] Concluído\n";
