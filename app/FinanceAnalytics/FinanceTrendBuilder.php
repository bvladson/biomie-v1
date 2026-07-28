<?php

declare(strict_types=1);

namespace BIomie\FinanceAnalytics;

use BIomie\Support;
use DateTimeImmutable;

final class FinanceTrendBuilder
{
    public function __construct(
        private readonly FinanceLabelResolver $labelResolver,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $receivedRows
     * @param list<array<string,mixed>> $openTitles
     * @param list<array<string,mixed>> $overdueTitles
     * @return list<array<string,mixed>>
     */
    public function buildTrendSeries(string $start, string $end, array $receivedRows, array $openTitles, array $overdueTitles): array
    {
        $context = $this->describePeriodContext($start, $end);
        $buckets = $this->buildEmptyBuckets($start, $end, $context['granularity']);

        foreach ($receivedRows as $row) {
            $date = (string) ($row['paid_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $key = $this->bucketKeyForDate($date, $context['granularity']);
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['received'] += Support::toFloat($row['amount_net'] ?? $row['amount_paid'] ?? 0);
            $buckets[$key]['planned'] += Support::toFloat($row['amount_net'] ?? $row['amount_paid'] ?? 0);
        }

        foreach ($openTitles as $row) {
            $date = (string) ($row['due_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $key = $this->bucketKeyForDate($date, $context['granularity']);
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['open'] += Support::toFloat($row['amount_open'] ?? 0);
            $buckets[$key]['planned'] += Support::toFloat($row['amount_open'] ?? 0);
        }

        foreach ($overdueTitles as $row) {
            $date = (string) ($row['due_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $key = $this->bucketKeyForDate($date, $context['granularity']);
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['overdue'] += Support::toFloat($row['amount_open'] ?? 0);
        }

        return array_values(array_map(static fn (array $row): array => [
            'key' => (string) $row['key'],
            'label' => (string) $row['label'],
            'received' => Support::money($row['received']),
            'open' => Support::money($row['open']),
            'overdue' => Support::money($row['overdue']),
            'planned' => Support::money($row['planned']),
        ], $buckets));
    }

    /**
     * @param list<array<string,mixed>> $receivedRows
     * @param list<array<string,mixed>> $openTitles
     * @return list<array<string,mixed>>
     */
    public function buildForecastRows(string $start, string $end, array $receivedRows, array $openTitles): array
    {
        $rows = [];
        $cursor = new DateTimeImmutable(date('Y-m-01', strtotime($start)));
        $last = new DateTimeImmutable(date('Y-m-01', strtotime($end)));

        while ($cursor <= $last) {
            $key = $cursor->format('Y-m');
            $rows[$key] = [
                'year_month' => $key,
                'category_code' => 'financeiro',
                'category_name' => 'Carteira de títulos',
                'planned' => 0.0,
                'realized' => 0.0,
                'open' => 0.0,
                'overdue' => 0.0,
                'coverage_rate' => 0.0,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        foreach ($receivedRows as $row) {
            $date = (string) ($row['paid_date'] ?? $row['due_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $key = substr($date, 0, 7);
            if (!isset($rows[$key])) {
                continue;
            }
            $amount = Support::toFloat($row['amount_net'] ?? $row['amount_paid'] ?? 0);
            $rows[$key]['realized'] += $amount;
            $rows[$key]['planned'] += $amount;
        }

        foreach ($openTitles as $row) {
            $date = (string) ($row['due_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $key = substr($date, 0, 7);
            if (!isset($rows[$key])) {
                continue;
            }
            $amount = Support::toFloat($row['amount_open'] ?? 0);
            $rows[$key]['open'] += $amount;
            $rows[$key]['planned'] += $amount;
            if ($this->labelResolver->daysLate((string) ($row['due_date'] ?? '')) > 0) {
                $rows[$key]['overdue'] += $amount;
            }
        }

        foreach ($rows as &$row) {
            $row['coverage_rate'] = $row['planned'] > 0 ? round(($row['realized'] / $row['planned']) * 100, 2) : 0.0;
        }
        unset($row);

        return array_values($rows);
    }

    /**
     * @param list<array<string,mixed>> $budgetRows
     * @param list<array<string,mixed>> $forecastRows
     * @return list<array<string,mixed>>
     */
    public function mergeBudgetRows(string $start, string $end, array $budgetRows, array $forecastRows): array
    {
        $merged = [];
        $cursor = new DateTimeImmutable(date('Y-m-01', strtotime($start)));
        $last = new DateTimeImmutable(date('Y-m-01', strtotime($end)));

        while ($cursor <= $last) {
            $key = $cursor->format('Y-m');
            $merged[$key] = [
                'year_month' => $key,
                'category_code' => 'financeiro',
                'category_name' => 'Carteira de títulos',
                'planned' => 0.0,
                'realized' => 0.0,
                'open' => 0.0,
                'overdue' => 0.0,
                'coverage_rate' => 0.0,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        foreach ($budgetRows as $row) {
            $key = (string) ($row['year_month'] ?? '');
            if ($key === '' || !isset($merged[$key])) {
                continue;
            }
            $merged[$key]['category_code'] = (string) ($row['category_code'] ?? 'financeiro');
            $merged[$key]['category_name'] = (string) ($row['category_name'] ?? 'Carteira de títulos');
            $merged[$key]['planned'] += Support::toFloat($row['planned'] ?? 0);
            $merged[$key]['realized'] += Support::toFloat($row['realized'] ?? 0);
            $merged[$key]['open'] += Support::toFloat($row['open'] ?? 0);
            $merged[$key]['overdue'] += Support::toFloat($row['overdue'] ?? 0);
        }

        foreach ($forecastRows as $row) {
            $key = (string) ($row['year_month'] ?? '');
            if ($key === '' || !isset($merged[$key])) {
                continue;
            }
            $merged[$key]['category_code'] = 'financeiro';
            $merged[$key]['category_name'] = 'Carteira de títulos';
            $forecastPlanned = Support::toFloat($row['planned'] ?? 0);
            $merged[$key]['planned'] = max($merged[$key]['planned'], $forecastPlanned);
            $merged[$key]['realized'] = max($merged[$key]['realized'], Support::toFloat($row['realized'] ?? 0));
            $merged[$key]['open'] = max($merged[$key]['open'], Support::toFloat($row['open'] ?? 0));
            $merged[$key]['overdue'] = max($merged[$key]['overdue'], Support::toFloat($row['overdue'] ?? 0));
        }

        foreach ($merged as &$row) {
            $row['coverage_rate'] = $row['planned'] > 0 ? round(($row['realized'] / $row['planned']) * 100, 2) : 0.0;
        }
        unset($row);

        return array_values($merged);
    }

    /**
     * @return array{days:int,granularity:string,points:int}
     */
    public function describePeriodContext(string $start, string $end): array
    {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        $days = max(1, (int) $startDate->diff($endDate)->format('%a') + 1);
        $granularity = $days > 62 ? 'month' : 'day';
        $points = $granularity === 'month'
            ? (((int) $endDate->format('Y') - (int) $startDate->format('Y')) * 12 + ((int) $endDate->format('n') - (int) $startDate->format('n')) + 1)
            : $days;

        return [
            'days' => $days,
            'granularity' => $granularity,
            'points' => $points,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function buildEmptyBuckets(string $start, string $end, string $granularity): array
    {
        $buckets = [];
        if ($granularity === 'month') {
            $cursor = new DateTimeImmutable(date('Y-m-01', strtotime($start)));
            $last = new DateTimeImmutable(date('Y-m-01', strtotime($end)));
            while ($cursor <= $last) {
                $key = $cursor->format('Y-m');
                $buckets[$key] = [
                    'key' => $key,
                    'label' => $cursor->format('m/Y'),
                    'received' => 0.0,
                    'open' => 0.0,
                    'overdue' => 0.0,
                    'planned' => 0.0,
                ];
                $cursor = $cursor->modify('+1 month');
            }

            return $buckets;
        }

        foreach (Support::eachDate($start, $end) as $date) {
            $d = new DateTimeImmutable($date);
            $buckets[$date] = [
                'key' => $date,
                'label' => $d->format('d/m'),
                'received' => 0.0,
                'open' => 0.0,
                'overdue' => 0.0,
                'planned' => 0.0,
            ];
        }

        return $buckets;
    }

    public function bucketKeyForDate(string $date, string $granularity): string
    {
        $normalized = Support::formatDate($date) ?? $date;

        return $granularity === 'month' ? substr($normalized, 0, 7) : $normalized;
    }
}
