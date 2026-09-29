<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CanonicalRepository;
use BIomie\Commercial\CommercialFilterPreferenceStore;
use BIomie\Commercial\CommercialRequestScope;
use BIomie\Commercial\CommercialScopeDeniedException;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\Database;
use BIomie\FlightPlanV2Service;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;
use BIomie\TargetsRepository;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

$fpStartTime = microtime(true);

try {
    $range = Http::resolveDateRangeOrCompetencia();
    Http::validateDateRange($range['start'], $range['end']);

    $start          = $range['start'];
    $end            = $range['end'];
    $vendorId       = Http::query('vendor_id');
    $force          = Http::boolQuery('force', Http::boolQuery('force_refresh', false));
    $saturdayWeight = (float) $config->get('flight_plan.saturday_weight', 0.33);

    $targetsRepository = null;
    $canonical         = null;
    $pdo               = null;
    $targetsConnection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

    if (Database::isConfigured($config, $targetsConnection)) {
        try {
            $pdo               = Database::connect($config, $targetsConnection);
            $targetsRepository = new TargetsRepository($pdo, $logger);
            $repo              = new CanonicalRepository($pdo, $logger);
            if ($repo->isAvailable()) {
                $canonical = $repo;
            }
        } catch (Throwable $exception) {
            $logger->warning('Plano de Voo: falha ao conectar ao banco.', [
                'message'    => $exception->getMessage(),
                'connection' => $targetsConnection,
                'request_id' => RequestContext::id(),
            ]);
        }
    }

    if ($canonical === null) {
        Http::json([
            'ok'          => false,
            'error'       => 'flight_plan_error',
            'reason_code' => 'canonical_unavailable',
            'message'     => 'Base de dados canônica indisponível. Verifique a conexão e a sincronização Omie.',
            'source'      => 'flight_plan_v2',
            'request_id'  => RequestContext::id(),
            'diagnostics' => [
                'request_id' => RequestContext::id(),
                'runtime_ms' => (int) ((microtime(true) - $fpStartTime) * 1000),
            ],
        ], 503);
    }

    // ── Filtro Comercial Inteligente (canal) ─────────────────────────────────
    // O Plano de Voo cobre o universo do CONTEXTO (em "Todas as operações":
    // varejo + licitação). Ele não aplica o recorte de canal da preferência —
    // o vendedor é a unidade do plano —, e isso sai declarado em meta.
    // Pedido de canal fora do autorizado continua sendo 403.
    try {
        $flightScope = CommercialRequestScope::fromRequest($pdo, CommercialFilterPreferenceStore::PAGE_COMMERCIAL);
    } catch (CommercialScopeDeniedException $denied) {
        Http::json(['ok' => false, 'error' => 'channel_not_authorized',
            'message' => $denied->getMessage(), 'request_id' => RequestContext::id()], 403);
    }

    // Try snapshot for closed historical periods (no vendor filter, not forced)
    if ($pdo !== null && ($vendorId === null || $vendorId === '') && !$force) {
        try {
            $historical = new CommercialCompetenciaSnapshotService($pdo, $logger, $canonical, $targetsRepository, $saturdayWeight);
            $snapshotYm = $historical->shouldUseSnapshot($start, $end, $range['competencia']);
            if ($snapshotYm !== null) {
                $snap = $historical->loadPayload('flight_plan', $snapshotYm);
                if (is_array($snap) && canonicalFlightPlanPayloadHasSignal($snap)) {
                    $snap['source']      = 'snapshot';
                    $snap['diagnostics'] = [
                        'request_id'  => RequestContext::id(),
                        'runtime_ms'  => (int) ((microtime(true) - $fpStartTime) * 1000),
                        'served_from' => 'snapshot',
                    ];
                    if (!isset($snap['meta']) || !is_array($snap['meta'])) {
                        $snap['meta'] = [];
                    }
                    if (!isset($snap['meta']['targets']) || !is_array($snap['meta']['targets'])) {
                        $snap['meta']['targets'] = [];
                    }
                    $snap['meta']['targets']['connection'] = $targetsConnection;
                    Http::json($snap);
                }
            }
        } catch (Throwable $e) {
            $logger->warning('Plano de Voo: snapshot indisponível; usando leitura canônica ao vivo.', [
                'message'    => $e->getMessage(),
                'request_id' => RequestContext::id(),
            ]);
        }
    }

    // Build with FlightPlanV2Service — authoritative source, no legacy fallback
    $serviceV2 = new FlightPlanV2Service($canonical, $targetsRepository, $saturdayWeight);
    $payload   = $serviceV2->build($start, $end, $vendorId);

    // Annotate reason_code when no active targets exist for the period
    $hasTargets        = (float) ($payload['team']['target_period'] ?? 0) > 0;
    $vendorsWithTarget = count(array_filter(
        $payload['vendors'] ?? [],
        static fn (array $v): bool => (float) ($v['target_period'] ?? 0) > 0
    ));
    $vendorsCount = count($payload['vendors'] ?? []);

    if (!$hasTargets && $vendorsCount > 0) {
        $monthLabel             = date('n/Y', strtotime($start));
        $payload['reason_code'] = 'targets_missing';
        $payload['message']     = "Há atividade comercial em {$monthLabel}, mas as metas ainda não foram definidas.";
    } elseif (!$hasTargets) {
        $monthLabel             = date('n/Y', strtotime($start));
        $payload['reason_code'] = 'no_active_targets';
        $payload['message']     = "Nenhuma meta ativa para {$monthLabel}.";
    }

    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }
    if (!isset($payload['meta']['targets']) || !is_array($payload['meta']['targets'])) {
        $payload['meta']['targets'] = [];
    }
    $payload['meta']['targets']['connection'] = $targetsConnection;
    // Botão "Editar metas" só aparece para quem pode salvar (mesma regra do
    // upsert e da página de metas: sales.sync OU settings.manage).
    $payload['meta']['can_edit_targets'] = Auth::hasCapability('sales.sync')
        || Auth::hasCapability('settings.manage');
    $payload['meta']['business_scope'] = $flightScope->businessScope;
    $payload['meta']['channel_refine_applied'] = false;
    $payload['source']      = 'flight_plan_v2';
    $payload['diagnostics'] = [
        'request_id'            => RequestContext::id(),
        'runtime_ms'            => (int) ((microtime(true) - $fpStartTime) * 1000),
        'served_from'           => 'canonical_v2',
        'vendors_returned'      => $vendorsCount,
        'vendors_with_target'   => $vendorsWithTarget,
        'active_target_vendors' => $vendorsWithTarget,
        'fallback_used'         => false,
        'legacy_used'           => false,
    ];

    Http::json($payload);

} catch (InvalidArgumentException $exception) {
    Http::json([
        'ok'         => false,
        'message'    => $exception->getMessage(),
        'request_id' => RequestContext::id(),
    ], 400);
} catch (Throwable $exception) {
    $logger->error('Falha ao gerar Plano de Voo.', [
        'message'    => $exception->getMessage(),
        'trace'      => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);
    Http::json([
        'ok'          => false,
        'error'       => 'flight_plan_error',
        'reason_code' => 'service_exception',
        'message'     => 'Não foi possível montar o Plano de Voo.',
        'source'      => 'flight_plan_v2',
        'request_id'  => RequestContext::id(),
        'diagnostics' => [
            'request_id' => RequestContext::id(),
        ],
    ], 500);
}

/**
 * Returns true only when the payload contains operational evidence that the canonical
 * database has commercial data for the requested period.
 * Used to validate historical snapshots before serving them.
 *
 * @param array<string,mixed> $payload
 */
function canonicalFlightPlanPayloadHasSignal(array $payload): bool
{
    $team = is_array($payload['team'] ?? null) ? $payload['team'] : [];

    if (Support::toFloat($team['realized_period'] ?? 0) > 0) {
        return true;
    }
    if (Support::toFloat($team['pipeline_value'] ?? $team['backlog_value'] ?? 0) > 0) {
        return true;
    }
    if (Support::toFloat($team['cancelled_value'] ?? 0) > 0) {
        return true;
    }

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

    return false;
}
