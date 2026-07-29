<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Fiscal\Infrastructure\FiscalIntelligenceService;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.view', 'finance.view'], true);

try {
    $start   = Http::query('start',    date('Y-01-01'));
    $end     = Http::query('end',      date('Y-m-t'));
    $page    = max(1, (int) Http::query('page', '1'));
    $perPage = max(10, min(100, (int) Http::query('per_page', '50')));
    $status  = in_array(Http::query('status', 'autorizada'), ['autorizada', 'cancelada', 'todos'], true)
               ? Http::query('status', 'autorizada') : 'autorizada';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-01-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-t');

    $filters = ['status' => $status];
    if ($q    = Http::query('q',         '')) $filters['search']    = $q;
    if ($v    = Http::query('vendedor',  '')) $filters['vendedor']  = $v;
    if ($c    = Http::query('cliente',   '')) $filters['cliente']   = $c;
    if ($sort = Http::query('sort',      '')) $filters['sort']      = $sort;
    if ($dir  = Http::query('direction', '')) $filters['direction'] = $dir;
    $vinculo = Http::query('vinculo', '');
    if (in_array($vinculo, ['com_pedido', 'sem_pedido'], true)) $filters['vinculo'] = $vinculo;

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $svc    = new FiscalIntelligenceService(static fn () => $pdo);

    $fmt = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');

    $result = $svc->nfList($start, $end, $page, $perPage, $filters);

    $meses = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

    $rows = array_map(static function (array $r) use ($fmt, $meses): array {
        $dt    = $r['data_emissao'] ?? '';
        $label = '-';
        if ($dt && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dt, $m)) {
            $label = intval($m[3]) . ' ' . ($meses[intval($m[2])] ?? $m[2]) . ' ' . $m[1];
        }
        return [
            'nota_key'     => $r['nota_key'],
            'numero_nf'    => $r['numero_nf'],
            'serie_nf'     => $r['serie_nf'] ?? '',
            'data_label'   => $label,
            'data_emissao' => $dt,
            'nome_cliente' => $r['nome_cliente'],
            'documento'    => $r['documento_cliente'] ?? '',
            'nome_vendedor'=> $r['nome_vendedor'],
            // Pedido faturado que originou a NF — o elo que faltava entre a
            // Inteligencia Fiscal e o comercial.
            'pedido'       => (string) ($r['codigo_pedido_origem'] ?? ''),
            'pedido_id'    => (int) ($r['pedido_id_omie'] ?? 0),
            'has_pedido'   => (int) ($r['pedido_id_omie'] ?? 0) > 0,
            'valor_total'  => $fmt((float) $r['valor_total']),
            'icms'         => $fmt((float) $r['valor_icms']  ),
            'pis'          => $fmt((float) $r['valor_pis']   ),
            'cofins'       => $fmt((float) $r['valor_cofins']),
            'ipi'          => $fmt((float) $r['valor_ipi']   ),
            'trib_total'   => $fmt((float) $r['valor_trib_total']),
            'receita_pos_tributos' => $fmt(max(0.0, (float) $r['valor_total'] - (float) $r['valor_trib_total'])),
            'carga_pct'    => (float) ($r['carga_pct'] ?? 0),
            'status'       => $r['status_fiscal'],
            'principal_cfop'=> $r['principal_cfop'] ?? '',
            'sem_ncm_count' => (int) ($r['sem_ncm_count'] ?? 0),
            'icms_zero_count' => (int) ($r['icms_zero_count'] ?? 0),
            'has_titulo'    => (bool) ($r['has_titulo'] ?? true),
            'has_xml'       => trim((string) ($r['chave_nfe'] ?? '')) !== '',
            '_fat'         => (float) $r['valor_total'],
            '_trib'        => (float) $r['valor_trib_total'],
            '_receita_pos'  => max(0.0, (float) $r['valor_total'] - (float) $r['valor_trib_total']),
        ];
    }, $result['rows']);

    Http::json([
        'ok'       => true,
        'period'   => ['start' => $start, 'end' => $end],
        'total'    => $result['total'],
        'page'     => $result['page'],
        'per_page' => $result['per_page'],
        'pages'    => $result['pages'],
        'rows'     => $rows,
    ]);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'message' => 'Erro ao listar NFs fiscais.'], 500);
}
