<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\CaBundleResolver;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['json', 'base-url:', 'admin-token:']);
$root = dirname(__DIR__, 2);
$config = new Config();
$checks = [];

foreach (['db', 'flight_plan_db'] as $connection) {
    if (!Database::isConfigured($config, $connection)) {
        continue;
    }
    try {
        $pdo = Database::connect($config, $connection);
        $ssl = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $cipher = trim((string) ($ssl['Value'] ?? ''));
        $required = (bool) $config->get($connection . '.ssl_enabled', false);
        $checks[] = [
            'id' => $connection . '_tls',
            'ok' => !$required || $cipher !== '',
            'required' => $required,
            'cipher' => $cipher,
            'persistent' => (bool) $config->get($connection . '.persistent', false),
        ];
    } catch (Throwable $exception) {
        $checks[] = ['id' => $connection . '_tls', 'ok' => false, 'error' => $exception->getMessage()];
    }
}

$insecure = [];
foreach (['app', 'api', 'bin', 'tools/dev'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) @file_get_contents($file->getPathname());
        if (preg_match('/CURLOPT_SSL_VERIFYPEER\s*(?:=>|,)\s*false|CURLOPT_SSL_VERIFYHOST\s*(?:=>|,)\s*false/', $contents)) {
            $insecure[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}
$checks[] = [
    'id' => 'no_disabled_tls_verification',
    'ok' => $insecure === [],
    'files' => array_values(array_unique($insecure)),
];

$opcacheStatus = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$checks[] = [
    'id' => 'opcache_cli_observation',
    'ok' => extension_loaded('Zend OPcache'),
    'blocking' => false,
    'sapi' => PHP_SAPI,
    'enabled' => (bool) ini_get('opcache.enable'),
    'enabled_cli' => (bool) ini_get('opcache.enable_cli'),
    'scripts_cached' => is_array($opcacheStatus) ? (int) ($opcacheStatus['opcache_statistics']['num_cached_scripts'] ?? 0) : null,
    'note' => 'O estado do PHP web deve ser confirmado no health check autenticado.',
];

$caCandidates = array_values(array_filter(
    CaBundleResolver::candidates(),
    static fn (string $path): bool => is_file($path) && is_readable($path)
));
$checks[] = [
    'id' => 'cli_ca_bundle',
    'ok' => $caCandidates !== [],
    'blocking' => false,
    'candidates' => $caCandidates,
    'note' => $caCandidates === [] ? 'Configurar curl.cainfo/openssl.cafile no PHP CLI para chamadas externas.' : null,
];

$baseUrl = rtrim((string) ($opts['base-url'] ?? ''), '/');
if ($baseUrl !== '') {
    $adminToken = trim((string) ($opts['admin-token'] ?? getenv('BIOMIE_ADMIN_TOKEN') ?: ''));
    $headers = ['Connection: close'];
    if ($adminToken !== '') {
        $headers[] = 'X-BIomie-Token: ' . $adminToken;
    }
    $raw = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($baseUrl . '/runtimez.php');
        if ($curl !== false) {
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROXY => '',
            ]);
            $raw = curl_exec($curl);
            unset($curl);
        }
    } else {
        $context = stream_context_create(['http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'protocol_version' => 1.0,
            'header' => implode("\r\n", $headers),
        ]]);
        $raw = @file_get_contents($baseUrl . '/runtimez.php', false, $context);
    }
    $health = is_string($raw) ? json_decode($raw, true) : null;
    $webOpcache = is_array($health) && is_array($health['runtime']['opcache'] ?? null)
        ? $health['runtime']['opcache']
        : [];
    $checks[] = [
        'id' => 'opcache_web',
        'ok' => (bool) ($webOpcache['enabled'] ?? false),
        'blocking' => true,
        'sapi' => $health['runtime']['sapi'] ?? null,
        'validate_timestamps' => $webOpcache['validate_timestamps'] ?? null,
        'scripts_cached' => $webOpcache['scripts_cached'] ?? null,
        'hit_rate_pct' => $webOpcache['hit_rate_pct'] ?? null,
        'authenticated' => is_array($health) && ($health['ok'] ?? false) === true,
    ];
}

$blockingFailures = array_values(array_filter(
    $checks,
    static fn (array $check): bool => !($check['ok'] ?? false) && ($check['blocking'] ?? true),
));
$payload = [
    'ok' => $blockingFailures === [],
    'generated_at' => date(DATE_ATOM),
    'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'openssl' => OPENSSL_VERSION_TEXT],
    'checks' => $checks,
    'blocking_failures' => $blockingFailures,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($payload['ok'] ? 0 : 1);
