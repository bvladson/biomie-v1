<?php

declare(strict_types=1);

return [
    'dataset' => 'overview',
    'description' => 'Snapshot legado do overview com periodo fechado e contrato de dados anexado.',
    'snapshot' => [
        'mode' => 'live',
        'source' => 'legacy',
        'period' => [
            'start' => '2026-03-01',
            'end' => '2026-03-31',
            'label' => '01/03/2026 ate 31/03/2026',
        ],
        'summary' => [
            'revenue' => 15200.45,
            'gross_revenue' => 15200.45,
            'merchandise_total' => 14750.10,
            'discounts_total' => 330.50,
            'orders' => 18,
            'avg_ticket' => 844.47,
            'active_vendors' => 3,
            'active_clients' => 12,
            'top5_client_concentration_pct' => 48.4,
            'target' => 18000.0,
            'forecast' => 17650.0,
            'goal_progress' => 84.45,
            'pending_orders' => 4,
            'pending_value' => 2650.90,
            'cancelled_orders' => 1,
            'cancelled_value' => 220.0,
        ],
        'daily_sales' => [
            ['date' => '2026-03-03', 'revenue' => 1800.0, 'gross_revenue' => 1800.0, 'orders' => 2],
            ['date' => '2026-03-18', 'revenue' => 4200.5, 'gross_revenue' => 4200.5, 'orders' => 4],
        ],
        'vendor_ranking' => [
            [
                'vendor_id' => '10',
                'vendor_name' => 'Ana',
                'revenue' => 7200.15,
                'gross_revenue' => 7200.15,
                'merchandise_total' => 7000.0,
                'discount_total' => 120.0,
                'orders' => 8,
                'ticket_avg' => 900.02,
                'share' => 47.37,
            ],
            [
                'vendor_id' => '11',
                'vendor_name' => 'Bruno',
                'revenue' => 4900.30,
                'gross_revenue' => 4900.30,
                'merchandise_total' => 4800.0,
                'discount_total' => 95.0,
                'orders' => 6,
                'ticket_avg' => 816.72,
                'share' => 32.24,
            ],
        ],
        'top_orders' => [
            ['order_id' => 'P-3001', 'client_name' => 'Cliente A', 'gross_total' => 3200.0, 'stage' => 'Faturado'],
            ['order_id' => 'P-3002', 'client_name' => 'Cliente B', 'gross_total' => 2500.5, 'stage' => 'Faturado'],
        ],
        'top_products' => [
            ['product_code' => 'PRD-01', 'description' => 'Produto 01', 'quantity' => 13, 'revenue' => 4100.0],
            ['product_code' => 'PRD-02', 'description' => 'Produto 02', 'quantity' => 8, 'revenue' => 2550.3],
        ],
        'top_clients' => [
            ['client_id' => '200', 'client_name' => 'Cliente A', 'invoice_total' => 4200.0, 'share_pct' => 27.63],
            ['client_id' => '201', 'client_name' => 'Cliente B', 'invoice_total' => 3100.0, 'share_pct' => 20.39],
        ],
        'month_comparison' => [
            ['competencia' => '2025-12', 'label' => 'Dez/25', 'revenue' => 11800.0, 'orders' => 16, 'target' => 14000.0, 'forecast' => 11800.0, 'attainment_pct' => 84.29, 'is_current' => false],
            ['competencia' => '2026-03', 'label' => 'Mar/26', 'revenue' => 15200.45, 'orders' => 18, 'target' => 18000.0, 'forecast' => 17650.0, 'attainment_pct' => 84.45, 'is_current' => true],
        ],
        'alerts' => [
            ['level' => 'warning', 'title' => 'Concentracao elevada em cliente', 'message' => 'O principal cliente concentrou 27.6% da receita.'],
        ],
        'meta' => [
            'generated_at' => '2026-04-01 08:00:00',
            'served_from' => 'historical-snapshot',
            'last_sync' => '2026-04-01 08:00:00',
            'period_basis' => 'issue_date',
        ],
    ],
];
