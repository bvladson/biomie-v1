<?php

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\CommercialPlanning;
use BIomie\Database;
use BIomie\FlightPlanService;
use BIomie\FlightPlanV2Service;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;
use BIomie\TargetsRepository;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireAnyCapability(['sales.sync', 'settings.manage'], true);

set_time_limit(180);

try {
    $yearMonth = trim((string) Http::query('year_month', date('Y-m')));
    $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
    if (!$monthDate instanceof DateTimeImmutable || $monthDate->format('Y-m') !== $yearMonth) {
        throw new InvalidArgumentException('Informe um mês de referência válido.');
    }

    $page = max(1, (int) Http::query('page', '1'));
    $perPage = max(1, min(300, (int) Http::query('per_page', '100')));
    $todayMonth = date('Y-m');
    $lockedMonth = $yearMonth < $todayMonth;
    $cacheKey = "flightplan:targets:editor:{$yearMonth}:p{$page}:{$perPage}";
    $cached = $lockedMonth ? $cache->get($cacheKey, 120) : null;
    if ($cached !== null) {
        Http::json($cached);
    }

    $start = $yearMonth . '-01';
    $end = date('Y-m-t', strtotime($start));
    $targetsConnection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

    $targetsRepository = null;
    $canonical = null;

    if (Database::isConfigured($config, $targetsConnection)) {
        try {
            $pdo = Database::connect($config, $targetsConnection);
            $targetsRepository = new TargetsRepository($pdo, $logger);

            $repo = new CanonicalRepository($pdo, $logger);
            if ($repo->isAvailable()) {
                $canonical = $repo;
            }
        } catch (Throwable $exception) {
            $logger->warning('Targets editor: sem conexao MySQL.', [
                'message' => $exception->getMessage(),
                'request_id' => RequestContext::id(),
            ]);
        }
    }

    if ($canonical !== null) {
        $serviceV2 = new FlightPlanV2Service($canonical, $targetsRepository, (float) $config->get('flight_plan.saturday_weight', 0.33));
        $response = $serviceV2->buildTargetsEditor($yearMonth, $page, $perPage);
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
        $totalRows = (int) ($response['meta']['total'] ?? count($rows));

        if ($totalRows > 0) {
            if (!isset($response['meta']) || !is_array($response['meta'])) {
                $response['meta'] = [];
            }
            $response['meta']['request_id'] = RequestContext::id();
            if ($lockedMonth) {
                $cache->put($cacheKey, $response);
            }
            Http::json($response);
        }

        $logger->warning('Targets editor: canonical vazio para o mes; tentando fallback.', [
            'year_month' => $yearMonth,
            'request_id' => RequestContext::id(),
        ]);
    }

    $vendors = [];
    $source = 'empty';

    try {
        $service = new FlightPlanService($config, $cache, $logger, $analytics, $targetsRepository);
        $payload = $service->build($start, $end, false);
        $vendors = is_array($payload['vendors'] ?? null) ? $payload['vendors'] : [];
        if ($vendors !== []) {
            $source = 'legacy_flight_plan';
        }
    } catch (Throwable $exception) {
        $logger->warning('Targets editor: build completo falhou.', [
            'message' => $exception->getMessage(),
            'request_id' => RequestContext::id(),
        ]);
    }

    if ($vendors === [] && $targetsRepository !== null && $targetsRepository->isAvailable()) {
        $locked = $yearMonth < $todayMonth;
        foreach ($targetsRepository->getTargetsForMonths([$yearMonth]) as $row) {
            $vendors[] = [
                'vendor_id' => $row['vendor_id'],
                'vendor_name' => $row['vendor_name'],
                'realized_period' => 0.0,
                'meta_day_base' => 0.0,
                'ideal_for_period' => 0.0,
                'forecast_period' => 0.0,
                'gap_to_target' => 0.0,
                'attainment_pct' => 0.0,
                'new_daily_target' => 0.0,
                'risk_status' => $row['target_amount'] > 0 ? 'ATENCAO' : 'SEM_META',
                'target_period' => Support::toFloat($row['target_amount']),
                'target_month' => Support::toFloat($row['target_amount']),
                'locked' => $locked,
                'editable' => !$locked,
            ];
        }

        if ($vendors !== []) {
            $source = 'targets_db';
        }
    }

    usort($vendors, static function (array $left, array $right): int {
        return strcmp(
            trim(mb_strtolower((string) ($left['vendor_name'] ?? ''))),
            trim(mb_strtolower((string) ($right['vendor_name'] ?? '')))
        );
    });

    $total = count($vendors);
    $offset = ($page - 1) * $perPage;
    $response = [
        'ok' => true,
        'data' => array_slice($vendors, $offset, $perPage),
        'message' => null,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'year_month' => $yearMonth,
            'source' => $source,
            'request_id' => RequestContext::id(),
        ],
    ];

    if ($lockedMonth) {
        $cache->put($cacheKey, $response);
    }
    Http::json($response);
} catch (InvalidArgumentException $exception) {
    Http::json([
        'ok' => false,
        'message' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ], 400);
} catch (Throwable $exception) {
    $logger->error('Falha no flight_plan_targets_editor.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Nao foi possivel carregar as metas do editor. Tente novamente.',
        'request_id' => RequestContext::id(),
    ], 500);
}

/**
 * @return array<string,mixed>
 */
function buildCanonicalTargetsEditorResponse(
    CanonicalRepository $canonical,
    ?TargetsRepository $targetsRepository,
    string $yearMonth,
    string $start,
    string $end,
    int $page,
    int $perPage,
    float $saturdayWeight = 0.33
): array {
    $targetsMap = ($targetsRepository !== null && $targetsRepository->isAvailable())
        ? $targetsRepository->getTargetsForMonths([$yearMonth])
        : [];
    $vendorRows = $canonical->aggregateByVendorCommercial($start, $end);
    $planning = CommercialPlanning::build($vendorRows, $targetsMap, $start, $end, $saturdayWeight);

    $vendorIndex = [];
    foreach ($canonical->getVendorMap() as $vendorId => $vendorRow) {
        $vendorName = trim((string) ($vendorRow['nome'] ?? ''));
        if ($vendorName === '') {
            continue;
        }

        $vendorIndex[(string) $vendorId] = [
            'vendor_id' => (string) $vendorId,
            'vendor_name' => $vendorName,
        ];
    }

    foreach ($targetsMap as $targetRow) {
        $vendorId = $targetRow['vendor_id'];
        $vendorName = trim($targetRow['vendor_name']);
        if ($vendorId === '' && $vendorName === '') {
            continue;
        }

        $vendorIndex[$vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName)] = [
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName !== '' ? $vendorName : 'Sem vendedor',
        ];
    }

    $planningByKey = [];
    foreach ($planning['vendors'] as $row) {
        $vendorId = (string) ($row['vendor_id'] ?? '');
        $vendorName = (string) ($row['vendor_name'] ?? 'Sem vendedor');
        $key = $vendorId !== '' ? $vendorId : 'name:' . mb_strtolower($vendorName);
        $planningByKey[$key] = $row;
        $vendorIndex[$key] = [
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName,
        ];
    }

    $rows = [];
    foreach ($vendorIndex as $key => $vendorBase) {
        $plan = $planningByKey[$key] ?? [];
        $targetValue = 0.0;
        foreach ($targetsMap as $targetRow) {
            $targetVendorId = $targetRow['vendor_id'];
            $targetVendorName = $targetRow['vendor_name'];
            $targetKey = $targetVendorId !== '' ? $targetVendorId : 'name:' . mb_strtolower($targetVendorName);
            if ($targetKey === $key) {
                $targetValue = Support::toFloat($targetRow['target_amount']);
                break;
            }
        }

        $rows[] = [
            'vendor_id' => (string) ($vendorBase['vendor_id'] ?? ''),
            'vendor_name' => (string) ($vendorBase['vendor_name'] ?? 'Sem vendedor'),
            'realized_period' => Support::money((float) ($plan['realized_period'] ?? 0)),
            'backlog_value' => Support::money((float) ($plan['backlog_value'] ?? 0)),
            'target_period' => Support::money((float) ($plan['target_period'] ?? $targetValue)),
            'target_month' => Support::money((float) ($plan['target_month'] ?? $targetValue)),
            'forecast_period' => Support::money((float) ($plan['forecast_period'] ?? 0)),
            'gap_to_target' => Support::money((float) ($plan['gap_to_target'] ?? max($targetValue - (float) ($plan['realized_period'] ?? 0), 0))),
            'attainment_pct' => Support::toFloat($plan['attainment_pct'] ?? 0),
            'new_daily_target' => Support::money((float) ($plan['new_daily_target'] ?? 0)),
            'risk_status' => (string) ($plan['risk_status'] ?? ($targetValue > 0 ? 'ATENCAO' : 'SEM_META')),
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        return strcmp(
            trim(mb_strtolower((string) ($left['vendor_name'] ?? ''))),
            trim(mb_strtolower((string) ($right['vendor_name'] ?? '')))
        );
    });

    $total = count($rows);
    $offset = ($page - 1) * $perPage;

    return [
        'ok' => true,
        'data' => array_slice($rows, $offset, $perPage),
        'message' => null,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'year_month' => $yearMonth,
            'source' => 'canonical_targets_merge',
            'period_basis'        => 'year_month',   // metas — competencia mensal
            'target_period_basis' => 'year_month',
            'request_id' => RequestContext::id(),
            'team_target' => Support::toFloat($planning['team']['target_period'] ?? 0),
            'team_realized' => Support::toFloat($planning['team']['realized_period'] ?? 0),
            'team_forecast' => Support::toFloat($planning['team']['forecast_period'] ?? 0),
        ],
    ];
}

/**
 * @param array<string,mixed> $response
 * @return array<string,mixed>
 */
function paginateTargetsEditorResponse(array $response, string $yearMonth, int $page, int $perPage): array
{
    $rows = is_array($response['data'] ?? null) ? array_values($response['data']) : [];
    $total = (int) (($response['meta']['total'] ?? null) ?: count($rows));
    $offset = max(0, ($page - 1) * $perPage);

    $response['data'] = array_slice($rows, $offset, $perPage);
    $response['meta'] = is_array($response['meta'] ?? null) ? $response['meta'] : [];
    $response['meta']['page'] = $page;
    $response['meta']['per_page'] = $perPage;
    $response['meta']['total'] = $total;
    $response['meta']['year_month'] = $yearMonth;
    $response['meta']['source'] = (string) ($response['meta']['source'] ?? 'historical_snapshot');

    return $response;
}
