<?php

declare(strict_types=1);

use BIomie\OperationalPayloadFingerprint;
use BIomie\SyncCursorState;
use BIomie\SyncDeltaWindowPlanner;

require_once __DIR__ . '/TestSupport.php';
require_once __DIR__ . '/TestRunner.php';

/**
 * @return array<string,mixed>
 */
function run_replay_idempotency_suite(): array
{
    $runner = new TestRunner();

    $runner->run('bronze_stable_hash', 'O mesmo payload bronze em ordem diferente preserva request/payload hash e idempotencia.', static function (): array {
        $left = OperationalPayloadFingerprint::bronzeRecord(
            'commercial',
            'orders',
            'PedidoVenda/ListarPedidos',
            'ListarPedidos',
            ['pagina' => 1, 'registros_por_pagina' => 500, 'filtro' => ['status' => 'FATURADO', 'data' => '2026-04-12']],
            ['pedido' => ['codigo' => 'P-5001', 'valor' => 1250.0], 'itens' => [['sku' => 'ABC', 'qtd' => 2]]],
            'P-5001',
            '5001'
        );
        $right = OperationalPayloadFingerprint::bronzeRecord(
            'commercial',
            'orders',
            'PedidoVenda/ListarPedidos',
            'ListarPedidos',
            ['registros_por_pagina' => 500, 'filtro' => ['data' => '2026-04-12', 'status' => 'FATURADO'], 'pagina' => 1],
            ['itens' => [['qtd' => 2, 'sku' => 'ABC']], 'pedido' => ['valor' => 1250.0, 'codigo' => 'P-5001']],
            'P-5001',
            '5001'
        );

        $ok = $left['request_hash'] === $right['request_hash']
            && $left['payload_hash'] === $right['payload_hash']
            && $left['idempotency_key'] === $right['idempotency_key'];

        return [
            'ok' => $ok,
            'mismatches' => $ok ? [] : [[
                'path' => 'bronze_record',
                'expected' => $left,
                'actual' => $right,
            ]],
        ];
    });

    $runner->run('bronze_change_breaks_idempotency', 'Mudanca material de payload bronze altera o fingerprint final.', static function (): array {
        $base = OperationalPayloadFingerprint::bronzeRecord(
            'commercial',
            'orders',
            'PedidoVenda/ListarPedidos',
            'ListarPedidos',
            ['pagina' => 1],
            ['pedido' => ['codigo' => 'P-5002', 'valor' => 1000.0]],
            'P-5002',
            '5002'
        );
        $changed = OperationalPayloadFingerprint::bronzeRecord(
            'commercial',
            'orders',
            'PedidoVenda/ListarPedidos',
            'ListarPedidos',
            ['pagina' => 1],
            ['pedido' => ['codigo' => 'P-5002', 'valor' => 1010.0]],
            'P-5002',
            '5002'
        );

        $ok = $base['payload_hash'] !== $changed['payload_hash']
            && $base['idempotency_key'] !== $changed['idempotency_key'];

        return [
            'ok' => $ok,
            'mismatches' => $ok ? [] : [[
                'path' => 'payload_hash',
                'expected' => 'hashes diferentes',
                'actual' => [$base['payload_hash'], $changed['payload_hash']],
            ]],
        ];
    });

    $runner->run('webhook_duplicate_same_dedupe', 'Webhook duplicado com headers em ordem diferente gera a mesma chave de deduplicacao.', static function (): array {
        $left = OperationalPayloadFingerprint::webhookRecord(
            'commercial',
            'pedido',
            ['evento' => 'pedido_alterado', 'codigo_pedido' => 'P-7001', 'updated_at' => '2026-04-12 10:15:00'],
            ['x-request-id' => 'abc', 'x-omie-topic' => 'pedido'],
            'P-7001',
            ['source' => 'omie-webhook']
        );
        $right = OperationalPayloadFingerprint::webhookRecord(
            'commercial',
            'pedido',
            ['updated_at' => '2026-04-12 10:15:00', 'codigo_pedido' => 'P-7001', 'evento' => 'pedido_alterado'],
            ['x-omie-topic' => 'pedido', 'x-request-id' => 'abc'],
            'P-7001',
            ['source' => 'omie-webhook']
        );

        $ok = $left['dedupe_key'] === $right['dedupe_key'];

        return [
            'ok' => $ok,
            'mismatches' => $ok ? [] : [[
                'path' => 'dedupe_key',
                'expected' => $left['dedupe_key'],
                'actual' => $right['dedupe_key'],
            ]],
        ];
    });

    $runner->run('cursor_rerun_preserves_window', 'Reexecutar a mesma janela nao reduz last_event_at nem comprime a faixa de reparo.', static function (): array {
        $current = [
            'last_event_at' => '2026-04-12 10:00:00',
            'last_source_id' => 'evt-10',
            'repair_from' => '2026-04-12 08:30:00',
            'repair_to' => '2026-04-12 09:00:00',
            'last_success_at' => '2026-04-12 10:05:00',
            'status' => 'completed',
            'cursor_meta_json' => '{"cursor":"current"}',
            'safety_window_minutes' => 15,
        ];
        $merged = SyncCursorState::merge($current, 'commercial', 'pedido', [
            'last_event_at' => '2026-04-12 09:59:59',
            'last_source_id' => 'evt-09',
            'repair_from' => '2026-04-12 09:00:00',
            'repair_to' => '2026-04-12 08:45:00',
            'last_success_at' => '2026-04-12 10:04:00',
            'status' => 'completed_with_warnings',
            'cursor_meta' => ['window' => ['end' => '2026-04-12 09:00:00', 'start' => '2026-04-12 08:30:00']],
        ]);

        $expected = [
            'last_event_at' => '2026-04-12 10:00:00',
            'repair_from' => '2026-04-12 08:30:00',
            'repair_to' => '2026-04-12 09:00:00',
            'last_success_at' => '2026-04-12 10:05:00',
            'status' => 'completed_with_warnings',
        ];
        $actual = [
            'last_event_at' => $merged['last_event_at'] ?? null,
            'repair_from' => $merged['repair_from'] ?? null,
            'repair_to' => $merged['repair_to'] ?? null,
            'last_success_at' => $merged['last_success_at'] ?? null,
            'status' => $merged['status'] ?? null,
        ];

        $ok = $expected === $actual;

        return [
            'ok' => $ok,
            'mismatches' => $ok ? [] : [[
                'path' => 'cursor_merge',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    $runner->run('hot_window_without_cursors', 'Sem cursor previo, a janela delta usa hot window + safety window.', static function (): array {
        $now = new DateTimeImmutable('2026-04-12 12:00:00');
        $window = SyncDeltaWindowPlanner::resolve([
            'hot_window_minutes' => 120,
            'safety_window_minutes' => 15,
        ], [], $now);

        $expected = [
            'start' => '2026-04-12 09:45:00',
            'end' => '2026-04-12 12:00:00',
        ];
        $actual = $window === null ? null : [
            'start' => $window['start_at']->format('Y-m-d H:i:s'),
            'end' => $window['end_at']->format('Y-m-d H:i:s'),
        ];

        $ok = $expected === $actual;

        return [
            'ok' => $ok,
            'mismatches' => $ok ? [] : [[
                'path' => 'delta_window.default',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    $runner->run('late_event_repair_window', 'Evento tardio com repair_from explicito reabre a janela correta.', static function (): array {
        $now = new DateTimeImmutable('2026-04-12 12:00:00');
        $window = SyncDeltaWindowPlanner::resolve([
            'hot_window_minutes' => 120,
            'safety_window_minutes' => 15,
        ], [[
            'repair_from' => '2026-04-12 07:00:00',
            'repair_to' => '2026-04-12 11:10:00',
            'next_delta_at' => '2026-04-12 11:00:00',
            'safety_window_minutes' => 10,
        ]], $now);

        $expected = [
            'start' => '2026-04-12 07:00:00',
            'end' => '2026-04-12 11:10:00',
        ];
        $actual = $window === null ? null : [
            'start' => $window['start_at']->format('Y-m-d H:i:s'),
            'end' => $window['end_at']->format('Y-m-d H:i:s'),
        ];

        $ok = $actual === $expected;

        return [
            'ok' => $ok,
            'mismatches' => $ok ? [] : [[
                'path' => 'delta_window.repair',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    $runner->run('future_cursor_skips_delta', 'Quando o cursor ainda nao venceu, o planner nao agenda nova janela.', static function (): array {
        $window = SyncDeltaWindowPlanner::resolve([
            'hot_window_minutes' => 120,
            'safety_window_minutes' => 15,
        ], [[
            'next_delta_at' => '2026-04-12 12:05:00',
            'safety_window_minutes' => 15,
        ]], new DateTimeImmutable('2026-04-12 12:00:00'));

        $ok = $window === null;

        return [
            'ok' => $ok,
            'mismatches' => $ok ? [] : [[
                'path' => 'delta_window.future_cursor',
                'expected' => null,
                'actual' => $window,
            ]],
        ];
    });

    $runner->run('overlapping_repairs_expand_scope', 'Cursores sobrepostos escolhem o inicio mais antigo e o fim mais novo para replay seguro.', static function (): array {
        $window = SyncDeltaWindowPlanner::resolve([
            'hot_window_minutes' => 120,
            'safety_window_minutes' => 15,
        ], [
            [
                'repair_from' => '2026-04-12 05:30:00',
                'repair_to' => '2026-04-12 06:45:00',
                'next_delta_at' => '2026-04-12 11:00:00',
            ],
            [
                'repair_from' => '2026-04-12 05:45:00',
                'repair_to' => '2026-04-12 08:15:00',
                'next_delta_at' => '2026-04-12 11:00:00',
            ],
        ], new DateTimeImmutable('2026-04-12 12:00:00'));

        $expected = [
            'start' => '2026-04-12 05:30:00',
            'end' => '2026-04-12 08:15:00',
        ];
        $actual = $window === null ? null : [
            'start' => $window['start_at']->format('Y-m-d H:i:s'),
            'end' => $window['end_at']->format('Y-m-d H:i:s'),
        ];

        $ok = $expected === $actual;

        return [
            'ok' => $ok,
            'mismatches' => $ok ? [] : [[
                'path' => 'delta_window.overlap',
                'expected' => $expected,
                'actual' => $actual,
            ]],
        ];
    });

    return $runner->summary();
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = run_replay_idempotency_suite();
    echo pretty_json($result) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
}
