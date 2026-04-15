<?php

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\DatasetFreshnessService;
use BIomie\FinanceShadowCandidateBuilder;
use BIomie\Http;
use BIomie\LegacyEndpointPayloadFactory;
use BIomie\ShadowValidationService;

require __DIR__ . '/bootstrap_finance.php';

// Permite execucao fluida para buscas de grandes trimestres.
set_time_limit(300);

try {
    if ($finance === null) {
        Http::json(['ok' => false, 'message' => 'Credenciais Omie nao configuradas para o modulo financeiro.'], 500);
    }

    $freshness = DatasetFreshnessService::boot($config, $logger);
    $shadow = ShadowValidationService::boot($config, $logger);
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $snapshot = $finance->getSnapshot($range['start'], $range['end'], Http::boolQuery('force', false));

    $legacyPayload = $freshness->attach('finance_overview', LegacyEndpointPayloadFactory::financeOverview($snapshot, $freshness), ['observe_request' => true]);

    $shadow->defer(
        'finance_overview',
        static fn (): ?array => $legacyPayload,
        static function () use ($config, $logger, $freshness, $range): ?array {
            return buildCanonicalFinanceOverviewShadowPayload($config, $logger, $freshness, $range['start'], $range['end']);
        },
        [
            'start' => $range['start'],
            'end' => $range['end'],
            'official_mode' => 'legacy',
        ]
    );

    Http::json($legacyPayload);
} catch (\InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->error('Falha em api/finance_overview.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    Http::json(['ok' => false, 'message' => 'Erro interno ao carregar o financeiro. Tente novamente.'], 500);
}

/**
 * @return array<string,mixed>|null
 */
function buildCanonicalFinanceOverviewShadowPayload(\BIomie\Config $config, \BIomie\Logger $logger, DatasetFreshnessService $freshness, string $start, string $end): ?array
{
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        return null;
    }

    try {
        $pdo = Database::connect($config, $connection);
        $canonical = new CanonicalRepository($pdo, $logger);
        if (!$canonical->isAvailable()) {
            return null;
        }

        $builder = new FinanceShadowCandidateBuilder($canonical);
        return $freshness->attach('finance_overview', $builder->buildOverview($start, $end));
    } catch (Throwable) {
        return null;
    }
}
