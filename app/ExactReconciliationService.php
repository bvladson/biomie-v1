<?php

declare(strict_types=1);

namespace BIomie;

final class ExactReconciliationService
{
    /** @var array<string,array<string,string>> */
    private const CATALOG = [
        'pedido_sem_vendedor' => [
            'severity' => 'high',
            'entity_type' => 'pedido_comercial',
            'source_domain' => 'commercial',
            'source_origin' => 'biomie_pedidos_canonicos',
            'suggested_action' => 'Revisar cadastro do pedido e resolver o vendedor comercial no Omie.',
        ],
        'nf_sem_pedido_vinculado' => [
            'severity' => 'high',
            'entity_type' => 'nota_fiscal',
            'source_domain' => 'fiscal',
            'source_origin' => 'biomie_notas_fiscais_canonicas',
            'suggested_action' => 'Validar o vinculo da NF com o pedido de origem antes de consolidar a trilha comercial.',
        ],
        'pedido_faturado_sem_nf_valida' => [
            'severity' => 'critical',
            'entity_type' => 'pedido_comercial',
            'source_domain' => 'cross_domain',
            'source_origin' => 'pedido_x_nf',
            'suggested_action' => 'Confirmar o faturamento comercial e localizar a NF autorizada correspondente.',
        ],
        'nf_autorizada_sem_vendedor_resolvido' => [
            'severity' => 'high',
            'entity_type' => 'nota_fiscal',
            'source_domain' => 'fiscal',
            'source_origin' => 'biomie_notas_fiscais_canonicas',
            'suggested_action' => 'Resolver o vendedor vinculado na NF ou ajustar o cadastro comercial correspondente.',
        ],
        'pedido_cancelado_com_nf_ativa' => [
            'severity' => 'critical',
            'entity_type' => 'pedido_comercial',
            'source_domain' => 'cross_domain',
            'source_origin' => 'pedido_x_nf',
            'suggested_action' => 'Verificar cancelamento do pedido e o status fiscal da NF para evitar saldo incorreto.',
        ],
        'pedido_em_carteira_ja_faturado' => [
            'severity' => 'high',
            'entity_type' => 'pedido_comercial',
            'source_domain' => 'cross_domain',
            'source_origin' => 'pedido_x_nf',
            'suggested_action' => 'Atualizar a etapa comercial ou revisar o vinculo fiscal para retirar o pedido da carteira.',
        ],
        'diferenca_valor_pedido_nf' => [
            'severity' => 'medium',
            'entity_type' => 'cross_domain',
            'source_domain' => 'cross_domain',
            'source_origin' => 'pedido_x_nf',
            'suggested_action' => 'Conferir descontos, itens e faturamento parcial entre pedido e NF.',
        ],
        'diferenca_data_comercial_fiscal' => [
            'severity' => 'medium',
            'entity_type' => 'cross_domain',
            'source_domain' => 'cross_domain',
            'source_origin' => 'pedido_x_nf',
            'suggested_action' => 'Confirmar se a data comercial e a data fiscal devem divergir para este caso.',
        ],
        'diferenca_cliente_pedido_nf' => [
            'severity' => 'high',
            'entity_type' => 'cross_domain',
            'source_domain' => 'cross_domain',
            'source_origin' => 'pedido_x_nf',
            'suggested_action' => 'Comparar cliente do pedido com o destinatario fiscal antes de consolidar receita.',
        ],
        'duplicidade_chave_natural' => [
            'severity' => 'critical',
            'entity_type' => 'generic',
            'source_domain' => 'reconciliation',
            'source_origin' => 'canonical',
            'suggested_action' => 'Revisar a chave natural e remover a origem de duplicidade para evitar contagem dupla.',
        ],
        'mudanca_retroativa_detectada' => [
            'severity' => 'medium',
            'entity_type' => 'generic',
            'source_domain' => 'bronze',
            'source_origin' => 'biomie_omie_bronze',
            'suggested_action' => 'Reprocessar a janela afetada e revisar o impacto nos indicadores consolidados.',
        ],
    ];

    public function __construct(
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
        private readonly ?BronzeRepository $bronze = null,
    ) {
    }

    /**
     * @return array<string,array<string,string>>
     */
    public function catalog(): array
    {
        return self::CATALOG;
    }

    /**
     * @return array{
     *   period:array{start:string,end:string},
     *   scanned_rules:int,
     *   issues_found:int,
     *   by_rule:array<string,int>
     * }
     */
    public function runPeriod(string $start, string $end): array
    {
        $telemetry = Telemetry::global();
        $span = $telemetry?->startSpan('reconcile.exact_period', [
            'period_start' => $start,
            'period_end' => $end,
        ], 'reconciliation', 'exact_reconciliation');
        try {
            $orders = $this->canonical->listOrderRowsForReconciliation($start, $end);
            $invoices = $this->canonical->listInvoiceRowsForReconciliation($start, $end);
            $titles = $this->canonical->listFinancialTitlesByPeriod($start, $end);
            $vendorMap = $this->canonical->getVendorMap();

            $byRule = [];
            $issues = [];

        $ordersById = [];
        $ordersByCode = [];
        foreach ($orders as $order) {
            $orderId = trim((string) ($order['pedido_id_omie'] ?? ''));
            $orderCode = trim((string) ($order['codigo_pedido'] ?? ''));
            $orderReference = $this->orderReference($order);
            if ($orderId !== '') {
                $ordersById[$orderId] = $order;
            }
            if ($orderCode !== '') {
                $ordersByCode[$orderCode] = $order;
            }

            if (trim((string) ($order['codigo_vendedor_omie'] ?? '')) === '') {
                $issues[] = $this->issue(
                    'pedido_sem_vendedor',
                    $orderReference,
                    sprintf('Pedido %s esta sem vendedor resolvido.', (string) ($order['codigo_pedido'] ?? $orderId)),
                    [
                        'pedido_id_omie' => $order['pedido_id_omie'] ?? null,
                        'codigo_pedido' => $order['codigo_pedido'] ?? null,
                    ]
                );
            }
        }

        $invoiceGroups = [];
        foreach ($invoices as $invoice) {
            $noteKey = trim((string) ($invoice['nota_key'] ?? ''));
            $status = (string) ($invoice['status_fiscal'] ?? '');
            $orderId = trim((string) ($invoice['pedido_id_omie'] ?? ''));
            $orderCode = trim((string) ($invoice['codigo_pedido_origem'] ?? ''));

            if ($status === 'autorizada' && $orderId === '' && $orderCode === '') {
                $issues[] = $this->issue(
                    'nf_sem_pedido_vinculado',
                    'nota:' . $noteKey,
                    sprintf('NF %s foi autorizada sem pedido vinculado.', (string) ($invoice['numero_nf'] ?? $noteKey)),
                    ['nota_key' => $noteKey, 'chave_nfe' => $invoice['chave_nfe'] ?? null]
                );
            }

            $vendorId = trim((string) ($invoice['codigo_vendedor_omie'] ?? ''));
            if ($status === 'autorizada' && ($vendorId === '' || !isset($vendorMap[$vendorId]))) {
                $issues[] = $this->issue(
                    'nf_autorizada_sem_vendedor_resolvido',
                    'nota:' . $noteKey,
                    sprintf('NF %s esta autorizada sem vendedor resolvido.', (string) ($invoice['numero_nf'] ?? $noteKey)),
                    ['nota_key' => $noteKey, 'codigo_vendedor_omie' => $vendorId !== '' ? $vendorId : null]
                );
            }

            foreach ([$orderId, $orderCode] as $key) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                $invoiceGroups[$key][] = $invoice;
            }
        }

        foreach ($orders as $order) {
            $status = (string) ($order['status_comercial'] ?? 'outro');
            $orderId = trim((string) ($order['pedido_id_omie'] ?? ''));
            $orderCode = trim((string) ($order['codigo_pedido'] ?? ''));
            $orderReference = $this->orderReference($order);
            $linkedInvoices = array_merge($invoiceGroups[$orderId] ?? [], $invoiceGroups[$orderCode] ?? []);
            $linkedAuthorized = array_values(array_filter($linkedInvoices, static fn (array $invoice): bool => (string) ($invoice['status_fiscal'] ?? '') === 'autorizada'));

            if ($status === 'faturado' && $linkedAuthorized === []) {
                $issues[] = $this->issue(
                    'pedido_faturado_sem_nf_valida',
                    $orderReference,
                    sprintf('Pedido %s esta faturado sem NF valida vinculada.', $orderCode !== '' ? $orderCode : $orderId),
                    ['pedido_id_omie' => $order['pedido_id_omie'] ?? null, 'codigo_pedido' => $order['codigo_pedido'] ?? null]
                );
            }

            if ($status === 'cancelado' && $linkedAuthorized !== []) {
                $issues[] = $this->issue(
                    'pedido_cancelado_com_nf_ativa',
                    $orderReference,
                    sprintf('Pedido %s foi cancelado, mas ainda possui NF ativa.', $orderCode !== '' ? $orderCode : $orderId),
                    ['pedido_id_omie' => $order['pedido_id_omie'] ?? null, 'codigo_pedido' => $order['codigo_pedido'] ?? null]
                );
            }

            if (in_array($status, ['autorizado', 'outro'], true) && $linkedAuthorized !== []) {
                $issues[] = $this->issue(
                    'pedido_em_carteira_ja_faturado',
                    $orderReference,
                    sprintf('Pedido %s ainda aparece em carteira, mas ja possui NF autorizada.', $orderCode !== '' ? $orderCode : $orderId),
                    ['pedido_id_omie' => $order['pedido_id_omie'] ?? null, 'codigo_pedido' => $order['codigo_pedido'] ?? null]
                );
            }

            if ($linkedAuthorized === []) {
                continue;
            }

            $invoiceTotal = 0.0;
            $firstInvoice = $linkedAuthorized[0];
            foreach ($linkedAuthorized as $invoice) {
                $invoiceTotal += (float) ($invoice['valor_total'] ?? 0);
            }

            $orderTotal = (float) ($order['valor_total'] ?? 0);
            if (abs($orderTotal - $invoiceTotal) > 0.01) {
                $issues[] = $this->issue(
                    'diferenca_valor_pedido_nf',
                    $orderReference,
                    sprintf('Pedido %s diverge de valor entre comercial e fiscal.', $orderCode !== '' ? $orderCode : $orderId),
                    [
                        'pedido_id_omie' => $order['pedido_id_omie'] ?? null,
                        'valor_pedido' => $orderTotal,
                        'valor_nf' => Support::money($invoiceTotal),
                    ]
                );
            }

            $commercialDate = Support::formatDate((string) ($order['data_faturamento'] ?? $order['data_emissao'] ?? ''));
            $fiscalDate = Support::formatDate((string) ($firstInvoice['data_emissao'] ?? $firstInvoice['data_competencia'] ?? ''));
            if ($commercialDate !== null && $fiscalDate !== null && $commercialDate !== $fiscalDate) {
                $issues[] = $this->issue(
                    'diferenca_data_comercial_fiscal',
                    $orderReference,
                    sprintf('Pedido %s tem datas comercial e fiscal diferentes.', $orderCode !== '' ? $orderCode : $orderId),
                    [
                        'pedido_id_omie' => $order['pedido_id_omie'] ?? null,
                        'data_comercial' => $commercialDate,
                        'data_fiscal' => $fiscalDate,
                    ]
                );
            }

            $orderClient = trim((string) ($order['codigo_cliente_omie'] ?? $order['documento_cliente'] ?? ''));
            $invoiceClient = trim((string) ($firstInvoice['codigo_cliente_omie'] ?? $firstInvoice['documento_cliente'] ?? ''));
            if ($orderClient !== '' && $invoiceClient !== '' && $orderClient !== $invoiceClient) {
                $issues[] = $this->issue(
                    'diferenca_cliente_pedido_nf',
                    $orderReference,
                    sprintf('Pedido %s tem cliente diferente da NF vinculada.', $orderCode !== '' ? $orderCode : $orderId),
                    [
                        'pedido_id_omie' => $order['pedido_id_omie'] ?? null,
                        'cliente_pedido' => $order['codigo_cliente_omie'] ?? $order['documento_cliente'] ?? null,
                        'cliente_nf' => $firstInvoice['codigo_cliente_omie'] ?? $firstInvoice['documento_cliente'] ?? null,
                    ]
                );
            }
        }

        $issues = array_merge($issues, $this->detectDuplicates($orders, $invoices));
        $issues = array_merge($issues, $this->detectRetroactiveChanges($start, $end));

        foreach ($issues as $issue) {
            $ruleCode = (string) ($issue['rule_code'] ?? '');
            $byRule[$ruleCode] = (int) ($byRule[$ruleCode] ?? 0) + 1;
            $this->canonical->logDivergence(
                (string) ($issue['tipo'] ?? $ruleCode),
                (string) ($issue['referencia'] ?? ''),
                (string) ($issue['descricao'] ?? ''),
                is_array($issue['contexto'] ?? null) ? $issue['contexto'] : null,
                [
                    'rule_code' => $ruleCode,
                    'severity' => $issue['severity'] ?? 'medium',
                    'entity_type' => $issue['entity_type'] ?? 'generic',
                    'source_domain' => $issue['source_domain'] ?? 'reconciliation',
                    'source_origin' => $issue['source_origin'] ?? null,
                    'suggested_action' => $issue['suggested_action'] ?? null,
                    'period_start' => $start,
                    'period_end' => $end,
                ]
            );
        }

            $this->logger->info('Reconciliacao exata executada.', [
                'period_start' => $start,
                'period_end' => $end,
                'issues_found' => count($issues),
                'rules' => $byRule,
                'titles_checked' => count($titles),
            ]);

            $telemetry?->recordMetric('reconcile_issues_found_total', count($issues), 'counter', [
                'type' => 'exact',
            ], 'reconciliation', 'exact_reconciliation');
            foreach ($byRule as $rule => $total) {
                $telemetry?->recordMetric('divergence_rule_total', (int) $total, 'counter', [
                    'rule_code' => (string) $rule,
                ], 'reconciliation', 'exact_reconciliation');
            }

            $result = [
                'period' => ['start' => $start, 'end' => $end],
                'scanned_rules' => count(self::CATALOG),
                'issues_found' => count($issues),
                'by_rule' => $byRule,
            ];
            $span?->finish('ok', [
                'issues_found' => count($issues),
                'scanned_rules' => count(self::CATALOG),
            ]);

            return $result;
        } catch (\Throwable $exception) {
            $telemetry?->recordEvent('ERROR', 'reconcile.error', 'Falha na reconciliacao exata.', [
                'period_start' => $start,
                'period_end' => $end,
                'message' => $exception->getMessage(),
            ], 'reconciliation', 'exact_reconciliation');
            $span?->finish('error', [
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @param list<array<string,mixed>> $invoices
     * @return list<array<string,mixed>>
     */
    private function detectDuplicates(array $orders, array $invoices): array
    {
        $issues = [];

        $orderGroups = [];
        foreach ($orders as $order) {
            $key = trim((string) ($order['codigo_pedido'] ?? ''));
            if ($key === '') {
                continue;
            }
            $orderGroups[$key][] = $order;
        }

        foreach ($orderGroups as $key => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $issues[] = $this->issue(
                'duplicidade_chave_natural',
                'pedido_codigo:' . $key,
                sprintf('Codigo de pedido %s apareceu duplicado na camada canonica.', $key),
                ['entity_type' => 'pedido_comercial', 'rows' => count($rows)]
            );
        }

        $invoiceGroups = [];
        foreach ($invoices as $invoice) {
            $key = trim((string) ($invoice['chave_nfe'] ?? ''));
            if ($key === '') {
                continue;
            }
            $invoiceGroups[$key][] = $invoice;
        }

        foreach ($invoiceGroups as $key => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $issues[] = $this->issue(
                'duplicidade_chave_natural',
                'chave_nfe:' . $key,
                sprintf('Chave NF-e %s apareceu duplicada na camada canonica.', $key),
                ['entity_type' => 'nota_fiscal', 'rows' => count($rows)]
            );
        }

        return $issues;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function detectRetroactiveChanges(string $start, string $end): array
    {
        if ($this->bronze === null) {
            return [];
        }

        $issues = [];
        $rows = $this->bronze->findRetroactiveChanges($start, $end, ['orders', 'invoices', 'finance', 'order_events'], 100);
        foreach ($rows as $row) {
            $reference = sprintf(
                '%s:%s',
                (string) ($row['entity_name'] ?? 'entity'),
                (string) ($row['natural_key'] ?? $row['provider_key'] ?? '?')
            );
            $issues[] = $this->issue(
                'mudanca_retroativa_detectada',
                $reference,
                sprintf(
                    'A entidade %s/%s mudou retroativamente na bronze com %d payloads distintos.',
                    (string) ($row['domain_name'] ?? 'domain'),
                    (string) ($row['entity_name'] ?? 'entity'),
                    (int) ($row['distinct_payloads'] ?? 0)
                ),
                $row
            );
        }

        return $issues;
    }

    /**
     * @param array<string,mixed> $order
     */
    private function orderReference(array $order): string
    {
        $orderId = trim((string) ($order['pedido_id_omie'] ?? ''));
        if ($orderId !== '') {
            return 'pedido:' . $orderId;
        }

        $orderCode = trim((string) ($order['codigo_pedido'] ?? ''));
        if ($orderCode !== '') {
            return 'pedido_codigo:' . $orderCode;
        }

        return 'pedido_hash:' . substr(hash('sha256', json_encode($order)), 0, 16);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function issue(string $ruleCode, string $reference, string $description, array $context = []): array
    {
        $definition = self::CATALOG[$ruleCode] ?? [
            'severity' => 'medium',
            'entity_type' => 'generic',
            'source_domain' => 'reconciliation',
            'source_origin' => 'reconciliation',
            'suggested_action' => 'Revisar a divergencia na trilha de origem.',
        ];

        if (isset($context['entity_type']) && is_string($context['entity_type']) && $context['entity_type'] !== '') {
            $definition['entity_type'] = $context['entity_type'];
        }

        return [
            'tipo' => $ruleCode,
            'rule_code' => $ruleCode,
            'referencia' => $reference,
            'descricao' => $description,
            'contexto' => $context,
            'severity' => $definition['severity'],
            'entity_type' => $definition['entity_type'],
            'source_domain' => $definition['source_domain'],
            'source_origin' => $definition['source_origin'],
            'suggested_action' => $definition['suggested_action'],
        ];
    }
}
