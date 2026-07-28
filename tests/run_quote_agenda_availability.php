<?php

declare(strict_types=1);

/**
 * Gate de leitura da Agenda de Orcamentos.
 *
 * Nao acessa banco nem altera estado. Prova que:
 *  - OFF explicito e um kill switch e nunca e contornado pela politica;
 *  - flag ausente pode recuperar somente empresas previamente habilitadas;
 *  - politica OFF/ausente continua fail-closed.
 *
 *   php tests/run_quote_agenda_availability.php
 */

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\QuoteAgenda\QuoteAgendaAvailability;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$autoOn = QuoteAgendaAvailability::fromSignals(false, false, true);
$check('flag ausente + politica ON libera leitura', $autoOn['enabled'] === true, $autoOn);
$check('flag ausente fica no modo company_policy', $autoOn['global_mode'] === 'company_policy', $autoOn);

$autoOff = QuoteAgendaAvailability::fromSignals(false, false, false);
$check('flag ausente + politica OFF nega leitura', $autoOff['enabled'] === false, $autoOff);
$check('negacao identifica empresa desativada', $autoOff['error'] === 'company_feature_disabled', $autoOff);

$autoMissing = QuoteAgendaAvailability::fromSignals(false, false, null);
$check('flag ausente + politica ausente falha fechada', $autoMissing['enabled'] === false, $autoMissing);
$check('politica ausente tem erro especifico', $autoMissing['error'] === 'company_feature_not_configured', $autoMissing);

$killSwitch = QuoteAgendaAvailability::fromSignals(false, true, true);
$check('OFF explicito vence politica ON', $killSwitch['enabled'] === false, $killSwitch);
$check('OFF explicito retorna feature_disabled', $killSwitch['error'] === 'feature_disabled', $killSwitch);
$check('OFF explicito fica no modo off', $killSwitch['global_mode'] === 'off', $killSwitch);

$explicitOn = QuoteAgendaAvailability::fromSignals(true, true, true);
$check('ON explicito + politica ON libera leitura', $explicitOn['enabled'] === true, $explicitOn);

$explicitOnCompanyOff = QuoteAgendaAvailability::fromSignals(true, true, false);
$check('ON explicito nao contorna politica OFF', $explicitOnCompanyOff['enabled'] === false, $explicitOnCompanyOff);

$passed = count(array_filter($checks, static fn (array $row): bool => $row['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
