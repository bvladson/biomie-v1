<?php

declare(strict_types=1);

/**
 * Generates reviewable, secret-free snapshots of the live release gates.
 *
 * This command is read-only. It never migrates, repairs checksums or deletes data.
 */

$root = dirname(__DIR__, 2);
$outputDir = $root . '/docs/generated';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException('Unable to create docs/generated.');
}

/**
 * @param list<string> $arguments
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function runDiagnostic(string $root, string $program, array $arguments): array
{
    $command = array_merge([$program], $arguments);
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start diagnostic command.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

/** @param array<string,mixed> $payload */
function writeDiagnostic(string $path, array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write ' . $path);
    }
}

$generatedAt = gmdate('Y-m-d\TH:i:s\Z');
$php = PHP_BINARY;

$migrationRun = runDiagnostic($root, $php, ['bin/schema_migrate.php', 'status', '--format=json']);
$migrationPayload = json_decode($migrationRun['stdout'], true);
if (!is_array($migrationPayload)) {
    throw new RuntimeException('Migration status did not return valid JSON: ' . trim($migrationRun['stderr']));
}

// The public artifact must not retain hosts, database names, users or absolute paths.
$migrationResult = $migrationPayload['result'] ?? [];
if (is_array($migrationResult)) {
    unset($migrationResult['diagnostics']['connections']);
    unset($migrationResult['diagnostics']['db_name']);
    unset($migrationResult['diagnostics']['migrations_path']);
    foreach ($migrationResult['migrations'] ?? [] as &$migration) {
        if (is_array($migration)) {
            unset($migration['file']);
        }
    }
    unset($migration);
}
writeDiagnostic($outputDir . '/migration-status.json', [
    'generated_at_utc' => $generatedAt,
    'command' => 'php bin/schema_migrate.php status --format=json',
    'exit_code' => $migrationRun['exit_code'],
    'result' => $migrationResult,
]);

$phpstanRun = runDiagnostic($root, $php, [
    'vendor/phpstan/phpstan/phpstan',
    'analyse',
    '-c',
    'phpstan.core.neon',
    '--memory-limit=1G',
    '--no-progress',
    '--error-format=json',
]);
$phpstanPayload = json_decode($phpstanRun['stdout'], true);
writeDiagnostic($outputDir . '/phpstan-status.json', [
    'generated_at_utc' => $generatedAt,
    'command' => 'php vendor/phpstan/phpstan/phpstan analyse -c phpstan.core.neon --error-format=json',
    'exit_code' => $phpstanRun['exit_code'],
    'result' => is_array($phpstanPayload) ? $phpstanPayload : [
        'parse_error' => true,
        'stderr' => trim($phpstanRun['stderr']),
    ],
]);

$guardRun = runDiagnostic($root, $php, [
    'tools/dev/biomie_release_guard.php',
    '--json',
    '--read-only',
]);
$guardPayload = json_decode($guardRun['stdout'], true);
writeDiagnostic($outputDir . '/guard-status.json', [
    'generated_at_utc' => $generatedAt,
    'command' => 'php tools/dev/biomie_release_guard.php --json --read-only',
    'exit_code' => $guardRun['exit_code'],
    'result' => is_array($guardPayload) ? $guardPayload : [
        'parse_error' => true,
        'stderr' => trim($guardRun['stderr']),
    ],
]);

$pending = [];
foreach (($migrationResult['migrations'] ?? []) as $migration) {
    if (is_array($migration) && ($migration['status'] ?? null) === 'pending') {
        $pending[] = [
            'version' => $migration['version'] ?? null,
            'description' => $migration['description'] ?? null,
            'type' => $migration['type'] ?? null,
            'connection' => $migration['actual_connection'] ?? null,
            'file' => $migration['file_basename'] ?? null,
            'domain_group' => $migration['domain_group'] ?? null,
        ];
    }
}
writeDiagnostic($outputDir . '/schema-inventory.json', [
    'generated_at_utc' => $generatedAt,
    'read_only' => true,
    'summary' => $migrationResult['summary'] ?? null,
    'pending_migrations' => $pending,
    'note' => 'Detailed DDL and risk classification: docs/migrations-pending-audit.md',
]);

echo json_encode([
    'ok' => $migrationRun['exit_code'] === 0
        && $phpstanRun['exit_code'] === 0
        && $guardRun['exit_code'] === 0,
    'generated_at_utc' => $generatedAt,
    'files' => [
        'docs/generated/migration-status.json',
        'docs/generated/phpstan-status.json',
        'docs/generated/guard-status.json',
        'docs/generated/schema-inventory.json',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(($migrationRun['exit_code'] === 0 && $phpstanRun['exit_code'] === 0 && $guardRun['exit_code'] === 0) ? 0 : 1);
