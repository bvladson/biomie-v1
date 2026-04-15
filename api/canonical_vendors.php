<?php

declare(strict_types=1);

/**
 * Performance comercial — Leitura canônica.
 *
 * Mesmo contrato de resposta que api/vendors.php, mas lê da tabela
 * biomie_pedidos_canonicos em vez de chamar a API do Omie ao vivo.
 *
 * Se a tabela canônica não existir ou estiver vazia, redireciona
 * transparente para o fluxo antigo (api/vendors.php).
 */

use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support;

require __DIR__ . '/bootstrap.php';

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $start = $range['start'];
    $end = $range['end'];

    // ── Verificar se a camada canônica está disponível ────────────────────────
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $canonical = null;

    if (Database::isConfigured($config, $connection)) {
        try {
            $pdo = Database::connect($config, $connection);
            $repo = new CanonicalRepository($pdo, $logger);
            if ($repo->isAvailable()) {
                $canonical = $repo;
            }
        } catch (\Throwable $e) {
            $logger->warning('Canonical vendors: banco indisponível, usando fallback.', ['message' => $e->getMessage()]);
        }
    }

    // ── Fallback: delegar para o fluxo antigo ────────────────────────────────
    if ($canonical === null) {
        $snapshot = $analytics->getSnapshot($start, $end, Http::boolQuery('force', false));
        Http::json([
            'ok' => true,
            'mode' => $snapshot['mode'],
            'source' => 'legacy',
            'period' => $snapshot['period'],
            'summary' => $snapshot['summary'],
            'summary_status' => $snapshot['summary_status'] ?? [],
            'vendors' => $snapshot['vendor_ranking'],
            'vendor_status_matrix' => $snapshot['vendor_status_matrix'] ?? [],
            'daily_status' => $snapshot['daily_status'] ?? [],
            'funnel' => $snapshot['funnel'] ?? [],
            'alerts_operational' => $snapshot['alerts_operational'] ?? [],
            'meta' => $snapshot['meta'],
        ]);
    }

    // ── Leitura canônica ─────────────────────────────────────────────────────

    $summary = $canonical->summarize($start, $end);
    $vendorRows = $canonical->aggregateByVendor($start, $end);
    $dailyRows = $canonical->dailyByStatus($start, $end);
    $lastSync = $canonical->lastSyncAt($start, $end);

    $billedVal = (float) ($summary['billed_value'] ?? 0);
    $billedQty = (int) ($summary['billed_orders'] ?? 0);
    $authVal = (float) ($summary['authorized_value'] ?? 0);
    $authQty = (int) ($summary['authorized_orders'] ?? 0);
    $cancelVal = (float) ($summary['cancelled_value'] ?? 0);
    $cancelQty = (int) ($summary['cancelled_orders'] ?? 0);

    $convRate = ($authQty + $billedQty) > 0 ? round(($billedQty / ($authQty + $billedQty)) * 100, 2) : 0;
    $cancelRate = ($authQty + $cancelQty) > 0 ? round(($cancelQty / ($authQty + $cancelQty)) * 100, 2) : 0;

    $summaryStatus = [
        'billed_value' => Support::money($billedVal),
        'billed_orders' => $billedQty,
        'authorized_value' => Support::money($authVal),
        'authorized_orders' => $authQty,
        'cancelled_value' => Support::money($cancelVal),
        'cancelled_orders' => $cancelQty,
        'backlog_value' => Support::money($authVal),
        'conversion_rate' => $convRate,
        'cancel_rate' => $cancelRate,
    ];

    // ── Montar vendor_status_matrix (mesmo formato do SalesAnalyticsService) ─
    $vendorMatrix = [];
    $byVendor = [];

    foreach ($vendorRows as $row) {
        $vId = (string) ($row['codigo_vendedor_omie'] ?? '');
        $vName = (string) ($row['nome_vendedor'] ?? 'Sem vendedor');
        $status = (string) ($row['status_comercial'] ?? 'outro');
        $key = $vId ?: 'name:' . mb_strtolower($vName);

        if (!isset($byVendor[$key])) {
            $byVendor[$key] = [
                'vendor_id' => $vId,
                'vendor_name' => $vName,
                'billed_value' => 0.0, 'billed_orders' => 0,
                'authorized_value' => 0.0, 'authorized_orders' => 0,
                'cancelled_value' => 0.0, 'cancelled_orders' => 0,
            ];
        }

        $total = (float) ($row['total'] ?? 0);
        $pedidos = (int) ($row['pedidos'] ?? 0);

        if ($status === 'faturado') {
            $byVendor[$key]['billed_value'] += $total;
            $byVendor[$key]['billed_orders'] += $pedidos;
        } elseif ($status === 'autorizado') {
            $byVendor[$key]['authorized_value'] += $total;
            $byVendor[$key]['authorized_orders'] += $pedidos;
        } elseif ($status === 'cancelado') {
            $byVendor[$key]['cancelled_value'] += $total;
            $byVendor[$key]['cancelled_orders'] += $pedidos;
        }
    }

    foreach ($byVendor as $v) {
        $vBilledVal = $v['billed_value'];
        $vBilledQty = $v['billed_orders'];
        $vAuthQty = $v['authorized_orders'];
        $vCancelQty = $v['cancelled_orders'];

        $vendorMatrix[] = [
            'vendor_id' => $v['vendor_id'],
            'vendor_name' => $v['vendor_name'],
            'billed_value' => Support::money($vBilledVal),
            'billed_orders' => $vBilledQty,
            'authorized_value' => Support::money($v['authorized_value']),
            'authorized_orders' => $vAuthQty,
            'cancelled_value' => Support::money($v['cancelled_value']),
            'cancelled_orders' => $vCancelQty,
            'backlog_value' => Support::money($v['authorized_value']),
            'conversion_rate' => ($vAuthQty + $vBilledQty) > 0 ? round(($vBilledQty / ($vAuthQty + $vBilledQty)) * 100, 2) : 0,
            'cancel_rate' => ($vAuthQty + $vCancelQty) > 0 ? round(($vCancelQty / ($vAuthQty + $vCancelQty)) * 100, 2) : 0,
            'avg_ticket_billed' => $vBilledQty > 0 ? Support::money($vBilledVal / $vBilledQty) : 0,
            'avg_days_to_bill' => 0,
            'share_billed' => $billedVal > 0 ? round(($vBilledVal / $billedVal) * 100, 2) : 0,
        ];
    }
    usort($vendorMatrix, fn($a, $b) => $b['billed_value'] <=> $a['billed_value']);

    // ── Montar daily_status ──────────────────────────────────────────────────
    $dailyStatus = [];
    foreach (Support::eachDate($start, $end) as $date) {
        $dailyStatus[$date] = [
            'date' => $date,
            'billed_value' => 0.0, 'billed_orders' => 0,
            'authorized_value' => 0.0, 'authorized_orders' => 0,
            'cancelled_value' => 0.0, 'cancelled_orders' => 0,
        ];
    }
    foreach ($dailyRows as $row) {
        $dia = $row['dia'] ?? '';
        $status = $row['status_comercial'] ?? '';
        if (!isset($dailyStatus[$dia])) continue;

        $val = (float) ($row['total'] ?? 0);
        $qty = (int) ($row['pedidos'] ?? 0);

        if ($status === 'faturado') {
            $dailyStatus[$dia]['billed_value'] += $val;
            $dailyStatus[$dia]['billed_orders'] += $qty;
        } elseif ($status === 'autorizado') {
            $dailyStatus[$dia]['authorized_value'] += $val;
            $dailyStatus[$dia]['authorized_orders'] += $qty;
        } elseif ($status === 'cancelado') {
            $dailyStatus[$dia]['cancelled_value'] += $val;
            $dailyStatus[$dia]['cancelled_orders'] += $qty;
        }
    }

    // ── Alertas operacionais ─────────────────────────────────────────────────
    $alertsOp = [];
    foreach ($vendorMatrix as $v) {
        if ($v['cancel_rate'] > 15 && ($v['cancelled_orders'] + $v['authorized_orders']) >= 3) {
            $alertsOp[] = [
                'level' => 'danger',
                'title' => $v['vendor_name'] . ': cancelamento de ' . $v['cancel_rate'] . '%',
                'message' => $v['cancelled_orders'] . ' cancelado(s) de ' . ($v['cancelled_orders'] + $v['authorized_orders']) . ' pedido(s).',
            ];
        }
    }

    // ── Summary compatível com o cockpit ─────────────────────────────────────
    $revenueOrders = $billedQty + $authQty;
    $grossRevenue = $billedVal + $authVal;

    $summaryCompat = [
        'revenue' => Support::money($grossRevenue),
        'gross_revenue' => Support::money($grossRevenue),
        'merchandise_total' => 0,
        'discounts_total' => 0,
        'orders' => $revenueOrders,
        'avg_ticket' => $revenueOrders > 0 ? Support::money($grossRevenue / $revenueOrders) : 0,
        'avg_ticket_billed' => $billedQty > 0 ? Support::money($billedVal / $billedQty) : 0,
        'active_vendors' => count(array_filter($vendorMatrix, fn($v) => $v['vendor_name'] !== 'Sem vendedor')),
        'target' => 0,
        'forecast' => 0,
        'goal_progress' => 0,
    ];

    Http::json([
        'ok' => true,
        'mode' => 'canonical',
        'source' => 'canonical',
        'period' => [
            'start' => $start,
            'end' => $end,
            'label' => date('d/m/Y', strtotime($start)) . ' até ' . date('d/m/Y', strtotime($end)),
        ],
        'summary' => $summaryCompat,
        'summary_status' => $summaryStatus,
        'vendors' => $vendorMatrix,
        'vendor_status_matrix' => $vendorMatrix,
        'daily_status' => array_values(array_map(fn($d) => [
            'date' => $d['date'],
            'billed_value' => Support::money($d['billed_value']),
            'billed_orders' => $d['billed_orders'],
            'authorized_value' => Support::money($d['authorized_value']),
            'authorized_orders' => $d['authorized_orders'],
            'cancelled_value' => Support::money($d['cancelled_value']),
            'cancelled_orders' => $d['cancelled_orders'],
        ], $dailyStatus)),
        'funnel' => [
            'authorized' => Support::money($authVal + $billedVal),
            'billed' => Support::money($billedVal),
            'cancelled' => Support::money($cancelVal),
            'received' => 0.0,
        ],
        'alerts_operational' => $alertsOp,
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'served_from' => 'canonical-db',
            'last_sync' => $lastSync,
        ],
    ]);
} catch (\InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $logger->error('Falha em api/canonical_vendors.', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    Http::json(['ok' => false, 'message' => 'Erro interno ao carregar dados canônicos.'], 500);
}
