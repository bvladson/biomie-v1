<?php

declare(strict_types=1);

use BIomie\Logger;
use BIomie\Database;
use BIomie\CanonicalRepository;
use BIomie\Sync\GeoAnalyticsCoordinator;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$config = new \BIomie\Config();

$longopts = [
    "start:",
    "end:",
    "dry-run"
];
$options = getopt("", $longopts);

$start = $options['start'] ?? date('Y-m-01');
$end = $options['end'] ?? date('Y-m-31');
$dryRun = isset($options['dry-run']);

$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$connectionName = 'indisponivel';

$startTime = microtime(true);
echo "Iniciando build do agregador Geo Analytics ($start a $end)...\n";

try {
    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];
    $connectionName = $connectionData[1];
    $canonicalRepo = new CanonicalRepository($pdo, $logger);
    $coordinator = new GeoAnalyticsCoordinator($canonicalRepo, $logger);

    echo "Conexao: $connectionName\n";

    $logger->info('Geo analytics CLI: build solicitado.', [
        'start' => $start,
        'end' => $end,
        'dry_run' => $dryRun,
        'connection' => $connectionName,
    ]);

    $missingTables = $canonicalRepo->missingGeoAnalyticsTables();
    if ($missingTables !== []) {
        $message = 'Tabelas geográficas ausentes. Rode as migrations no painel administrativo.';
        $logger->warning('Geo analytics CLI: build bloqueado por tabelas ausentes.', [
            'missing_tables' => $missingTables,
            'start' => $start,
            'end' => $end,
            'connection' => $connectionName,
        ]);

        echo "ERRO: $message\n";
        echo "Tabelas faltando:\n";
        foreach ($missingTables as $table) {
            echo "- $table\n";
        }
        exit(2);
    }

    if ($dryRun) {
        echo "[DRY-RUN] Nenhuma materializacao executada.\n";
        $logger->info('Geo analytics CLI: dry-run concluido.', [
            'start' => $start,
            'end' => $end,
            'connection' => $connectionName,
        ]);
    } else {
        $coordinator->buildAnalytics($start, $end);
    }
    $duration = round(microtime(true) - $startTime, 2);

    echo "Concluido.\n\n";
    echo "Metricas de Execucao:\n";
    echo "- Inicio: $start\n";
    echo "- Fim: $end\n";
    echo "- Duracao: {$duration}s\n";
} catch (\Throwable $e) {
    $logger->error('Geo analytics CLI: falha no build.', [
        'message' => $e->getMessage(),
        'start' => $start,
        'end' => $end,
        'connection' => $connectionName,
    ]);

    echo "ERRO FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
