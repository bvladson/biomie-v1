<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — testes da cadência (Fase 3/4). Puro: sem DB, sem Omie.
 *   php tests/run_quote_followup_policy.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\QuoteAgenda\FollowupDatePolicy;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$tz = FollowupDatePolicy::timezone();
$p  = new FollowupDatePolicy([1, 3, 7, 14], 'roll_forward', 4);
$at = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s, $tz);

// ── Marcos absolutos a partir da criação ─────────────────────────────────────────
// Criado quarta 01/07/2026 → +1=02/07(qui) +3=04/07(SÁB→06/07 seg) +7=08/07(qua) +14=15/07(qua)
$c = $at('2026-07-01 10:00:00');
$check('marco 1 = created+1', $p->dueOn($c, 1) === '2026-07-02', $p->dueOn($c, 1));
$check('marco 2 = created+3 rola sábado→segunda', $p->dueOn($c, 2) === '2026-07-06', $p->dueOn($c, 2));
$check('marco 3 = created+7', $p->dueOn($c, 3) === '2026-07-08', $p->dueOn($c, 3));
$check('marco 4 = created+14', $p->dueOn($c, 4) === '2026-07-15', $p->dueOn($c, 4));

// Fim de semana: sexta+1 = sábado → segunda
$sex = $at('2026-07-03 09:00:00'); // sexta
$check('sexta+1 (sábado) → segunda', $p->dueOn($sex, 1) === '2026-07-06', $p->dueOn($sex, 1));

// Hora não influencia o dia civil (cobrança é diária)
$check('hora não muda o marco',
    $p->dueOn($at('2026-07-01 00:01:00'), 1) === $p->dueOn($at('2026-07-01 23:59:00'), 1), null);

// ── Régua ABSOLUTA: o clique não reinicia o relógio ──────────────────────────────
$check('nextStep avança exatamente 1', $p->nextStep(1) === 2 && $p->nextStep(2) === 3, null);
$check('nextStep não passa do último', $p->nextStep(4) === 4 && $p->nextStep(9) === 4, null);
$check('marco do passo 2 é created+3 mesmo cobrando tarde',
    $p->dueOn($c, $p->nextStep(1)) === '2026-07-06', $p->dueOn($c, 2));

// ── seedStep: orçamento antigo NÃO entra como "1ª cobrança amanhã" ───────────────
$check('recém-criado → passo 1', $p->seedStep($c, $at('2026-07-01 12:00:00')) === 1, $p->seedStep($c, $at('2026-07-01 12:00:00')));
$check('D+1 vencido → passo 1 (atrasado)', $p->seedStep($c, $at('2026-07-02 12:00:00')) === 1, $p->seedStep($c, $at('2026-07-02 12:00:00')));
$check('D+3 vencido → passo 2', $p->seedStep($c, $at('2026-07-06 12:00:00')) === 2, $p->seedStep($c, $at('2026-07-06 12:00:00')));
$check('D+7 vencido → passo 3', $p->seedStep($c, $at('2026-07-08 12:00:00')) === 3, $p->seedStep($c, $at('2026-07-08 12:00:00')));
$check('D+14 vencido → passo 4 (crítico)', $p->seedStep($c, $at('2026-07-15 12:00:00')) === 4, $p->seedStep($c, $at('2026-07-15 12:00:00')));
$check('orçamento de 6 meses → passo 4, não 1', $p->seedStep($at('2026-01-08 10:00:00'), $at('2026-07-16 12:00:00')) === 4,
    $p->seedStep($at('2026-01-08 10:00:00'), $at('2026-07-16 12:00:00')));

// ── Urgência (cores do calendário) ───────────────────────────────────────────────
$hoje = $at('2026-07-08 12:00:00');
$check('futuro → future', $p->urgency('2026-07-10', 1, $hoje) === 'future', $p->urgency('2026-07-10', 1, $hoje));
$check('hoje → today', $p->urgency('2026-07-08', 1, $hoje) === 'today', $p->urgency('2026-07-08', 1, $hoje));
$check('passado → overdue', $p->urgency('2026-07-06', 2, $hoje) === 'overdue', $p->urgency('2026-07-06', 2, $hoje));
$check('passo 4 vencido → critical', $p->urgency('2026-07-01', 4, $hoje) === 'critical', $p->urgency('2026-07-01', 4, $hoje));
$check('isCritical só a partir do passo 4', !$p->isCritical(3) && $p->isCritical(4), null);

// ── Política vinda do banco ──────────────────────────────────────────────────────
$fromRow = FollowupDatePolicy::fromPolicyRow([
    'cadence_days_json' => '[1,3,7,14]', 'weekend_policy' => 'roll_forward', 'critical_after_step' => 4,
]);
$check('fromPolicyRow lê a cadência', $fromRow->cadenceDays() === [1, 3, 7, 14], $fromRow->cadenceDays());
$check('JSON inválido cai no default',
    FollowupDatePolicy::fromPolicyRow(['cadence_days_json' => 'xxx'])->cadenceDays() === [1, 3, 7, 14], null);
$semRoll = new FollowupDatePolicy([1, 3, 7, 14], 'none');
$check('weekend_policy=none não rola', $semRoll->dueOn($c, 2) === '2026-07-04', $semRoll->dueOn($c, 2));

// ── Determinismo ─────────────────────────────────────────────────────────────────
$check('determinístico', $p->dueOn($c, 3) === $p->dueOn($c, 3), null);

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
