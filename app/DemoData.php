<?php

declare(strict_types=1);

namespace BIomie;

final class DemoData
{
    public static function orders(string $start, string $end): array
    {
        return [
            [
                'codigo' => 'PV-10982',
                'codigo_integracao' => 'OM-2026-001',
                'cliente' => 'Prefeitura Municipal',
                'vendor_id' => 10,
                'vendor_name' => 'Alexandre',
                'issue_date' => $start,
                'billing_date' => $start,
                'stage' => 'FATURADO',
                'items_qty' => 8,
                'total' => 62450.00,
                'items' => [
                    ['product_code' => 'CAD-EXEC-01', 'description' => 'Cadeira Executiva Ergonômica', 'quantity' => 14, 'total' => 24500.00],
                    ['product_code' => 'MESA-PEPAINEL-02', 'description' => 'Mesa Pé Painel 1,60m', 'quantity' => 9, 'total' => 37950.00],
                ],
            ],
            [
                'codigo' => 'PV-10985',
                'codigo_integracao' => 'OM-2026-002',
                'cliente' => 'Clínica Fortaleza Sul',
                'vendor_id' => 11,
                'vendor_name' => 'Ana Karyne',
                'issue_date' => $start,
                'billing_date' => date('Y-m-d', strtotime($start . ' +2 day')),
                'stage' => 'FATURADO',
                'items_qty' => 5,
                'total' => 48190.00,
                'items' => [
                    ['product_code' => 'ARM-ALTO-80', 'description' => 'Armário Alto Executivo 80', 'quantity' => 7, 'total' => 20160.00],
                    ['product_code' => 'EST-ILHA-120', 'description' => 'Estação de Trabalho Ilha 120', 'quantity' => 6, 'total' => 28030.00],
                ],
            ],
            [
                'codigo' => 'PV-10994',
                'codigo_integracao' => 'OM-2026-003',
                'cliente' => 'Grupo Educacional Nordeste',
                'vendor_id' => 12,
                'vendor_name' => 'Gabriel',
                'issue_date' => date('Y-m-d', strtotime($start . ' +4 day')),
                'billing_date' => date('Y-m-d', strtotime($start . ' +4 day')),
                'stage' => 'FATURADO',
                'items_qty' => 10,
                'total' => 79990.00,
                'items' => [
                    ['product_code' => 'LONG-6P-03', 'description' => 'Longarina 3 Lugares', 'quantity' => 18, 'total' => 21420.00],
                    ['product_code' => 'MESA-REUN-24', 'description' => 'Mesa de Reunião 2,40m', 'quantity' => 9, 'total' => 58570.00],
                ],
            ],
            [
                'codigo' => 'PV-11001',
                'codigo_integracao' => 'OM-2026-004',
                'cliente' => 'Construtora Mar Azul',
                'vendor_id' => 10,
                'vendor_name' => 'Alexandre',
                'issue_date' => date('Y-m-d', strtotime($start . ' +8 day')),
                'billing_date' => date('Y-m-d', strtotime($start . ' +8 day')),
                'stage' => 'FATURADO',
                'items_qty' => 6,
                'total' => 33640.00,
                'items' => [
                    ['product_code' => 'GAV-BC-03', 'description' => 'Gaveteiro Volante 3 Gavetas', 'quantity' => 8, 'total' => 9360.00],
                    ['product_code' => 'CADEIRA-OPER-04', 'description' => 'Cadeira Operacional Tela', 'quantity' => 12, 'total' => 24280.00],
                ],
            ],
            [
                'codigo' => 'PV-11009',
                'codigo_integracao' => 'OM-2026-005',
                'cliente' => 'Hospital Vida Nova',
                'vendor_id' => 13,
                'vendor_name' => 'Núbia',
                'issue_date' => date('Y-m-d', strtotime($start . ' +10 day')),
                'billing_date' => date('Y-m-d', strtotime($start . ' +10 day')),
                'stage' => 'FATURADO',
                'items_qty' => 4,
                'total' => 58770.00,
                'items' => [
                    ['product_code' => 'ARQ-ACO-4G', 'description' => 'Arquivo de Aço 4 Gavetas', 'quantity' => 15, 'total' => 24300.00],
                    ['product_code' => 'MESA-GER-200', 'description' => 'Mesa Gerência 2,00m', 'quantity' => 7, 'total' => 34470.00],
                ],
            ],
            [
                'codigo' => 'PV-11014',
                'codigo_integracao' => 'OM-2026-006',
                'cliente' => 'Call Center Ceará',
                'vendor_id' => 11,
                'vendor_name' => 'Ana Karyne',
                'issue_date' => date('Y-m-d', strtotime($start . ' +14 day')),
                'billing_date' => date('Y-m-d', strtotime($start . ' +14 day')),
                'stage' => 'FATURADO',
                'items_qty' => 16,
                'total' => 91320.00,
                'items' => [
                    ['product_code' => 'EST-120-OPER', 'description' => 'Estação Operacional 1,20m', 'quantity' => 20, 'total' => 64800.00],
                    ['product_code' => 'PAINEL-DIV', 'description' => 'Painel Divisor Acústico', 'quantity' => 22, 'total' => 26520.00],
                ],
            ],
            [
                'codigo' => 'PV-11019',
                'codigo_integracao' => 'OM-2026-007',
                'cliente' => 'Escritório Horizonte',
                'vendor_id' => 12,
                'vendor_name' => 'Gabriel',
                'issue_date' => date('Y-m-d', strtotime($start . ' +18 day')),
                'billing_date' => date('Y-m-d', strtotime($start . ' +18 day')),
                'stage' => 'FATURADO',
                'items_qty' => 3,
                'total' => 18750.00,
                'items' => [
                    ['product_code' => 'CADEIRA-DIR-08', 'description' => 'Cadeira Diretor', 'quantity' => 5, 'total' => 11250.00],
                    ['product_code' => 'MESA-APOIO-90', 'description' => 'Mesa de Apoio 90cm', 'quantity' => 4, 'total' => 7500.00],
                ],
            ],
        ];
    }

    public static function vendors(): array
    {
        return [
            ['codigo' => 10, 'nome' => 'Alexandre', 'email' => 'alexandre@mag.com.br', 'comissao' => 4.5],
            ['codigo' => 11, 'nome' => 'Ana Karyne', 'email' => 'anakaryne@mag.com.br', 'comissao' => 5.0],
            ['codigo' => 12, 'nome' => 'Gabriel', 'email' => 'gabriel@mag.com.br', 'comissao' => 4.0],
            ['codigo' => 13, 'nome' => 'Núbia', 'email' => 'nubia@mag.com.br', 'comissao' => 4.2],
        ];
    }
}
