<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts = getopt('', ['output:', 'allowlist:', 'write-allowlist', 'enforce']);
$root = dirname(__DIR__, 2);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets/js'));
foreach ($iterator as $entry) {
    if ($entry->isFile() && $entry->getExtension() === 'js' && !str_ends_with($entry->getFilename(), '.min.js')) {
        $files[] = $entry->getPathname();
    }
}
sort($files);

$occurrences = [];
foreach ($files as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
        if (!preg_match('/(?:\\.innerHTML\\s*(?:\\+?=)|insertAdjacentHTML\\s*\\()/', $line)) {
            continue;
        }
        $classification = preg_match('/innerHTML\\s*=\\s*[\'"][^$\'"]*[\'"]\\s*;?\\s*$/', trim($line))
            ? 'static_template'
            : (preg_match('/escape|sanitize|safeHtml|DOMPurify|esc\\(/i', $line) ? 'escaped_or_sanitized' : 'dynamic_review_required');
        $occurrences[] = [
            'file' => str_replace('\\', '/', substr($file, strlen($root) + 1)),
            'line' => $index + 1,
            'classification' => $classification,
            'source' => trim($line),
        ];
    }
}

$selectStar = [];
foreach (glob($root . '/api/*.php') ?: [] as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
        if (preg_match('/SELECT\\s+(?:[a-z_]+\\.)?\\*/i', $line)) {
            $selectStar[] = [
                'file' => 'api/' . basename($file),
                'line' => $index + 1,
                'source' => trim($line),
            ];
        }
    }
}

$payload = [
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'inner_html' => [
        'total' => count($occurrences),
        'dynamic_review_required' => count(array_filter(
            $occurrences,
            static fn (array $row): bool => $row['classification'] === 'dynamic_review_required'
        )),
        'occurrences' => $occurrences,
    ],
    'api_select_star' => [
        'total' => count($selectStar),
        'occurrences' => $selectStar,
    ],
];

$allowlistPath = isset($opts['allowlist'])
    ? (string) $opts['allowlist']
    : $root . '/config/security-html-allowlist.json';
$currentKeys = [];
foreach ($occurrences as $row) {
    if ($row['classification'] === 'dynamic_review_required') {
        $currentKeys[] = hash('sha256', $row['file'] . '|' . $row['source']);
    }
}
foreach ($selectStar as $row) {
    $currentKeys[] = hash('sha256', $row['file'] . '|SELECT_STAR|' . $row['source']);
}
$currentKeys = array_values(array_unique($currentKeys));
sort($currentKeys);

if (isset($opts['write-allowlist'])) {
    $allowlistPayload = [
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'purpose' => 'Ratchet only: every entry still requires manual security review.',
        'entries' => $currentKeys,
    ];
    $allowlistJson = json_encode($allowlistPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($allowlistJson === false || file_put_contents($allowlistPath, $allowlistJson . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write security allowlist.');
    }
}

$allowedPayload = is_file($allowlistPath)
    ? json_decode((string) file_get_contents($allowlistPath), true)
    : [];
$allowed = is_array($allowedPayload['entries'] ?? null) ? $allowedPayload['entries'] : [];
$newKeys = array_values(array_diff($currentKeys, $allowed));
$payload['ratchet'] = [
    'allowlist' => str_replace('\\', '/', substr($allowlistPath, strlen($root) + 1)),
    'current_entries' => count($currentKeys),
    'new_unapproved_entries' => count($newKeys),
    'ok' => $newKeys === [],
];

$output = isset($opts['output']) ? (string) $opts['output'] : '';
if ($output !== '') {
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($output, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write frontend security inventory.');
    }
}
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(isset($opts['enforce']) && $newKeys !== [] ? 1 : 0);
