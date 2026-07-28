<?php

declare(strict_types=1);

/**
 * bin/geo_r21_run.php
 *
 * GEO.R2.1 — Pipeline orquestrador de enriquecimento geográfico
 *
 * Encadeia todas as etapas do GEO.R2.1 em ordem:
 *   1. insert_skeletons — garante que todos os clientes com NF existam em geo_dim
 *   2. local_enrich     — preenche cidade/bairro a partir de biomie_clientes_dim (sem API)
 *   3. viacep           — enriquece CEPs não resolvidos via ViaCEP (opcional)
 *   4. omie_enrich      — ConsultarCliente Omie para todos os elegíveis (opcional)
 *   5. rebuild_mart     — re-materializa mart_compradores_por_geo + CNAE mart (opcional)
 *
 * Uso:
 *   php bin/geo_r21_run.php                       # modo padrão (steps 1-2 + rebuild)
 *   php bin/geo_r21_run.php --dry-run             # simula sem escrever nada
 *   php bin/geo_r21_run.php --with-viacep         # inclui step 3 (ViaCEP)
 *   php bin/geo_r21_run.php --with-omie           # inclui steps 3+4 (ViaCEP + Omie)
 *   php bin/geo_r21_run.php --skip-rebuild        # pula step 5 (não re-materializa)
 *   php bin/geo_r21_run.php --skip-omie           # alias para --with-viacep (sem Omie)
 *   php bin/geo_r21_run.php --only=omie           # executa apenas o step Omie
 */

$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts       = getopt('', ['dry-run', 'with-viacep', 'with-omie', 'skip-rebuild', 'skip-omie', 'only:']);
$dryRun     = isset($opts['dry-run']);
$withViaCep = isset($opts['with-viacep']) || isset($opts['with-omie']);
$withOmie   = isset($opts['with-omie']) && !isset($opts['skip-omie']);
$skipRebuild = isset($opts['skip-rebuild']);
$only       = $opts['only'] ?? null;

$started = microtime(true);
$stepResults = [];

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║   GEO.R2.1 — Pipeline de Enriquecimento Geográfico  ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";
echo "  Data/hora:  " . date('Y-m-d H:i:s') . "\n";
echo "  dry_run:    " . ($dryRun ? 'true' : 'false') . "\n";
echo "  ViaCEP:     " . ($withViaCep ? 'sim' : 'não') . "\n";
echo "  Omie API:   " . ($withOmie ? 'sim' : 'não') . "\n";
echo "  Rebuild:    " . ($skipRebuild ? 'não' : 'sim') . "\n";
if ($only) { echo "  Apenas:     $only\n"; }
echo "\n";

/**
 * Executa um script PHP como sub-processo, captura output e retorna código de saída.
 */
function runStep(string $label, string $scriptPath, array $args = [], bool $dryRun = false): array
{
    $dryFlag = $dryRun ? ' --dry-run' : '';
    $argsStr = $args ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '';
    $cmd = 'php ' . escapeshellarg($scriptPath) . $dryFlag . $argsStr . ' 2>&1';

    echo "┌─ $label\n";
    echo "│  cmd: php " . basename($scriptPath) . ($dryFlag ?: '') . ($argsStr ?: '') . "\n│\n";

    $t0 = microtime(true);
    $output = []; $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $elapsed = round((microtime(true) - $t0) * 1000);

    foreach ($output as $line) {
        echo "│  $line\n";
    }

    $status = $exitCode === 0 ? 'OK' : 'ERRO';
    echo "│\n└─ [$status] {$elapsed}ms\n\n";

    return ['step' => $label, 'exit_code' => $exitCode, 'elapsed_ms' => $elapsed, 'lines' => count($output)];
}

require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pdo = \BIomie\Database::connect(new \BIomie\Config(), 'db');

// ── Baseline ────────────────────────────────────────────────────────────────

if (!$only) {
    echo "━━━ BASELINE ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    $nfTotal = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
    $nfGeo   = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
    $nfPct   = $nfTotal > 0 ? round($nfGeo / $nfTotal * 100, 1) : 0;

    $chiodi  = (int)$pdo->query("SELECT COUNT(DISTINCT nf.codigo_cliente_omie) FROM biomie_notas_fiscais_canonicas nf LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie=nf.codigo_cliente_omie WHERE nf.status_fiscal='autorizada' AND d.codigo_cliente_omie IS NULL")->fetchColumn();

    $geoDim  = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
    $semCid  = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
    $semBai  = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE (cidade_norm IS NOT NULL AND TRIM(cidade_norm)!='') AND (bairro_norm IS NULL OR TRIM(bairro_norm)='')")->fetchColumn();

    printf("  NF geo coverage:    %d/%d (%.1f%%) — %d NFs fora do mapa\n", $nfGeo, $nfTotal, $nfPct, $nfTotal - $nfGeo);
    printf("  Caso CHIODI:        %d clientes em NF ausentes de clientes_dim\n", $chiodi);
    printf("  geo_dim:            %d total | %d sem cidade | %d com cidade mas sem bairro\n\n", $geoDim, $semCid, $semBai);
}

// ── Step 1: insert_skeletons ────────────────────────────────────────────────

if (!$only || $only === 'skeletons') {
    echo "━━━ STEP 1: INSERT SKELETONS ━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $stepResults[] = runStep(
        'Insert geo_dim skeletons para clientes em NF sem registro',
        $projectRoot . '/bin/geo_r21_insert_skeletons.php',
        [],
        $dryRun
    );
}

// ── Step 2: local_enrich ────────────────────────────────────────────────────

if (!$only || $only === 'local') {
    echo "━━━ STEP 2: LOCAL ENRICH (clientes_dim) ━━━━━━━━━━━━━━\n";
    $stepResults[] = runStep(
        'Enriquecimento local via biomie_clientes_dim (sem API)',
        $projectRoot . '/bin/geo_r21_local_enrich.php',
        [],
        $dryRun
    );
}

// ── Step 3: viacep ──────────────────────────────────────────────────────────

if ($withViaCep && (!$only || $only === 'viacep')) {
    echo "━━━ STEP 3: VIACEP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $stepResults[] = runStep(
        'Enriquecimento via ViaCEP (CEPs disponíveis em clientes_dim)',
        $projectRoot . '/bin/geo_r21_viacep.php',
        ['--limit=200'],
        $dryRun
    );
} elseif (!$withViaCep && !$only) {
    echo "  [SKIP] Step 3 (ViaCEP) — use --with-viacep para habilitar\n\n";
}

// ── Step 4: omie_enrich ─────────────────────────────────────────────────────

if ($withOmie && (!$only || $only === 'omie')) {
    echo "━━━ STEP 4: OMIE API ENRICH ━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  ⚠ Chamadas à API Omie — respeitando rate limit (200ms entre requisições)\n\n";
    $stepResults[] = runStep(
        'Enriquecimento via Omie ConsultarCliente (inclui clientes ausentes da dim)',
        $projectRoot . '/bin/geo_r21_enrich.php',
        ['--limit=30', '--batches=20'],
        $dryRun
    );
} elseif (!$withOmie && !$only) {
    echo "  [SKIP] Step 4 (Omie API) — use --with-omie para habilitar\n\n";
}

// ── Step 5: rebuild mart ────────────────────────────────────────────────────

if (!$skipRebuild && (!$only || $only === 'rebuild')) {
    echo "━━━ STEP 5: REBUILD MART ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    $materializeScript = $projectRoot . '/bin/materialize_marts.php';
    if (file_exists($materializeScript)) {
        if (!$dryRun) {
            $stepResults[] = runStep(
                'Re-materializar mart_compradores_por_geo + CNAE mart',
                $materializeScript,
                ['--target=geo', '--target=cnae'],
                false
            );
        } else {
            echo "  [dry-run] Skipping mart rebuild — execute sem --dry-run para re-materializar\n\n";
        }
    } else {
        echo "  [WARN] bin/materialize_marts.php não encontrado — re-materialize manualmente:\n";
        echo "         php artisan geo:materialize  ou  php bin/materialize_marts.php\n\n";
    }
} elseif ($skipRebuild && !$only) {
    echo "  [SKIP] Step 5 (rebuild mart) — use sem --skip-rebuild para habilitar\n\n";
}

// ── Resumo ───────────────────────────────────────────────────────────────────

if ($stepResults) {
    echo "━━━ RESUMO ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $errors = 0;
    foreach ($stepResults as $r) {
        $icon = $r['exit_code'] === 0 ? '✔' : '✘';
        printf("  %s  %-60s  %dms\n", $icon, $r['step'], $r['elapsed_ms']);
        if ($r['exit_code'] !== 0) { $errors++; }
    }
    echo "\n";
    if ($errors > 0) {
        echo "  ✘ $errors step(s) com erro — verifique os logs acima\n";
    } else {
        echo "  ✔ Pipeline concluído sem erros\n";
    }
    echo "\n";
}

// ── Métricas pós-pipeline ───────────────────────────────────────────────────

if (!$dryRun && !$only) {
    echo "━━━ PÓS-PIPELINE ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    $pdoAfter = \BIomie\Database::connect(new \BIomie\Config(), 'db');
    $nfGeoA  = (int)$pdoAfter->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
    $semCidA = (int)$pdoAfter->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
    $geoA    = (int)$pdoAfter->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();

    printf("  NF com geo:    %d/%d (%.1f%%)\n", $nfGeoA, $nfTotal ?? 0, ($nfTotal ?? 1) > 0 ? round($nfGeoA / ($nfTotal ?? 1) * 100, 1) : 0);
    printf("  geo_dim total: %d | sem cidade: %d\n", $geoA, $semCidA);

    echo "\n  Próximos passos:\n";
    if ($semCidA > 0) {
        echo "  1. Execute --with-omie para enriquecer via Omie API os clientes ainda sem cidade\n";
    }
    echo "  2. php tools/dev/geo_mapa100_probe.php — validar critérios de aceitação\n";
    echo "  3. Smoke visual no navegador (hard refresh, modo mapa_composto_ceara)\n";
}

$totalMs = round((microtime(true) - $started) * 1000);
echo "\n[GEO.R2.1 PIPELINE] Concluído em {$totalMs}ms — " . date('Y-m-d H:i:s') . "\n";
