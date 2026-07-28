<?php

declare(strict_types=1);

/**
 * FILTRO COMERCIAL INTELIGENTE — preferência do próprio usuário (self-service).
 *
 *   GET                                          — estado resolvido atual
 *                                                  (canais autorizados/efetivos,
 *                                                  vendedores efetivos, filter_hash,
 *                                                  sanitização). Para a UI hidratar
 *                                                  o header sem esperar o dashboard.
 *   POST { action:"save", channels?[], seller_ids?[]|null, csrf_token }
 *                                                  — SALVA a intenção, mas SÓ depois
 *                                                  de validar contra a autorização.
 *   POST { action:"clear", csrf_token }           — volta ao default.
 *
 * SEGURANÇA (o coração do contrato):
 *   · A validação é a MESMA do dashboard: {@see CommercialFilterResolver} no
 *     caminho de URL explícita. Pedir canal/vendedor fora do autorizado ⇒ 403,
 *     e NADA é gravado — jamais se persiste um estado proibido.
 *   · Só grava o que foi VALIDADO. O que se persiste é a INTENÇÃO ("todas" vira
 *     null), não o efetivo — a leitura recalcula sempre.
 *   · É a preferência do PRÓPRIO usuário (não de terceiros): a capability é a de
 *     ver comercial, e a chave usa o usuário autenticado + a empresa do contexto.
 *   · Exige contexto de UMA empresa; consolidado/grupo não persiste (409).
 */

use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Commercial\CommercialFilterPreferenceStore;
use BIomie\Commercial\CommercialFilterResolver;
use BIomie\Commercial\CommercialScopeDeniedException;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('sales.view', true);

$PAGE = CommercialFilterPreferenceStore::PAGE_COMMERCIAL_DASHBOARD;

try {
    /** @var Config $config */
    $config = new Config();
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);

    $userId    = (int) (Auth::currentUser()['id'] ?? 0);
    $companyId = TenantContext::companyIdOrNull();
    $businessScope = TenantContext::businessScope();

    if ($userId <= 0) {
        Http::json(['ok' => false, 'message' => 'Sessão inválida.', 'request_id' => RequestContext::id()], 401);
    }
    // Preferência é por empresa: o consolidado não tem recorte único a memorizar.
    if ($companyId === null) {
        Http::json(['ok' => false, 'error' => 'context_company_required',
            'message' => 'Selecione uma empresa para salvar filtros comerciais.',
            'request_id' => RequestContext::id()], 409);
    }

    $resolver = CommercialFilterResolver::fromPdo($pdo);
    $store = new CommercialFilterPreferenceStore($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    // ── GET: estado resolvido atual (default OU preferência salva) ────────────
    if ($method === 'GET') {
        $resolved = $resolver->resolve($companyId, $userId, $PAGE, $businessScope, [
            'channel_present' => false,
            'channels' => [],
            'seller_present' => false,
            'seller_ids' => [],
        ]);
        Http::json(respond($resolved, $companyId, $businessScope));
    }

    if ($method !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Método não permitido.', 'request_id' => RequestContext::id()], 405);
    }

    // ── POST ──────────────────────────────────────────────────────────────────
    $body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
    $csrf = (string) ($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!Auth::verifyCsrf($csrf)) {
        Http::json(['ok' => false, 'message' => 'Token CSRF inválido.', 'request_id' => RequestContext::id()], 403);
    }

    $action = strtolower(trim((string) ($body['action'] ?? 'save')));

    if ($action === 'clear') {
        $store->clear($companyId, $userId, $PAGE, $businessScope);
        $resolved = $resolver->resolve($companyId, $userId, $PAGE, $businessScope, [
            'channel_present' => false, 'channels' => [],
            'seller_present' => false, 'seller_ids' => [],
        ]);
        Http::json(respond($resolved, $companyId, $businessScope) + ['action' => 'clear']);
    }

    if ($action !== 'save') {
        Http::json(['ok' => false, 'message' => 'Ação inválida (use save ou clear).', 'request_id' => RequestContext::id()], 400);
    }

    // Um SAVE define os dois eixos de uma vez (a UI envia o estado completo):
    //   channels  ausente/[]  = "todas as operações autorizadas"
    //   seller_ids ausente/null = "todos os vendedores autorizados"
    //   seller_ids []          = "nenhum" (vazio explícito, preservado)
    //
    // MERGE PARCIAL POR EIXO: um SAVE pode trazer só canal (toggle de operação) OU
    // só vendedor. O eixo AUSENTE do corpo é PRESERVADO (lido da preferência atual
    // pelo resolvedor), nunca sobrescrito — trocar o canal não apaga o vendedor.
    $channelProvided = array_key_exists('channels', $body);
    $sellerProvided = array_key_exists('seller_ids', $body);
    $channels = normalizeList($body['channels'] ?? []);
    // seller_ids: presente e null ⇒ "todos" (present + vazio no resolver). Lista ⇒ específicos.
    $sellerNull = $sellerProvided && $body['seller_ids'] === null;
    $sellerIds = ($sellerProvided && !$sellerNull) ? normalizeList($body['seller_ids']) : [];

    try {
        // Valida só os eixos PRESENTES (403 se proibido); os ausentes vêm da pref.
        $resolved = $resolver->resolve($companyId, $userId, $PAGE, $businessScope, [
            'channel_present' => $channelProvided,
            'channels' => $channels,
            'seller_present' => $sellerProvided,
            'seller_ids' => $sellerIds,
        ]);
    } catch (CommercialScopeDeniedException $denied) {
        // NADA é gravado quando o pedido é proibido.
        Http::json(['ok' => false, 'error' => 'scope_denied',
            'message' => $denied->getMessage(), 'request_id' => RequestContext::id()], 403);
    }

    // Persiste a INTENÇÃO validada (não o efetivo): "todas" ⇒ null. Ambos os eixos
    // saem do $resolved — o ausente reflete a pref atual (preservação).
    $store->save(
        $companyId,
        $userId,
        $PAGE,
        $businessScope,
        $resolved->requestedChannels,
        $resolved->requestedSellerIds,
        $userId,
    );

    Http::json(respond($resolved, $companyId, $businessScope) + ['action' => 'save', 'saved' => true]);
} catch (Throwable $e) {
    Http::json(['ok' => false, 'error' => 'internal',
        'message' => 'Falha ao processar a preferência de filtro comercial.',
        'request_id' => RequestContext::id()], 500);
}

/**
 * Resposta padrão: efetivo + escopo de canal + filter_hash + sanitização.
 *
 * @return array<string,mixed>
 */
function respond(\BIomie\Commercial\ResolvedCommercialFilter $r, int $companyId, string $businessScope): array
{
    $scope = $r->toEffectiveScope($companyId, '1970-01-01', '1970-01-01'); // hash só dos eixos
    return [
        'ok' => true,
        'business_scope' => $businessScope,
        'company_id' => $companyId,
        'source' => $r->source,
        'channel_scope' => $r->channelScopeMeta(),
        'seller_scope' => [
            'requested' => $r->requestedSellerIds,          // null=todos · []=nenhum
            'effective' => $r->effectiveSellerIds,
            'restricted' => $r->effectiveSellerIds !== null,
        ],
        'sanitized' => $r->sanitized(),
        'sanitization' => [
            'channels_dropped' => $r->droppedChannels,
            'sellers_dropped' => $r->droppedSellers,
        ],
        'filter_hash_axes' => $scope->filterHash(),
        'request_id' => RequestContext::id(),
    ];
}

/**
 * @param  mixed $raw
 * @return list<string>
 */
function normalizeList(mixed $raw): array
{
    if (!is_array($raw)) {
        $raw = $raw === null || $raw === '' ? [] : explode(',', (string) $raw);
    }

    return array_values(array_unique(array_filter(
        array_map(static fn ($v): string => trim((string) $v), $raw),
        static fn (string $v): bool => $v !== ''
    )));
}
