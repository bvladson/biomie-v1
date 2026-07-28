<?php

/**
 * Materialização da dimensão de clientes por empresa (biomie_clientes_dim + biomie_clientes_geo_dim).
 *
 * Uso:
 *   php bin/materialize_client_dim.php --company=SLUG
 *
 * Exemplos:
 *   php bin/materialize_client_dim.php --company=MAG_FILIAL
 *   php bin/materialize_client_dim.php --company=ERGOOFFICE
 *   php bin/materialize_client_dim.php --company=EXPERT
 *
 * Saída: JSON com contadores de inseridos/atualizados/inalterados + status.
 */

declare(strict_types=1);

use BIomie\Config;
use BIomie\CustomerDimensionMaterializationService;
use BIomie\Database;
use BIomie\Logger;
use BIomie\TableHealthService;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

// ── Bootstrap ────────────────────────────────────────────────────────────────

$logPath = dirname(__DIR__) . '/storage/logs/materialize_client_dim.log';
$logger  = new Logger($logPath);
$config  = new Config();

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    echo "[ERRO] Banco de dados não configurado. Verifique {$connection} em config.\n";
    exit(1);
}

// ── Tenant ───────────────────────────────────────────────────────────────────

try {
    $pdo    = Database::connect($config, $connection);
    $tenant = TenantCliContext::boot($pdo, $argv ?? []);
} catch (\Throwable $e) {
    echo "[ERRO] Falha no boot do tenant: " . $e->getMessage() . "\n";
    echo "Uso: php bin/materialize_client_dim.php --company=SLUG\n";
    exit(1);
}

$companyId   = (int) $tenant['company_id'];
$companySlug = (string) ($tenant['slug'] ?? 'unknown');

echo sprintf(
    "[%s] materialize_client_dim: company=%s (id=%d)\n",
    date('Y-m-d H:i:s'),
    $companySlug,
    $companyId
);

$logger->info('materialize_client_dim: iniciando.', [
    'company_id'   => $companyId,
    'company_slug' => $companySlug,
]);

// ── Execução ─────────────────────────────────────────────────────────────────

try {
    $tableHealth = new TableHealthService($pdo);
    $service     = new CustomerDimensionMaterializationService($pdo, $logger, $tableHealth, $companyId);
    $result      = $service->run();

    $status   = $result['status'] ?? 'unknown';
    $inserted = (int) ($result['inserted_count'] ?? 0);
    $updated  = (int) ($result['updated_count'] ?? 0);
    $unchanged= (int) ($result['unchanged_count'] ?? 0);
    $geo      = (int) ($result['geo_dim_inserted'] ?? 0);
    $durationMs = (int) ($result['duration_ms'] ?? 0);

    echo sprintf(
        "[%s] %s | company=%s | inseridos=%d atualizados=%d inalterados=%d geo_dim=%d | %dms\n",
        date('Y-m-d H:i:s'),
        strtoupper($status),
        $companySlug,
        $inserted,
        $updated,
        $unchanged,
        $geo,
        $durationMs
    );

    foreach ($result['warnings'] ?? [] as $w) {
        echo "  [WARN] $w\n";
    }
    foreach ($result['errors'] ?? [] as $e) {
        echo "  [ERRO] $e\n";
    }

    $finalCounts = $result['final_counts'] ?? [];
    if ($finalCounts !== []) {
        echo sprintf(
            "  Total na dim: %d | com_doc=%d | com_vendedor=%d | com_geo=%d | com_cnae=%d\n",
            $finalCounts['total'] ?? 0,
            $finalCounts['com_documento'] ?? 0,
            $finalCounts['com_vendedor'] ?? 0,
            $finalCounts['com_geo'] ?? 0,
            $finalCounts['com_cnae'] ?? 0
        );
    }

    $logger->info('materialize_client_dim: concluido.', [
        'company_id'   => $companyId,
        'company_slug' => $companySlug,
        'status'       => $status,
        'inserted'     => $inserted,
        'updated'      => $updated,
        'unchanged'    => $unchanged,
        'geo_dim'      => $geo,
        'duration_ms'  => $durationMs,
    ]);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($result['ok'] ? 0 : 1);

} catch (\Throwable $e) {
    $msg = $e->getMessage();
    echo "[ERRO] materialize_client_dim falhou: $msg\n";
    $logger->error('materialize_client_dim: falha critica.', ['message' => $msg, 'company_id' => $companyId]);
    exit(1);
}
