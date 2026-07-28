<?php

/**
 * qa_final_suite.php — Suíte unificada de QA para release candidate BIomie
 *
 * Uso:
 *   php tools/dev/qa_final_suite.php
 *   php tools/dev/qa_final_suite.php --json
 *   php tools/dev/qa_final_suite.php --skip-db
 *   php tools/dev/qa_final_suite.php --fail-fast
 *
 * Saídas de processo:
 *   0 — todos os checks passaram
 *   1 — um ou mais checks falharam
 *
 * Checks executados (em ordem):
 *   1. PHP Syntax Lint       (lint_all.php)
 *   2. RBAC Audit            (rbac_audit.php)
 *   3. Semantic Audit        (semantic_audit.php)
 *   4. System Health Check   (system_health_check.php)  [requer DB]
 *   5. Data Coverage Audit   (data_coverage_audit.php)  [requer DB]
 *   6. Geo Quality Report    (geo_quality_report.php)   [requer DB]
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$argv      = $argv ?? [];
$jsonMode  = in_array('--json', $argv, true);
$skipDb    = in_array('--skip-db', $argv, true);
$failFast  = in_array('--fail-fast', $argv, true);

$root      = dirname(__DIR__, 2);
$toolsDir  = __DIR__;
$startedAt = date('c');
$startMs   = (int) round(microtime(true) * 1000);

// ── Check definitions ─────────────────────────────────────────────────────

/** @var list<array{id:string,label:string,cmd:string,requires_db:bool,fail_on_error:bool}> */
$checks = [
    [
        'id'            => 'lint',
        'label'         => 'PHP Syntax Lint',
        'cmd'           => PHP_BINARY . ' ' . escapeshellarg($toolsDir . '/lint_all.php'),
        'requires_db'   => false,
        'fail_on_error' => true,
    ],
    [
        'id'            => 'rbac',
        'label'         => 'RBAC Audit',
        'cmd'           => PHP_BINARY . ' ' . escapeshellarg($toolsDir . '/rbac_audit.php'),
        'requires_db'   => false,
        'fail_on_error' => true,
    ],
    [
        'id'            => 'semantic',
        'label'         => 'Semantic Audit (KPIs)',
        'cmd'           => PHP_BINARY . ' ' . escapeshellarg($toolsDir . '/semantic_audit.php') . ' --fail-on-warning',
        'requires_db'   => false,
        'fail_on_error' => true,
    ],
    [
        'id'            => 'system_health',
        'label'         => 'System Health Check',
        'cmd'           => PHP_BINARY . ' ' . escapeshellarg($toolsDir . '/system_health_check.php') . ' --json',
        'requires_db'   => true,
        'fail_on_error' => false,
    ],
    [
        'id'            => 'data_coverage',
        'label'         => 'Data Coverage Audit',
        'cmd'           => PHP_BINARY . ' ' . escapeshellarg($toolsDir . '/data_coverage_audit.php'),
        'requires_db'   => true,
        'fail_on_error' => false,
    ],
    [
        'id'            => 'geo_quality',
        'label'         => 'Geo Quality Report',
        'cmd'           => PHP_BINARY . ' ' . escapeshellarg($toolsDir . '/geo_quality_report.php'),
        'requires_db'   => true,
        'fail_on_error' => false,
    ],
];

// ── Run checks ────────────────────────────────────────────────────────────

/**
 * @return array{exit_code:int,stdout:string,duration_ms:int}
 */
function runCheck(string $cmd): array
{
    $t0 = microtime(true);
    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);
    return [
        'exit_code'   => $exitCode,
        'stdout'      => implode("\n", $output),
        'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
    ];
}

/** @var list<array<string,mixed>> */
$results  = [];
$anyFail  = false;

foreach ($checks as $check) {
    $skipped = $skipDb && $check['requires_db'];

    if ($skipped) {
        $results[] = [
            'id'          => $check['id'],
            'label'       => $check['label'],
            'status'      => 'skipped',
            'exit_code'   => null,
            'duration_ms' => 0,
            'output'      => 'Pulado: requer banco de dados (--skip-db).',
            'requires_db' => true,
            'fail_on_error' => $check['fail_on_error'],
        ];
        if (!$jsonMode) {
            echo "  [SKIP] {$check['label']}\n";
        }
        continue;
    }

    if (!$jsonMode) {
        echo "  [....] {$check['label']}";
        flush();
    }

    $run = runCheck($check['cmd']);
    $passed = $run['exit_code'] === 0;
    $status = $passed ? 'pass' : ($check['fail_on_error'] ? 'fail' : 'warn');

    if (!$passed && $check['fail_on_error']) {
        $anyFail = true;
    }

    $results[] = [
        'id'            => $check['id'],
        'label'         => $check['label'],
        'status'        => $status,
        'exit_code'     => $run['exit_code'],
        'duration_ms'   => $run['duration_ms'],
        'output'        => $run['stdout'],
        'requires_db'   => $check['requires_db'],
        'fail_on_error' => $check['fail_on_error'],
    ];

    if (!$jsonMode) {
        $tag = $passed ? '[PASS]' : ($check['fail_on_error'] ? '[FAIL]' : '[WARN]');
        echo "\r  {$tag} {$check['label']} ({$run['duration_ms']} ms)\n";
        if (!$passed) {
            $lines = array_slice(array_filter(explode("\n", $run['stdout'])), 0, 15);
            foreach ($lines as $line) {
                echo "         {$line}\n";
            }
        }
    }

    if ($failFast && !$passed && $check['fail_on_error']) {
        break;
    }
}

// ── Summary ───────────────────────────────────────────────────────────────

$totalMs     = (int) round(microtime(true) * 1000) - $startMs;
$countPass   = count(array_filter($results, fn ($r) => $r['status'] === 'pass'));
$countFail   = count(array_filter($results, fn ($r) => $r['status'] === 'fail'));
$countWarn   = count(array_filter($results, fn ($r) => $r['status'] === 'warn'));
$countSkip   = count(array_filter($results, fn ($r) => $r['status'] === 'skipped'));

$summary = [
    'passed'        => $countPass,
    'failed'        => $countFail,
    'warnings'      => $countWarn,
    'skipped'       => $countSkip,
    'total'         => count($checks),
    'release_ready' => !$anyFail,
    'started_at'    => $startedAt,
    'duration_ms'   => $totalMs,
];

if ($jsonMode) {
    echo json_encode([
        'ok'      => !$anyFail,
        'summary' => $summary,
        'checks'  => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    $statusLine = $anyFail ? 'FALHOU' : 'PASSOU';
    $readyLine  = !$anyFail ? 'Release candidate: PRONTO' : 'Release candidate: NAO PRONTO';
    echo "\n";
    echo "─────────────────────────────────────────────────────\n";
    echo " QA Final Suite — {$statusLine}  ({$totalMs} ms)\n";
    echo "─────────────────────────────────────────────────────\n";
    echo " Passou:     {$countPass}\n";
    echo " Falhou:     {$countFail}\n";
    echo " Avisos:     {$countWarn}\n";
    echo " Pulados:    {$countSkip}\n";
    echo " Total:      " . count($checks) . "\n";
    echo "\n {$readyLine}\n";
    echo "─────────────────────────────────────────────────────\n";
}

exit($anyFail ? 1 : 0);
