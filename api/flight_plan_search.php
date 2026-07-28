<?php

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\FlightPlanV2Service;
use BIomie\Http;
use BIomie\Logger;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\TargetsRepository;

require __DIR__ . '/bootstrap.php';
/** @var Config $config */
/** @var Logger $logger */
\BIomie\Auth::requireCapability('sales.view', true);

set_time_limit(60);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    $start = $range['start'];
    $end = $range['end'];
    $query = FilterNormalizer::text((string) (Http::query('q') ?? Http::query('vendedor') ?? ''), 100);
    $vendorId = FilterNormalizer::text((string) (Http::query('vendedor_id') ?? ''), 60);
    $status = FilterNormalizer::text((string) (Http::query('status_meta') ?? ''), 30);
    $channel = FilterNormalizer::text((string) (Http::query('channel') ?? ''), 30);
    $sort = FilterNormalizer::text((string) (Http::query('sort') ?? 'percentual_meta'), 40);
    $direction = FilterNormalizer::direction((string) (Http::query('direction') ?? 'desc'));
    $page = max(1, (int) (Http::query('page') ?? 1));
    $perPage = max(1, min(100, (int) (Http::query('per_page') ?? 25)));

    $allowedStatuses = ['acima_meta', 'abaixo_meta', 'sem_venda', 'com_venda', 'sem_meta', ''];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = '';
    }

    $allowedChannels = ['varejo', 'revenda', 'representante', 'outro', ''];
    if (!in_array($channel, $allowedChannels, true)) {
        $channel = '';
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger);
    if (!$canonical->isAvailable()) {
        throw new RuntimeException('Base comercial indisponível.');
    }

    $targets = new TargetsRepository($pdo, $logger);
    $service = new FlightPlanV2Service(
        $canonical,
        $targets,
        (float) $config->get('flight_plan.saturday_weight', 0.33)
    );

    $t0 = microtime(true);
    $payload = $service->build($start, $end, null);
    $vendors = array_values(array_filter(
        is_array($payload['vendors'] ?? null) ? $payload['vendors'] : [],
        static function (array $vendor) use ($query, $vendorId, $status, $channel): bool {
            $currentId = trim((string) ($vendor['vendor_id'] ?? ''));
            $name = trim((string) ($vendor['vendor_name'] ?? ''));
            $realized = (float) ($vendor['realized_period'] ?? 0);
            $target = (float) ($vendor['target_period'] ?? 0);
            $attainment = (float) ($vendor['attainment_pct'] ?? 0);
            $risk = (string) ($vendor['risk'] ?? '');

            if ($query !== '' && mb_stripos($name . ' ' . $currentId, $query) === false) {
                return false;
            }
            if ($vendorId !== '' && $currentId !== $vendorId) {
                return false;
            }
            if ($channel !== '' && (string) ($vendor['vendor_channel'] ?? 'outro') !== $channel) {
                return false;
            }

            return match ($status) {
                'acima_meta' => $target > 0 && $attainment >= 100,
                'abaixo_meta' => $target > 0 && $realized > 0 && $attainment < 100,
                'sem_venda' => $risk === 'no_sales',
                'com_venda' => $realized > 0,
                'sem_meta' => $target <= 0,
                default => true,
            };
        }
    ));

    $sortFields = [
        'vendedor' => 'vendor_name',
        'realizado' => 'realized_period',
        'meta' => 'target_period',
        'gap' => 'gap_to_target',
        'percentual_meta' => 'attainment_pct',
        'nfs' => 'notes_count',
        'pedidos' => 'pipeline_orders_count',
        'clientes' => 'customers_count',
        'ticket_medio' => 'ticket_avg',
        'previsao' => 'forecast_period',
        'risco' => 'risk_sort',
    ];
    $sortField = $sortFields[$sort] ?? 'attainment_pct';
    usort($vendors, static function (array $left, array $right) use ($sortField, $direction): int {
        $a = $left[$sortField] ?? 0;
        $b = $right[$sortField] ?? 0;
        $result = is_string($a) || is_string($b)
            ? strnatcasecmp((string) $a, (string) $b)
            : ((float) $a <=> (float) $b);
        return $direction === 'asc' ? $result : -$result;
    });

    $summary = summarizeFlightPlanRows($vendors);
    $pagination = new Pagination(count($vendors), $page, $perPage);
    $rows = array_slice($vendors, $pagination->offset, $pagination->perPage);

    Http::json([
        'ok' => true,
        'data' => $rows,
        'chart_data' => array_slice($vendors, 0, 20),
        'summary' => $summary,
        'pagination' => $pagination->toArray(),
        'filters' => [
            'q' => $query,
            'vendedor_id' => $vendorId,
            'status_meta' => $status,
            'channel' => $channel,
            'start' => $start,
            'end' => $end,
        ],
        'sort' => ['field' => $sort, 'direction' => $direction],
        'diagnostics' => [
            'query_ms' => round((microtime(true) - $t0) * 1000, 1),
            'source' => 'flight_plan_v2',
            'contract' => 'same_as_primary_view',
            'connection' => $connection,
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    Http::json([
        'ok' => false,
        'error' => ['code' => 'FLIGHT_PLAN_SEARCH_FAILED', 'message' => $exception->getMessage()],
    ], 400);
} catch (Throwable $exception) {
    $logger->warning('flight_plan_search.php failed', ['message' => $exception->getMessage()]);
    Http::json([
        'ok' => false,
        'error' => [
            'code' => 'FLIGHT_PLAN_SEARCH_FAILED',
            'message' => 'Não foi possível atualizar o Plano de Voo agora.',
        ],
    ], 500);
}

/**
 * @param list<array<string,mixed>> $vendors
 * @return array<string,int|float>
 */
function summarizeFlightPlanRows(array $vendors): array
{
    $summary = [
        'meta_total' => 0.0,
        'realizado_total' => 0.0,
        'gap_total' => 0.0,
        'previsao_total' => 0.0,
        'carteira_total' => 0.0,
        'nfs_total' => 0,
        'pedidos_carteira_total' => 0,
        'vendedores_acima_meta' => 0,
        'vendedores_abaixo_meta' => 0,
        'vendedores_sem_venda' => 0,
        'vendedores_sem_meta' => 0,
    ];

    foreach ($vendors as $vendor) {
        $target = (float) ($vendor['target_period'] ?? 0);
        $realized = (float) ($vendor['realized_period'] ?? 0);
        $attainment = (float) ($vendor['attainment_pct'] ?? 0);
        $summary['meta_total'] += $target;
        $summary['realizado_total'] += $realized;
        $summary['gap_total'] += (float) ($vendor['gap_to_target'] ?? 0);
        $summary['previsao_total'] += (float) ($vendor['forecast_period'] ?? 0);
        $summary['carteira_total'] += (float) ($vendor['pipeline_value'] ?? 0);
        $summary['nfs_total'] += (int) ($vendor['notes_count'] ?? 0);
        $summary['pedidos_carteira_total'] += (int) ($vendor['pipeline_orders_count'] ?? 0);
        $summary['vendedores_acima_meta'] += $target > 0 && $attainment >= 100 ? 1 : 0;
        $summary['vendedores_abaixo_meta'] += $target > 0 && $realized > 0 && $attainment < 100 ? 1 : 0;
        $summary['vendedores_sem_venda'] += (string) ($vendor['risk'] ?? '') === 'no_sales' ? 1 : 0;
        $summary['vendedores_sem_meta'] += $target <= 0 ? 1 : 0;
    }

    $summary['percentual_meta'] = $summary['meta_total'] > 0
        ? round($summary['realizado_total'] / $summary['meta_total'] * 100, 2)
        : 0.0;

    foreach (['meta_total', 'realizado_total', 'gap_total', 'previsao_total', 'carteira_total'] as $field) {
        $summary[$field] = round((float) $summary[$field], 2);
    }

    return $summary;
}
