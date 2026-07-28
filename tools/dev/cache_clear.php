<?php

declare(strict_types=1);

/**
 * CLI de limpeza de cache do BIomie.
 *
 * Uso:
 *   php tools/dev/cache_clear.php [--all] [--api] [--dashboard] [--wallpaper] [--assets_meta] [--opcache] [--dry-run] [--json]
 *
 * Flags:
 *   --all         Limpa api + dashboard + wallpaper + assets_meta
 *   --api         Limpa storage/cache/api/*.json
 *   --dashboard   Limpa storage/cache/dashboard/*.json e root *.json
 *   --wallpaper   Remove storage/cache/login_wallpaper.json
 *   --assets_meta Remove cache de build (config/build.php se gerado dinamicamente)
 *   --opcache     Executa opcache_reset() se disponível
 *   --dry-run     Lista o que seria removido sem remover nada
 *   --json        Saída em JSON estruturado
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$args = array_slice($argv ?? [], 1);
$all = in_array('--all', $args, true);
$dryRun = in_array('--dry-run', $args, true);
$jsonOutput = in_array('--json', $args, true);

$doApi = $all || in_array('--api', $args, true);
$doDashboard = $all || in_array('--dashboard', $args, true);
$doWallpaper = $all || in_array('--wallpaper', $args, true);
$doAssetsMeta = $all || in_array('--assets_meta', $args, true);
$doOpcache = in_array('--opcache', $args, true);

$root = \BIomie\Bootstrap::rootPath();
$targets = [];

if ($doApi) {
    $targets[] = ['type' => 'dir', 'path' => $root . '/storage/cache/api', 'label' => 'api'];
}
if ($doDashboard) {
    $targets[] = ['type' => 'dir', 'path' => $root . '/storage/cache/dashboard', 'label' => 'dashboard'];
    $targets[] = ['type' => 'dir_json_only', 'path' => $root . '/storage/cache', 'label' => 'cache_root'];
}
if ($doWallpaper) {
    $targets[] = ['type' => 'file', 'path' => $root . '/storage/cache/login_wallpaper.json', 'label' => 'wallpaper'];
}
if ($doAssetsMeta) {
    $assetsBuildPath = $root . '/storage/cache/assets_build.json';
    if (is_file($assetsBuildPath)) {
        $targets[] = ['type' => 'file', 'path' => $assetsBuildPath, 'label' => 'assets_meta'];
    }
}

$removed = [];
$errors = [];

foreach ($targets as $target) {
    if ($target['type'] === 'file') {
        if (is_file($target['path'])) {
            if ($dryRun) {
                $removed[] = str_replace($root . '/', '', $target['path']) . ' [dry-run]';
            } elseif (@unlink($target['path'])) {
                $removed[] = str_replace($root . '/', '', $target['path']);
            } else {
                $errors[] = 'Falha ao remover: ' . str_replace($root . '/', '', $target['path']);
            }
        }
        continue;
    }

    $glob = $target['type'] === 'dir_json_only'
        ? glob(rtrim($target['path'], '/\\') . '/*.json') ?: []
        : array_merge(
            glob(rtrim($target['path'], '/\\') . '/*.json') ?: [],
            glob(rtrim($target['path'], '/\\') . '/*.php') ?: []
        );

    foreach ($glob as $file) {
        $rel = str_replace($root . '/', '', $file);
        if ($dryRun) {
            $removed[] = $rel . ' [dry-run]';
        } elseif (@unlink($file)) {
            $removed[] = $rel;
        } else {
            $errors[] = 'Falha ao remover: ' . $rel;
        }
    }
}

$opcacheReset = false;
$opcacheAvailable = function_exists('opcache_reset');
if ($doOpcache) {
    $opcacheReset = $opcacheAvailable ? (bool) @opcache_reset() : false;
}

$result = [
    'ok' => $errors === [],
    'dry_run' => $dryRun,
    'removed_count' => count($removed),
    'removed' => $removed,
    'errors' => $errors,
    'opcache_reset' => $opcacheReset,
    'opcache_available' => $opcacheAvailable,
];

if ($jsonOutput) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    $prefix = $dryRun ? '[DRY-RUN] ' : '';
    foreach ($removed as $f) {
        echo $prefix . 'removido: ' . $f . PHP_EOL;
    }
    foreach ($errors as $e) {
        fwrite(STDERR, '[ERRO] ' . $e . PHP_EOL);
    }
    if ($doOpcache) {
        echo ($opcacheReset ? '[OK] OPcache resetado.' : ($opcacheAvailable ? '[ERRO] Falha ao resetar OPcache.' : '[INFO] OPcache indisponivel neste ambiente.')) . PHP_EOL;
    }
    echo ($dryRun ? '[DRY-RUN] ' : '') . count($removed) . ' arquivo(s) ' . ($dryRun ? 'seriam removidos' : 'removidos') . '.' . PHP_EOL;
}

exit($errors === [] ? 0 : 1);
