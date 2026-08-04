<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — feedback do vendedor sobre um orçamento (POST).
 *
 * Corpo: { omie_order_id, verdict, expected_close_on?, lost_reason?, comment? }
 * verdict ∈ vai_fechar | incerto | nao_vai_fechar | ja_fechou | perdido
 *
 * POR QUE ISTO EXISTE: hoje o sistema não tem rótulo confiável de desfecho.
 * A coluna `resolution` da agenda está 100% NULL (o portão de desativação
 * automática nunca foi ligado) e `data_faturamento` só existe em 3 de 334
 * pedidos. Sem rótulo não há modelo supervisionado — a medição em 03/08/2026
 * deu 72 linhas com 2 conversões, longe do mínimo para treinar.
 *
 * O feedback do vendedor é a fonte de rótulo que NÃO depende de esperar a NF
 * sair. Cada resposta aqui é uma linha de treino no futuro.
 *
 * Guarda o `score_id` que estava na tela: comparar a resposta com o score
 * VIGENTE meses depois compararia com outro número. A avaliação honesta exige
 * saber o que o vendedor estava vendo quando respondeu.
 */

use BIomie\Auth;
use BIomie\Http;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/quote_agenda_common.php';

Auth::requireCapability('sales.quotes.followup.view', true);
quoteAgendaRequireEnabled();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Http::methodNotAllowed(['POST']);
}
Http::enforceCsrf($logger);

const VEREDITOS = ['vai_fechar', 'incerto', 'nao_vai_fechar', 'ja_fechou', 'perdido'];

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    Http::validationError('Corpo JSON inválido.');
}

$orderId = trim((string) ($body['omie_order_id'] ?? ''));
if ($orderId === '' || !ctype_digit($orderId)) {
    Http::validationError('"omie_order_id" é obrigatório e deve ser numérico.');
}

$verdict = trim((string) ($body['verdict'] ?? ''));
if (!in_array($verdict, VEREDITOS, true)) {
    Http::validationError('"verdict" inválido. Use: ' . implode(', ', VEREDITOS) . '.');
}

$expected = trim((string) ($body['expected_close_on'] ?? ''));
if ($expected !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected)) {
    Http::validationError('"expected_close_on" deve ser YYYY-MM-DD.');
}

// MESMO escopo de vendedores da cobrança: quem não enxerga o orçamento também
// não opina sobre ele. Sem isto, o endpoint viraria um caminho lateral para
// descobrir que um pedido existe fora do escopo.
quoteAgendaAssertWritable($orderId, 0);

$user = Auth::currentUser();
$userId = (int) ($user['id'] ?? 0);
if ($userId <= 0) {
    Http::validationError('Usuário não identificado.');
}

$pdo = quoteAgendaPdo();
$companyId = quoteAgendaCompanyId();

// Score que o vendedor estava vendo. Só os modos visíveis — um feedback jamais
// pode ser atribuído a um score em modo silencioso, que ele nunca viu.
$scoreId = null;
try {
    $st = $pdo->prepare(
        "SELECT id FROM intelligence_quote_scores
          WHERE company_id = ? AND omie_order_id = ? AND is_current = 1
            AND run_mode IN ('baseline','active')
          ORDER BY CASE run_mode WHEN 'active' THEN 0 ELSE 1 END
          LIMIT 1"
    );
    $st->execute([$companyId, $orderId]);
    $found = $st->fetchColumn();
    $scoreId = $found === false ? null : (int) $found;
} catch (\Throwable $e) {
    // Sem score na tela o feedback continua valendo — ele é o dado escasso aqui.
    $logger->warning('quote_feedback: não consegui resolver o score vigente.', [
        'message' => $e->getMessage(),
    ]);
}

try {
    $st = $pdo->prepare(
        'INSERT INTO intelligence_quote_feedback
            (company_id, omie_order_id, user_id, score_id, verdict,
             expected_close_on, lost_reason, comment, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $st->execute([
        $companyId,
        $orderId,
        $userId,
        $scoreId,
        $verdict,
        $expected !== '' ? $expected : null,
        mb_substr(trim((string) ($body['lost_reason'] ?? '')), 0, 64) ?: null,
        mb_substr(trim((string) ($body['comment'] ?? '')), 0, 500) ?: null,
    ]);
} catch (\Throwable $e) {
    $logger->error('quote_feedback: falha ao gravar.', ['message' => $e->getMessage()]);
    quoteAgendaRespondRaw([
        'ok' => false,
        'error' => 'persist_failed',
        'message' => 'Não foi possível registrar o feedback.',
    ], 500);
}

quoteAgendaRespondRaw([
    'ok' => true,
    'data' => [
        'omie_order_id' => $orderId,
        'verdict' => $verdict,
        'score_id' => $scoreId,
        'registrado_em' => gmdate('c'),
    ],
], 200);
