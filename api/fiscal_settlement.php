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

    $config = new \BIomie\Config();
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $service = new FiscalIntelligenceService(static fn () => $pdo);
    $data = $service->monthlySettlement($start, $end);
    $postBilling = $service->postBillingBalance($start, $end);
    $fmt = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');

    $months = ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    $rows = array_map(static function (array $row) use ($fmt, $months): array {
        [$year, $month] = array_pad(explode('-', (string) $row['competencia']), 2, '');
        $numeric = [];
        foreach ($row as $key => $value) {
            if (preg_match('/^(faturamento|debito_|credito_|retencao_|tributos_|creditos_|retencoes_|saldo_)/', (string) $key)) {
                $numeric['_' . $key] = (float) $value;
            }
        }
        return array_merge($row, $numeric, [
            'label' => ($months[(int) $month] ?? $month) . '/' . substr($year, 2),
            'faturamento_fmt' => $fmt((float) $row['faturamento']),
            'tributos_brutos_fmt' => $fmt((float) $row['tributos_brutos']),
            'creditos_totais_fmt' => $fmt((float) $row['creditos_totais']),
            'retencoes_totais_fmt' => $fmt((float) $row['retencoes_totais']),
            'saldo_liquido_estimado_fmt' => $fmt((float) $row['saldo_liquido_estimado']),
        ]);
    }, $data['rows']);

    $totals = $data['totals'];
    Http::json([
        'ok' => true,
        'period' => ['start' => $start, 'end' => $end],
        'coverage' => $data['coverage'],
        'totals' => array_merge($totals, [
            'faturamento_fmt' => $fmt((float) ($totals['faturamento'] ?? 0)),
            'tributos_brutos_fmt' => $fmt((float) ($totals['tributos_brutos'] ?? 0)),
            'creditos_totais_fmt' => $fmt((float) ($totals['creditos_totais'] ?? 0)),
            'retencoes_totais_fmt' => $fmt((float) ($totals['retencoes_totais'] ?? 0)),
            'saldo_liquido_estimado_fmt' => $fmt((float) ($totals['saldo_liquido_estimado'] ?? 0)),
        ]),
        'rows' => $rows,
        'post_billing' => array_merge($postBilling, [
            'faturamento_fmt' => $fmt((float) $postBilling['faturamento']),
            'reserva_tributaria_fmt' => $fmt((float) $postBilling['reserva_tributaria']),
            'receita_pos_impostos_fmt' => $fmt((float) $postBilling['receita_pos_impostos']),
            'recebido_fmt' => $fmt((float) $postBilling['recebido']),
            'em_aberto_fmt' => $fmt((float) $postBilling['em_aberto']),
            'caixa_recebido_pos_impostos_fmt' => $fmt((float) $postBilling['caixa_recebido_pos_impostos']),
            'carteira_aberta_pos_impostos_fmt' => $fmt((float) $postBilling['carteira_aberta_pos_impostos']),
            'tax_min_fmt' => $fmt((float) $postBilling['tax_min']),
            'tax_max_fmt' => $fmt((float) $postBilling['tax_max']),
            'net_revenue_min_fmt' => $fmt((float) $postBilling['net_revenue_min']),
            'net_revenue_max_fmt' => $fmt((float) $postBilling['net_revenue_max']),
        ]),
    ]);
} catch (\Throwable $exception) {
    Http::json(['ok' => false, 'message' => 'Erro ao carregar apuração fiscal mensal.'], 500);
}
