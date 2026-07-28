<?php

declare(strict_types=1);

/**
 * PROVISIONAMENTO DE USUÁRIO — a ÚNICA requisição do assistente de 4 etapas.
 *
 *   POST  { nonce, csrf_token, name, username, email, password, is_active,
 *           companies:[{company_id, role, can_varejo, can_licitacao, is_default}],
 *           visibility:{ "<company_id>": {mode, seller_ids[]} } }
 *
 * O assistente NÃO grava nada enquanto o admin navega pelas etapas — o estado
 * vive no cliente. Só esta chamada escreve, e escreve tudo de uma vez.
 *
 * ─── ANTI-DUPLICIDADE EM DUAS CAMADAS ───────────────────────────────────────
 *  · nonce de USO ÚNICO na sessão: o segundo POST idêntico não cria nada.
 *    Botão desabilitado no cliente é conforto, não garantia — quem reenviar a
 *    requisição por fora passaria por cima dele.
 *  · UNIQUE de username/email no banco é a rede final.
 * Reenvio com nonce já gasto responde 409 explicando, sem criar segundo usuário.
 *
 * Nunca expõe SQL, stack trace, hash de senha ou o próprio nonce.
 */

use BIomie\Admin\AdminLogService;
use BIomie\Admin\RbacService;
use BIomie\Admin\UserProvisioningService;
use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Logger;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('users.manage', true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Http::methodNotAllowed(['POST']);
}

const PROVISION_NONCE_KEY = 'admin_user_provision_nonce';

$body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);

if (!Auth::verifyCsrf((string) ($body['csrf_token'] ?? ''))) {
    Http::json(['ok' => false, 'code' => 'CSRF_INVALID',
        'message' => 'Sessão expirada. Recarregue a página.', 'request_id' => RequestContext::id()], 403);
}

// ── Nonce de uso único ──────────────────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$sentNonce  = (string) ($body['nonce'] ?? '');
$storeNonce = (string) ($_SESSION[PROVISION_NONCE_KEY] ?? '');

if ($sentNonce === '' || $storeNonce === '' || !hash_equals($storeNonce, $sentNonce)) {
    // Nonce não confere: submissão repetida OU token defasado após um envio
    // anterior. Emite um nonce NOVO junto do 409 para que o cliente se recupere
    // — sem isso, uma falha pós-consumo deixaria o admin travado sem token.
    // NOTA: isto impede DUPLO usuário (o UNIQUE também), mas não distingue
    // "já criei" de "falhou" — a idempotência plena (nonce→status→resultado
    // guardados) fica como evolução futura, fora deste pacote.
    $fresh = bin2hex(random_bytes(16));
    $_SESSION[PROVISION_NONCE_KEY] = $fresh;
    Http::json([
        'ok' => false,
        'code' => 'DUPLICATE_SUBMISSION',
        'message' => 'Esta criação já foi enviada. Confira a lista de usuários antes de tentar de novo.',
        'next_nonce' => $fresh,
        'request_id' => RequestContext::id(),
    ], 409);
}
// Consome ANTES de executar: mesmo que a operação falhe, o token não se repete.
unset($_SESSION[PROVISION_NONCE_KEY]);

try {
    $config = new Config();
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);
    $logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');

    $actor = Auth::currentUser();
    $actorId = (int) ($actor['id'] ?? 0);
    $actorRole = (string) ($actor['role'] ?? 'viewer');

    $service = new UserProvisioningService(
        $pdo,
        new RbacService($pdo),
        new AdminLogService($pdo, $logger),
        $logger
    );

    $result = $service->provision($body, $actorId, $actorRole);
    $status = (int) $result['http_status'];
    $payload = (array) $result['body'];

    // Falha interna: log técnico FORA da transação, sem dado sensível.
    if (isset($result['internal'])) {
        $logger->error('USER_PROVISION_FAILED', [
            'request_id' => RequestContext::id(),
            'actor_id'   => $actorId,
            'stage'      => 'transaction',
            'error_class' => (string) $result['internal'],
        ]);
    }

    // Sucesso: emite um nonce NOVO para o admin poder criar outro sem recarregar.
    if ($status === 201) {
        $_SESSION[PROVISION_NONCE_KEY] = bin2hex(random_bytes(16));
        $payload['next_nonce'] = $_SESSION[PROVISION_NONCE_KEY];
    } else {
        // Falha recuperável (validação): devolve um nonce novo para o admin
        // corrigir e reenviar sem perder o que digitou.
        $_SESSION[PROVISION_NONCE_KEY] = bin2hex(random_bytes(16));
        $payload['next_nonce'] = $_SESSION[PROVISION_NONCE_KEY];
    }

    Http::json($payload + ['request_id' => RequestContext::id()], $status);
} catch (Throwable $e) {
    Http::json([
        'ok' => false,
        'code' => 'PROVISIONING_FAILED',
        'message' => 'Nenhuma alteração foi aplicada.',
        'request_id' => RequestContext::id(),
    ], 500);
}
