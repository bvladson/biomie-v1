<?php
declare(strict_types=1);
/**
 * GEO.R2.1 — Enriquecimento geográfico dos 311 clientes sem geo
 * Uso: php bin/geo_r21_enrich.php [--dry-run] [--limit=50] [--batches=20]
 */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Cache;
use BIomie\Config;
use BIomie\CustomerOmieEnrichmentService;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\Support\TenantCliContext;

$opts    = getopt('', ['company:', 'dry-run', 'limit:', 'batches:', 'sleep:']);
$dryRun  = isset($opts['dry-run']);
$limit   = (int)($opts['limit']   ?? 30);
$batches = (int)($opts['batches'] ?? 20);
$sleepMs = (int)($opts['sleep']   ?? 200);

$config = new Config();
$logger = new Logger($projectRoot . '/storage/logs/geo_r21_enrich.log');

echo "[GEO.R2.1] Enriquecimento geo — " . date('Y-m-d H:i:s') . "\n";
echo "  dry_run=" . ($dryRun?'true':'false') . "  limit=$limit  batches=$batches  sleep_ms=$sleepMs\n\n";

// ── BASELINE antes ────────────────────────────────────────────────────────────
$pdo = \BIomie\Database::connect($config, 'db');
$tenantArgs = [$argv[0] ?? 'geo_r21_enrich.php'];
if (isset($opts['company'])) {
    $tenantArgs[] = '--company=' . $opts['company'];
}
$tenant = TenantCliContext::boot($pdo, $tenantArgs);
$cid = $tenant['company_id'];
$beforeNfGeo  = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.company_id=n.company_id AND g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.company_id={$cid} AND n.status_fiscal='autorizada'")->fetchColumn();
$beforeNfAuth = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE company_id={$cid} AND status_fiscal='autorizada'")->fetchColumn();
$beforeSemCid = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE company_id={$cid} AND (cidade_norm IS NULL OR TRIM(cidade_norm)='')")->fetchColumn();
$beforeCliGeo = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE company_id={$cid}")->fetchColumn();
echo "  ANTES:\n";
echo "    NFs com geo:     $beforeNfGeo / $beforeNfAuth (" . round($beforeNfGeo/$beforeNfAuth*100,1) . "%)\n";
echo "    Clientes geo:    $beforeCliGeo\n";
echo "    Sem cidade:      $beforeSemCid\n\n";

// ── ENRIQUECIMENTO ────────────────────────────────────────────────────────────
try {
    $pdo = \BIomie\Database::connect($config, 'db');
    $cache = new Cache(\BIomie\Bootstrap::rootPath('storage/cache'));
    $omieClient = (new OmieClientFactory($pdo, $logger, $config, $cache))
        ->forCompany($cid);
    $service = new CustomerOmieEnrichmentService($pdo, $omieClient, $logger, $config);
    $report = $service->runAll([
        'dry_run'             => $dryRun,
        'limit'               => $limit,
        'max_batches'         => $batches,
        'only_missing'        => true,
        'sleep_ms'            => $sleepMs,
        'max_runtime_seconds' => 0,
        'target'              => 'geo',
        'source'              => 'sales_buyers',
    ]);
    echo "  STATUS: " . ($report['status'] ?? 'done') . "\n";
    foreach ($report as $k => $v) {
        if (!is_array($v)) echo "    $k: $v\n";
    }
    if (!empty($report['errors'])) {
        foreach ((array)$report['errors'] as $err) echo "  ERR: $err\n";
    }
} catch (\Throwable $e) {
    echo "  EXCEPTION: " . $e->getMessage() . "\n";
    echo "  " . $e->getTraceAsString() . "\n";
}

// ── DEPOIS ────────────────────────────────────────────────────────────────────
// Re-connect (connection may have been reused)
$pdo2 = \BIomie\Database::connect($config, 'db');
$afterNfGeo  = (int)$pdo2->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.company_id=n.company_id AND g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.company_id={$cid} AND n.status_fiscal='autorizada'")->fetchColumn();
$afterSemCid = (int)$pdo2->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE company_id={$cid} AND (cidade_norm IS NULL OR TRIM(cidade_norm)='')")->fetchColumn();
$afterCliGeo = (int)$pdo2->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE company_id={$cid}")->fetchColumn();

echo "\n  DEPOIS:\n";
echo "    NFs com geo:     $afterNfGeo / $beforeNfAuth (" . round($afterNfGeo/$beforeNfAuth*100,1) . "%)\n";
echo "    Clientes geo:    $afterCliGeo (+" . ($afterCliGeo-$beforeCliGeo) . " novos)\n";
echo "    Sem cidade:      $afterSemCid (delta: " . ($afterSemCid-$beforeSemCid) . ")\n";
echo "    Ganho NFs:       +" . ($afterNfGeo-$beforeNfGeo) . " NFs mapeadas\n\n";
echo "[GEO.R2.1] Concluído — " . date('Y-m-d H:i:s') . "\n";
