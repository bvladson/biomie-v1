<?php

declare(strict_types=1);

use BIomie\Logger;
use BIomie\Database;
use BIomie\OmieRepository;
use BIomie\CanonicalRepository;
use BIomie\Sync\ClientEnrichmentCoordinator;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$config = new \BIomie\Config();

$longopts = [
    "limit:",
    "company-id:"
];
$options = getopt("", $longopts);

$limit = isset($options['limit']) ? (int) $options['limit'] : 200;
// We ignore company-id for now as ClientEnrichmentCoordinator processQueue doesn't filter specifically by it, it processes the queue globally.

$pdo = Database::connect($config);
$logger = new Logger(dirname(__DIR__) . '/storage/logs/geo_enrichment.log');

$omieRepo = new OmieRepository($config, $logger);
$canonicalRepo = new CanonicalRepository($pdo, $logger);

$coordinator = new ClientEnrichmentCoordinator($omieRepo, $canonicalRepo, $logger);

$startTime = microtime(true);
echo "Iniciando processamento da fila de enriquecimento Geo (Limit: {$limit})...\n";

try {
    $result = $coordinator->processQueue($limit);
    $duration = round(microtime(true) - $startTime, 2);

    echo "Concluido.\n\n";
    echo "Metricas de Execucao:\n";
    echo "- Processados: " . ($result['processed'] + $result['failed']) . "\n";
    echo "- Enriquecidos com Sucesso: " . $result['processed'] . "\n";
    echo "- Falhas/Erro/Sem Cadastro: " . $result['failed'] . "\n";
    echo "- Duracao: {$duration}s\n";
} catch (\Throwable $e) {
    echo "ERRO FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
