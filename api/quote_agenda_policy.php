<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — política de cobrança por empresa.
 *
 * GET  · exige sales.quotes.followup.view
 * POST · exige sales.quotes.followup.manage
 *
 * NÃO carrega bloco freshness: a política é configuração do BIomie, não dado
 * derivado do Omie. Nunca consulta o Omie.
 */

use BIomie\Auth;
use BIomie\Http;
use BIomie\Http\RequestInput;
use BIomie\QuoteAgenda\FollowupDatePolicy;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/quote_agenda_common.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    Http::methodNotAllowed(['GET', 'POST']);
}

Auth::requireCapability(
    $method === 'GET' ? 'sales.quotes.followup.view' : 'sales.quotes.followup.manage',
    true
);
quoteAgendaRequireEnabled();

$pdo = quoteAgendaPdo();
$cid = quoteAgendaCompanyId();

if ($method === 'GET') {
    $st = $pdo->prepare(
        'SELECT company_id, cadence_days_json, cadence_model, weekend_policy, holiday_policy,
                critical_after_step, stale_after_minutes, enabled
           FROM biomie_quote_followup_policy WHERE company_id = :c'
    );
    $st->execute(['c' => $cid]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $policy = FollowupDatePolicy::fromPolicyRow($row);

    quoteAgendaRespondRaw([
        'ok' => true,
        'data' => [
            'company_id' => $cid,
            'cadence_days' => $policy->cadenceDays(),
            'cadence_model' => (string) ($row['cadence_model'] ?? 'absolute_from_creation'),
            'weekend_policy' => (string) ($row['weekend_policy'] ?? 'roll_forward'),
            'holiday_policy' => (string) ($row['holiday_policy'] ?? 'none'),
            'critical_after_step' => (int) ($row['critical_after_step'] ?? 4),
            'stale_after_minutes' => (int) ($row['stale_after_minutes'] ?? 45),
            'enabled' => (bool) ($row['enabled'] ?? false),
            'total_steps' => $policy->totalSteps(),
        ],
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

Http::enforceCsrf($logger);

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    Http::validationError('Corpo JSON inválido.');
}

$input = new RequestInput($body);
$cadence = $input->intList('cadence_days');
if (!is_array($cadence) || $cadence === []) {
    Http::validationError('cadence_days deve ser uma lista de inteiros, ex.: [1,3,7,14].');
}
foreach ($cadence as $d) {
    if ($d < 0 || $d > 365) {
        Http::validationError('cadence_days deve conter inteiros entre 0 e 365.');
    }
}
sort($cadence);

$weekend = $input->string('weekend_policy') ?? 'roll_forward';
if (!in_array($weekend, ['roll_forward', 'none'], true)) {
    Http::validationError('weekend_policy inválida.');
}
$stale = $input->positiveInt('stale_after_minutes') ?? 45;
if ($stale < 5 || $stale > 1440) {
    Http::validationError('stale_after_minutes deve estar entre 5 e 1440.');
}

$pdo->prepare(
    'INSERT INTO biomie_quote_followup_policy
        (company_id, cadence_days_json, weekend_policy, critical_after_step, stale_after_minutes, enabled)
     VALUES (:c, :cad, :wk, :crit, :stale, :en)
     ON DUPLICATE KEY UPDATE
        cadence_days_json = VALUES(cadence_days_json),
        weekend_policy = VALUES(weekend_policy),
        critical_after_step = VALUES(critical_after_step),
        stale_after_minutes = VALUES(stale_after_minutes),
        enabled = VALUES(enabled),
        updated_at = NOW()'
)->execute([
    'c' => $cid,
    'cad' => json_encode($cadence),
    'wk' => $weekend,
    'crit' => $input->positiveInt('critical_after_step') ?? count($cadence),
    'stale' => $stale,
    'en' => $input->boolean('enabled') ? 1 : 0,
]);

quoteAgendaRespondRaw([
    'ok' => true,
    'data' => ['company_id' => $cid, 'cadence_days' => $cadence, 'weekend_policy' => $weekend,
               'stale_after_minutes' => $stale, 'enabled' => (bool) ($body['enabled'] ?? false)],
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
]);
