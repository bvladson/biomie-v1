<?php

declare(strict_types=1);

return [
    [
        'id' => 'pedido_autorizado_sem_nf',
        'description' => 'Pedido autorizado ainda em carteira, sem NF vinculada.',
        'omie_input' => [
            'pedidos' => [['codigo_pedido' => 'P-1001', 'etapa' => 'Autorizado']],
            'notas' => [],
        ],
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1001',
                    'codigo_pedido' => 'P-1001',
                    'status_comercial' => 'autorizado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '200',
                    'data_emissao' => '2026-03-03',
                    'valor_total' => 1500.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'canonical_state' => [
                'order_status_counts' => ['autorizado' => 1],
                'invoice_status_counts' => ['autorizada' => 0],
            ],
            'kpis' => [
                'pedido_aberto' => 1,
                'pedido_faturado' => 0,
                'nf_autorizada' => 0,
                'receita_comercial' => 0.0,
                'receita_fiscal' => 0.0,
            ],
            'divergences' => [],
        ],
    ],
    [
        'id' => 'pedido_faturado_com_nf_autorizada',
        'description' => 'Pedido faturado com NF autorizada e valores casados.',
        'omie_input' => [
            'pedidos' => [['codigo_pedido' => 'P-1002', 'faturado' => true]],
            'notas' => [['chave_nfe' => 'NFE-1002', 'status' => 'autorizada']],
        ],
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1002',
                    'codigo_pedido' => 'P-1002',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '200',
                    'data_emissao' => '2026-03-05',
                    'data_faturamento' => '2026-03-07',
                    'valor_total' => 2500.00,
                ],
            ],
            'invoices' => [
                [
                    'nota_key' => 'NF-1002',
                    'chave_nfe' => 'NFE-1002',
                    'status_fiscal' => 'autorizada',
                    'pedido_id_omie' => '1002',
                    'codigo_pedido_origem' => 'P-1002',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '200',
                    'data_emissao' => '2026-03-07',
                    'data_competencia' => '2026-03-07',
                    'valor_total' => 2500.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'kpis' => [
                'pedido_aberto' => 0,
                'pedido_faturado' => 1,
                'nf_autorizada' => 1,
                'receita_comercial' => 2500.0,
                'receita_fiscal' => 2500.0,
            ],
            'divergences' => [],
        ],
    ],
    [
        'id' => 'nf_cancelada_apos_emissao',
        'description' => 'NF cancelada depois da emissao nao deve permanecer em receita fiscal.',
        'omie_input' => [
            'notas' => [['chave_nfe' => 'NFE-1003', 'status' => 'cancelada']],
        ],
        'canonical_input' => [
            'invoices' => [
                [
                    'nota_key' => 'NF-1003',
                    'chave_nfe' => 'NFE-1003',
                    'status_fiscal' => 'cancelada',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '200',
                    'data_emissao' => '2026-03-08',
                    'data_cancelamento' => '2026-03-10',
                    'valor_total' => 1800.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'kpis' => [
                'nf_autorizada' => 0,
                'nf_cancelada' => 1,
                'receita_fiscal' => 0.0,
            ],
            'divergences' => [],
        ],
    ],
    [
        'id' => 'pedido_devolvido',
        'description' => 'Pedido devolvido nao entra como aberto nem faturado.',
        'omie_input' => [
            'pedidos' => [['codigo_pedido' => 'P-1004', 'status' => 'devolvido']],
        ],
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1004',
                    'codigo_pedido' => 'P-1004',
                    'status_comercial' => 'devolvido',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '201',
                    'data_emissao' => '2026-03-11',
                    'valor_total' => 900.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'canonical_state' => [
                'order_status_counts' => ['devolvido' => 1],
            ],
            'kpis' => [
                'pedido_aberto' => 0,
                'pedido_faturado' => 0,
                'receita_comercial' => 0.0,
            ],
            'divergences' => [],
        ],
    ],
    [
        'id' => 'pedido_sem_vendedor',
        'description' => 'Pedido sem vendedor resolvido deve abrir divergencia tipada.',
        'omie_input' => [
            'pedidos' => [['codigo_pedido' => 'P-1005', 'vendedor' => null]],
        ],
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1005',
                    'codigo_pedido' => 'P-1005',
                    'status_comercial' => 'autorizado',
                    'codigo_vendedor_omie' => '',
                    'codigo_cliente_omie' => '202',
                    'data_emissao' => '2026-03-12',
                    'valor_total' => 1200.00,
                ],
            ],
        ],
        'expected' => [
            'canonical_state' => [
                'missing_vendor_orders' => 1,
            ],
            'divergences' => ['pedido_sem_vendedor'],
        ],
    ],
    [
        'id' => 'vendedor_inativo_historico_valido',
        'description' => 'Historico com vendedor inativo deve continuar legivel sem abrir divergencia sozinho.',
        'omie_input' => [
            'pedidos' => [['codigo_pedido' => 'P-1006', 'vendedor' => '12']],
        ],
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1006',
                    'codigo_pedido' => 'P-1006',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '12',
                    'codigo_cliente_omie' => '203',
                    'data_emissao' => '2026-03-13',
                    'valor_total' => 2200.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '12', 'active' => false],
            ],
        ],
        'expected' => [
            'canonical_state' => [
                'inactive_vendor_history' => 1,
            ],
            'divergences' => ['pedido_faturado_sem_nf_valida'],
        ],
    ],
    [
        'id' => 'cliente_sem_cadastro_resumido',
        'description' => 'Cliente sem cadastro resumido deve ser rastreado como lacuna de completude.',
        'omie_input' => [
            'pedidos' => [['codigo_pedido' => 'P-1007', 'cliente' => 'sem resumo']],
        ],
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1007',
                    'codigo_pedido' => 'P-1007',
                    'status_comercial' => 'autorizado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '999',
                    'client_registry_missing' => true,
                    'data_emissao' => '2026-03-14',
                    'valor_total' => 700.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'canonical_state' => [
                'missing_client_registry' => 1,
            ],
            'divergences' => [],
        ],
    ],
    [
        'id' => 'mudanca_retroativa_em_janela_consolidada',
        'description' => 'Mudanca retroativa precisa abrir sinalizacao propria.',
        'omie_input' => [
            'pedidos' => [['codigo_pedido' => 'P-1008', 'retroativo' => true]],
        ],
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1008',
                    'codigo_pedido' => 'P-1008',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '204',
                    'data_emissao' => '2026-02-20',
                    'valor_total' => 3100.00,
                    'retroactive_change_detected' => true,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
            'metadata' => [
                'retroactive_change_detected' => true,
            ],
        ],
        'expected' => [
            'canonical_state' => [
                'retroactive_changes' => 2,
            ],
            'divergences' => ['mudanca_retroativa_detectada', 'pedido_faturado_sem_nf_valida'],
        ],
    ],
    [
        'id' => 'pedido_previsto_mes_e_faturado_em_outro',
        'description' => 'Competencia comercial pode divergir da fiscal em meses diferentes.',
        'omie_input' => [
            'pedidos' => [['codigo_pedido' => 'P-1009', 'previsto_mes' => '2026-03']],
            'notas' => [['chave_nfe' => 'NFE-1009', 'competencia' => '2026-04']],
        ],
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1009',
                    'codigo_pedido' => 'P-1009',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '205',
                    'data_emissao' => '2026-03-29',
                    'data_faturamento' => '2026-04-02',
                    'valor_total' => 4100.00,
                ],
            ],
            'invoices' => [
                [
                    'nota_key' => 'NF-1009',
                    'chave_nfe' => 'NFE-1009',
                    'status_fiscal' => 'autorizada',
                    'pedido_id_omie' => '1009',
                    'codigo_pedido_origem' => 'P-1009',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '205',
                    'data_emissao' => '2026-04-02',
                    'data_competencia' => '2026-04-02',
                    'valor_total' => 4100.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'canonical_state' => [
                'cross_month_competencia_mismatches' => 1,
                'commercial_competencias' => ['2026-03' => 1],
                'fiscal_competencias' => ['2026-04' => 1],
            ],
            'divergences' => [],
        ],
    ],
    [
        'id' => 'competencia_fiscal_diferente_da_comercial',
        'description' => 'Datas e clientes divergentes entre comercial e fiscal devem ser tipados.',
        'omie_input' => [
            'pedidos' => [['codigo_pedido' => 'P-1010']],
            'notas' => [['chave_nfe' => 'NFE-1010']],
        ],
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1010',
                    'codigo_pedido' => 'P-1010',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '205',
                    'data_emissao' => '2026-03-31',
                    'data_faturamento' => '2026-03-31',
                    'valor_total' => 5000.00,
                ],
            ],
            'invoices' => [
                [
                    'nota_key' => 'NF-1010',
                    'chave_nfe' => 'NFE-1010',
                    'status_fiscal' => 'autorizada',
                    'pedido_id_omie' => '1010',
                    'codigo_pedido_origem' => 'P-1010',
                    'codigo_vendedor_omie' => '',
                    'codigo_cliente_omie' => '206',
                    'data_emissao' => '2026-04-01',
                    'data_competencia' => '2026-04-01',
                    'valor_total' => 5200.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'divergences' => [
                'diferenca_cliente_pedido_nf',
                'diferenca_data_comercial_fiscal',
                'diferenca_valor_pedido_nf',
                'nf_autorizada_sem_vendedor_resolvido',
            ],
        ],
    ],
    [
        'id' => 'comercial_faturado_fiscal_ainda_nao_valida',
        'description' => 'Comercial reconhece faturamento, mas o fiscal ainda nao validou a NF.',
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1011',
                    'codigo_pedido' => 'P-1011',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '207',
                    'data_emissao' => '2026-03-20',
                    'data_faturamento' => '2026-03-21',
                    'valor_total' => 1800.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'kpis' => [
                'pedido_faturado' => 1,
                'nf_autorizada' => 0,
                'receita_comercial' => 1800.0,
                'receita_fiscal' => 0.0,
            ],
            'divergences' => ['pedido_faturado_sem_nf_valida'],
        ],
    ],
    [
        'id' => 'fiscal_cancelou_comercial_nao_atualizou',
        'description' => 'Fiscal cancelou a nota, mas o comercial continua faturado.',
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1012',
                    'codigo_pedido' => 'P-1012',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '208',
                    'data_emissao' => '2026-03-22',
                    'data_faturamento' => '2026-03-22',
                    'valor_total' => 2100.00,
                ],
            ],
            'invoices' => [
                [
                    'nota_key' => 'NF-1012',
                    'chave_nfe' => 'NFE-1012',
                    'status_fiscal' => 'cancelada',
                    'pedido_id_omie' => '1012',
                    'codigo_pedido_origem' => 'P-1012',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '208',
                    'data_emissao' => '2026-03-22',
                    'data_cancelamento' => '2026-03-23',
                    'valor_total' => 2100.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'kpis' => [
                'pedido_faturado' => 1,
                'nf_cancelada' => 1,
                'receita_fiscal' => 0.0,
            ],
            'divergences' => ['pedido_faturado_sem_nf_valida'],
        ],
    ],
    [
        'id' => 'financeiro_liquidado_sem_origem',
        'description' => 'Titulo liquidado sem documento de origem deve manter o financeiro e abrir divergencia.',
        'canonical_input' => [
            'titles' => [
                [
                    'titulo_key' => 'T-1013',
                    'status_financeiro' => 'liquidado',
                    'valor_liquidado' => 880.00,
                    'numero_documento' => 'RCB-1013',
                ],
            ],
        ],
        'expected' => [
            'kpis' => [
                'financeiro_realizado' => 880.0,
            ],
            'divergences' => ['titulo_financeiro_sem_origem'],
        ],
    ],
    // ─── Casos reais do ambiente ───────────────────────────────────────────────

    [
        'id' => 'vendedor_na_lista_sem_pedidos',
        'description' => 'Vendedor no cadastro sem nenhum pedido no periodo nao deve abrir divergencia.',
        'canonical_input' => [
            'vendors' => [
                ['codigo_vendedor_omie' => '20', 'active' => true],
            ],
        ],
        'expected' => [
            'canonical_state' => [
                'orders_total' => 0,
                'invoices_total' => 0,
                'missing_vendor_orders' => 0,
            ],
            'kpis' => [
                'pedido_aberto' => 0,
                'pedido_faturado' => 0,
                'nf_autorizada' => 0,
                'receita_comercial' => 0.0,
                'receita_fiscal' => 0.0,
                'financeiro_realizado' => 0.0,
            ],
            'divergences' => [],
        ],
    ],

    [
        'id' => 'titulo_recebido_completo',
        'description' => 'Cadeia completa pedido+NF+titulo liquidado deve bater sem nenhuma divergencia.',
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '2001',
                    'codigo_pedido' => 'P-2001',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '300',
                    'data_emissao' => '2026-04-05',
                    'data_faturamento' => '2026-04-06',
                    'valor_total' => 3600.00,
                ],
            ],
            'invoices' => [
                [
                    'nota_key' => 'NF-2001',
                    'chave_nfe' => 'NFE-2001',
                    'status_fiscal' => 'autorizada',
                    'pedido_id_omie' => '2001',
                    'codigo_pedido_origem' => 'P-2001',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '300',
                    'data_emissao' => '2026-04-06',
                    'data_competencia' => '2026-04-06',
                    'valor_total' => 3600.00,
                ],
            ],
            'titles' => [
                [
                    'titulo_key' => 'T-2001',
                    'pedido_id_omie' => '2001',
                    'codigo_pedido_origem' => 'P-2001',
                    'nota_key' => 'NF-2001',
                    'status_financeiro' => 'liquidado',
                    'valor_liquidado' => 3600.00,
                    'numero_documento' => 'RCB-2001',
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'kpis' => [
                'pedido_faturado' => 1,
                'nf_autorizada' => 1,
                'receita_comercial' => 3600.0,
                'receita_fiscal' => 3600.0,
                'financeiro_realizado' => 3600.0,
            ],
            'divergences' => [],
        ],
    ],

    [
        'id' => 'carteira_em_aberto_nao_aumenta_realizado',
        'description' => 'Pedido autorizado em carteira nao deve aumentar receita realizada nem abrir divergencia.',
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '2002',
                    'codigo_pedido' => 'P-2002',
                    'status_comercial' => 'autorizado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '301',
                    'data_emissao' => '2026-04-07',
                    'valor_total' => 8500.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'canonical_state' => [
                'order_status_counts' => ['autorizado' => 1],
                'invoice_status_counts' => ['autorizada' => 0],
            ],
            'kpis' => [
                'pedido_aberto' => 1,
                'pedido_faturado' => 0,
                'nf_autorizada' => 0,
                'receita_comercial' => 0.0,
                'receita_fiscal' => 0.0,
                'financeiro_realizado' => 0.0,
            ],
            'divergences' => [],
        ],
    ],

    [
        'id' => 'item_top_faturado_com_titulo_liquidado',
        'description' => 'Pedido de alto valor totalmente consistente entre comercial, fiscal e financeiro.',
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '2003',
                    'codigo_pedido' => 'P-2003',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '302',
                    'data_emissao' => '2026-04-03',
                    'data_faturamento' => '2026-04-04',
                    'valor_total' => 50000.00,
                ],
            ],
            'invoices' => [
                [
                    'nota_key' => 'NF-2003',
                    'chave_nfe' => 'NFE-2003',
                    'status_fiscal' => 'autorizada',
                    'pedido_id_omie' => '2003',
                    'codigo_pedido_origem' => 'P-2003',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '302',
                    'data_emissao' => '2026-04-04',
                    'data_competencia' => '2026-04-04',
                    'valor_total' => 50000.00,
                ],
            ],
            'titles' => [
                [
                    'titulo_key' => 'T-2003',
                    'pedido_id_omie' => '2003',
                    'codigo_pedido_origem' => 'P-2003',
                    'nota_key' => 'NF-2003',
                    'status_financeiro' => 'liquidado',
                    'valor_liquidado' => 50000.00,
                    'numero_documento' => 'RCB-2003',
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'kpis' => [
                'pedido_faturado' => 1,
                'nf_autorizada' => 1,
                'receita_comercial' => 50000.0,
                'receita_fiscal' => 50000.0,
                'financeiro_realizado' => 50000.0,
            ],
            'divergences' => [],
        ],
    ],

    [
        'id' => 'nf_cancelada_titulo_ja_liquidado',
        'description' => 'Conflito fiscal x financeiro: NF cancelada mas titulo ja foi liquidado antes.',
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '2004',
                    'codigo_pedido' => 'P-2004',
                    'status_comercial' => 'faturado',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '303',
                    'data_emissao' => '2026-04-01',
                    'data_faturamento' => '2026-04-01',
                    'valor_total' => 4200.00,
                ],
            ],
            'invoices' => [
                [
                    'nota_key' => 'NF-2004',
                    'chave_nfe' => 'NFE-2004',
                    'status_fiscal' => 'cancelada',
                    'pedido_id_omie' => '2004',
                    'codigo_pedido_origem' => 'P-2004',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '303',
                    'data_emissao' => '2026-04-01',
                    'data_cancelamento' => '2026-04-02',
                    'valor_total' => 4200.00,
                ],
            ],
            'titles' => [
                [
                    'titulo_key' => 'T-2004',
                    'pedido_id_omie' => '2004',
                    'codigo_pedido_origem' => 'P-2004',
                    'nota_key' => 'NF-2004',
                    'status_financeiro' => 'liquidado',
                    'valor_liquidado' => 4200.00,
                    'numero_documento' => 'RCB-2004',
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'kpis' => [
                'pedido_faturado' => 1,
                'nf_autorizada' => 0,
                'nf_cancelada' => 1,
                'receita_comercial' => 4200.0,
                'receita_fiscal' => 0.0,
                'financeiro_realizado' => 4200.0,
            ],
            'divergences' => ['pedido_faturado_sem_nf_valida'],
        ],
    ],

    [
        'id' => 'pedido_devolvido_com_titulo_aberto',
        'description' => 'Pedido devolvido com titulo ainda aberto deve ficar tipado como conflito de dominio.',
        'canonical_input' => [
            'orders' => [
                [
                    'pedido_id_omie' => '1014',
                    'codigo_pedido' => 'P-1014',
                    'status_comercial' => 'devolvido',
                    'codigo_vendedor_omie' => '10',
                    'codigo_cliente_omie' => '209',
                    'data_emissao' => '2026-03-24',
                    'valor_total' => 990.00,
                ],
            ],
            'titles' => [
                [
                    'titulo_key' => 'T-1014',
                    'pedido_id_omie' => '1014',
                    'codigo_pedido_origem' => 'P-1014',
                    'status_financeiro' => 'aberto',
                    'valor_aberto' => 990.00,
                ],
            ],
            'vendors' => [
                ['codigo_vendedor_omie' => '10', 'active' => true],
            ],
        ],
        'expected' => [
            'kpis' => [
                'pedido_aberto' => 0,
                'pedido_faturado' => 0,
                'financeiro_realizado' => 0.0,
            ],
            'divergences' => ['pedido_devolvido_com_titulo_aberto'],
        ],
    ],
];
