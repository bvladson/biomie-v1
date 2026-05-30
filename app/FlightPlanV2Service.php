<?php

declare(strict_types=1);

namespace BIomie;

use DateTimeImmutable;

final class FlightPlanV2Service
{
    public function __construct(
        private readonly CanonicalRepository $canonical,
        private readonly ?TargetsRepository $targetsRepository,
        private readonly float $saturdayWeight = 0.33,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(string $start, string $end, ?string $vendorFilter = null): array
    {
        $months = CommercialPlanning::monthsInRange($start, $end);
        $today = new DateTimeImmutable('today');
        $workdays = WorkingDays::summarize($start, $end, $today, $this->saturdayWeight);
        $targetsMap = ($this->targetsRepository !== null && $this->targetsRepository->isAvailable())
            ? $this->targetsRepository->getTargetsForMonths($months, $vendorFilter)
            : [];

        // Phase F: enriched period fields
        $yearMonth    = substr($start, 0, 7);
        $startDt      = new DateTimeImmutable($start);
        $endDt        = new DateTimeImmutable($end);
        $calendarDays = (int) $startDt->diff($endDt)->days + 1;
        $elapsedDays  = $today >= $startDt
            ? min($calendarDays, (int) $startDt->diff($today <= $endDt ? $today : $endDt)->days + 1)
            : 0;
        $remainingDays  = max(0, $calendarDays - $elapsedDays);
        $isCurrentMonth = $today >= $startDt && $today <= $endDt;

        $vendorMap = [];
        $vendorNameIndex = [];

        foreach ($this->canonical->listCommercialVendorRoster() as $vendor) {
            $this->registerVendorIdentity(
                $vendorMap,
                $vendorNameIndex,
                trim((string) ($vendor['vendor_id'] ?? '')),
                (string) ($vendor['vendor_name'] ?? 'Sem vendedor')
            );
        }

        foreach ($targetsMap as $target) {
            $this->registerVendorIdentity(
                $vendorMap,
                $vendorNameIndex,
                trim((string) ($target['vendor_id'] ?? '')),
                (string) ($target['vendor_name'] ?? 'Sem vendedor')
            );
        }

        $invoicedRows = $this->canonical->aggregateInvoicedByVendorCommercial($start, $end);
        $pipelineRows = $this->canonical->aggregateOrderPipelineByVendorCommercial($start, $end);
        $cancelledRows = $this->canonical->aggregateCancelledByVendorCommercial($start, $end);

        foreach ([$invoicedRows, $pipelineRows, $cancelledRows] as $group) {
            foreach ($group as $row) {
                $this->resolveVendorIdentityKey($row, $vendorMap, $vendorNameIndex);
            }
        }

        require_once __DIR__ . '/Modules/Sales/Application/Vendors/VendorIdentityResolver.php';
        require_once __DIR__ . '/Modules/Sales/Application/Vendors/VendorRealizedService.php';
        $vendorMapCanonical = $this->canonical->getVendorMap();
        $resolver = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMapCanonical);
        $realizedService = new \BIomie\Modules\Sales\Application\Vendors\VendorRealizedService($this->canonical, $resolver);
        $officialRealizedPayload = $realizedService->summarizeByVendor($start, $end);
        
        $officialMap = [];
        foreach ($officialRealizedPayload as $or) {
            $officialMap[$or['vendor_id']] = $or;
        }

        $invoicedByVendor = [];
        foreach ($invoicedRows as $row) {
            $vendorKey = $this->resolveVendorIdentityKey($row, $vendorMap, $vendorNameIndex);
            if ($vendorKey === null) {
                continue;
            }

            $bucket = $invoicedByVendor[$vendorKey] ?? [
                'notes_count'      => 0,
                'customers_count'  => 0,
                'merchandise_total' => 0.0,
                'invoice_total'    => 0.0,
                'discount_total'   => 0.0,
            ];
            $bucket['notes_count']       += (int) ($row['notes_count']      ?? 0);
            $bucket['customers_count']   += (int) ($row['customers_count']  ?? 0);
            $bucket['merchandise_total'] += Support::toFloat($row['merchandise_total'] ?? 0);
            $bucket['invoice_total']     += Support::toFloat($row['invoice_total']     ?? 0);
            $bucket['discount_total']    += Support::toFloat($row['discount_total']    ?? 0);
            $invoicedByVendor[$vendorKey] = $bucket;
        }

        $pipelineByVendor = [];
        foreach ($pipelineRows as $row) {
            $vendorKey = $this->resolveVendorIdentityKey($row, $vendorMap, $vendorNameIndex);
            if ($vendorKey === null) {
                continue;
            }

            $bucket = $pipelineByVendor[$vendorKey] ?? [
                'orders_count' => 0,
                'merchandise_total' => 0.0,
                'order_total' => 0.0,
            ];
            $bucket['orders_count'] += (int) ($row['orders_count'] ?? 0);
            $bucket['merchandise_total'] += Support::toFloat($row['merchandise_total'] ?? 0);
            $bucket['order_total'] += Support::toFloat($row['order_total'] ?? 0);
            $pipelineByVendor[$vendorKey] = $bucket;
        }

        $cancelledByVendor = [];
        foreach ($cancelledRows as $row) {
            $vendorKey = $this->resolveVendorIdentityKey($row, $vendorMap, $vendorNameIndex);
            if ($vendorKey === null) {
                continue;
            }

            $bucket = $cancelledByVendor[$vendorKey] ?? [
                'notes_count' => 0,
                'merchandise_total' => 0.0,
                'invoice_total' => 0.0,
            ];
            $bucket['notes_count'] += (int) ($row['notes_count'] ?? 0);
            $bucket['merchandise_total'] += Support::toFloat($row['merchandise_total'] ?? 0);
            $bucket['invoice_total'] += Support::toFloat($row['invoice_total'] ?? 0);
            $cancelledByVendor[$vendorKey] = $bucket;
        }

        $periodWeight = max(0.0, (float) ($workdays['total_weight'] ?? 0));
        $elapsedWeight = max(0.0, min($periodWeight, (float) ($workdays['elapsed_weight'] ?? 0)));
        $remainingWeight = max(0.0, (float) ($workdays['remaining_weight'] ?? 0));

        $vendors = [];
        $team = [
            'realized_period' => 0.0,
            'invoice_total' => 0.0,
            'merchandise_total' => 0.0,
            'discount_total' => 0.0,
            'target_period' => 0.0,
            'target_month' => 0.0,
            'forecast_period' => 0.0,
            'gap_to_target' => 0.0,
            'new_daily_target' => 0.0,
            'ideal_for_period' => 0.0,
            'real_x_ideal' => 0.0,
            'notes_count' => 0,
            'orders' => 0,
            'pipeline_orders_count' => 0,
            'pipeline_value' => 0.0,
            'backlog_value' => 0.0,
            'cancelled_notes_count' => 0,
            'cancelled_value' => 0.0,
        ];

        ksort($vendorMap);

        foreach ($vendorMap as $vendorKey => $vendorBase) {
            $vendorId = trim((string) ($vendorBase['vendor_id'] ?? ''));
            if ($vendorFilter !== null && $vendorFilter !== '' && $vendorId !== $vendorFilter && $vendorKey !== $vendorFilter) {
                continue;
            }

            $vendorName = self::decodeVendorLabel((string) ($vendorBase['vendor_name'] ?? 'Sem vendedor'));
            $targetPeriod = CommercialPlanning::resolveTargetForPeriod(
                $vendorId,
                $start,
                $end,
                $months,
                $targetsMap,
                $this->saturdayWeight,
                $vendorName
            );
            $targetMonth = CommercialPlanning::resolveFullMonthTarget($vendorId, $months, $targetsMap, $vendorName);

            // Phase F rule 7: exclude vendors without active target from Plano de Voo
            if ($targetPeriod <= 0.0) {
                continue;
            }

            $invoiced = $invoicedByVendor[$vendorKey] ?? [];
            $pipeline = $pipelineByVendor[$vendorKey] ?? [];
            $cancelled = $cancelledByVendor[$vendorKey] ?? [];

            // Get the Official Realized using the new Identity Resolver
            $identity = $resolver->resolve($vendorId !== '' ? $vendorId : $vendorName);
            $official = $officialMap[$identity['vendor_id']] ?? null;

            $customersCount = (int) ($invoiced['customers_count'] ?? 0);

            if ($official !== null) {
                $realized = Support::money((float) ($official['realized'] ?? 0));
                $invoiceTotal = Support::money((float) ($invoiced['invoice_total'] ?? 0));
                $merchandiseTotal = Support::money((float) ($official['merchandise_total'] ?? 0));
                $discountTotal = Support::money((float) ($invoiced['discount_total'] ?? 0));
                $notesCount = (int) ($official['invoices_count'] ?? 0);
                // customers_count comes from canonical invoiced rows (distinct per vendor code)
                if ($customersCount === 0 && isset($official['customers_count'])) {
                    $customersCount = (int) $official['customers_count'];
                }
            } else {
                $realized = Support::money((float) ($invoiced['merchandise_total'] ?? 0)); // fallback
                $invoiceTotal = Support::money((float) ($invoiced['invoice_total'] ?? 0));
                $merchandiseTotal = Support::money((float) ($invoiced['merchandise_total'] ?? 0));
                $discountTotal = Support::money((float) ($invoiced['discount_total'] ?? 0));
                $notesCount = (int) ($invoiced['notes_count'] ?? 0);
            }

            $pipelineOrders = (int) ($pipeline['orders_count'] ?? 0);
            $pipelineValue = Support::money((float) ($pipeline['merchandise_total'] ?? 0));

            $cancelledNotes = (int) ($cancelled['notes_count'] ?? 0);
            $cancelledValue = Support::money((float) ($cancelled['merchandise_total'] ?? 0));

            $metaDayBase = $periodWeight > 0 ? Support::money($targetPeriod / $periodWeight) : 0.0;
            $idealForPeriod = Support::money($metaDayBase * $elapsedWeight);
            $realXIdeal = Support::money($realized - $idealForPeriod);

            $forecasts = CommercialPlanning::calculateForecast($realized, $pipelineValue, $workdays, $today, $start, $end);
            $baseForecast = Support::money((float) ($forecasts['base'] ?? $realized));
            $probableForecast = Support::money((float) ($forecasts['probable'] ?? $realized));
            $commitForecast = Support::money((float) ($forecasts['commit'] ?? $realized));

            $gap = Support::money(max($targetPeriod - $realized, 0));
            $newDailyTarget = $remainingWeight > 0 ? Support::money($gap / $remainingWeight) : 0.0;
            $attainment = $targetPeriod > 0 ? round(($realized / $targetPeriod) * 100, 2) : 0.0;
            $forecastPct = $targetPeriod > 0 ? round(($probableForecast / $targetPeriod) * 100, 2) : 0.0;
            $line85 = Support::money($targetPeriod * 0.85);

            $row = [
                'vendor_key' => $vendorKey,
                'vendor_ref' => $vendorKey,
                'vendor_id' => $vendorId !== '' ? $vendorId : $vendorKey,
                'vendor_name' => self::decodeVendorLabel((string) ($vendorBase['vendor_name'] ?? 'Sem vendedor')),
                'target_period' => $targetPeriod,
                'target_month' => $targetMonth,
                'meta_day_base' => $metaDayBase,
                'realized_period' => $realized,
                'realizado_vendedor' => $realized,
                'mercadoria_faturada_vendedor' => $merchandiseTotal,
                'invoice_total' => $invoiceTotal,
                'merchandise_total' => $merchandiseTotal,
                'discount_total' => $discountTotal,
                'notes_count'          => $notesCount,
                'customers_count'      => $customersCount,
                'orders'               => $notesCount,
                'pipeline_orders_count' => $pipelineOrders,
                'pipeline_value' => $pipelineValue,
                'backlog_value' => $pipelineValue,
                'cancelled_notes_count' => $cancelledNotes,
                'cancelled_value' => $cancelledValue,
                'forecast_period' => $probableForecast,
                'forecasts' => [
                    'base' => $baseForecast,
                    'probable' => $probableForecast,
                    'commit' => $commitForecast,
                ],
                'gap_to_target' => $gap,
                'line_85_value' => $line85,
                'line_85_gap' => Support::money(max($line85 - $realized, 0)),
                'attainment_pct' => $attainment,
                'forecast_pct' => $forecastPct,
                'ideal_for_period' => $idealForPeriod,
                'real_x_ideal' => $realXIdeal,
                'new_daily_target' => $newDailyTarget,
                'coverage_pct' => $targetPeriod > 0 ? round((($realized + $pipelineValue) / $targetPeriod) * 100, 2) : 0.0,
                'risk_status' => CommercialPlanning::riskStatus($probableForecast, $targetPeriod, $line85),
                'share' => 0.0,
                'ticket_avg' => $notesCount > 0 ? Support::money($realized / $notesCount) : 0.0,
            ];

            // Phase F: risk classification (on_track / attention / critical / no_sales)
            $riskNew            = self::classifyRisk($targetPeriod, $realized, $forecastPct);
            $row['risk']        = $riskNew;
            $row['risk_label']  = self::riskLabel($riskNew);
            $row['risk_reason'] = self::riskReasonText($riskNew, $forecastPct);
            $row['risk_severity'] = match ($riskNew) {
                'critical'  => 'danger',
                'no_sales'  => 'warning',
                'attention' => 'warning',
                default     => 'success',
            };
            $row['risk_sort'] = match ($riskNew) {
                'critical'  => 3,
                'no_sales'  => 2,
                'attention' => 1,
                default     => 0,
            };

            $vendors[] = $row;

            $team['realized_period'] += $realized;
            $team['invoice_total'] += $invoiceTotal;
            $team['merchandise_total'] += $merchandiseTotal;
            $team['discount_total'] += $discountTotal;
            $team['target_period'] += $targetPeriod;
            $team['target_month'] += $targetMonth;
            $team['notes_count'] += $notesCount;
            $team['orders'] += $notesCount;
            $team['pipeline_orders_count'] += $pipelineOrders;
            $team['pipeline_value'] += $pipelineValue;
            $team['backlog_value'] += $pipelineValue;
            $team['cancelled_notes_count'] += $cancelledNotes;
            $team['cancelled_value'] += $cancelledValue;
        }

        $team['ideal_for_period'] = $periodWeight > 0 && $team['target_period'] > 0
            ? Support::money(($team['target_period'] / $periodWeight) * $elapsedWeight)
            : 0.0;
        $team['real_x_ideal'] = Support::money($team['realized_period'] - $team['ideal_for_period']);

        $teamForecasts = CommercialPlanning::calculateForecast((float) $team['realized_period'], (float) $team['pipeline_value'], $workdays, $today, $start, $end);
        $teamBaseForecast = Support::money((float) ($teamForecasts['base'] ?? $team['realized_period']));
        $teamProbableForecast = Support::money((float) ($teamForecasts['probable'] ?? $team['realized_period']));
        $teamCommitForecast = Support::money((float) ($teamForecasts['commit'] ?? $team['realized_period']));

        $team['forecast_period'] = $teamProbableForecast;
        $team['forecasts'] = [
            'base' => $teamBaseForecast,
            'probable' => $teamProbableForecast,
            'commit' => $teamCommitForecast,
        ];
        $team['gap_to_target'] = Support::money(max((float) $team['target_period'] - (float) $team['realized_period'], 0));
        $team['new_daily_target'] = $remainingWeight > 0
            ? Support::money((float) $team['gap_to_target'] / $remainingWeight)
            : 0.0;
        $team['attainment_pct'] = (float) $team['target_period'] > 0
            ? round(((float) $team['realized_period'] / (float) $team['target_period']) * 100, 2)
            : 0.0;
        $team['forecast_pct'] = (float) $team['target_period'] > 0
            ? round(($teamProbableForecast / (float) $team['target_period']) * 100, 2)
            : 0.0;
        $team['line_85_value'] = Support::money((float) $team['target_period'] * 0.85);
        $team['line_85_gap'] = Support::money(max((float) $team['line_85_value'] - (float) $team['realized_period'], 0));
        $team['coverage_pct'] = (float) $team['target_period'] > 0
            ? round((((float) $team['realized_period'] + (float) $team['pipeline_value']) / (float) $team['target_period']) * 100, 2)
            : 0.0;
        $team['risk_status'] = CommercialPlanning::riskStatus(
            $teamProbableForecast,
            (float) $team['target_period'],
            (float) $team['line_85_value']
        );
        $team['workdays'] = $workdays;
        $team['basis_value']            = 'invoice_total';
        $team['realized_source_table']  = 'biomie_notas_fiscais_canonicas';
        $team['target_source_table']    = 'biomie_sales_targets_monthly';
        $team['join_key']               = 'vendor_id/codigo_vendedor_omie';
        $team['period_basis']           = 'data_competencia';  // backward compat (realized)
        $team['realized_period_basis']  = 'data_competencia';  // NFs autorizadas — campo data_competencia
        $team['wallet_period_basis']    = 'data_emissao';      // carteira — campo data_emissao do pedido
        $team['target_period_basis']    = 'year_month';        // metas — competencia mensal
        $team['active_vendors_count'] = count(array_filter($vendors, static fn (array $row): bool => (float) ($row['realized_period'] ?? 0) > 0));
        $team['missing_targets_count'] = count(array_filter($vendors, static function (array $row): bool {
            return (float) ($row['target_period'] ?? 0) <= 0
                && (((float) ($row['realized_period'] ?? 0) > 0) || ((float) ($row['pipeline_value'] ?? 0) > 0));
        }));

        usort($vendors, static function (array $left, array $right): int {
            $realizedCompare = (float) ($right['realized_period'] ?? 0) <=> (float) ($left['realized_period'] ?? 0);
            if ($realizedCompare !== 0) {
                return $realizedCompare;
            }

            return strcmp((string) ($left['vendor_name'] ?? ''), (string) ($right['vendor_name'] ?? ''));
        });

        $teamRealized = (float) ($team['realized_period'] ?? 0);
        foreach ($vendors as $index => $row) {
            $vendors[$index]['share'] = $teamRealized > 0
                ? round((((float) ($row['realized_period'] ?? 0)) / $teamRealized) * 100, 2)
                : 0.0;
        }

        // Phase F: team risk counts (all vendors now have targets)
        $team['active_target_vendors'] = count($vendors);
        $team['vendors_on_track']  = count(array_filter($vendors, static fn (array $v): bool => ($v['risk'] ?? '') === 'on_track'));
        $team['vendors_attention'] = count(array_filter($vendors, static fn (array $v): bool => ($v['risk'] ?? '') === 'attention'));
        $team['vendors_critical']  = count(array_filter($vendors, static fn (array $v): bool => ($v['risk'] ?? '') === 'critical'));
        $team['vendors_no_sales']  = count(array_filter($vendors, static fn (array $v): bool => ($v['risk'] ?? '') === 'no_sales'));

        $dailySalesMap = [];
        foreach (Support::eachDate($start, $end) as $date) {
            $dailySalesMap[$date] = [
                'date' => $date,
                'gross_revenue' => 0.0,
                'invoice_total' => 0.0,
                'notes_count' => 0,
                'orders' => 0,
            ];
        }

        if ($vendorFilter === null || $vendorFilter === '') {
            foreach ($this->canonical->dailyInvoicedCommercial($start, $end, null) as $row) {
                $date = (string) ($row['dia'] ?? '');
                if ($date === '' || !isset($dailySalesMap[$date])) {
                    continue;
                }

                $notesCount = (int) ($row['notes_count'] ?? 0);
                $invoiceTotal = Support::money((float) ($row['invoice_total'] ?? 0));
                $dailySalesMap[$date]['gross_revenue'] = $invoiceTotal;
                $dailySalesMap[$date]['invoice_total'] = $invoiceTotal;
                $dailySalesMap[$date]['notes_count'] = $notesCount;
                $dailySalesMap[$date]['orders'] = $notesCount;
            }
        } else {
            foreach ($this->canonical->listInvoicedOrdersForDisplayCommercial($start, $end, null) as $row) {
                $vendorKey = $this->resolveVendorIdentityKey($row, $vendorMap, $vendorNameIndex);
                if ($vendorKey === null) {
                    continue;
                }

                $resolvedVendorId = trim((string) ($vendorMap[$vendorKey]['vendor_id'] ?? ''));
                if ($vendorFilter !== $vendorKey && $vendorFilter !== $resolvedVendorId) {
                    continue;
                }

                $date = (string) ($row['competencia_date'] ?? $row['data_competencia'] ?? $row['issue_date'] ?? $row['billing_date'] ?? '');
                if ($date === '' || !isset($dailySalesMap[$date])) {
                    continue;
                }

                $invoiceTotal = Support::toFloat($row['gross_total'] ?? $row['invoice_total'] ?? $row['total'] ?? 0);
                $dailySalesMap[$date]['gross_revenue'] = Support::money(
                    (float) $dailySalesMap[$date]['gross_revenue'] + $invoiceTotal
                );
                $dailySalesMap[$date]['invoice_total'] = Support::money(
                    (float) $dailySalesMap[$date]['invoice_total'] + $invoiceTotal
                );
                $dailySalesMap[$date]['notes_count']++;
                $dailySalesMap[$date]['orders']++;
            }
        }

        $hasOperationalData = (float) ($team['realized_period'] ?? 0) > 0
            || (float) ($team['pipeline_value'] ?? 0) > 0
            || (float) ($team['cancelled_value'] ?? 0) > 0;
        $hasTargets = (float) ($team['target_period'] ?? 0) > 0;

        $dataQuality = match (true) {
            $hasOperationalData => 'operational',
            $hasTargets         => 'meta_only',
            default             => 'empty',
        };

        // Phase F: risks panel — top critical/attention/no_sales vendors sorted by risk_sort desc
        $riskVendors = array_filter($vendors, static fn (array $v): bool =>
            in_array($v['risk'] ?? '', ['critical', 'no_sales', 'attention'], true)
        );
        usort($riskVendors, static fn (array $a, array $b): int => ($b['risk_sort'] ?? 0) <=> ($a['risk_sort'] ?? 0));
        $risks = [];
        foreach (array_slice(array_values($riskVendors), 0, 10) as $rv) {
            $risks[] = [
                'severity'       => $rv['risk_severity'] ?? 'warning',
                'vendor_id'      => $rv['vendor_id'],
                'vendor_name'    => $rv['vendor_name'],
                'title'          => $rv['risk_label'] ?? 'Risco',
                'description'    => $rv['risk_reason'] ?? '',
                'gap'            => $rv['gap_to_target'] ?? 0,
                'projection_pct' => $rv['forecast_pct'] ?? 0,
                'action'         => ['label' => 'Ver vendedor', 'url' => ''],
            ];
        }

        $dailySalesArr = array_values($dailySalesMap);
        $alertsArr     = $this->buildAlerts($vendors, $team, $hasOperationalData);

        return [
            'ok'           => true,
            'mode'         => 'canonical',
            'source'       => 'flight_plan_v2',
            'data_quality' => $dataQuality,
            'period' => [
                'start'                => $start,
                'end'                  => $end,
                'year_month'           => $yearMonth,
                'elapsed_days'         => $elapsedDays,
                'calendar_days'        => $calendarDays,
                'remaining_days'       => $remainingDays,
                'is_month_in_progress' => $isCurrentMonth,
                'label'                => self::periodLabel($start, $end),
            ],
            'team'        => $team,
            'summary'     => $team,          // Phase F alias
            'vendors'     => $vendors,
            'daily_sales' => $dailySalesArr,
            'daily'       => $dailySalesArr, // Phase F alias
            'alerts'      => $alertsArr,
            'risks'       => $risks,
            'meta' => [
                'generated_at'          => date(DATE_ATOM),
                'last_sync'             => $this->canonical->lastSyncAt($start, $end) ?: null,
                'period_basis'          => 'data_competencia',
                'realized_period_basis' => 'data_competencia',
                'wallet_period_basis'   => 'data_emissao',
                'target_period_basis'   => 'year_month',
                'value_basis'           => 'invoice_total',
                'realized_source_table' => 'biomie_notas_fiscais_canonicas',
                'target_source_table'   => 'biomie_sales_targets_monthly',
                'join_key'              => 'vendor_id/codigo_vendedor_omie',
                'targets' => [
                    'months'                => $months,
                    'missing_vendors_count' => (int) ($team['missing_targets_count'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildTargetsEditor(string $yearMonth, int $page, int $perPage): array
    {
        $start = $yearMonth . '-01';
        $months = CommercialPlanning::monthsInRange($start, $start);
        $targetsMap = ($this->targetsRepository !== null && $this->targetsRepository->isAvailable())
            ? $this->targetsRepository->getTargetsForMonths($months)
            : [];
        $todayMonth = (new DateTimeImmutable('today'))->format('Y-m');
        $locked = $yearMonth < $todayMonth;

        $vendorMap = [];
        foreach ($this->canonical->listCommercialVendorRoster() as $vendor) {
            $vendorId = trim((string) ($vendor['vendor_id'] ?? ''));
            if ($vendorId === '') {
                continue;
            }

            $vendorMap[$vendorId] = [
                'vendor_id' => $vendorId,
                'vendor_name' => self::decodeVendorLabel((string) ($vendor['vendor_name'] ?? 'Sem vendedor')),
            ];
        }

        foreach ($targetsMap as $target) {
            $vendorId = trim((string) ($target['vendor_id'] ?? ''));
            if ($vendorId === '') {
                continue;
            }

            if (!isset($vendorMap[$vendorId])) {
                $vendorMap[$vendorId] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => self::decodeVendorLabel((string) ($target['vendor_name'] ?? 'Sem vendedor')),
                ];
            }
        }

        ksort($vendorMap);

        $rows = [];
        $teamTarget = 0.0;
        $withTargetCount = 0;

        foreach ($vendorMap as $vendorId => $vendorBase) {
            $vendorId = trim((string) ($vendorBase['vendor_id'] ?? $vendorId));
            if ($vendorId === '') {
                continue;
            }

            $vendorName = self::decodeVendorLabel((string) ($vendorBase['vendor_name'] ?? 'Sem vendedor'));
            $targetRow = self::findTargetRowForVendor($targetsMap, $vendorId, $vendorName, $yearMonth);
            $targetPeriod = CommercialPlanning::resolveFullMonthTarget($vendorId, $months, $targetsMap, $vendorName);
            $targetMonth = CommercialPlanning::resolveFullMonthTarget($vendorId, $months, $targetsMap, $vendorName);
            $hasTarget = $targetMonth > 0;

            $rows[] = [
                'vendor_id' => $vendorId,
                'vendor_name' => self::decodeVendorLabel((string) ($vendorBase['vendor_name'] ?? 'Sem vendedor')),
                'realized_period' => 0.0,
                'target_period' => $targetPeriod,
                'target_month' => $targetMonth,
                'meta_day_base' => 0.0,
                'ideal_for_period' => 0.0,
                'forecast_period' => 0.0,
                'gap_to_target' => 0.0,
                'attainment_pct' => 0.0,
                'new_daily_target' => 0.0,
                'risk_status' => $hasTarget ? 'OK' : 'SEM_META',
                'updated_at' => isset($targetRow['updated_at']) ? (string) $targetRow['updated_at'] : null,
                'locked' => $locked,
                'editable' => !$locked,
            ];

            $teamTarget += $targetPeriod;
            if ($hasTarget) {
                $withTargetCount++;
            }
        }

        usort($rows, static fn (array $left, array $right): int => strcmp(
            mb_strtolower((string) ($left['vendor_name'] ?? '')),
            mb_strtolower((string) ($right['vendor_name'] ?? ''))
        ));

        $total = count($rows);
        $offset = ($page - 1) * $perPage;

        return [
            'ok' => true,
            'data' => array_slice($rows, $offset, $perPage),
            'message' => $locked ? 'Historico bloqueado para edicao.' : null,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'year_month' => $yearMonth,
                'source' => 'flight_plan_v2',
                'period_basis' => 'year_month',
                'value_basis' => 'target_amount',
                'target_source_table' => 'biomie_sales_targets_monthly',
                'locked' => $locked,
                'seller_count' => $total,
                'with_target_count' => $withTargetCount,
                'missing_targets_count' => max(0, $total - $withTargetCount),
                'team_target' => Support::money($teamTarget),
                'team_realized' => 0.0,
                'team_forecast' => 0.0,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $vendors
     * @return array<int,array{level:string,title:string,message:string}>
     */
    private function buildAlerts(array $vendors, array $team, bool $hasOperationalData): array
    {
        $alerts = [];

        if (!$hasOperationalData) {
            $hasTargets = (float) ($team['target_period'] ?? 0) > 0;
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Dados operacionais ausentes',
                'message' => $hasTargets
                    ? 'Metas carregadas mas nenhuma NF ou pedido foi encontrado no periodo. Verifique a sincronizacao com o Omie.'
                    : 'Nenhum dado operacional encontrado: sem realizado, pipeline ou canceladas. Sincronize antes de interpretar este painel.',
            ];

            return $alerts;
        }

        $missingTargets = (int) ($team['missing_targets_count'] ?? 0);
        if ($missingTargets > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Metas ausentes',
                'message' => sprintf('%d vendedor(es) ativos sem meta no periodo.', $missingTargets),
            ];
        }

        if ((float) ($team['attainment_pct'] ?? 0) < 85 && (float) ($team['target_period'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Abaixo do ritmo',
                'message' => 'O realizado de mercadorias faturadas esta abaixo da linha de seguranca de 85% da meta.',
            ];
        }

        foreach ($vendors as $vendor) {
            if (($vendor['risk_status'] ?? '') === 'SALA_DE_GUERRA') {
                $alerts[] = [
                    'level' => 'danger',
                    'title' => (string) ($vendor['vendor_name'] ?? 'Vendedor em risco'),
                    'message' => 'A projecao do periodo ficou abaixo da linha minima de seguranca. Revisar carteira e conversao.',
                ];
            }
        }

        if ($alerts === []) {
            $alerts[] = [
                'level' => 'success',
                'title' => 'Plano consistente',
                'message' => 'Metas, realizado faturado e projecao estao sincronizados na mesma base comercial.',
            ];
        }

        return array_slice($alerts, 0, 6);
    }

    private static function periodLabel(string $start, string $end): string
    {
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $end);

        if (!$startDate instanceof DateTimeImmutable || !$endDate instanceof DateTimeImmutable) {
            return $start . ' ate ' . $end;
        }

        return $startDate->format('d/m/Y') . ' ate ' . $endDate->format('d/m/Y');
    }

    /**
     * @param array<string,array{vendor_id:string,vendor_name:string,vendor_key:string}> $vendorMap
     * @param array<string,string> $vendorNameIndex
     */
    private function registerVendorIdentity(array &$vendorMap, array &$vendorNameIndex, string $vendorId, string $vendorName, ?string $preferredKey = null): string
    {
        $vendorId = trim($vendorId);
        $vendorName = self::decodeVendorLabel($vendorName);
        if ($vendorName === '') {
            $vendorName = 'Sem vendedor';
        }

        $vendorKey = $preferredKey ?? ($vendorId !== '' ? $vendorId : self::syntheticVendorKey($vendorName));
        if (!isset($vendorMap[$vendorKey])) {
            $vendorMap[$vendorKey] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $vendorName,
                'vendor_key' => $vendorKey,
            ];
        } else {
            if ($vendorId !== '' && trim((string) ($vendorMap[$vendorKey]['vendor_id'] ?? '')) === '') {
                $vendorMap[$vendorKey]['vendor_id'] = $vendorId;
            }
            if ($vendorName !== '' && trim((string) ($vendorMap[$vendorKey]['vendor_name'] ?? '')) === 'Sem vendedor') {
                $vendorMap[$vendorKey]['vendor_name'] = $vendorName;
            }
        }

        $normalizedName = self::normalizeVendorName($vendorName);
        if ($normalizedName !== '' && !isset($vendorNameIndex[$normalizedName])) {
            $vendorNameIndex[$normalizedName] = $vendorKey;
        }

        return $vendorKey;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array{vendor_id:string,vendor_name:string,vendor_key:string}> $vendorMap
     * @param array<string,string> $vendorNameIndex
     */
    private function resolveVendorIdentityKey(array $row, array &$vendorMap, array &$vendorNameIndex): ?string
    {
        $vendorId = trim((string) ($row['codigo_vendedor_omie'] ?? $row['vendor_id'] ?? ''));
        $vendorName = self::decodeVendorLabel((string) ($row['nome_vendedor'] ?? $row['vendor_name'] ?? ''));

        if ($vendorId !== '' && isset($vendorMap[$vendorId])) {
            return $vendorId;
        }

        $normalizedName = self::normalizeVendorName($vendorName);
        if ($normalizedName !== '' && isset($vendorNameIndex[$normalizedName])) {
            $vendorKey = $vendorNameIndex[$normalizedName];
            if ($vendorId !== '' && trim((string) ($vendorMap[$vendorKey]['vendor_id'] ?? '')) === '') {
                $vendorMap[$vendorKey]['vendor_id'] = $vendorId;
            }
            if ($vendorName !== '' && trim((string) ($vendorMap[$vendorKey]['vendor_name'] ?? '')) === 'Sem vendedor') {
                $vendorMap[$vendorKey]['vendor_name'] = $vendorName;
            }

            return $vendorKey;
        }

        if ($vendorId === '' && $vendorName === '') {
            return null;
        }

        return $this->registerVendorIdentity($vendorMap, $vendorNameIndex, $vendorId, $vendorName);
    }

    private static function normalizeVendorName(string $vendorName): string
    {
        $normalized = trim(mb_strtolower(self::decodeVendorLabel($vendorName)));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        return $normalized;
    }

    private static function decodeVendorLabel(string $vendorName): string
    {
        $decoded = str_contains($vendorName, '%') ? rawurldecode($vendorName) : $vendorName;
        $decoded = trim($decoded);
        return $decoded !== '' ? $decoded : 'Sem vendedor';
    }

    private static function syntheticVendorKey(string $vendorName): string
    {
        return 'name:' . md5(self::normalizeVendorName($vendorName));
    }

    // ── Phase F: risk helpers ─────────────────────────────────────────────────

    private static function classifyRisk(float $target, float $realized, float $forecastPct): string
    {
        if ($target > 0 && $realized <= 0.0) {
            return 'no_sales';
        }
        if ($forecastPct < 70.0) {
            return 'critical';
        }
        if ($forecastPct < 95.0) {
            return 'attention';
        }
        return 'on_track';
    }

    private static function riskLabel(string $risk): string
    {
        return match ($risk) {
            'critical'  => 'Meta em risco',
            'no_sales'  => 'Sem vendas',
            'attention' => 'Atenção',
            default     => 'No ritmo',
        };
    }

    private static function riskReasonText(string $risk, float $forecastPct): string
    {
        return match ($risk) {
            'critical'  => sprintf('Projeção %.1f%% — abaixo do mínimo de 70%%', $forecastPct),
            'no_sales'  => 'Sem faturamento no período',
            'attention' => sprintf('Projeção %.1f%% — abaixo de 95%%', $forecastPct),
            default     => sprintf('Projeção %.1f%% — no alvo', $forecastPct),
        };
    }

    /**
     * @param array<string,array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,currency:string,updated_at:?string}> $targetsMap
     * @return array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,currency:string,updated_at:?string}|null
     */
    private static function findTargetRowForVendor(array $targetsMap, string $vendorId, string $vendorName, string $yearMonth): ?array
    {
        foreach ($targetsMap as $targetRow) {
            if ((string) ($targetRow['year_month'] ?? '') !== $yearMonth) {
                continue;
            }

            $targetVendorId = trim((string) ($targetRow['vendor_id'] ?? ''));
            $targetVendorName = (string) ($targetRow['vendor_name'] ?? '');
            $matches = ($vendorId !== '' && $targetVendorId !== '' && $targetVendorId === $vendorId)
                || (
                    self::normalizeVendorName($targetVendorName) !== ''
                    && self::normalizeVendorName($targetVendorName) === self::normalizeVendorName($vendorName)
                );
            if (!$matches) {
                continue;
            }

            return $targetRow;
        }

        return null;
    }
}
