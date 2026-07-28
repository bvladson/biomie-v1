<?php

/**
 * bin/cache_cleanup.php
 *
 * Remove arquivos de cache no formato antigo (sem prefixo c{N}) que ficaram
 * em disco após a migração das cache keys para incluir company_id.
 *
 * Padrões antigos removidos:
 *   api_overview_payload_<hash>.json          → substituído por api_overview_payload_c{N}_<hash>
 *   home_bootstrap_v2_<start>_<end>.json      → substituído por home_bootstrap_v2_c{N}_<start>_<end>
 *   finance_snapshot_<start>_<end>.json       → substituído por finance_snapshot_c{N}_<start>_<end>
 *   finance_snapshot_history.json             → substituído por finance_snapshot_history_c{N}
 *   snapshot_fiscal_v*_<start>_<end>.json     → substituído por snapshot_fiscal_c{N}_v*_<start>_<end>
 *   finance_chunk_<service>_<...>.json        → substituído por finance_chunk_c{N}_<service>_<...>
 *   finance_budget_<ano>_<mes>.json           → substituído por finance_budget_c{N}_<ano>_<mes>
 *
 * Uso:
 *   php bin/cache_cleanup.php [--dry-run] [--dir=/path/to/cache] [--verbose]
 *
 * Exit: 0 = sucesso, 1 = erro
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts    = getopt('', ['dry-run', 'dir:', 'verbose']);
$dryRun  = isset($opts['dry-run']);
$verbose = isset($opts['verbose']) || $dryRun;
$cacheDir = isset($opts['dir'])
    ? rtrim((string) $opts['dir'], '/\\')
    : dirname(__DIR__) . '/storage/cache';

if (!is_dir($cacheDir)) {
    fwrite(STDERR, "Diretório de cache não encontrado: {$cacheDir}" . PHP_EOL);
    exit(1);
}

/** @var list<string> */
$OLD_PATTERNS = [
    // Overview payload sem company prefix
    '/^api_overview_payload_(?!c\d+_)[0-9a-f]+\.json$/',
    // Home bootstrap sem company prefix
    '/^home_bootstrap_v2_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.json$/',
    // Finance snapshot sem company prefix
    '/^finance_snapshot_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.json$/',
    // Finance snapshot history global (sem company)
    '/^finance_snapshot_history\.json$/',
    // Sales snapshot sem company prefix
    '/^snapshot_fiscal_v\d+_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.json$/',
    // Finance chunk sem company prefix
    '/^finance_chunk_(?!c\d+_)[a-z_]+_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.json$/',
    // Finance budget sem company prefix
    '/^finance_budget_\d{4}_\d{2}\.json$/',
];

$scanned = 0;
$matched = 0;
$deleted = 0;
$errors  = 0;

$files = new DirectoryIterator($cacheDir);
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'json') {
        continue;
    }
    $scanned++;
    $name = $file->getFilename();

    $isOld = false;
    foreach ($OLD_PATTERNS as $pattern) {
        if (preg_match($pattern, $name) === 1) {
            $isOld = true;
            break;
        }
    }

    if (!$isOld) {
        continue;
    }

    $matched++;
    $path = $file->getPathname();

    if ($verbose) {
        echo ($dryRun ? '[DRY] ' : '') . "Removendo: {$name}" . PHP_EOL;
    }

    if (!$dryRun) {
        if (@unlink($path)) {
            $deleted++;
        } else {
            $errors++;
            fwrite(STDERR, "Falha ao remover: {$path}" . PHP_EOL);
        }
    } else {
        $deleted++;
    }
}

$label = $dryRun ? 'simulados' : 'removidos';
echo sprintf(
    "Cache cleanup: %d arquivos verificados, %d no formato antigo, %d %s, %d erros%s" . PHP_EOL,
    $scanned,
    $matched,
    $deleted,
    $label,
    $errors,
    $dryRun ? ' (dry-run — nenhum arquivo foi alterado)' : '',
);

exit($errors > 0 ? 1 : 0);
