<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\Logger;
use BIomie\RequestContext;
use BIomie\Support;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Gera sinais comerciais estruturados para o card "Pontos de Atenção".
 *
 * Recebe dados já computados pelo CanonicalOverviewPayloadBuilder para evitar
 * queries repetidas. Uma única query extra (carteira parada) pode ser feita via PDO.
 *
 * Contrato de saída: attention_signals[], attention_summary{}, attention_diagnostics{}.
 */
final class AttentionSignalsBuilder
{
    private const MAX_SIGNALS           = 6;
    private const STALE_DAYS            = 5;
    private const CANCEL_WARNING_PCT    = 20.0;
    private const CANCEL_DANGER_PCT     = 30.0;
    private const WALLET_DANGER_AMOUNT  = 30000.0;
    private const WALLET_DANGER_ORDERS  = 10;
    private const FORECAST_DANGER_PCT   = 70.0;
    private const FORECAST_WARNING_PCT  = 95.0;
    private const FINANCE_DANGER_AMOUNT = 50000.0;
    private const FINANCE_DANGER_RATIO  = 0.20;

    private const SEVERITY_ORDER = ['danger' => 0, 'warning' => 1, 'success' => 2, 'info' => 3];

    /**
     * @param array<string,mixed> $context Pre-computed overview data.
     * @return array{attention_signals:list<array<string,mixed>>,attentionSignals:list<array<string,mixed>>,attention_summary:array<string,mixed>,attention_diagnostics:array<string,mixed>}
     */
    public function build(
        PDO $pdo,
        Logger $logger,
        string $start,
        string $end,
        string $requestId,
        array $context
    ): array {
        $t0 = microtime(true);

        $diagnostics = [
            'rules_evaluated' => 0,
            'rules_failed'    => 0,
            'rules_skipped'   => 0,
            'errors'          => [],
            'query_count'     => 0,
        ];

        $signals   = [];
        $vendorRanking  = (array) ($context['vendor_ranking'] ?? []);
        $planningRows   = (array) ($context['planning_rows'] ?? []);
        $grossRevenue   = (float) ($context['gross_revenue'] ?? 0);
        $pendingSummary = (array) ($context['pending_summary'] ?? []);
        $planning       = (array) ($context['planning'] ?? []);
        $topClientsSource = (string) ($context['top_clients_source'] ?? 'canonical');
        $financeSummary = isset($context['finance_summary']) && is_array($context['finance_summary'])
            ? $context['finance_summary']
            : null;

        // ── Regra 1: Maior vendedor do período ───────────────────────────────
        $diagnostics['rules_evaluated']++;
        try {
            $topVendor = null;
            foreach ($vendorRanking as $row) {
                $rev = (float) ($row['revenue'] ?? $row['gross_revenue'] ?? 0);
                if ($rev > 0 && trim((string) ($row['vendor_name'] ?? '')) !== ''
                    && strcasecmp((string) ($row['vendor_name'] ?? ''), 'Sem vendedor') !== 0
                ) {
                    $topVendor = $row;
                    break;
                }
            }

            if ($topVendor !== null) {
                $vid   = trim((string) ($topVendor['vendor_id'] ?? ''));
                $vname = trim((string) ($topVendor['vendor_name'] ?? 'Sem vendedor'));
                $rev   = (float) ($topVendor['revenue'] ?? $topVendor['gross_revenue'] ?? 0);
                $orders = (int) ($topVendor['orders'] ?? 0);

                $signals[] = [
                    'id'           => 'top_vendor_' . str_replace('-', '_', substr($start, 0, 7)),
                    'severity'     => 'success',
                    'category'     => 'destaque',
                    'title'        => 'Maior vendedor do período',
                    'value'        => $vname,
                    'amount'       => $rev,
                    'amount_label' => 'Receita',
                    'description'  => 'Lidera o período em faturamento autorizado por data_competência.',
                    'metric'       => [
                        'name'      => 'receita_fiscal',
                        'value'     => $rev,
                        'formatted' => self::formatBRL($rev),
                        'unit'      => 'BRL',
                    ],
                    'context' => [
                        'vendor_id'       => $vid,
                        'vendor_name'     => $vname,
                        'documents_count' => $orders,
                    ],
                    'action' => $vid !== '' ? [
                        'label' => 'Ver vendedor',
                        'url'   => 'vendedores.php?start=' . urlencode($start) . '&end=' . urlencode($end) . '&vendor_id=' . urlencode($vid),
                    ] : null,
                    'source'       => in_array($topClientsSource, ['canonical', 'mart_cliente_periodo'], true) ? 'canonical' : 'gold',
                    'period_basis' => 'data_competencia',
                    'reason_code'  => null,
                ];
            }
        } catch (Throwable $e) {
            $diagnostics['rules_failed']++;
            $diagnostics['errors'][] = self::captureError('top_vendor', $e, $requestId);
        }

        // ── Regra 2: Carteira parada ──────────────────────────────────────────
        $diagnostics['rules_evaluated']++;
        try {
            $staleData = $this->queryStaleOrders($pdo, $end);
            $diagnostics['query_count']++;

            if ($staleData !== null && (int) ($staleData['total_orders'] ?? 0) > 0) {
                $totalOrders  = (int) $staleData['total_orders'];
                $totalAmount  = (float) ($staleData['total_amount'] ?? 0);
                $vendorNames  = (array) ($staleData['vendor_names'] ?? []);

                $isDanger = $totalAmount >= self::WALLET_DANGER_AMOUNT
                    || $totalOrders >= self::WALLET_DANGER_ORDERS;

                $vendorLabel = implode(', ', array_filter(array_slice($vendorNames, 0, 3)));
                $extraVendors = max(0, count($vendorNames) - 3);

                $signals[] = [
                    'id'           => 'wallet_stale_' . str_replace('-', '_', $end),
                    'severity'     => $isDanger ? 'danger' : 'warning',
                    'category'     => 'carteira',
                    'title'        => 'Carteira parada',
                    'value'        => $totalOrders . ' pedido(s) autorizado(s) há mais de ' . self::STALE_DAYS . ' dias',
                    'amount'       => $totalAmount,
                    'amount_label' => 'Carteira',
                    'description'  => $vendorLabel
                        . ($extraVendors > 0 ? ' e mais ' . $extraVendors . ' vendedor(es).' : '.')
                        . ' Base: data_emissão do pedido.',
                    'metric' => [
                        'name'      => 'carteira_parada',
                        'value'     => $totalOrders,
                        'formatted' => $totalOrders . ' pedidos',
                        'unit'      => 'count',
                    ],
                    'context' => [
                        'stale_days'   => self::STALE_DAYS,
                        'vendor_names' => $vendorNames,
                        'order_count'  => $totalOrders,
                    ],
                    'action' => [
                        'label' => 'Ver carteira',
                        'url'   => 'vendedores.php?start=' . urlencode($start) . '&end=' . urlencode($end) . '&tab=carteira',
                    ],
                    'source'       => 'canonical',
                    'period_basis' => 'data_emissao',
                    'reason_code'  => null,
                ];
            }
        } catch (Throwable $e) {
            $diagnostics['rules_failed']++;
            $diagnostics['errors'][] = self::captureError('wallet_stale', $e, $requestId);
        }

        // ── Regra 3: Cancelamento alto por vendedor ───────────────────────────
        $diagnostics['rules_evaluated']++;
        try {
            $cancelData = $this->computeCancellationByVendor($planningRows);
            foreach ($cancelData as $vData) {
                $cancelPct = $vData['cancel_pct'];
                if ($cancelPct < self::CANCEL_WARNING_PCT || $vData['total_docs'] < 3) {
                    continue;
                }

                $severity = $cancelPct >= self::CANCEL_DANGER_PCT ? 'danger' : 'warning';
                $vid      = (string) ($vData['vendor_id'] ?? '');
                $vname    = (string) ($vData['vendor_name'] ?? 'Sem vendedor');

                $signals[] = [
                    'id'           => 'cancel_high_' . preg_replace('/[^a-z0-9]/', '_', mb_strtolower($vid ?: $vname)),
                    'severity'     => $severity,
                    'category'     => 'cancelamento',
                    'title'        => 'Cancelamento alto',
                    'value'        => $vname . ': ' . round($cancelPct, 1) . '%',
                    'amount'       => $vData['cancelled_value'],
                    'amount_label' => 'Canceladas',
                    'description'  => $vData['cancelled_docs'] . ' cancelado(s) de ' . $vData['total_docs'] . ' documentos no período.',
                    'metric'       => [
                        'name'      => 'taxa_cancelamento',
                        'value'     => $cancelPct,
                        'formatted' => round($cancelPct, 1) . '%',
                        'unit'      => 'pct',
                    ],
                    'context' => [
                        'vendor_id'      => $vid,
                        'vendor_name'    => $vname,
                        'total_docs'     => $vData['total_docs'],
                        'cancelled_docs' => $vData['cancelled_docs'],
                        'cancel_pct'     => round($cancelPct, 2),
                    ],
                    'action' => $vid !== '' ? [
                        'label' => 'Ver vendedor',
                        'url'   => 'vendedores.php?start=' . urlencode($start) . '&end=' . urlencode($end) . '&vendor_id=' . urlencode($vid),
                    ] : null,
                    'source'       => 'canonical',
                    'period_basis' => 'data_competencia',
                    'reason_code'  => null,
                ];
                break; // apenas o pior vendedor por sinal de cancelamento
            }
        } catch (Throwable $e) {
            $diagnostics['rules_failed']++;
            $diagnostics['errors'][] = self::captureError('cancel_high', $e, $requestId);
        }

        // ── Regra 4/5: Meta fora do ritmo / no ritmo ─────────────────────────
        $diagnostics['rules_evaluated']++;
        try {
            $planVendors = is_array($planning['vendors'] ?? null) ? $planning['vendors'] : [];
            $teamTarget  = (float) ($planning['team']['target_period'] ?? 0);

            if ($teamTarget > 0 && $planVendors !== []) {
                $dangerVendors   = [];
                $warningVendors  = [];
                $onTrackVendors  = [];

                foreach ($planVendors as $vp) {
                    $target   = (float) ($vp['target_period'] ?? 0);
                    $forecast = (float) ($vp['forecast_period'] ?? 0);
                    $vname    = trim((string) ($vp['vendor_name'] ?? ''));

                    if ($target <= 0 || $vname === '' || strcasecmp($vname, 'Sem vendedor') === 0) {
                        continue;
                    }

                    $forecastPct = $forecast / $target * 100;

                    if ($forecastPct < self::FORECAST_DANGER_PCT) {
                        $dangerVendors[] = ['name' => $vname, 'pct' => $forecastPct, 'gap' => (float) ($vp['gap_to_target'] ?? 0), 'id' => (string) ($vp['vendor_id'] ?? '')];
                    } elseif ($forecastPct < self::FORECAST_WARNING_PCT) {
                        $warningVendors[] = ['name' => $vname, 'pct' => $forecastPct, 'gap' => (float) ($vp['gap_to_target'] ?? 0), 'id' => (string) ($vp['vendor_id'] ?? '')];
                    } else {
                        $onTrackVendors[] = ['name' => $vname, 'pct' => $forecastPct, 'id' => (string) ($vp['vendor_id'] ?? '')];
                    }
                }

                foreach ($dangerVendors as $v) {
                    $signals[] = [
                        'id'           => 'meta_risco_danger_' . preg_replace('/[^a-z0-9]/', '_', mb_strtolower($v['id'] ?: $v['name'])),
                        'severity'     => 'danger',
                        'category'     => 'meta',
                        'title'        => 'Meta em risco crítico',
                        'value'        => $v['name'],
                        'amount'       => $v['gap'],
                        'amount_label' => 'Gap',
                        'description'  => 'Projeção: ' . round($v['pct'], 1) . '% da meta. Abaixo da linha de ' . self::FORECAST_DANGER_PCT . '%.',
                        'metric'       => ['name' => 'forecast_pct', 'value' => $v['pct'], 'formatted' => round($v['pct'], 1) . '%', 'unit' => 'pct'],
                        'context'      => ['vendor_id' => $v['id'], 'vendor_name' => $v['name'], 'forecast_pct' => round($v['pct'], 2)],
                        'action'       => ['label' => 'Ver Plano de Voo', 'url' => 'plano_voo.php?start=' . urlencode($start) . '&end=' . urlencode($end)],
                        'source'       => 'canonical',
                        'period_basis' => 'year_month',
                        'reason_code'  => null,
                    ];
                }

                foreach ($warningVendors as $v) {
                    $signals[] = [
                        'id'           => 'meta_risco_warning_' . preg_replace('/[^a-z0-9]/', '_', mb_strtolower($v['id'] ?: $v['name'])),
                        'severity'     => 'warning',
                        'category'     => 'meta',
                        'title'        => 'Meta em atenção',
                        'value'        => $v['name'],
                        'amount'       => $v['gap'],
                        'amount_label' => 'Gap',
                        'description'  => 'Projeção: ' . round($v['pct'], 1) . '% da meta. Abaixo da linha de ' . self::FORECAST_WARNING_PCT . '%.',
                        'metric'       => ['name' => 'forecast_pct', 'value' => $v['pct'], 'formatted' => round($v['pct'], 1) . '%', 'unit' => 'pct'],
                        'context'      => ['vendor_id' => $v['id'], 'vendor_name' => $v['name'], 'forecast_pct' => round($v['pct'], 2)],
                        'action'       => ['label' => 'Ver Plano de Voo', 'url' => 'plano_voo.php?start=' . urlencode($start) . '&end=' . urlencode($end)],
                        'source'       => 'canonical',
                        'period_basis' => 'year_month',
                        'reason_code'  => null,
                    ];
                }

                if ($onTrackVendors !== [] && $dangerVendors === [] && $warningVendors === []) {
                    $names = implode(', ', array_slice(array_column($onTrackVendors, 'name'), 0, 2));
                    $signals[] = [
                        'id'           => 'meta_no_ritmo_' . str_replace('-', '_', substr($start, 0, 7)),
                        'severity'     => 'success',
                        'category'     => 'meta',
                        'title'        => 'Meta no ritmo',
                        'value'        => $names,
                        'amount'       => null,
                        'amount_label' => null,
                        'description'  => count($onTrackVendors) . ' vendedor(es) com projeção acima do ritmo esperado.',
                        'metric'       => ['name' => 'on_track_count', 'value' => count($onTrackVendors), 'formatted' => count($onTrackVendors) . ' vendedores', 'unit' => 'count'],
                        'context'      => ['on_track_count' => count($onTrackVendors)],
                        'action'       => ['label' => 'Ver Plano de Voo', 'url' => 'plano_voo.php?start=' . urlencode($start) . '&end=' . urlencode($end)],
                        'source'       => 'canonical',
                        'period_basis' => 'year_month',
                        'reason_code'  => null,
                    ];
                }
            } elseif ($planVendors !== [] && $teamTarget <= 0) {
                // Há vendedores mas sem metas
                $diagnostics['rules_skipped']++;
            } else {
                // Plano de voo indisponível ou sem dados
                $diagnostics['rules_skipped']++;
            }
        } catch (Throwable $e) {
            $diagnostics['rules_failed']++;
            $diagnostics['errors'][] = self::captureError('meta_ritmo', $e, $requestId);
        }

        // ── Regra 6: Financeiro vencido ───────────────────────────────────────
        $diagnostics['rules_evaluated']++;
        try {
            if ($financeSummary !== null && (bool) ($financeSummary['available'] ?? false)) {
                $overdueAmount = (float) ($financeSummary['overdue'] ?? $financeSummary['overdue_amount'] ?? 0);

                if ($overdueAmount > 0) {
                    $overdueRatio = $grossRevenue > 0 ? $overdueAmount / $grossRevenue : 0;
                    $isDanger = $overdueAmount >= self::FINANCE_DANGER_AMOUNT
                        || $overdueRatio >= self::FINANCE_DANGER_RATIO;

                    $signals[] = [
                        'id'           => 'finance_overdue_' . str_replace('-', '_', substr($start, 0, 7)),
                        'severity'     => $isDanger ? 'danger' : 'warning',
                        'category'     => 'financeiro',
                        'title'        => 'Recebíveis em atraso',
                        'value'        => self::formatBRL($overdueAmount),
                        'amount'       => $overdueAmount,
                        'amount_label' => 'Em atraso',
                        'description'  => 'Há títulos vencidos que exigem acompanhamento financeiro.',
                        'metric'       => ['name' => 'overdue_amount', 'value' => $overdueAmount, 'formatted' => self::formatBRL($overdueAmount), 'unit' => 'BRL'],
                        'context'      => ['overdue_ratio_pct' => round($overdueRatio * 100, 2)],
                        'action'       => ['label' => 'Ver financeiro', 'url' => 'financeiro.php?start=' . urlencode($start) . '&end=' . urlencode($end)],
                        'source'       => 'canonical',
                        'period_basis' => 'data_vencimento',
                        'reason_code'  => null,
                    ];
                }
            } else {
                $diagnostics['rules_skipped']++;
            }
        } catch (Throwable $e) {
            $diagnostics['rules_failed']++;
            $diagnostics['errors'][] = self::captureError('finance_overdue', $e, $requestId);
        }

        // ── Regra 7: Nenhum ponto crítico ────────────────────────────────────
        $diagnostics['rules_evaluated']++;
        try {
            $hasCritical = false;
            foreach ($signals as $s) {
                if (in_array($s['severity'] ?? '', ['danger', 'warning'], true)) {
                    $hasCritical = true;
                    break;
                }
            }

            if (!$hasCritical && $grossRevenue > 0) {
                $signals[] = [
                    'id'           => 'no_critical_signals_' . str_replace('-', '_', substr($start, 0, 7)),
                    'severity'     => 'success',
                    'category'     => 'operacao',
                    'title'        => 'Operação sem alertas críticos',
                    'value'        => 'Período saudável',
                    'amount'       => null,
                    'amount_label' => null,
                    'description'  => 'Não foram encontrados sinais críticos nos filtros atuais.',
                    'metric'       => null,
                    'context'      => [],
                    'action'       => null,
                    'source'       => 'canonical',
                    'period_basis' => 'data_competencia',
                    'reason_code'  => null,
                ];
            }
        } catch (Throwable $e) {
            $diagnostics['rules_failed']++;
            $diagnostics['errors'][] = self::captureError('no_critical', $e, $requestId);
        }

        // ── Se tudo falhou ────────────────────────────────────────────────────
        if ($signals === [] && $diagnostics['rules_failed'] > 0) {
            $signals[] = [
                'id'           => 'attention_unavailable',
                'severity'     => 'info',
                'category'     => 'dados',
                'title'        => 'Sinais indisponíveis',
                'value'        => null,
                'amount'       => null,
                'amount_label' => null,
                'description'  => 'Não foi possível avaliar os sinais comerciais neste momento.',
                'metric'       => null,
                'context'      => [],
                'action'       => null,
                'source'       => 'empty',
                'period_basis' => null,
                'reason_code'  => 'attention_builder_error',
            ];
        }

        // ── Ordenar sinais ────────────────────────────────────────────────────
        usort($signals, static function (array $a, array $b): int {
            $orderA = self::SEVERITY_ORDER[$a['severity'] ?? 'info'] ?? 3;
            $orderB = self::SEVERITY_ORDER[$b['severity'] ?? 'info'] ?? 3;
            if ($orderA !== $orderB) {
                return $orderA - $orderB;
            }
            // Dentro da mesma severidade, maior amount primeiro
            return (float) ($b['amount'] ?? 0) <=> (float) ($a['amount'] ?? 0);
        });

        // ── Limitar e computar summary ────────────────────────────────────────
        $hiddenCount = max(0, count($signals) - self::MAX_SIGNALS);
        $visibleSignals = array_values(array_slice($signals, 0, self::MAX_SIGNALS));

        $summary = ['total' => count($signals), 'success' => 0, 'info' => 0, 'warning' => 0, 'danger' => 0, 'hidden_count' => $hiddenCount];
        $highestSeverity = 'none';
        foreach ($signals as $s) {
            $sev = $s['severity'] ?? 'info';
            if (isset($summary[$sev])) {
                $summary[$sev]++;
            }
            if ((self::SEVERITY_ORDER[$sev] ?? 99) < (self::SEVERITY_ORDER[$highestSeverity] ?? 99)) {
                $highestSeverity = $sev;
            }
        }
        $summary['highest_severity'] = $highestSeverity;
        $summary['source']           = $grossRevenue > 0 ? 'canonical' : 'empty';
        $summary['generated_at']     = (new DateTimeImmutable())->format('c');

        $diagnostics['runtime_ms'] = (int) round((microtime(true) - $t0) * 1000);
        if ($diagnostics['runtime_ms'] > 200) {
            $diagnostics['performance_warning'] = 'runtime_ms > 200ms — revisar queries';
        }

        return [
            'attention_signals'     => $visibleSignals,
            'attentionSignals'      => $visibleSignals,
            'attention_summary'     => $summary,
            'attention_diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function queryStaleOrders(PDO $pdo, string $end): ?array
    {
        $cutoff = (new DateTimeImmutable($end))
            ->modify('-' . self::STALE_DAYS . ' days')
            ->format('Y-m-d');

        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $stmt = $pdo->prepare(<<<SQL
            SELECT
                COUNT(*)                        AS total_orders,
                COALESCE(SUM(valor_total), 0)   AS total_amount,
                GROUP_CONCAT(
                    DISTINCT COALESCE(nome_vendedor, 'Sem vendedor')
                    ORDER BY nome_vendedor
                    SEPARATOR '||'
                )                               AS vendor_list
            FROM biomie_pedidos_canonicos
            WHERE {$tenantFilter}
              AND status_comercial = 'autorizado'
              AND data_emissao <= :cutoff
        SQL);
        $stmt->execute(['cutoff' => $cutoff]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || (int) ($row['total_orders'] ?? 0) === 0) {
            return null;
        }

        $vendorNames = array_values(array_filter(
            array_unique(explode('||', (string) ($row['vendor_list'] ?? ''))),
            static fn (string $v): bool => $v !== ''
        ));

        return [
            'total_orders'  => (int) ($row['total_orders'] ?? 0),
            'total_amount'  => (float) ($row['total_amount'] ?? 0),
            'vendor_names'  => $vendorNames,
        ];
    }

    /**
     * @param list<array<string,mixed>> $planningRows Raw rows from aggregateByVendorCommercial
     * @return list<array<string,mixed>> Sorted by cancel_pct descending
     */
    private function computeCancellationByVendor(array $planningRows): array
    {
        $byVendor = [];
        foreach ($planningRows as $row) {
            $vid    = trim((string) ($row['codigo_vendedor_omie'] ?? $row['vendor_id'] ?? ''));
            $vname  = trim((string) ($row['nome_vendedor'] ?? $row['vendor_name'] ?? 'Sem vendedor'));
            $status = (string) ($row['status_comercial'] ?? $row['status_fiscal'] ?? '');
            $docs   = (int) ($row['pedidos'] ?? $row['notes_count'] ?? $row['orders'] ?? 1);
            $total  = (float) ($row['total'] ?? $row['invoice_total'] ?? 0);
            $key    = $vid !== '' ? $vid : 'name:' . mb_strtolower($vname);

            if (!isset($byVendor[$key])) {
                $byVendor[$key] = ['vendor_id' => $vid, 'vendor_name' => $vname, 'billed_docs' => 0, 'cancelled_docs' => 0, 'cancelled_value' => 0.0];
            }

            if (in_array($status, ['faturado', 'autorizado'], true)) {
                $byVendor[$key]['billed_docs'] += $docs;
            } elseif (str_contains($status, 'cancel')) {
                $byVendor[$key]['cancelled_docs'] += $docs;
                $byVendor[$key]['cancelled_value'] += $total;
            }
        }

        $result = [];
        foreach ($byVendor as $v) {
            $total     = $v['billed_docs'] + $v['cancelled_docs'];
            $cancelPct = $total > 0 ? ($v['cancelled_docs'] / $total) * 100 : 0.0;
            $result[]  = array_merge($v, ['total_docs' => $total, 'cancel_pct' => $cancelPct]);
        }

        usort($result, static fn (array $a, array $b): int => $b['cancel_pct'] <=> $a['cancel_pct']);

        return $result;
    }

    private static function formatBRL(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    /**
     * @return array<string,string>
     */
    private static function captureError(string $rule, Throwable $e, string $requestId): array
    {
        return [
            'rule'       => $rule,
            'message'    => $e->getMessage(),
            'class'      => get_class($e),
            'request_id' => $requestId,
        ];
    }
}
