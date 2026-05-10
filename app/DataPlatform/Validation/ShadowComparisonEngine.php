<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Validation;

use BIomie\Support;

final class ShadowComparisonEngine
{
    public function __construct(
        private readonly ShadowTolerancePolicy $policy = new ShadowTolerancePolicy(),
        private readonly ShadowValidationFormatter $formatter = new ShadowValidationFormatter(),
    ) {
    }

    /**
     * @return array{diff_type:string,severity:string,summary:string,details:array<string,mixed>}
     */
    public function comparePayloads(string $endpoint, array $legacy, array $candidate): array
    {
        return match ($endpoint) {
            'overview' => $this->compareOverview($legacy, $candidate),
            'orders' => $this->compareOrders($legacy, $candidate),
            'vendors' => $this->compareVendors($legacy, $candidate),
            'finance_overview' => $this->compareFinanceOverview($legacy, $candidate),
            'finance_titles' => $this->compareFinanceTitles($legacy, $candidate),
            'reconciliation' => $this->compareReconciliation($legacy, $candidate),
            default => $this->compareGeneric($legacy, $candidate),
        };
    }

    private function compareOverview(array $legacy, array $candidate): array
    {
        return $this->buildComparisonResult('overview', [
            'summary' => $this->numericDiffs($legacy, $candidate, [
                'summary.revenue', 'summary.gross_revenue', 'summary.orders',
                'summary.pending_orders', 'summary.cancelled_orders',
                'summary.active_vendors', 'summary.avg_ticket',
            ]),
            'vendor_ranking' => $this->compareKeySets(
                $this->extractKeys($legacy['vendor_ranking'] ?? [], ['vendor_id', 'vendor_name']),
                $this->extractKeys($candidate['vendor_ranking'] ?? [], ['vendor_id', 'vendor_name']),
                12
            ),
            'divergences' => $this->compareKeySets(
                $this->extractKeys($legacy['divergences'] ?? [], ['key', 'entity_key', 'message']),
                $this->extractKeys($candidate['divergences'] ?? [], ['key', 'entity_key', 'message']),
                10
            ),
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    private function compareOrders(array $legacy, array $candidate): array
    {
        return $this->buildComparisonResult('orders', [
            'summary' => $this->numericDiffs($legacy, $candidate, [
                'summary.revenue', 'summary.orders', 'summary.pending_orders',
                'summary.cancelled_orders', 'summary.active_vendors',
            ]),
            'orders' => $this->compareKeySets(
                $this->extractKeys($legacy['orders'] ?? [], ['codigo', 'codigo_pedido', 'order_id', 'pedido_id_omie']),
                $this->extractKeys($candidate['orders'] ?? [], ['codigo', 'codigo_pedido', 'order_id', 'pedido_id_omie']),
                30
            ),
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    private function compareVendors(array $legacy, array $candidate): array
    {
        return $this->buildComparisonResult('vendors', [
            'summary' => $this->numericDiffs($legacy, $candidate, [
                'summary.revenue', 'summary.orders', 'summary.pending_orders',
                'summary.cancelled_orders', 'summary.active_vendors',
                'summary_status.billed_value', 'summary_status.authorized_value',
                'summary_status.cancelled_value',
            ]),
            'vendors' => $this->compareKeySets(
                $this->extractKeys($legacy['vendor_status_matrix'] ?? $legacy['vendors'] ?? [], ['vendor_id', 'vendor_name']),
                $this->extractKeys($candidate['vendor_status_matrix'] ?? $candidate['vendors'] ?? [], ['vendor_id', 'vendor_name']),
                20
            ),
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    private function compareFinanceOverview(array $legacy, array $candidate): array
    {
        return $this->buildComparisonResult('finance_overview', [
            'summary' => $this->numericDiffs($legacy, $candidate, [
                'summary.received', 'summary.open', 'summary.overdue',
                'summary.received_titles', 'summary.open_titles',
                'summary.overdue_titles', 'summary.vendors',
            ]),
            'received_rows' => $this->compareKeySets(
                $this->extractKeys($legacy['received_rows'] ?? [], ['title_id', 'document', 'document_label']),
                $this->extractKeys($candidate['received_rows'] ?? [], ['title_id', 'document', 'document_label']),
                20
            ),
            'open_titles' => $this->compareKeySets(
                $this->extractKeys($legacy['open_titles'] ?? [], ['title_id', 'document', 'document_label']),
                $this->extractKeys($candidate['open_titles'] ?? [], ['title_id', 'document', 'document_label']),
                20
            ),
            'overdue_titles' => $this->compareKeySets(
                $this->extractKeys($legacy['overdue_titles'] ?? [], ['title_id', 'document', 'document_label']),
                $this->extractKeys($candidate['overdue_titles'] ?? [], ['title_id', 'document', 'document_label']),
                20
            ),
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    private function compareFinanceTitles(array $legacy, array $candidate): array
    {
        $bucketLegacy = trim((string) ($legacy['bucket'] ?? 'open'));
        $bucketCandidate = trim((string) ($candidate['bucket'] ?? 'open'));

        return $this->buildComparisonResult('finance_titles', [
            'bucket' => $bucketLegacy !== $bucketCandidate ? ['legacy' => $bucketLegacy, 'candidate' => $bucketCandidate] : [],
            'rows' => $this->compareKeySets(
                $this->extractKeys($legacy['rows'] ?? [], ['title_id', 'document', 'document_label']),
                $this->extractKeys($candidate['rows'] ?? [], ['title_id', 'document', 'document_label']),
                30
            ),
            'totals' => [
                'row_count' => [
                    'legacy' => is_array($legacy['rows'] ?? null) ? count($legacy['rows']) : 0,
                    'candidate' => is_array($candidate['rows'] ?? null) ? count($candidate['rows']) : 0,
                ],
                'amount_total' => [
                    'legacy' => $this->sumRowAmounts($legacy['rows'] ?? []),
                    'candidate' => $this->sumRowAmounts($candidate['rows'] ?? []),
                ],
            ],
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    private function compareReconciliation(array $legacy, array $candidate): array
    {
        return $this->buildComparisonResult('reconciliation', [
            'totals' => $this->numericDiffs($legacy, $candidate, [
                'totals.faturado', 'totals.autorizado', 'totals.cancelado',
                'totals.pedidos_faturados', 'totals.pedidos_autorizados',
                'totals.pedidos_cancelados', 'divergences_count',
            ]),
            'vendors' => $this->compareKeySets(
                $this->extractKeys($legacy['vendors'] ?? [], ['codigo_vendedor', 'nome']),
                $this->extractKeys($candidate['vendors'] ?? [], ['codigo_vendedor', 'nome']),
                20
            ),
            'divergences' => $this->compareKeySets(
                $this->extractKeys($legacy['divergences'] ?? [], ['key', 'entity_key', 'message']),
                $this->extractKeys($candidate['divergences'] ?? [], ['key', 'entity_key', 'message']),
                20
            ),
            'freshness' => $this->compareFreshness($legacy, $candidate),
        ]);
    }

    private function compareGeneric(array $legacy, array $candidate): array
    {
        $legacyHash = $this->formatter->payloadHash($legacy);
        $candidateHash = $this->formatter->payloadHash($candidate);
        $details = ['legacy_hash' => $legacyHash, 'candidate_hash' => $candidateHash];

        return $legacyHash === $candidateHash
            ? ['diff_type' => 'match', 'severity' => 'none', 'summary' => 'Shadow sem diferencas materiais.', 'details' => $details]
            : ['diff_type' => 'payload_mismatch', 'severity' => 'medium', 'summary' => 'Shadow encontrou diferenca de payload bruto.', 'details' => $details];
    }

    private function buildComparisonResult(string $endpoint, array $sections): array
    {
        $material = [];
        $maxSeverity = 'none';
        foreach ($sections as $name => $section) {
            if (!$this->sectionHasDiff($section)) {
                continue;
            }
            $material[$name] = $section;
            $maxSeverity = $this->formatter->maxSeverity($maxSeverity, $this->severityForSection($section));
        }

        if ($material === []) {
            return [
                'diff_type' => 'match',
                'severity' => 'none',
                'summary' => sprintf('Shadow do endpoint %s sem diferencas materiais.', $endpoint),
                'details' => $sections,
            ];
        }

        return [
            'diff_type' => 'material_difference',
            'severity' => $maxSeverity,
            'summary' => sprintf(
                'Shadow do endpoint %s encontrou diferenca em %d bloco(s): %s.',
                $endpoint,
                count($material),
                implode(', ', array_keys($material))
            ),
            'details' => $sections,
        ];
    }

    private function severityForSection(array $section): string
    {
        if (($section['missing_count'] ?? 0) > 0 || ($section['extra_count'] ?? 0) > 0) {
            return 'high';
        }
        if (($section['mismatches_count'] ?? 0) > 0) {
            return 'medium';
        }
        if (($section['legacy'] ?? null) !== ($section['candidate'] ?? null)) {
            return 'medium';
        }

        return 'low';
    }

    private function sectionHasDiff(array $section): bool
    {
        if ($section === []) {
            return false;
        }
        if (($section['missing_count'] ?? 0) > 0 || ($section['extra_count'] ?? 0) > 0 || ($section['mismatches_count'] ?? 0) > 0) {
            return true;
        }
        if (array_key_exists('legacy', $section) && array_key_exists('candidate', $section)) {
            if (is_numeric($section['legacy']) || is_numeric($section['candidate'])) {
                return abs((float) $section['legacy'] - (float) $section['candidate']) > $this->policy->numericTolerance();
            }

            return $section['legacy'] !== $section['candidate'];
        }

        return false;
    }

    private function numericDiffs(array $legacy, array $candidate, array $paths): array
    {
        $mismatches = [];
        foreach ($paths as $path) {
            $legacyValue = Support::toFloat(Support::get($legacy, $path, 0));
            $candidateValue = Support::toFloat(Support::get($candidate, $path, 0));
            if (abs($legacyValue - $candidateValue) <= $this->policy->moneyTolerance()) {
                continue;
            }
            $mismatches[$path] = [
                'legacy' => $legacyValue,
                'candidate' => $candidateValue,
                'delta' => Support::money($candidateValue - $legacyValue),
            ];
        }

        return ['mismatches_count' => count($mismatches), 'mismatches' => $mismatches];
    }

    private function compareKeySets(array $legacyKeys, array $candidateKeys, int $previewLimit = 20): array
    {
        $legacyKeys = array_values(array_unique(array_filter($legacyKeys, static fn (string $key): bool => trim($key) !== '')));
        $candidateKeys = array_values(array_unique(array_filter($candidateKeys, static fn (string $key): bool => trim($key) !== '')));
        $missing = array_values(array_diff($legacyKeys, $candidateKeys));
        $extra = array_values(array_diff($candidateKeys, $legacyKeys));

        return [
            'legacy_count' => count($legacyKeys),
            'candidate_count' => count($candidateKeys),
            'missing_count' => count($missing),
            'extra_count' => count($extra),
            'missing' => array_slice($missing, 0, $previewLimit),
            'extra' => array_slice($extra, 0, $previewLimit),
        ];
    }

    private function extractKeys(mixed $rows, array $keys): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $extracted = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($keys as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $extracted[] = $value;
                    break;
                }
            }
        }

        return $extracted;
    }

    private function compareFreshness(array $legacy, array $candidate): array
    {
        $diffs = [];
        foreach (['data_contract.freshness_at', 'data_contract.coverage_start', 'data_contract.coverage_end', 'data_contract.reconciled_until', 'data_contract.data_mode'] as $path) {
            $legacyValue = Support::get($legacy, $path, null);
            $candidateValue = Support::get($candidate, $path, null);
            if ($legacyValue === $candidateValue) {
                continue;
            }
            $diffs[$path] = ['legacy' => $legacyValue, 'candidate' => $candidateValue];
        }

        return ['mismatches_count' => count($diffs), 'mismatches' => $diffs];
    }

    private function sumRowAmounts(mixed $rows): float
    {
        if (!is_array($rows)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $sum += Support::toFloat($row['amount_open'] ?? $row['amount_net'] ?? 0);
            }
        }

        return Support::money($sum);
    }
}
