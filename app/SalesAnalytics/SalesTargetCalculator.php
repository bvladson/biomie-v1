<?php

declare(strict_types=1);

namespace BIomie\SalesAnalytics;

use BIomie\Support;
use DateTimeImmutable;

final class SalesTargetCalculator
{
    public function calculateTargetForPeriod(string $start, string $end, float $monthlyTarget): float
    {
        if ($monthlyTarget <= 0) {
            return 0.0;
        }

        $target = 0.0;
        foreach (Support::eachDate($start, $end) as $date) {
            $dateObj = new DateTimeImmutable($date);
            $daysInMonth = (int) $dateObj->format('t');
            $target += $monthlyTarget / max($daysInMonth, 1);
        }

        return Support::money($target);
    }
}
