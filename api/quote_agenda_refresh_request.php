<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — botão "Atualizar" (Fase 2).
 *
 * POST · exige sales.quotes.followup.view · CSRF.
 *
 * Contrato do clique:
 *  1. LÊ O BANCO NA HORA e devolve os números atuais — o gestor vê o resultado
 *     imediatamente, sem depender da Omie.
 *  2. SÓ ENFILEIRA a sincronização remota se o cooldown da empresa permitir.
 *  3. NUNCA espera resposta da Omie e NUNCA toca outra empresa: o escopo é o
 *     TenantContext da sessão, e o job enfileirado carrega esse company_id.
 *
 * Sem a flag do refresh, o botão continua servindo (leitura local) — apenas não
 * enfileira nada. A reconciliação periódica segue sendo a rede de segurança.
 */

use BIomie\Auth;
use BIomie\Config;
use BIomie\Http;
use BIomie\Queue\FileJobQueue;
use BIomie\QuoteAgenda\QuoteAgendaReadService;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/quote_agenda_common.php';

Auth::requireCapability('sales.quotes.followup.view', true);
quoteAgendaRequireEnabled();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Http::methodNotAllowed(['POST']);
}
$body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
if (!Auth::verifyCsrf((string) ($body['csrf_token'] ?? ''))) {
    quoteAgendaRespondRaw(['ok' => false, 'error' => 'csrf', 'message' => 'Token CSRF inválido.'], 403);
}

/**
 * COOLDOWN DO BOTÃO (segundos) — intervalo mínimo entre sincronizações remotas
 * disparadas pelo gestor, POR EMPRESA. É um controle DIFERENTE do debounce por
 * pedido ({@see \BIomie\QuoteAgenda\QuoteOrderRefresher::DEBOUNCE_SECONDS}=75s):
 *  · debounce = evita reconsultar o MESMO pedido à Omie (fronteira de 60s + margem);
 *  · cooldown = evita o gestor pedir sync da EMPRESA inteira em rajada.
 * Vivem em camadas distintas; este é maior de propósito.
 */
const REMOTE_COOLDOWN_SECONDS = 120;

$pdo = quoteAgendaPdo();
$cid = quoteAgendaCompanyId();

/** @var Config $config */
$config = $GLOBALS['config'];

// ── 1. LEITURA LOCAL IMEDIATA ───────────────────────────────────────────────
$read = new QuoteAgendaReadService($pdo, $cid);
$bundle = $read->readWithFreshness(
    static fn (QuoteAgendaReadService $s): array => ['kpis' => $s->summary()['kpis'] ?? null]
);

// ── 2. COOLDOWN da sincronização remota (por empresa) ───────────────────────
// Base: quando o último scan COMEÇOU. Usar o início (e não o sucesso) impede
// que cliques repetidos empilhem jobs enquanto um scan ainda está rodando.
$st = $pdo->prepare(
    'SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) AS since_start, status
       FROM biomie_quote_live_snapshot_sync WHERE company_id = :c'
);
$st->execute(['c' => $cid]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$sinceStart = isset($row['since_start']) && $row['since_start'] !== null ? (int) $row['since_start'] : null;
$running = ($row['status'] ?? '') === 'running';

$cooldownOk = !$running && ($sinceStart === null || $sinceStart >= REMOTE_COOLDOWN_SECONDS);
$retryAfter = $cooldownOk ? 0 : max(1, REMOTE_COOLDOWN_SECONDS - (int) ($sinceStart ?? 0));

$queued = false;
$queueReason = 'cooldown';
if (!(bool) $config->get('features.quote_agenda_webhook_refresh_enabled', false)) {
    $queueReason = 'sincronização sob demanda desabilitada';
} elseif ($running) {
    $queueReason = 'já há uma sincronização em andamento';
} elseif (!$cooldownOk) {
    $queueReason = sprintf('aguarde %ds antes de pedir de novo', $retryAfter);
} else {
    try {
        // SÓ ESTA EMPRESA. O job carrega o company_id da sessão; nenhuma outra
        // empresa é tocada por este clique.
        (new FileJobQueue(
            dirname(__DIR__) . '/storage/cache',
            new \BIomie\Logger(dirname(__DIR__) . '/storage/logs/quote_agenda_refresh.log')
        ))
            ->enqueue('high', 'quote_agenda.company.scan', [
                'company_id' => $cid,
                'source' => 'manual',
            ], [
                'idempotency_key' => sprintf('quote_agenda.company.scan|company:%d|%d',
                    $cid, (int) floor(time() / REMOTE_COOLDOWN_SECONDS)),
                'max_attempts' => 2,
            ]);
        $queued = true;
        $queueReason = 'sincronização enfileirada';
    } catch (Throwable) {
        $queueReason = 'não foi possível enfileirar';
    }
}

quoteAgendaRespond([
    'data' => [
        'kpis' => $bundle['data']['kpis'] ?? null,
        'remote_sync' => [
            'queued' => $queued,
            'reason' => $queueReason,
            'retry_after_seconds' => $retryAfter,
            'cooldown_seconds' => REMOTE_COOLDOWN_SECONDS,
        ],
    ],
    'freshness' => $bundle['freshness'],
    'generated_at' => $bundle['generated_at'],
]);
