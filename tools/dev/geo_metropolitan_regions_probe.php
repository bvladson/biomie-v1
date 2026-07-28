<?php

declare(strict_types=1);

/**
 * geo_metropolitan_regions_probe.php
 *
 * Probe das camadas de Regiões Metropolitanas CE (GEO.R1.5).
 *
 * Uso:
 *   php tools/dev/geo_metropolitan_regions_probe.php
 *   php tools/dev/geo_metropolitan_regions_probe.php --json
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) continue;
    [$k,$v] = array_pad(explode('=', substr($a, 2), 2), 2, true);
    $opts[$k] = $v;
}

$root = dirname(__DIR__, 2);
$r    = runProbe($root);

if (isset($opts['json'])) {
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($r['ready_to_deploy'] ? 0 : 1);
}
printReport($r);
exit($r['ready_to_deploy'] ? 0 : 1);

// ──────────────────────────────────────────────────────────────────────────────

/** @return array<string,mixed> */
function runProbe(string $root): array
{
    $files = [
        'metro_sobral'    => ['file' => 'metro_sobral_municipios.geojson',  'expected' => 18, 'name' => 'Metrópole Sobral'],
        'metro_cariri'    => ['file' => 'metro_cariri_municipios.geojson',   'expected' => 9,  'name' => 'Metrópole Cariri'],
        'metro_todas'     => ['file' => 'metro_todas_municipios.geojson',    'expected' => 27, 'name' => 'Todas as Metrópoles'],
    ];

    $accentNames = ['Sobral','Juazeiro do Norte','Crato','Barbalha','São Gonçalo do Amarante'];
    $r = [
        'probe'          => 'geo_metropolitan_regions_probe',
        'generated_at'   => date(DATE_ATOM),
        'regions'        => [],
        'issues'         => [],
        'warnings'       => [],
        'ready_to_deploy'=> false,
    ];

    $allOk = true;
    foreach ($files as $id => $cfg) {
        $path = $root . '/assets/geo/' . $cfg['file'];
        $res  = checkRegionFile($path, $cfg, $accentNames);
        $r['regions'][$id] = $res;
        if (!$res['ok']) {
            $allOk = false;
            foreach ($res['issues'] as $issue) {
                $r['issues'][] = "[{$id}] {$issue}";
            }
        }
    }

    // Garantia: registry PHP existe
    $registryFile = $root . '/app/Geo/GeoMetropolitanRegionRegistry.php';
    if (!file_exists($registryFile)) {
        $r['issues'][] = 'GeoMetropolitanRegionRegistry.php não encontrado.';
        $allOk = false;
    }

    $r['ready_to_deploy'] = $allOk && $r['issues'] === [];
    return $r;
}

/** @return array<string,mixed> */
function checkRegionFile(string $path, array $cfg, array $accentNames): array
{
    $res = ['file' => basename($path), 'ok' => false, 'features' => 0, 'issues' => []];

    if (!file_exists($path)) {
        $res['issues'][] = 'Arquivo não encontrado.';
        return $res;
    }
    $raw = file_get_contents($path);
    $geo = json_decode($raw ?? '', true);
    if (!is_array($geo) || ($geo['type'] ?? '') !== 'FeatureCollection') {
        $res['issues'][] = 'JSON inválido.';
        return $res;
    }
    $features = $geo['features'];
    $res['features'] = count($features);

    if (count($features) < $cfg['expected'] - 1) {
        $res['issues'][] = sprintf('Esperado ~%d features, encontrado %d.', $cfg['expected'], count($features));
    }

    // Campos obrigatórios
    $required = ['metro_region_id','metro_region_nome','codigo_ibge','municipio_nome','municipio_norm','cidade_key','source'];
    foreach ($required as $field) {
        $absent = count(array_filter($features, fn($f) => !array_key_exists($field, $f['properties'] ?? [])));
        if ($absent > 0) {
            $res['issues'][] = "Campo '{$field}' ausente em {$absent} features.";
        }
    }

    // Sem duplicados
    $codes = array_map(fn($f) => (string)($f['properties']['codigo_ibge'] ?? ''), $features);
    $dupes = count($codes) - count(array_unique($codes));
    if ($dupes > 0) {
        $res['issues'][] = "{$dupes} código(s) IBGE duplicados.";
    }

    // Ceará only
    $outside = array_filter($codes, fn($c) => !str_starts_with($c, '23'));
    if ($outside) {
        $res['issues'][] = count($outside) . ' município(s) fora do Ceará.';
    }

    // Encoding
    foreach ($features as $feat) {
        $nome = (string)($feat['properties']['municipio_nome'] ?? '');
        if ($nome && !mb_check_encoding($nome, 'UTF-8')) {
            $res['issues'][] = "Encoding inválido: '{$nome}'.";
            break;
        }
    }

    $res['ok'] = $res['issues'] === [];
    return $res;
}

function printReport(array $r): void
{
    $chk = fn(bool $v) => $v ? '[PASS]' : '[FAIL]';
    echo PHP_EOL;
    echo '╔════════════════════════════════════════════════════════════════╗' . PHP_EOL;
    echo '║  GEO — Probe Regiões Metropolitanas CE (GEO.R1.5)             ║' . PHP_EOL;
    echo '╚════════════════════════════════════════════════════════════════╝' . PHP_EOL . PHP_EOL;

    foreach ($r['regions'] as $id => $res) {
        echo '  ' . $chk($res['ok']) . "  {$res['file']} ({$res['features']} municípios)" . PHP_EOL;
        foreach ($res['issues'] as $i) echo '      ! ' . $i . PHP_EOL;
    }
    echo PHP_EOL;

    if (!empty($r['issues'])) {
        echo '── Issues ───────────────────────────────────────────────────────' . PHP_EOL;
        foreach ($r['issues'] as $i) echo '  [!] ' . $i . PHP_EOL;
        echo PHP_EOL;
    }

    echo '── Resultado ────────────────────────────────────────────────────' . PHP_EOL;
    echo ($r['ready_to_deploy'] ? '  [PASS] PRONTO PARA DEPLOY' : '  [FAIL] NÃO PRONTO') . PHP_EOL . PHP_EOL;
}
