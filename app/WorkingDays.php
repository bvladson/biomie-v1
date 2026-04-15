<?php

declare(strict_types=1);

namespace BIomie;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;

final class WorkingDays
{
    /**
     * @return array{rule:string,total_weight:float,elapsed_weight:float,remaining_weight:float,total_days:int,elapsed_days:int,remaining_days:int}
     */
    public static function summarize(string $start, string $end, ?DateTimeImmutable $today = null, float $saturdayWeight = 0.33): array
    {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        $today = $today ?? new DateTimeImmutable('today');

        $totalWeight = 0.0;
        $elapsedWeight = 0.0;
        $remainingWeight = 0.0;
        $totalDays = 0;
        $elapsedDays = 0;
        $remainingDays = 0;

        foreach (self::period($startDate, $endDate) as $date) {
            $weight = self::weightFor($date, $saturdayWeight);
            $totalWeight += $weight;
            $totalDays++;

            if ($date <= $today) {
                $elapsedWeight += $weight;
                $elapsedDays++;
            } else {
                $remainingWeight += $weight;
                $remainingDays++;
            }
        }

        return [
            'rule' => sprintf('weekday=1.0,sat=%.2f,sun=0.0', $saturdayWeight),
            'total_weight' => round($totalWeight, 2),
            'elapsed_weight' => round($elapsedWeight, 2),
            'remaining_weight' => round($remainingWeight, 2),
            'total_days' => $totalDays,
            'elapsed_days' => $elapsedDays,
            'remaining_days' => $remainingDays,
        ];
    }

    public static function weightedBetween(string $start, string $end, float $saturdayWeight = 0.33): float
    {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);

        $weight = 0.0;
        foreach (self::period($startDate, $endDate) as $date) {
            $weight += self::weightFor($date, $saturdayWeight);
        }

        return round($weight, 4);
    }

    public static function monthTotalWeight(string $yearMonth, float $saturdayWeight = 0.33): float
    {
        $start = new DateTimeImmutable($yearMonth . '-01');
        $end = $start->modify('last day of this month');

        return self::weightedBetween($start->format('Y-m-d'), $end->format('Y-m-d'), $saturdayWeight);
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private static function period(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($end < $start) {
            return [];
        }

        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
        $dates = [];
        foreach ($period as $date) {
            $dates[] = $date;
        }

        return $dates;
    }

    private static function weightFor(DateTimeImmutable $date, float $saturdayWeight): float
    {
        $dayOfWeek = (int) $date->format('w');
        if ($dayOfWeek === 0) {
            return 0.0;
        }
        if ($dayOfWeek === 6) {
            return $saturdayWeight;
        }

        return 1.0;
    }
}
