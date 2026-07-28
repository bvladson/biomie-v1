<?php

declare(strict_types=1);

/**
 * Guard de isolamento multi-empresa das leituras gold (mart_*).
 *
 * Para cada empresa, roda os readers e confere que cada valor retornado bate
 * com a FATIA daquela empresa — pega regressao de query sem company_id.
 *
 * Uso:
 *   php bin/tenant_isolation_guard.php            # JSON
 *   php bin/tenant_isolation_guard.php --pretty
 *   php bin/tenant_isolation_guard.php --alert     # envia Telegram/webhook se falhar
 *
 * Exit: 0 = isolado, 2 = VAZAMENTO detectado.
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Ops\AlertNotifier;
use BIomie\Ops\TenantIsolationGuard;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['pretty', 'alert']);
$pretty = isset($options['pretty']);
$alert = isset($options['alert']);

try {
    $config = new Config();
    $logger = new Logger(dirname(__DIR__) . '/storage/logs/tenant_isolation_guard.log');
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);

    $guard = new TenantIsolationGuard($pdo, $logger);
    $result = $guard->run();

    if ($alert && !$result['ok']) {
        (new AlertNotifier($config, $logger))->send(
            'critical',
            '🔴 VAZAMENTO ENTRE EMPRESAS — isolamento de dados quebrado',
            $guard->alertLines($result),
        );
    }

    if ($pretty) {
        printf(
            "Guard de isolamento — %s\nEmpresas: %d · Checagens: %d · %s\n",
            $result['ok'] ? 'PASS ✅' : 'FAIL 🔴',
            (int) $result['companies'],
            (int) $result['checks'],
            (string) ($result['period']['comps'] ?? ''),
        );
        foreach ((array) $result['failures'] as $f) {
            printf(
                "  LEAK %s · %s · %s: reader=%s fatia=%s\n",
                (string) $f['company'],
                (string) $f['probe'],
                (string) $f['detail'],
                (string) ($f['reader'] ?? '?'),
                (string) ($f['own'] ?? '?'),
            );
        }
    } else {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    exit($result['ok'] ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, SecretMask::sanitizeErrorMessage($exception->getMessage(), 500) . PHP_EOL);
    exit(3);
}
