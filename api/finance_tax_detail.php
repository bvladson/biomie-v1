<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Fiscal\Infrastructure\FiscalTaxExtractorService;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('finance.view', true);

try {
    $start = Http::query('start', date('Y-m-01'));
    $end   = Http::query('end',   date('Y-m-t'));
    $limit = max(10, min(200, (int) Http::query('limit', '50')));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-t');

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados não configurado.'], 503);
        exit;
    }
    $pdo = Database::connect($config, $conn);
    $svc = new FiscalTaxExtractorService(static fn () => $pdo);

    $summary  = $svc->summarizeTaxes($start, $end);
    $trend    = $svc->taxTrend($start, $end);
    $monthly  = $svc->monthlyBreakdown($start, $end);
    $topNFs   = $svc->topNFsByTax($start, $end, $limit);

    $fmt  = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
    $fmt0 = static fn (float $v): string => 'R$ ' . number_format($v, 0, ',', '.');

    Http::json([
        'ok'     => true,
        'period' => ['start' => $start, 'end' => $end],
        'summary' => [
            'icms'               => $fmt($summary['icms']),
            'pis'                => $fmt($summary['pis']),
            'cofins'             => $fmt($summary['cofins']),
            'ipi'                => $fmt($summary['ipi']),
            'iss'                => $fmt($summary['iss']),
            'trib_total'         => $fmt($summary['trib_total']),
            'faturamento_total'  => $fmt($summary['faturamento_total']),
            'carga_tributaria'   => $summary['carga_tributaria'],
            'nfs_com_imposto'    => $summary['nfs_com_imposto'],
            'nfs_sem_imposto'    => $summary['nfs_sem_imposto'],
            'total_nfs'          => $summary['total_nfs'],
            '_icms'              => $summary['icms'],
            '_pis'               => $summary['pis'],
            '_cofins'            => $summary['cofins'],
            '_ipi'               => $summary['ipi'],
            '_iss'               => $summary['iss'],
            '_trib_total'        => $summary['trib_total'],
            '_faturamento_total' => $summary['faturamento_total'],
            '_media_nf'          => $summary['nfs_com_imposto'] > 0
                ? round($summary['trib_total'] / $summary['nfs_com_imposto'], 2)
                : 0.0,
        ],
        'trend' => array_map(static function (array $r): array {
            return [
                'month'       => $r['month'],
                'label'       => $r['label'],
                'icms'        => (float) ($r['icms']        ?? 0),
                'pis'         => (float) ($r['pis']         ?? 0),
                'cofins'      => (float) ($r['cofins']      ?? 0),
                'ipi'         => (float) ($r['ipi']         ?? 0),
                'iss'         => (float) ($r['iss']         ?? 0),
                'trib_total'  => (float) ($r['trib_total']  ?? 0),
                'faturamento' => (float) ($r['faturamento'] ?? 0),
            ];
        }, $trend),
        'monthly' => array_map(static function (array $r) use ($fmt, $fmt0): array {
            return [
                'month'      => $r['month'],
                'label'      => $r['label'],
                'total_nfs'  => (int)   ($r['total_nfs']  ?? 0),
                'faturamento'=> $fmt0((float) ($r['faturamento'] ?? 0)),
                'icms'       => $fmt((float) ($r['icms']        ?? 0)),
                'pis'        => $fmt((float) ($r['pis']         ?? 0)),
                'cofins'     => $fmt((float) ($r['cofins']      ?? 0)),
                'ipi'        => $fmt((float) ($r['ipi']         ?? 0)),
                'iss'        => $fmt((float) ($r['iss']         ?? 0)),
                'trib_total' => $fmt((float) ($r['trib_total']  ?? 0)),
                'carga_pct'  => round((float) ($r['carga_pct'] ?? 0), 1),
                '_fat'       => (float) ($r['faturamento'] ?? 0),
                '_trib'      => (float) ($r['trib_total']  ?? 0),
                '_icms'      => (float) ($r['icms']        ?? 0),
            ];
        }, $monthly),
        'top_nfs' => array_map(static function (array $r) use ($fmt): array {
            return [
                'numero_nf'        => (string) ($r['numero_nf']        ?? '-'),
                'data_emissao'     => (string) ($r['data_emissao']     ?? '-'),
                'nome_cliente'     => (string) ($r['nome_cliente']     ?? '-'),
                'nome_vendedor'    => (string) ($r['nome_vendedor']    ?? '-'),
                'valor_total'      => $fmt((float) ($r['valor_total']      ?? 0)),
                'valor_trib_total' => $fmt((float) ($r['valor_trib_total'] ?? 0)),
                'valor_icms'       => $fmt((float) ($r['valor_icms']       ?? 0)),
                'valor_pis'        => $fmt((float) ($r['valor_pis']        ?? 0)),
                'valor_cofins'     => $fmt((float) ($r['valor_cofins']     ?? 0)),
                'valor_ipi'        => $fmt((float) ($r['valor_ipi']        ?? 0)),
                'carga_pct'        => (float) ($r['carga_pct'] ?? 0),
                '_trib'            => (float) ($r['valor_trib_total'] ?? 0),
            ];
        }, $topNFs),
    ]);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'message' => 'Erro ao carregar detalhamento tributário.'], 500);
}
