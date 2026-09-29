<?php

declare(strict_types=1);

/**
 * Sentinela de INTEGRIDADE COMERCIAL — vendas escondidas ou descartadas.
 *
 * Roda o CommercialIntegrityHealthCheck (o mesmo do Admin → Saúde) e, com
 * --notify, alerta por Telegram/webhook (AlertNotifier) e no sininho, com dedup:
 * só repete o alerta se o conjunto de problemas mudar ou depois de 24 h.
 *
 * Uso:
 *   php bin/commercial_integrity_check.php            # avalia e imprime
 *   php bin/commercial_integrity_check.php --notify   # avalia e alerta (cron)
 *   php bin/commercial_integrity_check.php --json
 *
 * Exit: 0 = ok, 1 = alerta, 2 = crítico — serve de verificação pós-deploy.
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Ops\AlertNotifier;
use BIomie\SystemHealth\Checks\CommercialIntegrityHealthCheck;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['notify', 'json']);
$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');

$findings = (new CommercialIntegrityHealthCheck($pdo, dirname(__DIR__)))->evaluate();
$problems = array_values(array_filter(
    $findings,
    static fn (array $f): bool => in_array($f['severity'], ['critical', 'warning'], true),
));
$worst = 'ok';
foreach ($problems as $p) {
    if ($p['severity'] === 'critical') {
        $worst = 'critical';
        break;
    }
    $worst = 'warning';
}

if (isset($options['json'])) {
    echo json_encode(['severity' => $worst, 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} else {
    printf("Integridade comercial — %s — %s\n\n", date('Y-m-d H:i'), strtoupper($worst));
    foreach ($findings as $f) {
        printf("%-9s %-24s %6d  %s\n", strtoupper($f['severity']), $f['code'], $f['count'], $f['count'] > 0 ? $f['title'] : '');
        foreach ($f['details'] as $d) {
            echo "            · {$d}\n";
        }
        if ($f['count'] > 0 && $f['severity'] !== 'info') {
            echo "            → {$f['action']}\n";
        }
    }
}

if (isset($options['notify']) && $problems !== []) {
    $signature = hash('sha256', json_encode(array_map(
        static fn (array $p): array => [$p['code'], $p['count']],
        $problems,
    )));
    $stateFile = dirname(__DIR__) . '/storage/cache/commercial_integrity_state.json';
    $state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
    $repeat = ($state['signature'] ?? '') !== $signature || (time() - (int) ($state['sent_at'] ?? 0)) >= 86400;

    if ($repeat) {
        $title = 'Integridade comercial: ' . count($problems) . ' problema(s) — vendas podem estar escondidas ou fora do BIomie';
        $lines = array_map(static fn (array $p): string => $p['title'] . ' → ' . $p['action'], $problems);
        $logger = new Logger(dirname(__DIR__) . '/storage/logs/commercial_integrity.log');
        (new AlertNotifier($config, $logger))->send($worst, $title, $lines);
        try {
            $rw = Database::connect($config, $connection);
            $rw->exec('SET SESSION TRANSACTION READ WRITE');
            (new \BIomie\Notifications\NotificationService($rw))->push([
                'category' => 'business',
                'severity' => $worst,
                'title' => $title,
                'body' => implode("\n", array_slice($lines, 0, 4)),
                'company_id' => null,
                'source' => 'commercial_integrity',
                'link' => 'admin.php?tab=system-health',
                'dedupe_key' => 'commercial_integrity|' . $signature,
                'dedupe_window_seconds' => 86400,
            ]);
        } catch (\Throwable) {
            // O alerta externo já saiu; o sininho é espelho.
        }
        @file_put_contents($stateFile, json_encode(['signature' => $signature, 'sent_at' => time()]));
    }
}

exit($worst === 'critical' ? 2 : ($worst === 'warning' ? 1 : 0));
