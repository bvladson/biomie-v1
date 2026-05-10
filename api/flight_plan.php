<?php

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\Database;
use BIomie\FlightPlanService;
use BIomie\FlightPlanV2Service;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;
use BIomie\TargetsRepository;
use BIomie\WorkingDays;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

try {
    $range = Http::resolveDateRangeOrCompetencia();
    Http::validateDateRange($range['start'], $range['end']);

    $start = $range['start'];
    $end = $range['end'];
    $vendorId = Http::query('vendor_id');
    $force = Http::boolQuery('force', false);
    $saturdayWeight = (float) $config->get('flight_plan.saturday_weight', 0.33);

    $targetsRepository = null;
    $canonical = null;
    $targetsConnection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

    if (Database::isConfigured($config, $targetsConnection)) {
        try {
            $pdo = Database::connect($config, $targetsConnection);
            $targetsRepository = new TargetsRepository($pdo, $logger);

            $repo = new CanonicalRepository($pdo, $logger);
            if ($repo->isAvailable()) {
                $canonical = $repo;
            }

            if ($targetsConnection !== 'flight_plan_db') {
                $logger->warning('Plano de Voo usando conexao global de banco para metas. Recomendado configurar flight_plan_db.', [
                    'connection' => $targetsConnection,
                ]);
            }
        } catch (Throwable $exception) {
            $logger->warning('Plano de Voo sem conexao MySQL ativa; seguindo com fallback.', [
                'message' => $exception->getMessage(),
                'connection' => $targetsConnection,
            ]);
        }
    }

    if ($canonical !== null && (bool) $config->get('features.canonical_fiscal_ready', false)) {
        $historical = new CommercialCompetenciaSnapshotService($pdo, $logger, $canonical, $targetsRepository, $saturdayWeight);
        $snapshotYm = $historical->shouldUseSnapshot($start, $end, $range['competencia']);
        if ($snapshotYm !== null && ($vendorId === null || $vendorId === '')) {
            try {
                $payload = $historical->loadPayload('flight_plan', $snapshotYm);
                if ($force || !is_array($payload)) {
                    $logger->warning('Plano de Voo: snapshot historico nao materializado durante request; seguindo com leitura canonical.', [
                        'competencia' => $snapshotYm,
                        'force' => $force,
                        'request_id' => RequestContext::id(),
                    ]);
                } elseif (canonicalFlightPlanPayloadHasSignal($payload)) {
                    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
                        $payload['meta'] = [];
                    }
                    if (!isset($payload['meta']['targets']) || !is_array($payload['meta']['targets'])) {
                        $payload['meta']['targets'] = [];
                    }
                    $payload['meta']['targets']['connection'] = $targetsConnection;
                    $payload['data_quality'] = $payload['data_quality'] ?? 'operational';
                    Http::json($payload);
                }

                $logger->warning('Plano de Voo: snapshot historico sem sinal; mantendo fallback do periodo.', [
                    'competencia' => $snapshotYm,
                    'request_id' => RequestContext::id(),
                ]);
            } catch (Throwable $exception) {
                $logger->warning('Plano de Voo: snapshot historico indisponivel; seguindo com leitura canonical.', [
                    'competencia' => $snapshotYm,
                    'message' => $exception->getMessage(),
                    'request_id' => RequestContext::id(),
                ]);
            }
        }

        $serviceV2 = new FlightPlanV2Service($canonical, $targetsRepository, $saturdayWeight);
        $payload = $serviceV2->build($start, $end, $vendorId);

        if (!isset($payload['meta']) || !is_array($payload['meta'])) {
            $payload['meta'] = [];
        }
        if (!isset($payload['meta']['targets']) || !is_array($payload['meta']['targets'])) {
            $payload['meta']['targets'] = [];
        }

        $payload['meta']['targets']['connection'] = $targetsConnection;
        $payload['data_quality'] = $payload['data_quality'] ?? (canonicalFlightPlanPayloadHasSignal($payload) ? 'operational' : 'meta_only');
        if (($vendorId !== null && $vendorId !== '') || canonicalFlightPlanPayloadHasSignal($payload)) {
            Http::json($payload);
        }

        $logger->warning('Plano de Voo: canonical sem sinal no periodo aberto; mantendo fallback legado.', [
            'period' => ['start' => $start, 'end' => $end],
            'request_id' => RequestContext::id(),
        ]);
    }

    $service = new FlightPlanService($config, $cache, $logger, $analytics, $targetsRepository);
    $payload = $service->build($start, $end, $force, $vendorId);

    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }
    if (!isset($payload['meta']['targets']) || !is_array($payload['meta']['targets'])) {
        $payload['meta']['targets'] = [];
    }

    $payload['meta']['targets']['connection'] = $targetsConnection;
    $payload['source'] = $payload['source'] ?? 'legacy';
    $payload['data_quality'] = $payload['data_quality'] ?? 'fallback';

    Http::json($payload);
} catch (InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->error('Falha ao gerar Plano de Voo.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Nao foi possivel gerar o Plano de Voo. Tente novamente.',
        'request_id' => RequestContext::id(),
    ], 500);
}

/**
 * @return array<string,mixed>
 */
function buildCanonicalFlightPlanPayload(
    CanonicalRepository $canonical,
    ?TargetsRepository $targetsRepository,
    string $start,
    string $end,
    ?string $vendorFilter,
    float $saturdayWeight
): array {
    $months = monthsInRangeFlightPlan($start, $end);
    $today = new DateTimeImmutable('today');
    $workdays = WorkingDays::summarize($start, $end, $today, $saturdayWeight);
    $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
        ? $targetsRepository->getTargetsForMonths($months, $vendorFilter)
        : [];

    $vendorRows = $canonical->aggregateByVendorCommercial($start, $end);
    $dailyRows = $canonical->dailyByStatusCommercial($start, $end);
    $vendorMap = $canonical->getVendorMap();
    $lastSync = $canonical->lastSyncAt($start, $end) ?: date(DATE_ATOM);

    $vendorsByKey = [];
    foreach ($vendorRows as $row) {
        $vendorId = trim((string) ($row['codigo_vendedor_omie'] ?? ''));
        if ($vendorFilter !== null && $vendorFilter !== '' && $vendorId !== $vendorFilter) {
            continue;
        }

        $vendorName = trim((string) ($row['nome_vendedor'] ?? 'Sem vendedor'));
        $key = vendorKeyFlightPlan($vendorId, $vendorName);
        if (!isset($vendorsByKey[$key])) {
            $vendorsByKey[$key] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $vendorName !== '' ? $vendorName : 'Sem vendedor',
                'realized' => 0.0,
                'backlog' => 0.0,
                'orders' => 0,
            ];
        }

        $status = (string) ($row['status_comercial'] ?? 'outro');
        $total = Support::toFloat($row['total'] ?? 0);
        $orders = (int) ($row['pedidos'] ?? 0);

        if ($status === 'faturado') {
            $vendorsByKey[$key]['realized'] += $total;
            $vendorsByKey[$key]['orders'] += $orders;
        } elseif ($status === 'autorizado') {
            $vendorsByKey[$key]['backlog'] += $total;
        }
    }

    foreach ($targetsMap as $targetRow) {
        $vendorId = trim((string) ($targetRow['vendor_id'] ?? ''));
        if ($vendorFilter !== null && $vendorFilter !== '' && $vendorId !== $vendorFilter) {
            continue;
        }

        $vendorName = trim((string) ($targetRow['vendor_name'] ?? ($vendorMap[$vendorId]['nome'] ?? 'Sem vendedor')));
        $key = vendorKeyFlightPlan($vendorId, $vendorName);
        if (!isset($vendorsByKey[$key])) {
            $vendorsByKey[$key] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $vendorName !== '' ? $vendorName : 'Sem vendedor',
                'realized' => 0.0,
                'backlog' => 0.0,
                'orders' => 0,
            ];
        }
    }

    $vendors = [];
    $teamRealized = 0.0;
    $teamBacklog = 0.0;
    $teamTarget = 0.0;
    $missingTargets = 0;

    foreach ($vendorsByKey as $row) {
        $vendorId = (string) ($row['vendor_id'] ?? '');
        $vendorName = (string) ($row['vendor_name'] ?? ($vendorMap[$vendorId]['nome'] ?? 'Sem vendedor'));
        $realized = Support::money((float) ($row['realized'] ?? 0));
        $backlog = Support::money((float) ($row['backlog'] ?? 0));
        $orders = (int) ($row['orders'] ?? 0);

        $targetPeriod = resolveVendorTargetForPeriodFlightPlan($vendorId, $start, $end, $months, $targetsMap, $saturdayWeight);
        $targetMonth = resolveVendorFullMonthTargetFlightPlan($vendorId, $months, $targetsMap);
        if ($realized <= 0.0 && $backlog <= 0.0 && $targetPeriod <= 0.0) {
            continue;
        }

        if ($targetPeriod <= 0.0 && ($realized > 0.0 || $backlog > 0.0)) {
            $missingTargets++;
        }

        $forecasts = calculateForecastFlightPlan($realized, $backlog, $workdays, $today, $start, $end);
        $forecast = $forecasts['probable'];
        $line85 = Support::money($targetPeriod * 0.85);

        $vendors[] = [
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName,
            'target_month' => $targetMonth,
            'target_period' => $targetPeriod,
            'realized_period' => $realized,
            'attainment_pct' => $targetPeriod > 0 ? round(($realized / $targetPeriod) * 100, 2) : 0.0,
            'forecast_period' => $forecast,
            'forecasts' => $forecasts,
            'gap_to_target' => Support::money(max($targetPeriod - $realized, 0)),
            'line_85_value' => $line85,
            'line_85_gap' => Support::money(max($line85 - $realized, 0)),
            'new_daily_target' => calculateNewDailyTargetFlightPlan($realized, $targetPeriod, $workdays),
            'risk_status' => riskStatusFlightPlan($forecast, $targetPeriod, $line85),
            'orders' => $orders,
            'share' => 0.0,
            'ticket_avg' => $orders > 0 ? Support::money($realized / $orders) : 0.0,
            'backlog_value' => $backlog,
        ];

        $teamRealized += $realized;
        $teamBacklog += $backlog;
        $teamTarget += $targetPeriod;
    }

    usort($vendors, static function (array $left, array $right): int {
        $realizedCompare = $right['realized_period'] <=> $left['realized_period'];
        if ($realizedCompare !== 0) {
            return $realizedCompare;
        }

        return $right['target_period'] <=> $left['target_period'];
    });

    $teamRealized = Support::money($teamRealized);
    $teamBacklog = Support::money($teamBacklog);
    $teamTarget = Support::money($teamTarget);

    foreach ($vendors as $index => $vendor) {
        $vendors[$index]['share'] = $teamRealized > 0
            ? round(((float) $vendor['realized_period'] / $teamRealized) * 100, 2)
            : 0.0;
    }

    $teamForecasts = calculateForecastFlightPlan($teamRealized, $teamBacklog, $workdays, $today, $start, $end);
    $teamForecast = $teamForecasts['probable'];
    $teamLine85 = Support::money($teamTarget * 0.85);

    $dailySalesMap = [];
    foreach (Support::eachDate($start, $end) as $date) {
        $dailySalesMap[$date] = ['date' => $date, 'gross_revenue' => 0.0, 'orders' => 0];
    }

    foreach ($dailyRows as $row) {
        if ((string) ($row['status_comercial'] ?? '') !== 'faturado') {
            continue;
        }

        $date = (string) ($row['dia'] ?? '');
        if ($date === '' || !isset($dailySalesMap[$date])) {
            continue;
        }

        $dailySalesMap[$date]['gross_revenue'] += Support::toFloat($row['total'] ?? 0);
        $dailySalesMap[$date]['orders'] += (int) ($row['pedidos'] ?? 0);
    }

    $dailySales = array_values(array_map(static function (array $row): array {
        $row['gross_revenue'] = Support::money((float) $row['gross_revenue']);
        return $row;
    }, $dailySalesMap));

    $alerts = [];
    if ($teamTarget <= 0.0) {
        $alerts[] = [
            'level' => 'warning',
            'title' => 'Sem metas cadastradas',
            'message' => 'Cadastre as metas mensais para habilitar projecoes e risco.',
        ];
    }
    if ($missingTargets > 0) {
        $alerts[] = [
            'level' => 'warning',
            'title' => 'Vendedores sem meta',
            'message' => $missingTargets . ' vendedor(es) possuem realizado ou backlog, mas ainda nao tem meta no mes.',
        ];
    }
    if ($teamTarget > 0.0 && $teamForecast < $teamLine85) {
        $alerts[] = [
            'level' => 'danger',
            'title' => 'Meta da equipe em risco',
            'message' => 'A projecao provavel esta abaixo da linha de 85% da meta.',
        ];
    }

    return [
        'ok' => true,
        'mode' => 'canonical',
        'source' => 'canonical',
        'period' => [
            'start' => $start,
            'end' => $end,
            'label' => formatFlightPlanPeriodLabel($start, $end),
        ],
        'team' => [
            'currency' => 'BRL',
            'target_period' => $teamTarget,
            'realized_period' => $teamRealized,
            'attainment_pct' => $teamTarget > 0 ? round(($teamRealized / $teamTarget) * 100, 2) : 0.0,
            'forecast_period' => $teamForecast,
            'forecasts' => $teamForecasts,
            'gap_to_target' => Support::money(max($teamTarget - $teamRealized, 0)),
            'line_85_value' => $teamLine85,
            'line_85_gap' => Support::money(max($teamLine85 - $teamRealized, 0)),
            'workdays' => $workdays,
            'new_daily_target' => calculateNewDailyTargetFlightPlan($teamRealized, $teamTarget, $workdays),
            'risk_status' => riskStatusFlightPlan($teamForecast, $teamTarget, $teamLine85),
        ],
        'vendors' => $vendors,
        'daily_sales' => $dailySales,
        'alerts' => $alerts,
        'meta' => [
            'generated_at' => $lastSync,
            'last_sync' => $lastSync,
            'period_basis' => 'issue_date',
            'realized_basis' => 'faturado_por_emissao',
            'backlog_basis' => 'autorizado_por_emissao',
            'request_id' => RequestContext::id(),
            'targets' => [
                'source' => ($targetsRepository !== null && $targetsRepository->isAvailable()) ? 'mysql' : 'unconfigured',
                'last_updated_at' => $targetsRepository?->getLastUpdatedAt(),
                'missing_vendors_count' => $missingTargets,
                'months_loaded' => $months,
            ],
            'fallback_available' => true,
        ],
    ];
}

/**
 * Returns true only when the payload contains operational evidence that the canonical
 * database actually has commercial data for the requested period.
 *
 * Intentionally EXCLUDES targets and vendor-roster size as signal:
 * - targets are configuration, not operational data
 * - a vendor list with only meta-rows and zero realized/pipeline/cancelled is empty
 *
 * Without operational evidence the legacy live-API path must be used so the front-end
 * never renders a "healthy-looking" screen backed by an empty canonical DB.
 *
 * @param array<string,mixed> $payload
 */
function canonicalFlightPlanPayloadHasSignal(array $payload): bool
{
    $team = is_array($payload['team'] ?? null) ? $payload['team'] : [];

    // Operational evidence: faturadas, pipeline or cancellations
    if (Support::toFloat($team['realized_period'] ?? 0) > 0) {
        return true;
    }
    if (Support::toFloat($team['pipeline_value'] ?? $team['backlog_value'] ?? 0) > 0) {
        return true;
    }
    if (Support::toFloat($team['cancelled_value'] ?? 0) > 0) {
        return true;
    }

    // Check individual vendors — any vendor with actual transactions counts
    if (is_array($payload['vendors'] ?? null)) {
        foreach ($payload['vendors'] as $vendor) {
            if (!is_array($vendor)) {
                continue;
            }
            if (
                Support::toFloat($vendor['realized_period'] ?? 0) > 0
                || Support::toFloat($vendor['pipeline_value'] ?? $vendor['backlog_value'] ?? 0) > 0
                || Support::toFloat($vendor['cancelled_value'] ?? 0) > 0
            ) {
                return true;
            }
        }
    }

    // Targets alone or a non-empty vendor roster with all-zero data are NOT signal
    return false;
}

/**
 * @return list<string>
 */
function monthsInRangeFlightPlan(string $start, string $end): array
{
    $months = [];
    $cursor = new DateTimeImmutable(substr($start, 0, 7) . '-01');
    $last = new DateTimeImmutable(substr($end, 0, 7) . '-01');

    while ($cursor <= $last) {
        $months[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }

    return $months;
}

function resolveVendorTargetForPeriodFlightPlan(
    string $vendorId,
    string $start,
    string $end,
    array $months,
    array $targetsMap,
    float $saturdayWeight
): float {
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
        if ($monthTarget <= 0.0) {
            continue;
        }

        [$overlapStart, $overlapEnd] = monthOverlapFlightPlan($month, $start, $end);
        if ($overlapStart === null || $overlapEnd === null) {
            continue;
        }

        $monthWeight = WorkingDays::monthTotalWeight($month, $saturdayWeight);
        $overlapWeight = WorkingDays::weightedBetween($overlapStart, $overlapEnd, $saturdayWeight);
        if ($monthWeight > 0.0) {
            $target += $monthTarget * ($overlapWeight / $monthWeight);
        }
    }

    return Support::money($target);
}

function resolveVendorFullMonthTargetFlightPlan(string $vendorId, array $months, array $targetsMap): float
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
 * @return array{0:?string,1:?string}
 */
function monthOverlapFlightPlan(string $yearMonth, string $start, string $end): array
{
    $monthStart = new DateTimeImmutable($yearMonth . '-01');
    $monthEnd = $monthStart->modify('last day of this month');
    $rangeStart = new DateTimeImmutable($start);
    $rangeEnd = new DateTimeImmutable($end);

    $overlapStart = $monthStart > $rangeStart ? $monthStart : $rangeStart;
    $overlapEnd = $monthEnd < $rangeEnd ? $monthEnd : $rangeEnd;
    if ($overlapEnd < $overlapStart) {
        return [null, null];
    }

    return [$overlapStart->format('Y-m-d'), $overlapEnd->format('Y-m-d')];
}

/**
 * @return array{base:float,probable:float,commit:float}
 */
function calculateForecastFlightPlan(
    float $realized,
    float $backlog,
    array $workdays,
    DateTimeImmutable $today,
    string $start,
    string $end
): array {
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    $empty = ['base' => 0.0, 'probable' => 0.0, 'commit' => 0.0];

    if ($today < $startDate) {
        return $empty;
    }

    if ($today > $endDate) {
        $realized = Support::money($realized);
        return ['base' => $realized, 'probable' => $realized, 'commit' => $realized];
    }

    $elapsed = (float) ($workdays['elapsed_weight'] ?? 0.0);
    $total = (float) ($workdays['total_weight'] ?? 0.0);
    if ($elapsed <= 0.0 || $total <= 0.0) {
        return $empty;
    }

    $base = Support::money(($realized / $elapsed) * $total);
    $probable = Support::money($base + ($backlog * 0.8));
    $commit = Support::money($base + ($backlog * 0.4));

    return [
        'base' => $base,
        'probable' => $probable,
        'commit' => $commit,
    ];
}

function calculateNewDailyTargetFlightPlan(float $realized, float $target, array $workdays): float
{
    $remainingWeight = (float) ($workdays['remaining_weight'] ?? 0.0);
    if ($target <= 0.0 || $remainingWeight <= 0.0) {
        return 0.0;
    }

    return Support::money(max($target - $realized, 0.0) / $remainingWeight);
}

function riskStatusFlightPlan(float $forecast, float $target, float $line85): string
{
    if ($target <= 0.0) {
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

function vendorKeyFlightPlan(string $vendorId, string $vendorName): string
{
    return $vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName);
}

function formatFlightPlanPeriodLabel(string $start, string $end): string
{
    return date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end));
}
