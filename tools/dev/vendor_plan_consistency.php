<?php

declare(strict_types=1);

/**
 * Ferramenta de consistência: compara o realizado no VendorRealizedService,
 * no FlightPlanV2Service e na query direta ao canônico.
 *
 * Uso:
 *   php tools/dev/vendor_plan_consistency.php --start=2026-05-01 --end=2026-05-31 [--vendor=ID] [--json]
 *
 * Saída:
 *   status: ok | critical
 *   Para cada vendedor: canonical_direct, vendor360_realized, flight_plan_realized, diffs
 *   Se qualquer diff > 0.01 → status critical
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Config;
use BIomie\Logger;
use BIomie\FlightPlanV2Service;
use BIomie\TargetsRepository;
use BIomie\DataPlatform\Silver\Readers\CanonicalReadSupport;
use BIomie\Support\FiscalValueBasis;
use BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver;
use BIomie\Modules\Sales\Application\Vendors\VendorRealizedService;

$args      = array_slice($argv ?? [], 1);
$opts      = getopt('', ['start:', 'end:', 'vendor:', 'json']);
$start     = trim((string) ($opts['start']  ?? date('Y-m-01')));
$end       = trim((string) ($opts['end']    ?? date('Y-m-t')));
$vendorFilter = isset($opts['vendor']) ? trim((string) $opts['vendor']) : null;
$jsonOutput   = isset($opts['json']);

// ── Bootstrap ────────────────────────────────────────────────────────────────
$config = new Config();
$logger = new Logger(Bootstrap::rootPath() . '/storage/logs/biomie.log');
$pdo    = Database::connect($config, 'db');

$canonical   = new CanonicalRepository($pdo, $logger);
$vendorMap   = $canonical->getVendorMap();
$resolver    = new VendorIdentityResolver($vendorMap);
$realizedSvc = new VendorRealizedService($canonical, $resolver);

// ── 1. VendorRealizedService (fonte oficial do Vendor 360) ──────────────────
$vendor360Summary = $realizedSvc->summarizeByVendor($start, $end);

// Index por vendor_id
$vendor360Map = [];
foreach ($vendor360Summary as $v) {
    $vendor360Map[$v['vendor_id']] = $v;
}

// ── 2. FlightPlanV2Service (fonte do plano de voo) ──────────────────────────
$targetsRepo = null;
try {
    $targetsRepo = new TargetsRepository($pdo, $logger);
} catch (\Throwable) {
    // sem metas — ok para o teste de consistência
}

$plan     = (new FlightPlanV2Service($canonical, $targetsRepo))->build($start, $end, null);
$planMap  = [];
foreach ($plan['vendors'] ?? [] as $row) {
    $vid = trim((string) ($row['vendor_id'] ?? $row['vendor_key'] ?? ''));
    if ($vid !== '') {
        $planMap[$vid] = $row;
    }
}

// ── 3. Query direta ao canônico (ground truth) ──────────────────────────────
$readSupport = new CanonicalReadSupport(static fn (): \PDO => $pdo);
$commercialScope = $readSupport->commercialScopeFilter('nf.');
$commercialBase = FiscalValueBasis::preComputedBaseSql('nf');
$stmt = $pdo->prepare(
    "SELECT
        COALESCE(nf.codigo_vendedor_omie, '') AS vendor_id,
        COALESCE(nf.nome_vendedor, 'Sem vendedor') AS vendor_name,
        SUM({$commercialBase}) AS canonical_total,
        COUNT(*) AS nf_count
     FROM biomie_notas_fiscais_canonicas nf
     WHERE nf.data_competencia BETWEEN :start AND :end
       AND nf.status_fiscal = 'autorizada'
       {$commercialScope}
     GROUP BY nf.codigo_vendedor_omie, nf.nome_vendedor
     ORDER BY canonical_total DESC"
);
$stmt->execute([':start' => $start, ':end' => $end]);
$directRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// Aggregate by resolved vendor_id (identity resolver may merge aliases)
$directMap = [];
foreach ($directRows as $row) {
    $vRef     = trim((string) $row['vendor_id']);
    $identity = $resolver->resolve($vRef !== '' ? $vRef : (string) $row['vendor_name']);
    $key      = $identity['vendor_id'];
    if (!isset($directMap[$key])) {
        $directMap[$key] = [
            'vendor_id'       => $identity['vendor_id'],
            'vendor_name'     => $identity['vendor_name'],
            'canonical_total' => 0.0,
            'nf_count'        => 0,
        ];
    }
    $directMap[$key]['canonical_total'] += (float) $row['canonical_total'];
    $directMap[$key]['nf_count']        += (int)   $row['nf_count'];
}

// ── 4. Construir lista de vendedores a checar ────────────────────────────────
$allKeys = array_unique(array_merge(
    array_keys($vendor360Map),
    array_keys($planMap),
    array_keys($directMap)
));

$THRESH    = 0.01; // diferença máxima tolerada (R$)
$diffs     = [];
$criticals = 0;

foreach ($allKeys as $vid) {
    if ($vendorFilter !== null && $vendorFilter !== '' && $vid !== $vendorFilter) {
        continue;
    }

    $v360  = $vendor360Map[$vid] ?? null;
    $plan  = $planMap[$vid]      ?? null;
    $dir   = $directMap[$vid]    ?? null;

    $name = $v360['vendor_name'] ?? $plan['vendor_name'] ?? $dir['vendor_name'] ?? $vid;

    $canonical_direct  = round((float) ($dir['canonical_total']  ?? 0.0), 2);
    $vendor360_realized = round((float) ($v360['realized']        ?? 0.0), 2);
    $flight_plan_realized = round((float) ($plan['realized_period'] ?? 0.0), 2);

    $diff_vendor360     = round(abs($canonical_direct - $vendor360_realized), 2);
    $diff_flight_plan   = round(abs($canonical_direct - $flight_plan_realized), 2);

    $status = ($diff_vendor360 > $THRESH || $diff_flight_plan > $THRESH) ? 'critical' : 'ok';
    if ($status === 'critical') {
        $criticals++;
    }

    $entry = [
        'vendor_id'             => $vid,
        'vendor_name'           => $name,
        'canonical_direct'      => $canonical_direct,
        'vendor360_realized'    => $vendor360_realized,
        'flight_plan_realized'  => $flight_plan_realized,
        'diff_vendor360'        => $diff_vendor360,
        'diff_flight_plan'      => $diff_flight_plan,
        'nf_count'              => (int) ($dir['nf_count'] ?? 0),
        'status'                => $status,
    ];

    $diffs[] = $entry;
}

// Sort: criticals first, then by canonical_direct desc
usort($diffs, function (array $a, array $b): int {
    if ($a['status'] !== $b['status']) {
        return $a['status'] === 'critical' ? -1 : 1;
    }
    return $b['canonical_direct'] <=> $a['canonical_direct'];
});

$overallStatus = $criticals > 0 ? 'critical' : 'ok';

$result = [
    'status'           => $overallStatus,
    'period'           => ['start' => $start, 'end' => $end],
    'vendors_checked'  => count($diffs),
    'critical_count'   => $criticals,
    'threshold'        => $THRESH,
    'sources'          => [
        'canonical_direct'    => 'biomie_notas_fiscais_canonicas WHERE status_fiscal=autorizada GROUP BY vendor_id / data_competencia BETWEEN',
        'vendor360_realized'  => 'VendorRealizedService::summarizeByVendor()',
        'flight_plan_realized'=> 'FlightPlanV2Service::build() → vendors[].realized_period',
    ],
    'diffs'            => $diffs,
];

if ($jsonOutput) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($criticals > 0 ? 2 : 0);
}

// ── Human-readable output ────────────────────────────────────────────────────
$icon = $overallStatus === 'ok' ? '[OK]' : '[CRITICAL]';
echo sprintf("%s período %s → %s | %d vendedores | %d divergências\n",
    $icon, $start, $end, count($diffs), $criticals);
echo str_repeat('-', 100) . "\n";
printf("%-30s %14s %14s %14s %10s %10s %8s\n",
    'Vendedor', 'Canônico Direto', 'Vendor360', 'Plano de Voo', 'ΔV360', 'ΔPlano', 'Status');
echo str_repeat('-', 100) . "\n";

foreach ($diffs as $d) {
    $flag = $d['status'] === 'critical' ? ' *** DIVERGÊNCIA' : '';
    printf("%-30s %14.2f %14.2f %14.2f %10.2f %10.2f %8s%s\n",
        mb_substr($d['vendor_name'], 0, 29),
        $d['canonical_direct'],
        $d['vendor360_realized'],
        $d['flight_plan_realized'],
        $d['diff_vendor360'],
        $d['diff_flight_plan'],
        $d['status'],
        $flag
    );
}

echo str_repeat('-', 100) . "\n";
echo sprintf("Threshold: R$ %.2f | Fonte canônica: biomie_notas_fiscais_canonicas (data_competencia)\n", $THRESH);

exit($criticals > 0 ? 2 : 0);
