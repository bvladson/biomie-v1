<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__);
$failures = [];
$scanned = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/vendor/')
        || str_contains($path, '/storage/')
        || str_contains($path, '/tools/dev/')
        || str_contains($path, '/scratch/')
        || str_contains($path, '/database/migrations/Version20260619000000_')
    ) {
        continue;
    }
    $content = file_get_contents($file->getPathname());
    if (!is_string($content)) {
        continue;
    }
    $scanned++;

    if (preg_match('/new\s+OmieClient\s*\(/', $content) === 1
        && !str_ends_with($path, '/app/Omie/OmieClientFactory.php')
    ) {
        $failures[] = ['file' => $path, 'rule' => 'direct_omie_client'];
    }

    if (preg_match('/get\([\'"]omie\.(app_key|app_secret)[\'"]/', $content) === 1
        && !str_ends_with($path, '/app/Config.php')
        && !str_ends_with($path, '/config/env.php')
    ) {
        $failures[] = ['file' => $path, 'rule' => 'global_omie_credential'];
    }
}

$foundation = file_get_contents(
    $root . '/database/migrations/Version20260619000000_MultitenantFoundation.php'
);
if (is_string($foundation)
    && preg_match('/[\'"][a-f0-9]{32}[\'"]/i', $foundation) === 1
) {
    $failures[] = [
        'file' => 'database/migrations/Version20260619000000_MultitenantFoundation.php',
        'rule' => 'historical_plaintext_secret',
    ];
}

$ok = $failures === [];
echo json_encode([
    'ok' => $ok,
    'files_scanned' => $scanned,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($ok ? 0 : 1);
