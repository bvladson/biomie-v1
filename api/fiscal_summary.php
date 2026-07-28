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
    $cacheKey = 'fiscal_summary_v3_' . $start . '_' . $end;

    if (!$force) {
        $cached = $cache->get($cacheKey, 1800); // 30 min
        if ($cached !== null) {
            Http::json($cached);
        }
    }

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $svc    = new FiscalIntelligenceService(static fn () => $pdo);

    $fmt  = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
    $fmt0 = static fn (float $v): string => 'R$ ' . number_format($v, 0, ',', '.');

    $raw     = $svc->executiveSummary($start, $end);
    $trend   = $svc->monthlyTrend($start, $end);
    $vendors = $svc->vendorRanking($start, $end);
    $mart    = $svc->martStats();

    $fat   = (float) ($raw['faturamento'] ?? 0);
    $trib  = (float) ($raw['trib_total']  ?? 0);
    $cbs   = (float) ($raw['cbs']         ?? 0);
    $ibs   = (float) ($raw['ibs']         ?? 0);
    $receitaLiquida = max(0.0, $fat - $trib);
    $alertCount = $svc->alertedInvoiceCount($start, $end);
    $authorizedCount = max(1, (int) ($raw['autorizadas'] ?? 0));
    $riskScore = min(100, round(($alertCount / $authorizedCount) * 100, 1));

    // Formatar trend
    $maxFat = max(1.0, ...array_map(static fn ($r) => (float) ($r['faturamento'] ?? 0), $trend ?: [[]]));
    $trendFmt = array_map(static function (array $r) use ($fmt0, $fmt): array {
        [$y, $m] = explode('-', $r['month'] . '-01');
        $meses = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
        return [
            'month'      => $r['month'],
            'label'      => ($meses[(int) $m] ?? $m) . '/' . substr($y, 2),
            'faturamento'=> (float) ($r['faturamento'] ?? 0),
            'icms'       => (float) ($r['icms']        ?? 0),
            'pis'        => (float) ($r['pis']         ?? 0),
            'cofins'     => (float) ($r['cofins']       ?? 0),
            'ipi'        => (float) ($r['ipi']         ?? 0),
            'trib_total' => (float) ($r['trib_total']  ?? 0),
            'carga_pct'  => (float) ($r['carga_pct']   ?? 0),
            '_f'         => $fmt0((float) ($r['faturamento'] ?? 0)),
            '_t'         => $fmt((float) ($r['trib_total'] ?? 0)),
        ];
    }, $trend);

    // Top 6 vendors
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

    $payload = [
        'ok'     => true,
        'period' => ['start' => $start, 'end' => $end],
        'mart'   => $mart,
        'kpis'   => [
            'faturamento'  => $fmt($fat),
            'trib_total'   => $fmt($trib),
            'receita_liquida_fiscal' => $fmt($receitaLiquida),
            'icms'         => $fmt((float) ($raw['icms']   ?? 0)),
            'pis_cofins'   => $fmt((float) ($raw['pis'] ?? 0) + (float) ($raw['cofins'] ?? 0)),
            'ipi'          => $fmt((float) ($raw['ipi']    ?? 0)),
            'carga_pct'    => round((float) ($raw['carga_pct'] ?? 0), 2),
            'max_carga_pct'=> round((float) ($raw['max_carga_pct'] ?? 0), 2),
            'media_trib_nf'=> $fmt((float) ($raw['media_trib_nf'] ?? 0)),
            'total_nfs'    => (int) ($raw['total_nfs'] ?? 0),
            'autorizadas'   => (int) ($raw['autorizadas'] ?? 0),
            'canceladas'   => (int) ($raw['canceladas'] ?? 0),
            'alertas_total' => $alertCount,
            'sem_ncm'       => (int) ($raw['sem_ncm'] ?? 0),
            'xml_pendente'  => (int) ($raw['xml_pendente'] ?? 0),
            'sem_titulo'    => (int) ($raw['sem_titulo'] ?? 0),
            'cbs_ibs_zero'  => (int) ($raw['cbs_ibs_zero'] ?? 0),
            'valor_sem_titulo' => $fmt((float) ($raw['valor_sem_titulo'] ?? 0)),
            'cbs_info'     => $fmt($cbs),
            'ibs_info'     => $fmt($ibs),
            'cbs_ibs_info' => $fmt($cbs + $ibs),
            '_fat'         => $fat,
            '_trib'        => $trib,
            '_receita_liquida_fiscal' => $receitaLiquida,
            '_icms'        => (float) ($raw['icms']   ?? 0),
            '_pis'         => (float) ($raw['pis']    ?? 0),
            '_cofins'      => (float) ($raw['cofins'] ?? 0),
            '_ipi'         => (float) ($raw['ipi']    ?? 0),
            '_iss'          => (float) ($raw['iss']    ?? 0),
            '_cbs'         => $cbs,
            '_ibs'         => $ibs,
            '_valor_sem_titulo' => (float) ($raw['valor_sem_titulo'] ?? 0),
        ],
        'alerts' => [
            'icms_zero'  => (int) ($raw['alerta_icms_zero'] ?? 0),
            'canceladas' => (int) ($raw['canceladas']       ?? 0),
            'sem_titulo' => (int) ($raw['sem_titulo']       ?? 0),
            'sem_ncm'    => (int) ($raw['sem_ncm']          ?? 0),
            'xml_pendente' => (int) ($raw['xml_pendente']   ?? 0),
            'cbs_ibs_zero' => (int) ($raw['cbs_ibs_zero']   ?? 0),
        ],
        'intelligence' => [
            'notas_analisadas' => (int) ($raw['total_nfs'] ?? 0),
            'notas_com_alerta' => $alertCount,
            'xmls_pendentes'   => (int) ($raw['xml_pendente'] ?? 0),
            'carga_media_nf'   => round((float) ($raw['carga_pct'] ?? 0), 2),
            'maior_carga_fiscal' => round((float) ($raw['max_carga_pct'] ?? 0), 2),
            'divergencia_fiscal_financeiro' => $fmt((float) ($raw['valor_sem_titulo'] ?? 0)),
            '_divergencia_fiscal_financeiro' => (float) ($raw['valor_sem_titulo'] ?? 0),
            'score_risco_fiscal' => $riskScore,
        ],
        'dre' => [
            'receita_bruta' => $fmt($fat),
            'tributos' => $fmt($trib),
            'receita_liquida_fiscal' => $fmt($receitaLiquida),
            'carga_pct' => round((float) ($raw['carga_pct'] ?? 0), 2),
            '_receita_bruta' => $fat,
            '_tributos' => $trib,
            '_receita_liquida_fiscal' => $receitaLiquida,
        ],
        'trend'   => $trendFmt,
        'vendors' => $vendorsFmt,
    ];

    $cache->put($cacheKey, $payload);
    Http::json($payload);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'message' => 'Erro ao carregar resumo fiscal.'], 500);
}
