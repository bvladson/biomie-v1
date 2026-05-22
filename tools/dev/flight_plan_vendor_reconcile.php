<?php

declare(strict_types=1);

/**
 * flight_plan_vendor_reconcile.php — Reconcilia Plano de Voo x Vendor 360 x NFs Canonicas.
 *
 * Compara, por vendedor:
 *   - Realizado no Plano de Voo (FlightPlanV2Service via canonical)
 *   - Realizado no Vendor 360 (VendorRealizedService)
 *   - NFs autorizadas diretamente (canonical sum)
 *
 * Identifica divergências: vendedores diferentes entre fontes, valores inconsistentes.
 *
 * Uso:
 *   php tools/dev/flight_plan_vendor_reconcile.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]
 *                                                  [--threshold=5] [--json]
 */

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\RequestContext;
use BIomie\Support;
use BIomie\TargetsRepository;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root   = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

$options   = getopt('', ['start:', 'end:', 'threshold:', 'json', 'vendor:']);
$start     = (string) ($options['start']     ?? date('Y-m-01'));
$end       = (string) ($options['end']       ?? date('Y-m-t'));
$threshold = max(0.0, (float) ($options['threshold'] ?? 5.0));
$isJson    = isset($options['json']);
$vendorFilter = isset($options['vendor']) ? (string) $options['vendor'] : null;

$requestId = RequestContext::id();

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Nenhuma conexao MySQL configurada.\n");
    exit(1);
}

try {
    $pdo = Database::connect($config, $connection);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO ao conectar: ' . $e->getMessage() . "\n");
    exit(1);
}

$canonical  = new CanonicalRepository($pdo, $logger);
$targets    = new TargetsRepository($pdo, $logger);
$canonicalAvail = $canonical->isAvailable();

$result = [
    'request_id'        => $requestId,
    'source'            => 'flight_plan_vendor_reconcile',
    'period'            => ['start' => $start, 'end' => $end],
    'vendor_filter'     => $vendorFilter,
    'divergence_threshold_pct' => $threshold,
];

if (!$canonicalAvail) {
    $result['reason_code'] = 'canonical_unavailable';
    if ($isJson) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, "ERRO: canonical indisponivel.\n");
    }
    exit(0);
}

// ── Fonte A: Plano de Voo canonical (invoiced rows por vendedor) ──────────────

$invoicedRows = $canonical->aggregateInvoicedByVendorCommercial($start, $end);
$pipelineRows = $canonical->aggregateOrderPipelineByVendorCommercial($start, $end);

$flightPlanByVendor = [];
foreach ($invoicedRows as $row) {
    $vid   = trim((string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
    $vname = trim((string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? 'Sem vendedor'));
    if ($vendorFilter !== null && $vid !== $vendorFilter) {
        continue;
    }
    $key = $vid !== '' ? $vid : 'name:' . mb_strtolower($vname);
    $flightPlanByVendor[$key] = [
        'vendor_id'        => $vid,
        'vendor_name'      => $vname !== '' ? $vname : 'Sem vendedor',
        'fp_realized'      => ($flightPlanByVendor[$key]['fp_realized'] ?? 0.0) + Support::toFloat($row['invoice_total'] ?? $row['merchandise_total'] ?? 0),
        'fp_orders'        => ($flightPlanByVendor[$key]['fp_orders'] ?? 0) + (int) ($row['notes_count'] ?? $row['pedidos'] ?? 0),
    ];
}

$flightPlanPipelineByVendor = [];
foreach ($pipelineRows as $row) {
    $vid   = trim((string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
    $vname = trim((string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? 'Sem vendedor'));
    if ($vendorFilter !== null && $vid !== $vendorFilter) {
        continue;
    }
    $key = $vid !== '' ? $vid : 'name:' . mb_strtolower($vname);
    $flightPlanPipelineByVendor[$key] = ($flightPlanPipelineByVendor[$key] ?? 0.0)
        + Support::toFloat($row['order_total'] ?? $row['merchandise_total'] ?? 0);
}

// ── Fonte B: Vendor 360 / VendorRealizedService ────────────────────────────────

$vendor360ByVendor = [];
try {
    require_once dirname(__DIR__, 2) . '/app/Modules/Sales/Application/Vendors/VendorIdentityResolver.php';
    require_once dirname(__DIR__, 2) . '/app/Modules/Sales/Application/Vendors/VendorRealizedService.php';

    $vendorMapCanonical = $canonical->getVendorMap();
    $resolver     = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMapCanonical);
    $realizedSvc  = new \BIomie\Modules\Sales\Application\Vendors\VendorRealizedService($canonical, $resolver);
    $officialRows = $realizedSvc->summarizeByVendor($start, $end);

    foreach ($officialRows as $row) {
        $vid   = trim((string) ($row['vendor_id'] ?? ''));
        $vname = trim((string) ($row['vendor_name'] ?? 'Sem vendedor'));
        if ($vendorFilter !== null && $vid !== $vendorFilter) {
            continue;
        }
        $key = $vid !== '' ? $vid : 'name:' . mb_strtolower($vname);
        $vendor360ByVendor[$key] = [
            'vendor_id'        => $vid,
            'vendor_name'      => $vname !== '' ? $vname : 'Sem vendedor',
            'v360_realized'    => Support::toFloat($row['realized'] ?? $row['invoice_total'] ?? 0),
            'v360_orders'      => (int) ($row['orders'] ?? $row['invoices_count'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    $result['vendor_360_error'] = $e->getMessage();
}

// ── Fonte C: NFs canonicas diretas (sum por vendedor) ────────────────────────

$nfDirectByVendor = [];
try {
    $summaryStmt = $pdo->prepare(
        "SELECT
             COALESCE(codigo_vendedor_omie, '') AS vendor_id,
             COALESCE(nome_vendedor, 'Sem vendedor') AS vendor_name,
             SUM(valor_total_nf) AS total_nf,
             COUNT(*) AS nf_count
         FROM biomie_notas_fiscais_canonicas
         WHERE status_fiscal = 'autorizada'
           AND data_competencia BETWEEN :start AND :end
         GROUP BY codigo_vendedor_omie, nome_vendedor
         ORDER BY total_nf DESC"
    );
    $summaryStmt->execute(['start' => $start, 'end' => $end]);

    foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $vid   = trim((string) ($row['vendor_id'] ?? ''));
        $vname = trim((string) ($row['vendor_name'] ?? 'Sem vendedor'));
        if ($vendorFilter !== null && $vid !== $vendorFilter) {
            continue;
        }
        $key = $vid !== '' ? $vid : 'name:' . mb_strtolower($vname);
        $nfDirectByVendor[$key] = [
            'vendor_id'   => $vid,
            'vendor_name' => $vname !== '' ? $vname : 'Sem vendedor',
            'nf_total'    => (float) ($row['total_nf'] ?? 0),
            'nf_count'    => (int) ($row['nf_count'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    $result['nf_direct_error'] = $e->getMessage();
}

// ── Metas por vendedor ────────────────────────────────────────────────────────

$targetByVendor = [];
if ($targets->isAvailable()) {
    $monthsInRange = [];
    $cursor = new DateTimeImmutable(substr($start, 0, 7) . '-01');
    $last   = new DateTimeImmutable(substr($end, 0, 7) . '-01');
    while ($cursor <= $last) {
        $monthsInRange[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }
    $targetsMap = $targets->getTargetsForMonths($monthsInRange, $vendorFilter);
    foreach ($targetsMap as $row) {
        $vid = trim((string) ($row['vendor_id'] ?? ''));
        $targetByVendor[$vid] = ($targetByVendor[$vid] ?? 0.0) + (float) ($row['target_amount'] ?? 0);
    }
}

// ── Reconciliação ──────────────────────────────────────────────────────────────

$allKeys = array_unique(array_merge(
    array_keys($flightPlanByVendor),
    array_keys($vendor360ByVendor),
    array_keys($nfDirectByVendor)
));

$reconcile = [];
$divergences = [];

foreach ($allKeys as $key) {
    $fpRow    = $flightPlanByVendor[$key]   ?? null;
    $v360Row  = $vendor360ByVendor[$key]    ?? null;
    $nfRow    = $nfDirectByVendor[$key]     ?? null;

    $vid   = (string) ($fpRow['vendor_id'] ?? $v360Row['vendor_id'] ?? $nfRow['vendor_id'] ?? $key);
    $vname = (string) ($fpRow['vendor_name'] ?? $v360Row['vendor_name'] ?? $nfRow['vendor_name'] ?? $key);

    $fpRealized  = (float) ($fpRow['fp_realized']   ?? 0.0);
    $v360Realized= (float) ($v360Row['v360_realized']?? 0.0);
    $nfTotal     = (float) ($nfRow['nf_total']       ?? 0.0);
    $target      = (float) ($targetByVendor[$vid]    ?? 0.0);
    $fpPipeline  = (float) ($flightPlanPipelineByVendor[$key] ?? 0.0);

    $onlyInFp    = $fpRealized > 0.0 && $v360Realized <= 0.0 && $nfTotal <= 0.0;
    $onlyInV360  = $fpRealized <= 0.0 && $v360Realized > 0.0 && $nfTotal <= 0.0;
    $onlyInNf    = $fpRealized <= 0.0 && $v360Realized <= 0.0 && $nfTotal > 0.0;

    // Divergence: percentage difference between fp and nf_direct
    $fpVsNfDivPct = null;
    if ($nfTotal > 0.0) {
        $fpVsNfDivPct = round(abs($fpRealized - $nfTotal) / $nfTotal * 100, 2);
    }

    $fpVsV360DivPct = null;
    if ($v360Realized > 0.0) {
        $fpVsV360DivPct = round(abs($fpRealized - $v360Realized) / $v360Realized * 100, 2);
    }

    $hasDivergence = ($fpVsNfDivPct !== null && $fpVsNfDivPct > $threshold)
                  || ($fpVsV360DivPct !== null && $fpVsV360DivPct > $threshold)
                  || $onlyInFp || $onlyInV360 || $onlyInNf;

    $row = [
        'vendor_key'        => $key,
        'vendor_id'         => $vid,
        'vendor_name'       => $vname,
        'fp_realized'       => round($fpRealized, 2),
        'fp_pipeline'       => round($fpPipeline, 2),
        'v360_realized'     => round($v360Realized, 2),
        'nf_direct_total'   => round($nfTotal, 2),
        'target'            => round($target, 2),
        'fp_vs_nf_div_pct'  => $fpVsNfDivPct,
        'fp_vs_v360_div_pct'=> $fpVsV360DivPct,
        'attainment_pct'    => $target > 0 ? round($fpRealized / $target * 100, 2) : null,
        'only_in_fp'        => $onlyInFp,
        'only_in_v360'      => $onlyInV360,
        'only_in_nf'        => $onlyInNf,
        'has_divergence'    => $hasDivergence,
    ];

    $reconcile[] = $row;
    if ($hasDivergence) {
        $divergences[] = $row;
    }
}

usort($reconcile, static fn (array $a, array $b): int => $b['fp_realized'] <=> $a['fp_realized']);
usort($divergences, static fn (array $a, array $b): int =>
    (float) ($b['fp_vs_nf_div_pct'] ?? 0) <=> (float) ($a['fp_vs_nf_div_pct'] ?? 0));

$teamFpRealized  = array_sum(array_column($reconcile, 'fp_realized'));
$teamV360Realized = array_sum(array_column($reconcile, 'v360_realized'));
$teamNfTotal     = array_sum(array_column($reconcile, 'nf_direct_total'));
$teamTarget      = array_sum(array_column($reconcile, 'target'));

$result['summary'] = [
    'vendors_total'          => count($reconcile),
    'vendors_with_divergence'=> count($divergences),
    'team_fp_realized'       => round($teamFpRealized, 2),
    'team_v360_realized'     => round($teamV360Realized, 2),
    'team_nf_direct'         => round($teamNfTotal, 2),
    'team_target'            => round($teamTarget, 2),
    'team_attainment_pct'    => $teamTarget > 0 ? round($teamFpRealized / $teamTarget * 100, 2) : null,
    'fp_vs_nf_delta'         => round(abs($teamFpRealized - $teamNfTotal), 2),
    'fp_vs_v360_delta'       => round(abs($teamFpRealized - $teamV360Realized), 2),
    'reason_code'            => count($divergences) > 0 ? 'divergences_found_above_threshold' : null,
];
$result['divergences']  = $divergences;
$result['all_vendors']  = $reconcile;

// ── Output ────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$money = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$line  = static fn (string $label, mixed $value): string =>
    sprintf("%-48s %s\n", $label . ':', is_bool($value) ? ($value ? 'sim' : 'nao') : (string) ($value ?? '-'));

echo "\n=== Plano de Voo x Vendor 360 x NFs Canonicas — Reconciliacao ===\n";
echo "Request ID        : {$requestId}\n";
echo "Periodo           : {$start} ate {$end}\n";
echo "Threshold div     : {$threshold}%\n\n";

$s = $result['summary'];
echo "--- Resumo ---\n";
echo $line('Vendedores total', $s['vendors_total']);
echo $line('Com divergencia', $s['vendors_with_divergence']);
echo $line('Equipe FP realizado', $money($s['team_fp_realized']));
echo $line('Equipe V360 realizado', $money($s['team_v360_realized']));
echo $line('Equipe NF direta', $money($s['team_nf_direct']));
echo $line('Equipe Meta', $money($s['team_target']));
echo $line('Atingimento', ($s['team_attainment_pct'] ?? '-') . '%');
echo $line('Delta FP x NF', $money($s['fp_vs_nf_delta']));
echo $line('Delta FP x V360', $money($s['fp_vs_v360_delta']));
if (($s['reason_code'] ?? null) !== null) {
    echo $line('[reason_code]', $s['reason_code']);
}
echo "\n";

if (!empty($result['divergences'])) {
    echo "--- Divergencias (threshold > {$threshold}%) ---\n";
    foreach ($result['divergences'] as $d) {
        echo sprintf(
            "  %-28s  FP=%s  V360=%s  NF=%s  div_NF=%s%%  div_V360=%s%%\n",
            substr((string) $d['vendor_name'], 0, 28),
            $money($d['fp_realized']),
            $money($d['v360_realized']),
            $money($d['nf_direct_total']),
            $d['fp_vs_nf_div_pct'] ?? '-',
            $d['fp_vs_v360_div_pct'] ?? '-'
        );
        if ($d['only_in_fp'])   echo "    -> Apenas no FP (ausente em V360 e NF direta)\n";
        if ($d['only_in_v360']) echo "    -> Apenas no V360 (ausente em FP e NF direta)\n";
        if ($d['only_in_nf'])   echo "    -> Apenas em NF direta (ausente em FP e V360)\n";
    }
    echo "\n";
}

echo "--- Todos os Vendedores ---\n";
echo sprintf("%-28s  %-14s  %-14s  %-14s  %-8s  %s\n",
    'Vendedor', 'FP Real', 'V360 Real', 'NF Direta', 'Meta', 'Ating');
echo str_repeat('-', 100) . "\n";
foreach ($result['all_vendors'] as $v) {
    $flag = $v['has_divergence'] ? ' [DIV]' : '';
    echo sprintf("%-28s  %-14s  %-14s  %-14s  %-8s  %s%s\n",
        substr((string) $v['vendor_name'], 0, 28),
        $money($v['fp_realized']),
        $money($v['v360_realized']),
        $money($v['nf_direct_total']),
        $money($v['target']),
        $v['attainment_pct'] !== null ? $v['attainment_pct'] . '%' : '-',
        $flag
    );
}

echo "\nUso: php tools/dev/flight_plan_vendor_reconcile.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]\n";
echo "     [--threshold=5] [--vendor=ID] [--json]\n\n";
