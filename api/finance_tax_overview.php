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

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-t');

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados não configurado.'], 503);
        exit;
    }
    $pdo = Database::connect($config, $conn);

    $svc     = new FiscalTaxExtractorService(static fn () => $pdo);
    $summary = $svc->summarizeTaxes($start, $end);
    $trend   = $svc->taxTrend($start, $end);

    $fmt = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');

    Http::json([
        'ok'     => true,
        'period' => ['start' => $start, 'end' => $end],
        'summary' => [
            'icms'              => $fmt($summary['icms']),
            'pis'               => $fmt($summary['pis']),
            'cofins'            => $fmt($summary['cofins']),
            'ipi'               => $fmt($summary['ipi']),
            'iss'               => $fmt($summary['iss']),
            'trib_total'        => $fmt($summary['trib_total']),
            'faturamento_total' => $fmt($summary['faturamento_total']),
            'carga_tributaria'  => $summary['carga_tributaria'],
            'nfs_com_imposto'   => $summary['nfs_com_imposto'],
            'nfs_sem_imposto'   => $summary['nfs_sem_imposto'],
            'total_nfs'         => $summary['total_nfs'],
            '_icms'             => $summary['icms'],
            '_pis'              => $summary['pis'],
            '_cofins'           => $summary['cofins'],
            '_ipi'              => $summary['ipi'],
            '_iss'              => $summary['iss'],
            '_trib_total'        => $summary['trib_total'],
            '_faturamento_total' => $summary['faturamento_total'],
        ],
        'trend' => array_map(static function (array $row): array {
            return [
                'month'       => $row['month'],
                'label'       => implode('/', array_reverse(explode('-', $row['month']))),
                'icms'        => (float) ($row['icms']        ?? 0),
                'pis'         => (float) ($row['pis']         ?? 0),
                'cofins'      => (float) ($row['cofins']      ?? 0),
                'ipi'         => (float) ($row['ipi']         ?? 0),
                'iss'         => (float) ($row['iss']         ?? 0),
                'trib_total'  => (float) ($row['trib_total']  ?? 0),
                'faturamento' => (float) ($row['faturamento'] ?? 0),
            ];
        }, $trend),
    ]);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'message' => 'Erro ao calcular impostos do período.'], 500);
}
