<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Fiscal\Infrastructure\FiscalIntelligenceService;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.view', 'finance.view'], true);

try {
    $start = Http::query('start', date('Y-01-01'));
    $end   = Http::query('end',   date('Y-m-t'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-01-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-t');

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $svc    = new FiscalIntelligenceService(static fn () => $pdo);

    $raw  = $svc->fiscalAlerts($start, $end);
    $fmt  = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
    $meses = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

    $formatRows = static function (array $rows) use ($fmt, $meses): array {
        return array_map(static function (array $r) use ($fmt, $meses): array {
            $dt = $r['data_emissao'] ?? '';
            $label = '-';
            if ($dt && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dt, $m)) {
                $label = intval($m[3]) . ' ' . ($meses[intval($m[2])] ?? $m[2]) . ' ' . $m[1];
            }
            return [
                'nota_key'     => $r['nota_key'],
                'numero_nf'    => $r['numero_nf'],
                'data_label'   => $label,
                'nome_cliente' => $r['nome_cliente'] ?? '-',
                'nome_vendedor'=> $r['nome_vendedor'] ?? '-',
                'valor_total'  => $fmt((float) $r['valor_total']),
                '_fat'         => (float) $r['valor_total'],
                'carga_pct'    => isset($r['carga_pct']) ? (float) $r['carga_pct'] : null,
                'cfop'         => $r['cfop'] ?? null,
                'cancelamento' => $r['data_cancelamento'] ?? null,
            ];
        }, $rows);
    };

    $severityByCount = static function (int $count, int $warn, int $critical): string {
        if ($count >= $critical) return 'critical';
        if ($count >= $warn) return 'warning';
        return $count > 0 ? 'info' : 'ok';
    };

    Http::json([
        'ok'     => true,
        'period' => ['start' => $start, 'end' => $end],
        'alerts' => [
            'icms_zero' => [
                'count'  => $raw['icms_zero']['count'],
                'total'  => $fmt($raw['icms_zero']['total']),
                '_total' => $raw['icms_zero']['total'],
                'rows'   => $formatRows($raw['icms_zero']['rows']),
                'label'  => 'NFs com ICMS zerado',
                'desc'   => 'Notas autorizadas com valor > R$ 500 e ICMS = zero',
                'severity' => $raw['icms_zero']['count'] > 0 ? 'warning' : 'ok',
            ],
            'canceladas' => [
                'count'  => $raw['canceladas']['count'],
                'total'  => $fmt($raw['canceladas']['total']),
                '_total' => $raw['canceladas']['total'],
                'rows'   => $formatRows($raw['canceladas']['rows']),
                'label'  => 'NFs canceladas segregadas',
                'desc'   => 'Monitoradas para auditoria e excluídas do faturamento e da carga fiscal',
                'severity' => $raw['canceladas']['count'] > 10 ? 'warning' : ($raw['canceladas']['count'] > 0 ? 'info' : 'ok'),
            ],
            'sem_titulo' => [
                'count'  => $raw['sem_titulo']['count'],
                'total'  => $fmt($raw['sem_titulo']['total']),
                '_total' => $raw['sem_titulo']['total'],
                'rows'   => $formatRows($raw['sem_titulo']['rows']),
                'label'  => 'NFs sem Título Financeiro',
                'desc'   => 'Notas autorizadas sem contas a receber vinculadas (gap fiscal × financeiro)',
                'severity' => $raw['sem_titulo']['count'] > 50 ? 'critical' : ($raw['sem_titulo']['count'] > 0 ? 'warning' : 'ok'),
            ],
            'sem_ncm' => [
                'count'  => $raw['sem_ncm']['count'],
                'total'  => $fmt($raw['sem_ncm']['total']),
                '_total' => $raw['sem_ncm']['total'],
                'rows'   => $formatRows($raw['sem_ncm']['rows']),
                'label'  => 'Produtos sem NCM',
                'desc'   => 'Notas com item sem NCM informado no mart fiscal',
                'severity' => $severityByCount((int) $raw['sem_ncm']['count'], 1, 10),
            ],
            'xml_pendente' => [
                'count'  => $raw['xml_pendente']['count'],
                'total'  => $fmt($raw['xml_pendente']['total']),
                '_total' => $raw['xml_pendente']['total'],
                'rows'   => $formatRows($raw['xml_pendente']['rows']),
                'label'  => 'XML/chave pendente',
                'desc'   => 'Notas autorizadas sem chave NF-e registrada',
                'severity' => $severityByCount((int) $raw['xml_pendente']['count'], 1, 10),
            ],
            'carga_acima_media' => [
                'count'  => $raw['carga_acima_media']['count'],
                'total'  => $fmt($raw['carga_acima_media']['total']),
                '_total' => $raw['carga_acima_media']['total'],
                'rows'   => $formatRows($raw['carga_acima_media']['rows']),
                'label'  => 'Carga acima da média',
                'desc'   => 'Notas com carga superior a 125% da média do período',
                'severity' => $severityByCount((int) $raw['carga_acima_media']['count'], 5, 20),
                'threshold' => round((float) ($raw['carga_acima_media']['threshold'] ?? 0), 2),
                'media' => round((float) ($raw['carga_acima_media']['media'] ?? 0), 2),
            ],
            'cbs_ibs_zero' => [
                'count'  => $raw['cbs_ibs_zero']['count'],
                'total'  => $fmt($raw['cbs_ibs_zero']['total']),
                '_total' => $raw['cbs_ibs_zero']['total'],
                'rows'   => $formatRows($raw['cbs_ibs_zero']['rows']),
                'label'  => 'CBS/IBS zerado em 2026',
                'desc'   => 'Notas de 2026 sem destaque informativo CBS/IBS no mart',
                'severity' => $severityByCount((int) $raw['cbs_ibs_zero']['count'], 1, 10),
            ],
            'cfop_incomum' => [
                'count'  => $raw['cfop_incomum']['count'],
                'total'  => $fmt($raw['cfop_incomum']['total']),
                '_total' => $raw['cfop_incomum']['total'],
                'rows'   => $formatRows($raw['cfop_incomum']['rows']),
                'label'  => 'CFOP incomum para a operação',
                'desc'   => 'CFOP utilizado por apenas uma nota no período; requer validação contextual',
                'severity' => $severityByCount((int) $raw['cfop_incomum']['count'], 1, 5),
            ],
            'pis_cofins_divergente' => [
                'count'  => $raw['pis_cofins_divergente']['count'],
                'total'  => $fmt($raw['pis_cofins_divergente']['total']),
                '_total' => $raw['pis_cofins_divergente']['total'],
                'rows'   => $formatRows($raw['pis_cofins_divergente']['rows']),
                'label'  => 'PIS/COFINS divergente da média',
                'desc'   => 'Taxa efetiva da nota fora da faixa estatística do período',
                'severity' => $severityByCount((int) $raw['pis_cofins_divergente']['count'], 1, 10),
                'media_pct' => round((float) ($raw['pis_cofins_divergente']['media_pct'] ?? 0), 2),
            ],
        ],
    ]);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'message' => 'Erro ao carregar alertas fiscais.'], 500);
}
