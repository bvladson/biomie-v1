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
    $end = Http::query('end', date('Y-m-t'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-01-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-t');

    $page = max(1, (int) Http::query('page', '1'));
    $perPage = max(10, min(100, (int) Http::query('per_page', '25')));
    $search = trim(Http::query('q', ''));
    $sort = Http::query('sort', 'data_entrada');
    $direction = Http::query('direction', 'desc');

    $config = new \BIomie\Config();
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $service = new FiscalIntelligenceService(static fn () => $pdo);
    $result = $service->purchaseEntries(
        $start,
        $end,
        $page,
        $perPage,
        $search,
        $sort,
        $direction,
    );

    $fmt = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
    $summary = $result['summary'];
    foreach (['compras', 'credito_potencial', 'icms', 'pis', 'cofins', 'ipi', 'cbs', 'ibs'] as $key) {
        $summary['_' . $key] = (float) ($summary[$key] ?? 0);
        $summary[$key . '_fmt'] = $fmt((float) ($summary[$key] ?? 0));
    }

    $rows = array_map(static function (array $row) use ($fmt): array {
        $date = (string) ($row['data_entrada'] ?? '');
        $dateLabel = $date;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) === 1) {
            $dateLabel = $matches[3] . '/' . $matches[2] . '/' . $matches[1];
        }
        return [
            'entry_key' => $row['entry_key'],
            'numero_documento' => $row['numero_documento'],
            'data_entrada' => $date,
            'data_label' => $dateLabel,
            'fornecedor_nome' => $row['fornecedor_nome'] ?: 'Fornecedor não identificado',
            'valor_total' => $fmt((float) $row['valor_total']),
            'credito_potencial' => $fmt((float) $row['credito_potencial']),
            'icms' => $fmt((float) $row['valor_icms']),
            'pis_cofins' => $fmt((float) $row['valor_pis'] + (float) $row['valor_cofins']),
            'ipi' => $fmt((float) $row['valor_ipi']),
            'cbs_ibs' => $fmt((float) $row['valor_cbs'] + (float) $row['valor_ibs']),
            'itens_count' => (int) $row['itens_count'],
            'ncm_list' => (string) ($row['ncm_list'] ?? ''),
            'cfop_list' => (string) ($row['cfop_list'] ?? ''),
            'status' => $row['status_entrada'],
            '_valor_total' => (float) $row['valor_total'],
            '_credito_potencial' => (float) $row['credito_potencial'],
        ];
    }, $result['rows']);

    Http::json([
        'ok' => true,
        'period' => ['start' => $start, 'end' => $end],
        'summary' => $summary,
        'rows' => $rows,
        'total' => $result['total'],
        'page' => $result['page'],
        'pages' => $result['pages'],
        'source' => $result['source'],
        'rules' => [
            'credit_status' => 'potential',
            'message' => 'Os tributos de entrada vêm do Omie e representam crédito potencial. O aproveitamento depende de CST, CFOP, finalidade da compra e validação contábil.',
        ],
    ]);
} catch (\Throwable $exception) {
    Http::json([
        'ok' => false,
        'message' => 'Não foi possível carregar as entradas de compras.',
    ], 500);
}
