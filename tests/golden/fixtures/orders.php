<?php

declare(strict_types=1);

return [
    'dataset' => 'orders',
    'description' => 'Pedidos filtrados por status com resumo reconstruido e vendedor resolvido pelo ranking.',
    'status' => 'faturado',
    'snapshot' => [
        'mode' => 'live',
        'period' => [
            'start' => '2026-03-01',
            'end' => '2026-03-31',
            'label' => '01/03/2026 ate 31/03/2026',
        ],
        'summary' => [
            'revenue' => 5600.0,
            'gross_revenue' => 5600.0,
            'merchandise_total' => 5450.0,
            'discounts_total' => 150.0,
            'orders' => 3,
            'avg_ticket' => 1866.67,
            'active_vendors' => 2,
        ],
        'vendor_ranking' => [
            ['vendor_id' => '001', 'vendor_name' => 'Ana'],
            ['vendor_id' => '011', 'vendor_name' => 'Bruno'],
        ],
        'top_orders' => [
            [
                'order_id' => 'P-4101',
                'vendor_id' => '001',
                'vendor_name' => 'Sem vendedor',
                'stage' => 'Faturado',
                'gross_total' => 3200.0,
                'merchandise_total' => 3100.0,
                'discount_total' => 100.0,
            ],
            [
                'order_id' => 'P-4102',
                'vendor_id' => '011',
                'vendor_name' => 'Bruno',
                'stage' => 'Autorizado',
                'gross_total' => 1500.0,
                'merchandise_total' => 1480.0,
                'discount_total' => 20.0,
            ],
            [
                'order_id' => 'P-4103',
                'vendor_id' => '001',
                'vendor_name' => 'Ana',
                'stage' => 'Cancelado',
                'gross_total' => 900.0,
                'merchandise_total' => 870.0,
                'discount_total' => 30.0,
            ],
        ],
        'meta' => [
            'generated_at' => '2026-04-01 08:00:00',
            'served_from' => 'historical-snapshot',
            'last_sync' => '2026-04-01 08:00:00',
        ],
    ],
];
