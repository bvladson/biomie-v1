<?php

/**
 * Classifica retroativamente os canais comerciais (varejo/licitação)
 * de pedidos e NFs canonicais usando o engine de regras.
 *
 * Uso:
 *   php bin/classify_channels.php --company=mag_filial
 *   php bin/classify_channels.php --company=all
 *   php bin/classify_channels.php --company=mag_filial --dry-run
 *   php bin/classify_channels.php --company=mag_filial --pedidos-only
 *   php bin/classify_channels.php --company=mag_filial --nfs-only
 */

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\ChannelClassifierService;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

set_time_limit(0);
ini_set('memory_limit', '256M');

$args      = array_slice($argv ?? [], 1);
$dryRun    = in_array('--dry-run', $args, true);
$pedOnly   = in_array('--pedidos-only', $args, true);
$nfsOnly   = in_array('--nfs-only', $args, true);
$companyArg = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--company=')) {
        $companyArg = substr($arg, strlen('--company='));
    }
}

if ($companyArg === '') {
    fwrite(STDERR, "Uso: php bin/classify_channels.php --company=SLUG|all [--dry-run] [--pedidos-only] [--nfs-only]\n");
    exit(2);
}

// ── Resolve lista de empresas a classificar ───────────────────────────────────
if ($companyArg === 'all') {
    $companyRows = $pdo->query(
        "SELECT id, slug FROM biomie_companies WHERE ativo = 1 ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare(
        "SELECT id, slug FROM biomie_companies WHERE slug = :slug AND ativo = 1 LIMIT 1"
    );
    $stmt->execute(['slug' => $companyArg]);
    $companyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($companyRows)) {
        fwrite(STDERR, "Empresa nao encontrada ou inativa: {$companyArg}\n");
        exit(2);
    }
}

$startAll = microtime(true);
$report   = [];

foreach ($companyRows as $company) {
    $cid  = (int) $company['id'];
    $slug = (string) $company['slug'];
    echo ($dryRun ? '[DRY-RUN] ' : '') . "Classificando empresa: {$slug} (id={$cid})...\n";

    $classifier = new ChannelClassifierService($pdo, $cid);
    $compStats  = ['company_id' => $cid, 'slug' => $slug];
    $t0 = microtime(true);

    if (!$nfsOnly) {
        echo "  → pedidos... ";
        $ps = $classifier->classifyPedidos($dryRun);
        echo "total={$ps['pedidos_total']} varejo={$ps['pedidos_varejo']} licitacao={$ps['pedidos_licitacao']}\n";
        $compStats = array_merge($compStats, $ps);
    }

    if (!$pedOnly) {
        echo "  → notas fiscais... ";
        $ns = $classifier->classifyNFs($dryRun);
        echo "total={$ns['nfs_total']} varejo={$ns['nfs_varejo']} licitacao={$ns['nfs_licitacao']}\n";
        $compStats = array_merge($compStats, $ns);
    }

    $compStats['propagation'] = $classifier->propagateRelatedChannels($dryRun);

    $compStats['elapsed_s'] = round(microtime(true) - $t0, 2);
    $report[] = $compStats;
}

$totalElapsed = round(microtime(true) - $startAll, 1);
echo "\n=== Relatório final (" . ($dryRun ? 'DRY-RUN, nada gravado' : 'dados gravados') . ") ===\n";
echo json_encode(
    ['elapsed_s' => $totalElapsed, 'dry_run' => $dryRun, 'companies' => $report],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . "\n";
