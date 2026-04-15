<?php

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support;

require __DIR__ . '/bootstrap_finance.php';

set_time_limit(90);

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
    if (Database::isConfigured($config, $connection)) {
        try {
            $pdo = Database::connect($config, $connection);
            $canonical = new CanonicalRepository($pdo, $logger);
            if ($canonical->isAvailable()) {
                $canonicalAvailable = true;
                $billed = $canonical->summarizeInvoicedCommercial($start, $end);
            }
        } catch (Throwable $exception) {
            $logger->warning('Commercial health sem leitura canonical.', [
                'connection' => $connection,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    if ($finance === null) {
        Http::json([
            'ok' => true,
            'available' => false,
            'source' => 'finance-unconfigured',
            'period' => ['start' => $start, 'end' => $end, 'label' => $range['label']],
            'summary' => [
                'invoice_total' => Support::money((float) ($billed['invoice_total'] ?? 0)),
                'merchandise_total' => Support::money((float) ($billed['merchandise_total'] ?? 0)),
                'notes_count' => (int) ($billed['notes_count'] ?? 0),
            ],
            'message' => 'Modulo financeiro Omie nao configurado para enriquecer o painel comercial.',
        ]);
    }

    $snapshot = $finance->getSnapshot($start, $end, $force);
    $financeSummary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
    $received = Support::toFloat($financeSummary['received'] ?? 0);
    $open = Support::toFloat($financeSummary['open'] ?? 0);
    $overdue = Support::toFloat($financeSummary['overdue'] ?? 0);
    $invoiceTotal = Support::toFloat($billed['invoice_total'] ?? 0);
    $merchandiseTotal = Support::toFloat($billed['merchandise_total'] ?? 0);

    $cashConversionPct = $invoiceTotal > 0 ? round(($received / $invoiceTotal) * 100, 2) : 0.0;
    $overdueExposurePct = $open > 0 ? round(($overdue / $open) * 100, 2) : 0.0;
    $topOverdueClients = array_slice((array) ($snapshot['by_client'] ?? []), 0, 5);
    $paymentMethods = array_slice((array) ($snapshot['payment_methods'] ?? []), 0, 5);

    Http::json([
        'ok' => true,
        'available' => true,
        'source' => 'finance-live-widget',
        'period' => ['start' => $start, 'end' => $end, 'label' => $range['label']],
        'summary' => [
            'invoice_total' => Support::money($invoiceTotal),
            'merchandise_total' => Support::money($merchandiseTotal),
            'notes_count' => (int) ($billed['notes_count'] ?? 0),
            'received' => $received,
            'open' => $open,
            'overdue' => $overdue,
            'delinquency_rate' => Support::toFloat($financeSummary['delinquency_rate'] ?? 0),
            'realized_vs_planned' => Support::toFloat($financeSummary['realized_vs_planned'] ?? 0),
            'cash_conversion_pct' => $cashConversionPct,
            'overdue_exposure_pct' => $overdueExposurePct,
            'received_titles' => (int) ($financeSummary['received_titles'] ?? 0),
            'open_titles' => (int) ($financeSummary['open_titles'] ?? 0),
            'overdue_titles' => (int) ($financeSummary['overdue_titles'] ?? 0),
        ],
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
        'meta' => [
            'canonical_available' => $canonicalAvailable,
            'canonical_connection' => $connection,
            'finance_generated_at' => (string) ($snapshot['meta']['generated_at'] ?? date(DATE_ATOM)),
            'finance_served_from' => (string) ($snapshot['meta']['served_from'] ?? 'live-build'),
            'request_id' => (string) ($snapshot['meta']['request_id'] ?? ''),
        ],
    ]);
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
