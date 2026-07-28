<?php

declare(strict_types=1);

return [
    'dataset' => 'finance_titles',
    'description' => 'Bucket financeiro especifico com rows deterministicas.',
    'bucket' => 'overdue',
    'snapshot' => [
        'mode' => 'live-finance',
        'period' => [
            'start' => '2026-03-01',
            'end' => '2026-03-31',
            'label' => '01/03/2026 ate 31/03/2026',
        ],
        'received_rows' => [
            ['title_id' => 'RCB-01', 'amount' => 5200.0],
        ],
        'open_titles' => [
            ['title_id' => 'ABR-01', 'amount' => 1800.0],
        ],
        'overdue_titles' => [
            ['title_id' => 'VENC-01', 'amount' => 1250.0, 'vendor_name' => 'Bruno', 'days_late' => 19],
            ['title_id' => 'VENC-02', 'amount' => 330.0, 'vendor_name' => 'Ana', 'days_late' => 4],
        ],
        'meta' => [
            'generated_at' => '2026-04-01 08:00:00',
            'served_from' => 'historical-snapshot',
            'last_sync' => '2026-04-01 08:00:00',
        ],
    ],
];
