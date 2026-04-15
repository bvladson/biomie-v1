<?php

declare(strict_types=1);

require_once __DIR__ . '/TestSupport.php';
require_once __DIR__ . '/TestRunner.php';

/**
 * @return array<string,mixed>
 */
function run_freshness_fallback_suite(): array
{
    $runner = new TestRunner();
    $today = new DateTimeImmutable('today');
    $todayDate = $today->format('Y-m-d');
    $nowTimestamp = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $runner->run('freshness_live_online', 'API online com dado recente deve permanecer em modo live.', static function () use ($todayDate, $nowTimestamp): array {
        $freshness = make_freshness_service();
        $payload = $freshness->attach('overview', [
            'ok' => true,
            'source' => 'legacy',
            'period' => ['start' => $todayDate, 'end' => $todayDate],
            'meta' => [
                'served_from' => 'live-omie',
                'generated_at' => $nowTimestamp,
            ],
        ]);

        $contract = $payload['data_contract'] ?? [];
        $expected = [
            'origin' => 'live-omie',
            'data_mode' => 'live',
            'staleness_reason' => null,
            'reconcile_status' => 'partial',
        ];
        $actual = [
            'origin' => $contract['origin'] ?? null,
            'data_mode' => $contract['data_mode'] ?? null,
            'staleness_reason' => $contract['staleness_reason'] ?? null,
            'reconcile_status' => $contract['reconcile_status'] ?? null,
        ];

        return [
            'ok' => $expected === $actual,
            'mismatches' => $expected === $actual ? [] : [[
                'path' => 'contract.live',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    $runner->run('freshness_snapshot_reconciled', 'Snapshot local de periodo fechado deve ser marcado como reconciled.', static function (): array {
        $freshness = make_freshness_service();
        $payload = $freshness->attach('vendors', [
            'ok' => true,
            'source' => 'legacy_fallback',
            'period' => ['start' => '2026-03-01', 'end' => '2026-03-31'],
            'meta' => [
                'served_from' => 'historical-snapshot',
                'generated_at' => '2026-04-01 08:00:00',
                'last_sync' => '2026-04-01 08:00:00',
            ],
        ]);

        $contract = $payload['data_contract'] ?? [];
        $expected = [
            'origin' => 'historical-snapshot',
            'data_mode' => 'reconciled',
            'staleness_reason' => null,
            'reconcile_status' => 'reconciled',
            'reconciled_until' => '2026-03-31 23:59:59',
        ];
        $actual = [
            'origin' => $contract['origin'] ?? null,
            'data_mode' => $contract['data_mode'] ?? null,
            'staleness_reason' => $contract['staleness_reason'] ?? null,
            'reconcile_status' => $contract['reconcile_status'] ?? null,
            'reconciled_until' => $contract['reconciled_until'] ?? null,
        ];

        return [
            'ok' => $expected === $actual,
            'mismatches' => $expected === $actual ? [] : [[
                'path' => 'contract.snapshot',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    $runner->run('freshness_cache_fallback', 'Fallback de cache stale-if-error deve expor badge de cache_fallback.', static function () use ($todayDate, $nowTimestamp): array {
        $freshness = make_freshness_service();
        $payload = $freshness->attach('finance_overview', [
            'ok' => true,
            'period' => ['start' => $todayDate, 'end' => $todayDate],
            'meta' => [
                'served_from' => 'cache-stale',
                'generated_at' => $nowTimestamp,
            ],
        ]);

        $contract = $payload['data_contract'] ?? [];
        $expected = [
            'data_mode' => 'stale',
            'staleness_reason' => 'cache_fallback',
            'completeness_status' => 'partial',
        ];
        $actual = [
            'data_mode' => $contract['data_mode'] ?? null,
            'staleness_reason' => $contract['staleness_reason'] ?? null,
            'completeness_status' => $contract['completeness_status'] ?? null,
        ];

        return [
            'ok' => $expected === $actual,
            'mismatches' => $expected === $actual ? [] : [[
                'path' => 'contract.cache_fallback',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    $runner->run('freshness_retry_backlog', 'Backlog ou retry pendente deve degradar o contrato para stale com motivo operacional.', static function () use ($todayDate, $nowTimestamp): array {
        $freshness = make_freshness_service([
            'recent_runs' => [[
                'domain_name' => 'commercial',
                'status' => 'failed_retryable',
                'run_type' => 'delta',
                'finished_at' => $nowTimestamp,
            ]],
            'pipeline_summary' => [
                'webhook_inbox' => [
                    'pending_total' => 4,
                    'oldest_pending_age_seconds' => 1800,
                ],
            ],
        ]);
        $payload = $freshness->attach('orders', [
            'ok' => true,
            'source' => 'legacy',
            'period' => ['start' => $todayDate, 'end' => $todayDate],
            'meta' => [
                'served_from' => 'live-omie',
                'generated_at' => $nowTimestamp,
            ],
        ]);

        $contract = $payload['data_contract'] ?? [];
        $expected = [
            'data_mode' => 'stale',
            'staleness_reason' => 'pending_retry_or_backlog',
            'has_retry_or_delay' => true,
        ];
        $actual = [
            'data_mode' => $contract['data_mode'] ?? null,
            'staleness_reason' => $contract['staleness_reason'] ?? null,
            'has_retry_or_delay' => (bool) ($contract['has_retry_or_delay'] ?? false),
        ];

        return [
            'ok' => $expected === $actual,
            'mismatches' => $expected === $actual ? [] : [[
                'path' => 'contract.retry_backlog',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    $runner->run('freshness_missing_marker', 'Ausencia total de marcador de frescor deve ser exposta como missing_freshness_marker.', static function () use ($todayDate): array {
        $freshness = make_freshness_service();
        $payload = $freshness->attach('finance_titles', [
            'ok' => true,
            'source' => 'legacy',
            'period' => ['start' => $todayDate, 'end' => $todayDate],
            'meta' => [
                'served_from' => 'live-omie',
            ],
        ]);

        $contract = $payload['data_contract'] ?? [];
        $expected = [
            'data_mode' => 'stale',
            'staleness_reason' => 'missing_freshness_marker',
            'completeness_status' => 'partial',
        ];
        $actual = [
            'data_mode' => $contract['data_mode'] ?? null,
            'staleness_reason' => $contract['staleness_reason'] ?? null,
            'completeness_status' => $contract['completeness_status'] ?? null,
        ];

        return [
            'ok' => $expected === $actual,
            'mismatches' => $expected === $actual ? [] : [[
                'path' => 'contract.missing_marker',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    $runner->run('freshness_closed_period_pending_reconcile', 'Periodo fechado sem reconciliacao formal deve carregar motivo reconcile_pending.', static function (): array {
        $freshness = make_freshness_service();
        $payload = $freshness->attach('reconciliation', [
            'ok' => true,
            'period' => ['start' => '2026-03-01', 'end' => '2026-03-31'],
            'meta' => [
                'served_from' => 'live-omie',
                'generated_at' => '2026-03-15 08:00:00',
            ],
        ]);

        $contract = $payload['data_contract'] ?? [];
        $expected = [
            'data_mode' => 'stale',
            'staleness_reason' => 'reconcile_pending',
            'reconcile_status' => 'partial',
        ];
        $actual = [
            'data_mode' => $contract['data_mode'] ?? null,
            'staleness_reason' => $contract['staleness_reason'] ?? null,
            'reconcile_status' => $contract['reconcile_status'] ?? null,
        ];

        return [
            'ok' => $expected === $actual,
            'mismatches' => $expected === $actual ? [] : [[
                'path' => 'contract.reconcile_pending',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    return $runner->summary();
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = run_freshness_fallback_suite();
    echo pretty_json($result) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
}
