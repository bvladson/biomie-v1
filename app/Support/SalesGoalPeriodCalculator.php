<?php

declare(strict_types=1);

namespace BIomie\Support;

use DateTimeImmutable;

/**
 * COM.R1.3 — Calculadora oficial de metas proporcionais por período.
 *
 * Calcula a meta proporcional para qualquer intervalo de datas,
 * decompondo por mês e aplicando a fração de dias corridos.
 *
 * Regra oficial (aprovada em COM.R1.3):
 *   Para cada mês intersectado pelo período:
 *     meta_proporcional_mes = meta_mensal_cheia * dias_intersectados / dias_totais_do_mes
 *   meta_total_periodo = soma das metas_proporcional_mes
 *
 * Exemplos:
 *   2026-01-01 → 2026-05-28: jan_cheia + fev_cheia + mar_cheia + abr_cheia + 28/31*meta_mai
 *   2026-04-15 → 2026-05-15: 16/30*meta_abr + 15/31*meta_mai
 *   2026-05-01 → 2026-05-31: meta_cheia_mai
 *   2026-01-01 → 2026-12-31: soma das 12 metas cheias
 *
 * NOTA: não usa dias úteis ponderados — usa dias corridos.
 * Para cálculo com dias úteis, use CommercialPlanning::resolveTargetForPeriod().
 */
final class SalesGoalPeriodCalculator
{
    /**
     * Calcula meta proporcional total para o período, dado um mapa mensal de metas.
     *
     * @param string $start       — data início, YYYY-MM-DD
     * @param string $end         — data fim, YYYY-MM-DD
     * @param array<string,float> $monthlyTargets — ano-mês ('2026-05') => meta mensal
     * @return array{
     *   meta_total_periodo: float,
     *   dias_periodo: int,
     *   breakdown: list<array{
     *     year_month: string,
     *     meta_mensal_cheia: float,
     *     dias_do_mes: int,
     *     dias_considerados: int,
     *     percentual_mes: float,
     *     meta_proporcional_mes: float,
     *     is_month_complete: bool,
     *   }>
     * }
     */
    public function calculate(string $start, string $end, array $monthlyTargets): array
    {
        $startDt = new DateTimeImmutable($start);
        $endDt   = new DateTimeImmutable($end);

        if ($endDt < $startDt) {
            return ['meta_total_periodo' => 0.0, 'dias_periodo' => 0, 'breakdown' => []];
        }

        $months = $this->monthsInRange($start, $end);
        $breakdown = [];
        $totalTarget = 0.0;

        foreach ($months as $ym) {
            $monthStart = new DateTimeImmutable($ym . '-01');
            $daysInMonth = (int) $monthStart->format('t');
            $monthEnd    = $monthStart->modify('+' . ($daysInMonth - 1) . ' days');

            // Intersection
            $overlapStart = $monthStart < $startDt ? $startDt : $monthStart;
            $overlapEnd   = $monthEnd > $endDt     ? $endDt   : $monthEnd;

            // Days in intersection (inclusive)
            $daysConsidered = max(0, (int) $overlapStart->diff($overlapEnd)->days + 1);
            if ($daysConsidered <= 0) continue;

            $monthlyTarget = $monthlyTargets[$ym] ?? 0.0;
            $pct           = $daysInMonth > 0 ? $daysConsidered / $daysInMonth : 0.0;
            $proportional  = round($monthlyTarget * $pct, 2);
            $totalTarget  += $proportional;

            $breakdown[] = [
                'year_month'           => $ym,
                'meta_mensal_cheia'    => $monthlyTarget,
                'dias_do_mes'          => $daysInMonth,
                'dias_considerados'    => $daysConsidered,
                'percentual_mes'       => round($pct * 100, 2),
                'meta_proporcional_mes'=> $proportional,
                'is_month_complete'    => $daysConsidered === $daysInMonth,
            ];
        }

        $diasPeriodo = max(0, (int) $startDt->diff($endDt)->days + 1);

        return [
            'meta_total_periodo' => round($totalTarget, 2),
            'dias_periodo'       => $diasPeriodo,
            'breakdown'          => $breakdown,
        ];
    }

    /**
     * Calcula meta proporcional para um único vendedor, lendo do targetsMap.
     *
     * @param string $start
     * @param string $end
     * @param string $vendorId
     * @param string $vendorName
     * @param list<array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float}> $targetsRows
     * @return array<string,mixed>  — mesma estrutura de calculate() + vendor info
     */
    public function calculateForVendor(
        string $start,
        string $end,
        string $vendorId,
        string $vendorName,
        array  $targetsRows
    ): array {
        $monthlyMap = [];
        foreach ($targetsRows as $row) {
            $rowVendorId   = trim((string) ($row['vendor_id']   ?? ''));
            $rowVendorName = mb_strtolower(trim((string) ($row['vendor_name'] ?? '')));
            $ym            = (string) ($row['year_month'] ?? '');
            $amount        = (float) ($row['target_amount'] ?? 0);

            $matchById   = $vendorId !== '' && $rowVendorId === $vendorId;
            $matchByName = $vendorName !== '' && mb_strtolower($vendorName) === $rowVendorName;

            if (($matchById || $matchByName) && $ym !== '') {
                $monthlyMap[$ym] = ($monthlyMap[$ym] ?? 0.0) + $amount;
            }
        }

        $result = $this->calculate($start, $end, $monthlyMap);
        $result['vendor_id']   = $vendorId;
        $result['vendor_name'] = $vendorName;
        return $result;
    }

    /**
     * Validates proportional calculation with known examples.
     * Returns [passed, failures].
     *
     * @return array{passed: bool, failures: list<string>}
     */
    public function selfTest(): array
    {
        $failures = [];
        $target1000 = ['2026-01' => 1000.0, '2026-02' => 1000.0, '2026-03' => 1000.0,
                        '2026-04' => 1000.0, '2026-05' => 1000.0, '2026-12' => 1000.0];

        // Test 1: full months Jan-Apr + 28 days of May (31-day month)
        $r = $this->calculate('2026-01-01', '2026-05-28', $target1000);
        $expected1 = 1000.0 + 1000.0 + 1000.0 + 1000.0 + round(1000 * 28 / 31, 2);
        if (abs($r['meta_total_periodo'] - $expected1) > 0.02) {
            $failures[] = "Test 1 failed: expected={$expected1}, got={$r['meta_total_periodo']}";
        }

        // Test 2: partial April (16 days from 15th) + partial May (15 days from 1st)
        $r2 = $this->calculate('2026-04-15', '2026-05-15', $target1000);
        $expected2 = round(1000 * 16 / 30, 2) + round(1000 * 15 / 31, 2);
        if (abs($r2['meta_total_periodo'] - $expected2) > 0.02) {
            $failures[] = "Test 2 failed: expected={$expected2}, got={$r2['meta_total_periodo']}";
        }

        // Test 3: full May
        $r3 = $this->calculate('2026-05-01', '2026-05-31', $target1000);
        if (abs($r3['meta_total_periodo'] - 1000.0) > 0.01) {
            $failures[] = "Test 3 failed: expected=1000.00, got={$r3['meta_total_periodo']}";
        }

        // Test 4: full year (12 months, each 1000)
        $fullYear = [];
        for ($m = 1; $m <= 12; $m++) {
            $fullYear[sprintf('2026-%02d', $m)] = 1000.0;
        }
        $r4 = $this->calculate('2026-01-01', '2026-12-31', $fullYear);
        if (abs($r4['meta_total_periodo'] - 12000.0) > 0.01) {
            $failures[] = "Test 4 failed: expected=12000.00, got={$r4['meta_total_periodo']}";
        }

        return ['passed' => empty($failures), 'failures' => $failures];
    }

    /** @return list<string>  'YYYY-MM' */
    private function monthsInRange(string $start, string $end): array
    {
        $months = [];
        $cursor = new DateTimeImmutable(substr($start, 0, 7) . '-01');
        $last   = new DateTimeImmutable(substr($end, 0, 7) . '-01');
        while ($cursor <= $last) {
            $months[] = $cursor->format('Y-m');
            $cursor   = $cursor->modify('+1 month');
        }
        return $months;
    }
}
