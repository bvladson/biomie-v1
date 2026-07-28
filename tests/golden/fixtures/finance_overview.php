<?php

declare(strict_types=1);

return [
    'dataset' => 'finance_overview',
    'description' => 'Visao financeira completa com buckets, budget e tendencia em periodo fechado.',
    'snapshot' => [
        'mode' => 'live-finance',
        'period' => [
            'start' => '2026-03-01',
            'end' => '2026-03-31',
            'label' => '01/03/2026 ate 31/03/2026',
        ],
        'summary' => [
            'received_total' => 18300.0,
            'open_total' => 4200.0,
            'overdue_total' => 1250.0,
            'titles_total' => 26,
        ],
        'received_rows' => [
            ['title_id' => 'RCB-01', 'vendor_name' => 'Ana', 'amount' => 5200.0, 'paid_at' => '2026-03-08'],
            ['title_id' => 'RCB-02', 'vendor_name' => 'Bruno', 'amount' => 3100.0, 'paid_at' => '2026-03-17'],
        ],
        'open_titles' => [
            ['title_id' => 'ABR-01', 'vendor_name' => 'Ana', 'amount' => 1800.0, 'due_date' => '2026-03-29'],
        ],
        'overdue_titles' => [
            ['title_id' => 'VENC-01', 'vendor_name' => 'Bruno', 'amount' => 1250.0, 'due_date' => '2026-03-12'],
        ],
        'by_account' => [
            ['account_label' => 'Banco A', 'received_total' => 9400.0],
        ],
        'by_vendor' => [
            ['vendor_name' => 'Ana', 'received_total' => 9300.0],
            ['vendor_name' => 'Bruno', 'received_total' => 6100.0],
        ],
        'by_client' => [
            ['client_name' => 'Cliente A', 'received_total' => 4800.0],
        ],
        'budget' => [
            ['month' => '2026-03', 'budget_total' => 20000.0, 'received_total' => 18300.0],
        ],
        'trend' => [
            ['month' => '2026-01', 'received_total' => 15100.0],
            ['month' => '2026-02', 'received_total' => 16500.0],
            ['month' => '2026-03', 'received_total' => 18300.0],
        ],
        'aging' => [
            ['bucket' => '0-30', 'amount' => 900.0],
            ['bucket' => '31-60', 'amount' => 350.0],
        ],
        'status_breakdown' => [
            ['status' => 'recebido', 'count' => 18, 'amount' => 18300.0],
            ['status' => 'aberto', 'count' => 7, 'amount' => 4200.0],
        ],
        'document_types' => [
            ['type' => 'boleto', 'amount' => 9000.0],
        ],
        'payment_methods' => [
            ['method' => 'pix', 'amount' => 6200.0],
        ],
        'meta' => [
            'generated_at' => '2026-04-01 08:00:00',
            'served_from' => 'historical-snapshot',
            'last_sync' => '2026-04-01 08:00:00',
        ],
    ],
];
