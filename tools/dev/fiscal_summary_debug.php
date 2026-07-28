<?php
/**
 * Debug: executa fiscal_summary sem cache e sem try-catch para expor erros reais
 * CLI only
 */
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Database;
use BIomie\Modules\Fiscal\Infrastructure\FiscalIntelligenceService;

$config = new \BIomie\Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
echo "Usando conexão: $conn\n";
$pdo = Database::connect($config, $conn);
$svc = new FiscalIntelligenceService(static fn () => $pdo);

$start = '2026-01-01';
$end   = '2026-12-31';

echo "1. executiveSummary...\n";
$raw = $svc->executiveSummary($start, $end);
echo "   total_nfs: " . ($raw['total_nfs'] ?? 'null') . "\n";
echo "   faturamento: " . ($raw['faturamento'] ?? 'null') . "\n";

echo "2. monthlyTrend...\n";
$trend = $svc->monthlyTrend($start, $end);
echo "   meses: " . count($trend) . "\n";

echo "3. vendorRanking...\n";
$vendors = $svc->vendorRanking($start, $end);
echo "   vendedores: " . count($vendors) . "\n";

echo "4. martStats...\n";
$mart = $svc->martStats();
echo "   rows: " . ($mart['rows'] ?? 0) . "\n";

echo "5. Construindo payload...\n";
$fmt  = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$fmt0 = static fn (float $v): string => 'R$ ' . number_format($v, 0, ',', '.');

$fat  = (float) ($raw['faturamento'] ?? 0);
$trib = (float) ($raw['trib_total']  ?? 0);
$cbs  = (float) ($raw['cbs']         ?? 0);
$ibs  = (float) ($raw['ibs']         ?? 0);

// maxFat — onde o spread pode dar erro
$mapResult = array_map(static fn ($r) => (float) ($r['faturamento'] ?? 0), $trend ?: [[]]);
echo "   mapResult count: " . count($mapResult) . "\n";
$maxFat = max(1.0, ...$mapResult);
echo "   maxFat: $maxFat\n";

// trendFmt
$trendFmt = array_map(static function (array $r) use ($fmt0, $fmt): array {
    [$y, $m] = explode('-', $r['month'] . '-01');
    $meses = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    return [
        'month'      => $r['month'],
        'label'      => ($meses[(int) $m] ?? $m) . '/' . substr($y, 2),
        'faturamento'=> (float) ($r['faturamento'] ?? 0),
        'trib_total' => (float) ($r['trib_total']  ?? 0),
        'carga_pct'  => (float) ($r['carga_pct']   ?? 0),
        '_f'         => $fmt0((float) ($r['faturamento'] ?? 0)),
        '_t'         => $fmt((float) ($r['trib_total'] ?? 0)),
    ];
}, $trend);
echo "   trendFmt count: " . count($trendFmt) . "\n";

// vendors
$vendorsFmt = array_slice(array_map(static function (array $v) use ($fmt): array {
    return [
        'nome'       => $v['nome_vendedor'],
        'nfs'        => (int) $v['total_nfs'],
        'faturamento'=> $fmt((float) $v['faturamento']),
        'trib_total' => $fmt((float) $v['trib_total']),
        'carga_pct'  => (float) $v['carga_pct'],
        '_fat'       => (float) $v['faturamento'],
        '_trib'      => (float) $v['trib_total'],
    ];
}, $vendors), 0, 8);
echo "   vendorsFmt count: " . count($vendorsFmt) . "\n";

$payload = [
    'ok'     => true,
    'period' => ['start' => $start, 'end' => $end],
    'mart'   => $mart,
    'kpis'   => [
        'faturamento' => $fmt($fat),
        'trib_total'  => $fmt($trib),
        'carga_pct'   => round((float) ($raw['carga_pct'] ?? 0), 2),
        'total_nfs'   => (int) ($raw['total_nfs'] ?? 0),
    ],
    'trend'   => $trendFmt,
    'vendors' => $vendorsFmt,
];

$json = json_encode($payload);
echo "6. JSON encode OK, length: " . strlen($json) . "\n";
echo "\nSUCESSO — o payload parece correto!\n";
