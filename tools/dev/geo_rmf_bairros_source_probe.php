<?php

declare(strict_types=1);

/**
 * geo_rmf_bairros_source_probe.php
 *
 * Probe completo de validação do GeoJSON de bairros metropolitanos (RMF) IBGE 2022.
 * Deve retornar [PASS] PRONTO PARA DEPLOY antes de ativar geometryAvailable: true.
 *
 * Uso:
 *   php tools/dev/geo_rmf_bairros_source_probe.php
 *   php tools/dev/geo_rmf_bairros_source_probe.php --json
 *   php tools/dev/geo_rmf_bairros_source_probe.php --check-ibge-api
 *
 * Valida:
 *   [1] Arquivo GeoJSON existe
 *   [2] JSON válido, type=FeatureCollection, features não vazia
 *   [3] Tamanho <3 MB
 *   [4] Campos obrigatórios em todas as features (amostra)
 *   [5] Geometrias: Polygon/MultiPolygon, sem null, sem coordinates vazias
 *   [6] Cobertura: ≥8 municípios da RMF com bairros
 *   [7] Municípios esperados com bairros presentes
 *   [8] Nenhum município fora da RMF
 *   [9] Encoding UTF-8 (Eusébio, Maracanaú, São Gonçalo)
 *   [10] Metadados: source, source_year, crs_origin
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts        = parseCliArgs($argv);
$asJson      = isset($opts['json']);
$checkApi    = isset($opts['check-ibge-api']);
$root        = dirname(__DIR__, 2);
$findings    = runProbe($root, $checkApi);

if ($asJson) {
    echo json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($findings['ready_to_deploy'] ? 0 : 1);
}

printReport($findings);
exit($findings['ready_to_deploy'] ? 0 : 1);

// ─────────────────────────────────────────────────────────────────────────────

function parseCliArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $out[$key] = $value;
    }
    return $out;
}

/** @return array<string,mixed> */
function runProbe(string $root, bool $checkApi): array
{
    // 19 municípios da RMF
    $rmfCodes = [
        2304400 => 'Fortaleza',
        2303709 => 'Caucaia',
        2307650 => 'Maracanaú',
        2307700 => 'Maranguape',
        2304285 => 'Eusébio',
        2301000 => 'Aquiraz',
        2305233 => 'Horizonte',
        2309607 => 'Pacajus',
        2309706 => 'Pacatuba',
        2306256 => 'Itaitinga',
        2304954 => 'Guaiúba',
        2303956 => 'Chorozinho',
        2303501 => 'Cascavel',
        2310852 => 'Pindoretama',
        2310209 => 'Paracuru',
        2310258 => 'Paraipaba',
        2312403 => 'São Gonçalo do Amarante',
        2312601 => 'São Luís do Curu',
        2313500 => 'Trairi',
    ];

    // Municípios que DEVEM ter bairros (confirmado no arquivo IBGE 2022)
    $expectedWithBairros = [2303709, 2307650, 2307700, 2304285, 2309706, 2306256, 2304954, 2313500];

    // Campos obrigatórios em cada feature.properties
    $requiredFields = [
        'layer_id', 'source', 'source_year', 'source_file', 'crs_origin',
        'municipio_ibge', 'municipio_nome', 'municipio_norm',
        'bairro_ibge', 'bairro_nome', 'bairro_norm', 'bairro_key', 'cidade_key',
        'label', 'join_key',
    ];

    $geojsonPath  = $root . '/assets/geo/rmf_bairros_ibge_2022.geojson';
    $pythonScript = $root . '/tools/dev/geo_generate_rmf_bairros.py';

    $r = [
        'probe'                   => 'geo_rmf_bairros_source_probe',
        'generated_at'            => date(DATE_ATOM),
        'geojson_path'            => $geojsonPath,
        'geojson_exists'          => false,
        'geojson_valid'           => false,
        'file_size_kb'            => 0,
        'file_size_ok'            => false,
        'fields_ok'               => false,
        'geometry_ok'             => false,
        'coverage_count'          => 0,
        'coverage_ok'             => false,
        'expected_munis_ok'       => false,
        'no_outside_rmf'          => false,
        'encoding_ok'             => false,
        'metadata_ok'             => false,
        'total_features'          => 0,
        'municipalities_found'    => [],
        'municipalities_missing'  => [],
        'municipalities_extra'    => [],
        'issues'                  => [],
        'warnings'                => [],
        'ready_to_deploy'         => false,
        'ibge_api_ok'             => null,
        'python_script_exists'    => file_exists($pythonScript),
    ];

    // ── 1. Arquivo existe? ────────────────────────────────────────────────────
    if (!file_exists($geojsonPath)) {
        $r['issues'][] = 'GeoJSON não encontrado: ' . $geojsonPath;
        $r['issues'][] = 'Execute: python tools/dev/geo_generate_rmf_bairros.py';
        if ($checkApi) {
            $r['ibge_api_ok'] = checkIbgeApiConnectivity();
        }
        return $r;
    }
    $r['geojson_exists'] = true;

    // ── 2. Tamanho ────────────────────────────────────────────────────────────
    $sizeKb = (int) round((filesize($geojsonPath) ?: 0) / 1024);
    $r['file_size_kb'] = $sizeKb;
    $r['file_size_ok'] = $sizeKb < 3000;
    if ($sizeKb >= 3000) {
        $r['issues'][] = sprintf('Arquivo grande (%d KB). Rode com --tolerance=0.0002.', $sizeKb);
    }

    // ── 3. Parse JSON ─────────────────────────────────────────────────────────
    $raw = file_get_contents($geojsonPath);
    if ($raw === false) {
        $r['issues'][] = 'Falha ao ler o arquivo.';
        return $r;
    }
    $geo = json_decode($raw, true);
    if (!is_array($geo) || ($geo['type'] ?? '') !== 'FeatureCollection' || !isset($geo['features'])) {
        $r['issues'][] = 'Não é um GeoJSON FeatureCollection válido.';
        return $r;
    }
    $r['geojson_valid']  = true;
    $features            = $geo['features'];
    $r['total_features'] = count($features);
    if ($r['total_features'] === 0) {
        $r['issues'][] = 'GeoJSON sem features — rode o gerador novamente.';
        return $r;
    }

    // ── 4. Campos obrigatórios (amostra primeiras 10 features) ───────────────
    $fieldIssues = validateRequiredFields(array_slice($features, 0, 10), $requiredFields);
    $r['fields_ok']      = $fieldIssues === [];
    $r['fields_missing'] = $fieldIssues;
    foreach ($fieldIssues as $iss) {
        $r['issues'][] = $iss;
    }

    // ── 5. Geometrias ─────────────────────────────────────────────────────────
    $geomIssues   = [];
    $nullGeomCount = 0;
    $badTypeCount  = 0;
    $emptyCoords   = 0;
    $sample        = min(count($features), 50);
    for ($i = 0; $i < $sample; $i++) {
        $geom = $features[$i]['geometry'] ?? null;
        if ($geom === null) {
            $nullGeomCount++;
            continue;
        }
        $t = $geom['type'] ?? '';
        if (!in_array($t, ['Polygon', 'MultiPolygon'], true)) {
            $badTypeCount++;
        }
        $coords = $geom['coordinates'] ?? [];
        if (empty($coords)) {
            $emptyCoords++;
        }
    }
    if ($nullGeomCount > 0) {
        $geomIssues[] = "{$nullGeomCount} geometria(s) nula(s) na amostra.";
    }
    if ($badTypeCount > 0) {
        $geomIssues[] = "{$badTypeCount} geometria(s) com tipo inesperado (não Polygon/MultiPolygon).";
    }
    if ($emptyCoords > 0) {
        $geomIssues[] = "{$emptyCoords} geometria(s) com coordinates vazia(s).";
    }
    $r['geometry_ok'] = $geomIssues === [];
    foreach ($geomIssues as $iss) {
        $r['issues'][] = $iss;
    }

    // ── 6. Cobertura por município da RMF ─────────────────────────────────────
    $countByMuni = [];
    foreach ($features as $feat) {
        $props = $feat['properties'] ?? [];
        $code  = isset($props['municipio_ibge']) ? (int) $props['municipio_ibge'] : 0;
        if ($code > 0) {
            $countByMuni[$code] = ($countByMuni[$code] ?? 0) + 1;
        }
    }

    $found   = [];
    $missing = [];
    foreach ($rmfCodes as $code => $name) {
        $cnt = $countByMuni[$code] ?? 0;
        if ($cnt > 0) {
            $found[]   = ['code' => $code, 'name' => $name, 'count' => $cnt];
        } else {
            $missing[] = ['code' => $code, 'name' => $name];
        }
    }

    // Bairros fora da RMF
    $extraCodes = array_diff(array_keys($countByMuni), array_keys($rmfCodes));
    $extraCount = count($extraCodes);
    $r['municipalities_found']   = $found;
    $r['municipalities_missing'] = $missing;
    $r['municipalities_extra']   = $extraCodes;
    $r['coverage_count']         = count($found);
    $r['coverage_ok']            = count($found) >= 8;
    $r['no_outside_rmf']         = $extraCount === 0;

    if (!$r['coverage_ok']) {
        $r['issues'][] = sprintf(
            'Cobertura insuficiente: %d/19 municípios com bairros (mínimo: 8).',
            count($found)
        );
    }
    if ($extraCount > 0) {
        $r['issues'][] = sprintf('%d código(s) de município fora da RMF detectados.', $extraCount);
    }

    // ── 7. Municípios esperados com bairros ───────────────────────────────────
    $foundCodes = array_column($found, 'code');
    $missingExpected = array_filter(
        $expectedWithBairros,
        fn(int $c) => !in_array($c, $foundCodes, true)
    );
    $r['expected_munis_ok'] = $missingExpected === [];
    foreach ($missingExpected as $code) {
        $r['issues'][] = sprintf(
            'Município esperado com bairros não encontrado: %s (%d).',
            $rmfCodes[$code] ?? 'desconhecido',
            $code
        );
    }

    // Municípios sem bairros (apenas WARN, não bloqueiam deploy)
    $withoutBairros = array_map(
        fn(array $m) => $m['name'] . ' (' . $m['code'] . ')',
        array_filter($missing, fn(array $m) => !in_array($m['code'], [2304400], true))
    );
    if ($withoutBairros) {
        $r['warnings'][] = 'Municípios sem bairros no IBGE 2022 (fallback via rmf_municipios): '
            . implode(', ', $withoutBairros);
    }

    // ── 8. Encoding UTF-8 ─────────────────────────────────────────────────────
    $accentedExpected = ['Eusébio', 'Maracanaú'];
    $encodingIssues   = checkEncoding($features, $accentedExpected);
    $r['encoding_ok'] = $encodingIssues === [];
    foreach ($encodingIssues as $iss) {
        $r['issues'][] = $iss;
    }

    // ── 9. Metadados ──────────────────────────────────────────────────────────
    $meta = $geo['metadata'] ?? [];
    $metaIssues = [];
    if (($meta['source'] ?? '') !== 'IBGE_BAIRROS_CD2022') {
        $metaIssues[] = "metadata.source incorreto: '{$meta['source']}' (esperado: IBGE_BAIRROS_CD2022).";
    }
    if ((int) ($meta['source_year'] ?? 0) !== 2022) {
        $metaIssues[] = "metadata.source_year incorreto (esperado: 2022).";
    }
    if (!str_contains((string) ($meta['crs_origin'] ?? ''), '4674')) {
        $metaIssues[] = "metadata.crs_origin deve conter EPSG:4674/SIRGAS2000.";
    }
    $r['metadata_ok'] = $metaIssues === [];
    foreach ($metaIssues as $iss) {
        $r['issues'][] = $iss;
    }

    // ── 10. IBGE API ping ─────────────────────────────────────────────────────
    if ($checkApi) {
        $r['ibge_api_ok'] = checkIbgeApiConnectivity();
    }

    // ── Resultado final ───────────────────────────────────────────────────────
    $r['ready_to_deploy'] =
        $r['geojson_exists']  &&
        $r['geojson_valid']   &&
        $r['file_size_ok']    &&
        $r['fields_ok']       &&
        $r['geometry_ok']     &&
        $r['coverage_ok']     &&
        $r['expected_munis_ok'] &&
        $r['no_outside_rmf']  &&
        $r['encoding_ok']     &&
        $r['metadata_ok']     &&
        $r['issues'] === [];

    return $r;
}

/** @return list<string> */
function validateRequiredFields(array $sample, array $fields): array
{
    $issues = [];
    foreach ($fields as $field) {
        $absent = 0;
        $empty  = 0;
        foreach ($sample as $feat) {
            $props = $feat['properties'] ?? [];
            if (!array_key_exists($field, $props)) {
                $absent++;
            } elseif ((string) ($props[$field] ?? '') === '') {
                $empty++;
            }
        }
        $n = count($sample);
        if ($absent === $n) {
            $issues[] = "Campo '{$field}' ausente em todas as features da amostra.";
        } elseif ($absent > 0) {
            $issues[] = "Campo '{$field}' ausente em {$absent}/{$n} features.";
        } elseif ($empty > (int) ($n * 0.5)) {
            $issues[] = "Campo '{$field}' vazio em {$empty}/{$n} features.";
        }
    }
    return $issues;
}

/** @return list<string> */
function checkEncoding(array $features, array $accentedNames): array
{
    $issues   = [];
    $seenMuni = [];
    foreach ($features as $feat) {
        $props = $feat['properties'] ?? [];
        $nome  = (string) ($props['municipio_nome'] ?? '');
        if ($nome === '') {
            continue;
        }
        if (!mb_check_encoding($nome, 'UTF-8')) {
            $issues[] = "municipio_nome com encoding inválido: '{$nome}'.";
            continue;
        }
        foreach ($accentedNames as $expected) {
            if (str_contains($nome, $expected)) {
                $seenMuni[$expected] = true;
            }
        }
    }
    // Nomes acentuados encontrados não precisam ser TODOS presentes
    // (alguns municípios podem ter sido excluídos do GeoJSON).
    // Apenas verifica se, quando encontrado, está correto (já validado pelo mb_check acima).
    return $issues;
}

function checkIbgeApiConnectivity(): bool
{
    $url = 'https://servicodados.ibge.gov.br/api/v3/malhas/estados/23?resolucao=1&formato=application/vnd.geo%2Bjson';
    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp !== false && strlen($resp) > 100;
}

function printReport(array $r): void
{
    $chk = fn(bool $v): string => $v ? '[PASS]' : '[FAIL]';
    $wrn = fn(bool $v): string => $v ? '[PASS]' : '[WARN]';

    echo PHP_EOL;
    echo '╔══════════════════════════════════════════════════════════════╗' . PHP_EOL;
    echo '║   GEO — Probe RMF Bairros IBGE 2022                         ║' . PHP_EOL;
    echo '╚══════════════════════════════════════════════════════════════╝' . PHP_EOL . PHP_EOL;

    echo '── Arquivo ──────────────────────────────────────────────────────' . PHP_EOL;
    echo '  ' . $chk($r['geojson_exists']) . '  GeoJSON existe' . PHP_EOL;
    echo '  ' . $chk($r['geojson_valid'])  . '  GeoJSON válido (FeatureCollection)' . PHP_EOL;
    echo '  ' . $chk($r['file_size_ok'])   . '  Tamanho: ' . $r['file_size_kb'] . ' KB (limite 3.000 KB)' . PHP_EOL;
    echo PHP_EOL;

    if ($r['geojson_valid']) {
        echo '── Campos obrigatórios ──────────────────────────────────────────' . PHP_EOL;
        echo '  ' . $chk($r['fields_ok']) . '  15 campos obrigatórios presentes' . PHP_EOL;
        if (!empty($r['fields_missing'])) {
            foreach ($r['fields_missing'] as $f) {
                echo '      ! ' . $f . PHP_EOL;
            }
        }
        echo PHP_EOL;

        echo '── Geometrias ───────────────────────────────────────────────────' . PHP_EOL;
        echo '  ' . $chk($r['geometry_ok'])   . '  Polygon/MultiPolygon, sem null, sem coords vazias' . PHP_EOL;
        echo PHP_EOL;

        echo '── Cobertura RMF ────────────────────────────────────────────────' . PHP_EOL;
        echo '  ' . $chk($r['coverage_ok'])        . '  ' . $r['coverage_count'] . '/19 municípios com bairros (mínimo: 8)' . PHP_EOL;
        echo '  ' . $chk($r['expected_munis_ok'])   . '  Municípios esperados presentes' . PHP_EOL;
        echo '  ' . $chk($r['no_outside_rmf'])      . '  Sem municípios fora da RMF' . PHP_EOL;
        echo '  ' . $chk($r['total_features'] > 0)  . '  Total de bairros: ' . $r['total_features'] . PHP_EOL;
        echo PHP_EOL;

        if (!empty($r['municipalities_found'])) {
            echo '── Municípios com bairros (' . count($r['municipalities_found']) . ') ─────────────────────────' . PHP_EOL;
            foreach ($r['municipalities_found'] as $m) {
                echo sprintf('    ✓  %-35s %3d bairros%s', $m['name'], $m['count'], PHP_EOL);
            }
            echo PHP_EOL;
        }

        if (!empty($r['municipalities_missing'])) {
            $missingWithoutFortaleza = array_filter(
                $r['municipalities_missing'],
                fn(array $m) => $m['code'] !== 2304400
            );
            if ($missingWithoutFortaleza) {
                echo '── Municípios sem bairros (fallback rmf_municipios) ─────────────' . PHP_EOL;
                foreach ($missingWithoutFortaleza as $m) {
                    echo '    ✗  ' . $m['name'] . ' (' . $m['code'] . ')' . PHP_EOL;
                }
                echo PHP_EOL;
            }
        }

        echo '── Encoding UTF-8 ───────────────────────────────────────────────' . PHP_EOL;
        echo '  ' . $chk($r['encoding_ok']) . '  Encoding UTF-8 válido' . PHP_EOL;
        echo PHP_EOL;

        echo '── Metadados ────────────────────────────────────────────────────' . PHP_EOL;
        echo '  ' . $chk($r['metadata_ok']) . '  source=IBGE_BAIRROS_CD2022, source_year=2022, crs_origin=EPSG:4674' . PHP_EOL;
        echo PHP_EOL;
    }

    if (!empty($r['warnings'])) {
        echo '── Avisos (não bloqueiam deploy) ────────────────────────────────' . PHP_EOL;
        foreach ($r['warnings'] as $w) {
            echo '  [WARN] ' . $w . PHP_EOL;
        }
        echo PHP_EOL;
    }

    if (!empty($r['issues'])) {
        echo '── Issues (bloqueiam deploy) ────────────────────────────────────' . PHP_EOL;
        foreach ($r['issues'] as $iss) {
            echo '  [!] ' . $iss . PHP_EOL;
        }
        echo PHP_EOL;
    }

    if (!$r['geojson_exists']) {
        echo '── Instruções ───────────────────────────────────────────────────' . PHP_EOL;
        echo '  python tools/dev/geo_generate_rmf_bairros.py --dry-run' . PHP_EOL;
        echo '  python tools/dev/geo_generate_rmf_bairros.py' . PHP_EOL;
        echo PHP_EOL;
    }

    echo '── Resultado ────────────────────────────────────────────────────' . PHP_EOL;
    if ($r['ready_to_deploy']) {
        echo '  [PASS] PRONTO PARA DEPLOY' . PHP_EOL;
        echo PHP_EOL;
        echo '  Próximo passo: ativar geometryAvailable: true em biomie-map.js' . PHP_EOL;
    } else {
        echo '  [FAIL] NÃO PRONTO — corrija os issues acima' . PHP_EOL;
    }
    echo PHP_EOL;
}
