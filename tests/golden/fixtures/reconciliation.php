<?php

declare(strict_types=1);

return [
    'dataset' => 'reconciliation',
    'description' => 'Relatorio legado de reconciliacao com totais e vendors normalizados.',
    'start' => '2026-03-01',
    'end' => '2026-03-31',
    'snapshot' => [
        'summary_status' => [
            'billed_value' => 12450.0,
            'billed_orders' => 14,
            'authorized_value' => 2200.0,
            'authorized_orders' => 3,
            'cancelled_value' => 180.0,
            'cancelled_orders' => 1,
        ],
        'vendor_status_matrix' => [
            [
                'vendor_id' => '10',
                'vendor_name' => 'Ana',
                'billed_value' => 6100.0,
                'billed_orders' => 7,
                'authorized_value' => 900.0,
                'authorized_orders' => 1,
                'cancelled_value' => 0.0,
                'cancelled_orders' => 0,
            ],
            [
                'vendor_id' => '11',
                'vendor_name' => 'Bruno',
                'billed_value' => 4200.0,
                'billed_orders' => 4,
                'authorized_value' => 800.0,
                'authorized_orders' => 1,
                'cancelled_value' => 180.0,
                'cancelled_orders' => 1,
            ],
        ],
        'meta' => [
            'generated_at' => '2026-04-01 08:00:00',
            'served_from' => 'snapshot_fiscal',
            'last_sync' => '2026-04-01 08:00:00',
        ],
    ],
];
