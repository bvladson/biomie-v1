<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Cache;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Fiscal\Infrastructure\FiscalIntelligenceService;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.view', 'finance.view'], true);

try {
    $start = Http::query('start', date('Y-01-01'));
    $end   = Http::query('end',   date('Y-m-t'));
    $force = Http::query('force', '0') === '1';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-01-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-t');

    $cache    = new Cache(__DIR__ . '/../storage/cache');
    $cacheKey = 'fiscal_reform_v2_' . $start . '_' . $end;

    if (!$force) {
        $cached = $cache->get($cacheKey, 3600); // 1h — dados históricos estáveis
        if ($cached !== null) Http::json($cached);
    }

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $svc    = new FiscalIntelligenceService(static fn () => $pdo);

    $raw = $svc->reformSimulation($start, $end);

    if (!$raw['has_mart']) {
        Http::json(['ok' => true, 'has_mart' => false, 'message' => 'Mart fiscal não construído. Execute fiscal_build_mart.php.']);
    }

    $fmt  = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
    $fmt0 = static fn (float $v): string => 'R$ ' . number_format($v, 0, ',', '.');

    $tot = $raw['totals'];
    $cargatual = (float) ($tot['carga_atual'] ?? 0);
    $cbs       = (float) ($tot['cbs']         ?? 0);
    $ibs       = (float) ($tot['ibs']         ?? 0);
    $cargaNova = $cbs + $ibs;
    $fat       = (float) ($tot['faturamento'] ?? 0);

    $meses = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

    $trendFmt = array_map(static function (array $r) use ($fmt0, $fmt, $meses): array {
        [$y, $m] = explode('-', $r['month'] . '-01');
        $carga = (float) ($r['carga_atual'] ?? 0);
        $nova  = (float) ($r['cbs'] ?? 0) + (float) ($r['ibs'] ?? 0);
        return [
            'month'       => $r['month'],
            'label'       => ($meses[(int) $m] ?? $m) . '/' . substr($y, 2),
            'carga_atual' => $carga,
            'cbs'         => (float) ($r['cbs'] ?? 0),
            'ibs'         => (float) ($r['ibs'] ?? 0),
            'carga_nova'  => $nova,
            'fat'         => (float) ($r['faturamento'] ?? 0),
            '_ca_fmt'     => $fmt($carga),
            '_cn_fmt'     => $fmt($nova),
        ];
    }, $raw['trend']);

    $ncmFmt = array_map(static fn ($r) => [
        'ncm'         => $r['ncm'] ?? '-',
        'produto'     => $r['produto'] ?? '-',
        'cbs'         => $fmt((float) $r['cbs']),
        'ibs'         => $fmt((float) $r['ibs']),
        'carga_atual' => $fmt((float) $r['carga_atual']),
        'faturamento' => $fmt((float) $r['faturamento']),
        '_cbs'        => (float) $r['cbs'],
        '_carga'      => (float) $r['carga_atual'],
    ], $raw['ncm_rank']);

    $payload = [
        'ok'       => true,
        'has_mart' => true,
        'period'   => ['start' => $start, 'end' => $end],
        'totals'   => [
            'faturamento'     => $fmt($fat),
            'carga_atual'     => $fmt($cargatual),
            'cbs_info'        => $fmt($cbs),
            'ibs_info'        => $fmt($ibs),
            'carga_nova_info' => $fmt($cargaNova),
            'reducao_absoluta'=> $fmt($cargatual - $cargaNova),
            'reducao_pct'     => $cargatual > 0 ? round(($cargatual - $cargaNova) / $cargatual * 100, 1) : 0,
            'carga_atual_pct' => $fat > 0 ? round($cargatual / $fat * 100, 2) : 0,
            'carga_nova_pct'  => $fat > 0 ? round($cargaNova  / $fat * 100, 2) : 0,
            'aliq_cbs_media'  => round((float) ($tot['aliq_cbs_media'] ?? 0), 2),
            'aliq_ibs_media'  => round((float) ($tot['aliq_ibs_media'] ?? 0), 2),
            'total_nfs'       => (int) ($tot['total_nfs'] ?? 0),
            '_fat'            => $fat,
            '_atual'          => $cargatual,
            '_nova'           => $cargaNova,
            '_cbs'            => $cbs,
            '_ibs'            => $ibs,
        ],
        'trend'    => $trendFmt,
        'ncm_rank' => $ncmFmt,
    ];

    $cache->put($cacheKey, $payload);
    Http::json($payload);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'message' => 'Erro ao carregar simulação de reforma.'], 500);
}
