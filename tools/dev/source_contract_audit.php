<?php

declare(strict_types=1);

/**
 * ReadModel Source Governance audit.
 *
 * Read-only scanner: reports API files that still query business tables directly
 * instead of going through official read models. Existing legacy files are
 * reported as warnings by default; use --strict to turn findings into a
 * non-zero exit code suitable for a future release guard.
 *
 * Usage:
 *   php tools/dev/source_contract_audit.php
 *   php tools/dev/source_contract_audit.php --json
 *   php tools/dev/source_contract_audit.php --strict --json
 */

$root = dirname(__DIR__, 2);
require $root . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $root . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$argv = $GLOBALS['argv'] ?? [];
$args = array_slice($argv, 1);
$json = in_array('--json', $args, true) || in_array('--format=json', $args, true);
$strict = in_array('--strict', $args, true);

$blockedTables = [
    'biomie_notas_fiscais_canonicas',
    'biomie_notas_fiscais_itens_canonicos',
    'biomie_clientes_dim',
    'biomie_clientes_geo_dim',
    'mart_compradores_por_geo',
    'mart_vendas_por_cnae_cnpj',
    'mart_cliente_periodo',
    'mart_produto_periodo',
    'mart_vendedor_dia',
    'mart_fiscal_nfe',
    'mart_financeiro_titulo',
];

$exemptPatterns = [
    '#api[\\\\/]bootstrap(?:_finance)?\.php$#',
    '#api[\\\\/](?:health|health_public|status|system_health|commercial_health)\.php$#',
    '#api[\\\\/].*(?:diagnostic|diagnostics|audit|trace|coverage|status)\.php$#',
    '#api[\\\\/]admin_.*\.php$#',
    '#api[\\\\/]geo_cnae_materialize\.php$#',
    '#api[\\\\/]geo_materialize\.php$#',
    '#api[\\\\/]materialize_clients\.php$#',
    '#api[\\\\/](?:canonical_sync|finance_sync|omie_.*|sync|refresh_status)\.php$#',
];

$apiDir = $root . '/api';
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $files[] = $file->getPathname();
}
sort($files);

$findings = [];
$exemptCount = 0;
foreach ($files as $path) {
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $isExempt = false;
    foreach ($exemptPatterns as $pattern) {
        if (preg_match($pattern, $relative) === 1) {
            $isExempt = true;
            break;
        }
    }

    if ($isExempt) {
        $exemptCount++;
        continue;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }

    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*')) {
            continue;
        }

        foreach ($blockedTables as $table) {
            if (preg_match('/\b(?:FROM|JOIN|UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+`?' . preg_quote($table, '/') . '`?/i', $line) !== 1) {
                continue;
            }
            $findings[] = [
                'file' => $relative,
                'line' => $i + 1,
                'table' => $table,
                'severity' => 'warning',
                'message' => 'API reads a governed business table directly; migrate behind a ReadModel adapter.',
            ];
        }
    }
}

$byTable = [];
foreach ($findings as $finding) {
    $table = (string) $finding['table'];
    $byTable[$table] = ($byTable[$table] ?? 0) + 1;
}
ksort($byTable);

$payload = [
    'ok' => !$strict || $findings === [],
    'status' => $findings === [] ? 'clean' : ($strict ? 'failed' : 'legacy_warnings'),
    'strict' => $strict,
    'files_scanned' => count($files),
    'files_exempted' => $exemptCount,
    'blocked_tables' => $blockedTables,
    'findings_count' => count($findings),
    'findings_by_table' => $byTable,
    'findings' => $findings,
    'recommended_next_step' => $findings === []
        ? 'Enable this audit as a release guard.'
        : 'Migrate the highest-fanout endpoints to domain ReadModels before enabling --strict.',
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($payload['ok'] ? 0 : 3);
}

echo "ReadModel Source Governance Audit\n";
echo "Status: {$payload['status']}\n";
echo "Files scanned: {$payload['files_scanned']} | exempted: {$payload['files_exempted']}\n";
echo "Findings: {$payload['findings_count']}\n\n";

if ($byTable !== []) {
    echo "Findings by table:\n";
    foreach ($byTable as $table => $count) {
        echo "  - {$table}: {$count}\n";
    }
    echo "\n";
}

$sample = array_slice($findings, 0, 25);
if ($sample !== []) {
    echo "Sample:\n";
    foreach ($sample as $finding) {
        echo "  - {$finding['file']}:{$finding['line']} {$finding['table']}\n";
    }
    if (count($findings) > count($sample)) {
        echo "  ... +" . (count($findings) - count($sample)) . " findings\n";
    }
    echo "\n";
}

echo $payload['recommended_next_step'] . "\n";
exit($payload['ok'] ? 0 : 3);
