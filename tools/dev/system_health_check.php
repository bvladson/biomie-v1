<?php

/**
 * system_health_check.php — CLI para verificar a saúde do sistema BIomie
 *
 * Uso:
 *   php tools/dev/system_health_check.php
 *   php tools/dev/system_health_check.php --json
 *   php tools/dev/system_health_check.php --check database
 *   php tools/dev/system_health_check.php --check jobs,freshness
 *
 * Códigos de saída:
 *   0 — ok (todos os checks ok)
 *   1 — warning (ao menos um warning, nenhum critical/unknown)
 *   2 — unknown (ao menos um unknown, nenhum critical)
 *   3 — critical (ao menos um critical)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\SystemHealth\SystemHealthService;

Bootstrap::init();

$config = new Config();

// ── Parse args ────────────────────────────────────────────────────────────────

$jsonMode  = in_array('--json', $argv ?? [], true);
$onlyCheck = null;
for ($i = 1; $i < count($argv ?? []); $i++) {
    if (($argv[$i] ?? '') === '--check' && isset($argv[$i + 1])) {
        $onlyCheck = array_map('trim', explode(',', $argv[$i + 1]));
    }
}

// ── Run ───────────────────────────────────────────────────────────────────────

try {
    [$pdo] = Database::getGeoConnection($config);
    $service = new SystemHealthService($pdo);
    $report  = $service->run();
    $data    = $report->toArray();
} catch (\Throwable $e) {
    if ($jsonMode) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT) . "\n";
    } else {
        fwrite(STDERR, "[CRITICAL] Falha ao executar health check: " . $e->getMessage() . "\n");
    }
    exit(3);
}

// Filter checks if --check was passed
if ($onlyCheck !== null) {
    $data['checks'] = array_values(array_filter(
        $data['checks'],
        static fn(array $c) => in_array($c['id'], $onlyCheck, true)
    ));
    // Recalculate overall status
    $rank = ['ok' => 0, 'unknown' => 1, 'warning' => 2, 'critical' => 3];
    $worst = 'ok';
    foreach ($data['checks'] as $c) {
        if (($rank[$c['status']] ?? 1) > ($rank[$worst] ?? 0)) {
            $worst = $c['status'];
        }
    }
    $data['status'] = $worst;
}

// ── Output ────────────────────────────────────────────────────────────────────

if ($jsonMode) {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    $statusColors = ['ok' => "\033[32m", 'warning' => "\033[33m", 'critical' => "\033[31m", 'unknown' => "\033[90m"];
    $reset = "\033[0m";

    echo "\n=== BIomie — System Health Check ===\n";
    echo "Gerado em: " . ($data['generated_at'] ?? date('c')) . "\n\n";

    foreach ($data['checks'] as $check) {
        $color = $statusColors[$check['status']] ?? "\033[90m";
        printf(
            "%s[%-8s]%s %-30s %s\n",
            $color, strtoupper($check['status']), $reset,
            $check['label'],
            $check['summary']
        );
        if (!empty($check['actions'])) {
            foreach ($check['actions'] as $action) {
                echo "            → {$action}\n";
            }
        }
    }

    $overallColor = $statusColors[$data['status']] ?? "\033[90m";
    echo "\n=== Status Geral: {$overallColor}" . strtoupper($data['status']) . "{$reset} ===\n\n";
}

// ── Exit code ─────────────────────────────────────────────────────────────────

$exitCodes = ['ok' => 0, 'warning' => 1, 'unknown' => 2, 'critical' => 3];
exit($exitCodes[$data['status']] ?? 3);
