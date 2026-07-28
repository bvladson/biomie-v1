<?php

declare(strict_types=1);

/**
 * tools/dev/geo_official_geometry_coverage_audit.php — GEO.R1.1 Fase 6
 *
 * Diagnostica a cobertura territorial das vendas no mart:
 *   - Faturamento com cidade reconhecida vs. sem cidade
 *   - Faturamento com bairro oficial vs. sem bairro
 *   - Faturamento em Fortaleza sem bairro oficial
 *   - Faturamento em cidades fora da RMF
 *   - Aliases de bairro/cidade detectados
 *   - Territórios com venda mas sem geometria
 *
 * Não corrige dados — apenas sinaliza para ação no Omie.
 *
 * Uso:
 *   php tools/dev/geo_official_geometry_coverage_audit.php \
 *       --start=2026-01-01 --end=2026-05-31 [--pretty] [--json]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$opts     = getopt('', ['start:', 'end:', 'pretty', 'json']);
$pretty   = array_key_exists('pretty', $opts);
$jsonMode = array_key_exists('json',   $opts);
$start    = (string) ($opts['start'] ?? date('Y-m-01'));
$end      = (string) ($opts['end']   ?? date('Y-m-d'));

$startComp = substr($start, 0, 7);
$endComp   = substr($end,   0, 7);

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

$checks = [];
$report = [];

// ── 1. Total do período ───────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(faturamento),0) AS fat, COALESCE(SUM(qtd_notas),0) AS nfs,
                COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR),documento,cliente_nome)) AS clientes
         FROM mart_compradores_por_geo WHERE competencia BETWEEN :s AND :e"
    );
    $stmt->execute([':s'=>$startComp,':e'=>$endComp]);
    $total = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $totalFat = (float)($total['fat'] ?? 0);
    $totalNfs = (int)  ($total['nfs'] ?? 0);
    $report['total'] = ['receita'=>$totalFat,'nfs'=>$totalNfs,'clientes'=>(int)($total['clientes']??0)];
} catch (\Throwable) { $totalFat = 0; $totalNfs = 0; $report['total'] = []; }

$pct = fn(float $v) => $totalFat > 0 ? round($v/$totalFat*100,1) : 0.0;

// ── 2. Com cidade reconhecida vs. sem cidade ──────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN cidade_norm IS NOT NULL AND TRIM(cidade_norm)!='' THEN faturamento ELSE 0 END),0) AS com_cidade,
                COALESCE(SUM(CASE WHEN cidade_norm IS NULL OR TRIM(cidade_norm)='' THEN faturamento ELSE 0 END),0) AS sem_cidade
         FROM mart_compradores_por_geo WHERE competencia BETWEEN :s AND :e"
    );
    $stmt->execute([':s'=>$startComp,':e'=>$endComp]);
    $r = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $comCidade = (float)($r['com_cidade']??0);
    $semCidade = (float)($r['sem_cidade']??0);
    $checks[] = ['id'=>'com_cidade_pct','pass'=>$pct($semCidade)<30,
        'detail'=>"Com cidade: R\$".number_format($comCidade,0,',','.')." ({$pct($comCidade)}%) | Sem cidade: R\$".number_format($semCidade,0,',','.')." ({$pct($semCidade)}%)"];
    $report['cobertura_cidade'] = ['com_cidade'=>$comCidade,'sem_cidade'=>$semCidade,'sem_cidade_pct'=>$pct($semCidade)];
} catch (\Throwable) {}

// ── 3. Com bairro vs. sem bairro ──────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN bairro_norm IS NOT NULL AND TRIM(bairro_norm)!='' AND bairro_norm!='nao_informado' THEN faturamento ELSE 0 END),0) AS com_bairro,
                COALESCE(SUM(CASE WHEN bairro_norm IS NULL OR TRIM(bairro_norm)='' OR bairro_norm='nao_informado' THEN faturamento ELSE 0 END),0) AS sem_bairro
         FROM mart_compradores_por_geo WHERE competencia BETWEEN :s AND :e"
    );
    $stmt->execute([':s'=>$startComp,':e'=>$endComp]);
    $r = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $comBairro = (float)($r['com_bairro']??0);
    $semBairro = (float)($r['sem_bairro']??0);
    $checks[] = ['id'=>'com_bairro_pct','pass'=>$pct($semBairro)<50,
        'detail'=>"Com bairro: R\$".number_format($comBairro,0,',','.')." ({$pct($comBairro)}%) | Sem bairro: R\$".number_format($semBairro,0,',','.')." ({$pct($semBairro)}%)"];
    $report['cobertura_bairro'] = ['com_bairro'=>$comBairro,'sem_bairro'=>$semBairro,'sem_bairro_pct'=>$pct($semBairro)];
} catch (\Throwable) {}

// ── 4. Fortaleza sem bairro oficial ──────────────────────────────────────────
try {
    $hasDim = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_bairros_fortaleza_dim' LIMIT 1")->fetchColumn();

    if ($hasDim) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(g.faturamento),0) AS fat,
                    COUNT(DISTINCT COALESCE(CAST(g.codigo_cliente_omie AS CHAR),g.documento,g.cliente_nome)) AS clientes
             FROM mart_compradores_por_geo g
             LEFT JOIN biomie_bairros_fortaleza_dim d ON LOWER(g.bairro_norm) = LOWER(d.bairro_nome_normalizado)
             WHERE g.competencia BETWEEN :s AND :e
               AND LOWER(TRIM(COALESCE(g.cidade_norm, '')))='fortaleza'
               AND (g.bairro_norm IS NULL OR TRIM(g.bairro_norm)='' OR g.bairro_norm='nao_informado' OR d.id IS NULL)"
        );
        $stmt->execute([':s'=>$startComp,':e'=>$endComp]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $fat = (float)($r['fat']??0);
        $checks[] = ['id'=>'fortaleza_sem_bairro_oficial','pass'=>$pct($fat)<40,
            'detail'=>"Fortaleza sem bairro oficial: R\$".number_format($fat,0,',','.')." ({$pct($fat)}%) — atualizar endereço no Omie."];
        $report['fortaleza_sem_bairro'] = ['receita'=>$fat,'pct'=>$pct($fat)];
    }
} catch (\Throwable) {}

// ── 5. Cidades com venda fora do Ceará ───────────────────────────────────────
try {
    $hasMun = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_geo_municipios_ce' LIMIT 1")->fetchColumn();
    if ($hasMun) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(g.faturamento),0) AS fat
             FROM mart_compradores_por_geo g
             WHERE g.competencia BETWEEN :s AND :e
               AND g.cidade_norm IS NOT NULL AND TRIM(g.cidade_norm)!=''
               AND NOT EXISTS (SELECT 1 FROM biomie_geo_municipios_ce m WHERE LOWER(g.cidade_norm)=m.nome_norm)"
        );
        $stmt->execute([':s'=>$startComp,':e'=>$endComp]);
        $fat = (float)($stmt->fetchColumn()??0);
        $checks[] = ['id'=>'vendas_fora_ce','pass'=>true,
            'detail'=>"Faturamento em cidades não mapeadas no CE: R\$".number_format($fat,0,',','.')." ({$pct($fat)}%) — pode incluir outros estados."];
        $report['fora_ce'] = ['receita'=>$fat,'pct'=>$pct($fat)];
    }
} catch (\Throwable) {}

// ── 6. Top cidades sem geometria (com venda mas não no GeoJSON) ───────────────
$citiesMissingGeo = [];
try {
    $geoFile = dirname(__DIR__, 2) . '/assets/geo/ceara_mun.json';
    if (is_file($geoFile)) {
        $geoData = json_decode((string)file_get_contents($geoFile), true);
        $geoCidades = [];
        foreach ($geoData['features'] ?? [] as $feat) {
            $n = $feat['properties']['municipio_norm'] ?? '';
            if ($n) $geoCidades[$n] = true;
        }

        $stmt = $pdo->prepare(
            "SELECT LOWER(cidade_norm) AS cn, COALESCE(SUM(faturamento),0) AS fat
             FROM mart_compradores_por_geo
             WHERE competencia BETWEEN :s AND :e
               AND cidade_norm IS NOT NULL AND TRIM(cidade_norm)!=''
             GROUP BY LOWER(cidade_norm) ORDER BY fat DESC LIMIT 50"
        );
        $stmt->execute([':s'=>$startComp,':e'=>$endComp]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $cn = (string)($r['cn']??'');
            if ($cn && !isset($geoCidades[$cn])) {
                $citiesMissingGeo[] = ['cidade_norm'=>$cn,'receita'=>(float)($r['fat']??0)];
            }
        }
    }
    $checks[] = ['id'=>'cidades_sem_geometria','pass'=>count($citiesMissingGeo)<20,
        'detail'=>count($citiesMissingGeo)." cidades com venda mas sem geometria no ceara_mun.json (pode ser outro estado ou alias)."];
    $report['cidades_sem_geometria'] = array_slice($citiesMissingGeo,0,10);
} catch (\Throwable) {}

// ── 7. Aliases detectados (bairros com variações conhecidas) ──────────────────
$knownAliases = [
    'vl_velha'=>'vila_velha','vl_peri'=>'vila_peri','prq_iracema'=>'parque_iracema',
    'prq_manibura'=>'parque_manibura','prq_dois_irmaos'=>'parque_dois_irmaos',
    'd_lustosa'=>'dom_lustosa','lga_redonda'=>'lagoa_redonda',
    'manuel_satiro'=>'manoel_satiro','pres_kennedy'=>'presidente_kennedy',
];
try {
    $aliasFat = 0.0;
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :s AND :e AND bairro_norm IN ('" . implode("','", array_keys($knownAliases)) . "')"
    );
    $stmt->execute([':s'=>$startComp,':e'=>$endComp]);
    $aliasFat = (float)$stmt->fetchColumn();
    $checks[] = ['id'=>'known_aliases','pass'=>true,
        'detail'=>"Aliases de bairro conhecidos no mart: R\$".number_format($aliasFat,0,',','.')." ({$pct($aliasFat)}%) — diagnóstico, não erro."];
    $report['aliases'] = ['receita'=>$aliasFat,'count'=>count($knownAliases),'pct'=>$pct($aliasFat)];
} catch (\Throwable) {}

// ── 8. GeoJSONs status ────────────────────────────────────────────────────────
$root = dirname(__DIR__, 2);
$geoFiles = [
    'fortaleza_bairros.json' => ['expected'=>121,'critical'=>true,'is_approx'=>false],
    'rmf_municipios.json'    => ['expected'=>19, 'critical'=>false,'is_approx'=>false],
    'ceara_mun.json'         => ['expected'=>184,'critical'=>false,'is_approx'=>false],
    'ceara_regioes.json'     => ['expected'=>14, 'critical'=>false,'is_approx'=>true],
];
$geoReport = [];
foreach ($geoFiles as $f => $cfg) {
    $path = $root . '/assets/geo/' . $f;
    if (!is_file($path)) {
        $geoReport[$f] = ['exists'=>false,'features'=>null,'is_approx'=>$cfg['is_approx'],'status'=>'MISS'];
        continue;
    }
    $data = json_decode((string)file_get_contents($path), true);
    $ct   = count($data['features'] ?? []);
    $meta = $data['metadata'] ?? [];
    $source = $meta['source'] ?? '';
    $isApprox = str_contains($source,'bbox') || str_contains($source,'aproxim') || ($cfg['is_approx'] && !str_contains($source,'oficial'));
    $status = $ct >= $cfg['expected'] ? ($isApprox ? 'WARN' : 'PASS') : 'WARN';
    $geoReport[$f] = ['exists'=>true,'features'=>$ct,'expected'=>$cfg['expected'],'size_kb'=>round(filesize($path)/1024,1),'is_approx'=>$isApprox,'source'=>$source,'status'=>$status];
    $checks[] = ['id'=>'geo_'.$f,'pass'=>$ct>=$cfg['expected'],
        'detail'=>"{$f}: {$ct} features (esp. {$cfg['expected']}) — " . ($isApprox ? 'bbox derivado' : 'oficial') . " — {$status}"];
}
$report['geojsons'] = $geoReport;

// ── CTA para Omie ─────────────────────────────────────────────────────────────
$report['cta_omie'] = 'Atualize o endereço/cadastro dos clientes no Omie para que apareçam corretamente no mapa. Não corrigir dados automaticamente.';

// ── Output ───────────────────────────────────────────────────────────────────
$passCount = count(array_filter($checks, fn($c) => $c['pass']));
$total     = count($checks);
$allPass   = $passCount === $total;

if ($jsonMode) {
    echo json_encode([
        'geo_official_geometry_coverage_audit' => [
            'period'=>['start'=>$start,'end'=>$end],
            'overall'=>$allPass?'PASS':'WARN',
            'passed'=>$passCount,'total'=>$total,
            'checks'=>$checks,'report'=>$report,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo PHP_EOL;
    exit(0);
}

if ($pretty) {
    $hr = str_repeat('═', 72);
    echo PHP_EOL . $hr . PHP_EOL;
    echo "  GEO.R1.1 — Coverage Audit (Fase 6)\n";
    echo "  Período: {$start} → {$end}\n";
    echo $hr . PHP_EOL . PHP_EOL;

    if (!empty($report['total'])) {
        printf("  TOTAL: R\$%s | %d NFs\n\n", number_format((float)($report['total']['receita']??0),0,',','.'), (int)($report['total']['nfs']??0));
    }

    foreach ($checks as $c) {
        $b = $c['pass'] ? "\033[32mPASS\033[0m" : "\033[33mWARN\033[0m";
        echo "  [{$b}]  {$c['detail']}\n";
    }

    if (!empty($report['cidades_sem_geometria'])) {
        echo PHP_EOL . "  CIDADES SEM GEOMETRIA NO MAP (top 5):\n";
        foreach (array_slice($report['cidades_sem_geometria'],0,5) as $c) {
            printf("    %-30s R\$%s\n", $c['cidade_norm'], number_format($c['receita'],0,',','.'));
        }
    }

    echo PHP_EOL . "  CTA: " . ($report['cta_omie'] ?? '') . PHP_EOL;
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    $ob = $allPass ? "\033[32mPASS\033[0m" : "\033[33mWARN\033[0m";
    echo "  [{$ob}]  {$passCount}/{$total} checks.\n" . PHP_EOL;
} else {
    echo ($allPass ? 'PASS' : 'WARN') . " {$passCount}/{$total}\n";
}
exit(0);
