<?php

declare(strict_types=1);

namespace BIomie;

use DateTimeImmutable;
use Throwable;

final class FlightPlanService
{
    public function __construct(
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly Logger $logger,
        private readonly SalesAnalyticsService $analytics,
        private readonly ?TargetsRepository $targetsRepository = null,
    ) {
    }

    public function build(string $start, string $end, bool $forceRefresh = false, ?string $vendorFilter = null): array
    {
        $snapshot = $this->analytics->getSnapshot($start, $end, $forceRefresh);

        $featureEnabled = (bool) $this->config->get('features.flight_plan_v2', false);
        $saturdayWeight = (float) $this->config->get('flight_plan.saturday_weight', 0.33);
        $today = new DateTimeImmutable('today');

        $months = $this->monthsInRange($start, $end);
        $targetsMap = [];

        try {
            if ($this->targetsRepository !== null && $this->targetsRepository->isAvailable()) {
                $targetsMap = $this->targetsRepository->getTargetsForMonths($months, $vendorFilter);
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Falha ao carregar metas do Plano de Voo; seguindo sem metas.', [
                'message' => $exception->getMessage(),
                'period_start' => $start,
                'period_end' => $end,
            ]);
        }

        $workdays = WorkingDays::summarize($start, $end, $today, $saturdayWeight);
        $vendorsById = [];

        $vendorMatrix = is_array($snapshot['vendor_status_matrix'] ?? null) ? $snapshot['vendor_status_matrix'] : [];

        $rows = is_array($snapshot['vendor_ranking'] ?? null) ? $snapshot['vendor_ranking'] : [];
        foreach ($rows as $row) {
            $vendorId = trim((string) ($row['vendor_id'] ?? ''));
            $vendorName = trim((string) ($row['vendor_name'] ?? 'Sem vendedor'));

            if ($vendorFilter !== null && $vendorFilter !== '' && $vendorId !== $vendorFilter) {
                continue;
            }

            $realized = Support::money(Support::toFloat($row['gross_revenue'] ?? $row['revenue'] ?? 0));
            $targetPeriod = $this->resolveVendorTargetForPeriod($vendorId, $start, $end, $months, $targetsMap, $saturdayWeight);
            $targetMonth = $this->resolveVendorFullMonthTarget($vendorId, $months, $targetsMap);

            $vendorBacklog = $this->resolveVendorBacklog($vendorId, $vendorName, $vendorMatrix);
            $forecasts = $this->calculateForecast($realized, $vendorBacklog, $workdays, $today, $start, $end);
            $forecast = $forecasts['probable'];
            $line85 = Support::money($targetPeriod * 0.85);
            $gapToTarget = Support::money(max($targetPeriod - $realized, 0));
            $line85Gap = Support::money(max($line85 - $realized, 0));
            $newDailyTarget = $this->calculateNewDailyTarget($realized, $targetPeriod, $workdays);
            $attainment = $targetPeriod > 0 ? round(($realized / $targetPeriod) * 100, 2) : 0.0;

            $key = $vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName);

            // Defensive: if a different key already holds the same vendor name, merge into it.
            if (!isset($vendorsById[$key])) {
                $normalizedName = mb_strtolower($vendorName);
                foreach ($vendorsById as $existKey => $existRow) {
                    if (mb_strtolower((string) ($existRow['vendor_name'] ?? '')) === $normalizedName) {
                        $existRealized = Support::toFloat($existRow['realized_period']);
                        $merged = Support::money($existRealized + $realized);
                        $vendorsById[$existKey]['realized_period'] = $merged;
                        $vendorsById[$existKey]['orders'] += (int) ($row['orders'] ?? 0);
                        // Prefer shorter numeric vendor_id as canonical
                        $existId = (string) ($existRow['vendor_id'] ?? '');
                        if ($vendorId !== '' && ctype_digit($vendorId) && strlen($vendorId) < strlen($existId)) {
                            $vendorsById[$existKey]['vendor_id'] = $vendorId;
                        }
                        // Re-derive metrics after merge
                        $tgt = (float) $existRow['target_period'];
                        $vendorsById[$existKey]['attainment_pct'] = $tgt > 0 ? round(($merged / $tgt) * 100, 2) : 0.0;
                        $existBacklog = $this->resolveVendorBacklog($vendorsById[$existKey]['vendor_id'], $vendorsById[$existKey]['vendor_name'], $vendorMatrix);
                        $newFcs = $this->calculateForecast($merged, $existBacklog, $workdays, $today, $start, $end);
                        $vendorsById[$existKey]['forecast_period'] = $newFcs['probable'];
                        $vendorsById[$existKey]['forecasts'] = $newFcs;
                        $vendorsById[$existKey]['gap_to_target'] = Support::money(max($tgt - $merged, 0));
                        $line85m = Support::money($tgt * 0.85);
                        $vendorsById[$existKey]['line_85_value']  = $line85m;
                        $vendorsById[$existKey]['line_85_gap']    = Support::money(max($line85m - $merged, 0));
                        $vendorsById[$existKey]['new_daily_target'] = $this->calculateNewDailyTarget($merged, $tgt, $workdays);
                        $vendorsById[$existKey]['risk_status'] = $this->riskStatus($vendorsById[$existKey]['forecast_period'], $tgt, $line85m);
                        continue 2; // skip creating a new entry
                    }
                }
            }

            $vendorsById[$key] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $vendorName,
                'target_month' => $targetMonth,
                'target_period' => $targetPeriod,
                'realized_period' => $realized,
                'attainment_pct' => $attainment,
                'forecast_period' => $forecast,
                'forecasts' => $forecasts,
                'gap_to_target' => $gapToTarget,
                'line_85_value' => $line85,
                'line_85_gap' => $line85Gap,
                'new_daily_target' => $newDailyTarget,
                'risk_status' => $this->riskStatus($forecast, $targetPeriod, $line85),
                'orders' => (int) ($row['orders'] ?? 0),
                'share' => round((float) ($row['share'] ?? 0), 2),
                'ticket_avg' => Support::money((float) ($row['ticket_avg'] ?? 0)),
            ];
        }

        // Fase 2.1: exibe também vendedores com meta, mesmo sem venda no período.
        foreach ($targetsMap as $targetRow) {
            $vendorId = trim($targetRow['vendor_id']);
            $vendorName = trim($targetRow['vendor_name']);

            if ($vendorFilter !== null && $vendorFilter !== '' && $vendorId !== $vendorFilter) {
                continue;
            }

            $key = $vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName);
            if (isset($vendorsById[$key])) {
                continue;
            }

            $targetPeriod = $this->resolveVendorTargetForPeriod($vendorId, $start, $end, $months, $targetsMap, $saturdayWeight);
            $targetMonth = $this->resolveVendorFullMonthTarget($vendorId, $months, $targetsMap);
            $vendorBacklog = $this->resolveVendorBacklog($vendorId, $vendorName, $vendorMatrix);
            $forecasts = $this->calculateForecast(0.0, $vendorBacklog, $workdays, $today, $start, $end);
            $forecast = $forecasts['probable'];
            $line85 = Support::money($targetPeriod * 0.85);

            $vendorsById[$key] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $vendorName,
                'target_month' => $targetMonth,
                'target_period' => $targetPeriod,
                'realized_period' => 0.0,
                'attainment_pct' => 0.0,
                'forecast_period' => $forecast,
                'forecasts' => $forecasts,
                'gap_to_target' => Support::money($targetPeriod),
                'line_85_value' => $line85,
                'line_85_gap' => $line85,
                'new_daily_target' => $this->calculateNewDailyTarget(0.0, $targetPeriod, $workdays),
                'risk_status' => $this->riskStatus($forecast, $targetPeriod, $line85),
                'orders' => 0,
                'share' => 0.0,
                'ticket_avg' => 0.0,
            ];
        }

        $vendors = array_values($vendorsById);
        usort($vendors, static function (array $a, array $b): int {
            $realizedCompare = $b['realized_period'] <=> $a['realized_period'];
            if ($realizedCompare !== 0) {
                return $realizedCompare;
            }

            return $b['target_period'] <=> $a['target_period'];
        });

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];

        $teamRealized = 0.0;
        $teamTarget = 0.0;
        foreach ($vendors as $vendor) {
            $teamRealized += (float) $vendor['realized_period'];
            $teamTarget += (float) $vendor['target_period'];
        }

        // Mantém compatibilidade com snapshots antigos, caso a lista venha vazia.
        if ($teamRealized <= 0.0) {
            $teamRealized = Support::money(Support::toFloat($summary['gross_revenue'] ?? $summary['revenue'] ?? 0));
        } else {
            $teamRealized = Support::money($teamRealized);
        }

        $teamTarget = Support::money($teamTarget);
        $teamBacklog = Support::toFloat($snapshot['summary_status']['backlog_value'] ?? 0);
        $teamForecasts = $this->calculateForecast($teamRealized, $teamBacklog, $workdays, $today, $start, $end);
        $teamForecast = $teamForecasts['probable'];
        $teamLine85 = Support::money($teamTarget * 0.85);
        $teamGap = Support::money(max($teamTarget - $teamRealized, 0));
        $teamLine85Gap = Support::money(max($teamLine85 - $teamRealized, 0));
        $teamNewDailyTarget = $this->calculateNewDailyTarget($teamRealized, $teamTarget, $workdays);
        $teamAttainment = $teamTarget > 0 ? round(($teamRealized / $teamTarget) * 100, 2) : 0.0;

        $dailySales = [];
        foreach (($snapshot['daily_sales'] ?? []) as $day) {
            $date = (string) ($day['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $dailySales[] = [
                'date' => $date,
                'gross_revenue' => Support::money(Support::toFloat($day['gross_revenue'] ?? $day['revenue'] ?? 0)),
                'orders' => (int) ($day['orders'] ?? 0),
            ];
        }

        $missingTargetsCount = count(array_filter(
            $vendors,
            static fn (array $vendor): bool => (float) $vendor['target_period'] <= 0.0 && (float) $vendor['realized_period'] > 0.0
        ));
        $lastTargetsUpdate = $this->targetsRepository?->getLastUpdatedAt();
        $generatedAt = date(DATE_ATOM);

        return [
            'ok' => true,
            'mode' => (string) ($snapshot['mode'] ?? 'unknown'),
            'period' => $snapshot['period'] ?? [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' até ' . date('d/m/Y', strtotime($end)),
            ],
            'team' => [
                'currency' => 'BRL',
                'target_period' => $teamTarget,
                'realized_period' => $teamRealized,
                'attainment_pct' => $teamAttainment,
                'forecast_period' => $teamForecast,
                'forecasts' => $teamForecasts,
                'gap_to_target' => $teamGap,
                'line_85_value' => $teamLine85,
                'line_85_gap' => $teamLine85Gap,
                'workdays' => $workdays,
                'new_daily_target' => $teamNewDailyTarget,
                'risk_status' => $this->riskStatus($teamForecast, $teamTarget, $teamLine85),
            ],
            'vendors' => $vendors,
            'daily_sales' => $dailySales,
            'alerts' => $this->buildAlerts($vendors, $teamTarget, $teamForecast, $teamLine85, $missingTargetsCount),
            'meta' => [
                'generated_at' => $generatedAt,
                'cache' => [
                    'hit' => !$forceRefresh,
                    'snapshot' => $snapshot['meta']['cache'] ?? null,
                ],
                'targets' => [
                    'source' => $this->targetsRepository !== null && $this->targetsRepository->isAvailable() ? 'mysql' : 'unconfigured',
                    'last_updated_at' => $lastTargetsUpdate,
                    'missing_vendors_count' => $missingTargetsCount,
                    'months_loaded' => $months,
                ],
                'flags' => [
                    'flight_plan_v2' => $featureEnabled,
                ],
                'fallback_available' => true,
            ],
        ];
    }

    private function resolveVendorTargetForPeriod(string $vendorId, string $start, string $end, array $months, array $targetsMap, float $saturdayWeight): float
    {
        if ($vendorId === '') {
            return 0.0;
        }

        $target = 0.0;
        foreach ($months as $month) {
            $key = $vendorId . '|' . $month;
            if (!isset($targetsMap[$key])) {
                continue;
            }

            $monthTarget = Support::toFloat($targetsMap[$key]['target_amount'] ?? 0);
            if ($monthTarget <= 0) {
                continue;
            }

            [$overlapStart, $overlapEnd] = $this->monthOverlap($month, $start, $end);
            if ($overlapStart === null || $overlapEnd === null) {
                continue;
            }

            $monthWeight = WorkingDays::monthTotalWeight($month, $saturdayWeight);
            $overlapWeight = WorkingDays::weightedBetween($overlapStart, $overlapEnd, $saturdayWeight);

            if ($monthWeight > 0) {
                $target += $monthTarget * ($overlapWeight / $monthWeight);
            }
        }

        return Support::money($target);
    }

    private function resolveVendorFullMonthTarget(string $vendorId, array $months, array $targetsMap): float
    {
        if ($vendorId === '') {
            return 0.0;
        }

        $target = 0.0;
        foreach ($months as $month) {
            $key = $vendorId . '|' . $month;
            if (!isset($targetsMap[$key])) {
                continue;
            }
            $target += Support::toFloat($targetsMap[$key]['target_amount'] ?? 0);
        }

        return Support::money($target);
    }

    /**
     * @return array{base:float,probable:float,commit:float}
     */
    private function calculateForecast(float $realized, float $backlog, array $workdays, DateTimeImmutable $today, string $start, string $end): array
    {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);

        $empty = ['base' => 0.0, 'probable' => 0.0, 'commit' => 0.0];

        if ($today < $startDate) {
            return $empty;
        }

        if ($today > $endDate) {
            $r = Support::money($realized);
            return ['base' => $r, 'probable' => $r, 'commit' => $r];
        }

        $elapsed = (float) ($workdays['elapsed_weight'] ?? 0.0);
        $total = (float) ($workdays['total_weight'] ?? 0.0);

        if ($elapsed <= 0 || $total <= 0) {
            return $empty;
        }

        $base = Support::money(($realized / $elapsed) * $total);
        $probable = Support::money($base + ($backlog * 0.8)); // 80% do funil
        $commit = Support::money($base + ($backlog * 0.4));   // cenário conservador

        return [
            'base' => $base,
            'probable' => $probable,
            'commit' => $commit,
        ];
    }

    private function resolveVendorBacklog(string $vendorId, string $vendorName, array $matrix): float
    {
        foreach ($matrix as $row) {
            if ((string)($row['vendor_id'] ?? '') === $vendorId || (string)($row['vendor_name'] ?? '') === $vendorName) {
                return Support::toFloat($row['backlog_value'] ?? 0);
            }
        }
        return 0.0;
    }

    private function calculateNewDailyTarget(float $realized, float $target, array $workdays): float
    {
        $remainingWeight = (float) ($workdays['remaining_weight'] ?? 0.0);
        $gap = max($target - $realized, 0.0);

        if ($remainingWeight <= 0) {
            return Support::money($gap);
        }

        return Support::money($gap / $remainingWeight);
    }

    private function riskStatus(float $forecast, float $target, float $line85): string
    {
        if ($target <= 0) {
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

    /**
     * @return list<array{level:string,title:string,message:string}>
     */
    private function buildAlerts(array $vendors, float $teamTarget, float $teamForecast, float $teamLine85, int $missingTargetsCount): array
    {
        $alerts = [];

        if ($teamTarget > 0 && $teamForecast < $teamLine85) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Sala de guerra',
                'message' => 'A projeção da equipe está abaixo da linha de 85% da meta do período.',
            ];
        } elseif ($teamTarget > 0 && $teamForecast < $teamTarget) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Atenção na projeção',
                'message' => 'A equipe ainda não projeta fechamento integral da meta do período.',
            ];
        } else {
            $alerts[] = [
                'level' => 'success',
                'title' => 'Dentro da projeção',
                'message' => 'O ritmo atual sustenta a meta do período no cenário projetado.',
            ];
        }

        $critical = array_values(array_filter($vendors, static fn (array $vendor): bool => ($vendor['risk_status'] ?? '') === 'SALA_DE_GUERRA'));
        if ($critical !== []) {
            $names = implode(', ', array_slice(array_map(static fn (array $vendor): string => (string) $vendor['vendor_name'], $critical), 0, 3));
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Consultores críticos',
                'message' => $names . ' precisam de ação tática imediata no período atual.',
            ];
        }

        if ($missingTargetsCount > 0) {
            $alerts[] = [
                'level' => 'info',
                'title' => 'Metas pendentes',
                'message' => $missingTargetsCount . ' vendedor(es) com faturamento ainda não possuem meta cadastrada no MySQL.',
            ];
        }

        return $alerts;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function monthOverlap(string $yearMonth, string $start, string $end): array
    {
        $monthStart = new DateTimeImmutable($yearMonth . '-01');
        $monthEnd = $monthStart->modify('last day of this month');
        $periodStart = new DateTimeImmutable($start);
        $periodEnd = new DateTimeImmutable($end);

        $overlapStart = $periodStart > $monthStart ? $periodStart : $monthStart;
        $overlapEnd = $periodEnd < $monthEnd ? $periodEnd : $monthEnd;

        if ($overlapEnd < $overlapStart) {
            return [null, null];
        }

        return [$overlapStart->format('Y-m-d'), $overlapEnd->format('Y-m-d')];
    }

    /**
     * @return list<string>
     */
    private function monthsInRange(string $start, string $end): array
    {
        $months = [];
        $current = new DateTimeImmutable(substr($start, 0, 7) . '-01');
        $last = new DateTimeImmutable(substr($end, 0, 7) . '-01');

        while ($current <= $last) {
            $months[] = $current->format('Y-m');
            $current = $current->modify('+1 month');
        }

        return $months;
    }
}
