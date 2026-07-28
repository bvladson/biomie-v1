<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\CanonicalRepository;
use BIomie\DataPlatform\Silver\Readers\CanonicalReadSupport;
use BIomie\Logger;
use BIomie\Support;
use BIomie\TargetsRepository;
use DateTimeImmutable;
use PDO;

final class OverviewMonthComparisonBuilder
{
    /**
     * @param array<string,mixed>|null $snapshot
     * @return array<string,mixed>  rich object with 'rows' for chart backward-compat
     */
    public function build(
        PDO $pdo,
        Logger $logger,
        CanonicalRepository $canonical,
        ?TargetsRepository $targetsRepository,
        string $start,
        float $saturdayWeight
    ): array {
        $today  = new DateTimeImmutable('today');
        $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $start) ?: $today;
        $anchor = $anchor->modify('first day of this month');

        $anchorYm      = $anchor->format('Y-m');
        $isCurrentMonth = ($anchorYm === $today->format('Y-m'));

        $anchorStart  = $anchor->format('Y-m-d');
        $anchorEndDt  = $anchor->modify('last day of this month');
        $anchorEnd    = $anchorEndDt->format('Y-m-d');
        $calendarDays = (int) $anchorEndDt->format('d');
        $elapsedDays  = $isCurrentMonth ? (int) $today->format('d') : $calendarDays;

        $prevMonth = $anchor->modify('-1 month');
        $m2Month   = $anchor->modify('-2 months');
        $m3Month   = $anchor->modify('-3 months');
        $slyMonth  = $anchor->modify('-12 months');

        $prevYm = $prevMonth->format('Y-m');
        $m2Ym   = $m2Month->format('Y-m');
        $m3Ym   = $m3Month->format('Y-m');
        $slyYm  = $slyMonth->format('Y-m');

        // Série do comparativo: 12 meses (M-11..M0). Os KPIs/deltas continuam
        // ancorados em M-3..M0 + mesmo mês do ano anterior.
        $seriesMonths = [];
        for ($i = 11; $i >= 0; $i--) {
            $seriesMonths[] = $anchor->modify(sprintf('-%d months', $i));
        }

        // ── Targets ───────────────────────────────────────────────────────────
        $targetYms = array_map(
            static fn (DateTimeImmutable $m): string => $m->format('Y-m'),
            $seriesMonths
        );
        $targetsRaw = ($targetsRepository !== null && $targetsRepository->isAvailable())
            ? $targetsRepository->getTargetsForMonths($targetYms)
            : [];
        $targets = $this->collapseTargets($targetsRaw);
        $readSupport = new CanonicalReadSupport(static fn (): PDO => $pdo);

        // ── Main aggregation: M-11 to M0 ──────────────────────────────────────
        $mainStart  = $seriesMonths[0]->format('Y-m-01');
        $mainEnd    = $anchorEnd;
        $monthData  = $this->queryMonthAggregates($pdo, $readSupport, $mainStart, $mainEnd);

        // ── Same month last year ───────────────────────────────────────────────
        $slyStart   = $slyMonth->format('Y-m-01');
        $slyEnd     = $slyMonth->modify('last day of this month')->format('Y-m-d');
        $slyRaw     = $this->queryMonthAggregates($pdo, $readSupport, $slyStart, $slyEnd);

        // ── Previous month same elapsed days ──────────────────────────────────
        $prevSameElapsedData = [];
        if ($elapsedDays > 0) {
            $prevElapsedEnd  = $prevMonth->modify(sprintf('+%d days', $elapsedDays - 1))->format('Y-m-d');
            $prevSameElapsedData = $this->queryMonthAggregates(
                $pdo,
                $readSupport,
                $prevMonth->format('Y-m-01'),
                $prevElapsedEnd,
            );
        }
        $prevSameElapsedRow = $prevSameElapsedData[$prevYm] ?? null;

        $m0  = $monthData[$anchorYm] ?? $this->emptyRow($anchor);
        $m1  = $monthData[$prevYm]   ?? $this->emptyRow($prevMonth);
        $m2  = $monthData[$m2Ym]     ?? $this->emptyRow($m2Month);
        $m3  = $monthData[$m3Ym]     ?? $this->emptyRow($m3Month);
        $sly = $slyRaw[$slyYm]       ?? null;

        $m0Target = $targets[$anchorYm] ?? 0.0;
        $m1Target = $targets[$prevYm]   ?? 0.0;
        $m2Target = $targets[$m2Ym]     ?? 0.0;
        $m3Target = $targets[$m3Ym]     ?? 0.0;

        // ── Projection ────────────────────────────────────────────────────────
        $projection = null;
        if ($isCurrentMonth && $elapsedDays > 0 && $calendarDays > 0) {
            $pace = ($m0['revenue'] / $elapsedDays) * $calendarDays;
            $projection = [
                'probable'       => round($pace, 2),
                'optimistic'     => round($pace * 1.10, 2),
                'pessimistic'    => round($pace * 0.90, 2),
                'method'         => 'pace_extrapolation',
                'elapsed_days'   => $elapsedDays,
                'remaining_days' => $calendarDays - $elapsedDays,
            ];
        }

        // ── 3-month average (M-1, M-2, M-3 with data) ────────────────────────
        $last3 = array_values(array_filter([$m1, $m2, $m3], static fn (array $r): bool => $r['revenue'] > 0));
        $last3Count        = count($last3);
        $last3Avg          = $last3Count > 0 ? array_sum(array_column($last3, 'revenue')) / $last3Count : null;
        $last3AvgInvoices  = $last3Count > 0 ? (int) round(array_sum(array_column($last3, 'invoices_count')) / $last3Count) : 0;

        // ── Best recent month ─────────────────────────────────────────────────
        $best = null;
        foreach ($last3 as $row) {
            if ($best === null || $row['revenue'] > $best['revenue']) {
                $best = $row;
            }
        }

        // ── Deltas ────────────────────────────────────────────────────────────
        $deltas = [
            'vs_previous_pct'             => $m1['revenue'] > 0
                ? round((($m0['revenue'] - $m1['revenue']) / $m1['revenue']) * 100, 2) : null,
            'vs_previous_abs'             => round($m0['revenue'] - $m1['revenue'], 2),
            'vs_3m_avg_pct'               => ($last3Avg !== null && $last3Avg > 0)
                ? round((($m0['revenue'] - $last3Avg) / $last3Avg) * 100, 2) : null,
            'vs_3m_avg_abs'               => $last3Avg !== null ? round($m0['revenue'] - $last3Avg, 2) : null,
            'vs_same_elapsed_pct'         => ($prevSameElapsedRow !== null && $prevSameElapsedRow['revenue'] > 0)
                ? round((($m0['revenue'] - $prevSameElapsedRow['revenue']) / $prevSameElapsedRow['revenue']) * 100, 2) : null,
            'vs_target_pct'               => $m0Target > 0
                ? round(($m0['revenue'] / $m0Target) * 100, 2) : null,
            'vs_same_month_last_year_pct' => ($sly !== null && $sly['revenue'] > 0)
                ? round((($m0['revenue'] - $sly['revenue']) / $sly['revenue']) * 100, 2) : null,
        ];

        // ── Insights ──────────────────────────────────────────────────────────
        $insights = $this->buildInsights($m0, $m1, $last3Avg, $best, $sly, $deltas, $m0Target, $projection, $isCurrentMonth);

        // ── Rows (série de 12 meses para o gráfico) ───────────────────────────
        $monthsWithTargets = [];
        foreach ($seriesMonths as $seriesMonth) {
            $seriesYm = $seriesMonth->format('Y-m');
            $monthsWithTargets[] = [
                $monthData[$seriesYm] ?? $this->emptyRow($seriesMonth),
                $targets[$seriesYm] ?? 0.0,
            ];
        }
        $rows = $this->buildRows($monthsWithTargets, $anchorYm, $projection);

        return [
            'available'    => true,
            'source'       => 'canonical',
            'period_basis' => 'data_competencia',
            'mode'         => $isCurrentMonth ? 'month_in_progress' : 'closed_month',
            'period'       => [
                'year_month'           => $anchorYm,
                'start'                => $anchorStart,
                'end'                  => $anchorEnd,
                'elapsed_days'         => $elapsedDays,
                'calendar_days'        => $calendarDays,
                'is_month_in_progress' => $isCurrentMonth,
            ],
            'current'      => [
                'year_month'      => $anchorYm,
                'label'           => $m0['label'],
                'revenue'         => round($m0['revenue'], 2),
                'invoices_count'  => $m0['invoices_count'],
                'cancelled_value' => round($m0['cancelled_value'], 2),
                'cancelled_count' => $m0['cancelled_count'],
                'target'          => round($m0Target, 2),
                'attainment_pct'  => $m0Target > 0 ? round(($m0['revenue'] / $m0Target) * 100, 2) : null,
                'forecast'        => $projection !== null ? $projection['probable'] : round($m0['revenue'], 2),
                'source'          => 'canonical',
            ],
            'previous'     => [
                'year_month'      => $prevYm,
                'label'           => $m1['label'],
                'revenue'         => round($m1['revenue'], 2),
                'invoices_count'  => $m1['invoices_count'],
                'cancelled_value' => round($m1['cancelled_value'], 2),
                'cancelled_count' => $m1['cancelled_count'],
                'target'          => round($m1Target, 2),
                'attainment_pct'  => $m1Target > 0 ? round(($m1['revenue'] / $m1Target) * 100, 2) : null,
                'source'          => 'canonical',
            ],
            'previous_same_elapsed_days' => $prevSameElapsedRow !== null ? [
                'year_month'     => $prevYm,
                'elapsed_days'   => $elapsedDays,
                'revenue'        => round($prevSameElapsedRow['revenue'], 2),
                'invoices_count' => $prevSameElapsedRow['invoices_count'],
            ] : null,
            'last_3_months_avg'   => $last3Avg !== null ? [
                'revenue'        => round($last3Avg, 2),
                'invoices_count' => $last3AvgInvoices,
                'months_used'    => $last3Count,
            ] : null,
            'best_recent_month'   => $best !== null ? [
                'year_month'     => $best['year_month'],
                'label'          => $best['label'],
                'revenue'        => round($best['revenue'], 2),
                'invoices_count' => $best['invoices_count'],
            ] : null,
            'same_month_last_year' => $sly !== null ? [
                'year_month'     => $sly['year_month'],
                'label'          => $sly['label'],
                'revenue'        => round($sly['revenue'], 2),
                'invoices_count' => $sly['invoices_count'],
            ] : null,
            'projection'   => $projection,
            'deltas'       => $deltas,
            'insights'     => $insights,
            'rows'         => $rows,
            'reason_code'  => null,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Runs a single GROUP BY month SQL and returns keyed by year_month.
     *
     * @return array<string,array<string,mixed>>
     */
    private function queryMonthAggregates(
        PDO $pdo,
        CanonicalReadSupport $readSupport,
        string $start,
        string $end,
    ): array
    {
        $scopeFilter = $readSupport->commercialScopeFilter();
        $commercialBase = $readSupport->commercialBaseSql();
        // year_month é palavra reservada no MariaDB (INTERVAL ... YEAR_MONTH) —
        // o alias precisa de backticks ou a query quebra com erro 1064.
        $stmt = $pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(data_competencia, '%Y-%m') AS `year_month`,
                COALESCE(SUM(CASE WHEN status_fiscal = 'autorizada' THEN {$commercialBase} ELSE 0 END), 0)  AS revenue,
                COALESCE(SUM(CASE WHEN status_fiscal = 'cancelada'  THEN {$commercialBase} ELSE 0 END), 0)  AS cancelled_value,
                COUNT(CASE WHEN status_fiscal = 'autorizada' THEN 1 ELSE NULL END)                    AS invoices_count,
                COUNT(CASE WHEN status_fiscal = 'cancelada'  THEN 1 ELSE NULL END)                    AS cancelled_count
            FROM biomie_notas_fiscais_canonicas
            WHERE data_competencia >= :start
              AND data_competencia < DATE_ADD(:end, INTERVAL 1 DAY){$scopeFilter}
            GROUP BY DATE_FORMAT(data_competencia, '%Y-%m')
            ORDER BY DATE_FORMAT(data_competencia, '%Y-%m') ASC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $ym = (string) ($row['year_month'] ?? '');
            if ($ym === '') {
                continue;
            }
            $dt = DateTimeImmutable::createFromFormat('!Y-m', $ym) ?: new DateTimeImmutable('today');
            $result[$ym] = [
                'year_month'      => $ym,
                'label'           => ucfirst($dt->format('M/y')),
                'revenue'         => (float) ($row['revenue'] ?? 0),
                'cancelled_value' => (float) ($row['cancelled_value'] ?? 0),
                'invoices_count'  => (int) ($row['invoices_count'] ?? 0),
                'cancelled_count' => (int) ($row['cancelled_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array<string,float>  year_month => total target amount
     */
    private function collapseTargets(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $ym = (string) ($row['year_month'] ?? '');
            if ($ym === '') {
                continue;
            }
            $result[$ym] = ($result[$ym] ?? 0.0) + Support::toFloat($row['target_amount'] ?? 0);
        }
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyRow(DateTimeImmutable $month): array
    {
        return [
            'year_month'      => $month->format('Y-m'),
            'label'           => ucfirst($month->format('M/y')),
            'revenue'         => 0.0,
            'cancelled_value' => 0.0,
            'invoices_count'  => 0,
            'cancelled_count' => 0,
        ];
    }

    /**
     * Builds the rolling 12-row array consumed by the two semester charts.
     *
     * @param list<array{0:array<string,mixed>,1:float}> $monthsWithTargets  [[row, target], ...]
     * @return list<array<string,mixed>>
     */
    private function buildRows(array $monthsWithTargets, string $currentYm, ?array $projection): array
    {
        $rows = [];
        $previousRevenue = null;

        foreach ($monthsWithTargets as [$row, $target]) {
            $revenueRaw = $row['revenue'];
            $revenue    = round($revenueRaw, 2);
            $isCurrent  = $row['year_month'] === $currentYm;

            if ($previousRevenue === null) {
                $variationPct   = null;
                $variationAbs   = null;
                $variationLabel = null;
            } elseif ($previousRevenue <= 0.0) {
                $variationPct   = null;
                $variationAbs   = round($revenueRaw - $previousRevenue, 2);
                $variationLabel = 'Sem base anterior';
            } else {
                $variationPct   = round((($revenueRaw - $previousRevenue) / $previousRevenue) * 100, 2);
                $variationAbs   = round($revenueRaw - $previousRevenue, 2);
                $variationLabel = null;
            }

            $forecast = $revenue;
            if ($isCurrent && $projection !== null) {
                $forecast = round($projection['probable'], 2);
            }

            $rows[] = [
                'year_month'        => $row['year_month'],
                'competencia'       => $row['year_month'],
                'label'             => $row['label'],
                'revenue'           => $revenue,
                'merchandise_total' => 0.0,
                'invoices_count'    => $row['invoices_count'],
                'orders'            => $row['invoices_count'],
                'target'            => round($target, 2),
                'forecast'          => $forecast,
                'attainment_pct'    => $target > 0 ? round(($revenue / $target) * 100, 2) : 0.0,
                'previous_revenue'  => $previousRevenue !== null ? round($previousRevenue, 2) : null,
                'variation_abs'     => $variationAbs,
                'variation_pct'     => $variationPct,
                'variation_label'   => $variationLabel,
                'is_current'        => $isCurrent,
                'source'            => 'canonical',
            ];

            $previousRevenue = $revenueRaw;
        }

        return $rows;
    }

    /**
     * Generates at least 6 insight items covering key commercial dimensions.
     *
     * @return list<array<string,mixed>>
     */
    private function buildInsights(
        array $m0,
        array $m1,
        ?float $last3Avg,
        ?array $best,
        ?array $sly,
        array $deltas,
        float $target,
        ?array $projection,
        bool $isCurrentMonth
    ): array {
        $insights = [];

        // 1. Revenue vs previous month
        if ($deltas['vs_previous_pct'] !== null) {
            $pct = $deltas['vs_previous_pct'];
            $insights[] = [
                'id'        => 'vs_previous_month',
                'severity'  => $pct >= 5.0 ? 'success' : ($pct >= 0.0 ? 'info' : ($pct > -10.0 ? 'warning' : 'danger')),
                'label'     => 'vs mes anterior',
                'value_pct' => $pct,
                'value_abs' => $deltas['vs_previous_abs'] ?? null,
                'detail'    => $pct >= 0.0
                    ? sprintf('Faturamento %.1f%% acima do mes anterior.', $pct)
                    : sprintf('Faturamento %.1f%% abaixo do mes anterior.', abs($pct)),
            ];
        }

        // 2. Revenue vs 3-month average
        if ($deltas['vs_3m_avg_pct'] !== null) {
            $pct = $deltas['vs_3m_avg_pct'];
            $insights[] = [
                'id'        => 'vs_3m_avg',
                'severity'  => $pct >= 10.0 ? 'success' : ($pct >= 0.0 ? 'info' : ($pct > -15.0 ? 'warning' : 'danger')),
                'label'     => 'vs media 3 meses',
                'value_pct' => $pct,
                'value_abs' => $deltas['vs_3m_avg_abs'] ?? null,
                'detail'    => $pct >= 0.0
                    ? sprintf('Faturamento %.1f%% acima da media dos 3 ultimos meses.', $pct)
                    : sprintf('Faturamento %.1f%% abaixo da media dos 3 ultimos meses.', abs($pct)),
            ];
        }

        // 3. Average ticket vs previous month
        $m0Ticket = $m0['invoices_count'] > 0 ? $m0['revenue'] / $m0['invoices_count'] : null;
        $m1Ticket = $m1['invoices_count'] > 0 ? $m1['revenue'] / $m1['invoices_count'] : null;
        if ($m0Ticket !== null && $m1Ticket !== null && $m1Ticket > 0) {
            $ticketPct = round((($m0Ticket - $m1Ticket) / $m1Ticket) * 100, 1);
            $insights[] = [
                'id'        => 'ticket_avg',
                'severity'  => $ticketPct >= 5.0 ? 'success' : ($ticketPct >= -5.0 ? 'info' : 'warning'),
                'label'     => 'ticket medio',
                'value_pct' => $ticketPct,
                'value_abs' => round($m0Ticket - $m1Ticket, 2),
                'detail'    => $ticketPct >= 0.0
                    ? sprintf('Ticket medio %.1f%% acima do mes anterior.', $ticketPct)
                    : sprintf('Ticket medio %.1f%% abaixo do mes anterior.', abs($ticketPct)),
            ];
        } elseif ($m0Ticket !== null) {
            $insights[] = [
                'id'        => 'ticket_avg',
                'severity'  => 'info',
                'label'     => 'ticket medio',
                'value_pct' => null,
                'value_abs' => round($m0Ticket, 2),
                'detail'    => sprintf('Ticket medio do periodo: R$ %.2f.', $m0Ticket),
            ];
        }

        // 4. Invoice volume vs previous month
        if ($m0['invoices_count'] > 0 && $m1['invoices_count'] > 0) {
            $invPct = round((($m0['invoices_count'] - $m1['invoices_count']) / $m1['invoices_count']) * 100, 1);
            $insights[] = [
                'id'        => 'invoices_count',
                'severity'  => $invPct >= 5.0 ? 'success' : ($invPct >= -5.0 ? 'info' : 'warning'),
                'label'     => 'volume de NFs',
                'value_pct' => $invPct,
                'value_abs' => $m0['invoices_count'] - $m1['invoices_count'],
                'detail'    => $invPct >= 0.0
                    ? sprintf('Volume de NFs %.0f%% acima do mes anterior (%d NFs).', $invPct, $m0['invoices_count'])
                    : sprintf('Volume de NFs %.0f%% abaixo do mes anterior (%d NFs).', abs($invPct), $m0['invoices_count']),
            ];
        }

        // 5. Cancellation rate
        $m0TotalBruto = $m0['revenue'] + $m0['cancelled_value'];
        if ($m0TotalBruto > 0 && $m0['cancelled_value'] > 0) {
            $cancelRate = round(($m0['cancelled_value'] / $m0TotalBruto) * 100, 1);
            $insights[] = [
                'id'        => 'cancellation_rate',
                'severity'  => $cancelRate >= 15.0 ? 'danger' : ($cancelRate >= 8.0 ? 'warning' : 'info'),
                'label'     => 'taxa cancelamento',
                'value_pct' => $cancelRate,
                'value_abs' => round($m0['cancelled_value'], 2),
                'detail'    => sprintf('%.1f%% do bruto emitido no mes foi cancelado (R$ %.2f).', $cancelRate, $m0['cancelled_value']),
            ];
        } elseif (count($insights) < 5) {
            // Positive signal when no cancellations
            $insights[] = [
                'id'        => 'cancellation_rate',
                'severity'  => 'success',
                'label'     => 'cancelamentos',
                'value_pct' => 0.0,
                'value_abs' => 0.0,
                'detail'    => 'Nenhum cancelamento registrado no periodo.',
            ];
        }

        // 6a. Projection (current month in progress)
        if ($projection !== null && $isCurrentMonth) {
            $projPct = $target > 0 ? round(($projection['probable'] / $target) * 100, 1) : null;
            $insights[] = [
                'id'        => 'projection',
                'severity'  => ($projPct !== null && $projPct >= 100.0) ? 'success' : (($projPct !== null && $projPct >= 80.0) ? 'warning' : 'info'),
                'label'     => 'projecao do mes',
                'value_pct' => $projPct,
                'value_abs' => $projection['probable'],
                'detail'    => $projPct !== null
                    ? sprintf('Projecao ao ritmo atual: %.1f%% da meta (R$ %.2f).', $projPct, $projection['probable'])
                    : sprintf('Projecao ao ritmo atual: R$ %.2f.', $projection['probable']),
            ];
        } elseif ($sly !== null && $deltas['vs_same_month_last_year_pct'] !== null) {
            // 6b. Same month last year (when no projection)
            $slyPct = $deltas['vs_same_month_last_year_pct'];
            $insights[] = [
                'id'        => 'vs_same_month_last_year',
                'severity'  => $slyPct >= 10.0 ? 'success' : ($slyPct >= 0.0 ? 'info' : ($slyPct > -10.0 ? 'warning' : 'danger')),
                'label'     => 'vs mesmo mes ano anterior',
                'value_pct' => $slyPct,
                'value_abs' => round($m0['revenue'] - $sly['revenue'], 2),
                'detail'    => $slyPct >= 0.0
                    ? sprintf('Faturamento %.1f%% acima do mesmo mes do ano anterior.', $slyPct)
                    : sprintf('Faturamento %.1f%% abaixo do mesmo mes do ano anterior.', abs($slyPct)),
            ];
        } elseif ($best !== null) {
            // 6c. Best recent month highlight
            $bestPct = $best['revenue'] > 0 ? round(($m0['revenue'] / $best['revenue']) * 100, 1) : null;
            $insights[] = [
                'id'        => 'vs_best_recent',
                'severity'  => ($bestPct !== null && $bestPct >= 100.0) ? 'success' : 'info',
                'label'     => 'vs melhor recente',
                'value_pct' => $bestPct,
                'value_abs' => $best['revenue'] > 0 ? round($m0['revenue'] - $best['revenue'], 2) : null,
                'detail'    => sprintf('Melhor mes recente foi %s (R$ %.2f).', $best['label'], $best['revenue']),
            ];
        }

        return $insights;
    }

}
