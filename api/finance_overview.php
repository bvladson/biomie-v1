<?php

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\DatasetFreshnessService;
use BIomie\FinanceShadowCandidateBuilder;
use BIomie\Http;
use BIomie\LegacyEndpointPayloadFactory;
use BIomie\ShadowValidationService;

use BIomie\Auth;

require __DIR__ . '/bootstrap_finance.php';

Auth::requireCapability('finance.view', true);

// Permite execucao fluida para buscas de grandes trimestres.
set_time_limit(300);

try {
    $freshness = DatasetFreshnessService::boot($config, $logger);
    $shadow    = ShadowValidationService::boot($config, $logger);
    $range     = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $start = $range['start'];
    $end   = $range['end'];

    // ── FIN.R2: canonical emergency fallback quando Omie não configurado ───
    if ($finance === null) {
        $conn2 = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (Database::isConfigured($config, $conn2)) {
            try {
                $pdo2 = Database::connect($config, $conn2);
                $can2 = new CanonicalRepository($pdo2, $logger);
                if ($can2->isAvailable()) {
                    $bld2     = new FinanceShadowCandidateBuilder($can2);
                    $fallback = $bld2->buildOverview($start, $end);
                    $fallback['mode']                = 'canonical_local';
                    $fallback['source']              = 'canonical_local';
                    $fallback['local_data_notice']   = 'Dados locais — última sincronização disponível';
                    $fallback['meta']['served_from'] = 'canonical-local-fallback';
                    Http::json($freshness->attach('finance_overview', $fallback, ['observe_request' => true]));
                }
            } catch (Throwable $e2) {
                $logger->warning('finance_overview: canonical emergency fallback falhou.', ['message' => $e2->getMessage()]);
            }
        }
        Http::json(['ok' => false, 'message' => 'Módulo financeiro indisponível. Dados locais não encontrados.'], 503);
    }

    // ── Canonical: serve quando flag ativa e camada disponível ─────────────
    if ($config->isCanonicalEnabled('finance_overview') && (bool) $config->get('features.canonical_fiscal_ready', false)) {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (Database::isConfigured($config, $connection)) {
            try {
                $pdo = Database::connect($config, $connection);
                $canonical = new CanonicalRepository($pdo, $logger);
                if ($canonical->isAvailable()) {
                    $builder = new FinanceShadowCandidateBuilder($canonical);
                    $canonicalPayload = $builder->buildOverview($start, $end);
                    $canonicalPayload['mode'] = 'canonical';
                    $canonicalPayload['source'] = 'canonical';
                    $canonicalPayload['meta']['served_from'] = 'canonical-db';
                    $canonicalPayload = $freshness->attach('finance_overview', $canonicalPayload, ['observe_request' => true]);

                    $shadow->defer(
                        'finance_overview',
                        static function () use ($finance, $freshness, $start, $end): ?array {
                            try {
                                $snapshot = $finance->getSnapshot($start, $end, false);
                                return $freshness->attach('finance_overview', LegacyEndpointPayloadFactory::financeOverview($snapshot, $freshness));
                            } catch (Throwable) {
                                return null;
                            }
                        },
                        static fn (): ?array => $canonicalPayload,
                        ['start' => $start, 'end' => $end, 'official_mode' => 'canonical']
                    );

                    Http::json($canonicalPayload);
                }
            } catch (Throwable $e) {
                $logger->error('Finance overview: canonical falhou; usando legado de emergencia.', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    // ── Legado (emergencia ou flag desativada) ──────────────────────────────
    $snapshot = $finance->getSnapshot($start, $end, Http::boolQuery('force', false));
    $legacyPayload = $freshness->attach('finance_overview', LegacyEndpointPayloadFactory::financeOverview($snapshot, $freshness), ['observe_request' => true]);

    $shadow->defer(
        'finance_overview',
        static fn (): ?array => $legacyPayload,
        static function () use ($config, $logger, $freshness, $start, $end): ?array {
            return buildCanonicalFinanceOverviewShadowPayload($config, $logger, $freshness, $start, $end);
        },
        ['start' => $start, 'end' => $end, 'official_mode' => 'legacy']
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
