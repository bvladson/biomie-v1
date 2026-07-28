<?php

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\FinanceAnalyticsService;
use BIomie\Http;
use BIomie\Modules\Finance\Application\CommercialHealthSourceResolver;
use BIomie\Modules\Finance\Application\FinanceOverviewBuilder;
use BIomie\Modules\Finance\Infrastructure\FinanceReadRepository;
use BIomie\Support;
use BIomie\Support\GlobalHomeDataGuard;

$financeBootstrapCapabilities = ['jobs.view', 'sales.view', 'finance.view'];
require __DIR__ . '/bootstrap_finance.php';
\BIomie\Auth::requireAnyCapability(['jobs.view', 'sales.view', 'finance.view'], true);

set_time_limit(90);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    Http::json([
        'ok' => false,
        'message' => 'Este endpoint e somente de leitura. Use api/omie_materialize_commercial.php para atualizar a visao comercial.',
    ], 405);
}

GlobalHomeDataGuard::requireUnrestricted();

try {
    $range = Http::resolveDateRangeOrCompetencia();
    Http::validateDateRange($range['start'], $range['end']);

    $start = $range['start'];
    $end = $range['end'];
    $force = Http::boolQuery('force', false);

    $billed = [
        'invoice_total' => 0.0,
        'merchandise_total' => 0.0,
        'notes_count' => 0,
    ];

    $canonicalAvailable = false;
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $remittanceRow = null;
    if (Database::isConfigured($config, 'flight_plan_db') || Database::isConfigured($config, 'db')) {
        try {
            $pdo = Database::connect($config, $connection);
            $canonical = new CanonicalRepository($pdo, $logger);
            if ($canonical->isAvailable()) {
                $canonicalAvailable = true;
                $billed = $canonical->summarizeInvoicedCommercial($start, $end);
                $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
                $stmtRem = $pdo->prepare(
                    "SELECT COUNT(*) AS nf_count, COALESCE(SUM(valor_total),0) AS total_value
                     FROM biomie_notas_fiscais_canonicas
                     WHERE status_fiscal = 'autorizada'
                       AND {$tenantFilter}
                       AND operation_scope = 'remittance'
                       AND COALESCE(data_competencia, data_emissao) BETWEEN ? AND ?"
                );
                $stmtRem->execute([$start, $end]);
                $remittanceRow = $stmtRem->fetch(\PDO::FETCH_ASSOC);
            }
        } catch (Throwable $exception) {
            $logger->warning('Commercial health sem leitura canonical.', [
                'connection' => $connection,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    $canonicalPayload = null;
    $canonicalReason = 'canonical_finance_unavailable';
    if (Database::isConfigured($config, $connection)) {
        try {
            $pdo = $pdo ?? Database::connect($config, $connection);
            $financeRepo = new FinanceReadRepository(static fn (): \PDO => $pdo);
            $financeSummary = (new FinanceOverviewBuilder($financeRepo))->build(
                $start,
                $end,
                null,
                Support::toFloat($billed['invoice_total'] ?? 0),
                '',
            );
            $canonicalReason = (string) ($financeSummary['reason_code'] ?? '');

            if (CommercialHealthSourceResolver::canonicalIsAuthoritative($financeSummary)) {
                $canonicalPayload = commercialHealthCanonicalPayload(
                    $financeSummary,
                    $billed,
                    $remittanceRow,
                    $range,
                    $canonicalAvailable,
                    $connection,
                );
            }
        } catch (\Throwable $canonicalException) {
            $canonicalReason = 'canonical_query_failed';
            $logger->warning('Commercial health: canonical finance read failed.', [
                'message' => $canonicalException->getMessage(),
            ]);
        }
    }

    // A dashboard nunca inicia uma chamada ao ERP por conta propria. Um
    // snapshot live anterior pode servir de fallback por ate a mesma janela
    // stale-while-revalidate do FinanceAnalyticsService.
    $cachedPayload = null;
    if (
        isset($finance)
        && $finance !== null
        && isset($cache, $config)
        && $cache instanceof \BIomie\Cache
        && $config instanceof \BIomie\Config
    ) {
        $cacheKey = FinanceAnalyticsService::cacheKey($start, $end);
        $cacheMeta = $cache->getMeta($cacheKey);
        $ttlMinutes = (int) $config->get(
            'finance.cache_ttl_minutes',
            $config->get('analytics.cache_ttl_minutes', 60),
        );
        $staleTtlSeconds = max($ttlMinutes * 24, 720) * 60;
        if ((int) ($cacheMeta['age_seconds'] ?? PHP_INT_MAX) <= $staleTtlSeconds) {
            $cachedSnapshot = $cache->read($cacheKey);
            if (is_array($cachedSnapshot)) {
                $cachedPayload = commercialHealthLivePayload(
                    $cachedSnapshot,
                    $billed,
                    $remittanceRow,
                    $range,
                    $canonicalAvailable,
                    $connection,
                    'finance-cache-fallback',
                );
            }
        }
    }

    $unavailablePayload = [
        'ok' => true,
        'available' => false,
        'source' => 'finance-unavailable',
        'period' => ['start' => $start, 'end' => $end, 'label' => $range['label']],
        'summary' => commercialHealthBilledSummary($billed),
        'message' => 'Dados financeiros canonicos ainda nao estao disponiveis para este periodo.',
        'reason_code' => $canonicalReason !== '' ? $canonicalReason : 'canonical_finance_unavailable',
        'remittance_summary' => commercialHealthRemittanceSummary($remittanceRow),
        'meta' => [
            'canonical_available' => $canonicalAvailable,
            'canonical_connection' => $connection,
            'finance_source' => 'unavailable',
        ],
    ];

    $resolver = new CommercialHealthSourceResolver();
    $payload = $resolver->resolve(
        $force,
        $canonicalPayload,
        $cachedPayload,
        $unavailablePayload,
        $finance !== null
            ? static function () use (
                $finance,
                $start,
                $end,
                $billed,
                $remittanceRow,
                $range,
                $canonicalAvailable,
                $connection,
            ): array {
                $snapshot = $finance->getSnapshot($start, $end, true);
                $servedFrom = (string) ($snapshot['meta']['served_from'] ?? 'manual-refresh');
                $source = in_array($servedFrom, ['cache-stale', 'stale-if-error'], true)
                    ? 'finance-cache-fallback'
                    : 'finance-live-widget';

                return commercialHealthLivePayload(
                    $snapshot,
                    $billed,
                    $remittanceRow,
                    $range,
                    $canonicalAvailable,
                    $connection,
                    $source,
                );
            }
            : null,
    );

    Http::json($payload);
} catch (InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->warning('Widget commercial_health indisponivel.', ['message' => $exception->getMessage()]);
    Http::json([
        'ok' => true,
        'available' => false,
        'source' => 'finance-error',
        'period' => ['start' => $start ?? null, 'end' => $end ?? null, 'label' => $range['label'] ?? null],
        'summary' => [],
        'message' => 'Nao foi possivel enriquecer o painel comercial com dados de recebimento agora.',
        'meta' => ['error' => $exception->getMessage()],
    ]);
}

/**
 * @param array<string,mixed> $financeSummary
 * @param array<string,mixed> $billed
 * @param array<string,mixed>|null $remittanceRow
 * @param array{start:string,end:string,label:string,competencia?:?string} $range
 *
 * @return array<string,mixed>
 */
function commercialHealthCanonicalPayload(
    array $financeSummary,
    array $billed,
    ?array $remittanceRow,
    array $range,
    bool $canonicalAvailable,
    string $connection,
): array {
    $payload = FinanceOverviewBuilder::toHealthFormat($financeSummary);
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $open = Support::toFloat($summary['open'] ?? 0);
    $overdue = Support::toFloat($summary['overdue'] ?? 0);
    $received = Support::toFloat($summary['received'] ?? 0);
    $portfolioExpected = $received + $open;

    $payload['summary'] = array_merge(commercialHealthBilledSummary($billed), $summary, [
        'delinquency_rate' => $open > 0 ? round(($overdue / $open) * 100, 2) : 0.0,
        'realized_vs_planned' => $portfolioExpected > 0
            ? round(($received / $portfolioExpected) * 100, 2)
            : 0.0,
        'overdue_exposure_pct' => $open > 0 ? round(($overdue / $open) * 100, 2) : 0.0,
    ]);
    $payload['period'] = [
        'start' => $range['start'],
        'end' => $range['end'],
        'label' => $range['label'],
    ];
    $payload['payment_methods'] = [];
    $payload['remittance_summary'] = commercialHealthRemittanceSummary($remittanceRow);
    $payload['reason_code'] = $financeSummary['reason_code'] ?? null;
    if (($payload['available'] ?? false) !== true) {
        $payload['message'] = 'Nenhum titulo financeiro encontrado no periodo selecionado.';
    }
    $payload['meta'] = [
        'canonical_available' => $canonicalAvailable,
        'canonical_connection' => $connection,
        'finance_source' => 'biomie_titulos_financeiros_canonicos',
        'finance_served_from' => 'canonical-db',
        'reason_code' => $financeSummary['reason_code'] ?? null,
        'diagnostics' => $financeSummary['diagnostics'] ?? null,
    ];

    return $payload;
}

/**
 * @param array<string,mixed> $snapshot
 * @param array<string,mixed> $billed
 * @param array<string,mixed>|null $remittanceRow
 * @param array{start:string,end:string,label:string,competencia?:?string} $range
 *
 * @return array<string,mixed>
 */
function commercialHealthLivePayload(
    array $snapshot,
    array $billed,
    ?array $remittanceRow,
    array $range,
    bool $canonicalAvailable,
    string $connection,
    string $source,
): array {
    $financeSummary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
    $received = Support::toFloat($financeSummary['received'] ?? 0);
    $open = Support::toFloat($financeSummary['open'] ?? 0);
    $overdue = Support::toFloat($financeSummary['overdue'] ?? 0);
    $invoiceTotal = Support::toFloat($billed['invoice_total'] ?? 0);
    $topOverdueClients = array_slice((array) ($snapshot['by_client'] ?? []), 0, 5);
    $paymentMethods = array_slice((array) ($snapshot['payment_methods'] ?? []), 0, 5);

    return [
        'ok' => true,
        'available' => true,
        'source' => $source,
        'period' => ['start' => $range['start'], 'end' => $range['end'], 'label' => $range['label']],
        'summary' => array_merge(commercialHealthBilledSummary($billed), [
            'received' => $received,
            'open' => $open,
            'overdue' => $overdue,
            'delinquency_rate' => Support::toFloat($financeSummary['delinquency_rate'] ?? 0),
            'realized_vs_planned' => Support::toFloat($financeSummary['realized_vs_planned'] ?? 0),
            'cash_conversion_pct' => $invoiceTotal > 0 ? round(($received / $invoiceTotal) * 100, 2) : 0.0,
            'overdue_exposure_pct' => $open > 0 ? round(($overdue / $open) * 100, 2) : 0.0,
            'received_titles' => (int) ($financeSummary['received_titles'] ?? 0),
            'open_titles' => (int) ($financeSummary['open_titles'] ?? 0),
            'overdue_titles' => (int) ($financeSummary['overdue_titles'] ?? 0),
        ]),
        'top_overdue_clients' => array_map(static fn (array $row): array => [
            'client_name' => (string) ($row['client_name'] ?? 'Cliente nao informado'),
            'client_document' => (string) ($row['client_document'] ?? ''),
            'overdue' => Support::money((float) ($row['overdue'] ?? 0)),
            'titles' => (int) ($row['titles'] ?? 0),
            'max_days_late' => (int) ($row['max_days_late'] ?? 0),
            'dominant_document_type' => (string) ($row['dominant_document_type'] ?? ''),
            'dominant_payment_method' => (string) ($row['dominant_payment_method'] ?? ''),
            'cause_label' => (string) ($row['cause_label'] ?? ''),
        ], $topOverdueClients),
        'payment_methods' => array_map(static fn (array $row): array => [
            'payment_method' => (string) ($row['payment_method'] ?? 'Nao identificado'),
            'received' => Support::money((float) ($row['received'] ?? 0)),
            'titles' => (int) ($row['titles'] ?? 0),
        ], $paymentMethods),
        'remittance_summary' => commercialHealthRemittanceSummary($remittanceRow),
        'meta' => [
            'canonical_available' => $canonicalAvailable,
            'canonical_connection' => $connection,
            'finance_generated_at' => (string) ($snapshot['meta']['generated_at'] ?? date(DATE_ATOM)),
            'finance_served_from' => (string) ($snapshot['meta']['served_from'] ?? 'live-build'),
            'request_id' => (string) ($snapshot['meta']['request_id'] ?? ''),
        ],
    ];
}

/**
 * @param array<string,mixed> $billed
 *
 * @return array{invoice_total:float,merchandise_total:float,notes_count:int}
 */
function commercialHealthBilledSummary(array $billed): array
{
    return [
        'invoice_total' => Support::money((float) ($billed['invoice_total'] ?? 0)),
        'merchandise_total' => Support::money((float) ($billed['merchandise_total'] ?? 0)),
        'notes_count' => (int) ($billed['notes_count'] ?? 0),
    ];
}

/**
 * @param array<string,mixed>|null $remittanceRow
 *
 * @return array{nf_count:int,total_value:float}|null
 */
function commercialHealthRemittanceSummary(?array $remittanceRow): ?array
{
    if ($remittanceRow === null || (int) ($remittanceRow['nf_count'] ?? 0) <= 0) {
        return null;
    }

    return [
        'nf_count' => (int) $remittanceRow['nf_count'],
        'total_value' => Support::money((float) ($remittanceRow['total_value'] ?? 0)),
    ];
}
