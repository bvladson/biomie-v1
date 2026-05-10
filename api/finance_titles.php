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
\BIomie\Auth::requireCapability('finance.view', true);

try {
    if ($finance === null) {
        Http::json(['ok' => false, 'message' => 'Credenciais Omie nao configuradas para o modulo financeiro.'], 500);
    }

    $freshness = DatasetFreshnessService::boot($config, $logger);
    $shadow = ShadowValidationService::boot($config, $logger);
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $snapshot = $finance->getSnapshot($range['start'], $range['end'], Http::boolQuery('force', false));

    $bucket = strtolower(trim((string) ($_GET['bucket'] ?? 'open')));
    $map = [
        'open' => 'open_titles',
        'overdue' => 'overdue_titles',
        'received' => 'received_rows',
    ];
    $key = $map[$bucket] ?? 'open_titles';

    $legacyPayload = $freshness->attach('finance_titles', LegacyEndpointPayloadFactory::financeTitles($snapshot, $freshness, $bucket), ['observe_request' => true]);

    $shadow->defer(
        'finance_titles',
        static fn (): ?array => $legacyPayload,
        static function () use ($config, $logger, $freshness, $range, $bucket): ?array {
            return buildCanonicalFinanceTitlesShadowPayload($config, $logger, $freshness, $range['start'], $range['end'], $bucket);
        },
        [
            'start' => $range['start'],
            'end' => $range['end'],
            'bucket' => $bucket,
            'official_mode' => 'legacy',
        ]
    );

    Http::json($legacyPayload);
} catch (\InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 500);
}

/**
 * @return array<string,mixed>|null
 */
function buildCanonicalFinanceTitlesShadowPayload(\BIomie\Config $config, \BIomie\Logger $logger, DatasetFreshnessService $freshness, string $start, string $end, string $bucket): ?array
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
        return $freshness->attach('finance_titles', $builder->buildTitles($start, $end, $bucket));
    } catch (Throwable) {
        return null;
    }
}
