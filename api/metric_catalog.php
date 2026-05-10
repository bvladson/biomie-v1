<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\DataPlatform\Semantic\MetricCatalogExporter;
use BIomie\DataPlatform\Semantic\MetricRegistry;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['settings.view', 'admin.view', 'logs.view'], true);

/**
 * Catálogo interno de métricas BIomie.
 *
 * Retorna todos os KPIs registrados no MetricRegistry com metadados completos:
 * domínio, status, source_of_truth, tabela, data oficial, permissão,
 * freshness_domain, dimensões, owner e observações.
 *
 * Permissão: settings.view | admin.view | logs.view
 * Método: GET
 * Parâmetros:
 *   domain     — filtra por domínio (sales|fiscal|finance|customers|geo|cnae|operations|shadow)
 *   status     — filtra por status (active|draft|deprecated)
 *   format     — json (padrão) ou summary
 */

try {
    $registry  = MetricRegistry::build();
    $exporter  = new MetricCatalogExporter($registry);

    $filterDomain = strtolower(trim((string) ($_GET['domain'] ?? '')));
    $filterStatus = strtolower(trim((string) ($_GET['status'] ?? '')));
    $format        = strtolower(trim((string) ($_GET['format'] ?? 'json')));

    $all = $registry->all();

    if ($filterDomain !== '') {
        $all = array_filter($all, fn ($d) => $d->domain === $filterDomain);
    }
    if ($filterStatus !== '') {
        $all = array_filter($all, fn ($d) => $d->status === $filterStatus);
    }

    // Group by domain for readability
    $byDomain = [];
    foreach ($all as $def) {
        $byDomain[$def->domain][] = $def->toArray();
    }
    ksort($byDomain);

    if ($format === 'summary') {
        $catalog = $exporter->toArray();
        Http::json([
            'ok'           => true,
            'generated_at' => $catalog['generated_at'],
            'totals'       => $catalog['totals'],
            'request_id'   => RequestContext::id(),
        ]);
    }

    Http::json([
        'ok'           => true,
        'generated_at' => date('c'),
        'totals'       => [
            'kpis'       => count($registry->all()),
            'active'     => count($registry->active()),
            'draft'      => count($registry->draft()),
            'deprecated' => count($registry->deprecated()),
        ],
        'kpis_by_domain' => $byDomain,
        'request_id'     => RequestContext::id(),
    ]);
} catch (Throwable $exception) {
    $logger->error('Falha ao carregar catálogo de métricas.', [
        'request_id' => RequestContext::id(),
        'exception'  => $exception::class,
        'message'    => $exception->getMessage(),
    ]);

    Http::json([
        'ok'         => false,
        'message'    => 'Não foi possível carregar o catálogo de métricas.',
        'request_id' => RequestContext::id(),
    ], 500);
}
