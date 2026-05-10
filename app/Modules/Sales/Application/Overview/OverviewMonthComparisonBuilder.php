<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\CommercialPlanning;
use BIomie\Logger;
use BIomie\Support;
use BIomie\TargetsRepository;
use BIomie\WorkingDays;
use DateTimeImmutable;
use PDO;

final class OverviewMonthComparisonBuilder
{
    /**
     * @return list<array<string,mixed>>
     */
    public function build(
        PDO $pdo,
        Logger $logger,
        CanonicalRepository $canonical,
        ?TargetsRepository $targetsRepository,
        string $start,
        float $saturdayWeight
    ): array {
        $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $start) ?: new DateTimeImmutable('today');
        $anchor = $anchor->modify('first day of this month');

        $months = [];
        for ($offset = 3; $offset >= 0; $offset--) {
            $monthStart = $anchor->modify('-' . $offset . ' months')->modify('first day of this month');
            $months[] = $monthStart;
        }

        $targetMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
            ? $targetsRepository->getTargetsForMonths(array_map(
                static fn (DateTimeImmutable $month): string => $month->format('Y-m'),
                $months
            ))
            : [];

        $currentMonth = (new DateTimeImmutable('today'))->format('Y-m');
        $comparison = [];

        foreach ($months as $monthStart) {
            $monthKey = $monthStart->format('Y-m');
            $monthEnd = $monthStart->modify('last day of this month');
            $rangeStart = $monthStart->format('Y-m-d');
            $rangeEnd = $monthEnd->format('Y-m-d');

            $historical = new CommercialCompetenciaSnapshotService($pdo, $logger, $canonical, $targetsRepository, $saturdayWeight);
            $snapshotYm = $historical->shouldUseSnapshot($rangeStart, $rangeEnd, $monthKey);

            $revenueRaw = 0.0;
            $orders = 0;

            if ($snapshotYm !== null) {
                $snapPayload = $historical->loadPayload('overview', $snapshotYm);
                if ($snapPayload !== null) {
                    $revenueRaw = Support::toFloat($snapPayload['summary']['gross_revenue'] ?? $snapPayload['summary']['revenue'] ?? 0);
                    $orders = (int) ($snapPayload['summary']['orders'] ?? 0);
                } else {
                    $summary = $canonical->summarizeInvoicesCommercial($rangeStart, $rangeEnd);
                    $revenueRaw = (float) ($summary['billed_value'] ?? 0);
                    $orders = (int) ($summary['billed_orders'] ?? 0);
                }
            } else {
                $summary = $canonical->summarizeInvoicesCommercial($rangeStart, $rangeEnd);
                $revenueRaw = (float) ($summary['billed_value'] ?? 0);
                $orders = (int) ($summary['billed_orders'] ?? 0);
            }

            $revenue = Support::money($revenueRaw);
            $target = 0.0;

            foreach ($targetMap as $targetRow) {
                if ((string) ($targetRow['year_month'] ?? '') !== $monthKey) {
                    continue;
                }

                $target += Support::toFloat($targetRow['target_amount'] ?? 0);
            }

            $target = Support::money($target);
            $forecast = $revenue;
            if ($monthKey === $currentMonth) {
                $pending = $canonical->summarizePendingCommercial($rangeStart, $rangeEnd);
                $workdays = WorkingDays::summarize($rangeStart, $rangeEnd, new DateTimeImmutable('today'), $saturdayWeight);
                $pipeline = Support::money(
                    (float) ($pending['authorized_value'] ?? 0)
                    + (float) ($pending['quote_value'] ?? 0)
                );
                $forecast = Support::money(
                    (float) (CommercialPlanning::calculateForecast(
                        $revenue,
                        $pipeline,
                        $workdays,
                        new DateTimeImmutable('today'),
                        $rangeStart,
                        $rangeEnd
                    )['probable'] ?? $revenue)
                );
            }

            $comparison[] = [
                'competencia' => $monthKey,
                'label' => ucfirst($monthStart->format('M/y')),
                'revenue' => $revenue,
                'orders' => $orders,
                'target' => $target,
                'forecast' => $forecast,
                'attainment_pct' => $target > 0 ? round(($revenue / $target) * 100, 2) : 0.0,
                'is_current' => $monthKey === $anchor->format('Y-m'),
            ];
        }

        return $comparison;
    }
}
