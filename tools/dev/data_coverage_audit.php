<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Monitoring\DataCoverageRepository;
use BIomie\DataPlatform\Monitoring\DataCoverageService;
use BIomie\Logger;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['start:', 'end:', 'month:', 'json', 'markdown']);

$targetMonth = $opts['month'] ?? '';
$isJson = isset($opts['json']);
$isMarkdown = isset($opts['markdown']);

$start = $opts['start'] ?? date('Y-m-01', strtotime('-1 month'));
$end = $opts['end'] ?? date('Y-m-t');
$root = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/data_coverage_audit.log');

$months = [];
if ($targetMonth !== '') {
    $months[] = $targetMonth;
} else {
    $cursor = DateTimeImmutable::createFromFormat('Y-m-d', $start)->modify('first day of this month');
    $limit = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    while ($cursor <= $limit) {
        $months[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }
}

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    
    $repository = new DataCoverageRepository($pdo, $logger);
    $service = new DataCoverageService($repository);

    $dbInfo = $service->getDatabaseInfo();
    
    $coverage = [];
    $exitCode = 0;
    
    foreach ($months as $month) {
        $result = $service->analyzeMonth($month);
        $coverage[$month] = $result;
        
        if ($result['status'] === 'critical') {
            $exitCode = max($exitCode, 2);
        } elseif ($result['status'] === 'warning') {
            $exitCode = max($exitCode, 1);
        }
    }

    if ($isJson) {
        echo json_encode([
            'database' => $dbInfo,
            'coverage' => $coverage
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit($exitCode);
    }

    if ($isMarkdown) {
        echo "# Data Coverage Audit\n\n";
        echo "## Database Info\n";
        echo "- **Configured DB**: {$connection}\n";
        echo "- **Active DB**: {$dbInfo['database']}\n";
        echo "- **Current User**: {$dbInfo['current_user']}\n";
        echo "- **MariaDB Version**: {$dbInfo['version']}\n\n";
        
        foreach ($coverage as $month => $data) {
            echo "## Month: {$month} ({$data['status']})\n";
            echo "- **First Broken Layer**: " . ($data['first_broken_layer'] ?: 'None') . "\n";
            echo "- **Recommended Action**: " . ($data['recommended_action'] ?: 'None') . "\n";
            echo "### Layers\n";
            foreach ($data['layers'] as $table => $info) {
                echo "- `{$table}`: {$info['rows_in_period']} rows\n";
            }
            echo "\n";
        }
        exit($exitCode);
    }

    // Default plaintext output
    echo "============================================================\n";
    echo "DATA COVERAGE AUDIT\n";
    echo "============================================================\n";
    echo "DB configurado: {$connection}\n";
    echo "SELECT DATABASE(): {$dbInfo['database']}\n";
    echo "CURRENT_USER(): {$dbInfo['current_user']}\n";
    echo "MariaDB version: {$dbInfo['version']}\n";
    echo str_repeat('-', 60) . "\n";

    foreach ($coverage as $month => $data) {
        echo "\n[ Mês: {$month} ] Status: " . strtoupper($data['status']) . "\n";
        echo "canonical_nfs: " . ($data['layers']['biomie_notas_fiscais_canonicas']['rows_in_period'] ?? 0) . "\n";
        echo "canonical_revenue: " . ($data['layers']['biomie_notas_fiscais_canonicas']['revenue_in_period'] ?? 0.0) . "\n";
        echo "canonical_items: " . ($data['layers']['biomie_notas_fiscais_itens_canonicos']['rows_in_period'] ?? 0) . "\n";
        echo "mart_fiscal_rows: " . ($data['layers']['mart_fiscal_nfe']['rows_in_period'] ?? 0) . "\n";
        echo "mart_cliente_periodo_rows: " . ($data['layers']['mart_cliente_periodo']['rows_in_period'] ?? 0) . "\n";
        echo "mart_geo_rows: " . ($data['layers']['mart_compradores_por_geo']['rows_in_period'] ?? 0) . "\n";
        echo "mart_geo_revenue: " . ($data['layers']['mart_compradores_por_geo']['revenue_in_period'] ?? 0.0) . "\n";
        echo "mart_vendedor_dia_rows: " . ($data['layers']['mart_vendedor_dia']['rows_in_period'] ?? 0) . "\n";
        echo "mart_produto_rows: " . ($data['layers']['mart_produto_periodo']['rows_in_period'] ?? 0) . "\n";
        echo "mart_cnae_rows: " . ($data['layers']['mart_vendas_por_cnae_cnpj']['rows_in_period'] ?? 0) . "\n";
        echo "bronze_payloads: " . ($data['layers']['biomie_omie_bronze']['rows_in_period'] ?? 0) . "\n";
        echo "first_broken_layer: " . ($data['first_broken_layer'] ?? 'N/A') . "\n";
        echo "recommended_action: " . ($data['recommended_action'] ?? 'N/A') . "\n";
    }

    echo "\nTerminado.\n";
    exit($exitCode);

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(3);
}
