<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);
$paths = ['app', 'api', 'cron', 'tests', 'bin'];
$failures = [];
$checked = 0;

foreach ($paths as $path) {
    $absolute = $root . DIRECTORY_SEPARATOR . $path;
    if (!is_dir($absolute)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (!$item->isFile() || strtolower($item->getExtension()) !== 'php') {
            continue;
        }

        $checked++;
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($item->getPathname());
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $failures[] = [
                'file' => str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1)),
                'output' => implode(PHP_EOL, $output),
            ];
        }
    }
}

$result = [
    'ok' => $failures === [],
    'checked_files' => $checked,
    'failed_files' => count($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
