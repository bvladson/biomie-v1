<?php

declare(strict_types=1);

/**
 * geo_ceara_municipios_source_probe.php
 *
 * Probe do GeoJSON oficial de municípios do Ceará (GEO.R1.4).
 * Retorna [PASS] PRONTO PARA DEPLOY somente quando todas as validações críticas passam.
 *
 * Uso:
 *   php tools/dev/geo_ceara_municipios_source_probe.php
 *   php tools/dev/geo_ceara_municipios_source_probe.php --json
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts  = getCliArgs($argv);
$root  = dirname(__DIR__, 2);
$r     = runProbe($root);

if (isset($opts['json'])) {
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($r['ready_to_deploy'] ? 0 : 1);
}
printReport($r);
exit($r['ready_to_deploy'] ? 0 : 1);

// ──────────────────────────────────────────────────────────────────────────────

function getCliArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) continue;
        [$k,$v] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $out[$k] = $v;
    }
    return $out;
}

/** @return array<string,mixed> */
function runProbe(string $root): array
{
    $path     = $root . '/assets/geo/ceara_municipios_oficial.geojson';
    $required = ['layer_id','source','source_year','crs_origin','generated_at',
                 'codigo_ibge','municipio_nome','municipio_norm','uf','cidade_key','label','join_key'];
    $accentNames = ['Eusébio','Maracanaú','São Gonçalo do Amarante','Itapipoca','Quixeramobim'];

    $r = [
        'probe'            => 'geo_ceara_municipios_source_probe',
        'generated_at'     => date(DATE_ATOM),
        'path'             => $path,
        'exists'           => false,
        'valid_json'       => false,
        'count_ok'         => false,
        'fields_ok'        => false,
        'no_dupes'         => false,
        'geometry_ok'      => false,
        'encoding_ok'      => false,
        'metadata_ok'      => false,
        'size_ok'          => false,
        'total_features'   => 0,
        'file_size_kb'     => 0,
        'issues'           => [],
        'warnings'         => [],
        'ready_to_deploy'  => false,
    ];

    if (!file_exists($path)) {
        $r['issues'][] = 'Arquivo não encontrado. Execute: python tools/dev/geo_generate_ceara_municipios.py';
        return $r;
    }
    $r['exists'] = true;

    $sizeKb = (int) round((filesize($path) ?: 0) / 1024);
    $r['file_size_kb'] = $sizeKb;
    $r['size_ok']      = $sizeKb < 5000;
    if ($sizeKb >= 5000) {
        $r['issues'][] = "Arquivo grande ({$sizeKb} KB). Re-gere com tolerance maior.";
    }

    $raw = file_get_contents($path);
    $geo = json_decode($raw ?? '', true);
    if (!is_array($geo) || ($geo['type'] ?? '') !== 'FeatureCollection' || !isset($geo['features'])) {
        $r['issues'][] = 'Não é um GeoJSON FeatureCollection válido.';
        return $r;
    }
    $r['valid_json']     = true;
    $features            = $geo['features'];
    $r['total_features'] = count($features);

    // Check 1: contagem
    if (count($features) !== 184) {
        $r['issues'][] = sprintf('Esperado 184 features, encontrado %d.', count($features));
    } else {
        $r['count_ok'] = true;
    }

    // Check 2: campos obrigatórios (amostra 10)
    $fieldIssues = checkFields(array_slice($features, 0, 10), $required);
    $r['fields_ok'] = $fieldIssues === [];
    foreach ($fieldIssues as $i) $r['issues'][] = $i;

    // Check 3: duplicados
    $codes = array_map(fn($f) => (string) ($f['properties']['codigo_ibge'] ?? ''), $features);
    $dupes = count($codes) - count(array_unique($codes));
    if ($dupes > 0) {
        $r['issues'][] = "{$dupes} código(s) IBGE duplicados.";
    } else {
        $r['no_dupes'] = true;
    }

    // Check 4: municípios fora do CE (código não começa com 23)
    $outside = array_filter($codes, fn($c) => str_starts_with($c, '23') === false);
    if ($outside) {
        $r['issues'][] = count($outside) . ' município(s) com código fora do Ceará.';
    }

    // Check 5: geometrias
    $geomIssues = [];
    foreach (array_slice($features, 0, 50) as $feat) {
        $t = $feat['geometry']['type'] ?? '';
        if (!in_array($t, ['Polygon','MultiPolygon'], true)) {
            $geomIssues[] = "Tipo inválido: {$t}";
        }
        if (empty($feat['geometry']['coordinates'] ?? [])) {
            $geomIssues[] = 'Coordenadas vazias';
        }
    }
    $r['geometry_ok'] = $geomIssues === [];
    foreach (array_unique($geomIssues) as $i) $r['issues'][] = $i;

    // Check 6: encoding
    $encOk = true;
    foreach ($features as $feat) {
        $nome = (string) ($feat['properties']['municipio_nome'] ?? '');
        if ($nome && !mb_check_encoding($nome, 'UTF-8')) {
            $r['issues'][] = "Encoding inválido: '{$nome}'";
            $encOk = false;
            break;
        }
    }
    $r['encoding_ok'] = $encOk;

    // Check 7: metadados
    $meta = $geo['metadata'] ?? [];
    if (empty($meta['source'])) {
        $r['issues'][] = 'metadata.source ausente.';
    }
    if (empty($meta['source_year'])) {
        $r['issues'][] = 'metadata.source_year ausente.';
    }
    if (empty($meta['crs_output'])) {
        $r['warnings'][] = 'metadata.crs_output ausente (menor prioridade).';
    }
    $r['metadata_ok'] = empty(array_filter($r['issues'], fn($i) => str_contains($i, 'metadata.')));

    $r['ready_to_deploy'] =
        $r['exists'] && $r['valid_json'] && $r['count_ok'] &&
        $r['fields_ok'] && $r['no_dupes'] && $r['geometry_ok'] &&
        $r['encoding_ok'] && $r['size_ok'] && $r['issues'] === [];

    return $r;
}

/** @return list<string> */
function checkFields(array $sample, array $required): array
{
    $issues = [];
    foreach ($required as $field) {
        $absent = 0;
        foreach ($sample as $feat) {
            if (!array_key_exists($field, $feat['properties'] ?? [])) $absent++;
        }
        if ($absent === count($sample)) {
            $issues[] = "Campo '{$field}' ausente em todas as features.";
        } elseif ($absent > 0) {
            $issues[] = "Campo '{$field}' ausente em {$absent}/" . count($sample) . " features.";
        }
    }
    return $issues;
}

function printReport(array $r): void
{
    $chk = fn(bool $v) => $v ? '[PASS]' : '[FAIL]';
    echo PHP_EOL;
    echo '╔════════════════════════════════════════════════════════════════╗' . PHP_EOL;
    echo '║  GEO — Probe Ceará Municípios Oficial (GEO.R1.4)              ║' . PHP_EOL;
    echo '╚════════════════════════════════════════════════════════════════╝' . PHP_EOL . PHP_EOL;

    echo '── Arquivo ──────────────────────────────────────────────────────' . PHP_EOL;
    echo '  ' . $chk($r['exists'])     . '  Arquivo existe (ceara_municipios_oficial.geojson)' . PHP_EOL;
    echo '  ' . $chk($r['valid_json']) . '  JSON válido FeatureCollection' . PHP_EOL;
    echo '  ' . $chk($r['size_ok'])    . '  Tamanho: ' . $r['file_size_kb'] . ' KB (limite 5.000 KB)' . PHP_EOL . PHP_EOL;

    if ($r['valid_json']) {
        echo '── Conteúdo ─────────────────────────────────────────────────────' . PHP_EOL;
        echo '  ' . $chk($r['count_ok'])    . '  Features: ' . $r['total_features'] . ' (esperado 184)' . PHP_EOL;
        echo '  ' . $chk($r['fields_ok'])   . '  12 campos obrigatórios presentes' . PHP_EOL;
        echo '  ' . $chk($r['no_dupes'])    . '  Sem códigos IBGE duplicados' . PHP_EOL;
        echo '  ' . $chk($r['geometry_ok']) . '  Geometrias Polygon/MultiPolygon válidas' . PHP_EOL;
        echo '  ' . $chk($r['encoding_ok']) . '  Encoding UTF-8 válido' . PHP_EOL;
        echo '  ' . $chk($r['metadata_ok']) . '  Metadados de fonte presentes' . PHP_EOL . PHP_EOL;
    }

    if (!empty($r['issues'])) {
        echo '── Issues ───────────────────────────────────────────────────────' . PHP_EOL;
        foreach ($r['issues'] as $i) echo '  [!] ' . $i . PHP_EOL;
        echo PHP_EOL;
    }
    if (!empty($r['warnings'])) {
        echo '── Avisos ───────────────────────────────────────────────────────' . PHP_EOL;
        foreach ($r['warnings'] as $w) echo '  [WARN] ' . $w . PHP_EOL;
        echo PHP_EOL;
    }

    echo '── Resultado ────────────────────────────────────────────────────' . PHP_EOL;
    echo ($r['ready_to_deploy'] ? '  [PASS] PRONTO PARA DEPLOY' : '  [FAIL] NÃO PRONTO') . PHP_EOL . PHP_EOL;
}
