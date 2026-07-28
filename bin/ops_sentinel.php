<?php

declare(strict_types=1);

/**
 * Sentinela operacional do pipeline de sync.
 *
 * Roda a cada ~15min via cron. Limpa runs presas, detecta pipeline morto /
 * dominios fora do SLA e dispara alerta (Telegram/webhook) com dedup.
 *
 * Uso:
 *   php bin/ops_sentinel.php              # avalia e alerta se necessario
 *   php bin/ops_sentinel.php --pretty     # saida legivel
 *   php bin/ops_sentinel.php --no-notify  # so avalia (nao envia alerta)
 *
 * Exit: 0 = ok, 1 = warning, 2 = critical (util para monitoramento externo).
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Ops\AlertNotifier;
use BIomie\Ops\OpsSentinel;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['pretty', 'no-notify']);
$pretty = isset($options['pretty']);
$notify = !isset($options['no-notify']);

try {
    $config = new Config();
    $logger = new Logger(dirname(__DIR__) . '/storage/logs/ops_sentinel.log');
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);

    $sentinel = new OpsSentinel($pdo, $config, $logger, new AlertNotifier($config, $logger));
    $result = $sentinel->run($notify);

    if ($pretty) {
        printf("Sentinela operacional — %s\n", (string) ($result['database_now'] ?? '-'));
        printf("Severidade: %s%s\n", strtoupper((string) ($result['severity'] ?? '?')), ($result['dead_pipeline'] ?? false) ? '  [PIPELINE MORTO]' : '');
        printf("Runs presas limpas: %d\n\n", (int) ($result['stuck_cleaned'] ?? 0));
        printf("%-12s %-22s %-10s %s\n", 'DOMINIO', 'ULTIMO OK', 'IDADE(min)', 'LIMITES(w/c)');
        echo str_repeat('-', 64) . "\n";
        foreach ((array) ($result['domains'] ?? []) as $domain => $d) {
            printf(
                "%-12s %-22s %-10s %d/%d\n",
                (string) $domain,
                (string) ($d['last_ok'] ?? '-'),
                $d['age_min'] === null ? 'sem run' : (string) $d['age_min'],
                (int) ($d['warn_min'] ?? 0),
                (int) ($d['crit_min'] ?? 0),
            );
        }
        $alert = $result['alert'] ?? [];
        $alertLabel = ($alert['sent'] ?? false) === true
            ? 'ENVIADO'
            : (($alert['needed'] ?? false) === true ? 'necessario (nao enviado)' : 'nao');
        echo "\nAlerta: " . $alertLabel . ' (' . (string) ($alert['reason'] ?? '-') . ")\n";
    } else {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    $severity = (string) ($result['severity'] ?? 'critical');
    exit($severity === 'critical' ? 2 : ($severity === 'warning' ? 1 : 0));
} catch (Throwable $exception) {
    fwrite(STDERR, SecretMask::sanitizeErrorMessage($exception->getMessage(), 500) . PHP_EOL);
    exit(3);
}
