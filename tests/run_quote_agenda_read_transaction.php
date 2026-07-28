<?php

declare(strict_types=1);

/**
 * AGENDA — readWithFreshness ABRINDO a própria transação.
 *
 * REGRESSÃO REAL (descoberta em 2026-07-20, código já em produção):
 * `START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT` **exige REPEATABLE
 * READ**. Esta instalação roda em READ-COMMITTED, então o comando lançava
 * erro 4062 e QUALQUER chamada a summary/calendar/day/detail resultaria em 500.
 *
 * Passou despercebido porque a suíte de frescor chamava readWithFreshness
 * DENTRO da transação do próprio teste (`$own = false`), pulando exatamente a
 * linha defeituosa. Só a flag OFF (403 antes de tudo) impediu o incidente.
 *
 * Este teste roda FORA de transação de propósito — é o único jeito de exercitar
 * o caminho que quebrava.
 *
 * READ-ONLY: não escreve nada.
 *
 *   php tests/run_quote_agenda_read_transaction.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\QuoteAgenda\QuoteAgendaReadService;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$config = new Config();
$pdo = Database::connect($config, Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');

$isolation = (string) $pdo->query('SELECT @@transaction_isolation')->fetchColumn();
$check('isolamento da sessão identificado', $isolation !== '', $isolation);
$check('sem transação aberta (o caminho que quebrava)', !$pdo->inTransaction());

$svc = new QuoteAgendaReadService($pdo, 1);

// ── 1. O caminho que lançava 4062 ────────────────────────────────────────────
$erro = null;
$env = null;
try {
    $env = $svc->readWithFreshness(static fn (QuoteAgendaReadService $s): array => $s->summary());
} catch (Throwable $e) {
    $erro = $e->getMessage();
}
$check('🔴 readWithFreshness NÃO lança abrindo a própria transação', $erro === null, $erro ?? 'sem erro');
$check('envelope completo devolvido',
    is_array($env) && isset($env['data'], $env['freshness'], $env['generated_at']));

// ── 2. A transação foi ENCERRADA (não pode vazar aberta) ────────────────────
// Transação read-only pendurada segura recursos e, num pool, contamina o
// próximo uso da conexão.
$check('🔴 nenhuma transação ficou aberta após o retorno', !$pdo->inTransaction());

// ── 3. A degradação é VISÍVEL, nunca silenciosa ─────────────────────────────
$check('freshness informa se o snapshot foi consistente',
    is_array($env) && array_key_exists('consistent_snapshot', $env['freshness']),
    var_export($env['freshness']['consistent_snapshot'] ?? null, true));
$check('nesta instalação o snapshot consistente FOI obtido',
    ($env['freshness']['consistent_snapshot'] ?? false) === true,
    'isolamento da sessão: ' . $isolation);

// ── 4. O isolamento da SESSÃO não foi alterado de forma persistente ─────────
// `SET TRANSACTION` vale só para a próxima transação. Se virasse `SET SESSION`,
// contaminaria todo o resto do app que compartilha a conexão (há pool).
$depois = (string) $pdo->query('SELECT @@transaction_isolation')->fetchColumn();
$check('🔴 isolamento da sessão INALTERADO após a leitura',
    $depois === $isolation, "{$isolation} → {$depois}");

// ── 5. Reentrância: chamada DENTRO de transação alheia não a encerra ────────
// O caller pode já ser dono de uma transação; o service não pode commitá-la.
$pdo->beginTransaction();
try {
    $envIn = $svc->readWithFreshness(static fn (QuoteAgendaReadService $s): array => $s->summary());
    $check('dentro de transação alheia: retorna normalmente', isset($envIn['data']));
    $check('🔴 NÃO comita a transação de quem chamou', $pdo->inTransaction());
    $check('e sinaliza que o snapshot não é próprio',
        ($envIn['freshness']['consistent_snapshot'] ?? null) === false);
} finally {
    $pdo->rollBack();
}
$check('rollback do caller funcionou', !$pdo->inTransaction());

// ── 6. Os demais leitores usados pelos endpoints também abrem transação ────
foreach ([
    'calendar' => static fn (QuoteAgendaReadService $s): array => $s->calendar('2026-07-01', '2026-07-31'),
    'day'      => static fn (QuoteAgendaReadService $s): array => $s->day('2026-07-20'),
    'openItems'=> static fn (QuoteAgendaReadService $s): array => $s->openItems(),
] as $nome => $reader) {
    $e = null;
    try { $svc->readWithFreshness($reader); } catch (Throwable $ex) { $e = $ex->getMessage(); }
    $check("readWithFreshness({$nome}) sem exceção", $e === null, $e ?? 'ok');
    $check("readWithFreshness({$nome}) não vazou transação", !$pdo->inTransaction());
}

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'context' => ['isolation' => $isolation],
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
