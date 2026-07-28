<?php

declare(strict_types=1);

namespace BIomie;

final class ConsistencyHomologationService
{
    /**
     * @param list<array<string,mixed>> $cases
     * @return array<string,mixed>
     */
    public function runSuite(array $cases): array
    {
        $results = [];
        $passed = 0;
        $failed = 0;

        foreach ($cases as $case) {
            $result = $this->runCase($case);
            $results[] = $result;
            if ((bool) ($result['ok'] ?? false)) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'ok' => $failed === 0,
            'total' => count($results),
            'passed' => $passed,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    public function runCase(array $case): array
    {
        $canonical = is_array($case['canonical_input'] ?? null) ? $case['canonical_input'] : [];
        $actual = $this->evaluateCanonical($canonical);
        $expected = is_array($case['expected'] ?? null) ? $case['expected'] : [];
        $mismatches = [];

        if (isset($expected['canonical_state']) && is_array($expected['canonical_state'])) {
            $this->assertSubset($expected['canonical_state'], $actual['canonical_state'], 'canonical_state', $mismatches);
        }

        if (isset($expected['kpis']) && is_array($expected['kpis'])) {
            $this->assertSubset($expected['kpis'], $actual['kpis'], 'kpis', $mismatches);
        }

        if (isset($expected['divergences']) && is_array($expected['divergences'])) {
            $expectedDivergences = array_values(array_unique(array_map('strval', $expected['divergences'])));
            sort($expectedDivergences);
            $actualDivergences = array_values(array_unique(array_map('strval', $actual['divergences'])));
            sort($actualDivergences);

            if ($expectedDivergences !== $actualDivergences) {
                $mismatches[] = [
                    'path' => 'divergences',
                    'expected' => $expectedDivergences,
                    'actual' => $actualDivergences,
                ];
            }
        }

        return [
            'id' => (string) ($case['id'] ?? 'case'),
            'description' => (string) ($case['description'] ?? ''),
            'ok' => $mismatches === [],
            'mismatches' => $mismatches,
            'actual' => $actual,
        ];
    }

    /**
     * @param array<string,mixed> $canonical
     * @return array<string,mixed>
     */
    public function evaluateCanonical(array $canonical): array
    {
        $orders = array_values(array_filter((array) ($canonical['orders'] ?? []), 'is_array'));
        $invoices = array_values(array_filter((array) ($canonical['invoices'] ?? []), 'is_array'));
        $titles = array_values(array_filter((array) ($canonical['titles'] ?? []), 'is_array'));
        $vendors = array_values(array_filter((array) ($canonical['vendors'] ?? []), 'is_array'));
        $metadata = is_array($canonical['metadata'] ?? null) ? $canonical['metadata'] : [];

        $vendorMap = [];
        foreach ($vendors as $vendor) {
            $vendorId = trim((string) ($vendor['codigo_vendedor_omie'] ?? $vendor['vendor_id'] ?? ''));
            if ($vendorId !== '') {
                $vendorMap[$vendorId] = $vendor;
            }
        }

        $invoiceGroups = [];
        $invoiceStatusCounts = [
            'autorizada' => 0,
            'cancelada' => 0,
            'denegada' => 0,
            'devolvida' => 0,
        ];
        $receitaFiscal = 0.0;
        $fiscalCompetencias = [];
        $missingClientRegistry = 0;
        $inactiveVendorHistory = 0;

        foreach ($invoices as $invoice) {
            $status = strtolower(trim((string) ($invoice['status_fiscal'] ?? '')));
            if (isset($invoiceStatusCounts[$status])) {
                $invoiceStatusCounts[$status]++;
            }

            $orderId = trim((string) ($invoice['pedido_id_omie'] ?? ''));
            $orderCode = trim((string) ($invoice['codigo_pedido_origem'] ?? $invoice['codigo_pedido'] ?? ''));
            foreach ([$orderId, $orderCode] as $key) {
                if ($key !== '') {
                    $invoiceGroups[$key][] = $invoice;
                }
            }

            if ($status === 'autorizada') {
                $receitaFiscal += Support::toFloat($invoice['valor_total'] ?? 0);
            }

            $competencia = $this->resolveFiscalCompetencia($invoice);
            if ($competencia !== null) {
                $fiscalCompetencias[$competencia] = (int) ($fiscalCompetencias[$competencia] ?? 0) + 1;
            }

            if ((bool) ($invoice['client_registry_missing'] ?? false)) {
                $missingClientRegistry++;
            }

            $vendorId = trim((string) ($invoice['codigo_vendedor_omie'] ?? ''));
            if ($vendorId !== '' && isset($vendorMap[$vendorId]) && !(bool) ($vendorMap[$vendorId]['active'] ?? true)) {
                $inactiveVendorHistory++;
            }
        }

        $orderStatusCounts = [
            'autorizado' => 0,
            'faturado' => 0,
            'cancelado' => 0,
            'devolvido' => 0,
            'outro' => 0,
        ];
        $pedidoAberto = 0;
        $pedidoFaturado = 0;
        $receitaComercial = 0.0;
        $financeiroRealizado = 0.0;
        $commercialCompetencias = [];
        $missingVendorOrders = 0;
        $crossMonthMismatches = 0;
        $divergences = [];
        $titleGroups = [];

        foreach ($titles as $title) {
            $status = strtolower(trim((string) ($title['status_financeiro'] ?? $title['status'] ?? '')));
            if (in_array($status, ['liquidado', 'recebido', 'pago'], true)) {
                $financeiroRealizado += Support::toFloat($title['valor_liquidado'] ?? $title['valor_pago'] ?? 0);
            }

            $hasOrigin = trim((string) ($title['documento_origem_ref'] ?? '')) !== ''
                || trim((string) ($title['nota_key'] ?? '')) !== ''
                || trim((string) ($title['pedido_id_omie'] ?? '')) !== ''
                || trim((string) ($title['codigo_pedido_origem'] ?? '')) !== '';
            if (!$hasOrigin && in_array($status, ['liquidado', 'recebido', 'pago'], true)) {
                $divergences[] = 'titulo_financeiro_sem_origem';
            }

            $pedidoId = trim((string) ($title['pedido_id_omie'] ?? ''));
            $pedidoCodigo = trim((string) ($title['codigo_pedido_origem'] ?? ''));
            foreach ([$pedidoId, $pedidoCodigo] as $key) {
                if ($key !== '') {
                    $titleGroups[$key][] = $title;
                }
            }
        }

        foreach ($orders as $order) {
            $status = strtolower(trim((string) ($order['status_comercial'] ?? 'outro')));
            if (!isset($orderStatusCounts[$status])) {
                $status = 'outro';
            }
            $orderStatusCounts[$status]++;

            $orderId = trim((string) ($order['pedido_id_omie'] ?? ''));
            $orderCode = trim((string) ($order['codigo_pedido'] ?? ''));
            $linkedInvoices = $this->deduplicateLinkedInvoices(array_merge($invoiceGroups[$orderId] ?? [], $invoiceGroups[$orderCode] ?? []));
            $authorizedInvoices = array_values(array_filter(
                $linkedInvoices,
                static fn (array $invoice): bool => strtolower(trim((string) ($invoice['status_fiscal'] ?? ''))) === 'autorizada'
            ));
            $linkedTitles = $this->deduplicateLinkedTitles(array_merge($titleGroups[$orderId] ?? [], $titleGroups[$orderCode] ?? []));

            $vendorId = trim((string) ($order['codigo_vendedor_omie'] ?? ''));
            if ($vendorId === '') {
                $missingVendorOrders++;
                $divergences[] = 'pedido_sem_vendedor';
            } elseif (isset($vendorMap[$vendorId]) && !(bool) ($vendorMap[$vendorId]['active'] ?? true)) {
                $inactiveVendorHistory++;
            }

            if ((bool) ($order['client_registry_missing'] ?? false)) {
                $missingClientRegistry++;
            }

            if (in_array($status, ['autorizado', 'outro'], true) && $authorizedInvoices === []) {
                $pedidoAberto++;
            }

            if ($status === 'faturado' || (bool) ($order['vinculo_faturamento'] ?? false)) {
                $pedidoFaturado++;
                $receitaComercial += Support::toFloat($order['valor_total'] ?? 0);
            }

            $competencia = $this->resolveCommercialCompetencia($order);
            if ($competencia !== null) {
                $commercialCompetencias[$competencia] = (int) ($commercialCompetencias[$competencia] ?? 0) + 1;
            }

            if ($status === 'faturado' && $authorizedInvoices === []) {
                $divergences[] = 'pedido_faturado_sem_nf_valida';
            }

            if ($status === 'cancelado' && $authorizedInvoices !== []) {
                $divergences[] = 'pedido_cancelado_com_nf_ativa';
            }

            if (in_array($status, ['autorizado', 'outro'], true) && $authorizedInvoices !== []) {
                $divergences[] = 'pedido_em_carteira_ja_faturado';
            }

            if ($authorizedInvoices !== []) {
                $invoiceTotal = array_reduce($authorizedInvoices, static function (float $sum, array $invoice): float {
                    return $sum + Support::toFloat($invoice['valor_total'] ?? 0);
                }, 0.0);

                if (abs(Support::toFloat($order['valor_total'] ?? 0) - $invoiceTotal) > 0.01) {
                    $divergences[] = 'diferenca_valor_pedido_nf';
                }

                $orderClient = trim((string) ($order['codigo_cliente_omie'] ?? $order['documento_cliente'] ?? ''));
                $invoiceClient = trim((string) ($authorizedInvoices[0]['codigo_cliente_omie'] ?? $authorizedInvoices[0]['documento_cliente'] ?? ''));
                if ($orderClient !== '' && $invoiceClient !== '' && $orderClient !== $invoiceClient) {
                    $divergences[] = 'diferenca_cliente_pedido_nf';
                }

                $commercialDate = $this->resolveCommercialDate($order);
                $fiscalDate = $this->resolveFiscalDate($authorizedInvoices[0]);
                if ($commercialDate !== null && $fiscalDate !== null && $commercialDate !== $fiscalDate) {
                    $divergences[] = 'diferenca_data_comercial_fiscal';
                }

                $commercialMonth = $this->resolveCommercialCompetencia($order);
                $fiscalMonth = $this->resolveFiscalCompetencia($authorizedInvoices[0]);
                if ($commercialMonth !== null && $fiscalMonth !== null && $commercialMonth !== $fiscalMonth) {
                    $crossMonthMismatches++;
                }
            }

            if ($status === 'devolvido') {
                foreach ($linkedTitles as $title) {
                    $titleStatus = strtolower(trim((string) ($title['status_financeiro'] ?? $title['status'] ?? '')));
                    $valorAberto = Support::toFloat($title['valor_aberto'] ?? 0);
                    if (!in_array($titleStatus, ['liquidado', 'recebido', 'pago'], true) && $valorAberto > 0.01) {
                        $divergences[] = 'pedido_devolvido_com_titulo_aberto';
                        break;
                    }
                }
            }
        }

        foreach ($invoices as $invoice) {
            $status = strtolower(trim((string) ($invoice['status_fiscal'] ?? '')));
            if ($status !== 'autorizada') {
                continue;
            }

            $orderId = trim((string) ($invoice['pedido_id_omie'] ?? ''));
            $orderCode = trim((string) ($invoice['codigo_pedido_origem'] ?? $invoice['codigo_pedido'] ?? ''));
            if ($orderId === '' && $orderCode === '') {
                $divergences[] = 'nf_sem_pedido_vinculado';
            }

            $vendorId = trim((string) ($invoice['codigo_vendedor_omie'] ?? ''));
            if ($vendorId === '' || !isset($vendorMap[$vendorId])) {
                $divergences[] = 'nf_autorizada_sem_vendedor_resolvido';
            }
        }

        if ($this->hasDuplicateNaturalKeys($orders, ['codigo_pedido', 'pedido_id_omie'])) {
            $divergences[] = 'duplicidade_chave_natural';
        }
        if ($this->hasDuplicateNaturalKeys($invoices, ['chave_nfe', 'nota_key'])) {
            $divergences[] = 'duplicidade_chave_natural';
        }
        if ($this->hasDuplicateNaturalKeys($titles, ['codigo_titulo', 'documento_origem'])) {
            $divergences[] = 'duplicidade_chave_natural';
        }

        $retroactiveChanges = 0;
        if ((bool) ($metadata['retroactive_change_detected'] ?? false)) {
            $retroactiveChanges++;
        }
        foreach (array_merge($orders, $invoices, $titles) as $row) {
            if ((bool) ($row['retroactive_change_detected'] ?? false)) {
                $retroactiveChanges++;
            }
        }
        if ($retroactiveChanges > 0) {
            $divergences[] = 'mudanca_retroativa_detectada';
        }

        $divergences = array_values(array_unique($divergences));
        sort($divergences);
        ksort($commercialCompetencias);
        ksort($fiscalCompetencias);

        return [
            'canonical_state' => [
                'orders_total' => count($orders),
                'invoices_total' => count($invoices),
                'titles_total' => count($titles),
                'order_status_counts' => $orderStatusCounts,
                'invoice_status_counts' => $invoiceStatusCounts,
                'missing_vendor_orders' => $missingVendorOrders,
                'missing_client_registry' => $missingClientRegistry,
                'inactive_vendor_history' => $inactiveVendorHistory,
                'retroactive_changes' => $retroactiveChanges,
                'cross_month_competencia_mismatches' => $crossMonthMismatches,
                'commercial_competencias' => $commercialCompetencias,
                'fiscal_competencias' => $fiscalCompetencias,
            ],
            'kpis' => [
                'pedido_aberto' => $pedidoAberto,
                'pedido_faturado' => $pedidoFaturado,
                'nf_autorizada' => (int) ($invoiceStatusCounts['autorizada'] ?? 0),
                'nf_cancelada' => (int) ($invoiceStatusCounts['cancelada'] ?? 0),
                'receita_comercial' => round($receitaComercial, 2),
                'receita_fiscal' => round($receitaFiscal, 2),
                'financeiro_realizado' => round($financeiroRealizado, 2),
            ],
            'divergences' => $divergences,
        ];
    }

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $actual
     * @param list<array<string,mixed>> $mismatches
     */
    private function assertSubset(array $expected, array $actual, string $path, array &$mismatches): void
    {
        foreach ($expected as $key => $expectedValue) {
            $currentPath = $path . '.' . $key;
            $actualValue = $actual[$key] ?? null;

            if (is_array($expectedValue) && is_array($actualValue)) {
                $this->assertSubset($expectedValue, $actualValue, $currentPath, $mismatches);
                continue;
            }

            if ($expectedValue !== $actualValue) {
                $mismatches[] = [
                    'path' => $currentPath,
                    'expected' => $expectedValue,
                    'actual' => $actualValue,
                ];
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $keys
     */
    private function hasDuplicateNaturalKeys(array $rows, array $keys): bool
    {
        $seen = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($keys as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }

            if ($parts === []) {
                continue;
            }

            $signature = implode('|', $parts);
            if (isset($seen[$signature])) {
                return true;
            }

            $seen[$signature] = true;
        }

        return false;
    }

    private function resolveCommercialDate(array $order): ?string
    {
        foreach (['data_faturamento', 'data_emissao'] as $field) {
            $value = Support::formatDate((string) ($order[$field] ?? ''));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function resolveFiscalDate(array $invoice): ?string
    {
        foreach (['data_emissao', 'data_competencia', 'data_cancelamento'] as $field) {
            $value = Support::formatDate((string) ($invoice[$field] ?? ''));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function resolveCommercialCompetencia(array $order): ?string
    {
        $date = Support::formatDate((string) ($order['data_emissao'] ?? ''));
        return $date !== null ? substr($date, 0, 7) : null;
    }

    private function resolveFiscalCompetencia(array $invoice): ?string
    {
        foreach (['data_competencia', 'data_emissao', 'data_cancelamento'] as $field) {
            $date = Support::formatDate((string) ($invoice[$field] ?? ''));
            if ($date !== null) {
                return substr($date, 0, 7);
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function deduplicateLinkedInvoices(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $signature = trim((string) ($row['nota_key'] ?? $row['chave_nfe'] ?? ''));
            if ($signature === '') {
                $signature = md5(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($row));
            }

            $unique[$signature] = $row;
        }

        return array_values($unique);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function deduplicateLinkedTitles(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $signature = trim((string) ($row['titulo_key'] ?? $row['titulo_id_omie'] ?? $row['numero_documento'] ?? ''));
            if ($signature === '') {
                $signature = md5(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($row));
            }

            $unique[$signature] = $row;
        }

        return array_values($unique);
    }
}
