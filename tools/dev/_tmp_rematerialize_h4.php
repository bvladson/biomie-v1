<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(300);

/**
 * Fase H.4 — T8: Rematerialização completa do período comercial após override de escopo.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Gold\GoldRefreshCoordinator;
use BIomie\Logger;

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo        = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$logger = new Logger('/tmp/remat_h4_biomie.log');

$start = '2026-02-02';
$end   = '2026-05-20';

echo "\n=== Rematerialização gold marts [H.4] ===\n";
echo "Período: $start → $end\n";
flush();

echo "Iniciando GoldRefreshCoordinator...\n";
flush();

$coordinator = new GoldRefreshCoordinator($pdo, $logger);
echo "Coordinator criado. Executando refreshPeriod...\n";
flush();

$counts = $coordinator->refreshPeriod($start, $end);

echo "\nResultado:\n";
foreach ($counts as $mart => $n) {
    printf("  %-20s  %s\n", $mart, is_float($n) ? sprintf('%.2fs', $n) : $n);
}

echo "\nOK. Execute commercial_recalibration_audit.php para verificar integridade.\n\n";
