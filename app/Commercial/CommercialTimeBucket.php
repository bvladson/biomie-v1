<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * COM.R2 — Utilitário de granularidade temporal para dashboards comerciais.
 *
 * Determina automaticamente se o período deve ser agrupado por dia, semana
 * ou mês, e fornece expressões SQL e rótulos legíveis para cada nível.
 *
 * Read-only. Sem acesso ao banco de dados.
 *
 * Regras de granularidade:
 *   ≤ 62 dias   → 'day'
 *   63–180 dias → 'week'
 *   > 180 dias  → 'month'
 */
final class CommercialTimeBucket
{
    // ── Granularity constants ─────────────────────────────────────────────────

    public const GRANULARITY_DAY   = 'day';
    public const GRANULARITY_WEEK  = 'week';
    public const GRANULARITY_MONTH = 'month';

    private const VALID_GRANULARITIES = [
        self::GRANULARITY_DAY,
        self::GRANULARITY_WEEK,
        self::GRANULARITY_MONTH,
    ];

    // Thresholds (inclusive)
    private const DAY_MAX_DAYS  = 62;
    private const WEEK_MAX_DAYS = 180;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Resolve granularity for the given [start, end] range.
     *
     * @param  string $start  YYYY-MM-DD
     * @param  string $end    YYYY-MM-DD
     * @return string         'day' | 'week' | 'month'
     */
    public static function resolveGranularity(string $start, string $end): string
    {
        $days = self::daysBetween($start, $end);

        if ($days <= self::DAY_MAX_DAYS) {
            return self::GRANULARITY_DAY;
        }
        if ($days <= self::WEEK_MAX_DAYS) {
            return self::GRANULARITY_WEEK;
        }
        return self::GRANULARITY_MONTH;
    }

    /**
     * Build an ordered list of time buckets for the range [start, end].
     *
     * Each bucket:
     *   - bucket_start   YYYY-MM-DD
     *   - bucket_end     YYYY-MM-DD
     *   - label          string (human-readable)
     *   - granularity    string
     *
     * @param  string $start  YYYY-MM-DD
     * @param  string $end    YYYY-MM-DD
     * @return list<array{bucket_start:string,bucket_end:string,label:string,granularity:string}>
     */
    public static function buildBuckets(string $start, string $end): array
    {
        self::assertDateFormat($start, 'start');
        self::assertDateFormat($end, 'end');

        $granularity = self::resolveGranularity($start, $end);
        $startDt     = new DateTimeImmutable($start);
        $endDt       = new DateTimeImmutable($end);

        return match ($granularity) {
            self::GRANULARITY_DAY   => self::buildDayBuckets($startDt, $endDt),
            self::GRANULARITY_WEEK  => self::buildWeekBuckets($startDt, $endDt),
            self::GRANULARITY_MONTH => self::buildMonthBuckets($startDt, $endDt),
        };
    }

    /**
     * Count the number of days between start and end (inclusive).
     *
     * @param  string $start  YYYY-MM-DD
     * @param  string $end    YYYY-MM-DD
     * @return int            ≥ 1
     */
    public static function daysBetween(string $start, string $end): int
    {
        self::assertDateFormat($start, 'start');
        self::assertDateFormat($end, 'end');

        $startDt = new DateTimeImmutable($start);
        $endDt   = new DateTimeImmutable($end);
        $diff    = $startDt->diff($endDt);

        return $diff->days + 1;
    }

    /**
     * Return a MySQL expression to group rows by the given granularity.
     *
     * day:   DATE(`col`)
     * week:  DATE(DATE_SUB(`col`, INTERVAL WEEKDAY(`col`) DAY))   — ISO Monday
     * month: DATE_FORMAT(`col`, '%Y-%m')
     *
     * @param  string $dateColumn  Column name (will be backtick-quoted)
     * @param  string $granularity 'day' | 'week' | 'month'
     * @return string              MySQL expression
     * @throws InvalidArgumentException on unknown granularity
     */
    public static function bucketSqlExpr(string $dateColumn, string $granularity): string
    {
        self::assertGranularity($granularity);

        $col = '`' . addslashes($dateColumn) . '`';

        return match ($granularity) {
            self::GRANULARITY_DAY   => "DATE({$col})",
            self::GRANULARITY_WEEK  => "DATE(DATE_SUB({$col}, INTERVAL WEEKDAY({$col}) DAY))",
            self::GRANULARITY_MONTH => "DATE_FORMAT({$col}, '%Y-%m')",
        };
    }

    /**
     * Human-readable label for a bucket's starting date.
     *
     * day:   '15/04'
     * week:  'Semana 15/04'
     * month: 'Abr/2026'
     *
     * @param  string $bucketStart  YYYY-MM-DD (the bucket_start value)
     * @param  string $granularity  'day' | 'week' | 'month'
     * @return string
     * @throws InvalidArgumentException on unknown granularity
     */
    public static function bucketLabel(string $bucketStart, string $granularity): string
    {
        self::assertGranularity($granularity);
        self::assertDateFormat($bucketStart, 'bucketStart');

        $dt = new DateTimeImmutable($bucketStart);

        return match ($granularity) {
            self::GRANULARITY_DAY  => $dt->format('d/m'),
            self::GRANULARITY_WEEK => 'Semana ' . $dt->format('d/m'),
            self::GRANULARITY_MONTH => self::monthLabel($dt),
        };
    }

    // ── Private bucket builders ───────────────────────────────────────────────

    /**
     * @return list<array{bucket_start:string,bucket_end:string,label:string,granularity:string}>
     */
    private static function buildDayBuckets(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $buckets = [];
        $cursor  = $start;

        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $buckets[] = [
                'bucket_start' => $day,
                'bucket_end'   => $day,
                'label'        => $cursor->format('d/m'),
                'granularity'  => self::GRANULARITY_DAY,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $buckets;
    }

    /**
     * Each bucket covers Mon–Sun. Partial weeks at start/end are included.
     *
     * @return list<array{bucket_start:string,bucket_end:string,label:string,granularity:string}>
     */
    private static function buildWeekBuckets(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $buckets = [];

        // Snap cursor to the Monday of the start date's week
        $weekDay = (int) $start->format('N'); // 1=Mon … 7=Sun
        $cursor  = $start->modify('-' . ($weekDay - 1) . ' days');

        while ($cursor <= $end) {
            $weekStart = $cursor;
            $weekEnd   = $cursor->modify('+6 days');

            // Clamp to [start, end]
            $bucketStart = $weekStart < $start ? $start : $weekStart;
            $bucketEnd   = $weekEnd   > $end   ? $end   : $weekEnd;

            $buckets[] = [
                'bucket_start' => $bucketStart->format('Y-m-d'),
                'bucket_end'   => $bucketEnd->format('Y-m-d'),
                'label'        => 'Semana ' . $weekStart->format('d/m'),
                'granularity'  => self::GRANULARITY_WEEK,
            ];

            $cursor = $cursor->modify('+7 days');
        }

        return $buckets;
    }

    /**
     * Each bucket is a calendar month. Partial months at edges are included.
     *
     * @return list<array{bucket_start:string,bucket_end:string,label:string,granularity:string}>
     */
    private static function buildMonthBuckets(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $buckets = [];
        $cursor  = new DateTimeImmutable($start->format('Y-m') . '-01');
        $endMonth = new DateTimeImmutable($end->format('Y-m') . '-01');

        while ($cursor <= $endMonth) {
            $monthFirst = $cursor;
            $daysInMonth = (int) $cursor->format('t');
            $monthLast  = new DateTimeImmutable(
                $cursor->format('Y-m-') . str_pad((string) $daysInMonth, 2, '0', STR_PAD_LEFT)
            );

            $bucketStart = $monthFirst < $start ? $start : $monthFirst;
            $bucketEnd   = $monthLast  > $end   ? $end   : $monthLast;

            $buckets[] = [
                'bucket_start' => $bucketStart->format('Y-m-d'),
                'bucket_end'   => $bucketEnd->format('Y-m-d'),
                'label'        => self::monthLabel($cursor),
                'granularity'  => self::GRANULARITY_MONTH,
            ];

            $cursor = $cursor->modify('+1 month');
        }

        return $buckets;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Portuguese abbreviated month name + year, e.g. 'Abr/2026'.
     */
    private static function monthLabel(DateTimeImmutable $dt): string
    {
        static $ptMonths = [
            1  => 'Jan', 2  => 'Fev', 3  => 'Mar', 4  => 'Abr',
            5  => 'Mai', 6  => 'Jun', 7  => 'Jul', 8  => 'Ago',
            9  => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
        ];
        $month = (int) $dt->format('n');
        $year  = $dt->format('Y');
        return ($ptMonths[$month] ?? $dt->format('M')) . '/' . $year;
    }

    /** @throws InvalidArgumentException */
    private static function assertDateFormat(string $date, string $paramName): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException(
                "CommercialTimeBucket: '{$paramName}' must be YYYY-MM-DD, got '{$date}'"
            );
        }
    }

    /** @throws InvalidArgumentException */
    private static function assertGranularity(string $granularity): void
    {
        if (!in_array($granularity, self::VALID_GRANULARITIES, true)) {
            throw new InvalidArgumentException(
                "CommercialTimeBucket: unknown granularity '{$granularity}'. " .
                'Valid: ' . implode(', ', self::VALID_GRANULARITIES)
            );
        }
    }
}
