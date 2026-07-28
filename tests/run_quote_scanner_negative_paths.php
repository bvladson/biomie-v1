<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — CAMINHOS NEGATIVOS do scanner (Fase 4).
 *
 * Cobre exatamente o que as asserções de estado NÃO cobriam:
 *   1. paginação incompleta → failed e NENHUMA ausência marcada
 *   2. falha PRESERVA last_successful_at
 *   3. pedido reaparecido LIMPA missing_since
 *   4. scanner NÃO altera contador, passo, cobrança ou auditoria
 *   5. ausência NÃO resolve (resolved_at continua NULL)
 *
 * Usa uma fonte FALSA (QuoteLiveSnapshotSource) — sem Omie, sem rede.
 * Tudo roda numa transação revertida no fim, com company_id sintético.
 *
 *   php tests/run_quote_scanner_negative_paths.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\QuoteAgenda\QuoteLiveSnapshot;
use BIomie\QuoteAgenda\QuoteLiveSnapshotScanner;
use BIomie\QuoteAgenda\QuoteLiveSnapshotSource;

const TEST_COMPANY = 999901;
const TEST_ORDER   = 9999000001;

/** Fonte controlada: devolve o snapshot que o teste mandar. */
final class FakeSnapshotSource implements QuoteLiveSnapshotSource
{
    public function __construct(private readonly QuoteLiveSnapshot $snap) {}

    public function capture(string $etapa = '10'): QuoteLiveSnapshot
    {
        return $this->snap;
    }

    public function diffAgainstCanonical(QuoteLiveSnapshot $snap): array
    {
        return ['existing' => [], 'missing' => $snap->eligibleIds(), 'drifts' => [], 'emissao_suspeita' => []];
    }

    public function upsertEligible(QuoteLiveSnapshot $snap): array
    {
        // Não toca no canônico: o foco aqui é o comportamento do scanner.
        return ['inserted' => 0, 'updated' => 0, 'unchanged' => count($snap->eligibleById), 'skipped' => 0];
    }
}

function makeSnapshot(array $eligible, array $problems = []): QuoteLiveSnapshot
{
    return new QuoteLiveSnapshot(
        companyId: TEST_COMPANY,
        etapa: '10',
        rawById: [],
        normalizedById: $eligible,
        eligibleById: $eligible,
        excludedById: [],
        totalRegistros: count($eligible),
        totalPaginas: 1,
        problems: $problems,
        snapshotHash: str_repeat('a', 64),
    );
}

function orderRow(string $id): array
{
    return [
        'pedido_id_omie' => $id,
        'codigo_pedido' => 'T-' . $id,
        'codigo_vendedor_omie' => '123',
        'etapa_omie' => '10',
        'data_inclusao_omie' => '2026-07-10 09:00:00',
        'data_previsao_faturamento' => '2026-07-20',
        'valor_total' => 1000.00,
    ];
}

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$config = new Config();
$pdo = test_pdo(); // suíte MUTÁVEL: escreve → só o test_db aceita (TestDatabaseGuard bloqueia produção)
$logger = new Logger(dirname(__DIR__) . '/storage/logs/tests.log');

$T_SCHED = 'biomie_sales_quote_followup_schedule';
$T_SNAP  = 'biomie_quote_live_snapshot_sync';
$T_POL   = 'biomie_quote_followup_policy';

$pdo->beginTransaction();
try {
    // ── Cenário: empresa sintética, política default e 1 followup "maduro" ───────
    $pdo->prepare("INSERT INTO {$T_POL} (company_id, cadence_days_json, weekend_policy, enabled)
                   VALUES (:c, '[1,3,7,14]', 'roll_forward', 0)")->execute(['c' => TEST_COMPANY]);

    // last_successful_at ANTERIOR — não pode ser perdido numa falha.
    $pdo->prepare("INSERT INTO {$T_SNAP} (company_id, started_at, completed_at, last_successful_at, status, live_total, eligible_total)
                   VALUES (:c, '2026-07-01 10:00:00', '2026-07-01 10:00:05', '2026-07-01 10:00:05', 'success', 5, 5)")
        ->execute(['c' => TEST_COMPANY]);

    // Followup com histórico operacional: 3 cobranças, passo 3, ausente há dias.
    $pdo->prepare("INSERT INTO {$T_SCHED}
        (company_id, omie_order_id, seller_id, quote_created_at, quote_created_source, quote_forecast_on,
         next_followup_on, followup_step, charge_count, last_manager_charge_at, last_manager_user_id,
         last_seen_at, missing_since, reconciliation_attempts)
        VALUES (:c, :o, '123', '2026-07-10 09:00:00', 'omie_dinc', '2026-07-20',
                '2026-07-17', 3, 3, '2026-07-15 12:00:00', 77, '2026-07-15 12:00:00', '2026-07-16 08:00:00', 2)")
        ->execute(['c' => TEST_COMPANY, 'o' => TEST_ORDER]);

    $before = $pdo->query("SELECT * FROM {$T_SCHED} WHERE company_id=" . TEST_COMPANY)->fetch(PDO::FETCH_ASSOC);
    $snapBefore = $pdo->query("SELECT * FROM {$T_SNAP} WHERE company_id=" . TEST_COMPANY)->fetch(PDO::FETCH_ASSOC);

    // ── TESTE 1+2: paginação incompleta → failed, sem ausência, sem perder sucesso ─
    $scannerBad = new QuoteLiveSnapshotScanner(
        $pdo,
        new FakeSnapshotSource(makeSnapshot([], ['paginação incompleta: 3 recebidos ≠ 42 total_de_registros'])),
        $logger,
        TEST_COMPANY
    );
    $r1 = $scannerBad->scan(true);

    $check('1. snapshot incompleto → ok=false', ($r1['ok'] ?? true) === false, json_encode($r1['blocking'] ?? []));
    $check('1. snapshot incompleto → applied=false', ($r1['applied'] ?? true) === false);

    $snapAfterFail = $pdo->query("SELECT * FROM {$T_SNAP} WHERE company_id=" . TEST_COMPANY)->fetch(PDO::FETCH_ASSOC);
    $check('1. status = failed', ($snapAfterFail['status'] ?? '') === 'failed', $snapAfterFail['status'] ?? '?');

    $rowAfterFail = $pdo->query("SELECT * FROM {$T_SCHED} WHERE company_id=" . TEST_COMPANY)->fetch(PDO::FETCH_ASSOC);
    $check('1. NENHUMA ausência nova marcada (missing_since intacto)',
        $rowAfterFail['missing_since'] === $before['missing_since'], $rowAfterFail['missing_since']);
    $check('1. reconciliation_attempts NÃO incrementado na falha',
        (int) $rowAfterFail['reconciliation_attempts'] === (int) $before['reconciliation_attempts'],
        $rowAfterFail['reconciliation_attempts']);

    $check('2. falha PRESERVA last_successful_at',
        $snapAfterFail['last_successful_at'] === $snapBefore['last_successful_at'],
        $snapAfterFail['last_successful_at']);
    $check('2. falha registra last_error', !empty($snapAfterFail['last_error']), $snapAfterFail['last_error'] ?? '');

    // ── TESTE 3+4: pedido REAPARECE → limpa missing_since, preserva operacional ───
    $scannerOk = new QuoteLiveSnapshotScanner(
        $pdo,
        new FakeSnapshotSource(makeSnapshot([(string) TEST_ORDER => orderRow((string) TEST_ORDER)])),
        $logger,
        TEST_COMPANY
    );
    $r2 = $scannerOk->scan(true);
    $check('3. scan com snapshot completo → ok=true', ($r2['ok'] ?? false) === true, json_encode($r2['blocking'] ?? []));

    $after = $pdo->query("SELECT * FROM {$T_SCHED} WHERE company_id=" . TEST_COMPANY)->fetch(PDO::FETCH_ASSOC);
    $check('3. reaparecimento LIMPA missing_since', $after['missing_since'] === null, var_export($after['missing_since'], true));
    $check('3. reaparecimento zera reconciliation_attempts', (int) $after['reconciliation_attempts'] === 0, $after['reconciliation_attempts']);
    $check('3. contabilizado como reaparecido', (int) (($r2['followups']['reappeared'] ?? 0)) === 1, $r2['followups']['reappeared'] ?? null);

    $check('4. charge_count PRESERVADO', (int) $after['charge_count'] === 3, $after['charge_count']);
    $check('4. followup_step PRESERVADO', (int) $after['followup_step'] === 3, $after['followup_step']);
    $check('4. last_manager_charge_at PRESERVADO',
        $after['last_manager_charge_at'] === $before['last_manager_charge_at'], $after['last_manager_charge_at']);
    $check('4. last_manager_user_id PRESERVADO',
        (int) $after['last_manager_user_id'] === 77, $after['last_manager_user_id']);
    $check('4. followup NÃO duplicado',
        (int) $pdo->query("SELECT COUNT(*) FROM {$T_SCHED} WHERE company_id=" . TEST_COMPANY)->fetchColumn() === 1);
    $check('4. next_followup_on recalculado p/ o passo vigente (created+7 = 17/07)',
        $after['next_followup_on'] === '2026-07-17', $after['next_followup_on']);

    $snapOk = $pdo->query("SELECT * FROM {$T_SNAP} WHERE company_id=" . TEST_COMPANY)->fetch(PDO::FETCH_ASSOC);
    $check('3. sucesso AVANÇA last_successful_at',
        $snapOk['last_successful_at'] > $snapBefore['last_successful_at'], $snapOk['last_successful_at']);

    // ── TESTE 5: pedido SOME → missing_since, mas NÃO resolve ────────────────────
    $scannerGone = new QuoteLiveSnapshotScanner(
        $pdo, new FakeSnapshotSource(makeSnapshot([])), $logger, TEST_COMPANY
    );
    $r3 = $scannerGone->scan(true);
    $gone = $pdo->query("SELECT * FROM {$T_SCHED} WHERE company_id=" . TEST_COMPANY)->fetch(PDO::FETCH_ASSOC);

    $check('5. ausência marca missing_since', $gone['missing_since'] !== null, var_export($gone['missing_since'], true));
    $check('5. ausência incrementa tentativas', (int) $gone['reconciliation_attempts'] === 1, $gone['reconciliation_attempts']);
    $check('5. ausência NÃO resolve (resolved_at NULL)', $gone['resolved_at'] === null, var_export($gone['resolved_at'], true));
    $check('5. ausência NÃO define resolution', $gone['resolution'] === null, var_export($gone['resolution'], true));
    $check('5. ausência NÃO zera charge_count', (int) $gone['charge_count'] === 3, $gone['charge_count']);
} finally {
    $pdo->rollBack(); // nada persiste
}

$leaked = (int) $pdo->query("SELECT COUNT(*) FROM {$T_SCHED} WHERE company_id=" . TEST_COMPANY)->fetchColumn();
$check('rollback limpou o cenário de teste', $leaked === 0, "$leaked linhas");

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
