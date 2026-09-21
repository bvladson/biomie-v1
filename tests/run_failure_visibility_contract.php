<?php

declare(strict_types=1);

/**
 * Contrato de visibilidade de falhas (auditoria 21/09/2026).
 *
 * 1. O SLA dos jobs de inteligência lê `intelligence_job_runs` (gravada em
 *    UTC pelo Python) como UTC — antes subestimava a idade em 3 h.
 * 2. A falha do delta Omie carrega o motivo real de cada domínio — antes era
 *    só "Sync delta Omie falhou.", o que escondeu 592 itens da dead-letter.
 */

use BIomie\Intelligence\IntelligenceJobSla;
use BIomie\Queue\JobRunner;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$checks = [];
$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

// O app roda em Fortaleza; o teste precisa reproduzir isso para pegar o erro.
$previousTz = date_default_timezone_get();
date_default_timezone_set('America/Fortaleza');
$now = (new DateTimeImmutable('2026-09-21 15:00:00', new DateTimeZone('UTC')))->getTimestamp();

$age = IntelligenceJobSla::ageHoursSinceUtc('2026-09-21 13:00:00', $now);
$check('sla_reads_utc_even_with_fortaleza_default', abs($age - 2.0) < 0.001, $age);
$check(
    'sla_future_timestamp_is_zero_not_negative',
    IntelligenceJobSla::ageHoursSinceUtc('2026-09-21 16:00:00', $now) === 0.0,
);
$check('sla_invalid_timestamp_is_zero', IntelligenceJobSla::ageHoursSinceUtc('nao-e-data', $now) === 0.0);
date_default_timezone_set($previousTz);

$message = JobRunner::deltaFailureMessage([
    'ok' => false,
    'results' => [
        ['domain' => 'commercial', 'status' => 'failed_terminal', 'message' => 'Classificador retornou canal fiscal invalido.'],
        ['domain' => 'finance', 'status' => 'completed'],
    ],
], '20260921_101500');
$check(
    'delta_failure_names_domain_reason_and_run',
    str_contains($message, 'commercial: Classificador retornou canal fiscal invalido.')
        && str_contains($message, '[run 20260921_101500]')
        && !str_contains($message, 'finance'),
    $message,
);
$check(
    'delta_failure_keeps_prefix_for_existing_filters',
    str_starts_with($message, 'Sync delta Omie falhou'),
);
$check(
    'delta_failure_without_details_stays_readable',
    JobRunner::deltaFailureMessage(['ok' => false, 'results' => []]) === 'Sync delta Omie falhou.',
);
$many = JobRunner::deltaFailureMessage(['ok' => false, 'results' => array_fill(0, 6, [
    'domain' => 'commercial',
    'status' => 'failed_terminal',
    'message' => str_repeat('x', 400),
])]);
$check('delta_failure_is_bounded', mb_strlen($many) < 600, mb_strlen($many));

$failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
$result = [
    'ok' => $failed === [],
    'passed' => count($checks) - count($failed),
    'total' => count($checks),
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
