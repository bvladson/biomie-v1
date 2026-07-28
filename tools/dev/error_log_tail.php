<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap.php';

use BIomie\Bootstrap;

Bootstrap::init();

$limit = 20;
$json = false;
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(500, (int) substr($arg, 8)));
    }
    if ($arg === '--json') {
        $json = true;
    }
}

$path = Bootstrap::rootPath('storage/logs/biomie_errors.jsonl');
$rows = [];
if (is_file($path)) {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice($lines, -$limit) as $line) {
        $decoded = json_decode($line, true);
        $rows[] = is_array($decoded) ? $decoded : ['raw' => $line];
    }
}

if ($json) {
    echo json_encode(['ok' => true, 'log' => $path, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

foreach ($rows as $row) {
    printf(
        "[%s] %s %s %s:%s %s\n",
        (string) ($row['ts'] ?? '-'),
        (string) ($row['request_id'] ?? '-'),
        (string) ($row['route'] ?? '-'),
        basename((string) ($row['file'] ?? '-')),
        (string) ($row['line'] ?? '-'),
        (string) ($row['message'] ?? '')
    );
}
