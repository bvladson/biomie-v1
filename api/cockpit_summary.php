<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\Semantic\FreshnessService;
use BIomie\DataPlatform\Semantic\MetricRegistry;
use BIomie\DataPlatform\Semantic\SemanticMetricValidator;
use BIomie\Http;
use BIomie\Http\ErrorBoundary;
use BIomie\RequestContext;
use BIomie\SystemHealth\SystemHealthService;

ErrorBoundary::runApi(function () use ($config): void {
    Auth::requireAnyCapability([
        'sales.view',
        'finance.view',
        'fiscal.view',
        'geo.view',
        'admin.view',
        'logs.view',
    ], true);

    $warnings = [];
    $partial = false;
    $pdo = null;
    try {
        [$pdo] = Database::getGeoConnection($config);
    } catch (Throwable $e) {
        $partial = true;
        $warnings[] = [
            'component' => 'database',
            'message' => 'Banco indisponivel para alguns diagnosticos.',
        ];
    }

    $health = ['status' => 'unknown', 'checks' => []];
    try {
        $health = (new SystemHealthService($pdo))->run()->toArray();
    } catch (Throwable $e) {
        $partial = true;
        $warnings[] = ['component' => 'system_health', 'message' => 'Saude do sistema indisponivel.'];
    }

    $metricTotals = ['kpis' => 0, 'active' => 0, 'draft' => 0, 'deprecated' => 0];
    $metricCards = [];
    $semantic = ['audit_ok' => null, 'errors' => [], 'warnings' => []];
    try {
        $registry = MetricRegistry::build();
        $audit = (new SemanticMetricValidator())->validate($registry);
        $metricTotals = [
            'kpis' => count($registry->all()),
            'active' => count($registry->active()),
            'draft' => count($registry->draft()),
            'deprecated' => count($registry->deprecated()),
        ];
        foreach (array_slice($registry->all(), 0, 18) as $def) {
            $metricCards[] = $def->toArray();
        }
        $semantic = [
            'audit_ok' => $audit->ok,
            'errors' => $audit->errors,
            'warnings' => $audit->warnings,
        ];
    } catch (Throwable $e) {
        $partial = true;
        $warnings[] = ['component' => 'metric_catalog', 'message' => 'Catalogo de metricas indisponivel.'];
    }

    $freshness = ['available' => false, 'by_domain' => [], 'gold_job' => null];
    if ($pdo !== null) {
        try {
            $service = new FreshnessService($pdo);
            foreach (['sales', 'fiscal', 'finance', 'geo', 'cnae', 'operations', 'omie', 'gold'] as $domain) {
                $freshness['by_domain'][$domain] = $service->forDomain($domain);
            }
            $freshness['available'] = true;
            $freshness['gold_job'] = $freshness['by_domain']['gold'] ?? null;
        } catch (Throwable $e) {
            $partial = true;
            $warnings[] = ['component' => 'freshness', 'message' => 'Freshness indisponivel.'];
        }
    }

    $status = (string) ($health['status'] ?? 'unknown');
    if ($partial && $status === 'ok') {
        $status = 'warning';
    }

    Http::json([
        'ok' => true,
        'partial' => $partial,
        'status' => $status,
        'cards' => [
            ['id' => 'system', 'label' => 'Sistema', 'status' => $health['status'] ?? 'unknown'],
            ['id' => 'semantic', 'label' => 'Semantic Layer', 'status' => ($semantic['audit_ok'] ?? null) === true ? 'ok' : 'warning'],
            ['id' => 'metrics', 'label' => 'KPIs ativos', 'status' => ($metricTotals['active'] ?? 0) > 0 ? 'ok' : 'warning', 'value' => $metricTotals['active']],
            ['id' => 'drafts', 'label' => 'KPIs draft', 'status' => ($metricTotals['draft'] ?? 0) > 0 ? 'warning' : 'ok', 'value' => $metricTotals['draft']],
        ],
        'sections' => [
            'health' => $health,
            'metrics' => ['totals' => $metricTotals, 'items' => $metricCards],
            'semantic' => $semantic,
            'freshness' => $freshness,
        ],
        'warnings' => $warnings,
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'request_id' => RequestContext::id(),
        ],
    ]);
}, [
    'api' => 'cockpit_summary',
    'route' => 'api/cockpit_summary.php',
]);
