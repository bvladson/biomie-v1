<?php

declare(strict_types=1);

return [
    'dataset' => 'vendors',
    'description' => 'Payload legado de vendedores com erro canonical sinalizado e contrato de frescor anexado.',
    'source' => 'legacy_fallback',
    'canonical_error' => 'Snapshot canonical sem sinal.',
    'range' => [
        'start' => '2026-03-01',
        'end' => '2026-03-31',
        'label' => '01/03/2026 ate 31/03/2026',
        'competencia' => '2026-03',
    ],
    'snapshot' => [
        'mode' => 'live',
        'summary' => [
            'revenue' => 12450.0,
            'gross_revenue' => 12450.0,
            'orders' => 14,
            'pending_orders' => 3,
            'pending_value' => 2200.0,
            'cancelled_orders' => 1,
            'cancelled_value' => 180.0,
            'active_vendors' => 3,
            'target' => 15000.0,
            'forecast' => 13800.0,
            'goal_progress' => 83.0,
        ],
        'summary_status' => [
            'billed_value' => 12450.0,
            'billed_orders' => 14,
            'authorized_value' => 2200.0,
            'authorized_orders' => 3,
            'cancelled_value' => 180.0,
            'cancelled_orders' => 1,
            'backlog_value' => 2200.0,
            'conversion_rate' => 82.35,
            'cancel_rate' => 6.67,
        ],
        'vendor_ranking' => [
            ['vendor_id' => '10', 'vendor_name' => 'Ana', 'billed_value' => 6100.0, 'billed_orders' => 7],
            ['vendor_id' => '11', 'vendor_name' => 'Bruno', 'billed_value' => 4200.0, 'billed_orders' => 4],
        ],
        'vendor_status_matrix' => [
            ['vendor_id' => '10', 'vendor_name' => 'Ana', 'billed_value' => 6100.0, 'billed_orders' => 7, 'authorized_value' => 900.0, 'authorized_orders' => 1, 'cancelled_value' => 0.0, 'cancelled_orders' => 0],
            ['vendor_id' => '11', 'vendor_name' => 'Bruno', 'billed_value' => 4200.0, 'billed_orders' => 4, 'authorized_value' => 800.0, 'authorized_orders' => 1, 'cancelled_value' => 180.0, 'cancelled_orders' => 1],
        ],
        'daily_status' => [
            ['date' => '2026-03-02', 'billed_value' => 1200.0, 'billed_orders' => 1, 'authorized_value' => 0.0, 'authorized_orders' => 0, 'cancelled_value' => 0.0, 'cancelled_orders' => 0],
            ['date' => '2026-03-29', 'billed_value' => 1800.0, 'billed_orders' => 2, 'authorized_value' => 450.0, 'authorized_orders' => 1, 'cancelled_value' => 0.0, 'cancelled_orders' => 0],
        ],
        'funnel' => [
            'authorized' => 2200.0,
            'billed' => 12450.0,
            'cancelled' => 180.0,
            'received' => 0.0,
        ],
        'alerts_operational' => [
            ['level' => 'warning', 'title' => 'Bruno: conversao abaixo da meta', 'message' => 'Conversao do periodo abaixo do esperado.'],
        ],
        'meta' => [
            'generated_at' => '2026-04-01 08:00:00',
            'served_from' => 'historical-snapshot',
            'last_sync' => '2026-04-01 08:00:00',
        ],
    ],
];
