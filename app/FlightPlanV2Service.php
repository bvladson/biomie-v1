<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Commercial\TargetLadder;
use BIomie\Support\VendorAliasResolver;
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

        $vendorRoster = $this->canonical->listCommercialVendorRoster();
        foreach ($vendorRoster as $vendor) {
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
                trim($target['vendor_id']),
                $target['vendor_name']
            );
        }

        $invoicedDisplayRows = $this->canonical->listInvoicedOrdersForDisplayCommercial($start, $end, null, false);
        $invoicedRows = $this->aggregateInvoicedDisplayRows($invoicedDisplayRows);
        $pipelineRows = $this->canonical->aggregateOrderPipelineByVendorCommercial($start, $end);
        $cancelledRows = $this->canonical->aggregateCancelledByVendorCommercial($start, $end);

        foreach ([$invoicedRows, $pipelineRows, $cancelledRows] as $group) {
            foreach ($group as $row) {
                $this->resolveVendorIdentityKey($row, $vendorMap, $vendorNameIndex);
            }
        }

        require_once __DIR__ . '/Modules/Sales/Application/Vendors/VendorIdentityResolver.php';
        require_once __DIR__ . '/Modules/Sales/Application/Vendors/VendorRealizedService.php';
        $vendorMapCanonical = [];
        foreach ($vendorRoster as $vendor) {
            $vendorId = trim((string) ($vendor['vendor_id'] ?? ''));
            if ($vendorId === '') {
                continue;
            }
            $vendorMapCanonical[$vendorId] = [
                'codigo_omie' => $vendorId,
                'nome' => (string) ($vendor['vendor_name'] ?? ''),
            ];
        }
        $resolver = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMapCanonical);
        $aliasResolver = new VendorAliasResolver();
        $realizedService = new \BIomie\Modules\Sales\Application\Vendors\VendorRealizedService($this->canonical, $resolver);
        $officialRealizedPayload = $realizedService->summarizeRowsByVendor($invoicedDisplayRows);
        
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
            'base_target' => 0.0,
            'active_target' => 0.0,
            'maximum_target' => 0.0,
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
                $billedOrdersCount = (int) ($official['orders_count'] ?? $notesCount);
                // customers_count comes from canonical invoiced rows (distinct per vendor code)
                if ($customersCount === 0 && isset($official['customers_count'])) {
                    $customersCount = (int) $official['customers_count'];
                }
            } else {
                $realized = Support::money((float) ($invoiced['invoice_total'] ?? 0));
                $invoiceTotal = Support::money((float) ($invoiced['invoice_total'] ?? 0));
                $merchandiseTotal = Support::money((float) ($invoiced['merchandise_total'] ?? 0));
                $discountTotal = Support::money((float) ($invoiced['discount_total'] ?? 0));
                $notesCount = (int) ($invoiced['notes_count'] ?? 0);
                $billedOrdersCount = $notesCount;
            }

            $pipelineOrders = (int) ($pipeline['orders_count'] ?? 0);
            $pipelineValue = Support::money((float) ($pipeline['merchandise_total'] ?? 0));

            $cancelledNotes = (int) ($cancelled['notes_count'] ?? 0);
            $cancelledValue = Support::money((float) ($cancelled['merchandise_total'] ?? 0));
            $hasOperationalSignal = $realized > 0.0
                || $pipelineValue > 0.0
                || $cancelledValue > 0.0;

            // Missing targets are actionable warnings; they must not hide real sales.
            if ($targetPeriod <= 0.0 && !$hasOperationalSignal) {
                continue;
            }

            $channel = (string) $aliasResolver->resolve($vendorName)['vendor_channel'];
            if ($channel === VendorAliasResolver::CHANNEL_LICITACAO) {
                continue;
            }

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
            $line85 = Support::money($targetPeriod * (CommercialPlanning::RISK_ATTENTION_PCT / 100));

            $row = [
                'vendor_key' => $vendorKey,
                'vendor_ref' => $vendorKey,
                'vendor_id' => $vendorId !== '' ? $vendorId : $vendorKey,
                'vendor_name' => self::decodeVendorLabel((string) ($vendorBase['vendor_name'] ?? 'Sem vendedor')),
                'vendor_channel' => $channel,
                'channel_label' => self::channelLabel($channel),
                'has_target' => $targetPeriod > 0.0,
                'has_operational_signal' => $hasOperationalSignal,
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
                'orders'               => $billedOrdersCount,
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

            // Metas múltiplas — nível atual/próximo nível (opcional por vendedor)
            $tierInfo = self::buildTierIntelligence(
                $targetsMap,
                $vendorId,
                $vendorName,
                $months,
                $targetPeriod,
                $targetMonth,
                $realized
            );
            if ($tierInfo !== null) {
                $row = array_merge($row, $tierInfo);
            }

            // A conquista da meta-base permanece registrada; gap, ritmo e risco
            // do desafio passam a mirar o primeiro nível ainda não atingido.
            $row['base_target'] = (float) ($row['base_target'] ?? $targetPeriod);
            $row['maximum_target'] = (float) ($row['maximum_target'] ?? $targetPeriod);
            $activeTarget = $tierInfo === null
                ? ($targetPeriod > 0 ? $targetPeriod : null)
                : (isset($row['active_target']) && $row['active_target'] !== null
                    ? (float) $row['active_target']
                    : null);
            $row['active_target_period'] = $activeTarget;
            $comparisonTarget = (float) ($activeTarget ?? $row['maximum_target']);
            $row['base_gap'] = Support::money(max($targetPeriod - $realized, 0));
            $row['gap_to_target'] = $activeTarget !== null
                ? Support::money(max($activeTarget - $realized, 0))
                : 0.0;
            $row['new_daily_target'] = $activeTarget !== null && $remainingWeight > 0
                ? Support::money($row['gap_to_target'] / $remainingWeight)
                : 0.0;
            $row['active_forecast_pct'] = $activeTarget !== null && $activeTarget > 0
                ? round(($probableForecast / $activeTarget) * 100, 2)
                : 100.0;
            $row['base_attainment_pct'] = $targetPeriod > 0
                ? round(($realized / $targetPeriod) * 100, 2)
                : 0.0;
            $row['attainment_pct'] = $comparisonTarget > 0
                ? round(($realized / $comparisonTarget) * 100, 2)
                : 0.0;
            $row['forecast_pct'] = $comparisonTarget > 0
                ? round(($probableForecast / $comparisonTarget) * 100, 2)
                : 0.0;
            $row['line_85_value'] = Support::money($comparisonTarget * (CommercialPlanning::RISK_ATTENTION_PCT / 100));
            $row['line_85_gap'] = Support::money(max((float) $row['line_85_value'] - $realized, 0));
            $row['risk_status'] = CommercialPlanning::riskStatus(
                $probableForecast,
                $comparisonTarget,
                (float) $row['line_85_value']
            );
            $row['challenge_risk'] = $activeTarget === null
                ? 'all_levels_achieved'
                : self::classifyRisk($activeTarget, $realized, (float) $row['active_forecast_pct']);

            // Phase F: risk classification (on_track / attention / critical / no_sales)
            $riskNew            = self::classifyRisk($comparisonTarget, $realized, (float) $row['forecast_pct']);
            $row['risk']        = $riskNew;
            $row['risk_label']  = self::riskLabel($riskNew);
            $row['risk_reason'] = self::riskReasonText($riskNew, (float) $row['forecast_pct']);
            $row['risk_severity'] = match ($riskNew) {
                'no_target' => 'danger',
                'critical'  => 'danger',
                'no_sales'  => 'warning',
                'attention' => 'warning',
                default     => 'success',
            };
            $row['risk_sort'] = match ($riskNew) {
                'no_target' => 4,
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
            $team['base_target'] += (float) $row['base_target'];
            $team['active_target'] += $activeTarget ?? (float) $row['maximum_target'];
            $team['maximum_target'] += (float) $row['maximum_target'];
            $team['notes_count'] += $notesCount;
            $team['orders'] += $billedOrdersCount;
            $team['pipeline_orders_count'] += $pipelineOrders;
            $team['pipeline_value'] += $pipelineValue;
            $team['backlog_value'] += $pipelineValue;
            $team['cancelled_notes_count'] += $cancelledNotes;
            $team['cancelled_value'] += $cancelledValue;
        }

        $team['base_ideal_for_period'] = $periodWeight > 0 && $team['target_period'] > 0
            ? Support::money(($team['target_period'] / $periodWeight) * $elapsedWeight)
            : 0.0;
        $team['ideal_for_period'] = $periodWeight > 0 && $team['active_target'] > 0
            ? Support::money(($team['active_target'] / $periodWeight) * $elapsedWeight)
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
        $team['base_gap'] = Support::money(max((float) $team['target_period'] - (float) $team['realized_period'], 0));
        $team['active_gap'] = Support::money(max((float) $team['active_target'] - (float) $team['realized_period'], 0));
        $team['gap_to_target'] = $team['active_gap'];
        $team['new_daily_target'] = $remainingWeight > 0
            ? Support::money((float) $team['active_gap'] / $remainingWeight)
            : 0.0;
        $team['base_attainment_pct'] = (float) $team['target_period'] > 0
            ? round(((float) $team['realized_period'] / (float) $team['target_period']) * 100, 2)
            : 0.0;
        $team['attainment_pct'] = (float) $team['active_target'] > 0
            ? round(((float) $team['realized_period'] / (float) $team['active_target']) * 100, 2)
            : 0.0;
        $team['forecast_pct'] = (float) $team['active_target'] > 0
            ? round(($teamProbableForecast / (float) $team['active_target']) * 100, 2)
            : 0.0;
        $team['active_forecast_pct'] = (float) $team['active_target'] > 0
            ? round(($teamProbableForecast / (float) $team['active_target']) * 100, 2)
            : 0.0;
        $team['maximum_attainment_pct'] = (float) $team['maximum_target'] > 0
            ? round(((float) $team['realized_period'] / (float) $team['maximum_target']) * 100, 2)
            : 0.0;
        $team['line_85_value'] = Support::money((float) $team['active_target'] * (CommercialPlanning::RISK_ATTENTION_PCT / 100));
        $team['line_85_gap'] = Support::money(max((float) $team['line_85_value'] - (float) $team['realized_period'], 0));
        $team['coverage_pct'] = (float) $team['active_target'] > 0
            ? round((((float) $team['realized_period'] + (float) $team['pipeline_value']) / (float) $team['active_target']) * 100, 2)
            : 0.0;
        $team['risk_status'] = CommercialPlanning::riskStatus(
            $teamProbableForecast,
            (float) $team['active_target'],
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
        $team['active_vendors_count'] = count(array_filter($vendors, static fn (array $row): bool => (float) $row['realized_period'] > 0));
        $team['missing_targets_count'] = count(array_filter($vendors, static function (array $row): bool {
            return (float) $row['target_period'] <= 0
                && (((float) $row['realized_period'] > 0) || ((float) $row['pipeline_value'] > 0));
        }));

        usort($vendors, static function (array $left, array $right): int {
            $realizedCompare = (float) $right['realized_period'] <=> (float) $left['realized_period'];
            if ($realizedCompare !== 0) {
                return $realizedCompare;
            }

            return strcmp((string) $left['vendor_name'], (string) $right['vendor_name']);
        });

        $teamRealized = (float) $team['realized_period'];
        foreach ($vendors as $index => $row) {
            $vendors[$index]['share'] = $teamRealized > 0
                ? round((((float) $row['realized_period']) / $teamRealized) * 100, 2)
                : 0.0;
        }

        $team['active_target_vendors'] = count(array_filter($vendors, static fn (array $v): bool => (float) $v['target_period'] > 0));
        $team['vendors_without_target'] = (int) $team['missing_targets_count'];
        $team['vendors_on_track']  = count(array_filter($vendors, static fn (array $v): bool => $v['risk'] === 'on_track'));
        $team['vendors_attention'] = count(array_filter($vendors, static fn (array $v): bool => $v['risk'] === 'attention'));
        $team['vendors_critical']  = count(array_filter($vendors, static fn (array $v): bool => $v['risk'] === 'critical'));
        $team['vendors_no_sales']  = count(array_filter($vendors, static fn (array $v): bool => $v['risk'] === 'no_sales'));
        $team['vendors_base_achieved'] = count(array_filter($vendors, static fn (array $v): bool => (int) ($v['achieved_level'] ?? 0) >= 1));
        $team['vendors_all_levels_achieved'] = count(array_filter($vendors, static fn (array $v): bool => ($v['all_levels_achieved'] ?? false) === true));
        $team['vendors_by_active_level'] = [];
        foreach ($vendors as $vendor) {
            $level = $vendor['active_level'] ?? null;
            if ($level === null) continue;
            $key = (string) $level;
            $team['vendors_by_active_level'][$key] = ($team['vendors_by_active_level'][$key] ?? 0) + 1;
        }

        $dailySalesMap = [];
        foreach (Support::eachDate($start, $end) as $date) {
            $dailySalesMap[$date] = [
                'date' => $date,
                'gross_revenue' => 0.0,
                'invoice_total' => 0.0,
                'notes_count' => 0,
                'orders' => 0,
                'workday_weight' => WorkingDays::weightedBetween($date, $date, $this->saturdayWeight),
            ];
        }

        // Build the daily series from the same commercial-base rows used by the
        // vendor realized total, avoiding a raw-invoice-total mismatch in charts.
        foreach ($invoicedDisplayRows as $row) {
            $vendorKey = $this->resolveVendorIdentityKey($row, $vendorMap, $vendorNameIndex);
            if ($vendorKey === null) {
                continue;
            }

            $resolvedVendorId = trim((string) ($vendorMap[$vendorKey]['vendor_id'] ?? ''));
            if ($vendorFilter !== null && $vendorFilter !== ''
                && $vendorFilter !== $vendorKey && $vendorFilter !== $resolvedVendorId) {
                continue;
            }

            $date = (string) ($row['competencia_date'] ?? $row['data_competencia'] ?? $row['issue_date'] ?? $row['billing_date'] ?? '');
            if ($date === '' || !isset($dailySalesMap[$date])) {
                continue;
            }

            $commercialBase = Support::toFloat($row['gross_total'] ?? $row['total'] ?? 0);
            $dailySalesMap[$date]['gross_revenue'] = Support::money(
                (float) $dailySalesMap[$date]['gross_revenue'] + $commercialBase
            );
            $dailySalesMap[$date]['invoice_total'] = Support::money(
                (float) $dailySalesMap[$date]['invoice_total'] + $commercialBase
            );
            $dailySalesMap[$date]['notes_count']++;
            $dailySalesMap[$date]['orders']++;
        }

        $hasOperationalData = (float) $team['realized_period'] > 0
            || (float) $team['pipeline_value'] > 0
            || (float) $team['cancelled_value'] > 0;
        $hasTargets = (float) $team['target_period'] > 0;

        $dataQuality = match (true) {
            $hasOperationalData => 'operational',
            $hasTargets         => 'meta_only',
            default             => 'empty',
        };

        // Phase F: risks panel — top critical/attention/no_sales vendors sorted by risk_sort desc
        $riskVendors = array_filter($vendors, static fn (array $v): bool =>
            in_array($v['risk'], ['no_target', 'critical', 'no_sales', 'attention'], true)
        );
        usort($riskVendors, static fn (array $a, array $b): int => $b['risk_sort'] <=> $a['risk_sort']);
        $risks = [];
        foreach (array_slice(array_values($riskVendors), 0, 10) as $rv) {
            $risks[] = [
                'severity'       => $rv['risk_severity'],
                'vendor_id'      => $rv['vendor_id'],
                'vendor_name'    => $rv['vendor_name'],
                'title'          => $rv['risk_label'],
                'description'    => $rv['risk_reason'],
                'gap'            => $rv['gap_to_target'],
                'projection_pct' => $rv['forecast_pct'],
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
                    'missing_vendors_count' => (int) $team['missing_targets_count'],
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
            $vendorId = trim($target['vendor_id']);
            if ($vendorId === '') {
                continue;
            }

            if (!isset($vendorMap[$vendorId])) {
                $vendorMap[$vendorId] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => self::decodeVendorLabel($target['vendor_name']),
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
                // Metas múltiplas: níveis opcionais além da meta base (Meta 2..N)
                'tiers' => $targetRow['tiers'] ?? [],
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
            mb_strtolower((string) $left['vendor_name']),
            mb_strtolower((string) $right['vendor_name'])
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
     * @return array<int,array<string,mixed>>
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
                'title' => 'Metas pendentes',
                'message' => sprintf('%d vendedor(es) com atividade ainda precisam de meta para o período.', $missingTargets),
                'action' => ['label' => 'Definir metas', 'url' => 'plano_voo_metas.php'],
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
            if ((int) ($vendor['achieved_level'] ?? 0) >= 1
                && ($vendor['all_levels_achieved'] ?? false) !== true
                && in_array((string) ($vendor['challenge_risk'] ?? ''), ['attention', 'critical'], true)) {
                $alerts[] = [
                    'level' => 'info',
                    'title' => (string) ($vendor['vendor_name'] ?? 'Próximo desafio'),
                    'message' => sprintf(
                        'Compromisso cumprido. Meta %d ativa; faltam %s para o próximo nível.',
                        (int) ($vendor['active_level'] ?? 1),
                        'R$ ' . number_format((float) ($vendor['active_gap'] ?? $vendor['gap_to_target'] ?? 0), 2, ',', '.')
                    ),
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
        if ($target <= 0.0) {
            return 'no_target';
        }
        if ($target > 0 && $realized <= 0.0) {
            return 'no_sales';
        }
        // Régua única (CommercialPlanning): mesma linha de corte da tabela e
        // dos alertas — antes havia 70/95 aqui e 85/100 lá, e o mesmo vendedor
        // aparecia "Atenção" numa visão e "Sala de guerra" na outra.
        if ($forecastPct < CommercialPlanning::RISK_ATTENTION_PCT) {
            return 'critical';
        }
        if ($forecastPct < CommercialPlanning::RISK_ON_TRACK_PCT) {
            return 'attention';
        }
        return 'on_track';
    }

    private static function riskLabel(string $risk): string
    {
        return match ($risk) {
            'no_target' => 'Meta pendente',
            'critical'  => 'Meta em risco',
            'no_sales'  => 'Sem vendas',
            'attention' => 'Atenção',
            default     => 'No ritmo',
        };
    }

    private static function riskReasonText(string $risk, float $forecastPct): string
    {
        return match ($risk) {
            'no_target' => 'Há vendas ou carteira, mas a meta do período ainda não foi definida',
            'critical'  => sprintf('Projeção %.1f%% — abaixo do mínimo de 70%%', $forecastPct),
            'no_sales'  => 'Sem faturamento no período',
            'attention' => sprintf('Projeção %.1f%% — abaixo de 95%%', $forecastPct),
            default     => sprintf('Projeção %.1f%% — no alvo', $forecastPct),
        };
    }

    /**
     * Reuses the fiscal rows already needed by the official realized total and
     * daily series, avoiding another aggregation query over the same period.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function aggregateInvoicedDisplayRows(array $rows): array
    {
        $byVendor = [];
        foreach ($rows as $row) {
            $vendorId = trim((string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
            $vendorName = self::decodeVendorLabel((string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? 'Sem vendedor'));
            $key = $vendorId !== '' ? 'id:' . $vendorId : 'name:' . self::normalizeVendorName($vendorName);
            $customerId = trim((string) ($row['customer_id'] ?? $row['codigo_cliente_omie'] ?? ''));

            $bucket = $byVendor[$key] ?? [
                'codigo_vendedor_omie' => $vendorId,
                'nome_vendedor' => $vendorName,
                'notes_count' => 0,
                'customers_count' => 0,
                'merchandise_total' => 0.0,
                'invoice_total' => 0.0,
                'discount_total' => 0.0,
                '_customers' => [],
            ];
            $bucket['notes_count']++;
            $bucket['merchandise_total'] += Support::toFloat($row['merchandise_total'] ?? 0);
            $bucket['invoice_total'] += Support::toFloat($row['valor_total_nf'] ?? $row['total'] ?? 0);
            $bucket['discount_total'] += Support::toFloat($row['discount_total'] ?? 0);
            if ($customerId !== '' && $customerId !== '0') {
                $bucket['_customers'][$customerId] = true;
            }
            $byVendor[$key] = $bucket;
        }

        foreach ($byVendor as &$bucket) {
            $bucket['customers_count'] = count($bucket['_customers']);
            unset($bucket['_customers']);
        }
        unset($bucket);

        return array_values($byVendor);
    }

    private static function channelLabel(string $channel): string
    {
        return match ($channel) {
            VendorAliasResolver::CHANNEL_REV => 'Revenda',
            VendorAliasResolver::CHANNEL_VAR => 'Varejo',
            VendorAliasResolver::CHANNEL_REPRESENTANTE => 'Representante',
            VendorAliasResolver::CHANNEL_LICITACAO => 'Licitação',
            default => 'Não definido',
        };
    }

    /**
     * @param array<string,array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,tiers:list<float>,currency:string,updated_at:?string}> $targetsMap
     * @return array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,tiers:list<float>,currency:string,updated_at:?string}|null
     */
    private static function findTargetRowForVendor(array $targetsMap, string $vendorId, string $vendorName, string $yearMonth): ?array
    {
        foreach ($targetsMap as $targetRow) {
            if ($targetRow['year_month'] !== $yearMonth) {
                continue;
            }

            $targetVendorId = trim($targetRow['vendor_id']);
            $targetVendorName = $targetRow['vendor_name'];
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

    /**
     * Metas múltiplas — leitura inteligente por vendedor.
     *
     * Soma cada nível (Meta 2..N) nos meses do período, escala pela MESMA proporção
     * usada na meta base (targetPeriod/targetMonth — respeita a ponderação de dias
     * úteis/sábados vigente) e resolve nível atual + próximo nível.
     *
     * @param array<string,array<string,mixed>> $targetsMap
     * @param list<string> $months
     * @return array<string,mixed>|null null quando o vendedor não tem níveis definidos
     */
    private static function buildTierIntelligence(
        array $targetsMap,
        string $vendorId,
        string $vendorName,
        array $months,
        float $targetPeriod,
        float $targetMonth,
        float $realized
    ): ?array {
        if ($targetMonth <= 0) {
            return null; // níveis exigem meta base (validação do upsert também garante)
        }

        // Soma tier_i em todos os meses do período (mês sem o nível contribui 0)
        $tierSums = [];
        foreach ($targetsMap as $targetRow) {
            $ym = (string) ($targetRow['year_month'] ?? '');
            if (!in_array($ym, $months, true)) {
                continue;
            }
            $rowVendorId   = trim((string) ($targetRow['vendor_id'] ?? ''));
            $rowVendorName = self::normalizeVendorName((string) ($targetRow['vendor_name'] ?? ''));
            $matches = ($vendorId !== '' && $rowVendorId !== '' && $rowVendorId === $vendorId)
                || ($rowVendorName !== '' && $rowVendorName === self::normalizeVendorName($vendorName));
            if (!$matches) {
                continue;
            }
            $tiers = is_array($targetRow['tiers'] ?? null) ? $targetRow['tiers'] : [];
            foreach (array_values($tiers) as $i => $tierValue) {
                $tierSums[$i] = ($tierSums[$i] ?? 0.0) + (float) $tierValue;
            }
        }

        if ($tierSums === []) {
            return null;
        }

        ksort($tierSums);
        $ratio = $targetPeriod / $targetMonth;

        // Níveis do período: base (Meta 1) + níveis escalados, crescentes
        $levels = [round($targetPeriod, 2)];
        foreach ($tierSums as $sum) {
            $scaled = round($sum * $ratio, 2);
            if ($scaled > end($levels)) {
                $levels[] = $scaled;
            }
        }

        $achieved = 0;
        foreach ($levels as $level) {
            if ($realized >= $level && $level > 0) {
                $achieved++;
            }
        }

        $nextValue = null;
        foreach ($levels as $level) {
            if ($level > $realized) {
                $nextValue = $level;
                break;
            }
        }

        return TargetLadder::evaluate($levels, $realized);
    }
}
