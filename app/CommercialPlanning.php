<?php

declare(strict_types=1);

namespace BIomie;

use DateTimeImmutable;

final class CommercialPlanning
{
    /**
     * @param list<array<string,mixed>> $vendorRows
     * @param array<string,array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,currency:string,updated_at:?string}> $targetsMap
     * @return array{
     *   vendors:list<array<string,mixed>>,
     *   team:array{target_period:float,realized_period:float,backlog_value:float,forecast_period:float,gap_to_target:float,goal_progress:float,missing_targets_count:int,workdays:array<string,mixed>,forecasts:array{base:float,probable:float,commit:float}},
     *   months:list<string>
     * }
     */
    public static function build(array $vendorRows, array $targetsMap, string $start, string $end, float $saturdayWeight = 0.33): array
    {
        $months = self::monthsInRange($start, $end);
        $today = new DateTimeImmutable('today');
        $workdays = WorkingDays::summarize($start, $end, $today, $saturdayWeight);

        $vendorsByKey = [];
        foreach ($vendorRows as $row) {
            $vendorId = trim((string) ($row['codigo_vendedor_omie'] ?? ''));
            $vendorName = trim((string) ($row['nome_vendedor'] ?? 'Sem vendedor'));
            $key = self::vendorKey($vendorId, $vendorName);

            if (!isset($vendorsByKey[$key])) {
                $vendorsByKey[$key] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => $vendorName !== '' ? $vendorName : 'Sem vendedor',
                    'realized_period' => 0.0,
                    'backlog_value' => 0.0,
                    'orders' => 0,
                    'merchandise_total' => 0.0,
                    'discount_total' => 0.0,
                ];
            }

            $status = (string) ($row['status_comercial'] ?? 'outro');
            $total = Support::toFloat($row['total'] ?? 0);
            $orders = (int) ($row['pedidos'] ?? 0);
            $merchandise = Support::toFloat($row['mercadorias'] ?? 0);
            $discount = Support::toFloat($row['descontos'] ?? 0);

            if ($status === 'faturado') {
                $vendorsByKey[$key]['realized_period'] += $total;
                $vendorsByKey[$key]['orders'] += $orders;
                $vendorsByKey[$key]['merchandise_total'] += $merchandise;
                $vendorsByKey[$key]['discount_total'] += $discount;
            } elseif ($status === 'autorizado') {
                $vendorsByKey[$key]['backlog_value'] += $total;
                $vendorsByKey[$key]['orders'] += $orders;
                $vendorsByKey[$key]['merchandise_total'] += $merchandise;
                $vendorsByKey[$key]['discount_total'] += $discount;
            }
        }

        foreach ($targetsMap as $targetRow) {
            $vendorId = trim((string) ($targetRow['vendor_id'] ?? ''));
            $vendorName = trim((string) ($targetRow['vendor_name'] ?? 'Sem vendedor'));
            $key = self::vendorKey($vendorId, $vendorName);
            if (!isset($vendorsByKey[$key])) {
                $vendorsByKey[$key] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => $vendorName !== '' ? $vendorName : 'Sem vendedor',
                    'realized_period' => 0.0,
                    'backlog_value' => 0.0,
                    'orders' => 0,
                    'merchandise_total' => 0.0,
                    'discount_total' => 0.0,
                ];
            }
        }

        $vendors = [];
        $teamRealized = 0.0;
        $teamBacklog = 0.0;
        $teamTarget = 0.0;
        $missingTargets = 0;

        foreach ($vendorsByKey as $row) {
            $vendorId = (string) ($row['vendor_id'] ?? '');
            $vendorName = (string) ($row['vendor_name'] ?? 'Sem vendedor');
            $targetPeriod = self::resolveTargetForPeriod($vendorId, $start, $end, $months, $targetsMap, $saturdayWeight, $vendorName);
            $targetMonth = self::resolveFullMonthTarget($vendorId, $months, $targetsMap, $vendorName);
            $realized = Support::money((float) ($row['realized_period'] ?? 0));
            $backlog = Support::money((float) ($row['backlog_value'] ?? 0));
            $forecasts = self::calculateForecast($realized, $backlog, $workdays, $today, $start, $end);
            $forecast = $forecasts['probable'];
            $orders = (int) ($row['orders'] ?? 0);

            if ($targetPeriod <= 0.0 && ($realized > 0.0 || $backlog > 0.0)) {
                $missingTargets++;
            }

            $vendors[] = [
                'vendor_id' => $vendorId,
                'vendor_name' => (string) ($row['vendor_name'] ?? 'Sem vendedor'),
                'realized_period' => $realized,
                'backlog_value' => $backlog,
                'orders' => $orders,
                'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
                'discount_total' => Support::money((float) ($row['discount_total'] ?? 0)),
                'target_period' => $targetPeriod,
                'target_month' => $targetMonth,
                'forecast_period' => $forecast,
                'forecasts' => $forecasts,
                'gap_to_target' => Support::money(max($targetPeriod - $realized, 0.0)),
                'attainment_pct' => $targetPeriod > 0 ? round(($realized / $targetPeriod) * 100, 2) : 0.0,
                'new_daily_target' => self::calculateNewDailyTarget($realized, $targetPeriod, $workdays),
                'risk_status' => self::riskStatus($forecast, $targetPeriod, Support::money($targetPeriod * 0.85)),
                'ticket_avg' => $orders > 0 ? Support::money($realized / $orders) : 0.0,
            ];

            $teamRealized += $realized;
            $teamBacklog += $backlog;
            $teamTarget += $targetPeriod;
        }

        usort($vendors, static function (array $left, array $right): int {
            $realizedCompare = (float) ($right['realized_period'] ?? 0) <=> (float) ($left['realized_period'] ?? 0);
            if ($realizedCompare !== 0) {
                return $realizedCompare;
            }
            return strcmp((string) ($left['vendor_name'] ?? ''), (string) ($right['vendor_name'] ?? ''));
        });

        $teamForecasts = self::calculateForecast($teamRealized, $teamBacklog, $workdays, $today, $start, $end);
        $teamForecast = $teamForecasts['probable'];

        return [
            'vendors' => $vendors,
            'team' => [
                'target_period' => Support::money($teamTarget),
                'realized_period' => Support::money($teamRealized),
                'backlog_value' => Support::money($teamBacklog),
                'forecast_period' => $teamForecast,
                'gap_to_target' => Support::money(max($teamTarget - $teamRealized, 0.0)),
                'goal_progress' => $teamTarget > 0 ? round(($teamRealized / $teamTarget) * 100, 2) : 0.0,
                'missing_targets_count' => $missingTargets,
                'workdays' => $workdays,
                'forecasts' => $teamForecasts,
            ],
            'months' => $months,
        ];
    }

    /** @return list<string> */
    public static function monthsInRange(string $start, string $end): array
    {
        $months = [];
        $cursor = new DateTimeImmutable(substr($start, 0, 7) . '-01');
        $last = new DateTimeImmutable(substr($end, 0, 7) . '-01');

        while ($cursor <= $last) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * @param list<string> $months
     * @param array<string,array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,currency:string,updated_at:?string}> $targetsMap
     */
    public static function resolveTargetForPeriod(
        string $vendorId,
        string $start,
        string $end,
        array $months,
        array $targetsMap,
        float $saturdayWeight = 0.33,
        string $vendorName = ''
    ): float
    {
        if ($vendorId === '' && trim($vendorName) === '') {
            return 0.0;
        }

        $target = 0.0;
        foreach ($months as $month) {
            foreach ($targetsMap as $targetRow) {
                if (!self::targetRowMatchesVendor($targetRow, $vendorId, $vendorName)) {
                    continue;
                }

                if ((string) ($targetRow['year_month'] ?? '') !== $month) {
                    continue;
                }

                $monthTarget = Support::toFloat($targetRow['target_amount'] ?? 0);
                if ($monthTarget <= 0.0) {
                    continue;
                }

                [$overlapStart, $overlapEnd] = self::monthOverlap($month, $start, $end);
                if ($overlapStart === null || $overlapEnd === null) {
                    continue;
                }

                $monthWeight = WorkingDays::monthTotalWeight($month, $saturdayWeight);
                $overlapWeight = WorkingDays::weightedBetween($overlapStart, $overlapEnd, $saturdayWeight);
                if ($monthWeight > 0.0) {
                    $target += $monthTarget * ($overlapWeight / $monthWeight);
                }
            }
        }

        return Support::money($target);
    }

    /**
     * @param list<string> $months
     * @param array<string,array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,currency:string,updated_at:?string}> $targetsMap
     */
    public static function resolveFullMonthTarget(string $vendorId, array $months, array $targetsMap, string $vendorName = ''): float
    {
        if ($vendorId === '' && trim($vendorName) === '') {
            return 0.0;
        }

        $target = 0.0;
        foreach ($targetsMap as $targetRow) {
            if (!self::targetRowMatchesVendor($targetRow, $vendorId, $vendorName)) {
                continue;
            }

            if (!in_array((string) ($targetRow['year_month'] ?? ''), $months, true)) {
                continue;
            }

            $target += Support::toFloat($targetRow['target_amount'] ?? 0);
        }

        return Support::money($target);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    public static function monthOverlap(string $yearMonth, string $start, string $end): array
    {
        $monthStart = new DateTimeImmutable($yearMonth . '-01');
        $monthEnd = $monthStart->modify('last day of this month');
        $rangeStart = new DateTimeImmutable($start);
        $rangeEnd = new DateTimeImmutable($end);

        $overlapStart = $monthStart > $rangeStart ? $monthStart : $rangeStart;
        $overlapEnd = $monthEnd < $rangeEnd ? $monthEnd : $rangeEnd;
        if ($overlapEnd < $overlapStart) {
            return [null, null];
        }

        return [$overlapStart->format('Y-m-d'), $overlapEnd->format('Y-m-d')];
    }

    /**
     * @param array<string,mixed> $workdays
     * @return array{base:float,probable:float,commit:float}
     */
    public static function calculateForecast(float $realized, float $backlog, array $workdays, DateTimeImmutable $today, string $start, string $end): array
    {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        $empty = ['base' => 0.0, 'probable' => 0.0, 'commit' => 0.0];

        if ($today < $startDate) {
            return $empty;
        }

        if ($today > $endDate) {
            $realized = Support::money($realized);
            return ['base' => $realized, 'probable' => $realized, 'commit' => $realized];
        }

        $elapsed = (float) ($workdays['elapsed_weight'] ?? 0.0);
        $total = (float) ($workdays['total_weight'] ?? 0.0);
        if ($elapsed <= 0.0 || $total <= 0.0) {
            return $empty;
        }

        $base = Support::money(($realized / $elapsed) * $total);
        $probable = Support::money($base + ($backlog * 0.8));
        $commit = Support::money($base + ($backlog * 0.4));

        return [
            'base' => $base,
            'probable' => $probable,
            'commit' => $commit,
        ];
    }

    /** @param array<string,mixed> $workdays */
    public static function calculateNewDailyTarget(float $realized, float $target, array $workdays): float
    {
        $remainingWeight = (float) ($workdays['remaining_weight'] ?? 0.0);
        if ($target <= 0.0 || $remainingWeight <= 0.0) {
            return 0.0;
        }

        return Support::money(max($target - $realized, 0.0) / $remainingWeight);
    }

    public static function riskStatus(float $forecast, float $target, float $line85): string
    {
        if ($target <= 0.0) {
            return 'SEM_META';
        }
        if ($forecast >= $target) {
            return 'OK';
        }
        if ($forecast >= $line85) {
            return 'ATENCAO';
        }

        return 'SALA_DE_GUERRA';
    }

    private static function vendorKey(string $vendorId, string $vendorName): string
    {
        return $vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName);
    }

    /**
     * @param array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,currency:string,updated_at:?string} $targetRow
     */
    private static function targetRowMatchesVendor(array $targetRow, string $vendorId, string $vendorName): bool
    {
        $targetVendorId = trim((string) ($targetRow['vendor_id'] ?? ''));
        if ($vendorId !== '' && $targetVendorId !== '' && $targetVendorId === $vendorId) {
            return true;
        }

        $normalizedVendorName = self::normalizeVendorName($vendorName);
        if ($normalizedVendorName === '') {
            return false;
        }

        return self::normalizeVendorName((string) ($targetRow['vendor_name'] ?? '')) === $normalizedVendorName;
    }

    private static function normalizeVendorName(string $vendorName): string
    {
        $decoded = str_contains($vendorName, '%') ? rawurldecode($vendorName) : $vendorName;
        $normalized = trim(mb_strtolower($decoded));
        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }
}
