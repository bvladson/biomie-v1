<?php

declare(strict_types=1);

/**
 * Orchestrador multi-empresa para o pipeline de sync Omie.
 *
 * Consulta biomie_company_integrations para descobrir quais empresas têm
 * integração Omie ativa e dispara sync_realtime_pipeline.php para cada uma
 * em sequência, via proc_open (exec desabilitado no servidor).
 *
 * Uso:
 *   php cron/sync_all_companies.php
 *   php cron/sync_all_companies.php --domains=commercial
 *   php cron/sync_all_companies.php --domains=finance --mode=delta
 *   php cron/sync_all_companies.php --mode=backfill --start=2026-01-01 --end=2026-06-30
 *   php cron/sync_all_companies.php --mode=reconcile --reconcile=30
 *   php cron/sync_all_companies.php --dry-run
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

set_time_limit(0);
ini_set('memory_limit', '256M');

// ── Argumentos ───────────────────────────────────────────────────────────────
$rawArgs  = array_slice($argv ?? [], 1);
$dryRun   = in_array('--dry-run', $rawArgs, true);
$jsonMode = in_array('--json', $rawArgs, true);

$passthroughArgs = [];
foreach ($rawArgs as $arg) {
    // --dry-run e --json são tratados localmente; --company= é definido pelo loop
    if ($arg === '--dry-run' || $arg === '--json' || str_starts_with($arg, '--company=')) {
        continue;
    }
    $passthroughArgs[] = $arg;
}

// ── Empresas com integração Omie ativa ───────────────────────────────────────
$stmt = $pdo->query(
    "SELECT c.id, c.slug, c.canal_padrao
       FROM biomie_companies c
       JOIN biomie_company_integrations i
         ON i.company_id = c.id AND i.provider = 'omie' AND i.active = 1
      WHERE c.ativo = 1
      ORDER BY c.id ASC"
);
$companies = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

if (empty($companies)) {
    fwrite(STDERR, "Nenhuma empresa com integração Omie ativa encontrada.\n");
    exit(2);
}

$startedAt = date(DATE_ATOM);
$phpBin    = PHP_BINARY;
$pipelineScript = dirname(__DIR__) . '/cron/sync_realtime_pipeline.php';
$results   = [];
$hasErrors = false;

foreach ($companies as $company) {
    $slug = (string) $company['slug'];
    $cid  = (int) $company['id'];
    $ts   = date('Y-m-d H:i:s');

    if (!$jsonMode) {
        echo "[{$ts}] → company={$slug} (id={$cid})\n";
    }

    if ($dryRun) {
        $results[] = [
            'company_id'   => $cid,
            'company_slug' => $slug,
            'ok'           => true,
            'dry_run'      => true,
        ];
        continue;
    }

    $cmd = array_merge(
        [$phpBin, $pipelineScript, "--company={$slug}"],
        $passthroughArgs,
        ['--json']
    );

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $desc, $pipes, dirname(__DIR__));

    if (!is_resource($proc)) {
        $hasErrors = true;
        $results[] = [
            'company_id'   => $cid,
            'company_slug' => $slug,
            'ok'           => false,
            'error'        => 'proc_open falhou',
        ];
        $logger->error("sync_all_companies: proc_open falhou para {$slug}.");
        continue;
    }

    fclose($pipes[0]);
    $stdout   = stream_get_contents($pipes[1]);
    $stderr   = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $output = is_string($stdout) ? @json_decode($stdout, true) : null;
    $ok     = $exitCode === 0;
    if (!$ok) {
        $hasErrors = true;
    }

    $entry = [
        'company_id'   => $cid,
        'company_slug' => $slug,
        'ok'           => $ok,
        'exit_code'    => $exitCode,
        'result'       => $output,
    ];
    if (!$ok && $stderr !== '') {
        $entry['stderr'] = substr($stderr, 0, 500);
    }

    $results[] = $entry;

    if (!$jsonMode) {
        $status = $ok ? 'OK' : "ERRO (exit={$exitCode})";
        echo "     {$status}\n";
        if (!$ok && $stderr !== '') {
            echo '     ' . trim(substr($stderr, 0, 200)) . "\n";
        }
    }
}

$summary = [
    'ok'               => !$hasErrors,
    'dry_run'          => $dryRun,
    'companies_total'  => count($companies),
    'companies_ok'     => count(array_filter($results, fn ($r) => (bool) ($r['ok'] ?? false))),
    'companies_failed' => count(array_filter($results, fn ($r) => !(bool) ($r['ok'] ?? false))),
    'started_at'       => $startedAt,
    'finished_at'      => date(DATE_ATOM),
    'results'          => $results,
];

if ($jsonMode) {
    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} else {
    echo sprintf(
        "\n[%s] Concluído: %d/%d empresas OK\n",
        date('Y-m-d H:i:s'),
        $summary['companies_ok'],
        $summary['companies_total']
    );
}

exit($hasErrors ? 1 : 0);
