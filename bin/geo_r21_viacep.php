<?php
declare(strict_types=1);
/**
 * GEO.R2.1 — Enriquecimento via CEP (ViaCEP) para clientes sem geo
 * Usa CEP da biomie_clientes_dim para buscar cidade/bairro via ViaCEP
 * Uso: php bin/geo_r21_viacep.php [--dry-run] [--limit=100]
 */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$opts   = getopt('', ['dry-run', 'limit:']);
$dryRun = isset($opts['dry-run']);
$limit  = (int)($opts['limit'] ?? 200);

$config = new \BIomie\Config();
$pdo    = \BIomie\Database::connect($config, 'db');

echo "[GEO.R2.1 ViaCEP] " . date('Y-m-d H:i:s') . " dry=$" . ($dryRun?'true':'false') . " limit=$limit\n\n";

// ── Find clients without geo_dim that have CEP in clientes_dim ────────────────
$missingWithCep = $pdo->prepare("
    SELECT d.codigo_cliente_omie, d.nome_cliente, d.cep,
           d.cidade AS cidade_dim, d.bairro AS bairro_dim, d.estado AS estado_dim
    FROM biomie_clientes_dim d
    LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = d.codigo_cliente_omie
    WHERE g.codigo_cliente_omie IS NULL
      AND d.cep IS NOT NULL AND TRIM(d.cep) != '' AND LENGTH(REPLACE(d.cep,'-','')) = 8
    LIMIT :lim
");
$missingWithCep->bindValue(':lim', $limit, \PDO::PARAM_INT);
$missingWithCep->execute();
$clients = $missingWithCep->fetchAll(\PDO::FETCH_ASSOC);

// ── Also find clients IN geo_dim but missing city ─────────────────────────────
$missingCity = $pdo->prepare("
    SELECT g.codigo_cliente_omie, d.nome_cliente, d.cep,
           d.cidade AS cidade_dim, d.bairro AS bairro_dim, d.estado AS estado_dim,
           g.cidade_norm, g.bairro_norm
    FROM biomie_clientes_geo_dim g
    INNER JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = g.codigo_cliente_omie
    WHERE (g.cidade_norm IS NULL OR TRIM(g.cidade_norm) = '')
      AND d.cep IS NOT NULL AND TRIM(d.cep) != ''
    LIMIT :lim
");
$missingCity->bindValue(':lim', $limit, \PDO::PARAM_INT);
$missingCity->execute();
$clientsNeedCity = $missingCity->fetchAll(\PDO::FETCH_ASSOC);

echo "  Clientes sem geo_dim com CEP disponível: " . count($clients) . "\n";
echo "  Clientes com geo_dim sem cidade com CEP: " . count($clientsNeedCity) . "\n\n";

// ── ViaCEP lookup helper ──────────────────────────────────────────────────────
function viaCep(string $cep): ?array {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) !== 8) return null;
    $url = "https://viacep.com.br/ws/$cep/json/";
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!is_array($data) || isset($data['erro'])) return null;
    return $data; // logradouro, bairro, localidade, uf, cep
}

function normKey(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}

// ── Process: clients without geo_dim ─────────────────────────────────────────
$inserted = 0; $updated_city = 0; $not_found = 0; $skipped = 0;

foreach ($clients as $client) {
    $cep  = preg_replace('/\D/', '', $client['cep'] ?? '');
    $data = viaCep($cep);

    if (!$data) {
        // ViaCEP failed — try using clientes_dim data if city is available
        if (!empty($client['cidade_dim'])) {
            $cidadeNorm = normKey($client['cidade_dim']);
            $bairroNorm = !empty($client['bairro_dim']) ? normKey($client['bairro_dim']) : '';
            $data = ['localidade' => $client['cidade_dim'], 'bairro' => $client['bairro_dim'] ?? '', 'uf' => $client['estado_dim'] ?? 'CE'];
        } else {
            $not_found++;
            printf("  ✘ %-50s cep:%-10s NOT FOUND\n", substr($client['nome_cliente'],0,49), $cep);
            continue;
        }
    }

    $cidadeNorm = normKey($data['localidade'] ?? '');
    $bairroNorm = normKey($data['bairro']     ?? '');
    $cidadeKey  = $cidadeNorm;
    $bairroKey  = $bairroNorm;

    if (empty($cidadeNorm)) { $not_found++; continue; }

    printf("  ✔ %-50s %s / %s\n", substr($client['nome_cliente'],0,49), $cidadeNorm, $bairroNorm ?: '-');

    if (!$dryRun) {
        $pdo->prepare("
            INSERT INTO biomie_clientes_geo_dim
                (codigo_cliente_omie, cidade_norm, bairro_norm, cidade_key, bairro_key, updated_at)
            VALUES
                (:cod, :cidade, :bairro, :ckey, :bkey, NOW())
            ON DUPLICATE KEY UPDATE
                cidade_norm = IF(cidade_norm IS NULL OR TRIM(cidade_norm)='', VALUES(cidade_norm), cidade_norm),
                bairro_norm = IF(bairro_norm IS NULL OR TRIM(bairro_norm)='', VALUES(bairro_norm), bairro_norm),
                cidade_key  = IF(cidade_key  IS NULL OR TRIM(cidade_key)='',  VALUES(cidade_key),  cidade_key),
                bairro_key  = IF(bairro_key  IS NULL OR TRIM(bairro_key)='',  VALUES(bairro_key),  bairro_key),
                updated_at  = NOW()
        ")->execute([
            ':cod'    => $client['codigo_cliente_omie'],
            ':cidade' => $cidadeNorm,
            ':bairro' => $bairroNorm,
            ':ckey'   => $cidadeKey,
            ':bkey'   => $bairroKey,
        ]);
        $inserted++;
    } else {
        $inserted++;
    }
    usleep(100000); // 100ms between API calls
}

// ── Process: clients with geo_dim but missing city ────────────────────────────
foreach ($clientsNeedCity as $client) {
    $cep = preg_replace('/\D/', '', $client['cep'] ?? '');
    $data = viaCep($cep);
    if (!$data && empty($client['cidade_dim'])) { $not_found++; continue; }
    $cidadeNorm = $data ? normKey($data['localidade'] ?? '') : normKey($client['cidade_dim'] ?? '');
    $bairroNorm = $data ? normKey($data['bairro'] ?? '') : normKey($client['bairro_dim'] ?? '');
    if (empty($cidadeNorm)) { $not_found++; continue; }
    printf("  ↑ %-50s %s / %s (was sem_cidade)\n", substr($client['nome_cliente'],0,49), $cidadeNorm, $bairroNorm ?: '-');
    if (!$dryRun) {
        $pdo->prepare("UPDATE biomie_clientes_geo_dim SET cidade_norm=:c, cidade_key=:ck, bairro_norm=COALESCE(NULLIF(bairro_norm,''),:b), bairro_key=COALESCE(NULLIF(bairro_key,''),:bk), updated_at=NOW() WHERE codigo_cliente_omie=:cod AND (cidade_norm IS NULL OR TRIM(cidade_norm)='')")
            ->execute([':c'=>$cidadeNorm,':ck'=>$cidadeNorm,':b'=>$bairroNorm,':bk'=>$bairroNorm,':cod'=>$client['codigo_cliente_omie']]);
        $updated_city++;
    } else { $updated_city++; }
    usleep(100000);
}

// ── After ─────────────────────────────────────────────────────────────────────
echo "\n  Resultados:\n";
echo "    Inseridos/enriquecidos: $inserted\n";
echo "    Cidade atualizada: $updated_city\n";
echo "    Não encontrados: $not_found\n";

if (!$dryRun && ($inserted + $updated_city) > 0) {
    // Trigger mart refresh
    echo "\n  Re-materializando mart geo...\n";
    $geoMart = new \BIomie\Modules\Geo\Infrastructure\GeoAggregateBuilder($pdo, new \BIomie\Logger(dirname(__DIR__).'/storage/logs/geo_r21_viacep.log'));
    // Don't re-materialize here - just note that it should be done
    echo "  Execute: php bin/materialize_marts.php para atualizar geo marts\n";
}

$afterNfGeo  = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
$afterNfAuth = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
$afterSemCid = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
$afterCliGeo = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
echo "\n  Cobertura pós ViaCEP:\n";
echo "    NFs com geo: $afterNfGeo / $afterNfAuth (" . round($afterNfGeo/$afterNfAuth*100,1) . "%)\n";
echo "    Clientes geo: $afterCliGeo\n";
echo "    Sem cidade: $afterSemCid\n";
echo "\n[GEO.R2.1 ViaCEP] Concluído — " . date('Y-m-d H:i:s') . "\n";
