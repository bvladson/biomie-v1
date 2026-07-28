<?php

declare(strict_types=1);

/**
 * AGENDA — decisão por pedido (Fase 2): o que tira (e o que NUNCA tira) da Agenda.
 *
 * A propriedade central: **falha de rede não é exclusão**. Um timeout, 425, 429
 * ou 500 lido como "sumiu" apagaria orçamentos reais da Agenda — e ninguém
 * perceberia, porque o item simplesmente deixa de aparecer e o vendedor nunca
 * é cobrado. Só CONFIRMAÇÃO do Omie desativa.
 *
 * READ-ONLY: decisão pura, não toca banco nem rede.
 *
 *   php tests/run_quote_order_decision.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\QuoteAgenda\QuoteOrderDecision as D;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

// ── 1. Etapa 10 = segue orçamento ────────────────────────────────────────────
$keep = D::fromEtapa('10');
$check('etapa 10 → KEEP (segue na Agenda)', $keep['action'] === D::KEEP);
$check('KEEP não desativa', !D::deactivates($keep));
$check('etapa 10 como INT também é KEEP', D::fromEtapa(10)['action'] === D::KEEP);

// ── 2. Etapas conhecidas resolvem com o motivo certo ─────────────────────────
foreach ([
    '20' => 'converted', '50' => 'invoiced', '60' => 'closed',
    '70' => 'cancelled', '80' => 'returned',
] as $etapa => $esperado) {
    $d = D::fromEtapa($etapa);
    $check("etapa {$etapa} → RESOLVE/{$esperado}",
        $d['action'] === D::RESOLVE && $d['resolution'] === $esperado, json_encode($d));
    $check("etapa {$etapa} desativa", D::deactivates($d));
}

// ── 3. 🔴 O QUE NUNCA PODE DESATIVAR ─────────────────────────────────────────
// Falhas transitórias por HTTP.
foreach ([408, 425, 429, 500, 502, 503, 504] as $status) {
    $d = D::fromFailure($status, 'erro');
    $check("HTTP {$status} → RETRY (NUNCA desativa)",
        $d['action'] === D::RETRY && !D::deactivates($d), json_encode($d));
}
// Falhas transitórias por texto.
foreach ([
    'Timeout ao conectar',
    'cURL error 28: Operation timed out',
    'API bloqueada por consumo indevido. Tente novamente em 1775 seg',
    'Too Many Requests',
    'reagende em 30s',
    'SSL connection error',
    'Bad gateway',
] as $txt) {
    $d = D::fromFailure(null, $txt);
    $check('texto transitório → RETRY: "' . substr($txt, 0, 34) . '"',
        $d['action'] === D::RETRY && !D::deactivates($d), $d['reason']);
}
// Erro desconhecido: na dúvida, mantém.
$d = D::fromFailure(null, 'algo completamente inesperado xyz');
$check('🔴 erro NÃO classificado → RETRY (mantém por segurança)',
    $d['action'] === D::RETRY && !D::deactivates($d));

// Etapa vazia/ilegível: pedido existe mas não sabemos o estado.
$check('etapa vazia → RETRY (não desativa)', D::fromEtapa('')['action'] === D::RETRY);
$check('etapa null → RETRY (não desativa)', D::fromEtapa(null)['action'] === D::RETRY);

// Etapa desconhecida (empresa pode ter etapa customizada): mantém.
$dUnk = D::fromEtapa('99');
$check('🔴 etapa DESCONHECIDA → RETRY (não inventa resolução)',
    $dUnk['action'] === D::RETRY && !D::deactivates($dUnk), $dUnk['reason']);

// ── 4. Exclusão CONFIRMADA pelo Omie ─────────────────────────────────────────
foreach ([
    'Pedido nao cadastrado',
    'Pedido não cadastrado',
    'ERROR: Pedido não cadastrado para o código informado',
    'Registro not found',
] as $txt) {
    $d = D::fromFailure(null, $txt);
    $check('exclusão confirmada → VANISHED: "' . substr($txt, 0, 30) . '"',
        $d['action'] === D::VANISHED && $d['resolution'] === 'vanished' && D::deactivates($d), $d['reason']);
}

// ── 5. 🔴 PRECEDÊNCIA: throttle vence "não encontrado" ───────────────────────
// Um 429 pode vir com corpo genérico/enganoso. O status manda: é transitório.
$d = D::fromFailure(429, 'Pedido nao cadastrado');
$check('🔴 HTTP 429 com corpo "não cadastrado" → RETRY (status vence)',
    $d['action'] === D::RETRY && !D::deactivates($d), $d['reason']);
$d = D::fromFailure(500, 'not found');
$check('🔴 HTTP 500 com "not found" → RETRY (status vence)',
    $d['action'] === D::RETRY && !D::deactivates($d));

// ── 6. Invariante geral: só 2 caminhos desativam ─────────────────────────────
$acoes = [];
foreach (['', null, '10', '20', '50', '60', '70', '80', '99', 'abc'] as $e) {
    $acoes[D::fromEtapa($e)['action']] = true;
}
foreach ([null, 408, 425, 429, 500, 502, 503, 504, 400, 401, 404] as $s) {
    foreach (['timeout', 'nao cadastrado', 'xpto'] as $t) {
        $acoes[D::fromFailure($s, $t)['action']] = true;
    }
}
ksort($acoes);
$check('apenas 4 ações possíveis (nenhum retorno inesperado)',
    array_keys($acoes) === ['keep', 'retry', 'resolve', 'vanished']
    || array_keys($acoes) === ['keep', 'resolve', 'retry', 'vanished'],
    implode(',', array_keys($acoes)));

$desativam = array_filter(array_keys($acoes), static fn (string $a): bool => D::deactivates(['action' => $a]));
sort($desativam);
$check('🔴 SÓ resolve e vanished desativam', $desativam === ['resolve', 'vanished'], implode(',', $desativam));

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
