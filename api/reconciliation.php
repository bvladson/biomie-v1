<?php

declare(strict_types=1);

/**
 * Relatorio de reconciliacao - admin-only.
 *
 * Retorna totais canonicos por vendedor, no formato:
 * vendedor | faturado | autorizado | cancelado | total | ultima sync
 *
 * Esse relatorio serve para conferir BIomie vs planilha vs Omie.
 */

use BIomie\Auth;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\DatasetFreshnessService;
use BIomie\Http;
use BIomie\LegacyEndpointPayloadFactory;
use BIomie\ShadowValidationService;
use BIomie\Support;

require __DIR__ . '/bootstrap.php';

Auth::requireAdmin(true);

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $freshness = DatasetFreshnessService::boot($config, $logger);
    $shadow = ShadowValidationService::boot($config, $logger);
    $start = $range['start'];
    $end = $range['end'];
    $forceRefresh = Http::boolQuery('force', false);

    if (!(bool) $config->get('features.canonical_fiscal_ready', false)) {
        $snapshot = $analytics->getSnapshot($start, $end, $forceRefresh);
        $legacyPayload = buildLegacyReconciliationPayload($snapshot, $start, $end, $freshness);
        $shadow->defer(
            'reconciliation',
            static fn (): ?array => $legacyPayload,
            static function () use ($config, $logger, $freshness, $start, $end): ?array {
                return buildCanonicalReconciliationShadowPayload($config, $logger, $freshness, $start, $end);
            },
            [
                'start' => $start,
                'end' => $end,
                'official_mode' => 'snapshot_fiscal',
            ]
        );

        Http::json($legacyPayload);
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        Http::json(['ok' => false, 'message' => 'Banco nao configurado.'], 500);
    }

    $pdo = Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger);

    if (!$canonical->isAvailable()) {
        Http::json([
            'ok' => false,
            'message' => 'Tabelas canonicas nao encontradas. Execute database/canonical_sales.sql.',
        ], 500);
    }

    $report = $canonical->reconciliationReport($start, $end);
    $summary = $canonical->summarize($start, $end);
    $divergences = $canonical->openDivergences(50);
    $lastSync = $canonical->lastSyncAt($start, $end);

    // Formatar para exibicao.
    $vendors = [];
    $totalFaturado = 0.0;
    $totalAutorizado = 0.0;
    $totalCancelado = 0.0;

    foreach ($report as $row) {
        $fat = (float) ($row['total_faturado'] ?? 0);
        $aut = (float) ($row['total_autorizado'] ?? 0);
        $can = (float) ($row['total_cancelado'] ?? 0);

        $totalFaturado += $fat;
        $totalAutorizado += $aut;
        $totalCancelado += $can;

        $vendors[] = [
            'codigo_vendedor' => (string) ($row['codigo_vendedor_omie'] ?? ''),
            'nome' => (string) ($row['nome_vendedor'] ?? 'Sem vendedor'),
            'faturado' => Support::money($fat),
            'faturado_qtd' => (int) ($row['qtd_faturado'] ?? 0),
            'autorizado' => Support::money($aut),
            'autorizado_qtd' => (int) ($row['qtd_autorizado'] ?? 0),
            'cancelado' => Support::money($can),
            'cancelado_qtd' => (int) ($row['qtd_cancelado'] ?? 0),
            'total_pedidos' => (int) ($row['qtd_total'] ?? 0),
            'total_geral' => Support::money((float) ($row['total_geral'] ?? 0)),
            'ultima_sync' => $row['ultima_sync'] ?? null,
        ];
    }

    $canonicalPayload = $freshness->attach('reconciliation', [
        'ok' => true,
        'period' => [
            'start' => $start,
            'end' => $end,
            'label' => date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end)),
        ],
        'totals' => [
            'faturado' => Support::money($totalFaturado),
            'autorizado' => Support::money($totalAutorizado),
            'cancelado' => Support::money($totalCancelado),
            'realizado' => Support::money($totalFaturado),
            'pedidos_faturados' => (int) ($summary['billed_orders'] ?? 0),
            'pedidos_autorizados' => (int) ($summary['authorized_orders'] ?? 0),
            'pedidos_cancelados' => (int) ($summary['cancelled_orders'] ?? 0),
        ],
        'vendors' => $vendors,
        'divergences_count' => count($divergences),
        'divergences' => $divergences,
        'last_sync' => $lastSync,
        'generated_at' => date(DATE_ATOM),
    ], ['observe_request' => true]);

    $shadow->defer(
        'reconciliation',
        static function () use ($analytics, $freshness, $start, $end, $forceRefresh): ?array {
            return buildLegacyReconciliationPayload($analytics->getSnapshot($start, $end, $forceRefresh), $start, $end, $freshness);
        },
        static fn (): ?array => $canonicalPayload,
        [
            'start' => $start,
            'end' => $end,
            'official_mode' => 'canonical',
        ]
    );

    Http::json($canonicalPayload);
} catch (\InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $logger->error('Falha no relatorio de reconciliacao.', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    Http::json(['ok' => false, 'message' => 'Erro ao gerar relatorio de reconciliacao.'], 500);
}

/**
 * @return array<string,mixed>
 */
function buildLegacyReconciliationPayload(array $snapshot, string $start, string $end, DatasetFreshnessService $freshness): array
{
    return LegacyEndpointPayloadFactory::reconciliation($snapshot, $freshness, $start, $end);
}

/**
 * @return array<string,mixed>|null
 */
function buildCanonicalReconciliationShadowPayload(\BIomie\Config $config, \BIomie\Logger $logger, DatasetFreshnessService $freshness, string $start, string $end): ?array
{
    if (!(bool) $config->get('features.canonical_fiscal_ready', false)) {
        return null;
    }

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

        $report = $canonical->reconciliationReport($start, $end);
        $summary = $canonical->summarize($start, $end);
        $divergences = $canonical->openDivergences(50);
        $lastSync = $canonical->lastSyncAt($start, $end);

        $vendors = [];
        $totalFaturado = 0.0;
        $totalAutorizado = 0.0;
        $totalCancelado = 0.0;

        foreach ($report as $row) {
            $fat = (float) ($row['total_faturado'] ?? 0);
            $aut = (float) ($row['total_autorizado'] ?? 0);
            $can = (float) ($row['total_cancelado'] ?? 0);

            $totalFaturado += $fat;
            $totalAutorizado += $aut;
            $totalCancelado += $can;

            $vendors[] = [
                'codigo_vendedor' => (string) ($row['codigo_vendedor_omie'] ?? ''),
                'nome' => (string) ($row['nome_vendedor'] ?? 'Sem vendedor'),
                'faturado' => Support::money($fat),
                'faturado_qtd' => (int) ($row['qtd_faturado'] ?? 0),
                'autorizado' => Support::money($aut),
                'autorizado_qtd' => (int) ($row['qtd_autorizado'] ?? 0),
                'cancelado' => Support::money($can),
                'cancelado_qtd' => (int) ($row['qtd_cancelado'] ?? 0),
                'total_pedidos' => (int) ($row['qtd_total'] ?? 0),
                'total_geral' => Support::money((float) ($row['total_geral'] ?? 0)),
                'ultima_sync' => $row['ultima_sync'] ?? null,
            ];
        }

        return $freshness->attach('reconciliation', [
            'ok' => true,
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end)),
            ],
            'totals' => [
                'faturado' => Support::money($totalFaturado),
                'autorizado' => Support::money($totalAutorizado),
                'cancelado' => Support::money($totalCancelado),
                'realizado' => Support::money($totalFaturado),
                'pedidos_faturados' => (int) ($summary['billed_orders'] ?? 0),
                'pedidos_autorizados' => (int) ($summary['authorized_orders'] ?? 0),
                'pedidos_cancelados' => (int) ($summary['cancelled_orders'] ?? 0),
            ],
            'vendors' => $vendors,
            'divergences_count' => count($divergences),
            'divergences' => $divergences,
            'last_sync' => $lastSync,
            'generated_at' => date(DATE_ATOM),
        ]);
    } catch (Throwable) {
        return null;
    }
}
