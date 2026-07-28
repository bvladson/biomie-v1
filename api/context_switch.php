<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Http\ErrorBoundary;
use BIomie\Logger;
use BIomie\RequestContext;
use BIomie\Support\CompanyContext;
use BIomie\Support\ContextSwitchAuditLogger;
use BIomie\Support\TenantContext;
use BIomie\Support\TenantContextResolver;
use BIomie\Support\TenantContextUnavailableException;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();
ErrorBoundary::registerApiHandlers([
    'route' => 'api/context_switch.php',
    'handler' => 'api/context_switch.php',
]);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: same-origin');

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
Auth::boot($config, $logger);
Auth::requireLogin(true);

$pdo = Database::connect($config, 'db');
$resolver = new TenantContextResolver($pdo);
$audit = new ContextSwitchAuditLogger($pdo);
$userId = (int) (Auth::currentUser()['id'] ?? 0);
$from = null;
$targetType = null;
$targetCompanyId = null;
$isClone = false;

/**
 * Adaptador legado injeta body já traduzido. O cliente nunca escolhe o ID.
 *
 * @var array<string,mixed>|null $bodyOverride
 */
$bodyOverride = is_array($GLOBALS['biomie_context_switch_body'] ?? null)
    ? $GLOBALS['biomie_context_switch_body']
    : null;

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        contextSwitchAuditFailure($audit, $userId, null, null, null, 'method_not_allowed');
        Http::json([
            'ok' => false,
            'error' => 'method_not_allowed',
            'message' => 'Método não permitido. Use POST.',
            'request_id' => RequestContext::id(),
        ], 405, ['Allow' => 'POST']);
    }

    if ($bodyOverride !== null) {
        $body = $bodyOverride;
    } else {
        $rawBody = (string) file_get_contents('php://input');
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            contextSwitchAuditFailure($audit, $userId, null, null, null, 'invalid_json');
            Http::json([
                'ok' => false,
                'error' => 'invalid_json',
                'message' => 'Envie um objeto JSON válido.',
                'request_id' => RequestContext::id(),
            ], 400);
        }
        $body = $decoded;
    }

    $csrf = trim((string) (
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $body['csrf_token']
        ?? ''
    ));
    if (!Auth::verifyCsrf($csrf)) {
        contextSwitchAuditFailure($audit, $userId, null, null, null, 'csrf_invalid');
        Http::json([
            'ok' => false,
            'error' => 'csrf_invalid',
            'message' => 'Token CSRF inválido.',
            'request_id' => RequestContext::id(),
        ], 403);
    }

    // O primeiro contexto nasce na tela de seleção sem ctx anterior. Se o
    // cliente enviar qualquer ctx, ele precisa ser íntegro e pertencer ao usuário.
    if (contextSwitchHasContextCandidate()) {
        try {
            $from = $resolver->resolveFromRequest($userId);
        } catch (TenantContextUnavailableException $exception) {
            contextSwitchAuditFailure(
                $audit,
                $userId,
                null,
                null,
                null,
                $exception->getReasonCode(),
            );
            $status = in_array($exception->getCode(), [400, 401, 403, 409, 422, 503], true)
                ? $exception->getCode()
                : 403;
            Http::json([
                'ok' => false,
                'error' => $exception->getReasonCode(),
                'message' => $exception->getMessage(),
                'request_id' => RequestContext::id(),
                'redirect' => Auth::url('select_empresa.php?context_reset=1'),
            ], $status, [
                'X-BIOMIE-Context-Invalid' => '1',
                'X-BIOMIE-Context-Reason' => $exception->getReasonCode(),
                'X-BIOMIE-Context-Redirect' => Auth::url('select_empresa.php?context_reset=1'),
            ]);
        }
    }

    $targetType = strtolower(trim((string) ($body['target_type'] ?? '')));
    if ($targetType === 'clone') {
        $isClone = true;
        if (!$from instanceof CompanyContext) {
            throw new TenantContextUnavailableException(
                'context_required',
                403,
                'Não existe contexto válido para clonar.',
            );
        }
        $targetType = $from->type;
        $targetCompanyId = $from->companyId;
    } elseif ($targetType === 'company') {
        $targetCompanyId = filter_var($body['company_id'] ?? null, FILTER_VALIDATE_INT);
        $targetCompanyId = is_int($targetCompanyId) ? $targetCompanyId : null;
    }

    if (!in_array($targetType, ['company', 'group'], true)) {
        throw new TenantContextUnavailableException(
            'context_target_invalid',
            400,
            'target_type deve ser company ou group.',
        );
    }
    if ($targetType === 'group' && !TenantContext::groupContextEnabled()) {
        throw new TenantContextUnavailableException(
            'context_group_not_available',
            409,
            'A visão consolidada está temporariamente indisponível até concluir a validação de escopo das rotas.',
        );
    }

    $pdo->beginTransaction();
    if ($targetType === 'company') {
        if ($targetCompanyId === null || $targetCompanyId <= 0) {
            throw new TenantContextUnavailableException(
                'context_target_invalid',
                400,
                'company_id é obrigatório para target_type=company.',
            );
        }
        $scope = isset($body['business_scope'])
            ? (string) $body['business_scope']
            : ($isClone ? $from?->businessScope : null);
        $to = $resolver->createCompanyContext($userId, $targetCompanyId, $scope);
    } else {
        $requestedIds = $body['company_ids'] ?? ($from?->allowedCompanyIds ?? []);
        if (!is_array($requestedIds)) {
            throw new TenantContextUnavailableException(
                'context_target_invalid',
                400,
                'company_ids deve ser uma lista de IDs.',
            );
        }
        $to = $resolver->createGroupContext($userId, $requestedIds);
    }

    // Auditoria e criação pertencem à mesma transação: não existe troca sem log.
    $audit->record($userId, $from, $to, 'success');
    $pdo->commit();

    $redirectUrl = Auth::url('index.php') . '?ctx=' . rawurlencode($to->contextId);
    $publicContext = contextSwitchPublicContext($to);
    $response = [
        'ok' => true,
        'context' => $publicContext,
        'redirect_url' => $redirectUrl,
        'message' => 'Contexto alterado para ' . $to->companyName . '.',
        'request_id' => RequestContext::id(),
        'meta' => [
            'context_id' => $to->contextId,
            'context_type' => $to->type,
            'company_id' => $to->companyId,
            'company_slug' => $to->companySlug,
            'business_scope' => $to->businessScope,
            'seller_scope_hash' => $to->sellerScopeHash,
            'permissions_version' => $to->permissionsVersion,
            'allowed_company_ids' => $to->allowedCompanyIds,
            'allowed_business_scopes' => $to->allowedBusinessScopes,
            'context_resolved_at' => date(DATE_ATOM),
        ],
    ];

    // Uma versão antiga dos assets pode existir durante a virada de release.
    // O adaptador só acrescenta aliases; nunca volta a gravar contexto em sessão.
    if (!empty($GLOBALS['biomie_context_switch_legacy_response'])) {
        $response += [
            'context_id' => $to->contextId,
            'company_id' => $to->companyId ?? 0,
            'slug' => $to->companySlug,
            'canal' => $to->isGroup() ? 'consolidado' : $to->businessScope,
            'allowed_ids' => $to->allowedCompanyIds,
        ];
    }

    Http::json($response, 200, [
        'X-BIOMIE-Context' => $to->contextId,
    ]);
} catch (TenantContextUnavailableException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    contextSwitchAuditFailure(
        $audit,
        $userId,
        $from,
        $targetType,
        $targetCompanyId,
        $exception->getReasonCode(),
    );
    $status = in_array($exception->getCode(), [400, 401, 403, 409, 422, 503], true)
        ? $exception->getCode()
        : 403;
    Http::json([
        'ok' => false,
        'error' => $exception->getReasonCode(),
        'message' => $exception->getMessage(),
        'request_id' => RequestContext::id(),
        'redirect' => null,
    ], $status);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    contextSwitchAuditFailure(
        $audit,
        $userId,
        $from,
        $targetType,
        $targetCompanyId,
        'context_switch_internal',
        'error',
    );
    $logger->error('Falha interna ao trocar contexto.', [
        'request_id' => RequestContext::id(),
        'user_id' => $userId,
        'target_type' => $targetType,
        'target_company_id' => $targetCompanyId,
        'message' => $exception->getMessage(),
    ]);
    Http::json([
        'ok' => false,
        'error' => 'context_switch_internal',
        'message' => 'Não foi possível alterar o contexto.',
        'request_id' => RequestContext::id(),
    ], 500);
}

/**
 * @return array<string,mixed>
 */
function contextSwitchPublicContext(CompanyContext $context): array
{
    return [
        'id' => $context->contextId,
        'type' => $context->type,
        'company_id' => $context->companyId,
        'company_slug' => $context->companySlug,
        'company_name' => $context->companyName,
        'business_scope' => $context->businessScope,
        'role' => $context->role,
        'seller_scope_hash' => $context->sellerScopeHash,
        'permissions_version' => $context->permissionsVersion,
        'allowed_company_ids' => $context->allowedCompanyIds,
        'allowed_business_scopes' => $context->allowedBusinessScopes,
    ];
}

function contextSwitchHasContextCandidate(): bool
{
    return isset($_SERVER['HTTP_X_BIOMIE_CONTEXT'])
        || array_key_exists('ctx', $_GET)
        || array_key_exists('ctx', $_POST);
}

function contextSwitchAuditFailure(
    ContextSwitchAuditLogger $audit,
    int $userId,
    ?CompanyContext $from,
    ?string $targetType,
    ?int $targetCompanyId,
    string $reason,
    string $status = 'blocked',
): void {
    try {
        $audit->recordBlocked(
            $userId,
            $from,
            $targetType,
            $targetCompanyId,
            $reason,
            $status,
        );
    } catch (Throwable) {
        // Se a migration ainda não existe, a resposta controlada continua sendo
        // fail-closed; nunca liberamos a troca sem o storage/audit R1.
    }
}
