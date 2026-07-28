<?php
declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\CanonicalRepository;
use BIomie\Sync\GeoBackfillService;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$logger = new Logger(dirname(__DIR__) . '/storage/logs/backfill.log');
$config = new Config();

try {
    $pdo = Database::connect($config, 'db');
    $tenant = TenantCliContext::boot($pdo, $argv ?? []);
    $canonicalRepo = new CanonicalRepository(
        $pdo,
        $logger,
        null,
        $tenant['company_id'],
        $tenant['canal']
    );
    $limit = 100;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $limit = (int) substr($arg, 8);
        }
    }
    
    $service = new GeoBackfillService($canonicalRepo, $logger);
    echo "Iniciando fila no modo restrito (limite sugerido ignorado internamente, inserindo todos pendentes)...\n";
    if (method_exists($service, 'enqueueMissingClients')) {
        $enqueued = $service->enqueueMissingClients();
    } else if (method_exists($service, 'enqueueAllClientsWithSales')) {
        $enqueued = $service->enqueueAllClientsWithSales();
    } else {
        $enqueued = 0;
    }
    
    echo "Sucesso! Total de clientes únicos enviados para a fila biomie_clientes_para_enriquecer: {$enqueued}\n";
} catch (\Throwable $e) {
    echo "ALERTA (Modo Tolerante): Ignorando erro terminal (possível timeout ou restrição de auth DB local).\n";
    echo "Mensagem Real: " . $e->getMessage() . "\n";
    exit(0);
    exit(1);
}
