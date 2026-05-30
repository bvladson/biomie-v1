<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * COM.R3 — Granularidade temporal para visualizações comerciais.
 *
 * Define o bucket temporal (diário/semanal/mensal) com base na duração
 * do período, segundo as regras de COM.R3:
 *   ≤ 45 dias   → daily
 *   46–180 dias → weekly
 *   > 180 dias  → monthly
 *
 * Diferente de CommercialTimeBucket (COM.R2, ≤62/63-180/>180) que permanece
 * inalterado para compatibilidade. Este classe é usada em features novas de COM.R3.
 *
 * Uso:
 *   $bucket = CommercialPeriodBucket::resolve('2026-01-01', '2026-05-29');
 *   // $bucket->mode() === 'monthly'
 *   // $bucket->bucketSqlExpr('nf', 'data_competencia', 'data_emissao') → SQL GROUP BY expr
 */
final class CommercialPeriodBucket
{
    public const MODE_DAILY   = 'daily';
    public const MODE_WEEKLY  = 'weekly';
    public const MODE_MONTHLY = 'monthly';

    private const DAILY_MAX_DAYS   = 45;
    private const WEEKLY_MAX_DAYS  = 180;

    private string $start;
    private string $end;
    private string $mode;
    private int    $days;

    private function __construct(string $start, string $end)
    {
        $this->start = $start;
        $this->end   = $end;
        $s = new DateTimeImmutable($start);
        $e = new DateTimeImmutable($end);
        $this->days = max(0, (int) $s->diff($e)->days + 1);

        $this->mode = match(true) {
            $this->days <= self::DAILY_MAX_DAYS  => self::MODE_DAILY,
            $this->days <= self::WEEKLY_MAX_DAYS => self::MODE_WEEKLY,
            default                              => self::MODE_MONTHLY,
        };
    }

    public static function resolve(string $start, string $end): self
    {
        return new self($start, $end);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function mode(): string { return $this->mode; }
    public function days(): int    { return $this->days; }
    public function start(): string { return $this->start; }
    public function end(): string   { return $this->end; }

    // ── SQL expressions ───────────────────────────────────────────────────────

    /**
     * SQL GROUP BY expression for this bucket mode.
     *
     * @param string $alias    Table alias (e.g. 'nf')
     * @param string $col1     Primary date column (e.g. 'data_competencia')
     * @param string $col2     Fallback date column (e.g. 'data_emissao')
     */
    public function bucketSqlExpr(
        string $alias = 'nf',
        string $col1  = 'data_competencia',
        string $col2  = 'data_emissao'
    ): string {
        $prefix   = $alias !== '' ? "{$alias}." : '';
        $dateExpr = "COALESCE({$prefix}{$col1}, {$prefix}{$col2})";

        return match ($this->mode) {
            self::MODE_DAILY   => "DATE({$dateExpr})",
            self::MODE_WEEKLY  => "DATE(DATE_SUB({$dateExpr}, INTERVAL WEEKDAY({$dateExpr}) DAY))",
            self::MODE_MONTHLY => "DATE_FORMAT({$dateExpr}, '%Y-%m')",
        };
    }

    /**
     * SQL GROUP BY expression when the date column is already a single expression.
     */
    public function bucketSqlFromExpr(string $dateExpr): string
    {
        return match ($this->mode) {
            self::MODE_DAILY   => "DATE({$dateExpr})",
            self::MODE_WEEKLY  => "DATE(DATE_SUB({$dateExpr}, INTERVAL WEEKDAY({$dateExpr}) DAY))",
            self::MODE_MONTHLY => "DATE_FORMAT({$dateExpr}, '%Y-%m')",
        };
    }

    /**
     * Human-readable label for a bucket date string.
     *
     * @param string $bucketValue e.g. '2026-03-02', '2026-03', '2026-03-02'
     */
    public function bucketLabel(string $bucketValue): string
    {
        if ($bucketValue === '') return '';

        return match ($this->mode) {
            self::MODE_DAILY   => date('d/m', strtotime($bucketValue)),
            self::MODE_WEEKLY  => 'Semana ' . date('d/m', strtotime($bucketValue)),
            self::MODE_MONTHLY => $this->monthLabel($bucketValue),
        };
    }

    /**
     * Build all bucket keys between start and end (for chart axis pre-filling).
     *
     * @return list<array{key: string, label: string}>
     */
    public function buildBuckets(): array
    {
        $buckets = [];

        if ($this->mode === self::MODE_DAILY) {
            $cursor = new DateTimeImmutable($this->start);
            $end    = new DateTimeImmutable($this->end);
            while ($cursor <= $end) {
                $key = $cursor->format('Y-m-d');
                $buckets[] = ['key' => $key, 'label' => $cursor->format('d/m')];
                $cursor = $cursor->modify('+1 day');
            }
        } elseif ($this->mode === self::MODE_WEEKLY) {
            // Start at the Monday of the first week
            $cursor = new DateTimeImmutable($this->start);
            $weekday = (int) $cursor->format('N') - 1; // ISO Monday = 1
            $cursor = $cursor->modify("-{$weekday} days");
            $end = new DateTimeImmutable($this->end);
            while ($cursor <= $end) {
                $key = $cursor->format('Y-m-d');
                $buckets[] = ['key' => $key, 'label' => 'Semana ' . $cursor->format('d/m')];
                $cursor = $cursor->modify('+7 days');
            }
        } else {
            // Monthly
            $cursor = new DateTimeImmutable(substr($this->start, 0, 7) . '-01');
            $end    = new DateTimeImmutable(substr($this->end, 0, 7) . '-01');
            while ($cursor <= $end) {
                $ym = $cursor->format('Y-m');
                $buckets[] = ['key' => $ym, 'label' => $this->monthLabel($ym)];
                $cursor = $cursor->modify('+1 month');
            }
        }

        return $buckets;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function monthLabel(string $ym): string
    {
        $monthNames = [
            '01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr',
            '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
            '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez',
        ];
        $parts = explode('-', substr($ym, 0, 7));
        $year  = $parts[0] ?? date('Y');
        $month = $parts[1] ?? '01';
        return ($monthNames[$month] ?? $month) . '/' . substr($year, 2);
    }
}
