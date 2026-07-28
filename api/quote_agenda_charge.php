<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — "Cobrança realizada" (POST, idempotente).
 *
 * Exige sales.quotes.followup.manage. Nunca consulta o Omie.
 *
 * Cabeçalho OBRIGATÓRIO: Idempotency-Key (UUID por operação lógica).
 * Corpo: { omie_order_id | followup_id, expected_charge_count, note? }
 *
 * `expected_charge_count` protege o DUPLO CLIQUE — que gera DUAS chaves
 * distintas e por isso não é coberto só pela Idempotency-Key.
 *
 * Respostas: 200 (ok/replay) · 400 · 403 · 404 · 409 (in_progress|stale) · 422.
 * No replay devolvemos o MESMO status e corpo + `Idempotency-Replayed: true`.
 */

use BIomie\Auth;
use BIomie\Http;
use BIomie\QuoteAgenda\QuoteAgendaReadService;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/quote_agenda_common.php';

Auth::requireCapability('sales.quotes.followup.manage', true);
quoteAgendaRequireEnabled();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Http::methodNotAllowed(['POST']);
}
Http::enforceCsrf($logger);

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    Http::validationError('Corpo JSON inválido.');
}

$orderId = trim((string) ($body['omie_order_id'] ?? ''));
$followupId = (int) ($body['followup_id'] ?? 0);
if ($orderId === '' && $followupId <= 0) {
    Http::validationError('Informe "omie_order_id" ou "followup_id".');
}
if (!array_key_exists('expected_charge_count', $body)) {
    quoteAgendaRespondRaw([
        'ok' => false,
        'error' => 'missing_expected_charge_count',
        'message' => 'expected_charge_count é obrigatório (proteção contra duplo clique).',
    ], 400);
}

// Escopo de vendedores ANTES de qualquer efeito: a cobrança grava trilha e
// avança o passo do acompanhamento.
quoteAgendaAssertWritable($orderId, $followupId);

$user = Auth::currentUser();
$input = [
    'omie_order_id' => $orderId,
    'followup_id' => $followupId,
    'manager_user_id' => (int) ($user['id'] ?? 0),
    // Snapshot para a trilha: fica FORA do fingerprint (não altera o resultado).
    'manager_name' => trim((string) ($user['nome'] ?? $user['name'] ?? $user['email'] ?? '')),
    'expected_charge_count' => (int) $body['expected_charge_count'],
    'note' => trim((string) ($body['note'] ?? '')),
];

$key = (string) Http::headerValue('Idempotency-Key', '');
$result = quoteAgendaChargeService()->charge($key, $input);

$headers = [];
if ($result['replayed']) {
    $headers['Idempotency-Replayed'] = 'true';
}
if (isset($result['body']['retry_after'])) {
    $headers['Retry-After'] = (string) $result['body']['retry_after'];
}

// Sucesso devolve o followup atualizado ⇒ carrega o frescor junto.
if ($result['status'] === 200) {
    $envelope = quoteAgendaReadService()->readWithFreshness(
        static fn (QuoteAgendaReadService $s): array => $result['body']
    );

    quoteAgendaRespondRaw([
        'ok' => true,
        'data' => $envelope['data'],
        'freshness' => $envelope['freshness'],
        'generated_at' => $envelope['generated_at'],
        'replayed' => $result['replayed'],
    ], 200, $headers);
}

quoteAgendaRespondRaw($result['body'], $result['status'], $headers);
