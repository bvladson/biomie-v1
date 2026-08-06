<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Cache;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Fiscal\Infrastructure\FiscalIntelligenceService;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.view', 'finance.view'], true);

try {
    $start  = Http::query('start',  date('Y-01-01'));
    $end    = Http::query('end',    date('Y-m-t'));
    $ncm    = trim(Http::query('ncm', ''));
    $force  = Http::query('force', '0') === '1';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-01-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-t');

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $svc    = new FiscalIntelligenceService(static fn () => $pdo);

    $fmt = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
    $cfopDesc = static function (string $cfop): string {
        $map = [
            '5101' => 'Venda de produção do estabelecimento',
            '5102' => 'Venda de mercadoria adquirida de terceiros',
            '5103' => 'Venda de produção efetuada fora do estabelecimento',
            '5403' => 'Venda com substituição tributária',
            '5910' => 'Bonificação, doação ou brinde',
            '5915' => 'Remessa de mercadoria',
            '5916' => 'Retorno de mercadoria',
            '5922' => 'Lançamento por simples faturamento',
            '6101' => 'Venda interestadual de produção do estabelecimento',
            '6116' => 'Venda interestadual originada de encomenda',
            '6201' => 'Devolução interestadual de compra para industrialização',
            '6202' => 'Devolução interestadual de compra para comercialização',
        ];
        return $map[preg_replace('/\D/', '', $cfop) ?: $cfop] ?? 'Operação fiscal';
    };

    // NCM detail mode
    if ($ncm !== '') {
        $products = $svc->ncmProducts($ncm, $start, $end);
        $cfops    = $svc->cfopDistribution($start, $end);

        Http::json([
            'ok'       => true,
            'ncm'      => $ncm,
            'products' => array_map(static fn ($p) => [
                'codigo'    => $p['codigo_produto'] ?? '-',
                'descricao' => $p['descricao_produto'] ?? '-',
                'quantidade'=> number_format((float) $p['quantidade'], 0, ',', '.'),
                'faturamento'=> $fmt((float) $p['faturamento']),
                'icms'      => $fmt((float) $p['icms']),
                'trib_total'=> $fmt((float) $p['trib_total']),
                'carga_pct' => (float) $p['carga_pct'],
                'aliq_icms' => (float) $p['aliq_icms_media'],
                '_fat'      => (float) $p['faturamento'],
                '_trib'     => (float) $p['trib_total'],
            ], $products),
        ]);
    }

    // Ranking mode
    $cache    = new Cache(__DIR__ . '/../storage/cache');
    // Cache fiscal nunca pode atravessar empresa. A chave antiga misturava
    // MAG_FILIAL e ERGOOFFICE para o mesmo periodo.
    $cacheKey = 'fiscal_ncm_v3_company_' . TenantContext::companyId() . '_' . $start . '_' . $end;
    if (!$force) {
        $cached = $cache->get($cacheKey, 3600); // 1h
        if ($cached !== null) Http::json($cached);
    }

    $ranking = $svc->ncmRanking($start, $end, 40);
    $cfops   = $svc->cfopDistribution($start, $end);

    $maxTrib = max(1.0, ...array_map(static fn ($r) => (float) ($r['trib_total'] ?? 0), $ranking ?: [[]]));

    $rankFmt = array_map(static function (array $r) use ($fmt, $maxTrib): array {
        $trib = (float) ($r['trib_total'] ?? 0);
        return [
            'ncm'         => $r['ncm'],
            'produto'     => $r['produto_exemplo'] ?? '-',
            'nfs'         => (int) $r['total_nfs'],
            'faturamento' => $fmt((float) $r['faturamento']),
            'icms'        => $fmt((float) $r['icms']),
            'pis'         => $fmt((float) $r['pis']),
            'cofins'      => $fmt((float) $r['cofins']),
            'ipi'         => $fmt((float) $r['ipi']),
            'trib_total'  => $fmt($trib),
            'carga_pct'   => (float) $r['carga_pct'],
            'aliq_icms'   => round((float) $r['aliq_icms_media'], 1),
            'principal_cfop' => (string) ($r['principal_cfop'] ?? ''),
            'principal_cfop_desc' => $cfopDesc((string) ($r['principal_cfop'] ?? '')),
            'bar_w'       => $maxTrib > 0 ? round($trib / $maxTrib * 100) : 0,
            'cbs'         => $fmt((float) $r['cbs_total']),
            'ibs'         => $fmt((float) $r['ibs_total']),
            '_fat'        => (float) $r['faturamento'],
            '_trib'       => $trib,
            '_cbs'        => (float) $r['cbs_total'],
        ];
    }, $ranking);

    $cfopFmt = array_map(static fn ($r) => [
        'cfop'       => $r['cfop'] ?? '-',
        'descricao'  => $cfopDesc((string) ($r['cfop'] ?? '')),
        'nfs'        => (int) $r['nfs'],
        'faturamento'=> $fmt((float) $r['faturamento']),
        'icms'       => $fmt((float) $r['icms']),
        'trib_total' => $fmt((float) $r['trib_total']),
        'carga_pct'  => (float) ($r['carga_pct'] ?? 0),
        '_fat'       => (float) $r['faturamento'],
        '_trib'      => (float) $r['trib_total'],
    ], $cfops);

    $payload = [
        'ok'      => true,
        'period'  => ['start' => $start, 'end' => $end],
        'has_mart'=> $svc->martExists(),
        'ranking' => $rankFmt,
        'cfops'   => $cfopFmt,
    ];

    $cache->put($cacheKey, $payload);
    Http::json($payload);
} catch (\Throwable $e) {
    global $logger;
    $logger?->error('fiscal_ncm error', [
        'exception' => $e->getMessage(),
        'request_id' => \BIomie\RequestContext::id(),
        'company_id' => TenantContext::companyId(),
    ]);
    Http::json(['ok' => false, 'message' => 'Erro ao carregar NCM fiscal.'], 500);
}
