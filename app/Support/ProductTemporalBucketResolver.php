<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * EST.R1.3 — Resolve bucket temporal para evolução do produto.
 *
 * Regras:
 *   diff ≤ 45 dias  → bucket diário
 *   46–180 dias     → bucket semanal (início da semana ISO)
 *   > 180 dias      → bucket mensal
 *
 * Datas quebradas (ex: 2026-02-15 → 2026-03-15):
 *   Usa apenas os dias dentro do recorte real.
 *   Não há proporcionalidade de venda — vendas são reais, metas são proporcionais.
 *
 * Buckets sem venda são preenchidos com zero para o gráfico não quebrar.
 */
final class ProductTemporalBucketResolver
{
    public const MODE_DAY   = 'day';
    public const MODE_WEEK  = 'week';
    public const MODE_MONTH = 'month';

    /**
     * Resolve o modo de bucket dado um intervalo de datas.
     */
    public static function resolveMode(string $start, string $end, ?string $override = null): string
    {
        if ($override !== null && in_array($override, [self::MODE_DAY, self::MODE_WEEK, self::MODE_MONTH], true)) {
            return $override;
        }

        $ts1  = strtotime($start);
        $ts2  = strtotime($end);
        $diff = (int) round(abs($ts2 - $ts1) / 86400);

        if ($diff <= 45)  return self::MODE_DAY;
        if ($diff <= 180) return self::MODE_WEEK;
        return self::MODE_MONTH;
    }

    /**
     * Gera lista de buckets vazios entre start e end no modo dado.
     * Cada bucket = ['bucket' => key, 'label' => label, 'revenue' => 0.0, 'quantity' => 0.0, 'invoices_count' => 0, 'customers_count' => 0]
     *
     * @return list<array<string,mixed>>
     */
    public static function generateEmptyBuckets(string $start, string $end, string $mode): array
    {
        $ts1 = strtotime($start);
        $ts2 = strtotime($end);
        if ($ts1 === false || $ts2 === false || $ts1 > $ts2) return [];

        $empty = static fn(string $key, string $label): array => [
            'bucket'          => $key,
            'label'           => $label,
            'revenue'         => 0.0,
            'revenue_raw'     => 0.0,
            'quantity'        => 0.0,
            'invoices_count'  => 0,
            'customers_count' => 0,
        ];

        $buckets = [];

        if ($mode === self::MODE_DAY) {
            $cur = $ts1;
            while ($cur <= $ts2) {
                $key = date('Y-m-d', $cur);
                $buckets[$key] = $empty($key, $key);
                $cur = strtotime('+1 day', $cur);
            }
            return array_values($buckets);
        }

        if ($mode === self::MODE_WEEK) {
            // ISO week start (Monday)
            $cur = strtotime('monday this week', $ts1);
            if ($cur > $ts1) {
                $cur = strtotime('monday last week', $ts1);
            }
            while ($cur <= $ts2) {
                $weekStart = date('Y-m-d', $cur);
                $weekEnd   = date('Y-m-d', strtotime('+6 days', $cur));
                // Clip to period
                $effectiveStart = max($cur, $ts1);
                $label = date('d/m', $effectiveStart);
                $key   = date('Y-W', $cur);
                $buckets[$key] = $empty($key, $label);
                $cur = strtotime('+7 days', $cur);
            }
            return array_values($buckets);
        }

        // MODE_MONTH
        $curY = (int) date('Y', $ts1);
        $curM = (int) date('n', $ts1);
        $endY = (int) date('Y', $ts2);
        $endM = (int) date('n', $ts2);

        while ($curY < $endY || ($curY === $endY && $curM <= $endM)) {
            $key   = sprintf('%04d-%02d', $curY, $curM);
            $label = $key;
            $buckets[$key] = $empty($key, $label);
            $curM++;
            if ($curM > 12) { $curM = 1; $curY++; }
        }
        return array_values($buckets);
    }

    /**
     * Merge dados reais (rows do DB) nos buckets vazios.
     * Rows precisam ter campo 'bucket' compatível com os keys.
     *
     * @param list<array<string,mixed>> $empty
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function mergeIntoBuckets(array $empty, array $rows, string $mode): array
    {
        $index = [];
        foreach ($empty as $i => $b) {
            $index[$b['bucket']] = $i;
        }

        foreach ($rows as $row) {
            $rawBucket = (string) ($row['bucket'] ?? '');
            $key       = self::normalizeRowKey($rawBucket, $mode);
            if (isset($index[$key])) {
                $i = $index[$key];
                $empty[$i]['revenue']         = (float)  ($row['revenue']         ?? 0);
                $empty[$i]['revenue_raw']      = (float)  ($row['revenue_raw']     ?? $row['revenue'] ?? 0);
                $empty[$i]['quantity']         = (float)  ($row['quantity']        ?? 0);
                $empty[$i]['invoices_count']   = (int)    ($row['invoices_count']  ?? 0);
                $empty[$i]['customers_count']  = (int)    ($row['customers_count'] ?? 0);
            }
        }

        return $empty;
    }

    /**
     * Bucket title legível para o front-end.
     */
    public static function bucketTitle(string $mode): string
    {
        return match ($mode) {
            self::MODE_DAY  => 'Evolução diária',
            self::MODE_WEEK => 'Evolução semanal',
            default         => 'Evolução mensal',
        };
    }

    /**
     * SQL expression para o bucket, dependendo do mode.
     */
    public static function bucketSql(string $tableAlias, string $mode): string
    {
        $dateExpr = "COALESCE({$tableAlias}.data_competencia, {$tableAlias}.data_emissao)";
        return match ($mode) {
            self::MODE_DAY  => $dateExpr,
            self::MODE_WEEK => "DATE_FORMAT({$dateExpr}, '%Y-%u')",
            default         => "DATE_FORMAT({$dateExpr}, '%Y-%m')",
        };
    }

    private static function normalizeRowKey(string $raw, string $mode): string
    {
        if ($mode === self::MODE_WEEK) {
            // DB returns DATE_FORMAT(date, '%Y-%u') = 'YYYY-WW' (already correct)
            return $raw;
        }
        return $raw;
    }
}
