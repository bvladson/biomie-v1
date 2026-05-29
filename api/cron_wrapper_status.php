<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Http;
use BIomie\Support\SecretMask;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireAnyCapability(['jobs.view', 'logs.view', 'admin.view'], true);

try {
    $root = dirname(__DIR__);
    $wrappers = [
        [
            'key' => 'worker',
            'label' => 'Worker',
            'frequency' => '*/5 * * * *',
            'command' => '/bin/sh ' . $root . '/cron/hostinger/worker.sh',
            'log' => 'storage/logs/cron/worker.log',
        ],
        [
            'key' => 'sync_commercial',
            'label' => 'Sync comercial',
            'frequency' => '*/15 * * * *',
            'command' => '/bin/sh ' . $root . '/cron/hostinger/sync_commercial.sh',
            'log' => 'storage/logs/cron/sync_commercial.log',
        ],
        [
            'key' => 'sync_finance',
            'label' => 'Sync financeiro',
            'frequency' => '*/15 * * * *',
            'command' => '/bin/sh ' . $root . '/cron/hostinger/sync_finance.sh',
            'log' => 'storage/logs/cron/sync_finance.log',
        ],
        [
            'key' => 'materialize',
            'label' => 'Materializacao Gold',
            'frequency' => '0,30 * * * *',
            'command' => '/bin/sh ' . $root . '/cron/hostinger/materialize.sh',
            'log' => 'storage/logs/cron/materialize.log',
        ],
        [
            'key' => 'daily_integrity',
            'label' => 'Integridade diaria',
            'frequency' => '*/30 * * * *',
            'command' => '/bin/sh ' . $root . '/cron/hostinger/daily_integrity.sh',
            'log' => 'storage/logs/cron/daily_integrity.log',
        ],
    ];

    $rows = array_map(static function (array $wrapper) use ($root): array {
        $logPath = $root . '/' . $wrapper['log'];
        $exists = is_file($logPath);
        $mtime = $exists ? (int) (@filemtime($logPath) ?: 0) : 0;
        $ageMinutes = $mtime > 0 ? max(0, (int) floor((time() - $mtime) / 60)) : null;
        $lastLines = $exists ? tailFile($logPath, 16) : [];
        $lastText = implode("\n", $lastLines);
        $failed = preg_match('/exit=([1-9]\d*)|failed|fatal|error/i', $lastText) === 1;
        $status = match (true) {
            !$exists => 'unknown',
            $failed => 'warning',
            $ageMinutes !== null && $ageMinutes <= 120 => 'ok',
            default => 'warning',
        };

        return $wrapper + [
            'log_exists' => $exists,
            'last_modified_at' => $mtime > 0 ? date(DATE_ATOM, $mtime) : null,
            'age_minutes' => $ageMinutes,
            'status' => $status,
            'last_lines' => array_map(
                static fn (string $line): string => SecretMask::sanitizeErrorMessage($line, 300),
                $lastLines
            ),
        ];
    }, $wrappers);

    Http::json([
        'ok' => true,
        'generated_at' => date(DATE_ATOM),
        'wrappers' => $rows,
    ]);
} catch (Throwable $e) {
    $logger->error('api/cron_wrapper_status.php falhou.', [
        'message' => SecretMask::sanitizeErrorMessage($e->getMessage(), 200),
    ]);
    Http::json([
        'ok' => false,
        'error' => 'internal_error',
        'message' => 'Nao foi possivel ler status dos wrappers de cron.',
    ], 500);
}

/**
 * @return list<string>
 */
function tailFile(string $path, int $lines): array
{
    $raw = @file($path, FILE_IGNORE_NEW_LINES);
    if ($raw === false) {
        return [];
    }
    return array_slice($raw, -max(1, $lines));
}
