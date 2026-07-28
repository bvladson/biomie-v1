<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\TenantContext;

/**
 * Selects Gold only when every commercial core grain is equivalent to Canonical.
 *
 * Cards, daily series and vendor ranking must always come from the same bundle.
 * Gold is an acceleration layer; Canonical remains the fallback truth source.
 */
final class CommercialGoldReconciler
{
    private const MONEY_TOLERANCE = 0.01;

    /** @var array<string,array<string,mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly CanonicalRepository $canonical,
        private readonly ?GoldMartRepository $goldMart,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function reconcile(string $start, string $end): array
    {
        $cacheKey = $this->scopeFingerprint() . ':' . $start . ':' . $end;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $canonical = [
            'invoice_summary' => $this->canonical->summarizeInvoicesCommercial($start, $end),
            'commercial_summary' => $this->canonical->summarizeCommercial($start, $end),
            'daily_rows' => $this->canonical->dailyByStatusCommercial($start, $end),
            'vendor_rows' => $this->canonical->aggregateByVendorCommercial($start, $end),
        ];

        if ($this->goldMart === null || !$this->goldMart->isAvailableForPeriod($start, $end)) {
            return $this->cache[$cacheKey] = [
                'reconciled' => false,
                'selected_source' => 'canonical',
                'fallback_used' => true,
                'reason_code' => 'gold_unavailable',
                'checks' => [
                    'gold_available' => [
                        'ok' => false,
                        'expected' => true,
                        'actual' => false,
                    ],
                ],
                'canonical' => $canonical,
                'gold' => null,
            ];
        }

        $gold = [
            'invoice_summary' => $this->goldMart->summarizeInvoicesCommercial($start, $end),
            'commercial_summary' => $this->goldMart->summarizeCommercial($start, $end),
            'daily_rows' => $this->goldMart->dailyByStatusCommercial($start, $end),
            'vendor_rows' => $this->goldMart->aggregateByVendorCommercial($start, $end),
        ];

        return $this->cache[$cacheKey] = self::compareBundles($canonical, $gold) + [
            'canonical' => $canonical,
            'gold' => $gold,
        ];
    }

    /**
     * Pure comparison entrypoint used by the runtime gate and its regression test.
     *
     * @param array<string,mixed> $canonical
     * @param array<string,mixed> $gold
     * @return array{reconciled:bool,selected_source:string,fallback_used:bool,reason_code:?string,checks:array<string,array<string,mixed>>}
     */
    public static function compareBundles(array $canonical, array $gold): array
    {
        $checks = [
            'gold_available' => [
                'ok' => true,
                'expected' => true,
                'actual' => true,
            ],
        ];

        $canonicalInvoice = is_array($canonical['invoice_summary'] ?? null)
            ? $canonical['invoice_summary']
            : [];
        $goldInvoice = is_array($gold['invoice_summary'] ?? null)
            ? $gold['invoice_summary']
            : [];

        foreach (['billed_orders', 'active_vendors', 'active_clients'] as $field) {
            $checks['invoice_' . $field] = self::countCheck($canonicalInvoice, $goldInvoice, $field);
        }
        foreach (['billed_value', 'merchandise_total', 'discounts_total'] as $field) {
            $checks['invoice_' . $field] = self::moneyCheck($canonicalInvoice, $goldInvoice, $field);
        }

        $canonicalSummary = is_array($canonical['commercial_summary'] ?? null)
            ? $canonical['commercial_summary']
            : [];
        $goldSummary = is_array($gold['commercial_summary'] ?? null)
            ? $gold['commercial_summary']
            : [];
        foreach (['billed', 'authorized', 'cancelled'] as $status) {
            $checks['summary_' . $status . '_orders'] = self::countCheck(
                $canonicalSummary,
                $goldSummary,
                $status . '_orders',
            );
            $checks['summary_' . $status . '_value'] = self::moneyCheck(
                $canonicalSummary,
                $goldSummary,
                $status . '_value',
            );
        }

        $checks['daily_rows'] = self::groupedRowsCheck(
            self::rows($canonical, 'daily_rows'),
            self::rows($gold, 'daily_rows'),
            static fn (array $row): string => implode('|', [
                trim((string) ($row['dia'] ?? '')),
                mb_strtolower(trim((string) ($row['status_comercial'] ?? ''))),
            ]),
        );
        $checks['vendor_rows'] = self::groupedRowsCheck(
            self::rows($canonical, 'vendor_rows'),
            self::rows($gold, 'vendor_rows'),
            static function (array $row): string {
                $vendorId = trim((string) ($row['codigo_vendedor_omie'] ?? ''));
                $vendorName = mb_strtolower(trim((string) ($row['nome_vendedor'] ?? '')));
                $identity = $vendorId !== '' ? 'id:' . $vendorId : 'name:' . $vendorName;

                return $identity . '|' . mb_strtolower(trim((string) ($row['status_comercial'] ?? '')));
            },
        );

        foreach ($checks as $id => $check) {
            if (!(bool) ($check['ok'] ?? false)) {
                return [
                    'reconciled' => false,
                    'selected_source' => 'canonical',
                    'fallback_used' => true,
                    'reason_code' => $id . '_mismatch',
                    'checks' => $checks,
                ];
            }
        }

        return [
            'reconciled' => true,
            'selected_source' => 'gold',
            'fallback_used' => false,
            'reason_code' => null,
            'checks' => $checks,
        ];
    }

    /**
     * Removes the potentially large row bundles before exposing diagnostics.
     *
     * @param array<string,mixed> $reconciliation
     * @return array<string,mixed>
     */
    public static function diagnostics(array $reconciliation): array
    {
        unset($reconciliation['canonical'], $reconciliation['gold']);
        return $reconciliation;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private static function countCheck(array $left, array $right, string $field): array
    {
        $expected = (int) ($left[$field] ?? 0);
        $actual = (int) ($right[$field] ?? 0);

        return [
            'ok' => $expected === $actual,
            'expected' => $expected,
            'actual' => $actual,
            'delta' => $actual - $expected,
        ];
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private static function moneyCheck(array $left, array $right, string $field): array
    {
        $expected = Support::toFloat($left[$field] ?? 0);
        $actual = Support::toFloat($right[$field] ?? 0);
        $delta = $actual - $expected;

        return [
            'ok' => abs($delta) <= self::MONEY_TOLERANCE,
            'expected' => round($expected, 2),
            'actual' => round($actual, 2),
            'delta' => round($delta, 2),
            'tolerance' => self::MONEY_TOLERANCE,
        ];
    }

    /**
     * @param list<array<string,mixed>> $canonicalRows
     * @param list<array<string,mixed>> $goldRows
     * @param callable(array<string,mixed>):string $groupKey
     * @return array<string,mixed>
     */
    private static function groupedRowsCheck(array $canonicalRows, array $goldRows, callable $groupKey): array
    {
        $canonical = self::normalizeGroupedRows($canonicalRows, $groupKey);
        $gold = self::normalizeGroupedRows($goldRows, $groupKey);
        $keys = array_values(array_unique(array_merge(array_keys($canonical), array_keys($gold))));
        sort($keys, SORT_STRING);

        $mismatches = [];
        foreach ($keys as $key) {
            $expected = $canonical[$key] ?? ['value' => 0.0, 'orders' => 0];
            $actual = $gold[$key] ?? ['value' => 0.0, 'orders' => 0];
            $delta = $actual['value'] - $expected['value'];
            if ($expected['orders'] !== $actual['orders'] || abs($delta) > self::MONEY_TOLERANCE) {
                $mismatches[] = [
                    'key' => $key,
                    'expected_orders' => $expected['orders'],
                    'actual_orders' => $actual['orders'],
                    'expected_value' => round($expected['value'], 2),
                    'actual_value' => round($actual['value'], 2),
                ];
            }
        }

        return [
            'ok' => $mismatches === [],
            'expected_groups' => count($canonical),
            'actual_groups' => count($gold),
            'mismatch_count' => count($mismatches),
            'mismatches' => array_slice($mismatches, 0, 10),
            'tolerance' => self::MONEY_TOLERANCE,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):string $groupKey
     * @return array<string,array{value:float,orders:int}>
     */
    private static function normalizeGroupedRows(array $rows, callable $groupKey): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = trim($groupKey($row), '|');
            if ($key === '') {
                continue;
            }
            $groups[$key] ??= ['value' => 0.0, 'orders' => 0];
            $groups[$key]['value'] += Support::toFloat($row['total'] ?? $row['revenue'] ?? 0);
            $groups[$key]['orders'] += (int) ($row['pedidos'] ?? $row['orders'] ?? 0);
        }
        ksort($groups, SORT_STRING);

        return $groups;
    }

    /**
     * @param array<string,mixed> $bundle
     * @return list<array<string,mixed>>
     */
    private static function rows(array $bundle, string $key): array
    {
        $rows = $bundle[$key] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    private function scopeFingerprint(): string
    {
        if (!TenantContext::isBooted()) {
            return 'tenant-unbooted';
        }

        $companyIds = TenantContext::allowedCompanyIds();
        sort($companyIds, SORT_NUMERIC);

        return implode(':', [
            (string) TenantContext::companyId(),
            TenantContext::canal(),
            implode(',', $companyIds),
        ]);
    }
}
