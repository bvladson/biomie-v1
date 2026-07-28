<?php

declare(strict_types=1);

/**
 * Alertas de NEGOCIO para gestao/diretoria (Telegram/webhook).
 *
 * Uso:
 *   php bin/management_alerts.php --digest          # resumo diario (sempre envia)
 *   php bin/management_alerts.php --events          # eventos: meta batida / gap / inadimplencia (dedup)
 *   php bin/management_alerts.php --digest --pretty --no-notify   # previa sem enviar
 *
 * Cron sugerido (hPanel):
 *   0 11 * * *        digest  (08:00 Fortaleza)
 *   0 12,17,21 * * *  events
 */

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Modules\Sales\Application\Board\BoardConsolidatedService;
use BIomie\Ops\AlertNotifier;
use BIomie\Ops\ManagementAlerts;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['digest', 'events', 'pretty', 'no-notify']);
$mode = isset($options['digest']) ? 'digest' : 'events';
$pretty = isset($options['pretty']);
$notify = !isset($options['no-notify']);

try {
    $config = new Config();
    $logger = new Logger(dirname(__DIR__) . '/storage/logs/management_alerts.log');
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $cache = new Cache(dirname(__DIR__) . '/storage/cache');

    $board = new BoardConsolidatedService($config, $logger, $cache);
    $service = new ManagementAlerts($pdo, $config, $logger, new AlertNotifier($config, $logger), $board);
    $result = $service->run($mode, $notify);

    if ($pretty) {
        echo 'Modo: ' . (string) ($result['mode'] ?? $mode) . PHP_EOL;
        if (($result['mode'] ?? '') === 'digest') {
            foreach ((array) ($result['preview_lines'] ?? []) as $line) {
                echo '  ' . $line . PHP_EOL;
            }
            echo PHP_EOL . 'Enviado: ' . (($result['sent'] ?? false) ? 'sim' : 'nao (preview)') . PHP_EOL;
        } else {
            echo 'Alertas disparados: ' . (int) ($result['count'] ?? 0) . PHP_EOL;
            foreach ((array) ($result['alerts_sent'] ?? []) as $a) {
                echo '  - ' . $a . PHP_EOL;
            }
        }
    } else {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, SecretMask::sanitizeErrorMessage($exception->getMessage(), 500) . PHP_EOL);
    exit(2);
}
